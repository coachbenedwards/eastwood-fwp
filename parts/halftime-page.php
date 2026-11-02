<?php
/**
 * The half time show page, below the hero: eastwoodfc.com/halftimeshow.
 *
 * The hero and the sign-up form at the top are halftime.php. Everything
 * under them is here, one block per section, in the order the approved
 * board (HTS-12) runs them:
 *
 *   [eastwood_hts_next]    the next show: the fixture, then the featured
 *                          game on the pitch and the featured app game
 *   [eastwood_hts_steps]   how to take part, as a two-lane timeline
 *   [eastwood_hts_past]    a past show: the result and its photographs
 *   [eastwood_hts_coming]  the next home games, each with its half time game
 *   [eastwood_hts_vote]    the vote for the next half time game, live counts
 *   [eastwood_hts_play]    the app queue, as three phones
 *
 * Section headings and the line under each are the page's own heading and
 * text widgets, not these blocks. Every word a block shows is a field on it,
 * defaults in the *_defaults() functions, lists as pipe-delimited lines in
 * the block's body.
 *
 * The fixtures come from the league feed through the same chokepoint as
 * every other surface (ew_fixtures_league_list + ew_fixture_apply), so a
 * corrected date or a dropped game reaches this page with the rest.
 *
 * Votes: POST /wp-json/eastwood/v1/halftime/vote  {game, round, page}
 *        GET  /wp-json/eastwood/v1/halftime/votes?round=...
 * Counts are kept in the option ew_hts_votes, per round, so changing the
 * round field on the block starts a fresh vote. One vote per browser is
 * held in localStorage, with a light per-address limit on the server.
 *
 * @package eastwood-fwp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EW_HTS_TAGS = array(
	'eastwood_hts_next', 'eastwood_hts_steps', 'eastwood_hts_past',
	'eastwood_hts_coming', 'eastwood_hts_vote', 'eastwood_hts_play',
);

/* ------------------------------------------------------------------
 * Shared helpers
 * ------------------------------------------------------------------ */

/** Lines from a block body, through the shared reader when it is loaded. */
function ew_hts_lines( $content ) {
	if ( function_exists( 'ew_hb_lines' ) ) {
		return ew_hb_lines( $content );
	}
	$out = array();
	foreach ( preg_split( '/\r\n|\r|\n/', wp_strip_all_tags( (string) $content ) ) as $l ) {
		$l = trim( $l );
		if ( '' !== $l ) {
			$out[] = $l;
		}
	}
	return $out;
}

/** A pipe list in a field, trimmed, empties dropped. */
function ew_hts_pipes( $s ) {
	return array_values( array_filter( array_map( 'trim', explode( '|', (string) $s ) ), 'strlen' ) );
}

/** The fixtures, corrected, oldest first. */
function ew_hts_fixtures() {
	static $all = null;
	if ( null !== $all ) {
		return $all;
	}
	$all = array();
	if ( ! function_exists( 'ew_fixtures_league_list' ) ) {
		return $all;
	}
	foreach ( ew_fixtures_league_list() as $m ) {
		$m = function_exists( 'ew_fixture_apply' ) ? ew_fixture_apply( $m ) : $m;
		if ( $m ) {
			$all[] = $m;
		}
	}
	usort( $all, function ( $a, $b ) {
		return strcmp( (string) ( $a['date'] ?? '' ) . (string) ( $a['time'] ?? '' ),
			(string) ( $b['date'] ?? '' ) . (string) ( $b['time'] ?? '' ) );
	} );
	return $all;
}

function ew_hts_is_home( $m ) {
	return (int) ( ( (array) ( $m['home-team'] ?? array() ) )['id'] ?? 0 ) === (int) EW_TEAM;
}

function ew_hts_played( $m ) {
	return 'FT' === (string) ( $m['status']['short'] ?? '' );
}

/** Home games still to come, from today. */
function ew_hts_home_to_come() {
	$today = function_exists( 'current_time' ) ? current_time( 'Y-m-d' ) : gmdate( 'Y-m-d' );
	$out   = array();
	foreach ( ew_hts_fixtures() as $m ) {
		if ( (string) ( $m['date'] ?? '' ) < $today || ew_hts_played( $m ) || ! ew_hts_is_home( $m ) ) {
			continue;
		}
		$out[] = $m;
	}
	return $out;
}

/** One match by feed id, or by date (any format strtotime reads). */
function ew_hts_find( $id, $date ) {
	$id  = (int) $id;
	$day = '' !== trim( (string) $date ) ? strtotime( (string) $date ) : 0;
	foreach ( ew_hts_fixtures() as $m ) {
		if ( $id && (int) ( $m['id'] ?? 0 ) === $id ) {
			return $m;
		}
		if ( ! $id && $day && (string) ( $m['date'] ?? '' ) === gmdate( 'Y-m-d', $day ) ) {
			return $m;
		}
	}
	return null;
}

/** A media-library picture at a sensible size, rather than the 2560px original. */
function ew_hts_img( $url, $size = 'large' ) {
	$url = trim( (string) $url );
	if ( '' === $url || ! function_exists( 'attachment_url_to_postid' ) ) {
		return $url;
	}
	$id = attachment_url_to_postid( $url );
	if ( ! $id ) {
		// A -scaled upload is stored under its unscaled name too.
		$id = attachment_url_to_postid( str_replace( '-scaled.', '.', $url ) );
	}
	if ( $id ) {
		$src = wp_get_attachment_image_url( $id, $size );
		if ( $src ) {
			return $src;
		}
	}
	return $url;
}

function ew_hts_crest( $id ) {
	return function_exists( 'ew_home_badge' ) ? ew_home_badge( (int) $id ) : '';
}

function ew_hts_comp( $m ) {
	$cid = (int) ( $m['competition']['id'] ?? 0 );
	return ( $cid && function_exists( 'ew_comp_badge' ) ) ? ew_comp_badge( $cid ) : '';
}

/** The initials of a game, for the tile shown when it has no drawing. */
function ew_hts_initials( $title ) {
	$skip  = array( 'or', 'with', 'the', 'it', 'in', 'a', 'of', 'is', 'and' );
	$words = preg_split( '/\s+/u', trim( (string) $title ) );
	$out   = '';
	foreach ( $words as $w ) {
		$w = preg_replace( '/[^\p{L}\p{N}]/u', '', $w );
		if ( '' === $w || ( in_array( strtolower( $w ), $skip, true ) && '' !== $out ) ) {
			continue;
		}
		$out .= function_exists( 'mb_substr' ) ? mb_strtoupper( mb_substr( $w, 0, 1 ) ) : strtoupper( $w[0] );
		if ( strlen( $out ) >= 3 ) {
			break;
		}
	}
	return '' !== $out ? $out : '?';
}

/** Picture or initials tile. */
function ew_hts_picture( $url, $title, $class, $alt = '' ) {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return '<div class="' . esc_attr( $class ) . ' ewhp-tile" aria-hidden="true"><span>'
			. esc_html( ew_hts_initials( $title ) ) . '</span></div>';
	}
	return '<img class="' . esc_attr( $class ) . '" src="' . esc_url( ew_hts_img( $url ) ) . '" alt="'
		. esc_attr( $alt ) . '" loading="lazy" decoding="async">';
}

/** The two store buttons. $style is 'line' (dark card) or 'solid' (white). */
function ew_hts_stores( $a, $style ) {
	$apple = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M16.4 12.6c0-2.4 2-3.6 2.1-3.7-1.1-1.7-2.9-1.9-3.5-1.9-1.5-.2-2.9.9-3.7.9-.8 0-1.9-.9-3.2-.8-1.6 0-3.1 1-4 2.4-1.7 3-.4 7.4 1.2 9.8.8 1.2 1.8 2.5 3 2.4 1.2 0 1.7-.8 3.1-.8 1.5 0 1.9.8 3.2.8 1.3 0 2.1-1.2 2.9-2.4.9-1.4 1.3-2.7 1.3-2.8-.1 0-2.5-1-2.5-3.9zM14 5.5c.7-.8 1.1-1.9 1-3-1 0-2.1.7-2.8 1.5-.6.7-1.2 1.8-1 2.9 1.1.1 2.1-.6 2.8-1.4z"/></svg>';
	$play  = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="#34A853" d="M3.6 2.3l10.6 10.6L3.6 21.7c-.4-.2-.6-.6-.6-1.1V3.4c0-.5.2-.9.6-1.1z"/><path fill="#FBBC04" d="M17.6 9.4l-3.4 3.5 3.4 3.4 3.9-2.2c1.1-.6 1.1-1.6 0-2.3z"/><path fill="#4285F4" d="M3.6 21.7l10.6-8.8 3.4 3.4-12.4 7c-.6.3-1.2.4-1.6-.1z"/><path fill="#EA4335" d="M3.6 2.3c.4-.5 1-.4 1.6-.1l12.4 7-3.4 3.5z"/></svg>';
	$icons = 'line' === $style;
	$h     = '';
	foreach ( array(
		array( $a['ios_url'], $a['ios_small'], $a['ios_big'], $apple ),
		array( $a['play_url'], $a['play_small'], $a['play_big'], $play ),
	) as $b ) {
		if ( '' === trim( (string) $b[0] ) ) {
			continue;
		}
		$h .= '<a class="ewhp-store is-' . esc_attr( $style ) . '" href="' . esc_url( $b[0] ) . '" rel="noopener">'
			. ( $icons ? $b[3] : '' )
			. '<span><small>' . esc_html( $b[1] ) . '</small><b>' . esc_html( $b[2] ) . '</b></span></a>';
	}
	return $h;
}

/** The store fields every block with buttons shares. */
function ew_hts_store_defaults() {
	return array(
		'ios_small'  => 'Download on the',
		'ios_big'    => 'App Store',
		'ios_url'    => 'https://apps.apple.com/gb/app/eastwood-fc/id6820880105',
		'play_small' => 'Get it on',
		'play_big'   => 'Google Play',
		'play_url'   => 'https://play.google.com/store/apps/details?id=com.eastwoodfc.team',
	);
}

