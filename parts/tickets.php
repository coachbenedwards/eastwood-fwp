<?php
/**
 * Event tickets: cards, a basket, and Stripe's own payment form on our page.
 *
 * [eastwood_tickets event="Peaky Blinders Night" title="Get your tickets"]
 * Basic ticket | 10 | | Entry from 7pm; Four vintage races; Casino tables from 9pm
 * Ticket with food | | Most popular | Everything in the basic ticket; Food on the night
 * [/eastwood_tickets]
 *
 * One ticket type per line: name | price in pounds | small tag | what is
 * included, separated by semicolons | places it uses (blank = 1; a table
 * of ten is 10). cap="160" on the block limits the places sold in total,
 * counted from paid Stripe bookings for this page.
 *
 * One card can be the featured one: feature="3" picks the third line, then
 * banner="Drinks on us" runs across its top, stamp="10" with
 * stamp_small="Seats" draws the round stamp, and bonus_head="On the house"
 * with bonus="10 pints; 2 bottles of wine" draws the drinks panel (a line
 * mentioning wine gets the bottle, anything else the pint glass).
 *
 * Any card can also take two more columns on its line:
 *   ... | seats | banner across the top | panel
 * The banner is an outline (the featured card's banner stays solid). The
 * panel is "Heading > item; item", e.g. "Plus > Food on the night": a
 * number or the first word is drawn big, the rest small, with an icon
 * picked from the words. icons="1" puts a small drawn icon at the start of
 * each included line on card 1 (a list of card numbers, e.g. "1,2"). A blank price shows the "to be
 * confirmed" wording and the ticket cannot be added until a price is set.
 *
 * Every word the block shows is a field or a line on the block, so a person
 * changes prices and wording in Elementor. Prices are read again on the
 * server from the page itself when someone checks out -- the browser only
 * says which tickets and how many, never what they cost.
 *
 * Money goes through Stripe's embedded Checkout: Stripe draws the card form
 * inside our page and card details never touch this site. The keys are the
 * Ventures Stripe keys held by ew_stripe_creds(); nothing secret is in here.
 *
 * @package eastwood-fwp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* --- Keys ---------------------------------------------------------- */

/** The Ventures Stripe keys, from the one place the site keeps them. */
function ew_tix_pk() {
	return function_exists( 'ew_stripe_creds' ) ? (string) ( ew_stripe_creds()['publishable'] ?? '' ) : '';
}
function ew_tix_sk() {
	return function_exists( 'ew_stripe_creds' ) ? (string) ( ew_stripe_creds()['secret'] ?? '' ) : '';
}

/* --- The block -------------------------------------------------------- */

/** The ticket types written in a block body. */
function ew_tix_lines( $content ) {
	$out = array();
	foreach ( ew_hb_lines( (string) $content ) as $line ) {
		$b = array_map( 'trim', explode( '|', $line ) );
		if ( '' === (string) ( $b[0] ?? '' ) ) {
			continue;
		}
		$raw   = preg_replace( '/[^0-9.]/', '', (string) ( $b[1] ?? '' ) );
		$price = '' === $raw ? 0 : (int) round( (float) $raw * 100 );
		$out[] = array(
			'name'  => (string) $b[0],
			'pence' => $price,
			'tag'   => (string) ( $b[2] ?? '' ),
			'inc'   => array_values( array_filter( array_map( 'trim', explode( ';', (string) ( $b[3] ?? '' ) ) ) ) ),
			'seats' => max( 1, (int) ( $b[4] ?? 1 ) ),
			'flag'  => (string) ( $b[5] ?? '' ),
			'panel' => (string) ( $b[6] ?? '' ),
		);
	}
	return $out;
}

