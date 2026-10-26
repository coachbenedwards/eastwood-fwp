<?php
/**
 * The hospitality page, as blocks.
 *
 * The page it replaces was a hand-written slab: a photograph of a football,
 * sponsor rows that are sold privately and had no business on a checkout,
 * and room copy typed in twice -- once on the page and once in On sale --
 * which is how a room ends up named one thing on the page it is sold from
 * and another on the screen that sells it.
 *
 * So the room's NAME is not a field here. It comes from On sale, which is
 * where the rooms, their prices and their per-game ticks already live. What
 * the page owns is how a room is described and pictured; what the club sells
 * stays in one place. Everything else a visitor reads -- the headline, the
 * lede, every line of the running order, the section labels, the button --
 * is a field on the block, so it changes in the builder rather than in code.
 *
 * Five blocks, in the order they sit on the page:
 *
 *   eastwood_hosp_hero    photograph, eyebrow, headline, opening line
 *   eastwood_hosp_rooms   one tile per room that is on sale
 *   eastwood_hosp_run     how the afternoon runs, a line per step
 *   eastwood_hosp_strip   three photographs
 *   eastwood_hosp_cta     the band that sends you to the fixtures
 *
 * The room page uses the same run and strip blocks with its own words, plus
 * eastwood_hosp_room for the panel of facts down its right-hand side.
 *
 * @package eastwood-fwp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** A photograph: an asset inside the plugin, or a URL somebody pasted. */
function ew_hosp_pic( $v ) {
	$v = trim( (string) $v );
	if ( '' === $v ) {
		return '';
	}
	if ( preg_match( '#^(https?:)?//#', $v ) || 0 === strpos( $v, '/' ) ) {
		return $v;
	}
	return function_exists( 'ew_hb_asset' ) ? ew_hb_asset( $v ) : '';
}

/** Lines typed into the block, blank ones dropped. */
function ew_hosp_lines( $content ) {
	if ( function_exists( 'ew_hb_lines' ) ) {
		return ew_hb_lines( $content );
	}
	$out = array();
	foreach ( preg_split( '/\r\n|\r|\n/', wp_strip_all_tags( (string) $content ) ) as $line ) {
		$line = trim( html_entity_decode( $line, ENT_QUOTES, 'UTF-8' ) );
		if ( '' !== $line ) {
			$out[] = $line;
		}
	}
	return $out;
}

/** One line split on pipes. */
function ew_hosp_bits( $line, $n ) {
	$bits = array_map( 'trim', explode( '|', (string) $line ) );
	return array_pad( array_slice( $bits, 0, $n ), $n, '' );
}

/* -------------------------------------------------------------- hero -- */

function ew_hosp_hero_defaults() {
	return array(
		'eyebrow' => 'Coronation Park',
		'title'   => 'Matchday hospitality',
		'lede'    => 'Make a day of it at Coronation Park. Two matchday lounges, food and drinks, and a table that is yours before the game, at half-time and long after the final whistle.',
		'photo'   => 'hosp/hero.jpg',
		'height'  => '420',
		'back'    => '',
		'back_url' => '',
	);
}

function ew_hosp_hero_sc( $atts ) {
	$a   = shortcode_atts( ew_hosp_hero_defaults(), $atts, 'eastwood_hosp_hero' );
	$pic = ew_hosp_pic( $a['photo'] );

	$h  = '<div class="ewhp"><div class="ewhp-hero" style="height:' . max( 220, (int) $a['height'] ) . 'px">';
	$h .= '<div class="ewhp-shot"' . ( '' !== $pic ? ' style="background-image:url(' . esc_url( $pic ) . ')"' : '' ) . '></div>';
	$h .= '<div class="ewhp-ov"></div><div class="ewhp-tx"><div class="ewhp-in">';
	if ( '' !== trim( (string) $a['back'] ) ) {
		$h .= '<a class="ewhp-back" href="' . esc_url( home_url( (string) $a['back_url'] ) ) . '">'
			. esc_html( $a['back'] ) . '</a>';
	}
	if ( '' !== trim( (string) $a['eyebrow'] ) ) {
		$h .= '<p class="ewhp-eye">' . esc_html( $a['eyebrow'] ) . '</p>';
	}
	$h .= '<h1>' . esc_html( $a['title'] ) . '</h1>';
	$h .= '</div></div></div>';

	if ( '' !== trim( (string) $a['lede'] ) ) {
		$h .= '<div class="ewhp-in"><p class="ewhp-lede">' . esc_html( $a['lede'] ) . '</p></div>';
	}
	return $h . '</div>';
}
add_shortcode( 'eastwood_hosp_hero', 'ew_hosp_hero_sc' );

