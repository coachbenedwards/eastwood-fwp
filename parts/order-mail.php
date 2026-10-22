<?php
/**
 * Order emails sent by the website itself, through Google as orders@.
 *
 * The confirmation and the one-hour reminder for shirt orders and event
 * tickets used to be sent by the GoHighLevel workflows. They now go from
 * the website, so they reach the main inbox; GoHighLevel still hears about
 * every step (contacts, tags, pipeline) and keeps the marketing emails.
 *
 * Every word of each email is a setting on the Eastwood FWP screen, not a
 * string in the code. The defaults below are the wording the GoHighLevel
 * emails had. Nothing is sent until "Send order emails from the website"
 * is ticked, so the switch-over can be made once GoHighLevel's own email
 * steps are taken out -- otherwise a buyer would get both.
 *
 * Placeholders: {first_name} {greeting} {event} {tickets} {paid} {total}
 * {event_url}
 *
 * @package eastwood-fwp
 */

defined( 'ABSPATH' ) || exit;

/** The four emails: which kind of order, which step, and the default words. */
function ew_om_emails() {
	return array(
		'shop_paid'      => array(
			'label'   => 'Shirt order – paid',
			'subject' => 'Thanks for your order',
			'body'    => "Hi {first_name},\n\nThanks for your order.\n\n{tickets}\nTotal paid: £{paid}\n\nYour receipt comes separately from Stripe. We'll be in touch about getting it to you.\n\nAny questions, just reply to this email.\n\nEastwood FC",
		),
		'shop_reminder'  => array(
			'label'   => 'Shirt order – not finished (an hour later)',
			'subject' => 'Your order is still waiting',
			'body'    => "{greeting},\n\nYou started an order but didn't quite finish.\n\nIt's still here if you want it:\n{event_url}\n\nAny questions, just reply to this email.\n\nEastwood FC",
		),
		'event_paid'     => array(
			'label'   => 'Event tickets – paid',
			'subject' => "You're in: {event}",
			'body'    => "Hi {first_name},\n\nYou're booked for {event}.\n\n{tickets}\nTotal paid: £{paid}\n\nYour receipt comes separately from Stripe. On the night, just give your name at the door.\n\nAny questions, just reply to this email.\n\nSee you there,\nEastwood FC",
		),
		'event_reminder' => array(
			'label'   => 'Event tickets – not finished (an hour later)',
			'subject' => 'Your {event} tickets are still waiting',
			'body'    => "{greeting},\n\nYou started booking {event} but didn't quite finish.\n\nYour tickets are still here if you want them:\n{event_url}\n\nAny questions, just reply to this email.\n\nEastwood FC",
		),
	);
}

function ew_om_on() {
	return '1' === (string) get_option( 'ew_om_on', '' ) && function_exists( 'ew_smtp_ready' ) && ew_smtp_ready();
}

function ew_om_text( $key, $part ) {
	$all = ew_om_emails();
	$v   = get_option( 'ew_om_' . $key . '_' . $part, null );
	return null === $v ? (string) $all[ $key ][ $part ] : (string) $v;
}

/** Shirt orders are marked when they start; older ones are known by name. */
function ew_om_kind( $booking_id ) {
	$k = (string) get_post_meta( $booking_id, '_ewt_kind', true );
	if ( '' !== $k ) {
		return $k;
	}
	return false !== stripos( (string) get_post_meta( $booking_id, '_ewt_event', true ), 'shirt' ) ? 'shop' : 'event';
}

