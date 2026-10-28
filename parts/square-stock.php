<?php
/**
 * Shirt stock: one count, kept in the Eastwood Ventures Square.
 *
 * The tills sell shirts out of Square and Square keeps the count. The
 * website used to keep a second count of its own, so a shirt sold at the
 * bar never came off the website and one sold online never came off the
 * till. Now the website asks Square instead:
 *
 *   - Reading. Every answer from /eastwood/v1/shop/item (the shirt page,
 *     the staff order screen and the claim page all read it) has each
 *     size's in_stock set from Square's count at the Ventures location,
 *     and gains a `count`. A size at 0 in Square shows sold out online.
 *   - Selling. When a website order is paid (ew_shirt_mark_paid, which
 *     all three routes end in), each shirt on it is recorded in Square as
 *     sold, so the till's count drops by the same shirt.
 *
 * Square is found by name: the shop item's name is the Square item's name
 * ("Home shirt 2026/27", "Home shirt 2026/27 (Kids)") and each size is a
 * variation of the same name ("ADULT M"). Rename one and the other has to
 * follow; until it does, that shirt falls back to the website's own list.
 *
 * Square being unreachable never stops a sale: the page falls back to what
 * the shop plugin says, and a sale that could not be recorded is kept on
 * the booking (_ewt_sq_stock = "failed") and tried again on the next run.
 *
 * Credentials: Settings → Eastwood FWP → Square, the set marked in use.
 *
 * @package eastwood-fwp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EW_SQ_API_VERSION = '2025-01-23';

/** Square's API base for the environment in use. */
function ew_sq_base() {
	return 'live' === ew_sq_env() ? 'https://connect.squareup.com/v2/' : 'https://connect.squareupsandbox.com/v2/';
}

/** True when a token and a location are both saved for the set in use. */
function ew_sq_ready() {
	$c = ew_sq_creds();
	return '' !== $c['token'] && '' !== $c['location_id'];
}

/**
 * One call to Square. Returns the decoded body, or a WP_Error.
 *
 * @param string     $method GET or POST.
 * @param string     $path   Path after /v2/.
 * @param array|null $body   JSON body.
 */
function ew_sq_call( $method, $path, $body = null ) {
	$c    = ew_sq_creds();
	$args = array(
		'method'  => $method,
		'timeout' => 8,
		'headers' => array(
			'Authorization'  => 'Bearer ' . $c['token'],
			'Square-Version' => EW_SQ_API_VERSION,
			'Content-Type'   => 'application/json',
		),
	);
	if ( null !== $body ) {
		$args['body'] = wp_json_encode( $body );
	}
	$res = wp_remote_request( ew_sq_base() . ltrim( $path, '/' ), $args );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	$j    = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	if ( $code >= 300 || ! is_array( $j ) ) {
		$msg = is_array( $j ) && ! empty( $j['errors'][0]['detail'] ) ? (string) $j['errors'][0]['detail'] : 'Square answered ' . $code . '.';
		return new WP_Error( 'ew_sq', $msg );
	}
	return $j;
}

/**
 * The Square variation ids for one item, by size name, e.g.
 * array( 'ADULT M' => 'ABC123…' ). Found by the item's exact name and kept
 * for an hour. Empty when Square has no item of that name.
 *
 * @param string $name The shop item's name.
 */
function ew_sq_variations( $name ) {
	$name = trim( (string) $name );
	if ( '' === $name ) {
		return array();
	}
	$key = 'ew_sq_var_' . md5( ew_sq_env() . '|' . ew_sq_creds()['location_id'] . '|' . $name );
	$hit = get_transient( $key );
	if ( is_array( $hit ) ) {
		return $hit;
	}
	$j = ew_sq_call( 'POST', 'catalog/search', array(
		'object_types' => array( 'ITEM' ),
		'query'        => array(
			'exact_query' => array( 'attribute_name' => 'name', 'attribute_value' => $name ),
		),
		'limit'        => 10,
	) );
	if ( is_wp_error( $j ) ) {
		return array(); // not cached: try again next time.
	}
	$map = array();
	foreach ( (array) ( $j['objects'] ?? array() ) as $obj ) {
		if ( ! empty( $obj['is_deleted'] ) || trim( (string) ( $obj['item_data']['name'] ?? '' ) ) !== $name ) {
			continue;
		}
		foreach ( (array) ( $obj['item_data']['variations'] ?? array() ) as $v ) {
			$vn = strtoupper( trim( (string) ( $v['item_variation_data']['name'] ?? '' ) ) );
			if ( '' !== $vn && ! empty( $v['id'] ) ) {
				$map[ $vn ] = (string) $v['id'];
			}
		}
		break;
	}
	set_transient( $key, $map, $map ? HOUR_IN_SECONDS : 5 * MINUTE_IN_SECONDS );
	return $map;
}

