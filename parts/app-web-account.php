<?php
/**
 * eastwoodfc.com/my-tickets: the app's account and Tickets tab, on the website.
 *
 * [eastwood_account]
 *
 * The same supporter account as the Eastwood FC app (Supabase Auth): email and
 * password, create an account, forgotten password (an emailed link that only
 * works in the browser that asked for it: PKCE, so a copy of the email seen in
 * a shared inbox or the CRM is useless),
 * and the booking reference. Signed in, it shows exactly what the app's
 * Tickets tab shows, read from the same place (POST /app/mine), so the two can
 * never disagree. Signed in, each ticket shows the same QR the app shows (the
 * rows /app/mine sends), so the turnstile scans either. The block's qr field
 * set to "off" goes back to "Show in the app"; app_panel "on" brings back the
 * app box and the Get the app strip (for when the apps are in the stores).
 *
 * Apple and Google buttons follow the block's social field: "google", "apple",
 * "on" for both, "off" for neither. Each needs its web set-up first (Google:
 * done 3 Nov 2026 in the Eastwood FC App project; Apple: still to do).
 *
 * Every word is a field on the block.
 *
 * The header's Log in button (from the captured theme) is pointed here on
 * every page, and reads "My tickets" once someone is signed in.
 *
 * @package eastwood-fwp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ew_acc_defaults() {
	return array(
		'signin_title'  => 'Sign in to your tickets',
		'signin_line'   => 'The same account as the Eastwood FC app. Sign in here or in the app and you see the same tickets.',
		'email_label'   => 'Email',
		'password_label' => 'Password',
		'signin_button' => 'Sign in',
		'new_here'      => 'New here?',
		'create_link'   => 'Create an account',
		'forgot_link'   => 'Forgotten your password?',
		'or'            => 'or',
		'social'        => 'off',
		'apple'         => 'Continue with Apple',
		'google'        => 'Continue with Google',
		'booked'        => 'Booked on eastwoodfc.com?',
		'ref_link'      => 'Use your booking reference',
		'create_title'  => 'Create your free account',
		'create_line'   => 'Use the email you book with and your tickets show up here and in the app.',
		'create_button' => 'Create account',
		'have_account'  => 'Already have an account?',
		'reset_title'   => 'Reset your password',
		'reset_line'    => "Put in the email on your account and we'll email you a link to choose a new password.",
		'reset_button'  => 'Email me a link',
		'code_title'    => 'Check your email',
		'code_line'     => "If there's an account for that email, a link is on its way. Open it in this browser: it only works here, for one hour.",
		'newpw_title'   => 'Choose a new password',
		'newpw_label'   => 'New password',
		'code_button'   => 'Save and sign in',
		'link_failed'   => 'That link did not work. It only works in the browser you asked from, for one hour. Ask for a new one.',
		'id_label'      => 'MyEastwood · ID',
		'tickets_title' => 'Your tickets',
		'signed_as'     => 'Signed in as',
		'sign_out'      => 'Sign out',
		'none'          => 'Nothing booked under your account yet.',
		'show_app'      => 'Show in the app',
		'qr'            => 'on',
		'show_gate'     => 'Show this at the turnstile',
		'app_panel'     => 'off',
		'used'          => 'Used',
		'other_email'   => 'Booked with a different email?',
		'add_ref'       => 'Add it with its booking reference',
		'ref_title'     => 'Your booking reference',
		'ref_line'      => "It's in the confirmation email we sent you, with your tickets.",
		'ref_label'     => 'Booking reference',
		'ref_button'    => 'Find my tickets',
		'found_title'   => 'Found them',
		'found_line'    => 'Set a password and this is your Eastwood FC account, with your tickets in it.',
		'found_button'  => 'Save and see my tickets',
		'lost_ref'      => 'Lost your reference? Email me a link instead',
		'emailed'       => "If anything is booked under that email, we've emailed it a link. Tap it and those tickets join your account.",
		'back'          => 'Back',
		'buy'           => 'Buy tickets for the next home game',
		'buy_url'       => '/tickets/',
		'app_kicker'    => 'The Eastwood FC app',
		'app_title'     => 'Your tickets, at the gate',
		'app_line'      => 'Show your ticket from your phone, vote for Man of the Match and follow the game live.',
		'app_banner'    => 'Your ticket to scan at the gate is in the app. Sign in there with this account.',
		'app_button'    => 'Get the app',
		'app_url'       => '/app/',
		'ios_label'     => 'App Store',
		'ios_url'       => 'https://apps.apple.com/gb/app/eastwood-fc/id6820880105',
		'android_label' => 'Google Play',
		'android_url'   => 'https://play.google.com/store/apps/details?id=com.eastwoodfc.team',
		'short_pw'      => 'At least 8 characters, please.',
		'bad_email'     => 'That email does not look right.',
		'kind_match'    => 'Match ticket',
		'kind_event'    => 'Event ticket',
		'kind_food'     => 'Food tokens',
		'kind_shop'     => 'Your order',
		'kind_signup'   => 'Your place',
	);
}

function ew_acc_shortcode( $atts ) {
	$a = shortcode_atts( ew_acc_defaults(), (array) $atts, 'eastwood_account' );
	$words = $a;
	unset( $words['ios_url'], $words['android_url'] );
	$cfg = array(
		'supabase' => EW_FAN_SUPABASE,
		'anon'     => EW_FAN_ANON,
		'api'      => esc_url_raw( rest_url( 'eastwood/v1/' ) ),
		'page'     => get_permalink(),
		'w'        => $words,
	);
	$out  = '<div class="ewac">';
	$out .= '<section class="ewac-main" aria-live="polite"><p class="ewac-wait">&nbsp;</p></section>';
	if ( 'on' === strtolower( (string) $a['app_panel'] ) ) {
	$out .= '<aside class="ewac-app">';
	$out .= '<p class="ewac-kick">' . esc_html( $a['app_kicker'] ) . '</p>';
	$out .= '<h2>' . esc_html( $a['app_title'] ) . '</h2>';
	$out .= '<p>' . esc_html( $a['app_line'] ) . '</p>';
	$out .= '<a class="ewac-store" href="' . esc_url( $a['ios_url'] ) . '">' . esc_html( $a['ios_label'] ) . '</a>';
	$out .= '<a class="ewac-store ewac-store2" href="' . esc_url( $a['android_url'] ) . '">' . esc_html( $a['android_label'] ) . '</a>';
	$out .= '</aside>';
	}
	$out .= '<script type="application/json" class="ewac-cfg">' . wp_json_encode( $cfg ) . '</script>';
	$out .= '</div>';
	return $out;
}
add_shortcode( 'eastwood_account', 'ew_acc_shortcode' );

function ew_acc_css() {
	return '
.ewac{max-width:1120px;margin:0 auto;padding:48px 24px 96px;display:flex;flex-wrap:wrap;gap:32px;align-items:flex-start;font-family:"Instrument Sans",system-ui,sans-serif;color:#121417;box-sizing:border-box}
.ewac *{box-sizing:border-box}
.ewac-main{flex:999 1 460px;min-width:0;background:#fff;border:1px solid #e4e5e8;border-radius:14px;padding:32px;display:flex;flex-direction:column;gap:14px}
.ewac-main h1{margin:0;font-family:Anton,sans-serif;font-weight:400;font-size:40px;line-height:1.1;text-transform:uppercase;color:#121417;background:none;padding:0}
.ewac-line{margin:0;font-size:16px;line-height:1.5;color:#565c64}
.ewac label{display:block;font-size:11px;font-weight:600;letter-spacing:1px;color:#8b9099;text-transform:uppercase}
.ewac input{display:block;width:100%;height:52px;margin-top:8px;border:1px solid #d9d9dd;border-radius:10px;padding:0 14px;font-size:16px;font-family:inherit;color:#121417;background:#fff}
.ewac input:focus{outline:2px solid #121417;outline-offset:1px}
.ewac-btn{display:flex;align-items:center;justify-content:center;gap:10px;height:54px;border-radius:10px;border:0;background:#CC0000;color:#fff !important;font-size:17px;font-weight:700;font-family:inherit;cursor:pointer;text-decoration:none !important;width:100%}
.ewac-btn[disabled]{opacity:.6}
.ewac-dark{background:#111}
.ewac-light{background:#fff;color:#121417 !important;border:1px solid #d9d9dd}
.ewac-fine{margin:0;font-size:14px;color:#565c64}
.ewac-fine a,.ewac-link{color:#121417;font-weight:600;text-decoration:underline;cursor:pointer;background:none;border:0;padding:0;font:inherit}
.ewac-or{margin:4px 0;text-align:center;font-size:13px;color:#8b9099}
.ewac-box{margin:0;font-size:14px;color:#565c64;background:#f3f3f3;border-radius:8px;padding:12px}
.ewac-err{margin:0;font-size:14px;color:#CC0000}
.ewac-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
.ewac-tk{background:#000;border-radius:14px;padding:20px;color:#fff;display:flex;flex-direction:column;gap:6px}
.ewac-tk .k{margin:0;font-family:Anton,sans-serif;font-size:13px;letter-spacing:1px;color:#ff4d4d;text-transform:uppercase}
.ewac-tk .t{margin:0;font-family:Anton,sans-serif;font-size:26px;line-height:1.15;text-transform:uppercase}
.ewac-tk .s{margin:0;font-size:14px;color:rgba(255,255,255,.7)}
.ewac-tk .r{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:10px}
.ewac-tk .c{font-size:15px;font-weight:600;letter-spacing:1px}
.ewac-tk a{height:40px;padding:0 14px;border-radius:6px;background:#CC0000;color:#fff !important;display:flex;align-items:center;font-family:Anton,sans-serif;font-size:15px;text-transform:uppercase;text-decoration:none !important;white-space:nowrap}
.ewac-tk .q{display:flex;align-items:center;gap:14px;margin-top:12px}
.ewac-tk .qr{background:#fff;border-radius:8px;padding:6px;flex:none;display:block;line-height:0}
.ewac-tk .q .c{display:block;font-family:Anton,sans-serif;font-size:22px;letter-spacing:2px}
.ewac-tk .g{display:block;font-size:13px;color:rgba(255,255,255,.7);margin-top:4px}
.ewac-fine{line-height:1.8}
.ewac-box{line-height:1.8}
.ewac-head{display:flex;flex-wrap:wrap;align-items:flex-end;gap:12px}
.ewac-head>div{flex-grow:1}
.ewac-id{margin:0 0 4px;font-family:Anton,sans-serif;font-size:14px;letter-spacing:1px;color:#CC0000;text-transform:uppercase}
.ewac-buy{height:46px;padding:0 18px;border-radius:10px;border:2px solid #111;display:flex;align-items:center;color:#111 !important;font-weight:700;text-decoration:none !important}
.ewac-banner{display:flex;flex-wrap:wrap;align-items:center;gap:14px;background:#f3f3f3;border-radius:12px;padding:18px}
.ewac-banner p{margin:0;flex-grow:1;font-size:15px;line-height:1.5}
.ewac-banner a{height:46px;padding:0 18px;border-radius:10px;background:#CC0000;color:#fff !important;display:flex;align-items:center;font-weight:700;text-decoration:none !important}
.ewac-app{flex:1 1 300px;min-width:0;background:#111;color:#fff;border-radius:14px;padding:32px;display:flex;flex-direction:column;gap:12px}
.ewac-app h2{margin:0;font-family:Anton,sans-serif;font-weight:400;font-size:30px;line-height:1.1;text-transform:uppercase;color:#fff}
.ewac-app p{margin:0;font-size:15px;line-height:1.5;color:rgba(255,255,255,.75)}
.ewac-app .ewac-kick{font-family:Anton,sans-serif;font-size:13px;letter-spacing:1px;color:#ff4d4d;text-transform:uppercase}
.ewac-store{display:flex;align-items:center;justify-content:center;height:50px;border-radius:10px;background:#fff;color:#111 !important;font-weight:700;text-decoration:none !important}
.ewac-store2{background:transparent;color:#fff !important;border:1px solid rgba(255,255,255,.4)}
.ewac-wait{min-height:200px;margin:0}
@media (max-width:700px){.ewac{padding:20px 16px 64px;gap:20px}.ewac-main{padding:22px}.ewac-main h1{font-size:30px}.ewac-grid{grid-template-columns:repeat(1,minmax(0,1fr))}}
';
}

function ew_acc_js() {
	return <<<'JS'
(function () {
  var root = document.querySelector('.ewac');
  if (!root || !window.supabase) return;
  var cfg = JSON.parse(root.querySelector('.ewac-cfg').textContent);
  var W = cfg.w, main = root.querySelector('.ewac-main');
  var sb = window.supabase.createClient(cfg.supabase, cfg.anon, { auth: { persistSession: true, autoRefreshToken: true, detectSessionInUrl: true, flowType: 'pkce' } });
  var recovering = /[?&]code=/.test(location.search), linkFailed = /[?&#]error/.test(location.search + location.hash);
  sb.auth.onAuthStateChange(function (ev) { if (ev === 'PASSWORD_RECOVERY') { recovering = true; st.err = ''; show('newpw'); } });
  var st = { email: '', ref: '', wallet: null, err: '' };
  // social: "off", "google", "apple", or "on" (both). Each needs its web set-up first.
  var soc = String(W.social || 'off').toLowerCase();
  var socialApple = soc === 'on' || soc.indexOf('apple') > -1;
  var socialGoogle = soc === 'on' || soc.indexOf('google') > -1;
  var qrOn = String(W.qr || 'on').toLowerCase() !== 'off';
  var appOn = String(W.app_panel || 'off').toLowerCase() === 'on';
  /* The gate code, drawn from the rows the site sends: the same QR as the app. */
  function qrSvg(rows) {
    var n = rows.length, q = 2, t = n + q * 2, p = '';
    rows.forEach(function (r, y) { for (var x = 0; x < r.length; x++) { if (r.charAt(x) === '1') p += 'M' + (x + q) + ' ' + (y + q) + 'h1v1h-1z'; } });
    return '<svg viewBox="0 0 ' + t + ' ' + t + '" width="116" height="116" shape-rendering="crispEdges" aria-hidden="true"><rect width="' + t + '" height="' + t + '" fill="#fff"/><path d="' + p + '" fill="#000"/></svg>';
  }
  var APPLE = '<svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path fill="#fff" d="M16.4 12.6c0-2.6 2.1-3.8 2.2-3.9-1.2-1.8-3.1-2-3.7-2-1.6-.2-3.1.9-3.9.9-.8 0-2-.9-3.4-.9-1.7 0-3.3 1-4.2 2.6-1.8 3.1-.5 7.8 1.3 10.3.9 1.2 1.9 2.6 3.2 2.6 1.3-.1 1.8-.8 3.3-.8s2 .8 3.4.8c1.4 0 2.3-1.3 3.1-2.5 1-1.4 1.4-2.8 1.4-2.9-.1 0-2.7-1-2.7-4.2ZM13.9 4.9c.7-.9 1.2-2.1 1-3.3-1 0-2.3.7-3 1.6-.7.8-1.2 2-1.1 3.2 1.2.1 2.4-.6 3.1-1.5Z"/></svg>';
  var GOOGLE = '<svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9.1 3.6l6.8-6.8C35.8 2.4 30.2 0 24 0 14.6 0 6.6 5.4 2.6 13.3l7.9 6.2C12.4 13.7 17.7 9.5 24 9.5z"/><path fill="#4285F4" d="M46.1 24.6c0-1.6-.1-3.1-.4-4.6H24v9h12.4c-.5 2.9-2.2 5.3-4.6 6.9l7.4 5.7c4.3-4 6.9-9.9 6.9-17z"/><path fill="#FBBC05" d="M10.5 28.5c-.5-1.4-.8-2.9-.8-4.5s.3-3.1.8-4.5l-7.9-6.2C1 16.6 0 20.2 0 24s1 7.4 2.6 10.7l7.9-6.2z"/><path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.9-5.8l-7.4-5.7c-2.1 1.4-4.8 2.3-8.5 2.3-6.3 0-11.6-4.2-13.5-10l-7.9 6.2C6.6 42.6 14.6 48 24 48z"/></svg>';

  function e(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function field(id, label, type, val, ac) { return '<label>' + e(label) + '<input id="ewac-' + id + '" type="' + type + '" value="' + e(val || '') + '" autocomplete="' + ac + '"></label>'; }
  function v(id) { var n = document.getElementById('ewac-' + id); return n ? n.value.trim() : ''; }
  function err() { return st.err ? '<p class="ewac-err">' + e(st.err) + '</p>' : ''; }
  function okEmail(x) { return /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(x); }
  async function token() { var s = (await sb.auth.getSession()).data.session; return s ? s.access_token : null; }
  async function api(path, body) {
    var h = { 'Content-Type': 'application/json', Accept: 'application/json' }, t = await token();
    if (t) h['X-Eastwood-Fan'] = t;
    var r = await fetch(cfg.api + path, { method: 'POST', headers: h, body: JSON.stringify(body || {}) });
    var j = await r.json().catch(function () { return {}; });
    if (!r.ok) throw new Error(j.message || 'Something went wrong. Please try again.');
    return j;
  }
  function busy(on) { main.querySelectorAll('button').forEach(function (b) { b.disabled = on; }); }
  async function act(fn) { st.err = ''; busy(true); try { await fn(); } catch (x) { st.err = (x && x.message) || String(x); show(st.view); } busy(false); }

  var views = {
    signin: function () {
      return '<h1>' + e(W.signin_title) + '</h1><p class="ewac-line">' + e(W.signin_line) + '</p>'
        + field('email', W.email_label, 'email', st.email, 'email') + field('pw', W.password_label, 'password', '', 'current-password') + err()
        + '<button class="ewac-btn" data-do="signin">' + e(W.signin_button) + '</button>'
        + '<p class="ewac-fine">' + e(W.new_here) + ' <button class="ewac-link" data-go="create">' + e(W.create_link) + '</button><br><button class="ewac-link" data-go="reset">' + e(W.forgot_link) + '</button></p>'
        + (socialApple || socialGoogle ? '<p class="ewac-or">' + e(W.or) + '</p>' : '')
        + (socialApple ? '<button class="ewac-btn ewac-dark" data-do="apple">' + APPLE + e(W.apple) + '</button>' : '')
        + (socialGoogle ? '<button class="ewac-btn ewac-light" data-do="google">' + GOOGLE + e(W.google) + '</button>' : '')
        + '<p class="ewac-box">' + e(W.booked) + '<br><button class="ewac-link" data-go="ref">' + e(W.ref_link) + '</button></p>';
    },
    create: function () {
      return '<h1>' + e(W.create_title) + '</h1><p class="ewac-line">' + e(W.create_line) + '</p>'
        + field('email', W.email_label, 'email', st.email, 'email') + field('pw', W.password_label, 'password', '', 'new-password') + err()
        + '<button class="ewac-btn" data-do="create">' + e(W.create_button) + '</button>'
        + '<p class="ewac-fine">' + e(W.have_account) + ' <button class="ewac-link" data-go="signin">' + e(W.signin_button) + '</button></p>';
    },
    reset: function () {
      return '<h1>' + e(W.reset_title) + '</h1><p class="ewac-line">' + e(W.reset_line) + '</p>'
        + field('email', W.email_label, 'email', st.email, 'email') + err()
        + '<button class="ewac-btn" data-do="sendcode">' + e(W.reset_button) + '</button>'
        + '<p class="ewac-fine"><button class="ewac-link" data-go="signin">' + e(W.back) + '</button></p>';
    },
    code: function () {
      return '<h1>' + e(W.code_title) + '</h1><p class="ewac-line">' + e(W.code_line) + '</p>'
        + '<p class="ewac-fine"><button class="ewac-link" data-go="reset">' + e(W.back) + '</button></p>';
    },
    newpw: function () {
      return '<h1>' + e(W.newpw_title) + '</h1>'
        + field('pw', W.newpw_label, 'password', '', 'new-password') + err()
        + '<button class="ewac-btn" data-do="setpw">' + e(W.code_button) + '</button>';
    },
    ref: function () {
      return '<h1>' + e(W.ref_title) + '</h1><p class="ewac-line">' + e(W.ref_line) + '</p>'
        + field('email', W.email_label, 'email', st.email, 'email') + field('ref', W.ref_label, 'text', st.ref, 'off') + err()
        + '<button class="ewac-btn" data-do="claim">' + e(W.ref_button) + '</button>'
        + (st.signedIn ? '<p class="ewac-fine"><button class="ewac-link" data-do="emaillink">' + e(W.lost_ref) + '</button></p>' : '')
        + '<p class="ewac-fine"><button class="ewac-link" data-go="' + (st.signedIn ? 'tickets' : 'signin') + '">' + e(W.back) + '</button></p>';
    },
    found: function () {
      return '<h1>' + e(W.found_title) + '</h1><p class="ewac-line">' + e(W.found_line) + '</p>'
        + field('pw', W.password_label, 'password', '', 'new-password') + err()
        + '<button class="ewac-btn" data-do="foundpw">' + e(W.found_button) + '</button>';
    },
    emailed: function () {
      return '<h1>' + e(W.code_title) + '</h1><p class="ewac-line">' + e(W.emailed) + '</p>'
        + '<button class="ewac-btn" data-go="tickets">' + e(W.back) + '</button>';
    },
    tickets: function () {
      var items = st.items || [];
      var cards = items.map(function (i) {
        var k = W['kind_' + i.kind] || W.kind_event;
        var foot = (qrOn && i.qr_rows && i.qr_rows.length)
          ? '<div class="q"><span class="qr">' + qrSvg(i.qr_rows) + '</span><span><span class="c">' + e(i.code) + '</span><span class="g">' + e(i.used ? W.used : W.show_gate) + '</span></span></div>'
          : '<div class="r"><span class="c">' + e(i.code) + '</span><a href="' + e(W.app_url) + '">' + e(W.show_app) + '</a></div>';
        return '<div class="ewac-tk"><p class="k">' + e(k) + (i.used ? ' · ' + e(W.used) : '') + '</p><p class="t">' + e(i.title) + '</p>'
          + (i.lines ? '<p class="s">' + e(i.lines) + '</p>' : '') + foot + '</div>';
      }).join('');
      return '<div class="ewac-head"><div>' + (st.efc ? '<p class="ewac-id">' + e(W.id_label) + ' ' + e(st.efc) + '</p>' : '') + '<h1>' + e(W.tickets_title) + '</h1><p class="ewac-fine">' + e(W.signed_as) + ' ' + e(st.who) + ' · <button class="ewac-link" data-do="signout">' + e(W.sign_out) + '</button></p></div>'
        + (W.buy_url ? '<a class="ewac-buy" href="' + e(W.buy_url) + '">' + e(W.buy) + '</a>' : '') + '</div>' + err()
        + (items.length ? '<div class="ewac-grid">' + cards + '</div>' : '<p class="ewac-line">' + e(W.none) + '</p>')
        + '<p class="ewac-fine">' + e(W.other_email) + '<br><button class="ewac-link" data-go="ref">' + e(W.add_ref) + '</button></p>'
        + (appOn ? '<div class="ewac-banner"><p>' + e(W.app_banner) + '</p><a href="' + e(W.app_url) + '">' + e(W.app_button) + '</a></div>' : '');
    }
  };

  function show(name) {
    st.view = name;
    main.innerHTML = views[name]();
    var f = main.querySelector('input'); if (f && name !== 'tickets') f.focus();
  }

  async function loadTickets() {
    var t = await token();
    if (!t) { st.signedIn = false; show('signin'); return; }
    st.signedIn = true;
    st.who = ((await sb.auth.getUser()).data.user || {}).email || '';
    var mine = await api('app/mine', { wallets: st.wallet ? [st.wallet] : [] });
    st.items = mine.items || [];
    // Their MyEastwood number (EFC-101234). Only their own row is readable.
    try {
      var sup = await sb.from('supporters').select('efc_number').maybeSingle();
      st.efc = sup && sup.data && sup.data.efc_number ? 'EFC-' + sup.data.efc_number : '';
    } catch (x) { st.efc = ''; }
    st.err = '';
    show('tickets');
  }

  var actions = {
    signin: function () { return act(async function () {
      st.email = v('email');
      var r = await sb.auth.signInWithPassword({ email: st.email.toLowerCase(), password: v('pw') });
      if (r.error) throw r.error; await loadTickets();
    }); },
    create: function () { return act(async function () {
      st.email = v('email'); var pw = v('pw');
      if (!okEmail(st.email)) throw new Error(W.bad_email);
      if (pw.length < 8) throw new Error(W.short_pw);
      var r = await sb.auth.signUp({ email: st.email.toLowerCase(), password: pw });
      if (r.error) throw r.error; await loadTickets();
    }); },
    sendcode: function () { return act(async function () {
      st.email = v('email'); if (!okEmail(st.email)) throw new Error(W.bad_email);
      var r = await sb.auth.resetPasswordForEmail(st.email.toLowerCase(), { redirectTo: cfg.page }); if (r.error) throw r.error; show('code');
    }); },
    setpw: function () { return act(async function () {
      var pw = v('pw'); if (pw.length < 8) throw new Error(W.short_pw);
      var u = await sb.auth.updateUser({ password: pw }); if (u.error) throw u.error;
      recovering = false; history.replaceState(null, '', cfg.page); await loadTickets();
    }); },
    claim: function () { return act(async function () {
      st.email = v('email'); st.ref = v('ref');
      var w = await api('app/claim', { email: st.email, ref: st.ref });
      st.wallet = w.wallet;
      if (st.signedIn) await loadTickets(); else { st.email = w.email; show('found'); }
    }); },
    foundpw: function () { return act(async function () {
      var pw = v('pw'); if (pw.length < 8) throw new Error(W.short_pw);
      var r = await sb.auth.signUp({ email: st.email.toLowerCase(), password: pw });
      if (r.error && /registered|exists/i.test(r.error.message)) r = await sb.auth.signInWithPassword({ email: st.email.toLowerCase(), password: pw });
      if (r.error) throw r.error; await loadTickets();
    }); },
    emaillink: function () { return act(async function () {
      st.email = v('email'); if (!okEmail(st.email)) throw new Error(W.bad_email);
      await api('app/link-email', { email: st.email }); show('emailed');
    }); },
    signout: function () { return act(async function () { await sb.auth.signOut(); st.items = []; st.signedIn = false; show('signin'); }); },
    apple: function () { return act(async function () { var r = await sb.auth.signInWithOAuth({ provider: 'apple', options: { redirectTo: cfg.page } }); if (r.error) throw r.error; }); },
    google: function () { return act(async function () { var r = await sb.auth.signInWithOAuth({ provider: 'google', options: { redirectTo: cfg.page } }); if (r.error) throw r.error; }); }
  };

  main.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-go],[data-do]'); if (!b) return;
    ev.preventDefault();
    var em = document.getElementById('ewac-email'); if (em) st.email = em.value.trim();
    if (b.dataset.go) { st.err = ''; if (b.dataset.go === 'tickets') { act(loadTickets); } else { show(b.dataset.go); } }
    else if (actions[b.dataset.do]) actions[b.dataset.do]();
  });
  main.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Enter' || ev.target.tagName !== 'INPUT') return;
    var b = main.querySelector('.ewac-btn[data-do]'); if (b) { ev.preventDefault(); b.click(); }
  });

  if (/[?&]ref\b/.test(location.search)) { st.view = 'ref'; }
  if (/[?&]reset\b/.test(location.search)) { st.view = 'reset'; }
  if (linkFailed) { st.err = W.link_failed; history.replaceState(null, '', cfg.page); show('reset'); }
  else if (!recovering) act(async function () { await loadTickets(); if (!st.signedIn && (st.view === 'ref' || st.view === 'reset')) show(st.view); });
  else setTimeout(function () { if (st.view !== 'newpw') act(loadTickets); }, 4000);
})();
JS;
}

