<?php
/**
 * The app's Tickets tab: everything somebody has booked with the club.
 *
 * Getting in, from the app's welcome sheet:
 *   POST /eastwood/v1/app/claim  { email, ref }   their email and the booking
 *                                                 reference from their email
 *   POST /eastwood/v1/app/claim  { claim }        the private link from the
 *                                                 website's last page
 * Either gives back their email and a wallet: a key that opens everything
 * booked under that email, and nothing else. The email alone opens nothing,
 * so nobody can see somebody else's tickets by guessing an address.
 *
 *   GET /eastwood/v1/app/wallet?w=<wallet>       their bookings, newest first
 *
 * Signed in, the account is the key instead: see app-account.php
 * (POST /app/mine). A claim made while signed in is kept on the account.
 *
 * The code on each ticket is the booking's private key, which the gate
 * scanner already accepts.
 *
 * @package eastwood-fwp
 */

defined( 'ABSPATH' ) || exit;

function ew_wallet_for( $email ) {
	$email = strtolower( trim( (string) $email ) );
	$b64   = rtrim( strtr( base64_encode( $email ), '+/', '-_' ), '=' );
	return 'w1.' . $b64 . '.' . substr( hash_hmac( 'sha256', $email, wp_salt( 'auth' ) . 'ew-wallet' ), 0, 32 );
}

/** The email a wallet opens, or '' if it has been tampered with. */
function ew_wallet_email( $w ) {
	$p = explode( '.', (string) $w );
	if ( 3 !== count( $p ) || 'w1' !== $p[0] ) {
		return '';
	}
	$email = (string) base64_decode( strtr( $p[1], '-_', '+/' ) );
	return ( is_email( $email ) && hash_equals( ew_wallet_for( $email ), (string) $w ) ) ? $email : '';
}

/** Ten wrong tries an hour from one address, then it stops answering. */
function ew_wallet_throttled() {
	$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$key = 'ew_wallet_try_' . md5( $ip );
	$n   = (int) get_transient( $key );
	return array( $key, $n );
}

/** The QR code as rows of 1s and 0s, so the app draws it without a library. */
function ew_wallet_qr_rows( $text ) {
	if ( ! class_exists( 'EwQrCode' ) ) {
		require_once dirname( __DIR__ ) . '/lib/qrcode.php';
	}
	$q    = EwQrCode::getMinimumQRCode( (string) $text, EWQR_ERROR_CORRECT_LEVEL_M );
	$n    = $q->getModuleCount();
	$rows = array();
	for ( $r = 0; $r < $n; $r++ ) {
		$line = '';
		for ( $c = 0; $c < $n; $c++ ) {
			$line .= $q->isDark( $r, $c ) ? '1' : '0';
		}
		$rows[] = $line;
	}
	return $rows;
}

function ew_wallet_item( $id ) {
	$m      = function ( $k ) use ( $id ) {
		return (string) get_post_meta( $id, '_ewt_' . $k, true );
	};
	$status = $m( 'status' );
	$kind   = $m( 'kind' );
	if ( (int) $m( 'fixture' ) > 0 ) {
		$kind = 'match';
	} elseif ( '' === $kind ) {
		$kind = 'event';
	}
	return array(
		'id'     => (int) $id,
		'kind'   => $kind,
		'form'   => $m( 'form' ),
		'status' => $status,
		'title'  => $m( 'event' ),
		'lines'  => $m( 'lines' ),
		'name'   => $m( 'name' ),
		'child'  => $m( 'child' ),
		'code'   => function_exists( 'ew_booking_code' ) ? ew_booking_code( $id ) : $m( 'code' ),
		'qr'     => function_exists( 'ew_booking_key' ) ? ew_booking_key( $id ) : $m( 'key' ),
		'qr_rows' => ew_wallet_qr_rows( function_exists( 'ew_booking_key' ) ? ew_booking_key( $id ) : $m( 'key' ) ),
		'used'   => '' !== $m( 'in' ),
		'when'   => get_post_time( 'c', true, $id ),
	);
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'eastwood/v1', '/app/claim', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) {
			list( $tkey, $tries ) = ew_wallet_throttled();
			if ( $tries >= 10 ) {
				return new WP_REST_Response( array( 'message' => 'Too many tries. Please wait an hour and try again.' ), 429 );
			}
			$bid   = 0;
			$claim = (string) $req->get_param( 'claim' );
			if ( '' !== $claim && false !== strpos( $claim, '.' ) ) {
				list( $id, $k ) = explode( '.', $claim, 2 );
				$id = (int) $id;
				if ( $id && 'ewt_booking' === get_post_type( $id ) && hash_equals( (string) get_post_meta( $id, '_ewt_key', true ), (string) $k ) ) {
					$bid = $id;
				}
			} else {
				$email = sanitize_email( (string) $req->get_param( 'email' ) );
				$ref   = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $req->get_param( 'ref' ) ) );
				$ref   = 'EFC-' . preg_replace( '/^EFC/', '', $ref );
				if ( is_email( $email ) && strlen( $ref ) > 4 ) {
					$hit = get_posts( array(
						'post_type'   => 'ewt_booking',
						'post_status' => 'any',
						'numberposts' => 1,
						'fields'      => 'ids',
						'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery
							array( 'key' => '_ewt_code', 'value' => $ref ),
							array( 'key' => '_ewt_email', 'value' => $email ),
						),
					) );
					$bid = $hit ? (int) $hit[0] : 0;
					// Until 3.94 the match-ticket thank-you page showed "EFC-" plus the
					// booking's own number instead of its reference. Accept that too,
					// still only with the booking's own email.
					if ( ! $bid && preg_match( '/^EFC-(\d{1,5})$/', $ref, $mm ) ) {
						$old = (int) $mm[1];
						if ( 'ewt_booking' === get_post_type( $old )
							&& strtolower( (string) get_post_meta( $old, '_ewt_email', true ) ) === strtolower( $email ) ) {
							$bid = $old;
						}
					}
				}
			}
			if ( ! $bid ) {
				set_transient( $tkey, $tries + 1, HOUR_IN_SECONDS );
				return new WP_REST_Response( array( 'message' => "We couldn't find that booking. Check the email and the reference in your confirmation email." ), 404 );
			}
			$email = (string) get_post_meta( $bid, '_ewt_email', true );
			// Signed in while claiming: the booking's email joins their account, on every phone.
			if ( function_exists( 'ew_fan_from_token' ) ) {
				$fan = ew_fan_from_token( ew_fan_token( $req ) );
				if ( $fan ) {
					ew_fan_link( $fan['sub'], $email );
				}
			}
			return array(
				'email'  => $email,
				'name'   => (string) get_post_meta( $bid, '_ewt_name', true ),
				'wallet' => ew_wallet_for( $email ),
			);
		},
	) );

	register_rest_route( 'eastwood/v1', '/app/wallet', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) {
			$email = ew_wallet_email( (string) $req->get_param( 'w' ) );
			if ( '' === $email ) {
				return new WP_REST_Response( array( 'message' => 'not found' ), 404 );
			}
			$ids = get_posts( array(
				'post_type'   => 'ewt_booking',
				'post_status' => 'any',
				'numberposts' => 100,
				'fields'      => 'ids',
				'orderby'     => 'date',
				'order'       => 'DESC',
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array( 'key' => '_ewt_email', 'value' => $email ),
					// Started and never finished is not something they hold.
					array( 'key' => '_ewt_status', 'value' => 'started', 'compare' => '!=' ),
				),
			) );
			return array( 'items' => array_map( 'ew_wallet_item', $ids ) );
		},
	) );
} );