/* ------------------------------------------------------------- rooms -- */

function ew_hosp_rooms_defaults() {
	return array(
		'sect' => 'The rooms',
		'go'   => 'Explore {room}',
		'none' => 'Hospitality is not open at the moment.',
	);
}

/**
 * A tile per room. The name comes from On sale; the line under it, the
 * paragraph, the photograph and where the tile goes are typed here, one
 * room to a line:
 *
 *   room-key | line under the name | the paragraph | /where-it-goes/ | photo.jpg
 *
 * A room that is not in On sale is skipped rather than invented, and the
 * order of the lines is the order of the tiles, so the rooms can be swapped
 * round without touching anything else.
 */
function ew_hosp_rooms_sc( $atts, $content = '' ) {
	$a     = shortcode_atts( ew_hosp_rooms_defaults(), $atts, 'eastwood_hosp_rooms' );
	$rooms = function_exists( 'ew_sales_rooms_std' ) ? ew_sales_rooms_std() : array();

	$tiles = '';
	foreach ( ew_hosp_lines( $content ) as $line ) {
		list( $key, $cap, $blurb, $url, $photo ) = ew_hosp_bits( $line, 5 );
		$key = sanitize_key( $key );
		if ( '' === $key || ! isset( $rooms[ $key ] ) ) {
			continue;
		}
		$name = (string) $rooms[ $key ]['name'];
		$pic  = ew_hosp_pic( $photo );
		$go   = str_replace( '{room}', $name, (string) $a['go'] );

		$tiles .= '<a class="ewhp-tile" href="' . esc_url( home_url( '' !== $url ? $url : '/hospitality/' ) ) . '">';
		$tiles .= '<div class="ewhp-shot"' . ( '' !== $pic ? ' style="background-image:url(' . esc_url( $pic ) . ')"' : '' ) . '></div>';
		$tiles .= '<div class="ewhp-ov"></div><div class="ewhp-bd">';
		$tiles .= '<h2>' . esc_html( $name ) . '</h2>';
		if ( '' !== $cap ) {
			$tiles .= '<p class="ewhp-cap">' . esc_html( $cap ) . '</p>';
		}
		if ( '' !== $blurb ) {
			$tiles .= '<p>' . esc_html( $blurb ) . '</p>';
		}
		$tiles .= '<span class="ewhp-go">' . esc_html( $go ) . '</span>';
		$tiles .= '</div></a>';
	}

	$h = '<div class="ewhp"><div class="ewhp-in">';
	if ( '' !== trim( (string) $a['sect'] ) ) {
		$h .= '<p class="ewhp-sect">' . esc_html( $a['sect'] ) . '</p>';
	}
	$h .= '' !== $tiles
		? '<div class="ewhp-tiles">' . $tiles . '</div>'
		: '<p class="ewhp-none">' . esc_html( $a['none'] ) . '</p>';
	return $h . '</div></div>';
}
add_shortcode( 'eastwood_hosp_rooms', 'ew_hosp_rooms_sc' );

/* --------------------------------------------------------------- run -- */

