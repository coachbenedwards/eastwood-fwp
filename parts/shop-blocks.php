<?php
/**
 * The shop pages as blocks.
 *
 * Both of these were pages of hand-written HTML -- the shop in the page
 * itself, the shirt in a single code box -- so nobody but me could change
 * a word of either. They are blocks now, and every word they put on the
 * screen is a field: the card names and prices, the FIT and SIZE labels,
 * the two delivery choices and their small print, and the lines shown
 * while the shop is loading or when it cannot be reached.
 *
 * What is NOT a field is the data: prices, sizes and stock come from the
 * shop API, as they did before.
 *
 * @package eastwood-fwp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The drawn shirt both pages use in place of a photograph. */
function ew_shop_shirt_svg() {
	return '<svg viewBox="0 0 440 520" width="100%"> <defs> <linearGradient id="bodyGp" x1="0.1" y1="0.05" x2="0.92" y2="0.95"> <stop offset="0" stop-color="#ef1f1f"></stop><stop offset=".45" stop-color="#d81212"></stop> <stop offset=".8" stop-color="#b40707"></stop><stop offset="1" stop-color="#950303"></stop> </linearGradient> <clipPath id="clipSp"><path d="M150 62 C172 51 196 46 220 46 C244 46 268 51 290 62 L390 142 L368 212 L318 192 L318 408 C318 417 311 423 302 423 L138 423 C129 423 122 417 122 408 L122 192 L72 212 L50 142 Z"></path></clipPath> <filter id="softp" x="-30%" y="-30%" width="160%" height="160%"> <feGaussianBlur stdDeviation="14"></feGaussianBlur> </filter> <filter id="soft2p" x="-30%" y="-30%" width="160%" height="160%"> <feGaussianBlur stdDeviation="7"></feGaussianBlur> </filter> <filter id="dropSp" x="-30%" y="-20%" width="160%" height="155%"> <feDropShadow dx="0" dy="16" stdDeviation="18" flood-color="#000" flood-opacity=".32"></feDropShadow> </filter> </defs> <g filter="url(#dropSp)"> <path d="M150 62 C172 51 196 46 220 46 C244 46 268 51 290 62 L390 142 L368 212 L318 192 L318 408 C318 417 311 423 302 423 L138 423 C129 423 122 417 122 408 L122 192 L72 212 L50 142 Z" fill="url(#bodyGp)"></path> <g clip-path="url(#clipSp)"> <g filter="url(#softp)" opacity=".55"> <rect x="78" y="40" width="58" height="420" fill="#6d0000"></rect> <rect x="304" y="40" width="60" height="420" fill="#6d0000"></rect> <ellipse cx="220" cy="150" rx="96" ry="66" fill="#ff6a6a" opacity=".55"></ellipse> </g> <g filter="url(#soft2p)" opacity=".4"> <path d="M158 96 C166 200 162 320 158 430 L176 430 C180 320 182 200 172 96 Z" fill="#7d0000"></path> <path d="M282 96 C272 200 278 320 282 430 L300 430 C304 320 300 200 296 96 Z" fill="#7d0000"></path> <path d="M206 92 C210 220 208 330 210 430 L230 430 C232 330 230 220 234 92 Z" fill="#ff8a8a" opacity=".5"></path> </g> <path d="M130 66 L310 66 L310 94 L130 94 Z" fill="#fff" opacity=".07" filter="url(#soft2p)"></path> </g> <path d="M180 58 C191 83 204 94 220 94 C236 94 249 83 260 58 L250 55 C242 75 232 82 220 82 C208 82 198 75 190 55 Z" fill="#1c1c1c"></path> <path d="M190 55 C198 77 208 84 220 84 C232 84 242 77 250 55" fill="none" stroke="#f2f2f2" stroke-width="3" opacity=".95"></path> </g> </svg>';
}

/* --- The shop index ------------------------------------------------- */

