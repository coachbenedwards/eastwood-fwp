<?php
/**
 * The club shop: shirts with sizes, a basket, and Stripe's own form.
 *
 * [eastwood_shop title="Get the kit" postage="4.95"]
 * 26/27 Home Shirt | 45 | S,M,L,XL,2XL | Short sleeve
 * 26/27 Away Shirt | 45 | S,M,L,XL,2XL |
 * Training Top | 35 | S,M,L,XL | Quarter zip
 * [/eastwood_shop]
 *
 * One product per line: name | price in pounds | sizes separated by
 * commas | a short note. A blank price shows the "to be confirmed"
 * wording and that product cannot be added, so the block can go on a
 * page before the prices are settled without ever selling at a made-up
 * one. A product with no sizes written is sold as one thing.
 *
 * Built on the same bones as the tickets block, and the same rule holds:
 * every word the block shows is a field or a line, so prices, sizes and
 * wording are changed in Elementor and never in here.
 *
 * A size is part of the basket line, not a note on it -- two of the same
 * shirt in M and one in L are two lines, priced and sent to Stripe
 * separately, so the receipt says which is which.
 *
 * The browser only ever says which product, which size and how many.
 * Prices are read again on the server from the page itself at checkout,
 * so a tampered-with basket cannot set its own price.
 *
 * Money goes to Eastwood FC Ventures through the Stripe keys held by
 * ew_stripe_creds() -- merchandise is Ventures, not the CIC. Card
 * details never touch this site: Stripe draws the form inside the page.
 *
 * @package eastwood-fwp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* --- Keys ------------------------------------------------------------- */

/** The Ventures Stripe keys, from the one place the site keeps them. */
function ew_shop_pk() {
	return function_exists( 'ew_stripe_creds' ) ? (string) ( ew_stripe_creds()['publishable'] ?? '' ) : '';
}
function ew_shop_sk() {
	return function_exists( 'ew_stripe_creds' ) ? (string) ( ew_stripe_creds()['secret'] ?? '' ) : '';
}

/* --- The block -------------------------------------------------------- */

/** Pounds as somebody writes them, in pence; zero when nothing is set. */
function ew_shop_pence( $raw ) {
	$raw = preg_replace( '/[^0-9.]/', '', (string) $raw );
	return '' === $raw ? 0 : (int) round( (float) $raw * 100 );
}

function ew_shop_money( $pence ) {
	return '£' . ( 0 === $pence % 100 ? number_format( $pence / 100 ) : number_format( $pence / 100, 2 ) );
}

/** The products written in a block body. */
function ew_shop_lines( $content ) {
	$out = array();
	foreach ( ew_hb_lines( (string) $content ) as $line ) {
		$b = array_map( 'trim', explode( '|', $line ) );
		if ( '' === (string) ( $b[0] ?? '' ) ) {
			continue;
		}
		$out[] = array(
			'name'  => (string) $b[0],
			'pence' => ew_shop_pence( $b[1] ?? '' ),
			'sizes' => array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $b[2] ?? '' ) ) ) ) ),
			'note'  => (string) ( $b[3] ?? '' ),
		);
	}
	return $out;
}

