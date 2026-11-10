<?php
/**
 * Emma (Ben's assistant agent) — read and write the site's bookings.
 *
 * Every paid ticket, free school claim, half-time sign-up and shirt order is an
 * ewt_booking. These routes let Emma list, count, add and change them.
 * Nothing is ever deleted: cancelling sets status "cancelled".
 *
 * All routes need header X-Emma-Key matching the ew_emma_key option.
 * The key is set once by an admin: POST /eastwood/v1/emma/key {"key":"..."}.
 *
 *   GET  /eastwood/v1/emma/bookings  ?status= &event= &q= &since=YYYY-MM-DD &limit=
 *   GET  /eastwood/v1/emma/summary   ?event= &since=
 *   GET  /eastwood/v1/emma/events    pages carrying a claim or tickets block
 *   POST /eastwood/v1/emma/booking   create
 *   POST /eastwood/v1/emma/booking/update   {id|code, ...fields}
 */

function ew_emma_ok( $req ) {
	$key = (string) get_option( 'ew_emma_key', '' );
	$got = (string) $req->get_header( 'x-emma-key' );
	return strlen( $key ) >= 24 && '' !== $got && hash_equals( $key, $got );
}

function ew_emma_row( $id ) {
	$m   = function ( $k ) use ( $id ) {
		return get_post_meta( $id, '_ewt_' . $k, true );
	};
	$in  = $m( 'in' );
	return array(
		'id'       => (int) $id,
		'code'     => (string) $m( 'code' ),
		'status'   => (string) $m( 'status' ),
		'merged_into' => ( $mi = (int) $m( 'merged_into' ) ) ? (string) get_post_meta( $mi, '_ewt_code', true ) : '',
		'name'     => (string) $m( 'name' ),
		'email'    => (string) $m( 'email' ),
		'phone'    => (string) $m( 'phone' ),
		'event'    => (string) $m( 'event' ),
		'page'     => (int) $m( 'page' ),
		'school'   => (string) $m( 'school' ),
		'adults'   => (int) $m( 'adults' ),
		'children' => (int) $m( 'children' ),
		'lines'    => $m( 'lines' ),
		'total'    => $m( 'total' ),
		'paid'     => $m( 'paid' ),
		'note'     => (string) $m( 'note' ),
		'source'   => (string) $m( 'source' ),
		'checked_in' => $in ? gmdate( 'c', (int) $in ) : '',
		'created'  => get_post_time( 'c', true, $id ),
	);
}

function ew_emma_query( $req, $limit ) {
	$meta = array();
	$status = sanitize_text_field( (string) $req->get_param( 'status' ) );
	if ( '' !== $status ) {
		$meta[] = array( 'key' => '_ewt_status', 'value' => $status );
	}
	$event = sanitize_text_field( (string) $req->get_param( 'event' ) );
	if ( '' !== $event ) {
		$meta[] = ctype_digit( $event )
			? array( 'key' => '_ewt_page', 'value' => $event )
			: array( 'key' => '_ewt_event', 'value' => $event, 'compare' => 'LIKE' );
	}
	$args = array(
		'post_type'      => 'ewt_booking',
		'post_status'    => 'any',
		'posts_per_page' => $limit,
		'fields'         => 'ids',
		'orderby'        => 'date',
		'order'          => 'DESC',
	);
	if ( $meta ) {
		$args['meta_query'] = array_merge( array( 'relation' => 'AND' ), $meta ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}
	$since = sanitize_text_field( (string) $req->get_param( 'since' ) );
	if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $since ) ) {
		$args['date_query'] = array( array( 'after' => $since, 'inclusive' => true ) );
	}
	$q = sanitize_text_field( (string) $req->get_param( 'q' ) );
	if ( '' !== $q ) {
		$args['s'] = $q;
	}
	$ids = get_posts( $args );
	if ( '' !== $q && ! $ids ) {
		unset( $args['s'] );
		$args['meta_query'] = array_merge( $args['meta_query'] ?? array( 'relation' => 'AND' ), array( array(
			'relation' => 'OR',
			array( 'key' => '_ewt_email', 'value' => $q, 'compare' => 'LIKE' ),
			array( 'key' => '_ewt_code', 'value' => $q ),
			array( 'key' => '_ewt_school', 'value' => $q, 'compare' => 'LIKE' ),
		) ) );
		$ids = get_posts( $args );
	}
	return $ids;
}

