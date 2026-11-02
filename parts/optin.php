<?php
/**
 * The two-step way every sign-up form on the site now works.
 *
 * Step one asks for an email and nothing else. The moment the button is
 * pressed a Booking is saved as "started", the CRM is told, and the adverts
 * hear about a lead -- so somebody who stops there is still known, and gets
 * one reminder an hour later. Step two asks for the rest on the same card,
 * mobile first, and saves each box as they leave it. The form's own
 * endpoint then finishes the same Booking rather than making a new one.
 *
 * The last thing they see is "your place is in the app": their place or
 * ticket sliding out of the app's Home screen, with the store buttons and
 * a private link that carries the booking into the app.
 *
 * Nothing here is a block of its own. Each sign-up block (the half-time
 * show first) draws its own fields and calls ew_optin_*() for the shared
 * parts, so the forms cannot drift apart.
 *
 * @package eastwood-fwp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Which sign-up blocks use this, and how to read each one's fields from the
 * page it sits on. A block registers itself with the ew_optin_forms filter:
 * kind => callable( WP_Post ) returning its fields, or null.
 */
function ew_optin_forms() {
	return (array) apply_filters( 'ew_optin_forms', array() );
}

/** A booking reference that can be read out and typed: EFC-123456. */
function ew_booking_code( $bid ) {
	$have = (string) get_post_meta( $bid, '_ewt_code', true );
	if ( '' !== $have ) {
		return $have;
	}
	do {
		$code = 'EFC-' . wp_rand( 100000, 999999 );
	} while ( get_posts( array( 'post_type' => 'ewt_booking', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_ewt_code', 'meta_value' => $code ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	update_post_meta( $bid, '_ewt_code', $code );
	return $code;
}

/** The private key that lets the person who started a booking carry on with it. */
function ew_booking_key( $bid ) {
	$k = (string) get_post_meta( $bid, '_ewt_key', true );
	if ( '' === $k ) {
		$k = strtolower( wp_generate_password( 24, false, false ) );
		update_post_meta( $bid, '_ewt_key', $k );
	}
	return $k;
}

/**
 * The booking a form submission carries on, if it carries one and the key
 * matches. A booking already finished cannot be taken over again.
 */
function ew_optin_claim( $req ) {
	$bid = (int) $req->get_param( 'bid' );
	$key = (string) $req->get_param( 'key' );
	if ( ! $bid || '' === $key || 'ewt_booking' !== get_post_type( $bid ) ) {
		return 0;
	}
	if ( ! hash_equals( (string) get_post_meta( $bid, '_ewt_key', true ), $key ) ) {
		return 0;
	}
	return 'started' === get_post_meta( $bid, '_ewt_status', true ) ? $bid : 0;
}

/**
 * The booking a request is about once it is finished: the bid and key the
 * last page was handed (ew_optin_finished's claim, "bid.key"). Any status;
 * the caller says which ones it accepts.
 */
function ew_optin_owner( $req ) {
	$bid = (int) $req->get_param( 'bid' );
	$key = (string) $req->get_param( 'key' );
	$cl  = (string) $req->get_param( 'claim' );
	if ( ( ! $bid || '' === $key ) && false !== strpos( $cl, '.' ) ) {
		list( $bid, $key ) = explode( '.', $cl, 2 );
		$bid = (int) $bid;
	}
	if ( ! $bid || '' === $key || 'ewt_booking' !== get_post_type( $bid ) ) {
		return 0;
	}
	return hash_equals( (string) get_post_meta( $bid, '_ewt_key', true ), (string) $key ) ? $bid : 0;
}

/** What a finished sign-up hands back to the page: the in-the-app panel. */
function ew_optin_finished( $bid ) {
	return array(
		'ok'    => 1,
		'code'  => ew_booking_code( $bid ),
		'claim' => $bid . '.' . ew_booking_key( $bid ),
	);
}

add_action( 'rest_api_init', function () {
	/* Step one: the email, on its own. */
	register_rest_route( 'eastwood/v1', '/optin/start', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) {
			$kind  = sanitize_key( (string) $req->get_param( 'kind' ) );
			$forms = ew_optin_forms();
			$post  = get_post( (int) $req->get_param( 'page' ) );
			$a     = ( $post && isset( $forms[ $kind ] ) ) ? call_user_func( $forms[ $kind ], $post ) : null;
			if ( ! $a ) {
				return new WP_REST_Response( array( 'message' => 'no form' ), 400 );
			}
			$email = sanitize_email( (string) $req->get_param( 'email' ) );
			if ( ! is_email( $email ) ) {
				return new WP_REST_Response( array( 'message' => (string) ( $a['need_email'] ?? 'We need an email.' ) ), 400 );
			}
			// A form can ask for a name on step one too. Only sent when it
			// does; the email-only forms never send one.
			$name = '';
			if ( null !== $req->get_param( 'name' ) ) {
				$name = trim( sanitize_text_field( (string) $req->get_param( 'name' ) ) );
				if ( '' !== $name && function_exists( 'ew_person_name' ) ) {
					$name = ew_person_name( $name );
				}
				if ( '' === $name ) {
					return new WP_REST_Response( array( 'message' => (string) ( $a['need_name'] ?? 'We need your name.' ) ), 400 );
				}
			}
			// Somebody who came from an invite link (?ref=) carries it in.
			$ref = substr( preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $req->get_param( 'ref' ) ) ), 0, 40 );

			// The same person pressing the button twice on the same page is
			// one sign-up, not two.
			$again = get_posts( array(
				'post_type'   => 'ewt_booking',
				'post_status' => 'any',
				'numberposts' => 1,
				'fields'      => 'ids',
				'date_query'  => array( array( 'after' => '1 day ago' ) ),
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array( 'key' => '_ewt_email', 'value' => $email ),
					array( 'key' => '_ewt_page', 'value' => $post->ID ),
					array( 'key' => '_ewt_status', 'value' => 'started' ),
					array( 'key' => '_ewt_kind', 'value' => 'signup' ),
				),
			) );
			if ( $again ) {
				$bid = (int) $again[0];
				if ( '' !== $name ) {
					update_post_meta( $bid, '_ewt_name', $name );
					wp_update_post( array( 'ID' => $bid, 'post_title' => $name ) );
				}
			} else {
				if ( ! function_exists( 'ew_tix_booking_start' ) ) {
					return new WP_REST_Response( array( 'message' => (string) ( $a['failed'] ?? 'Please try again.' ) ), 500 );
				}
				$bid = ew_tix_booking_start( $post, (string) ( $a['event'] ?? '' ), $name, $email, '', 0, (string) ( $a['crm_hook'] ?? '' ) );
				if ( ! $bid ) {
					return new WP_REST_Response( array( 'message' => (string) ( $a['failed'] ?? 'Please try again.' ) ), 500 );
				}
				update_post_meta( $bid, '_ewt_kind', 'signup' );
				update_post_meta( $bid, '_ewt_form', $kind );
			}
			if ( '' !== $ref && '' === (string) get_post_meta( $bid, '_ewt_ref', true ) ) {
				update_post_meta( $bid, '_ewt_ref', $ref );
				do_action( 'ew_optin_ref', $bid, $ref, $kind );
			}
			return array( 'bid' => $bid, 'key' => ew_booking_key( $bid ) );
		},
	) );

	/* Step two, one box at a time, as they leave it. */
	register_rest_route( 'eastwood/v1', '/optin/save', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) {
			$bid = ew_optin_claim( $req );
			if ( ! $bid ) {
				return new WP_REST_Response( array( 'ok' => 0 ), 400 );
			}
			$field = sanitize_key( (string) $req->get_param( 'field' ) );
			if ( ! in_array( $field, array( 'name', 'phone', 'notes' ), true ) ) {
				return new WP_REST_Response( array( 'ok' => 0 ), 400 );
			}
			$value = trim( sanitize_text_field( (string) $req->get_param( 'value' ) ) );
			if ( 'name' === $field && function_exists( 'ew_person_name' ) ) {
				$value = ew_person_name( $value );
			}
			if ( '' === $value ) {
				return array( 'ok' => 1 );
			}
			$had = (string) get_post_meta( $bid, '_ewt_' . $field, true );
			update_post_meta( $bid, '_ewt_' . $field, $value );
			if ( 'name' === $field ) {
				wp_update_post( array( 'ID' => $bid, 'post_title' => $value ) );
			}
			// The CRM hears once that a mobile has arrived, not on every keystroke.
			if ( 'phone' === $field && '' === $had && function_exists( 'ew_tix_crm' ) && strlen( preg_replace( '/\D/', '', $value ) ) >= 10 ) {
				ew_tix_crm( (string) get_post_meta( $bid, '_ewt_hook', true ), $bid, 'details' );
			}
			return array( 'ok' => 1 );
		},
	) );
} );

