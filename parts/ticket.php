<?php
/**
 * One ticket, every funnel.
 *
 * A ticket is the same object wherever it turns up: the match on the left,
 * the perforated stub on the right, a row for each thing somebody can
 * choose, a running total, a button. What differs between a school claim, a
 * match ticket, a hospitality seat and an event night is only WHAT THE ROWS
 * ARE -- so the rows are all a funnel supplies, and the rest is built here.
 *
 * Before this, the same ticket was written out three times over, with three
 * class prefixes, three sets of counters and three ways of reaching Stripe.
 * A price corrected in one was still wrong in the other two, and the
 * quantity rules had already drifted apart. There is nothing clever in this
 * file; the whole point of it is that there is only one of it.
 *
 * A funnel calls ew_ticket() with its rows and its words, listens for the
 * 'ewt:go' event if it wants to do something of its own, and owns nothing
 * else.
 *
 * @package Eastwood
 */

defined( 'ABSPATH' ) || exit;

/* --- Money -------------------------------------------------------------- */

/**
 * Pence as the club writes money: no trailing pence on a round pound.
 *
 * Everything that shows a price goes through here, so £7 and £7.00 can
 * never both appear on the same page again.
 */
function ew_ticket_money( $pence ) {
	$pence = (int) $pence;
	return '£' . ( 0 === $pence % 100
		? number_format_i18n( $pence / 100 )
		: number_format_i18n( $pence / 100, 2 ) );
}

/* --- The photograph ----------------------------------------------------- */

/** The club's ticket photograph, in one place. */
const EW_TICKET_BG = 'claim/ticket-bg.jpg';

/**
 * The picture behind a ticket.
 *
 * Every ticket the club prints or shows has the same photograph behind it,
 * so it is the default here rather than something each funnel has to
 * remember to pass -- forgetting it is precisely how the fixture card
 * ended up flat black. A funnel may pass its own (a path inside assets, or
 * a full URL), or false for none at all.
 */
function ew_ticket_bg( $v = null ) {
	if ( false === $v ) {
		return '';
	}
	$v = trim( (string) ( null === $v ? '' : $v ) );
	if ( '' === $v ) {
		$v = EW_TICKET_BG;
	}
	if ( preg_match( '#^(https?:)?//#', $v ) || 0 === strpos( $v, '/' ) ) {
		return $v;
	}
	return function_exists( 'ew_hb_asset' ) ? ew_hb_asset( $v ) : '';
}

/* --- The rows a funnel hands over --------------------------------------- */

/**
 * Settle one row into the shape the ticket draws.
 *
 * A funnel may pass as little as a label and a price. Everything else has
 * a sensible answer, and the two summary words are worked out from the
 * label when they are not given -- which is right for "2 adults" and wrong
 * for "2 Pitchside Bar & Lounges", so a room passes its own.
 */
function ew_ticket_row( $row ) {
	$row   = (array) $row;
	$label = (string) ( $row['label'] ?? '' );
	$one   = (string) ( $row['one'] ?? '' );

	return array(
		'key'   => (string) ( $row['key'] ?? sanitize_key( $label ) ),
		'label' => $label,
		'note'  => (string) ( $row['note'] ?? '' ),
		'pence' => max( 0, (int) ( $row['pence'] ?? 0 ) ),
		'n'     => max( 0, (int) ( $row['n'] ?? 0 ) ),
		'max'   => max( 1, (int) ( $row['max'] ?? 20 ) ),
		// What the running summary calls one of these, and more than one.
		'one'   => '' !== $one ? $one : strtolower( $label ),
		'many'  => (string) ( $row['many'] ?? ( '' !== $one ? $one : strtolower( $label ) . 's' ) ),
	);
}

/* --- The ticket --------------------------------------------------------- */

/**
 * Build a ticket.
 *
 * The face is the match; the stub is whatever is being sold. When 'say' is
 * set the stub carries that sentence instead of counters -- not on sale
 * yet, sold out, nothing to sell -- because a ticket with counters that
 * cannot add up to anything is worse than a ticket that says why.
 *
 * @param array $a Face, words, rows and the hand-off. See ew_ticket_row().
 */
