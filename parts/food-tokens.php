<?php
/**
 * Food tokens: pay for food before an event, show a pass at the counter.
 *
 * eastwoodfc.com/foodtoken (and /foodtokens, which sends people there).
 *
 * The events, and the deals each one sells, are a list the club keeps on
 * the Food tokens screen in wp-admin -- not words written into a page -- so
 * a new event, a new deal or a changed price is a form, not a build:
 *
 *   Scott Loach Goalie Camp · 2026-11-14 · 9am - 3pm
 *   Hot dog deal | 5 | Hot dog; A drink; Crisps
 *   Snack token | 2.50 | Any snack from the counter
 *
 * Selling is the event-ticket checkout, not a copy of it. The page draws
 * the chosen event's deals through [eastwood_tickets] -- the same cards,
 * basket bar, email-then-card step, Booking and CRM call -- and the
 * checkout asks this file for the prices through the ew_tix_block filter,
 * so a price is only ever read from the list above, on the server.
 *
 * What is new here is only what tickets do not have:
 *   - the pass: a QR code, the short code, how many of each token are left,
 *     and the ways to send it on to a child;
 *   - staff use: a logged-in member of staff who opens the pass (scanning
 *     the QR with a phone camera does that) gets a "Use one" button per
 *     token, and only on the day of the event;
 *   - the email with the pass in it.
 *
 * Every word the page shows is a field on the [eastwood_food_tokens] block.
 * The email's words are settings on the Food tokens screen.
 *
 * @package eastwood-fwp
 */

defined( 'ABSPATH' ) || exit;

const EW_FOOD_OPT = 'ew_food_events';

/* --- The list of events ------------------------------------------------- */

/** Every event on the Food tokens screen, as saved. */
function ew_food_events_all() {
	$v = get_option( EW_FOOD_OPT, array() );
	return is_array( $v ) ? array_values( $v ) : array();
}

/** One event by its id, or null. */
function ew_food_event( $id ) {
	$id = sanitize_key( (string) $id );
	if ( '' === $id ) {
		return null;
	}
	foreach ( ew_food_events_all() as $e ) {
		if ( (string) ( $e['id'] ?? '' ) === $id ) {
			return $e;
		}
	}
	return null;
}

/** Events on sale and not yet past, soonest first. */
function ew_food_upcoming() {
	$today = current_time( 'Y-m-d' );
	$out   = array();
	foreach ( ew_food_events_all() as $e ) {
		if ( empty( $e['on'] ) || '' === (string) ( $e['date'] ?? '' ) || (string) $e['date'] < $today ) {
			continue;
		}
		$out[] = $e;
	}
	usort( $out, function ( $a, $b ) {
		return strcmp( (string) $a['date'], (string) $b['date'] );
	} );
	return $out;
}

/** A Y-m-d date as the club writes it. Noon, so no timezone moves the day. */
function ew_food_day( $ymd, $fmt = 'l j F' ) {
	$ts = strtotime( (string) $ymd . ' 12:00:00' );
	return $ts ? date_i18n( $fmt, $ts ) : '';
}

/**
 * The deals as [eastwood_tickets] lines: name | price | | included.
 *
 * Written on the screen as "name | price | what is in it", which is the
 * same thing with the tag column left out, so the ticket parser reads them
 * and a price is handled exactly as a ticket price is.
 */
function ew_food_lines( $e ) {
	$out = array();
	foreach ( ew_hb_lines( (string) ( $e['deals'] ?? '' ) ) as $line ) {
		$b = array_map( 'trim', explode( '|', $line ) );
		if ( '' === (string) ( $b[0] ?? '' ) ) {
			continue;
		}
		$out[] = $b[0] . ' | ' . (string) ( $b[1] ?? '' ) . ' | | ' . (string) ( $b[2] ?? '' );
	}
	return implode( "\n", $out );
}

/** What the booking and Stripe call this event: name and day. */
function ew_food_label( $e ) {
	return trim( (string) ( $e['name'] ?? '' ) . ' · ' . ew_food_day( $e['date'] ?? '', 'D j M' ), ' ·' );
}

/* --- The block on a page ------------------------------------------------ */

/** The [eastwood_food_tokens] block's fields on a page. */
function ew_food_block_on( $post ) {
	if ( ! $post ) {
		return null;
	}
	$found = null;
	$data  = json_decode( (string) get_post_meta( $post->ID, '_elementor_data', true ), true );
	if ( is_array( $data ) ) {
		$walk = function ( $nodes ) use ( &$walk, &$found ) {
			foreach ( (array) $nodes as $n ) {
				if ( $found ) {
					return;
				}
				$sc = (string) ( $n['settings']['shortcode'] ?? '' );
				if ( 0 === strpos( ltrim( $sc ), '[eastwood_food_tokens' ) ) {
					$found = ew_blocks_parse( $sc );
					return;
				}
				if ( ! empty( $n['elements'] ) ) {
					$walk( $n['elements'] );
				}
			}
		};
		$walk( $data );
	}
	if ( ! $found && preg_match( '/\[eastwood_food_tokens[^\]]*\]/', (string) $post->post_content, $m ) ) {
		$found = ew_blocks_parse( $m[0] );
	}
	return $found;
}

/**
 * The checkout asks for the block on a page; on the food tokens page it
 * gets the chosen event's deals in the tickets block's own shape.
 */
add_filter( 'ew_tix_block', function ( $found, $post, $ev ) {
	if ( $found || '' === $ev || ! ew_post_contains( $post, 'eastwood_food_tokens' ) ) {
		return $found;
	}
	$e = ew_food_event( $ev );
	if ( ! $e || empty( $e['on'] ) || (string) $e['date'] < current_time( 'Y-m-d' ) ) {
		return null;
	}
	$block = ew_food_block_on( $post );
	$f     = shortcode_atts( ew_food_defaults(), (array) ( $block['fields'] ?? array() ) );
	return array(
		'block'  => 'eastwood_tickets',
		'fields' => array(
			'event'    => ew_food_label( $e ),
			'kind'     => 'food',
			'ev'       => (string) $e['id'],
			'need'     => $f['need'],
			'child_label' => $f['child_label'],
			'child_need'  => $f['child_need'],
			'crm_hook' => $f['crm_hook'],
			'greeting' => $f['greeting'],
		),
		'lines'  => ew_food_lines( $e ),
	);
}, 10, 3 );

