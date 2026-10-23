<?php
/**
 * What the club can actually see.
 *
 * Two Meta pixels sit on every page through the theme and fire PageView,
 * which counts arrivals and nothing else -- nobody could answer "did that
 * post sell a shirt", because no event was ever raised when something was
 * bought or somebody signed up.
 *
 * This raises the two that matter, Purchase and Lead, and sends each to
 * both pixels and to Google Analytics in one breath, so the three never
 * disagree about what happened.
 *
 * Nothing is hardcoded: the Google measurement ID is a setting, and left
 * empty no Google tag is put on the site at all. The pixels belong to the
 * theme and are not touched from here.
 *
 * @package eastwood-fwp
 */

defined( 'ABSPATH' ) || exit;

/** The Google measurement ID, or '' when none is set or it is malformed. */
function ew_ga4_id() {
	$v = strtoupper( trim( (string) get_option( 'ew_ga4_id', '' ) ) );
	return preg_match( '/^G-[A-Z0-9]{4,20}$/', $v ) ? $v : '';
}

/**
 * The Google tag, and the one helper everything else calls.
 *
 * ewTrack() is deliberately forgiving. A pixel blocked by an ad blocker, a
 * measurement ID left empty, a visitor who refused cookies -- in every
 * case it does nothing rather than throwing, because an error here would
 * break the page a buyer is standing on, which matters more than the
 * counting.
 */
add_action( 'wp_head', function () {
	if ( is_admin() ) {
		return;
	}
	$ga = ew_ga4_id();

	if ( '' !== $ga ) {
		echo '<script async src="https://www.googletagmanager.com/gtag/js?id=' . esc_attr( $ga ) . '"></script>' . "\n";
		echo '<script id="ew-ga4">'
			. 'window.dataLayer=window.dataLayer||[];'
			. 'function gtag(){dataLayer.push(arguments);}'
			. 'gtag("js",new Date());'
			. 'gtag("config",' . wp_json_encode( $ga ) . ');'
			. '</script>' . "\n";
	}

	/* Meta and Google want the same two happenings under different names
	   and different shapes. One call in, both spellings out. */
	echo '<script id="ew-track">'
		. 'window.ewTrack=function(name,p){try{'
		. 'p=p||{};'
		. 'var money=(typeof p.value==="number"&&p.value>0);'
		. 'if(typeof fbq==="function"){'
		. 'var f={};if(money){f.value=p.value;f.currency="GBP";}'
		. 'if(p.what){f.content_name=p.what;}'
		. 'fbq("track",name,f);}'
		. 'if(typeof gtag==="function"){'
		. 'var g={};if(money){g.value=p.value;g.currency="GBP";}'
		. 'if(p.what){g.items=[{item_name:p.what}];}'
		. 'if(p.id){g.transaction_id=p.id;}'
		. 'gtag("event",name==="Purchase"?"purchase":"generate_lead",g);}'
		. '}catch(e){}};'
		. '</script>' . "\n";
}, 5 );

/**
 * A booking that has just this moment been paid for.
 *
 * Called from the two places that mark a booking paid, which both run on
 * the page the buyer lands on coming back from Stripe -- the only moment
 * where the browser is present and the payment is known to be real. They
 * each run once per booking, so a buyer who reloads is not counted twice.
 *
 * The amount is what Stripe actually took, not the price printed on the
 * page, so printing, postage or a discount are all counted correctly.
 *
 * @param int $booking The booking.
 */
function ew_track_purchase( $booking ) {
	$booking = (int) $booking;
	$pence   = (int) get_post_meta( $booking, '_ewt_paid', true );
	if ( ! $pence ) {
		$pence = (int) get_post_meta( $booking, '_ewt_total', true );
	}
	if ( ! $pence ) {
		return;
	}
	ew_track_fire( 'Purchase', array(
		'value' => round( $pence / 100, 2 ),
		'what'  => trim( (string) get_post_meta( $booking, '_ewt_event', true ) ),
		'id'    => 'b' . $booking,
	) );
}

/**
 * Somebody signed up for something free: a trial, a school ticket, a
 * half-time place, a community ticket. No money moved and it is still the
 * thing the club most wants to know an advert produced.
 *
 * The free sign-ups are told in the browser, at the moment the form says
 * yes, so there is nothing to call here for those. This is for anything
 * raised from PHP.
 *
 * @param string $what What they signed up to.
 */
function ew_track_lead( $what ) {
	ew_track_fire( 'Lead', array( 'what' => (string) $what ) );
}

/**
 * Put one event in the page footer.
 *
 * The footer, because the pixels and the Google tag have to have loaded
 * first, and because a shortcode has usually already rendered by the time
 * we know what happened.
 *
 * @param string $name   Purchase or Lead.
 * @param array  $params value, what, id.
 */
function ew_track_fire( $name, $params ) {
	add_action( 'wp_footer', function () use ( $name, $params ) {
		echo '<script>if(window.ewTrack)ewTrack(' . wp_json_encode( $name ) . ','
			. wp_json_encode( array_filter( $params ) ) . ');</script>' . "\n";
	}, 99 );
}

/* ------------------------------------------------------------------ *
 * The setting.
 * ------------------------------------------------------------------ */

add_action( 'ew_fwp_after_settings', function () {
	if ( isset( $_POST['ew_tr_save'] ) && check_admin_referer( 'ew_tr_save' ) ) {
		update_option( 'ew_ga4_id', sanitize_text_field( wp_unslash( (string) ( $_POST['ew_ga4_id'] ?? '' ) ) ) );
		echo '<div class="notice notice-success"><p>Google Analytics setting saved.</p></div>';
	}

	$ga = ew_ga4_id();
	echo '<h2>Google Analytics</h2>';
	echo '<p>The measurement ID for this site, which looks like G-XXXXXXXXXX. Leave it empty and no Google tag goes on the site at all. '
		. 'Either way the website tells both Facebook pixels and Google when a shirt or a ticket is paid for and when somebody registers: '
		. 'the two pixels live in the theme and are not changed from here.</p>';
	echo '<form method="post">';
	wp_nonce_field( 'ew_tr_save' );
	echo '<input type="hidden" name="ew_tr_save" value="1">';
	echo '<p><input type="text" name="ew_ga4_id" size="30" placeholder="G-XXXXXXXXXX" value="'
		. esc_attr( (string) get_option( 'ew_ga4_id', '' ) ) . '"></p>';
	echo '<p>' . ( '' !== $ga
		? 'Sending to ' . esc_html( $ga ) . '.'
		: 'Nothing is being sent to Google Analytics.' ) . '</p>';
	submit_button( 'Save Google Analytics' );
	echo '</form>';
}, 40 );
