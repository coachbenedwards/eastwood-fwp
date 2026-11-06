<?php
/**
 * Failed supporter sign-ins and password reset requests, for the activity log.
 *
 * Supabase turns a wrong password away without keeping any record on this
 * plan, so the sign-in screens (My Tickets, the claim page, the app) report
 * each failure here after Supabase has said no. Only the email tried and the
 * reason are sent; a password never is. Rate-limited per address so it cannot
 * be used to flood the log.
 *
 *   POST /wp-json/eastwood/v1/auth/failed   { email, reason, where, platform }
 *   POST /wp-json/eastwood/v1/auth/reset    { email, where, platform }
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** True while this address is under its limit (30 reports an hour). */
function ew_auth_log_allowed() {
	$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
	$key = 'ew_authlog_' . md5( $ip );
	$n   = (int) get_transient( $key );
	if ( $n >= 30 ) {
		return false;
	}
	set_transient( $key, $n + 1, HOUR_IN_SECONDS );
	return true;
}

/** Where the report came from: the app says which phone, anything else is the website. */
function ew_auth_log_source( $req ) {
	$p = (string) $req->get_param( 'platform' );
	if ( in_array( $p, array( 'ios', 'app_ios' ), true ) ) {
		return 'app_ios';
	}
	if ( in_array( $p, array( 'android', 'app_android' ), true ) ) {
		return 'app_android';
	}
	return function_exists( 'ew_log_source' ) ? ew_log_source() : 'website';
}

/** A short, known reason from whatever the screen sent. */
function ew_auth_log_reason( $raw, $email ) {
	$r = strtolower( substr( (string) $raw, 0, 120 ) );
	if ( preg_match( '/rate|too many|429/', $r ) ) {
		return 'too_many_tries';
	}
	if ( preg_match( '/cancel/', $r ) ) {
		return 'cancelled';
	}
	if ( preg_match( '/code|otp|token/', $r ) ) {
		return 'wrong_code';
	}
	if ( preg_match( '/confirm/', $r ) ) {
		return 'email_not_confirmed';
	}
	if ( preg_match( '/apple|google|oauth|provider/', $r ) ) {
		return 'provider_error';
	}
	if ( preg_match( '/invalid|credential|password|wrong/', $r ) && '' !== $email && function_exists( 'ew_claim_has_account' ) ) {
		$has = ew_claim_has_account( $email );
		if ( false === $has ) {
			return 'unknown_email';
		}
		if ( true === $has ) {
			return 'wrong_password';
		}
	}
	return '' !== $r ? 'other' : 'unknown';
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'eastwood/v1', '/auth/failed', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) {
			if ( ! function_exists( 'ew_log' ) || ! ew_auth_log_allowed() ) {
				return array( 'ok' => true );
			}
			$email = strtolower( sanitize_email( (string) $req->get_param( 'email' ) ) );
			$raw   = sanitize_text_field( (string) $req->get_param( 'reason' ) );
			ew_log( 'auth.signin.failed', array(
				'object_type' => 'supporter',
				'result'      => 'failed',
				'source'      => ew_auth_log_source( $req ),
				'email'       => $email,
				'actor'       => array( 'actor_type' => 'guest', 'actor_email' => $email ?: null, 'actor_label' => $email ?: null ),
				'detail'      => array_filter( array(
					'reason'  => ew_auth_log_reason( $raw, $email ),
					'message' => substr( $raw, 0, 160 ),
					'where'   => substr( sanitize_key( (string) $req->get_param( 'where' ) ), 0, 40 ),
					'method'  => substr( sanitize_key( (string) $req->get_param( 'method' ) ), 0, 20 ),
				) ),
			) );
			return array( 'ok' => true );
		},
	) );

	register_rest_route( 'eastwood/v1', '/auth/reset', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) {
			if ( ! function_exists( 'ew_log' ) || ! ew_auth_log_allowed() ) {
				return array( 'ok' => true );
			}
			$email = strtolower( sanitize_email( (string) $req->get_param( 'email' ) ) );
			ew_log( 'auth.password_reset_requested', array(
				'object_type' => 'supporter',
				'source'      => ew_auth_log_source( $req ),
				'email'       => $email,
				'actor'       => array( 'actor_type' => 'guest', 'actor_email' => $email ?: null, 'actor_label' => $email ?: null ),
				'detail'      => array( 'where' => substr( sanitize_key( (string) $req->get_param( 'where' ) ), 0, 40 ) ),
			) );
			return array( 'ok' => true );
		},
	) );
} );