/** Fill the placeholders from the booking. */
function ew_om_fill( $text, $booking_id ) {
	$m     = function ( $k ) use ( $booking_id ) {
		return (string) get_post_meta( $booking_id, '_ewt_' . $k, true );
	};
	$name  = trim( $m( 'name' ) );
	$first = false === strpos( $name, ' ' ) ? $name : substr( $name, 0, strpos( $name, ' ' ) );
	$map   = array(
		'{first_name}' => $first,
		'{greeting}'   => '' !== $m( 'greeting' ) ? $m( 'greeting' ) : 'Hi Eastwood Fan',
		'{event}'      => $m( 'event' ),
		'{tickets}'    => $m( 'lines' ),
		'{paid}'       => number_format( (int) $m( 'paid' ) / 100, 2, '.', '' ),
		'{total}'      => number_format( (int) $m( 'total' ) / 100, 2, '.', '' ),
		'{event_url}'  => $m( 'url' ),
	);
	$out = strtr( (string) $text, $map );
	// "Hi ," when nobody gave a name reads as a mistake; "Hi," does not.
	return preg_replace( '/ +,/', ',', $out );
}

/** Called for every step the CRM is told about. */
add_action( 'ew_tix_step', function ( $booking_id, $step ) {
	if ( ! in_array( $step, array( 'paid', 'reminder' ), true ) || ! ew_om_on() ) {
		return;
	}
	$to = (string) get_post_meta( $booking_id, '_ewt_email', true );
	if ( ! is_email( $to ) ) {
		return;
	}
	$key  = ew_om_kind( $booking_id ) . '_' . $step;
	$sent = '_ewt_mailed_' . $step;
	if ( ! array_key_exists( $key, ew_om_emails() ) || get_post_meta( $booking_id, $sent, true ) ) {
		return;
	}
	$ok = wp_mail( $to, ew_om_fill( ew_om_text( $key, 'subject' ), $booking_id ), ew_om_fill( ew_om_text( $key, 'body' ), $booking_id ) );
	update_post_meta( $booking_id, $sent, $ok ? time() : 'failed' );
}, 10, 2 );

/** The settings: the switch, and the words of each email. */
add_action( 'ew_fwp_after_settings', function () {
	if ( isset( $_POST['ew_om_save'] ) && check_admin_referer( 'ew_om_save' ) ) {
		update_option( 'ew_om_on', empty( $_POST['ew_om_on'] ) ? '' : '1' );
		foreach ( array_keys( ew_om_emails() ) as $key ) {
			update_option( 'ew_om_' . $key . '_subject', sanitize_text_field( wp_unslash( $_POST[ 'ew_om_' . $key . '_subject' ] ?? '' ) ) );
			update_option( 'ew_om_' . $key . '_body', sanitize_textarea_field( wp_unslash( $_POST[ 'ew_om_' . $key . '_body' ] ?? '' ) ) );
		}
		echo '<div class="notice notice-success"><p>Order emails saved.</p></div>';
	}
	echo '<h2>Order emails</h2>';
	echo '<p>The emails a buyer gets after paying, and an hour after starting checkout without paying. They go from the website through Google. '
		. 'Placeholders: {first_name} {greeting} {event} {tickets} {paid} {total} {event_url}.</p>';
	echo '<form method="post">';
	wp_nonce_field( 'ew_om_save' );
	echo '<input type="hidden" name="ew_om_save" value="1">';
	echo '<p><label><input type="checkbox" name="ew_om_on" value="1"' . checked( '1', (string) get_option( 'ew_om_on', '' ), false ) . '> Send order emails from the website</label></p>';
	foreach ( ew_om_emails() as $key => $e ) {
		echo '<h3>' . esc_html( $e['label'] ) . '</h3>';
		echo '<p><label>Subject<br><input type="text" class="large-text" name="ew_om_' . esc_attr( $key ) . '_subject" value="' . esc_attr( ew_om_text( $key, 'subject' ) ) . '"></label></p>';
		echo '<p><label>Email<br><textarea class="large-text" rows="10" name="ew_om_' . esc_attr( $key ) . '_body">' . esc_textarea( ew_om_text( $key, 'body' ) ) . '</textarea></label></p>';
	}
	submit_button( 'Save order emails' );
	echo '</form>';
}, 30 );