/**
 * How many of each variation are in stock at the Ventures location.
 * Kept for one minute, so a busy page asks Square at most once a minute.
 *
 * @param array $ids Variation ids.
 * @return array|null id => count, or null if Square could not be asked.
 */
function ew_sq_counts( $ids ) {
	$ids = array_values( array_unique( array_filter( array_map( 'strval', (array) $ids ) ) ) );
	if ( ! $ids ) {
		return array();
	}
	sort( $ids );
	$loc = ew_sq_creds()['location_id'];
	$key = 'ew_sq_cnt_' . md5( ew_sq_env() . '|' . $loc . '|' . implode( ',', $ids ) );
	$hit = get_transient( $key );
	if ( is_array( $hit ) ) {
		return $hit;
	}
	$j = ew_sq_call( 'POST', 'inventory/counts/batch-retrieve', array(
		'catalog_object_ids' => $ids,
		'location_ids'       => array( $loc ),
		'states'             => array( 'IN_STOCK' ),
	) );
	if ( is_wp_error( $j ) ) {
		return null;
	}
	$out = array_fill_keys( $ids, 0 ); // nothing counted means none in stock.
	foreach ( (array) ( $j['counts'] ?? array() ) as $c ) {
		$id = (string) ( $c['catalog_object_id'] ?? '' );
		if ( isset( $out[ $id ] ) && 'IN_STOCK' === ( $c['state'] ?? '' ) ) {
			$out[ $id ] = max( 0, (int) floor( (float) ( $c['quantity'] ?? 0 ) ) );
		}
	}
	set_transient( $key, $out, MINUTE_IN_SECONDS );
	return $out;
}

/** Forget the cached counts, after a sale has been recorded. */
function ew_sq_forget_counts() {
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_ew\\_sq\\_cnt\\_%' OR option_name LIKE '\\_transient\\_timeout\\_ew\\_sq\\_cnt\\_%'" ); // phpcs:ignore
}

/**
 * Put Square's counts onto a shop item answer.
 *
 * @param array $item The shop plugin's answer.
 * @return array The same, with in_stock from Square and a count per size.
 */
function ew_sq_apply_stock( $item ) {
	if ( ! ew_sq_ready() || empty( $item['sizes'] ) ) {
		return $item;
	}
	$map = ew_sq_variations( (string) ( $item['name'] ?? '' ) );
	if ( ! $map ) {
		return $item;
	}
	$counts = ew_sq_counts( array_values( $map ) );
	if ( null === $counts ) {
		return $item;
	}
	foreach ( $item['sizes'] as $i => $row ) {
		$size = strtoupper( trim( (string) ( $row['size'] ?? '' ) ) );
		if ( ! isset( $map[ $size ] ) ) {
			// A size the website lists but Square does not carry cannot be
			// counted, so it cannot be sold.
			$item['sizes'][ $i ]['in_stock'] = false;
			$item['sizes'][ $i ]['count']    = 0;
			continue;
		}
		$n                               = (int) ( $counts[ $map[ $size ] ] ?? 0 );
		$item['sizes'][ $i ]['in_stock'] = $n > 0;
		$item['sizes'][ $i ]['count']    = $n;
	}
	$item['stock_from'] = 'square';
	return $item;
}

/*
 * Every caller of the shop item endpoint -- the page over HTTP and the
 * checkout, staff screen and claim page inside PHP -- gets Square's counts.
 */
