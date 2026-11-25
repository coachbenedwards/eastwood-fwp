<?php
/**
 * Apple Wallet passes: a ticket added to Wallet like a boarding pass.
 *
 *   GET /wp-json/eastwood/v1/wallet/apple?c=<booking id>.<booking key>
 *
 * Builds the .pkpass for one booking: the match, the date, the gate (when the
 * ticket has one), who it admits, the reference, and the same QR the gate
 * scanner reads in the app. The link is the booking's own private claim link,
 * so nobody can fetch somebody else's pass.
 *
 * Signing: the Pass Type ID pass.com.eastwoodfc.team (team QV95H9JQ9K). The
 * signing file (certificate and private key, PEM) is uploaded once under
 * Bookings > Wallet passes and kept in the database, never in the plugin.
 * Apple's WWDR G4 certificate is public and ships in assets/wallet/.
 *
 * @package eastwood-fwp
 */

defined( 'ABSPATH' ) || exit;

function ew_wp_pass_type() {
	return 'pass.com.eastwoodfc.team';
}

function ew_wp_team() {
	return 'QV95H9JQ9K';
}

/** The booking a claim string (id.key) opens, or 0. */
function ew_wp_booking( $c ) {
	$c = (string) $c;
	if ( false === strpos( $c, '.' ) ) {
		return 0;
	}
	list( $id, $key ) = explode( '.', $c, 2 );
	$id = (int) $id;
	if ( ! $id || 'ewt_booking' !== get_post_type( $id ) || '' === $key ) {
		return 0;
	}
	return hash_equals( (string) get_post_meta( $id, '_ewt_key', true ), (string) $key ) ? $id : 0;
}

/** The private link that adds this booking to Apple Wallet. */
function ew_wp_link( $bid ) {
	if ( ! function_exists( 'ew_booking_key' ) ) {
		return '';
	}
	return add_query_arg( 'c', $bid . '.' . ew_booking_key( $bid ), rest_url( 'eastwood/v1/wallet/apple' ) );
}

