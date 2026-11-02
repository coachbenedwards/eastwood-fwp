<?php
/**
 * eastwoodfc.com/app: one link for the club app, for every phone.
 *
 * Shared on WhatsApp, a poster or a QR code, the link shows the club's own
 * preview card (the page's Featured image and Excerpt, through share-tags.php)
 * instead of the cropped icon Apple's App Store link shows. Opened on an
 * iPhone it goes straight to the App Store, on Android straight to Google
 * Play; on a computer the page stays put and shows both buttons.
 *
 * [eastwood_app]
 *
 * Every word and both store addresses are fields on the block. The heading
 * and the line above the buttons are the page's own widgets.
 *
 * Add ?stay to the address to see the page on a phone without being sent on.
 *
 * @package eastwood-fwp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The block's fields and what they say until someone changes them. */
function ew_app_defaults() {
	return array(
		'ios_label'     => 'Download on the App Store',
		'ios_url'       => 'https://apps.apple.com/gb/app/eastwood-fc/id6820880105',
		'android_label' => 'Get it on Google Play',
		'android_url'   => 'https://play.google.com/store/apps/details?id=com.eastwoodfc.team',
		'redirect'      => 'yes',
		'note'          => '',
	);
}

function ew_app_shortcode( $atts ) {
	$a = shortcode_atts( ew_app_defaults(), (array) $atts, 'eastwood_app' );

	$apple = '<svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true"><path fill="currentColor" d="M16.4 12.6c0-2.4 2-3.6 2.1-3.7-1.1-1.7-2.9-1.9-3.5-1.9-1.5-.2-2.9.9-3.7.9-.8 0-1.9-.9-3.2-.8-1.6 0-3.1 1-4 2.4-1.7 3-.4 7.4 1.2 9.8.8 1.2 1.8 2.5 3 2.4 1.2 0 1.7-.8 3.1-.8 1.5 0 1.9.8 3.2.8 1.3 0 2.1-1.2 2.9-2.4.9-1.4 1.3-2.7 1.3-2.8 0 0-2.5-1-2.4-3.9zM14 5.3c.7-.8 1.1-1.9 1-3-1 0-2.1.7-2.8 1.5-.6.7-1.2 1.8-1 2.9 1.1.1 2.1-.6 2.8-1.4z"/></svg>';
	$play  = '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" d="M4.5 3.2v17.6c0 .5.5.8.9.5l15-8.8c.4-.3.4-.8 0-1.1l-15-8.8c-.4-.2-.9.1-.9.6z"/></svg>';

	$out  = '<div class="ewa" data-ios="' . esc_url( $a['ios_url'] ) . '" data-android="' . esc_url( $a['android_url'] ) . '" data-redirect="' . esc_attr( $a['redirect'] ) . '">';
	$out .= '<div class="ewa-btns">';
	$out .= '<a class="ewa-btn" href="' . esc_url( $a['ios_url'] ) . '">' . $apple . '<span>' . esc_html( $a['ios_label'] ) . '</span></a>';
	$out .= '<a class="ewa-btn" href="' . esc_url( $a['android_url'] ) . '">' . $play . '<span>' . esc_html( $a['android_label'] ) . '</span></a>';
	$out .= '</div>';
	if ( '' !== trim( $a['note'] ) ) {
		$out .= '<p class="ewa-note">' . esc_html( $a['note'] ) . '</p>';
	}
	$out .= '</div>';
	return $out;
}
add_shortcode( 'eastwood_app', 'ew_app_shortcode' );

function ew_app_css() {
	return '
.ewa{max-width:1248px;margin:24px auto 64px;padding:0 16px;font-family:"Instrument Sans",system-ui,sans-serif}
.ewa-btns{display:flex;gap:16px;flex-wrap:wrap}
.ewa-btn{display:inline-flex;align-items:center;gap:12px;min-height:60px;padding:0 24px;border-radius:12px;background:#0a0a0b;color:#fff !important;text-decoration:none !important;font-weight:700;font-size:18px;line-height:1.2}
.ewa-btn:hover{background:#CC0000}
.ewa-btn svg{flex:none}
.ewa-note{margin:16px 0 0;color:#5d5d66;font-size:15px}
@media (max-width:600px){.ewa-btns{flex-direction:column}.ewa-btn{justify-content:center}}
';
}

/* Sends a phone straight to its store. Link-preview robots do not run this,
   so WhatsApp and the rest still read the page and its preview card. */
function ew_app_js() {
	return <<<'JS'
(function () {
  var box = document.querySelector('.ewa');
  if (!box || box.getAttribute('data-redirect') !== 'yes') return;
  if (/[?&]stay\b/.test(location.search)) return;
  var ua = navigator.userAgent || '';
  var ios = /iPhone|iPad|iPod/.test(ua) || (/Macintosh/.test(ua) && 'ontouchend' in document);
  var android = /Android/i.test(ua);
  var to = ios ? box.getAttribute('data-ios') : (android ? box.getAttribute('data-android') : '');
  if (to) location.replace(to);
})();
JS;
}

add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_singular() ) {
		return;
	}
	$post = get_post();
	if ( ! $post || ! function_exists( 'ew_post_contains' ) || ! ew_post_contains( $post, 'eastwood_app' ) ) {
		return;
	}
	wp_enqueue_style( 'ewc-fonts', 'https://fonts.googleapis.com/css2?family=Anton&family=Instrument+Sans:wght@400;600;700&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
	wp_register_style( 'ew-app', false, array(), EW_FWP_VERSION );
	wp_enqueue_style( 'ew-app' );
	wp_add_inline_style( 'ew-app', ew_app_css() );
	wp_register_script( 'ew-app', '', array(), EW_FWP_VERSION, true );
	wp_enqueue_script( 'ew-app' );
	wp_add_inline_script( 'ew-app', ew_app_js() );
}, 22 );

/* The command channel lays this block out like the others. */
add_filter( 'ew_blocks_allowed', function ( $tags ) {
	$tags[] = 'eastwood_app';
	return $tags;
} );
