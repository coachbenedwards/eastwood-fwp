<?php
/**
 * What a link to the site looks like when it is sent on WhatsApp, iMessage,
 * Facebook and the rest.
 *
 * A page with a Featured image shows that photo, big. A page without one
 * shows the club card: the crest on black (assets/share/share-default.jpg).
 * The line under the title is the page's Excerpt, when it has one, or else
 * the opening of the page's text. Both are
 * set on the page itself in WordPress, so nobody needs the code to change
 * them.
 *
 * @package eastwood-fwp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* Pages get an Excerpt box, for the line under the title. */
add_action( 'init', function () {
	add_post_type_support( 'page', 'excerpt' );
} );

add_action( 'wp_head', function () {
	// An SEO plugin writes its own tags; leave it to that.
	if ( defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' ) ) {
		return;
	}

	$title = wp_get_document_title();
	$url   = is_singular() ? get_permalink() : home_url( add_query_arg( array() ) );
	$desc  = '';
	$img   = '';
	$w     = 1200;
	$h     = 630;

	if ( is_singular() ) {
		$post = get_post();
		if ( $post && has_excerpt( $post ) ) {
			$desc = wp_strip_all_tags( get_the_excerpt( $post ) );
		}
		$thumb = get_post_thumbnail_id( $post );
		if ( $thumb ) {
			$src = wp_get_attachment_image_src( $thumb, 'large' );
			if ( $src ) {
				list( $img, $w, $h ) = $src;
			}
		}
	}
	if ( '' === $desc && is_singular() && function_exists( 'ew_meta_description' ) ) {
		$desc = ew_meta_description( (string) get_post()->post_content );
	}
	if ( '' === $desc ) {
		$desc = (string) get_bloginfo( 'description' );
	}
	if ( '' === $img ) {
		$img = plugins_url( 'assets/share/share-default.jpg', dirname( __DIR__ ) . '/eastwood-fwp.php' );
		$w   = 1200;
		$h   = 630;
	}

	$tags = array(
		'og:type'        => is_singular( 'post' ) ? 'article' : 'website',
		'og:site_name'   => get_bloginfo( 'name' ),
		'og:title'       => $title,
		'og:description' => $desc,
		'og:url'         => $url,
		'og:image'       => $img,
		'og:image:width' => (string) (int) $w,
		'og:image:height' => (string) (int) $h,
	);
	foreach ( $tags as $k => $v ) {
		if ( '' !== (string) $v ) {
			echo '<meta property="' . esc_attr( $k ) . '" content="' . esc_attr( $v ) . "\" />\n";
		}
	}
	echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
}, 5 );
