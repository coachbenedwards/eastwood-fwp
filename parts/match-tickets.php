<?php
/**
 * Buying a ticket for a game: eastwoodfc.com/tickets.
 *
 * The tickets page was a price list. Buy tickets on a fixture pointed at it,
 * so the one link on the site that says "buy" landed somewhere you could not
 * buy anything -- and the prices on it were typed into the page, which meant
 * there was no priced thing anywhere for a checkout to sell.
 *
 * This block is the page doing the job instead. With no game named it lists
 * the home games, and says of each one what is true: on sale, the day it
 * goes on sale, or not announced. Name a game -- which the fixture card does
 * when it hands over, carrying the numbers somebody already chose -- and it
 * is the ticket: who is coming, the total, then the card.
 *
 * What it sells comes from On sale under Fixtures, not from here, so the page
 * and the fixture card can never disagree about what is for sale. The money
 * follows the same road as every other payment on the site: the Stripe
 * checkout, which is Ventures', a Booking saved before the card so a booker
 * who never pays is still known, and the CRM told at each step.
 *
 * Under-16s are free with a paying adult, so they are counted and named on
 * the booking but never put on the bill -- and a basket of nothing but
 * under-16s is turned away, because the free ticket is the adult's.
 *
 * @package eastwood-fwp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The block's fields and what they say until somebody changes them. */
function ew_mt_defaults() {
	return array(
		'title'      => 'Tickets',
		'sub'        => 'Pick a game and pay here, or pay on the gate — same price either way. Under-16s come in free with an adult.',
		'list_head'  => 'Home games',
		'empty'      => 'Nothing is on sale right now. Tickets for each game go on sale a few weeks before it.',
		'buy'        => 'Buy tickets',
		'soon'       => 'On sale {when}',
		'off'        => 'Not announced yet',
		'out'        => 'Sold out',
		'left'       => '{n} left',
		'back'       => 'All games',
		'who'        => "Who's coming?",
		'free'       => 'Free',
		'none'       => 'Nobody yet',
		'go'         => 'Continue',
		'need'       => 'Add at least one person.',
		'need_adult' => 'Under-16s come in free with a paying adult, so add an adult too.',
		'pay_head'   => 'Your details',
		'loading'    => 'Loading the payment form…',
		'payoff'     => 'Card payments are not switched on right now. You can pay on the gate.',
		'thanks'     => "You're in. Your tickets are on the way to your email.",
		'ref'        => 'Reference {code}',
		'gate'       => 'Show the email at the turnstile. Parking on site is free.',
		'hosp'       => 'Make a day of it — hospitality',
		'hosp_url'   => '/hospitality/',
		'hosp_sub'   => 'The whole afternoon rather than a better seat: in early, food at your table, and your match ticket included.',
		// Step two: the email on its own, saved the moment it is given.
		'email_label'  => 'Your email · your tickets are sent here',
		'email_go'     => 'Continue to payment',
		'need_email'   => 'Please add your email.',
		'failed'       => 'Something went wrong. Please try again.',
		'crm_hook'     => '',
		'greeting'     => '',
		// The last page, after paying: the ticket in the app.
		'app_title'    => 'Your tickets are in the app',
		'ticket_title' => 'Eastwood FC',
		'ticket_sub'   => 'v {away}',
	);
}

