<?php
/**
 * What the Team EastwoodFC app reads to draw the shop and hospitality
 * screens natively.
 *
 * The app does not get its own copy of anything. Both endpoints read the
 * SAME blocks the site's pages are built from -- the shirt block on
 * /shop/home-shirt/ and the hospitality blocks on /hospitality/ -- so a
 * word changed in the builder, a price changed in the shop, or a room
 * switched on in On sale shows in the app the next time it loads, with
 * nothing to edit in two places.
 *
 * Nothing here sells. The shop screen hands the chosen shirt to the site's
 * own shirt page (?fit=&size=&post=&pname=&pnum=&go=details), which carries
 * straight on to the details and card steps; the money goes the one road it
 * always goes.
 *
 * @package eastwood-fwp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The fields and lines of the first block of a kind on a page, or null. */
function ew_app_block_on( $post, $name ) {
	if ( ! $post ) {
		return null;
	}
	$found = null;
	$walk  = function ( $nodes ) use ( &$walk, &$found, $name ) {
		foreach ( (array) $nodes as $n ) {
			if ( $found ) {
				return;
			}
			$sc = (string) ( $n['settings']['shortcode'] ?? '' );
			if ( 0 === strpos( ltrim( $sc ), '[' . $name ) ) {
				$found = ew_blocks_parse( $sc );
				return;
			}
			if ( ! empty( $n['elements'] ) ) {
				$walk( $n['elements'] );
			}
		}
	};
	$walk( ew_blocks_read( $post ) );
	if ( ! $found && preg_match( '/\[' . preg_quote( $name, '/' ) . '[^\]]*\](?:.*?\[\/' . preg_quote( $name, '/' ) . '\])?/s', (string) $post->post_content, $m ) ) {
		$found = ew_blocks_parse( $m[0] );
	}
	return $found;
}

/** Every block of a kind on a page, in page order. */
function ew_app_blocks_on( $post, $name ) {
	$out = array();
	if ( ! $post ) {
		return $out;
	}
	$doc = ew_blocks_read( $post );
	ew_blocks_walk( $doc, function ( $n ) use ( &$out, $name ) {
		$sc = (string) ( $n['settings']['shortcode'] ?? '' );
		if ( 0 === strpos( ltrim( $sc ), '[' . $name ) ) {
			$b = ew_blocks_parse( $sc );
			if ( $b ) {
				$out[] = $b;
			}
		}
	} );
	return $out;
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'eastwood/v1', '/shop/app', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => 'ew_app_shop_rest',
	) );
	register_rest_route( 'eastwood/v1', '/hospitality/app', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => 'ew_app_hosp_rest',
	) );
} );

/**
 * The shop, as the app draws it: the shirt page's words and fits, the
 * live price, postage and sizes from the shop, and the page to hand the
 * buyer to. The words come out of the page by running its own block and
 * reading the config it hands its script, so there is one set of words.
 */
function ew_app_shop_rest( $req ) {
	$page_id = (int) ( $req->get_param( 'page' ) ?: 107 );
	$post    = get_post( $page_id );
	$block   = $post ? ew_app_block_on( $post, 'eastwood_shirt' ) : null;
	if ( ! $post || ! $block ) {
		return new WP_Error( 'ew_app_shop', 'No shirt page.', array( 'status' => 404 ) );
	}
	$f    = (array) $block['fields'];
	$html = '';
	// The block builds its own config; borrow it rather than rebuild it.
	$prev = $GLOBALS['post'] ?? null;
	$GLOBALS['post'] = $post; // phpcs:ignore
	setup_postdata( $post );
	$html = ew_shirt_shortcode( $f, (string) $block['lines'] );
	wp_reset_postdata();
	$GLOBALS['post'] = $prev; // phpcs:ignore
	$cfg = array();
	if ( preg_match( '/data-shirt="([^"]*)"/', $html, $m ) ) {
		$cfg = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true ) ?: array();
	}
	unset( $cfg['pk'], $cfg['rest'] );

	// Live price, postage and sizes, per SKU the fits name.
	$items = array();
	foreach ( (array) ( $cfg['fits'] ?? array() ) as $fit ) {
		$sku = (string) ( $fit['sku'] ?? '' );
		if ( '' === $sku || isset( $items[ $sku ] ) ) {
			continue;
		}
		$look = new WP_REST_Request( 'GET', '/eastwood/v1/shop/item' );
		$look->set_query_params( array( 'sku' => $sku ) );
		$found = rest_do_request( $look );
		$items[ $sku ] = $found->is_error() ? null : (array) $found->get_data();
	}

	$out = array(
		'page'    => $page_id,
		'url'     => get_permalink( $post ),
		'title'   => (string) ( $f['title'] ?? $cfg['title'] ?? '' ),
		'brand'   => (string) ( $f['brand'] ?? '' ),
		'front'   => '' !== trim( (string) ( $f['shirt_front'] ?? '' ) ) ? (string) $f['shirt_front'] : ew_asset_url( 'assets/shop/home-shirt-front.png' ),
		'back'    => '' !== trim( (string) ( $f['shirt_back'] ?? '' ) ) ? (string) $f['shirt_back'] : ew_asset_url( 'assets/shop/home-shirt-back.png' ),
		'about'   => array( 'label' => (string) ( $f['about_label'] ?? '' ), 'text' => (string) ( $f['about'] ?? '' ) ),
		'ship'    => array( 'label' => (string) ( $f['ship_label'] ?? '' ), 'text' => (string) ( $f['ship'] ?? '' ) ),
		'labels'  => array(
			'fit'  => (string) ( $f['fit_label'] ?? 'Fit' ),
			'size' => (string) ( $f['size_label'] ?? 'Size' ),
			'pay'  => (string) ( $cfg['paybtn'] ?? 'Pay by card' ),
			'total' => (string) ( $cfg['totlab'] ?? 'Total' ),
		),
		'words'   => $cfg,
		'items'   => $items,
	);
	$res = rest_ensure_response( $out );
	$res->header( 'Cache-Control', 'public, max-age=60' );
	return $res;
}