function ew_ticket( $a ) {
	$a    = (array) $a;
	$rows = array_map( 'ew_ticket_row', (array) ( $a['rows'] ?? array() ) );
	$mode = (string) ( $a['mode'] ?? 'link' );
	$mode = in_array( $mode, array( 'link', 'pay', 'event' ), true ) ? $mode : 'link';

	$class = trim( 'ewt ' . (string) ( $a['class'] ?? '' ) );
	$bg    = ew_ticket_bg( array_key_exists( 'bg', $a ) ? $a['bg'] : null );
	$stub  = (string) ( $a['stub_width'] ?? '' );

	$style = array();
	if ( '' !== $bg ) {
		$style[] = 'background-image:url(' . esc_url( $bg ) . ')';
	}
	if ( '' !== $stub ) {
		$style[] = '--ewt-stub:' . preg_replace( '/[^0-9a-z%.]/i', '', $stub );
	}

	// Everything the driver needs, named once here rather than three times
	// in three funnels. Anything absent simply never gets read.
	$data = array(
		'data-ewt'      => '',
		'data-ewt-mode' => $mode,
		'data-id'       => (string) ( $a['id'] ?? '' ),
		'data-none'     => (string) ( $a['none'] ?? 'Nobody yet' ),
		'data-need'     => (string) ( $a['need'] ?? 'Choose at least one.' ),
	);
	if ( ! empty( $a['need_paid'] ) ) {
		$data['data-need-paid'] = '1';
		$data['data-paid-msg']  = (string) ( $a['paid_msg'] ?? 'A paying ticket is needed too.' );
	}
	foreach ( array( 'url', 'fx', 'rest', 'pk', 'page', 'loading', 'off' ) as $k ) {
		if ( isset( $a[ $k ] ) && '' !== (string) $a[ $k ] ) {
			$data[ 'data-' . $k ] = (string) $a[ $k ];
		}
	}

	$at = '';
	foreach ( $data as $k => $v ) {
		$at .= ' ' . $k . ( '' === $v ? '' : '="' . esc_attr( $v ) . '"' );
	}

	$h  = '<div class="' . esc_attr( $class ) . '"' . $at
		. ( $style ? ' style="' . esc_attr( implode( ';', $style ) ) . '"' : '' ) . '>';
	$h .= ew_ticket_face( $a );
	$h .= '<div class="ewt-stub">';
	$h .= ew_ticket_head( $a );

	// Not selling: say so, hold the stub's height, and leave whatever the
	// funnel wants underneath (a way back, a link to the other side).
	if ( '' !== (string) ( $a['say'] ?? '' ) ) {
		$h .= '<p class="ewt-say">' . esc_html( (string) $a['say'] ) . '</p>';
		foreach ( array( 'sub' => 'ewt-sub', 'gate' => 'ewt-sub ewt-gate' ) as $k => $cls ) {
			if ( '' !== (string) ( $a[ $k ] ?? '' ) ) {
				$h .= '<p class="' . $cls . '">' . esc_html( (string) $a[ $k ] ) . '</p>';
			}
		}
		$h .= '<div class="ewt-fill"></div>';
		$h .= (string) ( $a['extras'] ?? '' );
		return $h . '</div></div>';
	}

	if ( '' !== (string) ( $a['lab'] ?? '' ) ) {
		$h .= '<p class="ewt-lab">' . esc_html( (string) $a['lab'] ) . '</p>';
	}

	$h .= '<div class="ewt-rows">';
	foreach ( $rows as $r ) {
		$h .= ew_ticket_row_html( $r, $a );
	}
	$h .= '</div>';

	$h .= '<p class="ewt-sum"><span data-ewt-who>' . esc_html( $data['data-none'] ) . '</span>'
		. '<span data-ewt-tot>' . esc_html( ew_ticket_money( 0 ) ) . '</span></p>';
	$h .= '<button type="button" class="ewt-go" data-ewt-go disabled>'
		. esc_html( (string) ( $a['go'] ?? 'Continue' ) ) . '</button>';
	$h .= '<p class="ewt-msg" data-ewt-msg></p>';
	$h .= (string) ( $a['extras'] ?? '' );
	$h .= '</div>';

	// Where an embedded checkout lands. Empty and hidden until it is asked
	// for, so a ticket that never gets bought costs nothing.
	if ( 'pay' === $mode ) {
		$h .= '</div><div class="ewt-pay" data-ewt-pay hidden>'
			. '<div class="ewt-mount" data-ewt-mount></div></div>';
		return $h;
	}

	return $h . '</div>';
}

