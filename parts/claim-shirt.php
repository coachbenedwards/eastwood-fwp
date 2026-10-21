<?php
/**
 * The shirt and printing offers inside the ticket claim (/claimticket).
 *
 * After someone claims a ticket, the stub shows the shirts that are really
 * on sale -- price and sizes read from the shop, same as the shirt page --
 * with "No thanks" going straight to the pass. Picking one and paying goes
 * through Stripe's own form. Back from paying, printing is offered as the
 * next thing on its own: Players, Custom or None, added to the same card in
 * one tap, or a different card if they want. Then the pass, with what they
 * bought listed under the QR.
 *
 * Every word is a field on the claim block (ew_claim_defaults). What the
 * printing costs, the squad list, the CRM address and the payment rules are
 * read off the shirt page's block, so there is one place for each.
 *
 * Money is Eastwood FC Ventures through Stripe, the same as the shirt page.
 *
 * @package eastwood-fwp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The shirt page's block fields: print price, squad, CRM hook, pay rules. */
function ew_claim_shirt_src( $a ) {
	static $cache = array();
	$pid = (int) $a['shirt_page'];
	if ( isset( $cache[ $pid ] ) ) {
		return $cache[ $pid ];
	}
	$post  = $pid ? get_post( $pid ) : null;
	$block = ( $post && function_exists( 'ew_shirt_block_on' ) ) ? ew_shirt_block_on( $post ) : null;
	$cache[ $pid ] = (array) ( $block['fields'] ?? array() );
	return $cache[ $pid ];
}

/** One shop item (price, sizes in stock), read inside this request. */
function ew_claim_item( $sku ) {
	static $cache = array();
	if ( ! isset( $cache[ $sku ] ) ) {
		$req = new WP_REST_Request( 'GET', '/eastwood/v1/shop/item' );
		$req->set_query_params( array( 'sku' => $sku ) );
		$res           = rest_do_request( $req );
		$cache[ $sku ] = $res->is_error() ? array() : (array) $res->get_data();
	}
	return $cache[ $sku ];
}

/**
 * The shirts to offer: "CODE | title | sku | size label | word; ...".
 * Sizes are the shop's sizes starting with CODE ("KIDS M" shows as "M").
 * A row with no price or no sizes is left out.
 */
function ew_claim_offer_rows( $a ) {
	$out = array();
	foreach ( array_filter( array_map( 'trim', explode( ';', (string) $a['offer_rows'] ) ) ) as $r ) {
		$b    = array_map( 'trim', explode( '|', $r ) );
		$code = strtoupper( (string) ( $b[0] ?? '' ) );
		$sku  = (string) ( $b[2] ?? '' );
		if ( '' === $code || '' === $sku ) {
			continue;
		}
		$item  = ew_claim_item( $sku );
		$pence = (int) round( (float) ( $item['price'] ?? 0 ) * 100 );
		$sizes = array();
		foreach ( (array) ( $item['sizes'] ?? array() ) as $s ) {
			$full = (string) ( $s['size'] ?? '' );
			if ( 0 === stripos( $full, $code . ' ' ) ) {
				$sizes[] = array(
					'size'  => $full,
					'short' => trim( substr( $full, strlen( $code ) ) ),
					'in'    => ! empty( $s['in_stock'] ),
				);
			}
		}
		if ( $pence <= 0 || ! $sizes ) {
			continue;
		}
		$out[] = array(
			'code'  => $code,
			'title' => (string) ( $b[1] ?? $code ),
			'sku'   => $sku,
			'label' => (string) ( $b[3] ?? '' ),
			'word'  => (string) ( $b[4] ?? '' ),
			'name'  => trim( (string) ( $item['name'] ?? '' ) ),
			'pence' => $pence,
			'sizes' => $sizes,
		);
	}
	return $out;
}

/** The squad on the shirt page: "9 | Tyler Blake; ...". */
function ew_claim_squad( $a ) {
	$out = array();
	foreach ( preg_split( '/[;\r\n]+/', (string) ( ew_claim_shirt_src( $a )['print_squad'] ?? '' ) ) as $p ) {
		$b = array_map( 'trim', explode( '|', $p ) );
		if ( count( $b ) >= 2 && '' !== $b[0] && '' !== $b[1] ) {
			$out[] = array(
				'n'    => preg_replace( '/[^0-9]/', '', $b[0] ),
				'w'    => $b[1],
				'last' => strtoupper( preg_replace( "/[^A-Za-z '-]/", '', (string) substr( strrchr( ' ' . $b[1], ' ' ), 1 ) ) ),
			);
		}
	}
	return $out;
}

/** Printing price in pence, off the shirt page. 0 means no printing offer. */
function ew_claim_print_pence( $a ) {
	return (int) round( (float) ( ew_claim_shirt_src( $a )['print_price'] ?? 0 ) * 100 );
}

function ew_claim_print_max( $a ) {
	return max( 1, (int) ( ew_claim_shirt_src( $a )['print_max'] ?? 12 ) );
}

function ew_claim_pass_url( $id ) {
	return add_query_arg(
		array(
			'pass' => (string) get_post_meta( $id, '_ewt_code', true ),
			'k'    => (string) get_post_meta( $id, '_ewt_key', true ),
		),
		get_permalink( (int) get_post_meta( $id, '_ewt_page', true ) )
	);
}

function ew_claim_tick_svg() {
	return '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';
}

function ew_claim_pk() {
	return function_exists( 'ew_stripe_creds' ) ? (string) ( ew_stripe_creds()['publishable'] ?? '' ) : '';
}

/* --- Step 3: the shirt offer ------------------------------------------ */