/* --- The shared front end --------------------------------------------- */

/**
 * The step-one card: a heading, one box, one button.
 *
 * $opt['name'] set to a label adds a name box above the email, sent with
 * it and saved on the started booking. Without it the card is email only.
 */
function ew_optin_step_one( $p, $a, $opt = array() ) {
	$h  = '<div class="ewo-one" data-ewo-one>';
	$h .= '<p class="' . esc_attr( $p ) . '-head">' . esc_html( $a['form_title'] ) . '</p>';
	if ( '' !== (string) ( $opt['name'] ?? '' ) ) {
		$h .= '<label class="' . esc_attr( $p ) . '-lab" for="' . esc_attr( $p ) . 'StartName">' . esc_html( $opt['name'] ) . '</label>';
		$h .= '<input class="' . esc_attr( $p ) . '-in" id="' . esc_attr( $p ) . 'StartName" type="text" autocomplete="name" required data-ewo-name>';
	}
	$h .= '<label class="' . esc_attr( $p ) . '-lab" for="' . esc_attr( $p ) . 'Email">' . esc_html( $a['email_label'] ) . '</label>';
	$h .= '<input class="' . esc_attr( $p ) . '-in" id="' . esc_attr( $p ) . 'Email" type="email" autocomplete="email" required data-ewo-email>';
	$h .= '<p class="' . esc_attr( $p ) . '-err" role="alert" data-ewo-err1></p>';
	$h .= '<button type="button" class="' . esc_attr( $p ) . '-btn" data-ewo-next>' . esc_html( $a['send'] ) . '</button>';
	return $h . '</div>';
}

