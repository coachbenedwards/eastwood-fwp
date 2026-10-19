<?php
/**
 * Eastwood TV — the clips come off the club YouTube channel.
 *
 * Three ways in, tried in this order, so the band fills itself with
 * nothing asked of anyone:
 *
 *   1. the YouTube Data API, if a key has been put in place. Exact,
 *      supported, gives the running time of every clip.
 *   2. the channel videos page. No key, gives the running time and
 *      the age of the clip, but it reads YouTube own page markup, so
 *      it is the one that could break if they change it.
 *   3. the channel public feed. No key, never breaks, but carries no
 *      running times and only ever holds the last fifteen uploads.
 *
 * Whatever comes back is held for half an hour so the homepage is
 * never waiting on YouTube.
 *
 * @package eastwood-fwp
 */

defined( 'ABSPATH' ) || exit;

/** The channel, as it is written on YouTube. */
function ew_yt_handle() {
	return apply_filters( 'ew_yt_handle', '@eastwoodfootballclub' );
}

/** A Data API key, if one has been set. Nothing here needs one. */
function ew_yt_api_key() {
	if ( defined( 'EW_YT_API_KEY' ) && EW_YT_API_KEY ) {
		return (string) EW_YT_API_KEY;
	}
	return (string) apply_filters( 'ew_yt_api_key', get_option( 'ew_yt_api_key', '' ) );
}

/** How long a fetched list is kept. */
function ew_yt_ttl() {
	return (int) apply_filters( 'ew_yt_ttl', 30 * MINUTE_IN_SECONDS );
}

/**
 * A handle such as @eastwoodfootballclub into the UC… id YouTube uses
 * everywhere else. Looked up once and then remembered for good.
 */
function ew_yt_channel_id( $chan = '' ) {
	$chan = trim( (string) $chan );
	if ( '' === $chan ) {
		$chan = ew_yt_handle();
	}

	if ( preg_match( '#(UC[A-Za-z0-9_\-]{22})#', $chan, $m ) ) {
		return $m[1];
	}

	$handle = ltrim( $chan, '@' );
	if ( '' === $handle ) {
		return '';
	}

	$slot = 'ew_yt_cid_' . md5( strtolower( $handle ) );
	$kept = get_option( $slot, '' );
	if ( $kept ) {
		return $kept;
	}

	$body = ew_yt_get( 'https://www.youtube.com/@' . rawurlencode( $handle ) );
	if ( '' !== $body
		&& preg_match( '#"(?:channelId|externalId)":"(UC[A-Za-z0-9_\-]{22})"#', $body, $m ) ) {
		update_option( $slot, $m[1], false );
		return $m[1];
	}

	return '';
}

/** One GET, with a browser-ish header set, body or an empty string. */
function ew_yt_get( $url ) {
	$res = wp_remote_get(
		$url,
		array(
			'timeout'     => 10,
			'redirection' => 3,
			'headers'     => array(
				'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
				'Accept-Language' => 'en-GB,en;q=0.9',
				'User-Agent'      => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36'
					. ' (KHTML, like Gecko) Chrome/125.0 Safari/537.36',
			),
		)
	);

	if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		return '';
	}

	return (string) wp_remote_retrieve_body( $res );
}

/**
 * The clips. Each one: id, title, url, img, secs, time, stamp, when.
 */
function ew_yt_videos( $chan = '', $count = 8, $min_secs = 61 ) {
	$cid = ew_yt_channel_id( $chan );
	if ( ! $cid ) {
		return array();
	}

	$count    = max( 1, min( 30, (int) $count ) );
	$min_secs = max( 0, (int) $min_secs );

	$slot = 'ew_yt_v_' . md5( $cid . '|' . $count . '|' . $min_secs . '|' . ew_yt_api_key() );
	$kept = get_transient( $slot );
	if ( is_array( $kept ) ) {
		return $kept;
	}

	$clips = array();
	if ( ew_yt_api_key() ) {
		$clips = ew_yt_from_api( $cid, $count, $min_secs );
	}
	if ( ! $clips ) {
		$clips = ew_yt_from_page( $cid, $count, $min_secs );
	}
	if ( ! $clips ) {
		$clips = ew_yt_from_feed( $cid, $count );
	}

	/* A miss is held briefly too, so a YouTube wobble is not a hundred
	   requests from one busy page. */
	set_transient( $slot, $clips, $clips ? ew_yt_ttl() : 5 * MINUTE_IN_SECONDS );

	return $clips;
}