add_filter( 'rest_request_after_callbacks', function ( $response, $handler, $request ) {
	if ( '/eastwood/v1/shop/item' !== $request->get_route() || is_wp_error( $response ) ) {
		return $response;
	}
	$data = $response instanceof WP_REST_Response ? $response->get_data() : $response;
	if ( ! is_array( $data ) ) {
		return $response;
	}
	$data = ew_sq_apply_stock( $data );
	if ( $response instanceof WP_REST_Response ) {
		$response->set_data( $data );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
	return $data;
}, 10, 3 );

/**
 * Which shirts a booking is for, as a list of array( sku, size ).
 *
 * @param int        $bid Booking id.
 * @param array|null $s   The Stripe session, when there is one to hand.
 */
function ew_sq_booking_shirts( $bid, $s = null ) {
	$saved = json_decode( (string) get_post_meta( $bid, '_ewt_shirts', true ), true );
	if ( is_array( $saved ) && $saved ) {
		return $saved;
	}
	$sku  = (string) ( $s['metadata']['sku'] ?? '' );
	$size = (string) ( $s['metadata']['size'] ?? '' );
	if ( '' !== $sku && '' !== $size ) {
		return array( array( $sku, $size ) );
	}
	return array();
}

/** The shop item's name for a SKU, read inside this request. */
function ew_sq_item_name( $sku ) {
	$r = new WP_REST_Request( 'GET', '/eastwood/v1/shop/item' );
	$r->set_query_params( array( 'sku' => $sku ) );
	$res = rest_do_request( $r );
	return $res->is_error() ? '' : (string) ( $res->get_data()['name'] ?? '' );
}

/**
 * Record a paid website order in Square, so the till's count drops.
 * Safe to call more than once: Square is told each booking once, by key.
 *
 * @param int        $bid Booking id.
 * @param array|null $s   The Stripe session.
 */
function ew_sq_record_sale( $bid, $s = null ) {
	$bid = (int) $bid;
	if ( ! $bid || ! ew_sq_ready() || 'done' === get_post_meta( $bid, '_ewt_sq_stock', true ) ) {
		return;
	}
	$shirts = ew_sq_booking_shirts( $bid, $s );
	if ( ! $shirts ) {
		return;
	}
	$loc     = ew_sq_creds()['location_id'];
	$when    = gmdate( 'Y-m-d\TH:i:s\Z' );
	$qty     = array();
	$missing = array();
	foreach ( $shirts as $pair ) {
		list( $sku, $size ) = array_pad( (array) $pair, 2, '' );
		$map  = ew_sq_variations( ew_sq_item_name( $sku ) );
		$size = strtoupper( trim( (string) $size ) );
		if ( empty( $map[ $size ] ) ) {
			$missing[] = $sku . ' ' . $size;
			continue;
		}
		$qty[ $map[ $size ] ] = ( $qty[ $map[ $size ] ] ?? 0 ) + 1;
	}
	if ( ! $qty ) {
		update_post_meta( $bid, '_ewt_sq_stock', 'failed' );
		update_post_meta( $bid, '_ewt_sq_note', 'Not found in Square: ' . implode( ', ', $missing ) );
		return;
	}
	$changes = array();
	foreach ( $qty as $id => $n ) {
		$changes[] = array(
			'type'       => 'ADJUSTMENT',
			'adjustment' => array(
				'catalog_object_id' => $id,
				'location_id'       => $loc,
				'from_state'        => 'IN_STOCK',
				'to_state'          => 'SOLD',
				'quantity'          => (string) $n,
				'occurred_at'       => $when,
				'reference_id'      => 'website-' . $bid,
			),
		);
	}
	$j = ew_sq_call( 'POST', 'inventory/changes/batch-create', array(
		'idempotency_key'       => 'ew-booking-' . $bid,
		'changes'               => $changes,
		'ignore_unchanged_counts' => false,
	) );
	if ( is_wp_error( $j ) ) {
		update_post_meta( $bid, '_ewt_sq_stock', 'failed' );
		update_post_meta( $bid, '_ewt_sq_note', $j->get_error_message() );
		if ( ! wp_next_scheduled( 'ew_sq_retry' ) ) {
			wp_schedule_single_event( time() + 15 * MINUTE_IN_SECONDS, 'ew_sq_retry' );
		}
		return;
	}
	update_post_meta( $bid, '_ewt_sq_stock', 'done' );
	update_post_meta( $bid, '_ewt_sq_note', $missing ? 'Not found in Square: ' . implode( ', ', $missing ) : '' );
	ew_sq_forget_counts();
}

/** Try again on any paid order Square has not been told about. */
add_action( 'ew_sq_retry', function () {
	$q = get_posts( array(
		'post_type'      => 'any',
		'post_status'    => 'any',
		'posts_per_page' => 50,
		'fields'         => 'ids',
		'meta_query'     => array( array( 'key' => '_ewt_sq_stock', 'value' => 'failed' ) ),
	) );
	foreach ( $q as $bid ) {
		ew_sq_record_sale( $bid );
	}
} );

/** Shown on the Square settings screen: what the site can see right now. */
function ew_sq_status_html() {
	if ( ! ew_sq_ready() ) {
		return '<p><strong>Shirt stock is not linked to Square yet.</strong> Save an access token and location ID above (Live, in use) and the shop will read its stock from Square.</p>';
	}
	$h = '';
	foreach ( array( 'home-shirt-2627', 'home-shirt-2627-kids' ) as $sku ) {
		$r = new WP_REST_Request( 'GET', '/eastwood/v1/shop/item' );
		$r->set_query_params( array( 'sku' => $sku ) );
		$res = rest_do_request( $r );
		if ( $res->is_error() ) {
			continue;
		}
		$d = $res->get_data();
		$h .= '<p><strong>' . esc_html( (string) ( $d['name'] ?? $sku ) ) . '</strong> — ';
		if ( 'square' !== ( $d['stock_from'] ?? '' ) ) {
			$h .= 'not found in Square under this exact name; the website is using its own list.</p>';
			continue;
		}
		$bits = array();
		foreach ( (array) ( $d['sizes'] ?? array() ) as $row ) {
			$bits[] = esc_html( $row['size'] . ': ' . (int) ( $row['count'] ?? 0 ) );
		}
		$h .= implode( ' · ', $bits ) . '</p>';
	}
	return '<h3>Shirt stock in Square now</h3>' . $h;
}