function ew_shop_shortcode( $atts, $content = null ) {
	$a = shortcode_atts( array(
		'kicker'   => 'Club Shop',
		'title'    => 'Get the kit',
		'sub'      => '',
		'note'     => '',
		'pick'     => 'Choose a size',
		'need'     => 'Choose a size first',
		'tbc'      => '£ TBC',
		'soon'     => 'Coming soon',
		'postage'  => '',
		'post_as'  => 'Post it to me',
		'collect'  => 'Collect at Coronation Park',
		'free'     => 'free',
		'checkout' => 'Checkout',
		'co_title' => 'Your order',
		'loading'  => 'Loading the payment form…',
		'off'      => 'The shop opens soon.',
		'thanks'   => 'Thank you. Your receipt is on its way to your email.',
	), $atts, 'eastwood_shop' );

	$items = ew_shop_lines( $content );
	if ( ! $items ) {
		return '';
	}

	$page = get_the_ID();
	$ship = ew_shop_pence( $a['postage'] );
	$paid = isset( $_GET['ews_paid'] ); // phpcs:ignore WordPress.Security.NonceVerification

	$h  = '<section class="ews" id="shop" data-page="' . (int) $page . '" data-rest="' . esc_url( rest_url( 'eastwood/v1/shop/checkout' ) ) . '"';
	$h .= ' data-pk="' . esc_attr( ew_shop_pk() ) . '" data-off="' . esc_attr( $a['off'] ) . '" data-loading="' . esc_attr( $a['loading'] ) . '"';
	$h .= ' data-need="' . esc_attr( $a['need'] ) . '" data-ship="' . (int) $ship . '" data-postname="' . esc_attr( $a['post_as'] ) . '">';

	if ( $paid ) {
		$h .= '<p class="ews-thanks">' . esc_html( $a['thanks'] ) . '</p>';
	}
	if ( '' !== $a['kicker'] ) {
		$h .= '<p class="ews-kick">' . esc_html( $a['kicker'] ) . '</p>';
	}
	$h .= '<h2 class="ews-title">' . esc_html( $a['title'] ) . '</h2>';
	if ( '' !== $a['sub'] ) {
		$h .= '<p class="ews-sub">' . esc_html( $a['sub'] ) . '</p>';
	}

	$h .= '<div class="ews-grid ews-n' . min( 4, count( $items ) ) . '">';
	foreach ( $items as $i => $it ) {
		$on = $it['pence'] > 0;
		$h .= '<div class="ews-card' . ( $on ? '' : ' ews-off' ) . '" data-i="' . (int) $i . '" data-p="' . (int) $it['pence'] . '" data-n="' . esc_attr( $it['name'] ) . '">';
		$h .= '<h3 class="ews-name">' . esc_html( $it['name'] ) . '</h3>';
		$h .= '<p class="ews-pnote">' . esc_html( $it['note'] ) . '</p>';
		$h .= '<p class="ews-price">' . esc_html( $on ? ew_shop_money( $it['pence'] ) : $a['tbc'] ) . '</p>';

		if ( $on && $it['sizes'] ) {
			$h .= '<select class="ews-size" aria-label="' . esc_attr( $a['pick'] ) . '">';
			$h .= '<option value="">' . esc_html( $a['pick'] ) . '</option>';
			foreach ( $it['sizes'] as $s ) {
				$h .= '<option value="' . esc_attr( $s ) . '">' . esc_html( $s ) . '</option>';
			}
			$h .= '</select>';
		}

		if ( $on ) {
			$h .= '<div class="ews-qty"><button type="button" data-d="-1" aria-label="One fewer">−</button><span>0</span><button type="button" data-d="1" aria-label="One more">+</button></div>';
		} else {
			$h .= '<div class="ews-qty ews-soon">' . esc_html( $a['soon'] ) . '</div>';
		}
		$h .= '</div>';
	}
	$h .= '</div>';

	if ( $ship > 0 ) {
		$h .= '<div class="ews-deliver">';
		$h .= '<label><input type="radio" name="ews-deliver" value="collect" checked> ' . esc_html( $a['collect'] ) . ' <em>' . esc_html( $a['free'] ) . '</em></label>';
		$h .= '<label><input type="radio" name="ews-deliver" value="post"> ' . esc_html( $a['post_as'] ) . ' <em>' . esc_html( ew_shop_money( $ship ) ) . '</em></label>';
		$h .= '</div>';
	}

	if ( '' !== $a['note'] ) {
		$h .= '<p class="ews-note">' . esc_html( $a['note'] ) . '</p>';
	}

	$h .= '<div class="ews-co" hidden><h3 class="ews-co-title">' . esc_html( $a['co_title'] ) . '</h3><div class="ews-lines"></div><div class="ews-mount"></div><p class="ews-msg"></p></div>';
	$h .= '<div class="ews-bar"><div class="ews-bar-in"><span class="ews-bar-lines"></span><span class="ews-bar-tot">£0</span><button type="button" class="ews-go">' . esc_html( $a['checkout'] ) . '</button></div></div>';
	$h .= '</section>';
	return $h;
}
add_shortcode( 'eastwood_shop', 'ew_shop_shortcode' );

