<?php
/**
 * Every ticket gets its own gate (option C, 13 Nov 2026).
 *
 * For the game set under Bookings > Matchday entrances, each booking is given
 * one of the open gates, balanced by the number of people on it, so the queues
 * at Gate 1, Gate 2, Gate 3, Gate N4 and Gate N5 come out even. A booking keeps
 * its gate (a family always enters together). On the day a gate can be moved:
 * everyone whose ticket says Gate 3 is sent to Gate 2, or spread across the
 * other open gates, and the next time they open their ticket it says so.
 *
 * Gate 1 and Gate 2 are separate turnstiles at the Chewton St End. The ground
 * map labels them together as "Gate 1&2"; here they are set and counted apart.
 *
 * A gate can also have queue letters (A, B, C...). Each ticket at that gate
 * then gets one of them, shared out evenly by people, and the ticket shows
 * "Gate 3 · Queue B". The ticket only ever says where to go now, never where
 * it used to say.
 *
 * The gate is only ever shown live on the ticket (app and website), never in
 * an email or on a page, so it can move with the crowd.
 *
 *   Bookings > Matchday gates            switch on, pick open gates, move gates
 *   GET /eastwood/v1/app/entrance?id=&code=   one ticket's gate (see app-entrance.php)
 *
 * @package eastwood-fwp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The turnstiles: key => [name, end]. */
function ew_gate_list() {
	return array(
		'1'  => array( 'Gate 1', 'Chewton St End' ),
		'2'  => array( 'Gate 2', 'Chewton St End' ),
		'3'  => array( 'Gate 3', 'Coronation Park End' ),
		'N4' => array( 'Gate N4', 'North End' ),
		'N5' => array( 'Gate N5', 'North End' ),
		'H'  => array( 'Hospitality Gate', 'Chewton St End, main doors next to Gate 1' ),
	);
}

/** Gates given out by hand only (never shared out automatically). */
function ew_gate_manual_only() {
	return array( 'H' );
}

function ew_gates_cfg() {
	$s = get_option( 'ew_gates', array() );
	$s = wp_parse_args( is_array( $s ) ? $s : array(), array(
		'on'      => 0,
		'open'    => array_diff( array_keys( ew_gate_list() ), ew_gate_manual_only() ),
		'moves'   => array(),
		'updated' => 0,
		'auto'    => 1,
		'msgs'    => array(),
		'sent'    => array(),
		'queues'  => array(),
		'tills'   => array(),
		'away'    => array(),
		'away_only' => 0,
	) );
	$s['away'] = array_values( array_intersect( array_keys( ew_gate_list() ), (array) $s['away'] ) );
	$s['msgs'] = wp_parse_args( is_array( $s['msgs'] ) ? $s['msgs'] : array(), ew_gate_msg_defaults() );
	$all       = array_diff( array_keys( ew_gate_list() ), ew_gate_manual_only() );
	$s['open'] = array_values( array_intersect( $all, (array) $s['open'] ) );
	$s['moves'] = is_array( $s['moves'] ) ? $s['moves'] : array();
	$s['queues'] = is_array( $s['queues'] ) ? $s['queues'] : array();
	$s['tills']  = is_array( $s['tills'] ) ? $s['tills'] : array();
	// The gate-change wording used to name the old gate; it no longer does.
	if ( false !== strpos( (string) $s['msgs']['change_b'], '{from}' ) ) {
		$s['msgs']['change_b'] = ew_gate_msg_defaults()['change_b'];
	}
	return $s;
}

/** People on a booking: adults + children, else the quantity bought, at least 1. */
function ew_gate_heads( $id ) {
	// Told us they can't make it (and haven't come in anyway): not expected at a gate.
	if ( 'no' === (string) get_post_meta( $id, '_ewt_answer', true ) && '' === (string) get_post_meta( $id, '_ewt_in', true ) ) {
		return 0;
	}
	$n = (int) get_post_meta( $id, '_ewt_adults', true ) + (int) get_post_meta( $id, '_ewt_children', true );
	if ( $n < 1 ) {
		$n = (int) get_post_meta( $id, '_ewt_qty', true );
	}
	return max( 1, $n );
}

/** Is this booking a ticket for the game currently set? */
function ew_gate_in_game( $id ) {
	if ( ! function_exists( 'ew_ent_get' ) ) {
		return false;
	}
	$e = ew_ent_get();
	if ( '' === trim( $e['game'] ) || '' === $e['date'] || wp_date( 'Y-m-d' ) > $e['date'] ) {
		return false;
	}
	if ( false === stripos( (string) get_post_meta( $id, '_ewt_event', true ), trim( $e['game'] ) ) ) {
		return false;
	}
	return in_array( (string) get_post_meta( $id, '_ewt_status', true ), array( 'claimed', 'paid', 'community' ), true );
}

