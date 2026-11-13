<?php
/**
 * eastwoodfc.com/myday: "Your day at Eastwood", for someone with a ticket.
 *
 * [eastwood_day]
 * time|title|line|marker
 * [/eastwood_day]
 *
 * [eastwood_day_upgrades]
 * title|line|price|link
 * [/eastwood_day_upgrades]
 *
 * The same supporter account and Supabase session as /my-tickets (one
 * sign-in works on both), and the same tickets, read from POST /app/mine.
 * Signed in, it shows:
 *  - a header card: their MyEastwood ID, the match their next ticket is for,
 *    its date and kick-off from the fixtures feed, and their name and party;
 *  - that ticket's QR and code, drawn from the same qr_rows as My Tickets;
 *  - the day, one line per row of the block's body. A row whose time or text
 *    is empty is not shown, so nothing half-written reaches the page. In a
 *    row, {kickoff} is the match's kick-off and {match} its name. The marker
 *    is hollow, solid, or offer (solid, with the offer tag);
 *  - "Make it a better day", from the upgrades block's body, which sits in
 *    its slot on the day page and is not shown at all while it is empty;
 *  - the next home game from the fixtures feed, with an offer line that is
 *    not shown while it is empty.
 * Signed out, it is the My Tickets email and password box, no Apple or Google.
 *
 * Every word is a field on the block.
 *
 * @package eastwood-fwp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ew_day_defaults() {
	return array(
		'id_label'      => 'MyEastwood · ID',
		'title'         => 'Your day at Eastwood',
		'kickoff_label' => 'Kick-off',
		'versus'        => 'v',
		'ticket_label'  => 'Your ticket',
		'show_gate'     => 'Show this at the turnstile',
		'entrance_label' => 'Your entrance',
		'moved_from'    => 'moved from',
		'used'          => 'Used',
		'day_title'     => 'Your day',
		'offer_tag'     => 'Offer',
		'next_label'    => 'Your next game',
		'next_offer'    => '',
		'next_link'     => 'Get tickets',
		'next_url'      => '/tickets/',
		'no_ticket'     => 'You have no ticket for a home game yet.',
		'buy'           => 'Get tickets for the next home game',
		'buy_url'       => '/tickets/',
		'tickets_link'  => 'See all my tickets',
		'tickets_url'   => '/my-tickets/',
		'signed_as'     => 'Signed in as',
		'sign_out'      => 'Sign out',
		'signin_title'  => 'Sign in to see your day',
		'signin_line'   => 'The same account as My Tickets and the Eastwood FC app.',
		'email_label'   => 'Email',
		'password_label' => 'Password',
		'signin_button' => 'Sign in',
		'new_here'      => 'New here?',
		'create_link'   => 'Create an account',
		'create_url'    => '/my-tickets/?create',
		'forgot_link'   => 'Forgotten your password?',
		'forgot_url'    => '/my-tickets/?reset',
		'booked'        => 'Booked on eastwoodfc.com?',
		'ref_link'      => 'Use your booking reference',
		'ref_url'       => '/my-tickets/?ref',
	);
}

/** The day, as it is set up before anybody fills it in: only kick-off has a time. */
function ew_day_default_lines() {
	return implode( "\n", array(
		'|Gates open|Coronation Park, Eastwood.|hollow',
		'By 1:30|Here early||offer',
		'|Food and drink|The stands and the clubhouse bar are open.|hollow',
		'{kickoff}|Kick-off|{match}|solid',
		'|Half-time||hollow',
		'|Full time|Vote for the Man of the Match.|hollow',
	) );
}

/** The block's body as lines, blank ones dropped. */
function ew_day_lines( $content ) {
	if ( function_exists( 'ew_hb_lines' ) ) {
		return ew_hb_lines( $content );
	}
	$out = array();
	foreach ( preg_split( '/\r\n|\r|\n/', wp_strip_all_tags( (string) $content ) ) as $line ) {
		$line = trim( html_entity_decode( $line, ENT_QUOTES, 'UTF-8' ) );
		if ( '' !== $line ) {
			$out[] = $line;
		}
	}
	return $out;
}

