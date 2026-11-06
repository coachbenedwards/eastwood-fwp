<?php
/**
 * Activity log: every action by anyone, staff and supporters alike.
 *
 * One append-only table, public.activity_log, in the fan app Supabase project
 * (supabase/migrations/20261106_activity_log.sql in the app repo). This file
 * collects events during a request and sends them in one call when the
 * request ends, so logging never slows or breaks what the person was doing.
 *
 *   ew_log( 'ticket.scan.ok', array( 'object_type' => 'booking', 'object_id' => 123 ) );
 *
 * Wired in here, without touching each feature:
 *   - every write to the site API (eastwood/v1), plus ticket checks, with the
 *     result (ticket scans: ok, duplicate, unpaid, not found)
 *   - bookings created, every status change (paid, claimed, cancelled,
 *     refunded...), gate check-ins, bookings trashed or deleted
 *   - wp-admin: sign-ins, failed sign-ins, sign-outs, every admin form sent,
 *     posts and pages saved, trashed and deleted, club settings changed,
 *     plugin and theme updates, users changed
 * Supporter sign-ups, sign-ins (with the device) and password resets are read
 * from Auth itself through the activity_feed view, not copied.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Events waiting to be sent at the end of this request. */
function &ew_log_queue() {
	static $q = array();
	return $q;
}