/**
 * [eastwood_shop]
 *
 * One card per line in the body:
 *
 *   Home shirt 2026/27 | From £30 | Adult, Women's and Kids fits | /shop/home-shirt/
 *
 * @param array       $atts    Attributes.
 * @param string|null $content Body.
 * @return string
 */
function ew_shop_shortcode( $atts, $content = null ) {
	$cards = array();
	foreach ( preg_split( '/\r\n|\r|\n/', (string) $content ) as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}
		$bits = array_map( 'trim', explode( '|', $line ) );
		if ( '' === (string) ( $bits[0] ?? '' ) ) {
			continue;
		}
		$cards[] = array(
			'name'  => (string) $bits[0],
			'price' => (string) ( $bits[1] ?? '' ),
			'note'  => (string) ( $bits[2] ?? '' ),
			'href'  => (string) ( $bits[3] ?? '' ),
		);
	}
	if ( ! $cards ) {
		return '';
	}

	/*
	 * The same class names the page used when it was hand-written HTML,
	 * deliberately: the stylesheet that squares this page up with the rest
	 * of the site is written against them, so keeping them means the page
	 * comes out of the conversion looking identical rather than needing a
	 * second set of rules that says the same thing.
	 */
	$out = '<div class="ewshop"><div class="ewshop-grid">';
	foreach ( $cards as $c ) {
		$out .= '<a class="ewshop-card" href="' . esc_url( $c['href'] ) . '">';
		$out .= '<div class="ewshop-pic">' . ew_shop_shirt_svg() . '</div>';
		$out .= '<div class="ewshop-body">';
		$out .= '<h2>' . esc_html( $c['name'] ) . '</h2>';
		if ( '' !== $c['price'] ) {
			$out .= '<p class="p">' . esc_html( $c['price'] ) . '</p>';
		}
		if ( '' !== $c['note'] ) {
			$out .= '<p class="m">' . esc_html( $c['note'] ) . '</p>';
		}
		$out .= '</div></a>';
	}
	$out .= '</div></div>';

	return $out;
}
add_shortcode( 'eastwood_shop', 'ew_shop_shortcode' );

/* --- One product ----------------------------------------------------- */

/**
 * [eastwood_shirt]
 *
 * One fit per line in the body: the code the shop API uses, the word a
 * person reads, and the SKU it belongs to.
 *
 *   ADULT | Adult | home-shirt-2627
 *   WOMENS | Women's | home-shirt-2627
 *   KIDS | Kids | home-shirt-2627-kids
 *
 * @param array       $atts    Attributes.
 * @param string|null $content Body.
 * @return string
 */
function ew_shirt_shortcode( $atts, $content = null ) {
	$a = shortcode_atts(
		array(
			'title'         => '',
			'brand'         => '',
			'fit_label'     => 'Fit',
			'size_label'    => 'Size',
			'ful_label'     => 'How do you want it',
			'collect_title' => 'Collect at the ground',
			'collect_note'  => '',
			'post_title'    => 'Post it to me',
			'post_note'     => 'Royal Mail. {price} added at checkout.',
			'prompt'        => 'Choose a size.',
			'pay_note'      => '',
			'empty'         => 'Not on sale yet.',
			'failed'        => 'Could not load the shop just now.',
			'collected'     => 'collected at the ground',
			'posted'        => 'posted',
			'pay'           => 'Pay by card',
			'co_title'      => 'Your order',
			'loading'       => 'Loading the payment form…',
			'off'           => 'Card payment opens soon.',
			'thanks'        => 'Thank you. Your receipt is on its way to your email.',
		),
		(array) $atts,
		'eastwood_shirt'
	);

	$fits = array();
	foreach ( preg_split( '/\r\n|\r|\n/', (string) $content ) as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}
		$bits = array_map( 'trim', explode( '|', $line ) );
		$code = strtoupper( (string) ( $bits[0] ?? '' ) );
		if ( '' === $code ) {
			continue;
		}
		$fits[] = array(
			'code' => $code,
			'name' => (string) ( $bits[1] ?? $code ),
			'sku'  => (string) ( $bits[2] ?? '' ),
		);
	}
	if ( ! $fits ) {
		return '';
	}

	// Everything the script needs to say, handed to it rather than written
	// into it, so the words stay here where a person can reach them.
	$words = array(
		'fits'      => $fits,
		'collect'   => array( $a['collect_title'], $a['collect_note'] ),
		'post'      => array( $a['post_title'], $a['post_note'] ),
		'prompt'    => $a['prompt'],
		'pay'       => $a['pay_note'],
		'empty'     => $a['empty'],
		'failed'    => $a['failed'],
		'collected' => $a['collected'],
		'posted'    => $a['posted'],
		'paybtn'    => $a['pay'],
		'loading'   => $a['loading'],
		'off'       => $a['off'],
		// The publishable key is public by design; the secret never
		// leaves the server.
		'pk'        => function_exists( 'ew_stripe_creds' )
			? (string) ( ew_stripe_creds()['publishable'] ?? '' ) : '',
		'rest'      => rest_url( 'eastwood/v1/shirt/checkout' ),
	);

	ob_start();
	?>