/** Every booking for the current game. */
function ew_gate_bookings() {
	$e = ew_ent_get();
	if ( '' === trim( $e['game'] ) ) {
		return array();
	}
	$ids = get_posts( array(
		'post_type'   => 'ewt_booking',
		'post_status' => 'any',
		'numberposts' => -1,
		'fields'      => 'ids',
		'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery
			array( 'key' => '_ewt_event', 'value' => trim( $e['game'] ), 'compare' => 'LIKE' ),
			array( 'key' => '_ewt_status', 'value' => array( 'claimed', 'paid', 'community' ), 'compare' => 'IN' ),
		),
	) );
	$ids = array_map( 'intval', $ids );
	if ( $ids ) {
		update_meta_cache( 'post', $ids ); // one query for every ticket's details, not three each
	}
	return $ids;
}

/** People already given each gate, for the current game. */
function ew_gate_load() {
	$load = array_fill_keys( array_keys( ew_gate_list() ), 0 );
	foreach ( ew_gate_bookings() as $id ) {
		$g = (string) get_post_meta( $id, '_ewt_gate', true );
		if ( isset( $load[ $g ] ) ) {
			$load[ $g ] += ew_gate_heads( $id );
		}
	}
	return $load;
}

/** The open gate with the fewest people on it. */
function ew_gate_pick( $load, $open ) {
	$best = '';
	foreach ( $open as $g ) {
		if ( '' === $best || $load[ $g ] < $load[ $best ] ) {
			$best = $g;
		}
	}
	return $best;
}

/** This booking's own gate, given now if it has none yet. */
function ew_gate_assign( $id, $cfg = null ) {
	$cfg  = $cfg ? $cfg : ew_gates_cfg();
	$have = (string) get_post_meta( $id, '_ewt_gate', true );
	if ( isset( ew_gate_list()[ $have ] ) ) {
		return $have;
	}
	// Away fans go to the gates set for them; with "away fans only" on, home fans are kept off those gates.
	$pool = $cfg['open'];
	if ( $cfg['away'] ) {
		if ( ew_gate_is_away( $id ) ) {
			$pool = $cfg['away'];
		} elseif ( ! empty( $cfg['away_only'] ) && array_diff( $pool, $cfg['away'] ) ) {
			$pool = array_values( array_diff( $pool, $cfg['away'] ) );
		}
	}
	if ( ! $pool ) {
		return '';
	}
	$load = get_transient( 'ew_gate_load' );
	if ( ! is_array( $load ) ) {
		$load = ew_gate_load();
	}
	$g = ew_gate_pick( $load, $pool );
	update_post_meta( $id, '_ewt_gate', $g );
	$load[ $g ] += ew_gate_heads( $id );
	set_transient( 'ew_gate_load', $load, 10 * MINUTE_IN_SECONDS );
	return $g;
}

/** A ticket bought through the away fans link. */
function ew_gate_is_away( $id ) {
	return 'away' === (string) get_post_meta( $id, '_ewt_fan', true );
}

/** Where a ticket for gate $g goes right now, after any move. */
function ew_gate_effective( $id, $g, $cfg ) {
	$to = isset( $cfg['moves'][ $g ] ) ? (string) $cfg['moves'][ $g ] : '';
	if ( '' === $to ) {
		return $g;
	}
	if ( 'spread' === $to ) {
		$others = array_values( array_diff( $cfg['open'], array( $g ) ) );
		return $others ? $others[ $id % count( $others ) ] : $g;
	}
	return isset( ew_gate_list()[ $to ] ) ? $to : $g;
}

/** The queue letters set for a gate, e.g. array( 'A', 'B', 'C' ). */
function ew_gate_queue_letters( $cfg, $g ) {
	$raw = isset( $cfg['queues'][ $g ] ) ? (string) $cfg['queues'][ $g ] : '';
	$out = array();
	foreach ( preg_split( '/[\s,]+/', strtoupper( $raw ) ) as $l ) {
		$l = preg_replace( '/[^A-Z0-9]/', '', $l );
		if ( '' !== $l && strlen( $l ) <= 3 && ! in_array( $l, $out, true ) ) {
			$out[] = $l;
		}
	}
	return $out;
}

/** People already given each queue letter, per gate: [gate][letter] => people. */
function ew_gate_queue_load( $cfg ) {
	$load = array();
	foreach ( ew_gate_bookings() as $id ) {
		$q = (string) get_post_meta( $id, '_ewt_queue', true );
		if ( false === strpos( $q, ':' ) ) {
			continue;
		}
		list( $g, $l ) = explode( ':', $q, 2 );
		if ( ! isset( $load[ $g ][ $l ] ) ) {
			$load[ $g ][ $l ] = 0;
		}
		$load[ $g ][ $l ] += ew_gate_heads( $id );
	}
	return $load;
}

/**
 * This ticket's queue letter at gate $g, given now if it has none there yet
 * (the letter with the fewest people). Empty when the gate has no queues.
 */
