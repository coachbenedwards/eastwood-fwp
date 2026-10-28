<?php
/**
 * Gate ticket scanning — endpoints for the Eastwood FC app's "Scan tickets".
 *
 * Auto-loaded from parts/. Two routes under eastwood/v1:
 *   GET  /wp-json/eastwood/v1/ticket/verify?ref=<EFC-code or _ewt_key>
 *        Public read. Looks a ticket up; does NOT mark it.
 *   POST /wp-json/eastwood/v1/ticket/checkin   { "ref": "<...>" }
 *        Header  X-Scan-Secret: <ew_scan_secret option>
 *        Marks _ewt_in once (admits the ticket).
 *   POST /wp-json/eastwood/v1/ticket/food-use   { "ref": "<...>", "i": <token> }
 *        Header  X-Scan-Secret. Takes one token off a food token pass.
 *
 * A food token pass is not a way through the turnstile, so check-in never
 * admits one. Verify still recognises it and answers status "food" with
 * the tokens left, so the same scanner can serve the food counter.
 *
 * Reuses the existing ewt_booking records (paid tickets + free claims).
 *
 * The check-in secret defaults to the value baked into the app build below, so
 * nothing needs setting up. To rotate it later, set the ew_scan_secret option
 * (and change EXPO_PUBLIC_SCAN_SECRET in the app build to match).
 */

/** Shared secret for the check-in write. Default matches the app build. */
const EW_SCAN_SECRET_DEFAULT = '02be2f0e444a7321a1b8dbf8da19087ad3122112ef8dc42a';

add_action('rest_api_init', function () {
	register_rest_route('eastwood/v1', '/ticket/verify', [
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => 'ew_ticket_verify',
	]);
	register_rest_route('eastwood/v1', '/ticket/checkin', [
		'methods'             => 'POST',
		'permission_callback' => '__return_true', // secret checked inside
		'callback'            => 'ew_ticket_checkin',
	]);
	register_rest_route('eastwood/v1', '/ticket/food-use', [
		'methods'             => 'POST',
		'permission_callback' => '__return_true', // secret checked inside
		'callback'            => 'ew_ticket_food_use',
	]);
});

/**
 * Find a booking by its short code (_ewt_code) or opaque key (_ewt_key).
 *
 * A food token pass is a paid Booking with a code too, but it is not a way
 * through the turnstile, so the gate scanner is never shown one.
 */
function ew_ticket_find($ref) {
	$id = ew_ticket_find_any($ref);
	if ($id && 'food' === get_post_meta($id, '_ewt_kind', true)) return null;
	return $id;
}

function ew_ticket_find_any($ref) {
	$ref = trim((string) $ref);
	if ($ref === '') return null;
	foreach (['_ewt_code', '_ewt_key'] as $meta) {
		$q = new WP_Query([
			'post_type'      => 'ewt_booking',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'no_found_rows'  => true,
			'meta_key'       => $meta,
			'meta_value'     => $ref,
			'fields'         => 'ids',
		]);
		if (!empty($q->posts)) return (int) $q->posts[0];
	}
	return null;
}

/** How many people a booking admits. */
function ew_ticket_headcount($id) {
	$adults   = (int) get_post_meta($id, '_ewt_adults', true);
	$children = (int) get_post_meta($id, '_ewt_children', true);
	if ($adults + $children > 0) return $adults + $children;
	$lines = get_post_meta($id, '_ewt_lines', true);
	if (is_string($lines) && preg_match_all('/(\d+)\s*[x\xC3\x97]/i', $lines, $m)) {
		$n = array_sum(array_map('intval', $m[1]));
		if ($n > 0) return $n;
	}
	return 1;
}

function ew_ticket_payload($id, $status) {
	return [
		'status'        => $status, // valid | used | unpaid | notfound
		'name'          => get_post_meta($id, '_ewt_name', true) ?: null,
		'event'         => get_post_meta($id, '_ewt_event', true) ?: null,
		'code'          => get_post_meta($id, '_ewt_code', true) ?: null,
		'headcount'     => ew_ticket_headcount($id),
		'checked_in_at' => get_post_meta($id, '_ewt_in', true) ?: null,
		// A match ticket is tied to a fixture; anything else is an event ticket.
		'kind'          => (int) get_post_meta($id, '_ewt_fixture', true) > 0 ? 'match' : 'event',
	];
}

/** paid tickets and free claims are admissible; started/halftime are not. */
function ew_ticket_admissible($bstatus) {
	return in_array($bstatus, ['paid', 'claimed'], true);
}