/** The words the shared script needs, merged into the block's own. */
function ew_optin_words( $kind, $a ) {
	return array(
		'kind'       => $kind,
		'start'      => esc_url_raw( rest_url( 'eastwood/v1/optin/start' ) ),
		'save'       => esc_url_raw( rest_url( 'eastwood/v1/optin/save' ) ),
		'need_email' => (string) $a['need_email'],
		'failed'     => (string) $a['failed'],
		'what'       => (string) ( $a['track'] ?? $kind ),
		'need_name'  => (string) ( $a['need_name'] ?? '' ),
	);
}

/**
 * The step-one / step-two machine, shared by every sign-up block.
 *
 *   ewOptin(root, W, on)
 *
 * root  the block's section; holds [data-ewo-one] and [data-ewo-two]
 * W     its words, with start / save / kind / need_email / failed / what;
 *       W.ref true sends ?ref= from the address with step one
 * on    optional: on.open(o) runs whenever step two is shown
 *
 * Gives back an object the block's own submit uses: o.bid, o.key, o.email,
 * and o.name when step one has a name box ([data-ewo-name]).
 * Boxes in step two marked data-ewo-save="phone|name|notes" save on blur.
 */
function ew_optin_js() {
	return <<<'JS'
window.ewOptin = window.ewOptin || function(root, W, on){
 var o = {bid: 0, key: '', email: '', name: ''};
 var one = root.querySelector('[data-ewo-one]'), two = root.querySelector('[data-ewo-two]');
 var box = one.querySelector('[data-ewo-email]'), err = one.querySelector('[data-ewo-err1]'), btn = one.querySelector('[data-ewo-next]');
 var nbox = one.querySelector('[data-ewo-name]');
 var page = root.getAttribute('data-page');
 var memo = 'ewo:' + W.kind + ':' + page;
 function post(url, body){
  return fetch(url, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)})
   .then(function(r){ return r.json().then(function(j){ return {ok: r.ok, j: j}; }); });
 }
 function open(){
  one.hidden = true; two.hidden = false;
  if(on && on.open){ try{ on.open(o); }catch(e){} }
  var f = two.querySelector('input:not([type=checkbox]),select,textarea'); if(f){ try{ f.focus(); }catch(e){} }
 }
 try{ var kept = JSON.parse(sessionStorage.getItem(memo) || 'null'); if(kept && kept.bid){ o.bid = kept.bid; o.key = kept.key; o.email = kept.email; o.name = kept.name || ''; open(); } }catch(e){}
 function go(){
  err.textContent = '';
  var name = nbox ? nbox.value.trim() : '';
  if(nbox && !name){ err.textContent = W.need_name || W.need_email; return; }
  var email = box.value.trim();
  if(!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)){ err.textContent = W.need_email; return; }
  var body = {kind: W.kind, page: page, email: email};
  if(nbox) body.name = name;
  if(W.ref){ try{ var ref = new URLSearchParams(location.search).get('ref'); if(ref) body.ref = ref; }catch(e){} }
  btn.disabled = true;
  post(W.start, body).then(function(x){
   btn.disabled = false;
   if(!x.ok || !x.j.bid){ err.textContent = (x.j && x.j.message) || W.failed; return; }
   o.bid = x.j.bid; o.key = x.j.key; o.email = email; o.name = name;
   try{ sessionStorage.setItem(memo, JSON.stringify({bid: o.bid, key: o.key, email: o.email, name: o.name})); }catch(e){}
   if(window.ewTrack) ewTrack('Lead', {what: W.what});
   open();
  }).catch(function(){ btn.disabled = false; err.textContent = W.failed; });
 }
 btn.addEventListener('click', go);
 [box, nbox].forEach(function(b){ if(b) b.addEventListener('keydown', function(e){ if(e.key === 'Enter'){ e.preventDefault(); go(); } }); });
 two.addEventListener('focusout', function(e){
  var f = e.target.getAttribute && e.target.getAttribute('data-ewo-save');
  if(!f || !o.bid) return;
  post(W.save, {bid: o.bid, key: o.key, field: f, value: e.target.value}).catch(function(){});
 });
 o.done = function(){ try{ sessionStorage.removeItem(memo); }catch(e){} if(window.ewTrack) ewTrack('CompleteRegistration', {what: W.what}); };
 return o;
};
JS;
}