/** Drop the held list, so the next page view goes and looks again. */
function ew_yt_forget() {
	global $wpdb;
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		  WHERE option_name LIKE '\_transient\_ew\_yt\_v\_%'
		     OR option_name LIKE '\_transient\_timeout\_ew\_yt\_v\_%'"
	);
}

/* ---------------------------------------------------------------- 1 */

function ew_yt_from_api( $cid, $count, $min_secs ) {
	$key = ew_yt_api_key();
	if ( ! $key ) {
		return array();
	}

	/* The uploads playlist of a channel is its id with UU for UC. */
	$list = ew_yt_api_call(
		'playlistItems',
		array(
			'part'       => 'snippet,contentDetails',
			'playlistId' => 'UU' . substr( $cid, 2 ),
			'maxResults' => min( 50, max( 10, $count * 3 ) ),
			'key'        => $key,
		)
	);

	if ( empty( $list['items'] ) ) {
		return array();
	}

	$order = array();
	foreach ( $list['items'] as $item ) {
		$id = isset( $item['contentDetails']['videoId'] ) ? $item['contentDetails']['videoId'] : '';
		if ( $id ) {
			$order[ $id ] = isset( $item['snippet'] ) ? $item['snippet'] : array();
		}
	}
	if ( ! $order ) {
		return array();
	}

	$runtime = array();
	foreach ( array_chunk( array_keys( $order ), 50 ) as $batch ) {
		$more = ew_yt_api_call(
			'videos',
			array(
				'part' => 'contentDetails',
				'id'   => implode( ',', $batch ),
				'key'  => $key,
			)
		);
		if ( empty( $more['items'] ) ) {
			continue;
		}
		foreach ( $more['items'] as $item ) {
			if ( isset( $item['id'], $item['contentDetails']['duration'] ) ) {
				$runtime[ $item['id'] ] = ew_yt_iso_secs( $item['contentDetails']['duration'] );
			}
		}
	}

	$clips = array();
	foreach ( $order as $id => $snip ) {
		$secs = isset( $runtime[ $id ] ) ? $runtime[ $id ] : 0;
		if ( $min_secs && $secs && $secs < $min_secs ) {
			continue;
		}

		$stamp = isset( $snip['publishedAt'] ) ? strtotime( $snip['publishedAt'] ) : 0;
		$clips[] = ew_yt_clip(
			$id,
			isset( $snip['title'] ) ? $snip['title'] : '',
			$secs,
			'',
			$stamp,
			ew_yt_best_thumb( isset( $snip['thumbnails'] ) ? $snip['thumbnails'] : array(), $id )
		);

		if ( count( $clips ) >= $count ) {
			break;
		}
	}

	return $clips;
}

function ew_yt_api_call( $what, $args ) {
	$body = ew_yt_get( add_query_arg( $args, 'https://www.googleapis.com/youtube/v3/' . $what ) );
	if ( '' === $body ) {
		return array();
	}
	$out = json_decode( $body, true );
	return is_array( $out ) ? $out : array();
}

function ew_yt_iso_secs( $iso ) {
	if ( ! preg_match( '#^P(?:(\d+)D)?T?(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$#i', (string) $iso, $m ) ) {
		return 0;
	}
	return ( isset( $m[1] ) && '' !== $m[1] ? (int) $m[1] * 86400 : 0 )
		+ ( isset( $m[2] ) && '' !== $m[2] ? (int) $m[2] * 3600 : 0 )
		+ ( isset( $m[3] ) && '' !== $m[3] ? (int) $m[3] * 60 : 0 )
		+ ( isset( $m[4] ) && '' !== $m[4] ? (int) $m[4] : 0 );
}

