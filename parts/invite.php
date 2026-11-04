<?php
/**
 * Personal event invites: eastwoodfc.com/invite/<code>.
 *
 * One invite = one person + one company + the event they are invited to.
 * The link opens their own page: a big thank you, their booking already
 * in the basket, then two steps -- their details (email first, saved and
 * sent to the CRM the moment they press Continue), then payment with the
 * upgrade: more behind the bar for their table, shown as it will read on
 * their invoice. One payment, one line: the sponsorship package.
 *
 * Two layouts of the payment step, both built so they can be tried:
 *   v4 -- the heading, the bar tab, then the invoice under it
 *   v5 -- the invoice mock-up first (numbers shown as XX.XX), then the bar tab
 * The block's layout field picks one; ?v=4 or ?v=5 on the link overrides it.
 *
 * Every word the page shows is a field on the [eastwood_invite] block.
 * The invites themselves are made in wp-admin -> Invites.
 *
 * Money: Eastwood Ventures' Stripe, through the same helpers as tickets.
 * Each paid invite is a Booking (status paid) with the bar amount in
 * _ewt_bar, so the bar money can be settled afterwards.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'ew_blocks_allowed', function ( $list ) {
	$list[] = 'eastwood_invite';
	return $list;
} );

/* --- The invites ------------------------------------------------------ */

add_action( 'init', function () {
	register_post_type( 'ew_invite', array(
		'labels'          => array(
			'name'          => 'Invites',
			'singular_name' => 'Invite',
			'add_new'       => 'New invite',
			'add_new_item'  => 'New invite',
			'edit_item'     => 'Edit invite',
			'search_items'  => 'Search invites',
		),
		'public'          => false,
		'show_ui'         => true,
		'show_in_menu'    => true,
		'menu_position'   => 26,
		'menu_icon'       => 'dashicons-email-alt',
		'supports'        => array( 'title' ),
		'capability_type' => 'page',
		'map_meta_cap'    => true,
	) );
	add_rewrite_rule( '^invite/([A-Za-z0-9-]+)/?$', 'index.php?ew_invite=$matches[1]', 'top' );
	if ( '2' !== get_option( 'ew_invite_rw' ) ) {
		flush_rewrite_rules( false );
		update_option( 'ew_invite_rw', '2' );
	}
} );

add_filter( 'query_vars', function ( $v ) {
	$v[] = 'ew_invite';
	return $v;
} );

/** The invite a code belongs to, or 0. */
function ew_inv_find( $code ) {
	$code = strtolower( preg_replace( '/[^A-Za-z0-9-]/', '', (string) $code ) );
	if ( '' === $code ) {
		return 0;
	}
	$ids = get_posts( array(
		'post_type'   => 'ew_invite',
		'post_status' => 'any',
		'numberposts' => 1,
		'fields'      => 'ids',
		'meta_key'    => '_ewi_code', // phpcs:ignore WordPress.DB.SlowDBQuery
		'meta_value'  => $code, // phpcs:ignore WordPress.DB.SlowDBQuery
	) );
	return $ids ? (int) $ids[0] : 0;
}

/** A fresh, unguessable code. */
function ew_inv_new_code() {
	do {
		$code = strtolower( wp_generate_password( 8, false, false ) );
	} while ( ew_inv_find( $code ) );
	return $code;
}

/** The page an invite opens: its own, else the page at /invite/. */
function ew_inv_page_id( $inv ) {
	$pid = $inv ? (int) get_post_meta( $inv, '_ewi_page', true ) : 0;
	if ( ! $pid ) {
		$p   = get_page_by_path( 'invite' );
		$pid = $p ? (int) $p->ID : 0;
	}
	return $pid;
}

/** /invite/<code> shows the invite page, keeping the code. */
add_filter( 'request', function ( $vars ) {
	if ( empty( $vars['ew_invite'] ) ) {
		return $vars;
	}
	$pid = ew_inv_page_id( ew_inv_find( $vars['ew_invite'] ) );
	if ( $pid ) {
		return array( 'page_id' => $pid, 'ew_invite' => $vars['ew_invite'] );
	}
	return $vars;
} );
add_filter( 'redirect_canonical', function ( $url ) {
	return get_query_var( 'ew_invite' ) ? false : $url;
} );
add_filter( 'wp_robots', function ( $r ) {
	if ( get_query_var( 'ew_invite' ) ) {
		$r['noindex']  = true;
		$r['nofollow'] = true;
	}
	return $r;
} );
add_action( 'template_redirect', function () {
	if ( get_query_var( 'ew_invite' ) ) {
		nocache_headers();
		header( 'Cache-Control: no-store, private' );
	}
} );