function ew_claim_offer_html( $a, $id ) {
	$rows = ew_claim_offer_rows( $a );
	if ( ! $rows || '' === ew_claim_pk() || ! function_exists( 'ew_tix_stripe' ) ) {
		return '';
	}
	$img = ew_claim_img( $a['offer_img'] );
	$h   = '<div class="ewc-offer" data-rest="' . esc_url( rest_url( 'eastwood/v1/claim' ) ) . '" data-code="' . esc_attr( get_post_meta( $id, '_ewt_code', true ) ) . '" data-k="' . esc_attr( get_post_meta( $id, '_ewt_key', true ) ) . '" data-pk="' . esc_attr( ew_claim_pk() ) . '" data-loading="' . esc_attr( $a['offer_loading'] ) . '" data-failed="' . esc_attr( $a['failed'] ) . '">';
	$h  .= '<p class="ewc-tickline">' . ew_claim_tick_svg() . esc_html( $a['offer_tick'] ) . '</p>';
	$h  .= '<p class="ewc-otitle">' . esc_html( $a['offer_title'] ) . '</p>';
	if ( '' !== $a['offer_label'] ) {
		$h .= '<p class="ewc-olab">' . esc_html( $a['offer_label'] ) . '</p>';
	}
	foreach ( $rows as $i => $r ) {
		$h .= '<div class="ewc-orow" data-i="' . (int) $i . '" data-pay="' . esc_attr( str_replace( '{price}', ew_tix_money( $r['pence'] ), $a['offer_pay'] ) ) . '">';
		$h .= '<button type="button" class="ewc-otop" aria-pressed="false"><span class="ewc-obox"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#101010" stroke-width="3" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>';
		if ( '' !== $img ) {
			$h .= '<img src="' . esc_url( $img ) . '" alt="">';
		}
		$h .= '<span class="ewc-oname">' . esc_html( $r['title'] ) . '</span><span class="ewc-oprice">' . esc_html( ew_tix_money( $r['pence'] ) ) . '</span></button>';
		$h .= '<div class="ewc-osizes" hidden>';
		if ( '' !== $r['label'] ) {
			$h .= '<span class="ewc-oslab">' . esc_html( $r['label'] ) . '</span>';
		}
		foreach ( $r['sizes'] as $s ) {
			$h .= '<button type="button" data-size="' . esc_attr( $s['size'] ) . '"' . ( $s['in'] ? '' : ' disabled' ) . '>' . esc_html( $s['short'] ) . '</button>';
		}
		$h .= '</div></div>';
	}
	$h .= '<p class="ewc-err" role="alert"></p>';
	$h .= '<button type="button" class="ewc-btn ewc-opay" disabled>' . esc_html( trim( str_replace( '{price}', '', $a['offer_pay'] ) ) ) . '</button>';
	$h .= '<div class="ewc-mount"></div><p class="ewc-omsg"></p>';
	$h .= '<p class="ewc-oskip"><button type="button">' . esc_html( $a['offer_skip'] ) . '</button></p>';
	return $h . '</div>';
}

/* --- Back from paying: the shirt is paid, offer the printing ------------ */

/** The shirt order this claim paid for, once it is paid. */
function ew_claim_shirt_booking( $id ) {
	$bid = (int) get_post_meta( $id, '_ewt_shirt_booking', true );
	return ( $bid && 'paid' === get_post_meta( $bid, '_ewt_status', true ) ) ? $bid : 0;
}

/** A Stripe session for this claim, if the link is one and it is paid. */
function ew_claim_session( $id, $sid, $kind ) {
	if ( ! preg_match( '/^cs_[A-Za-z0-9_]+$/', (string) $sid ) || ! function_exists( 'ew_tix_stripe' ) || '' === ew_tix_sk() ) {
		return array();
	}
	$s = ew_tix_stripe( 'GET', 'checkout/sessions/' . rawurlencode( $sid ) );
	if ( empty( $s['id'] ) || 'paid' !== ( $s['payment_status'] ?? '' )
		|| (string) ( $s['metadata']['claim'] ?? '' ) !== (string) (int) $id
		|| (string) ( $s['metadata']['kind'] ?? '' ) !== $kind ) {
		return array();
	}
	return $s;
}

/** Write printing onto the shirt order. */
function ew_claim_record_print( $bid, $label, $pence ) {
	if ( '' !== (string) get_post_meta( $bid, '_ewt_print', true ) ) {
		return;
	}
	update_post_meta( $bid, '_ewt_print', $label );
	update_post_meta( $bid, '_ewt_print_paid', (int) $pence );
	update_post_meta( $bid, '_ewt_extra', 'Printing · ' . $label . ' · ' . ew_tix_money( (int) $pence ) );
	update_post_meta( $bid, '_ewt_lines', trim( (string) get_post_meta( $bid, '_ewt_lines', true ) . ' · printed ' . $label ) );
	update_post_meta( $bid, '_ewt_paid', (int) get_post_meta( $bid, '_ewt_paid', true ) + (int) $pence );
}

/**
 * Called at the top of the claim block when a pass link comes back from
 * Stripe. Records what was paid. Returns the whole block when the printing
 * offer is next, or '' to let the pass show as usual.
 */
