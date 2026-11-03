<?php
/**
 * [eastwood_academy_team] The academy team's own page: invite a family to a 1:1.
 *
 * Who gets in: only the email addresses typed into the block's "allow" field.
 * They type their email, the site emails them a one-time link (30 minutes),
 * and that link signs this one browser in for a set number of days (kept in
 * the browser and sent with each call, never in the page or its address).
 *
 * What they get: this page and nothing else. Signing in here makes no
 * WordPress account and gives no wp-admin access; the sign-in is a separate
 * signed pass that only this page's own calls accept, and it is checked
 * against the allow list on every call, so taking an address off the list
 * shuts that person out at once. They can see families waiting for an invite
 * and families already invited, send an invite, and add a family. They cannot
 * edit or delete bookings, see payments, or open anything else on the site.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ew_act_defaults() {
	return array(
		'allow'           => '',
		'days'            => '30',
		'interest_page'   => '/academy/register-interest/',
		'meeting_page'    => '/academy/meeting/',
		'from'            => 'academy@eastwoodfc.com',
		'signin_title'    => 'Academy team',
		'signin_note'     => 'Type your email and we will send you a link to sign in.',
		'email_label'     => 'Your email',
		'signin_btn'      => 'Send me a link',
		'sent_note'       => 'If that email is on the academy team list, a sign-in link is on its way. It works for 30 minutes.',
		'link_subject'    => 'Your sign-in link for the Eastwood FC Academy team page',
		'link_body'       => "Hi,\n\nUse this link to sign in to the academy team page:\n{link}\n\nIt works once, in the next 30 minutes. After that you stay signed in on that device for {days} days.\n\nThank you,\nEastwood FC Academy",
		'bad_link'        => 'That sign-in link has expired or has been used. Ask for a new one below.',
		'signed_in'       => 'Signed in as {email}',
		'signout'         => 'Sign out',
		'form_title'      => 'Invite a family',
		'form_note'       => 'They get the same 1:1 invite email and their own link to pick a time.',
		'pick_label'      => 'Pick a family who has registered',
		'pick_first'      => 'Choose a family',
		'pick_none'       => 'Nobody is waiting for an invite right now.',
		'k_registered'    => 'Registered',
		'k_from'          => 'From',
		'k_born'          => 'Born',
		'k_club'          => 'Club',
		'k_email'         => 'Email',
		'k_mobile'        => 'Mobile',
		'send'            => 'Send the invite',
		'not_listed'      => 'Not on the list?',
		'add_btn'         => 'Add a family',
		'parent_label'    => 'Parent or guardian name',
		'player_label'    => "Player's full name",
		'pemail_label'    => "Parent's email",
		'pphone_label'    => "Parent's mobile",
		'add_send'        => 'Add and send the invite',
		'sent'            => 'Invite sent to {email}.',
		'need_player'     => 'We need the player name.',
		'need_email'      => 'We need the parent email.',
		'failed'          => 'Something went wrong. Please try again.',
		'list_title'      => 'Invited so far',
		'col_player'      => 'Player',
		'col_parent'      => 'Parent',
		'col_invited'     => 'Invited',
		'col_meeting'     => '1:1',
		'not_yet'         => 'Not booked yet',
		'again'           => 'Send again',
		'list_none'       => 'Nobody invited yet.',
		'free'            => 'Times still free: {n} of {total}',
		'loading'         => 'Loading',
	);
}

/* ---------- who is allowed, and the signed-in cookie ---------- */

function ew_act_allowed( $a ) {
	$out = array();
	foreach ( preg_split( '/[\s,;]+/', (string) $a['allow'] ) as $e ) {
		$e = strtolower( sanitize_email( $e ) );
		if ( is_email( $e ) ) {
			$out[] = $e;
		}
	}
	return $out;
}

function ew_act_sig( $email, $exp ) {
	return hash_hmac( 'sha256', 'acteam|' . $email . '|' . $exp, wp_salt( 'auth' ) );
}

