<?php
/**
 * Eastwood Padel Club: its pages, its own address, and the waiting list.
 *
 * Padel lives inside the club site so the club has one system behind it:
 * one set of Bookings, one CRM, one app, one Stripe. Its pages are the
 * page "padel" and the pages under it (/padel/, /padel/<child>/).
 *
 * eastwoodpadelclub.com points at this same WordPress. When a request comes
 * in on that address:
 *   /          shows the page "padel"
 *   /<slug>/   shows the page "padel/<slug>"
 *   anything else is sent to the same path on eastwoodfc.com,
 * and links, the canonical address and the REST calls the forms make stay
 * on the padel address, so a visitor never sees eastwoodfc.com there.
 *
 * [eastwood_padel_list] is the waiting-list form: email first, saved the
 * moment the button is pressed, then name and mobile, then the place in
 * the app. It is a Booking with status "padel". Every word it shows is a
 * field on the block.
 *
 * The splash pages are Elementor containers carrying the class ewpd-splash
 * (ewpd-splash-b for the court picture), with each piece a widget of its
 * own: the picture, the name, the club tab, the heading, the line under it,
 * the form and the footer line.
 *
 * @package eastwood-fwp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The padel address, without www. */
function ew_padel_domain() {
	return 'eastwoodpadelclub.com';
}

/** The page the padel address opens on, and the parent of every padel page. */
function ew_padel_root() {
	return 'padel';
}