function ew_mt_shortcode( $atts ) {
	$a = shortcode_atts( ew_mt_defaults(), $atts, 'eastwood_match_tickets' );

	if ( ! function_exists( 'ew_sales_state' ) ) {
		return '';
	}

	$paid = (string) ( $_GET['ewt_paid'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$fx   = (int) ( $_GET['fx'] ?? 0 );           // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	$h = '<section class="ewmt" id="tickets">';

	if ( '' !== $paid ) {
		$h .= ew_mt_done( $paid, $a );
	}

	$game = $fx ? ew_mt_game( $fx ) : null;
	if ( $game && 'on' === ew_sales_state( $fx, 'tix' )['state'] && '' === $paid ) {
		$h .= ew_mt_one( $game, $a );
	} else {
		$h .= ew_mt_many( $a );
	}

	return $h . '</section>';
}
add_shortcode( 'eastwood_match_tickets', 'ew_mt_shortcode' );

/** One fixture from the feed, corrections applied, or null. */
function ew_mt_game( $id ) {
	if ( ! function_exists( 'ew_fixtures_league_list' ) ) {
		return null;
	}
	foreach ( ew_fixtures_league_list() as $m ) {
		if ( (int) ( $m['id'] ?? 0 ) !== (int) $id ) {
			continue;
		}
		$m = function_exists( 'ew_fixture_apply' ) ? ew_fixture_apply( $m ) : $m;
		return $m ? $m : null;
	}
	return null;
}

/** Our home games still to come, in order. */
function ew_mt_home_games() {
	if ( ! function_exists( 'ew_fixtures_league_list' ) ) {
		return array();
	}
	$today = current_time( 'Y-m-d' );
	$out   = array();
	foreach ( ew_fixtures_league_list() as $m ) {
		if ( (string) ( $m['date'] ?? '' ) < $today ) {
			continue;
		}
		$m = function_exists( 'ew_fixture_apply' ) ? ew_fixture_apply( $m ) : $m;
		if ( ! $m ) {
			continue;
		}
		if ( (int) ( ( (array) ( $m['home-team'] ?? array() ) )['id'] ?? 0 ) !== (int) EW_TEAM ) {
			continue;
		}
		$out[] = $m;
	}
	return $out;
}

/**
 * The list: every home game and what is true about it.
 *
 * One row each, stacked, because a run of games read down the page and not
 * across it. A game that is not on sale is still a row -- it is the held
 * back games that make the released ones worth hurrying for, and a page
 * that showed only what was for sale would hide that.
 */
function ew_mt_many( $a ) {
	$games = ew_mt_home_games();
	$h     = '<h2 class="ewmt-h">' . esc_html( $a['list_head'] ) . '</h2>';
	if ( ! $games ) {
		return $h . '<p class="ewmt-empty">' . esc_html( $a['empty'] ) . '</p>';
	}

	$any  = false;
	$rows = '';
	foreach ( $games as $m ) {
		$id  = (int) ( $m['id'] ?? 0 );
		$st  = ew_sales_state( $id, 'tix' );
		$ts  = strtotime( (string) ( $m['date'] ?? '' ) . ' ' . (string) ( $m['time'] ?? '15:00' ) );
		$opp = (string) ( ( (array) ( $m['away-team'] ?? array() ) )['name'] ?? '' );

		$rows .= '<li class="ewmt-row is-' . esc_attr( $st['state'] ) . '">';
		$rows .= '<span class="ewmt-when"><b>' . esc_html( $ts ? date_i18n( 'D j M', $ts ) : '' ) . '</b>'
			. '<i>' . esc_html( $ts ? date_i18n( 'H:i', $ts ) : '' ) . '</i></span>';
		$rows .= '<span class="ewmt-who"><b>' . esc_html( $opp ) . '</b>'
			. '<i>' . esc_html( (string) ( $m['competition']['name'] ?? '' ) ) . '</i></span>';

		if ( 'on' === $st['state'] ) {
			$any   = true;
			$note  = $st['cap']
				? '<i>' . esc_html( str_replace( '{n}', number_format_i18n( $st['left'] ), $a['left'] ) ) . '</i>'
				: '';
			$rows .= '<span class="ewmt-act">' . $note . '<a class="ewmt-buy" href="'
				. esc_url( add_query_arg( 'fx', $id, get_permalink() ) ) . '">'
				. esc_html( $a['buy'] ) . '</a></span>';
		} else {
			$word = 'soon' === $st['state'] && $st['when']
				? str_replace( '{when}', date_i18n( 'D j M, ga', $st['when'] ), $a['soon'] )
				: ( 'out' === $st['state'] ? $a['out'] : $a['off'] );
			$rows .= '<span class="ewmt-act"><em>' . esc_html( $word ) . '</em></span>';
		}
		$rows .= '</li>';
	}

	if ( ! $any ) {
		$h .= '<p class="ewmt-empty">' . esc_html( $a['empty'] ) . '</p>';
	}
	return $h . '<ul class="ewmt-rows">' . $rows . '</ul>' . ew_mt_hosp( $a );
}

/** The one line out to hospitality, where there is something to sell. */
function ew_mt_hosp( $a ) {
	if ( '' === trim( (string) $a['hosp'] ) ) {
		return '';
	}
	return '<div class="ewmt-cross"><div><b>' . esc_html( $a['hosp'] ) . '</b>'
		. '<span>' . esc_html( $a['hosp_sub'] ) . '</span></div>'
		. '<a href="' . esc_url( home_url( $a['hosp_url'] ) ) . '">'
		. esc_html( $a['hosp'] ) . '</a></div>';
}

/**
 * One game: the ticket, the counters, and the card.
 *
 * The numbers can arrive on the link, because the fixture card has already
 * asked who is coming by the time it hands over. Somebody who lands here
 * cold starts at nought, and somebody sent the link gets what the sender
 * chose -- the same screen either way.
 */
function ew_mt_one( $m, $a ) {
	$id   = (int) ( $m['id'] ?? 0 );
	$st   = ew_sales_state( $id, 'tix' );
	$ts   = strtotime( (string) ( $m['date'] ?? '' ) . ' ' . (string) ( $m['time'] ?? '15:00' ) );
	$home = (array) ( $m['home-team'] ?? array() );
	$away = (array) ( $m['away-team'] ?? array() );
	$want = ew_mt_wanted();

	$h  = '<p class="ewmt-back"><a href="' . esc_url( remove_query_arg( array( 'fx', 'q' ) ) ) . '">'
		. '&larr; ' . esc_html( $a['back'] ) . '</a></p>';

	$h .= '<div class="ewmt-tk" data-fx="' . $id . '" data-page="' . (int) get_the_ID() . '"'
		. ' data-rest="' . esc_url( rest_url( 'eastwood/v1/tickets/match' ) ) . '"'
		. ' data-start="' . esc_url( rest_url( 'eastwood/v1/tickets/match/start' ) ) . '"'
		. ' data-need-email="' . esc_attr( $a['need_email'] ) . '"'
		. ' data-failed="' . esc_attr( $a['failed'] ) . '"'
		. ' data-pk="' . esc_attr( function_exists( 'ew_tix_pk' ) ? ew_tix_pk() : '' ) . '"'
		. ' data-off="' . esc_attr( $a['payoff'] ) . '"'
		. ' data-loading="' . esc_attr( $a['loading'] ) . '"'
		. ' data-need="' . esc_attr( $a['need'] ) . '"'
		. ' data-adult="' . esc_attr( $a['need_adult'] ) . '"'
		. ' data-none="' . esc_attr( $a['none'] ) . '">';

	$h .= '<div class="ewmt-tkMatch">';
	$h .= '<p class="ewmt-tie"><span>' . esc_html( (string) ( $home['name'] ?? '' ) ) . '</span>'
		. '<em>v</em><span>' . esc_html( (string) ( $away['name'] ?? '' ) ) . '</span></p>';
	$h .= '<p class="ewmt-tkWhen"><b>' . esc_html( $ts ? date_i18n( 'l j F Y', $ts ) : '' ) . '</b>'
		. '<i>' . esc_html( $ts ? date_i18n( 'ga', $ts ) : '' ) . '</i></p>';
	$h .= '<p class="ewmt-tkAt">Coronation Park, Chewton Street, Eastwood NG16 3HB</p>';
	if ( $st['cap'] ) {
		$h .= '<p class="ewmt-tkLeft">' . esc_html( str_replace( '{n}', number_format_i18n( $st['left'] ), $a['left'] ) ) . '</p>';
	}
	$h .= '</div>';

	$h .= '<div class="ewmt-tkStub">';
	$h .= '<p class="ewmt-lab">' . esc_html( $a['who'] ) . '</p>';
	$h .= '<div class="ewmt-tkRows">';
	foreach ( ew_sales_types() as $key => $t ) {
		$n = max( 0, min( 20, (int) ( $want[ $key ] ?? 0 ) ) );
		$h .= '<div class="ewmt-tkRow" data-t="' . esc_attr( $key ) . '" data-p="' . (int) $t['pence'] . '">';
		$h .= '<span class="ewmt-tkWho"><b>' . esc_html( $t['label'] ) . '</b>'
			. ( '' !== $t['note'] ? '<small>' . esc_html( $t['note'] ) . '</small>' : '' ) . '</span>';
		$h .= '<span class="ewmt-tkPick"><span class="ewmt-tkP">'
			. esc_html( $t['pence'] ? ew_sales_money( $t['pence'] ) : $a['free'] )
			. '</span><span class="ewmt-tkPm">'
			. '<button type="button" data-d="-1" aria-label="One fewer"' . ( $n ? '' : ' disabled' ) . '>&minus;</button>'
			. '<output>' . (int) $n . '</output>'
			. '<button type="button" data-d="1" aria-label="One more">+</button>'
			. '</span></span></div>';
	}
	$h .= '</div>';
	$h .= '<p class="ewmt-sum"><span data-ewmt-who>' . esc_html( $a['none'] ) . '</span>'
		. '<span data-ewmt-tot>' . esc_html( ew_sales_money( 0 ) ) . '</span></p>';
	$h .= '<button type="button" class="ewmt-go" data-ewmt-go>' . esc_html( $a['go'] ) . '</button>';
	$h .= '<p class="ewmt-msg" data-ewmt-msg></p>';
	$h .= '</div></div>';

	$h .= '<div class="ewmt-pay" data-ewmt-pay hidden>';
	$h .= '<h2 class="ewmt-h">' . esc_html( $a['pay_head'] ) . '</h2>';
	// The email on its own, before the card: saved as soon as it is given,
	// so somebody who stops at the card is still known.
	$h .= '<div class="ewmt-email" data-ewmt-email>';
	$h .= '<label class="ewmt-elab" for="ewmtEmail">' . esc_html( $a['email_label'] ) . '</label>';
	$h .= '<div class="ewmt-erow"><input class="ewmt-ein" id="ewmtEmail" type="email" autocomplete="email" inputmode="email" required>';
	$h .= '<button type="button" class="ewmt-go ewmt-enext" data-ewmt-next>' . esc_html( $a['email_go'] ) . '</button></div>';
	$h .= '<p class="ewmt-err" role="alert" data-ewmt-err></p>';
	$h .= '</div>';
	$h .= '<div class="ewmt-mount" data-ewmt-mount></div>';
	$h .= '</div>';

	return $h . ew_mt_hosp( $a );
}

/** The numbers the fixture card handed over: "adult:2,u16:2". */
function ew_mt_wanted() {
	$raw  = (string) ( $_GET['q'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$out  = array();
	foreach ( explode( ',', $raw ) as $bit ) {
		$bit = explode( ':', $bit );
		if ( 2 !== count( $bit ) ) {
			continue;
		}
		$key = sanitize_key( $bit[0] );
		if ( '' !== $key ) {
			$out[ $key ] = max( 0, min( 20, (int) $bit[1] ) );
		}
	}
	return $out;
}

/* --- Back from paying --------------------------------------------------- */

/**
 * The session is checked with Stripe rather than trusted from the link, so
 * a hand-typed ewt_paid cannot mark anything paid.
 */
function ew_mt_done( $sid, $a ) {
	if ( ! preg_match( '/^cs_[A-Za-z0-9_]+$/', $sid ) || ! function_exists( 'ew_tix_stripe' ) ) {
		return '';
	}
	$s = ew_tix_stripe( 'GET', 'checkout/sessions/' . rawurlencode( $sid ) );
	if ( empty( $s['id'] ) || 'paid' !== ( $s['payment_status'] ?? '' ) ) {
		return '';
	}
	$fx = (int) ( $s['metadata']['fixture'] ?? 0 );
	if ( ! $fx ) {
		return '';
	}

	// The booking saved when the email was given is the one that is now
	// paid. A session from before the email step has none, and gets one
	// written now, as it always did.
	$bid = function_exists( 'ew_tix_booking_for' ) ? ew_tix_booking_for( $s ) : 0;
	if ( $bid && function_exists( 'ew_tix_mark_paid' ) ) {
		ew_tix_mark_paid( $bid, $s, (string) get_post_meta( $bid, '_ewt_hook', true ) );
		$phone = trim( (string) ( $s['customer_details']['phone'] ?? '' ) );
		if ( '' !== $phone && '' === (string) get_post_meta( $bid, '_ewt_phone', true ) ) {
			update_post_meta( $bid, '_ewt_phone', $phone );
		}
	} else {
		$bid = ew_mt_booking( $s, $fx );
	}

	// The last page: the ticket, in the app.
	if ( function_exists( 'ew_tix_inapp' ) ) {
		$app = ew_tix_inapp( $a['app_title'], ew_mt_ticket( $fx, $a ), $bid );
		if ( '' !== $app ) {
			return '<div class="ewmt-inapp">' . $app . '</div>';
		}
	}

	$h  = '<div class="ewmt-thanks">';
	$h .= '<p class="ewmt-thanksT">' . esc_html( $a['thanks'] ) . '</p>';
	$h .= '<p class="ewmt-thanksW">' . esc_html( (string) ( $s['metadata']['what'] ?? '' ) ) . '</p>';
	if ( $bid ) {
		$h .= '<p class="ewmt-thanksR">' . esc_html( str_replace( '{code}', 'EFC-' . $bid, $a['ref'] ) ) . '</p>';
	}
	$h .= '<p class="ewmt-thanksG">' . esc_html( $a['gate'] ) . '</p>';
	return $h . '</div>';
}

/** The match ticket that slides out of the phone on the last page. */
function ew_mt_ticket( $fx, $a ) {
	$game = ew_mt_game( $fx );
	$ts   = $game ? strtotime( (string) ( $game['date'] ?? '' ) . ' ' . (string) ( $game['time'] ?? '15:00' ) ) : 0;
	$away = $game ? (string) ( ( (array) ( $game['away-team'] ?? array() ) )['name'] ?? '' ) : '';
	$when = function_exists( 'ew_tix_ticket_when' ) ? ew_tix_ticket_when( $ts ) : array();
	return array(
		'title' => (string) $a['ticket_title'],
		'sub'   => '' !== $away ? str_replace( '{away}', $away, (string) $a['ticket_sub'] ) : '',
	) + $when;
}

/**
 * The Booking for a paid session, made the first time we are told about it.
 *
 * The event flow saves a Booking before the card, because it asks for a name
 * and an email itself. Here Stripe asks for them, so there is nothing worth
 * saving until it has -- and the session id is what makes this safe to run
 * again: a refresh of the thank-you page finds the Booking rather than
 * writing a second one.
 */
function ew_mt_booking( $s, $fx ) {
	$have = get_posts(
		array(
			'post_type'              => 'ewt_booking',
			'post_status'            => 'any',
			'numberposts'            => 1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_key'               => '_ewt_session',   // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'             => (string) $s['id'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		)
	);
	if ( $have ) {
		return (int) $have[0];
	}

	$who   = ew_person_name( (string) ( $s['customer_details']['name'] ?? '' ) );
	$email = trim( (string) ( $s['customer_details']['email'] ?? '' ) );
	$bid   = wp_insert_post(
		array(
			'post_type'   => 'ewt_booking',
			'post_status' => 'publish',
			'post_title'  => '' !== $who ? $who : ( '' !== $email ? $email : 'Match ticket' ),
		)
	);
	if ( ! $bid || is_wp_error( $bid ) ) {
		return 0;
	}
	$bid = (int) $bid;

	update_post_meta( $bid, '_ewt_status', 'paid' );
	update_post_meta( $bid, '_ewt_session', (string) $s['id'] );
	update_post_meta( $bid, '_ewt_fixture', (int) $fx );
	update_post_meta( $bid, '_ewt_seats', (int) ( $s['metadata']['seats'] ?? 0 ) );
	update_post_meta( $bid, '_ewt_paid', (int) ( $s['amount_total'] ?? 0 ) );
	update_post_meta( $bid, '_ewt_event', (string) ( $s['metadata']['what'] ?? '' ) );
	if ( '' !== $who ) {
		update_post_meta( $bid, '_ewt_name', $who );
	}
	if ( '' !== $email ) {
		update_post_meta( $bid, '_ewt_email', $email );
	}
	$phone = trim( (string) ( $s['customer_details']['phone'] ?? '' ) );
	if ( '' !== $phone ) {
		update_post_meta( $bid, '_ewt_phone', $phone );
	}

	if ( function_exists( 'ew_track_purchase' ) ) {
		ew_track_purchase( $bid );
	}
	return $bid;
}

/* --- Checkout ----------------------------------------------------------- */

add_action( 'rest_api_init', function () {
	register_rest_route( 'eastwood/v1', '/tickets/match/start', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => 'ew_mt_start',
	) );
	register_rest_route( 'eastwood/v1', '/tickets/match', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => 'ew_mt_checkout',
	) );
} );

/** The tickets page a request came from, and its block's fields. */
function ew_mt_page( $req ) {
	$post = get_post( (int) $req->get_param( 'page' ) );
	if ( ! $post || 'publish' !== $post->post_status ) {
		$post = get_page_by_path( 'tickets' );
	}
	$block = ( $post && function_exists( 'ew_app_block_on' ) ) ? ew_app_block_on( $post, 'eastwood_match_tickets' ) : null;
	return array( $post, shortcode_atts( ew_mt_defaults(), (array) ( $block['fields'] ?? array() ) ) );
}

/**
 * Step one of paying: the email, on its own. A Booking is saved as started
 * with the game, who is coming and the total, the CRM is told and the
 * one-hour reminder is set -- before any card form is asked for.
 * Pressing it again (after changing who is coming) updates the same one.
 */
function ew_mt_start( $req ) {
	list( $post, $a ) = ew_mt_page( $req );
	$email = sanitize_email( (string) $req->get_param( 'email' ) );
	if ( ! is_email( $email ) ) {
		return new WP_Error( 'ewmt_email', $a['need_email'], array( 'status' => 400 ) );
	}
	$b = ew_mt_basket( (int) $req->get_param( 'fx' ), (array) $req->get_param( 'items' ) );
	if ( is_wp_error( $b ) ) {
		return $b;
	}
	if ( ! $post || ! function_exists( 'ew_tix_booking_start' ) ) {
		return new WP_Error( 'ewmt_failed', $a['failed'], array( 'status' => 500 ) );
	}
	$bid = function_exists( 'ew_tix_carry' ) ? ew_tix_carry( $req ) : 0;
	if ( $bid ) {
		update_post_meta( $bid, '_ewt_email', $email );
		update_post_meta( $bid, '_ewt_event', $b['what'] );
		update_post_meta( $bid, '_ewt_lines', implode( ', ', $b['lines'] ) );
		update_post_meta( $bid, '_ewt_total', $b['total'] );
	} else {
		$bid = ew_tix_booking_start( $post, $b['what'], '', $email, implode( ', ', $b['lines'] ), $b['total'], $a['crm_hook'], $a['greeting'] );
		if ( ! $bid ) {
			return new WP_Error( 'ewmt_failed', $a['failed'], array( 'status' => 500 ) );
		}
	}
	update_post_meta( $bid, '_ewt_fixture', (int) $b['fx'] );
	update_post_meta( $bid, '_ewt_seats', (int) $b['seats'] );
	return array( 'bid' => (int) $bid, 'key' => ew_tix_key( $bid ) );
}

/**
 * What is being bought, priced here from On sale and never from the
 * browser: the lines, the Stripe line items, the seats and the total.
 */
function ew_mt_basket( $fx, $items ) {
	$fx = (int) $fx;
	$st = ew_sales_state( $fx, 'tix' );
	if ( 'on' !== $st['state'] ) {
		return new WP_Error( 'ewmt_shut', 'Tickets for this game are not on sale.', array( 'status' => 409 ) );
	}
	$game = ew_mt_game( $fx );
	if ( ! $game ) {
		return new WP_Error( 'ewmt_game', 'No such game.', array( 'status' => 404 ) );
	}

	$ts   = strtotime( (string) ( $game['date'] ?? '' ) . ' ' . (string) ( $game['time'] ?? '15:00' ) );
	$away = (string) ( ( (array) ( $game['away-team'] ?? array() ) )['name'] ?? '' );
	$what = 'Eastwood FC v ' . $away . ( $ts ? ', ' . date_i18n( 'j M Y', $ts ) : '' );

	$types = ew_sales_types();
	$items = (array) $items;
	$total = 0;
	$seats = 0;
	$paidn = 0;
	$k     = 0;
	$lines = array();
	$body  = array();

	foreach ( $types as $key => $t ) {
		$qty = max( 0, min( 20, (int) ( $items[ $key ] ?? 0 ) ) );
		if ( ! $qty ) {
			continue;
		}
		$seats += $qty;
		$lines[] = $qty . ' × ' . $t['label'];
		// A free type is counted and named but never billed, so Stripe is
		// never asked to take nothing for a line.
		if ( $t['pence'] < 1 ) {
			continue;
		}
		$paidn += $qty;
		$total += $qty * (int) $t['pence'];
		$body[ "line_items[$k][quantity]" ]                       = (string) $qty;
		$body[ "line_items[$k][price_data][currency]" ]           = 'gbp';
		$body[ "line_items[$k][price_data][unit_amount]" ]        = (string) $t['pence'];
		$body[ "line_items[$k][price_data][product_data][name]" ] = $what . ' · ' . $t['label'];
		$k++;
	}

	if ( ! $seats ) {
		return new WP_Error( 'ewmt_qty', 'Add at least one person.', array( 'status' => 400 ) );
	}
	if ( ! $paidn ) {
		return new WP_Error(
			'ewmt_adult',
			'Under-16s come in free with a paying adult, so add an adult too.',
			array( 'status' => 400 )
		);
	}
	if ( $st['cap'] && $seats > $st['left'] ) {
		return new WP_Error(
			'ewmt_full',
			sprintf( 'Only %d left for this game. Take some out and try again.', (int) $st['left'] ),
			array( 'status' => 409 )
		);
	}

	return array(
		'fx'    => $fx,
		'what'  => $what,
		'lines' => $lines,
		'seats' => $seats,
		'total' => $total,
		'body'  => $body,
	);
}

/**
 * Step two: the card. Carries on the Booking step one saved, so paying
 * marks that booking paid; the email is handed to Stripe so nobody is
 * asked for it twice.
 */
function ew_mt_checkout( $req ) {
	if ( ! function_exists( 'ew_tix_sk' ) || '' === ew_tix_sk() ) {
		return new WP_Error( 'ewmt_off', 'Card payments are not switched on.', array( 'status' => 503 ) );
	}
	list( $post, $a ) = ew_mt_page( $req );
	$bid = function_exists( 'ew_tix_carry' ) ? ew_tix_carry( $req ) : 0;
	if ( ! $bid ) {
		return new WP_Error( 'ewmt_email', $a['need_email'], array( 'status' => 400 ) );
	}
	$b = ew_mt_basket( (int) $req->get_param( 'fx' ), (array) $req->get_param( 'items' ) );
	if ( is_wp_error( $b ) ) {
		return $b;
	}
	$fx    = $b['fx'];
	$seats = $b['seats'];
	$what  = $b['what'];
	$lines = $b['lines'];
	$body  = $b['body'];
	// Who is coming may have changed since the email was given.
	update_post_meta( $bid, '_ewt_lines', implode( ', ', $lines ) );
	update_post_meta( $bid, '_ewt_total', $b['total'] );
	update_post_meta( $bid, '_ewt_seats', (int) $seats );

	$here = $post ? get_permalink( $post ) : home_url( '/tickets/' );

	$body['ui_mode']                          = 'embedded_page';
	$body['mode']                             = 'payment';
	$body['return_url']                       = add_query_arg( 'ewt_paid', '{CHECKOUT_SESSION_ID}', $here ) . '#tickets';
	$body['phone_number_collection[enabled]'] = 'true';
	$body['metadata[fixture]']                = (string) $fx;
	$body['metadata[seats]']                  = (string) $seats;
	$body['metadata[what]']                   = $what . ' — ' . implode( ' · ', $lines );
	$body['metadata[kind]']                   = 'match';
	$body['metadata[booking]']                = (string) $bid;
	$email = (string) get_post_meta( $bid, '_ewt_email', true );
	if ( is_email( $email ) ) {
		$body['customer_email'] = $email;
	}

	$res = wp_remote_post( 'https://api.stripe.com/v1/checkout/sessions', array(
		'timeout' => 20,
		'headers' => array(
			'Authorization'  => 'Bearer ' . ew_tix_sk(),
			'Stripe-Version' => '2026-03-25.dahlia',
		),
		'body'    => $body,
	) );
	if ( is_wp_error( $res ) ) {
		return new WP_Error( 'ewmt_stripe', 'Could not reach Stripe.', array( 'status' => 503 ) );
	}
	$j = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	if ( empty( $j['client_secret'] ) ) {
		return new WP_Error(
			'ewmt_stripe',
			(string) ( $j['error']['message'] ?? 'Stripe refused the booking.' ),
			array( 'status' => 400 )
		);
	}
	if ( ! empty( $j['id'] ) ) {
		update_post_meta( $bid, '_ewt_open_session', (string) $j['id'] );
		do_action( 'ew_tix_session', (int) $bid, (string) $j['id'] );
	}
	return array( 'clientSecret' => $j['client_secret'] );
}

/* --- Styles and script -------------------------------------------------- */

function ew_mt_css() {
	return '
.ewmt{max-width:1180px;margin:0 auto;font-family:"Instrument Sans",system-ui,sans-serif;color:#101010}
.ewmt p{margin:0}
.ewmt .ewmt-h{font-family:Anton,Impact,sans-serif;font-weight:400;font-size:32px;line-height:1;
 letter-spacing:.5px;text-transform:uppercase;margin:0 0 16px;background:none;padding:0}
.ewmt-empty{font-size:16px;line-height:1.6;color:#666;margin:0 0 20px}
.ewmt-rows{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:10px}
.ewmt-row{list-style:none;display:flex;align-items:center;gap:20px;background:#fff;
 border:1.5px solid #e2e2e2;border-radius:10px;padding:16px 20px}
.ewmt-row.is-on{border-color:#CC0000}
.ewmt-row.is-off,.ewmt-row.is-out{opacity:.62}
.ewmt-when{flex:0 0 118px}
.ewmt-when b{display:block;font-family:Anton,Impact,sans-serif;font-weight:400;font-size:24px;line-height:1}
.ewmt-when i{display:block;font-style:normal;font-size:13px;color:#777;margin-top:3px}
.ewmt-who{flex:1 1 auto;min-width:0}
.ewmt-who b{display:block;font-size:21px;font-weight:700;letter-spacing:-.3px}
.ewmt-who i{display:block;font-style:normal;font-size:14px;color:#777}
.ewmt-act{flex:0 0 auto;display:flex;align-items:center;gap:14px}
.ewmt-act i{font-style:normal;font-size:13px;color:#777}
.ewmt-act em{font-style:normal;font-size:14px;font-weight:700;letter-spacing:.04em;
 text-transform:uppercase;color:#777}
.ewmt-buy{display:inline-block;background:#CC0000;color:#fff;border-radius:6px;padding:13px 22px;
 font-size:15px;font-weight:700;text-decoration:none;white-space:nowrap}
.ewmt-back{margin:0 0 16px}
.ewmt-back a{font-size:15px;font-weight:700;color:#101010;text-decoration:none}
.ewmt-back a:hover{text-decoration:underline}

.ewmt-tk{display:grid;grid-template-columns:minmax(0,1fr) 420px;background:#101010;color:#fff;
 border-radius:10px;overflow:hidden}
.ewmt-tkMatch{padding:34px 36px;display:flex;flex-direction:column;justify-content:center;gap:18px}
.ewmt-tie{display:flex;flex-direction:column;gap:4px;font-family:Anton,Impact,sans-serif;
 font-weight:400;font-size:52px;line-height:.98;text-transform:uppercase;letter-spacing:.5px}
.ewmt-tie em{font-family:Georgia,serif;font-style:italic;font-size:28px;color:#e01b1b;line-height:1}
.ewmt-tkWhen{display:flex;align-items:baseline;gap:14px;flex-wrap:wrap}
.ewmt-tkWhen b{font-size:16px;font-weight:700;letter-spacing:.04em;text-transform:uppercase}
.ewmt-tkWhen i{font-family:Anton,Impact,sans-serif;font-style:normal;font-size:32px;color:#e01b1b}
.ewmt-tkAt,.ewmt-tkLeft{font-size:13.5px;color:rgba(255,255,255,.6)}
.ewmt-tkStub{border-left:3px dashed rgba(255,255,255,.85);padding:28px 30px;background:rgba(255,255,255,.04)}
.ewmt-lab{font-size:12px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;
 color:rgba(255,255,255,.55);margin:0 0 10px}
.ewmt-tkRow{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:12px 0;
 border-top:1px solid rgba(255,255,255,.1)}
.ewmt-tkRow:first-child{border-top:0}
.ewmt-tkWho b{display:block;font-size:15px;font-weight:700}
.ewmt-tkWho small{display:block;font-size:12px;color:rgba(255,255,255,.5);margin-top:1px}
.ewmt-tkPick{display:flex;align-items:center;gap:14px;flex:none}
.ewmt-tkP{font-size:15px;font-weight:700;min-width:42px;text-align:right}
.ewmt-tkPm{display:inline-flex;align-items:center;gap:2px;border:1px solid rgba(255,255,255,.3);border-radius:5px}
.ewmt-tkPm button{appearance:none;background:none;border:0;color:#fff;font:inherit;font-size:17px;
 line-height:1;width:34px;height:34px;cursor:pointer}
.ewmt-tkPm button[disabled]{color:rgba(255,255,255,.28);cursor:default}
.ewmt-tkPm output{min-width:24px;text-align:center;font-size:15px;font-weight:700}
.ewmt-sum{display:flex;align-items:baseline;justify-content:space-between;gap:12px;margin:16px 0 0;
 border-top:1px solid rgba(255,255,255,.18);padding-top:12px;font-size:14px}
.ewmt-sum span:last-child{font-family:Anton,Impact,sans-serif;font-size:28px}
.ewmt-go{display:block;width:100%;margin:14px 0 0;appearance:none;border:0;border-radius:6px;
 background:#CC0000;color:#fff;font:inherit;font-size:15px;font-weight:700;letter-spacing:.05em;
 text-transform:uppercase;padding:16px;cursor:pointer}
.ewmt-msg{margin:10px 0 0;font-size:13.5px;color:rgba(255,255,255,.75);min-height:18px}
.ewmt-pay{margin-top:36px}
.ewmt-mount{background:#fff;border-radius:6px;min-height:40px}
.ewmt-mount:empty{display:none}
.ewmt-email{background:#101010;color:#fff;border-radius:10px;padding:26px 30px;max-width:640px}
.ewmt-elab{display:block;font-size:12px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;
 color:rgba(255,255,255,.55);margin:0 0 10px}
.ewmt-erow{display:flex;gap:10px;flex-wrap:wrap}
.ewmt-ein{flex:1 1 260px;min-width:0;box-sizing:border-box;background:#fff;color:#101010;border:0;border-radius:6px;
 padding:15px 14px;font:400 16px "Instrument Sans",system-ui,sans-serif}
.ewmt-ein:focus{outline:2px solid rgba(255,255,255,.6);outline-offset:1px}
.ewmt .ewmt-enext{flex:0 0 auto;width:auto;margin:0;padding:15px 24px}
.ewmt .ewmt-enext[disabled]{opacity:.6;cursor:wait}
.ewmt-err{margin:10px 0 0;font-size:13.5px;color:rgba(255,255,255,.75);min-height:18px}
.ewmt-inapp{max-width:520px;margin:0 0 30px}

.ewmt-thanks{background:#101010;color:#fff;border-radius:10px;padding:30px 34px;margin:0 0 30px}
.ewmt-thanksT{font-family:Anton,Impact,sans-serif;font-size:34px;line-height:1;text-transform:uppercase;
 letter-spacing:.5px;margin:0 0 12px}
.ewmt-thanksW{font-size:16px;font-weight:700;margin:0 0 6px}
.ewmt-thanksR{font-family:Anton,Impact,sans-serif;font-size:20px;color:#e01b1b;margin:0 0 12px}
.ewmt-thanksG{font-size:14.5px;line-height:1.6;color:rgba(255,255,255,.7)}

.ewmt-cross{background:#101010;color:#fff;border-radius:10px;padding:26px 30px;margin:34px 0 0;
 display:flex;align-items:center;justify-content:space-between;gap:24px;flex-wrap:wrap}
.ewmt-cross b{display:block;font-family:Anton,Impact,sans-serif;font-weight:400;font-size:27px;
 line-height:1.05;text-transform:uppercase;letter-spacing:.5px;margin:0 0 6px}
.ewmt-cross span{display:block;font-size:15px;line-height:1.6;color:rgba(255,255,255,.7);max-width:62ch}
.ewmt-cross a{flex:none;background:#CC0000;color:#fff;border-radius:6px;padding:15px 26px;
 font-size:15px;font-weight:700;text-decoration:none;white-space:nowrap}

@media(max-width:860px){
.ewmt-email{padding:22px 20px}
.ewmt .ewmt-enext{flex:1 1 100%}
.ewmt-tk{grid-template-columns:minmax(0,1fr)}
.ewmt-tkStub{border-left:0;border-top:3px dashed rgba(255,255,255,.85)}
.ewmt-tie{font-size:36px}
.ewmt-row{flex-wrap:wrap;gap:12px}
.ewmt-when,.ewmt-act{flex:1 1 100%}
}';
}

function ew_mt_js() {
	return <<<'JS'
(function(){
 var root = document.querySelector('.ewmt-tk');
 if(!root){ return; }
 var pay = document.querySelector('[data-ewmt-pay]');
 var mount = document.querySelector('[data-ewmt-mount]');
 var msg = root.querySelector('[data-ewmt-msg]');
 var ebox = document.querySelector('[data-ewmt-email]');
 var ein = document.getElementById('ewmtEmail');
 var enext = document.querySelector('[data-ewmt-next]');
 var eerr = document.querySelector('[data-ewmt-err]');
 var checkout = null, basketNow = null;
 /* The booking saved when the email was given, carried on to the card. */
 var memo = 'ewmt:' + root.dataset.fx, O = {bid: 0, key: ''};
 try{ var kept = JSON.parse(sessionStorage.getItem(memo) || 'null'); if(kept && kept.bid){ O = kept; if(kept.email && ein){ ein.value = kept.email; } } }catch(e){}

 function money(p){
  return '£' + (p % 100 === 0 ? (p/100).toLocaleString() : (p/100).toFixed(2));
 }

 function basket(){
  var n = 0, paid = 0, pence = 0, bits = [], items = {};
  root.querySelectorAll('.ewmt-tkRow').forEach(function(r){
   var q = parseInt(r.querySelector('output').textContent, 10) || 0;
   if(!q){ return; }
   var p = parseInt(r.dataset.p, 10) || 0;
   n += q; pence += q * p;
   if(p > 0){ paid += q; }
   items[r.dataset.t] = q;
   var name = r.querySelector('.ewmt-tkWho b').textContent.toLowerCase();
   bits.push(q + ' ' + name + (q > 1 ? 's' : ''));
  });
  return {n:n, paid:paid, pence:pence, bits:bits, items:items};
 }

 function render(){
  var b = basket();
  root.querySelector('[data-ewmt-who]').textContent = b.bits.length ? b.bits.join(' · ') : root.dataset.none;
  root.querySelector('[data-ewmt-tot]').textContent = money(b.pence);
  // Changing the basket invalidates a mounted checkout, so it goes rather
  // than quietly charging for what was in it a moment ago.
  if(checkout){ checkout.destroy(); checkout = null; mount.innerHTML = ''; }
  pay.hidden = true;
 }

 function post(url, body){
  return fetch(url, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)})
   .then(function(r){ return r.json().then(function(j){ return {ok: r.ok, j: j}; }); });
 }

 root.addEventListener('click', function(e){
  var pm = e.target.closest('.ewmt-tkPm button');
  if(pm){
   var r = pm.closest('.ewmt-tkRow'), out = r.querySelector('output');
   var q = Math.max(0, Math.min(20, (parseInt(out.textContent,10)||0) + (parseInt(pm.dataset.d,10)||0)));
   out.textContent = q;
   r.querySelector('[data-d="-1"]').disabled = q < 1;
   msg.textContent = '';
   render();
   return;
  }

  if(!e.target.closest('[data-ewmt-go]')){ return; }
  var b = basket();
  if(!b.n){ msg.textContent = root.dataset.need; return; }
  if(!b.paid){ msg.textContent = root.dataset.adult; return; }
  if(!root.dataset.pk || !window.Stripe){ msg.textContent = root.dataset.off; return; }
  if(checkout){ return; }

  /* Step two: the email, on its own, before any card form. */
  msg.textContent = ''; eerr.textContent = '';
  basketNow = b;
  pay.hidden = false; ebox.hidden = false;
  pay.scrollIntoView({behavior:'smooth', block:'start'});
  setTimeout(function(){ try{ ein.focus({preventScroll:true}); }catch(x){} }, 350);
 });

 function card(){
  var stripe = window.Stripe(root.dataset.pk);
  var make = stripe.createEmbeddedCheckoutPage || stripe.initEmbeddedCheckout;
  eerr.textContent = root.dataset.loading;
  make.call(stripe, {fetchClientSecret: function(){
   return fetch(root.dataset.rest, {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({fx: +root.dataset.fx, page: +(root.dataset.page||0), items: basketNow.items, bid: O.bid, key: O.key})
   }).then(function(r){ return r.json(); }).then(function(j){
    if(!j.clientSecret){ throw new Error(j.message || 'error'); }
    return j.clientSecret;
   });
  }}).then(function(c){
   checkout = c; eerr.textContent = ''; ebox.hidden = true; c.mount(mount);
  }).catch(function(err){
   enext.disabled = false;
   eerr.textContent = (err && err.message) || root.dataset.off;
  });
 }

 function next(){
  eerr.textContent = '';
  var email = (ein.value || '').trim();
  if(!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)){ eerr.textContent = root.dataset.needEmail; ein.focus(); return; }
  if(!basketNow || checkout){ return; }
  enext.disabled = true;
  /* Saved the moment the button is pressed: the booking, the CRM, the
     reminder an hour later. Then the card. */
  post(root.dataset.start, {fx: +root.dataset.fx, page: +(root.dataset.page||0), items: basketNow.items, email: email, bid: O.bid, key: O.key})
   .then(function(x){
    if(!x.ok || !x.j.bid){ enext.disabled = false; eerr.textContent = (x.j && x.j.message) || root.dataset.failed; return; }
    O = {bid: x.j.bid, key: x.j.key, email: email};
    try{ sessionStorage.setItem(memo, JSON.stringify(O)); }catch(e){}
    if(window.ewTrack){ ewTrack('Lead', {what: 'Match tickets'}); }
    card();
   })
   .catch(function(){ enext.disabled = false; eerr.textContent = root.dataset.failed; });
 }
 enext.addEventListener('click', next);
 ein.addEventListener('keydown', function(e){ if(e.key === 'Enter'){ e.preventDefault(); next(); } });

 render();
})();
JS;
}

add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_singular() ) {
		return;
	}
	$post = get_post();
	if ( ! $post || ! function_exists( 'ew_post_contains' )
		|| ! ew_post_contains( $post, 'eastwood_match_tickets' ) ) {
		return;
	}
	wp_register_style( 'ew-mt', false, array(), EW_FWP_VERSION );
	wp_enqueue_style( 'ew-mt' );
	wp_add_inline_style( 'ew-mt', ew_mt_css() . ( function_exists( 'ew_inapp_css' ) ? ew_inapp_css() : '' ) );
	wp_enqueue_script( 'stripe-js', 'https://js.stripe.com/v3/', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
	wp_register_script( 'ew-mt', '', array( 'stripe-js' ), EW_FWP_VERSION, true );
	wp_enqueue_script( 'ew-mt' );
	wp_add_inline_script( 'ew-mt', ew_mt_js() );
} );

add_action( 'wp_head', function () {
	$post = is_singular() ? get_post() : null;
	if ( $post && function_exists( 'ew_post_contains' )
		&& ew_post_contains( $post, 'eastwood_match_tickets' ) ) {
		echo '<link rel="stylesheet" href="https://fonts.googleapis.com/css2'
			. '?family=Anton&amp;family=Instrument+Sans:wght@400;600;700&amp;display=swap">' . "\n";
	}
}, 20 );