/** The signed-in email for this request, or '' (checked against the list every time). */
function ew_act_me( $a ) {
	// The browser keeps it and sends it as a header: the site's cache in front strips cookies it does not know.
	$raw = isset( $_SERVER['HTTP_X_EW_TEAM'] ) ? (string) wp_unslash( $_SERVER['HTTP_X_EW_TEAM'] ) : '';
	if ( '' === $raw && isset( $_COOKIE['ew_acteam'] ) ) {
		$raw = (string) wp_unslash( $_COOKIE['ew_acteam'] );
	}
	$p   = explode( '|', base64_decode( $raw, true ) ?: '' );
	if ( 3 !== count( $p ) ) {
		return '';
	}
	list( $email, $exp, $sig ) = $p;
	if ( (int) $exp < time() || ! hash_equals( ew_act_sig( $email, $exp ), $sig ) ) {
		return '';
	}
	return in_array( strtolower( $email ), ew_act_allowed( $a ), true ) ? strtolower( $email ) : '';
}

function ew_act_pass( $email, $days ) {
	$exp = time() + max( 1, (int) $days ) * DAY_IN_SECONDS;
	return base64_encode( $email . '|' . $exp . '|' . ew_act_sig( $email, $exp ) );
}

/** The block and its words, read from the page itself. */
function ew_act_block( $page_id ) {
	$post = get_post( (int) $page_id );
	$blk  = ( $post && function_exists( 'ew_acad_block_on' ) ) ? ew_acad_block_on( $post, 'eastwood_academy_team' ) : null;
	if ( ! $blk ) {
		return null;
	}
	return array( $post, shortcode_atts( ew_act_defaults(), $blk['atts'], 'eastwood_academy_team' ) );
}

function ew_act_page_id( $path ) {
	$path = trim( (string) $path );
	if ( ctype_digit( $path ) ) {
		return (int) $path;
	}
	$p = get_page_by_path( trim( wp_parse_url( $path, PHP_URL_PATH ) ?: $path, '/' ) );
	return $p ? (int) $p->ID : 0;
}

/* ---------- the one-time link ---------- */

