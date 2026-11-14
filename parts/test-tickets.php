<?php
/**
 * Test tickets for staff, so a scanner and a fan can try the gate over and over.
 *
 *   POST /eastwood/v1/admin/test-tickets   (admins only)
 *        { people: [ { email, name, sub? } ], template?: <booking id> }
 *        → { template, made: [ { email, code, id, new } ] }
 *
 * Each is a free claim copied from the newest real claimed booking (or the one
 * named), so it looks and scans exactly like the real ticket for that game. It
 * is marked _ewt_test: the gate never marks it used, it sends nothing to the
 * CRM, and its title starts TEST. When the fan account's id (sub) is given the
 * email is joined to that account, so it shows in the app straight away.
 * Asking again for the same email and game hands back the same ticket.
 *
 * @package eastwood-fwp
 */

defined( 'ABSPATH' ) || exit;

/** True for a staff test ticket. */
function ew_is_test_ticket( $id ) {
	return (bool) get_post_meta( (int) $id, '_ewt_test', true );
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'eastwood/v1', '/admin/test-tickets', array(
		'methods'             => 'POST',
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
		'callback'            => function ( $req ) {
			$tpl = (int) $req->get_param( 'template' );
			if ( ! $tpl ) {
				$ids = get_posts( array(
					'post_type'   => 'ewt_booking',
					'post_status' => 'any',
					'numberposts' => 1,
					'fields'      => 'ids',
					'orderby'     => 'date',
					'order'       => 'DESC',
					'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery
						array( 'key' => '_ewt_status', 'value' => 'claimed' ),
						array( 'key' => '_ewt_test', 'compare' => 'NOT EXISTS' ),
					),
				) );
				$tpl = $ids ? (int) $ids[0] : 0;
			}
			if ( ! $tpl || 'ewt_booking' !== get_post_type( $tpl ) ) {
				return new WP_REST_Response( array( 'message' => 'No claimed ticket to copy.' ), 400 );
			}
			// Everything about the game is copied; everything about the person is not.
			$skip = array( 'name', 'email', 'phone', 'code', 'key', 'in', 'hook', 'consent', 'adults', 'children', 'lines', 'extra', 'school', 'test', 'contact', 'ghl', 'crm' );
			$copy = array();
			foreach ( get_post_meta( $tpl ) as $k => $v ) {
				if ( 0 !== strpos( $k, '_ewt_' ) || in_array( substr( $k, 5 ), $skip, true ) || 0 === strpos( substr( $k, 5 ), 'crm' ) ) {
					continue;
				}
				$copy[ $k ] = maybe_unserialize( $v[0] );
			}
			$game = (string) ( $copy['_ewt_event'] ?? '' );
			$made = array();
			foreach ( array_slice( (array) $req->get_param( 'people' ), 0, 30 ) as $p ) {
				$email = strtolower( sanitize_email( (string) ( $p['email'] ?? '' ) ) );
				$name  = sanitize_text_field( (string) ( $p['name'] ?? '' ) ) ?: $email;
				if ( ! is_email( $email ) ) {
					continue;
				}
				$have = get_posts( array(
					'post_type'   => 'ewt_booking',
					'post_status' => 'any',
					'numberposts' => 1,
					'fields'      => 'ids',
					'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery
						array( 'key' => '_ewt_test', 'value' => '1' ),
						array( 'key' => '_ewt_email', 'value' => $email ),
						array( 'key' => '_ewt_event', 'value' => $game ),
					),
				) );
				$new = ! $have;
				if ( $have ) {
					$id = (int) $have[0];
				} else {
					do {
						$code = 'EFC-' . wp_rand( 1000, 9999 );
					} while ( get_posts( array( 'post_type' => 'ewt_booking', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_ewt_code', 'meta_value' => $code ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
					$id = wp_insert_post( array(
						'post_type'   => 'ewt_booking',
						'post_status' => 'publish',
						'post_title'  => 'TEST ' . $name,
					) );
					if ( ! $id || is_wp_error( $id ) ) {
						continue;
					}
					foreach ( $copy as $k => $v ) {
						update_post_meta( $id, $k, $v );
					}
					foreach ( array(
						'name'     => $name,
						'email'    => $email,
						'adults'   => 1,
						'children' => 0,
						'lines'    => '1 adult',
						'school'   => 'Staff test ticket',
						'extra'    => 'Staff test ticket',
						'status'   => 'claimed',
						'total'    => 0,
						'consent'  => 'no',
						'code'     => $code,
						'key'      => wp_generate_password( 12, false ),
						'hook'     => '',
						'test'     => 1,
					) as $k => $v ) {
						update_post_meta( $id, '_ewt_' . $k, $v );
					}
				}
				$sub = sanitize_text_field( (string) ( $p['sub'] ?? '' ) );
				if ( '' !== $sub && function_exists( 'ew_fan_link' ) ) {
					ew_fan_link( $sub, $email );
				}
				$made[] = array( 'email' => $email, 'code' => get_post_meta( $id, '_ewt_code', true ), 'id' => $id, 'new' => $new );
			}
			return array( 'template' => array( 'id' => $tpl, 'event' => $game, 'fixture' => $copy['_ewt_fixture'] ?? null ), 'made' => $made );
		},
	) );
} );