function ew_claim_shirt_page( $a, $id ) {
	// phpcs:disable WordPress.Security.NonceVerification
	$shirt = sanitize_text_field( wp_unslash( $_GET['ewc_paid'] ?? '' ) );
	$print = sanitize_text_field( wp_unslash( $_GET['ewc_print'] ?? '' ) );
	// phpcs:enable

	if ( '' !== $print ) {
		$s   = ew_claim_session( $id, $print, 'print' );
		$bid = $s ? (int) ( $s['metadata']['booking'] ?? 0 ) : 0;
		if ( $bid && (int) get_post_meta( $id, '_ewt_shirt_booking', true ) === $bid ) {
			ew_claim_record_print( $bid, (string) ( $s['metadata']['label'] ?? '' ), (int) ( $s['amount_total'] ?? 0 ) );
		}
		return '';
	}
	if ( '' === $shirt ) {
		return '';
	}
	$s = ew_claim_session( $id, $shirt, 'shirt' );
	if ( ! $s ) {
		return '';
	}
	$bid = (int) ( $s['metadata']['booking'] ?? 0 );
	if ( ! $bid || 'ewt_booking' !== get_post_type( $bid ) ) {
		return '';
	}
	if ( function_exists( 'ew_shirt_mark_paid' ) ) {
		ew_shirt_mark_paid( $s['id'], (string) get_post_meta( $bid, '_ewt_hook', true ) );
	}
	update_post_meta( $id, '_ewt_shirt_booking', $bid );
	update_post_meta( $bid, '_ewt_claim', $id );

	$pp = ew_claim_print_pence( $a );
	if ( $pp <= 0 || '' !== (string) get_post_meta( $bid, '_ewt_print', true )
		|| time() - (int) ( $s['created'] ?? 0 ) > 2 * HOUR_IN_SECONDS ) {
		return '';
	}

	$bg  = ew_claim_img( $a['background'] );
	$h   = '<section class="ewc" data-page="' . (int) get_post_meta( $id, '_ewt_page', true ) . '">';
	$h  .= '<div class="ewc-tk"' . ( '' !== $bg ? ' style="background-image:url(' . esc_url( $bg ) . ')"' : '' ) . '>';
	$h  .= '<div class="ewc-match ewc-shirtside"><div class="ewc-prev"><img src="' . esc_url( ew_claim_img( $a['print_back'] ) ) . '" alt="" width="700" height="764"><b></b><i></i></div></div>';
	$h  .= '<div class="ewc-stub">' . ew_claim_print_html( $a, $id, $bid, $pp, (int) get_post_meta( $bid, '_ewt_paid', true ) ) . '</div></div>';
	$h  .= '<div class="ewc-after" hidden>' . ew_claim_extras_html( $a ) . '</div>';
	return $h . '</section>';
}

function ew_claim_print_html( $a, $id, $bid, $pp, $paid ) {
	$what  = (string) get_post_meta( $bid, '_ewt_shirt_what', true );
	$size  = (string) get_post_meta( $bid, '_ewt_shirt_size', true );
	$word  = (string) get_post_meta( $bid, '_ewt_shirt_word', true );
	$max   = ew_claim_print_max( $a );
	$squad = ew_claim_squad( $a );

	$h  = '<div class="ewc-print" data-rest="' . esc_url( rest_url( 'eastwood/v1/claim/print' ) ) . '" data-code="' . esc_attr( get_post_meta( $id, '_ewt_code', true ) ) . '" data-k="' . esc_attr( get_post_meta( $id, '_ewt_key', true ) ) . '" data-pk="' . esc_attr( ew_claim_pk() ) . '" data-pass="' . esc_url( ew_claim_pass_url( $id ) ) . '"';
	$h .= ' data-add="' . esc_attr( str_replace( '{price}', ew_tix_money( $pp ), $a['print_add'] ) ) . '" data-go="' . esc_attr( $a['print_go'] ) . '" data-prints="' . esc_attr( $a['print_prints'] ) . '" data-and="' . esc_attr( $a['print_and'] ) . '" data-chars="' . esc_attr( $a['print_chars'] ) . '" data-max="' . (int) $max . '" data-loading="' . esc_attr( $a['offer_loading'] ) . '" data-failed="' . esc_attr( $a['failed'] ) . '">';
	$h .= '<p class="ewc-tickline">' . ew_claim_tick_svg() . esc_html( str_replace( array( '{price}', '{word}' ), array( ew_tix_money( $paid ), $word ), $a['paid_tick'] ) ) . '</p>';
	$h .= '<p class="ewc-ptitle">' . esc_html( $a['print_shirt'] ) . '</p>';
	$h .= '<p class="ewc-psub">' . esc_html( str_replace( array( '{what}', '{size}' ), array( $what, $size ), $a['print_sub'] ) ) . '</p>';
	$h .= '<div class="ewc-pbox"><p class="ewc-plab">' . esc_html( $a['print_title'] ) . '</p>';
	$h .= '<div class="ewc-pmodes" role="group">';
	foreach ( array( 'player' => $a['print_players'], 'custom' => $a['print_custom'], 'none' => $a['print_none'] ) as $m => $label ) {
		$h .= '<button type="button" data-m="' . $m . '"' . ( 'player' === $m ? ' class="on" aria-pressed="true"' : ' aria-pressed="false"' ) . '>' . esc_html( $label ) . '</button>';
	}
	$h .= '</div>';
	$h .= '<div class="ewc-pplayer"><select class="ewc-psel"><option value="">' . esc_html( $a['print_pick'] ) . '</option>';
	foreach ( $squad as $i => $p ) {
		$h .= '<option value="' . (int) $i . '" data-name="' . esc_attr( $p['last'] ) . '" data-num="' . esc_attr( $p['n'] ) . '">' . esc_html( $p['n'] . ' · ' . $p['w'] ) . '</option>';
	}
	$h .= '</select><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#101010" stroke-width="2" aria-hidden="true"><path d="M5 9l7 7 7-7"/></svg></div>';
	$h .= '<div class="ewc-pcustom" hidden><div class="ewc-pf"><label for="ewcPName">' . esc_html( $a['print_name'] ) . '</label><input id="ewcPName" type="text" maxlength="' . (int) $max . '" autocomplete="off"><small class="ewc-pcount"></small></div>';
	$h .= '<div class="ewc-pf ewc-pfnum"><label for="ewcPNum">' . esc_html( $a['print_num'] ) . '</label><input id="ewcPNum" type="text" inputmode="numeric" maxlength="2" autocomplete="off"><small>' . esc_html( $a['print_range'] ) . '</small></div></div>';
	$h .= '<p class="ewc-pwhat"></p></div>';
	$h .= '<p class="ewc-err" role="alert"></p>';
	$h .= '<button type="button" class="ewc-btn ewc-padd" disabled>' . esc_html( str_replace( '{price}', ew_tix_money( $pp ), $a['print_add'] ) ) . '</button>';
	$h .= '<p class="ewc-pcard">' . esc_html( $a['print_card'] ) . ' &middot; <button type="button" class="ewc-pother">' . esc_html( $a['print_other'] ) . '</button></p>';
	$h .= '<div class="ewc-mount"></div><p class="ewc-omsg"></p>';
	$h .= '<p class="ewc-pwarn">' . esc_html( $a['print_warn'] );
	if ( '' !== $a['terms_url'] ) {
		$h .= ' <a href="' . esc_url( $a['terms_url'] ) . '">' . esc_html( $a['terms_word'] ) . '</a>';
	}
	return $h . '</p></div>';
}