/** Places already paid for on this page, from Stripe (kept two minutes). */
function ew_tix_sold( $page_id ) {
	$key  = 'ewt_sold_' . (int) $page_id;
	$sold = get_transient( $key );
	if ( false !== $sold ) {
		return (int) $sold;
	}
	$sk = ew_tix_sk();
	if ( '' === $sk ) {
		return 0;
	}
	$sold  = 0;
	$after = '';
	for ( $n = 0; $n < 20; $n++ ) {
		$url = 'https://api.stripe.com/v1/checkout/sessions?limit=100&status=complete' . ( $after ? '&starting_after=' . rawurlencode( $after ) : '' );
		$res = wp_remote_get( $url, array( 'timeout' => 15, 'headers' => array( 'Authorization' => 'Bearer ' . $sk ) ) );
		if ( is_wp_error( $res ) ) {
			return $sold;
		}
		$j = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		foreach ( (array) ( $j['data'] ?? array() ) as $cs ) {
			if ( (string) ( $cs['metadata']['page'] ?? '' ) === (string) (int) $page_id && 'paid' === ( $cs['payment_status'] ?? '' ) ) {
				$sold += (int) ( $cs['metadata']['seats'] ?? 0 );
			}
			$after = (string) $cs['id'];
		}
		if ( empty( $j['has_more'] ) ) {
			break;
		}
	}
	set_transient( $key, $sold, 2 * MINUTE_IN_SECONDS );
	return $sold;
}

/** A small line icon chosen from the words it sits next to. */
function ew_tix_icon( $words, $size = 30 ) {
	$w     = strtolower( (string) $words );
	$paths = array(
		'wine'   => 'M10 2h4v4.5c0 1 2 2.2 2 4.5V21a1 1 0 0 1-1 1H9a1 1 0 0 1-1-1V11c0-2.3 2-3.5 2-4.5zM8 13h8',
		'pint'   => 'M6 4h10l-1.2 16H7.2zM6.3 8h9.4M16 9h2a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2h-2.6',
		'food'   => 'M3 18h18M4.5 18a7.5 7.5 0 0 1 15 0M12 10.5V8M10.5 8h3M4 21h16',
		'clock'  => 'M12 3.5a8.5 8.5 0 1 0 0 17 8.5 8.5 0 0 0 0-17zM12 7v5l3 2',
		'race'   => 'M5 21V4M5 4h12l-2.5 4L17 12H5',
		'casino' => 'M12 3.5a8.5 8.5 0 1 0 0 17 8.5 8.5 0 0 0 0-17zM12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8zM12 3.5v3M12 17.5v3M3.5 12h3M17.5 12h3',
		'ticket' => 'M3 8a2 2 0 0 0 2-2h14a2 2 0 0 0 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 0-2 2H5a2 2 0 0 0-2-2v-2a2 2 0 0 0 0-4z',
	);
	$key = '';
	foreach ( array(
		'wine'   => '/wine/',
		'pint'   => '/pint|beer|lager|drink/',
		'food'   => '/food|meal|dinner|buffet/',
		'race'   => '/race/',
		'casino' => '/casino|roulette|blackjack|poker/',
		'clock'  => '/entry|doors|\d\s*(am|pm)\b/',
		'ticket' => '/ticket|everything/',
	) as $k => $re ) {
		if ( preg_match( $re, $w ) ) {
			$key = $k;
			break;
		}
	}
	if ( '' === $key ) {
		return '';
	}
	return '<svg width="' . (int) $size . '" height="' . (int) $size . '" viewBox="0 0 24 24" aria-hidden="true"><path d="' . $paths[ $key ] . '"/></svg>';
}

/** A boxed panel: a small heading, then items with a big number or word. */
function ew_tix_panel( $head, $items ) {
	$items = array_filter( array_map( 'trim', explode( ';', (string) $items ) ) );
	if ( ! $items ) {
		return '';
	}
	$h = '<div class="ewt-bonus">';
	if ( '' !== trim( (string) $head ) ) {
		$h .= '<p class="ewt-bonus-head">' . esc_html( trim( $head ) ) . '</p>';
	}
	$h .= '<div class="ewt-drinks">';
	foreach ( $items as $d ) {
		if ( preg_match( '/^(\d+)\s*(.*)$/', $d, $m ) || preg_match( '/^(\S+)\s*(.*)$/', $d, $m ) ) {
			$big   = $m[1];
			$small = $m[2];
		} else {
			$big   = $d;
			$small = '';
		}
		$h .= '<div class="ewt-drink">' . ew_tix_icon( $d ) . '<div><strong>' . esc_html( $big ) . '</strong>' . ( '' !== $small ? '<em>' . esc_html( $small ) . '</em>' : '' ) . '</div></div>';
	}
	return $h . '</div></div>';
}

