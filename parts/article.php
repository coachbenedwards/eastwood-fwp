<?php
/**
 * The article on a single news post.
 *
 * The captured single-post template prints the_content() INSIDE the hero
 * widget -- the big picture at the top of the page. That widget is a
 * fixed height with overflow:hidden, so every word of every match report
 * has been clipped away behind the photograph since the site went up.
 * The text was in the page source the whole time. Nobody could read it.
 *
 * Nor was there a headline, a date or a byline anywhere on the page: a
 * post opened with a picture and went straight to the Latest News cards.
 * The byline has been collected on import since the start and shown
 * nowhere.
 *
 * The theme is not in our deploy loop, so the fix is made where the
 * plugin already takes its single pass over the finished page. The body
 * is lifted out of the hero and set below it with a kicker, the
 * headline, the date and the byline -- an article, in the club's own
 * type, on a white page.
 *
 * ew_article_markup() is called from ew_rewrite_markup() in the main
 * file, guarded by function_exists, because parts load before init and
 * nothing may assume the load order.
 *
 * @package eastwood-fwp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Where the <div> opened at $at closes.
 *
 * A match report can hold any amount of nested markup, so the end of the
 * body is found by counting divs rather than by looking for the next
 * closing tag.
 *
 * @param string $html The page.
 * @param int    $at   Offset of the opening <div.
 * @return int Offset of its </div>, or -1 if the markup never closes.
 */
function ew_article_div_end( $html, $at ) {
	$i     = $at;
	$depth = 0;
	$len   = strlen( $html );

	while ( $i < $len ) {
		$open  = stripos( $html, '<div', $i );
		$close = stripos( $html, '</div>', $i );

		if ( false === $close ) {
			return -1;
		}
		if ( false !== $open && $open < $close ) {
			$depth++;
			$i = $open + 4;
			continue;
		}
		$depth--;
		if ( 0 === $depth ) {
			return $close;
		}
		$i = $close + 6;
	}

	return -1;
}

/**
 * The kicker over the headline: the section this post belongs to.
 *
 * A post is filed in a section and often in a team as well. The section
 * is the useful half, and it sorts first, so the first category is the
 * one that goes above the headline.
 *
 * @param int $id Post id.
 * @return string
 */
function ew_article_kicker( $id ) {
	$cats = get_the_category( $id );
	return ( $cats && isset( $cats[0]->name ) ) ? (string) $cats[0]->name : 'Club News';
}

/**
 * The header that goes above the lifted body.
 *
 * @param int $id Post id.
 * @return string
 */
function ew_article_header( $id ) {
	$title  = get_the_title( $id );
	$when   = get_the_date( 'j F Y', $id );
	$byline = trim( (string) get_post_meta( $id, 'ew_byline', true ) );

	$meta = $when;
	if ( '' !== $byline ) {
		$meta .= ' &middot; Words by ' . esc_html( $byline );
	}

	return '<p class="ew-art-kick">' . esc_html( ew_article_kicker( $id ) ) . '</p>'
		. '<h1 class="ew-art-title">' . esc_html( $title ) . '</h1>'
		. '<p class="ew-art-meta">' . $meta . '</p>'
		. '<div class="ew-art-rule"></div>';
}

/**
 * The article's own styling.
 *
 * It is printed with the markup rather than enqueued, because the lift
 * happens in the output buffer -- by the time we know the page needs it,
 * the head has already gone out.
 *
 * The measure is 72ch on a content box inside the same 1248px gutter the
 * rest of the site holds, which is what ew-prose does on the written
 * pages, so an article and a page read at the same width.
 *
 * @return string
 */
