<?php
/**
 * Bookings -> Email bookers: one email to everyone who booked or claimed for an event.
 *
 * Staff pick an event, write a subject and a message ({first_name} is filled
 * in for each person), see exactly who it will go to, untick anyone, and send.
 * Each person gets their own email from a confirmed send-as address (orders@
 * by default). Club addresses are left off, each email address gets one copy,
 * and someone already sent the same send is never sent it twice.
 *
 * @package eastwood-fwp
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
	add_submenu_page( 'edit.php?post_type=ewt_booking', 'Email bookers', 'Email bookers', 'edit_posts', 'ew-email-bookers', 'ew_eb_page' );
}, 22 );

/** Addresses the site may send as: orders@ plus any confirmed extras. */
function ew_eb_froms() {
	$list = array( ew_smtp_from() );
	foreach ( preg_split( '/[\s,]+/', (string) get_option( 'ew_smtp_aliases', '' ) ) as $a ) {
		$a = sanitize_email( $a );
		if ( is_email( $a ) && ! in_array( $a, $list, true ) ) {
			$list[] = $a;
		}
	}
	return $list;
}

/** Events that have bookings, newest first: [event => count]. */
function ew_eb_events() {
	global $wpdb;
	$rows = $wpdb->get_results( "SELECT pm.meta_value AS ev, COUNT(*) AS n, MAX(p.post_date) AS d FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = 'ewt_booking' AND p.post_status <> 'trash' AND pm.meta_key = '_ewt_event' GROUP BY pm.meta_value ORDER BY d DESC" );
	$out = array();
	foreach ( (array) $rows as $r ) {
		$out[ (string) $r->ev ] = (int) $r->n;
	}
	return $out;
}

/** One row per email address for an event, claimed or paid only, club addresses left off. */
function ew_eb_people( $event ) {
	$q   = new WP_Query( array(
		'post_type'      => 'ewt_booking',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'orderby'        => 'date',
		'order'          => 'ASC',
		'meta_query'     => array( array( 'key' => '_ewt_event', 'value' => $event ) ),
		'fields'         => 'ids',
	) );
	$out = array();
	foreach ( $q->posts as $id ) {
		$st = (string) get_post_meta( $id, '_ewt_status', true );
		if ( ! in_array( $st, array( 'claimed', 'paid' ), true ) ) {
			continue;
		}
		$em = strtolower( trim( (string) get_post_meta( $id, '_ewt_email', true ) ) );
		if ( ! is_email( $em ) || preg_match( '/@(eastwoodfc\.com|benedwards\.com)$/', $em ) ) {
			continue;
		}
		if ( ! isset( $out[ $em ] ) ) {
			$out[ $em ] = array( 'name' => trim( (string) get_post_meta( $id, '_ewt_name', true ) ), 'ids' => array() );
		}
		$out[ $em ]['ids'][] = $id;
	}
	return $out;
}

function ew_eb_first( $name ) {
	$f = trim( strtok( (string) $name, ' ' ) );
	return '' === $f ? 'there' : ucfirst( strtolower( $f ) );
}

function ew_eb_page() {
	$froms  = ew_eb_froms();
	$events = ew_eb_events();
	$event  = sanitize_text_field( wp_unslash( $_POST['event'] ?? '' ) );
	$from   = sanitize_email( wp_unslash( $_POST['from'] ?? $froms[0] ) );
	$subj   = sanitize_text_field( wp_unslash( $_POST['subject'] ?? '' ) );
	$body   = sanitize_textarea_field( wp_unslash( $_POST['body'] ?? '' ) );
	$step   = sanitize_key( $_POST['step'] ?? '' );
	if ( ! in_array( $from, $froms, true ) ) {
		$from = $froms[0];
	}

	echo '<div class="wrap"><h1>Email bookers</h1>';

	if ( 'send' === $step && check_admin_referer( 'ew_eb_send' ) && $event && $subj && $body ) {
		$people = ew_eb_people( $event );
		$pick   = array_map( 'strtolower', (array) wp_unslash( $_POST['to'] ?? array() ) );
		$key    = '_ewt_eb_' . substr( md5( $event . '|' . $subj ), 0, 10 );
		$sent   = 0;
		$skip   = 0;
		$fail   = array();
		foreach ( $people as $em => $p ) {
			if ( ! in_array( $em, $pick, true ) ) {
				continue;
			}
			if ( get_post_meta( $p['ids'][0], $key, true ) ) {
				$skip++;
				continue;
			}
			$text = str_replace( '{first_name}', ew_eb_first( $p['name'] ), $body );
			$ok   = wp_mail( $em, $subj, $text, array( 'From: Eastwood FC <' . $from . '>' ) );
			if ( $ok ) {
				update_post_meta( $p['ids'][0], $key, time() );
				$sent++;
			} else {
				$fail[] = $em;
			}
		}
		echo '<div class="notice notice-' . ( $fail ? 'warning' : 'success' ) . '"><p>Sent ' . (int) $sent . ' from ' . esc_html( $from ) . '.'
			. ( $skip ? ' ' . (int) $skip . ' already had this email, so were skipped.' : '' )
			. ( $fail ? ' Could not send to: ' . esc_html( implode( ', ', $fail ) ) . '.' : '' ) . '</p></div>';
		$step = '';
	}

	echo '<form method="post">';
	echo '<table class="form-table"><tr><th>Event</th><td><select name="event">';
	foreach ( $events as $ev => $n ) {
		echo '<option value="' . esc_attr( $ev ) . '"' . selected( $ev, $event, false ) . '>' . esc_html( $ev ) . '</option>';
	}
	echo '</select></td></tr>';
	echo '<tr><th>From</th><td><select name="from">';
	foreach ( $froms as $f ) {
		echo '<option' . selected( $f, $from, false ) . '>' . esc_html( $f ) . '</option>';
	}
	echo '</select></td></tr>';
	echo '<tr><th>Subject</th><td><input type="text" name="subject" class="large-text" value="' . esc_attr( $subj ) . '"></td></tr>';
	echo '<tr><th>Message</th><td><textarea name="body" rows="14" class="large-text">' . esc_textarea( $body ) . '</textarea><p class="description">{first_name} is replaced with each person\'s first name.</p></td></tr></table>';

	if ( 'preview' === $step && $event ) {
		wp_nonce_field( 'ew_eb_send' );
		$people = ew_eb_people( $event );
		$key    = '_ewt_eb_' . substr( md5( $event . '|' . $subj ), 0, 10 );
		echo '<h2>Who it goes to (' . count( $people ) . ')</h2><p>Untick anyone who should not get it.</p><table class="widefat striped"><thead><tr><th></th><th>Name</th><th>Email</th><th>Sent already</th></tr></thead><tbody>';
		foreach ( $people as $em => $p ) {
			$done = get_post_meta( $p['ids'][0], $key, true );
			echo '<tr><td><input type="checkbox" name="to[]" value="' . esc_attr( $em ) . '"' . ( $done ? '' : ' checked' ) . '></td><td>' . esc_html( $p['name'] ) . '</td><td>' . esc_html( $em ) . '</td><td>' . ( $done ? esc_html( wp_date( 'j M, g:ia', (int) $done ) ) : '' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<input type="hidden" name="step" value="send">';
		submit_button( 'Send to the ticked people' );
	} else {
		echo '<input type="hidden" name="step" value="preview">';
		submit_button( 'Show who it goes to' );
	}
	echo '</form></div>';
}