function ew_tix_money( $pence ) {
	return '£' . ( 0 === $pence % 100 ? number_format( $pence / 100 ) : number_format( $pence / 100, 2 ) );
}

function ew_tix_shortcode( $atts, $content = null ) {
	$a = shortcode_atts( array(
		'event'    => '',
		'kicker'   => 'Tickets',
		'title'    => 'Get your tickets',
		'sub'      => '',
		'note'     => '',
		'each'     => 'each',
		'tbc'      => '£ TBC',
		'soon'     => 'Coming soon',
		'checkout' => 'Checkout',
		'co_title' => 'Your booking',
		'loading'  => 'Loading the payment form…',
		'off'      => 'Online booking opens soon.',
		'thanks'   => 'You are in. Your receipt is on its way to your email.',
		'cap'      => '',
		'soldout'  => 'Sold out',
		'feature'     => '',
		'banner'      => '',
		'stamp'       => '',
		'stamp_small' => '',
		'bonus_head'  => '',
		'bonus'       => '',
		'icons'       => '',
	), $atts, 'eastwood_tickets' );

	$tix = ew_tix_lines( $content );
	if ( ! $tix ) {
		return '';
	}

	$page = get_the_ID();
	$paid = isset( $_GET['ewt_paid'] ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( $paid ) {
		delete_transient( 'ewt_sold_' . (int) $page );
	}
	$cap  = (int) $a['cap'];
	$left = $cap > 0 ? max( 0, $cap - ew_tix_sold( $page ) ) : PHP_INT_MAX;

	$h  = '<section class="ewt" id="tickets" data-page="' . (int) $page . '" data-rest="' . esc_url( rest_url( 'eastwood/v1/tickets/checkout' ) ) . '"';
	$h .= ' data-pk="' . esc_attr( ew_tix_pk() ) . '" data-off="' . esc_attr( $a['off'] ) . '" data-loading="' . esc_attr( $a['loading'] ) . '">';
	if ( $paid ) {
		$h .= '<p class="ewt-thanks">' . esc_html( $a['thanks'] ) . '</p>';
	}
	if ( '' !== $a['kicker'] ) {
		$h .= '<p class="ewt-kick">' . esc_html( $a['kicker'] ) . '</p>';
	}
	$h .= '<h2 class="ewt-title">' . esc_html( $a['title'] ) . '</h2>';
	if ( '' !== $a['sub'] ) {
		$h .= '<p class="ewt-sub">' . esc_html( $a['sub'] ) . '</p>';
	}
	$iconed = array_map( 'intval', array_filter( array_map( 'trim', explode( ',', (string) $a['icons'] ) ) ) );
	$h .= '<div class="ewt-grid ewt-n' . min( 4, count( $tix ) ) . '">';
	foreach ( $tix as $i => $t ) {
		$on  = $t['pence'] > 0;
		$out  = $on && $left < $t['seats'];
		$feat = (int) $a['feature'] === $i + 1;
		$flag = $feat ? (string) $a['banner'] : $t['flag'];
		$h .= '<div class="ewt-card' . ( $on && ! $out ? '' : ' ewt-off' ) . ( $feat ? ' ewt-feat' : '' ) . ( '' !== $flag ? ' ewt-has-banner' : '' ) . ( ! $feat && '' !== $flag ? ' ewt-flagged' : '' ) . '" data-i="' . (int) $i . '" data-p="' . (int) $t['pence'] . '" data-n="' . esc_attr( $t['name'] ) . '">';
		if ( '' !== $flag ) {
			$h .= '<p class="ewt-banner' . ( $feat ? '' : ' ewt-banner-line' ) . '">' . esc_html( $flag ) . '</p>';
		}
		if ( $feat && '' !== $a['stamp'] ) {
			$h .= '<p class="ewt-stamp">' . ( '' !== $a['stamp_small'] ? '<small>' . esc_html( $a['stamp_small'] ) . '</small>' : '' ) . '<b>' . esc_html( $a['stamp'] ) . '</b></p>';
		}
		$h .= '<p class="ewt-tag">' . esc_html( $t['tag'] ) . '</p>';
		$h .= '<h3 class="ewt-name">' . esc_html( $t['name'] ) . '</h3>';
		$h .= '<p class="ewt-price">' . esc_html( $on ? ew_tix_money( $t['pence'] ) : $a['tbc'] ) . ( $on && '' !== $a['each'] ? '<small>' . esc_html( $a['each'] ) . '</small>' : '' ) . '</p>';
		if ( $feat && '' !== trim( $a['bonus'] ) ) {
			$h .= ew_tix_panel( $a['bonus_head'], $a['bonus'] );
		} elseif ( '' !== $t['panel'] ) {
			$pp  = explode( '>', $t['panel'], 2 );
			$h  .= isset( $pp[1] ) ? ew_tix_panel( $pp[0], $pp[1] ) : ew_tix_panel( '', $pp[0] );
		}
		if ( $t['inc'] ) {
			$withi = in_array( $i + 1, $iconed, true );
			$h    .= '<ul class="ewt-inc' . ( $withi ? ' ewt-inc-icons' : '' ) . '">';
			foreach ( $t['inc'] as $li ) {
				$h .= '<li>' . ( $withi ? ew_tix_icon( $li, 20 ) : '' ) . '<span>' . esc_html( $li ) . '</span></li>';
			}
			$h .= '</ul>';
		}
		if ( $out ) {
			$h .= '<div class="ewt-qty ewt-soon">' . esc_html( $a['soldout'] ) . '</div>';
		} elseif ( $on ) {
			$h .= '<div class="ewt-qty"><button type="button" data-d="-1" aria-label="One fewer">−</button><span>0</span><button type="button" data-d="1" aria-label="One more">+</button></div>';
		} else {
			$h .= '<div class="ewt-qty ewt-soon">' . esc_html( $a['soon'] ) . '</div>';
		}
		$h .= '</div>';
	}
	$h .= '</div>';
	if ( '' !== $a['note'] ) {
		$h .= '<p class="ewt-note">' . esc_html( $a['note'] ) . '</p>';
	}
	$h .= '<div class="ewt-co" hidden><h3 class="ewt-co-title">' . esc_html( $a['co_title'] ) . '</h3><div class="ewt-lines"></div><div class="ewt-mount"></div><p class="ewt-msg"></p></div>';
	$h .= '<div class="ewt-bar"><div class="ewt-bar-in"><span class="ewt-bar-lines"></span><span class="ewt-bar-tot">£0</span><button type="button" class="ewt-go">' . esc_html( $a['checkout'] ) . '</button></div></div>';
	$h .= '</section>';
	return $h;
}
add_shortcode( 'eastwood_tickets', 'ew_tix_shortcode' );

/* --- Checkout --------------------------------------------------------- */

/** The tickets block on a page, read from the page itself. */
function ew_tix_block_on( $post ) {
	$found = null;
	$data  = json_decode( (string) get_post_meta( $post->ID, '_elementor_data', true ), true );
	if ( is_array( $data ) ) {
		$walk = function ( $nodes ) use ( &$walk, &$found ) {
			foreach ( (array) $nodes as $n ) {
				if ( $found ) {
					return;
				}
				$sc = (string) ( $n['settings']['shortcode'] ?? '' );
				if ( 0 === strpos( ltrim( $sc ), '[eastwood_tickets' ) ) {
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
	if ( ! $found && preg_match( '/\[eastwood_tickets[^\]]*\].*?\[\/eastwood_tickets\]/s', (string) $post->post_content, $m ) ) {
		$found = ew_blocks_parse( $m[0] );
	}
	return $found;
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'eastwood/v1', '/tickets/checkout', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) {
			$sk = ew_tix_sk();
			if ( '' === $sk ) {
				return new WP_Error( 'ewt_off', 'Payments are not switched on.', array( 'status' => 503 ) );
			}
			$post = get_post( (int) $req->get_param( 'page' ) );
			if ( ! $post || 'publish' !== $post->post_status ) {
				return new WP_Error( 'ewt_page', 'No such event.', array( 'status' => 404 ) );
			}
			$block = ew_tix_block_on( $post );
			if ( ! $block ) {
				return new WP_Error( 'ewt_block', 'This page sells no tickets.', array( 'status' => 404 ) );
			}
			$tix   = ew_tix_lines( $block['lines'] );
			$event = (string) ( $block['fields']['event'] ?? get_the_title( $post ) );

			$body  = array(
				'ui_mode'                         => 'embedded_page',
				'mode'                            => 'payment',
				'return_url'                      => add_query_arg( 'ewt_paid', '{CHECKOUT_SESSION_ID}', get_permalink( $post ) ) . '#tickets',
				'phone_number_collection[enabled]' => 'true',
				'metadata[page]'                  => (string) $post->ID,
				'metadata[event]'                 => $event,
			);
			$count = 0;
			$seats = 0;
			$k     = 0;
			foreach ( (array) $req->get_param( 'items' ) as $i => $qty ) {
				$i   = (int) $i;
				$qty = max( 0, min( 50, (int) $qty ) );
				if ( ! $qty || empty( $tix[ $i ] ) || $tix[ $i ]['pence'] <= 0 ) {
					continue;
				}
				$count += $qty;
				$seats += $qty * $tix[ $i ]['seats'];
				$body[ "line_items[$k][quantity]" ]                          = (string) $qty;
				$body[ "line_items[$k][price_data][currency]" ]              = 'gbp';
				$body[ "line_items[$k][price_data][unit_amount]" ]           = (string) $tix[ $i ]['pence'];
				$body[ "line_items[$k][price_data][product_data][name]" ]    = trim( $event . ' · ' . $tix[ $i ]['name'], ' ·' );
				$k++;
			}
			if ( ! $count || $count > 100 ) {
				return new WP_Error( 'ewt_qty', 'Choose between 1 and 100 tickets.', array( 'status' => 400 ) );
			}
			$cap = (int) ( $block['fields']['cap'] ?? 0 );
			if ( $cap > 0 ) {
				delete_transient( 'ewt_sold_' . (int) $post->ID );
				$left = max( 0, $cap - ew_tix_sold( $post->ID ) );
				if ( $seats > $left ) {
					return new WP_Error( 'ewt_full', sprintf( 'Only %d places left. Take some tickets out and try again.', $left ), array( 'status' => 409 ) );
				}
			}
			$body['metadata[seats]'] = (string) $seats;

			$res = wp_remote_post( 'https://api.stripe.com/v1/checkout/sessions', array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $sk,
					'Stripe-Version' => '2026-03-25.dahlia',
				),
				'body'    => $body,
			) );
			if ( is_wp_error( $res ) ) {
				return new WP_Error( 'ewt_stripe', 'Could not reach Stripe.', array( 'status' => 503 ) );
			}
			$j = json_decode( (string) wp_remote_retrieve_body( $res ), true );
			if ( empty( $j['client_secret'] ) ) {
				$msg = (string) ( $j['error']['message'] ?? 'Stripe refused the booking.' );
				return new WP_Error( 'ewt_stripe', $msg, array( 'status' => 400 ) );
			}
			return array( 'clientSecret' => $j['client_secret'] );
		},
	) );
} );