function ew_yt_best_thumb( $thumbs, $id ) {
	$best = '';
	$wide = 0;
	foreach ( (array) $thumbs as $t ) {
		if ( ! empty( $t['url'] ) && (int) $t['width'] >= $wide ) {
			$wide = (int) $t['width'];
			$best = $t['url'];
		}
	}
	return $best ? $best : 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg';
}

/* ---------------------------------------------------------------- 2 */

function ew_yt_from_page( $cid, $count, $min_secs ) {
	$body = ew_yt_get( 'https://www.youtube.com/channel/' . $cid . '/videos' );
	if ( '' === $body ) {
		return array();
	}

	$at = strpos( $body, 'ytInitialData' );
	if ( false === $at ) {
		return array();
	}
	$open = strpos( $body, '{', $at );
	if ( false === $open ) {
		return array();
	}

	$data = json_decode( ew_yt_brace_slice( $body, $open ), true );
	if ( ! is_array( $data ) ) {
		return array();
	}

	$found = array();
	ew_yt_collect( $data, $found );

	$clips = array();
	foreach ( $found as $id => $node ) {
		/* A short has no running time on it, which is how we leave the
		   vertical clips out of a row of landscape cards. */
		$len = ew_yt_node_text( isset( $node['lengthText'] ) ? $node['lengthText'] : null );
		if ( '' === $len ) {
			continue;
		}

		$secs = ew_yt_clock_secs( $len );
		if ( $min_secs && $secs && $secs < $min_secs ) {
			continue;
		}

		$title = ew_yt_node_text( isset( $node['title'] ) ? $node['title'] : null );
		if ( '' === $title ) {
			continue;
		}

		$clips[] = ew_yt_clip(
			$id,
			$title,
			$secs,
			ew_yt_node_text( isset( $node['publishedTimeText'] ) ? $node['publishedTimeText'] : null ),
			0,
			ew_yt_best_thumb(
				isset( $node['thumbnail']['thumbnails'] ) ? $node['thumbnail']['thumbnails'] : array(),
				$id
			)
		);

		if ( count( $clips ) >= $count ) {
			break;
		}
	}

	return $clips;
}

/** The object that starts at $i, brace counted, strings respected. */
function ew_yt_brace_slice( $s, $i ) {
	$len   = strlen( $s );
	$depth = 0;
	$instr = false;
	$esc   = false;

	for ( $p = $i; $p < $len; $p++ ) {
		$c = $s[ $p ];

		if ( $instr ) {
			if ( $esc ) {
				$esc = false;
			} elseif ( '\\' === $c ) {
				$esc = true;
			} elseif ( '"' === $c ) {
				$instr = false;
			}
			continue;
		}

		if ( '"' === $c ) {
			$instr = true;
		} elseif ( '{' === $c ) {
			$depth++;
		} elseif ( '}' === $c ) {
			$depth--;
			if ( 0 === $depth ) {
				return substr( $s, $i, $p - $i + 1 );
			}
		}
	}

	return '';
}

/** Every video shaped thing in there, in the order it appears. */
function ew_yt_collect( $node, &$out ) {
	if ( ! is_array( $node ) ) {
		return;
	}

	if ( isset( $node['videoId'], $node['title'] ) && is_string( $node['videoId'] ) ) {
		$id = $node['videoId'];
		if ( ! isset( $out[ $id ] ) ) {
			$out[ $id ] = $node;
		}
	}

	foreach ( $node as $child ) {
		if ( is_array( $child ) ) {
			ew_yt_collect( $child, $out );
		}
	}
}

/** YouTube writes its text two ways. */
function ew_yt_node_text( $node ) {
	if ( is_string( $node ) ) {
		return trim( $node );
	}
	if ( ! is_array( $node ) ) {
		return '';
	}
	if ( isset( $node['simpleText'] ) ) {
		return trim( (string) $node['simpleText'] );
	}
	if ( ! empty( $node['runs'] ) && is_array( $node['runs'] ) ) {
		$out = '';
		foreach ( $node['runs'] as $run ) {
			if ( isset( $run['text'] ) ) {
				$out .= $run['text'];
			}
		}
		return trim( $out );
	}
	return '';
}