/** One line split on pipes, padded to $n parts. */
function ew_day_bits( $line, $n ) {
	$bits = array_map( 'trim', explode( '|', (string) $line ) );
	return array_pad( array_slice( $bits, 0, $n ), $n, '' );
}

/** "15:00" as "3pm", "19:45" as "7.45pm". */
function ew_day_time( $hm ) {
	if ( ! preg_match( '/^(\d{1,2}):(\d{2})/', (string) $hm, $t ) ) {
		return '';
	}
	$h = (int) $t[1];
	$m = (int) $t[2];
	$x = $h >= 12 ? 'pm' : 'am';
	$h = $h % 12 ? $h % 12 : 12;
	return $h . ( $m ? '.' . sprintf( '%02d', $m ) : '' ) . $x;
}

/** Home games from today, from the fixtures feed with its corrections, oldest first. */
function ew_day_games( $versus ) {
	$games = array();
	if ( function_exists( 'ew_hts_home_to_come' ) ) {
		$list = ew_hts_home_to_come();
	} elseif ( function_exists( 'ew_mt_home_games' ) ) {
		$list = ew_mt_home_games();
	} else {
		$list = array();
	}
	foreach ( $list as $m ) {
		$home = (array) ( $m['home-team'] ?? array() );
		$away = (array) ( $m['away-team'] ?? array() );
		$date = (string) ( $m['date'] ?? '' );
		$ts   = strtotime( $date . ' 12:00' );
		if ( ! $ts ) {
			continue;
		}
		$time    = (string) ( $m['time'] ?? '' );
		$games[] = array(
			'id'    => (int) ( $m['id'] ?? 0 ),
			'date'  => $date,
			'name'  => trim( (string) ( $home['name'] ?? '' ) . ' ' . $versus . ' ' . (string) ( $away['name'] ?? '' ) ),
			'away'  => (string) ( $away['name'] ?? '' ),
			'day'   => wp_date( 'l j F', $ts ),
			'dm'    => wp_date( 'j F', $ts ),
			'ko'    => ew_day_time( $time ),
			'clock' => preg_match( '/^(\d{1,2}):(\d{2})/', $time, $c ) ? ( ( (int) $c[1] % 12 ? (int) $c[1] % 12 : 12 ) . ':' . $c[2] ) : '',
		);
	}
	return array_slice( $games, 0, 12 );
}

function ew_day_shortcode( $atts, $content = '' ) {
	$a     = shortcode_atts( ew_day_defaults(), (array) $atts, 'eastwood_day' );
	$lines = ew_day_lines( $content );
	if ( ! $lines ) {
		$lines = ew_day_lines( ew_day_default_lines() );
	}
	$rows = array();
	foreach ( $lines as $l ) {
		list( $time, $title, $line, $mark ) = ew_day_bits( $l, 4 );
		$rows[] = array( 'time' => $time, 'title' => $title, 'line' => $line, 'mark' => strtolower( $mark ) );
	}
	$cfg = array(
		'supabase' => EW_FAN_SUPABASE,
		'anon'     => EW_FAN_ANON,
		'api'      => esc_url_raw( rest_url( 'eastwood/v1/' ) ),
		'w'        => $a,
		'rows'     => $rows,
		'games'    => ew_day_games( $a['versus'] ),
	);
	return '<div class="ewday"><div class="ewday-main" aria-live="polite"><p class="ewday-wait">&nbsp;</p></div>'
		. '<script type="application/json" class="ewday-cfg">' . wp_json_encode( $cfg ) . '</script></div>';
}
add_shortcode( 'eastwood_day', 'ew_day_shortcode' );

function ew_day_upgrades_defaults() {
	return array(
		'title' => 'Make it a better day',
		'add'   => 'Add',
	);
}