function ew_hosp_run_defaults() {
	return array(
		'sect'      => 'The afternoon',
		'lab'       => 'Matchday hospitality',
		'title'     => 'How the afternoon runs',
		'menu'      => '',
		'menu_url'  => '',
		'menu_note' => '',
	);
}

/**
 * The running order. One step to a line:
 *
 *   3:45 | Hot food at half-time | Served back in the room.
 *
 * A step that matters more than the others -- the guest, the players coming
 * in afterwards -- is marked with a * at the front of the line and gets the
 * solid node, the same language the club's wall charts use. A step with a
 * real clock time sets it in Anton; anything else ("On arrival") is a quiet
 * label, because half an afternoon has no time on it and inventing one on a
 * page somebody is about to pay from would be a promise.
 */
function ew_hosp_run_sc( $atts, $content = '' ) {
	$a    = shortcode_atts( ew_hosp_run_defaults(), $atts, 'eastwood_hosp_run' );
	$rows = '';

	foreach ( ew_hosp_lines( $content ) as $line ) {
		$key = false;
		if ( 0 === strpos( $line, '*' ) ) {
			$key  = true;
			$line = trim( substr( $line, 1 ) );
		}
		list( $when, $what, $note ) = ew_hosp_bits( $line, 3 );
		if ( '' === $what ) {
			continue;
		}
		$clock = (bool) preg_match( '/^\d{1,2}([:.]\d{2})?\s*(AM|PM)?$/i', $when );

		$rows .= '<li' . ( $key ? ' class="is-key"' : '' ) . '>';
		$rows .= '<span class="ewhp-t' . ( $clock ? '' : ' is-word' ) . '">' . esc_html( $when ) . '</span>';
		$rows .= '<span class="ewhp-w"><b>' . esc_html( $what ) . '</b>';
		if ( '' !== $note ) {
			$rows .= '<span>' . esc_html( $note ) . '</span>';
		}
		$rows .= '</span></li>';
	}

	if ( '' === $rows ) {
		return '';
	}

	$h = '<div class="ewhp"><div class="ewhp-in">';
	if ( '' !== trim( (string) $a['sect'] ) ) {
		$h .= '<p class="ewhp-sect">' . esc_html( $a['sect'] ) . '</p>';
	}
	$h .= '<div class="ewhp-run">';
	if ( '' !== trim( (string) $a['lab'] ) ) {
		$h .= '<p class="ewhp-lab">' . esc_html( $a['lab'] ) . '</p>';
	}
	$h .= '<h2>' . esc_html( $a['title'] ) . '</h2>';
	$h .= '<ol>' . $rows . '</ol>';

	if ( '' !== trim( (string) $a['menu'] ) ) {
		$h .= '<p class="ewhp-menu"><a href="' . esc_url( home_url( (string) $a['menu_url'] ) ) . '">'
			. esc_html( $a['menu'] ) . '</a>';
		if ( '' !== trim( (string) $a['menu_note'] ) ) {
			$h .= '<span>' . esc_html( $a['menu_note'] ) . '</span>';
		}
		$h .= '</p>';
	}
	return $h . '</div></div></div>';
}
add_shortcode( 'eastwood_hosp_run', 'ew_hosp_run_sc' );

/* ------------------------------------------------------------- strip -- */

function ew_hosp_strip_defaults() {
	return array(
		'sect'   => 'What the afternoon looks like',
		'height' => '285',
	);
}

