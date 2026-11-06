<?php
/**
 * Stripe tells the site the moment money moves: paid, failed, refunded
 * (including refunds done straight in the Stripe dashboard) and disputed.
 *
 *   POST /wp-json/eastwood/v1/stripe/webhook
 *
 * Every call is checked against the signing secret saved on the Settings
 * screen (Stripe, "Webhook signing secret") before anything is believed.
 * The matching Booking is updated once, however many times Stripe repeats
 * itself, and each event goes in the activity log with its amount.
 *
 * Bookings keep their meaning for the gate: a full refund or an open dispute
 * stops the ticket scanning; a partial refund leaves it paid and records how
 * much went back.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The signing secret for one environment (or the one in use). */
function ew_stripe_whsec( $env = null ) {
	$env = in_array( $env, array( 'live', 'test' ), true ) ? $env : ( function_exists( 'ew_stripe_env' ) ? ew_stripe_env() : 'live' );
	return (string) get_option( 'ew_stripe_whsec_' . $env, '' );
}

/** True when the Stripe-Signature header was made with one of our secrets. */
function ew_stripe_sig_ok( $payload, $header ) {
	$t  = 0;
	$v1 = array();
	foreach ( explode( ',', (string) $header ) as $part ) {
		$kv = explode( '=', trim( $part ), 2 );
		if ( 2 !== count( $kv ) ) {
			continue;
		}
		if ( 't' === $kv[0] ) {
			$t = (int) $kv[1];
		} elseif ( 'v1' === $kv[0] ) {
			$v1[] = $kv[1];
		}
	}
	if ( ! $t || ! $v1 || abs( time() - $t ) > 300 ) {
		return false;
	}
	foreach ( array( 'live', 'test' ) as $env ) {
		$secret = ew_stripe_whsec( $env );
		if ( '' === $secret ) {
			continue;
		}
		$want = hash_hmac( 'sha256', $t . '.' . $payload, $secret );
		foreach ( $v1 as $sig ) {
			if ( hash_equals( $want, $sig ) ) {
				return true;
			}
		}
	}
	return false;
}

/** The Booking a payment belongs to: from metadata, a remembered payment, or by asking Stripe. */
function ew_stripe_booking_for_pi( $pi, $meta = array() ) {
	$id = (int) ( $meta['booking'] ?? 0 );
	if ( $id && 'ewt_booking' === get_post_type( $id ) ) {
		return $id;
	}
	$pi = (string) $pi;
	if ( '' === $pi ) {
		return 0;
	}
	$found = get_posts( array(
		'post_type'      => 'ewt_booking',
		'post_status'    => 'any',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'meta_key'       => '_ewt_pi', // phpcs:ignore WordPress.DB.SlowDBQuery
		'meta_value'     => $pi, // phpcs:ignore WordPress.DB.SlowDBQuery
	) );
	if ( $found ) {
		return (int) $found[0];
	}
	if ( function_exists( 'ew_tix_stripe' ) ) {
		$list = ew_tix_stripe( 'GET', 'checkout/sessions?limit=1&payment_intent=' . rawurlencode( $pi ) );
		$s    = $list['data'][0] ?? null;
		$id   = (int) ( $s['metadata']['booking'] ?? 0 );
		if ( $id && 'ewt_booking' === get_post_type( $id ) ) {
			update_post_meta( $id, '_ewt_pi', $pi );
			return $id;
		}
	}
	return 0;
}

/** Change a Booking's status without the general status log (the sale.* entry below carries the amount). */
function ew_stripe_set_status( $bid, $status ) {
	if ( ! $bid || (string) get_post_meta( $bid, '_ewt_status', true ) === $status ) {
		return false;
	}
	$GLOBALS['ew_log_quiet_status'] = true;
	update_post_meta( $bid, '_ewt_status', $status );
	$GLOBALS['ew_log_quiet_status'] = false;
	return true;
}