function ew_gate_queue( $id, $g, $cfg ) {
	$letters = ew_gate_queue_letters( $cfg, $g );
	if ( ! $letters ) {
		return '';
	}
	$have = (string) get_post_meta( $id, '_ewt_queue', true );
	if ( 0 === strpos( $have, $g . ':' ) && in_array( substr( $have, strlen( $g ) + 1 ), $letters, true ) ) {
		return substr( $have, strlen( $g ) + 1 );
	}
	$load = get_transient( 'ew_gate_qload' );
	if ( ! is_array( $load ) ) {
		$load = ew_gate_queue_load( $cfg );
	}
	$best = '';
	foreach ( $letters as $l ) {
		$n = isset( $load[ $g ][ $l ] ) ? $load[ $g ][ $l ] : 0;
		if ( '' === $best || $n < ( isset( $load[ $g ][ $best ] ) ? $load[ $g ][ $best ] : 0 ) ) {
			$best = $l;
		}
	}
	update_post_meta( $id, '_ewt_queue', $g . ':' . $best );
	$load[ $g ][ $best ] = ( isset( $load[ $g ][ $best ] ) ? $load[ $g ][ $best ] : 0 ) + ew_gate_heads( $id );
	set_transient( 'ew_gate_qload', $load, 10 * MINUTE_IN_SECONDS );
	return $best;
}

/** "Gate 3" or "Gate 3 · Queue B": how the gate reads on a ticket, the scanner and alerts. */
function ew_gate_label( $g, $queue ) {
	$names = ew_gate_list();
	return $names[ $g ][0] . ( '' !== $queue ? ' · Queue ' . $queue : '' );
}

/**
 * What one ticket shows right now: its gate, any queue letter and the end
 * it's at. Empty when gates are off or the ticket isn't for this game.
 */
function ew_gate_for_booking( $id ) {
	$cfg = ew_gates_cfg();
	if ( empty( $cfg['on'] ) || ! ew_gate_in_game( $id ) ) {
		return array();
	}
	$own = ew_gate_assign( $id, $cfg );
	if ( '' === $own ) {
		return array();
	}
	$g     = ew_gate_effective( $id, $own, $cfg );
	$names = ew_gate_list();
	$e     = ew_ent_get();
	$queue = ew_gate_queue( $id, $g, $cfg );
	return array(
		'gate'     => $g,
		'entrance' => $names[ $g ][0],
		'queue'    => $queue,
		'label'    => ew_gate_label( $g, $queue ),
		'end'      => $names[ $g ][1],
		'note'     => trim( $e['note'] ),
		'updated'  => $cfg['updated'] ? wp_date( 'g:ia', (int) $cfg['updated'] ) : '',
	);
}

/** Give every ticket for the game a gate. $fresh starts everyone again. */
function ew_gate_assign_all( $fresh = false ) {
	$cfg = ew_gates_cfg();
	$ids = ew_gate_bookings();
	if ( $fresh ) {
		foreach ( $ids as $id ) {
			delete_post_meta( $id, '_ewt_gate' );
		}
	}
	$load = ew_gate_load();
	$todo = array();
	foreach ( $ids as $id ) {
		if ( ! isset( ew_gate_list()[ (string) get_post_meta( $id, '_ewt_gate', true ) ] ) ) {
			$todo[ $id ] = ew_gate_heads( $id );
		}
	}
	arsort( $todo ); // biggest groups first keeps the gates level
	foreach ( $todo as $id => $n ) {
		$g = ew_gate_pick( $load, $cfg['open'] );
		if ( '' === $g ) {
			break;
		}
		update_post_meta( $id, '_ewt_gate', $g );
		$load[ $g ] += $n;
	}
	delete_transient( 'ew_gate_load' );
	ew_gate_queue_all( $fresh );
	return count( $todo );
}

/** Give every ticket a queue letter at the gate it's going to now. $fresh starts everyone again. */
function ew_gate_queue_all( $fresh = false ) {
	$cfg = ew_gates_cfg();
	if ( $fresh ) {
		foreach ( ew_gate_bookings() as $id ) {
			delete_post_meta( $id, '_ewt_queue' );
		}
	}
	delete_transient( 'ew_gate_qload' );
	$where = ew_gate_where_now( $cfg );
	$heads = array();
	foreach ( $where as $id => $g ) {
		$heads[ $id ] = ew_gate_heads( $id );
	}
	arsort( $heads );
	foreach ( array_keys( $heads ) as $id ) {
		ew_gate_queue( $id, $where[ $id ], $cfg );
	}
	delete_transient( 'ew_gate_qload' );
}

