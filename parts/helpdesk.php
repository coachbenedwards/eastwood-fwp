<?php
/**
 * Help desk: find anyone's booking fast and send a ready-made reply from a
 * club address. Bookings -> Help desk. Replies come into the Google Groups
 * as normal; this is where staff answer them.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ew_hd_templates_default() {
	return "Your reference (we write first) | Your Eastwood FC ticket reference | Hi {first_name},\n\nYour booking for today's game is {ref} for {party}.\n\nTo see your ticket in the Eastwood FC app, sign in (or sign up) with the email you booked with: {email}. Your ticket then shows in the app.\n\nIf it doesn't show, add it in the app using your email {email} and the reference {ref}.\n\nIf you have any trouble at the gate, give the steward your reference {ref} and they can find you.\n\nSee you later,\nEastwood FC\n"
		. "---\nTicket not showing in the app | Your Eastwood FC ticket | Hi {first_name},\n\nThanks for getting in touch. Your booking is {ref} for {party}.\n\nTo see your ticket in the Eastwood FC app, sign in (or sign up) with the email you booked with: {email}. Your ticket then shows in the app.\n\nIf it still doesn't show, add it in the app using your email {email} and the reference {ref}.\n\nIf you have any trouble at the gate, give the steward your reference {ref} and they can find you.\n\nSee you later,\nEastwood FC\n"
		. "---\nReference not recognised | Your Eastwood FC booking {ref} | Hi {first_name},\n\nSorry about that. Your reference is {ref} and it's booked under {email}. In the app, enter that email exactly, with the reference {ref}.\n\nThe easiest way is to sign in to the Eastwood FC app with {email}, and your ticket shows by itself.\n\nIf you have any trouble at the gate, give the steward your reference {ref}.\n\nSee you later,\nEastwood FC\n"
		. "---\nGetting here and parking | Getting to Coronation Park today | Hi {first_name},\n\nCoronation Park, Chewton Street, Eastwood, NG16 3HB. Gates open at 1pm and kick-off is 3pm.\n\nThere is no spectator parking at the ground, so bus or train is best if you can. Accessible parking must be booked with the club before you come.\n\nAll the travel info is at eastwoodfc.com/matchday\n\nSee you later,\nEastwood FC\n"
		. "---\nCan I still get tickets | Tickets for today | Hi {first_name},\n\nYes, you can get tickets here: eastwoodfc.com/tickets/?fx=585791\n\nYour ticket goes into the Eastwood FC app. We're expecting a big crowd, so please arrive early.\n\nSee you later,\nEastwood FC\n"
		. "---\nPayment not completed | Your Eastwood FC booking | Hi {first_name},\n\nThanks for getting in touch. We can see your booking was started but the payment didn't complete, so there isn't a ticket on it yet.\n\nYou can buy your ticket here: eastwoodfc.com/tickets/?fx=585791\n\nSee you later,\nEastwood FC\n"
		. "---\nAway fans | Away fan tickets for today | Hi {first_name},\n\nAway fans can get tickets here: eastwoodfc.com/tickets/?fan=away&fx=585791\n\nGates open at 1pm and kick-off is 3pm. Travel info is at eastwoodfc.com/matchday\n\nSee you later,\nEastwood FC";
}

/** title => [subject, body] */
function ew_hd_templates() {
	$raw = (string) get_option( 'ew_hd_templates', ew_hd_templates_default() );
	$out = array();
	foreach ( preg_split( '/\R---\R/', $raw ) as $block ) {
		$parts = explode( '|', $block, 3 );
		if ( 3 === count( $parts ) ) {
			$out[ trim( $parts[0] ) ] = array( trim( $parts[1] ), ltrim( $parts[2], " \t" ) );
		}
	}
	return $out;
}

/**
 * A first name to greet someone by, or "there". Titles are skipped (Mr Adrian
 * Rice is Adrian), and a name that looks wrong (an email, one long run of
 * letters like "AndrewFletcher", initials) gets "there" rather than a guess.
 */
function ew_hd_first( $name ) {
	$name  = trim( preg_replace( '/\s+/', ' ', (string) $name ) );
	$words = array_values( array_filter( explode( ' ', str_replace( '.', ' ', $name ) ) ) );
	while ( $words && in_array( strtolower( $words[0] ), array( 'mr', 'mrs', 'ms', 'miss', 'mx', 'dr', 'sir', 'rev' ), true ) ) {
		array_shift( $words );
	}
	$f = $words ? $words[0] : '';
	if ( '' === $f || false !== strpos( $name, '@' ) || strlen( $f ) < 2 || ! preg_match( "/^[\\p{L}'-]+$/u", $f ) ) {
		return 'there';
	}
	// One word with a capital in the middle, or a single long word: two names typed together.
	if ( 1 === count( $words ) && ( preg_match( '/\p{Ll}\p{Lu}/u', $f ) || mb_strlen( $f ) > 12 ) ) {
		return 'there';
	}
	return mb_strtoupper( mb_substr( $f, 0, 1 ) ) . mb_strtolower( mb_substr( $f, 1 ) );
}

