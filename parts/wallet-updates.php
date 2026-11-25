<?php
/**
 * Apple Wallet pass updates: a pass already in someone's Wallet changes
 * itself when its gate (or anything else on it) changes, and the lock
 * screen says "Your gate is now Gate 3". No app needed.
 *
 * This is Apple's pass web service. Every pass made by wallet-pass.php
 * carries webServiceURL (below) and its own authenticationToken. When it is
 * added to Wallet the phone registers here with a push token; every five
 * minutes, and straight after the gates are saved, each registered pass is
 * rebuilt and compared with the last copy the phone got. A changed pass is
 * pushed to Apple (APNs, signed with the same pass certificate) and the
 * phone fetches the new one.
 *
 *   POST   /walletws/v1/devices/<device>/registrations/<pass type>/<serial>   register
 *   GET    /walletws/v1/devices/<device>/registrations/<pass type>           what changed
 *   GET    /walletws/v1/passes/<pass type>/<serial>                          the latest pass
 *   DELETE /walletws/v1/devices/<device>/registrations/<pass type>/<serial>   removed from Wallet
 *   POST   /walletws/v1/log                                                  Wallet's own errors
 *
 * The serial is the booking id. Registrations live in {prefix}ew_wallet_reg.
 *
 * @package eastwood-fwp
 */

defined( 'ABSPATH' ) || exit;

/** Base URL Wallet calls; Apple adds /v1/... itself. */
function ew_ws_url() {
	return rest_url( 'eastwood/v1/walletws' );
}

/** The pass's own token (Apple asks for 16+ characters). */
function ew_ws_token( $bid ) {
	$key = function_exists( 'ew_booking_key' ) ? ew_booking_key( $bid ) : (string) get_post_meta( $bid, '_ewt_key', true );
	return substr( hash_hmac( 'sha256', $bid . '.' . $key, wp_salt( 'auth' ) ), 0, 40 );
}

function ew_ws_table() {
	global $wpdb;
	return $wpdb->prefix . 'ew_wallet_reg';
}