/**
 * One fixture panel, the /matches shape: badge strip, Home and the ground,
 * NAME crest V crest NAME. $foot is optional markup for the strip under it.
 */
function ew_hts_fixture( $m, $a, $foot = '' ) {
	$home  = (array) ( $m['home-team'] ?? array() );
	$away  = (array) ( $m['away-team'] ?? array() );
	$ts    = strtotime( (string) ( $m['date'] ?? '' ) . ' ' . (string) ( $m['time'] ?? '15:00' ) );
	$badge = ew_hts_comp( $m );
	$comp  = (string) ( $m['competition']['name'] ?? '' );
	$at    = ew_hts_is_home( $m );

	$h  = '<div class="ewhp-fx">';
	$h .= '<div class="ewhp-fxTop">';
	if ( '' !== $badge ) {
		$h .= '<span class="ewhp-fxBadge"><img src="' . esc_url( $badge ) . '" alt="" loading="lazy" decoding="async" onerror="this.parentNode.remove()"></span>';
	}
	$h .= '<b>' . esc_html( $ts ? date_i18n( 'D j M', $ts ) : '' ) . '</b>';
	$h .= '<i>' . esc_html( $ts ? date_i18n( 'H:i', $ts ) : '' ) . '</i>';
	if ( '' !== $comp ) {
		$h .= '<span class="ewhp-fxComp">' . esc_html( $comp ) . '</span>';
	}
	$h .= '</div>';
	$h .= '<div class="ewhp-fxBody">';
	$h .= '<p class="ewhp-fxWhere"><em>' . esc_html( $at ? $a['home_word'] : $a['away_word'] ) . '</em>'
		. esc_html( $at ? $a['venue'] : (string) ( $m['venue'] ?? '' ) ) . '</p>';
	$h .= '<div class="ewhp-tie">';
	$h .= '<span class="ewhp-tieName">' . esc_html( (string) ( $home['name'] ?? '' ) ) . '</span>';
	$h .= '<span class="ewhp-tieMid">';
	$h .= '<img src="' . esc_url( ew_hts_crest( $home['id'] ?? 0 ) ) . '" alt="' . esc_attr( (string) ( $home['name'] ?? '' ) ) . '" loading="lazy" decoding="async" onerror="this.style.visibility=\'hidden\'">';
	$h .= '<b class="ewhp-tieV">' . esc_html( $a['v'] ) . '</b>';
	$h .= '<img src="' . esc_url( ew_hts_crest( $away['id'] ?? 0 ) ) . '" alt="' . esc_attr( (string) ( $away['name'] ?? '' ) ) . '" loading="lazy" decoding="async" onerror="this.style.visibility=\'hidden\'">';
	$h .= '</span>';
	$h .= '<span class="ewhp-tieName">' . esc_html( (string) ( $away['name'] ?? '' ) ) . '</span>';
	$h .= '</div></div>';
	$h .= $foot;
	return $h . '</div>';
}

/* ------------------------------------------------------------------
 * 1. [eastwood_hts_next]
 * ------------------------------------------------------------------ */

function ew_hts_next_defaults() {
	return array(
		'match'         => '',
		'home_word'     => 'Home',
		'away_word'     => 'Away',
		'venue'         => 'Coronation Park',
		'v'             => 'V',
		'empty'         => 'The next home game will be here once it is fixed.',
		'pitch_tag'     => 'On the pitch · Featured game',
		'pitch_img'     => 'https://eastwoodfc.com/wp-content/uploads/2026/10/image-scaled.jpg',
		'pitch_alt'     => 'Children on the pitch at a half time show',
		'pitch_title'   => 'Penalty shoot-out',
		'guest_kicker'  => 'Special guest',
		'guest_name'    => 'Jamie Bigg',
		'guest_line'    => 'Giant, from Gladiators',
		'guest_img'     => '',
		'pitch_text'    => 'Kids from the crowd step up and take him on from the spot, in front of Coronation Park.',
		'pitch_btn'     => 'Put your child forward',
		'pitch_url'     => '#form',
		'screen_tag'    => 'On the big screen · Featured app game',
		'screen_img'    => '',
		'screen_title'  => 'Curby with Curtis',
		'played_kicker' => 'Played on',
		'played_name'   => 'Team EastwoodFC app',
		'played_line'   => 'Anyone in the ground can play',
		'played_img'    => '',
		'screen_text'   => 'Join the queue in the app at half time. When it’s your go, you’re on the big screen.',
	) + ew_hts_store_defaults();
}

function ew_hts_next_sc( $atts ) {
	$a = shortcode_atts( ew_hts_next_defaults(), (array) $atts, 'eastwood_hts_next' );

	$m = null;
	if ( '' !== trim( (string) $a['match'] ) ) {
		$m = ew_hts_find( $a['match'], '' );
	}
	if ( ! $m ) {
		$next = ew_hts_home_to_come();
		$m    = $next ? $next[0] : null;
	}

	$h  = '<div class="ewhp ewhp-next">';
	$h .= $m ? ew_hts_fixture( $m, $a ) : '<p class="ewhp-empty">' . esc_html( $a['empty'] ) . '</p>';

	$h .= '<div class="ewhp-feat">';

	// On the pitch.
	$h .= '<article class="ewhp-card">';
	$h .= '<div class="ewhp-cardMedia">' . ew_hts_picture( $a['pitch_img'], $a['pitch_title'], 'ewhp-cardImg', $a['pitch_alt'] );
	if ( '' !== $a['pitch_tag'] ) {
		$h .= '<p class="ewhp-tag">' . esc_html( $a['pitch_tag'] ) . '</p>';
	}
	$h .= '</div><div class="ewhp-cardBody">';
	$h .= '<h3 class="ewhp-cardTitle">' . esc_html( $a['pitch_title'] ) . '</h3>';
	if ( '' !== $a['guest_name'] ) {
		$h .= '<div class="ewhp-who">';
		$h .= '' !== trim( $a['guest_img'] )
			? '<img class="ewhp-whoPic is-round" src="' . esc_url( ew_hts_img( $a['guest_img'], 'medium' ) ) . '" alt="' . esc_attr( $a['guest_name'] ) . '" loading="lazy">'
			: '<span class="ewhp-whoPic is-round is-blank" aria-hidden="true"></span>';
		$h .= '<div><p class="ewhp-whoK">' . esc_html( $a['guest_kicker'] ) . '</p>'
			. '<p class="ewhp-whoN">' . esc_html( $a['guest_name'] ) . '</p>'
			. ( '' !== $a['guest_line'] ? '<p class="ewhp-whoL">' . esc_html( $a['guest_line'] ) . '</p>' : '' )
			. '</div></div>';
	}
	if ( '' !== $a['pitch_text'] ) {
		$h .= '<p class="ewhp-cardText">' . esc_html( $a['pitch_text'] ) . '</p>';
	}
	if ( '' !== $a['pitch_btn'] ) {
		$h .= '<a class="ewhp-btn" href="' . esc_url( $a['pitch_url'] ) . '" data-ewhp-scroll>' . esc_html( $a['pitch_btn'] ) . '</a>';
	}
	$h .= '</div></article>';

	// On the big screen.
	$h .= '<article class="ewhp-card">';
	$h .= '<div class="ewhp-cardMedia is-contain">' . ew_hts_picture( $a['screen_img'], $a['screen_title'], 'ewhp-cardImg', $a['screen_title'] );
	if ( '' !== $a['screen_tag'] ) {
		$h .= '<p class="ewhp-tag">' . esc_html( $a['screen_tag'] ) . '</p>';
	}
	$h .= '</div><div class="ewhp-cardBody">';
	$h .= '<h3 class="ewhp-cardTitle">' . esc_html( $a['screen_title'] ) . '</h3>';
	if ( '' !== $a['played_name'] ) {
		$icon = '' !== trim( $a['played_img'] ) ? $a['played_img'] : ew_hts_crest( EW_TEAM );
		$h   .= '<div class="ewhp-who">';
		$h   .= '<img class="ewhp-whoPic is-icon" src="' . esc_url( $icon ) . '" alt="" loading="lazy">';
		$h   .= '<div><p class="ewhp-whoK">' . esc_html( $a['played_kicker'] ) . '</p>'
			. '<p class="ewhp-whoN">' . esc_html( $a['played_name'] ) . '</p>'
			. ( '' !== $a['played_line'] ? '<p class="ewhp-whoL">' . esc_html( $a['played_line'] ) . '</p>' : '' )
			. '</div></div>';
	}
	if ( '' !== $a['screen_text'] ) {
		$h .= '<p class="ewhp-cardText">' . esc_html( $a['screen_text'] ) . '</p>';
	}
	$stores = ew_hts_stores( $a, 'line' );
	if ( '' !== $stores ) {
		$h .= '<div class="ewhp-stores">' . $stores . '</div>';
	}
	$h .= '</div></article>';

	$h .= '</div>';
	return $h . '</div>';
}
add_shortcode( 'eastwood_hts_next', 'ew_hts_next_sc' );

/* ------------------------------------------------------------------
 * 2. [eastwood_hts_steps]
 *    Before|the game|On the pitch box|On the big screen box
 *    A row with nothing in the last box has one box across both lanes.
 * ------------------------------------------------------------------ */

function ew_hts_steps_defaults() {
	return array(
		'left'  => 'On the pitch',
		'right' => 'On the big screen',
		'lines' => "Before|the game|Put your child forward with the form at the top of this page.|Download the Team EastwoodFC app.\n"
			. "On|the day|We text you where to be and when.|Open the app and join the half time queue.\n"
			. "15:45|half time|Out on the pitch in front of the crowd.|Your go, live on the big screen.\n"
			. '16:00|second half|Back to your seat for the second half.|',
	);
}