function ew_stripe_log( $action, $bid, $obj, $extra = array(), $result = 'ok' ) {
	if ( ! function_exists( 'ew_log' ) ) {
		return;
	}
	$email = $bid ? strtolower( (string) get_post_meta( $bid, '_ewt_email', true ) ) : '';
	if ( '' === $email ) {
		$email = strtolower( (string) ( $obj['customer_details']['email'] ?? ( $obj['receipt_email'] ?? ( $obj['billing_details']['email'] ?? '' ) ) ) );
	}
	ew_log( $action, array(
		'object_type' => $bid ? 'booking' : 'stripe',
		'object_id'   => $bid ? $bid : ( $obj['id'] ?? null ),
		'result'      => $result,
		'source'      => 'system',
		'actor'       => array( 'actor_type' => 'system', 'actor_label' => 'Stripe' ),
		'email'       => $email,
		'detail'      => array_filter( array_merge( array(
			'stripe_id' => $obj['id'] ?? null,
			'currency'  => isset( $obj['currency'] ) ? strtoupper( (string) $obj['currency'] ) : null,
			'live'      => isset( $obj['livemode'] ) ? (bool) $obj['livemode'] : null,
			'title'     => $bid ? get_the_title( $bid ) : null,
			'email'     => '' !== $email ? $email : null,
		), $extra ), function ( $v ) {
			return null !== $v && '' !== $v;
		} ),
	) );
}