function ew_gates_save( $in ) {
	$cfg    = ew_gates_cfg();
	$before = ! empty( $cfg['on'] ) ? ew_gate_where_now( $cfg ) : array();
	$all = array_keys( ew_gate_list() );
	if ( isset( $in['on'] ) ) {
		$cfg['on'] = (int) ! empty( $in['on'] );
	}
	if ( isset( $in['open'] ) ) {
		$cfg['open'] = array_values( array_intersect( $all, array_map( 'strval', (array) $in['open'] ) ) );
	}
	if ( isset( $in['moves'] ) && is_array( $in['moves'] ) ) {
		$moves = array();
		foreach ( $in['moves'] as $from => $to ) {
			$from = (string) $from;
			$to   = sanitize_text_field( wp_unslash( (string) $to ) );
			if ( in_array( $from, $all, true ) && ( 'spread' === $to || ( in_array( $to, $all, true ) && $to !== $from ) ) ) {
				$moves[ $from ] = $to;
			}
		}
		$cfg['moves'] = $moves;
	}
	if ( isset( $in['auto'] ) ) {
		$cfg['auto'] = (int) ! empty( $in['auto'] );
	}
	if ( isset( $in['away'] ) ) {
		$cfg['away']      = array_values( array_intersect( array_keys( ew_gate_list() ), array_map( 'strval', (array) $in['away'] ) ) );
		$cfg['away_only'] = (int) ! empty( $in['away_only'] );
	}
	if ( isset( $in['tills'] ) && is_array( $in['tills'] ) ) {
		$t = array();
		foreach ( $in['tills'] as $g => $v ) {
			$g = (string) $g;
			if ( in_array( $g, $all, true ) ) {
				$t[ $g ] = sanitize_text_field( wp_unslash( (string) $v ) );
			}
		}
		$cfg['tills'] = $t;
	}
	if ( isset( $in['queues'] ) && is_array( $in['queues'] ) ) {
		$q = array();
		foreach ( $in['queues'] as $g => $v ) {
			$g = (string) $g;
			if ( in_array( $g, $all, true ) ) {
				$q[ $g ] = sanitize_text_field( wp_unslash( (string) $v ) );
			}
		}
		$cfg['queues'] = $q;
	}
	if ( isset( $in['msgs'] ) && is_array( $in['msgs'] ) ) {
		foreach ( array_keys( ew_gate_msg_defaults() ) as $k ) {
			if ( isset( $in['msgs'][ $k ] ) ) {
				$cfg['msgs'][ $k ] = sanitize_text_field( wp_unslash( (string) $in['msgs'][ $k ] ) );
			}
		}
	}
	$cfg['updated'] = time();
	update_option( 'ew_gates', $cfg, false );
	delete_transient( 'ew_gate_load' );
	delete_transient( 'ew_gate_qload' );
	// A gate moved: tell the people whose gate just changed, like a gate change at an airport.
	if ( $before && ! empty( $cfg['on'] ) && ! empty( $cfg['auto'] ) ) {
		$after   = ew_gate_where_now( $cfg );
		$changed = array();
		foreach ( $after as $id => $g ) {
			if ( isset( $before[ $id ] ) && $before[ $id ] !== $g ) {
				$changed[ $id ] = $before[ $id ];
			}
		}
		if ( $changed ) {
			$cfg['sent']['change'] = ew_gate_send( 'change', false, $changed );
			update_option( 'ew_gates', $cfg, false );
		}
	}
	do_action( 'ew_gates_changed', $cfg );
	return $cfg;
}

// ---------- Airport-style alerts ----------

/** The alert wording. {game} {gate} {end} are filled in per ticket ({gate} carries the queue letter when there is one). */
function ew_gate_msg_defaults() {
	return array(
		'announce_t' => 'Your gate: {gate}',
		'announce_b' => '{game}: use {gate} at the {end}. Your ticket shows it.',
		'open_t'     => 'Gates are open',
		'open_b'     => 'Head to {gate}, {end}. Have your ticket open.',
		'final_t'    => 'Kick-off soon',
		'final_b'    => 'Last call for {gate}, {end}. Head in now.',
		'change_t'   => 'Gate change: now {gate}',
		'change_b'   => 'Your gate has changed. Use {gate}, {end}.',
	);
}

/** Each ticket for the game and the gate it says right now. */
function ew_gate_where_now( $cfg ) {
	$out = array();
	foreach ( ew_gate_bookings() as $id ) {
		$own = (string) get_post_meta( $id, '_ewt_gate', true );
		if ( isset( ew_gate_list()[ $own ] ) && '' === (string) get_post_meta( $id, '_ewt_in', true ) ) {
			$out[ $id ] = ew_gate_effective( $id, $own, $cfg );
		}
	}
	return $out;
}

/**
 * Send one alert to ticket holders: 'announce', 'open', 'final' to everyone
 * with a gate, or 'change' to the bookings in $only (id => old gate). Goes to
 * the phones signed in to the app with the booking's email.
 */
function ew_gate_send( $kind, $testers = false, $only = null ) {
	$cfg   = ew_gates_cfg();
	if ( empty( $cfg['on'] ) ) {
		return array( 'at' => time(), 'people' => 0, 'sent' => 0, 'testers' => (int) $testers, 'error' => 'Turn on "Give every ticket its own gate" first' );
	}
	$names = ew_gate_list();
	$e     = ew_ent_get();
	$m     = $cfg['msgs'];
	$where = ew_gate_where_now( $cfg );
	$msgs  = array();
	foreach ( $where as $id => $g ) {
		if ( null !== $only && ! isset( $only[ $id ] ) ) {
			continue;
		}
		$email = strtolower( trim( (string) get_post_meta( $id, '_ewt_email', true ) ) );
		if ( ! is_email( $email ) || isset( $msgs[ $email ] ) ) {
			continue;
		}
		$fill = array(
			'{game}' => trim( $e['game'] ),
			'{gate}' => ew_gate_label( $g, ew_gate_queue( $id, $g, $cfg ) ),
			'{end}'  => $names[ $g ][1],
			'{from}' => null !== $only && isset( $names[ $only[ $id ] ] ) ? $names[ $only[ $id ] ][0] : '',
		);
		$msgs[ $email ] = array(
			'email' => $email,
			'title' => strtr( (string) $m[ $kind . '_t' ], $fill ),
			'body'  => strtr( (string) $m[ $kind . '_b' ], $fill ),
		);
	}
	$res = array( 'at' => time(), 'people' => count( $msgs ), 'sent' => 0, 'testers' => (int) $testers );
	if ( ! $msgs || ! function_exists( 'ew_runs_key' ) || '' === ew_runs_key() ) {
		$res['error'] = $msgs ? 'No Supabase key saved' : '';
		return $res;
	}
	$r = wp_remote_post( EW_FAN_SUPABASE . '/functions/v1/gate-push', array(
		'timeout' => 60,
		'headers' => array( 'Authorization' => 'Bearer ' . ew_runs_key(), 'Content-Type' => 'application/json' ),
		'body'    => wp_json_encode( array( 'kind' => 'gate_' . $kind, 'testers' => (bool) $testers, 'messages' => array_values( $msgs ) ) ),
	) );
	if ( is_wp_error( $r ) ) {
		$res['error'] = $r->get_error_message();
		return $res;
	}
	$b = json_decode( (string) wp_remote_retrieve_body( $r ), true );
	$res['sent']    = (int) ( $b['sent'] ?? 0 );
	$res['devices'] = (int) ( $b['devices'] ?? 0 );
	if ( ! empty( $b['error'] ) ) {
		$res['error'] = (string) $b['error'];
	}
	return $res;
}

