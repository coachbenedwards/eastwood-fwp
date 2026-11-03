<?php
/**
 * Events know when they have happened.
 *
 * An event's date lives in one place: the `date` field (YYYY-MM-DD) on the
 * tickets block of its own page under /events/. Everything else reads it.
 *
 * Anything on any page can be tied to an event by a CSS class:
 *   ewx-before-<slug>  shows until the event is over
 *   ewx-after-<slug>   shows from the morning after
 * where <slug> is the event page's slug (halloween-family-party). Put the
 * class on an Elementor row (Advanced > CSS classes) or on an element inside
 * a text widget. A row with the class `ewx-sec` is a section heading: it
 * hides itself when every event row under it (down to the next ewx-sec)
 * is hidden, so "Also coming up" goes when nothing is.
 *
 * The page is cached, so the switch is made twice: by CSS on the server when
 * the page is built, and by a few lines of script in the browser, which use
 * the visitor's clock. A cached copy from before the event still flips.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** slug => unix time the event counts as over (the end of its day, UK time). */
function ew_event_ends() {
	static $out = null;
	if ( null !== $out ) {
		return $out;
	}
	$out    = array();
	$parent = get_page_by_path( 'events' );
	if ( ! $parent || ! function_exists( 'ew_acad_block_on' ) ) {
		return $out;
	}
	$kids = get_posts( array( 'post_type' => 'page', 'post_parent' => $parent->ID, 'post_status' => 'publish', 'numberposts' => 200 ) );
	$tz   = wp_timezone();
	foreach ( $kids as $k ) {
		$blk  = ew_acad_block_on( $k, 'eastwood_tickets' );
		$date = $blk ? trim( (string) ( $blk['atts']['date'] ?? '' ) ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			continue;
		}
		try {
			$end = new DateTimeImmutable( $date . ' 23:59:59', $tz );
		} catch ( Exception $e ) {
			continue;
		}
		$out[ $k->post_name ] = $end->getTimestamp();
	}
	return $out;
}

function ew_event_is_past( $slug ) {
	$e = ew_event_ends();
	return isset( $e[ $slug ] ) && time() > $e[ $slug ];
}

/** The slug of an /events/<slug>/ link, or ''. */
function ew_event_slug_of_url( $url ) {
	$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
	return preg_match( '~^/events/([a-z0-9-]+)/?~', $path, $m ) ? $m[1] : '';
}

/** The event slug of the page being shown, if it is an event page with a date. */
function ew_event_this_page() {
	$post = get_post();
	if ( ! $post || 'page' !== $post->post_type ) {
		return '';
	}
	$ends = ew_event_ends();
	return isset( $ends[ $post->post_name ] ) && (int) $post->post_parent === (int) ( get_page_by_path( 'events' )->ID ?? 0 ) ? $post->post_name : '';
}

add_filter( 'body_class', function ( $c ) {
	foreach ( ew_event_ends() as $slug => $t ) {
		if ( time() > $t ) {
			$c[] = 'ew-past-' . $slug;
		}
	}
	$me = ew_event_this_page();
	if ( '' !== $me ) {
		$c[] = 'ew-event-page-' . $me;
	}
	return $c;
} );

add_action( 'wp_head', function () {
	$ends = ew_event_ends();
	if ( ! $ends ) {
		return;
	}
	$css = '';
	foreach ( array_keys( $ends ) as $s ) {
		$s    = sanitize_html_class( $s );
		$css .= 'body:not(.ew-past-' . $s . ') .ewx-after-' . $s . ',body.ew-past-' . $s . ' .ewx-before-' . $s . '{display:none!important}';
		// The checkout bar the tickets block pins to the bottom of its own page.
		$css .= 'body.ew-past-' . $s . '.ew-event-page-' . $s . ' .ewt-bar{display:none!important}';
	}
	echo '<style id="ew-event-dates">' . $css . '.ewx-sec.ew-empty{display:none!important}</style>' . "\n";
	$js = 'window.EW_EVENT_ENDS=' . wp_json_encode( $ends ) . ';'
		. '(function(){function go(){var e=window.EW_EVENT_ENDS,n=Date.now()/1000,b=document.body;if(!b)return;'
		. 'for(var s in e){b.classList.toggle("ew-past-"+s,n>e[s]);}'
		. 'var rows=document.querySelectorAll(".ewx-sec");rows.forEach(function(h){var el=h.nextElementSibling,any=false,seen=false;'
		. 'while(el&&!el.classList.contains("ewx-sec")){if(/\bewx-(before|after)-/.test(el.className)){seen=true;if(getComputedStyle(el).display!=="none")any=true;}el=el.nextElementSibling;}'
		. 'h.classList.toggle("ew-empty",seen&&!any);});}'
		. 'if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",go);else go();})();';
	echo '<script id="ew-event-dates-js">' . $js . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from slugs and integers
}, 5 );
