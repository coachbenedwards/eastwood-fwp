<?php
/**
 * The matchday guide, as the Team EastwoodFC app draws it.
 *
 * The words live once, on /matchday/, as ordinary Elementor heading and
 * text widgets a person edits in the builder. This reads them in page order
 * and hands them over as sections, so a changed turnstile time or a new
 * "arrive early" line shows in the app the next time it loads, with no app
 * release and nothing edited twice.
 *
 *   h1 heading             the screen's title
 *   text before any h2     the lede under the title
 *   h2 heading             starts a section
 *   text widgets           the section's paragraphs (links kept)
 *   class ew-alert         on a heading, or its container: the section is
 *                          drawn as the red notice at the top (a full house,
 *                          get here early)
 *
 * @package eastwood-fwp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'eastwood/v1', '/matchday/app', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => 'ew_app_matchday_rest',
	) );
} );

/** A text widget's HTML as paragraphs of runs: plain text and links. */
function ew_app_paragraphs( $html ) {
	$html = (string) $html;
	if ( function_exists( 'wptexturize' ) ) {
		$html = wptexturize( $html );
	}
	// Whitespace in the source is layout, not words: fold it to single
	// spaces, then mark the real breaks. (wp_strip_all_tags trims each
	// piece, which ate the space before every link, so it isn't used here.)
	$html  = preg_replace( '#<(script|style)[^>]*>.*?</\1>#is', '', $html );
	$html  = preg_replace( '/\s+/u', ' ', $html );
	$html  = preg_replace( '#<br\s*/?>#i', "\x01", $html );
	$html  = preg_replace( '#</(p|li|h[1-6]|div)>#i', "\n\n", $html );
	$html  = preg_replace( '#<li[^>]*>#i', '• ', $html );
	$paras = array();
	foreach ( preg_split( "/\n\s*\n/", $html ) as $chunk ) {
		$runs = array();
		$rest = $chunk;
		while ( preg_match( '#<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', $rest, $m, PREG_OFFSET_CAPTURE ) ) {
			$before = substr( $rest, 0, $m[0][1] );
			$t      = str_replace( "\x01", "\n", html_entity_decode( strip_tags( $before ), ENT_QUOTES, 'UTF-8' ) );
			if ( '' !== $t ) {
				$runs[] = array( 'text' => $t );
			}
			$runs[] = array(
				'text' => trim( html_entity_decode( strip_tags( $m[2][0] ), ENT_QUOTES, 'UTF-8' ) ),
				'href' => esc_url_raw( html_entity_decode( $m[1][0], ENT_QUOTES, 'UTF-8' ) ),
			);
			$rest = substr( $rest, $m[0][1] + strlen( $m[0][0] ) );
		}
		$t = str_replace( "\x01", "\n", html_entity_decode( strip_tags( $rest ), ENT_QUOTES, 'UTF-8' ) );
		if ( '' !== $t ) {
			$runs[] = array( 'text' => $t );
		}
		// Tidy the whitespace the markup left at either end of the paragraph.
		if ( $runs ) {
			$runs[0]['text']                     = ltrim( $runs[0]['text'] );
			$runs[ count( $runs ) - 1 ]['text']  = rtrim( $runs[ count( $runs ) - 1 ]['text'] );
			$runs = array_values( array_filter( $runs, function ( $r ) {
				return '' !== trim( $r['text'] ) || ! empty( $r['href'] );
			} ) );
		}
		if ( $runs ) {
			$paras[] = $runs;
		}
	}
	return $paras;
}

function ew_app_matchday_rest( $req ) {
	$post = get_page_by_path( 'matchday' );
	if ( ! $post || 'publish' !== $post->post_status ) {
		return new WP_Error( 'ew_app_matchday', 'No matchday page.', array( 'status' => 404 ) );
	}
	$out = array(
		'url'      => get_permalink( $post ),
		'title'    => get_the_title( $post ),
		'lede'     => array(),
		'sections' => array(),
		'updated'  => get_post_modified_time( 'c', true, $post ),
	);
	$cur  = null;
	$walk = function ( $nodes, $alert_parent ) use ( &$walk, &$out, &$cur ) {
		foreach ( (array) $nodes as $n ) {
			$cls   = ' ' . (string) ( $n['settings']['_css_classes'] ?? $n['settings']['css_classes'] ?? '' ) . ' ';
			$alert = $alert_parent || false !== strpos( $cls, ' ew-alert ' );
			if ( 'widget' === ( $n['elType'] ?? '' ) ) {
				$type = (string) ( $n['widgetType'] ?? '' );
				$s    = (array) ( $n['settings'] ?? array() );
				if ( 'heading' === $type ) {
					$tag  = strtolower( (string) ( $s['header_size'] ?? 'h2' ) );
					$text = trim( html_entity_decode( wp_strip_all_tags( (string) ( $s['title'] ?? '' ) ), ENT_QUOTES, 'UTF-8' ) );
					if ( 'h1' === $tag ) {
						if ( '' !== $text ) {
							$out['title'] = $text;
						}
						continue;
					}
					if ( '' === $text ) {
						continue;
					}
					if ( null !== $cur ) {
						$out['sections'][] = $cur;
					}
					$cur = array( 'title' => $text, 'alert' => $alert, 'paragraphs' => array() );
				} elseif ( 'text-editor' === $type ) {
					$paras = ew_app_paragraphs( (string) ( $s['editor'] ?? '' ) );
					if ( null === $cur ) {
						$out['lede'] = array_merge( $out['lede'], $paras );
					} else {
						$cur['paragraphs'] = array_merge( $cur['paragraphs'], $paras );
						$cur['alert']      = $cur['alert'] || $alert;
					}
				}
				continue;
			}
			if ( ! empty( $n['elements'] ) ) {
				$walk( $n['elements'], $alert );
			}
		}
	};
	$walk( ew_blocks_read( $post ), false );
	if ( null !== $cur ) {
		$out['sections'][] = $cur;
	}
	$res = rest_ensure_response( $out );
	$res->header( 'Cache-Control', 'public, max-age=60' );
	return $res;
}
