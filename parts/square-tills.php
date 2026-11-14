<?php
/**
 * Square tills into the activity log: every sale, refund and cancelled sale
 * from the club's two Square accounts, as it happens.
 *
 *   Eastwood FC Ventures Ltd   POST /wp-json/eastwood/v1/square/webhook/ventures
 *   Eastwood FC CIC            POST /wp-json/eastwood/v1/square/webhook/cic
 *
 * Settings → Square tills holds, per account, an access token (to look up
 * the till, the staff member and the customer's email) and the webhook
 * signature key Square shows for the subscription. Neither is ever shown
 * back. Every call is checked against Square's signature before it is
 * believed. Only the card brand and last four digits are kept, never more.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The two Square accounts, in the order they are set up. */
function ew_sqt_accounts() {
	return array(
		'ventures' => 'Eastwood FC Ventures Ltd',
		'cic'      => 'Eastwood FC CIC',
	);
}

/** One account's saved settings. */
function ew_sqt_cfg( $acct ) {
	$names = ew_sqt_accounts();
	return array(
		'label' => (string) get_option( 'ew_sqt_label_' . $acct, $names[ $acct ] ?? $acct ),
		'token' => (string) get_option( 'ew_sqt_token_' . $acct, '' ),
		'sig'   => (string) get_option( 'ew_sqt_sig_' . $acct, '' ),
	);
}

/** The exact address Square must post to (the signature is made over it). */
function ew_sqt_url( $acct ) {
	return rest_url( 'eastwood/v1/square/webhook/' . $acct );
}