<div class="ewsh" data-shirt="<?php echo esc_attr( wp_json_encode( $words ) ); ?>">
 <div class="ewsh-wrap">
  <div class="ewsh-gal"><?php echo ew_shop_shirt_svg(); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
  <div class="ewsh-info">
   <?php if ( isset( $_GET['ewsh_paid'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
   <p class="ewsh-thanks"><?php echo esc_html( $a['thanks'] ); ?></p>
   <?php endif; ?>
   <h1><?php echo esc_html( $a['title'] ); ?></h1>
   <p class="ewsh-sub"><?php echo esc_html( $a['brand'] ); ?></p>
   <p class="ewsh-price" id="ewshPrice"></p>
   <p class="ewsh-note" id="ewshNote"></p>
   <p class="ewsh-lab"><?php echo esc_html( $a['fit_label'] ); ?></p>
   <div class="ewsh-fits" id="ewshFits"></div>
   <p class="ewsh-lab"><?php echo esc_html( $a['size_label'] ); ?></p>
   <div class="ewsh-sizes" id="ewshSizes"></div>
   <p class="ewsh-lab"><?php echo esc_html( $a['ful_label'] ); ?></p>
   <div class="ewsh-ful" id="ewshFul"></div>
   <div class="ewsh-pay" id="ewshPay"></div>
   <p class="ewsh-sum" id="ewshSum"></p>
   <div class="ewsh-co" hidden>
    <p class="ewsh-lab"><?php echo esc_html( $a['co_title'] ); ?></p>
    <div class="ewsh-mount"></div>
    <p class="ewsh-msg"></p>
   </div>
  </div>
 </div>
</div>
	<?php
	return trim( ob_get_clean() );
}
add_shortcode( 'eastwood_shirt', 'ew_shirt_shortcode' );

/* --- What the two pages wear and how the product page behaves -------- */

/**
 * The shop index stylesheet.
 *
 * Word for word what was in the page's own style tag before it became a
 * block, minus the h1 and the wrapper, which the page no longer draws: the
 * title is a heading widget now and gets the red band from the sheet that
 * gives every other page its band.
 */
function ew_shop_css() {
	return /* The row holding the title, the line under it and the grid is laid out
   in normal flow, so the margins between them collapse the way they did
   when the three were one piece of HTML. */
		'.e-con.ew-shoprow{display:block}'
		/* The title band. The sheet that gives every page its band sets a
		   line-height of 1 and no bottom margin; this page's own markup used
		   to add 1.05 and 34px, which is three pixels of red and the gap
		   under the band. Measured off the page as it was, not guessed. */
		. '.ew-shoprow .elementor-widget-heading h1.elementor-heading-title'
		. '{line-height:1.05;margin:0 calc(50% - 50vw) 34px !important}'
		/* The line under the title is a text widget now, so it needs the
		   column gutter the wrapper used to give it.
		
		   15px, not the 17px the plugin's own sheet asks for: two rules set
		   this paragraph and the page's own came last, so 15px is what it has
		   always been. Its line-height was 1.6 inherited as a number from the
		   wrapper, which on 15px text is 24, and its margin 30. Those three
		   numbers are the seven pixels the page was out by. */
		. '.elementor-widget-text-editor > p.lede{font-size:15px;line-height:1.6;'
		. 'color:#5c5c5c;margin:0 0 30px;'
		. 'padding-left:max(16px,calc(50vw - 624px));'
		. 'padding-right:max(16px,calc(50vw - 624px))}'
		. '.ewshop{max-width:1120px;margin:0 auto;padding:40px 20px 70px; font:400 16px/1.6 Inter,-apple-system,system-ui,sans-serif;color:#111} .ewshop h1{font-size:40px;line-height:1.05;margin:0 0 6px;letter-spacing:.3px} .ewshop .lede{color:#5c5c5c;margin:0 0 30px;font-size:15px} .ewshop-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:20px} .ewshop-card{border:1px solid #e4e4e7;border-radius:6px;overflow:hidden;background:#fff; text-decoration:none;color:inherit;display:block;transition:border-color .15s} .ewshop-card:hover{border-color:#CC0000} .ewshop-pic{background:#CC0000;height:190px;display:flex;align-items:center;justify-content:center} .ewshop-pic svg{width:120px;height:auto} .ewshop-body{padding:16px 18px 20px} .ewshop-body h2{font-size:17px;margin:0 0 4px;line-height:1.3} .ewshop-body .p{font-weight:700;margin:0 0 6px} .ewshop-body .m{font-size:13px;color:#6a6a6a;margin:0}';
}

/**
 * The product page stylesheet, as it was in the code box, with one
 * correction Ben asked for.
 *
 * The sheet styled .ewsh-info h2 and the page has always had an h1, so
 * the product title had never once been drawn in Anton -- it came out in
 * whatever the browser uses by default, among a page of club headings.
 * The selector takes both now.
 */
function ew_shirt_css() {
	return /* The section this page used to sit in held the content 30px in
		   from each edge on a phone. The row it sits in now does not, which
		   made the shirt bigger and the text column narrower -- eleven
		   pixels of difference that were really two larger ones cancelling.
		   Measured at 390: with this the page is the height it was. */
		'@media(max-width:767px){.ewsh{padding-left:30px;padding-right:30px}}'
		. '.ewsh{font-family:"Instrument Sans",system-ui,sans-serif;color:#101010}.ewsh-wrap{display:flex;gap:52px;align-items:flex-start;max-width:1140px;margin:0 auto}.ewsh-gal{flex:0 0 470px;background:#f2f2f2;border-radius:12px;padding:26px 36px 20px}.ewsh-info{flex:1 1 auto;min-width:0}.ewsh-info h1,.ewsh-info h2{font-family:Anton,Impact,sans-serif;font-weight:400;font-size:42px;line-height:1.03;letter-spacing:.5px;text-transform:uppercase;margin:0 0 6px;color:#101010}.ewsh-sub{font-size:14px;color:#777;margin:0 0 22px}.ewsh-price{font-family:Anton,Impact,sans-serif;font-size:38px;margin:0 0 4px;color:#101010}.ewsh-note{font-size:13px;color:#8a8a8a;margin:0 0 26px}.ewsh-lab{font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;margin:0 0 10px}.ewsh-sizes{display:flex;gap:9px;flex-wrap:wrap;margin:0 0 26px}.ewsh-sizes button{min-width:62px;padding:13px 6px;background:#fff;border:1.5px solid #d6d6d6;border-radius:6px;font:inherit;font-size:14px;font-weight:600;cursor:pointer}.ewsh-sizes button.on{border-color:#101010;background:#101010;color:#fff}.ewsh-sizes button[disabled]{color:#c0c0c0;border-style:dashed;cursor:not-allowed;text-decoration:line-through}.ewsh-ful{display:flex;flex-direction:column;gap:10px;margin:0 0 26px}.ewsh-ful label{display:flex;gap:12px;align-items:flex-start;border:1.5px solid #d6d6d6;border-radius:8px;padding:15px 16px;cursor:pointer}.ewsh-ful label.on{border-color:#101010}.ewsh-ful b{display:block;font-size:15px;margin-bottom:3px}.ewsh-ful span span{display:block;font-size:13px;color:#777;line-height:1.45}.ewsh-pay{border:1.5px solid #e2e2e2;border-radius:10px;padding:22px;background:#fafafa}.ewsh-pay p{margin:0;font-size:14px;line-height:1.6;color:#666}.ewsh-pay b{display:block;font-size:12px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#101010;margin-bottom:6px}@media(max-width:900px){.ewsh-wrap{flex-direction:column;gap:26px}.ewsh-gal{flex:1 1 auto;width:100%}} .ewsh-fits{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 18px} .ewsh-fits button{font:700 13px/1 Inter,sans-serif;letter-spacing:.4px;padding:11px 18px; border:1.5px solid #d8d8dc;background:#fff;color:#111;border-radius:4px;cursor:pointer} .ewsh-fits button.on{border-color:#CC0000;background:#CC0000;color:#fff} .ewsh-sizes button[disabled]{opacity:.4;text-decoration:line-through;cursor:not-allowed} .ewsh-sizes button.on{border-color:#CC0000;background:#CC0000;color:#fff} .ewsh-sum{margin:14px 0 0;font-size:14px;color:#444} .ewsh-sum b{color:#111}      .ewsh-go{margin:0;width:100%;background:#CC0000;color:#fff;border:0;border-radius:6px;font:700 15px/1 Inter,sans-serif;letter-spacing:.4px;padding:17px 20px;cursor:pointer}      .ewsh-go:hover{background:#b30000}      .ewsh-small{margin:12px 0 0;font-size:13px;color:#777;line-height:1.5}      .ewsh-co{margin:18px 0 0}      .ewsh-mount{background:#fff;border-radius:6px;min-height:40px}      .ewsh-mount:empty{display:none}      .ewsh-msg{margin:10px 0 0;font-size:13px;color:#777}      .ewsh-thanks{border:1.5px solid #CC0000;border-radius:8px;padding:16px 18px;margin:0 0 22px;font-size:15px;color:#101010}';
}

/**
 * The product page's behaviour.
 *
 * Unchanged from the code box except in one respect: every word it used to
 * carry is now read off the block, so changing "Collect at the ground" is
 * an edit in Elementor rather than an edit here.
 */
function ew_shirt_js() {
	return <<<'JS'
(function(){
 var EL = document.querySelector('.ewsh');
 if(!EL) return;
 var W;
 try { W = JSON.parse(EL.getAttribute('data-shirt') || '{}'); } catch(e){ return; }
 if(!W.fits || !W.fits.length) return;

 var API = '/wp-json/eastwood/v1/shop/item';
 var NAMES = {}, ORDER = [], SKUS = {};
 W.fits.forEach(function(f){
  NAMES[f.code] = f.name; ORDER.push(f.code);
  (SKUS[f.sku] = SKUS[f.sku] || []).push(f.code);
 });

 var model = {}, fit = null, size = null, post = false, checkout = null;
 var $ = function(id){ return document.getElementById(id); };
 function money(n){ return '\u00a3' + Number(n).toFixed(2); }

 function load(){
  var jobs = Object.keys(SKUS).map(function(sku){
   return fetch(API + '?' + 'sku=' + encodeURIComponent(sku))
    .then(function(r){ return r.ok ? r.json() : null; })
    .then(function(d){ return d ? {fits:SKUS[sku], data:d} : null; });
  });
  Promise.all(jobs).then(function(res){
   res.forEach(function(r){
    if(!r) return;
    r.fits.forEach(function(f){
     var sizes = (r.data.sizes||[]).filter(function(x){ return x.size.indexOf(f + ' ') === 0; })
       .map(function(x){ return {size:x.size, label:x.size.slice(f.length+1), stock:x.in_stock}; });
     if(sizes.length){
      model[f] = {sku:r.data.sku, price:r.data.price, postage:r.data.postage, sizes:sizes};
     }
    });
   });
   var have = ORDER.filter(function(f){ return model[f]; });
   if(!have.length){ $('ewshNote').textContent = W.empty; return; }
   fit = have[0];
   $('ewshNote').textContent = '';
   drawFits(have); drawSizes(); drawFul(); drawPay();
  }).catch(function(){ $('ewshNote').textContent = W.failed; });
 }

 function drawFits(have){
  var w = $('ewshFits'); w.innerHTML = '';
  have.forEach(function(f){
   var b = document.createElement('button');
   b.type = 'button'; b.textContent = NAMES[f] || f;
   if(f === fit) b.className = 'on';
   b.addEventListener('click', function(){ fit = f; size = null; post = false;
     drawFits(have); drawSizes(); drawFul(); drawPay(); });
   w.appendChild(b);
  });
  $('ewshPrice').textContent = money(model[fit].price);
 }

 function drawSizes(){
  var w = $('ewshSizes'); w.innerHTML = '';
  model[fit].sizes.forEach(function(s){
   var b = document.createElement('button');
   b.type = 'button'; b.textContent = s.label;
   if(!s.stock) b.disabled = true;
   if(s.size === size) b.className = 'on';
   b.addEventListener('click', function(){ size = s.size; drawSizes(); drawPay(); });
   w.appendChild(b);
  });
 }

 function drawFul(){
  var w = $('ewshFul'), m = model[fit]; w.innerHTML = '';
  var opts = [{k:false, t:W.collect[0], d:W.collect[1]}];
  if(Number(m.postage) > 0){
   opts.push({k:true, t:W.post[0], d:String(W.post[1]).replace('{price}', money(m.postage))});
  }
  opts.forEach(function(o){
   var l = document.createElement('label');
   if(o.k === post) l.className = 'on';
   var b = document.createElement('b'); b.textContent = o.t;
   var s = document.createElement('span'); s.textContent = o.d;
   l.appendChild(b); l.appendChild(s);
   l.addEventListener('click', function(){ post = o.k; drawFul(); drawPay(); });
   w.appendChild(l);
  });
 }

 function drawPay(){
  var m = model[fit], pay = $('ewshPay'), sum = $('ewshSum');
  closePay();
  if(!size){ sum.textContent = ''; pay.innerHTML = '<p></p>';
   pay.firstChild.textContent = W.prompt; return; }
  var total = Number(m.price) + (post ? Number(m.postage) : 0);
  sum.innerHTML = '';
  var b = document.createElement('b'); b.textContent = money(total);
  sum.appendChild(b);
  sum.appendChild(document.createTextNode(' \u2014 ' + (NAMES[fit]||fit) + ' ' +
    size.slice((fit+' ').length) + ', ' + (post ? W.posted : W.collected)));
  pay.innerHTML = '';
  var btn = document.createElement('button');
  btn.type = 'button'; btn.className = 'ewsh-go'; btn.textContent = W.paybtn;
  btn.addEventListener('click', openPay);
  pay.appendChild(btn);
  if(W.pay){
   var small = document.createElement('p');
   small.className = 'ewsh-small'; small.textContent = W.pay;
   pay.appendChild(small);
  }
 }

 /* Any change of fit, size or delivery throws the open form away: it was
    priced for the old choice and Stripe will not re-price it. */
 function closePay(){
  var co = EL.querySelector('.ewsh-co');
  if(checkout){ checkout.destroy(); checkout = null; }
  EL.querySelector('.ewsh-mount').innerHTML = '';
  EL.querySelector('.ewsh-msg').textContent = '';
  co.hidden = true;
 }

 function openPay(){
  var co = EL.querySelector('.ewsh-co'),
      mount = EL.querySelector('.ewsh-mount'),
      msg = EL.querySelector('.ewsh-msg');
  co.hidden = false;
  co.scrollIntoView({behavior:'smooth', block:'nearest'});
  if(!W.pk || !window.Stripe){ msg.textContent = W.off; return; }
  if(checkout) return;
  msg.textContent = W.loading;
  var stripe = window.Stripe(W.pk), m = model[fit];
  /* The browser says which shirt, which size and whether it is posted.
     What any of it costs is read again on the server. */
  stripe.initEmbeddedCheckout({fetchClientSecret: function(){
   return fetch(W.rest, {method:'POST', headers:{'Content-Type':'application/json'},
     body: JSON.stringify({sku: m.sku, size: size, post: post})})
    .then(function(r){ return r.json(); })
    .then(function(j){ if(!j.clientSecret) throw new Error(j.message || 'error');
      return j.clientSecret; });
  }}).then(function(c){ checkout = c; msg.textContent = ''; c.mount(mount); })
    .catch(function(err){ msg.textContent = (err && err.message) || W.off; });
 }

 load();
})();
JS;
}

/**
 * Print each sheet only on the page that holds its block.
 *
 * Gated on the block being in the page's content or its Elementor data,
 * through the one helper every other gate here uses, so a page converted
 * from a shortcode in the content to a block in the builder keeps its
 * styling either way.
 */
add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_singular() ) {
		return;
	}
	$post = get_post();
	if ( ! $post ) {
		return;
	}

	if ( ew_post_contains( $post, 'eastwood_shop' ) ) {
		wp_register_style( 'ew-shop', false, array(), EW_FWP_VERSION );
		wp_enqueue_style( 'ew-shop' );
		wp_add_inline_style( 'ew-shop', ew_shop_css() );
	}

	if ( ew_post_contains( $post, 'eastwood_shirt' ) ) {
		wp_register_style( 'ew-shirt', false, array(), EW_FWP_VERSION );
		wp_enqueue_style( 'ew-shirt' );
		wp_add_inline_style( 'ew-shirt', ew_shirt_css() );

		$ew_shirt_deps = array();
		if ( function_exists( 'ew_stripe_creds' ) && '' !== (string) ( ew_stripe_creds()['publishable'] ?? '' ) ) {
			wp_enqueue_script( 'stripe-js', 'https://js.stripe.com/v3/', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
			$ew_shirt_deps[] = 'stripe-js';
		}
		wp_register_script( 'ew-shirt', '', $ew_shirt_deps, EW_FWP_VERSION, true );
		wp_enqueue_script( 'ew-shirt' );
		wp_add_inline_script( 'ew-shirt', ew_shirt_js() );
	}
}, 22 );