/** One photograph to a line: photo.jpg | alt text. */
function ew_hosp_strip_sc( $atts, $content = '' ) {
	$a    = shortcode_atts( ew_hosp_strip_defaults(), $atts, 'eastwood_hosp_strip' );
	$pics = '';

	foreach ( ew_hosp_lines( $content ) as $line ) {
		list( $photo, $alt ) = ew_hosp_bits( $line, 2 );
		$pic = ew_hosp_pic( $photo );
		if ( '' === $pic ) {
			continue;
		}
		$pics .= '<div class="ewhp-shot" role="img" aria-label="' . esc_attr( $alt ) . '"'
			. ' style="height:' . max( 120, (int) $a['height'] ) . 'px;background-image:url(' . esc_url( $pic ) . ')"></div>';
	}

	if ( '' === $pics ) {
		return '';
	}

	$h = '<div class="ewhp"><div class="ewhp-in">';
	if ( '' !== trim( (string) $a['sect'] ) ) {
		$h .= '<p class="ewhp-sect">' . esc_html( $a['sect'] ) . '</p>';
	}
	return $h . '<div class="ewhp-strip">' . $pics . '</div></div></div>';
}
add_shortcode( 'eastwood_hosp_strip', 'ew_hosp_strip_sc' );

/* --------------------------------------------------------------- cta -- */

function ew_hosp_cta_defaults() {
	return array(
		'title' => 'Book hospitality for a game',
		'note'  => 'Pick the fixture, then the room. Only what is open for that game is shown.',
		'label' => 'See the fixtures',
		'url'   => '/matches/',
		'plain' => 'no',
	);
}

function ew_hosp_cta_sc( $atts ) {
	$a = shortcode_atts( ew_hosp_cta_defaults(), $atts, 'eastwood_hosp_cta' );

	$h  = '<div class="ewhp"><div class="ewhp-in"><div class="ewhp-foot">';
	$h .= '<div><h3>' . esc_html( $a['title'] ) . '</h3>';
	if ( '' !== trim( (string) $a['note'] ) ) {
		$h .= '<p>' . esc_html( $a['note'] ) . '</p>';
	}
	$h .= '</div>';
	$h .= '<a class="' . ( 'yes' === $a['plain'] ? 'is-plain' : '' ) . '" href="'
		. esc_url( home_url( (string) $a['url'] ) ) . '">' . esc_html( $a['label'] ) . '</a>';
	return $h . '</div></div></div>';
}
add_shortcode( 'eastwood_hosp_cta', 'ew_hosp_cta_sc' );

/* -------------------------------------------------------------- room -- */

function ew_hosp_room_defaults() {
	return array(
		'room'   => '',
		'sect'   => 'The afternoon',
		'head'   => 'The room',
		'price'  => 'From {price}',
		'per'    => 'per person',
		'book'   => 'Book {room}',
		'url'    => '/hospitality/book/',
		'menu'   => '',
		'menu_url' => '',
		'soon'   => 'Not on sale at the moment',
	);
}

/**
 * The room page's two columns: what the afternoon includes on the left, the
 * facts and the price on the right. Lines are either an included thing, or
 * a fact when they carry a pipe:
 *
 *   A drink and a light snack on arrival
 *   Food | Half-time
 *
 * The price is the room's own, from On sale, so the page cannot quote a
 * figure the checkout would not charge. No price set yet means the button
 * says so rather than offering a booking at nothing.
 */