add_action( 'init', function () {
	if ( '1' === get_option( 'ew_ws_db', '' ) ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( 'CREATE TABLE ' . ew_ws_table() . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		device varchar(128) NOT NULL,
		serial varchar(64) NOT NULL,
		push varchar(255) NOT NULL,
		added datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY device_serial (device,serial),
		KEY serial (serial)
	) " . $wpdb->get_charset_collate() . ';' );
	update_option( 'ew_ws_db', '1', true );
} );

/** The Authorization: ApplePass <token> header, wherever the host left it. */
function ew_ws_auth_ok( $req, $bid ) {
	$h = (string) $req->get_header( 'authorization' );
	if ( '' === $h ) {
		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $k ) {
			if ( ! empty( $_SERVER[ $k ] ) ) {
				$h = (string) wp_unslash( $_SERVER[ $k ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			}
		}
	}
	if ( '' === $h && function_exists( 'getallheaders' ) ) {
		foreach ( (array) getallheaders() as $k => $v ) {
			if ( 'authorization' === strtolower( $k ) ) {
				$h = (string) $v;
			}
		}
	}
	$tok = trim( (string) preg_replace( '/^ApplePass\s+/i', '', $h ) );
	return $bid && '' !== $tok && hash_equals( ew_ws_token( $bid ), $tok );
}

function ew_ws_bid( $serial ) {
	$bid = (int) $serial;
	return ( $bid && 'ewt_booking' === get_post_type( $bid ) ) ? $bid : 0;
}

/** What the pass says right now, as a fingerprint. */
function ew_ws_hash( $bid ) {
	return function_exists( 'ew_wp_json' ) ? md5( (string) wp_json_encode( ew_wp_json( $bid ) ) ) : '';
}

add_action( 'rest_api_init', function () {
	$pt  = '(?P<pt>[A-Za-z0-9.\-]+)';
	$dev = '(?P<dev>[A-Za-z0-9]+)';
	$ser = '(?P<ser>[A-Za-z0-9\-]+)';
	$ok  = '__return_true';

	register_rest_route( 'eastwood/v1', '/walletws/v1/devices/' . $dev . '/registrations/' . $pt . '/' . $ser, array(
		array(
			'methods'             => 'POST',
			'permission_callback' => $ok,
			'callback'            => function ( $req ) {
				global $wpdb;
				$bid = ew_ws_bid( $req['ser'] );
				if ( ew_wp_pass_type() !== $req['pt'] || ! ew_ws_auth_ok( $req, $bid ) ) {
					return new WP_REST_Response( null, 401 );
				}
				$body = json_decode( (string) $req->get_body(), true );
				$push = is_array( $body ) && ! empty( $body['pushToken'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) $body['pushToken'] ) : '';
				if ( '' === $push ) {
					return new WP_REST_Response( null, 400 );
				}
				$t   = ew_ws_table();
				$had = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE device = %s AND serial = %s", $req['dev'], (string) $bid ) ); // phpcs:ignore WordPress.DB
				if ( $had ) {
					$wpdb->update( $t, array( 'push' => $push ), array( 'id' => $had ) ); // phpcs:ignore WordPress.DB
				} else {
					$wpdb->insert( $t, array( 'device' => $req['dev'], 'serial' => (string) $bid, 'push' => $push, 'added' => current_time( 'mysql', true ) ) ); // phpcs:ignore WordPress.DB
				}
				if ( '' === (string) get_post_meta( $bid, '_ewt_wp_hash', true ) ) {
					update_post_meta( $bid, '_ewt_wp_hash', ew_ws_hash( $bid ) );
				}
				return new WP_REST_Response( null, $had ? 200 : 201 );
			},
		),
		array(
			'methods'             => 'DELETE',
			'permission_callback' => $ok,
			'callback'            => function ( $req ) {
				global $wpdb;
				$bid = ew_ws_bid( $req['ser'] );
				if ( ! ew_ws_auth_ok( $req, $bid ) ) {
					return new WP_REST_Response( null, 401 );
				}
				$wpdb->delete( ew_ws_table(), array( 'device' => $req['dev'], 'serial' => (string) $bid ) ); // phpcs:ignore WordPress.DB
				return new WP_REST_Response( null, 200 );
			},
		),
	) );

	register_rest_route( 'eastwood/v1', '/walletws/v1/devices/' . $dev . '/registrations/' . $pt, array(
		'methods'             => 'GET',
		'permission_callback' => $ok,
		'callback'            => function ( $req ) {
			global $wpdb;
			$t     = ew_ws_table();
			$since = (int) $req->get_param( 'passesUpdatedSince' );
			$rows  = $wpdb->get_col( $wpdb->prepare( "SELECT serial FROM $t WHERE device = %s", $req['dev'] ) ); // phpcs:ignore WordPress.DB
			$out   = array();
			foreach ( $rows as $s ) {
				if ( (int) get_post_meta( (int) $s, '_ewt_wp_updated', true ) > $since ) {
					$out[] = (string) $s;
				}
			}
			if ( ! $out ) {
				return new WP_REST_Response( null, 204 );
			}
			return new WP_REST_Response( array( 'serialNumbers' => $out, 'lastUpdated' => (string) time() ), 200 );
		},
	) );

	register_rest_route( 'eastwood/v1', '/walletws/v1/passes/' . $pt . '/' . $ser, array(
		'methods'             => 'GET',
		'permission_callback' => $ok,
		'callback'            => function ( $req ) {
			$bid = ew_ws_bid( $req['ser'] );
			if ( ! ew_ws_auth_ok( $req, $bid ) ) {
				return new WP_REST_Response( null, 401 );
			}
			$mod = (int) get_post_meta( $bid, '_ewt_wp_updated', true );
			$ims = strtotime( (string) $req->get_header( 'if_modified_since' ) );
			if ( $mod && $ims && $ims >= $mod ) {
				return new WP_REST_Response( null, 304 );
			}
			$pass = ew_wp_build( $bid );
			if ( is_wp_error( $pass ) ) {
				return new WP_REST_Response( null, 503 );
			}
			update_post_meta( $bid, '_ewt_wp_hash', ew_ws_hash( $bid ) );
			nocache_headers();
			header( 'Content-Type: application/vnd.apple.pkpass' );
			header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $mod ? $mod : time() ) . ' GMT' );
			header( 'Content-Length: ' . strlen( $pass ) );
			echo $pass; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		},
	) );

	register_rest_route( 'eastwood/v1', '/walletws/v1/log', array(
		'methods'             => 'POST',
		'permission_callback' => $ok,
		'callback'            => function ( $req ) {
			$body = json_decode( (string) $req->get_body(), true );
			$logs = is_array( $body ) && ! empty( $body['logs'] ) ? array_slice( (array) $body['logs'], 0, 20 ) : array();
			$keep = (array) get_option( 'ew_ws_log', array() );
			foreach ( $logs as $l ) {
				$keep[] = gmdate( 'Y-m-d H:i' ) . ' ' . substr( (string) $l, 0, 300 );
			}
			update_option( 'ew_ws_log', array_slice( $keep, -50 ), false );
			return new WP_REST_Response( null, 200 );
		},
	) );
} );

/**
 * Tell Apple to wake these phones. Returns how many were sent and how many
 * failed; a token Apple says is gone (410) is dropped.
 */