/** The left-hand face: who is playing, when, where. */
function ew_ticket_face( $a ) {
	$h = '<div class="ewt-face">';

	if ( '' !== (string) ( $a['title'] ?? '' ) ) {
		$h .= '<p class="ewt-title">' . esc_html( (string) $a['title'] ) . '</p>';
	} else {
		$tie = (array) ( $a['tie'] ?? array() );
		$h  .= '<p class="ewt-tie"><span>' . esc_html( (string) ( $tie['home'] ?? '' ) ) . '</span>'
			. '<em>' . esc_html( (string) ( $tie['v'] ?? 'v' ) ) . '</em>'
			. '<span>' . esc_html( (string) ( $tie['away'] ?? '' ) ) . '</span></p>';
	}

	if ( '' !== (string) ( $a['when'] ?? '' ) || '' !== (string) ( $a['time'] ?? '' ) ) {
		$h .= '<p class="ewt-when"><b>' . esc_html( (string) ( $a['when'] ?? '' ) ) . '</b>'
			. '<i>' . esc_html( (string) ( $a['time'] ?? '' ) ) . '</i></p>';
	}
	foreach ( array( 'at' => 'ewt-at', 'note' => 'ewt-note' ) as $k => $cls ) {
		if ( '' !== (string) ( $a[ $k ] ?? '' ) ) {
			$h .= '<p class="' . $cls . '">' . esc_html( (string) $a[ $k ] ) . '</p>';
		}
	}

	return $h . '</div>';
}

/** The stub's heading, and the step marker beside it. */
function ew_ticket_head( $a ) {
	if ( '' === (string) ( $a['head'] ?? '' ) ) {
		return '';
	}
	$step = (string) ( $a['step'] ?? '' );
	return '<p class="ewt-head"><span>' . esc_html( (string) $a['head'] ) . '</span>'
		. ( '' !== $step ? '<small>' . esc_html( $step ) . '</small>' : '' ) . '</p>';
}

/** One row: what it is, what it costs, how many. */
function ew_ticket_row_html( $r, $a ) {
	$free = (string) ( $a['free'] ?? 'Free' );
	$less = (string) ( $a['less'] ?? 'One fewer' );
	$more = (string) ( $a['more'] ?? 'One more' );

	$h  = '<div class="ewt-row" data-t="' . esc_attr( $r['key'] ) . '" data-p="' . $r['pence'] . '"'
		. ' data-max="' . $r['max'] . '" data-w="' . esc_attr( $r['one'] ) . '"'
		. ' data-wn="' . esc_attr( $r['many'] ) . '">';
	$h .= '<span class="ewt-who"><b>' . esc_html( $r['label'] ) . '</b>'
		. ( '' !== $r['note'] ? '<small>' . esc_html( $r['note'] ) . '</small>' : '' ) . '</span>';
	$h .= '<span class="ewt-pick"><span class="ewt-p">'
		. esc_html( $r['pence'] ? ew_ticket_money( $r['pence'] ) : $free ) . '</span>'
		. '<span class="ewt-pm">'
		. '<button type="button" data-d="-1" aria-label="' . esc_attr( $less ) . '"'
		. ( $r['n'] ? '' : ' disabled' ) . '>&minus;</button>'
		. '<output>' . $r['n'] . '</output>'
		. '<button type="button" data-d="1" aria-label="' . esc_attr( $more ) . '">+</button>'
		. '</span></span>';

	return $h . '</div>';
}