add_action( 'template_redirect', function () {
	if ( empty( $_GET['ewat'] ) || ! is_singular() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	nocache_headers();
	$post = get_post();
	$b    = $post ? ew_act_block( $post->ID ) : null;
	if ( ! $b ) {
		return;
	}
	$tok   = preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_GET['ewat'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$key   = 'ew_act_' . hash( 'sha256', $tok );
	$email = (string) get_transient( $key );
	delete_transient( $key );
	$url = get_permalink( $post );
	if ( '' !== $email && in_array( $email, ew_act_allowed( $b[1] ), true ) ) {
		// After the #, so it never reaches the server, the cache or its logs.
		$url .= '#ewts=' . rawurlencode( ew_act_pass( $email, $b[1]['days'] ) );
	} else {
		$url = add_query_arg( 'ewat_bad', 1, $url );
	}
	wp_safe_redirect( $url );
	exit;
} );

/* ---------- what the page shows ---------- */

function ew_act_registered() {
	$q = new WP_Query( array(
		'post_type'      => 'ewt_booking',
		'post_status'    => 'publish',
		'posts_per_page' => 300,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'meta_query'     => array( array( 'key' => '_ewt_status', 'value' => array( 'interest', 'trial', 'invited', 'meeting' ), 'compare' => 'IN' ) ),
	) );
	return $q->posts;
}

function ew_act_parent( $id ) {
	$p = ew_acad_m( $id, 'parent' );
	return '' !== $p ? $p : ew_acad_m( $id, 'email' );
}

function ew_act_data( $a ) {
	$waiting = array();
	$invited = array();
	$seen    = array();
	foreach ( ew_act_registered() as $id ) {
		$st    = ew_acad_m( $id, 'status' );
		$email = strtolower( ew_acad_m( $id, 'email' ) );
		$name  = ew_acad_m( $id, 'name' );
		if ( '' === $name ) {
			$name = get_the_title( $id );
		}
		if ( in_array( $st, array( 'invited', 'meeting' ), true ) ) {
			$seen[ $email . '|' . strtolower( $name ) ] = true;
			$inv = (int) get_post_meta( $id, '_ewt_invited', true );
			$invited[] = array(
				'id'      => $id,
				'player'  => $name,
				'parent'  => ew_act_parent( $id ),
				'invited' => $inv ? wp_date( 'j M', $inv ) : '',
				'meeting' => 'meeting' === $st ? ew_acad_m( $id, 'slot_label' ) : '',
			);
			continue;
		}
		$k = $email . '|' . strtolower( $name );
		if ( isset( $seen[ $k ] ) || '' === $email ) {
			continue; // already invited under another entry, or nobody to email
		}
		$seen[ $k ] = true;
		$dob = ew_acad_m( $id, 'dob' );
		$waiting[] = array(
			'id'      => $id,
			'label'   => $name . ' · ' . ew_act_parent( $id ),
			'rows'    => array_values( array_filter( array(
				array( $a['k_registered'], get_the_date( 'j M Y', $id ) ),
				array( $a['k_from'], ew_acad_m( $id, 'event' ) ),
				'' !== $dob ? array( $a['k_born'], $dob ) : null,
				'' !== ew_acad_m( $id, 'club' ) ? array( $a['k_club'], ew_acad_m( $id, 'club' ) ) : null,
				array( $a['k_email'], ew_acad_m( $id, 'email' ) ),
				'' !== ew_acad_m( $id, 'phone' ) ? array( $a['k_mobile'], ew_acad_m( $id, 'phone' ) ) : null,
			) ) ),
		);
	}
	$total = 0;
	$free  = 0;
	$mid   = ew_act_page_id( $a['meeting_page'] );
	$mpost = $mid ? get_post( $mid ) : null;
	$mblk  = $mpost ? ew_acad_block_on( $mpost, 'eastwood_meeting' ) : null;
	if ( $mblk ) {
		$taken = ew_acad_taken( $mid );
		$now   = current_time( 'Y-m-d H:i' );
		foreach ( ew_acad_slots( $mblk['body'] ) as $k => $label ) {
			$total++;
			if ( $k > $now && ! isset( $taken[ $k ] ) ) {
				$free++;
			}
		}
	}
	return array( 'waiting' => $waiting, 'invited' => $invited, 'free' => ew_acad_fill( $a['free'], array( 'n' => $free, 'total' => $total ) ) );
}

/** Send the invite, from the address the block names. */
function ew_act_invite( $id, $a ) {
	if ( 'ewt_booking' !== get_post_type( $id ) ) {
		return false;
	}
	if ( '' === ew_acad_m( $id, 'page' ) || ! ew_acad_block_on( get_post( (int) ew_acad_m( $id, 'page' ) ), 'eastwood_interest' ) ) {
		// A trial sign-up or a family added here: its invite uses the Register your interest page's words.
		$ip = ew_act_page_id( $a['interest_page'] );
		if ( $ip ) {
			update_post_meta( $id, '_ewt_page', (string) $ip );
		}
	}
	return ew_acad_invite( $id );
}

/* ---------- REST: every call checks the cookie against the list ---------- */

add_action( 'rest_api_init', function () {
	$guard = function ( $req ) {
		$b = ew_act_block( $req->get_param( 'page' ) );
		if ( ! $b ) {
			return array( null, null, '' );
		}
		return array( $b[0], $b[1], ew_act_me( $b[1] ) );
	};
	register_rest_route( 'eastwood/v1', '/academy/team/link', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) use ( $guard ) {
			list( $post, $a ) = $guard( $req );
			if ( ! $post ) {
				return new WP_REST_Response( array( 'message' => 'no' ), 400 );
			}
			$email = strtolower( sanitize_email( (string) $req->get_param( 'email' ) ) );
			// The same answer whether or not the address is on the list.
			if ( in_array( $email, ew_act_allowed( $a ), true ) && ! get_transient( 'ew_act_wait_' . md5( $email ) ) ) {
				set_transient( 'ew_act_wait_' . md5( $email ), 1, 60 );
				$tok = wp_generate_password( 32, false, false );
				set_transient( 'ew_act_' . hash( 'sha256', $tok ), $email, 30 * MINUTE_IN_SECONDS );
				$link = add_query_arg( 'ewat', $tok, get_permalink( $post ) );
				$from = function_exists( 'ew_acad_from' ) ? ew_acad_from( array( 'from' => $a['from'] ) ) : 'info@eastwoodfc.com';
				wp_mail( $email, $a['link_subject'], ew_acad_fill( str_replace( '\n', "\n", (string) $a['link_body'] ), array( 'link' => $link, 'days' => (int) $a['days'] ) ), array( 'From: Eastwood FC Academy <' . $from . '>' ) );
			}
			return array( 'ok' => 1, 'message' => $a['sent_note'] );
		},
	) );
	register_rest_route( 'eastwood/v1', '/academy/team/data', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) use ( $guard ) {
			nocache_headers();
			list( $post, $a, $me ) = $guard( $req );
			if ( ! $post || '' === $me ) {
				return array( 'ok' => 0 );
			}
			return array_merge( array( 'ok' => 1, 'me' => ew_acad_fill( $a['signed_in'], array( 'email' => $me ) ) ), ew_act_data( $a ) );
		},
	) );
	register_rest_route( 'eastwood/v1', '/academy/team/invite', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) use ( $guard ) {
			list( $post, $a, $me ) = $guard( $req );
			if ( ! $post || '' === $me ) {
				return new WP_REST_Response( array( 'message' => 'Sign in again.' ), 403 );
			}
			$id = (int) $req->get_param( 'id' );
			if ( ! in_array( ew_acad_m( $id, 'status' ), array( 'interest', 'trial', 'invited' ), true ) ) {
				return new WP_REST_Response( array( 'message' => $a['failed'] ), 400 );
			}
			$ok = ew_act_invite( $id, $a );
			update_post_meta( $id, '_ewt_invited_by', $me );
			return $ok ? array( 'ok' => 1, 'message' => ew_acad_fill( $a['sent'], array( 'email' => ew_acad_m( $id, 'email' ) ) ) ) : new WP_REST_Response( array( 'message' => $a['failed'] ), 500 );
		},
	) );
	register_rest_route( 'eastwood/v1', '/academy/team/add', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) use ( $guard ) {
			list( $post, $a, $me ) = $guard( $req );
			if ( ! $post || '' === $me ) {
				return new WP_REST_Response( array( 'message' => 'Sign in again.' ), 403 );
			}
			$player = function_exists( 'ew_person_name' ) ? ew_person_name( sanitize_text_field( (string) $req->get_param( 'player' ) ) ) : sanitize_text_field( (string) $req->get_param( 'player' ) );
			$parent = function_exists( 'ew_person_name' ) ? ew_person_name( sanitize_text_field( (string) $req->get_param( 'parent' ) ) ) : sanitize_text_field( (string) $req->get_param( 'parent' ) );
			$email  = sanitize_email( (string) $req->get_param( 'email' ) );
			$phone  = sanitize_text_field( (string) $req->get_param( 'phone' ) );
			if ( '' === $player ) {
				return new WP_REST_Response( array( 'message' => $a['need_player'] ), 400 );
			}
			if ( ! is_email( $email ) ) {
				return new WP_REST_Response( array( 'message' => $a['need_email'] ), 400 );
			}
			$ip = ew_act_page_id( $a['interest_page'] );
			$id = ew_acad_booking( $player, array(
				'name'     => $player,
				'parent'   => $parent,
				'guardian' => $parent,
				'email'    => $email,
				'phone'    => $phone,
				'event'    => 'Academy interest',
				'page'     => $ip,
				'url'      => $ip ? get_permalink( $ip ) : '',
				'lines'    => $player,
				'extra'    => 'Academy interest: added by the academy team',
				'total'    => 0,
				'added_by' => $me,
				'status'   => 'interest',
			) );
			if ( ! $id ) {
				return new WP_REST_Response( array( 'message' => $a['failed'] ), 500 );
			}
			$ok = ew_act_invite( $id, $a );
			return $ok ? array( 'ok' => 1, 'message' => ew_acad_fill( $a['sent'], array( 'email' => $email ) ) ) : new WP_REST_Response( array( 'message' => $a['failed'] ), 500 );
		},
	) );
	register_rest_route( 'eastwood/v1', '/academy/team/signout', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function () {
			return array( 'ok' => 1 );
		},
	) );
} );