/** The upgrades, drawn here and moved into the day page's slot once someone is signed in. */
function ew_day_upgrades_shortcode( $atts, $content = '' ) {
	$a     = shortcode_atts( ew_day_upgrades_defaults(), (array) $atts, 'eastwood_day_upgrades' );
	$cards = '';
	foreach ( ew_day_lines( $content ) as $l ) {
		list( $title, $line, $price, $link ) = ew_day_bits( $l, 4 );
		if ( '' === $title ) {
			continue;
		}
		$right = '';
		if ( '' !== $price ) {
			$right .= '<span class="p">' . esc_html( $price ) . '</span>';
		}
		if ( '' !== $link ) {
			$right .= '<a class="a" href="' . esc_url( $link ) . '">' . esc_html( $a['add'] ) . '</a>';
		}
		$cards .= '<div class="ewday-up"><div><p class="t">' . esc_html( $title ) . '</p>'
			. ( '' !== $line ? '<p class="l">' . esc_html( $line ) . '</p>' : '' ) . '</div>'
			. ( '' !== $right ? '<div class="r">' . $right . '</div>' : '' ) . '</div>';
	}
	if ( '' === $cards ) {
		return '';
	}
	return '<section class="ewday-ups" hidden><h2>' . esc_html( $a['title'] ) . '</h2><div class="ewday-upl">' . $cards . '</div></section>';
}
add_shortcode( 'eastwood_day_upgrades', 'ew_day_upgrades_shortcode' );