/** Under the QR: what this pass came with, and what they bought. */
function ew_claim_receipt_html( $a, $id ) {
	$bid = ew_claim_shirt_booking( $id );
	if ( ! $bid ) {
		return '';
	}
	$party = ew_claim_party( $a, (int) get_post_meta( $id, '_ewt_adults', true ), (int) get_post_meta( $id, '_ewt_children', true ) );
	$pp    = (int) get_post_meta( $bid, '_ewt_print_paid', true );
	$paid  = (int) get_post_meta( $bid, '_ewt_paid', true );
	$rows  = array( array( $party, $a['rec_free'] ) );
	$rows[] = array(
		str_replace( array( '{what}', '{size}' ), array( (string) get_post_meta( $bid, '_ewt_shirt_what', true ), (string) get_post_meta( $bid, '_ewt_shirt_size', true ) ), $a['rec_shirt'] ),
		ew_tix_money( $paid - $pp ),
	);
	if ( $pp > 0 ) {
		$rows[] = array( str_replace( '{what}', (string) get_post_meta( $bid, '_ewt_print', true ), $a['rec_print'] ), ew_tix_money( $pp ) );
	}
	$h = '<div class="ewc-rec">';
	foreach ( $rows as $r ) {
		$h .= '<p><span>' . esc_html( $r[0] ) . '</span><span>' . esc_html( $r[1] ) . '</span></p>';
	}
	return $h . '<p class="ewc-rec-t"><span>' . esc_html( $a['rec_total'] ) . '</span><span>' . esc_html( ew_tix_money( $paid ) ) . '</span></p></div>';
}

/* --- The two endpoints ------------------------------------------------- */

/** The claim a request names, with its block, or an error. */
function ew_claim_from_req( $req ) {
	$id = ew_claim_find( (string) $req->get_param( 'code' ), (string) $req->get_param( 'k' ) );
	if ( ! $id ) {
		return new WP_Error( 'ewc_pass', 'not found', array( 'status' => 404 ) );
	}
	$page  = get_post( (int) get_post_meta( $id, '_ewt_page', true ) );
	$block = $page ? ew_claim_block_on( $page ) : null;
	if ( ! $block ) {
		return new WP_Error( 'ewc_block', 'not found', array( 'status' => 404 ) );
	}
	return array( $id, $page, $block['atts'] );
}

/** Stripe's form for one thing. Returns the client secret or an error. */
function ew_claim_checkout( $body ) {
	$j = ew_tix_stripe( 'POST', 'checkout/sessions', $body );
	if ( empty( $j['client_secret'] ) ) {
		return new WP_Error( 'ewc_stripe', (string) ( $j['error']['message'] ?? 'Stripe refused.' ), array( 'status' => 400 ) );
	}
	return $j;
}