/** The header's Log in (from the captured theme) goes to the account page; "My tickets" once signed in. */
function ew_acc_header_js() {
	$url  = esc_url_raw( home_url( '/my-tickets/' ) );
	$key  = 'sb-' . explode( '.', wp_parse_url( EW_FAN_SUPABASE, PHP_URL_HOST ) )[0] . '-auth-token';
	return '(function(){var u=' . wp_json_encode( $url ) . ',k=' . wp_json_encode( $key ) . ',inn=false;try{inn=!!localStorage.getItem(k);}catch(x){}'
		. 'document.querySelectorAll(\'[aria-label="Log In"]\').forEach(function(w){w.style.cursor="pointer";w.addEventListener("click",function(ev){ev.preventDefault();location.href=u;});'
		. 'if(inn){w.setAttribute("aria-label","My tickets");w.querySelectorAll("span").forEach(function(s){if(/log\\s*in/i.test(s.textContent))s.textContent="My tickets";});}});})();';
}

add_action( 'wp_enqueue_scripts', function () {
	wp_register_script( 'ew-acc-header', '', array(), EW_FWP_VERSION, true );
	wp_enqueue_script( 'ew-acc-header' );
	wp_add_inline_script( 'ew-acc-header', ew_acc_header_js() );

	if ( ! is_singular() ) {
		return;
	}
	$post = get_post();
	if ( ! $post || ! function_exists( 'ew_uses_shortcode' ) || ! ew_uses_shortcode( $post, 'eastwood_account' ) ) {
		return;
	}
	wp_enqueue_style( 'ewc-fonts', 'https://fonts.googleapis.com/css2?family=Anton&family=Instrument+Sans:wght@400;600;700&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
	wp_register_style( 'ew-acc', false, array(), EW_FWP_VERSION );
	wp_enqueue_style( 'ew-acc' );
	wp_add_inline_style( 'ew-acc', ew_acc_css() );
	wp_enqueue_script( 'ew-supabase', 'https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2.45.4/dist/umd/supabase.min.js', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
	wp_register_script( 'ew-acc', '', array( 'ew-supabase' ), EW_FWP_VERSION, true );
	wp_enqueue_script( 'ew-acc' );
	wp_add_inline_script( 'ew-acc', ew_acc_js() );
}, 22 );

/* The command channel lays this block out like the others. */
add_filter( 'ew_blocks_allowed', function ( $tags ) {
	$tags[] = 'eastwood_account';
	return $tags;
} );
