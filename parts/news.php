<?php
/**
 * The three stories that go under the lead on the news page.
 *
 * The captured hero reserves a band under the big story -- on the
 * reference site it holds three more, picture on the left and headline
 * on the right, and the page goes lead, three across, category bar,
 * grid. Our build renders the lead and nothing else, so that band came
 * out as four hundred pixels of empty grey between the photograph and
 * the category bar. It is the single thing that made the page read as
 * unfinished.
 *
 * The strip is written into the hero's own container so it sits where
 * the reference puts it, and takes the second, third and fourth most
 * recent posts -- the lead is the first.
 *
 * @package eastwood-fwp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Is this the news listing?
 *
 * Checked on the address rather than the queried object: the hero
 * markup this hangs off appears on other pages too, and a strip of
 * news stories under the homepage hero would be somebody else's
 * decision, not this function's.
 *
 * @return bool
 */
function ew_news_is_listing() {
	$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
	return ( '/news' === rtrim( $path, '/' ) );
}

/**
 * The three posts under the lead.
 *
 * @return WP_Post[]
 */
function ew_news_runners_up() {
	$q = new WP_Query( array(
		'post_type'           => 'post',
		'post_status'         => 'publish',
		'posts_per_page'      => 4,
		'ignore_sticky_posts' => true,
		'no_found_rows'       => true,
	) );
	$posts = $q->posts;
	array_shift( $posts );            // The lead is already in the hero.
	return array_slice( $posts, 0, 3 );
}

/**
 * One card: picture on the left, headline and meta on the right.
 *
 * @param WP_Post $post The post.
 * @return string
 */
function ew_news_card( $post ) {
	$cats = get_the_category( $post->ID );
	$cat  = ( $cats && isset( $cats[0]->name ) ) ? $cats[0]->name : 'Club News';
	$when = human_time_diff( get_post_time( 'U', true, $post ), time() ) . ' ago';
	$img  = get_the_post_thumbnail_url( $post->ID, 'medium_large' );

	$pic = $img
		? '<span class="ewn-pic"><img src="' . esc_url( $img ) . '" alt="" loading="lazy" decoding="async"></span>'
		: '<span class="ewn-pic ewn-pic-none"></span>';

	return '<a class="ewn-card" href="' . esc_url( get_permalink( $post ) ) . '">'
		. $pic
		. '<span class="ewn-txt">'
		. '<span class="ewn-h">' . esc_html( get_the_title( $post ) ) . '</span>'
		. '<span class="ewn-m"><b>' . esc_html( $cat ) . '</b> | ' . esc_html( $when ) . '</span>'
		. '</span></a>';
}

/**
 * The strip's own styling, and the category bar put back to the
 * reference's type: Anton at 18, tight, with the live section carrying
 * a red rule under it rather than simply being a heavier weight.
 *
 * @return string
 */
function ew_news_css() {
	return '<style id="ew-news-strip">'
		. '.ewn-strip{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;'
		. 'width:100%}'
		/* The hero container is a fixed 828 tall with a 16px gap of its own,
		   so the strip needs no margin and the lead has to stretch into what
		   is left: 828 - 16 - 215 = 597, which is the reference's lead height
		   exactly. Measured, not guessed -- without this the lead stops at its
		   420 floor and leaves 177px of empty grey above the category bar. */
		. '@media(min-width:1001px){'
		. 'section.hero > div > a:has(img.wp-post-image){flex:1 1 auto}'
		. '}'
		. '.ewn-card{display:grid;grid-template-columns:196px minmax(0,1fr);background:#fff;'
		. 'border-radius:8px;overflow:hidden;min-height:215px;text-decoration:none;color:inherit}'
		. '.ewn-pic{display:block;overflow:hidden;background:#d8d8d8}'
		. '.ewn-pic img{width:100%;height:100%;object-fit:cover;display:block}'
		. '.ewn-txt{display:flex;flex-direction:column;justify-content:space-between;'
		. 'padding:18px 18px 16px;min-width:0}'
		. '.ewn-h{font-family:Anton,"Instrument Sans",system-ui,sans-serif;font-weight:400;'
		. 'text-transform:uppercase;font-size:19px;line-height:1.12;letter-spacing:.01em;color:#CC0000}'
		. '.ewn-m{font-family:"Instrument Sans",system-ui,sans-serif;font-size:13px;color:#777}'
		. '.ewn-m b{font-weight:600;color:#111}'
		. '.ewn-card:hover .ewn-h{color:#111}'
		/* The category bar. The reference sets Anton 18 with a red rule
		   under the live one; ours was the body face at two weights,
		   which is why the two bars did not look related. */
		. '.tabs .container > a{font-family:Anton,"Instrument Sans",system-ui,sans-serif!important;'
		. 'font-weight:700!important;font-size:18px!important;letter-spacing:-.45px;'
		. 'color:#101010!important;padding:8px 0 16px!important;margin-right:30px;'
		. 'border-bottom:2px solid transparent}'
		. '.tabs .container > a:last-child{margin-right:0}'
		. '.tabs .container > a.text-black{border-bottom-color:#CC0000}'
		. '.tabs .container{gap:0!important}'
		. '@media(max-width:1000px){'
		. '.ewn-strip{grid-template-columns:1fr}'
		/* One column inside the card too, or the 196px picture takes more
		   than half a 358px phone and the headline wraps three words to a
		   line. Every other card on this page stacks picture over headline
		   on a phone; these now do the same. */
		. '.ewn-card{min-height:0;grid-template-columns:1fr}'
		. '.ewn-pic{height:180px}'
		. '}'
		. '</style>';
}

/**
 * Put the strip inside the hero's own container.
 *
 * Called from ew_rewrite_markup() in the main file under
 * function_exists, because parts load before init.
 *
 * @param string $html The finished page.
 * @return string
 */
function ew_news_markup( $html ) {
	if ( is_admin() || ! ew_news_is_listing() ) {
		return $html;
	}

	$open = '<div class="flex flex-col gap-4 lg:px-4 container mx-auto">';
	$at   = strpos( $html, $open );
	if ( false === $at ) {
		return $html;
	}
	if ( ! function_exists( 'ew_article_div_end' ) ) {
		return $html;
	}
	$end = ew_article_div_end( $html, $at );
	if ( $end < 0 ) {
		return $html;
	}

	$posts = ew_news_runners_up();
	if ( ! $posts ) {
		return $html;
	}

	$strip = '<div class="ewn-strip">';
	foreach ( $posts as $post ) {
		$strip .= ew_news_card( $post );
	}
	$strip .= '</div>';

	return substr( $html, 0, $end ) . $strip . ew_news_css() . substr( $html, $end );
}