/* ---------- the block ---------- */

function ew_act_shortcode( $atts ) {
	$a    = shortcode_atts( ew_act_defaults(), (array) $atts, 'eastwood_academy_team' );
	$post = get_post();
	$keys = array( 'signin_title', 'signin_note', 'email_label', 'signin_btn', 'sent_note', 'bad_link', 'signout', 'form_title', 'form_note', 'pick_label', 'pick_first', 'pick_none', 'send', 'not_listed', 'add_btn', 'parent_label', 'player_label', 'pemail_label', 'pphone_label', 'add_send', 'failed', 'list_title', 'col_player', 'col_parent', 'col_invited', 'col_meeting', 'not_yet', 'again', 'list_none', 'loading' );
	$w    = array_intersect_key( $a, array_flip( $keys ) );
	$w['rest'] = esc_url_raw( rest_url( 'eastwood/v1/academy/team/' ) );
	// Nothing personal is in the HTML: it is the same for everyone, and the list is fetched after sign-in.
	return '<section class="ewtr ewact" data-page="' . (int) ( $post ? $post->ID : 0 ) . '"><div class="ewact-root"><p class="ewact-msg">' . esc_html( $a['loading'] ) . '</p></div>'
		. '<script type="application/json" class="ewact-words">' . wp_json_encode( $w ) . '</script></section>';
}
add_shortcode( 'eastwood_academy_team', 'ew_act_shortcode' );