/** What the scanner shows for a food token pass. */
function ew_ticket_food_payload($id) {
	$paid = 'paid' === get_post_meta($id, '_ewt_status', true);
	$e    = function_exists('ew_food_pass_event') ? ew_food_pass_event($id) : ['name' => '', 'date' => ''];
	$tok  = function_exists('ew_food_tokens') ? ew_food_tokens($id) : [];
	return [
		'status' => $paid ? 'food' : 'unpaid',
		'kind'   => 'food',
		// The child the tokens are for, when the parent gave one.
		'name'   => get_post_meta($id, '_ewt_child', true) ?: (get_post_meta($id, '_ewt_name', true) ?: null),
		'child'  => get_post_meta($id, '_ewt_child', true) ?: null,
		'event'  => $e['name'] ?: null,
		'date'   => $e['date'] ?: null,
		'today'  => $e['date'] === current_time('Y-m-d'),
		'code'   => get_post_meta($id, '_ewt_code', true) ?: null,
		'tokens' => array_values(array_map(function ($t) {
			return ['name' => (string) ($t['name'] ?? ''), 'n' => (int) ($t['n'] ?? 0), 'used' => (int) ($t['used'] ?? 0)];
		}, $tok)),
	];
}

function ew_ticket_secret_ok(WP_REST_Request $r) {
	$secret = get_option('ew_scan_secret', '') ?: EW_SCAN_SECRET_DEFAULT;
	return $secret && hash_equals((string) $secret, (string) $r->get_header('x_scan_secret'));
}

/** Take one token off a food pass, from the app's scanner. */
function ew_ticket_food_use(WP_REST_Request $r) {
	if (!ew_ticket_secret_ok($r)) {
		return new WP_REST_Response(['status' => 'error', 'message' => 'Not authorised'], 401);
	}
	$body = $r->get_json_params();
	$id   = ew_ticket_find_any(isset($body['ref']) ? $body['ref'] : '');
	if (!$id || 'food' !== get_post_meta($id, '_ewt_kind', true) || !function_exists('ew_food_use')) {
		return new WP_REST_Response(['status' => 'notfound'], 200);
	}
	if ('paid' !== get_post_meta($id, '_ewt_status', true)) {
		return new WP_REST_Response(ew_ticket_food_payload($id), 200);
	}
	$res = ew_food_use($id, (int) ($body['i'] ?? -1), 'App');
	$out = ew_ticket_food_payload($id);
	if (is_wp_error($res)) {
		$out['message'] = $res->get_error_message();
	}
	return new WP_REST_Response($out, 200);
}

function ew_ticket_verify(WP_REST_Request $r) {
	$any = ew_ticket_find_any($r->get_param('ref'));
	if ($any && 'food' === get_post_meta($any, '_ewt_kind', true)) {
		return new WP_REST_Response(ew_ticket_food_payload($any), 200);
	}
	$id = ew_ticket_find($r->get_param('ref'));
	if (!$id) return new WP_REST_Response(['status' => 'notfound'], 200);

	$bstatus = get_post_meta($id, '_ewt_status', true);
	if (!ew_ticket_admissible($bstatus)) {
		return new WP_REST_Response(ew_ticket_payload($id, 'unpaid'), 200);
	}
	$in = get_post_meta($id, '_ewt_in', true);
	return new WP_REST_Response(ew_ticket_payload($id, $in ? 'used' : 'valid'), 200);
}

function ew_ticket_checkin(WP_REST_Request $r) {
	$secret = get_option('ew_scan_secret', '') ?: EW_SCAN_SECRET_DEFAULT;
	$given  = $r->get_header('x_scan_secret'); // WP maps X-Scan-Secret -> x_scan_secret
	if (!$secret || !hash_equals((string) $secret, (string) $given)) {
		return new WP_REST_Response(['status' => 'error', 'message' => 'Not authorised'], 401);
	}

	$body = $r->get_json_params();
	$id = ew_ticket_find(isset($body['ref']) ? $body['ref'] : $r->get_param('ref'));
	if (!$id) return new WP_REST_Response(['status' => 'notfound'], 200);

	$bstatus = get_post_meta($id, '_ewt_status', true);
	if (!ew_ticket_admissible($bstatus)) {
		return new WP_REST_Response(ew_ticket_payload($id, 'unpaid'), 200);
	}

	$in = get_post_meta($id, '_ewt_in', true);
	if ($in) {
		return new WP_REST_Response(ew_ticket_payload($id, 'used'), 200);
	}

	$now = current_time('mysql');
	update_post_meta($id, '_ewt_in', $now);
	$payload = ew_ticket_payload($id, 'valid');
	$payload['checked_in_at'] = $now;
	return new WP_REST_Response($payload, 200);
}