function ew_hts_steps_sc( $atts, $content = '' ) {
	$d     = ew_hts_steps_defaults();
	$a     = shortcode_atts( $d, (array) $atts, 'eastwood_hts_steps' );
	$lines = ew_hts_lines( $content );
	if ( ! $lines ) {
		$lines = ew_hts_lines( $d['lines'] );
	}

	$h  = '<div class="ewhp ewhp-steps">';
	$h .= '<div class="ewhp-stHead" aria-hidden="true"><span></span><p>' . esc_html( $a['left'] ) . '</p><p>' . esc_html( $a['right'] ) . '</p></div>';
	$n  = count( $lines );
	foreach ( $lines as $i => $line ) {
		$c    = array_pad( array_map( 'trim', explode( '|', $line ) ), 4, '' );
		$last = $i === $n - 1;
		$h   .= '<div class="ewhp-stRow' . ( $last ? ' is-last' : '' ) . '">';
		if ( ! $last ) {
			$h .= '<span class="ewhp-stLine" aria-hidden="true"></span>';
		}
		$h .= '<div class="ewhp-stTime"><b>' . esc_html( $c[0] ) . '</b>'
			. ( '' !== $c[1] ? '<span>' . esc_html( $c[1] ) . '</span>' : '' ) . '</div>';
		$h .= '<span class="ewhp-stDot" aria-hidden="true"></span>';
		if ( '' === $c[3] ) {
			$h .= '<div class="ewhp-stBox is-wide">' . esc_html( $c[2] ) . '</div>';
		} else {
			$h .= '<div class="ewhp-stBox"><em>' . esc_html( $a['left'] ) . '</em>' . esc_html( $c[2] ) . '</div>';
			$h .= '<div class="ewhp-stBox"><em>' . esc_html( $a['right'] ) . '</em>' . esc_html( $c[3] ) . '</div>';
		}
		$h .= '</div>';
	}
	return $h . '</div>';
}
add_shortcode( 'eastwood_hts_steps', 'ew_hts_steps_sc' );

/* ------------------------------------------------------------------
 * 3. [eastwood_hts_past date="2026-10-03" photos="url|url"]
 * ------------------------------------------------------------------ */

function ew_hts_past_defaults() {
	return array(
		'match'     => '',
		'date'      => '2026-10-03',
		'home_word' => 'Home',
		'away_word' => 'Away',
		'venue'     => 'Coronation Park',
		'v'         => 'V',
		'ft'        => 'FT',
		'crowd'     => 'Crowd',
		'photos'    => 'https://eastwoodfc.com/wp-content/uploads/2026/10/image-scaled.jpg|https://eastwoodfc.com/wp-content/uploads/2026/10/image-1-scaled.jpg',
		'photo_alt' => 'The half time show',
	);
}

function ew_hts_past_sc( $atts ) {
	$a = shortcode_atts( ew_hts_past_defaults(), (array) $atts, 'eastwood_hts_past' );
	$m = ( '' !== trim( (string) $a['match'] ) || '' !== trim( (string) $a['date'] ) )
		? ew_hts_find( $a['match'], $a['date'] ) : null;

	$h = '<div class="ewhp ewhp-past"><div class="ewhp-pastCard">';

	if ( $m ) {
		$home  = (array) ( $m['home-team'] ?? array() );
		$away  = (array) ( $m['away-team'] ?? array() );
		$ts    = strtotime( (string) ( $m['date'] ?? '' ) );
		$badge = ew_hts_comp( $m );
		$comp  = (string) ( $m['competition']['name'] ?? '' );
		$at    = ew_hts_is_home( $m );
		$gate  = (int) ( $m['attendance'] ?? 0 );

		$h .= '<div class="ewhp-pastTop">';
		if ( '' !== $badge ) {
			$h .= '<img class="ewhp-pastBadge" src="' . esc_url( $badge ) . '" alt="" loading="lazy" onerror="this.remove()">';
		}
		$h .= '<p class="ewhp-pastWhen">' . esc_html( $ts ? strtoupper( date_i18n( 'D j M', $ts ) ) : '' )
			. ( ew_hts_played( $m ) ? ' <strong>' . esc_html( $a['ft'] ) . '</strong>' : '' ) . '</p>';
		if ( '' !== $comp ) {
			$h .= '<p class="ewhp-pastComp">' . esc_html( $comp ) . '</p>';
		}
		$h .= '</div>';

		$where = esc_html( $at ? $a['venue'] : (string) ( $m['venue'] ?? '' ) );
		if ( $gate && '' !== $a['crowd'] ) {
			$where .= ' &nbsp;·&nbsp; ' . esc_html( $a['crowd'] . ' ' . number_format( $gate ) );
		}
		$h .= '<p class="ewhp-pastWhere"><strong>' . esc_html( $at ? $a['home_word'] : $a['away_word'] ) . '</strong> &nbsp;' . $where . '</p>';

		$score = ew_hts_played( $m )
			? (int) ( $home['score'] ?? 0 ) . ' – ' . (int) ( $away['score'] ?? 0 )
			: $a['v'];
		$h .= '<div class="ewhp-pastTie">';
		$h .= '<p class="ewhp-pastName is-home">' . esc_html( (string) ( $home['name'] ?? '' ) ) . '</p>';
		$h .= '<span class="ewhp-pastMid">';
		$h .= '<img src="' . esc_url( ew_hts_crest( $home['id'] ?? 0 ) ) . '" alt="' . esc_attr( (string) ( $home['name'] ?? '' ) ) . '" loading="lazy" onerror="this.style.visibility=\'hidden\'">';
		$h .= '<b>' . esc_html( $score ) . '</b>';
		$h .= '<img src="' . esc_url( ew_hts_crest( $away['id'] ?? 0 ) ) . '" alt="' . esc_attr( (string) ( $away['name'] ?? '' ) ) . '" loading="lazy" onerror="this.style.visibility=\'hidden\'">';
		$h .= '</span>';
		$h .= '<p class="ewhp-pastName is-away">' . esc_html( (string) ( $away['name'] ?? '' ) ) . '</p>';
		$h .= '</div>';
	}

	$photos = array_slice( ew_hts_pipes( $a['photos'] ), 0, 6 );
	if ( $photos ) {
		$h .= '<div class="ewhp-photos is-n' . count( $photos ) . ( $m ? '' : ' is-alone' ) . '">';
		foreach ( $photos as $i => $p ) {
			$h .= '<img src="' . esc_url( ew_hts_img( $p ) ) . '" alt="' . esc_attr( $a['photo_alt'] ) . '" loading="lazy" decoding="async">';
		}
		$h .= '</div>';
	}

	return $h . '</div></div>';
}
add_shortcode( 'eastwood_hts_past', 'ew_hts_past_sc' );

/* ------------------------------------------------------------------
 * 4. [eastwood_hts_coming count="5" skip="1"]
 *    2026-12-05|Penalty shoot-out|With Jamie Bigg
 *    Keyed by date; a game without a line shows the two defaults.
 * ------------------------------------------------------------------ */

function ew_hts_coming_defaults() {
	return array(
		'count'     => '5',
		'skip'      => '1',
		'home_word' => 'Home',
		'away_word' => 'Away',
		'venue'     => 'Coronation Park',
		'v'         => 'V',
		'label'     => 'Half time show',
		'game'      => '[Half time game]',
		'status'    => 'Not announced yet',
		'empty'     => 'The next home games will be here once they are fixed.',
	);
}

function ew_hts_coming_sc( $atts, $content = '' ) {
	$a = shortcode_atts( ew_hts_coming_defaults(), (array) $atts, 'eastwood_hts_coming' );

	$plan = array();
	foreach ( ew_hts_lines( $content ) as $line ) {
		$c  = array_pad( array_map( 'trim', explode( '|', $line ) ), 3, '' );
		$ts = strtotime( $c[0] );
		if ( $ts ) {
			$plan[ gmdate( 'Y-m-d', $ts ) ] = $c;
		}
	}

	$games = array_slice( ew_hts_home_to_come(), max( 0, (int) $a['skip'] ), max( 1, (int) $a['count'] ) );
	if ( ! $games ) {
		return '<div class="ewhp ewhp-coming"><p class="ewhp-empty">' . esc_html( $a['empty'] ) . '</p></div>';
	}

	$h     = '<div class="ewhp ewhp-coming">';
	$month = '';
	$open  = false;
	foreach ( $games as $m ) {
		$ts  = strtotime( (string) ( $m['date'] ?? '' ) );
		$key = $ts ? date_i18n( 'F Y', $ts ) : '';
		if ( $key !== $month ) {
			if ( $open ) {
				$h .= '</div>';
			}
			$month = $key;
			$h    .= '<p class="ewhp-month">' . esc_html( $ts ? date_i18n( 'F', $ts ) : '' ) . '</p><div class="ewhp-rows">';
			$open  = true;
		}
		$p      = $plan[ (string) ( $m['date'] ?? '' ) ] ?? null;
		$game   = ( $p && '' !== $p[1] ) ? $p[1] : $a['game'];
		$status = ( $p && '' !== $p[2] ) ? $p[2] : ( $p ? '' : $a['status'] );
		$foot   = '<div class="ewhp-fxFoot"><span>' . esc_html( $a['label'] ) . '</span>'
			. '<b>' . esc_html( $game ) . '</b>'
			. ( '' !== $status ? '<i>' . esc_html( $status ) . '</i>' : '' ) . '</div>';
		$h     .= ew_hts_fixture( $m, $a, $foot );
	}
	if ( $open ) {
		$h .= '</div>';
	}
	return $h . '</div>';
}
add_shortcode( 'eastwood_hts_coming', 'ew_hts_coming_sc' );

/* ------------------------------------------------------------------
 * 5. [eastwood_hts_vote round="hucknall"]
 *    Title|Player|Description|image url
 * ------------------------------------------------------------------ */