/** One Stripe event. Safe to run twice for the same event. */
function ew_stripe_handle( $ev ) {
	$type = (string) ( $ev['type'] ?? '' );
	$o    = (array) ( $ev['data']['object'] ?? array() );
	$done = 'ew_stripe_ev_' . md5( (string) ( $ev['id'] ?? '' ) );
	if ( '' === (string) ( $ev['id'] ?? '' ) || get_transient( $done ) ) {
		return;
	}
	set_transient( $done, 1, 7 * DAY_IN_SECONDS );

	switch ( $type ) {
		case 'checkout.session.completed':
		case 'checkout.session.async_payment_succeeded':
			$bid = (int) ( $o['metadata']['booking'] ?? 0 );
			$bid = ( $bid && 'ewt_booking' === get_post_type( $bid ) ) ? $bid : 0;
			if ( $bid && ! empty( $o['payment_intent'] ) && is_string( $o['payment_intent'] ) ) {
				update_post_meta( $bid, '_ewt_pi', $o['payment_intent'] );
			}
			$paid = function_exists( 'ew_tix_is_paid' ) ? ew_tix_is_paid( $o ) : ( 'paid' === ( $o['payment_status'] ?? '' ) );
			if ( $bid && $paid && function_exists( 'ew_tix_mark_paid' ) ) {
				// The same path as coming back from Stripe in the browser, so it only happens once.
				$GLOBALS['ew_log_quiet_status'] = true;
				ew_tix_mark_paid( $bid, $o, (string) get_post_meta( $bid, '_ewt_hook', true ) );
				$GLOBALS['ew_log_quiet_status'] = false;
			}
			if ( $paid ) {
				ew_stripe_log( 'sale.paid', $bid, $o, array( 'amount' => (int) ( $o['amount_total'] ?? 0 ), 'via' => 'checkout' ) );
			}
			break;

		case 'checkout.session.async_payment_failed':
			$bid = (int) ( $o['metadata']['booking'] ?? 0 );
			ew_stripe_log( 'sale.failed', ( $bid && 'ewt_booking' === get_post_type( $bid ) ) ? $bid : 0, $o, array( 'amount' => (int) ( $o['amount_total'] ?? 0 ), 'via' => 'checkout' ), 'failed' );
			break;

		case 'payment_intent.succeeded':
			// Checkout payments are logged from the session; this catches the rest (add-ons charged on a saved card).
			$bid = ew_stripe_booking_for_pi( $o['id'] ?? '', (array) ( $o['metadata'] ?? array() ) );
			if ( empty( $o['metadata']['booking'] ) && $bid && get_post_meta( $bid, '_ewt_pi', true ) === ( $o['id'] ?? '' ) ) {
				break; // already logged as the checkout itself
			}
			ew_stripe_log( 'sale.paid', $bid, $o, array( 'amount' => (int) ( $o['amount_received'] ?? ( $o['amount'] ?? 0 ) ), 'via' => 'payment' ) );
			break;

		case 'payment_intent.payment_failed':
			$bid = ew_stripe_booking_for_pi( $o['id'] ?? '', (array) ( $o['metadata'] ?? array() ) );
			if ( $bid ) {
				update_post_meta( $bid, '_ewt_payment_failed', time() );
			}
			ew_stripe_log( 'sale.failed', $bid, $o, array(
				'amount' => (int) ( $o['amount'] ?? 0 ),
				'reason' => (string) ( $o['last_payment_error']['code'] ?? ( $o['last_payment_error']['decline_code'] ?? '' ) ),
			), 'failed' );
			break;

		case 'charge.refunded':
			$bid      = ew_stripe_booking_for_pi( $o['payment_intent'] ?? '', (array) ( $o['metadata'] ?? array() ) );
			$refunded = (int) ( $o['amount_refunded'] ?? 0 );
			$full     = ! empty( $o['refunded'] ) || $refunded >= (int) ( $o['amount'] ?? 0 );
			if ( $bid ) {
				update_post_meta( $bid, '_ewt_refunded', $refunded );
				if ( $full ) {
					ew_stripe_set_status( $bid, 'refunded' );
				}
			}
			ew_stripe_log( 'sale.refunded', $bid, $o, array(
				'amount'          => (int) ( $o['amount'] ?? 0 ),
				'refunded'        => $refunded,
				'refunded_change' => (int) ( $ev['data']['previous_attributes']['amount_refunded'] ?? -1 ) >= 0 ? $refunded - (int) $ev['data']['previous_attributes']['amount_refunded'] : null,
				'full'            => $full ? 'yes' : 'partial',
				'card'            => trim( (string) ( $o['payment_method_details']['card']['brand'] ?? '' ) . ' ' . (string) ( $o['payment_method_details']['card']['last4'] ?? '' ) ),
			) );
			break;

		case 'charge.dispute.created':
			$bid = ew_stripe_booking_for_pi( $o['payment_intent'] ?? '' );
			if ( $bid ) {
				if ( '' === (string) get_post_meta( $bid, '_ewt_status_before_dispute', true ) ) {
					update_post_meta( $bid, '_ewt_status_before_dispute', (string) get_post_meta( $bid, '_ewt_status', true ) );
				}
				ew_stripe_set_status( $bid, 'disputed' );
			}
			ew_stripe_log( 'sale.disputed', $bid, $o, array( 'amount' => (int) ( $o['amount'] ?? 0 ), 'reason' => (string) ( $o['reason'] ?? '' ) ), 'rejected' );
			break;

		case 'charge.dispute.closed':
			$bid = ew_stripe_booking_for_pi( $o['payment_intent'] ?? '' );
			$won = 'won' === (string) ( $o['status'] ?? '' );
			if ( $bid ) {
				$before = (string) get_post_meta( $bid, '_ewt_status_before_dispute', true );
				ew_stripe_set_status( $bid, $won ? ( $before ?: 'paid' ) : 'refunded' );
			}
			ew_stripe_log( $won ? 'sale.dispute_won' : 'sale.dispute_lost', $bid, $o, array( 'amount' => (int) ( $o['amount'] ?? 0 ), 'status' => (string) ( $o['status'] ?? '' ) ), $won ? 'ok' : 'failed' );
			break;
	}
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'eastwood/v1', '/stripe/webhook', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) {
			$payload = (string) $req->get_body();
			if ( ! ew_stripe_sig_ok( $payload, (string) $req->get_header( 'stripe_signature' ) ) ) {
				if ( function_exists( 'ew_log' ) ) {
					ew_log( 'stripe.webhook.rejected', array( 'result' => 'rejected', 'source' => 'system', 'actor' => array( 'actor_type' => 'guest', 'actor_label' => 'Unsigned Stripe call' ) ) );
				}
				return new WP_REST_Response( array( 'message' => 'bad signature' ), 400 );
			}
			$ev = json_decode( $payload, true );
			if ( is_array( $ev ) ) {
				ew_stripe_handle( $ev );
			}
			return array( 'received' => true );
		},
	) );
} );