add_action( 'rest_api_init', function () {
	/* Pay for a shirt from the claim. */
	register_rest_route( 'eastwood/v1', '/claim/shirt', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) {
			if ( ! function_exists( 'ew_tix_stripe' ) || '' === ew_tix_sk() ) {
				return new WP_Error( 'ewc_off', 'Card payment is not switched on.', array( 'status' => 503 ) );
			}
			$got = ew_claim_from_req( $req );
			if ( is_wp_error( $got ) ) {
				return $got;
			}
			list( $id, $page, $a ) = $got;
			$rows = ew_claim_offer_rows( $a );
			$r    = $rows[ (int) $req->get_param( 'row' ) ] ?? null;
			$size = (string) $req->get_param( 'size' );
			$ok   = false;
			$short = '';
			foreach ( (array) ( $r['sizes'] ?? array() ) as $s ) {
				if ( $s['size'] === $size && $s['in'] ) {
					$ok    = true;
					$short = $s['short'];
				}
			}
			if ( ! $r || ! $ok ) {
				return new WP_Error( 'ewc_size', 'That size has gone.', array( 'status' => 409 ) );
			}
			$src   = ew_claim_shirt_src( $a );
			$hook  = (string) ( $src['crm_hook'] ?? '' );
			$name  = (string) get_post_meta( $id, '_ewt_name', true );
			$email = (string) get_post_meta( $id, '_ewt_email', true );
			$what  = '' !== $r['name'] ? $r['name'] : $r['title'];

			$bid = function_exists( 'ew_tix_booking_start' )
				? (int) ew_tix_booking_start( $page, $what, $name, $email, $size . ' · collecting · claim ' . get_post_meta( $id, '_ewt_code', true ), $r['pence'], $hook, (string) ( $src['greeting'] ?? '' ) )
				: 0;
			if ( $bid ) {
				update_post_meta( $bid, '_ewt_shirt_what', $r['title'] );
				update_post_meta( $bid, '_ewt_shirt_size', $short );
				update_post_meta( $bid, '_ewt_shirt_word', $r['word'] );
				update_post_meta( $bid, '_ewt_claim', $id );
				if ( 'yes' === get_post_meta( $id, '_ewt_consent', true ) ) {
					update_post_meta( $bid, '_ewt_consent', 'yes' );
				}
			}

			// A customer, so the printing can go on the same card in one tap.
			$cus = ew_tix_stripe( 'POST', 'customers', array(
				'name'              => $name,
				'email'             => $email,
				'metadata[booking]' => (string) $bid,
			) );

			$back = add_query_arg( 'ewc_paid', '{CHECKOUT_SESSION_ID}', ew_claim_pass_url( $id ) );
			$body = array(
				'ui_mode'                                       => 'embedded_page',
				'mode'                                          => 'payment',
				'return_url'                                    => $back,
				'metadata[booking]'                             => (string) $bid,
				'metadata[claim]'                               => (string) $id,
				'metadata[kind]'                                => 'shirt',
				'metadata[page]'                                => (string) $page->ID,
				'metadata[sku]'                                 => $r['sku'],
				'metadata[size]'                                => $size,
				'metadata[delivery]'                            => 'collect',
				'line_items[0][quantity]'                       => '1',
				'line_items[0][price_data][currency]'           => 'gbp',
				'line_items[0][price_data][unit_amount]'        => (string) $r['pence'],
				'line_items[0][price_data][product_data][name]' => $what . ' · ' . $short,
			);
			if ( ! empty( $cus['id'] ) ) {
				$body['customer']                                = $cus['id'];
				$body['payment_intent_data[setup_future_usage]'] = 'off_session';
				if ( $bid ) {
					update_post_meta( $bid, '_ewt_customer', $cus['id'] );
				}
			} else {
				$body['customer_email'] = $email;
			}
			$hide = array_filter( array_map(
				function ( $v ) {
					return preg_replace( '/[^a-z_]/', '', strtolower( trim( $v ) ) );
				},
				explode( ',', (string) ( $src['hide_pay'] ?? '' ) )
			) );
			foreach ( array_values( $hide ) as $k => $m ) {
				$body[ "excluded_payment_method_types[$k]" ] = $m;
			}
			if ( in_array( strtolower( (string) ( $src['promos'] ?? '' ) ), array( 'yes', 'true', '1', 'on' ), true ) ) {
				$body['allow_promotion_codes'] = 'true';
			}
			$j = ew_claim_checkout( $body );
			if ( is_wp_error( $j ) ) {
				return $j;
			}
			if ( $bid ) {
				update_post_meta( $bid, '_ewt_open_session', (string) $j['id'] );
			}
			return array( 'clientSecret' => $j['client_secret'] );
		},
	) );

	/* Printing on the shirt just bought: one tap, or another card. */
	register_rest_route( 'eastwood/v1', '/claim/print', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) {
			if ( ! function_exists( 'ew_tix_stripe' ) || '' === ew_tix_sk() ) {
				return new WP_Error( 'ewc_off', 'Card payment is not switched on.', array( 'status' => 503 ) );
			}
			$got = ew_claim_from_req( $req );
			if ( is_wp_error( $got ) ) {
				return $got;
			}
			list( $id, $page, $a ) = $got;
			$bid = ew_claim_shirt_booking( $id );
			$pp  = ew_claim_print_pence( $a );
			if ( ! $bid || $pp <= 0 ) {
				return new WP_Error( 'ewc_print', $a['failed'], array( 'status' => 400 ) );
			}
			if ( '' !== (string) get_post_meta( $bid, '_ewt_print', true ) ) {
				return array( 'ok' => true, 'pass' => ew_claim_pass_url( $id ) );
			}

			// What goes on the back, worked out here so the browser cannot
			// print something that was not on the page.
			$max = ew_claim_print_max( $a );
			if ( 'player' === $req->get_param( 'mode' ) ) {
				$squad = ew_claim_squad( $a );
				$p     = $squad[ (int) $req->get_param( 'player' ) ] ?? null;
				if ( ! $p ) {
					return new WP_Error( 'ewc_print', $a['print_bad'], array( 'status' => 400 ) );
				}
				$pname = substr( $p['last'], 0, $max );
				$pnum  = $p['n'];
			} else {
				$pname = substr( strtoupper( trim( preg_replace( "/[^A-Za-z '-]/", '', (string) $req->get_param( 'name' ) ) ) ), 0, $max );
				$pnum  = (string) (int) preg_replace( '/[^0-9]/', '', (string) $req->get_param( 'num' ) );
				$pnum  = ( (int) $pnum > 0 && (int) $pnum < 100 ) ? $pnum : '';
			}
			if ( '' === $pname && '' === $pnum ) {
				return new WP_Error( 'ewc_print', $a['print_bad'], array( 'status' => 400 ) );
			}
			$label = trim( $pname . ' ' . $pnum );

			$sid = (string) get_post_meta( $bid, '_ewt_session', true );
			$s   = $sid ? ew_tix_stripe( 'GET', 'checkout/sessions/' . rawurlencode( $sid ) . '?expand[]=payment_intent' ) : array();
			$cus = is_array( $s['customer'] ?? null ) ? (string) ( $s['customer']['id'] ?? '' ) : (string) ( $s['customer'] ?? '' );

			if ( $req->get_param( 'card' ) ) {
				$body = array(
					'ui_mode'                                       => 'embedded_page',
					'mode'                                          => 'payment',
					'return_url'                                    => add_query_arg( 'ewc_print', '{CHECKOUT_SESSION_ID}', ew_claim_pass_url( $id ) ),
					'metadata[booking]'                             => (string) $bid,
					'metadata[claim]'                               => (string) $id,
					'metadata[kind]'                                => 'print',
					'metadata[page]'                                => (string) $page->ID,
					'metadata[label]'                               => $label,
					'line_items[0][quantity]'                       => '1',
					'line_items[0][price_data][currency]'           => 'gbp',
					'line_items[0][price_data][unit_amount]'        => (string) $pp,
					'line_items[0][price_data][product_data][name]' => 'Printing · ' . $label,
				);
				if ( '' !== $cus ) {
					$body['customer'] = $cus;
				} else {
					$body['customer_email'] = (string) get_post_meta( $id, '_ewt_email', true );
				}
				$j = ew_claim_checkout( $body );
				return is_wp_error( $j ) ? $j : array( 'clientSecret' => $j['client_secret'] );
			}

			if ( time() - (int) ( $s['created'] ?? 0 ) > 2 * HOUR_IN_SECONDS ) {
				return new WP_Error( 'ewc_print', $a['print_late'], array( 'status' => 410 ) );
			}
			$pi = (array) ( $s['payment_intent'] ?? array() );
			$pm = is_array( $pi['payment_method'] ?? null ) ? (string) ( $pi['payment_method']['id'] ?? '' ) : (string) ( $pi['payment_method'] ?? '' );
			if ( '' === $pm || '' === $cus ) {
				return new WP_Error( 'ewc_print', $a['print_declined'], array( 'status' => 400 ) );
			}
			$p = ew_tix_stripe( 'POST', 'payment_intents', array(
				'amount'            => (string) $pp,
				'currency'          => 'gbp',
				'customer'          => $cus,
				'payment_method'    => $pm,
				'off_session'       => 'true',
				'confirm'           => 'true',
				'description'       => 'Printing · ' . $label,
				'receipt_email'     => (string) get_post_meta( $id, '_ewt_email', true ),
				'metadata[booking]' => (string) $bid,
				'metadata[claim]'   => (string) $id,
				'metadata[kind]'    => 'print',
			) );
			if ( 'succeeded' !== ( $p['status'] ?? '' ) ) {
				return new WP_Error( 'ewc_print', $a['print_declined'], array( 'status' => 402 ) );
			}
			ew_claim_record_print( $bid, $label, $pp );
			return array( 'ok' => true, 'pass' => ew_claim_pass_url( $id ) );
		},
	) );
} );