/** The crest for the pass: the club's own (uploads/ew-badges/2059.png), else the plugin's. */
function ew_wp_crest() {
	foreach ( array( WP_CONTENT_DIR . '/uploads/ew-badges/2059.png', dirname( __DIR__ ) . '/assets/claim/eastwood-white.png' ) as $f ) {
		if ( is_readable( $f ) ) {
			return (string) file_get_contents( $f ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
	}
	return '';
}

/** pass.json for one booking. */
function ew_wp_json( $bid ) {
	$m     = function ( $k ) use ( $bid ) {
		return (string) get_post_meta( $bid, '_ewt_' . $k, true );
	};
	$event = $m( 'event' );
	$match = $event;
	$when  = '';
	if ( false !== strpos( $event, ', ' ) ) {
		$match = substr( $event, 0, strrpos( $event, ', ' ) );
		$when  = substr( $event, strrpos( $event, ', ' ) + 2 );
	}
	$code  = function_exists( 'ew_booking_code' ) ? ew_booking_code( $bid ) : $m( 'code' );
	$qr    = function_exists( 'ew_booking_key' ) ? ew_booking_key( $bid ) : $m( 'key' );
	$type  = '' !== $m( 'ticket_type' ) ? $m( 'ticket_type' ) : 'Match ticket';
	$sec   = array();
	if ( '' !== $when ) {
		$sec[] = array( 'key' => 'when', 'label' => 'DATE', 'value' => $when );
	}
	$gate = function_exists( 'ew_gate_for_booking' ) && '' === $m( 'in' ) ? ew_gate_for_booking( $bid ) : array();
	if ( ! empty( $gate['label'] ) ) {
		$sec[] = array( 'key' => 'gate', 'label' => 'GATE', 'value' => (string) $gate['label'], 'changeMessage' => 'Your gate is now %@' );
	}
	$aux = array();
	if ( '' !== $m( 'lines' ) ) {
		$aux[] = array( 'key' => 'admits', 'label' => 'ADMITS', 'value' => $m( 'lines' ) );
	}
	$aux[] = array( 'key' => 'ref', 'label' => 'REFERENCE', 'value' => $code );
	$back  = array(
		array( 'key' => 'name', 'label' => 'Name', 'value' => $m( 'name' ) ),
		array( 'key' => 'venue', 'label' => 'Venue', 'value' => 'Coronation Park, Chewton Street, Eastwood NG16 3HB' ),
	);
	return array(
		'formatVersion'      => 1,
		'passTypeIdentifier' => ew_wp_pass_type(),
		'teamIdentifier'     => ew_wp_team(),
		'serialNumber'       => $code,
		'organizationName'   => 'Eastwood FC',
		'description'        => '' !== $event ? $event : 'Eastwood FC ticket',
		'logoText'           => 'Eastwood FC',
		'foregroundColor'    => 'rgb(255, 255, 255)',
		'labelColor'         => 'rgb(229, 57, 43)',
		'backgroundColor'    => 'rgb(10, 10, 11)',
		'sharingProhibited'  => true,
		'barcodes'           => array( array( 'format' => 'PKBarcodeFormatQR', 'message' => $qr, 'messageEncoding' => 'iso-8859-1', 'altText' => $code ) ),
		'barcode'            => array( 'format' => 'PKBarcodeFormatQR', 'message' => $qr, 'messageEncoding' => 'iso-8859-1', 'altText' => $code ),
		'eventTicket'        => array(
			'headerFields'    => array( array( 'key' => 'type', 'label' => 'TICKET', 'value' => $type ) ),
			'primaryFields'   => array( array( 'key' => 'match', 'label' => 'MATCH', 'value' => $match ) ),
			'secondaryFields' => $sec,
			'auxiliaryFields' => $aux,
			'backFields'      => $back,
		),
	);
}

/** The finished .pkpass bytes, or a WP_Error saying what is missing. */
function ew_wp_build( $bid ) {
	$pem = (string) get_option( 'ew_wallet_signing', '' );
	if ( '' === $pem ) {
		return new WP_Error( 'unsigned', 'Wallet passes are not set up yet.' );
	}
	if ( ! class_exists( 'ZipArchive' ) || ! function_exists( 'openssl_pkcs7_sign' ) ) {
		return new WP_Error( 'host', 'This server cannot build Wallet passes.' );
	}
	$cert = openssl_x509_read( $pem );
	$key  = openssl_pkey_get_private( $pem );
	$wwdr = dirname( __DIR__ ) . '/assets/wallet/apple-wwdr-g4.pem';
	if ( ! $cert || ! $key || ! is_readable( $wwdr ) ) {
		return new WP_Error( 'signing', 'The Wallet signing file could not be read.' );
	}
	$crest = ew_wp_crest();
	$files = array( 'pass.json' => wp_json_encode( ew_wp_json( $bid ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	if ( '' !== $crest ) {
		foreach ( array( 'icon.png', 'icon@2x.png', 'logo.png', 'logo@2x.png' ) as $n ) {
			$files[ $n ] = $crest;
		}
	}
	$manifest = array();
	foreach ( $files as $n => $b ) {
		$manifest[ $n ] = sha1( $b );
	}
	$files['manifest.json'] = wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES );

	$dir = trailingslashit( get_temp_dir() ) . 'ewpass-' . wp_generate_password( 10, false );
	wp_mkdir_p( $dir );
	file_put_contents( $dir . '/manifest.json', $files['manifest.json'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	$ok = openssl_pkcs7_sign( $dir . '/manifest.json', $dir . '/sig.txt', $cert, $key, array(), PKCS7_BINARY | PKCS7_DETACHED, $wwdr );
	$sig = $ok ? (string) file_get_contents( $dir . '/sig.txt' ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$at  = strpos( $sig, 'filename="smime.p7s"' );
	if ( ! $ok || false === $at ) {
		return new WP_Error( 'sign', 'The pass could not be signed.' );
	}
	$sig = substr( $sig, $at + 20 );
	$sig = base64_decode( trim( substr( $sig, 0, strrpos( $sig, '------' ) ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	$files['signature'] = $sig;

	$zipf = $dir . '/pass.pkpass';
	$zip  = new ZipArchive();
	$zip->open( $zipf, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	foreach ( $files as $n => $b ) {
		$zip->addFromString( $n, $b );
	}
	$zip->close();
	$out = (string) file_get_contents( $zipf ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	foreach ( glob( $dir . '/*' ) as $f ) {
		wp_delete_file( $f );
	}
	@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	return $out;
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'eastwood/v1', '/wallet/apple', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) {
			$bid = ew_wp_booking( (string) $req->get_param( 'c' ) );
			if ( ! $bid ) {
				return new WP_REST_Response( array( 'message' => 'We cannot find that ticket.' ), 404 );
			}
			$pass = ew_wp_build( $bid );
			if ( is_wp_error( $pass ) ) {
				return new WP_REST_Response( array( 'message' => $pass->get_error_message() ), 503 );
			}
			nocache_headers();
			header( 'Content-Type: application/vnd.apple.pkpass' );
			header( 'Content-Disposition: inline; filename="eastwood-ticket.pkpass"' );
			header( 'Content-Length: ' . strlen( $pass ) );
			echo $pass; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		},
	) );
} );

/* Bookings > Wallet passes: upload the signing file once. */
add_action( 'admin_menu', function () {
	add_submenu_page( 'edit.php?post_type=ewt_booking', 'Wallet passes', 'Wallet passes', 'manage_options', 'ew-wallet', 'ew_wp_admin' );
} );

function ew_wp_admin() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( isset( $_POST['ew_wp_go'] ) && check_admin_referer( 'ew_wp' ) && ! empty( $_FILES['ew_wp_file']['tmp_name'] ) ) {
		$pem  = (string) file_get_contents( sanitize_text_field( wp_unslash( $_FILES['ew_wp_file']['tmp_name'] ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$cert = openssl_x509_read( $pem );
		$key  = openssl_pkey_get_private( $pem );
		if ( $cert && $key && openssl_x509_check_private_key( $cert, $key ) ) {
			update_option( 'ew_wallet_signing', $pem, false );
			echo '<div class="notice notice-success"><p>Saved. Wallet passes can be made now.</p></div>';
		} else {
			echo '<div class="notice notice-error"><p>That file needs the certificate and its private key, and they must match. Nothing was saved.</p></div>';
		}
	}
	$have = '' !== (string) get_option( 'ew_wallet_signing', '' );
	$info = '';
	if ( $have ) {
		$c = openssl_x509_parse( (string) get_option( 'ew_wallet_signing', '' ) );
		$info = $c ? ( $c['subject']['CN'] ?? '' ) . ', until ' . wp_date( 'j M Y', (int) $c['validTo_time_t'] ) : '';
	}
	echo '<div class="wrap"><h1>Wallet passes</h1>';
	echo '<p>' . ( $have ? 'Signing file in place: ' . esc_html( $info ) . '.' : 'No signing file yet.' ) . '</p>';
	echo '<form method="post" enctype="multipart/form-data">';
	wp_nonce_field( 'ew_wp' );
	echo '<p><input type="file" name="ew_wp_file" accept=".pem"></p>';
	echo '<p><button class="button button-primary" name="ew_wp_go" value="1">Upload signing file</button></p></form></div>';
}

/* Bookings list: each booking's Apple Wallet pass, for admins (to test, or to send on). */
add_filter( 'post_row_actions', function ( $actions, $post ) {
	if ( 'ewt_booking' === $post->post_type && current_user_can( 'manage_options' ) && '' !== (string) get_option( 'ew_wallet_signing', '' ) ) {
		$actions['ew_wallet'] = '<a href="' . esc_url( ew_wp_link( $post->ID ) ) . '">Apple Wallet pass</a>';
	}
	return $actions;
}, 10, 2 );