function ew_hts_vote_defaults() {
	return array(
		'round'    => 'next',
		'anchor'   => 'vote',
		'open'     => 'yes',
		'vote'     => 'Vote',
		'voted'    => 'Voted',
		'one'      => 'vote',
		'many'     => 'votes',
		'thanks'   => 'Thanks, your vote is in.',
		'already'  => 'You have already voted.',
		'busy'     => 'Too many votes from here just now. Try again later.',
		'failed'   => 'That did not go through. Please try again.',
		'closed'   => 'Voting has closed.',
		'lines'    => "Scott or Not|Scott Loach|A replay freezes before the ball arrives. Goal or save? Tap before it plays on.|\n"
			. "Charlie’s Angles|Charlie Marzano|Bounce it off the walls and into the net. Fewest bounces wins.|\n"
			. "Louis Says|Louis Nicholson|Simon Says with the whole ground. Fall for it and you’re out.|\n"
			. "Curby with Curtis|Curtis Thompson|The street game. Hit the kerb clean, catch it, first to 10.|\n"
			. "Tom Raider|Tom Marshall|Dodge the defenders and the puddles. Reach the goal before the clock runs out.|\n"
			. "Conkers with Connor|Connor Dimaio|The playground classic. Tap to swing. Last conker standing wins.|\n"
			. "Jacob’s Ladder|Jacob Pearce|Every keepy-up is a rung. Drop it and you’re back to the bottom.|\n"
			. "Ryan Air|Ryan Wilson|Keep it in the air through the gaps. Cheap flights, no legroom.|\n"
			. "Tyler Swift|Tyler Blake|Tap as fast as you can to race up the wing.|\n"
			. "Luke-y Dip|Luke Rawson|Everyone checked in goes in the drum. One name pulled live on the big screen.|\n"
			. "Keaton It Up|Keaton August|Thirty seconds of keepy-ups. Every count live on the big screen.|\n"
			. "Toby or Not Toby|Toby Moore|Higher or lower with club stats. More goals than Toby this season?|\n"
			. "Call It, Callum|Callum Chettle|Next corner, next card, next goal. Call it before it happens.|\n"
			. "Ce’an Is Believing|Ce’an Winn|Two freeze-frames from the first half. Spot what changed.|\n"
			. "Jack in the Box|Jack Duffy|Whack-a-mole with the opposition popping up.|\n"
			. 'Jamie’s 30-Second Challenge|Jamie Walker|A new challenge every week. Thirty seconds to do it.|',
	);
}

/** The games, each with a stable key. */
function ew_hts_vote_games( $content ) {
	$lines = ew_hts_lines( $content );
	if ( ! $lines ) {
		$d     = ew_hts_vote_defaults();
		$lines = ew_hts_lines( $d['lines'] );
	}
	$out = array();
	foreach ( $lines as $line ) {
		$c = array_pad( array_map( 'trim', explode( '|', $line ) ), 4, '' );
		if ( '' === $c[0] ) {
			continue;
		}
		$key = ew_hts_key( $c[0] );
		if ( '' === $key || isset( $out[ $key ] ) ) {
			continue;
		}
		$out[ $key ] = array( 'title' => $c[0], 'player' => $c[1], 'text' => $c[2], 'img' => $c[3] );
	}
	return array_slice( $out, 0, 40, true );
}

function ew_hts_key( $s ) {
	$s = function_exists( 'remove_accents' ) ? remove_accents( (string) $s ) : (string) $s;
	$s = strtolower( preg_replace( '/[^A-Za-z0-9]+/', '-', $s ) );
	return substr( trim( $s, '-' ), 0, 60 );
}

function ew_hts_round_key( $s ) {
	$k = ew_hts_key( $s );
	return '' !== $k ? $k : 'next';
}

/** Counts for one round. */
function ew_hts_counts( $round ) {
	$all = (array) get_option( 'ew_hts_votes', array() );
	return array_map( 'intval', (array) ( $all[ ew_hts_round_key( $round ) ] ?? array() ) );
}

function ew_hts_vote_sc( $atts, $content = '' ) {
	$a      = shortcode_atts( ew_hts_vote_defaults(), (array) $atts, 'eastwood_hts_vote' );
	$games  = ew_hts_vote_games( $content );
	$counts = ew_hts_counts( $a['round'] );
	$max    = $counts ? max( 1, max( $counts ) ) : 1;
	$open   = 'no' !== strtolower( trim( (string) $a['open'] ) );
	$post   = function_exists( 'get_post' ) ? get_post() : null;

	$cfg = array(
		'rest'    => esc_url_raw( rest_url( 'eastwood/v1/halftime/vote' ) ),
		'counts'  => esc_url_raw( rest_url( 'eastwood/v1/halftime/votes' ) ),
		'round'   => ew_hts_round_key( $a['round'] ),
		'page'    => (int) ( $post ? $post->ID : 0 ),
		'open'    => $open,
		'vote'    => $a['vote'],
		'voted'   => $a['voted'],
		'one'     => $a['one'],
		'many'    => $a['many'],
		'thanks'  => $a['thanks'],
		'already' => $a['already'],
		'busy'    => $a['busy'],
		'failed'  => $a['failed'],
	);

	$id = ew_hts_key( $a['anchor'] );
	$h  = '<div class="ewhp ewhp-vote"' . ( '' !== $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . ' data-ewhp-vote>';
	$h .= '<div class="ewhp-vGrid">';
	foreach ( $games as $key => $g ) {
		$n  = (int) ( $counts[ $key ] ?? 0 );
		$h .= '<article class="ewhp-vCard" data-game="' . esc_attr( $key ) . '">';
		$h .= ew_hts_picture( $g['img'], $g['title'], 'ewhp-vImg', $g['title'] );
		$h .= '<div class="ewhp-vBody">';
		if ( '' !== $g['player'] ) {
			$h .= '<p class="ewhp-vWho">' . esc_html( $g['player'] ) . '</p>';
		}
		$h .= '<h3 class="ewhp-vTitle">' . esc_html( $g['title'] ) . '</h3>';
		$h .= '<p class="ewhp-vText">' . esc_html( $g['text'] ) . '</p>';
		$h .= '<div class="ewhp-vFoot">';
		$h .= '<span class="ewhp-vBar" aria-hidden="true"><span style="width:' . (int) round( 100 * $n / $max ) . '%"></span></span>';
		$h .= '<span class="ewhp-vN" data-n>' . esc_html( $n . ' ' . ( 1 === $n ? $a['one'] : $a['many'] ) ) . '</span>';
		if ( $open ) {
			$h .= '<button type="button" class="ewhp-vBtn" data-vote>' . esc_html( $a['vote'] ) . '</button>';
		}
		$h .= '</div></div></article>';
	}
	$h .= '</div>';
	$h .= '<p class="ewhp-vMsg" role="status" aria-live="polite">' . ( $open ? '' : esc_html( $a['closed'] ) ) . '</p>';
	$h .= '<script type="application/json" class="ewhp-vCfg">' . wp_json_encode( $cfg ) . '</script>';
	return $h . '</div>';
}
add_shortcode( 'eastwood_hts_vote', 'ew_hts_vote_sc' );

/** The games named by the vote block on a page, for checking a vote against. */
function ew_hts_page_games( $page_id ) {
	$post = $page_id ? get_post( (int) $page_id ) : null;
	if ( ! $post ) {
		return ew_hts_vote_games( '' );
	}
	$hay = (string) $post->post_content;
	$raw = get_post_meta( $post->ID, '_elementor_data', true );
	if ( is_string( $raw ) && '' !== $raw ) {
		$doc = json_decode( $raw, true );
		$found = null;
		if ( is_array( $doc ) && function_exists( 'ew_blocks_walk' ) && function_exists( 'ew_blocks_parse' ) ) {
			ew_blocks_walk( $doc, function ( $node ) use ( &$found ) {
				$sc = (string) ( $node['settings']['shortcode'] ?? '' );
				if ( null === $found && false !== strpos( $sc, '[eastwood_hts_vote' ) ) {
					$found = ew_blocks_parse( $sc );
				}
			} );
		}
		if ( $found ) {
			return ew_hts_vote_games( (string) ( $found['lines'] ?? '' ) );
		}
	}
	if ( preg_match( '/\[eastwood_hts_vote[^\]]*\](.*?)\[\/eastwood_hts_vote\]/s', $hay, $mm ) ) {
		return ew_hts_vote_games( $mm[1] );
	}
	return ew_hts_vote_games( '' );
}

/** Who is voting, as far as the server can tell, hashed. */
function ew_hts_voter( $round ) {
	$ip = '';
	foreach ( array( 'HTTP_CF_CONNECTING_IP', 'REMOTE_ADDR' ) as $k ) {
		if ( ! empty( $_SERVER[ $k ] ) ) {
			$ip = (string) $_SERVER[ $k ];
			break;
		}
	}
	return 'ew_htsv_' . md5( $ip . '|' . $round . '|' . ( defined( 'AUTH_SALT' ) ? AUTH_SALT : '' ) );
}

/**
 * A few votes per address per round, every six hours. A family on the
 * ground wifi share one address, so this is a brake on a script, not on
 * a household.
 */
const EW_HTS_PER_IP = 6;

add_action( 'rest_api_init', function () {
	register_rest_route( 'eastwood/v1', '/halftime/votes', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) {
			$round = ew_hts_round_key( (string) $req->get_param( 'round' ) );
			$r     = new WP_REST_Response( array( 'round' => $round, 'counts' => (object) ew_hts_counts( $round ) ) );
			$r->header( 'Cache-Control', 'no-store, max-age=0' );
			return $r;
		},
	) );

	register_rest_route( 'eastwood/v1', '/halftime/vote', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) {
			$round = ew_hts_round_key( (string) $req->get_param( 'round' ) );
			$game  = ew_hts_key( (string) $req->get_param( 'game' ) );
			$games = ew_hts_page_games( (int) $req->get_param( 'page' ) );
			if ( '' === $game || ! isset( $games[ $game ] ) ) {
				return new WP_Error( 'ew_hts_game', 'Not a game on the vote.', array( 'status' => 400 ) );
			}

			$who  = ew_hts_voter( $round );
			$seen = (int) get_transient( $who );
			if ( $seen >= EW_HTS_PER_IP ) {
				return new WP_Error( 'ew_hts_busy', 'Too many votes from here.', array( 'status' => 429 ) );
			}
			set_transient( $who, $seen + 1, 6 * HOUR_IN_SECONDS );

			$all = (array) get_option( 'ew_hts_votes', array() );
			$row = (array) ( $all[ $round ] ?? array() );
			$row[ $game ] = (int) ( $row[ $game ] ?? 0 ) + 1;
			unset( $all[ $round ] );
			$all[ $round ] = $row;
			// Keep the last twenty rounds; an old show needs no counts.
			$all = array_slice( $all, -20, null, true );
			update_option( 'ew_hts_votes', $all, false );

			$r = new WP_REST_Response( array( 'ok' => true, 'round' => $round, 'game' => $game, 'counts' => (object) array_map( 'intval', $row ) ) );
			$r->header( 'Cache-Control', 'no-store, max-age=0' );
			return $r;
		},
	) );
} );

