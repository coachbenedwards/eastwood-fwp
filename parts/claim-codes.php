<?php
/**
 * Personal claim codes: eastwoodfc.com/claimticket/?c=HT-7K3P
 *
 * Made for people who claimed school tickets for one game, so they can claim
 * the next one in a tap. Each code belongs to one email address and is kept
 * on that person's booking from the earlier game (_ewt_cc), with the date it
 * stops working (_ewt_cc_until). Opening the link fills the claim form from
 * that booking: email, then name, school and the same party size. They can
 * change the numbers before they press Claim.
 *
 * The rules (agreed with Ben, 23 Nov 2026):
 *   - one claim per code; once used, the link says the tickets are in the app;
 *   - the game's place cap still applies, a code does not get round it;
 *   - a code stops working at the time it was made with (kick-off);
 *   - the plain claim page carries on as before for anyone without a code.
 *
 * Codes are made once, by an admin, with POST eastwood/v1/claim/codes
 * {from_event, prefix, until}. Making them again for the same game keeps the
 * codes already made and only adds missing ones.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** A code: prefix, dash, four characters with no look-alikes. */
function ew_cc_new( $prefix ) {
	$a = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
	do {
		$c = '';
		for ( $i = 0; $i < 4; $i++ ) {
			$c .= $a[ random_int( 0, strlen( $a ) - 1 ) ];
		}
		$c = $prefix . '-' . $c;
	} while ( ew_cc_booking( $c ) );
	return $c;
}

function ew_cc_clean( $code ) {
	return strtoupper( preg_replace( '/[^A-Za-z0-9-]/', '', (string) $code ) );
}

/** The earlier booking a code belongs to, or 0. */
function ew_cc_booking( $code ) {
	$code = ew_cc_clean( $code );
	if ( '' === $code ) {
		return 0;
	}
	$ids = get_posts( array( 'post_type' => 'ewt_booking', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => '_ewt_cc', 'meta_value' => $code ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	return $ids ? (int) $ids[0] : 0;
}

/**
 * What a code may do now: ['state' => ok|used|expired|none, 'id' => booking, 'fill' => [...]].
 */
function ew_cc_state( $code ) {
	$id = ew_cc_booking( $code );
	if ( ! $id ) {
		return array( 'state' => 'none', 'id' => 0 );
	}
	$m     = function ( $k ) use ( $id ) {
		return (string) get_post_meta( $id, '_ewt_' . $k, true );
	};
	$until = (int) $m( 'cc_until' );
	if ( $m( 'cc_used' ) ) {
		return array( 'state' => 'used', 'id' => $id );
	}
	if ( $until && time() >= $until ) {
		return array( 'state' => 'expired', 'id' => $id );
	}
	return array(
		'state' => 'ok',
		'id'    => $id,
		'fill'  => array(
			'code'     => $m( 'cc' ),
			'email'    => $m( 'email' ),
			'name'     => $m( 'name' ),
			'school'   => $m( 'school' ),
			'adults'   => max( 0, min( 20, (int) $m( 'adults' ) ) ),
			'children' => max( 0, min( 20, (int) $m( 'children' ) ) ),
		),
	);
}

/** The code someone was sent, found from any of their bookings. */
function ew_cc_for_ids( $ids ) {
	foreach ( (array) $ids as $id ) {
		$c = (string) get_post_meta( $id, '_ewt_cc', true );
		if ( '' !== $c ) {
			return $c;
		}
	}
	return '';
}

/** Their personal link to the claim page. */
function ew_cc_link( $code ) {
	return home_url( '/claimticket/?c=' . rawurlencode( $code ) );
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'eastwood/v1', '/claim/codes', array(
		'methods'             => 'POST',
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
		'callback'            => function ( $req ) {
			$event  = sanitize_text_field( (string) $req->get_param( 'from_event' ) );
			$prefix = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $req->get_param( 'prefix' ) ) );
			$until  = strtotime( get_gmt_from_date( sanitize_text_field( (string) $req->get_param( 'until' ) ) ) . ' UTC' );
			if ( '' === $event || '' === $prefix || ! $until ) {
				return new WP_REST_Response( array( 'message' => 'from_event, prefix and until are needed' ), 400 );
			}
			$people = function_exists( 'ew_eb_people' ) ? ew_eb_people( $event ) : array();
			$made   = 0;
			$kept   = 0;
			foreach ( $people as $em => $p ) {
				// Only free claims get a code, the latest claim for each address.
				$src = 0;
				foreach ( array_reverse( $p['ids'] ) as $id ) {
					if ( 'claimed' === get_post_meta( $id, '_ewt_status', true ) ) {
						$src = $id;
						break;
					}
				}
				if ( ! $src ) {
					continue;
				}
				if ( '' !== ew_cc_for_ids( $p['ids'] ) ) {
					$kept++;
					continue;
				}
				update_post_meta( $src, '_ewt_cc', ew_cc_new( $prefix ) );
				update_post_meta( $src, '_ewt_cc_until', $until );
				$made++;
			}
			// Extra addresses (a club address for testing) left off the email list on purpose.
			foreach ( array_filter( array_map( 'sanitize_email', explode( ',', (string) $req->get_param( 'also' ) ) ) ) as $em ) {
				$ids = get_posts( array( 'post_type' => 'ewt_booking', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'date', 'order' => 'DESC', 'meta_query' => array( array( 'key' => '_ewt_email', 'value' => $em ), array( 'key' => '_ewt_event', 'value' => $event ), array( 'key' => '_ewt_status', 'value' => 'claimed' ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
				if ( $ids && '' === ew_cc_for_ids( $ids ) ) {
					update_post_meta( $ids[0], '_ewt_cc', ew_cc_new( $prefix ) );
					update_post_meta( $ids[0], '_ewt_cc_until', $until );
					$made++;
				}
			}
			return array( 'made' => $made, 'kept' => $kept );
		},
	) );
} );