/** True when Square signed this body for this account. */
function ew_sqt_sig_ok( $acct, $body, $sig ) {
	$key = ew_sqt_cfg( $acct )['sig'];
	if ( '' === $key || '' === (string) $sig ) {
		return false;
	}
	$want = base64_encode( hash_hmac( 'sha256', ew_sqt_url( $acct ) . $body, $key, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	return hash_equals( $want, (string) $sig );
}

/** One GET to Square for an account, cached briefly. Empty array when it cannot be asked. */
function ew_sqt_get( $acct, $path, $cache = 0 ) {
	$token = ew_sqt_cfg( $acct )['token'];
	if ( '' === $token ) {
		return array();
	}
	$ck = 'ew_sqt_' . md5( $acct . $path );
	if ( $cache && is_array( $hit = get_transient( $ck ) ) ) {
		return $hit;
	}
	$res = wp_remote_get( 'https://connect.squareup.com/v2/' . ltrim( $path, '/' ), array(
		'timeout' => 6,
		'headers' => array(
			'Authorization'  => 'Bearer ' . $token,
			'Square-Version' => defined( 'EW_SQ_API_VERSION' ) ? EW_SQ_API_VERSION : '2025-01-23',
		),
	) );
	if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		return array();
	}
	$j = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	$j = is_array( $j ) ? $j : array();
	if ( $cache ) {
		set_transient( $ck, $j, $cache );
	}
	return $j;
}

/** Names for the till's location and staff member, looked up once a day. */
function ew_sqt_location( $acct, $id ) {
	if ( '' === (string) $id ) {
		return '';
	}
	$j = ew_sqt_get( $acct, 'locations/' . rawurlencode( $id ), DAY_IN_SECONDS );
	return (string) ( $j['location']['name'] ?? '' );
}
function ew_sqt_member( $acct, $id ) {
	if ( '' === (string) $id ) {
		return '';
	}
	$j = ew_sqt_get( $acct, 'team-members/' . rawurlencode( $id ), DAY_IN_SECONDS );
	$m = (array) ( $j['team_member'] ?? array() );
	return trim( (string) ( $m['given_name'] ?? '' ) . ' ' . (string) ( $m['family_name'] ?? '' ) );
}

/** The customer's email on a payment, if Square has one. */
function ew_sqt_email( $acct, $p ) {
	$e = (string) ( $p['buyer_email_address'] ?? '' );
	if ( '' === $e && ! empty( $p['customer_id'] ) ) {
		$c = ew_sqt_get( $acct, 'customers/' . rawurlencode( $p['customer_id'] ), HOUR_IN_SECONDS );
		$e = (string) ( $c['customer']['email_address'] ?? '' );
	}
	return strtolower( sanitize_email( $e ) );
}

/** Once per thing per state, however often Square repeats itself. */
function ew_sqt_once( $key ) {
	$k = 'ew_sqt_seen_' . md5( $key );
	if ( get_transient( $k ) ) {
		return false;
	}
	set_transient( $k, 1, 14 * DAY_IN_SECONDS );
	return true;
}

function ew_sqt_log( $acct, $action, $obj_type, $obj_id, $detail, $email, $result = 'ok' ) {
	if ( ! function_exists( 'ew_log' ) ) {
		return;
	}
	$label = ew_sqt_cfg( $acct )['label'];
	ew_log( $action, array(
		'object_type' => $obj_type,
		'object_id'   => $obj_id,
		'result'      => $result,
		'source'      => 'till',
		'email'       => $email,
		'actor'       => '' !== $email
			? array( 'actor_type' => 'guest', 'actor_email' => $email, 'actor_label' => $email )
			: array( 'actor_type' => 'guest', 'actor_label' => 'Square till · ' . $label ),
		'detail'      => array_filter( array_merge( array( 'company' => $label, 'account' => $acct ), $detail ), function ( $v ) {
			return null !== $v && '' !== $v;
		} ),
	) );
}

/** Money as pence and currency. */
function ew_sqt_money( $m ) {
	return array( 'amount' => (int) ( $m['amount'] ?? 0 ), 'currency' => (string) ( $m['currency'] ?? '' ) );
}

/** One Square event for one account. */
function ew_sqt_handle( $acct, $ev ) {
	$type = (string) ( $ev['type'] ?? '' );
	$obj  = (array) ( $ev['data']['object'] ?? array() );

	if ( 0 === strpos( $type, 'payment.' ) ) {
		$p      = (array) ( $obj['payment'] ?? array() );
		$status = (string) ( $p['status'] ?? '' );
		$map    = array( 'COMPLETED' => array( 'sale.paid', 'ok' ), 'FAILED' => array( 'sale.failed', 'failed' ), 'CANCELED' => array( 'sale.cancelled', 'rejected' ) );
		if ( ! isset( $map[ $status ] ) || ! ew_sqt_once( $acct . $p['id'] . $status ) ) {
			return;
		}
		$card  = (array) ( $p['card_details']['card'] ?? array() );
		$money = ew_sqt_money( $p['total_money'] ?? ( $p['amount_money'] ?? array() ) );
		ew_sqt_log( $acct, $map[ $status ][0], 'square_payment', $p['id'] ?? null, array(
			'amount'      => $money['amount'],
			'currency'    => $money['currency'],
			'tip'         => isset( $p['tip_money'] ) ? (int) $p['tip_money']['amount'] : null,
			'location'    => ew_sqt_location( $acct, $p['location_id'] ?? '' ),
			'device'      => (string) ( $p['device_details']['device_name'] ?? ( $p['application_details']['square_product'] ?? '' ) ),
			'staff'       => ew_sqt_member( $acct, $p['team_member_id'] ?? ( $p['employee_id'] ?? '' ) ),
			'paid_with'   => (string) ( $p['source_type'] ?? '' ),
			'card'        => trim( (string) ( $card['card_brand'] ?? '' ) . ' ' . (string) ( $card['last_4'] ?? '' ) ),
			'order'       => (string) ( $p['order_id'] ?? '' ),
			'receipt'     => (string) ( $p['receipt_number'] ?? '' ),
		), ew_sqt_email( $acct, $p ), $map[ $status ][1] );
		if ( 'COMPLETED' === $status ) {
			do_action( 'ew_square_paid', $acct, $p );
		}
		return;
	}

	if ( 0 === strpos( $type, 'refund.' ) ) {
		$r      = (array) ( $obj['refund'] ?? array() );
		$status = (string) ( $r['status'] ?? '' );
		$map    = array( 'COMPLETED' => array( 'sale.refunded', 'ok' ), 'FAILED' => array( 'sale.refund_failed', 'failed' ), 'REJECTED' => array( 'sale.refund_failed', 'rejected' ) );
		if ( ! isset( $map[ $status ] ) || ! ew_sqt_once( $acct . $r['id'] . $status ) ) {
			return;
		}
		$p     = ! empty( $r['payment_id'] ) ? (array) ( ew_sqt_get( $acct, 'payments/' . rawurlencode( $r['payment_id'] ) )['payment'] ?? array() ) : array();
		$money = ew_sqt_money( $r['amount_money'] ?? array() );
		ew_sqt_log( $acct, $map[ $status ][0], 'square_payment', $r['payment_id'] ?? ( $r['id'] ?? null ), array(
			'amount'   => $money['amount'],
			'currency' => $money['currency'],
			'refund'   => (string) ( $r['id'] ?? '' ),
			'reason'   => (string) ( $r['reason'] ?? '' ),
			'location' => ew_sqt_location( $acct, $r['location_id'] ?? ( $p['location_id'] ?? '' ) ),
			'staff'    => ew_sqt_member( $acct, $r['team_member_id'] ?? '' ),
		), $p ? ew_sqt_email( $acct, $p ) : '', $map[ $status ][1] );
		return;
	}

	if ( 0 === strpos( $type, 'order.' ) ) {
		$o     = (array) ( $obj['order_updated'] ?? ( $obj['order_created'] ?? array() ) );
		$state = (string) ( $o['state'] ?? '' );
		if ( 'CANCELED' !== $state || empty( $o['order_id'] ) || ! ew_sqt_once( $acct . $o['order_id'] . $state ) ) {
			return;
		}
		$full  = (array) ( ew_sqt_get( $acct, 'orders/' . rawurlencode( $o['order_id'] ) )['order'] ?? array() );
		$money = ew_sqt_money( $full['total_money'] ?? array() );
		ew_sqt_log( $acct, 'sale.cancelled', 'square_order', $o['order_id'], array(
			'amount'   => $money['amount'],
			'currency' => $money['currency'],
			'items'    => isset( $full['line_items'] ) ? count( (array) $full['line_items'] ) : null,
			'location' => ew_sqt_location( $acct, $o['location_id'] ?? ( $full['location_id'] ?? '' ) ),
		), '', 'rejected' );
	}
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'eastwood/v1', '/square/webhook/(?P<acct>ventures|cic)', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) {
			$acct = (string) $req['acct'];
			$body = (string) $req->get_body();
			if ( ! ew_sqt_sig_ok( $acct, $body, (string) $req->get_header( 'x_square_hmacsha256_signature' ) ) ) {
				if ( function_exists( 'ew_log' ) ) {
					ew_log( 'square.webhook.rejected', array( 'result' => 'rejected', 'source' => 'till', 'actor' => array( 'actor_type' => 'guest', 'actor_label' => 'Unsigned Square call' ), 'detail' => array( 'account' => $acct ) ) );
				}
				return new WP_REST_Response( array( 'message' => 'bad signature' ), 401 );
			}
			$ev = json_decode( $body, true );
			if ( is_array( $ev ) ) {
				ew_sqt_handle( $acct, $ev );
			}
			return array( 'received' => true );
		},
	) );
} );

