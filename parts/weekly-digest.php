<?php
/**
 * The Monday email: one overview of everything the website took last week.
 *
 * Tickets and shirts paid for, school tickets claimed, half-time sign-ups,
 * community requests, and the Academy trials, questions, interest and 1:1s.
 * Each heading says how many and, where money changed hands, how much; under
 * it one line per booking. The kinds and their names are the same ones the
 * instant alerts use (notify.php), so the two can never disagree.
 *
 * Who gets it: Bookings -> Weekly email. Sent Mondays at 8am, covering the
 * seven days before. The same screen can send it now, to you or the list.
 *
 * @package eastwood-fwp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ew_digest_to() {
	$raw = get_option( 'ew_digest_to', null );
	if ( null === $raw ) {
		$raw = 'emily@eastwoodfc.com';
	}
	return function_exists( 'ew_notify_parse' ) ? ew_notify_parse( $raw ) : array();
}

/**
 * The email for the seven days ending at $end.
 *
 * @return array( subject, body, count )
 */
function ew_digest_build( $end = 0 ) {
	$tz    = wp_timezone();
	$end   = $end ? (int) $end : time();
	$start = $end - 7 * DAY_IN_SECONDS;
	$kinds = function_exists( 'ew_notify_kinds' ) ? ew_notify_kinds() : array();
	$names = function_exists( 'ew_notify_routes' ) ? ew_notify_routes() : array();

	$q = new WP_Query( array(
		'post_type'      => 'ewt_booking',
		'post_status'    => 'publish',
		'posts_per_page' => 2000,
		'orderby'        => 'date',
		'order'          => 'ASC',
		'no_found_rows'  => true,
		'date_query'     => array( array(
			'after'     => wp_date( 'Y-m-d H:i:s', $start, $tz ),
			'before'    => wp_date( 'Y-m-d H:i:s', $end, $tz ),
			'inclusive' => true,
		) ),
	) );

	$groups = array();
	foreach ( array_keys( $names ) as $r ) {
		$groups[ $r ] = array( 'n' => 0, 'pence' => 0, 'lines' => array() );
	}
	$count = 0;
	foreach ( $q->posts as $p ) {
		$m = function ( $k ) use ( $p ) {
			return trim( html_entity_decode( (string) get_post_meta( $p->ID, '_ewt_' . $k, true ), ENT_QUOTES, 'UTF-8' ) );
		};
		$status = $m( 'status' );
		if ( ! isset( $kinds[ $status ] ) ) {
			continue; // a checkout started and left is not news
		}
		$route = function_exists( 'ew_notify_route_for' ) ? ew_notify_route_for( $p->ID, $status ) : $status;
		if ( ! isset( $groups[ $route ] ) ) {
			$groups[ $route ] = array( 'n' => 0, 'pence' => 0, 'lines' => array() );
			$names[ $route ]  = $kinds[ $status ];
		}
		$pence = (int) $m( 'paid' );
		$bits  = array_filter( array(
			wp_date( 'D j M', strtotime( $p->post_date_gmt . ' UTC' ), $tz ),
			'' !== $m( 'name' ) ? $m( 'name' ) : get_the_title( $p ),
			$m( 'event' ),
			$m( 'lines' ),
			$pence > 0 ? html_entity_decode( '&pound;', ENT_QUOTES, 'UTF-8' ) . number_format( $pence / 100, 2 ) : '',
		) );
		$groups[ $route ]['n']++;
		$groups[ $route ]['pence']  += $pence;
		$groups[ $route ]['lines'][] = '- ' . implode( ' / ', $bits );
		$count++;
	}

	$pound = html_entity_decode( '&pound;', ENT_QUOTES, 'UTF-8' );
	$from  = wp_date( 'D j M', $start, $tz );
	$to    = wp_date( 'D j M', $end - 1, $tz );
	$total = 0;
	$out   = array( 'Eastwood FC website: ' . $from . ' to ' . $to, '' );
	$sum   = array();
	$body  = array();
	foreach ( $groups as $r => $g ) {
		if ( ! $g['n'] ) {
			continue;
		}
		$total += $g['pence'];
		$head   = $names[ $r ] . ': ' . $g['n'] . ( $g['pence'] > 0 ? ' (' . $pound . number_format( $g['pence'] / 100, 2 ) . ')' : '' );
		$sum[]  = $head;
		$body[] = '';
		$body[] = strtoupper( $head );
		foreach ( $g['lines'] as $l ) {
			$body[] = $l;
		}
	}
	if ( ! $count ) {
		$out[] = 'Nothing came in through the website this week.';
	} else {
		$out[] = 'Taken: ' . $pound . number_format( $total / 100, 2 );
		foreach ( $sum as $s ) {
			$out[] = $s;
		}
		foreach ( $body as $b ) {
			$out[] = $b;
		}
	}
	$out[] = '';
	$out[] = 'All bookings: ' . admin_url( 'edit.php?post_type=ewt_booking' );

	$subject = 'Website week: ' . $count . ' in, ' . $pound . number_format( $total / 100, 2 ) . ' taken (' . $from . ' to ' . $to . ')';
	return array( $subject, implode( "\n", $out ), $count );
}