function ew_day_css() {
	return '
.ewday{max-width:1120px;margin:0 auto;padding:40px 24px 96px;font-family:"Instrument Sans",system-ui,sans-serif;color:#121417;box-sizing:border-box}
.ewday *{box-sizing:border-box}
.ewday p{margin:0}
.ewday-wait{min-height:240px}
.ewday-hd{background:#111;color:#fff;border-radius:14px;padding:40px}
.ewday-hd .id{font-family:Anton,sans-serif;font-size:14px;letter-spacing:1px;color:#ff4d4d;text-transform:uppercase}
.ewday-hd h1{margin:8px 0 0;font-family:Anton,sans-serif;font-weight:400;font-size:44px;line-height:1.02;text-transform:uppercase;color:#fff;background:none;padding:0}
.ewday-hd .m{margin-top:10px;font-size:16px;line-height:1.5;color:rgba(255,255,255,.8)}
.ewday-hd .m span{display:block}
.ewday-cols{display:grid;grid-template-columns:1.3fr 1fr;gap:24px;margin-top:24px;align-items:start}
.ewday-col{display:grid;gap:24px;min-width:0}
.ewday-tk{background:#000;color:#fff;border-radius:14px;padding:20px}
.ewday-k{font-family:Anton,sans-serif;font-size:13px;letter-spacing:1px;color:#ff4d4d;text-transform:uppercase}
.ewday-tk .q{display:flex;gap:14px;align-items:center;margin-top:12px}
.ewday-tk .gt{background:#CC0000;color:#fff;border-radius:8px;padding:10px 14px;margin-top:10px;display:flex;flex-direction:column;gap:2px}
.ewday-tk .gk{font-size:12px;font-weight:700;letter-spacing:2px;color:rgba(255,255,255,.85);text-transform:uppercase}
.ewday-tk .gn{font-family:Anton,sans-serif;font-size:28px;line-height:1.1;text-transform:uppercase}
.ewday-tk .ge{font-size:14px;color:rgba(255,255,255,.9)}
.ewday-tk .qr{background:#fff;border-radius:8px;padding:6px;flex:none;line-height:0}
.ewday-tk .c{display:block;font-family:Anton,sans-serif;font-size:24px;letter-spacing:2px}
.ewday-tk .g{display:block;font-size:13px;color:rgba(255,255,255,.7);margin-top:4px}
.ewday-box{background:#fff;border:1px solid #e4e5e8;border-radius:14px;padding:22px}
.ewday h2{margin:0;font-family:Anton,sans-serif;font-weight:400;font-size:26px;line-height:1.1;text-transform:uppercase;color:#121417;background:none;padding:0}
.ewday-tl{margin-top:10px}
.ewday-row{display:flex;gap:14px;padding:14px 0;border-top:1px solid #e8e9ec}
.ewday-row .tm{width:62px;flex:none;font-family:Anton,sans-serif;font-size:18px;color:#121417}
.ewday-row .dot{width:14px;height:14px;border-radius:50%;flex:none;margin-top:4px;background:#fff;border:2px solid #c9ccd1}
.ewday-row.solid .dot{background:#CC0000;border-color:#CC0000}
.ewday-row .tx{flex:1;min-width:0}
.ewday-row .tt{font-weight:700;font-size:16px}
.ewday-row .tag{margin-left:8px;font-size:11px;font-weight:700;letter-spacing:1px;color:#CC0000;text-transform:uppercase}
.ewday-row .ln{font-size:14px;color:#565c64;margin-top:3px;line-height:1.45}
.ewday-ups h2{margin-bottom:12px}
.ewday-upl{display:grid;gap:10px}
.ewday-up{border:1px solid #e4e5e8;border-radius:12px;padding:16px;background:#fff;display:flex;justify-content:space-between;gap:12px;align-items:center}
.ewday-up .t{font-weight:700;font-size:16px}
.ewday-up .l{font-size:14px;color:#565c64;margin-top:3px}
.ewday-up .r{text-align:right;flex:none}
.ewday-up .p{display:block;font-family:Anton,sans-serif;font-size:20px}
.ewday-up .a{display:block;font-size:13px;font-weight:700;color:#121417 !important;text-decoration:underline;margin-top:2px}
.ewday-nx{background:#CC0000;color:#fff;border-radius:14px;padding:20px}
.ewday-nx .ewday-k{color:#fff;opacity:.85}
.ewday-nx .n{font-family:Anton,sans-serif;font-size:24px;line-height:1.15;margin-top:6px;text-transform:uppercase}
.ewday-nx .d,.ewday-nx .o{font-size:14px;margin-top:6px;line-height:1.45}
.ewday-nx a{display:inline-flex;align-items:center;height:42px;padding:0 16px;margin-top:14px;border-radius:8px;background:#fff;color:#121417 !important;font-weight:700;text-decoration:none !important}
.ewday-fine{font-size:14px;color:#565c64;line-height:1.8}
.ewday-fine a,.ewday-link{color:#121417 !important;font-weight:600;text-decoration:underline;cursor:pointer;background:none;border:0;padding:0;font:inherit}
.ewday-none{display:grid;gap:14px}
.ewday-none .ln{font-size:16px;line-height:1.5;color:#565c64}
.ewday-btn{display:flex;align-items:center;justify-content:center;gap:10px;height:54px;border-radius:10px;border:0;background:#CC0000;color:#fff !important;font-size:17px;font-weight:700;font-family:inherit;cursor:pointer;text-decoration:none !important;width:100%}
.ewday-btn[disabled]{opacity:.6}
.ewday-in{max-width:560px;margin:0 auto;background:#fff;border:1px solid #e4e5e8;border-radius:14px;padding:32px;display:flex;flex-direction:column;gap:14px}
.ewday-in h1{margin:0;font-family:Anton,sans-serif;font-weight:400;font-size:40px;line-height:1.1;text-transform:uppercase;color:#121417;background:none;padding:0}
.ewday-in .ln{font-size:16px;line-height:1.5;color:#565c64}
.ewday label{display:block;font-size:11px;font-weight:600;letter-spacing:1px;color:#8b9099;text-transform:uppercase}
.ewday input{display:block;width:100%;height:52px;margin-top:8px;border:1px solid #d9d9dd;border-radius:10px;padding:0 14px;font-size:16px;font-family:inherit;color:#121417;background:#fff}
.ewday input:focus{outline:2px solid #121417;outline-offset:1px}
.ewday-box2{font-size:14px;color:#565c64;background:#f3f3f3;border-radius:8px;padding:12px;line-height:1.8}
.ewday-box2 a{color:#121417 !important;font-weight:600;text-decoration:underline}
.ewday-err{font-size:14px;color:#CC0000}
@media (max-width:860px){
.ewday{padding:20px 16px 64px}
.ewday-cols{display:flex;flex-direction:column;align-items:stretch;gap:18px;margin-top:18px}
.ewday-col{display:contents}
.ewday-hd{padding:28px 24px}
.ewday-hd h1{font-size:34px}
.ewday-tk{order:1}.ewday-tlb{order:2}.ewday-ups{order:3}.ewday-nx{order:4}.ewday-acct{order:5}
.ewday-in{padding:22px}
.ewday-in h1{font-size:30px}
}
';
}

function ew_day_js() {
	return <<<'JS'
(function () {
  var root = document.querySelector('.ewday');
  if (!root || !window.supabase) return;
  var cfg = JSON.parse(root.querySelector('.ewday-cfg').textContent);
  var W = cfg.w, main = root.querySelector('.ewday-main');
  var sb = window.supabase.createClient(cfg.supabase, cfg.anon, { auth: { persistSession: true, autoRefreshToken: true, detectSessionInUrl: false, flowType: 'pkce' } });
  var ups = document.querySelector('.ewday-ups');
  var st = { email: '', err: '' };
  function e(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function v(id) { var n = document.getElementById('ewday-' + id); return n ? n.value.trim() : ''; }
  function link(text, url) { return text && url ? '<a href="' + e(url) + '">' + e(text) + '</a>' : ''; }
  function qrSvg(rows) {
    var n = rows.length, q = 2, t = n + q * 2, p = '';
    rows.forEach(function (r, y) { for (var x = 0; x < r.length; x++) { if (r.charAt(x) === '1') p += 'M' + (x + q) + ' ' + (y + q) + 'h1v1h-1z'; } });
    return '<svg viewBox="0 0 ' + t + ' ' + t + '" width="110" height="110" shape-rendering="crispEdges" aria-hidden="true"><rect width="' + t + '" height="' + t + '" fill="#fff"/><path d="' + p + '" fill="#000"/></svg>';
  }
  async function token() { var s = (await sb.auth.getSession()).data.session; return s ? s.access_token : null; }
  function logFail(email, err) { try { fetch(cfg.api + 'auth/failed', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: String(email || '').toLowerCase(), reason: (err && (err.message || err.code)) || '', where: 'my_day', method: 'password' }), keepalive: true }); } catch (x) {} }
  async function mine() {
    var t = await token();
    var r = await fetch(cfg.api + 'app/mine', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Eastwood-Fan': t }, body: '{}' });
    var j = await r.json().catch(function () { return {}; });
    if (!r.ok) throw new Error(j.message || 'Something went wrong. Please try again.');
    return j.items || [];
  }
  /* Which home game a ticket is for: its fixture id, or the away side and the date in its title. */
  function gameFor(i) {
    var low = String(i.title || '').toLowerCase();
    for (var k = 0; k < cfg.games.length; k++) {
      var g = cfg.games[k];
      if (i.fixture && +i.fixture === +g.id) return g;
      if (g.away && low.indexOf(g.away.toLowerCase()) > -1 && low.indexOf(g.dm.toLowerCase()) > -1) return g;
    }
    return null;
  }
  function pick(items) {
    var best = null;
    items.forEach(function (i) {
      if (i.used || i.status === 'refunded' || i.status === 'cancelled') return;
      var g = gameFor(i); if (!g) return;
      if (!best || g.date < best.g.date) best = { i: i, g: g };
    });
    return best;
  }
  function fill(s, g) { return String(s || '').replace(/\{kickoff\}/g, g ? g.clock : '').replace(/\{match\}/g, g ? g.name : '').trim(); }

  function signinView() {
    return '<div class="ewday-in"><h1>' + e(W.signin_title) + '</h1>' + (W.signin_line ? '<p class="ln">' + e(W.signin_line) + '</p>' : '')
      + '<label>' + e(W.email_label) + '<input id="ewday-email" type="email" value="' + e(st.email) + '" autocomplete="email"></label>'
      + '<label>' + e(W.password_label) + '<input id="ewday-pw" type="password" autocomplete="current-password"></label>'
      + (st.err ? '<p class="ewday-err">' + e(st.err) + '</p>' : '')
      + '<button class="ewday-btn" data-do="signin">' + e(W.signin_button) + '</button>'
      + '<p class="ewday-fine">' + e(W.new_here) + '<br>' + link(W.create_link, W.create_url) + '<br>' + link(W.forgot_link, W.forgot_url) + '</p>'
      + (W.booked ? '<p class="ewday-box2">' + e(W.booked) + '<br>' + link(W.ref_link, W.ref_url) + '</p>' : '')
      + '</div>';
  }

  function dayView(efc, who, email, best) {
    var g = best ? best.g : null, i = best ? best.i : null;
    var meta = '';
    if (g) meta += '<span>' + e(g.name) + '</span><span>' + e(g.day) + (g.ko ? ' · ' + e(W.kickoff_label) + ' ' + e(g.ko) : '') + '</span>';
    var party = [who, i && i.lines].filter(function (x) { return x; }).join(' · ');
    if (party) meta += '<span>' + e(party) + '</span>';
    var head = '<div class="ewday-hd">' + (efc ? '<p class="id">' + e(W.id_label) + ' ' + e(efc) + '</p>' : '') + '<h1>' + e(W.title) + '</h1>'
      + (meta ? '<p class="m">' + meta + '</p>' : '') + '</div>';

    var left = '', right = '';
    if (i) {
      var rows = cfg.rows.map(function (r) {
        var tm = fill(r.time, g), tx = fill(r.line, g);
        if (!tm || !tx || !r.title) return '';
        var solid = r.mark === 'solid' || r.mark === 'offer';
        return '<div class="ewday-row' + (solid ? ' solid' : '') + '"><div class="tm">' + e(tm) + '</div><div class="dot"></div><div class="tx"><p class="tt">' + e(r.title)
          + (r.mark === 'offer' && W.offer_tag ? '<span class="tag">' + e(W.offer_tag) + '</span>' : '') + '</p><p class="ln">' + e(tx) + '</p></div></div>';
      }).join('');
      if (rows) left += '<div class="ewday-box ewday-tlb"><h2>' + e(W.day_title) + '</h2><div class="ewday-tl">' + rows + '</div></div>';
      left += '<div class="ewday-upslot"></div>';
      right += '<div class="ewday-tk"><p class="ewday-k">' + e(W.ticket_label) + '</p>'
        + (i.gate && i.gate.entrance ? '<div class="gt"><span class="gk">' + e(W.entrance_label) + '</span><span class="gn">' + e(i.gate.entrance) + '</span><span class="ge">' + e(i.gate.end || '') + (i.gate.moved ? ' · ' + e(W.moved_from) + ' ' + e(i.gate.moved) : '') + '</span></div>' : '')
        + (i.qr_rows && i.qr_rows.length ? '<div class="q"><span class="qr">' + qrSvg(i.qr_rows) + '</span><span><span class="c">' + e(i.code) + '</span><span class="g">' + e(W.show_gate) + '</span></span></div>'
          : '<div class="q"><span><span class="c">' + e(i.code) + '</span><span class="g">' + e(W.show_gate) + '</span></span></div>')
        + '</div>';
    } else {
      left += '<div class="ewday-box ewday-none"><p class="ln">' + e(W.no_ticket) + '</p>' + (W.buy && W.buy_url ? '<a class="ewday-btn" href="' + e(W.buy_url) + '">' + e(W.buy) + '</a>' : '') + '</div>';
    }
    var nx = null;
    for (var k = 0; k < cfg.games.length; k++) { if (!g || cfg.games[k].id !== g.id) { nx = cfg.games[k]; break; } }
    if (nx) {
      right += '<div class="ewday-nx"><p class="ewday-k">' + e(W.next_label) + '</p><p class="n">' + e(nx.name) + '</p>'
        + '<p class="d">' + e(nx.day) + (nx.ko ? ' · ' + e(W.kickoff_label) + ' ' + e(nx.ko) : '') + '</p>'
        + (W.next_offer ? '<p class="o">' + e(W.next_offer) + '</p>' : '')
        + (W.next_link && W.next_url ? '<a href="' + e(W.next_url) + '">' + e(W.next_link) + '</a>' : '') + '</div>';
    }
    right += '<p class="ewday-fine ewday-acct">' + (W.tickets_link && W.tickets_url ? link(W.tickets_link, W.tickets_url) + '<br>' : '')
      + e(W.signed_as) + ' ' + e(email) + '<br><button class="ewday-link" data-do="signout">' + e(W.sign_out) + '</button></p>';
    return head + '<div class="ewday-cols"><div class="ewday-col">' + left + '</div><div class="ewday-col">' + right + '</div></div>';
  }

  function parkUps() { if (ups) { ups.hidden = true; root.parentNode.insertBefore(ups, root.nextSibling); } }

  async function load() {
    var t = await token();
    if (!t) { parkUps(); main.innerHTML = signinView(); return; }
    var user = (await sb.auth.getUser()).data.user || {};
    var items = await mine();
    var efc = '', name = '';
    try {
      var sup = await sb.from('supporters').select('efc_number,name').maybeSingle();
      if (sup && sup.data) { efc = sup.data.efc_number ? 'EFC-' + sup.data.efc_number : ''; name = sup.data.name || ''; }
    } catch (x) {}
    var best = pick(items);
    var who = (best && best.i.name) || name;
    st.err = '';
    main.innerHTML = dayView(efc, who, user.email || '', best);
    var slot = main.querySelector('.ewday-upslot');
    if (ups && slot) { slot.replaceWith(ups); ups.hidden = false; } else parkUps();
  }

  async function run(fn) {
    main.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
    try { await fn(); } catch (x) { st.err = (x && x.message) || String(x); parkUps(); main.innerHTML = signinView(); }
  }

  main.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-do]'); if (!b) return;
    ev.preventDefault();
    if (b.dataset.do === 'signin') run(async function () {
      st.email = v('email');
      var r = await sb.auth.signInWithPassword({ email: st.email.toLowerCase(), password: v('pw') });
      if (r.error) { logFail(st.email, r.error); throw r.error; }
      await load();
    });
    if (b.dataset.do === 'signout') run(async function () { await sb.auth.signOut(); await load(); });
  });
  main.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Enter' || ev.target.tagName !== 'INPUT') return;
    var b = main.querySelector('.ewday-btn[data-do]'); if (b) { ev.preventDefault(); b.click(); }
  });
  run(load);
})();
JS;
}

add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_singular() ) {
		return;
	}
	$post = get_post();
	if ( ! $post || ! function_exists( 'ew_uses_shortcode' ) || ! ew_uses_shortcode( $post, 'eastwood_day' ) ) {
		return;
	}
	wp_enqueue_style( 'ewc-fonts', 'https://fonts.googleapis.com/css2?family=Anton&family=Instrument+Sans:wght@400;600;700&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
	wp_register_style( 'ew-day', false, array(), EW_FWP_VERSION );
	wp_enqueue_style( 'ew-day' );
	wp_add_inline_style( 'ew-day', ew_day_css() );
	wp_enqueue_script( 'ew-supabase', 'https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2.45.4/dist/umd/supabase.min.js', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
	wp_register_script( 'ew-day', '', array( 'ew-supabase' ), EW_FWP_VERSION, true );
	wp_enqueue_script( 'ew-day' );
	wp_add_inline_script( 'ew-day', ew_day_js() );
}, 22 );

/* The command channel lays these blocks out like the others. */
add_filter( 'ew_blocks_allowed', function ( $tags ) {
	$tags[] = 'eastwood_day';
	$tags[] = 'eastwood_day_upgrades';
	return $tags;
} );