function ew_yt_clock_secs( $clock ) {
	$bits = array_reverse( array_map( 'intval', explode( ':', trim( (string) $clock ) ) ) );
	$secs = 0;
	foreach ( $bits as $i => $n ) {
		$secs += $n * pow( 60, $i );
	}
	return $secs;
}

/* ---------------------------------------------------------------- 3 */

function ew_yt_from_feed( $cid, $count ) {
	$body = ew_yt_get( 'https://www.youtube.com/feeds/videos.xml?channel_id=' . $cid );
	if ( '' === $body ) {
		return array();
	}

	$clips = array();
	if ( ! preg_match_all( '#<entry>(.*?)</entry>#s', $body, $all ) ) {
		return array();
	}

	foreach ( $all[1] as $entry ) {
		if ( ! preg_match( '#<yt:videoId>(.*?)</yt:videoId>#', $entry, $m ) ) {
			continue;
		}
		$id = $m[1];

		$title = preg_match( '#<title>(.*?)</title>#s', $entry, $m )
			? html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) : '';
		if ( '' === $title ) {
			continue;
		}

		$stamp = preg_match( '#<published>(.*?)</published>#', $entry, $m ) ? strtotime( $m[1] ) : 0;
		$thumb = preg_match( '#<media:thumbnail[^>]+url="([^"]+)"#', $entry, $m ) ? $m[1] : '';

		$clips[] = ew_yt_clip( $id, $title, 0, '', $stamp, $thumb );

		if ( count( $clips ) >= $count ) {
			break;
		}
	}

	return $clips;
}

/* ------------------------------------------------------------ shared */

function ew_yt_clip( $id, $title, $secs, $when, $stamp, $img ) {
	if ( '' === $when && $stamp ) {
		$when = sprintf(
			/* translators: how long ago a clip went up. */
			__( '%s ago', 'eastwood-fwp' ),
			human_time_diff( $stamp, current_time( 'timestamp' ) )
		);
	}

	return array(
		'id'    => $id,
		'title' => trim( $title ),
		'url'   => 'https://www.youtube.com/watch?v=' . $id,
		'img'   => $img ? $img : 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg',
		'secs'  => (int) $secs,
		'time'  => $secs ? ew_yt_clock( $secs ) : '',
		'stamp' => (int) $stamp,
		'when'  => $when,
	);
}

function ew_yt_clock( $secs ) {
	$secs = (int) $secs;
	$h    = (int) floor( $secs / 3600 );
	$m    = (int) floor( ( $secs % 3600 ) / 60 );
	$s    = $secs % 60;

	return $h
		? sprintf( '%d:%02d:%02d', $h, $m, $s )
		: sprintf( '%d:%02d', $m, $s );
}

/**
 * Category=Label pairs out of one field, written
 *   Match Highlights=Highlights|This Is Eastwood=The Series
 */
function ew_yt_cat_map( $spec ) {
	$map = array();

	foreach ( explode( '|', (string) $spec ) as $pair ) {
		$pair = trim( $pair );
		if ( '' === $pair || false === strpos( $pair, '=' ) ) {
			continue;
		}
		$bits   = array_map( 'trim', explode( '=', $pair, 2 ) );
		$needle = $bits[0];
		if ( '' !== $needle ) {
			$map[ $needle ] = $bits[1];
		}
	}

	return $map;
}

/**
 * The label for a clip, and the title with that phrase taken back out
 * of it, so a card does not read Match Highlights twice.
 */
function ew_yt_label( $title, $map, $trim ) {
	$label = '';

	foreach ( $map as $needle => $text ) {
		if ( false !== stripos( $title, $needle ) ) {
			$label = $text;
			if ( $trim ) {
				$title = str_ireplace( $needle, '', $title );
			}
			break;
		}
	}

	/* Titles off the channel are written with pipes in them, so clear
	   up whatever the removal left behind. */
	$bits  = array_filter( array_map( 'trim', explode( '|', $title ) ), 'strlen' );
	$title = implode( ' | ', $bits );

	$title = preg_replace( '#^[\s|\-\x{2013}\x{2014}]+|[\s|\-\x{2013}\x{2014}]+$#u', '', $title );

	return array( 'title' => $title, 'cat' => $label );
}
