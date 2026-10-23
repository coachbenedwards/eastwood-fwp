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

/** Pull the real addresses out of a comma-or-space separated line. */
function ew_notify_parse( $raw ) {
	$out = array();
	foreach ( preg_split( '/[,;\s]+/', (string) $raw ) as $one ) {
		$one = trim( (string) $one );
		if ( '' !== $one && is_email( $one ) ) {
			$out[] = $one;
		}
	}
	return array_values( array_unique( $out ) );
}

/** The addresses told about everything. Empty array means tell nobody. */
function ew_notify_to() {
	return ew_notify_parse( get_option( 'ew_notify_to', '' ) );
}

/**
 * The things that can be routed somewhere of their own, and what each
 * is called on the settings screen.
 *
 * Finer than the statuses: a paid booking is either a shirt or a seat,
 * and the club has a different inbox for each. Everything else is one
 * status, one thing.
 *
 * @return array<string,string>
 */
function ew_notify_routes() {
	return array(
		'paid_shop'  => 'Shirt order paid',
		'paid_event' => 'Ticket paid',
		'claimed'    => 'School ticket claimed',
		'halftime'   => 'Half-time show sign-up',
		'trial'      => 'Academy trial registration',
		'community'  => 'Community ticket request',
	);
}

/**
 * Which route one booking takes.
 *
 * Shirt orders are marked when they are made. Older ones, and anything
 * the shop wrote before that mark existed, are recognised by the word
 * in the line that says what was bought.
 *
 * @param int    $id     The booking.
 * @param string $status Its status.
 * @return string
 */
function ew_notify_route_for( $id, $status ) {
	if ( 'paid' !== $status ) {
		return $status;
	}
	$kind = (string) get_post_meta( $id, '_ewt_kind', true );
	if ( 'shop' === $kind ) {
		return 'paid_shop';
	}
	$what = (string) get_post_meta( $id, '_ewt_event', true );
	return ( false !== stripos( $what, 'shirt' ) ) ? 'paid_shop' : 'paid_event';
}

/**
 * Everyone to tell about one booking.
 *
 * The main list hears about all of them -- somebody wants to watch the
 * whole thing tick over. Each route names people on top of that, so
 * trial registrations reach whoever runs the academy without the
 * academy hearing about every shirt.
 *
 * @param string $route The route key.
 * @return string[]
 */
function ew_notify_to_route( $route ) {
	$extra = ew_notify_parse( get_option( 'ew_notify_to_' . $route, '' ) );
	return array_values( array_unique( array_merge( ew_notify_to(), $extra ) ) );
}

/** Statuses worth an email, and what each one is called in it. */
function ew_notify_kinds() {
	return array(
		'paid'     => 'Paid booking',
		'claimed'  => 'School ticket claimed',
		'halftime' => 'Half-time show sign-up',
		'trial'    => 'Academy trial registration',
		'community' => 'Community ticket request',
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
	$to = ew_notify_to_route( ew_notify_route_for( $id, $status ) );
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