function ew_emma_find( $req ) {
	$id = (int) $req->get_param( 'id' );
	if ( ! $id ) {
		$code = sanitize_text_field( (string) $req->get_param( 'code' ) );
		if ( '' !== $code ) {
			$hit = get_posts( array( 'post_type' => 'ewt_booking', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_ewt_code', 'meta_value' => $code ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
			$id  = $hit ? (int) $hit[0] : 0;
		}
	}
	return ( $id && 'ewt_booking' === get_post_type( $id ) ) ? $id : 0;
}

add_action( 'rest_api_init', function () {
	$auth = 'ew_emma_ok';

	register_rest_route( 'eastwood/v1', '/emma/key', array(
		'methods'             => 'POST',
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
		'callback'            => function ( $req ) {
			$key = preg_replace( '/[^A-Za-z0-9]/', '', (string) $req->get_param( 'key' ) );
			if ( strlen( $key ) < 24 ) {
				return new WP_REST_Response( array( 'message' => 'key too short' ), 400 );
			}
			update_option( 'ew_emma_key', $key, false );
			return array( 'ok' => true );
		},
	) );

	register_rest_route( 'eastwood/v1', '/emma/bookings', array(
		'methods'             => 'GET',
		'permission_callback' => $auth,
		'callback'            => function ( $req ) {
			$limit = max( 1, min( 200, (int) ( $req->get_param( 'limit' ) ?: 50 ) ) );
			return array_map( 'ew_emma_row', ew_emma_query( $req, $limit ) );
		},
	) );

	register_rest_route( 'eastwood/v1', '/emma/summary', array(
		'methods'             => 'GET',
		'permission_callback' => $auth,
		'callback'            => function ( $req ) {
			$out = array();
			foreach ( ew_emma_query( $req, -1 ) as $id ) {
				$r   = ew_emma_row( $id );
				$k   = $r['event'] . ' | ' . $r['status'];
				$out[ $k ] = $out[ $k ] ?? array( 'event' => $r['event'], 'status' => $r['status'], 'bookings' => 0, 'adults' => 0, 'children' => 0, 'checked_in' => 0, 'schools' => array() );
				$out[ $k ]['bookings']++;
				$out[ $k ]['adults']   += $r['adults'];
				$out[ $k ]['children'] += $r['children'];
				$out[ $k ]['checked_in'] += $r['checked_in'] ? 1 : 0;
				if ( $r['school'] ) {
					$out[ $k ]['schools'][ $r['school'] ] = ( $out[ $k ]['schools'][ $r['school'] ] ?? 0 ) + 1;
				}
			}
			return array_values( $out );
		},
	) );

	register_rest_route( 'eastwood/v1', '/emma/events', array(
		'methods'             => 'GET',
		'permission_callback' => $auth,
		'callback'            => function () {
			$out = array();
			foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => -1 ) ) as $p ) {
				$raw = (string) get_post_meta( $p->ID, '_elementor_data', true ) . $p->post_content;
				foreach ( array( 'eastwood_claim' => 'claim', 'eastwood_tickets' => 'tickets', 'eastwood_halftime' => 'halftime' ) as $tag => $kind ) {
					if ( false !== strpos( $raw, '[' . $tag ) ) {
						$event = '';
						if ( 'claim' === $kind && function_exists( 'ew_claim_block_on' ) ) {
							$b = ew_claim_block_on( $p );
							$event = $b ? (string) $b['atts']['event'] : '';
						}
						$out[] = array( 'page' => $p->ID, 'title' => $p->post_title, 'url' => get_permalink( $p ), 'kind' => $kind, 'event' => $event );
					}
				}
			}
			return $out;
		},
	) );

	register_rest_route( 'eastwood/v1', '/emma/booking', array(
		'methods'             => 'POST',
		'permission_callback' => $auth,
		'callback'            => function ( $req ) {
			$name   = sanitize_text_field( (string) $req->get_param( 'name' ) );
			$email  = sanitize_email( (string) $req->get_param( 'email' ) );
			$status = sanitize_key( (string) ( $req->get_param( 'status' ) ?: 'claimed' ) );
			if ( ! in_array( $status, array( 'claimed', 'halftime', 'manual', 'guest' ), true ) ) {
				return new WP_REST_Response( array( 'message' => 'status must be claimed, halftime, manual or guest' ), 400 );
			}
			if ( '' === $name ) {
				return new WP_REST_Response( array( 'message' => 'name is needed' ), 400 );
			}
			$page   = (int) $req->get_param( 'page' );
			$post   = $page ? get_post( $page ) : null;
			$event  = sanitize_text_field( (string) $req->get_param( 'event' ) );
			$adults = max( 0, min( 50, (int) $req->get_param( 'adults' ) ) );
			$kids   = max( 0, min( 50, (int) $req->get_param( 'children' ) ) );
			$lines  = '';
			if ( $post && function_exists( 'ew_claim_block_on' ) ) {
				$b = ew_claim_block_on( $post );
				if ( $b ) {
					$event = $event ?: (string) $b['atts']['event'];
					$lines = function_exists( 'ew_claim_party' ) ? ew_claim_party( $b['atts'], $adults, $kids ) : '';
				}
			}
			if ( '' === $event ) {
				return new WP_REST_Response( array( 'message' => 'event or a claim page is needed' ), 400 );
			}
			do {
				$code = 'EFC-' . wp_rand( 1000, 9999 );
			} while ( get_posts( array( 'post_type' => 'ewt_booking', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_ewt_code', 'meta_value' => $code ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
			$id = wp_insert_post( array( 'post_type' => 'ewt_booking', 'post_status' => 'publish', 'post_title' => $name ) );
			if ( ! $id || is_wp_error( $id ) ) {
				return new WP_REST_Response( array( 'message' => 'could not save' ), 500 );
			}
			foreach ( array(
				'name'     => $name,
				'email'    => $email,
				'phone'    => sanitize_text_field( (string) $req->get_param( 'phone' ) ),
				'event'    => $event,
				'page'     => $post ? $post->ID : 0,
				'url'      => $post ? get_permalink( $post ) : '',
				'lines'    => $lines ?: sanitize_text_field( (string) $req->get_param( 'lines' ) ),
				'school'   => sanitize_text_field( (string) $req->get_param( 'school' ) ),
				'extra'    => sanitize_text_field( (string) $req->get_param( 'school' ) ),
				'adults'   => $adults,
				'children' => $kids,
				'total'    => 0,
				'status'   => $status,
				'note'     => sanitize_textarea_field( (string) $req->get_param( 'note' ) ),
				'code'     => $code,
				'key'      => wp_generate_password( 12, false ),
				'source'   => 'emma',
				'consent'  => 'no',
			) as $k => $v ) {
				update_post_meta( $id, '_ewt_' . $k, $v );
			}
			return ew_emma_row( $id );
		},
	) );

	register_rest_route( 'eastwood/v1', '/emma/booking/update', array(
		'methods'             => 'POST',
		'permission_callback' => $auth,
		'callback'            => function ( $req ) {
			$id = ew_emma_find( $req );
			if ( ! $id ) {
				return new WP_REST_Response( array( 'message' => 'booking not found' ), 404 );
			}
			foreach ( array( 'name', 'phone', 'school', 'note' ) as $k ) {
				$v = $req->get_param( $k );
				if ( null !== $v ) {
					update_post_meta( $id, '_ewt_' . $k, 'note' === $k ? sanitize_textarea_field( (string) $v ) : sanitize_text_field( (string) $v ) );
				}
			}
			$email = $req->get_param( 'email' );
			if ( null !== $email && is_email( $email ) ) {
				update_post_meta( $id, '_ewt_email', sanitize_email( $email ) );
			}
			foreach ( array( 'adults', 'children' ) as $k ) {
				$v = $req->get_param( $k );
				if ( null !== $v ) {
					update_post_meta( $id, '_ewt_' . $k, max( 0, min( 50, (int) $v ) ) );
				}
			}
			$status = $req->get_param( 'status' );
			if ( null !== $status ) {
				$status = sanitize_key( (string) $status );
				$now    = (string) get_post_meta( $id, '_ewt_status', true );
				if ( 'paid' === $now && 'cancelled' === $status ) {
					return new WP_REST_Response( array( 'message' => 'paid bookings are cancelled and refunded by Ben in Stripe, not here' ), 400 );
				}
				if ( ! in_array( $status, array( 'claimed', 'halftime', 'manual', 'guest', 'cancelled' ), true ) ) {
					return new WP_REST_Response( array( 'message' => 'status not allowed' ), 400 );
				}
				update_post_meta( $id, '_ewt_status', $status );
			}
			$merge = $req->get_param( 'merged_into' );
			if ( null !== $merge && '' !== (string) $merge ) {
				$res = ew_booking_merge( $id, ew_booking_by_code( (string) $merge ) );
				if ( is_wp_error( $res ) ) {
					return new WP_REST_Response( array( 'message' => $res->get_error_message() ), 400 );
				}
				return ew_emma_row( $id );
			}
			$in = $req->get_param( 'checked_in' );
			if ( null !== $in ) {
				update_post_meta( $id, '_ewt_in', $in ? time() : '' );
			}
			if ( null !== $req->get_param( 'name' ) ) {
				wp_update_post( array( 'ID' => $id, 'post_title' => sanitize_text_field( (string) $req->get_param( 'name' ) ) ) );
			}
			return ew_emma_row( $id );
		},
	) );
} );