/* --- The last page: your place is in the app -------------------------- */

/**
 * Everything the phone on the last page shows, read live from the same
 * feed the app reads: the next game, the next home game, the last result
 * and where we sit in the league.
 */
function ew_inapp_live() {
	if ( ! function_exists( 'ew_fwp_fetch' ) ) {
		return array();
	}
	$fx  = ew_fwp_fetch( 'fixtures-results' );
	$all = (array) ( $fx['fixtures-results']['matches'] ?? array() );
	usort( $all, function ( $a, $b ) { return strcmp( (string) $a['date'] . (string) ( $a['time'] ?? '' ), (string) $b['date'] . (string) ( $b['time'] ?? '' ) ); } );
	$played = function_exists( 'ew_home_played' ) ? 'ew_home_played' : function ( $m ) { return false; };
	$next = null;
	$home = null;
	$last = null;
	foreach ( $all as $m ) {
		if ( call_user_func( $played, $m ) ) {
			$last = $m;
			continue;
		}
		if ( ! $next ) {
			$next = $m;
		}
		if ( ! $home && (int) ( $m['home-team']['id'] ?? 0 ) === (int) EW_TEAM ) {
			$home = $m;
		}
	}
	$pos = null;
	$tb  = ew_fwp_fetch( 'league-table' );
	foreach ( (array) ( $tb['league-table']['teams'] ?? array() ) as $t ) {
		if ( (int) ( $t['id'] ?? 0 ) === (int) EW_TEAM ) {
			$pos = $t;
		}
	}
	return array( 'next' => $next, 'home' => $home, 'last' => $last, 'pos' => $pos );
}

function ew_inapp_crest( $id ) {
	return function_exists( 'ew_home_badge' ) ? (string) ew_home_badge( (int) $id ) : '';
}

function ew_inapp_ordinal( $n ) {
	$n = (int) $n;
	$s = array( 'th', 'st', 'nd', 'rd' );
	$v = $n % 100;
	return $n . ( $s[ ( $v - 20 ) % 10 ] ?? $s[ $v ] ?? $s[0] );
}

