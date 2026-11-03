<?php
/**
 * One booking system: the supporter's account is the key to their tickets.
 *
 * The app sends the signed-in supporter's Supabase token in the header
 * X-Eastwood-Fan. The site asks Supabase who it belongs to (never trusting
 * what the phone says) and then shows every booking made under:
 *
 *   - the account's own email, when Apple or Google has proved it is theirs.
 *     A typed email and password does not prove it (accounts open without a
 *     confirmation email), so that one counts only once a booking has;
 *   - every email the supporter has proved with a booking reference, the
 *     private link from the website, or a wallet already on their phone.
 *     Those proofs are kept against the account, so they follow the
 *     supporter to any phone they sign in on.
 *
 *   POST /eastwood/v1/app/mine  { wallets?: [w1...] }
 *        → { emails, items, linked }   items newest first, as /app/wallet
 *
 * /app/claim (app-wallet.php) links the claimed email to the account too,
 * when it is called while signed in.
 *
 * @package eastwood-fwp
 */

defined( 'ABSPATH' ) || exit;

const EW_FAN_SUPABASE = 'https://gimtavaqkkowjwffeefm.supabase.co';
// The project's public anon key: it names the project, it grants nothing.
const EW_FAN_ANON = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImdpbXRhdmFxa2tvd2p3ZmZlZWZtIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NjI5NDU2OTAsImV4cCI6MjA3ODUyMTY5MH0.YQ8YU3Updk25rIkufNcuVOiaLI7Gt-AekH2VGGOzaMI';

/** The token the app sent, from X-Eastwood-Fan (or Authorization: Bearer). */
function ew_fan_token( $req ) {
	$t = (string) $req->get_header( 'x_eastwood_fan' );
	if ( '' === $t ) {
		$a = (string) $req->get_header( 'authorization' );
		if ( 0 === stripos( $a, 'Bearer ' ) ) {
			$t = substr( $a, 7 );
		}
	}
	return trim( $t );
}

/**
 * Who the token belongs to, asked of Supabase itself. Null when it is not a
 * live supporter session. Kept for five minutes so a busy Tickets tab does
 * not ask on every pull.
 */
function ew_fan_from_token( $token ) {
	if ( '' === $token || strlen( $token ) > 8192 ) {
		return null;
	}
	$ck  = 'ew_fan_tok_' . md5( $token );
	$hit = get_transient( $ck );
	if ( is_array( $hit ) ) {
		return $hit;
	}
	$r = wp_remote_get( EW_FAN_SUPABASE . '/auth/v1/user', array(
		'timeout' => 8,
		'headers' => array(
			'apikey'        => EW_FAN_ANON,
			'Authorization' => 'Bearer ' . $token,
		),
	) );
	if ( is_wp_error( $r ) || 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
		return null;
	}
	$u = json_decode( (string) wp_remote_retrieve_body( $r ), true );
	if ( empty( $u['id'] ) ) {
		return null;
	}
	$email     = strtolower( trim( (string) ( $u['email'] ?? '' ) ) );
	$providers = (array) ( $u['app_metadata']['providers'] ?? array( $u['app_metadata']['provider'] ?? 'email' ) );
	$proven    = $email && is_email( $email ) && ! empty( $u['email_confirmed_at'] )
		&& array_intersect( $providers, array( 'apple', 'google' ) );
	$fan = array(
		'sub'    => sanitize_key( $u['id'] ),
		'email'  => $email,
		'proven' => $proven ? $email : '',
	);
	set_transient( $ck, $fan, 5 * MINUTE_IN_SECONDS );
	return $fan;
}

/** Emails proved against an account, kept in one private option. */
function ew_fan_links_all() {
	$all = get_option( 'ew_fan_links', array() );
	return is_array( $all ) ? $all : array();
}

function ew_fan_link( $sub, $email ) {
	$email = strtolower( trim( (string) $email ) );
	if ( '' === $sub || ! is_email( $email ) ) {
		return;
	}
	$all = ew_fan_links_all();
	$mine = isset( $all[ $sub ] ) ? (array) $all[ $sub ] : array();
	if ( in_array( $email, $mine, true ) ) {
		return;
	}
	$mine[]      = $email;
	$all[ $sub ] = array_slice( array_values( array_unique( $mine ) ), -20 );
	update_option( 'ew_fan_links', $all, false );
}

/** Every email whose bookings this supporter may see. */
function ew_fan_emails( $fan ) {
	$all    = ew_fan_links_all();
	$emails = isset( $all[ $fan['sub'] ] ) ? (array) $all[ $fan['sub'] ] : array();
	if ( '' !== $fan['proven'] ) {
		array_unshift( $emails, $fan['proven'] );
	}
	return array_values( array_unique( $emails ) );
}

/** Bookings they hold under any of these emails, newest first. */
function ew_wallet_ids_for( array $emails ) {
	if ( ! $emails ) {
		return array();
	}
	return get_posts( array(
		'post_type'   => 'ewt_booking',
		'post_status' => 'any',
		'numberposts' => 200,
		'fields'      => 'ids',
		'orderby'     => 'date',
		'order'       => 'DESC',
		'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery
			array( 'key' => '_ewt_email', 'value' => $emails, 'compare' => 'IN' ),
			// Started and never finished is not something they hold.
			array( 'key' => '_ewt_status', 'value' => 'started', 'compare' => '!=' ),
		),
	) );
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'eastwood/v1', '/app/mine', array(
		'methods'             => array( 'GET', 'POST' ),
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) {
			$fan = ew_fan_from_token( ew_fan_token( $req ) );
			if ( ! $fan ) {
				return new WP_REST_Response( array( 'message' => 'Please sign in again.' ), 401 );
			}
			// Wallets already on this phone prove their email: keep it on the account.
			foreach ( array_slice( (array) $req->get_param( 'wallets' ), 0, 10 ) as $w ) {
				$e = ew_wallet_email( (string) $w );
				if ( '' !== $e ) {
					ew_fan_link( $fan['sub'], $e );
				}
			}
			$emails = ew_fan_emails( $fan );
			$ids    = ew_wallet_ids_for( $emails );
			nocache_headers();
			return array(
				'email'  => $fan['email'],
				'emails' => $emails,
				// The account's own email isn't proved yet and has bookings waiting: ask for a reference.
				'linked' => '' !== $fan['proven'] || in_array( $fan['email'], $emails, true ),
				'items'  => array_map( 'ew_wallet_item', $ids ),
			);
		},
	) );
} );