function ew_hosp_room_sc( $atts, $content = '' ) {
	$a     = shortcode_atts( ew_hosp_room_defaults(), $atts, 'eastwood_hosp_room' );
	$key   = sanitize_key( (string) $a['room'] );
	$rooms = function_exists( 'ew_sales_rooms_std' ) ? ew_sales_rooms_std() : array();
	$row   = (array) ( $rooms[ $key ] ?? array() );
	$name  = (string) ( $row['name'] ?? '' );
	$pence = (int) ( $row['pence'] ?? 0 );

	$inc   = '';
	$facts = '';
	foreach ( ew_hosp_lines( $content ) as $line ) {
		if ( false === strpos( $line, '|' ) ) {
			$inc .= '<li>' . esc_html( $line ) . '</li>';
			continue;
		}
		list( $lab, $val ) = ew_hosp_bits( $line, 2 );
		$facts .= '<li><span>' . esc_html( $lab ) . '</span><b>' . esc_html( $val ) . '</b></li>';
	}

	$money = function_exists( 'ew_sales_money' ) ? ew_sales_money( $pence ) : '&pound;' . number_format_i18n( $pence / 100, 2 );

	$h  = '<div class="ewhp"><div class="ewhp-in">';
	if ( '' !== trim( (string) $a['sect'] ) ) {
		$h .= '<p class="ewhp-sect">' . esc_html( $a['sect'] ) . '</p>';
	}
	$h .= '<div class="ewhp-body"><div class="ewhp-left">';
	if ( '' !== $inc ) {
		$h .= '<ul class="ewhp-inc">' . $inc . '</ul>';
	}
	$h .= '</div><div class="ewhp-card">';
	$h .= '<h3>' . esc_html( $a['head'] ) . '</h3>';
	if ( '' !== $facts ) {
		$h .= '<ul class="ewhp-spec">' . $facts . '</ul>';
	}
	if ( $pence > 0 ) {
		$h .= '<p class="ewhp-price"><b>' . wp_kses_post( str_replace( '{price}', $money, (string) $a['price'] ) ) . '</b>'
			. '<span>' . esc_html( $a['per'] ) . '</span></p>';
		$h .= '<a class="ewhp-book" href="' . esc_url( home_url( (string) $a['url'] ) ) . '">'
			. esc_html( str_replace( '{room}', $name, (string) $a['book'] ) ) . '</a>';
	} else {
		$h .= '<p class="ewhp-soon">' . esc_html( $a['soon'] ) . '</p>';
	}
	if ( '' !== trim( (string) $a['menu'] ) ) {
		$h .= '<p class="ewhp-cmenu"><a href="' . esc_url( home_url( (string) $a['menu_url'] ) ) . '">'
			. esc_html( $a['menu'] ) . '</a></p>';
	}
	return $h . '</div></div></div></div>';
}
add_shortcode( 'eastwood_hosp_room', 'ew_hosp_room_sc' );

/* --------------------------------------------------------------- css -- */