/* --- Styles and script ------------------------------------------------ */

function ew_tix_css() {
	return <<<'CSS'
.ewt,.ewt-bar{--ewt-card:#231c16;--ewt-cream:#e8d5b0;--ewt-txt:#e9e2d6;--ewt-mut:rgba(233,226,214,.6);--ewt-line:rgba(232,213,176,.22)}
.ewt{max-width:1180px;margin:0 auto;padding:8px 0;color:var(--ewt-txt);font-family:'Instrument Sans',system-ui,sans-serif}
.ewt p{margin:0}
.ewt .ewt-kick{font-family:Anton,Impact,sans-serif;letter-spacing:.18em;text-transform:uppercase;color:var(--ewt-cream);font-size:15px;text-align:center}
.ewt .ewt-title{font-family:Anton,Impact,sans-serif;font-weight:400;text-transform:uppercase;color:var(--ewt-cream);font-size:clamp(34px,5vw,52px);line-height:1;text-align:center;margin:12px 0 10px;background:none;padding:0}
.ewt .ewt-sub{text-align:center;color:var(--ewt-mut);font-size:17px}
.ewt-grid{display:grid;gap:20px;margin-top:40px;grid-template-columns:repeat(3,1fr)}
.ewt-grid.ewt-n1{grid-template-columns:minmax(0,420px);justify-content:center}
.ewt-grid.ewt-n2{grid-template-columns:repeat(2,minmax(0,420px));justify-content:center}
.ewt-grid.ewt-n4{grid-template-columns:repeat(4,1fr)}
.ewt-card{background:var(--ewt-card);border:1px solid var(--ewt-line);display:flex;flex-direction:column;padding:28px;text-align:left}
.ewt-card.ewt-sel{border-color:rgba(232,213,176,.7)}
.ewt-card{position:relative}
.ewt-card.ewt-feat{border-color:rgba(232,213,176,.75);background:linear-gradient(180deg,#2c231b,#231c16);transform:translateY(-14px);box-shadow:0 18px 40px rgba(0,0,0,.45)}
.ewt .ewt-banner{position:absolute;top:-1px;left:-1px;right:-1px;background:var(--ewt-cream);color:#1b140f;text-align:center;font-family:Anton,Impact,sans-serif;letter-spacing:.14em;text-transform:uppercase;font-size:14px;padding:8px;margin:0}
.ewt-card.ewt-has-banner .ewt-tag{margin-top:30px}
.ewt-card.ewt-flagged{border-color:rgba(232,213,176,.45)}
.ewt .ewt-banner.ewt-banner-line{background:none;color:var(--ewt-cream);border:1px solid rgba(232,213,176,.45);border-bottom-color:var(--ewt-line)}
.ewt .ewt-inc.ewt-inc-icons li::before{display:none}
.ewt .ewt-inc.ewt-inc-icons li{display:flex;align-items:center;gap:12px}
.ewt .ewt-inc-icons li svg{stroke:var(--ewt-cream);fill:none;stroke-width:1.5;stroke-linecap:round;stroke-linejoin:round;flex:none}
.ewt .ewt-inc li span{display:inline}
.ewt .ewt-stamp{position:absolute;top:58px;right:22px;width:88px;height:88px;border:1.5px solid var(--ewt-cream);border-radius:50%;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;color:var(--ewt-cream);transform:rotate(-10deg);margin:0}
.ewt-stamp small{font-size:10px;letter-spacing:.14em;text-transform:uppercase}
.ewt-stamp b{font-family:Anton,Impact,sans-serif;font-weight:400;font-size:30px;line-height:1}
.ewt-bonus{border:1px solid rgba(232,213,176,.55);background:rgba(232,213,176,.06);padding:16px 18px;margin-bottom:22px}
.ewt .ewt-bonus-head{font-size:12px;letter-spacing:.16em;text-transform:uppercase;color:var(--ewt-cream);margin-bottom:12px}
.ewt-drinks{display:flex;gap:22px;flex-wrap:wrap}
.ewt-drink{display:flex;align-items:center;gap:10px;color:var(--ewt-cream)}
.ewt-drink svg{stroke:currentColor;fill:none;stroke-width:1.5;stroke-linecap:round;stroke-linejoin:round;flex:none}
.ewt-drink strong{display:block;font-family:Anton,Impact,sans-serif;font-weight:400;font-size:28px;line-height:1}
.ewt-drink em{font-style:normal;font-size:13px;color:var(--ewt-txt);display:block;line-height:1.2}
.ewt .ewt-tag{font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:var(--ewt-mut);min-height:18px}
.ewt .ewt-name{font-family:Anton,Impact,sans-serif;font-weight:400;text-transform:uppercase;color:var(--ewt-cream);font-size:30px;line-height:1.05;margin:8px 0 6px;background:none;padding:0}
.ewt .ewt-price{font-family:Anton,Impact,sans-serif;font-size:46px;color:var(--ewt-cream);line-height:1;margin:6px 0 16px}
.ewt .ewt-price small{font-family:'Instrument Sans',system-ui,sans-serif;font-size:14px;color:var(--ewt-mut);margin-left:6px}
.ewt .ewt-inc{list-style:none;margin:0 0 24px;padding:0;flex:1}
.ewt .ewt-inc li{border-top:1px solid var(--ewt-line);padding:9px 0;font-size:15px;color:var(--ewt-txt);list-style:none}
.ewt .ewt-inc li::before{content:'';display:inline-block;width:6px;height:6px;background:var(--ewt-cream);margin:0 10px 2px 0;transform:rotate(45deg)}
.ewt-qty{display:flex;align-items:center;justify-content:space-between;border:1px solid var(--ewt-line);height:52px}
.ewt-qty button{width:52px;height:100%;background:none;border:0;color:var(--ewt-cream);font-size:24px;cursor:pointer;padding:0}
.ewt-qty button:hover{background:rgba(232,213,176,.08)}
.ewt-qty span{font-family:Anton,Impact,sans-serif;font-size:24px;color:var(--ewt-txt)}
.ewt-qty.ewt-soon{justify-content:center;font-size:14px;letter-spacing:.12em;text-transform:uppercase;color:var(--ewt-mut)}
.ewt .ewt-note{font-size:14px;color:var(--ewt-mut);text-align:center;margin-top:24px}
.ewt .ewt-thanks{border:1px solid rgba(232,213,176,.7);color:var(--ewt-cream);padding:18px;text-align:center;margin-bottom:32px;font-size:18px}
.ewt-co{margin-top:48px;border-top:1px solid var(--ewt-line);padding-top:40px;text-align:left}
.ewt .ewt-co-title{font-family:Anton,Impact,sans-serif;font-weight:400;text-transform:uppercase;color:var(--ewt-cream);font-size:30px;margin:0 0 14px;background:none;padding:0}
.ewt-lines{margin-bottom:20px}
.ewt-lines div{display:flex;justify-content:space-between;border-top:1px solid var(--ewt-line);padding:10px 0;font-size:16px}
.ewt-lines div.ewt-t{font-family:Anton,Impact,sans-serif;font-size:26px;color:var(--ewt-cream)}
.ewt-mount{background:#fff;border-radius:6px;min-height:40px}
.ewt-mount:empty{display:none}
.ewt .ewt-msg{color:var(--ewt-mut);font-size:15px;margin-top:12px}
.ewt-bar{position:fixed;left:0;right:0;bottom:0;z-index:9990;background:#0b0908;border-top:1px solid var(--ewt-line);transform:translateY(110%);transition:transform .25s}
.ewt-bar.ewt-on{transform:none}
.ewt-bar-in{max-width:1180px;margin:0 auto;padding:14px 16px;display:flex;align-items:center;gap:20px}
.ewt-bar-lines{flex:1;font-size:14px;color:var(--ewt-mut)}
.ewt-bar-tot{font-family:Anton,Impact,sans-serif;font-size:30px;color:var(--ewt-cream)}
.ewt-go{background:var(--ewt-cream);color:#1b140f;border:0;font-family:Anton,Impact,sans-serif;text-transform:uppercase;letter-spacing:.06em;font-size:18px;padding:15px 34px;cursor:pointer}
.ewt-go:hover{background:#f3e6c8}
@media(max-width:900px){.ewt-card.ewt-feat{transform:none;margin-top:10px}.ewt-grid,.ewt-grid.ewt-n2,.ewt-grid.ewt-n4{grid-template-columns:1fr}.ewt-bar-lines{display:none}.ewt-bar-in{justify-content:space-between}}
CSS;
}

function ew_tix_js() {
	return <<<'JS'
/* Stripe renamed initEmbeddedCheckout to createEmbeddedCheckoutPage. Ask for
   whichever this browser's copy of Stripe.js actually has, so the page keeps
   working either side of that change. */
function ewStripeCheckout(stripe, opts){
 var make = stripe.createEmbeddedCheckoutPage || stripe.initEmbeddedCheckout;
 return make.call(stripe, opts);
}
(function(){
  document.querySelectorAll('.ewt').forEach(function(root){
    var q={}, cards=root.querySelectorAll('.ewt-card'), bar=root.querySelector('.ewt-bar'), co=root.querySelector('.ewt-co'), mount=root.querySelector('.ewt-mount'), msg=root.querySelector('.ewt-msg'), checkout=null;
    document.body.appendChild(bar);
    function money(p){return '£'+(p%100?(p/100).toFixed(2):(p/100).toLocaleString('en-GB'));}
    function render(){
      var tot=0, lines=[];
      cards.forEach(function(c){var i=c.dataset.i,n=q[i]||0,s=c.querySelector('.ewt-qty span');if(s)s.textContent=n;c.classList.toggle('ewt-sel',n>0);if(n){tot+=n*(+c.dataset.p);lines.push([n,c.dataset.n,n*(+c.dataset.p)]);}});
      bar.classList.toggle('ewt-on',tot>0);
      bar.querySelector('.ewt-bar-tot').textContent=money(tot);
      bar.querySelector('.ewt-bar-lines').textContent=lines.map(function(l){return l[0]+' × '+l[1];}).join(' · ');
      root.querySelector('.ewt-lines').innerHTML=lines.map(function(l){return '<div><span>'+l[0]+' × '+l[1].replace(/</g,'&lt;')+'</span><span>'+money(l[2])+'</span></div>';}).join('')+(tot?'<div class="ewt-t"><span></span><span>'+money(tot)+'</span></div>':'');
      if(checkout){checkout.destroy();checkout=null;mount.innerHTML='';co.hidden=true;}
    }
    root.addEventListener('click',function(e){var b=e.target.closest('.ewt-qty button');if(!b)return;var i=b.closest('.ewt-card').dataset.i;q[i]=Math.max(0,Math.min(50,(q[i]||0)+(+b.dataset.d)));render();});
    bar.querySelector('.ewt-go').addEventListener('click',function(){
      co.hidden=false; co.scrollIntoView({behavior:'smooth',block:'start'});
      if(!root.dataset.pk||!window.Stripe){msg.textContent=root.dataset.off;return;}
      if(checkout)return;
      msg.textContent=root.dataset.loading;
      var stripe=window.Stripe(root.dataset.pk);
      ewStripeCheckout(stripe,{fetchClientSecret:function(){
        return fetch(root.dataset.rest,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({page:+root.dataset.page,items:q})})
          .then(function(r){return r.json();}).then(function(j){if(!j.clientSecret)throw new Error(j.message||'error');return j.clientSecret;});
      }}).then(function(c){checkout=c;msg.textContent='';c.mount(mount);bar.classList.remove('ewt-on');})
        .catch(function(err){msg.textContent=(err&&err.message)||root.dataset.off;});
    });
    render();
  });
})();
JS;
}

add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_singular() ) {
		return;
	}
	$post = get_post();
	if ( ! $post || ! ew_post_contains( $post, 'eastwood_tickets' ) ) {
		return;
	}
	wp_register_style( 'ew-tix', false, array(), EW_FWP_VERSION );
	wp_enqueue_style( 'ew-tix' );
	wp_add_inline_style( 'ew-tix', ew_tix_css() );

	$deps = array();
	if ( '' !== ew_tix_pk() ) {
		wp_enqueue_script( 'stripe-js', 'https://js.stripe.com/v3/', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		$deps[] = 'stripe-js';
	}
	wp_register_script( 'ew-tix', '', $deps, EW_FWP_VERSION, true );
	wp_enqueue_script( 'ew-tix' );
	wp_add_inline_script( 'ew-tix', ew_tix_js() );
}, 22 );