/* --- Checkout --------------------------------------------------------- */

/** The shop block on a page, read from the page itself. */
function ew_shop_block_on( $post ) {
	$found = null;
	$data  = json_decode( (string) get_post_meta( $post->ID, '_elementor_data', true ), true );
	if ( is_array( $data ) ) {
		$walk = function ( $nodes ) use ( &$walk, &$found ) {
			foreach ( (array) $nodes as $n ) {
				if ( $found ) {
					return;
				}
				$sc = (string) ( $n['settings']['shortcode'] ?? '' );
				if ( 0 === strpos( ltrim( $sc ), '[eastwood_shop' ) ) {
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
	if ( ! $found && preg_match( '/\[eastwood_shop[^\]]*\].*?\[\/eastwood_shop\]/s', (string) $post->post_content, $m ) ) {
		$found = ew_blocks_parse( $m[0] );
	}
	return $found;
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'eastwood/v1', '/shop/checkout', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) {
			$sk = ew_shop_sk();
			if ( '' === $sk ) {
				return new WP_Error( 'ews_off', 'The shop is not switched on.', array( 'status' => 503 ) );
			}
			$post = get_post( (int) $req->get_param( 'page' ) );
			if ( ! $post || 'publish' !== $post->post_status ) {
				return new WP_Error( 'ews_page', 'No such page.', array( 'status' => 404 ) );
			}
			$block = ew_shop_block_on( $post );
			if ( ! $block ) {
				return new WP_Error( 'ews_block', 'This page sells nothing.', array( 'status' => 404 ) );
			}

			$items = ew_shop_lines( $block['lines'] );
			$ship  = ew_shop_pence( $block['fields']['postage'] ?? '' );
			// Posting is only on offer when the block sets a postage price.
			$posted = $ship > 0 && 'post' === (string) $req->get_param( 'deliver' );

			$body = array(
				'ui_mode'                          => 'embedded',
				'mode'                             => 'payment',
				'return_url'                       => add_query_arg( 'ews_paid', '{CHECKOUT_SESSION_ID}', get_permalink( $post ) ) . '#shop',
				'phone_number_collection[enabled]' => 'true',
				'metadata[page]'                   => (string) $post->ID,
				'metadata[delivery]'               => $posted ? 'post' : 'collect',
			);
			// Something posted needs somewhere to post it to.
			if ( $posted ) {
				$body['shipping_address_collection[allowed_countries][0]'] = 'GB';
			}

			$count = 0;
			$k     = 0;
			// Keys arrive as "index|size", because a size is part of the
			// line and not a note on it.
			foreach ( (array) $req->get_param( 'items' ) as $key => $qty ) {
				$bits = explode( '|', (string) $key );
				$i    = (int) $bits[0];
				$size = sanitize_text_field( (string) ( $bits[1] ?? '' ) );
				$qty  = max( 0, min( 20, (int) $qty ) );
				if ( ! $qty || empty( $items[ $i ] ) || $items[ $i ]['pence'] <= 0 ) {
					continue;
				}
				// A size that was not offered for this product is not a size.
				if ( $items[ $i ]['sizes'] && ! in_array( $size, $items[ $i ]['sizes'], true ) ) {
					return new WP_Error( 'ews_size', 'Choose a size for every item.', array( 'status' => 400 ) );
				}

				$count += $qty;
				$body[ "line_items[$k][quantity]" ]                       = (string) $qty;
				$body[ "line_items[$k][price_data][currency]" ]           = 'gbp';
				$body[ "line_items[$k][price_data][unit_amount]" ]        = (string) $items[ $i ]['pence'];
				$body[ "line_items[$k][price_data][product_data][name]" ] = trim( $items[ $i ]['name'] . ( '' !== $size ? ' · ' . $size : '' ) );
				$k++;
			}

			if ( ! $count || $count > 50 ) {
				return new WP_Error( 'ews_qty', 'Choose between 1 and 50 items.', array( 'status' => 400 ) );
			}

			// Postage is its own line so it shows on the receipt rather than
			// being hidden inside the price of a shirt.
			if ( $posted ) {
				$body[ "line_items[$k][quantity]" ]                       = '1';
				$body[ "line_items[$k][price_data][currency]" ]           = 'gbp';
				$body[ "line_items[$k][price_data][unit_amount]" ]        = (string) $ship;
				$body[ "line_items[$k][price_data][product_data][name]" ] = 'Postage';
			}

			$res = wp_remote_post( 'https://api.stripe.com/v1/checkout/sessions', array(
				'timeout' => 20,
				'headers' => array( 'Authorization' => 'Bearer ' . $sk ),
				'body'    => $body,
			) );
			if ( is_wp_error( $res ) ) {
				return new WP_Error( 'ews_stripe', 'Could not reach Stripe.', array( 'status' => 502 ) );
			}
			$j = json_decode( (string) wp_remote_retrieve_body( $res ), true );
			if ( empty( $j['client_secret'] ) ) {
				$msg = (string) ( $j['error']['message'] ?? 'Stripe refused the order.' );
				return new WP_Error( 'ews_stripe', $msg, array( 'status' => 502 ) );
			}
			return array( 'clientSecret' => $j['client_secret'] );
		},
	) );
} );