function ew_digest_send( $to = null ) {
	$to = null === $to ? ew_digest_to() : (array) $to;
	if ( ! $to ) {
		return false;
	}
	list( $subject, $body ) = ew_digest_build();
	return wp_mail( $to, $subject, $body, array( 'From: Eastwood FC <info@eastwoodfc.com>' ) );
}

/* Mondays, 8am club time. */
add_action( 'ew_digest_weekly', function () {
	ew_digest_send();
} );

add_action( 'init', function () {
	if ( wp_next_scheduled( 'ew_digest_weekly' ) ) {
		return;
	}
	$next = new DateTime( 'next monday 08:00', wp_timezone() );
	wp_schedule_event( $next->getTimestamp(), 'weekly', 'ew_digest_weekly' );
} );

/* Bookings -> Weekly email */
add_action( 'admin_menu', function () {
	add_submenu_page( 'edit.php?post_type=ewt_booking', 'Weekly email', 'Weekly email', 'edit_posts', 'ew-digest', 'ew_digest_page' );
} );

function ew_digest_page() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}
	$note = '';
	if ( isset( $_POST['ew_digest_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ew_digest_nonce'] ) ), 'ew_digest' ) ) {
		update_option( 'ew_digest_to', sanitize_text_field( wp_unslash( (string) ( $_POST['ew_digest_to'] ?? '' ) ) ), false );
		if ( isset( $_POST['ew_digest_me'] ) ) {
			$me   = wp_get_current_user()->user_email;
			$note = ew_digest_send( array( $me ) ) ? 'Sent to ' . $me . '.' : 'It did not send.';
		} elseif ( isset( $_POST['ew_digest_all'] ) ) {
			$note = ew_digest_send() ? 'Sent to ' . implode( ', ', ew_digest_to() ) . '.' : 'It did not send.';
		} else {
			$note = 'Saved.';
		}
	}
	$raw  = get_option( 'ew_digest_to', null );
	$raw  = null === $raw ? 'emily@eastwoodfc.com' : $raw;
	$next = wp_next_scheduled( 'ew_digest_weekly' );
	list( $subject, $body ) = ew_digest_build();

	echo '<div class="wrap"><h1>Weekly email</h1>';
	if ( '' !== $note ) {
		echo '<div class="notice notice-success"><p>' . esc_html( $note ) . '</p></div>';
	}
	echo '<p>Every Monday at 8am: everything the website took in the seven days before. Tickets, shirts, school claims, half-time, community requests and the Academy.</p>';
	echo '<p>Next one: ' . esc_html( $next ? wp_date( 'l j F, g:ia', $next ) : 'not scheduled' ) . '</p>';
	echo '<form method="post">';
	wp_nonce_field( 'ew_digest', 'ew_digest_nonce' );
	echo '<p><label for="ew_digest_to"><strong>Who gets it</strong> (several separated by commas)</label><br>';
	echo '<input type="text" class="regular-text" id="ew_digest_to" name="ew_digest_to" value="' . esc_attr( $raw ) . '"></p>';
	echo '<p><button class="button button-primary">Save</button> ';
	echo '<button class="button" name="ew_digest_me" value="1">Save and send this week to me now</button> ';
	echo '<button class="button" name="ew_digest_all" value="1">Save and send this week to the list now</button></p>';
	echo '</form>';
	echo '<h2>What it would say today</h2>';
	echo '<p><strong>' . esc_html( $subject ) . '</strong></p>';
	echo '<pre style="white-space:pre-wrap;background:#fff;border:1px solid #ccd0d4;padding:16px;max-width:900px">' . esc_html( $body ) . '</pre>';
	echo '</div>';
}