add_filter( 'ew_blocks_allowed', function ( $list ) {
	return array_merge( (array) $list, array( 'eastwood_academy_team' ) );
} );

add_action( 'wp_head', function () {
	$post = is_singular() ? get_post() : null;
	if ( $post && function_exists( 'ew_post_contains' ) && ew_post_contains( $post, 'eastwood_academy_team' ) ) {
		echo '<meta name="robots" content="noindex,nofollow">' . "\n";
	}
}, 1 );

add_action( 'wp_enqueue_scripts', function () {
	$post = is_singular() ? get_post() : null;
	if ( ! $post || ! function_exists( 'ew_post_contains' ) || ! ew_post_contains( $post, 'eastwood_academy_team' ) ) {
		return;
	}
	wp_enqueue_style( 'ewc-fonts', 'https://fonts.googleapis.com/css2?family=Anton&family=Instrument+Sans:wght@400;600;700&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
	wp_register_style( 'ew-acteam', false, array(), EW_FWP_VERSION );
	wp_enqueue_style( 'ew-acteam' );
	wp_add_inline_style( 'ew-acteam', ( function_exists( 'ew_trial_css' ) ? ew_trial_css() : '' ) . ew_act_css() );
	wp_register_script( 'ew-acteam', '', array(), EW_FWP_VERSION, true );
	wp_enqueue_script( 'ew-acteam' );
	wp_add_inline_script( 'ew-acteam', ew_act_js() );
}, 22 );

function ew_act_css() {
	return '
.ewact{display:grid;grid-template-columns:minmax(0,1fr) 420px;gap:56px;align-items:start}
.ewact .ewact-root{display:contents}
.ewact .ewact-msg{margin:0;font-size:16px;color:#555}
.ewact .ewact-list{background:#fff;border-radius:10px;padding:8px 24px 4px}
.ewact .ewact-lh{margin:0 0 10px;font-family:Anton,Impact,sans-serif;font-size:30px;text-transform:uppercase;color:#101010}
.ewact .ewact-tr{display:grid;grid-template-columns:2fr 2fr 1fr 2fr 110px;gap:14px;align-items:center;min-height:54px;border-top:1px solid #ececee;font-size:16px}
.ewact .ewact-tr.h{border-top:0;min-height:42px;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#8a8a90}
.ewact .ewact-again{background:none;border:0;padding:0;font:inherit;font-weight:700;color:#101010;cursor:pointer;text-align:left}
.ewact .ewact-free{margin:18px 0 0;font-size:16px;color:#555}
.ewact .ewact-me{margin:0 0 14px;font-size:14px;color:#555}
.ewact .ewact-me button{background:none;border:0;padding:0;margin-left:8px;font:inherit;text-decoration:underline;cursor:pointer;color:#101010}
.ewact select.ewtr-in{appearance:auto}
.ewact .ewact-det{margin:12px 0 0;border:1px solid rgba(255,255,255,.14);border-radius:8px;padding:12px 14px;font-size:14px;line-height:1.6;color:#d6d6da}
.ewact .ewact-det span{color:#9a9aa3;margin-right:6px}
.ewact .ewact-or{height:1px;background:rgba(255,255,255,.14);margin:22px 0 14px}
.ewact .ewact-add{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;height:52px;border:1px solid rgba(255,255,255,.35);border-radius:8px;background:transparent;color:#fff;font:inherit;font-size:17px;font-weight:700;cursor:pointer}
.ewact .ewact-ok{margin:12px 0 0;font-size:15px;color:#fff}
.ewact .ewact-solo{grid-column:2}
@media (max-width:900px){.ewact{grid-template-columns:minmax(0,1fr);gap:28px}.ewact .ewact-side{order:-1}.ewact .ewact-solo{grid-column:1}.ewact .ewact-tr{grid-template-columns:1fr 1fr;row-gap:2px;padding:8px 0}.ewact .ewact-tr.h{display:none}}
';
}

function ew_act_js() {
	return <<<'JS'
(function(){
 var root = document.querySelector('.ewact'); if(!root) return;
 var W = JSON.parse(root.querySelector('.ewact-words').textContent), page = root.getAttribute('data-page');
 var box = root.querySelector('.ewact-root');
 function pass(){ try { return localStorage.getItem('ew_acteam') || ''; } catch(e){ return ''; } }
 var mh = location.hash.match(/ewts=([^&]+)/);
 if(mh){ try { localStorage.setItem('ew_acteam', decodeURIComponent(mh[1])); } catch(e){} history.replaceState(null, '', location.pathname); }
 function esc(s){ return String(s == null ? '' : s).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
 function call(path, body){
   var o = {credentials:'same-origin', headers:{'Content-Type':'application/json', 'X-EW-Team': pass()}};
   if(body){ o.method = 'POST'; o.body = JSON.stringify(Object.assign({page: page}, body)); }
   return fetch(W.rest + path + (body ? '' : '?page=' + page + '&t=' + Date.now()), o).then(function(r){ return r.json().then(function(j){ return {ok: r.ok, j: j}; }); });
 }
 function signin(){
   var bad = /[?&]ewat_bad=1/.test(location.search);
   box.innerHTML = '<div class="ewact-side ewact-solo"><form class="ewtr-form">'
     + '<p class="ewtr-head">' + esc(W.signin_title) + '</p><p class="ewtr-note">' + esc(bad ? W.bad_link : W.signin_note) + '</p>'
     + '<label class="ewtr-lab" for="ewactE">' + esc(W.email_label) + '</label><input class="ewtr-in" id="ewactE" type="email" autocomplete="email" required>'
     + '<button class="ewtr-btn" type="submit">' + esc(W.signin_btn) + '</button><p class="ewtr-err" role="alert"></p></form></div>';
   var f = box.querySelector('form');
   f.addEventListener('submit', function(e){
     e.preventDefault(); var b = f.querySelector('button'); b.disabled = true;
     call('link', {email: f.querySelector('#ewactE').value.trim()}).then(function(x){
       f.querySelector('.ewtr-note').textContent = (x.j && x.j.message) || W.failed; b.disabled = false;
     }).catch(function(){ b.disabled = false; f.querySelector('.ewtr-err').textContent = W.failed; });
   });
 }
 function input(id, lab, type){ return '<label class="ewtr-lab" for="' + id + '">' + esc(lab) + '</label><input class="ewtr-in" id="' + id + '" type="' + (type || 'text') + '">'; }
 function show(d){
   var rows = d.invited.length ? d.invited.map(function(r){
     return '<div class="ewact-tr"><span>' + esc(r.player) + '</span><span>' + esc(r.parent) + '</span><span>' + esc(r.invited) + '</span><span>' + esc(r.meeting || W.not_yet) + '</span>'
       + (r.meeting ? '<span></span>' : '<button type="button" class="ewact-again" data-id="' + r.id + '">' + esc(W.again) + '</button>') + '</div>';
   }).join('') : '<p class="ewact-msg" style="padding:14px 0">' + esc(W.list_none) + '</p>';
   var opts = d.waiting.map(function(r, i){ return '<option value="' + i + '">' + esc(r.label) + '</option>'; }).join('');
   box.innerHTML = '<div class="ewact-main"><p class="ewact-me">' + esc(d.me) + '<button type="button" class="ewact-out">' + esc(W.signout) + '</button></p>'
     + '<p class="ewact-lh">' + esc(W.list_title) + '</p><div class="ewact-list"><div class="ewact-tr h"><span>' + esc(W.col_player) + '</span><span>' + esc(W.col_parent) + '</span><span>' + esc(W.col_invited) + '</span><span>' + esc(W.col_meeting) + '</span><span></span></div>' + rows + '</div>'
     + '<p class="ewact-free">' + esc(d.free) + '</p></div>'
     + '<div class="ewact-side"><div class="ewtr-form"><p class="ewtr-head">' + esc(W.form_title) + '</p><p class="ewtr-note">' + esc(W.form_note) + '</p>'
     + (d.waiting.length
        ? '<label class="ewtr-lab" for="ewactPick">' + esc(W.pick_label) + '</label><select class="ewtr-in" id="ewactPick"><option value="">' + esc(W.pick_first) + '</option>' + opts + '</select><div class="ewact-det" hidden></div>'
          + '<button type="button" class="ewtr-btn ewact-send" style="margin-top:14px">' + esc(W.send) + '</button>'
        : '<p class="ewtr-note">' + esc(W.pick_none) + '</p>')
     + '<p class="ewtr-err" role="alert"></p><p class="ewact-ok" hidden></p>'
     + '<div class="ewact-or"></div><p class="ewtr-note" style="margin:0 0 10px">' + esc(W.not_listed) + '</p>'
     + '<button type="button" class="ewact-add"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>' + esc(W.add_btn) + '</button>'
     + '<div class="ewact-new" hidden>' + input('ewactP', W.parent_label) + input('ewactN', W.player_label) + input('ewactM', W.pemail_label, 'email') + input('ewactT', W.pphone_label, 'tel')
     + '<button type="button" class="ewtr-btn ewact-addsend" style="margin-top:16px">' + esc(W.add_send) + '</button></div></div></div>';
   var err = box.querySelector('.ewtr-err'), ok = box.querySelector('.ewact-ok');
   function done(x, b){
     if(b) b.disabled = false;
     if(!x.ok){ err.textContent = (x.j && x.j.message) || W.failed; return; }
     err.textContent = ''; load(x.j.message);
   }
   var pick = box.querySelector('#ewactPick'), det = box.querySelector('.ewact-det');
   if(pick) pick.addEventListener('change', function(){
     var r = d.waiting[pick.value];
     det.hidden = !r; if(r) det.innerHTML = r.rows.map(function(kv){ return '<div><span>' + esc(kv[0]) + '</span>' + esc(kv[1]) + '</div>'; }).join('');
   });
   var send = box.querySelector('.ewact-send');
   if(send) send.addEventListener('click', function(){
     var r = d.waiting[pick.value]; if(!r) return; send.disabled = true;
     call('invite', {id: r.id}).then(function(x){ done(x, send); }).catch(function(){ send.disabled = false; err.textContent = W.failed; });
   });
   box.querySelector('.ewact-add').addEventListener('click', function(){ box.querySelector('.ewact-new').hidden = false; this.hidden = true; });
   var as = box.querySelector('.ewact-addsend');
   as.addEventListener('click', function(){
     as.disabled = true;
     function v(id){ return box.querySelector('#' + id).value.trim(); }
     call('add', {parent: v('ewactP'), player: v('ewactN'), email: v('ewactM'), phone: v('ewactT')}).then(function(x){ done(x, as); }).catch(function(){ as.disabled = false; err.textContent = W.failed; });
   });
   Array.prototype.forEach.call(box.querySelectorAll('.ewact-again'), function(b){
     b.addEventListener('click', function(){ b.disabled = true; call('invite', {id: +b.getAttribute('data-id')}).then(function(x){ done(x, b); }); });
   });
   box.querySelector('.ewact-out').addEventListener('click', function(){ try { localStorage.removeItem('ew_acteam'); } catch(e){} call('signout', {}).then(signin); });
 }
 function load(msg){
   call('data').then(function(x){
     if(!x.j || !x.j.ok){ signin(); return; }
     show(x.j);
     if(msg){ var ok = box.querySelector('.ewact-ok'); ok.textContent = msg; ok.hidden = false; }
   }).catch(signin);
 }
 load();
})();
JS;
}