function ew_article_css() {
	return '<style id="ew-art-css">'
		. '.ew-art{background:#fff;color:#111;'
		. 'font-family:"Instrument Sans",system-ui,sans-serif;'
		. 'padding:44px max(16px,calc(50vw - 624px)) 56px}'
		. '.ew-art *{box-sizing:border-box}'
		. '.ew-art-kick{margin:0;font-size:12px;font-weight:700;letter-spacing:.12em;'
		. 'text-transform:uppercase;color:#CC0000}'
		. '.ew-art-title{font-family:Anton,"Instrument Sans",sans-serif;font-weight:400;'
		. 'text-transform:uppercase;font-size:46px;line-height:1.02;letter-spacing:.01em;'
		. 'margin:12px 0 0;max-width:24ch;color:#111}'
		. '.ew-art-meta{margin:14px 0 0;font-size:14px;color:#777}'
		. '.ew-art-rule{width:64px;height:3px;background:#CC0000;margin:26px 0 30px}'
		/* The body comes from the editor, so every element it can produce
		   needs a rule. A content box, so the 72ch is the measure and not
		   the measure minus the gutters. */
		/* The editor container carries 8px 16px of its own, which set the
		   body a notch to the right of its own headline. */
		. '.ew-art-body{padding:0}'
		. '.ew-art-body > *{box-sizing:content-box;max-width:72ch}'
		. '.ew-art-body p{font-size:17px;line-height:1.7;margin:0 0 18px}'
		. '.ew-art-body h2,.ew-art-body h3{font-family:Anton,"Instrument Sans",sans-serif;'
		. 'font-weight:400;text-transform:uppercase;letter-spacing:.02em;'
		. 'margin:34px 0 12px;line-height:1.1;color:#111}'
		. '.ew-art-body h2{font-size:26px}'
		. '.ew-art-body h3{font-size:20px}'
		. '.ew-art-body ul,.ew-art-body ol{margin:0 0 20px;padding-left:20px}'
		. '.ew-art-body li{font-size:17px;line-height:1.6;margin:0 0 8px}'
		. '.ew-art-body a{color:#CC0000}'
		. '.ew-art-body strong{font-weight:700}'
		. '.ew-art-body blockquote{margin:26px 0;padding:2px 0 2px 20px;'
		. 'border-left:3px solid #CC0000;font-size:20px;line-height:1.5;color:#333}'
		. '.ew-art-body blockquote p{font-size:inherit;line-height:inherit}'
		. '.ew-art-body figure{margin:26px 0;max-width:100%}'
		. '.ew-art-body img{max-width:100%;height:auto;border-radius:4px}'
		. '.ew-art-body figcaption{font-size:13px;color:#888;margin-top:8px}'
		. '@media(max-width:860px){'
		. '.ew-art{padding-top:32px}'
		. '.ew-art-title{font-size:32px}'
		. '.ew-art-body p,.ew-art-body li{font-size:16px}'
		. '}'
		. '</style>';
}

/**
 * Lift the body out of the hero and set it below as an article.
 *
 * @param string $html The finished page.
 * @return string
 */
function ew_article_markup( $html ) {
	if ( ! is_singular( 'post' ) || is_admin() ) {
		return $html;
	}

	$id = get_queried_object_id();
	if ( ! $id ) {
		return $html;
	}

	// The body is the one container the editor writes: max-w-[720px] and
	// tiptap together. The byline strip the capture left behind carries
	// the same width but not that class.
	if ( ! preg_match( '#<div class="[^"]*\btiptap\b[^"]*">#i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
		return $html;
	}

	$open = (int) $m[0][1];
	$end  = ew_article_div_end( $html, $open );
	if ( $end < 0 ) {
		return $html;
	}

	$body  = substr( $html, $open, $end + 6 - $open );
	$after = substr( $html, $end + 6 );

	// The body is the hero's last child, so the hero's own closing tag is
	// the next thing on the page. If it is not, the template has changed
	// under us and the page is left exactly as it came.
	if ( 0 !== strpos( ltrim( $after ), '</div>' ) ) {
		return $html;
	}

	$pad   = strlen( $after ) - strlen( ltrim( $after ) );
	$after = substr( $after, $pad + 6 );

	// Same container, renamed, so the editor's own block classes still
	// find their styling.
	$body = preg_replace( '#^<div class="[^"]*"#', '<div class="ew-art-body tiptap"', $body, 1 );

	$article = ew_article_css()
		. '<div class="ew-art">'
		. ew_article_header( $id )
		. $body
		. '</div>';

	return substr( $html, 0, $open ) . '</div>' . $article . $after;
}

/* ------------------------------------------------------------------ *
 * The lead story's photograph on the news page.
 *
 * The captured markup gives that image -z-10. Nothing in the card
 * establishes a stacking context, so the negative z-index takes the
 * picture behind the nearest one that does -- the section around it,
 * which paints a background. The result is a grey void where the lead
 * story should be, with the headline stranded two thirds down it. It
 * has been that way for every lead story the site has ever had.
 *
 * Isolating the card keeps the image behind the card's own overlay and
 * text, which is what the -z-10 was for, while bringing it back in
 * front of the section's background, which is what it was not.
 * ------------------------------------------------------------------ */

add_action( 'wp_head', function () {
	if ( is_admin() ) {
		return;
	}
	echo '<style id="ew-lead-fix">'
		. 'section.hero > div > a:has(> img.wp-post-image){isolation:isolate}'
		. 'section.hero > div > a > img.wp-post-image{z-index:0}'
		. '</style>';
}, 99 );