/** Every word the block shows, and what it says until somebody changes it. */
function ew_food_defaults() {
	return array(
		'step1'       => '1 · Pick your event',
		'step2'       => '2 · Pick your tokens',
		'for_line'    => 'For {event} · use on {date} only',
		'none'        => 'There are no events with food tokens on sale right now.',
		'no_deals'    => 'Tokens for this event are not on sale yet.',
		'note'        => "Tokens only work on {date} and aren't refundable.",
		'each'        => 'each',
		'checkout'    => 'Checkout',
		'co_title'    => 'Your order',
		'child_label' => "Your child's name · it goes on the pass",
		'child_need'  => "Please add your child's name.",
		'email_label' => 'Your email · we send your token pass here',
		'continue'    => 'Continue to payment',
		'need'        => 'Please add your email.',
		'loading'     => 'Loading the payment form…',
		'off'         => 'Card payments are not switched on right now.',
		'crm_hook'    => '',
		'greeting'    => '',
		// The pass.
		'paid_kicker' => 'Paid · thank you',
		'paid_title'  => "You're all set",
		'paid_lede'   => "Your token pass is below and we've emailed it to {email}. Show it at the food counter on the day.",
		'pass_kicker' => 'Food tokens',
		'pass_title'  => 'Your food tokens',
		'pass_lede'   => 'Show this pass at the food counter on the day.',
		'only'        => '{event} · {date} only',
		'left'        => '{n} left',
		'pass_label'  => 'Food token pass',
		'pass_for'    => 'For {child}',
		'code_note'   => "If it won't scan, staff can type this code.",
		'send_head'   => 'Send it to your child',
		'share'       => 'Share',
		'whatsapp'    => 'WhatsApp',
		'copy'        => 'Copy link',
		'copied'      => 'Link copied',
		'share_note'  => "Share opens your phone's own list: Messages, AirDrop, WhatsApp and the rest.",
		'share_text'  => 'Your food tokens for {event}. Show this at the food counter:',
		'app'         => 'Add to the Eastwood FC app · coming soon',
		'here_head'   => "While you're here",
		'doc_title'   => 'This Is Eastwood',
		'doc_text'    => "The club's own documentary. Watch the story so far. Latest: {episode}.",
		'doc_button'  => 'Watch the documentary',
		'doc_series'  => 'This Is Eastwood',
		'doc_url'     => '/eastwood-tv/',
		'bar_title'   => 'Pitchside Bar & Lounge',
		'bar_text'    => 'Stay at the ground while you are here. Drinks, food and a seat looking out on the pitch.',
		'bar_img'     => '/wp-content/uploads/2026/10/events-bar.jpeg',
		'bar_url'     => '/hospitality/pitchside-bar/',
		'bad_pass'    => 'We could not find that pass. Check the link in your email.',
		'waiting'     => 'Your payment is still going through. Your pass will be emailed to you in a few minutes.',
		// Staff, shown only to somebody logged in who can edit posts.
		'staff_head'  => 'Staff',
		'use'         => 'Use one',
		'all_used'    => 'All used',
		'wrong_day'   => 'This pass is for {date}. Tokens only work on that day.',
		'used_head'   => 'Used so far',
		'used_none'   => 'Nothing used yet.',
	);
}

/** {event} {date} {email} {n} {episode} filled in. */
function ew_food_say( $text, $map ) {
	$out = (string) $text;
	foreach ( $map as $k => $v ) {
		$out = str_replace( '{' . $k . '}', (string) $v, $out );
	}
	return $out;
}

function ew_food_url( $u ) {
	$u = trim( (string) $u );
	return ( '' === $u || preg_match( '#^https?://#', $u ) ) ? $u : home_url( '/' . ltrim( $u, '/' ) );
}

/* --- The page ----------------------------------------------------------- */

