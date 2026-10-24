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

	// One design, two sizes. 'full' is the claim ticket exactly as it is
	// drawn today; 'compact' is the same ticket -- same white counters,
	// same round buttons, same red month, same everything -- at the size a
	// fixture row can hold. Nothing but the measurements differs.
	$size  = 'compact' === (string) ( $a['size'] ?? 'full' ) ? ' is-compact' : '';
	$class = trim( 'ewt' . $size . ' ' . (string) ( $a['class'] ?? '' ) );
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
	$h .= ew_ticket_date( $a );
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

/**
 * The left-hand face: who is playing, their crests, and when.
 *
 * Laid out as the claim ticket lays it out -- the names large and stacked,
 * the crests in a column beside them, and the day, date and kick-off along
 * the bottom with the time set big on the right.
 */
function ew_ticket_face( $a ) {
	$h = '<div class="ewt-face">';

	$h .= '<div class="ewt-teams"><div class="ewt-names">';
	if ( '' !== (string) ( $a['title'] ?? '' ) ) {
		$h .= '<p class="ewt-team">' . esc_html( (string) $a['title'] ) . '</p>';
	} else {
		$tie = (array) ( $a['tie'] ?? array() );
		$h  .= '<p class="ewt-team">' . esc_html( (string) ( $tie['home'] ?? '' ) ) . '</p>';
		$h  .= '<p class="ewt-v">' . esc_html( (string) ( $tie['v'] ?? 'v' ) ) . '</p>';
		$h  .= '<p class="ewt-team">' . esc_html( (string) ( $tie['away'] ?? '' ) ) . '</p>';
	}
	$h .= '</div>';

	$crests = (array) ( $a['crests'] ?? array() );
	$shown  = '';
	foreach ( array( 'home', 'away' ) as $side ) {
		$src = ew_ticket_bg( isset( $crests[ $side ] ) && '' !== $crests[ $side ] ? $crests[ $side ] : false );
		if ( '' !== $src ) {
			$tie    = (array) ( $a['tie'] ?? array() );
			$shown .= '<img src="' . esc_url( $src ) . '" alt="' . esc_attr( (string) ( $tie[ $side ] ?? '' ) ) . '"'
				. ' width="128" height="128" loading="lazy">';
		}
	}
	if ( '' !== $shown ) {
		$h .= '<div class="ewt-crests">' . $shown . '</div>';
	}
	$h .= '</div>';

	$day  = (string) ( $a['day'] ?? '' );
	$date = (string) ( $a['date'] ?? '' );
	$time = (string) ( $a['time'] ?? '' );
	if ( '' !== $day || '' !== $date || '' !== $time ) {
		$h .= '<div class="ewt-when"><div>';
		if ( '' !== $day ) {
			$h .= '<p class="ewt-day">' . esc_html( $day ) . '</p>';
		}
		if ( '' !== $date ) {
			$h .= '<p class="ewt-date">' . esc_html( $date ) . '</p>';
		}
		$h .= '</div>';
		if ( '' !== $time ) {
			$h .= '<p class="ewt-time">' . esc_html( $time ) . '</p>';
		}
		$h .= '</div>';
	}

	// The venue and what is left of a release are not on a claim ticket,
	// because a claim has neither. They sit quietly under the date where a
	// funnel does have them to say.
	foreach ( array( 'at' => 'ewt-at', 'note' => 'ewt-note' ) as $k => $cls ) {
		if ( '' !== (string) ( $a[ $k ] ?? '' ) ) {
			$h .= '<p class="' . $cls . '">' . esc_html( (string) $a[ $k ] ) . '</p>';
		}
	}

	return $h . '</div>';
}

