<?php
/**
 * Which entrance a ticket holder uses, set live on the day.
 *
 * The entrance is never written onto a ticket, an email or a page, because
 * the club moves people between entrances as the crowd builds. It lives in
 * one place -- Bookings > Matchday entrances -- and the app asks for it every
 * time a ticket is opened, so a change made at 2:15pm is what the next
 * person at the queue sees.
 *
 * One game at a time: its name (matched against the ticket's title), its
 * date (after which nothing shows), the entrance for claimed tickets, the
 * entrance for bought tickets, and an optional short note.
 *
 *   GET /eastwood/v1/app/entrance?kind=&form=&title=   what the app shows
 *
 * @package eastwood-fwp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The entrances at Coronation Park, offered as suggestions on the screen. */
function ew_ent_places() {
	return array(
		'Chewton Street End',
		'Coronation Park End (KAM Servicing Stand)',
		'North Stand turnstiles',
		'North Stand gate',
	);
}

function ew_ent_get() {
	$s = get_option( 'ew_entrances', array() );
	return wp_parse_args( is_array( $s ) ? $s : array(), array(
		'game'    => '',
		'date'    => '',
		'claimed' => '',
		'bought'  => '',
		'note'    => '',
		'updated' => 0,
	) );
}

/** Claimed (free) tickets, bought match tickets, or neither. */
function ew_ent_group( $kind, $form ) {
	if ( 'match' === $kind ) {
		return 'bought';
	}
	if ( 'signup' === $kind && in_array( $form, array( 'school', 'community' ), true ) ) {
		return 'claimed';
	}
	if ( 'event' === $kind ) {
		return 'claimed';
	}
	return '';
}

/** What one ticket should show right now, or an empty array. */
function ew_ent_for( $kind, $form, $title ) {
	$s = ew_ent_get();
	if ( '' === trim( $s['game'] ) || '' === $s['date'] ) {
		return array();
	}
	$today = wp_date( 'Y-m-d' );
	if ( $today > $s['date'] ) {
		return array();
	}
	if ( false === stripos( (string) $title, trim( $s['game'] ) ) ) {
		return array();
	}
	$g = ew_ent_group( (string) $kind, (string) $form );
	if ( '' === $g || '' === trim( $s[ $g ] ) ) {
		return array();
	}
	return array(
		'entrance' => trim( $s[ $g ] ),
		'note'     => trim( $s['note'] ),
		'updated'  => $s['updated'] ? wp_date( 'g:ia', (int) $s['updated'] ) : '',
	);
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'eastwood/v1', '/app/entrance', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) {
			$r = new WP_REST_Response( (object) ew_ent_for(
				(string) $req->get_param( 'kind' ),
				(string) $req->get_param( 'form' ),
				(string) $req->get_param( 'title' )
			) );
			$r->header( 'Cache-Control', 'no-store' );
			return $r;
		},
	) );
	register_rest_route( 'eastwood/v1', '/app/entrance/set', array(
		'methods'             => 'POST',
		'permission_callback' => function () {
			return current_user_can( 'edit_pages' );
		},
		'callback'            => function ( $req ) {
			ew_ent_save( (array) $req->get_json_params() );
			return ew_ent_get();
		},
	) );
} );

function ew_ent_save( $in ) {
	$s = ew_ent_get();
	foreach ( array( 'game', 'claimed', 'bought', 'note' ) as $k ) {
		if ( isset( $in[ $k ] ) ) {
			$s[ $k ] = sanitize_text_field( wp_unslash( (string) $in[ $k ] ) );
		}
	}
	if ( isset( $in['date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $in['date'] ) ) {
		$s['date'] = (string) $in['date'];
	}
	$s['updated'] = time();
	update_option( 'ew_entrances', $s, false );
}

add_action( 'admin_menu', function () {
	add_submenu_page( 'edit.php?post_type=ewt_booking', 'Matchday entrances', 'Matchday entrances', 'edit_pages', 'ew-entrances', 'ew_ent_page' );
} );

function ew_ent_page() {
	if ( ! current_user_can( 'edit_pages' ) ) {
		return;
	}
	$saved = false;
	if ( isset( $_POST['ew_ent_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['ew_ent_nonce'] ), 'ew_ent' ) ) {
		ew_ent_save( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		$saved = true;
	}
	$s = ew_ent_get();
	echo '<div class="wrap"><h1>Matchday entrances</h1>';
	echo '<p>What the app shows on a ticket, live. Change it as the crowd builds: the next person who opens their ticket sees the new entrance. It is never put on emails or pages.</p>';
	if ( $saved ) {
		echo '<div class="notice notice-success"><p>Saved. Ticket holders now see this.</p></div>';
	}
	echo '<form method="post">';
	wp_nonce_field( 'ew_ent', 'ew_ent_nonce' );
	echo '<datalist id="ew-ent-places">';
	foreach ( ew_ent_places() as $p ) {
		echo '<option value="' . esc_attr( $p ) . '">';
	}
	echo '</datalist><table class="form-table">';
	$row = function ( $k, $label, $help, $type = 'text', $list = false ) use ( $s ) {
		echo '<tr><th><label for="ew-' . esc_attr( $k ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input class="regular-text" type="' . esc_attr( $type ) . '" id="ew-' . esc_attr( $k ) . '" name="' . esc_attr( $k ) . '" value="' . esc_attr( $s[ $k ] ) . '"' . ( $list ? ' list="ew-ent-places"' : '' ) . '>';
		echo '<p class="description">' . esc_html( $help ) . '</p></td></tr>';
	};
	$row( 'game', 'Game', 'The opponent as it appears on the ticket, e.g. Hucknall Town.' );
	$row( 'date', 'Date', 'Nothing shows after this day.', 'date' );
	$row( 'claimed', 'Claimed tickets enter at', 'Free school and community tickets. Leave empty to show nothing.', 'text', true );
	$row( 'bought', 'Bought tickets enter at', 'Match tickets paid for online. Leave empty to show nothing.', 'text', true );
	$row( 'note', 'Short note (optional)', 'One line under the entrance, e.g. Have your ticket open before you reach the gate.' );
	echo '</table>';
	if ( $s['updated'] ) {
		echo '<p>Last changed ' . esc_html( wp_date( 'D j M, g:ia', (int) $s['updated'] ) ) . '.</p>';
	}
	submit_button( 'Save and show in the app' );
	echo '</form></div>';
}