/** Where this request came from: the app (by its HTTP client), wp-admin or the website. */
function ew_log_source() {
	$ua = (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( 0 === strpos( $ua, 'Expo/' ) || false !== strpos( $ua, 'CFNetwork' ) ) {
		return 'app_ios';
	}
	if ( 0 === strpos( $ua, 'okhttp/' ) ) {
		return 'app_android';
	}
	if ( is_admin() && ! wp_doing_ajax() ) {
		return 'wp_admin';
	}
	if ( wp_doing_cron() ) {
		return 'system';
	}
	return 'website';
}

/**
 * Who is acting. A signed-in WordPress user is staff; a supporter token in
 * X-Eastwood-Fan (read only for the label: the features that act on it
 * verify it themselves) is that supporter; the app scanner sends
 * X-Eastwood-Staff; anyone else is a guest.
 */
function ew_log_actor() {
	static $who = null;
	if ( null !== $who ) {
		return $who;
	}
	$who = array( 'actor_type' => 'guest' );
	if ( wp_doing_cron() ) {
		return $who = array( 'actor_type' => 'system', 'actor_label' => 'Scheduled job' );
	}
	$u = wp_get_current_user();
	if ( $u && $u->exists() ) {
		return $who = array(
			'actor_type'  => 'staff',
			'actor_id'    => 'wp:' . $u->ID,
			'actor_label' => $u->display_name,
			'actor_email' => strtolower( $u->user_email ),
		);
	}
	$fan = (string) ( $_SERVER['HTTP_X_EASTWOOD_FAN'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( '' === $fan && preg_match( '/^Bearer\s+(\S+)/', (string) ( $_SERVER['HTTP_AUTHORIZATION'] ?? '' ), $m ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$fan = $m[1];
	}
	$parts = explode( '.', $fan );
	if ( 3 === count( $parts ) ) {
		$p = json_decode( (string) base64_decode( strtr( $parts[1], '-_', '+/' ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		if ( is_array( $p ) && ! empty( $p['sub'] ) && 'authenticated' === ( $p['role'] ?? '' ) ) {
			return $who = array(
				'actor_type'  => 'supporter',
				'actor_id'    => (string) $p['sub'],
				'actor_label' => (string) ( $p['user_metadata']['full_name'] ?? ( $p['email'] ?? '' ) ),
				'actor_email' => strtolower( (string) ( $p['email'] ?? '' ) ),
			);
		}
	}
	$staff = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_EASTWOOD_STAFF'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( '' !== $staff ) {
		$email = preg_match( '/<([^>]+)>/', $staff, $m ) ? strtolower( $m[1] ) : ( is_email( $staff ) ? strtolower( $staff ) : '' );
		return $who = array( 'actor_type' => 'staff', 'actor_id' => $email ? 'app:' . $email : null, 'actor_label' => trim( preg_replace( '/<[^>]*>?/', '', $staff ) ) ?: $staff, 'actor_email' => $email );
	}
	return $who;
}

/**
 * Record one action. $args: object_type, object_id, result (ok|failed|rejected),
 * detail (array), email (the person's email when they are a guest),
 * actor (to override who did it).
 */
function ew_log( $action, $args = array() ) {
	$actor = isset( $args['actor'] ) && is_array( $args['actor'] ) ? $args['actor'] : ew_log_actor();
	$row   = array_merge( array(
		'actor_type'  => 'guest',
		'actor_id'    => null,
		'actor_label' => null,
		'actor_email' => null,
	), $actor );
	if ( 'guest' === $row['actor_type'] && ! empty( $args['email'] ) ) {
		$row['actor_email'] = strtolower( sanitize_email( $args['email'] ) );
		$row['actor_label'] = $row['actor_label'] ?: $row['actor_email'];
	}
	$detail = isset( $args['detail'] ) && is_array( $args['detail'] ) ? $args['detail'] : array();
	$q      = &ew_log_queue();
	$q[]    = array_merge( $row, array(
		'at'          => gmdate( 'Y-m-d\TH:i:s.u\Z' ),
		'action'      => substr( (string) $action, 0, 120 ),
		'object_type' => isset( $args['object_type'] ) ? (string) $args['object_type'] : null,
		'object_id'   => isset( $args['object_id'] ) ? (string) $args['object_id'] : null,
		'source'      => $args['source'] ?? ew_log_source(),
		'result'      => in_array( $args['result'] ?? 'ok', array( 'ok', 'failed', 'rejected' ), true ) ? ( $args['result'] ?? 'ok' ) : 'ok',
		'detail'      => (object) $detail,
		'ip'          => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : null,
		'user_agent'  => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 300 ) : null,
	) );
}

/** Send everything collected, in one call, after the response. Failures are ignored on purpose. */
add_action( 'shutdown', function () {
	$q = &ew_log_queue();
	if ( ! $q || ! function_exists( 'ew_runs_key' ) || '' === ew_runs_key() || ! defined( 'EW_FAN_SUPABASE' ) ) {
		return;
	}
	$rows = $q;
	$q    = array();
	$key  = ew_runs_key();
	wp_remote_post( EW_FAN_SUPABASE . '/rest/v1/activity_log', array(
		'blocking' => false,
		'timeout'  => 2,
		'headers'  => array(
			'apikey'        => $key,
			'Authorization' => 'Bearer ' . $key,
			'Content-Type'  => 'application/json',
			'Prefer'        => 'return=minimal',
		),
		'body'     => wp_json_encode( $rows ),
	) );
}, 99 );

/* --------------------------------------------------------- the site API */

/** Friendly action names for the routes; anything else is logged as api.<route>. */
function ew_log_route_names() {
	return array(
		'/claim'              => 'claim.completed',
		'/claim/school'       => 'claim.school_chosen',
		'/claim/account'      => 'claim.account_checked',
		'/claim/in'           => 'claim.signed_in',
		'/claim/shirt'        => 'claim.shirt',
		'/optin/start'        => 'form.started',
		'/optin/save'         => 'form.completed',
		'/tickets/checkout'   => 'sale.checkout_started',
		'/tickets/match/start'=> 'sale.match_ticket_started',
		'/tickets/match'      => 'sale.match_ticket_checkout',
		'/tickets/upsell'     => 'sale.upsell',
		'/shirt/start'        => 'sale.shirt_started',
		'/shirt/checkout'     => 'sale.shirt_checkout',
		'/invite/start'       => 'invite.started',
		'/invite/checkout'    => 'invite.checkout',
		'/invite/make'        => 'invite.made',
		'/community'          => 'community.applied',
		'/trial'              => 'trial.registered',
		'/halftime'           => 'halftime.applied',
		'/halftime/invite'    => 'halftime.invited',
		'/halftime/vote'      => 'halftime.voted',
		'/academy/ask'        => 'academy.question',
		'/academy/interest'   => 'academy.interest',
		'/academy/meeting'    => 'academy.meeting_booked',
		'/jobs'               => 'job.applied',
		'/jobs/video-url'     => 'job.video_upload_started',
		'/jobs/video-done'    => 'job.video_uploaded',
		'/feedback/send'      => 'feedback.sent',
		'/app/mine'           => 'account.tickets_viewed',
		'/app/link-email'     => 'account.email_linked',
		'/app/claim'          => 'account.booking_claimed',
		'/app/wallet'         => 'account.wallet_pass',
		'/food/use'           => 'food.redeem',
		'/runs-admin/plan'    => 'runs.plan_changed',
		'/blocks'             => 'admin.page_blocks_changed',
		'/block'              => 'admin.page_block_changed',
		'/row'                => 'admin.page_row_changed',
		'/page-blocks'        => 'admin.page_laid_out',
		'/squad'              => 'admin.squad_changed',
	);
}

/** Routes too frequent or too passive to log (page hits are counted elsewhere). */
function ew_log_route_skip( $route, $method ) {
	if ( in_array( $route, array( '/hit', '/doc-reached', '/fwp', '/update-status', '/r', '/auth/failed', '/auth/reset', '/stripe/webhook' ), true ) || 0 === strpos( $route, '/square/webhook' ) ) {
		return true;
	}
	// Reads are not actions, except a gate checking a ticket.
	return 'GET' === $method && '/ticket/verify' !== $route && '/app/mine' !== $route;
}

add_filter( 'rest_request_after_callbacks', function ( $response, $handler, $request ) {
	$route = (string) $request->get_route();
	if ( 0 !== strpos( $route, '/eastwood/v1/' ) ) {
		return $response;
	}
	$short  = substr( $route, strlen( '/eastwood/v1' ) );
	$method = $request->get_method();
	if ( ew_log_route_skip( $short, $method ) ) {
		return $response;
	}
	$code = is_wp_error( $response ) ? 500 : ( $response instanceof WP_REST_Response ? (int) $response->get_status() : 200 );
	$data = $response instanceof WP_REST_Response ? $response->get_data() : ( is_wp_error( $response ) ? array( 'message' => $response->get_error_message() ) : $response );
	$data = is_array( $data ) ? $data : array();
	$ref  = (string) ( $request->get_param( 'ref' ) ?? '' );

	// Ticket and food scans: the result is in the answer, not the status code.
	if ( in_array( $short, array( '/ticket/checkin', '/ticket/verify', '/ticket/food-use' ), true ) ) {
		$st     = (string) ( $data['status'] ?? ( $code >= 400 ? 'error' : '' ) );
		$verb   = '/ticket/verify' === $short ? 'ticket.check' : ( '/ticket/food-use' === $short ? 'food.redeem' : 'ticket.scan' );
		$ok     = array( 'valid', 'food' );
		$action = $verb . '.' . ( in_array( $st, $ok, true ) ? 'ok' : ( 'used' === $st ? 'duplicate' : ( $st ?: 'unknown' ) ) );
		ew_log( $action, array(
			'object_type' => 'booking',
			'object_id'   => $data['code'] ?? $ref,
			'result'      => in_array( $st, $ok, true ) ? 'ok' : ( in_array( $st, array( 'used', 'unpaid', 'notfound' ), true ) ? 'rejected' : 'failed' ),
			'detail'      => array_filter( array(
				'ref'    => $ref,
				'status' => $st,
				'name'   => $data['name'] ?? null,
				'event'  => $data['event'] ?? null,
				'kind'   => $data['kind'] ?? null,
				'admits' => $data['headcount'] ?? null,
				'token'  => $request->get_param( 'i' ),
			), function ( $v ) {
				return null !== $v && '' !== $v;
			} ),
		) );
		return $response;
	}

	$names  = ew_log_route_names();
	$action = $names[ $short ] ?? 'api' . str_replace( '/', '.', $short );
	$detail = array( 'route' => $short );
	foreach ( array( 'booking', 'booking_id', 'id', 'ref', 'reference', 'status' ) as $k ) {
		if ( isset( $data[ $k ] ) && is_scalar( $data[ $k ] ) ) {
			$detail[ $k ] = $data[ $k ];
		}
	}
	if ( $code >= 400 && ! empty( $data['message'] ) ) {
		$detail['message'] = substr( (string) $data['message'], 0, 300 );
	}
	foreach ( array( 'school', 'role', 'kind', 'event', 'qty', 'adults', 'children', 'size' ) as $k ) {
		$v = $request->get_param( $k );
		if ( null !== $v && is_scalar( $v ) && '' !== $v ) {
			$detail[ $k ] = $v;
		}
	}
	$email = $request->get_param( 'email' );
	ew_log( $action, array(
		'object_type' => isset( $detail['booking'] ) || isset( $detail['booking_id'] ) ? 'booking' : null,
		'object_id'   => $detail['booking_id'] ?? ( $detail['booking'] ?? null ),
		'result'      => $code >= 500 ? 'failed' : ( $code >= 400 ? 'rejected' : 'ok' ),
		'detail'      => $detail,
		'email'       => is_string( $email ) ? $email : '',
	) );
	return $response;
}, 99, 3 );

/* ------------------------------------------------------------- bookings */

/** The email on a booking, for linking a guest's actions to them later. */
function ew_log_booking_email( $id ) {
	return strtolower( (string) get_post_meta( $id, '_ewt_email', true ) );
}

add_action( 'wp_insert_post', function ( $id, $post, $update ) {
	if ( $update || 'ewt_booking' !== $post->post_type || wp_is_post_revision( $id ) ) {
		return;
	}
	ew_log( 'booking.created', array(
		'object_type' => 'booking',
		'object_id'   => $id,
		'detail'      => array( 'title' => $post->post_title ),
	) );
}, 99, 3 );

/** Status changes and gate check-ins, however they happen (checkout, Stripe, staff, scanner). */
function ew_log_booking_meta( $meta_id, $id, $key, $value ) {
	if ( ! in_array( $key, array( '_ewt_status', '_ewt_in', '_ewt_kind' ), true ) || 'ewt_booking' !== get_post_type( $id ) ) {
		return;
	}
	$email = ew_log_booking_email( $id );
	if ( '_ewt_kind' === $key ) {
		ew_log( 'booking.kind', array( 'object_type' => 'booking', 'object_id' => $id, 'detail' => array( 'kind' => (string) $value ), 'email' => $email ) );
		return;
	}
	if ( '_ewt_in' === $key ) {
		if ( $value ) {
			ew_log( 'booking.checked_in', array( 'object_type' => 'booking', 'object_id' => $id, 'detail' => array( 'at' => (int) $value ), 'email' => $email ) );
		}
		return;
	}
	$st  = sanitize_key( (string) $value );
	$map = array(
		'paid'      => 'sale.paid',
		'refunded'  => 'sale.refunded',
		'cancelled' => 'booking.cancelled',
		'canceled'  => 'booking.cancelled',
		'failed'    => 'sale.failed',
		'claimed'   => 'booking.claimed',
		'started'   => 'booking.started',
	);
	ew_log( $map[ $st ] ?? 'booking.status.' . ( $st ?: 'blank' ), array(
		'object_type' => 'booking',
		'object_id'   => $id,
		'result'      => 'failed' === $st ? 'failed' : 'ok',
		'detail'      => array(
			'status' => $st,
			'kind'   => (string) get_post_meta( $id, '_ewt_kind', true ),
			'total'  => get_post_meta( $id, '_ewt_total', true ),
			'title'  => get_the_title( $id ),
		),
		'email'       => $email,
	) );
}
add_action( 'added_post_meta', 'ew_log_booking_meta', 99, 4 );
add_action( 'updated_post_meta', 'ew_log_booking_meta', 99, 4 );

add_action( 'wp_trash_post', function ( $id ) {
	$type = get_post_type( $id );
	ew_log( 'ewt_booking' === $type ? 'booking.trashed' : 'admin.post_trashed', array( 'object_type' => $type, 'object_id' => $id, 'detail' => array( 'title' => get_the_title( $id ) ) ) );
} );
add_action( 'before_delete_post', function ( $id ) {
	$type = get_post_type( $id );
	if ( 'revision' === $type ) {
		return;
	}
	ew_log( 'ewt_booking' === $type ? 'booking.deleted' : 'admin.post_deleted', array( 'object_type' => $type, 'object_id' => $id, 'detail' => array( 'title' => get_the_title( $id ) ) ) );
} );
add_action( 'untrashed_post', function ( $id ) {
	ew_log( 'admin.post_restored', array( 'object_type' => get_post_type( $id ), 'object_id' => $id, 'detail' => array( 'title' => get_the_title( $id ) ) ) );
} );

/* ------------------------------------------------------------- wp-admin */

add_action( 'wp_login', function ( $login, $user ) {
	ew_log( 'staff.signin', array( 'actor' => array( 'actor_type' => 'staff', 'actor_id' => 'wp:' . $user->ID, 'actor_label' => $user->display_name, 'actor_email' => strtolower( $user->user_email ) ) ) );
}, 10, 2 );
add_action( 'wp_login_failed', function ( $login ) {
	ew_log( 'staff.signin.failed', array( 'result' => 'failed', 'actor' => array( 'actor_type' => 'guest', 'actor_label' => sanitize_text_field( $login ) ) ) );
} );
add_action( 'wp_logout', function ( $user_id ) {
	$u = get_userdata( $user_id );
	ew_log( 'staff.signout', array( 'actor' => array( 'actor_type' => 'staff', 'actor_id' => 'wp:' . (int) $user_id, 'actor_label' => $u ? $u->display_name : '', 'actor_email' => $u ? strtolower( $u->user_email ) : '' ) ) );
} );

/** Every admin form sent (admin-post.php and wp-admin pages posting back). Field values are not stored. */
add_action( 'admin_init', function () {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || wp_doing_ajax() ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return;
	}
	$action = sanitize_key( $_REQUEST['action'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
	$page   = sanitize_key( $_REQUEST['page'] ?? '' );   // phpcs:ignore WordPress.Security.NonceVerification
	$screen = basename( (string) ( $_SERVER['SCRIPT_NAME'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( in_array( $screen, array( 'post.php', 'async-upload.php' ), true ) ) {
		return; // saves are logged by save_post
	}
	ew_log( 'admin.form_sent', array( 'detail' => array_filter( array( 'screen' => $screen, 'page' => $page, 'action' => $action ) ) ) );
} );

add_action( 'save_post', function ( $id, $post, $update ) {
	if ( wp_is_post_revision( $id ) || wp_is_post_autosave( $id ) || in_array( $post->post_type, array( 'ewt_booking', 'revision', 'customize_changeset', 'oembed_cache', 'wp_global_styles' ), true ) ) {
		return;
	}
	if ( ! is_user_logged_in() ) {
		return;
	}
	ew_log( $update ? 'admin.post_saved' : 'admin.post_created', array(
		'object_type' => $post->post_type,
		'object_id'   => $id,
		'detail'      => array( 'title' => $post->post_title, 'status' => $post->post_status ),
	) );
}, 99, 3 );

/** Club settings changed by a person (not caches or scheduled jobs). */
add_action( 'updated_option', function ( $name ) {
	if ( wp_doing_cron() || ! is_user_logged_in() || 0 === strpos( $name, '_' ) ) {
		return;
	}
	if ( 0 !== strpos( $name, 'ew_' ) && ! in_array( $name, array( 'blogname', 'siteurl', 'home', 'admin_email', 'users_can_register', 'default_role', 'timezone_string', 'permalink_structure', 'active_plugins' ), true ) ) {
		return;
	}
	ew_log( 'admin.setting_changed', array( 'object_type' => 'option', 'object_id' => $name ) );
} );

add_action( 'upgrader_process_complete', function ( $upgrader, $extra ) {
	ew_log( 'admin.updated', array( 'detail' => array(
		'type'    => (string) ( $extra['type'] ?? '' ),
		'items'   => array_values( (array) ( $extra['plugins'] ?? ( $extra['themes'] ?? ( $extra['plugin'] ?? array() ) ) ) ),
	) ) );
}, 10, 2 );
add_action( 'activated_plugin', function ( $plugin ) {
	ew_log( 'admin.plugin_activated', array( 'object_type' => 'plugin', 'object_id' => $plugin ) );
} );
add_action( 'deactivated_plugin', function ( $plugin ) {
	ew_log( 'admin.plugin_deactivated', array( 'object_type' => 'plugin', 'object_id' => $plugin ) );
} );
add_action( 'user_register', function ( $uid ) {
	ew_log( 'admin.user_created', array( 'object_type' => 'user', 'object_id' => $uid ) );
} );
add_action( 'profile_update', function ( $uid ) {
	ew_log( 'admin.user_changed', array( 'object_type' => 'user', 'object_id' => $uid ) );
} );
add_action( 'set_user_role', function ( $uid, $role, $old ) {
	ew_log( 'admin.user_role_changed', array( 'object_type' => 'user', 'object_id' => $uid, 'detail' => array( 'role' => $role, 'was' => array_values( (array) $old ) ) ) );
}, 10, 3 );
add_action( 'delete_user', function ( $uid ) {
	ew_log( 'admin.user_deleted', array( 'object_type' => 'user', 'object_id' => $uid ) );
} );