/* ------------------------------------------------------------------
 * 6. [eastwood_hts_play]
 *    01|Join the queue|Before the game, from home or on the way.
 * ------------------------------------------------------------------ */

function ew_hts_play_defaults() {
	return array(
		'app'          => 'Team EastwoodFC',
		'app_icon'     => '',
		'sponsor_by'   => 'Queue sponsored by',
		'sponsor_logo' => '',
		'sponsor_name' => '',
		// Phone one: before the game.
		'p1_kicker'    => 'Before the game',
		'p1_title'     => 'Half time queue',
		'p1_game'      => 'Curby with Curtis',
		'p1_img'       => '',
		'p1_meta'      => 'Sat 14 Nov · v Hucknall Town',
		'p1_btn'       => 'Join the queue',
		'p1_note'      => 'Join from home before the game. Your place is saved until half time.',
		// Phone two: your place.
		'p2_label'     => 'Your place',
		'p2_place'     => '#14',
		'p2_up'        => 'Up 6 places today',
		'p2_jump'      => 'Jump the queue',
		'p2_points'    => '[points]',
		'p2_next'      => 'next jump at [points]',
		'p2_fill'      => '62',
		'p2_earn'      => 'Food at the ground|+[pts]|Drinks at the bar|+[pts]|Club shop|+[pts]',
		'p2_code'      => 'Show this code at the till and your family’s spend counts',
		// Phone three: the match centre.
		'p3_match'     => 'EFC v HUC',
		'p3_state'     => 'HT',
		'p3_tabs'      => 'Live|Line-ups|Queue',
		'p3_now'       => 'Curby with Curtis · now playing',
		'p3_queue'     => '[Fan name]|[Fan name]|[Your child]|[Fan name]|[Fan name]',
		'p3_you_at'    => '3',
		'p3_you'       => 'You',
		'p3_note'      => 'You’re up next. Head to the tunnel.',
		// Under the phones.
		'get_app'      => 'Get the Team EastwoodFC app',
		'lines'        => "01|Join the queue|Before the game, from home or on the way.\n"
			. "02|Jump places|Food, drink and the club shop earn points. Points move you up.\n"
			. '03|Watch the queue live|It sits in the match centre next to the score, so you never miss your go.',
	) + ew_hts_store_defaults();
}

function ew_hts_phone_top( $a ) {
	$icon = '' !== trim( (string) $a['app_icon'] ) ? $a['app_icon'] : ew_hts_crest( EW_TEAM );
	return '<div class="ewhp-phTop"><img src="' . esc_url( $icon ) . '" alt="" loading="lazy"><span>' . esc_html( $a['app'] ) . '</span></div>';
}

function ew_hts_phone_foot( $a ) {
	if ( '' === trim( (string) $a['sponsor_logo'] ) ) {
		return '';
	}
	return '<div class="ewhp-phSpon"><span>' . esc_html( $a['sponsor_by'] ) . '</span><img src="'
		. esc_url( $a['sponsor_logo'] ) . '" alt="' . esc_attr( $a['sponsor_name'] ) . '" loading="lazy"></div>';
}

function ew_hts_play_sc( $atts, $content = '' ) {
	$d     = ew_hts_play_defaults();
	$a     = shortcode_atts( $d, (array) $atts, 'eastwood_hts_play' );
	$steps = ew_hts_lines( $content );
	if ( ! $steps ) {
		$steps = ew_hts_lines( $d['lines'] );
	}
	$caps = array();
	foreach ( $steps as $s ) {
		$caps[] = array_pad( array_map( 'trim', explode( '|', $s ) ), 3, '' );
	}

	// Phone one.
	$p1  = '<div class="ewhp-phone">' . ew_hts_phone_top( $a );
	$p1 .= '<div class="ewhp-phRed"><p>' . esc_html( $a['p1_kicker'] ) . '</p><b>' . esc_html( $a['p1_title'] ) . '</b></div>';
	$p1 .= '<div class="ewhp-phPad"><div class="ewhp-phGame">' . ew_hts_picture( $a['p1_img'], $a['p1_game'], 'ewhp-phImg', $a['p1_game'] );
	$p1 .= '<div class="ewhp-phGameBody"><b>' . esc_html( $a['p1_game'] ) . '</b><p>' . esc_html( $a['p1_meta'] ) . '</p>';
	$p1 .= '<span class="ewhp-phBtn">' . esc_html( $a['p1_btn'] ) . '</span></div></div>';
	$p1 .= '<p class="ewhp-phNote">' . esc_html( $a['p1_note'] ) . '</p></div>';
	$p1 .= ew_hts_phone_foot( $a ) . '</div>';

	// Phone two.
	$earn = ew_hts_pipes( $a['p2_earn'] );
	$p2   = '<div class="ewhp-phone">' . ew_hts_phone_top( $a );
	$p2  .= '<div class="ewhp-phPlace"><p>' . esc_html( $a['p2_label'] ) . '</p><b>' . esc_html( $a['p2_place'] ) . '</b><i>' . esc_html( $a['p2_up'] ) . '</i></div>';
	$p2  .= '<div class="ewhp-phPad is-tight"><p class="ewhp-phK">' . esc_html( $a['p2_jump'] ) . '</p>';
	$p2  .= '<div class="ewhp-phBox"><div class="ewhp-phPts"><span>' . esc_html( $a['p2_points'] ) . '</span><span>' . esc_html( $a['p2_next'] ) . '</span></div>'
		. '<div class="ewhp-phBar"><span style="width:' . max( 0, min( 100, (int) $a['p2_fill'] ) ) . '%"></span></div></div>';
	if ( $earn ) {
		$p2 .= '<div class="ewhp-phBox ewhp-phEarn">';
		for ( $i = 0; $i < count( $earn ); $i += 2 ) {
			$p2 .= '<div><span>' . esc_html( $earn[ $i ] ) . '</span><b>' . esc_html( $earn[ $i + 1 ] ?? '' ) . '</b></div>';
		}
		$p2 .= '</div>';
	}
	$p2 .= '<div class="ewhp-phCode"><span aria-hidden="true"></span><p>' . esc_html( $a['p2_code'] ) . '</p></div>';
	$p2 .= '</div>' . ew_hts_phone_foot( $a ) . '</div>';

	// Phone three.
	$tabs = ew_hts_pipes( $a['p3_tabs'] );
	$q    = ew_hts_pipes( $a['p3_queue'] );
	$you  = (int) $a['p3_you_at'];
	$p3   = '<div class="ewhp-phone">' . ew_hts_phone_top( $a );
	$p3  .= '<div class="ewhp-phScore"><b>' . esc_html( $a['p3_match'] ) . '</b><i>' . esc_html( $a['p3_state'] ) . '</i></div>';
	$p3  .= '<div class="ewhp-phTabs">';
	foreach ( $tabs as $i => $t ) {
		$p3 .= '<span' . ( $i === count( $tabs ) - 1 ? ' class="is-on"' : '' ) . '>' . esc_html( $t ) . '</span>';
	}
	$p3 .= '</div><div class="ewhp-phPad is-q"><p class="ewhp-phK">' . esc_html( $a['p3_now'] ) . '</p>';
	foreach ( $q as $i => $name ) {
		$me  = $i + 1 === $you;
		$p3 .= '<div class="ewhp-phQ' . ( $me ? ' is-you' : '' ) . '"><b>' . ( $i + 1 ) . '</b><span>' . esc_html( $name ) . '</span>'
			. ( $me ? '<b>' . esc_html( $a['p3_you'] ) . '</b>' : '' ) . '</div>';
	}
	$p3 .= '<p class="ewhp-phUp">' . esc_html( $a['p3_note'] ) . '</p></div>';
	$p3 .= ew_hts_phone_foot( $a ) . '</div>';

	$h  = '<div class="ewhp ewhp-play"><div class="ewhp-phones">';
	foreach ( array( $p1, $p2, $p3 ) as $i => $phone ) {
		$c  = $caps[ $i ] ?? array( '', '', '' );
		$h .= '<div class="ewhp-phCol">' . $phone;
		if ( '' !== $c[1] || '' !== $c[2] ) {
			$h .= '<div class="ewhp-phCap"><p class="ewhp-phNum">' . esc_html( $c[0] ) . '</p><p class="ewhp-phCapT">'
				. esc_html( $c[1] ) . '</p><p class="ewhp-phCapX">' . esc_html( $c[2] ) . '</p></div>';
		}
		$h .= '</div>';
	}
	$h .= '</div>';
	$stores = ew_hts_stores( $a, 'solid' );
	if ( '' !== $stores || '' !== $a['get_app'] ) {
		$h .= '<div class="ewhp-playFoot"><p>' . esc_html( $a['get_app'] ) . '</p>' . $stores . '</div>';
	}
	return $h . '</div>';
}
add_shortcode( 'eastwood_hts_play', 'ew_hts_play_sc' );