/** The app's Home screen, small, from live data. */
function ew_inapp_phone() {
	$d    = ew_inapp_live();
	$base = plugins_url( 'assets/', dirname( __FILE__ ) . '/eastwood-fwp.php' );
	$h    = '<div class="ewi-phone"><div class="ewi-screen"><div class="ewi-app">';
	$h   .= '<div class="ewi-sb"><span>9:41</span><span>&#9679;&#9679;&#9679;</span></div>';
	$h   .= '<div class="ewi-tb"><img src="' . esc_url( ew_inapp_crest( EW_TEAM ) ) . '" alt=""><b>Eastwood FC</b></div>';
	$h   .= '<div class="ewi-strip"><i></i><b>Notifications are off</b><em></em></div>';
	$h   .= '<div class="ewi-body">';
	$n    = $d['next'] ?? null;
	if ( $n ) {
		$ts = strtotime( (string) $n['date'] . ' ' . (string) ( $n['time'] ?? '15:00' ) );
		$at = (int) ( $n['home-team']['id'] ?? 0 ) === (int) EW_TEAM ? 'Home' : 'Away';
		$h .= '<div class="ewi-next" style="background-image:url(' . esc_url( $base . 'banner/scarf.jpg' ) . ')"><div>';
		$h .= '<p class="ewi-when">' . esc_html( strtoupper( date_i18n( 'D j M', $ts ) ) ) . ' <span>' . esc_html( (string) ( $n['time'] ?? '' ) ) . '</span></p>';
		$h .= '<p class="ewi-comp">' . esc_html( (string) ( $n['competition']['name'] ?? '' ) ) . '</p>';
		$h .= '<p class="ewi-where">' . esc_html( strtoupper( $at . '  ' . (string) ( $n['venue'] ?? '' ) ) ) . '</p>';
		$h .= '<div class="ewi-tie">';
		foreach ( array( 'home-team', 'away-team' ) as $i => $side ) {
			if ( 1 === $i ) {
				$h .= '<b>V</b>';
			}
			$h .= '<span><img src="' . esc_url( ew_inapp_crest( $n[ $side ]['id'] ?? 0 ) ) . '" alt="">' . esc_html( (string) ( $n[ $side ]['name'] ?? '' ) ) . '</span>';
		}
		$h .= '</div></div></div>';
	}
	$hm = $d['home'] ?? null;
	if ( $hm ) {
		$ts   = strtotime( (string) $hm['date'] . ' ' . (string) ( $hm['time'] ?? '15:00' ) );
		$h   .= '<div class="ewi-tix" style="background-image:url(' . esc_url( $base . 'claim/ticket-bg.jpg' ) . ')"><div>';
		$h   .= '<p class="ewi-k">Next home game</p>';
		$h   .= '<p class="ewi-t">' . esc_html( strtoupper( 'Eastwood FC v ' . (string) ( $hm['away-team']['name'] ?? '' ) ) ) . '</p>';
		$h   .= '<p class="ewi-s">' . esc_html( strtoupper( date_i18n( 'D j M', $ts ) ) . ' · ' . (string) ( $hm['time'] ?? '' ) . ' · ' . (string) ( $hm['venue'] ?? '' ) ) . '</p>';
		$h   .= '<p class="ewi-r"><b>In the app</b><span>Show tickets</span></p></div></div>';
	}
	$l = $d['last'] ?? null;
	$p = $d['pos'] ?? null;
	if ( $l || $p ) {
		$h .= '<div class="ewi-mini">';
		if ( $l ) {
			$us = (int) ( $l['home-team']['id'] ?? 0 ) === (int) EW_TEAM;
			$h .= '<span><i>Last result</i><b>' . esc_html( (int) ( $us ? $l['home-team']['score'] : $l['away-team']['score'] ) . '–' . (int) ( $us ? $l['away-team']['score'] : $l['home-team']['score'] ) ) . '</b><em>' . esc_html( ( $us ? 'v ' : 'at ' ) . (string) ( $us ? $l['away-team']['name'] : $l['home-team']['name'] ) ) . '</em></span>';
		}
		if ( $p ) {
			$h .= '<span><i>League</i><b>' . esc_html( ew_inapp_ordinal( $p['position'] ?? 0 ) ) . '</b><em>' . esc_html( (int) ( $p['total-points'] ?? 0 ) . ' pts · ' . (int) ( $p['all-matches']['played'] ?? 0 ) . ' pld' ) . '</em></span>';
		}
		$h .= '</div>';
	}
	$h .= '<p class="ewi-news">Eastwood News</p></div></div></div></div>';
	return $h;
}