// ---------- Bookings > Matchday gates ----------

add_action( 'admin_menu', function () {
	add_submenu_page( 'edit.php?post_type=ewt_booking', 'Matchday gates', 'Matchday gates', 'edit_pages', 'ew-gates', 'ew_gates_page' );
} );

function ew_gates_page() {
	if ( ! current_user_can( 'edit_pages' ) ) {
		return;
	}
	$msg = '';
	if ( isset( $_POST['ew_gates_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['ew_gates_nonce'] ), 'ew_gates' ) ) {
		// phpcs:disable WordPress.Security.NonceVerification
		$in = array(
			'on'    => ! empty( $_POST['on'] ),
			'open'  => isset( $_POST['open'] ) ? (array) wp_unslash( $_POST['open'] ) : array(),
			'moves' => isset( $_POST['moves'] ) ? (array) wp_unslash( $_POST['moves'] ) : array(),
			'auto'  => ! empty( $_POST['auto'] ),
			'away'  => isset( $_POST['away'] ) ? (array) wp_unslash( $_POST['away'] ) : array(),
			'away_only' => ! empty( $_POST['away_only'] ),
			'queues' => isset( $_POST['queues'] ) ? (array) wp_unslash( $_POST['queues'] ) : array(),
			'tills'  => isset( $_POST['tills'] ) ? (array) wp_unslash( $_POST['tills'] ) : array(),
			'msgs'  => isset( $_POST['msgs'] ) ? (array) wp_unslash( $_POST['msgs'] ) : array(),
		);
		// phpcs:enable
		ew_gates_save( $in );
		$msg = 'Saved. The next time someone opens their ticket, it shows this.';
		$send = isset( $_POST['send'] ) ? sanitize_key( $_POST['send'] ) : '';
		if ( in_array( $send, array( 'announce', 'open', 'final' ), true ) ) {
			$r   = ew_gate_send( $send, ! empty( $_POST['testers'] ) );
			$cfg = ew_gates_cfg();
			$cfg['sent'][ $send ] = $r;
			update_option( 'ew_gates', $cfg, false );
			$msg = isset( $r['error'] ) && $r['error'] ? 'Not sent: ' . $r['error'] : 'Sent to ' . $r['sent'] . ' phones (' . $r['people'] . ' ticket holders with a gate' . ( $r['testers'] ? ', testers only' : '' ) . ').';
		}
		if ( isset( $_POST['assign'] ) && 'queues' === $_POST['assign'] ) {
			ew_gate_queue_all( true );
			$msg = 'Every ticket was given a queue letter again, evenly.';
		} elseif ( ! empty( $_POST['assign'] ) ) {
			$n   = ew_gate_assign_all( 'fresh' === $_POST['assign'] );
			$msg = 'fresh' === $_POST['assign'] ? 'Every ticket was given a gate again, evenly (' . $n . ' tickets).' : 'Gave a gate to ' . $n . ' tickets that had none.';
		}
	}
	$cfg   = ew_gates_cfg();
	$e     = ew_ent_get();
	$names = ew_gate_list();
	$own   = array_fill_keys( array_keys( $names ), array( 0, 0 ) );
	$now   = array_fill_keys( array_keys( $names ), 0 );
	$none  = 0;
	foreach ( ew_gate_bookings() as $id ) {
		$g = (string) get_post_meta( $id, '_ewt_gate', true );
		$n = ew_gate_heads( $id );
		if ( ! isset( $names[ $g ] ) ) {
			$none += $n;
			continue;
		}
		$own[ $g ][0]++;
		$own[ $g ][1] += $n;
		$now[ ew_gate_effective( $id, $g, $cfg ) ] += $n;
	}
	echo '<div class="wrap"><h1>Matchday gates</h1>';
	echo '<p>Every ticket for <strong>' . esc_html( $e['game'] ? $e['game'] : '(set the game under Matchday entrances)' ) . '</strong> gets its own gate, shared out evenly by the number of people. The gate shows live on the ticket in the app and on the website, never in emails.</p>';
	if ( $msg ) {
		echo '<div class="notice notice-success"><p>' . esc_html( $msg ) . '</p></div>';
	}
	echo '<form method="post">';
	wp_nonce_field( 'ew_gates', 'ew_gates_nonce' );
	echo '<p><label><input type="checkbox" name="on" value="1"' . checked( ! empty( $cfg['on'] ), true, false ) . '> <strong>Give every ticket its own gate</strong></label></p>';
	echo '<table class="widefat striped" style="max-width:960px"><thead><tr><th>Gate</th><th>Takes new tickets</th><th>Away fans</th><th>Tickets</th><th>People given this gate</th><th>Send these people to</th><th>People coming here now</th><th>Queue letters</th><th>Square till here</th></tr></thead><tbody>';
	foreach ( $names as $k => $nm ) {
		$sel = isset( $cfg['moves'][ $k ] ) ? $cfg['moves'][ $k ] : '';
		echo '<tr><td><strong>' . esc_html( $nm[0] ) . '</strong><br><span class="description">' . esc_html( $nm[1] ) . '</span></td>';
		echo '<td><input type="checkbox" name="open[]" value="' . esc_attr( $k ) . '"' . checked( in_array( $k, $cfg['open'], true ), true, false ) . '></td>';
		echo '<td><input type="checkbox" name="away[]" value="' . esc_attr( $k ) . '"' . checked( in_array( $k, $cfg['away'], true ), true, false ) . ' aria-label="Away fans use ' . esc_attr( $nm[0] ) . '"></td>';
		echo '<td>' . (int) $own[ $k ][0] . '</td><td>' . (int) $own[ $k ][1] . '</td>';
		echo '<td><select name="moves[' . esc_attr( $k ) . ']"><option value="">Keep at ' . esc_html( $nm[0] ) . '</option>';
		foreach ( $names as $k2 => $nm2 ) {
			if ( $k2 !== $k ) {
				echo '<option value="' . esc_attr( $k2 ) . '"' . selected( $sel, $k2, false ) . '>' . esc_html( $nm2[0] ) . '</option>';
			}
		}
		echo '<option value="spread"' . selected( $sel, 'spread', false ) . '>Spread across the other open gates</option></select></td>';
		echo '<td><strong>' . (int) $now[ $k ] . '</strong></td>';
		echo '<td><input type="text" size="8" name="queues[' . esc_attr( $k ) . ']" value="' . esc_attr( isset( $cfg['queues'][ $k ] ) ? $cfg['queues'][ $k ] : '' ) . '" placeholder="A, B, C"></td>';
		echo '<td><input type="text" size="18" name="tills[' . esc_attr( $k ) . ']" value="' . esc_attr( isset( $cfg['tills'][ $k ] ) ? $cfg['tills'][ $k ] : '' ) . '" placeholder="Square Handheld 4587"></td></tr>';
	}
	echo '</tbody></table>';
	echo '<p><label><input type="checkbox" name="away_only" value="1"' . checked( ! empty( $cfg['away_only'] ), true, false ) . '> Keep the away fans gates for away fans only</label> <span class="description">Away fans are tickets bought through the away fans link (the tickets link with ?fan=away). No away gate ticked: they get a gate like everyone else. Change it any time, then press "Share out every ticket again" to move tickets already given a gate.</span></p>';
	if ( $none ) {
		echo '<p>' . (int) $none . ' people on tickets without a gate yet. They get one the moment they open their ticket, or give them one now.</p>';
	}
	echo '<p><button class="button button-primary" type="submit">Save</button> ';
	echo '<button class="button" type="submit" name="assign" value="missing">Save and give a gate to every ticket without one</button> ';
	echo '<button class="button" type="submit" name="assign" value="queues">Share out the queue letters again</button> ';
	echo '<button class="button" type="submit" name="assign" value="fresh" onclick="return confirm(\'Give every ticket a gate again from scratch? People who already looked will see a different gate.\')">Share out every ticket again</button></p>';
	echo '<p class="description">Updated ' . esc_html( $cfg['updated'] ? wp_date( 'D j M, g:ia', (int) $cfg['updated'] ) : 'never' ) . '. Moving a gate doesn\'t change the ticket\'s own gate, so setting it back to "Keep" undoes the move. Queue letters: leave blank for no queues; tickets show "Gate 3 · Queue B" when set. Square till here: the till\'s name as Square shows it; people paying on the gate at that till are counted at that gate.</p>';
	echo '<h2>Alerts to ticket holders</h2>';
	echo '<p>Like gate calls at an airport. Each person gets their own gate in the alert. They go to phones signed in to the Eastwood FC app with the email the ticket was booked with. {gate} (with the queue letter when there is one), {end} and {game} are filled in for each person.</p>';
	echo '<p><label><input type="checkbox" name="auto" value="1"' . checked( ! empty( $cfg['auto'] ), true, false ) . '> Send a gate-change alert automatically when a gate is moved</label></p>';
	$labels = array( 'announce' => 'Gate announced', 'open' => 'Gates open', 'final' => 'Final call', 'change' => 'Gate change (automatic)' );
	echo '<table class="form-table">';
	foreach ( $labels as $k => $lab ) {
		$last = isset( $cfg['sent'][ $k ]['at'] ) ? 'Last sent ' . wp_date( 'D j M, g:ia', (int) $cfg['sent'][ $k ]['at'] ) . ' to ' . (int) $cfg['sent'][ $k ]['sent'] . ' phones' . ( ! empty( $cfg['sent'][ $k ]['testers'] ) ? ' (testers)' : '' ) : 'Not sent yet';
		echo '<tr><th>' . esc_html( $lab ) . '</th><td>';
		echo '<input class="regular-text" name="msgs[' . esc_attr( $k ) . '_t]" value="' . esc_attr( $cfg['msgs'][ $k . '_t' ] ) . '"><br>';
		echo '<input class="large-text" name="msgs[' . esc_attr( $k ) . '_b]" value="' . esc_attr( $cfg['msgs'][ $k . '_b' ] ) . '">';
		if ( 'change' !== $k ) {
			echo '<br><button class="button" type="submit" name="send" value="' . esc_attr( $k ) . '" onclick="return confirm(\'Send the ' . esc_attr( $lab ) . ' alert now?\')">Send ' . esc_html( $lab ) . ' now</button>';
		}
		echo '<p class="description">' . esc_html( $last ) . '</p></td></tr>';
	}
	echo '</table>';
	echo '<p><label><input type="checkbox" name="testers" value="1"> Send to testers only (phones marked as testers)</label></p>';
	echo '</form></div>';
}

// ---------- REST for the staff app ----------

/**
 * A wp-admin user who can edit pages, or the staff app's gates function
 * (Supabase, which checks the person may manage gates) with the service key.
 */
function ew_gates_rest_ok( $req ) {
	if ( current_user_can( 'edit_pages' ) ) {
		return true;
	}
	$given = (string) $req->get_header( 'x_ew_key' );
	if ( strlen( $given ) < 30 ) {
		return false;
	}
	$key = function_exists( 'ew_runs_key' ) ? (string) ew_runs_key() : '';
	if ( '' !== $key && hash_equals( $key, $given ) ) {
		return true;
	}
	// Otherwise it must be a Supabase key that can read the service-only table.
	$t = 'ew_gates_key_' . md5( $given );
	if ( get_transient( $t ) ) {
		return true;
	}
	if ( ! defined( 'EW_FAN_SUPABASE' ) ) {
		return false;
	}
	$r = wp_remote_get( EW_FAN_SUPABASE . '/rest/v1/app_secrets?select=name&limit=1', array(
		'timeout' => 10,
		'headers' => array( 'apikey' => $given, 'Authorization' => 'Bearer ' . $given ),
	) );
	if ( ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r ) ) {
		set_transient( $t, 1, 10 * MINUTE_IN_SECONDS );
		return true;
	}
	return false;
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'eastwood/v1', '/gates', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => 'ew_gates_rest_ok',
			'callback'            => function () {
				$cfg    = ew_gates_cfg();
				$coming = array_fill_keys( array_keys( ew_gate_list() ), 0 );
				if ( ! empty( $cfg['on'] ) ) {
					foreach ( ew_gate_where_now( $cfg ) as $id => $g ) {
						$coming[ $g ] += ew_gate_heads( $id );
					}
				}
				return array( 'cfg' => $cfg, 'load' => ew_gate_load(), 'gates' => ew_gate_list(), 'coming' => $coming );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => 'ew_gates_rest_ok',
			'callback'            => function ( $req ) {
				$in  = (array) $req->get_json_params();
				$cfg = ew_gates_save( $in );
				$n   = 0;
				if ( ! empty( $in['assign'] ) ) {
					$n = ew_gate_assign_all( 'fresh' === $in['assign'] );
				}
				return array( 'cfg' => $cfg, 'assigned' => $n, 'load' => ew_gate_load() );
			},
		),
	) );
} );