/** The stub's own date: the day as a number, then the month in red. */
function ew_ticket_date( $a ) {
	$big   = (string) ( $a['big'] ?? '' );
	$month = (string) ( $a['month'] ?? '' );
	if ( '' === $big && '' === $month ) {
		return '';
	}
	return ( '' !== $big ? '<p class="ewt-big">' . esc_html( $big ) . '</p>' : '' )
		. ( '' !== $month ? '<p class="ewt-month">' . esc_html( $month ) . '</p>' : '' );
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
.ewt{--ewt-stub:470px;--ewt-min:660px;font-family:"Instrument Sans",system-ui,sans-serif;
 display:grid;grid-template-columns:minmax(0,1fr) var(--ewt-stub);min-height:var(--ewt-min);
 border-radius:14px;overflow:hidden;color:#fff;background:#000 left center/cover no-repeat;
 text-align:left}
.ewt *{box-sizing:border-box}
.ewt [hidden]{display:none!important}

/* The match */
.ewt-face{background:rgba(0,0,0,.35);padding:56px 52px 44px;display:flex;flex-direction:column;
 justify-content:space-between;gap:32px}
.ewt-teams{display:flex;gap:32px;align-items:center}
.ewt-names{flex:1 1 auto;min-width:0}
.ewt .ewt-team{margin:0;font-family:Anton,Impact,sans-serif;font-size:96px;line-height:.95;
 text-transform:uppercase;color:#fff;overflow-wrap:break-word}
.ewt .ewt-v{margin:2px 0;font-family:Georgia,serif;font-style:italic;font-size:52px;line-height:1;color:#e01b1b}
.ewt-crests{flex:0 0 auto;display:flex;flex-direction:column;gap:30px}
.ewt-crests img{width:128px;height:128px;object-fit:contain}
.ewt-when{display:flex;justify-content:space-between;align-items:flex-end;gap:16px}
.ewt .ewt-day{margin:0 0 4px;font-size:18px;font-weight:700;letter-spacing:.4em;text-transform:uppercase;color:#e01b1b}
.ewt .ewt-date{margin:0;font-size:34px;text-transform:uppercase;color:#fff}
.ewt .ewt-time{margin:0;font-family:Anton,Impact,sans-serif;font-size:72px;line-height:1;color:#fff}
.ewt .ewt-at,.ewt .ewt-note{margin:0;font-size:15px;line-height:1.5;color:#e6e6e6}

/* The stub */
.ewt-stub{background:rgba(0,0,0,.8);border-left:3px dashed rgba(255,255,255,.85);padding:40px 44px;
 display:flex;flex-direction:column}
.ewt .ewt-big{margin:0;font-family:Anton,Impact,sans-serif;font-size:72px;line-height:1;text-align:center;color:#fff}
.ewt .ewt-month{margin:0 0 18px;font-family:Anton,Impact,sans-serif;font-size:26px;color:#e01b1b;
 text-align:center;text-transform:uppercase}
.ewt-head{display:flex;justify-content:space-between;align-items:baseline;
 border-top:1px solid rgba(255,255,255,.25);padding-top:14px;margin-bottom:16px}
.ewt-head span{font-family:Anton,Impact,sans-serif;font-size:28px;text-transform:uppercase}
.ewt-head small{font-size:13px;font-weight:700;color:#cfcfcf}
.ewt .ewt-lab{display:block;margin:0 0 8px;font-size:14px;font-weight:700;color:#fff}

/* What is being bought, on white, as a claim ticket counts its seats */
.ewt-rows{background:#fff;color:#101010;border-radius:8px;margin-bottom:22px}
.ewt-row{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:10px 16px}
.ewt-row+.ewt-row{border-top:1px solid #ececec}
.ewt-who{min-width:0}
.ewt-who b{display:block;font-weight:600}
.ewt-who small{display:block;font-size:13px;color:#555}
.ewt-pick{display:flex;align-items:center;gap:16px;flex:none}
.ewt-p{font-weight:700;font-size:15px;min-width:42px;text-align:right;color:#101010}
.ewt-pm{display:flex;align-items:center;gap:14px}
.ewt-pm button{width:40px;height:40px;border-radius:999px;border:1.5px solid #101010;background:#fff;
 color:#101010;font:inherit;font-size:20px;cursor:pointer;padding:0}
.ewt-pm button:disabled{border-color:#cfcfcf;color:#9a9a9a;cursor:default}
.ewt-pm output{font-family:Anton,Impact,sans-serif;font-size:22px;width:22px;text-align:center}

/* The running total, and the way on */
.ewt-sum{display:flex;justify-content:space-between;align-items:baseline;gap:12px;font-size:14px;
 color:#e6e6e6;border-top:1px solid rgba(255,255,255,.25);border-bottom:1px solid rgba(255,255,255,.25);
 padding:12px 0;margin:0 0 18px}
.ewt-go{display:block;width:100%;background:#CC0000;color:#fff;border:0;border-radius:6px;padding:15px;
 text-align:center;font:inherit;font-size:15px;font-weight:700;letter-spacing:.4px;cursor:pointer}
.ewt-go:hover{background:#b30000}
.ewt-go[disabled]{opacity:.6;cursor:default}
.ewt .ewt-msg{margin:12px 0 0;font-size:14px;font-weight:600;color:#ff8a8a}
.ewt .ewt-msg:empty{display:none}

/* A game with nothing to sell says why, in the stub it would have used */
.ewt .ewt-say{margin:0 0 12px;font-family:Anton,Impact,sans-serif;font-weight:400;font-size:44px;
 line-height:1.02;text-transform:uppercase;color:#fff}
.ewt .ewt-sub{margin:0 0 10px;font-size:15px;line-height:1.5;color:#e6e6e6}
.ewt .ewt-gate{color:#bdbdbd}
.ewt-fill{flex:1 1 auto;min-height:16px}
.ewt-swap{display:flex;justify-content:space-between;align-items:baseline;gap:12px;margin:16px 0 0;
 border-top:1px solid rgba(255,255,255,.25);padding-top:12px;font-size:14px;color:#e6e6e6}
.ewt-swap a{color:#fff;font-weight:700}
.ewt-back{margin:16px 0 0;text-align:center}
.ewt-back button,.ewt-back a{background:none;border:0;padding:0;color:#fff;font:inherit;font-size:13px;
 text-decoration:underline;cursor:pointer}
.ewt-pay{margin:28px 0 0}

/* The same ticket at the size a fixture row can hold. Only measurements. */
.ewt.is-compact{--ewt-stub:380px;--ewt-min:0;border-radius:10px;border:2px solid #fff}
/* The claim ticket has no border, and needs none -- it sits on a white
   page. The compact one sits on the black hospitality section, where
   without an edge it merges into the page. */
.ewt.is-compact .ewt-face{padding:30px 32px;gap:22px;justify-content:center}
.ewt.is-compact .ewt-teams{gap:20px}
.ewt.is-compact .ewt-team{font-size:46px}
.ewt.is-compact .ewt-v{font-size:26px}
.ewt.is-compact .ewt-crests{gap:14px}
.ewt.is-compact .ewt-crests img{width:64px;height:64px}
.ewt.is-compact .ewt-day{font-size:13px;letter-spacing:.3em}
.ewt.is-compact .ewt-date{font-size:20px}
.ewt.is-compact .ewt-time{font-size:44px}
.ewt.is-compact .ewt-at,.ewt.is-compact .ewt-note{font-size:13.5px}
.ewt.is-compact .ewt-stub{padding:24px 26px}
.ewt.is-compact .ewt-big{font-size:44px}
.ewt.is-compact .ewt-month{font-size:18px;margin-bottom:12px}
.ewt.is-compact .ewt-head{padding-top:10px;margin-bottom:12px}
.ewt.is-compact .ewt-head span{font-size:22px}
.ewt.is-compact .ewt-rows{margin-bottom:16px}
.ewt.is-compact .ewt-row{padding:8px 13px}
.ewt.is-compact .ewt-pm{gap:10px}
.ewt.is-compact .ewt-pm button{width:32px;height:32px;font-size:17px}
.ewt.is-compact .ewt-pm output{font-size:18px;width:18px}
.ewt.is-compact .ewt-say{font-size:34px}

@media(max-width:1100px){.ewt .ewt-team{font-size:68px}.ewt-crests img{width:96px;height:96px}}
@media(max-width:900px){
 .ewt{grid-template-columns:minmax(0,1fr);min-height:0}
 .ewt-face{padding:28px 24px;gap:22px}
 .ewt .ewt-team{font-size:44px}.ewt .ewt-v{font-size:30px}
 .ewt-crests{gap:14px}.ewt-crests img{width:64px;height:64px}
 .ewt .ewt-date{font-size:22px}.ewt .ewt-time{font-size:44px}
 .ewt-stub{border-left:0;border-top:3px dashed rgba(255,255,255,.85);padding:28px 22px}
 .ewt .ewt-big{font-size:52px}
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