/**
 * The ticket that slides out of the phone: the real one for a game, or a
 * place for an event. $t: title, sub, day, date, time.
 */
function ew_inapp_ticket( $t ) {
	$base = plugins_url( 'assets/', dirname( __FILE__ ) . '/eastwood-fwp.php' );
	$h    = '<div class="ewi-ticket"><div class="ewi-tk" style="background-image:url(' . esc_url( $base . 'claim/ticket-bg.jpg' ) . ')"><div>';
	$h   .= '<img class="ewi-tkc" src="' . esc_url( ew_inapp_crest( EW_TEAM ) ) . '" alt="">';
	$h   .= '<p class="ewi-tkt">' . esc_html( (string) $t['title'] ) . '</p>';
	if ( '' !== (string) ( $t['sub'] ?? '' ) ) {
		$h .= '<p class="ewi-tkv">' . esc_html( (string) $t['sub'] ) . '</p>';
	}
	$h .= '<p class="ewi-tkd">' . esc_html( (string) ( $t['day'] ?? '' ) ) . '</p>';
	$h .= '<p class="ewi-tkw"><span>' . esc_html( (string) ( $t['date'] ?? '' ) ) . '</span><b>' . esc_html( (string) ( $t['time'] ?? '' ) ) . '</b></p>';
	$h .= '</div></div><div class="ewi-stub"><b>' . esc_html( (string) ( $t['stub'] ?? '' ) ) . '</b><span>' . esc_html( (string) ( $t['stub2'] ?? '' ) ) . '</span></div></div>';
	return $h;
}

/** The store buttons, the same addresses as eastwoodfc.com/app. */
function ew_inapp_stores( $claim = '' ) {
	$d   = function_exists( 'ew_app_defaults' ) ? ew_app_defaults() : array( 'ios_url' => '', 'android_url' => '' );
	$app = home_url( '/app/' );
	if ( '' !== $claim ) {
		$app = add_query_arg( 'claim', rawurlencode( $claim ), $app );
	}
	$apple = '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path fill="currentColor" d="M16.4 12.6c0-2.4 2-3.6 2.1-3.7-1.1-1.7-2.9-1.9-3.5-1.9-1.5-.2-2.9.9-3.7.9-.8 0-1.9-.9-3.2-.8-1.6 0-3.1 1-4 2.4-1.7 3-.4 7.4 1.2 9.8.8 1.2 1.8 2.5 3 2.4 1.2 0 1.7-.8 3.1-.8 1.5 0 1.9.8 3.2.8 1.3 0 2.1-1.2 2.9-2.4.9-1.4 1.3-2.7 1.3-2.8 0 0-2.5-1-2.4-3.9zM14 5.3c.7-.8 1.1-1.9 1-3-1 0-2.1.7-2.8 1.5-.6.7-1.2 1.8-1 2.9 1.1.1 2.1-.6 2.8-1.4z"/></svg>';
	$play  = '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path d="M3.6 2.3 13.4 12 3.6 21.7c-.3-.2-.5-.6-.5-1V3.3c0-.4.2-.8.5-1z" fill="#2196F3"/><path d="M16.8 8.6 13.4 12 3.6 2.3c.3-.2.8-.2 1.2 0z" fill="#4CAF50"/><path d="M16.8 15.4 4.8 21.7c-.4.2-.9.2-1.2 0L13.4 12z" fill="#F44336"/><path d="M20.4 10.6c.9.5.9 2.2 0 2.8l-3.6 2-3.4-3.4 3.4-3.4z" fill="#FFC107"/></svg>';
	$h     = '<div class="ewi-stores">';
	$h    .= '<a class="ewi-store" data-ewi-store="ios" href="' . esc_url( $app ) . '">' . $apple . '<span><i>Download on the</i><b>App Store</b></span></a>';
	$h    .= '<a class="ewi-store" data-ewi-store="android" href="' . esc_url( $app ) . '">' . $play . '<span><i>Get it on</i><b>Google Play</b></span></a>';
	return $h . '</div>';
}