/** Is this request on the padel address. */
function ew_padel_host() {
	$h = strtolower( (string) ( $_SERVER['HTTP_HOST'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$h = preg_replace( '/:\d+$/', '', $h );
	$h = preg_replace( '/^www\./', '', $h );
	return ew_padel_domain() === $h;
}

/** The club address a padel-address URL is swapped from. */
function ew_padel_home_host() {
	return (string) wp_parse_url( get_option( 'home' ), PHP_URL_HOST );
}

/** A club-site URL as it reads on the padel address. */
function ew_padel_url( $url ) {
	$home = untrailingslashit( get_option( 'home' ) );
	$root = $home . '/' . ew_padel_root();
	if ( 0 === strpos( $url, $root . '/' ) || $url === $root ) {
		return 'https://' . ew_padel_domain() . '/' . ltrim( substr( $url, strlen( $root ) ), '/' );
	}
	return $url;
}

add_action( 'init', function () {
	if ( ! ew_padel_host() ) {
		return;
	}
	// WordPress would send every padel-address request back to eastwoodfc.com.
	add_filter( 'redirect_canonical', '__return_false' );
	// The forms post to the address they were loaded from, so no cross-site call.
	add_filter( 'rest_url', function ( $url ) {
		$club = ew_padel_home_host();
		return '' !== $club ? str_replace( '//' . $club . '/', '//' . ew_padel_domain() . '/', $url ) : $url;
	} );
	add_filter( 'page_link', 'ew_padel_url' );
	add_filter( 'get_canonical_url', 'ew_padel_url' );
}, 1 );

/** On the padel address, map the path to a padel page, or send it to the club site. */
add_filter( 'request', function ( $q ) {
	if ( ! ew_padel_host() || is_admin() || ! empty( $q['rest_route'] ) ) {
		return $q;
	}
	$path = trim( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH ), '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( preg_match( '#^(wp-json|wp-admin|wp-content|wp-includes|wp-login\.php|wp-cron\.php|xmlrpc\.php|favicon\.ico|robots\.txt)#', $path ) ) {
		return $q;
	}
	$slug = '' === $path ? ew_padel_root() : ew_padel_root() . '/' . $path;
	$page = get_page_by_path( $slug );
	if ( $page && 'publish' === $page->post_status ) {
		return array( 'pagename' => $slug );
	}
	wp_redirect( 'https://' . ew_padel_home_host() . ( '' === $path ? '/' : '/' . $path . '/' ), 302 ); // phpcs:ignore WordPress.Security.SafeRedirect
	exit;
} );

/** The waiting-list block's fields, and what they say until someone changes them. */
function ew_padel_defaults() {
	return array(
		// Step one: the email on its own.
		'form_title'   => 'Join the waiting list',
		'email_label'  => 'Email address',
		'send'         => 'Tell me first',
		'legal'        => 'We’ll email you about Eastwood Padel Club.',
		'legal_tip'    => 'We’ll only use your email to tell you about Eastwood Padel Club. You can unsubscribe at any time.',
		'legal_link'   => 'Privacy policy',
		'legal_url'    => 'https://eastwoodfc.com/privacy-policy/',
		// Step two: name and mobile.
		'next_title'   => 'Nearly there',
		'form_note'    => '',
		'name_label'   => 'Your full name',
		'phone_label'  => 'Your mobile',
		'phone_note'   => 'For a text when bookings open.',
		'consent'      => 'Tell me about founding memberships too',
		'send2'        => 'Join the list',
		// Errors.
		'need_name'    => 'We need your name for the list.',
		'need_email'   => 'We need an email to tell you on.',
		'need_phone'   => 'We need a mobile number.',
		'failed'       => 'Something went wrong. Please try again.',
		// The last page.
		'app_title'    => 'Your place is in the app',
		'ticket_title' => 'Waiting list',
		'ticket_sub'   => 'Eastwood Padel Club',
		'ticket_date'  => 'Opening 2027',
		'event'        => 'Eastwood Padel Club waiting list',
		'track'        => 'Padel waiting list',
		'crm_hook'     => '',
		// Who the confirmation email is from. The address must be a confirmed
		// send-as on the website mailbox; until it is, the club address is used.
		'mail_name'    => 'Eastwood Padel Club at Eastwood FC',
		'mail_from'    => 'noreply@eastwoodpadelclub.com',
	);
}

/** The block as written on a page. */
function ew_padel_atts( $atts ) {
	return shortcode_atts( ew_padel_defaults(), (array) $atts, 'eastwood_padel_list' );
}

function ew_padel_shortcode( $atts ) {
	$a    = ew_padel_atts( $atts );
	$post = get_post();

	$words = array(
		'need_name'  => $a['need_name'],
		'need_email' => $a['need_email'],
		'need_phone' => $a['need_phone'],
		'failed'     => $a['failed'],
		'ph'         => $a['email_label'],
		'rest'       => esc_url_raw( rest_url( 'eastwood/v1/padel-list' ) ),
	) + ( function_exists( 'ew_optin_words' ) ? ew_optin_words( 'padel', $a ) : array() );

	$arrow = '<svg aria-hidden="true" width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 8 L13 8"/><path d="M9 4 L13 8 L9 12"/></svg>';
	$info  = '<svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="12" r="10"/><line x1="12" y1="11" x2="12" y2="17"/><circle cx="12" cy="7.5" r="0.6" fill="currentColor"/></svg>';

	$h  = '<section class="ewpd" data-page="' . (int) ( $post ? $post->ID : 0 ) . '">';
	$h .= '<form class="ewpd-form" novalidate>';
	$h .= ew_optin_step_one( 'ewpd', $a );
	$h .= '<p class="ewpd-legal" data-ewpd-legal>' . esc_html( $a['legal'] ) . ' <button type="button" class="ewpd-i" aria-label="' . esc_attr( $a['legal_tip'] ) . '" aria-expanded="false" data-ewpd-i>' . $info . '</button></p>';
	$h .= '<p class="ewpd-tip" data-ewpd-tip hidden>' . esc_html( $a['legal_tip'] ) . ( '' !== trim( (string) $a['legal_url'] ) ? ' <a href="' . esc_url( $a['legal_url'] ) . '">' . esc_html( $a['legal_link'] ) . '</a>' : '' ) . '</p>';
	$h .= '<div class="ewpd-two" data-ewo-two hidden>';
	$h .= '<p class="ewpd-head">' . esc_html( $a['next_title'] ) . '</p>';
	if ( '' !== trim( (string) $a['form_note'] ) ) {
		$h .= '<p class="ewpd-note">' . esc_html( $a['form_note'] ) . '</p>';
	}
	$h .= '<label class="ewpd-lab" for="ewpdName">' . esc_html( $a['name_label'] ) . '</label><input class="ewpd-in" id="ewpdName" type="text" autocomplete="name" data-ewo-save="name">';
	$h .= '<label class="ewpd-lab" for="ewpdPhone">' . esc_html( $a['phone_label'] ) . '</label><input class="ewpd-in" id="ewpdPhone" type="tel" autocomplete="tel" data-ewo-save="phone">';
	if ( '' !== trim( (string) $a['phone_note'] ) ) {
		$h .= '<p class="ewpd-pnote">' . esc_html( $a['phone_note'] ) . '</p>';
	}
	$h .= '<label class="ewpd-tick"><input type="checkbox" id="ewpdConsent" checked><span>' . esc_html( $a['consent'] ) . '</span></label>';
	$h .= '<p class="ewpd-err" role="alert"></p>';
	$h .= '<button type="submit" class="ewpd-btn">' . esc_html( $a['send2'] ) . $arrow . '</button>';
	$h .= '</div>';
	$h .= '</form>';
	$h .= ew_inapp_panel( $a['app_title'], ew_padel_ticket( $a ) );
	$h .= '<script type="application/json" class="ewpd-words">' . wp_json_encode( $words ) . '</script>';
	return $h . '</section>';
}
add_shortcode( 'eastwood_padel_list', 'ew_padel_shortcode' );

/** The place that slides out of the app on the last page. */
function ew_padel_ticket( $a ) {
	return array(
		'title' => (string) $a['ticket_title'],
		'sub'   => (string) $a['ticket_sub'],
		'day'   => '',
		'date'  => strtoupper( (string) $a['ticket_date'] ),
		'time'  => '',
		'stub'  => '',
		'stub2' => '',
	);
}

add_filter( 'ew_blocks_allowed', function ( $list ) {
	$list[] = 'eastwood_padel_list';
	return $list;
} );

add_filter( 'ew_optin_forms', function ( $forms ) {
	$forms['padel'] = 'ew_padel_block_on';
	return $forms;
} );

/** The block on a page, read from the page itself, so a sign-up carries its words. */
function ew_padel_block_on( $post ) {
	$found = null;
	$data  = json_decode( (string) get_post_meta( $post->ID, '_elementor_data', true ), true );
	if ( is_array( $data ) ) {
		$walk = function ( $nodes ) use ( &$walk, &$found ) {
			foreach ( (array) $nodes as $n ) {
				if ( $found ) {
					return;
				}
				$sc = (string) ( $n['settings']['shortcode'] ?? '' );
				if ( 0 === strpos( ltrim( $sc ), '[eastwood_padel_list' ) ) {
					$found = $sc;
					return;
				}
				if ( ! empty( $n['elements'] ) ) {
					$walk( $n['elements'] );
				}
			}
		};
		$walk( $data );
	}
	if ( ! $found && preg_match( '/\[eastwood_padel_list[^\]]*\]/', (string) $post->post_content, $mm ) ) {
		$found = $mm[0];
	}
	if ( ! $found ) {
		return null;
	}
	$atts = array();
	if ( preg_match( '/\[eastwood_padel_list([^\]]*)\]/', $found, $o ) ) {
		$atts = (array) shortcode_parse_atts( $o[1] );
	}
	return ew_padel_atts( $atts );
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'eastwood/v1', '/padel-list', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) {
			$post = get_post( (int) $req->get_param( 'page' ) );
			$a    = $post ? ew_padel_block_on( $post ) : null;
			if ( ! $a ) {
				return new WP_REST_Response( array( 'message' => 'no block' ), 400 );
			}
			$name  = function_exists( 'ew_person_name' ) ? ew_person_name( sanitize_text_field( (string) $req->get_param( 'name' ) ) ) : sanitize_text_field( (string) $req->get_param( 'name' ) );
			$bid   = function_exists( 'ew_optin_claim' ) ? ew_optin_claim( $req ) : 0;
			$email = $bid ? (string) get_post_meta( $bid, '_ewt_email', true ) : sanitize_email( (string) $req->get_param( 'email' ) );
			$phone = trim( sanitize_text_field( (string) $req->get_param( 'phone' ) ) );
			if ( '' === $name ) {
				return new WP_REST_Response( array( 'message' => $a['need_name'] ), 400 );
			}
			if ( ! is_email( $email ) ) {
				return new WP_REST_Response( array( 'message' => $a['need_email'] ), 400 );
			}
			if ( strlen( preg_replace( '/\D/', '', $phone ) ) < 10 ) {
				return new WP_REST_Response( array( 'message' => $a['need_phone'] ), 400 );
			}
			if ( $bid ) {
				$id = $bid;
				wp_update_post( array( 'ID' => $id, 'post_title' => $name ) );
			} else {
				$id = wp_insert_post( array(
					'post_type'   => 'ewt_booking',
					'post_status' => 'publish',
					'post_title'  => $name,
				) );
			}
			if ( ! $id || is_wp_error( $id ) ) {
				return new WP_REST_Response( array( 'message' => $a['failed'] ), 500 );
			}
			foreach ( array(
				'name'    => $name,
				'email'   => $email,
				'phone'   => $phone,
				'event'   => (string) $a['event'],
				'page'    => $post->ID,
				'url'     => get_permalink( $post ),
				'lines'   => $name,
				'extra'   => 'Padel waiting list',
				'total'   => 0,
				'status'  => 'padel',
				'consent' => $req->get_param( 'consent' ) ? 'yes' : 'no',
				'hook'    => (string) $a['crm_hook'],
			) as $k => $v ) {
				update_post_meta( $id, '_ewt_' . $k, $v );
			}
			if ( function_exists( 'ew_tix_crm' ) ) {
				ew_tix_crm( (string) $a['crm_hook'], $id, 'padel' );
			}
			return function_exists( 'ew_optin_finished' ) ? ew_optin_finished( $id ) : array( 'ok' => 1 );
		},
	) );
} );

