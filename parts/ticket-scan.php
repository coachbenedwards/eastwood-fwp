<?php
/**
 * Gate ticket scanning — endpoints for the Eastwood FC app's "Scan tickets".
 *
 * Auto-loaded from parts/. Two routes under eastwood/v1:
 *   GET  /wp-json/eastwood/v1/ticket/verify?ref=<EFC-code or _ewt_key>
 *        Public read. Looks a ticket up; does NOT mark it.
 *   POST /wp-json/eastwood/v1/ticket/checkin   { "ref": "<...>" }
 *        Header  Authorization: Bearer <the steward's app sign-in>
 *        Marks _ewt_in once (admits the ticket).
 *   POST /wp-json/eastwood/v1/ticket/food-use   { "ref": "<...>", "i": <token> }
 *        Same sign-in. Takes one token off a food token pass.
 *
 * A food token pass is not a way through the turnstile, so check-in never
 * admits one. Verify still recognises it and answers status "food" with
 * the tokens left, so the same scanner can serve the food counter.
 *
 * Reuses the existing ewt_booking records (paid tickets + free claims).
 *
 * Every write needs a signed-in staff member with Scan tickets. There is no
 * shared secret any more.
 */


add_action('rest_api_init', function () {
	register_rest_route('eastwood/v1', '/ticket/verify', [
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => 'ew_ticket_verify',
	]);
	register_rest_route('eastwood/v1', '/ticket/checkin', [
		'methods'             => 'POST',
		'permission_callback' => '__return_true', // sign-in checked inside
		'callback'            => 'ew_ticket_checkin',
	]);
	register_rest_route('eastwood/v1', '/ticket/gate-claim', [
		'methods'             => 'POST',
		'permission_callback' => '__return_true', // sign-in checked inside
		'callback'            => 'ew_ticket_gate_claim',
	]);
	register_rest_route('eastwood/v1', '/ticket/food-use', [
		'methods'             => 'POST',
		'permission_callback' => '__return_true', // sign-in checked inside
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
		if (!empty($q->posts)) return ew_booking_resolve((int) $q->posts[0]);
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
		'paid'          => 'paid' === get_post_meta($id, '_ewt_status', true),
		// The gate this ticket was told to use, when every ticket has its own.
		'test'          => function_exists('ew_is_test_ticket') && ew_is_test_ticket($id),
		'gate'          => function_exists('ew_gate_for_booking') ? ((ew_gate_for_booking($id))['label'] ?? null) : null,
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
		'kids'   => ew_booking_kids($id),
		'event'  => $e['name'] ?: null,
		'date'   => $e['date'] ?: null,
		'today'  => $e['date'] === current_time('Y-m-d'),
		'code'   => get_post_meta($id, '_ewt_code', true) ?: null,
		'tokens' => array_values(array_map(function ($t) {
			return ['name' => (string) ($t['name'] ?? ''), 'n' => (int) ($t['n'] ?? 0), 'used' => (int) ($t['used'] ?? 0)];
		}, $tok)),
	];
}

/** A signed-in staff member with Scan tickets, checked against the app's database with their own sign-in. */
function ew_scan_staff_ok(WP_REST_Request $r) {
	if (!defined('EW_FAN_SUPABASE') || !defined('EW_FAN_ANON')) return false;
	$auth = (string) $r->get_header('authorization');
	if (0 !== stripos($auth, 'bearer ')) return false;
	$tok = trim(substr($auth, 7));
	if ('' === $tok || strlen($tok) > 4096) return false;
	$ck  = 'ew_scan_ok_' . md5($tok);
	$hit = get_transient($ck);
	if (false !== $hit) return '1' === $hit;
	$res = wp_remote_post(EW_FAN_SUPABASE . '/rest/v1/rpc/has_staff_permission', [
		'timeout' => 5,
		'headers' => ['apikey' => EW_FAN_ANON, 'Authorization' => 'Bearer ' . $tok, 'Content-Type' => 'application/json'],
		'body'    => wp_json_encode(['p_key' => 'scan']),
	]);
	if (is_wp_error($res)) return false;
	$code = (int) wp_remote_retrieve_response_code($res);
	$ok   = 200 === $code && 'true' === trim((string) wp_remote_retrieve_body($res));
	if (200 === $code || 401 === $code || 403 === $code) {
		set_transient($ck, $ok ? '1' : '0', $ok ? 5 * MINUTE_IN_SECONDS : MINUTE_IN_SECONDS);
	}
	return $ok;
}

function ew_ticket_staff_ok(WP_REST_Request $r) {
	return ew_scan_staff_ok($r);
}

/** Take one token off a food pass, from the app's scanner. */
function ew_ticket_food_use(WP_REST_Request $r) {
	if (!ew_ticket_staff_ok($r)) {
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
	$by  = wp_get_current_user();
	$res = ew_food_use(
		$id,
		(int) ($body['i'] ?? -1),
		$by && $by->exists() ? $by->display_name : 'App',
		mb_substr(sanitize_text_field((string) ($body['child'] ?? '')), 0, 60)
	);
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
	// A staff test ticket is never used up, so the gate can be tried again and again.
	$in = function_exists('ew_is_test_ticket') && ew_is_test_ticket($id) ? '' : get_post_meta($id, '_ewt_in', true);
	return new WP_REST_Response(ew_ticket_payload($id, $in ? 'used' : 'valid'), 200);
}

/**
 * Is the ground full? The staff app's "at capacity" switch, or live
 * attendance (people scanned in today) at the capacity. Checked fresh at most
 * every 10 seconds. If it can't be checked, the gate stays open.
 */
function ew_md_state() {
	$hit = get_transient('ew_md_state');
	if (is_array($hit)) return $hit;
	$out = array('closed' => false, 'inside' => 0, 'capacity' => 0);
	if (defined('EW_FAN_SUPABASE') && defined('EW_FAN_ANON')) {
		$res = wp_remote_post(EW_FAN_SUPABASE . '/rest/v1/rpc/matchday_closed', array(
			'timeout' => 4,
			'headers' => array('apikey' => EW_FAN_ANON, 'Authorization' => 'Bearer ' . EW_FAN_ANON, 'Content-Type' => 'application/json'),
			'body'    => '{}',
		));
		$j = is_wp_error($res) ? null : json_decode(wp_remote_retrieve_body($res), true);
		if (is_array($j) && isset($j['closed'])) $out = array('closed' => (bool) $j['closed'], 'inside' => (int) ($j['inside'] ?? 0), 'capacity' => (int) ($j['capacity'] ?? 0));
	}
	set_transient('ew_md_state', $out, 10);
	return $out;
}

function ew_md_full_reply() {
	return new WP_REST_Response(['status' => 'error', 'message' => (string) get_option('ew_md_full_msg', "AT CAPACITY. Don't let anyone else in. Ask the gate manager.")], 200);
}

/** Live attendance alerts to Ben: once at 3,000 inside and once at capacity. */
function ew_md_alerts() {
	$st = ew_md_state();
	if (!$st['inside']) return;
	$day  = wp_date('Y-m-d');
	$sent = (array) get_option('ew_md_alerted', array());
	foreach (array_unique(array_filter(array(3000, $st['capacity']))) as $mark) {
		$k = $day . '|' . $mark;
		if ($st['inside'] >= $mark && empty($sent[$k])) {
			$sent[$k] = time();
			update_option('ew_md_alerted', $sent, false);
			if (function_exists('ew_ou_tell_ben')) {
				$full = $mark === $st['capacity'];
				ew_ou_tell_ben(($full ? 'AT CAPACITY: ' : 'Live attendance: ') . number_format($st['inside']) . ' inside', number_format($st['inside']) . ' people are inside Coronation Park (capacity ' . number_format($st['capacity']) . ').' . ($full ? ' The turnstile scanners are now refusing entry.' : ''));
			}
		}
	}
}

/**
 * Count someone in on the day's attendance the moment their ticket is marked
 * in, whichever app version the steward has (15 Nov 2026: a phone without a
 * gate set let 110 tickets in uncounted). The gate is the one the phone sends,
 * or 'unset'; a phone that also records the ticket fills in the gate instead
 * of counting it twice (merge_gate_admission in Supabase).
 */
function ew_ticket_count_in($id, $kind, $gate = '') {
	$key = function_exists('ew_runs_key') ? ew_runs_key() : '';
	if ('' === $key || !defined('EW_FAN_SUPABASE')) return;
	$gate = in_array((string) $gate, ['1', '2', '3', 'N4', 'N5'], true) ? (string) $gate : 'unset';
	$code = (string) get_post_meta($id, '_ewt_code', true);
	// A short wait at most, so a slow database never holds up the turnstile.
	wp_remote_post(EW_FAN_SUPABASE . '/rest/v1/gate_admissions', [
		'timeout' => 3,
		'headers' => ['apikey' => $key, 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json', 'Prefer' => 'return=minimal'],
		'body'    => wp_json_encode([
			'gate'  => $gate,
			'heads' => max(1, min(50, (int) ew_ticket_headcount($id))),
			'kind'  => $kind,
			'ref'   => '' !== $code ? $code : null,
		]),
	]);
}

function ew_ticket_checkin(WP_REST_Request $r) {
	if (!ew_ticket_staff_ok($r)) {
		return new WP_REST_Response(['status' => 'error', 'message' => 'Not authorised'], 401);
	}

	$body = $r->get_json_params();
	$id = ew_ticket_find(isset($body['ref']) ? $body['ref'] : $r->get_param('ref'));
	if (!$id) return new WP_REST_Response(['status' => 'notfound'], 200);

	$bstatus = get_post_meta($id, '_ewt_status', true);
	if (!ew_ticket_admissible($bstatus)) {
		return new WP_REST_Response(ew_ticket_payload($id, 'unpaid'), 200);
	}

	$test = function_exists('ew_is_test_ticket') && ew_is_test_ticket($id);
	$in = $test ? '' : get_post_meta($id, '_ewt_in', true);
	if ($in) {
		return new WP_REST_Response(ew_ticket_payload($id, 'used'), 200);
	}

	if (ew_md_state()['closed']) {
		return ew_md_full_reply();
	}

	$now = current_time('mysql');
	if (!$test) {
		update_post_meta($id, '_ewt_in', $now);
		ew_ticket_count_in($id, 'paid' === $bstatus ? 'paid' : 'free', (string) ($body['gate'] ?? ''));
	}
	ew_md_alerts();
	$payload = ew_ticket_payload($id, 'valid');
	$payload['checked_in_at'] = $now;
	return new WP_REST_Response($payload, 200);
}

/**
 * A paper flyer at the gate (its QR only opens eastwoodfc.com/claimticket):
 * the steward types their name, email and how many, and this makes the
 * ticket exactly as the claim page would (same game, same email to them),
 * marks it as in, and answers with the ticket.
 */
function ew_ticket_gate_claim(WP_REST_Request $r) {
	if (!ew_ticket_staff_ok($r)) {
		return new WP_REST_Response(['status' => 'error', 'message' => 'Not authorised'], 401);
	}
	if (ew_md_state()['closed']) {
		return ew_md_full_reply();
	}
	$body     = (array) $r->get_json_params();
	$name     = function_exists('ew_person_name') ? ew_person_name(sanitize_text_field((string) ($body['name'] ?? ''))) : sanitize_text_field((string) ($body['name'] ?? ''));
	$email    = sanitize_email((string) ($body['email'] ?? ''));
	$adults   = max(0, min(20, (int) ($body['adults'] ?? 0)));
	$children = max(0, min(20, (int) ($body['children'] ?? 0)));
	if ('' === trim($name)) {
		return new WP_REST_Response(['status' => 'error', 'message' => 'Add their name.'], 400);
	}
	if (!is_email($email)) {
		return new WP_REST_Response(['status' => 'error', 'message' => 'That email does not look right.'], 400);
	}
	if ($adults + $children < 1) {
		return new WP_REST_Response(['status' => 'error', 'message' => 'Add at least one person.'], 400);
	}
	$page  = get_page_by_path('claimticket');
	$block = ($page && function_exists('ew_claim_block_on')) ? ew_claim_block_on($page) : null;
	if (!$block) {
		return new WP_REST_Response(['status' => 'error', 'message' => 'The claim page is not set up.'], 500);
	}
	$a = $block['atts'];
	do {
		$code = 'EFC-' . wp_rand(1000, 9999);
	} while (get_posts(['post_type' => 'ewt_booking', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_ewt_code', 'meta_value' => $code])); // phpcs:ignore WordPress.DB.SlowDBQuery
	$id = wp_insert_post(['post_type' => 'ewt_booking', 'post_status' => 'publish', 'post_title' => $name]);
	if (!$id || is_wp_error($id)) {
		return new WP_REST_Response(['status' => 'error', 'message' => 'Could not make the ticket.'], 500);
	}
	$now = current_time('mysql');
	foreach ([
		'name'     => $name,
		'email'    => $email,
		'event'    => $a['event'] ?? '',
		'page'     => $page->ID,
		'url'      => get_permalink($page),
		'lines'    => function_exists('ew_claim_party') ? ew_claim_party($a, $adults, $children) : '',
		'extra'    => 'Paper ticket at the gate',
		'school'   => 'Paper ticket at the gate',
		'adults'   => $adults,
		'children' => $children,
		'total'    => 0,
		'status'   => 'claimed',
		'consent'  => 'no',
		'code'     => $code,
		'key'      => wp_generate_password(12, false),
		'hook'     => (string) ($a['crm_hook'] ?? ''),
		'source'   => 'gate',
		'in'       => $now,
	] as $k => $v) {
		update_post_meta($id, '_ewt_' . $k, $v);
	}
	if (function_exists('ew_tix_crm') && !empty($a['crm_hook'])) {
		ew_tix_crm((string) $a['crm_hook'], $id, 'claimed');
	}
	ew_ticket_count_in($id, 'paper', (string) ($body['gate'] ?? ''));
	$out = ew_ticket_payload($id, 'valid');
	$out['checked_in_at'] = $now;
	return new WP_REST_Response($out, 200);
}
