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
 *
 * Reuses the existing ewt_booking records (paid tickets + free claims).
 *
 * Set the scan secret once (must equal the app build's EXPO_PUBLIC_SCAN_SECRET):
 *   wp option update ew_scan_secret '02be2f0e444a7321a1b8dbf8da19087ad3122112ef8dc42a'
 */

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
});

/** Find a booking by its short code (_ewt_code) or opaque key (_ewt_key). */
function ew_ticket_find($ref) {
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
	];
}

/** paid tickets and free claims are admissible; started/halftime are not. */
function ew_ticket_admissible($bstatus) {
	return in_array($bstatus, ['paid', 'claimed'], true);
}

function ew_ticket_verify(WP_REST_Request $r) {
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
	$secret = get_option('ew_scan_secret', '');
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