/* --- How it looks ------------------------------------------------------- */

/**
 * The ticket's own stylesheet.
 *
 * The stub's width is a custom property so a funnel can widen it without a
 * second copy of any of this.
 */
function ew_ticket_css() {
	return '
.ewt{--ewt-stub:380px;display:grid;grid-template-columns:minmax(0,1fr) var(--ewt-stub);color:#fff;
 border:2px solid #fff;border-radius:10px;overflow:hidden;text-align:left;
 background:#101010 left center/cover no-repeat}
.ewt-face{padding:30px 32px;display:flex;flex-direction:column;justify-content:center;gap:18px;
 background:rgba(0,0,0,.35)}
.ewt-tie,.ewt-title{margin:0;display:flex;flex-direction:column;gap:4px;font-family:Anton,Impact,sans-serif;
 font-weight:400;font-size:46px;line-height:.98;text-transform:uppercase;letter-spacing:.5px}
.ewt-tie em{font-family:Georgia,serif;font-style:italic;font-size:26px;color:#e01b1b;line-height:1}
.ewt-when{margin:0;display:flex;align-items:baseline;gap:14px;flex-wrap:wrap}
.ewt-when b{font-size:16px;font-weight:700;letter-spacing:.04em;text-transform:uppercase}
.ewt-when i{font-family:Anton,Impact,sans-serif;font-style:normal;font-size:30px;color:#e01b1b}
.ewt-at,.ewt-note{margin:0;font-size:13.5px;color:rgba(255,255,255,.6)}
.ewt-stub{display:flex;flex-direction:column;border-left:3px dashed rgba(255,255,255,.85);
 padding:24px 26px;background:rgba(0,0,0,.8)}
.ewt-head{margin:0 0 16px;display:flex;align-items:baseline;justify-content:space-between;gap:12px;
 border-bottom:1px solid rgba(255,255,255,.18);padding-bottom:10px}
.ewt-head span{font-family:Anton,Impact,sans-serif;font-size:24px;text-transform:uppercase;letter-spacing:.5px}
.ewt-head small{font-size:11.5px;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.5)}
.ewt-lab{margin:0 0 10px;font-size:12px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;
 color:rgba(255,255,255,.55)}
.ewt-row{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:11px 0;
 border-top:1px solid rgba(255,255,255,.1)}
.ewt-row:first-child{border-top:0}
.ewt-who b{display:block;font-size:15px;font-weight:700}
.ewt-who small{display:block;font-size:12px;color:rgba(255,255,255,.5);margin-top:1px}
.ewt-pick{display:flex;align-items:center;gap:14px;flex:none}
.ewt-p{font-size:15px;font-weight:700;min-width:42px;text-align:right}
.ewt-pm{display:inline-flex;align-items:center;gap:2px;border:1px solid rgba(255,255,255,.3);border-radius:5px}
.ewt-pm button{appearance:none;background:none;border:0;color:#fff;font:inherit;font-size:17px;
 line-height:1;width:32px;height:32px;cursor:pointer}
.ewt-pm button[disabled]{color:rgba(255,255,255,.28);cursor:default}
.ewt-pm output{min-width:24px;text-align:center;font-size:15px;font-weight:700}
.ewt-sum{display:flex;align-items:baseline;justify-content:space-between;gap:12px;margin:16px 0 0;
 border-top:1px solid rgba(255,255,255,.18);padding-top:12px;font-size:14px}
.ewt-sum span:last-child{font-family:Anton,Impact,sans-serif;font-size:26px}
.ewt-go{display:block;width:100%;margin:14px 0 0;appearance:none;border:0;border-radius:6px;
 background:#CC0000;color:#fff;font:inherit;font-size:15px;font-weight:700;letter-spacing:.05em;
 text-transform:uppercase;padding:15px;cursor:pointer}
.ewt-go[disabled]{background:rgba(255,255,255,.14);color:rgba(255,255,255,.45);cursor:default}
.ewt-msg{margin:10px 0 0;font-size:13.5px;color:#ff9a9a;min-height:1px}
.ewt-msg:empty{margin:0}
.ewt-say{margin:18px 0 10px;font-family:Anton,Impact,sans-serif;font-weight:400;font-size:34px;
 line-height:1.02;text-transform:uppercase;letter-spacing:.5px}
.ewt-sub{margin:0 0 10px;font-size:14.5px;line-height:1.6;color:rgba(255,255,255,.72)}
.ewt-gate{color:rgba(255,255,255,.5);font-size:13.5px}
.ewt-fill{flex:1 1 auto;min-height:10px}
.ewt-swap{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:14px 0 0;
 border-top:1px solid rgba(255,255,255,.18);padding-top:12px;font-size:13.5px;color:rgba(255,255,255,.65)}
.ewt-swap a{color:#fff;font-weight:700}
.ewt-back{margin:12px 0 0;text-align:center}
.ewt-back button,.ewt-back a{appearance:none;background:none;border:0;padding:0;font:inherit;
 font-size:13px;color:rgba(255,255,255,.6);text-decoration:underline;cursor:pointer}
.ewt-pay{margin:22px 0 0}
@media (max-width:860px){
.ewt{grid-template-columns:minmax(0,1fr)}
.ewt-stub{border-left:0;border-top:3px dashed rgba(255,255,255,.85)}
.ewt-tie,.ewt-title{font-size:34px}
}';
}

/* --- How it behaves ----------------------------------------------------- */

/**
 * The counters, the running total and the hand-off.
 *
 * One listener on the document, so a ticket that appears after the page
 * has loaded -- turned out of a fixture card, say -- works without being
 * wired up again. The hand-off has two shapes and no others: carry the
 * basket to another page, or open an embedded checkout here. A funnel that
 * wants to do something else listens for 'ewt:go' and calls
 * preventDefault() on it.
 */
function ew_ticket_js() {
	return <<<'JS'
(function(){
 if(window.ewTicket){ return; }

 function money(p){
  return '£' + (p % 100 === 0 ? (p/100).toLocaleString() : (p/100).toFixed(2));
 }

 function basket(tk){
  var n = 0, paid = 0, pence = 0, bits = [], items = {};
  tk.querySelectorAll('.ewt-row').forEach(function(r){
   var q = parseInt(r.querySelector('output').textContent, 10) || 0;
   if(!q){ return; }
   var p = parseInt(r.dataset.p, 10) || 0;
   n += q;
   pence += q * p;
   if(p > 0){ paid += q; }
   items[r.dataset.t] = q;
   bits.push(q + ' ' + (q > 1 ? (r.dataset.wn || r.dataset.w) : r.dataset.w));
  });
  return {n:n, paid:paid, pence:pence, bits:bits, items:items};
 }

 function say(tk, words){
  var m = tk.querySelector('[data-ewt-msg]');
  if(m){ m.textContent = words || ''; }
 }

 function render(tk){
  var b = basket(tk);
  var who = tk.querySelector('[data-ewt-who]');
  var tot = tk.querySelector('[data-ewt-tot]');
  var go  = tk.querySelector('[data-ewt-go]');
  if(who){ who.textContent = b.bits.length ? b.bits.join(' · ') : (tk.dataset.none || ''); }
  if(tot){ tot.textContent = money(b.pence); }
  if(go){ go.disabled = b.n < 1; }
  // Changing the basket invalidates anything already mounted, so it goes
  // rather than quietly charging for what was in it a moment ago.
  drop(tk);
  return b;
 }

 function drop(tk){
  if(!tk._ewtPay){ return; }
  try{ tk._ewtPay.destroy(); }catch(e){}
  tk._ewtPay = null;
  var pay = tk.querySelector('[data-ewt-pay]') || (tk.nextElementSibling && tk.nextElementSibling.matches && tk.nextElementSibling.matches('[data-ewt-pay]') ? tk.nextElementSibling : null);
  if(pay){ pay.hidden = true; var m = pay.querySelector('[data-ewt-mount]'); if(m){ m.innerHTML = ''; } }
 }

 function payPane(tk){
  var pay = tk.querySelector('[data-ewt-pay]');
  if(pay){ return pay; }
  var sib = tk.nextElementSibling;
  return (sib && sib.matches && sib.matches('[data-ewt-pay]')) ? sib : null;
 }

 function link(tk, b){
  var url = tk.dataset.url || '';
  if(!url){ return; }
  var q = [];
  Object.keys(b.items).forEach(function(k){ q.push(k + ':' + b.items[k]); });
  // The numbers go on the address rather than into a form, so the page
  // they land on can be sent to somebody else as it stands.
  window.location = url
   + (url.indexOf('?') < 0 ? '?' : '&')
   + (tk.dataset.fx ? 'fx=' + encodeURIComponent(tk.dataset.fx) + '&' : '')
   + 'q=' + encodeURIComponent(q.join(','));
 }

 function pay(tk, b){
  var pane = payPane(tk);
  var mount = pane && pane.querySelector('[data-ewt-mount]');
  if(!pane || !mount){ return; }
  if(!tk.dataset.pk || !window.Stripe){ say(tk, tk.dataset.off); return; }
  if(tk._ewtPay){ return; }

  say(tk, tk.dataset.loading);
  pane.hidden = false;
  var stripe = window.Stripe(tk.dataset.pk);
  var make = stripe.createEmbeddedCheckoutPage || stripe.initEmbeddedCheckout;
  make.call(stripe, {fetchClientSecret: function(){
   return fetch(tk.dataset.rest, {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({
     fx: +(tk.dataset.fx || 0),
     page: +(tk.dataset.page || 0),
     id: tk.dataset.id || '',
     items: b.items
    })
   }).then(function(r){ return r.json(); }).then(function(j){
    if(!j.clientSecret){ throw new Error(j.message || 'error'); }
    return j.clientSecret;
   });
  }}).then(function(c){
   tk._ewtPay = c;
   say(tk, '');
   c.mount(mount);
   pane.scrollIntoView({behavior:'smooth', block:'start'});
  }).catch(function(err){
   pane.hidden = true;
   say(tk, (err && err.message) || tk.dataset.off);
  });
 }

 document.addEventListener('click', function(e){
  var pm = e.target.closest('.ewt-pm button');
  if(pm){
   var r = pm.closest('.ewt-row');
   var tk = pm.closest('[data-ewt]');
   var out = r.querySelector('output');
   var max = parseInt(r.dataset.max, 10) || 20;
   var q = Math.max(0, Math.min(max, (parseInt(out.textContent, 10) || 0) + (parseInt(pm.dataset.d, 10) || 0)));
   out.textContent = q;
   r.querySelector('[data-d="-1"]').disabled = q < 1;
   r.querySelector('[data-d="1"]').disabled = q >= max;
   say(tk, '');
   render(tk);
   return;
  }

  var go = e.target.closest('[data-ewt-go]');
  if(!go || go.disabled){ return; }
  var tk = go.closest('[data-ewt]');
  if(!tk){ return; }

  var b = basket(tk);
  if(!b.n){ say(tk, tk.dataset.need); return; }
  if(tk.dataset.needPaid === '1' && !b.paid){ say(tk, tk.dataset.paidMsg); return; }

  // A funnel that wants the basket for itself takes it here.
  var ev = new CustomEvent('ewt:go', {bubbles:true, cancelable:true, detail:b});
  if(!tk.dispatchEvent(ev)){ return; }

  if(tk.dataset.ewtMode === 'pay'){ pay(tk, b); }
  else if(tk.dataset.ewtMode === 'link'){ link(tk, b); }
 });

 // Tickets already on the page start with their sums right, in case a
 // funnel pre-filled the counters from an address.
 function start(){
  document.querySelectorAll('[data-ewt]').forEach(render);
 }
 if(document.readyState === 'loading'){
  document.addEventListener('DOMContentLoaded', start);
 } else {
  start();
 }

 window.ewTicket = {basket: basket, render: render, money: money};
})();
JS;
}