/* --- Look and behaviour ------------------------------------------------ */

function ew_claim_shirt_css() {
	return '
.ewc .ewc-tickline{display:flex;align-items:center;gap:10px;margin:0 0 14px;font-size:15px;font-weight:600;color:#fff}
.ewc .ewc-otitle{margin:0 0 12px;font-family:Anton,Impact,sans-serif;font-size:32px;line-height:1.05;text-transform:uppercase;color:#fff}
.ewc .ewc-olab{margin:0 0 6px;font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#cfcfcf}
.ewc-orow{border:1.5px solid rgba(255,255,255,.25);border-radius:10px;padding:9px 12px;margin-bottom:8px}
.ewc-orow.on{border:2px solid #fff;background:rgba(255,255,255,.08)}
.ewc-otop{display:flex;align-items:center;gap:12px;width:100%;background:none;border:0;padding:0;color:#fff;font:inherit;text-align:left;cursor:pointer}
.ewc-obox{width:22px;height:22px;border-radius:5px;border:1.5px solid rgba(255,255,255,.55);display:flex;align-items:center;justify-content:center;flex:0 0 auto}
.ewc-obox svg{visibility:hidden}
.ewc-orow.on .ewc-obox{background:#fff;border-color:#fff}
.ewc-orow.on .ewc-obox svg{visibility:visible}
.ewc-otop img{width:44px;height:48px;object-fit:contain;background:#f2f2f2;border-radius:6px;flex:0 0 auto}
.ewc-oname{flex:1 1 auto;min-width:0;font-family:Anton,Impact,sans-serif;font-size:18px;line-height:1.1;text-transform:uppercase}
.ewc-oprice{font-family:Anton,Impact,sans-serif;font-size:20px}
.ewc-osizes{display:flex;flex-wrap:wrap;gap:6px;margin:10px 0 2px 34px}
.ewc-oslab{font-size:12px;font-weight:700;color:#cfcfcf;align-self:center;margin-right:4px;text-transform:uppercase}
.ewc-osizes button{border:1.5px solid rgba(255,255,255,.4);background:none;color:#fff;border-radius:6px;padding:5px 10px;font:inherit;font-size:13px;font-weight:700;cursor:pointer}
.ewc-osizes button.on{background:#fff;color:#101010;border-color:#fff}
.ewc-osizes button[disabled]{opacity:.35;text-decoration:line-through;cursor:not-allowed}
.ewc-opay{margin-top:8px}
.ewc-btn[disabled].ewc-opay,.ewc-btn[disabled].ewc-padd{opacity:1;background:#e6e6e8;color:#9b9b9f}
.ewc-mount{background:#fff;border-radius:8px;margin-top:10px;overflow:hidden}
.ewc-mount:empty{display:none}
.ewc .ewc-omsg{margin:8px 0 0;font-size:13px;color:#cfcfcf;text-align:center}
.ewc .ewc-omsg:empty{display:none}
.ewc .ewc-oskip{margin:12px 0 0;text-align:center}
.ewc-oskip button,.ewc-pother{background:none;border:0;padding:0;font:inherit;font-size:14px;color:#cfcfcf;text-decoration:underline;cursor:pointer}
.ewc-pother{font-size:13px;color:#fff}
.ewc-shirtside{justify-content:center;align-items:center}
.ewc-prev{position:relative;width:460px;max-width:100%}
.ewc-prev img{display:block;width:100%;height:auto}
.ewc-prev b,.ewc-prev i{position:absolute;left:0;right:0;text-align:center;color:#fff;-webkit-text-fill-color:#fff;-webkit-text-stroke:3.9px rgba(40,0,6,.9);paint-order:stroke;font-family:Oswald,"Instrument Sans",sans-serif;font-weight:700}
.ewc-prev b{top:17%;font-size:46px;line-height:34px;text-transform:uppercase}
.ewc-prev i{top:36%;font-style:normal;font-size:170px;line-height:124px}
.ewc .ewc-ptitle{margin:0;font-family:Anton,Impact,sans-serif;font-size:34px;line-height:1.05;text-transform:uppercase;color:#fff}
.ewc .ewc-psub{margin:2px 0 16px;font-size:14px;color:#cfcfcf}
.ewc-pbox{background:#fff;color:#101010;border:1.5px solid #e2e2e2;border-radius:8px;padding:16px 18px;margin-bottom:16px}
.ewc .ewc-plab{margin:0 0 10px;font-size:16px;font-weight:700;color:#101010}
.ewc-pmodes{display:flex;gap:10px}
.ewc-pmodes button{flex:1 1 0;min-width:0;font:inherit;font-size:15px;font-weight:700;padding:14px 6px;border-radius:0;background:#fff;color:#101010;border:1px solid #8a8a8a;cursor:pointer}
.ewc-pmodes button.on{background:#000;color:#fff;border-color:#000}
.ewc-pplayer{position:relative;margin-top:12px}
.ewc-psel{width:100%;font:inherit;font-size:15px;background:#fff;color:#101010;border:1px solid #8a8a8a;border-radius:6px;padding:14px 40px 14px 14px;appearance:none;-webkit-appearance:none}
.ewc-pplayer svg{position:absolute;right:14px;top:50%;transform:translateY(-50%);pointer-events:none}
.ewc-pcustom{display:flex;gap:12px;margin-top:14px}
.ewc-pf{flex:1 1 auto;min-width:0}
.ewc-pfnum{flex:0 0 90px}
.ewc-pf label{display:block;margin:0 0 8px;font-size:13px;font-weight:600}
.ewc-pf input{width:100%;font:inherit;font-size:15px;background:#fff;color:#101010;border:1px solid #d6d6d6;border-radius:6px;padding:13px 14px}
#ewcPName{font-weight:600;letter-spacing:1.2px;text-transform:uppercase}
.ewc-pf small{display:block;margin:7px 0 0;font-size:12px;color:#777}
.ewc .ewc-pwhat{margin:10px 0 0;font-size:14px;color:#101010}
.ewc .ewc-pwhat:empty{display:none}
.ewc .ewc-pcard{margin:8px 0 0;text-align:center;font-size:13px;color:#cfcfcf}
.ewc .ewc-pwarn{margin:18px 0 0;text-align:center;font-size:13px;color:#9a9aa3}
.ewc-pwarn a{color:#fff}
.ewc-rec{font-size:13px;color:#e6e6e6;margin-top:16px}
.ewc .ewc-rec p{margin:0;padding:6px 0;border-top:1px solid rgba(255,255,255,.25);display:flex;justify-content:space-between;gap:12px}
.ewc .ewc-rec .ewc-rec-t{border-bottom:1px solid rgba(255,255,255,.25);font-family:Anton,Impact,sans-serif;font-size:18px;color:#fff;text-transform:uppercase}
@media(max-width:900px){.ewc-shirtside{padding:22px}.ewc-prev{width:300px}.ewc-prev b{font-size:30px;line-height:22px}.ewc-prev i{font-size:110px;line-height:81px}}';
}

function ew_claim_shirt_js() {
	return <<<'JS'
(function(){
 function stripeForm(pk, url, body, mount, msg, words){
  if(!window.Stripe || !pk){ msg.textContent = words.failed; return Promise.resolve(null); }
  msg.textContent = words.loading;
  var s = window.Stripe(pk), make = s.createEmbeddedCheckoutPage || s.initEmbeddedCheckout;
  return make.call(s, {fetchClientSecret: function(){
   return fetch(url, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)})
    .then(function(r){ return r.json(); })
    .then(function(j){ if(!j.clientSecret) throw new Error(j.message || words.failed); return j.clientSecret; });
  }}).then(function(c){ msg.textContent = ''; c.mount(mount); return c; })
    .catch(function(e){ msg.textContent = (e && e.message) || words.failed; return null; });
 }

 /* The shirt offer, put in the stub straight after a claim. */
 function offer(el, pass){
  var D = el.dataset, rows = el.querySelectorAll('.ewc-orow'), pay = el.querySelector('.ewc-opay'),
      mount = el.querySelector('.ewc-mount'), msg = el.querySelector('.ewc-omsg'),
      row = -1, size = '', checkout = null;
  function reset(){ if(checkout){ checkout.destroy(); checkout = null; } mount.innerHTML = ''; msg.textContent = ''; pay.hidden = false; }
  function paint(){
   rows.forEach(function(r, i){
    var on = i === row;
    r.classList.toggle('on', on);
    r.querySelector('.ewc-otop').setAttribute('aria-pressed', on ? 'true' : 'false');
    r.querySelector('.ewc-osizes').hidden = !on;
    r.querySelectorAll('.ewc-osizes button').forEach(function(b){ b.classList.toggle('on', on && b.getAttribute('data-size') === size); });
   });
   pay.disabled = !(row > -1 && size);
   if(row > -1){ pay.textContent = rows[row].getAttribute('data-pay'); }
  }
  rows.forEach(function(r, i){
   r.querySelector('.ewc-otop').addEventListener('click', function(){
    if(row !== i){ row = i; size = ''; reset(); paint(); }
   });
   r.querySelectorAll('.ewc-osizes button').forEach(function(b){
    b.addEventListener('click', function(){ size = b.getAttribute('data-size'); reset(); paint(); });
   });
  });
  pay.addEventListener('click', function(){
   if(pay.disabled || checkout) return;
   pay.hidden = true;
   stripeForm(D.pk, D.rest + '/shirt', {code: D.code, k: D.k, row: row, size: size}, mount, msg, {loading: D.loading, failed: D.failed})
    .then(function(c){ if(c){ checkout = c; } else { pay.hidden = false; } });
  });
  el.querySelector('.ewc-oskip button').addEventListener('click', function(){
   reset();
   var s3 = el.parentNode; s3.innerHTML = pass;
   var root = s3.closest('.ewc'), after = root && root.querySelector('.ewc-after');
   if(after) after.hidden = false;
   if(window.ewcRedraw) window.ewcRedraw();
  });
  paint();
 }
 window.ewcOffer = offer;

 /* Printing, after the shirt is paid. */
 var P = document.querySelector('.ewc-print');
 if(!P) return;
 var D = P.dataset, mode = 'player', checkout = null,
     sel = P.querySelector('.ewc-psel'), nm = P.querySelector('#ewcPName'), nu = P.querySelector('#ewcPNum'),
     add = P.querySelector('.ewc-padd'), what = P.querySelector('.ewc-pwhat'), err = P.querySelector('.ewc-err'),
     mount = P.querySelector('.ewc-mount'), msg = P.querySelector('.ewc-omsg'), card = P.querySelector('.ewc-pcard'),
     root = P.closest('.ewc'), pvName = root.querySelector('.ewc-prev b'), pvNum = root.querySelector('.ewc-prev i');
 function esc(s){ return String(s).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
 function chosen(){
  if('player' === mode){
   var o = sel.options[sel.selectedIndex];
   return o && o.value !== '' ? {name: o.getAttribute('data-name'), num: o.getAttribute('data-num')} : {name:'', num:''};
  }
  if('custom' === mode){
   return {name: (nm.value || '').toUpperCase().replace(/[^A-Z '-]/g, '').trim(), num: (nu.value || '').replace(/[^0-9]/g, '')};
  }
  return {name:'', num:''};
 }
 function reset(){ if(checkout){ checkout.destroy(); checkout = null; } mount.innerHTML = ''; msg.textContent = ''; }
 function paint(){
  var c = chosen(), any = !!(c.name || c.num);
  pvName.textContent = c.name; pvNum.textContent = c.num;
  var bits = []; if(c.name) bits.push('<strong>' + esc(c.name) + '</strong>'); if(c.num) bits.push('<strong>' + esc(c.num) + '</strong>');
  what.innerHTML = any ? esc(D.prints).replace('{what}', bits.join(' ' + esc(D.and) + ' ')) : '';
  P.querySelector('.ewc-pcount').textContent = String(D.chars).replace('{n}', (nm.value || '').length).replace('{max}', D.max);
  add.textContent = 'none' === mode ? D.go : D.add;
  add.disabled = 'none' !== mode && !any;
  card.hidden = 'none' === mode;
  add.hidden = !!checkout;
 }
 P.querySelectorAll('.ewc-pmodes button').forEach(function(b){
  b.addEventListener('click', function(){
   mode = b.getAttribute('data-m');
   P.querySelectorAll('.ewc-pmodes button').forEach(function(x){ var on = x === b; x.classList.toggle('on', on); x.setAttribute('aria-pressed', on ? 'true' : 'false'); });
   P.querySelector('.ewc-pplayer').hidden = 'player' !== mode;
   P.querySelector('.ewc-pcustom').hidden = 'custom' !== mode;
   err.textContent = ''; reset(); paint();
  });
 });
 [sel, nm, nu].forEach(function(x){ x.addEventListener('input', function(){ reset(); paint(); }); x.addEventListener('change', paint); });
 function body(extra){
  var b = {code: D.code, k: D.k, mode: mode, player: sel.value, name: nm.value, num: nu.value};
  for(var k in extra){ b[k] = extra[k]; }
  return b;
 }
 add.addEventListener('click', function(){
  if(add.disabled) return;
  if('none' === mode){ location.href = D.pass; return; }
  add.disabled = true; err.textContent = '';
  fetch(D.rest, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body({}))})
   .then(function(r){ return r.json(); })
   .then(function(j){ if(j.ok && j.pass){ location.href = j.pass; return; } err.textContent = j.message || D.failed; add.disabled = false; })
   .catch(function(){ err.textContent = D.failed; add.disabled = false; });
 });
 P.querySelector('.ewc-pother').addEventListener('click', function(){
  var c = chosen();
  if('none' === mode || !(c.name || c.num) || checkout) return;
  err.textContent = ''; add.hidden = true;
  stripeForm(D.pk, D.rest, body({card: 1}), mount, msg, {loading: D.loading, failed: D.failed})
   .then(function(co){ if(co){ checkout = co; } else { add.hidden = false; } });
 });
 paint();
})();
JS;
}

add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_singular() ) {
		return;
	}
	$post = get_post();
	if ( ! $post || ! function_exists( 'ew_post_contains' ) || ! ew_post_contains( $post, 'eastwood_claim' ) ) {
		return;
	}
	wp_enqueue_style( 'ewc-oswald', 'https://fonts.googleapis.com/css2?family=Oswald:wght@700&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
	wp_register_style( 'ew-claim-shirt', false, array(), EW_FWP_VERSION );
	wp_enqueue_style( 'ew-claim-shirt' );
	wp_add_inline_style( 'ew-claim-shirt', ew_claim_shirt_css() );
	$deps = array();
	if ( '' !== ew_claim_pk() ) {
		wp_enqueue_script( 'stripe-js', 'https://js.stripe.com/v3/', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		$deps[] = 'stripe-js';
	}
	wp_register_script( 'ew-claim-shirt', '', $deps, EW_FWP_VERSION, true );
	wp_enqueue_script( 'ew-claim-shirt' );
	wp_add_inline_script( 'ew-claim-shirt', ew_claim_shirt_js() );
}, 23 );