function ew_food_shortcode( $atts ) {
	$a    = shortcode_atts( ew_food_defaults(), $atts, 'eastwood_food_tokens' );
	$page = (int) get_the_ID();

	$h = '<section class="ewft" id="tokens">';

	// A pass, opened from the email, a shared link or a QR scan.
	$key = isset( $_GET['pass'] ) ? sanitize_text_field( wp_unslash( $_GET['pass'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	if ( '' !== $key ) {
		$bid = ew_food_find( $key );
		$h  .= $bid ? ew_food_pass_html( $bid, $a, false ) : '<p class="ewft-bad">' . esc_html( $a['bad_pass'] ) . '</p>';
		return $h . '</section>';
	}

	// Back from paying.
	$sid = isset( $_GET['ewt_paid'] ) ? sanitize_text_field( wp_unslash( $_GET['ewt_paid'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	if ( '' !== $sid ) {
		$bid = ew_food_from_session( $sid, $page, $a );
		if ( $bid && 'paid' === get_post_meta( $bid, '_ewt_status', true ) ) {
			return $h . ew_food_pass_html( $bid, $a, true ) . '</section>';
		}
		if ( $bid ) {
			$h .= '<p class="ewft-bad">' . esc_html( $a['waiting'] ) . '</p>';
		}
	}

	$events = ew_food_upcoming();
	if ( ! $events ) {
		return $h . '<p class="ewft-none">' . esc_html( $a['none'] ) . '</p></section>';
	}

	$want   = isset( $_GET['ev'] ) ? sanitize_key( wp_unslash( $_GET['ev'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$chosen = $events[0];
	foreach ( $events as $e ) {
		if ( (string) $e['id'] === $want ) {
			$chosen = $e;
		}
	}

	$h .= '<h2 class="ewft-step">' . esc_html( $a['step1'] ) . '</h2><ul class="ewft-events">';
	foreach ( $events as $e ) {
		$on    = (string) $e['id'] === (string) $chosen['id'];
		$when  = ew_food_day( $e['date'], 'l j F' ) . ( '' !== trim( (string) ( $e['times'] ?? '' ) ) ? ' · ' . trim( (string) $e['times'] ) : '' );
		$h    .= '<li><a class="ewft-ev' . ( $on ? ' is-on' : '' ) . '"' . ( $on ? ' aria-current="true"' : '' )
			. ' href="' . esc_url( add_query_arg( 'ev', $e['id'], get_permalink() ) . '#tokens' ) . '">'
			. '<span class="ewft-dot" aria-hidden="true"></span>'
			. '<span class="ewft-evText"><b>' . esc_html( (string) $e['name'] ) . '</b><i>' . esc_html( $when ) . '</i></span></a></li>';
	}
	$h .= '</ul>';

	$lines = ew_food_lines( $chosen );
	$map   = array(
		'event' => (string) $chosen['name'],
		'date'  => ew_food_day( $chosen['date'], 'l j F' ),
	);
	$h .= '<h2 class="ewft-step">' . esc_html( $a['step2'] ) . '</h2>';
	if ( '' === $lines || ! function_exists( 'ew_tix_shortcode' ) ) {
		return $h . '<p class="ewft-none">' . esc_html( $a['no_deals'] ) . '</p></section>';
	}

	$h .= ew_tix_shortcode(
		array(
			'event'       => ew_food_label( $chosen ),
			'kicker'      => '',
			'title'       => '',
			'sub'         => ew_food_say( $a['for_line'], $map ),
			'note'        => ew_food_say( $a['note'], $map ),
			'each'        => $a['each'],
			'checkout'    => $a['checkout'],
			'co_title'    => $a['co_title'],
			'child_label' => $a['child_label'],
			'child_need'  => $a['child_need'],
			'email_label' => $a['email_label'],
			'continue'    => $a['continue'],
			'need'        => $a['need'],
			'loading'     => $a['loading'],
			'off'         => $a['off'],
			'crm_hook'    => $a['crm_hook'],
			'greeting'    => $a['greeting'],
			'ev'          => (string) $chosen['id'],
		),
		$lines
	);

	return $h . '</section>';
}
add_shortcode( 'eastwood_food_tokens', 'ew_food_shortcode' );

/* --- Bookings ----------------------------------------------------------- */

/** When the checkout saves a food booking: what it is and what was bought. */
add_action( 'ew_tix_started', function ( $bid, $block, $ev, $req ) {
	if ( 'food' !== (string) ( $block['fields']['kind'] ?? '' ) ) {
		return;
	}
	$e = ew_food_event( $ev );
	if ( ! $e ) {
		return;
	}
	$deals  = function_exists( 'ew_tix_lines' ) ? ew_tix_lines( (string) $block['lines'] ) : array();
	$tokens = array();
	foreach ( (array) $req->get_param( 'items' ) as $i => $qty ) {
		$i   = (int) $i;
		$qty = max( 0, min( 50, (int) $qty ) );
		if ( $qty && ! empty( $deals[ $i ] ) && $deals[ $i ]['pence'] > 0 ) {
			$tokens[] = array( 'name' => $deals[ $i ]['name'], 'n' => $qty, 'used' => 0 );
		}
	}
	update_post_meta( $bid, '_ewt_kind', 'food' );
	update_post_meta( $bid, '_ewt_fev', (string) $e['id'] );
	update_post_meta( $bid, '_ewt_fname', (string) $e['name'] );
	update_post_meta( $bid, '_ewt_fday', (string) $e['date'] );
	// Slashed, because update_post_meta() unslashes and a JSON escape is a backslash.
	update_post_meta( $bid, '_ewt_tokens', wp_slash( wp_json_encode( $tokens, JSON_UNESCAPED_UNICODE ) ) );
	// Somebody who pays and closes the tab still gets a pass and an email.
	wp_schedule_single_event( time() + 10 * MINUTE_IN_SECONDS, 'ew_food_settle', array( (int) $bid ) );
	wp_schedule_single_event( time() + 45 * MINUTE_IN_SECONDS, 'ew_food_settle', array( (int) $bid ) );
}, 10, 4 );

/** Ask Stripe whether a started food booking has been paid, and settle it. */
add_action( 'ew_food_settle', function ( $bid ) {
	$bid = (int) $bid;
	if ( 'food' !== get_post_meta( $bid, '_ewt_kind', true ) || 'started' !== get_post_meta( $bid, '_ewt_status', true ) ) {
		return;
	}
	$sid = (string) get_post_meta( $bid, '_ewt_open_session', true );
	if ( '' === $sid || ! function_exists( 'ew_tix_stripe' ) || '' === ew_tix_sk() ) {
		return;
	}
	$s = ew_tix_stripe( 'GET', 'checkout/sessions/' . rawurlencode( $sid ) );
	if ( ! empty( $s['id'] ) ) {
		ew_tix_mark_paid( $bid, $s, (string) get_post_meta( $bid, '_ewt_hook', true ) );
	}
} );

/** Back from Stripe on the page: the booking, marked paid if it is. */
function ew_food_from_session( $sid, $page, $a ) {
	if ( ! preg_match( '/^cs_[A-Za-z0-9_]+$/', $sid ) || ! function_exists( 'ew_tix_stripe' ) || '' === ew_tix_sk() ) {
		return 0;
	}
	$s = ew_tix_stripe( 'GET', 'checkout/sessions/' . rawurlencode( $sid ) );
	if ( empty( $s['id'] ) || (string) ( $s['metadata']['page'] ?? '' ) !== (string) (int) $page || 'food' !== ( $s['metadata']['kind'] ?? '' ) ) {
		return 0;
	}
	$bid = ew_tix_booking_for( $s );
	if ( $bid ) {
		ew_tix_mark_paid( $bid, $s, $a['crm_hook'] );
	}
	return $bid;
}

/** The moment a food booking is paid: its code, its key, and the email. */
add_action( 'ew_tix_paid', function ( $bid ) {
	if ( 'food' !== get_post_meta( $bid, '_ewt_kind', true ) ) {
		return;
	}
	ew_food_ready( $bid );
	ew_food_mail( $bid );
} );

function ew_food_ready( $bid ) {
	if ( '' !== (string) get_post_meta( $bid, '_ewt_key', true ) ) {
		return;
	}
	do {
		$code = 'EFC-' . wp_rand( 1000, 9999 );
	} while ( get_posts( array( 'post_type' => 'ewt_booking', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_ewt_code', 'meta_value' => $code ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	update_post_meta( $bid, '_ewt_code', $code );
	update_post_meta( $bid, '_ewt_key', strtolower( wp_generate_password( 24, false, false ) ) );
}

/**
 * A pass by its key -- or, for staff only, by its short code. A four-digit
 * code is easy to guess, so it never opens a pass for the public.
 */
function ew_food_find( $ref ) {
	$ref  = trim( (string) $ref );
	$keys = array( '_ewt_key' );
	if ( current_user_can( 'edit_posts' ) && preg_match( '/^EFC-\d{4}$/i', $ref ) ) {
		$keys[] = '_ewt_code';
		$ref    = strtoupper( $ref );
	}
	if ( '' === $ref ) {
		return 0;
	}
	foreach ( $keys as $meta ) {
		$ids = get_posts( array( 'post_type' => 'ewt_booking', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => $meta, 'meta_value' => $ref ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( $ids && 'food' === get_post_meta( $ids[0], '_ewt_kind', true ) && 'paid' === get_post_meta( $ids[0], '_ewt_status', true ) ) {
			return (int) $ids[0];
		}
	}
	return 0;
}

/** The tokens on a pass, with what has been used. */
function ew_food_tokens( $bid ) {
	$t = json_decode( (string) get_post_meta( $bid, '_ewt_tokens', true ), true );
	return is_array( $t ) ? $t : array();
}

/** The pass's own address: the page it was bought on, with its key. */
function ew_food_pass_url( $bid ) {
	$page = (int) get_post_meta( $bid, '_ewt_page', true );
	$base = $page ? get_permalink( $page ) : home_url( '/foodtoken/' );
	return add_query_arg( 'pass', (string) get_post_meta( $bid, '_ewt_key', true ), $base );
}

/** The event a pass is for: today's version if it is still listed. */
function ew_food_pass_event( $bid ) {
	$e = ew_food_event( (string) get_post_meta( $bid, '_ewt_fev', true ) );
	return array(
		'name'   => $e ? (string) $e['name'] : (string) get_post_meta( $bid, '_ewt_fname', true ),
		'date'   => $e ? (string) $e['date'] : (string) get_post_meta( $bid, '_ewt_fday', true ),
		'lounge' => $e ? trim( (string) ( $e['lounge'] ?? '' ) ) : '',
	);
}

/* --- The pass ----------------------------------------------------------- */

function ew_food_pass_html( $bid, $a, $just_paid ) {
	ew_food_ready( $bid );
	$e      = ew_food_pass_event( $bid );
	$map    = array(
		'event' => $e['name'],
		'date'  => ew_food_day( $e['date'], 'l j F' ),
		'email' => (string) get_post_meta( $bid, '_ewt_email', true ),
	);
	$url    = ew_food_pass_url( $bid );
	$code   = (string) get_post_meta( $bid, '_ewt_code', true );
	$tokens = ew_food_tokens( $bid );
	$child  = trim( (string) get_post_meta( $bid, '_ewt_child', true ) );
	$staff  = current_user_can( 'edit_posts' );
	$today  = current_time( 'Y-m-d' ) === $e['date'];

	$h  = '<div class="ewft-pass" data-pass="' . esc_attr( (string) get_post_meta( $bid, '_ewt_key', true ) ) . '"'
		. ' data-url="' . esc_url( $url ) . '"'
		. ' data-text="' . esc_attr( ew_food_say( $a['share_text'], $map ) ) . '"'
		. ' data-copied="' . esc_attr( $a['copied'] ) . '"'
		. ( $staff ? ' data-rest="' . esc_url( rest_url( 'eastwood/v1/food/use' ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '"'
			. ' data-left="' . esc_attr( $a['left'] ) . '" data-done="' . esc_attr( $a['all_used'] ) . '"' : '' )
		. '>';

	$h .= '<div class="ewft-passTop"><div class="ewft-passWords">';
	$h .= '<p class="ewft-kick">' . esc_html( $just_paid ? $a['paid_kicker'] : $a['pass_kicker'] ) . '</p>';
	$h .= '<h2 class="ewft-big">' . esc_html( $just_paid ? $a['paid_title'] : $a['pass_title'] ) . '</h2>';
	$h .= '<p class="ewft-lede">' . esc_html( ew_food_say( $just_paid ? $a['paid_lede'] : $a['pass_lede'], $map ) ) . '</p>';

	$h .= '<div class="ewft-have">';
	foreach ( $tokens as $i => $t ) {
		$left = max( 0, (int) $t['n'] - (int) $t['used'] );
		$h   .= '<div class="ewft-tok" data-i="' . (int) $i . '"><span>' . esc_html( (string) $t['name'] ) . '</span>'
			. '<b data-left-n>' . esc_html( ew_food_say( $a['left'], array( 'n' => $left ) ) ) . '</b>';
		if ( $staff ) {
			$h .= '<button type="button" class="ewft-use" data-i="' . (int) $i . '"' . ( $left && $today ? '' : ' disabled' ) . '>'
				. esc_html( $left ? $a['use'] : $a['all_used'] ) . '</button>';
		}
		$h .= '</div>';
	}
	$h .= '<p class="ewft-only">' . esc_html( ew_food_say( $a['only'], $map ) ) . '</p>';
	$h .= '</div>';

	if ( $staff ) {
		$h .= '<div class="ewft-staff"><p class="ewft-staffHead">' . esc_html( $a['staff_head'] ) . ' · '
			. esc_html( '' !== $child ? $child : (string) get_post_meta( $bid, '_ewt_name', true ) ) . ' · ' . esc_html( $code ) . '</p>';
		if ( ! $today ) {
			$h .= '<p class="ewft-wrong">' . esc_html( ew_food_say( $a['wrong_day'], $map ) ) . '</p>';
		}
		$log = json_decode( (string) get_post_meta( $bid, '_ewt_tlog', true ), true );
		$h  .= '<p class="ewft-staffSub">' . esc_html( $a['used_head'] ) . '</p><ul class="ewft-log" data-none="' . esc_attr( $a['used_none'] ) . '">';
		if ( is_array( $log ) && $log ) {
			foreach ( $log as $row ) {
				$h .= '<li>' . esc_html( (string) $row ) . '</li>';
			}
		} else {
			$h .= '<li>' . esc_html( $a['used_none'] ) . '</li>';
		}
		$h .= '</ul><p class="ewft-staffMsg" role="status"></p></div>';
	}
	$h .= '</div>';

	// The pass itself.
	$h .= '<div class="ewft-card">';
	$h .= '<p class="ewft-cardLab">' . esc_html( $a['pass_label'] ) . '</p>';
	if ( '' !== $child && '' !== $a['pass_for'] ) {
		$h .= '<p class="ewft-for">' . esc_html( ew_food_say( $a['pass_for'], array( 'child' => $child ) ) ) . '</p>';
	}
	$h .= '<div class="ewft-qr" data-qr="' . esc_url( $url ) . '" role="img" aria-label="QR code"></div>';
	$h .= '<p class="ewft-code">' . esc_html( $code ) . '</p>';
	$h .= '<p class="ewft-codeNote">' . esc_html( $a['code_note'] ) . '</p>';
	$h .= '<div class="ewft-send"><p class="ewft-sendHead">' . esc_html( $a['send_head'] ) . '</p><div class="ewft-sendBtns">';
	$h .= '<button type="button" data-share>' . ew_food_icon( 'share' ) . esc_html( $a['share'] ) . '</button>';
	$h .= '<a href="' . esc_url( 'https://wa.me/?text=' . rawurlencode( ew_food_say( $a['share_text'], $map ) . ' ' . $url ) ) . '" target="_blank" rel="noopener">' . ew_food_icon( 'chat' ) . esc_html( $a['whatsapp'] ) . '</a>';
	$h .= '<button type="button" data-copy>' . ew_food_icon( 'copy' ) . '<span>' . esc_html( $a['copy'] ) . '</span></button>';
	$h .= '</div><p class="ewft-sendNote">' . esc_html( $a['share_note'] ) . '</p>';
	if ( '' !== $a['app'] ) {
		$h .= '<button type="button" class="ewft-app" disabled>' . ew_food_icon( 'phone' ) . esc_html( $a['app'] ) . '</button>';
	}
	$h .= '</div></div>';

	$h .= '</div>';

	$h .= ew_food_here( $a, $e );
	return $h . '</div>';
}

/** "While you're here": the latest episode and the Pitchside Bar & Lounge. */
function ew_food_here( $a, $e ) {
	$cards = '';

	if ( '' !== $a['doc_title'] ) {
		$ep = null;
		if ( function_exists( 'ew_yt_videos' ) && '' !== trim( $a['doc_series'] ) ) {
			foreach ( ew_yt_videos( '', 40 ) as $clip ) {
				if ( empty( $clip['short'] ) && false !== stripos( $clip['title'], $a['doc_series'] ) ) {
					$ep = $clip;
					break;
				}
			}
		}
		$name = '';
		if ( $ep && function_exists( 'ew_yt_label' ) ) {
			$read = ew_yt_label( $ep['title'], array( $a['doc_series'] => '' ), true );
			$name = str_replace( ' | ', ', ', $read['title'] );
		}
		$text = '' !== $name ? ew_food_say( $a['doc_text'], array( 'episode' => $name ) ) : trim( preg_replace( '/\s*Latest:.*$/', '', $a['doc_text'] ) );
		$img  = $ep ? 'https://i.ytimg.com/vi/' . rawurlencode( $ep['id'] ) . '/maxresdefault.jpg' : '';
		$cards .= '<div class="ewft-here">'
			. ( '' !== $img ? '<img src="' . esc_url( $img ) . '" alt="" loading="lazy" onerror="this.onerror=null;this.src=\'' . esc_url( $ep['img'] ) . '\'">' : '' )
			. '<div><h3>' . esc_html( $a['doc_title'] ) . '</h3><p>' . esc_html( $text ) . '</p>'
			. '<a class="ewft-btn" href="' . esc_url( $ep ? $ep['url'] : ew_food_url( $a['doc_url'] ) ) . '"' . ( $ep ? ' target="_blank" rel="noopener"' : '' ) . '>' . esc_html( $a['doc_button'] ) . '</a></div></div>';
	}

	if ( '' !== $a['bar_title'] ) {
		$cards .= '<div class="ewft-here">'
			. ( '' !== $a['bar_img'] ? '<img src="' . esc_url( ew_food_url( $a['bar_img'] ) ) . '" alt="" loading="lazy">' : '' )
			. '<div><h3>' . esc_html( $a['bar_title'] ) . '</h3><p>' . esc_html( $a['bar_text'] ) . '</p>'
			. ( '' !== $e['lounge'] ? '<p class="ewft-open">' . esc_html( $e['lounge'] ) . '</p>' : '' )
			. ( '' !== $a['bar_url'] ? '<a class="ewft-link" href="' . esc_url( ew_food_url( $a['bar_url'] ) ) . '">' . esc_html( $a['bar_title'] ) . ' &rarr;</a>' : '' )
			. '</div></div>';
	}

	if ( '' === $cards ) {
		return '';
	}
	return '<h2 class="ewft-hereHead">' . esc_html( $a['here_head'] ) . '</h2><div class="ewft-hereGrid">' . $cards . '</div>';
}

function ew_food_icon( $which ) {
	$p = array(
		'share' => '<path d="M12 3v12"/><path d="M7 8l5-5 5 5"/><path d="M5 13v7h14v-7"/>',
		'chat'  => '<path d="M4 20l1.5-4.2A8 8 0 1 1 8.2 18.5z"/>',
		'copy'  => '<rect x="8" y="8" width="12" height="12"/><path d="M16 8V4H4v12h4"/>',
		'phone' => '<rect x="7" y="3" width="10" height="18" rx="2"/><path d="M11 18h2"/>',
	);
	return '<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . ( $p[ $which ] ?? '' ) . '</svg>';
}

/* --- Staff: use a token ------------------------------------------------- */

/**
 * Take one of token $i off pass $bid. The one place a token is used, for
 * the pass page's "Use one" button and the app's scanner alike.
 */
function ew_food_use( $bid, $i, $who ) {
	if ( ! $bid ) {
		return new WP_Error( 'ewft_pass', 'No such pass.', array( 'status' => 404 ) );
	}
	$e = ew_food_pass_event( $bid );
	if ( current_time( 'Y-m-d' ) !== $e['date'] ) {
		return new WP_Error( 'ewft_day', 'This pass is for ' . ew_food_day( $e['date'], 'l j F' ) . '.', array( 'status' => 409 ) );
	}
	$tokens = ew_food_tokens( $bid );
	if ( empty( $tokens[ $i ] ) ) {
		return new WP_Error( 'ewft_tok', 'No such token.', array( 'status' => 400 ) );
	}
	if ( (int) $tokens[ $i ]['used'] >= (int) $tokens[ $i ]['n'] ) {
		return new WP_Error( 'ewft_none', 'All used.', array( 'status' => 409 ) );
	}
	$tokens[ $i ]['used'] = (int) $tokens[ $i ]['used'] + 1;
	update_post_meta( $bid, '_ewt_tokens', wp_slash( wp_json_encode( $tokens, JSON_UNESCAPED_UNICODE ) ) );

	$log   = json_decode( (string) get_post_meta( $bid, '_ewt_tlog', true ), true );
	$log   = is_array( $log ) ? $log : array();
	$log[] = current_time( 'H:i' ) . ' · ' . $tokens[ $i ]['name'] . ' · ' . $who;
	update_post_meta( $bid, '_ewt_tlog', wp_slash( wp_json_encode( $log, JSON_UNESCAPED_UNICODE ) ) );

	return array(
		'ok'     => true,
		'left'   => max( 0, (int) $tokens[ $i ]['n'] - (int) $tokens[ $i ]['used'] ),
		'log'    => $log,
		'tokens' => $tokens,
	);
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'eastwood/v1', '/food/use', array(
		'methods'             => 'POST',
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'callback'            => function ( $req ) {
			$who = wp_get_current_user();
			return ew_food_use( ew_food_find( (string) $req->get_param( 'pass' ) ), (int) $req->get_param( 'i' ), $who && $who->exists() ? $who->display_name : '' );
		},
	) );
} );

/* --- The email ---------------------------------------------------------- */

function ew_food_mail_defaults() {
	return array(
		'subject' => 'Your food tokens for {event}',
		'button'  => 'Open your pass',
		'lounge'  => 'Pitchside Bar & Lounge: {lounge}',
		'body'    => "Hi {first_name},\n\nAbove is your food token pass for {event} on {date}. Show it at the food counter on the day. Staff will scan it and take off what you use.\n\nYou can use your tokens together or one at a time, on {date} only. They aren't refundable.\n\nYour receipt comes separately from Stripe. Any questions, just reply to this email.\n\nEastwood FC",
	);
}

function ew_food_mail_text( $part ) {
	$v = get_option( 'ew_food_mail_' . $part, null );
	return null === $v ? ew_food_mail_defaults()[ $part ] : (string) $v;
}

/** A QR code as an HTML table, which every mail client draws. */
function ew_food_qr_table( $text, $cell = 5 ) {
	if ( ! class_exists( 'EwQrCode' ) ) {
		require_once dirname( __DIR__ ) . '/lib/qrcode.php';
	}
	$q = EwQrCode::getMinimumQRCode( (string) $text, EWQR_ERROR_CORRECT_LEVEL_M );
	$n = $q->getModuleCount();
	$h = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin:0 auto;background:#ffffff">';
	for ( $r = -2; $r < $n + 2; $r++ ) {
		$h .= '<tr>';
		for ( $c = -2; $c < $n + 2; $c++ ) {
			$dark = $r >= 0 && $c >= 0 && $r < $n && $c < $n && $q->isDark( $r, $c );
			$h   .= '<td width="' . $cell . '" height="' . $cell . '" style="width:' . $cell . 'px;height:' . $cell . 'px;padding:0;font-size:0;line-height:0;background:' . ( $dark ? '#000000' : '#ffffff' ) . '"></td>';
		}
		$h .= '</tr>';
	}
	return $h . '</table>';
}

function ew_food_mail( $bid ) {
	if ( get_post_meta( $bid, '_ewt_mailed_food', true ) ) {
		return;
	}
	$to = (string) get_post_meta( $bid, '_ewt_email', true );
	if ( ! is_email( $to ) ) {
		return;
	}
	ew_food_ready( $bid );
	$e     = ew_food_pass_event( $bid );
	$name  = trim( (string) get_post_meta( $bid, '_ewt_name', true ) );
	$first = false === strpos( $name, ' ' ) ? $name : substr( $name, 0, strpos( $name, ' ' ) );
	$map   = array(
		'first_name' => $first,
		'event'      => $e['name'],
		'date'       => ew_food_day( $e['date'], 'l j F' ),
		'code'       => (string) get_post_meta( $bid, '_ewt_code', true ),
	);
	$url  = ew_food_pass_url( $bid );
	$body = preg_replace( '/ +,/', ',', ew_food_say( ew_food_mail_text( 'body' ), $map ) );

	$list = '';
	foreach ( ew_food_tokens( $bid ) as $t ) {
		$list .= '<tr><td style="padding:8px 0;border-top:1px solid #e2e2e2;font-size:16px">' . esc_html( (string) $t['name'] ) . '</td>'
			. '<td align="right" style="padding:8px 0;border-top:1px solid #e2e2e2;font-size:16px;font-weight:bold">' . (int) $t['n'] . '</td></tr>';
	}

	$html  = '<div style="font-family:Arial,Helvetica,sans-serif;color:#111111;max-width:520px;margin:0 auto">';
	$html .= '<div style="background:#0b0a09;color:#ffffff;padding:24px;text-align:center;margin:0 0 20px">';
	$html .= '<p style="margin:0 0 6px;font-size:13px;letter-spacing:2px;text-transform:uppercase;color:#ff4d4d">' . esc_html( $e['name'] . ' · ' . ew_food_day( $e['date'], 'D j M' ) ) . '</p>';
	$html .= '<div style="background:#ffffff;padding:12px;display:inline-block;margin:12px 0">' . ew_food_qr_table( $url ) . '</div>';
	$html .= '<p style="margin:6px 0 0;font-size:22px;letter-spacing:2px;font-weight:bold">' . esc_html( $map['code'] ) . '</p>';
	$html .= '<p style="margin:18px 0 0"><a href="' . esc_url( $url ) . '" style="display:inline-block;background:#cc0000;color:#ffffff;text-decoration:none;font-weight:bold;padding:12px 22px">' . esc_html( ew_food_say( ew_food_mail_text( 'button' ), $map ) ) . '</a></p>';
	$html .= '</div>';
	$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse">' . $list . '</table>';
	if ( '' !== $e['lounge'] ) {
		$html .= '<p style="font-size:14px;color:#55555c;margin-top:16px">' . esc_html( ew_food_say( ew_food_mail_text( 'lounge' ), array( 'lounge' => $e['lounge'] ) ) ) . '</p>';
	}
	$html .= '<p style="font-size:16px;line-height:1.5;margin-top:24px">' . nl2br( esc_html( $body ) ) . '</p>';
	$html .= '</div>';

	$ok = wp_mail( $to, ew_food_say( ew_food_mail_text( 'subject' ), $map ), $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
	update_post_meta( $bid, '_ewt_mailed_food', $ok ? time() : 'failed' );
}

/* --- The Food tokens screen --------------------------------------------- */

add_action( 'admin_menu', function () {
	add_menu_page( 'Food tokens', 'Food tokens', 'edit_pages', 'ew-food', 'ew_food_admin', 'dashicons-food', 27 );
} );

/** Passes sold for an event: [passes, tokens]. */
function ew_food_sold( $ev ) {
	$ids    = get_posts( array( 'post_type' => 'ewt_booking', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_ewt_fev', 'meta_value' => (string) $ev ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	$passes = 0;
	$toks   = 0;
	foreach ( $ids as $id ) {
		if ( 'paid' !== get_post_meta( $id, '_ewt_status', true ) ) {
			continue;
		}
		$passes++;
		foreach ( ew_food_tokens( $id ) as $t ) {
			$toks += (int) $t['n'];
		}
	}
	return array( $passes, $toks );
}

function ew_food_admin() {
	if ( ! current_user_can( 'edit_pages' ) ) {
		return;
	}
	if ( isset( $_POST['ew_food_save'] ) && check_admin_referer( 'ew_food_save' ) ) {
		$rows = isset( $_POST['ev'] ) && is_array( $_POST['ev'] ) ? wp_unslash( $_POST['ev'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$keep = array();
		foreach ( $rows as $r ) {
			$name = sanitize_text_field( (string) ( $r['name'] ?? '' ) );
			if ( ! empty( $r['del'] ) || '' === $name ) {
				continue;
			}
			$date = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $r['date'] ?? '' ) ) ? (string) $r['date'] : '';
			$id   = sanitize_key( (string) ( $r['id'] ?? '' ) );
			$keep[] = array(
				'id'     => '' !== $id ? $id : 'fe' . substr( md5( uniqid( '', true ) ), 0, 8 ),
				'name'   => $name,
				'date'   => $date,
				'times'  => sanitize_text_field( (string) ( $r['times'] ?? '' ) ),
				'deals'  => sanitize_textarea_field( (string) ( $r['deals'] ?? '' ) ),
				'lounge' => sanitize_text_field( (string) ( $r['lounge'] ?? '' ) ),
				'on'     => empty( $r['on'] ) ? '' : '1',
			);
		}
		update_option( EW_FOOD_OPT, $keep, false );
		update_option( 'ew_food_mail_subject', sanitize_text_field( wp_unslash( $_POST['ew_food_mail_subject'] ?? '' ) ) );
		update_option( 'ew_food_mail_body', sanitize_textarea_field( wp_unslash( $_POST['ew_food_mail_body'] ?? '' ) ) );
		update_option( 'ew_food_mail_button', sanitize_text_field( wp_unslash( $_POST['ew_food_mail_button'] ?? '' ) ) );
		update_option( 'ew_food_mail_lounge', sanitize_text_field( wp_unslash( $_POST['ew_food_mail_lounge'] ?? '' ) ) );
		echo '<div class="notice notice-success"><p>Food tokens saved.</p></div>';
	}

	$events   = ew_food_events_all();
	$events[] = array( 'id' => '', 'name' => '', 'date' => '', 'times' => '', 'deals' => '', 'lounge' => '', 'on' => '1', 'new' => true );
	$page     = get_page_by_path( 'foodtoken' );

	echo '<div class="wrap"><h1>Food tokens</h1>';
	echo '<p>Each event here appears on ' . ( $page ? '<a href="' . esc_url( get_permalink( $page ) ) . '" target="_blank">' . esc_html( get_permalink( $page ) ) . '</a>' : 'the food tokens page' )
		. ' while it is ticked on sale and its date has not passed. Tokens only work on the event&rsquo;s date.</p>';
	echo '<form method="post">';
	wp_nonce_field( 'ew_food_save' );
	echo '<input type="hidden" name="ew_food_save" value="1">';

	foreach ( $events as $i => $e ) {
		$new = ! empty( $e['new'] );
		$f   = 'ev[' . (int) $i . ']';
		echo '<div style="background:#fff;border:1px solid #dcdcde;padding:16px 20px;margin:16px 0;max-width:900px">';
		echo '<h2 style="margin-top:0">' . esc_html( $new ? 'Add an event' : (string) $e['name'] ) . '</h2>';
		if ( ! $new ) {
			list( $passes, $toks ) = ew_food_sold( $e['id'] );
			echo '<p>' . esc_html( sprintf( '%d passes sold · %d tokens', $passes, $toks ) ) . '</p>';
		}
		echo '<input type="hidden" name="' . esc_attr( $f ) . '[id]" value="' . esc_attr( (string) $e['id'] ) . '">';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th><label>Event</label></th><td><input type="text" class="regular-text" name="' . esc_attr( $f ) . '[name]" value="' . esc_attr( (string) $e['name'] ) . '" placeholder="Scott Loach Goalie Camp"></td></tr>';
		echo '<tr><th><label>Date</label></th><td><input type="date" name="' . esc_attr( $f ) . '[date]" value="' . esc_attr( (string) $e['date'] ) . '"> <span class="description">Tokens only work on this day.</span></td></tr>';
		echo '<tr><th><label>Times</label></th><td><input type="text" class="regular-text" name="' . esc_attr( $f ) . '[times]" value="' . esc_attr( (string) $e['times'] ) . '"></td></tr>';
		echo '<tr><th><label>Deals</label></th><td><textarea class="large-text code" rows="5" name="' . esc_attr( $f ) . '[deals]">' . esc_textarea( (string) $e['deals'] ) . '</textarea>'
			. '<p class="description">One deal per line: name | price | what is in it. Separate the things in it with a semicolon, for example<br><code>Hot dog deal | 5 | Hot dog; A drink; Crisps</code></p></td></tr>';
		echo '<tr><th><label>Pitchside Bar &amp; Lounge</label></th><td><input type="text" class="regular-text" name="' . esc_attr( $f ) . '[lounge]" value="' . esc_attr( (string) ( $e['lounge'] ?? '' ) ) . '"> <span class="description">Shown on the pass, e.g. when it is open that day. Leave empty to leave it off.</span></td></tr>';
		echo '<tr><th>On sale</th><td><label><input type="checkbox" name="' . esc_attr( $f ) . '[on]" value="1"' . checked( ! empty( $e['on'] ), true, false ) . '> Show on the page</label>'
			. ( $new ? '' : ' &nbsp; <label><input type="checkbox" name="' . esc_attr( $f ) . '[del]" value="1"> Delete this event</label>' ) . '</td></tr>';
		echo '</table></div>';
	}

	echo '<h2>The email with the pass</h2>';
	echo '<p>Sent when a pass is paid for: the QR code, the code, a button to open the pass and the list of tokens, then these words. Placeholders: {first_name} {event} {date} {code}.</p>';
	echo '<p><label>Subject<br><input type="text" class="large-text" name="ew_food_mail_subject" value="' . esc_attr( ew_food_mail_text( 'subject' ) ) . '"></label></p>';
	echo '<p><label>Button<br><input type="text" class="regular-text" name="ew_food_mail_button" value="' . esc_attr( ew_food_mail_text( 'button' ) ) . '"></label></p>';
	echo '<p><label>Pitchside Bar &amp; Lounge line ({lounge} is what the event says)<br><input type="text" class="large-text" name="ew_food_mail_lounge" value="' . esc_attr( ew_food_mail_text( 'lounge' ) ) . '"></label></p>';
	echo '<p><label>Email<br><textarea class="large-text" rows="10" name="ew_food_mail_body">' . esc_textarea( ew_food_mail_text( 'body' ) ) . '</textarea></label></p>';
	submit_button( 'Save food tokens' );
	echo '</form></div>';
}

/* --- /foodtokens goes to /foodtoken ------------------------------------- */

add_action( 'template_redirect', function () {
	$path = trim( (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ), '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( 'foodtokens' === strtolower( $path ) ) {
		$q = isset( $_SERVER['QUERY_STRING'] ) && '' !== $_SERVER['QUERY_STRING'] ? '?' . wp_unslash( $_SERVER['QUERY_STRING'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		wp_safe_redirect( home_url( '/foodtoken/' ) . $q, 301 );
		exit;
	}
}, 1 );

/* --- Look and behaviour ------------------------------------------------- */

add_filter( 'body_class', function ( $c ) {
	if ( is_singular() && ew_post_contains( get_post(), 'eastwood_food_tokens' ) ) {
		$c[] = 'ew-food';
	}
	return $c;
} );

add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_singular() || ! ew_post_contains( get_post(), 'eastwood_food_tokens' ) ) {
		return;
	}
	wp_register_style( 'ew-food', false, array(), EW_FWP_VERSION );
	wp_enqueue_style( 'ew-food' );
	wp_add_inline_style( 'ew-food', ew_food_css() );
	wp_enqueue_script( 'ew-qrcode', ew_hb_asset( 'claim/qrcode.js' ), array(), EW_FWP_VERSION, true );
	wp_register_script( 'ew-food', '', array( 'ew-qrcode' ), EW_FWP_VERSION, true );
	wp_enqueue_script( 'ew-food' );
	wp_add_inline_script( 'ew-food', ew_food_js() );
}, 23 );

function ew_food_css() {
	return <<<'CSS'
/* The same 1248px column as every page on the site, and the same 16px
   gutter once the window is narrower than that. */
.ewft{max-width:none;margin:0;padding:40px max(16px,calc((100% - 1248px) / 2)) 64px;color:#111;font-family:'Instrument Sans',system-ui,sans-serif;box-sizing:border-box}
.ewft *{box-sizing:border-box}
.ewft p{margin:0}
.ewft .ewft-step{font-family:Anton,Impact,sans-serif;font-weight:400;text-transform:uppercase;font-size:30px;line-height:1.1;color:#111;margin:0 0 18px;background:none;padding:0}
.ewft .ewft-step+*{margin-bottom:44px}
.ewft-events{list-style:none;margin:0;padding:0;display:grid;gap:10px}
.ewft-events li{margin:0;padding:0;list-style:none}
.ewft-ev{display:flex;align-items:center;gap:16px;background:#fff;border:1px solid #e2e2e2;border-radius:8px;padding:18px 20px;text-decoration:none;color:#111}
.ewft-ev:hover{border-color:#bdbdbd;color:#111}
.ewft-ev.is-on{border-color:#111}
.ewft-dot{width:18px;height:18px;border-radius:50%;border:2px solid #bdbdbd;flex:none}
.ewft-ev.is-on .ewft-dot{border-color:#cc0000;background:radial-gradient(#cc0000 0 4px,#fff 5px)}
.ewft-evText b{display:block;font-family:Anton,Impact,sans-serif;font-weight:400;text-transform:uppercase;font-size:22px;line-height:1.1}
.ewft-evText i{font-style:normal;font-size:15px;color:#55555c}
.ewft .ewft-none,.ewft .ewft-bad{background:#fff;border:1px solid #e2e2e2;border-radius:8px;padding:22px;font-size:17px;margin-bottom:32px}
.ewft .ewt{--ewt-card:#fff;--ewt-cream:#cc0000;--ewt-txt:#111;--ewt-mut:#55555c;--ewt-line:rgba(0,0,0,.12);max-width:none;padding:0}
body.ew-food .ewt-bar{--ewt-card:#fff;--ewt-cream:#cc0000;--ewt-txt:#111;--ewt-mut:rgba(255,255,255,.7);--ewt-line:rgba(255,255,255,.14);background:#111}
body.ew-food .ewt-bar .ewt-bar-tot{color:#fff}
.ewft .ewt .ewt-sub{text-align:left;font-size:16px}
.ewft .ewt-grid{margin-top:18px}
.ewft .ewt-card{border-radius:8px}
.ewft .ewt-card.ewt-sel{border-color:#111}
.ewft .ewt .ewt-name,.ewft .ewt .ewt-price{color:#111}
.ewft .ewt .ewt-note{text-align:left}
.ewft .ewt-go,body.ew-food .ewt-bar .ewt-go{background:#cc0000;color:#fff}
.ewft .ewt-go:hover,body.ew-food .ewt-bar .ewt-go:hover{background:#a80000;color:#fff}
.ewft .ewt-who input{background:#fff;color:#111;border-color:#cfcfcf}
.ewft .ewt-who input:focus{border-color:#111}
.ewft .ewt-qty button:hover{background:rgba(0,0,0,.05)}
.ewft-passTop{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,460px);gap:40px;align-items:start}
.ewft .ewft-kick{font-family:Anton,Impact,sans-serif;font-size:15px;letter-spacing:.12em;text-transform:uppercase;color:#cc0000}
.ewft .ewft-big{font-family:Anton,Impact,sans-serif;font-weight:400;text-transform:uppercase;font-size:clamp(44px,6vw,76px);line-height:1;color:#111;margin:12px 0 0;background:none;padding:0}
.ewft .ewft-lede{margin-top:18px;font-size:18px;line-height:1.5;color:#33333a}
.ewft-have{margin-top:22px;background:#fff;border-radius:8px;padding:12px 22px 18px}
.ewft-tok{display:flex;align-items:center;gap:14px;padding:12px 0;border-top:1px solid rgba(0,0,0,.1);font-size:17px}
.ewft-tok:first-child{border-top:0}
.ewft-tok span{flex:1}
.ewft-tok b{font-weight:600}
.ewft-use{background:#cc0000;color:#fff;border:0;font-family:Anton,Impact,sans-serif;text-transform:uppercase;font-size:15px;padding:10px 16px;cursor:pointer;min-height:44px}
.ewft-use:disabled{background:#e6e6e8;color:#9b9b9f;cursor:default}
.ewft .ewft-only{margin-top:10px;font-size:14px;color:#55555c}
.ewft-staff{margin-top:18px;border:1px solid #111;border-radius:8px;padding:16px 20px;background:#fff}
.ewft .ewft-staffHead{font-family:Anton,Impact,sans-serif;text-transform:uppercase;font-size:16px}
.ewft .ewft-staffMsg{margin-top:8px;color:#cc0000}
.ewft .ewt-title:empty{display:none}
.ewft .ewft-wrong{margin-top:8px;color:#cc0000;font-weight:600}
.ewft .ewft-staffSub{margin-top:12px;font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#55555c}
.ewft-log{margin:6px 0 0;padding:0 0 0 18px;font-size:15px}
.ewft-card{background:#0b0a09;color:#fff;border-radius:8px;padding:32px;display:flex;flex-direction:column;align-items:center;gap:12px}
.ewft .ewft-cardLab{font-family:Anton,Impact,sans-serif;font-size:14px;letter-spacing:.12em;text-transform:uppercase;color:#ff4d4d}
.ewft .ewft-for{margin:6px 0 0;font-family:Anton,Impact,sans-serif;font-size:26px;line-height:1.1;text-transform:uppercase}
.ewft-qr{background:#fff;padding:14px;width:228px;height:228px}
.ewft-qr img,.ewft-qr svg{display:block;width:200px;height:200px}
.ewft .ewft-code{font-family:Anton,Impact,sans-serif;font-size:24px;letter-spacing:.08em}
.ewft .ewft-codeNote{font-size:13px;color:rgba(255,255,255,.6)}
.ewft-send{width:100%;border-top:1px solid rgba(255,255,255,.14);margin-top:8px;padding-top:18px;display:flex;flex-direction:column;gap:10px}
.ewft .ewft-sendHead{font-family:Anton,Impact,sans-serif;font-size:16px;letter-spacing:.06em;text-transform:uppercase;text-align:center}
.ewft-sendBtns{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
.ewft-sendBtns button,.ewft-sendBtns a{background:#fff;color:#111;border:0;font:inherit;font-size:15px;font-weight:600;min-height:48px;display:flex;align-items:center;justify-content:center;gap:8px;text-decoration:none;cursor:pointer}
.ewft-sendBtns a:hover{color:#111}
.ewft .ewft-sendNote{font-size:13px;color:rgba(255,255,255,.6);text-align:center}
.ewft-app{background:none;color:rgba(255,255,255,.5);border:1px solid rgba(255,255,255,.2);font:inherit;font-size:15px;min-height:48px;display:flex;align-items:center;justify-content:center;gap:8px}
.ewft .ewft-hereHead{font-family:Anton,Impact,sans-serif;font-weight:400;text-transform:uppercase;font-size:40px;color:#cc0000;margin:64px 0 20px;background:none;padding:0}
.ewft-hereGrid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:24px}
.ewft-here{background:#fff;border-radius:8px;overflow:hidden;display:flex;flex-direction:column}
.ewft-here img{display:block;width:100%;aspect-ratio:16/9;object-fit:cover}
.ewft-here>div{padding:26px;display:flex;flex-direction:column;gap:12px;align-items:flex-start}
.ewft-here h3{margin:0;font-family:Anton,Impact,sans-serif;font-weight:400;text-transform:uppercase;font-size:30px;color:#111}
.ewft-here p{font-size:16px;line-height:1.55;color:#33333a}
.ewft .ewft-open{font-weight:600;color:#111}
.ewft-btn{background:#111;color:#fff;font-family:Anton,Impact,sans-serif;text-transform:uppercase;font-size:17px;padding:13px 26px;text-decoration:none}
.ewft-btn:hover{background:#333;color:#fff}
.ewft-link{color:#cc0000;font-weight:600;text-decoration:none}
@media(max-width:900px){
 .ewft-passTop,.ewft-hereGrid{grid-template-columns:1fr}
 .ewft-card{padding:24px 18px}
 .ewft .ewft-hereHead{font-size:30px;margin-top:44px}
}
@media(max-width:480px){.ewft-sendBtns{grid-template-columns:1fr}}
CSS;
}

function ew_food_js() {
	return <<<'JS'
(function(){
 document.querySelectorAll('.ewft-qr[data-qr]').forEach(function(el){
  if(!window.qrcode || el.firstChild){ return; }
  var q = qrcode(0, 'M'); q.addData(el.getAttribute('data-qr')); q.make();
  el.innerHTML = q.createSvgTag({cellSize:4, margin:0, scalable:true});
 });

 var pass = document.querySelector('.ewft-pass');
 if(!pass){ return; }
 var url = pass.dataset.url, text = pass.dataset.text;

 var share = pass.querySelector('[data-share]');
 if(share){
  share.addEventListener('click', function(){
   if(navigator.share){
    navigator.share({title: document.title, text: text, url: url}).catch(function(){});
   } else { copy(); }
  });
 }
 var copyBtn = pass.querySelector('[data-copy]');
 function copy(){
  var done = function(){
   var s = copyBtn && copyBtn.querySelector('span');
   if(s){ var was = s.textContent; s.textContent = pass.dataset.copied; setTimeout(function(){ s.textContent = was; }, 2000); }
  };
  if(navigator.clipboard){ navigator.clipboard.writeText(url).then(done, function(){ window.prompt('', url); }); }
  else { window.prompt('', url); }
 }
 if(copyBtn){ copyBtn.addEventListener('click', copy); }

 if(!pass.dataset.rest){ return; }
 pass.addEventListener('click', function(e){
  var b = e.target.closest('.ewft-use');
  if(!b || b.disabled){ return; }
  b.disabled = true;
  fetch(pass.dataset.rest, {
   method: 'POST',
   credentials: 'same-origin',
   headers: {'Content-Type':'application/json', 'X-WP-Nonce': pass.dataset.nonce},
   body: JSON.stringify({pass: pass.dataset.pass, i: +b.dataset.i})
  }).then(function(r){ return r.json(); }).then(function(j){
   if(!j.ok){ throw new Error(j.message || ''); }
   var row = b.closest('.ewft-tok');
   row.querySelector('[data-left-n]').textContent = pass.dataset.left.replace('{n}', j.left);
   if(j.left > 0){ b.disabled = false; } else { b.textContent = pass.dataset.done; }
   var log = pass.querySelector('.ewft-log');
   if(log){ log.innerHTML = ''; (j.log || []).forEach(function(t){ var li = document.createElement('li'); li.textContent = t; log.appendChild(li); }); }
  }).catch(function(err){
   b.disabled = false;
   var m = pass.querySelector('.ewft-staffMsg');
   if(m){ m.textContent = (err && err.message) || 'That did not go through.'; }
  });
 });
})();
JS;
}