/* --- Styles and script ------------------------------------------------ */

function ew_shop_css() {
	return <<<'CSS'
.ews,.ews-bar{--ews-card:#231c16;--ews-cream:#e8d5b0;--ews-txt:#e9e2d6;--ews-mut:rgba(233,226,214,.6);--ews-line:rgba(232,213,176,.22)}
.ews{max-width:1180px;margin:0 auto;padding:8px 0;color:var(--ews-txt);font-family:'Instrument Sans',system-ui,sans-serif}
.ews p{margin:0}
.ews .ews-kick{font-family:Anton,Impact,sans-serif;letter-spacing:.18em;text-transform:uppercase;color:var(--ews-cream);font-size:15px;text-align:center}
.ews .ews-title{font-family:Anton,Impact,sans-serif;font-weight:400;text-transform:uppercase;color:var(--ews-cream);font-size:clamp(34px,5vw,52px);line-height:1;text-align:center;margin:12px 0 10px;background:none;padding:0}
.ews .ews-sub{text-align:center;color:var(--ews-mut);font-size:17px}
.ews-grid{display:grid;gap:20px;margin-top:40px;grid-template-columns:repeat(3,1fr)}
.ews-grid.ews-n1{grid-template-columns:minmax(0,420px);justify-content:center}
.ews-grid.ews-n2{grid-template-columns:repeat(2,minmax(0,420px));justify-content:center}
.ews-grid.ews-n4{grid-template-columns:repeat(4,1fr)}
.ews-card{background:var(--ews-card);border:1px solid var(--ews-line);display:flex;flex-direction:column;padding:28px;text-align:left}
.ews-card.ews-sel{border-color:rgba(232,213,176,.7)}
.ews .ews-name{font-family:Anton,Impact,sans-serif;font-weight:400;text-transform:uppercase;color:var(--ews-cream);font-size:30px;line-height:1.05;margin:0 0 6px;background:none;padding:0}
.ews .ews-pnote{font-size:14px;color:var(--ews-mut);min-height:20px}
.ews .ews-price{font-family:Anton,Impact,sans-serif;font-size:46px;color:var(--ews-cream);line-height:1;margin:10px 0 18px;flex:1}
.ews-size{width:100%;height:52px;margin-bottom:12px;background:none;border:1px solid var(--ews-line);color:var(--ews-txt);font-family:'Instrument Sans',system-ui,sans-serif;font-size:16px;padding:0 14px}
.ews-size option{background:#231c16;color:#e9e2d6}
.ews-size.ews-ask{border-color:#e8d5b0}
.ews-qty{display:flex;align-items:center;justify-content:space-between;border:1px solid var(--ews-line);height:52px}
.ews-qty button{width:52px;height:100%;background:none;border:0;color:var(--ews-cream);font-size:24px;cursor:pointer;padding:0}
.ews-qty button:hover{background:rgba(232,213,176,.08)}
.ews-qty span{font-family:Anton,Impact,sans-serif;font-size:24px;color:var(--ews-txt)}
.ews-qty.ews-soon{justify-content:center;font-size:14px;letter-spacing:.12em;text-transform:uppercase;color:var(--ews-mut)}
.ews-deliver{display:flex;gap:28px;justify-content:center;flex-wrap:wrap;margin-top:32px}
.ews-deliver label{display:flex;align-items:center;gap:9px;font-size:15px;color:var(--ews-txt);cursor:pointer}
.ews-deliver em{font-style:normal;color:var(--ews-mut)}
.ews .ews-note{font-size:14px;color:var(--ews-mut);text-align:center;margin-top:24px}
.ews .ews-thanks{border:1px solid rgba(232,213,176,.7);color:var(--ews-cream);padding:18px;text-align:center;margin-bottom:32px;font-size:18px}
.ews-co{margin-top:48px;border-top:1px solid var(--ews-line);padding-top:40px;text-align:left}
.ews .ews-co-title{font-family:Anton,Impact,sans-serif;font-weight:400;text-transform:uppercase;color:var(--ews-cream);font-size:30px;margin:0 0 14px;background:none;padding:0}
.ews-lines{margin-bottom:20px}
.ews-lines div{display:flex;justify-content:space-between;border-top:1px solid var(--ews-line);padding:10px 0;font-size:16px}
.ews-lines div.ews-t{font-family:Anton,Impact,sans-serif;font-size:26px;color:var(--ews-cream)}
.ews-mount{background:#fff;border-radius:6px;min-height:40px}
.ews-mount:empty{display:none}
.ews .ews-msg{color:var(--ews-mut);font-size:15px;margin-top:12px}
.ews-bar{position:fixed;left:0;right:0;bottom:0;z-index:9990;background:#0b0908;border-top:1px solid var(--ews-line);transform:translateY(110%);transition:transform .25s}
.ews-bar.ews-on{transform:none}
.ews-bar-in{max-width:1180px;margin:0 auto;padding:14px 16px;display:flex;align-items:center;gap:20px}
.ews-bar-lines{flex:1;font-size:14px;color:var(--ews-mut)}
.ews-bar-tot{font-family:Anton,Impact,sans-serif;font-size:30px;color:var(--ews-cream)}
.ews-go{background:var(--ews-cream);color:#1b140f;border:0;font-family:Anton,Impact,sans-serif;text-transform:uppercase;letter-spacing:.06em;font-size:18px;padding:15px 34px;cursor:pointer}
.ews-go:hover{background:#f3e6c8}
@media(max-width:900px){.ews-grid,.ews-grid.ews-n2,.ews-grid.ews-n4{grid-template-columns:1fr}.ews-bar-lines{display:none}.ews-bar-in{justify-content:space-between}}
CSS;
}

function ew_shop_js() {
	return <<<'JS'
(function(){
  document.querySelectorAll('.ews').forEach(function(root){
    var q={}, cards=root.querySelectorAll('.ews-card'), bar=root.querySelector('.ews-bar'),
        co=root.querySelector('.ews-co'), mount=root.querySelector('.ews-mount'),
        msg=root.querySelector('.ews-msg'), ship=+root.dataset.ship||0, checkout=null;
    document.body.appendChild(bar);
    function money(p){return '£'+(p%100?(p/100).toFixed(2):(p/100).toLocaleString('en-GB'));}
    function posting(){var r=root.querySelector('.ews-deliver input:checked');return !!(r&&r.value==='post');}
    function key(c){var s=c.querySelector('.ews-size');return c.dataset.i+'|'+(s?s.value:'');}
    function render(){
      var tot=0, lines=[];
      cards.forEach(function(c){
        var n=q[key(c)]||0, s=c.querySelector('.ews-qty span');
        if(s)s.textContent=n;
        c.classList.toggle('ews-sel',n>0);
      });
      Object.keys(q).forEach(function(k){
        var n=q[k]; if(!n) return;
        var bits=k.split('|'), c=root.querySelector('.ews-card[data-i="'+bits[0]+'"]');
        if(!c) return;
        tot+=n*(+c.dataset.p);
        lines.push([n,c.dataset.n+(bits[1]?' · '+bits[1]:''),n*(+c.dataset.p)]);
      });
      if(tot&&ship&&posting()){tot+=ship;lines.push([1,root.dataset.postname,ship]);}
      bar.classList.toggle('ews-on',tot>0);
      bar.querySelector('.ews-bar-tot').textContent=money(tot);
      bar.querySelector('.ews-bar-lines').textContent=lines.map(function(l){return l[0]+' × '+l[1];}).join(' · ');
      root.querySelector('.ews-lines').innerHTML=lines.map(function(l){
        return '<div><span>'+l[0]+' × '+l[1].replace(/</g,'&lt;')+'</span><span>'+money(l[2])+'</span></div>';
      }).join('')+(tot?'<div class="ews-t"><span></span><span>'+money(tot)+'</span></div>':'');
      if(checkout){checkout.destroy();checkout=null;mount.innerHTML='';co.hidden=true;msg.textContent='';}
    }
    root.addEventListener('change',function(e){
      if(e.target.classList.contains('ews-size')){e.target.classList.remove('ews-ask');render();}
      if(e.target.name==='ews-deliver')render();
    });
    root.addEventListener('click',function(e){
      var b=e.target.closest('.ews-qty button'); if(!b)return;
      var c=b.closest('.ews-card'), s=c.querySelector('.ews-size');
      if(s&&!s.value){s.classList.add('ews-ask');s.focus();msg.textContent=root.dataset.need;return;}
      var k=key(c);
      q[k]=Math.max(0,Math.min(20,(q[k]||0)+(+b.dataset.d)));
      if(!q[k])delete q[k];
      render();
    });
    bar.querySelector('.ews-go').addEventListener('click',function(){
      co.hidden=false; co.scrollIntoView({behavior:'smooth',block:'start'});
      if(!root.dataset.pk||!window.Stripe){msg.textContent=root.dataset.off;return;}
      if(checkout)return;
      msg.textContent=root.dataset.loading;
      var stripe=window.Stripe(root.dataset.pk);
      stripe.initEmbeddedCheckout({fetchClientSecret:function(){
        return fetch(root.dataset.rest,{method:'POST',headers:{'Content-Type':'application/json'},
          body:JSON.stringify({page:+root.dataset.page,items:q,deliver:posting()?'post':'collect'})})
          .then(function(r){return r.json();})
          .then(function(j){if(!j.clientSecret)throw new Error(j.message||'error');return j.clientSecret;});
      }}).then(function(c){checkout=c;msg.textContent='';c.mount(mount);bar.classList.remove('ews-on');})
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
	if ( ! $post || ! ew_post_contains( $post, 'eastwood_shop' ) ) {
		return;
	}
	wp_register_style( 'ew-shop', false, array(), EW_FWP_VERSION );
	wp_enqueue_style( 'ew-shop' );
	wp_add_inline_style( 'ew-shop', ew_shop_css() );

	$deps = array();
	if ( '' !== ew_shop_pk() ) {
		wp_enqueue_script( 'stripe-js', 'https://js.stripe.com/v3/', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		$deps[] = 'stripe-js';
	}
	wp_register_script( 'ew-shop', '', $deps, EW_FWP_VERSION, true );
	wp_enqueue_script( 'ew-shop' );
	wp_add_inline_script( 'ew-shop', ew_shop_js() );
}, 22 );