/* ------------------------------------------------------------------
 * Styles and script, only on a page that carries one of these blocks.
 * ------------------------------------------------------------------ */

function ew_hts_on_page( $post ) {
	if ( ! $post || ! function_exists( 'ew_post_contains' ) ) {
		return false;
	}
	foreach ( EW_HTS_TAGS as $tag ) {
		if ( ew_post_contains( $post, '[' . $tag ) || ew_post_contains( $post, $tag ) ) {
			return true;
		}
	}
	return false;
}

function ew_hts_css() {
	return '
.e-con.ewhp-sec{background:#f3f3f3;padding:0 24px 80px!important;display:block!important}
.e-con.ewhp-sec.ewhp-first{padding-top:96px!important}
.ewhp-sec>.elementor-widget,.ewhp-sec>.e-con-inner>.elementor-widget{max-width:1248px!important;width:100%!important;margin-left:auto!important;margin-right:auto!important}
.ewhp-sec h2.elementor-heading-title.elementor-heading-title{font-family:Anton,Impact,sans-serif!important;font-weight:400!important;font-size:34px!important;line-height:1.5!important;letter-spacing:0!important;text-transform:uppercase;color:#101010!important;margin:0 0 10px!important;padding:0!important;background:none!important;width:auto!important;max-width:none!important;position:static!important;left:auto!important;transform:none!important}
.ewhp-sec .elementor-widget-heading:has(+ .elementor-widget-shortcode) h2.elementor-heading-title.elementor-heading-title{margin-bottom:28px!important}
.ewhp-sec .elementor-widget-text-editor p{font-family:"Instrument Sans",system-ui,sans-serif;font-size:18px;line-height:1.45;color:#333;margin:0 0 28px;max-width:none}
.ewhp-sec .elementor-widget-text-editor p.ewhp-note{margin:24px 0 0;font-size:16px;line-height:normal;color:#444}
.ewhp-sec .elementor-widget-text-editor p.ewhp-note a{color:#CC0000;font-weight:700;text-decoration:underline}
.ewhp{font-family:"Instrument Sans",system-ui,sans-serif;color:#101010;line-height:normal;-webkit-font-smoothing:antialiased}
.ewhp *,.ewhp *::before,.ewhp *::after{box-sizing:border-box}
.ewhp p,.ewhp h3{margin:0}
.ewhp img{max-width:100%}
.ewhp-empty{padding:24px;background:#fff;border-radius:8px;color:#5d5d66;font-size:16px;text-align:center}
.ewhp-tile{display:flex!important;align-items:center;justify-content:center;background:#17171a;color:#fff}
.ewhp-tile span{font-family:Anton,Impact,sans-serif;font-size:64px;line-height:1;letter-spacing:.04em;opacity:.9}

/* the fixture panel, as on /matches */
.ewhp-fx{background:#fff;border-radius:8px;overflow:hidden}
.ewhp-fxTop{display:flex;align-items:center;gap:16px;min-height:81px;padding:16px;background:#fafafa;border-bottom:1px solid #efefef}
.ewhp-fxBadge{display:flex;align-items:center;justify-content:center;width:48px;height:48px;flex:none;margin-right:4px}
.ewhp .ewhp-fxBadge img{max-width:100%;max-height:100%;width:auto;height:auto;display:block}
.ewhp-fxTop b{font-weight:400;font-size:20px;text-transform:uppercase;color:#111}
.ewhp-fxTop i{font-style:normal;font-size:20px;font-weight:700;color:#101010;margin-left:-4px}
.ewhp-fxComp{margin-left:auto;font-size:14px;letter-spacing:.025em;text-transform:uppercase;color:#9a9aa3;text-align:right;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ewhp-fxBody{padding:26px 22px 30px;text-align:center}
.ewhp-fxWhere{margin:0 0 22px!important;font-size:10px;text-transform:uppercase;color:#9a9aa3}
.ewhp-fxWhere em{font-style:normal;font-weight:700;color:#101010;margin-right:9px}
.ewhp-tie{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:18px}
.ewhp-tieName{font-family:Anton,Impact,sans-serif;font-weight:400;font-size:40px;line-height:1.02;letter-spacing:.01em;text-transform:uppercase;color:#111;text-align:center}
.ewhp-tieMid{display:flex;align-items:center;gap:22px}
.ewhp .ewhp-tieMid img{width:80px;height:80px;object-fit:contain;flex:none}
.ewhp-tieV{font-size:48px;line-height:1;font-weight:700;color:#101010}
.ewhp-fxFoot{display:flex;align-items:center;justify-content:center;flex-wrap:wrap;gap:10px;min-height:49px;padding:10px 16px;background:#fafafa;border-top:1px solid rgba(200,200,200,.3)}
.ewhp-fxFoot span{font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#101010}
.ewhp-fxFoot b{font-size:13px;font-weight:700;letter-spacing:.02em;color:#101010}
.ewhp-fxFoot i{font-style:normal;font-size:12px;color:#8a8a8a}
.ewhp-fxFoot i::before{content:"\2014";margin-right:7px;color:#c9c9c9}
.ewhp-month{margin:30px 0 14px!important;text-align:center;font-size:18px;font-weight:700;color:#212121}
.ewhp-coming>.ewhp-month:first-child{margin-top:2px!important}
.ewhp-rows{display:flex;flex-direction:column;gap:14px}

/* the two feature cards */
.ewhp-feat{display:flex;gap:24px;margin-top:24px}
.ewhp-card{flex:1;min-width:0;background:#0b0b0b;color:#fff;border-radius:8px;overflow:hidden;display:flex;flex-direction:column}
.ewhp-cardMedia{position:relative;background:#17171a}
.ewhp .ewhp-cardImg{width:100%;height:340px;object-fit:cover;object-position:50% 40%;display:block}
.ewhp .ewhp-cardMedia.is-contain img.ewhp-cardImg{object-fit:contain}
.ewhp-cardMedia .ewhp-tile span{font-size:120px;opacity:.18}
.ewhp-tag{position:absolute;left:20px;top:20px;padding:7px 12px;border-radius:4px;background:#CC0000;color:#fff;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;line-height:1.3}
.ewhp-cardBody{padding:28px 30px 32px;display:flex;flex-direction:column;gap:16px;flex:1}
.ewhp-cardTitle{font-family:Anton,Impact,sans-serif;font-weight:400;font-size:48px;line-height:1;text-transform:uppercase;color:#fff;letter-spacing:0}
.ewhp-who{display:flex;align-items:center;gap:16px;padding:16px;border-radius:8px;background:#17171a}
.ewhp .ewhp-whoPic{width:72px;height:72px;flex:none;display:block;object-fit:cover}
.ewhp-whoPic.is-round{border-radius:50%}
.ewhp-whoPic.is-blank{background:#2a2a2e}
.ewhp-whoPic.is-icon{object-fit:contain}
.ewhp-whoK{font-size:12px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#ff4a3d}
.ewhp-whoN{margin-top:4px!important;font-family:Anton,Impact,sans-serif;font-size:30px;line-height:1;text-transform:uppercase;color:#fff}
.ewhp-whoL{margin-top:4px!important;font-size:15px;color:#9a9aa3}
.ewhp-cardText{font-size:18px;line-height:1.5;color:#d6d6db}
.ewhp a.ewhp-btn{margin-top:auto;display:flex;align-items:center;justify-content:center;height:56px;border-radius:8px;background:#CC0000;color:#fff;text-decoration:none;font-family:Anton,Impact,sans-serif;font-size:20px;text-transform:uppercase;letter-spacing:0}
.ewhp a.ewhp-btn:hover{background:#b00000;color:#fff}
.ewhp-stores{margin-top:auto;display:flex;flex-wrap:wrap;gap:10px}
.ewhp a.ewhp-store{display:inline-flex;align-items:center;gap:9px;text-decoration:none;border-radius:8px}
.ewhp-store span{display:flex;flex-direction:column;line-height:1.1}
.ewhp-store small{font-size:10px;opacity:.8}
.ewhp-store b{font-size:16px;font-weight:600}
.ewhp a.ewhp-store.is-line{height:48px;padding:0 16px;border:1px solid rgba(255,255,255,.35);color:#fff}
.ewhp a.ewhp-store.is-line:hover{border-color:#fff;color:#fff}
.ewhp a.ewhp-store.is-solid{padding:8px 16px;background:#fff;color:#101010}
.ewhp-store.is-solid span{line-height:normal}
.ewhp-store.is-solid small{font-size:11px;opacity:1}
.ewhp-store.is-solid b{font-size:17px;font-weight:700}

/* how to take part */
.ewhp-steps{background:#fff;border-radius:8px;padding:40px 44px}
.ewhp-stHead{display:flex;gap:20px;margin-bottom:16px}
.ewhp-stHead span{width:190px;flex:none}
.ewhp-stHead p{flex:1;font-family:Anton,Impact,sans-serif;font-size:26px;line-height:1.5;text-transform:uppercase;color:#101010}
.ewhp-stRow{position:relative;display:flex;align-items:stretch;gap:20px;padding-bottom:20px}
.ewhp-stRow.is-last{padding-bottom:0}
.ewhp-stLine{position:absolute;left:189px;top:36px;bottom:-4px;width:2px;background:#e2e2e2}
.ewhp-stTime{width:150px;flex:none;padding-top:12px}
.ewhp-stTime b{display:block;font-family:Anton,Impact,sans-serif;font-weight:400;font-size:30px;line-height:1;color:#101010}
.ewhp-stTime span{display:block;margin-top:4px;font-size:13px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#9a9aa3}
.ewhp-stDot{position:relative;width:20px;height:20px;flex:none;margin-top:18px;border-radius:50%;background:#CC0000;box-shadow:0 0 0 6px #fff}
.ewhp-stBox{flex:1;min-width:0;padding:18px 22px;border-radius:8px;background:#f6f6f7;font-size:18px;line-height:1.45;color:#222}
.ewhp-stBox em{display:none}

/* past shows */
.ewhp-pastCard{background:#fff;border-radius:8px;overflow:hidden}
.ewhp-pastTop{height:81px;padding:16px 24px;background:#f7f7f7;border-bottom:1px solid #e6e6e6;display:flex;align-items:center;gap:18px}
.ewhp .ewhp-pastBadge{height:48px;width:auto;display:block}
.ewhp-pastWhen{font-size:22px;color:#101010}
.ewhp-pastWhen strong{font-weight:700}
.ewhp-pastComp{margin-left:auto!important;font-size:13px;color:#8a8a90;text-transform:uppercase;letter-spacing:.02em;text-align:right;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ewhp-pastWhere{margin-top:22px!important;text-align:center;font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#8a8a90}
.ewhp-pastWhere strong{color:#101010}
.ewhp-pastTie{display:flex;align-items:center;justify-content:center;gap:22px;padding:22px 24px 30px}
.ewhp-pastName{flex:1;font-family:Anton,Impact,sans-serif;font-size:36px;line-height:1.5;text-transform:uppercase;color:#101010;min-width:0}
.ewhp-pastName.is-home{text-align:right}
.ewhp-pastMid{display:flex;align-items:center;gap:22px}
.ewhp .ewhp-pastMid img{width:80px;height:80px;object-fit:contain;flex:none}
.ewhp-pastMid b{font-family:Anton,Impact,sans-serif;font-weight:400;font-size:48px;line-height:1.5;white-space:nowrap}
.ewhp-photos{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:6px;padding:0 6px 6px}
.ewhp-photos.is-alone{padding-top:6px}
.ewhp .ewhp-photos img{width:100%;height:300px;object-fit:cover;object-position:50% 40%;display:block}
.ewhp .ewhp-photos.is-n1 img{grid-column:span 4;height:420px}
.ewhp .ewhp-photos.is-n2 img{grid-column:span 2}
.ewhp .ewhp-photos.is-n3 img:first-child,.ewhp .ewhp-photos.is-n5 img:first-child,.ewhp .ewhp-photos.is-n6 img:first-child{grid-column:span 2}
.ewhp .ewhp-photos.is-n5 img:nth-child(n+4){grid-column:span 2}
.ewhp .ewhp-photos.is-n6 img:last-child{grid-column:span 2}

/* the vote */
.ewhp-vGrid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}
.ewhp-vCard{background:#fff;border-radius:8px;overflow:hidden;display:flex;flex-direction:column}
.ewhp .ewhp-vImg{width:100%;height:252px;object-fit:cover;display:block;background:#17171a;flex:none}
.ewhp-vBody{padding:16px 18px 18px;display:flex;flex-direction:column;gap:6px;flex:1;min-width:0}
.ewhp-vWho{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#CC0000}
.ewhp-vTitle{font-family:Anton,Impact,sans-serif;font-weight:400;font-size:24px;line-height:1.05;text-transform:uppercase;color:#101010;letter-spacing:0}
.ewhp-vText{font-size:13px;line-height:1.4;color:#5d5d66;flex:1}
.ewhp-vFoot{display:flex;align-items:center;gap:10px;margin-top:8px}
.ewhp-vBar{flex:1;height:8px;background:#ececee;border-radius:4px;overflow:hidden;min-width:24px}
.ewhp-vBar span{display:block;height:100%;background:#CC0000;border-radius:4px;transition:width .4s}
.ewhp-vN{font-size:12px;color:#8a8a90;white-space:nowrap}
.ewhp .ewhp-vBtn{background:#CC0000;color:#fff;border:0;border-radius:6px;padding:7px 14px;font:700 13px/normal "Instrument Sans",system-ui,sans-serif;cursor:pointer;white-space:nowrap}
.ewhp .ewhp-vBtn:hover{background:#b00000}
.ewhp .ewhp-vBtn:disabled{background:#d9d9dd;color:#8a8a90;cursor:default}
.ewhp .ewhp-vCard.is-mine .ewhp-vBtn{background:#101010;color:#fff}
.ewhp-vMsg{margin-top:16px!important;font-size:15px;font-weight:700;color:#101010}
.ewhp-vMsg:empty{display:none}

/* play at half time */
.ewhp-play{background:#0b0b0b;color:#fff;border-radius:8px;padding:48px 56px;display:flex;flex-direction:column;gap:32px}
.ewhp-phones{display:flex;justify-content:space-between;gap:24px}
.ewhp-phCol{display:flex;flex-direction:column;gap:32px;width:260px}
.ewhp-phone{width:260px;height:540px;flex:none;border:9px solid #2a2a2e;border-radius:34px;background:#f3f3f3;overflow:hidden;display:flex;flex-direction:column;color:#101010}
.ewhp-phTop{background:#101010;color:#fff;padding:14px 14px 10px;display:flex;align-items:center;gap:8px}
.ewhp .ewhp-phTop img{width:22px;height:22px;object-fit:contain}
.ewhp-phTop span{font-family:Anton,Impact,sans-serif;font-size:14px;text-transform:uppercase}
.ewhp-phRed{background:#CC0000;color:#fff;padding:12px 14px}
.ewhp-phRed p{font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase}
.ewhp-phRed b{display:block;margin-top:2px;font-family:Anton,Impact,sans-serif;font-weight:400;font-size:20px;text-transform:uppercase}
.ewhp-phPad{padding:12px;display:flex;flex-direction:column;gap:10px}
.ewhp-phPad.is-tight{gap:8px}
.ewhp-phPad.is-q{gap:6px}
.ewhp-phGame{background:#fff;border-radius:10px;overflow:hidden}
.ewhp .ewhp-phImg{width:100%;height:140px;object-fit:cover;display:block;background:#17171a}
.ewhp-phImg.ewhp-tile span{font-size:44px;opacity:.5}
.ewhp-phGameBody{padding:10px 12px 12px}
.ewhp-phGameBody b{display:block;font-family:Anton,Impact,sans-serif;font-weight:400;font-size:18px;text-transform:uppercase}
.ewhp-phGameBody p{margin:2px 0 10px!important;font-size:11px;color:#5d5d66}
.ewhp-phBtn{display:block;text-align:center;background:#CC0000;color:#fff;border-radius:6px;padding:9px;font-size:12px;font-weight:700;text-transform:uppercase}
.ewhp-phNote{font-size:11px;line-height:1.4;color:#5d5d66}
.ewhp-phPlace{background:#101010;color:#fff;padding:14px;border-top:1px solid #2a2a2e}
.ewhp-phPlace p{font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#9a9aa3}
.ewhp-phPlace b{display:block;font-family:Anton,Impact,sans-serif;font-weight:400;font-size:56px;line-height:1}
.ewhp-phPlace i{display:block;margin-top:4px;font-style:normal;font-size:11px;color:#ff6a5e;font-weight:700}
.ewhp-phK{font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#5d5d66}
.ewhp-phPad.is-q .ewhp-phK{margin-bottom:4px!important}
.ewhp-phBox{background:#fff;border-radius:8px;padding:10px 12px}
.ewhp-phPts{display:flex;justify-content:space-between;font-size:12px;font-weight:700}
.ewhp-phPts span+span{color:#8a8a90}
.ewhp-phBar{height:8px;background:#ececee;border-radius:4px;margin-top:8px}
.ewhp-phBar span{display:block;height:8px;background:#CC0000;border-radius:4px}
.ewhp-phEarn{display:flex;flex-direction:column;gap:7px;font-size:12px}
.ewhp-phEarn div{display:flex;justify-content:space-between}
.ewhp-phCode{background:#101010;color:#fff;border-radius:8px;padding:10px 12px;display:flex;align-items:center;gap:10px}
.ewhp-phCode span{width:34px;height:34px;flex:none;background:#fff;border-radius:4px}
.ewhp-phCode p{font-size:11px;line-height:1.35}
.ewhp-phScore{background:#101010;color:#fff;padding:10px 14px;display:flex;align-items:center;justify-content:space-between;border-top:1px solid #2a2a2e}
.ewhp-phScore b{font-family:Anton,Impact,sans-serif;font-weight:400;font-size:16px}
.ewhp-phScore i{font-style:normal;font-size:10px;font-weight:700;color:#ff6a5e;text-transform:uppercase}
.ewhp-phTabs{display:flex;background:#fff;border-bottom:1px solid #e6e6e6}
.ewhp-phTabs span{flex:1;text-align:center;padding:10px 0;font-size:11px;font-weight:700;text-transform:uppercase;color:#8a8a90}
.ewhp-phTabs span.is-on{color:#CC0000;border-bottom:2px solid #CC0000}
.ewhp-phQ{display:flex;align-items:center;gap:10px;padding:8px 12px;background:#fff;border-radius:6px;font-size:12px}
.ewhp-phQ>b:first-child{width:18px}
.ewhp-phQ span{flex:1}
.ewhp-phQ.is-you{background:#CC0000;color:#fff}
.ewhp-phUp{margin-top:6px!important;font-size:11px;line-height:1.4;color:#101010;font-weight:700}
.ewhp-phSpon{margin-top:auto;background:#fff;border-top:1px solid #e6e6e6;padding:10px 14px;display:flex;align-items:center;justify-content:space-between;gap:10px}
.ewhp-phSpon span{font-size:9px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#8a8a90}
.ewhp .ewhp-phSpon img{max-height:22px;max-width:96px;width:auto;object-fit:contain}
.ewhp-phCap{display:flex;flex-direction:column;gap:6px}
.ewhp-phNum{font-family:Anton,Impact,sans-serif;font-size:15px;color:#ff6a5e}
.ewhp-phCapT{font-family:Anton,Impact,sans-serif;font-size:24px;line-height:1.05;text-transform:uppercase;color:#fff}
.ewhp-phCapX{font-size:14px;line-height:1.45;color:#b9b9c0}
.ewhp-playFoot{display:flex;align-items:center;gap:16px;border-top:1px solid rgba(255,255,255,.14);padding-top:24px}
.ewhp-playFoot>p{flex:1;font-size:13px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#9a9aa3}

@media (max-width:1180px){
 .ewhp-vGrid{grid-template-columns:repeat(3,minmax(0,1fr))}
 .ewhp-play{padding:40px 32px}
 .ewhp-phones{flex-wrap:wrap;justify-content:center;gap:40px}
}
@media (max-width:900px){
 .ewhp-feat{flex-direction:column}
 .ewhp-vGrid{grid-template-columns:repeat(2,minmax(0,1fr))}
 .ewhp-tieName{font-size:30px}
 .ewhp-fxComp{display:none}
}
@media (max-width:767px){
 .e-con.ewhp-sec{padding:0 16px 56px!important}
 .e-con.ewhp-sec.ewhp-first{padding-top:56px!important}
 .ewhp-sec h2.elementor-heading-title.elementor-heading-title{font-size:30px!important}
 .ewhp-sec .elementor-widget-text-editor p{font-size:16px;margin-bottom:22px}
 .ewhp-fxTop{padding:12px 16px;min-height:64px}
 .ewhp-fxBody{padding:18px 16px 20px}
 .ewhp-tie{grid-template-columns:1fr;gap:10px}
 .ewhp-tieName{font-size:24px}
 .ewhp-tieMid{justify-content:center}
 .ewhp .ewhp-tieMid img{width:56px;height:56px}
 .ewhp-tieV{font-size:32px}
 .ewhp-fxFoot{gap:6px 10px}
 .ewhp .ewhp-cardImg{height:220px}
 .ewhp-cardMedia .ewhp-tile span{font-size:84px}
 .ewhp-cardBody{padding:22px 20px 24px}
 .ewhp-cardTitle{font-size:36px}
 .ewhp .ewhp-whoPic{width:60px;height:60px}
 .ewhp-whoN{font-size:24px}
 .ewhp-cardText{font-size:16px}
 .ewhp-steps{padding:24px 18px}
 .ewhp-stHead{display:none}
 .ewhp-stRow{display:grid;grid-template-columns:20px minmax(0,1fr);column-gap:16px;row-gap:10px;padding-bottom:26px}
 .ewhp-stLine{left:9px;top:24px}
 .ewhp-stDot{grid-column:1;grid-row:1;margin-top:4px;box-shadow:0 0 0 5px #fff}
 .ewhp-stTime{grid-column:2;grid-row:1;width:auto;padding-top:0;display:flex;align-items:baseline;gap:10px;flex-wrap:wrap}
 .ewhp-stTime b{font-size:26px}
 .ewhp-stTime span{margin-top:0}
 .ewhp-stBox{grid-column:2;font-size:16px;padding:14px 16px}
 .ewhp-stBox em{display:block;font-style:normal;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#CC0000;margin-bottom:4px}
 .ewhp-pastTop{height:auto;min-height:64px;padding:12px 16px}
 .ewhp .ewhp-pastBadge{height:40px}
 .ewhp-pastWhen{font-size:18px}
 .ewhp-pastComp{display:none}
 .ewhp-pastWhere{padding:0 16px}
 .ewhp-pastTie{flex-direction:column;gap:8px;padding:16px 16px 20px}
 .ewhp-pastName,.ewhp-pastName.is-home{text-align:center;font-size:24px;line-height:1.2}
 .ewhp .ewhp-pastMid img{width:56px;height:56px}
 .ewhp-pastMid b{font-size:36px;line-height:1.2}
 .ewhp-photos{grid-template-columns:repeat(2,minmax(0,1fr))}
 .ewhp .ewhp-photos img{grid-column:auto;height:200px}
 .ewhp .ewhp-photos img:first-child,.ewhp .ewhp-photos.is-n2 img,.ewhp .ewhp-photos.is-n4 img:first-child,.ewhp .ewhp-photos.is-n6 img:first-child{grid-column:span 2}
 .ewhp .ewhp-photos.is-n2 img,.ewhp .ewhp-photos.is-n4 img:first-child,.ewhp .ewhp-photos.is-n6 img:first-child{height:240px}
 .ewhp .ewhp-photos.is-n1 img{height:280px}
 .ewhp .ewhp-photos.is-n4 img:last-child{grid-column:span 2}
 .ewhp-vGrid{grid-template-columns:1fr;gap:10px}
 .ewhp-vCard{flex-direction:row}
 .ewhp .ewhp-vImg{width:104px;height:auto;min-height:132px}
 .ewhp-vImg.ewhp-tile span{font-size:30px}
 .ewhp-vBody{padding:12px 14px 14px;gap:4px}
 .ewhp-vTitle{font-size:20px}
 .ewhp-vFoot{flex-wrap:wrap;gap:8px}
 .ewhp-vBar{flex:1 1 100%}
 .ewhp-vN{flex:1}
 .ewhp-play{padding:28px 16px;gap:28px}
 .ewhp-phones{flex-direction:column;align-items:center;gap:40px}
 .ewhp-phCol{gap:20px;width:100%;max-width:300px;align-items:center}
 .ewhp-phCap{width:260px}
 .ewhp-playFoot{flex-wrap:wrap}
 .ewhp-playFoot>p{flex:1 1 100%}
}
';
}

function ew_hts_js() {
	return <<<'JS'
(function(){
 // The red button on the pitch card goes to the form at the top of the page.
 document.addEventListener('click', function(e){
  var a = e.target.closest ? e.target.closest('[data-ewhp-scroll]') : null;
  if (!a) { return; }
  var href = a.getAttribute('href') || '';
  var t = null;
  if (href.charAt(0) === '#' && href.length > 1) {
   try { t = document.querySelector(href); } catch (x) { t = null; }
  }
  if (!t) { t = document.querySelector('.ewh, form'); }
  if (!t) { return; }
  e.preventDefault();
  t.scrollIntoView({ behavior: 'smooth', block: 'start' });
  setTimeout(function(){
   var f = t.querySelector('input:not([type=hidden]):not([type=checkbox])');
   if (f) { try { f.focus({ preventScroll: true }); } catch (x) { f.focus(); } }
  }, 600);
 });

 function store(k, v){
  try { if (v === undefined) { return localStorage.getItem(k); } localStorage.setItem(k, v); } catch (x) { return null; }
  return null;
 }

 [].forEach.call(document.querySelectorAll('[data-ewhp-vote]'), function(root){
  var cfgEl = root.querySelector('.ewhp-vCfg');
  if (!cfgEl) { return; }
  var C = {};
  try { C = JSON.parse(cfgEl.textContent || '{}'); } catch (x) { return; }
  var key = 'ewhp-vote-' + C.round;
  var mine = store(key);
  var msg = root.querySelector('.ewhp-vMsg');
  var cards = root.querySelectorAll('.ewhp-vCard');

  function paint(counts){
   var max = 1;
   Object.keys(counts || {}).forEach(function(k){ max = Math.max(max, counts[k] || 0); });
   [].forEach.call(cards, function(c){
    var g = c.getAttribute('data-game');
    var n = counts ? (counts[g] || 0) : null;
    if (n !== null) {
     var el = c.querySelector('[data-n]');
     if (el) { el.textContent = n + ' ' + (n === 1 ? C.one : C.many); }
     var bar = c.querySelector('.ewhp-vBar span');
     if (bar) { bar.style.width = Math.round(100 * n / max) + '%'; }
    }
    var b = c.querySelector('[data-vote]');
    c.classList.toggle('is-mine', mine === g);
    if (b) {
     b.disabled = !!mine;
     b.textContent = mine === g ? C.voted : C.vote;
    }
   });
  }

  paint(null);
  fetch(C.counts + (C.counts.indexOf('?') < 0 ? '?' : '&') + 'round=' + encodeURIComponent(C.round) + '&_=' + Date.now(), { credentials: 'omit' })
   .then(function(r){ return r.ok ? r.json() : null; })
   .then(function(j){ if (j && j.counts) { paint(j.counts); } })
   .catch(function(){});

  root.addEventListener('click', function(e){
   var b = e.target.closest ? e.target.closest('[data-vote]') : null;
   if (!b || !C.open) { return; }
   if (mine) { msg.textContent = C.already; return; }
   var card = b.closest('.ewhp-vCard');
   var game = card ? card.getAttribute('data-game') : '';
   if (!game) { return; }
   [].forEach.call(root.querySelectorAll('[data-vote]'), function(x){ x.disabled = true; });
   fetch(C.rest, {
    method: 'POST',
    credentials: 'omit',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ game: game, round: C.round, page: C.page })
   })
    .then(function(r){ return r.json().then(function(j){ return { s: r.status, j: j }; }); })
    .then(function(x){
     if (x.s === 200 && x.j && x.j.ok) {
      mine = game;
      store(key, game);
      msg.textContent = C.thanks;
      paint(x.j.counts);
     } else {
      msg.textContent = x.s === 429 ? C.busy : C.failed;
      paint(null);
     }
    })
    .catch(function(){ msg.textContent = C.failed; paint(null); });
  });
 });
})();
JS;
}

add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_singular() ) {
		return;
	}
	$post = get_post();
	if ( ! ew_hts_on_page( $post ) ) {
		return;
	}
	wp_enqueue_style( 'ewc-fonts', 'https://fonts.googleapis.com/css2?family=Anton&family=Instrument+Sans:wght@400;600;700&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
	wp_register_style( 'ew-hts-page', false, array(), EW_FWP_VERSION );
	wp_enqueue_style( 'ew-hts-page' );
	wp_add_inline_style( 'ew-hts-page', ew_hts_css() );
	wp_register_script( 'ew-hts-page', '', array(), EW_FWP_VERSION, true );
	wp_enqueue_script( 'ew-hts-page' );
	wp_add_inline_script( 'ew-hts-page', ew_hts_js() );
}, 22 );