function ew_ws_push( $tokens ) {
	$res = array( 'sent' => 0, 'failed' => 0, 'error' => '' );
	$pem = (string) get_option( 'ew_wallet_signing', '' );
	if ( ! $tokens || '' === $pem ) {
		return $res;
	}
	if ( ! function_exists( 'curl_init' ) || ! defined( 'CURL_HTTP_VERSION_2_0' ) ) {
		$res['error'] = 'This server cannot talk to Apple (no HTTP/2).';
		return $res;
	}
	$file = wp_tempnam( 'ewws' );
	file_put_contents( $file, $pem ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	$ch = curl_init(); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init
	foreach ( array_unique( $tokens ) as $tok ) {
		curl_setopt_array( $ch, array( // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array
			CURLOPT_URL            => 'https://api.push.apple.com/3/device/' . $tok,
			CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2_0,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => '{}',
			CURLOPT_HTTPHEADER     => array( 'apns-topic: ' . ew_wp_pass_type(), 'apns-push-type: background', 'apns-priority: 10' ),
			CURLOPT_SSLCERT        => $file,
			CURLOPT_SSLKEY         => $file,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 10,
		) );
		$body = curl_exec( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec
		$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo
		if ( 200 === $code ) {
			++$res['sent'];
		} else {
			++$res['failed'];
			$res['error'] = $code ? 'Apple said ' . $code . ' ' . substr( (string) $body, 0, 120 ) : curl_error( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_error
			if ( 410 === $code ) {
				global $wpdb;
				$wpdb->delete( ew_ws_table(), array( 'push' => $tok ) ); // phpcs:ignore WordPress.DB
			}
		}
	}
	curl_close( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close
	@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
	return $res;
}

/**
 * Rebuild every pass that's in someone's Wallet; push the ones that
 * changed. $force pushes them all (the admin button, for testing).
 */
function ew_ws_sync( $force = false ) {
	global $wpdb;
	$t    = ew_ws_table();
	$rows = $wpdb->get_results( "SELECT serial, push FROM $t" ); // phpcs:ignore WordPress.DB
	$by   = array();
	foreach ( (array) $rows as $r ) {
		$by[ (int) $r->serial ][] = $r->push;
	}
	$tokens  = array();
	$changed = 0;
	foreach ( $by as $bid => $push ) {
		if ( 'ewt_booking' !== get_post_type( $bid ) ) {
			continue;
		}
		$h = ew_ws_hash( $bid );
		if ( $force || $h !== (string) get_post_meta( $bid, '_ewt_wp_hash', true ) ) {
			update_post_meta( $bid, '_ewt_wp_updated', time() );
			update_post_meta( $bid, '_ewt_wp_hash', $h );
			$tokens = array_merge( $tokens, $push );
			++$changed;
		}
	}
	$res            = ew_ws_push( $tokens );
	$res['changed'] = $changed;
	$res['passes']  = count( $by );
	$res['at']      = time();
	update_option( 'ew_ws_last', $res, false );
	return $res;
}

/* Straight after the gates are saved, and every five minutes. */
add_action( 'ew_gates_changed', function () {
	ew_ws_sync();
}, 20 );

add_filter( 'cron_schedules', function ( $s ) {
	$s['ew_5min'] = array( 'interval' => 300, 'display' => 'Every five minutes' );
	return $s;
} );
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'ew_ws_tick' ) ) {
		wp_schedule_event( time() + 60, 'ew_5min', 'ew_ws_tick' );
	}
} );
add_action( 'ew_ws_tick', 'ew_ws_sync' );

/* On the Bookings > Wallet passes page: what's registered, and a test push. */
add_action( 'ew_wp_admin_after', function () {
	global $wpdb;
	if ( isset( $_POST['ew_ws_go'] ) && check_admin_referer( 'ew_ws' ) ) {
		$r = ew_ws_sync( true );
		echo '<div class="notice notice-info"><p>Sent to ' . (int) $r['sent'] . ' phone(s), ' . (int) $r['failed'] . ' failed. ' . esc_html( $r['error'] ) . '</p></div>';
	}
	$t     = ew_ws_table();
	$phones = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT device) FROM $t" ); // phpcs:ignore WordPress.DB
	$passes = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT serial) FROM $t" ); // phpcs:ignore WordPress.DB
	$last   = (array) get_option( 'ew_ws_last', array() );
	echo '<h2>Updates</h2>';
	echo '<p>' . (int) $passes . ' pass(es) in Wallet on ' . (int) $phones . ' phone(s).</p>';
	if ( ! empty( $last['at'] ) ) {
		echo '<p>Last check ' . esc_html( wp_date( 'j M, g:ia', (int) $last['at'] ) ) . ': ' . (int) $last['changed'] . ' changed, ' . (int) $last['sent'] . ' sent, ' . (int) $last['failed'] . ' failed. ' . esc_html( (string) $last['error'] ) . '</p>';
	}
	echo '<form method="post">';
	wp_nonce_field( 'ew_ws' );
	echo '<p><button class="button" name="ew_ws_go" value="1">Refresh every pass now</button></p></form>';
	$log = (array) get_option( 'ew_ws_log', array() );
	if ( $log ) {
		echo '<p>Wallet errors:</p><pre style="white-space:pre-wrap">' . esc_html( implode( "\n", array_slice( $log, -10 ) ) ) . '</pre>';
	}
} );