// ---------- Pay on the gate (Square tills) ----------

/** Admission items on the Square tills: name => 'adult' or 'child'. Anything else on a sale is ignored. */
function ew_gate_till_items() {
	return array(
		'Eastwood · Adult'                      => 'adult',
		'Eastwood · Concession'                 => 'adult',
		'Eastwood · Season ticket'              => 'adult',
		'Eastwood · Under 16'                   => 'child',
		'Eastwood · Under 16 with paying adult' => 'child',
		'Away · Adult'                          => 'adult',
		'Away · Concession'                     => 'adult',
		'Away · Under 16'                       => 'child',
		'Away · Child with adult'               => 'child',
	);
}

/**
 * A sale on a Square till that includes admission: count those people in at
 * the gate that till is set against (or as "a gate till" when none is set),
 * and when Square has the payer's email, make them a paid ticket already
 * marked as in, so they're a customer with the ticket in their app.
 */
add_action( 'ew_square_paid', function ( $acct, $p ) {
	if ( empty( $p['order_id'] ) || ! function_exists( 'ew_sqt_get' ) ) {
		return;
	}
	$o = (array) ( ew_sqt_get( $acct, 'orders/' . rawurlencode( (string) $p['order_id'] ) )['order'] ?? array() );
	$kinds = ew_gate_till_items();
	$adults = 0;
	$children = 0;
	$lines = array();
	foreach ( (array) ( $o['line_items'] ?? array() ) as $li ) {
		$name = trim( (string) ( $li['name'] ?? '' ) );
		$q    = (int) round( (float) ( $li['quantity'] ?? 0 ) );
		if ( $q < 1 || ! isset( $kinds[ $name ] ) ) {
			continue;
		}
		if ( 'child' === $kinds[ $name ] ) {
			$children += $q;
		} else {
			$adults += $q;
		}
		$lines[] = $q . ' × ' . $name;
	}
	$heads = $adults + $children;
	if ( $heads < 1 ) {
		return;
	}
	$device = trim( (string) ( $p['device_details']['device_name'] ?? '' ) );
	$cfg    = ew_gates_cfg();
	$gate   = 'till';
	foreach ( (array) $cfg['tills'] as $g => $names ) {
		foreach ( preg_split( '/\s*,\s*/', (string) $names ) as $n ) {
			if ( '' !== $n && '' !== $device && 0 === strcasecmp( $n, $device ) ) {
				$gate = (string) $g;
				break 2;
			}
		}
	}
	if ( function_exists( 'ew_runs_sb' ) ) {
		ew_runs_sb( 'POST', '/rest/v1/gate_admissions', array(
			'gate'  => $gate,
			'heads' => min( 50, $heads ),
			'kind'  => 'gate_pay',
			'ref'   => (string) $p['order_id'],
		), array( 'Prefer' => 'return=minimal' ) );
	}
	$email = function_exists( 'ew_sqt_email' ) ? strtolower( trim( (string) ew_sqt_email( $acct, $p ) ) ) : '';
	if ( ! is_email( $email ) ) {
		return;
	}
	$name = '';
	if ( ! empty( $p['customer_id'] ) ) {
		$c    = (array) ( ew_sqt_get( $acct, 'customers/' . rawurlencode( (string) $p['customer_id'] ), HOUR_IN_SECONDS )['customer'] ?? array() );
		$name = trim( (string) ( $c['given_name'] ?? '' ) . ' ' . (string) ( $c['family_name'] ?? '' ) );
	}
	do {
		$code = 'EFC-' . wp_rand( 1000, 9999 );
	} while ( get_posts( array( 'post_type' => 'ewt_booking', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_ewt_code', 'meta_value' => $code ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	$id = wp_insert_post( array( 'post_type' => 'ewt_booking', 'post_status' => 'publish', 'post_title' => '' !== $name ? $name : $email ) );
	if ( ! $id || is_wp_error( $id ) ) {
		return;
	}
	$e = function_exists( 'ew_ent_get' ) ? ew_ent_get() : array( 'game' => '' );
	foreach ( array(
		'name'     => $name,
		'email'    => $email,
		'event'    => trim( (string) $e['game'] ),
		'lines'    => implode( ', ', $lines ),
		'adults'   => $adults,
		'children' => $children,
		'total'    => (int) ( $p['total_money']['amount'] ?? 0 ) / 100,
		'status'   => 'paid',
		'code'     => $code,
		'key'      => wp_generate_password( 12, false ),
		'source'   => 'gate_till',
		'square'   => (string) $p['order_id'],
		'gate'     => 'till' === $gate ? '' : $gate,
		'in'       => current_time( 'mysql' ),
	) as $k => $v ) {
		update_post_meta( $id, '_ewt_' . $k, $v );
	}
}, 10, 2 );

/**
 * A booking's own gate, set by hand on the booking screen, e.g. a family put
 * on the Hospitality Gate. "Automatic" leaves it to be shared out.
 */
add_action( 'add_meta_boxes_ewt_booking', function () {
	add_meta_box( 'ewt_gate_box', 'Matchday gate', function ( $post ) {
		wp_nonce_field( 'ewt_gate_box', 'ewt_gate_box_nonce' );
		$have = (string) get_post_meta( $post->ID, '_ewt_gate', true );
		echo '<select name="ewt_gate" style="width:100%"><option value="">Automatic</option>';
		foreach ( ew_gate_list() as $k => $nm ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $have, (string) $k, false ) . '>' . esc_html( $nm[0] . ' · ' . $nm[1] ) . '</option>';
		}
		echo '</select><p class="description">Shows live on their ticket in the app and on the website.</p>';
	}, 'ewt_booking', 'side', 'high' );
} );

add_action( 'save_post_ewt_booking', function ( $id ) {
	if ( ! isset( $_POST['ewt_gate_box_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ewt_gate_box_nonce'] ) ), 'ewt_gate_box' ) || ! current_user_can( 'edit_post', $id ) ) {
		return;
	}
	$g = isset( $_POST['ewt_gate'] ) ? sanitize_text_field( wp_unslash( $_POST['ewt_gate'] ) ) : '';
	if ( '' === $g ) {
		delete_post_meta( $id, '_ewt_gate' );
	} elseif ( isset( ew_gate_list()[ $g ] ) ) {
		update_post_meta( $id, '_ewt_gate', $g );
	}
	delete_transient( 'ew_gate_load' );
} );