/* ------------------------------------------------- Settings → Square tills */

add_action( 'admin_menu', function () {
	add_options_page( 'Square tills', 'Square tills', 'manage_options', 'ew-square-tills', 'ew_sqt_page' );
} );

function ew_sqt_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( isset( $_POST['ew_sqt_save'] ) && check_admin_referer( 'ew_sqt_save' ) ) {
		foreach ( array_keys( ew_sqt_accounts() ) as $acct ) {
			if ( isset( $_POST[ 'ew_sqt_label_' . $acct ] ) ) {
				update_option( 'ew_sqt_label_' . $acct, sanitize_text_field( wp_unslash( $_POST[ 'ew_sqt_label_' . $acct ] ) ), false );
			}
			// Secrets: blank keeps the saved one, so saving one account cannot wipe the other.
			foreach ( array( 'token', 'sig' ) as $f ) {
				$k = 'ew_sqt_' . $f . '_' . $acct;
				if ( isset( $_POST[ $k ] ) && '' !== trim( (string) wp_unslash( $_POST[ $k ] ) ) ) {
					update_option( $k, sanitize_text_field( wp_unslash( $_POST[ $k ] ) ), false );
				}
			}
		}
		if ( function_exists( 'ew_log' ) ) {
			ew_log( 'admin.square_tills_saved', array( 'source' => 'wp_admin' ) );
		}
		echo '<div class="notice notice-success"><p>Saved.</p></div>';
	}

	echo '<div class="wrap"><h1>Square tills</h1>';
	echo '<p>Every sale, refund and cancelled sale on each Square account goes into the activity log (Supporters → Activity) the moment it happens.</p>';
	echo '<form method="post">';
	wp_nonce_field( 'ew_sqt_save' );
	foreach ( ew_sqt_accounts() as $acct => $name ) {
		$c   = ew_sqt_cfg( $acct );
		$set = function ( $v, $what ) {
			return '' !== $v ? $what . ': <strong>set (' . (int) strlen( $v ) . ' characters)</strong>' : '<strong>No ' . $what . ' saved.</strong>';
		};
		printf( '<h2>%s</h2><table class="form-table"><tbody>', esc_html( $name ) );
		printf(
			'<tr><th scope="row">Name in the log</th><td><input type="text" name="ew_sqt_label_%s" size="40" value="%s"></td></tr>',
			esc_attr( $acct ),
			esc_attr( $c['label'] )
		);
		printf(
			'<tr><th scope="row">Notification URL</th><td><code>%s</code><p class="description">%s</p></td></tr>',
			esc_html( ew_sqt_url( $acct ) ),
			esc_html( 'In this Square account: Developer dashboard → your app → Webhooks → Add subscription (Production). Paste this URL exactly and tick payment.created, payment.updated, refund.created, refund.updated, order.created and order.updated.' )
		);
		printf(
			'<tr><th scope="row">Webhook signature key</th><td><p>%s</p><input type="password" name="ew_sqt_sig_%s" size="50" autocomplete="off" placeholder="%s"><p class="description">%s</p></td></tr>',
			wp_kses_post( $set( $c['sig'], 'Signature key' ) ),
			esc_attr( $acct ),
			esc_attr( '' !== $c['sig'] ? 'paste a new one to replace it' : 'shown on the subscription after you save it' ),
			esc_html( 'Left blank, the one saved is kept.' )
		);
		printf(
			'<tr><th scope="row">Access token (Production)</th><td><p>%s</p><input type="password" name="ew_sqt_token_%s" size="50" autocomplete="off" placeholder="%s"><p class="description">%s</p></td></tr>',
			wp_kses_post( $set( $c['token'], 'Access token' ) ),
			esc_attr( $acct ),
			esc_attr( '' !== $c['token'] ? 'paste a new one to replace it' : 'begins EAAA' ),
			esc_html( 'Used only to look up the till, the staff member and the customer email. Left blank, the one saved is kept.' )
		);
		echo '</tbody></table>';
	}
	submit_button( 'Save Square tills', 'primary', 'ew_sqt_save' );
	echo '</form></div>';
}