/** Is this a padel page: the splash layout or the waiting-list form is on it. */
function ew_padel_page( $post ) {
	return $post && function_exists( 'ew_post_contains' )
		&& ( ew_post_contains( $post, 'ewpd-splash' ) || ew_post_contains( $post, 'eastwood_padel_list' ) );
}

add_filter( 'body_class', function ( $c ) {
	if ( is_singular() && ew_padel_page( get_post() ) ) {
		$c[] = 'ewpd-page';
	}
	return $c;
} );

function ew_padel_css() {
	return '
body.ewpd-page{margin:0;background:#000}
.ewpd-splash{position:relative!important;min-height:100vh;min-height:100svh;background:#000;overflow:hidden;padding:clamp(110px,19vh,210px) 60px clamp(72px,11vh,120px)!important;display:flex!important;flex-direction:column!important;align-items:flex-start!important;justify-content:flex-start!important;gap:0!important;color:#ededf0;font-family:"DM Sans",system-ui,sans-serif}
.admin-bar .ewpd-splash{min-height:calc(100vh - 32px);min-height:calc(100svh - 32px)}
@media (max-width:782px){.admin-bar .ewpd-splash{min-height:calc(100svh - 46px)}}
.ewpd-splash::after{content:"";position:absolute;top:0;left:0;bottom:0;width:900px;max-width:100%;background:linear-gradient(90deg,rgba(0,0,0,.85) 0%,rgba(0,0,0,.55) 55%,rgba(0,0,0,0) 100%);z-index:1;pointer-events:none}
.ewpd-splash>.elementor-widget{position:relative;z-index:2;width:100%;max-width:900px!important;margin:0!important}
.ewpd-splash>.ewpd-bg{position:absolute!important;top:0;left:0;right:0;bottom:0;max-width:none!important;z-index:0}
.ewpd-splash .ewpd-bg .elementor-widget-container,.ewpd-splash .ewpd-bg img{width:100%!important;height:100%!important;max-width:none!important;display:block;object-fit:cover;object-position:center bottom}
.ewpd-splash-b .ewpd-bg img{object-position:center center}
.ewpd-splash>.ewpd-logo{position:absolute!important;left:60px;top:40px;width:auto;max-width:none!important}
.ewpd-splash .ewpd-logo .elementor-heading-title.elementor-heading-title{margin:0!important;padding:0!important;background:none!important;font-family:"Bebas Neue",sans-serif!important;font-weight:400!important;font-size:30px!important;letter-spacing:.06em;line-height:1!important;color:#fff!important;text-transform:none}
.ewpd-splash>.ewpd-tab{position:absolute!important;right:60px;top:0;width:150px;max-width:none!important;background:#CC0000;padding:22px 14px 18px;box-sizing:border-box;text-align:center}
.ewpd-splash .ewpd-tab img{width:64px;height:64px;object-fit:contain;display:block;margin:0 auto 10px}
.ewpd-splash .ewpd-tab p{margin:0;color:#fff}
.ewpd-splash .ewpd-tab p:nth-of-type(1){font-family:"DM Sans",sans-serif;font-size:12px;font-weight:700;letter-spacing:.2em;margin-bottom:8px}
.ewpd-splash .ewpd-tab p:nth-of-type(2){font-family:"Bebas Neue",sans-serif;font-size:26px;line-height:.95;letter-spacing:.03em}
.ewpd-splash .ewpd-eyebrow p{margin:0;font-family:"Geist Mono",monospace;text-transform:uppercase;letter-spacing:.08em;font-size:14px;line-height:1.3;color:#9a9aa3}
.ewpd-splash .ewpd-title .elementor-heading-title.elementor-heading-title{margin:18px 0 0!important;padding:0!important;background:none!important;width:auto!important;font-family:"Bebas Neue",sans-serif!important;font-weight:400!important;font-size:clamp(64px,14vh,140px)!important;line-height:.88!important;color:#fff!important;text-transform:none;text-align:left}
.ewpd-splash .ewpd-lead p{margin:clamp(12px,2.4vh,22px) 0 0;max-width:520px;font-size:clamp(17px,2.4vh,20px);line-height:1.5;color:#d6d6da}
.ewpd-splash>.elementor-widget-shortcode{margin-top:clamp(18px,3.4vh,32px)!important;max-width:580px!important}
.ewpd-splash>.ewpd-foot{position:absolute!important;left:60px;right:60px;bottom:36px;width:auto;max-width:none!important}
.ewpd-splash .ewpd-foot p{margin:0;display:flex;justify-content:space-between;font-size:13px;color:#9a9aa3}
.ewpd-splash .ewpd-foot a{color:#9a9aa3;text-decoration:none}
.ewpd{font-family:"DM Sans",system-ui,sans-serif;color:#ededf0}
.ewpd *{box-sizing:border-box}
.ewpd [hidden]{display:none!important}
.ewpd .ewo-one{display:flex;flex-wrap:wrap;align-items:stretch}
.ewpd .ewpd-head{flex:0 0 100%;margin:0 0 12px;font-family:"Geist Mono",monospace;text-transform:uppercase;letter-spacing:.08em;font-size:13px;color:#9a9aa3}
.ewpd .ewo-one .ewpd-lab{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}
.ewpd .ewo-one .ewpd-in{flex:1 1 260px;border-right:0}
.ewpd .ewpd-in{height:56px;padding:0 20px;background:rgba(0,0,0,.6);border:1px solid rgba(255,255,255,.25);border-radius:0;color:#fff;font:inherit;font-size:16px;outline:none;width:100%}
.ewpd .ewpd-in:focus{border-color:#fff}
.ewpd .ewpd-btn{display:inline-flex;align-items:center;justify-content:center;gap:12px;height:56px;padding:0 24px;background:transparent;border:1px solid rgba(255,255,255,.55);border-radius:0;color:#fff;cursor:pointer;white-space:nowrap;font-family:"Geist Mono",monospace;text-transform:uppercase;letter-spacing:.08em;font-size:13px}
.ewpd .ewpd-btn:hover{border-color:#fff}
.ewpd .ewpd-btn[disabled]{opacity:.6;cursor:default}
.ewpd .ewo-one .ewpd-err{flex:0 0 100%;order:5}
.ewpd .ewpd-err{margin:10px 0 0;min-height:0;font-size:14px;color:#ff8a8a}
.ewpd .ewpd-err:empty{display:none}
.ewpd .ewpd-legal{margin:14px 0 0;font-size:13px;line-height:1.6;color:#9a9aa3}
.ewpd .ewpd-i{background:none;border:0;padding:0;color:#9a9aa3;cursor:pointer;vertical-align:-2px}
.ewpd .ewpd-tip{margin:6px 0 0;max-width:460px;font-size:13px;line-height:1.5;color:#9a9aa3}
.ewpd .ewpd-tip a{color:#ededf0}
.ewpd .ewpd-two .ewpd-head{margin:0 0 4px;font-family:"Bebas Neue",sans-serif;font-size:40px;letter-spacing:0;text-transform:none;color:#fff}
.ewpd .ewpd-two .ewpd-lab{display:block;margin:16px 0 8px;font-family:"Geist Mono",monospace;text-transform:uppercase;letter-spacing:.08em;font-size:12px;color:#9a9aa3}
.ewpd .ewpd-pnote,.ewpd .ewpd-note{margin:8px 0 0;font-size:13px;line-height:1.5;color:#9a9aa3}
.ewpd .ewpd-tick{display:flex;gap:10px;align-items:flex-start;margin:18px 0 0;font-size:14px;line-height:1.45;color:#d6d6da;cursor:pointer}
.ewpd .ewpd-tick input{margin:2px 0 0;width:16px;height:16px;accent-color:#fff}
.ewpd .ewpd-two .ewpd-btn{width:100%;margin-top:16px}
.ewpd-page .ewpd .ewo-one + .ewpd-legal{display:block}
.ewpd-form:has(.ewpd-two:not([hidden])) .ewpd-legal,.ewpd-form:has(.ewpd-two:not([hidden])) .ewpd-tip{display:none}
@media (max-width:760px){
.ewpd-splash{padding:clamp(104px,16vh,150px) 20px clamp(64px,10vh,96px)!important}
.ewpd-splash::after{width:100%;background:linear-gradient(180deg,rgba(0,0,0,.85) 0%,rgba(0,0,0,.55) 45%,rgba(0,0,0,.9) 100%)}
.ewpd-splash .ewpd-bg img{object-position:70% bottom}
.ewpd-splash-b .ewpd-bg img{object-position:center 40%}
.ewpd-splash>.ewpd-logo{left:20px;top:26px}
.ewpd-splash .ewpd-logo .elementor-heading-title.elementor-heading-title{font-size:24px!important}
.ewpd-splash>.ewpd-tab{right:16px;width:84px;padding:12px 8px 10px}
.ewpd-splash .ewpd-tab img{width:36px;height:36px;margin-bottom:6px}
.ewpd-splash .ewpd-tab p:nth-of-type(1){font-size:8px;margin-bottom:4px}
.ewpd-splash .ewpd-tab p:nth-of-type(2){font-size:15px}
.ewpd-splash .ewpd-eyebrow p{font-size:12px;letter-spacing:.06em}
.ewpd-splash .ewpd-title .elementor-heading-title.elementor-heading-title{margin-top:12px!important;font-size:clamp(56px,9vh,84px)!important}
.ewpd-splash .ewpd-lead p{margin-top:clamp(10px,1.8vh,18px);font-size:16px}
.ewpd-splash>.elementor-widget-shortcode{margin-top:clamp(14px,2.6vh,24px)!important}
.ewpd-splash>.ewpd-foot{left:20px;right:20px;bottom:28px}
.ewpd-splash .ewpd-foot p{font-size:12px}
.ewpd .ewo-one .ewpd-in{flex:0 0 100%;height:52px;border-right:1px solid rgba(255,255,255,.25)}
.ewpd .ewo-one .ewpd-btn{flex:0 0 100%;height:52px;margin-top:10px}
}
';
}

function ew_padel_js() {
	return <<<'JS'
(function(){
 var root = document.querySelector('.ewpd'); if(!root) return;
 var wordsEl = root.querySelector('.ewpd-words'); if(!wordsEl) return;
 var W = JSON.parse(wordsEl.textContent), page = root.getAttribute('data-page');
 var form = root.querySelector('.ewpd-form'), err = root.querySelector('.ewpd-two .ewpd-err');
 var em = root.querySelector('[data-ewo-email]'); if(em && W.ph) em.placeholder = W.ph;
 var go = root.querySelector('[data-ewo-next]');
 if(go && !go.querySelector('svg')) go.insertAdjacentHTML('beforeend', '<svg aria-hidden="true" width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 8 L13 8"/><path d="M9 4 L13 8 L9 12"/></svg>');
 var ib = root.querySelector('[data-ewpd-i]'), tip = root.querySelector('[data-ewpd-tip]');
 if(ib && tip) ib.addEventListener('click', function(){ tip.hidden = !tip.hidden; ib.setAttribute('aria-expanded', tip.hidden ? 'false' : 'true'); });
 function val(id){ var el = root.querySelector('#' + id); return el ? el.value.trim() : ''; }
 var O = window.ewOptin ? ewOptin(root, W) : {bid: 0, key: '', email: ''};
 form.addEventListener('submit', function(e){
   e.preventDefault(); if(!err) return; err.textContent = '';
   var name = val('ewpdName'), phone = val('ewpdPhone');
   if(!name){ err.textContent = W.need_name; return; }
   if(phone.replace(/\D/g,'').length < 10){ err.textContent = W.need_phone; return; }
   var tick = root.querySelector('#ewpdConsent');
   var btn = form.querySelector('.ewpd-two .ewpd-btn'); btn.disabled = true;
   fetch(W.rest, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({
     page: page, bid: O.bid, key: O.key, email: O.email, name: name, phone: phone,
     consent: (tick && tick.checked) ? 1 : 0
   })})
   .then(function(r){ return r.json().then(function(j){ return {ok: r.ok, j: j}; }); })
   .then(function(x){
     if(!x.ok){ err.textContent = (x.j && x.j.message) || W.failed; btn.disabled = false; return; }
     form.hidden = true;
     if(O.done) O.done();
     if(window.ewInapp) ewInapp(root, x.j.claim || '');
   })
   .catch(function(){ err.textContent = W.failed; btn.disabled = false; });
 });
})();
JS;
}

add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_singular() ) {
		return;
	}
	$post = get_post();
	if ( ! ew_padel_page( $post ) ) {
		return;
	}
	wp_enqueue_style( 'ewpd-fonts', 'https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@400;500;600;700&family=Geist+Mono:wght@400;500&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
	wp_register_style( 'ew-padel', false, array(), EW_FWP_VERSION );
	wp_enqueue_style( 'ew-padel' );
	wp_add_inline_style( 'ew-padel', ew_padel_css() . ( function_exists( 'ew_inapp_css' ) ? ew_inapp_css() : '' ) );
	if ( ew_post_contains( $post, 'eastwood_padel_list' ) ) {
		wp_register_script( 'ew-padel', '', array(), EW_FWP_VERSION, true );
		wp_enqueue_script( 'ew-padel' );
		wp_add_inline_script( 'ew-padel', ( function_exists( 'ew_optin_js' ) ? ew_optin_js() . ew_inapp_js() : '' ) . ew_padel_js() );
	}
}, 22 );

/* ------------------------------------------------------------------ *
 * The waiting-list emails. Their words are on the Eastwood FWP screen
 * with the other order emails; who they are from is on the block.
 * ------------------------------------------------------------------ */

add_filter( 'ew_om_emails', function ( $emails ) {
	$emails['padel_done']     = array(
		'label'   => 'Padel waiting list – joined',
		'subject' => "You’re on the list: Eastwood Padel Club",
		'body'    => "Hi {first_name},\n\nYou’re on the waiting list for Eastwood Padel Club, the new padel courts at Eastwood Football Club.\n\nYou’ll be one of the first to hear when court bookings open, along with news on founding memberships.\n\nYour place on the list is saved in the Eastwood FC app. It’s the club’s app for everything at the ground: padel, match tickets and events.\n\nTo see it:\n1. Download the Eastwood FC app: {app_url}\n2. Sign in with this email address\n\nThat’s it. Your place is there, and it’s where bookings will open first.\n\nThanks,\nEastwood Padel Club at Eastwood FC",
	);
	$emails['padel_reminder'] = array(
		'label'   => 'Padel waiting list – not finished (an hour later)',
		'subject' => "Nearly on the list: Eastwood Padel Club",
		'body'    => "{greeting},\n\nYou started joining the Eastwood Padel Club waiting list but didn’t quite finish.\n\nIt only takes a minute:\n{event_url}\n\nThanks,\nEastwood Padel Club at Eastwood FC",
	);
	return $emails;
} );

/** Is this booking from the padel waiting list. */
function ew_padel_booking( $booking_id ) {
	return 'padel' === (string) get_post_meta( $booking_id, '_ewt_form', true )
		|| 'padel' === (string) get_post_meta( $booking_id, '_ewt_status', true );
}

/** The block a booking came from, for the sender name and address. */
function ew_padel_booking_atts( $booking_id ) {
	$page = get_post( (int) get_post_meta( $booking_id, '_ewt_page', true ) );
	$a    = $page ? ew_padel_block_on( $page ) : null;
	return $a ? $a : ew_padel_defaults();
}

add_filter( 'ew_om_key', function ( $key, $booking_id, $step ) {
	if ( ! ew_padel_booking( $booking_id ) ) {
		return $key;
	}
	$GLOBALS['ew_padel_mail'] = (int) $booking_id;
	return 'reminder' === $step ? 'padel_reminder' : 'padel_done';
}, 10, 3 );

/** Sent as the padel club: its name always, its address once Google has confirmed it. */
add_action( 'phpmailer_init', function ( $mail ) {
	if ( empty( $GLOBALS['ew_padel_mail'] ) ) {
		return;
	}
	$a    = ew_padel_booking_atts( (int) $GLOBALS['ew_padel_mail'] );
	$name = trim( (string) $a['mail_name'] );
	$from = sanitize_email( (string) $a['mail_from'] );
	$ok   = function_exists( 'ew_eb_froms' ) ? array_map( 'strtolower', ew_eb_froms() ) : array();
	if ( is_email( $from ) && in_array( strtolower( $from ), $ok, true ) ) {
		$mail->setFrom( $from, $name, false );
		$mail->clearReplyTos();
		$mail->addReplyTo( $from, $name );
	} else {
		$mail->FromName = $name;
	}
}, 20 );

add_action( 'wp_mail_succeeded', function () {
	unset( $GLOBALS['ew_padel_mail'] );
} );
add_action( 'wp_mail_failed', function () {
	unset( $GLOBALS['ew_padel_mail'] );
} );
