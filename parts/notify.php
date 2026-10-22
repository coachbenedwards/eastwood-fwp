<?php
/**
 * Tell somebody when a booking arrives.
 *
 * Every ticket, shirt order, school ticket claim, half-time sign-up and
 * Academy trial registration becomes a Booking. Until now they landed in the
 * Bookings list and nothing else happened, so nobody knew unless they went
 * looking. This sends a plain email the moment one is real.
 *
 * It listens for the booking status being written rather than hooking each
 * flow, so a flow added later is covered without being remembered. Only the
 * statuses that mean something has actually happened are sent -- a checkout
 * somebody started and abandoned is not news.
 *
 * Who gets told is a setting: Settings -> Eastwood FWP -> Who gets told.
 *
 * @package eastwood-fwp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The addresses to tell, as an array. Empty array means tell nobody. */
function ew_notify_to() {
	$raw = (string) get_option( 'ew_notify_to', '' );
	$out = array();
	foreach ( preg_split( '/[,;\s]+/', $raw ) as $one ) {
		$one = trim( (string) $one );
		if ( '' !== $one && is_email( $one ) ) {
			$out[] = $one;
		}
	}
	return array_values( array_unique( $out ) );
}

/** Statuses worth an email, and what each one is called in it. */
function ew_notify_kinds() {
	return array(
		'paid'     => 'Paid booking',
		'claimed'  => 'School ticket claimed',
		'halftime' => 'Half-time show sign-up',
		'trial'    => 'Academy trial registration',
	);
}

/** One booking, written out as plain lines. */
function ew_notify_body( $id, $kind ) {
	$m = function ( $k ) use ( $id ) {
		return trim( (string) get_post_meta( $id, '_ewt_' . $k, true ) );
	};
	$lines = array( $kind, '' );
	$pairs = array(
		'What'     => $m( 'event' ),
		'Name'     => $m( 'name' ),
		'Email'    => $m( 'email' ),
		'Mobile'   => $m( 'phone' ),
		'Details'  => $m( 'lines' ),
		'Where they live' => $m( 'loc' ),
		'School'   => $m( 'school' ),
		'Parent or guardian' => $m( 'guardian' ),
		'Adults'   => $m( 'adults' ),
		'Children' => $m( 'children' ),
		'Code'     => $m( 'code' ),
	);
	$paid = (int) $m( 'paid' );
	if ( $paid > 0 ) {
		$pairs['Paid'] = '&pound;' . number_format( $paid / 100, 2 );
	}
	if ( '' !== $m( 'consent' ) ) {
		$pairs['Happy to hear from us'] = $m( 'consent' );
	}
	foreach ( $pairs as $label => $value ) {
		if ( '' !== (string) $value && '0' !== (string) $value ) {
			$lines[] = $label . ': ' . html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' );
		}
	}
	$url = $m( 'url' );
	if ( '' !== $url ) {
		$lines[] = '';
		$lines[] = 'Page: ' . $url;
	}
	$lines[] = '';
	$lines[] = 'All of them: ' . admin_url( 'edit.php?post_type=ewt_booking' );
	$lines[] = 'This one: ' . admin_url( 'post.php?post=' . (int) $id . '&action=edit' );
	return implode( "\n", $lines );
}

/** Send it, once per booking. */
function ew_notify_booking( $id ) {
	$id = (int) $id;
	if ( 'ewt_booking' !== get_post_type( $id ) ) {
		return;
	}
	$status = (string) get_post_meta( $id, '_ewt_status', true );
	$kinds  = ew_notify_kinds();
	if ( ! isset( $kinds[ $status ] ) ) {
		return;
	}
	// Once only, however many times the status is written.
	if ( get_post_meta( $id, '_ewt_notified', true ) ) {
		return;
	}
	$to = ew_notify_to();
	if ( ! $to ) {
		return;
	}
	update_post_meta( $id, '_ewt_notified', time() );

	$event   = trim( (string) get_post_meta( $id, '_ewt_event', true ) );
	$name    = trim( (string) get_post_meta( $id, '_ewt_name', true ) );
	$subject = $kinds[ $status ] . ( '' !== $name ? ': ' . $name : '' ) . ( '' !== $event ? ' -- ' . $event : '' );

	wp_mail(
		$to,
		wp_specialchars_decode( $subject, ENT_QUOTES ),
		ew_notify_body( $id, $kinds[ $status ] ),
		array( 'From: Eastwood FC <info@eastwoodfc.com>' )
	);
}

/**
 * The booking ids seen this request, sent once everything is written.
 *
 * The status is saved partway through the list of fields, so sending at that
 * moment would post an email missing whatever is written after it -- the
 * turnstile code on a claim, the consent tick on a registration. Queueing to
 * the end of the request means the email carries the whole booking.
 */
function ew_notify_queue( $id = 0 ) {
	static $queued = array();
	if ( $id ) {
		$queued[ (int) $id ] = true;
	}
	return array_keys( $queued );
}

/*
 * The status meta is written once for a claim, a sign-up or a registration,
 * and written again when a checkout turns into a payment, so both the first
 * write and a later change are listened for.
 */
add_action( 'added_post_meta', function ( $mid, $id, $key ) {
	if ( '_ewt_status' === $key ) {
		ew_notify_queue( $id );
	}
}, 10, 3 );

add_action( 'updated_post_meta', function ( $mid, $id, $key ) {
	if ( '_ewt_status' === $key ) {
		ew_notify_queue( $id );
	}
}, 10, 3 );

add_action( 'shutdown', function () {
	foreach ( ew_notify_queue() as $id ) {
		ew_notify_booking( $id );
	}
} );