/**
 * The whole last page. The ticket and claim are filled in by the page once
 * the sign-up is saved. $after: the block's own markup under the store buttons.
 */
function ew_inapp_panel( $heading, $ticket, $after = '' ) {
	$h  = '<div class="ewi" data-ewi hidden>';
	$h .= '<p class="ewi-h">' . esc_html( $heading ) . '</p>';
	$h .= '<div class="ewi-art">' . ew_inapp_phone() . ew_inapp_ticket( $ticket ) . '</div>';
	$h .= ew_inapp_stores();
	return $h . $after . '</div>';
}

function ew_inapp_css() {
	return '
.ewi{background:#111;color:#fff;border-radius:14px;padding:30px 26px 30px;font-family:"Instrument Sans",system-ui,sans-serif}
.ewi *{box-sizing:border-box}
.ewi p{margin:0}
.ewi .ewi-h{font-family:Anton,Impact,sans-serif;font-size:34px;line-height:1.02;text-transform:uppercase;color:#fff}
.ewi-art{position:relative;height:345px;width:300px;margin:18px auto 14px}
.ewi-phone{position:absolute;left:0;top:4px;width:172px;height:330px;border-radius:30px;background:#1b1b1b;border:4px solid #3a3a3a;padding:6px;box-shadow:0 22px 50px rgba(0,0,0,.6)}
.ewi-screen{width:100%;height:100%;border-radius:22px;overflow:hidden;background:#F3F3F3}
.ewi-app{width:390px;height:844px;transform:scale(.41);transform-origin:top left;background:#F3F3F3;color:#121417}
.ewi-sb{background:#CC0000;color:#fff;height:47px;display:flex;align-items:flex-end;justify-content:space-between;padding:0 28px 6px;font-size:15px;font-weight:600}
.ewi-tb{background:#CC0000;display:flex;align-items:center;gap:10px;padding:2px 16px 12px}
.ewi-tb img{width:38px;height:38px}.ewi-tb b{font-family:Anton,Impact,sans-serif;font-weight:400;font-size:22px;color:#fff;text-transform:uppercase}
.ewi-strip{background:#CC0000;border-top:1px solid rgba(255,255,255,.18);display:flex;align-items:center;gap:12px;padding:10px 16px}
.ewi-strip i{width:36px;height:36px;border-radius:18px;background:#fff}.ewi-strip b{flex:1;font-family:Anton,Impact,sans-serif;font-weight:400;font-size:18px;color:#fff;text-transform:uppercase}
.ewi-strip em{width:50px;height:30px;border-radius:15px;background:rgba(255,255,255,.3)}
.ewi-body{padding:16px;display:flex;flex-direction:column;gap:12px}
.ewi-next{border-radius:6px;overflow:hidden;background:#111 center/cover;color:#fff}
.ewi-next>div{background:rgba(0,0,0,.62);padding:12px 16px 14px}
.ewi-when{font-family:Anton,Impact,sans-serif;font-size:15px}.ewi-when span{color:#CC0000}
.ewi-comp{font-size:12px;color:rgba(255,255,255,.75);margin-top:3px!important}
.ewi-where{font-size:12px;font-weight:600;letter-spacing:1px;color:rgba(255,255,255,.6);margin-top:8px!important}
.ewi-tie{display:flex;align-items:center;justify-content:space-around;margin-top:8px}
.ewi-tie span{display:flex;flex-direction:column;align-items:center;font-size:12px;font-weight:600}.ewi-tie img{width:44px;height:44px;object-fit:contain}
.ewi-tie b{font-family:Anton,Impact,sans-serif;font-weight:400;font-size:22px}
.ewi-tix{background:#000 center/cover;color:#fff}
.ewi-tix>div{background:rgba(0,0,0,.55);padding:16px 18px}
.ewi-k{font-family:Anton,Impact,sans-serif;font-size:13px;letter-spacing:1px;color:#CC0000;text-transform:uppercase}
.ewi-t{font-family:Anton,Impact,sans-serif;font-size:24px;line-height:30px;margin-top:4px!important}
.ewi-s{font-size:13px;color:rgba(255,255,255,.6);margin-top:4px!important}
.ewi-r{display:flex;align-items:center;justify-content:space-between;margin-top:10px!important}.ewi-r b{font-family:Anton,Impact,sans-serif;font-weight:400;font-size:21px;text-transform:uppercase}
.ewi-r span{height:40px;padding:0 14px;border-radius:6px;background:#CC0000;display:flex;align-items:center;font-family:Anton,Impact,sans-serif;font-size:15px;text-transform:uppercase}
.ewi-mini{display:flex;gap:10px}.ewi-mini span{flex:1;background:#fff;border:1px solid #E4E5E8;border-radius:6px;padding:12px;display:flex;flex-direction:column}
.ewi-mini i{font-style:normal;font-size:11px;font-weight:600;letter-spacing:1px;color:#8B9099;text-transform:uppercase}
.ewi-mini b{font-family:Anton,Impact,sans-serif;font-weight:400;font-size:28px;line-height:36px}.ewi-mini em{font-style:normal;font-size:13px;color:#565C64}
.ewi-news{font-family:Anton,Impact,sans-serif;font-size:26px;color:#CC0000;text-align:center;text-transform:uppercase}
.ewi-ticket{position:absolute;left:140px;top:139px;width:270px;border-radius:14px;overflow:hidden;background:#111;color:#fff;box-shadow:0 20px 44px rgba(0,0,0,.45);transform:scale(.6) rotate(12deg);transform-origin:top left;z-index:2}
.ewi-tk{background:#111 center/cover}.ewi-tk>div{position:relative;background:rgba(0,0,0,.45);padding:16px 16px 14px}
.ewi-tkc{position:absolute;right:16px;top:16px;width:40px;height:40px}
.ewi-tkt{font-family:Anton,Impact,sans-serif;font-size:27px;line-height:30px;text-transform:uppercase;max-width:200px}
.ewi-tkv{font-size:13px;color:rgba(255,255,255,.85);margin-top:4px!important;max-width:200px}
.ewi-tkd{font-weight:700;font-size:11px;letter-spacing:4px;color:#CC0000;margin-top:12px!important;text-transform:uppercase}
.ewi-tkw{display:flex;justify-content:space-between;align-items:flex-end}.ewi-tkw span{font-size:14px}.ewi-tkw b{font-family:Anton,Impact,sans-serif;font-weight:400;font-size:26px;line-height:28px}
.ewi-stub{border-top:3px dashed #E4E5E8;padding:10px 16px 12px;text-align:center}.ewi-stub b{display:block;font-family:Anton,Impact,sans-serif;font-weight:400;font-size:30px;line-height:34px}.ewi-stub span{font-family:Anton,Impact,sans-serif;font-size:15px;color:#CC0000}
.ewi-stores{display:flex;gap:10px}
.ewi .ewi-store{flex:1;height:54px;border-radius:10px;background:#000;border:1px solid rgba(255,255,255,.35);display:flex;align-items:center;gap:10px;padding:0 12px;color:#fff;text-decoration:none}
.ewi-store span{display:flex;flex-direction:column;min-width:0}.ewi-store i{font-style:normal;font-size:10px;color:rgba(255,255,255,.75);white-space:nowrap}.ewi-store b{font-size:15px;line-height:1.1;white-space:nowrap}
.ewi .ewi-store{padding:0 10px;gap:8px}
@media (max-width:380px){.ewi-art{transform:scale(.92);transform-origin:top center}}
';
}

/** Fills in the ticket and the private link, and shows the panel. */
function ew_inapp_js() {
	return <<<'JS'
window.ewInapp = window.ewInapp || function(root, claim){
 var p = root.querySelector('[data-ewi]'); if(!p) return;
 if(claim){
  p.querySelectorAll('[data-ewi-store]').forEach(function(a){
   try{ var u = new URL(a.href); u.searchParams.set('claim', claim); a.href = u.toString(); }catch(e){}
  });
 }
 p.hidden = false;
 try{ p.scrollIntoView({behavior:'smooth', block:'start'}); }catch(e){}
};
JS;
}