/**
 * Hospitality, as the app draws it: the hero words, each room that is on
 * sale (name and price from On sale, the line and paragraph and photograph
 * from the page), how the afternoon runs, and the home games it can be
 * booked for.
 */
function ew_app_hosp_rest( $req ) {
	$post = get_page_by_path( 'hospitality' );
	if ( ! $post ) {
		return new WP_Error( 'ew_app_hosp', 'No hospitality page.', array( 'status' => 404 ) );
	}
	$hero  = ew_app_block_on( $post, 'eastwood_hosp_hero' );
	$rooms = ew_app_block_on( $post, 'eastwood_hosp_rooms' );
	$run   = ew_app_block_on( $post, 'eastwood_hosp_run' );
	$cta   = ew_app_block_on( $post, 'eastwood_hosp_cta' );

	$ha = shortcode_atts( ew_hosp_hero_defaults(), (array) ( $hero['fields'] ?? array() ), 'eastwood_hosp_hero' );
	$std = function_exists( 'ew_sales_rooms_std' ) ? ew_sales_rooms_std() : array();

	$room_out = array();
	foreach ( ew_hosp_lines( (string) ( $rooms['lines'] ?? '' ) ) as $line ) {
		list( $key, $cap, $blurb, $url, $photo ) = ew_hosp_bits( $line, 5 );
		$key = sanitize_key( $key );
		if ( '' === $key || ! isset( $std[ $key ] ) ) {
			continue;
		}
		$room_page = '' !== $url ? get_page_by_path( trim( $url, '/' ) ) : null;
		$facts     = $room_page ? ew_app_block_on( $room_page, 'eastwood_hosp_room' ) : null;
		$room_run  = $room_page ? ew_app_block_on( $room_page, 'eastwood_hosp_run' ) : null;
		$room_out[] = array(
			'key'    => $key,
			'name'   => (string) $std[ $key ]['name'],
			'note'   => (string) $std[ $key ]['note'],
			'pence'  => (int) $std[ $key ]['pence'],
			'places' => (int) $std[ $key ]['qty'],
			'line'   => $cap,
			'text'   => $blurb,
			'photo'  => ew_hosp_pic( $photo ),
			'url'    => '' !== $url ? home_url( $url ) : home_url( '/hospitality/' ),
			'facts'  => $facts ? array( 'fields' => (array) $facts['fields'], 'lines' => ew_hosp_lines( (string) $facts['lines'] ) ) : null,
			'run'    => $room_run ? ew_app_run_lines( $room_run ) : array(),
		);
	}

	// Home games hospitality can be booked for: what On sale says, as the
	// fixture card says it.
	$games = array();
	if ( function_exists( 'ew_fixtures_league_list' ) && function_exists( 'ew_sales_state' ) ) {
		$today = current_time( 'Y-m-d' );
		foreach ( ew_fixtures_league_list() as $m ) {
			$home = (int) ( $m['home-team']['id'] ?? 0 );
			if ( 2059 !== $home || (string) ( $m['date'] ?? '' ) < $today ) {
				continue;
			}
			$st = ew_sales_state( (int) $m['id'], 'hosp' );
			$games[] = array(
				'id'       => (int) $m['id'],
				'date'     => (string) $m['date'],
				'time'     => (string) ( $m['time'] ?? '' ),
				'opponent' => (string) ( $m['away-team']['name'] ?? '' ),
				'opponentId' => (int) ( $m['away-team']['id'] ?? 0 ),
				'state'    => $st['state'],
				'from'     => $st['when'] ? gmdate( 'c', (int) $st['when'] ) : null,
				'rooms'    => array_keys( ew_sales_rooms( (int) $m['id'] ) ),
			);
			if ( count( $games ) >= 6 ) {
				break;
			}
		}
	}

	$out = array(
		'url'     => get_permalink( $post ),
		'eyebrow' => (string) $ha['eyebrow'],
		'title'   => (string) $ha['title'],
		'lede'    => (string) $ha['lede'],
		'photo'   => ew_hosp_pic( $ha['photo'] ),
		'rooms'   => $room_out,
		'run'     => $run ? ew_app_run_lines( $run ) : array(),
		'runTitle' => (string) ( $run['fields']['title'] ?? '' ),
		'cta'     => $cta ? (array) $cta['fields'] : null,
		'games'   => $games,
	);
	$res = rest_ensure_response( $out );
	$res->header( 'Cache-Control', 'public, max-age=120' );
	return $res;
}

/** The running order lines of a run block: when | what | note, key steps marked. */
function ew_app_run_lines( $block ) {
	$out = array();
	foreach ( ew_hosp_lines( (string) ( $block['lines'] ?? '' ) ) as $line ) {
		$key = false;
		if ( 0 === strpos( $line, '*' ) ) {
			$key  = true;
			$line = trim( substr( $line, 1 ) );
		}
		list( $when, $what, $note ) = ew_hosp_bits( $line, 3 );
		if ( '' === $what ) {
			continue;
		}
		$out[] = array( 'when' => $when, 'what' => $what, 'note' => $note, 'key' => $key );
	}
	return $out;
}