function ew_hd_fill( $text, $bid ) {
	$name = (string) get_post_meta( $bid, '_ewt_name', true );
	return strtr( $text, array(
		'{first_name}' => ew_hd_first( $name ),
		'{name}'       => $name,
		'{ref}'        => function_exists( 'ew_booking_code' ) ? ew_booking_code( $bid ) : '',
		'{email}'      => (string) get_post_meta( $bid, '_ewt_email', true ),
		'{party}'      => (string) get_post_meta( $bid, '_ewt_lines', true ),
		'{game}'       => (string) get_post_meta( $bid, '_ewt_event', true ),
	) );
}

function ew_hd_find( $q ) {
	$q = trim( $q );
	if ( strlen( $q ) < 3 ) {
		return array();
	}
	$code = strtoupper( $q );
	$args = array( 'post_type' => 'ewt_booking', 'post_status' => 'any', 'numberposts' => 25, 'fields' => 'ids' );
	if ( preg_match( '/^EFC-?\d+$/', $code ) ) {
		$args['meta_query'] = array( array( 'key' => '_ewt_code', 'value' => 'EFC-' . preg_replace( '/\D/', '', $code ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	} else {
		$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
			'relation' => 'OR',
			array( 'key' => '_ewt_email', 'value' => $q, 'compare' => 'LIKE' ),
			array( 'key' => '_ewt_name', 'value' => $q, 'compare' => 'LIKE' ),
		);
	}
	return get_posts( $args );
}

add_action( 'admin_menu', function () {
	add_submenu_page( 'edit.php?post_type=ewt_booking', 'Help desk', 'Help desk', 'edit_posts', 'ew-helpdesk', 'ew_hd_page' );
}, 22 );

function ew_hd_page() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}
	$froms = function_exists( 'ew_eb_froms' ) ? ew_eb_froms() : array( get_option( 'admin_email' ) );
	echo '<div class="wrap"><h1>Help desk</h1>';

	if ( isset( $_POST['ew_hd_send'] ) && check_admin_referer( 'ew_hd' ) ) {
		$to   = sanitize_email( wp_unslash( $_POST['to'] ?? '' ) );
		$from = sanitize_email( wp_unslash( $_POST['from'] ?? '' ) );
		$subj = sanitize_text_field( wp_unslash( $_POST['subject'] ?? '' ) );
		$body = sanitize_textarea_field( wp_unslash( $_POST['body'] ?? '' ) );
		$from = in_array( $from, $froms, true ) ? $from : $froms[0];
		$ok   = is_email( $to ) && '' !== $subj && '' !== $body
			&& wp_mail( $to, $subj, $body, array( 'From: Eastwood FC <' . $from . '>', 'Content-Type: text/plain; charset=UTF-8' ) );
		if ( $ok ) {
			$log   = (array) get_option( 'ew_hd_log', array() );
			array_unshift( $log, array( 't' => time(), 'to' => $to, 'from' => $from, 's' => $subj, 'by' => wp_get_current_user()->display_name ) );
			update_option( 'ew_hd_log', array_slice( $log, 0, 60 ), false );
		}
		echo '<div class="notice notice-' . ( $ok ? 'success' : 'error' ) . '"><p>' . ( $ok ? 'Sent to ' . esc_html( $to ) . ' from ' . esc_html( $from ) . '.' : 'Not sent. Check the email address, subject and message.' ) . '</p></div>';
	}
	if ( isset( $_POST['ew_hd_tpl'] ) && check_admin_referer( 'ew_hd' ) ) {
		update_option( 'ew_hd_templates', sanitize_textarea_field( wp_unslash( $_POST['templates'] ?? '' ) ), false );
		echo '<div class="notice notice-success"><p>Replies saved.</p></div>';
	}

	$q = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
	echo '<form method="get"><input type="hidden" name="post_type" value="ewt_booking"><input type="hidden" name="page" value="ew-helpdesk">'
		. '<p><input type="search" name="q" value="' . esc_attr( $q ) . '" placeholder="Name, email or EFC reference" style="width:360px;font-size:16px" autofocus> <button class="button button-primary">Find</button></p></form>';

	$tpls = ew_hd_templates();
	if ( '' !== $q ) {
		$ids = ew_hd_find( $q );
		if ( ! $ids ) {
			echo '<p><strong>No booking found for "' . esc_html( $q ) . '".</strong> They may have used a different email. Try their name or part of the email.</p>';
		}
		foreach ( $ids as $bid ) {
			$st    = (string) get_post_meta( $bid, '_ewt_status', true );
			$inapp = (int) get_post_meta( $bid, '_ewt_in_app', true );
			$ans   = (string) get_post_meta( $bid, '_ewt_answer', true );
			$dup   = (int) get_post_meta( $bid, '_ewt_dup_of', true );
			$state = 'started' === $st ? '<span style="color:#b32d2e"><strong>Payment not completed, no ticket</strong></span>' : '<strong>' . esc_html( ucfirst( $st ) ) . '</strong>';
			echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:14px 16px;margin:12px 0;max-width:900px">';
			echo '<p style="font-size:15px;margin:0 0 6px"><strong>' . esc_html( get_post_meta( $bid, '_ewt_name', true ) ) . '</strong> · ' . esc_html( get_post_meta( $bid, '_ewt_email', true ) ) . ' · <strong>' . esc_html( function_exists( 'ew_booking_code' ) ? ew_booking_code( $bid ) : '' ) . '</strong></p>';
			echo '<p style="margin:0 0 10px">' . esc_html( get_post_meta( $bid, '_ewt_event', true ) ) . ' · ' . esc_html( get_post_meta( $bid, '_ewt_lines', true ) ) . ' · ' . $state
				. ' · In the app: ' . ( $inapp ? 'yes' : 'not yet' ) . ( $ans ? ' · Answer: ' . esc_html( 'yes' === $ans ? 'coming' : "can't make it" ) : '' ) . ( $dup ? ' · Merged into another booking' : '' ) . '</p>';
			echo '<form method="post">';
			wp_nonce_field( 'ew_hd' );
			echo '<input type="hidden" name="to" value="' . esc_attr( get_post_meta( $bid, '_ewt_email', true ) ) . '">';
			echo '<p><select class="ew-hd-pick">' . '<option value="">Pick a reply…</option>';
			foreach ( $tpls as $title => $t ) {
				echo '<option data-s="' . esc_attr( ew_hd_fill( $t[0], $bid ) ) . '" data-b="' . esc_attr( ew_hd_fill( $t[1], $bid ) ) . '">' . esc_html( $title ) . '</option>';
			}
			echo '</select> From <select name="from">';
			foreach ( $froms as $f ) {
				echo '<option>' . esc_html( $f ) . '</option>';
			}
			echo '</select></p>';
			echo '<p><input type="text" name="subject" placeholder="Subject" style="width:100%"></p>';
			echo '<p><textarea name="body" rows="9" style="width:100%"></textarea></p>';
			echo '<p><button class="button button-primary" name="ew_hd_send" value="1">Send reply</button></p></form></div>';
		}
	}

	echo '<script>document.querySelectorAll(".ew-hd-pick").forEach(function(s){s.addEventListener("change",function(){var o=s.options[s.selectedIndex],f=s.closest("form");f.subject.value=o.dataset.s||"";f.body.value=o.dataset.b||"";});});</script>';

	$log = (array) get_option( 'ew_hd_log', array() );
	if ( $log ) {
		echo '<h2>Sent today</h2><table class="widefat striped" style="max-width:900px"><tbody>';
		foreach ( array_slice( $log, 0, 20 ) as $l ) {
			echo '<tr><td>' . esc_html( wp_date( 'g:ia', (int) $l['t'] ) ) . '</td><td>' . esc_html( $l['to'] ) . '</td><td>' . esc_html( $l['s'] ) . '</td><td>' . esc_html( $l['by'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	echo '<h2>Ready-made replies</h2><form method="post">';
	wp_nonce_field( 'ew_hd' );
	echo '<p class="description">One reply per block: title | subject | message, with a line of --- between replies. {first_name}, {ref}, {email}, {party} and {game} are filled in from the booking.</p>';
	echo '<textarea name="templates" rows="16" style="width:100%;max-width:900px;font-family:monospace">' . esc_textarea( (string) get_option( 'ew_hd_templates', ew_hd_templates_default() ) ) . '</textarea>';
	echo '<p><button class="button" name="ew_hd_tpl" value="1">Save replies</button></p></form></div>';
}