/* --- Paying for a shirt ---------------------------------------------- */

/**
 * Card payment for one shirt, through Stripe's own form on the page.
 *
 * The browser sends only the SKU, the size and whether it is being
 * posted. The price, the postage and whether that size is actually in
 * stock are read again here from the same shop endpoint the page reads,
 * so a basket edited in the console cannot set its own price or buy
 * something that has sold out.
 *
 * Shirt money is Eastwood FC Ventures, so it uses ew_stripe_creds() --
 * the same keys as the event tickets.
 */
add_action( 'rest_api_init', function () {
	register_rest_route( 'eastwood/v1', '/shirt/checkout', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) {
			$sk = function_exists( 'ew_stripe_creds' ) ? (string) ( ew_stripe_creds()['secret'] ?? '' ) : '';
			if ( '' === $sk ) {
				return new WP_Error( 'ewsh_off', 'Card payment is not switched on.', array( 'status' => 503 ) );
			}

			$sku  = sanitize_text_field( (string) $req->get_param( 'sku' ) );
			$size = sanitize_text_field( (string) $req->get_param( 'size' ) );
			$post = (bool) $req->get_param( 'post' );
			if ( '' === $sku || '' === $size ) {
				return new WP_Error( 'ewsh_what', 'Choose a size first.', array( 'status' => 400 ) );
			}

			// The same endpoint the page reads, called inside this request
			// rather than over HTTP.
			$look = new WP_REST_Request( 'GET', '/eastwood/v1/shop/item' );
			$look->set_query_params( array( 'sku' => $sku ) );
			$found = rest_do_request( $look );
			if ( $found->is_error() ) {
				return new WP_Error( 'ewsh_item', 'No such shirt.', array( 'status' => 404 ) );
			}
			$item = (array) $found->get_data();

			$in_stock = false;
			foreach ( (array) ( $item['sizes'] ?? array() ) as $row ) {
				if ( (string) ( $row['size'] ?? '' ) === $size ) {
					$in_stock = ! empty( $row['in_stock'] );
					break;
				}
			}
			if ( ! $in_stock ) {
				return new WP_Error( 'ewsh_stock', 'That size has gone.', array( 'status' => 409 ) );
			}

			$pence = (int) round( (float) ( $item['price'] ?? 0 ) * 100 );
			$ship  = (int) round( (float) ( $item['postage'] ?? 0 ) * 100 );
			if ( $pence <= 0 ) {
				return new WP_Error( 'ewsh_price', 'This shirt has no price yet.', array( 'status' => 409 ) );
			}
			$post = $post && $ship > 0;

			$back = wp_get_referer();
			$back = $back ? strtok( $back, '?' ) : home_url( '/shop/' );

			$body = array(
				'ui_mode'                          => 'embedded',
				'mode'                             => 'payment',
				'return_url'                       => add_query_arg( 'ewsh_paid', '{CHECKOUT_SESSION_ID}', $back ),
				'phone_number_collection[enabled]' => 'true',
				'metadata[sku]'                    => $sku,
				'metadata[size]'                   => $size,
				'metadata[delivery]'               => $post ? 'post' : 'collect',
				'line_items[0][quantity]'                       => '1',
				'line_items[0][price_data][currency]'           => 'gbp',
				'line_items[0][price_data][unit_amount]'        => (string) $pence,
				'line_items[0][price_data][product_data][name]' => trim( (string) ( $item['name'] ?? 'Shirt' ) . ' · ' . $size ),
			);

			// Something posted needs somewhere to post it to, and postage goes
			// on as its own line so the receipt shows it.
			if ( $post ) {
				$body['shipping_address_collection[allowed_countries][0]'] = 'GB';
				$body['line_items[1][quantity]']                          = '1';
				$body['line_items[1][price_data][currency]']              = 'gbp';
				$body['line_items[1][price_data][unit_amount]']           = (string) $ship;
				$body['line_items[1][price_data][product_data][name]']    = 'Postage';
			}

			$res = wp_remote_post( 'https://api.stripe.com/v1/checkout/sessions', array(
				'timeout' => 20,
				'headers' => array( 'Authorization' => 'Bearer ' . $sk ),
				'body'    => $body,
			) );
			if ( is_wp_error( $res ) ) {
				return new WP_Error( 'ewsh_stripe', 'Could not reach Stripe.', array( 'status' => 503 ) );
			}
			$j = json_decode( (string) wp_remote_retrieve_body( $res ), true );
			if ( empty( $j['client_secret'] ) ) {
				$msg = (string) ( $j['error']['message'] ?? 'Stripe refused the order.' );
				return new WP_Error( 'ewsh_stripe', $msg, array( 'status' => 400 ) );
			}
			return array( 'clientSecret' => $j['client_secret'] );
		},
	) );
} );