/** The code asked for on this request: /invite/<code>, or ?invite=<code>. */
function ew_inv_code_here() {
	$c = (string) get_query_var( 'ew_invite' );
	if ( '' === $c && isset( $_GET['invite'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$c = sanitize_text_field( wp_unslash( $_GET['invite'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
	}
	return strtolower( preg_replace( '/[^A-Za-z0-9-]/', '', $c ) );
}

/** Everything about one invite, in one place. */
function ew_inv_data( $inv ) {
	$m = function ( $k ) use ( $inv ) {
		return (string) get_post_meta( $inv, '_ewi_' . $k, true );
	};
	return array(
		'id'      => (int) $inv,
		'code'    => $m( 'code' ),
		'company' => get_the_title( $inv ),
		'first'   => $m( 'first' ),
		'last'    => $m( 'last' ),
		'email'   => $m( 'email' ),
		'price'   => $m( 'price' ),
		'status'  => '' !== $m( 'status' ) ? $m( 'status' ) : 'new',
		'booking' => (int) $m( 'booking' ),
	);
}

function ew_inv_link( $inv ) {
	return home_url( '/invite/' . get_post_meta( $inv, '_ewi_code', true ) . '/' );
}

/* --- wp-admin: making invites ------------------------------------------ */

add_action( 'add_meta_boxes_ew_invite', function () {
	add_meta_box( 'ewi_who', 'Who it is for', function ( $post ) {
		wp_nonce_field( 'ewi_save', 'ewi_nonce' );
		$f = function ( $k, $label, $type = 'text', $note = '' ) use ( $post ) {
			printf(
				'<p><label style="display:block;font-weight:600;margin-bottom:4px">%s</label><input type="%s" name="ewi_%s" value="%s" style="width:100%%;max-width:420px">%s</p>',
				esc_html( $label ),
				esc_attr( $type ),
				esc_attr( $k ),
				esc_attr( (string) get_post_meta( $post->ID, '_ewi_' . $k, true ) ),
				$note ? '<br><span class="description">' . esc_html( $note ) . '</span>' : ''
			);
		};
		echo '<p class="description">The title above is the company name. It is what the page says thank you to.</p>';
		$f( 'first', 'First name' );
		$f( 'last', 'Last name' );
		$f( 'email', 'Email', 'email' );
		$f( 'price', 'Their price, £', 'text', 'Leave blank for the price on the invite page.' );
		$f( 'page', 'Invite page id', 'text', 'Leave blank for the page at /invite/. A different event has its own invite page.' );
		$code = (string) get_post_meta( $post->ID, '_ewi_code', true );
		if ( '' !== $code ) {
			$url = ew_inv_link( $post->ID );
			echo '<p><label style="display:block;font-weight:600;margin-bottom:4px">Their link</label><input readonly value="' . esc_attr( $url ) . '" style="width:100%;max-width:420px" onclick="this.select()"> <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">Open</a></p>';
			echo '<p>Status: <strong>' . esc_html( ew_inv_data( $post->ID )['status'] ) . '</strong></p>';
		}
	}, 'ew_invite', 'normal', 'high' );
} );

add_action( 'save_post_ew_invite', function ( $id ) {
	if ( ! isset( $_POST['ewi_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ewi_nonce'] ) ), 'ewi_save' ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $id ) ) {
		return;
	}
	foreach ( array( 'first', 'last', 'price', 'page' ) as $k ) {
		update_post_meta( $id, '_ewi_' . $k, sanitize_text_field( wp_unslash( $_POST[ 'ewi_' . $k ] ?? '' ) ) );
	}
	update_post_meta( $id, '_ewi_email', sanitize_email( wp_unslash( $_POST['ewi_email'] ?? '' ) ) );
	if ( '' === (string) get_post_meta( $id, '_ewi_code', true ) ) {
		update_post_meta( $id, '_ewi_code', ew_inv_new_code() );
	}
} );

add_filter( 'manage_ew_invite_posts_columns', function ( $c ) {
	return array(
		'cb'        => $c['cb'] ?? '',
		'title'     => 'Company',
		'ewi_who'   => 'Person',
		'ewi_link'  => 'Link',
		'ewi_state' => 'Status',
	);
} );
add_action( 'manage_ew_invite_posts_custom_column', function ( $col, $id ) {
	$d = ew_inv_data( $id );
	if ( 'ewi_who' === $col ) {
		echo esc_html( trim( $d['first'] . ' ' . $d['last'] ) ) . '<br><span class="description">' . esc_html( $d['email'] ) . '</span>';
	} elseif ( 'ewi_link' === $col && '' !== $d['code'] ) {
		echo '<input readonly value="' . esc_attr( ew_inv_link( $id ) ) . '" style="width:100%" onclick="this.select()">';
	} elseif ( 'ewi_state' === $col ) {
		echo esc_html( $d['status'] );
		if ( 'paid' === $d['status'] && $d['booking'] ) {
			echo '<br><span class="description">' . esc_html( ew_tix_money( (int) get_post_meta( $d['booking'], '_ewt_paid', true ) ) ) . '</span>';
		}
	}
}, 10, 2 );

/* --- The block on the page ---------------------------------------------- */

function ew_inv_defaults() {
	return array(
		'layout'       => 'v5',
		'background'   => '',
		'crest'        => '',
		'kicker'       => 'Peaky Blinders Casino Night · Sat 14 Nov · Doors 7pm',
		'head'         => 'Thank you, {company}',
		'lede1'        => '{first}, your support has meant a huge amount to this club.',
		'lede2'        => 'So we just want to thank you again for your continued support.',
		'lede3'        => 'We appreciate you.',
		'step1'        => 'Step 1 of 2 · Your details',
		'booking'      => 'Your booking',
		'event'        => 'Peaky Blinders Casino Night',
		'package'      => 'Table sponsorship package',
		'price'        => '250',
		'total_word'   => 'Total',
		'email_label'  => 'Email for the invoice',
		'company_label' => 'Invoice to',
		'vat_label'    => 'VAT number (optional)',
		'continue'     => 'Continue to payment',
		'need'         => 'Please add your email.',
		'back'         => '← Back to your details',
		'step2'        => 'Step 2 of 2 · Payment',
		'v4_head'      => 'Think of your expense account now, whilst we prepare your tax invoice.',
		'inv_head'     => 'Here’s a mockup of your tax invoice',
		'alc_head'     => 'More expensed alcohol?',
		'inv_label'    => 'How it will appear on your tax invoice',
		'bar_kicker'   => 'Behind the bar',
		'bar_title'    => '{company}’s table',
		'adds'         => '250|500|750|1000',
		'add_note'     => 'Behind the bar for your table',
		'none'         => 'No thanks, I’d prefer to give the tax man this instead',
		'seller'       => 'Eastwood FC Ventures Ltd',
		'seller_line'  => 'Coronation Park, Eastwood · Company no. 17358254',
		'seller_vat'   => '',
		'inv_title_vat' => 'VAT invoice',
		'inv_title'    => 'Invoice',
		'inv_no'       => 'No. XXXX',
		'bill_to'      => 'Bill to',
		'inv_line'     => 'Peaky Blinders Casino Night, Sat 14 Nov 2026 · Sponsorship package',
		'inv_inc'      => 'Includes table for 10',
		'inv_bar'      => 'and {amount} bar allowance',
		'inv_thanks'   => 'Thank you for sponsoring Eastwood Football Club',
		'mock'         => 'XX.XX',
		'loading'      => 'Loading the payment form…',
		'off'          => 'Online payment opens soon.',
		'missing'      => 'We could not find this invite. Please check the link in your email, or contact info@eastwoodfc.com.',
		'done_head'    => 'Thank you, {first}',
		'done_text'    => 'Your payment has gone through. Your invoice will follow by email to {email}.',
		'crm_hook'     => '',
		'greeting'     => '',
		'tax_rate'     => '',
	);
}

/** Fill {first} {company} {email} {amount} into a field. */
function ew_inv_fill( $s, $vals ) {
	foreach ( $vals as $k => $v ) {
		$s = str_replace( '{' . $k . '}', (string) $v, (string) $s );
	}
	return $s;
}

/** The add-on amounts, in pence, from the adds field. */
function ew_inv_adds( $raw ) {
	$out = array();
	foreach ( explode( '|', (string) $raw ) as $p ) {
		$v = (float) preg_replace( '/[^0-9.]/', '', $p );
		if ( $v > 0 ) {
			$out[] = (int) round( $v * 100 );
		}
	}
	return array_slice( $out, 0, 6 );
}

/** An invite price in pence: the invite's own, else the block's. */
function ew_inv_price( $a, $d ) {
	$v = '' !== trim( (string) $d['price'] ) ? $d['price'] : $a['price'];
	return (int) round( (float) preg_replace( '/[^0-9.]/', '', (string) $v ) * 100 );
}

function ew_inv_img( $v ) {
	$v = trim( (string) $v );
	if ( '' !== $v && ctype_digit( $v ) ) {
		return (string) wp_get_attachment_url( (int) $v );
	}
	return $v;
}

function ew_inv_pint() {
	return '<svg class="ewv-d" width="30" height="44" viewBox="0 0 30 44" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" aria-hidden="true"><path d="M4 4h22l-3 36H7z"/><path d="M5 10h20" stroke-opacity=".6"/></svg>';
}
function ew_inv_bottle() {
	return '<svg class="ewv-d ewv-b" width="22" height="60" viewBox="0 0 22 60" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" aria-hidden="true"><path d="M8 2h6v14c4 3 6 7 6 12v28H2V28c0-5 2-9 6-12z"/><path d="M2 36h18" stroke-opacity=".6"/></svg>';
}

function ew_inv_shortcode( $atts ) {
	$a   = shortcode_atts( ew_inv_defaults(), $atts, 'eastwood_invite' );
	$inv = ew_inv_find( ew_inv_code_here() );
	$pid = (int) get_the_ID();
	$e   = function ( $s ) {
		return esc_html( $s );
	};

	if ( ! $inv ) {
		return '<div class="ewv ewv-none"><div class="ewv-in"><p class="ewv-lede">' . $e( $a['missing'] ) . '</p></div></div>';
	}
	$d    = ew_inv_data( $inv );
	$vals = array(
		'first'   => '' !== $d['first'] ? $d['first'] : 'Hello',
		'company' => $d['company'],
		'email'   => $d['email'],
	);
	$f    = function ( $k ) use ( $a, $vals ) {
		return esc_html( ew_inv_fill( $a[ $k ], $vals ) );
	};

	$layout = strtolower( (string) $a['layout'] );
	if ( isset( $_GET['v'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$layout = 'v' . (int) $_GET['v']; // phpcs:ignore WordPress.Security.NonceVerification
	}
	$layout = 'v4' === $layout ? 'v4' : 'v5';
	$bg     = ew_inv_img( $a['background'] );
	$crest  = ew_inv_img( $a['crest'] );
	if ( '' === $crest ) {
		$crest = plugins_url( 'assets/favicon/favicon-180.png', dirname( __DIR__ ) . '/eastwood-fwp.php' );
	}
	$base   = ew_inv_price( $a, $d );
	$adds   = ew_inv_adds( $a['adds'] );
	$vat    = '' !== trim( (string) $a['seller_vat'] );

	$bg_style = '' !== $bg ? ' style="--ewv-bg:url(' . esc_url( $bg ) . ')"' : '';

	/* Back from paying. */
	$sid = isset( $_GET['ewt_paid'] ) ? sanitize_text_field( wp_unslash( $_GET['ewt_paid'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	if ( 'paid' === $d['status'] || ( '' !== $sid && ew_inv_back_from_pay( $inv, $a, $sid ) ) ) {
		return '<div class="ewv"' . $bg_style . '><div class="ewv-in ewv-done"><p class="ewv-kicker">' . $e( $a['kicker'] ) . '</p><h1 class="ewv-h1">' . $f( 'done_head' ) . '</h1><p class="ewv-lede">' . $f( 'done_text' ) . '</p></div></div>';
	}
	if ( 'new' === $d['status'] || 'sent' === $d['status'] ) {
		update_post_meta( $inv, '_ewi_status', 'opened' );
		update_post_meta( $inv, '_ewi_opened', time() );
	}

	/* The bar tab card. */
	$drinks = str_repeat( ew_inv_pint(), 10 ) . '<span class="ewv-gap"></span>' . str_repeat( ew_inv_bottle(), 2 );
	$opts   = '';
	foreach ( $adds as $i => $p ) {
		$opts .= '<button type="button" class="ewv-o" data-i="' . (int) $i . '"><span class="ewv-o-p">+' . $e( ew_tix_money( $p ) ) . '</span><span class="ewv-o-n">' . $e( $a['add_note'] ) . '</span></button>';
	}
	$bar  = '<div class="ewv-bar">';
	$bar .= '<div class="ewv-bar-top"><div><p class="ewv-bar-k">' . $e( $a['bar_kicker'] ) . '</p><p class="ewv-bar-t">' . $f( 'bar_title' ) . '</p></div><p class="ewv-tab" data-tab>£0</p></div>';
	$bar .= '<div class="ewv-drinks">' . $drinks . '</div>';
	$bar .= '<div class="ewv-opts">' . $opts . '</div>';
	$bar .= '<button type="button" class="ewv-no">' . $e( $a['none'] ) . '</button>';
	$bar .= '</div>';

	/* The invoice as it will read. */
	$num  = $vat ? '<span>' . $e( 'NET' ) . '</span><span>' . $e( 'VAT 20%' ) . '</span>' : '';
	$invh  = '<div class="ewv-inv' . ( $vat ? ' ewv-inv-vat' : '' ) . '">';
	$invh .= '<div class="ewv-inv-top"><div class="ewv-inv-from"><img src="' . esc_url( $crest ) . '" alt="Eastwood FC crest" width="44" height="44"><div><p class="ewv-inv-b">' . $e( $a['seller'] ) . '</p><p>' . $e( $a['seller_line'] ) . ( $vat ? ' · VAT reg. no. ' . $e( $a['seller_vat'] ) : '' ) . '</p></div></div>';
	$invh .= '<div class="ewv-inv-title"><p class="ewv-inv-h">' . $e( $vat ? $a['inv_title_vat'] : $a['inv_title'] ) . '</p><p>' . $e( $a['inv_no'] ) . '</p></div></div>';
	$invh .= '<div class="ewv-inv-to"><p class="ewv-inv-k">' . $e( $a['bill_to'] ) . '</p><p class="ewv-inv-b" data-co>' . $e( $d['company'] ) . '</p><p data-vatno></p></div>';
	$invh .= '<div class="ewv-inv-row ewv-inv-hd"><span>DESCRIPTION</span>' . $num . '<span>TOTAL</span></div>';
	$invh .= '<div class="ewv-inv-row"><span class="ewv-inv-desc"><strong>' . $e( $a['inv_line'] ) . '</strong><em data-sub></em></span>' . ( $vat ? '<span data-net></span><span data-vat></span>' : '' ) . '<span data-gross></span></div>';
	$invh .= '<div class="ewv-inv-sum">' . ( $vat ? '<div><span>Net</span><span data-net></span></div><div><span>VAT at 20%</span><span data-vat></span></div>' : '' ) . '<div class="ewv-inv-tot"><span>Total paid</span><span data-gross></span></div></div>';
	$invh .= '<p class="ewv-inv-foot">' . $e( $a['inv_thanks'] ) . '</p></div>';

	if ( 'v4' === $layout ) {
		$left = '<h2 class="ewv-h2">' . $f( 'v4_head' ) . '</h2>' . $bar . '<p class="ewv-step ewv-invlab">' . $e( $a['inv_label'] ) . '</p>' . $invh;
	} else {
		$left = '<h2 class="ewv-h2">' . $f( 'inv_head' ) . '</h2>' . $invh . '<h2 class="ewv-h2 ewv-h2b">' . $f( 'alc_head' ) . '</h2>' . $bar;
	}

	$data = array(
		'data-pk'      => ew_tix_pk(),
		'data-code'    => $d['code'],
		'data-start'   => rest_url( 'eastwood/v1/invite/start' ),
		'data-pay'     => rest_url( 'eastwood/v1/invite/checkout' ),
		'data-base'    => (string) $base,
		'data-adds'    => wp_json_encode( $adds ),
		'data-mock'    => 'v5' === $layout ? (string) $a['mock'] : '',
		'data-vat'     => $vat ? '1' : '',
		'data-inc'     => ew_inv_fill( $a['inv_inc'], $vals ),
		'data-incbar'  => (string) $a['inv_bar'],
		'data-need'    => (string) $a['need'],
		'data-loading' => (string) $a['loading'],
		'data-off'     => (string) $a['off'],
		'data-layout'  => $layout,
	);
	$attrs = '';
	foreach ( $data as $k => $v ) {
		$attrs .= ' ' . $k . '="' . esc_attr( $v ) . '"';
	}

	$h  = '<div class="ewv ewv-' . $layout . '"' . $bg_style . $attrs . '><div class="ewv-in">';
	/* Step 1: details. */
	$h .= '<section class="ewv-s ewv-s1"><div class="ewv-l"><p class="ewv-kicker">' . $e( $a['kicker'] ) . '</p><h1 class="ewv-h1">' . $f( 'head' ) . '</h1>';
	foreach ( array( 'lede1', 'lede2' ) as $k ) {
		if ( '' !== trim( $a[ $k ] ) ) {
			$h .= '<p class="ewv-lede">' . $f( $k ) . '</p>';
		}
	}
	if ( '' !== trim( $a['lede3'] ) ) {
		$h .= '<p class="ewv-big">' . $f( 'lede3' ) . '</p>';
	}
	$h .= '</div><div class="ewv-r"><p class="ewv-step">' . $e( $a['step1'] ) . '</p><h3 class="ewv-h3">' . $e( $a['booking'] ) . '</h3>';
	$h .= '<div class="ewv-line"><span>' . $e( $a['event'] . ' · ' . $a['package'] ) . '</span><span>' . $e( ew_tix_money( $base ) ) . '</span></div>';
	$h .= '<div class="ewv-line ewv-tot"><span>' . $e( $a['total_word'] ) . '</span><span>' . $e( ew_tix_money( $base ) ) . '</span></div>';
	$h .= '<form class="ewv-form" novalidate>';
	$h .= '<label><span>' . $e( $a['email_label'] ) . '</span><input type="email" name="email" autocomplete="email" required value="' . esc_attr( $d['email'] ) . '"></label>';
	$h .= '<label><span>' . $e( $a['company_label'] ) . '</span><input type="text" name="company" autocomplete="organization" value="' . esc_attr( $d['company'] ) . '"></label>';
	$h .= '<label><span>' . $e( $a['vat_label'] ) . '</span><input type="text" name="vat"></label>';
	$h .= '<button type="submit" class="ewv-go">' . $e( $a['continue'] ) . '</button><p class="ewv-msg" data-msg1></p></form></div></section>';
	/* Step 2: pay, with the upgrade. */
	$h .= '<section class="ewv-s ewv-s2" hidden><div class="ewv-l"><button type="button" class="ewv-back">' . $e( $a['back'] ) . '</button><p class="ewv-step">' . $e( $a['step2'] ) . '</p>' . $left . '</div>';
	$h .= '<div class="ewv-r"><div class="ewv-line ewv-tot ewv-big-tot"><span>' . $e( $a['total_word'] ) . '</span><span data-total>' . $e( ew_tix_money( $base ) ) . '</span></div><div class="ewv-mount"></div><p class="ewv-msg" data-msg2></p></div></section>';
	$h .= '</div></div>';
	return $h;
}
add_shortcode( 'eastwood_invite', 'ew_inv_shortcode' );

/** The block on a page, read from the page itself. */
function ew_inv_block_on( $post ) {
	$found = null;
	$data  = json_decode( (string) get_post_meta( $post->ID, '_elementor_data', true ), true );
	if ( is_array( $data ) ) {
		$walk = function ( $nodes ) use ( &$walk, &$found ) {
			foreach ( (array) $nodes as $n ) {
				if ( $found ) {
					return;
				}
				$sc = (string) ( $n['settings']['shortcode'] ?? '' );
				if ( 0 === strpos( ltrim( $sc ), '[eastwood_invite' ) ) {
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
	if ( ! $found && preg_match( '/\[eastwood_invite[^\]]*\]/', (string) $post->post_content, $m ) ) {
		$found = ew_blocks_parse( $m[0] );
	}
	$fields = is_array( $found ) ? (array) ( $found['fields'] ?? array() ) : array();
	return array_merge( ew_inv_defaults(), $fields );
}

/** Coming back from Stripe: mark the booking and the invite paid. */
function ew_inv_back_from_pay( $inv, $a, $sid ) {
	if ( ! preg_match( '/^cs_[A-Za-z0-9_]+$/', (string) $sid ) || '' === ew_tix_sk() ) {
		return false;
	}
	$s = ew_tix_stripe( 'GET', 'checkout/sessions/' . rawurlencode( $sid ) );
	if ( empty( $s['id'] ) || (string) ( $s['metadata']['invite'] ?? '' ) !== (string) (int) $inv || ! ew_tix_is_paid( $s ) ) {
		return false;
	}
	$bid = ew_tix_booking_for( $s );
	ew_tix_mark_paid( $bid, $s, (string) $a['crm_hook'] );
	update_post_meta( $bid, '_ewt_bar', (int) ( $s['metadata']['bar'] ?? 0 ) );
	update_post_meta( $inv, '_ewi_status', 'paid' );
	update_post_meta( $inv, '_ewi_booking', (int) $bid );
	return true;
}

/* --- The two steps ---------------------------------------------------------- */

/** The invite and its page, for a request carrying a code. */
function ew_inv_from_req( $req ) {
	$inv = ew_inv_find( (string) $req->get_param( 'code' ) );
	if ( ! $inv ) {
		return new WP_Error( 'ewi_code', 'We could not find this invite.', array( 'status' => 404 ) );
	}
	$post = get_post( ew_inv_page_id( $inv ) );
	if ( ! $post ) {
		return new WP_Error( 'ewi_page', 'This invite has no page.', array( 'status' => 404 ) );
	}
	if ( 'paid' === ew_inv_data( $inv )['status'] ) {
		return new WP_Error( 'ewi_paid', 'This invite has already been paid. Thank you.', array( 'status' => 409 ) );
	}
	return array( $inv, $post, ew_inv_block_on( $post ) );
}

add_action( 'rest_api_init', function () {
	/* Step 1: the email, saved and sent to the CRM straight away. */
	register_rest_route( 'eastwood/v1', '/invite/start', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) {
			$got = ew_inv_from_req( $req );
			if ( is_wp_error( $got ) ) {
				return $got;
			}
			list( $inv, $post, $a ) = $got;
			$d     = ew_inv_data( $inv );
			$email = sanitize_email( (string) $req->get_param( 'email' ) );
			if ( ! is_email( $email ) ) {
				return new WP_Error( 'ewi_email', (string) $a['need'], array( 'status' => 400 ) );
			}
			$company = mb_substr( sanitize_text_field( (string) $req->get_param( 'company' ) ), 0, 120 );
			$vatno   = mb_substr( sanitize_text_field( (string) $req->get_param( 'vat' ) ), 0, 40 );
			$base    = ew_inv_price( $a, $d );
			$bid     = $d['booking'];
			if ( ! $bid || 'ewt_booking' !== get_post_type( $bid ) || 'started' !== get_post_meta( $bid, '_ewt_status', true ) ) {
				$name = trim( $d['first'] . ' ' . $d['last'] );
				$bid  = ew_tix_booking_start( $post, (string) $a['event'], $name, $email, (string) $a['package'], $base, (string) $a['crm_hook'], (string) $a['greeting'] );
				if ( ! $bid ) {
					return new WP_Error( 'ewi_save', 'Something went wrong. Please try again.', array( 'status' => 500 ) );
				}
				update_post_meta( $inv, '_ewi_booking', (int) $bid );
			} else {
				update_post_meta( $bid, '_ewt_email', $email );
			}
			update_post_meta( $bid, '_ewt_url', ew_inv_link( $inv ) );
			update_post_meta( $bid, '_ewt_company', '' !== $company ? $company : $d['company'] );
			update_post_meta( $bid, '_ewt_vat', $vatno );
			update_post_meta( $bid, '_ewt_invite', (int) $inv );
			update_post_meta( $inv, '_ewi_status', 'details' );
			return array( 'ok' => true, 'bid' => (int) $bid, 'key' => ew_booking_key( $bid ) );
		},
	) );

	/* Step 2: a Stripe form for the amount picked. Asked again when the pick changes. */
	register_rest_route( 'eastwood/v1', '/invite/checkout', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) {
			$sk = ew_tix_sk();
			if ( '' === $sk ) {
				return new WP_Error( 'ewi_off', 'Payments are not switched on.', array( 'status' => 503 ) );
			}
			$got = ew_inv_from_req( $req );
			if ( is_wp_error( $got ) ) {
				return $got;
			}
			list( $inv, $post, $a ) = $got;
			$d   = ew_inv_data( $inv );
			$bid = (int) $req->get_param( 'bid' );
			$key = (string) $req->get_param( 'key' );
			if ( ! $bid || $bid !== $d['booking'] || '' === $key || ! hash_equals( (string) get_post_meta( $bid, '_ewt_key', true ), $key ) || 'started' !== get_post_meta( $bid, '_ewt_status', true ) ) {
				return new WP_Error( 'ewi_who', 'Please go back and add your details again.', array( 'status' => 400 ) );
			}
			$base = ew_inv_price( $a, $d );
			$adds = ew_inv_adds( $a['adds'] );
			$opt  = (int) $req->get_param( 'opt' );
			$bar  = ( $opt >= 0 && isset( $adds[ $opt ] ) ) ? $adds[ $opt ] : 0;
			$tot  = $base + $bar;
			if ( $tot <= 0 ) {
				return new WP_Error( 'ewi_price', 'This invite has no price yet.', array( 'status' => 400 ) );
			}
			$desc = ew_inv_fill( $a['inv_inc'], array() ) . ( $bar ? ' ' . ew_inv_fill( $a['inv_bar'], array( 'amount' => ew_tix_money( $bar ) ) ) : '' );
			$email = (string) get_post_meta( $bid, '_ewt_email', true );
			$body = array(
				'ui_mode'                           => 'embedded_page',
				'mode'                              => 'payment',
				'return_url'                        => add_query_arg( 'ewt_paid', '{CHECKOUT_SESSION_ID}', ew_inv_link( $inv ) ),
				'phone_number_collection[enabled]'  => 'true',
				'customer_email'                    => $email,
				'line_items[0][quantity]'           => '1',
				'line_items[0][price_data][currency]' => 'gbp',
				'line_items[0][price_data][unit_amount]' => (string) $tot,
				'line_items[0][price_data][product_data][name]' => (string) $a['inv_line'],
				'line_items[0][price_data][product_data][description]' => $desc,
				'invoice_creation[enabled]'         => 'true',
				'invoice_creation[invoice_data][description]' => $desc,
				'invoice_creation[invoice_data][footer]' => (string) $a['inv_thanks'],
				'metadata[page]'                    => (string) $post->ID,
				'metadata[event]'                   => (string) $a['event'],
				'metadata[booking]'                 => (string) $bid,
				'metadata[invite]'                  => (string) $inv,
				'metadata[bar]'                     => (string) $bar,
			);
			$company = (string) get_post_meta( $bid, '_ewt_company', true );
			$vatno   = (string) get_post_meta( $bid, '_ewt_vat', true );
			$cf      = 0;
			if ( '' !== $company ) {
				$body[ "invoice_creation[invoice_data][custom_fields][$cf][name]" ]  = 'Company';
				$body[ "invoice_creation[invoice_data][custom_fields][$cf][value]" ] = mb_substr( $company, 0, 140 );
				$cf++;
			}
			if ( '' !== $vatno ) {
				$body[ "invoice_creation[invoice_data][custom_fields][$cf][name]" ]  = 'Customer VAT no.';
				$body[ "invoice_creation[invoice_data][custom_fields][$cf][value]" ] = $vatno;
			}
			$rate = trim( (string) $a['tax_rate'] );
			if ( preg_match( '/^txr_[A-Za-z0-9]+$/', $rate ) ) {
				$body['line_items[0][tax_rates][0]'] = $rate;
			}
			$s = ew_tix_stripe( 'POST', 'checkout/sessions', $body );
			if ( empty( $s['client_secret'] ) ) {
				return new WP_Error( 'ewi_stripe', (string) ( $s['error']['message'] ?? 'Stripe refused the payment.' ), array( 'status' => 400 ) );
			}
			update_post_meta( $bid, '_ewt_open_session', (string) $s['id'] );
			update_post_meta( $bid, '_ewt_total', $tot );
			update_post_meta( $bid, '_ewt_bar', $bar );
			update_post_meta( $bid, '_ewt_lines', (string) $a['package'] . ( $bar ? ' + ' . ew_tix_money( $bar ) . ' bar allowance' : '' ) );
			return array( 'clientSecret' => $s['client_secret'] );
		},
	) );

	/* Making an invite from the command channel (the admin screen does the same by hand). */
	register_rest_route( 'eastwood/v1', '/invite/make', array(
		'methods'             => 'POST',
		'permission_callback' => function () {
			return current_user_can( 'edit_pages' );
		},
		'callback'            => function ( $req ) {
			$company = sanitize_text_field( (string) $req->get_param( 'company' ) );
			if ( '' === $company ) {
				return new WP_Error( 'ewi_company', 'Add the company.', array( 'status' => 400 ) );
			}
			$id = wp_insert_post( array( 'post_type' => 'ew_invite', 'post_status' => 'publish', 'post_title' => $company ) );
			if ( ! $id || is_wp_error( $id ) ) {
				return new WP_Error( 'ewi_save', 'Could not save.', array( 'status' => 500 ) );
			}
			foreach ( array( 'first', 'last', 'price', 'page' ) as $k ) {
				update_post_meta( $id, '_ewi_' . $k, sanitize_text_field( (string) $req->get_param( $k ) ) );
			}
			update_post_meta( $id, '_ewi_email', sanitize_email( (string) $req->get_param( 'email' ) ) );
			update_post_meta( $id, '_ewi_code', ew_inv_new_code() );
			return array( 'id' => (int) $id, 'link' => ew_inv_link( $id ) );
		},
	) );
} );

/* --- Styles and script ------------------------------------------------------- */

function ew_inv_css() {
	return <<<'CSS'
.ewv{--c:#e8d5b0;--t:#e9e2d6;--m:rgba(233,226,214,.62);--ln:rgba(232,213,176,.22);--card:#231c16;position:relative;background:#14100d;color:var(--t);font-family:'Instrument Sans',system-ui,sans-serif;overflow:hidden}
.ewv:before{content:"";position:absolute;inset:0;background:var(--ewv-bg) center/cover no-repeat;filter:brightness(.3)}
.ewv-in{position:relative;max-width:1240px;margin:0 auto;padding:72px 40px 96px;box-sizing:border-box}
.ewv-s{display:flex;flex-wrap:wrap;gap:56px;align-items:flex-start}
.ewv-s[hidden]{display:none}
.ewv-l{flex:999 1 520px;min-width:0;display:flex;flex-direction:column;gap:18px}
.ewv-r{flex:1 1 420px;min-width:0;display:flex;flex-direction:column;gap:12px}
.ewv p{margin:0}
.ewv-kicker{font-family:Anton,sans-serif;font-size:16px;letter-spacing:.18em;text-transform:uppercase;color:var(--c)!important}
.ewv .ewv-h1{margin:0;font-family:Anton,sans-serif;font-weight:400;font-size:104px;line-height:.9;text-transform:uppercase;color:var(--c);background:none;padding:0;width:auto}
.ewv .ewv-h2{margin:0;font-family:Anton,sans-serif;font-weight:400;font-size:54px;line-height:.98;text-transform:uppercase;color:var(--c)}
.ewv .ewv-h2b{margin-top:18px}
.ewv .ewv-h3{margin:0;font-family:Anton,sans-serif;font-weight:400;font-size:30px;text-transform:uppercase;color:var(--c)}
.ewv-lede{font-size:21px;line-height:1.6;max-width:560px;color:var(--t)}
.ewv-big{font-family:Anton,sans-serif;font-size:30px;text-transform:uppercase;color:var(--c)!important}
.ewv-step{font-size:13px;letter-spacing:.14em;text-transform:uppercase;color:var(--m)!important}
.ewv-line{display:flex;justify-content:space-between;gap:16px;border-top:1px solid var(--ln);padding:10px 0;font-size:16px}
.ewv-tot{font-family:Anton,sans-serif;font-size:26px;color:var(--c)}
.ewv-big-tot{border-top:0;border-bottom:1px solid var(--ln);font-size:40px;align-items:baseline}
.ewv-big-tot span:first-child{font-family:'Instrument Sans',sans-serif;font-size:16px;color:var(--t)}
.ewv-form{display:grid;gap:14px;margin-top:8px}
.ewv-form label span{display:block;font-size:13px;color:var(--m);margin-bottom:6px}
.ewv-form input{width:100%;box-sizing:border-box;background:#0f0c0a;border:1px solid var(--ln);border-radius:0;color:var(--t);padding:14px;font:inherit;font-size:16px}
.ewv-go{justify-self:end;background:var(--c);color:#1b140f;border:0;font-family:Anton,sans-serif;text-transform:uppercase;letter-spacing:.06em;font-size:18px;padding:15px 34px;cursor:pointer}
.ewv-go:hover{background:#f3e6c8}
.ewv-go[disabled]{opacity:.6;cursor:default}
.ewv-msg{font-size:15px;color:var(--c)!important;min-height:1px}
.ewv-back{align-self:flex-start;background:none;border:0;padding:0;color:var(--c);font:inherit;font-size:15px;cursor:pointer}
.ewv-bar{background:var(--card);border:1px solid rgba(232,213,176,.35);padding:26px;display:flex;flex-direction:column;gap:20px}
.ewv-bar-top{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;border-bottom:1px dashed rgba(232,213,176,.35);padding-bottom:18px}
.ewv-bar-k{font-family:Anton,sans-serif;font-size:14px;letter-spacing:.16em;text-transform:uppercase;color:var(--m)!important}
.ewv-bar-t{font-family:Anton,sans-serif;font-size:30px;text-transform:uppercase;color:var(--c)!important}
.ewv-tab{font-family:Anton,sans-serif;font-size:56px;line-height:1;color:var(--c)!important}
.ewv-drinks{display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;color:var(--c)}
.ewv-d{opacity:.22;transition:opacity .25s}
.ewv-d.ewv-lit{opacity:1}
.ewv-gap{width:14px}
.ewv-opts{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.ewv-o{text-align:left;background:transparent;border:1px solid var(--ln);color:var(--t);padding:16px 18px;font:inherit;cursor:pointer;display:flex;flex-direction:column;gap:6px;border-radius:0}
.ewv-o:hover{border-color:rgba(232,213,176,.5)}
.ewv-o.ewv-sel{background:rgba(232,213,176,.08);border-color:rgba(232,213,176,.8)}
.ewv-o-p{font-family:Anton,sans-serif;font-size:26px;color:var(--c)}
.ewv-o-n{font-size:14px;color:var(--m);line-height:1.5}
.ewv-no{align-self:center;text-align:center;background:none;border:0;padding:4px 0 0;color:rgba(233,226,214,.72);text-decoration:underline;font:inherit;font-size:18px;cursor:pointer}
.ewv-invlab{margin-top:8px}
.ewv-inv{background:#fff;color:#111;padding:32px 34px;display:flex;flex-direction:column;gap:22px;box-shadow:0 18px 40px rgba(0,0,0,.45);font-family:'Instrument Sans',system-ui,sans-serif}
.ewv-inv p{color:#444449;font-size:12px;line-height:1.45}
.ewv-inv-top{display:flex;justify-content:space-between;align-items:flex-start;gap:16px}
.ewv-inv-from{display:flex;gap:12px;align-items:center}
.ewv-inv-from img{width:44px;height:44px;object-fit:contain}
.ewv-inv .ewv-inv-b{font-weight:700;color:#111;font-size:13px}
.ewv-inv-title{text-align:right}
.ewv-inv .ewv-inv-h{font-family:Anton,sans-serif;font-size:28px;line-height:1;text-transform:uppercase;color:#111}
.ewv-inv .ewv-inv-k{font-size:10px;letter-spacing:.12em;text-transform:uppercase;color:#5d5d66}
.ewv-inv-row{display:grid;grid-template-columns:minmax(0,1fr) 90px;gap:10px;padding:12px 0;border-bottom:1px solid #d6d6da;font-size:13px}
.ewv-inv-vat .ewv-inv-row{grid-template-columns:minmax(0,1fr) 80px 80px 90px}
.ewv-inv-row span:not(.ewv-inv-desc){text-align:right}
.ewv-inv-hd{padding:8px 0;border-bottom:1px solid #111;font-size:10px;letter-spacing:.08em;color:#5d5d66}
.ewv-inv-hd span:first-child{text-align:left!important}
.ewv-inv-desc{display:flex;flex-direction:column;gap:4px}
.ewv-inv-desc em{font-style:normal;font-size:12px;color:#444449}
.ewv-inv-sum{align-self:flex-end;width:240px;display:flex;flex-direction:column;gap:6px;font-size:13px}
.ewv-inv-sum div{display:flex;justify-content:space-between}
.ewv-inv-tot{border-top:1px solid #111;padding-top:8px;font-weight:700;font-size:16px}
.ewv-inv .ewv-inv-foot{border-top:1px solid #d6d6da;padding-top:12px;font-size:11px;color:#5d5d66}
.ewv-mount{background:#fff;border-radius:6px;min-height:120px}
.ewv-mount:empty{display:none}
.ewv-done{min-height:520px;display:flex;flex-direction:column;gap:18px;justify-content:center}
@media(max-width:900px){
.ewv-in{padding:24px 16px 48px}
.ewv-s1{flex-direction:column;flex-wrap:nowrap;gap:12px}
.ewv-s1[hidden]{display:none}
.ewv-s1 .ewv-l{display:contents}
.ewv-s1 .ewv-kicker{order:1}
.ewv-s1 .ewv-h1{order:2}
.ewv-s1 .ewv-lede:first-of-type{order:3}
.ewv-s1 .ewv-r{order:4;flex:none;width:100%;margin-top:6px}
.ewv-s1 .ewv-lede{order:5}
.ewv-s1 .ewv-big{order:6}
.ewv .ewv-s1 .ewv-h1{font-size:46px}
.ewv-s1 .ewv-r .ewv-h3{display:none}
.ewv-inv-title{text-align:left}
.ewv-gap{display:none}
.ewv-s{gap:28px}
.ewv .ewv-h1{font-size:56px}
.ewv .ewv-h2{font-size:36px}
.ewv-lede{font-size:17px}
.ewv-big{font-size:24px}
.ewv-kicker{font-size:13px}
.ewv-go{justify-self:stretch}
.ewv-bar{padding:18px}
.ewv-tab{font-size:40px}
.ewv-bar-t{font-size:22px}
.ewv-opts{grid-template-columns:repeat(2,minmax(0,1fr))}
.ewv-o-p{font-size:28px}
.ewv-no{font-size:16px}
.ewv-d{width:20px;height:30px}
.ewv-d.ewv-b{width:16px;height:44px}
.ewv-drinks{gap:5px}
.ewv-inv{padding:20px 18px}
.ewv-inv-top{flex-wrap:wrap}
.ewv-inv-vat .ewv-inv-row{grid-template-columns:minmax(0,1fr) 70px}
.ewv-inv-vat .ewv-inv-row span[data-net],.ewv-inv-vat .ewv-inv-row span[data-vat],.ewv-inv-vat .ewv-inv-hd span:nth-child(2),.ewv-inv-vat .ewv-inv-hd span:nth-child(3){display:none}
.ewv-inv-sum{width:100%}
}
CSS;
}

function ew_inv_js() {
	return <<<'JS'
(function(){
 function stripeCheckout(stripe,opts){var make=stripe.createEmbeddedCheckoutPage||stripe.initEmbeddedCheckout;return make.call(stripe,opts);}
 document.querySelectorAll('.ewv[data-code]').forEach(function(root){
  var ds=root.dataset, s1=root.querySelector('.ewv-s1'), s2=root.querySelector('.ewv-s2'), form=root.querySelector('.ewv-form');
  var msg1=root.querySelector('[data-msg1]'), msg2=root.querySelector('[data-msg2]'), mount=root.querySelector('.ewv-mount');
  var base=+ds.base, adds=JSON.parse(ds.adds||'[]'), pick=-1, bid=0, key='', checkout=null, timer=null, ask=0;
  var drinks=root.querySelectorAll('.ewv-d');
  function money(p){return '£'+(p%100?(p/100).toFixed(2):(p/100).toLocaleString('en-GB'));}
  function money2(p){return '£'+(p/100).toLocaleString('en-GB',{minimumFractionDigits:2,maximumFractionDigits:2});}
  function set(sel,txt){root.querySelectorAll(sel).forEach(function(n){n.textContent=txt;});}
  function render(){
   var add=pick>=0?adds[pick]:0, g=base+add, lit=pick>=0?Math.round((pick+1)/adds.length*drinks.length):0;
   root.querySelectorAll('.ewv-o').forEach(function(o){o.classList.toggle('ewv-sel',+o.dataset.i===pick);});
   drinks.forEach(function(d,i){d.classList.toggle('ewv-lit',i<lit);});
   set('[data-tab]',money(add)); set('[data-total]',money(g));
   var mock=ds.mock?'£'+ds.mock:'';
   set('[data-sub]',ds.inc+(add?' '+ds.incbar.replace('{amount}',mock||money(add)):''));
   set('[data-gross]',mock||money2(g)); set('[data-net]',mock||money2(g/1.2)); set('[data-vat]',mock||money2(g-g/1.2));
  }
  function unmount(){if(checkout){try{checkout.destroy();}catch(e){} checkout=null;} mount.innerHTML='';}
  function load(){
   unmount();
   if(!ds.pk||!window.Stripe){msg2.textContent=ds.off;return;}
   var mine=++ask; msg2.textContent=ds.loading;
   var stripe=window.Stripe(ds.pk);
   stripeCheckout(stripe,{fetchClientSecret:function(){
    return fetch(ds.pay,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({code:ds.code,bid:bid,key:key,opt:pick})})
     .then(function(r){return r.json();}).then(function(j){if(!j.clientSecret)throw new Error(j.message||'error');return j.clientSecret;});
   }}).then(function(c){ if(mine!==ask){try{c.destroy();}catch(e){} return;} checkout=c; msg2.textContent=''; c.mount(mount); })
     .catch(function(err){ if(mine===ask)msg2.textContent=(err&&err.message)||ds.off; });
  }
  function later(){render(); clearTimeout(timer); timer=setTimeout(load,450);}
  root.addEventListener('click',function(e){
   var o=e.target.closest('.ewv-o'); if(o){pick=(+o.dataset.i===pick)?-1:+o.dataset.i; later(); return;}
   if(e.target.closest('.ewv-no')){if(pick!==-1){pick=-1; later();} return;}
   if(e.target.closest('.ewv-back')){unmount(); s2.hidden=true; s1.hidden=false; root.scrollIntoView({behavior:'smooth',block:'start'});}
  });
  form.addEventListener('submit',function(e){
   e.preventDefault();
   var email=form.email.value.trim();
   if(!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)){msg1.textContent=ds.need; form.email.focus(); return;}
   var go=form.querySelector('.ewv-go'); go.disabled=true; msg1.textContent='';
   fetch(ds.start,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({code:ds.code,email:email,company:form.company.value.trim(),vat:form.vat.value.trim()})})
    .then(function(r){return r.json();}).then(function(j){
     go.disabled=false;
     if(!j.ok)throw new Error(j.message||'');
     bid=j.bid; key=j.key;
     var vn=form.vat.value.trim(); set('[data-vatno]',vn?'VAT no. '+vn:''); if(form.company.value.trim())set('[data-co]',form.company.value.trim());
     s1.hidden=true; s2.hidden=false; root.scrollIntoView({behavior:'smooth',block:'start'});
     render(); load();
    }).catch(function(err){go.disabled=false; msg1.textContent=(err&&err.message)||ds.off;});
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
	if ( ! $post || ! ew_post_contains( $post, 'eastwood_invite' ) ) {
		return;
	}
	wp_register_style( 'ew-inv', false, array(), EW_FWP_VERSION );
	wp_enqueue_style( 'ew-inv' );
	wp_add_inline_style( 'ew-inv', ew_inv_css() );
	$deps = array();
	if ( '' !== ew_tix_pk() ) {
		wp_enqueue_script( 'stripe-js', 'https://js.stripe.com/v3/', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		$deps[] = 'stripe-js';
	}
	wp_register_script( 'ew-inv', '', $deps, EW_FWP_VERSION, true );
	wp_enqueue_script( 'ew-inv' );
	wp_add_inline_script( 'ew-inv', ew_inv_js() );
}, 22 );