function ew_hosp_css() {
	return '
.ewhp{font-family:"Instrument Sans",system-ui,sans-serif;color:#101010}
.ewhp *{box-sizing:border-box}
.ewhp-in{max-width:1180px;margin:0 auto;padding:0 20px}
.ewhp-shot{background:#101010 center/cover no-repeat}
.ewhp-hero{position:relative;overflow:hidden}
.ewhp-hero .ewhp-shot{position:absolute;inset:0}
.ewhp-ov{position:absolute;inset:0;background:linear-gradient(180deg,rgba(10,10,11,.3),rgba(10,10,11,.5) 45%,rgba(10,10,11,.88))}
.ewhp-tx{position:absolute;left:0;right:0;bottom:30px}
.ewhp-eye{margin:0 0 10px;font-size:11.5px;font-weight:700;letter-spacing:.22em;text-transform:uppercase;color:#e5392b}
.ewhp h1{margin:0;font-family:Anton,Impact,sans-serif;font-weight:400;font-size:66px;line-height:.95;
 text-transform:uppercase;color:#fff}
.ewhp-back{display:inline-block;margin:0 0 12px;font-size:13px;font-weight:700;letter-spacing:.1em;
 text-transform:uppercase;color:#fff;text-decoration:none;border-bottom:1px solid rgba(255,255,255,.45)}
.ewhp-lede{margin:22px 0 0;max-width:66ch;font-size:17px;line-height:1.55;color:#454545}
.ewhp-sect{margin:34px 0 16px;padding-top:16px;border-top:1px solid #e4e4e4;font-size:11.5px;
 font-weight:700;letter-spacing:.22em;text-transform:uppercase;color:#8a8a8a}
.ewhp-none{color:#777;font-size:15px}
.ewhp-tiles{display:grid;grid-template-columns:1fr 1fr;gap:20px}
.ewhp-tile{position:relative;display:block;height:440px;border-radius:10px;overflow:hidden;
 text-decoration:none;color:#fff}
.ewhp-tile .ewhp-shot{position:absolute;inset:0}
.ewhp-tile .ewhp-ov{background:linear-gradient(180deg,rgba(10,10,11,.1) 16%,rgba(10,10,11,.6) 46%,rgba(10,10,11,.97) 76%)}
.ewhp-bd{position:absolute;left:26px;right:26px;bottom:24px}
.ewhp-bd h2{margin:0;font-family:Anton,Impact,sans-serif;font-weight:400;font-size:38px;line-height:1;
 text-transform:uppercase;color:#fff}
.ewhp-cap{margin:9px 0 0;font-size:11.5px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#9a9aa3}
.ewhp-bd p{margin:10px 0 0;font-size:15px;line-height:1.55;color:#c6c6cd}
.ewhp-go{margin-top:18px;display:inline-block;border:1.5px solid #fff;border-radius:6px;padding:11px 20px;
 font-size:14px;font-weight:700;color:#fff}
.ewhp-run{background:#fff;border:1px solid #e2e2e2;border-radius:12px;padding:32px 36px 30px}
.ewhp-lab{margin:0;font-size:11.5px;font-weight:700;letter-spacing:.2em;text-transform:uppercase;color:#8a8a8a}
.ewhp-run h2{margin:9px 0 0;font-family:Anton,Impact,sans-serif;font-weight:400;font-size:40px;line-height:1;
 text-transform:uppercase;color:#101010}
.ewhp ol,.ewhp ul{list-style:none!important}
.ewhp li{list-style:none!important}
.ewhp li::marker{content:""}
.ewhp-run ol{margin:24px 0 0;padding:0}
.ewhp-run li{position:relative;display:grid;grid-template-columns:96px minmax(0,1fr);gap:26px;padding:18px 0;
 border-top:1px solid #ececec}
.ewhp-run li:first-child{border-top:0}
.ewhp-run li:before{content:none}
.ewhp-run li:after{content:"";position:absolute;left:127px;top:30px;bottom:-25px;width:1px;background:#e0e0e0}
.ewhp-run li:last-child:after{display:none}
.ewhp-t{font-family:Anton,Impact,sans-serif;font-weight:400;font-size:29px;line-height:1;text-align:right;
 color:#101010;padding-top:2px}
.ewhp-t.is-word{font-family:"Instrument Sans",system-ui,sans-serif;font-size:11.5px;font-weight:700;
 letter-spacing:.14em;text-transform:uppercase;color:#9a9aa3;line-height:1.4;padding-top:5px}
.ewhp-w{position:relative;padding-left:28px}
.ewhp-w:before{content:"";position:absolute;left:0;top:7px;width:11px;height:11px;border-radius:50%;
 border:1.5px solid #c4c4c4;background:#fff;z-index:2}
.ewhp-run li.is-key .ewhp-w:before{background:#CC0000;border-color:#CC0000}
.ewhp-w b{display:block;font-size:19px;font-weight:700;line-height:1.3;color:#101010}
.ewhp-w span{display:block;margin-top:5px;font-size:14.5px;line-height:1.55;color:#555}
.ewhp-menu{margin:22px 0 0;padding-top:16px;border-top:1px solid #ececec;display:flex;align-items:baseline;
 gap:12px;flex-wrap:wrap}
.ewhp-menu a{font-size:14.5px;font-weight:700;color:#101010;text-decoration:underline}
.ewhp-menu span{font-size:13.5px;color:#777}
.ewhp-strip{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
.ewhp-strip .ewhp-shot{border-radius:8px}
.ewhp-foot{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:24px;align-items:center;
 border-top:1px solid #e4e4e4;margin-top:34px;padding:22px 0 8px}
.ewhp-foot h3{margin:0;font-family:Anton,Impact,sans-serif;font-weight:400;font-size:28px;
 text-transform:uppercase;color:#101010}
.ewhp-foot p{margin:7px 0 0;font-size:14.5px;color:#666}
.ewhp-foot a{background:#CC0000;color:#fff;text-decoration:none;border-radius:6px;padding:15px 26px;
 font-size:15px;font-weight:700;white-space:nowrap}
.ewhp-foot a.is-plain{background:transparent;color:#101010;border:1.5px solid #101010}
.ewhp-body{display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:26px;align-items:start}
.ewhp-inc{margin:0;padding:0;display:grid;grid-template-columns:1fr 1fr;gap:12px 22px}
.ewhp-inc li{position:relative;padding-left:20px;font-size:15.5px;line-height:1.5;color:#222}
.ewhp-inc li:before{content:"";position:absolute;left:0;top:10px;width:10px;height:2px;background:#CC0000}
.ewhp-card{background:#fff;border:1px solid #e2e2e2;border-radius:10px;padding:22px 24px}
.ewhp-card h3{margin:0 0 14px;font-family:Anton,Impact,sans-serif;font-weight:400;font-size:24px;
 text-transform:uppercase;color:#101010}
.ewhp-spec{margin:0;padding:0}
.ewhp-spec li{display:flex;justify-content:space-between;gap:14px;padding:10px 0;font-size:14.5px;
 border-top:1px solid #ececec;color:#666}
.ewhp-spec li:first-child{border-top:0}
.ewhp-spec li b{color:#101010;font-weight:700;text-align:right}
.ewhp-price{margin:18px 0 0;display:flex;align-items:baseline;gap:9px;border-top:1px solid #ececec;padding-top:16px}
.ewhp-price b{font-family:Anton,Impact,sans-serif;font-weight:400;font-size:34px;color:#101010}
.ewhp-price span{font-size:13.5px;color:#666}
.ewhp-book{margin:14px 0 0;display:block;background:#CC0000;color:#fff;text-align:center;text-decoration:none;
 border-radius:6px;padding:15px;font-size:15px;font-weight:700}
.ewhp-soon{margin:18px 0 0;padding-top:16px;border-top:1px solid #ececec;font-size:14.5px;color:#777}
.ewhp-cmenu{margin:14px 0 0;text-align:center;font-size:13.5px;color:#666}
.ewhp-cmenu a{color:#101010;font-weight:700}
@media(max-width:900px){
 .ewhp h1{font-size:44px}
 .ewhp-tiles,.ewhp-body,.ewhp-inc{grid-template-columns:1fr}
 .ewhp-tile{height:360px}
 .ewhp-strip{grid-template-columns:1fr 1fr}
 .ewhp-foot{grid-template-columns:1fr}
 .ewhp-run{padding:24px 20px}
 .ewhp-run li{grid-template-columns:76px minmax(0,1fr);gap:16px}
 .ewhp-run li:after{left:97px}
}
@media(max-width:560px){
 .ewhp-strip{grid-template-columns:1fr}
}';
}

add_action( 'wp_head', function () {
	$post = is_singular() ? get_post() : null;
	if ( ! $post || ! function_exists( 'ew_post_contains' ) ) {
		return;
	}
	foreach ( array( 'eastwood_hosp_hero', 'eastwood_hosp_rooms', 'eastwood_hosp_run',
		'eastwood_hosp_strip', 'eastwood_hosp_cta', 'eastwood_hosp_room' ) as $tag ) {
		if ( ew_post_contains( $post, $tag ) ) {
			echo '<style id="ew-hosp">' . ew_hosp_css() . '</style>'; // phpcs:ignore
			return;
		}
	}
}, 21 );

/** The command channel may write these blocks. */
add_filter( 'ew_blocks_allowed', function ( $tags ) {
	return array_merge( (array) $tags, array(
		'eastwood_hosp_hero', 'eastwood_hosp_rooms', 'eastwood_hosp_run',
		'eastwood_hosp_strip', 'eastwood_hosp_cta', 'eastwood_hosp_room',
	) );
} );
