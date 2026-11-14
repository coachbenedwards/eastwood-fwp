<?php
/**
 * Bookings -> Order update: the match day email that asks "are you still
 * coming?", in the photo ticket card design.
 *
 * Each booking gets its own email: subject "[ORDER UPDATE] Order No. EFC-…",
 * their booking reference and booking email in big, Yes / Can't make it,
 * the ticket card, the app, and getting here. Every word is a setting on
 * this screen, not a string in the code.
 *
 * The two buttons are personal links (eastwoodfc.com/answer/?r=<code>&a=yes).
 * The code is a random one stored on that booking, so no sign-in is needed:
 * the answer page reports the code and the answer, and the booking shows
 * Coming or Can't make it straight away. The counts are on this screen.
 *
 * The /answer/ page is made once, as a normal Elementor page holding the
 * [eastwood_answer] block, whose words are fields on the block.
 *
 * @package eastwood-fwp
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'ew_blocks_allowed', function ( $list ) {
	$list[] = 'eastwood_answer';
	return $list;
} );

/** The words and pictures, with today's defaults. */
function ew_ou_defaults() {
	$a = plugins_url( 'assets/', dirname( __DIR__ ) . '/eastwood-fwp.php' );
	return array(
		'subject'   => '[ORDER UPDATE] Order No. {ref}',
		'booking'   => 'YOUR BOOKING',
		'headline'  => 'ARE YOU STILL COMING?',
		'intro'     => 'Hi {first_name}, lots of tickets have been claimed for today, so we want to make sure nobody misses out. Let us know if you can still make it.',
		'yes'       => "YES, I'M COMING",
		'no'        => "I CAN'T MAKE IT",
		'home'      => 'EASTWOOD FC',
		'away'      => 'HUCKNALL TOWN',
		'day'       => 'SATURDAY',
		'date'      => '14 November 2026',
		'time'      => '3PM',
		'bg'        => $a . 'claim/ticket-bg-hucknall.jpg',
		'crest'     => $a . 'claim/eastwood-white.png',
		'away_crest' => $a . 'claim/hucknall-town-white.png',
		'sponsor'   => $a . 'email/solopress-white.png',
		'app_title' => 'YOUR TICKET IS IN THE APP',
		'app_text'  => 'Gates, maps and your ticket, all in one place.',
		'here'      => 'GETTING HERE',
		'address'   => 'Coronation Park, Chewton Street, Eastwood, NG16 3HB',
		'rows'      => "Arrive early | Gates open at 1pm and kick-off is 3pm. We are expecting a big crowd, so give yourself time to get through the gates.\n"
			. "By car | M1 junction 26, then the A610 towards Eastwood. Turn off at the IKEA junction onto the B6010 and follow it into Eastwood. Chewton Street is on the left at the top of the hill.\n"
			. "Parking | No spectator parking at the ground today. Please don't park across driveways or business entrances on Chewton Street. Accessible parking must be booked with the club before you come.\n"
			. "By bus | trentbarton Rainbow One runs from Nottingham Victoria Bus Station to Eastwood every 10 minutes. Get off at Eastwood Library or Morrisons and walk to Chewton Street.\n"
			. "By train | Langley Mill station is about 1.6 miles away, around a 30 minute walk. Taxis wait outside the station.\n"
			. 'Your entrance | Your entrance is shown on your ticket in the Eastwood FC app.',
		'partners'  => 'CLUB PARTNERS AND SPONSORS',
		'logos'     => $a . 'ew-sponsors/podcut-white.png' . "\n" . $a . 'ew-sponsors/kam-servicing.png' . "\n" . $a . 'email/solopress-white.png',
		'footer'    => 'You are getting this because you claimed a ticket at eastwoodfc.com.',
	);
}

function ew_ou_get() {
	$saved = get_option( 'ew_ou', array() );
	return array_merge( ew_ou_defaults(), is_array( $saved ) ? $saved : array() );
}

/** The booking's own answer code, made once. */
function ew_ou_code( $bid ) {
	$c = (string) get_post_meta( $bid, '_ewt_ou_code', true );
	if ( '' === $c ) {
		$c = function_exists( 'ew_eb_code' ) ? ew_eb_code() : strtolower( wp_generate_password( 8, false ) );
		update_post_meta( $bid, '_ewt_ou_code', $c );
	}
	return $c;
}

function ew_ou_by_code( $code ) {
	if ( ! preg_match( '/^[a-z0-9]{8}$/', (string) $code ) ) {
		return 0;
	}
	$ids = get_posts( array( 'post_type' => 'ewt_booking', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => '_ewt_ou_code', 'meta_value' => $code ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	return $ids ? (int) $ids[0] : 0;
}

function ew_ou_first( $name ) {
	$f = trim( strtok( (string) $name, ' ' ) );
	return '' === $f ? '' : ucfirst( strtolower( $f ) );
}

/** One person's email: [subject, html, text]. */
function ew_ou_email( $bid, $test_code = '' ) {
	$o     = ew_ou_get();
	$e     = function ( $s ) {
		return esc_html( (string) $s );
	};
	$ref   = function_exists( 'ew_booking_code' ) ? ew_booking_code( $bid ) : 'EFC-' . $bid;
	$email = (string) get_post_meta( $bid, '_ewt_email', true );
	$first = ew_ou_first( get_post_meta( $bid, '_ewt_name', true ) );
	$lines = trim( (string) get_post_meta( $bid, '_ewt_lines', true ) );
	$code  = '' !== $test_code ? $test_code : ew_ou_code( $bid );
	$yes   = add_query_arg( array( 'r' => $code, 'a' => 'yes' ), home_url( '/answer/' ) );
	$no    = add_query_arg( array( 'r' => $code, 'a' => 'no' ), home_url( '/answer/' ) );
	$fill  = function ( $s ) use ( $ref, $first ) {
		$s = strtr( (string) $s, array( '{ref}' => $ref, '{first_name}' => $first ) );
		return preg_replace( '/ +,/', ',', $s );
	};
	$subject = $fill( $o['subject'] );

	$anton = "font-family:Anton,Impact,'Arial Narrow Bold',sans-serif;font-weight:400";
	$sans  = "font-family:'Instrument Sans',Helvetica,Arial,sans-serif";

	$rows = '';
	foreach ( preg_split( '/\r?\n/', (string) $o['rows'] ) as $line ) {
		$bits = array_map( 'trim', explode( '|', $line, 2 ) );
		if ( count( $bits ) < 2 || '' === $bits[0] ) {
			continue;
		}
		$rows .= '<tr><td style="padding:12px 0;border-top:1px solid #e4e5e8;' . $sans . '"><div style="font-size:15px;font-weight:700;color:#121417">' . $e( $bits[0] ) . '</div><div style="font-size:14px;line-height:1.55;color:#3a3f46;margin-top:2px">' . $e( $bits[1] ) . '</div></td></tr>';
	}
	$logos = '';
	foreach ( array_filter( array_map( 'trim', preg_split( '/\r?\n/', (string) $o['logos'] ) ) ) as $src ) {
		$logos .= '<img src="' . esc_url( $src ) . '" alt="" height="24" style="height:24px;width:auto;margin:6px 11px;vertical-align:middle;border:0">';
	}
	$store = '<a href="https://apps.apple.com/app/id6820880105" style="display:inline-block;background:#000;color:#fff;text-decoration:none;border-radius:10px;border:1px solid rgba(255,255,255,.35);padding:10px 16px;margin:4px;' . $sans . ';font-size:15px;font-weight:700">App Store</a>'
		. '<a href="https://play.google.com/store/apps/details?id=com.eastwoodfc.team" style="display:inline-block;background:#000;color:#fff;text-decoration:none;border-radius:10px;border:1px solid rgba(255,255,255,.35);padding:10px 16px;margin:4px;' . $sans . ';font-size:15px;font-weight:700">Google Play</a>';
	$btn = function ( $href, $label, $solid ) use ( $anton ) {
		return '<a href="' . esc_url( $href ) . '" style="display:block;text-align:center;text-decoration:none;border-radius:10px;padding:15px 10px;margin-top:10px;' . $anton . ';font-size:19px;letter-spacing:.5px;'
			. ( $solid ? 'background:#CC0000;color:#ffffff;border:2px solid #CC0000' : 'background:#ffffff;color:#121417;border:2px solid #121417' ) . '">' . esc_html( $label ) . '</a>';
	};

	$html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
		. '<link href="https://fonts.googleapis.com/css2?family=Anton&family=Instrument+Sans:wght@400;700&display=swap" rel="stylesheet"></head>'
		. '<body style="margin:0;padding:0;background:#F3F3F3">'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F3F3F3"><tr><td align="center" style="padding:16px">'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border:1px solid #e4e5e8;border-radius:12px;overflow:hidden">'
		// Red header with the crest.
		. '<tr><td align="center" style="background:#CC0000;padding:18px"><img src="' . esc_url( $o['crest'] ) . '" alt="Eastwood FC" width="56" height="56" style="width:56px;height:56px;border:0"></td></tr>'
		. '<tr><td style="padding:22px 20px">'
		// Booking box: reference and email, big.
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#111111;border-radius:12px"><tr><td style="padding:18px;color:#ffffff;' . $sans . '">'
		. '<div style="font-size:11px;font-weight:700;letter-spacing:2px;color:#9a9aa3">' . $e( $o['booking'] ) . '</div>'
		. '<div style="' . $anton . ';font-size:52px;line-height:1.05;letter-spacing:2px;color:#ffffff;margin-top:6px">' . $e( $ref ) . '</div>'
		. '<div style="font-size:18px;font-weight:700;color:#ffffff;margin-top:8px;word-break:break-all">' . $e( $email ) . '</div>'
		. ( '' !== $lines ? '<div style="font-size:14px;color:#c9c9cf;margin-top:4px">' . $e( $lines ) . '</div>' : '' )
		. '</td></tr></table>'
		// The question and the two answers.
		. '<div style="' . $anton . ';font-size:30px;line-height:1.1;color:#121417;margin-top:22px">' . $e( $fill( $o['headline'] ) ) . '</div>'
		. '<div style="' . $sans . ';font-size:15px;line-height:1.55;color:#3a3f46;margin-top:10px">' . $e( $fill( $o['intro'] ) ) . '</div>'
		. $btn( $yes, $o['yes'], true ) . $btn( $no, $o['no'], false )
		// The photo ticket card.
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" background="' . esc_url( $o['bg'] ) . '" style="margin-top:18px;border-radius:12px;background:#000000 url(' . esc_url( $o['bg'] ) . ') left center / cover no-repeat"><tr><td style="padding:24px 20px;background:rgba(0,0,0,.45);border-radius:12px 12px 0 0">'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td valign="top">'
		. '<div style="' . $anton . ';font-size:36px;line-height:.98;color:#ffffff">' . $e( $o['home'] ) . '</div>'
		. '<div style="font-family:Georgia,serif;font-style:italic;font-size:24px;color:#E01B1B;margin:3px 0">v</div>'
		. '<div style="' . $anton . ';font-size:36px;line-height:.98;color:#ffffff">' . $e( $o['away'] ) . '</div></td>'
		. '<td valign="top" align="right" width="64" style="padding-top:22px"><img src="' . esc_url( $o['crest'] ) . '" alt="" width="52" height="52" style="width:52px;height:52px;border:0;display:block"><img src="' . esc_url( $o['away_crest'] ) . '" alt="" width="52" height="52" style="width:52px;height:52px;border:0;display:block;margin-top:10px"></td></tr></table>'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:16px"><tr><td style="' . $sans . '"><div style="font-size:15px;font-weight:700;letter-spacing:6px;color:#E01B1B">' . $e( $o['day'] ) . '</div><div style="font-size:18px;color:#ffffff;margin-top:3px">' . $e( $o['date'] ) . '</div></td>'
		. '<td align="right" valign="bottom" style="' . $anton . ';font-size:36px;color:#ffffff">' . $e( $o['time'] ) . '</td></tr></table>'
		. '</td></tr>'
		. '<tr><td style="padding:20px;background:rgba(0,0,0,.82);border-top:3px dashed rgba(255,255,255,.85);border-radius:0 0 12px 12px;' . $sans . '">'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td><div style="font-size:9px;letter-spacing:2px;color:#9a9aa3">TICKET</div><div style="font-size:14px;font-weight:700;letter-spacing:2px;color:#ffffff;margin-top:2px">' . $e( $ref ) . '</div></td>'
		. '<td align="right" style="font-size:12px;color:#c9c9cf">' . $e( $lines ) . '</td></tr></table>'
		. '<div style="' . $anton . ';font-size:20px;line-height:1.15;color:#ffffff;margin-top:16px">' . $e( $o['app_title'] ) . '</div>'
		. '<div style="font-size:14px;line-height:1.5;color:#c9c9cf;margin-top:6px">' . $e( $o['app_text'] ) . '</div>'
		. '<div style="margin-top:10px">' . $store . '</div>'
		. ( $o['sponsor'] ? '<div style="text-align:center;margin-top:14px"><span style="' . $anton . ';font-size:12px;letter-spacing:1px;color:#9a9aa3;vertical-align:middle">SPONSORED BY</span> <img src="' . esc_url( $o['sponsor'] ) . '" alt="" height="17" style="height:17px;width:auto;vertical-align:middle;border:0;margin-left:8px"></div>' : '' )
		. '</td></tr></table>'
		// Getting here.
		. '<div style="' . $anton . ';font-size:22px;color:#121417;margin-top:24px">' . $e( $o['here'] ) . '</div>'
		. '<div style="' . $sans . ';font-size:14px;line-height:1.55;color:#3a3f46;margin:6px 0 4px">' . $e( $o['address'] ) . '</div>'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $rows . '</table>'
		// Partners.
		. ( '' !== $logos ? '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#111111;border-radius:12px;margin-top:22px"><tr><td align="center" style="padding:16px 18px;' . $sans . '"><div style="font-size:11px;font-weight:700;letter-spacing:2px;color:#9a9aa3">' . $e( $o['partners'] ) . '</div><div style="margin-top:8px">' . $logos . '</div></td></tr></table>' : '' )
		. '<div style="border-top:1px solid #e8e9ec;margin-top:26px;padding-top:14px;' . $sans . ';font-size:12px;line-height:1.8;color:#8b9099">' . $e( $o['footer'] ) . '</div>'
		. '</td></tr></table></td></tr></table></body></html>';

	$text = $subject . "\n\n" . $ref . "\n" . $email . "\n" . $lines . "\n\n" . $fill( $o['headline'] ) . "\n" . $fill( $o['intro'] )
		. "\n\n" . $o['yes'] . ': ' . $yes . "\n" . $o['no'] . ': ' . $no . "\n\n" . $o['app_title'] . "\n" . home_url( '/app/' ) . "\n\n" . $o['here'] . "\n" . $o['address'] . "\n";
	foreach ( preg_split( '/\r?\n/', (string) $o['rows'] ) as $line ) {
		$text .= str_replace( ' | ', ': ', trim( $line ) ) . "\n";
	}
	return array( $subject, $html, $text );
}

function ew_ou_send( $to, $bid, $test_code = '' ) {
	list( $subject, $html, $text ) = ew_ou_email( $bid, $test_code );
	if ( '' !== $test_code ) {
		$subject = '[Test] ' . $subject;
	}
	$alt = function ( $mail ) use ( $text ) {
		$mail->AltBody = $text;
	};
	add_action( 'phpmailer_init', $alt, 99 );
	$from = function_exists( 'ew_smtp_from' ) ? ew_smtp_from() : get_option( 'admin_email' );
	$ok   = wp_mail( $to, $subject, $html, array( 'From: Eastwood FC <' . $from . '>', 'Content-Type: text/html; charset=UTF-8' ) );
	remove_action( 'phpmailer_init', $alt, 99 );
	return $ok;
}

/* --- The answer: recorded from the /answer/ page ------------------------ */

add_action( 'rest_api_init', function () {
	register_rest_route( 'eastwood/v1', '/answer', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( WP_REST_Request $req ) {
			$a   = 'no' === $req->get_param( 'a' ) ? 'no' : 'yes';
			$bid = ew_ou_by_code( (string) $req->get_param( 'r' ) );
			if ( ! $bid ) {
				return new WP_REST_Response( array( 'ok' => false ), 404 );
			}
			update_post_meta( $bid, '_ewt_answer', $a );
			update_post_meta( $bid, '_ewt_answer_at', time() );
			return array(
				'ok'    => true,
				'a'     => $a,
				'first' => ew_ou_first( get_post_meta( $bid, '_ewt_name', true ) ),
				'ref'   => function_exists( 'ew_booking_code' ) ? ew_booking_code( $bid ) : '',
			);
		},
	) );
} );

/** The staff app's live numbers: every booking on the games the email went to. */
function ew_ou_live() {
	$out = array( 'coming' => 0, 'people' => 0, 'cant' => 0, 'none' => 0, 'app' => 0, 'sent' => 0, 'rows' => array() );
	foreach ( (array) get_option( 'ew_ou_events', array() ) as $event ) {
		foreach ( ew_ou_people( $event ) as $bid => $em ) {
			$ans   = (string) get_post_meta( $bid, '_ewt_answer', true );
			$sent  = (int) get_post_meta( $bid, '_ewt_ou_sent', true );
			$inapp = (int) get_post_meta( $bid, '_ewt_in_app', true );
			$heads = (int) get_post_meta( $bid, '_ewt_adults', true ) + (int) get_post_meta( $bid, '_ewt_children', true );
			$out['sent'] += $sent ? 1 : 0;
			if ( 'yes' === $ans ) {
				$out['coming']++;
				$out['people'] += max( 1, $heads );
			} elseif ( 'no' === $ans ) {
				$out['cant']++;
			} else {
				$out['none']++;
			}
			$app = $sent && $inapp >= $sent;
			$out['app'] += $app ? 1 : 0;
			$out['rows'][] = array(
				'name'   => (string) get_post_meta( $bid, '_ewt_name', true ),
				'email'  => $em,
				'ref'    => function_exists( 'ew_booking_code' ) ? ew_booking_code( $bid ) : '',
				'party'  => (string) get_post_meta( $bid, '_ewt_lines', true ),
				'answer' => '' === $ans ? 'none' : $ans,
				'inApp'  => (bool) $inapp,
				'newApp' => $app,
			);
		}
	}
	return $out;
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'eastwood/v1', '/order-update/live', array(
		'methods'             => 'GET',
		'permission_callback' => function ( $r ) {
			return function_exists( 'ew_sho_rest_ok' ) && ew_sho_rest_ok( $r );
		},
		'callback'            => function () {
			nocache_headers();
			return ew_ou_live();
		},
	) );
} );

/** The /answer/ page's block. Every word is a field. */
add_shortcode( 'eastwood_answer', function ( $atts ) {
	$a = shortcode_atts( array(
		'yes_title' => 'SEE YOU LATER',
		'yes_text'  => 'Eastwood FC v Hucknall Town. Gates open 1pm, kick-off 3pm, Coronation Park.',
		'yes_btn'   => 'OPEN MY TICKET IN THE APP',
		'yes_url'   => home_url( '/app/' ),
		'no_title'  => 'THANKS FOR LETTING US KNOW',
		'no_text'   => 'Hope to see you at the next one.',
		'no_btn'    => 'SEE THE NEXT HOME GAME',
		'no_url'    => home_url( '/matches/' ),
		'undo'      => 'I can come after all',
		'bad'       => 'This link has expired. Your ticket is in the Eastwood FC app.',
	), $atts, 'eastwood_answer' );
	$id  = 'ewans' . wp_rand( 1000, 9999 );
	$out = '<style>.ewans{max-width:560px;margin:0 auto;padding:40px 20px 70px;font-family:"Instrument Sans",system-ui,sans-serif;color:#121417}'
		. '.ewans h1{font-family:Anton,Impact,sans-serif;font-weight:400;font-size:44px;line-height:1.05;margin:0 0 10px;background:none;padding:0;color:#121417}'
		. '.ewans p{font-size:17px;line-height:1.5;color:#3a3f46;margin:0}'
		. '.ewans a.ewans-btn{display:flex;align-items:center;justify-content:center;min-height:54px;border-radius:10px;background:#CC0000;color:#fff;text-decoration:none;font-family:Anton,Impact,sans-serif;font-size:19px;letter-spacing:.5px;margin-top:22px}'
		. '.ewans a.ewans-undo{display:block;text-align:center;margin-top:16px;font-size:15px;font-weight:700;color:#565c64;text-decoration:underline}'
		. '.ewans [data-s]{display:none}.ewans.is-yes [data-s=yes],.ewans.is-no [data-s=no],.ewans.is-bad [data-s=bad]{display:block}</style>'
		. '<div class="ewans" id="' . esc_attr( $id ) . '">'
		. '<div data-s="yes"><h1>' . esc_html( $a['yes_title'] ) . '</h1><p>' . esc_html( $a['yes_text'] ) . '</p><a class="ewans-btn" href="' . esc_url( $a['yes_url'] ) . '">' . esc_html( $a['yes_btn'] ) . '</a></div>'
		. '<div data-s="no"><h1>' . esc_html( $a['no_title'] ) . '</h1><p>' . esc_html( $a['no_text'] ) . '</p><a class="ewans-btn" href="' . esc_url( $a['no_url'] ) . '">' . esc_html( $a['no_btn'] ) . '</a><a class="ewans-undo" href="#" data-undo>' . esc_html( $a['undo'] ) . '</a></div>'
		. '<div data-s="bad"><p>' . esc_html( $a['bad'] ) . '</p></div></div>'
		. '<script>(function(){var el=document.getElementById(' . wp_json_encode( $id ) . '),q=new URLSearchParams(location.search),r=q.get("r"),a=q.get("a")==="no"?"no":"yes";'
		. 'function send(x){return fetch(' . wp_json_encode( rest_url( 'eastwood/v1/answer' ) ) . ',{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({r:r,a:x})}).then(function(res){return res.json().then(function(j){return res.ok&&j.ok;});}).catch(function(){return false;});}'
		. 'function show(x){el.className="ewans is-"+x;}'
		. 'if(!r){show("bad");return;}show(a);send(a).then(function(ok){if(!ok)show("bad");});'
		. 'var u=el.querySelector("[data-undo]");if(u)u.addEventListener("click",function(e){e.preventDefault();show("yes");send("yes");});})();</script>';
	return $out;
} );

/** Make the /answer/ page once, as a normal Elementor page with the block on it. */
add_action( 'admin_init', function () {
	if ( get_option( 'ew_ou_page' ) || ! function_exists( 'ew_blocks_widget' ) ) {
		return;
	}
	$page = get_page_by_path( 'answer' );
	if ( ! $page ) {
		$pid = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Your answer', 'post_name' => 'answer' ) );
		if ( ! $pid || is_wp_error( $pid ) ) {
			return;
		}
		update_post_meta( $pid, '_wp_page_template', 'elementor_header_footer' );
		$page = get_post( $pid );
		ew_blocks_write( $page, array( ew_blocks_row( array( ew_blocks_widget( array( 'block' => 'eastwood_answer' ) ) ) ) ) );
	}
	update_option( 'ew_ou_page', (int) $page->ID );
} );

/* --- The screen: Bookings -> Order update ------------------------------- */

add_action( 'admin_menu', function () {
	add_submenu_page( 'edit.php?post_type=ewt_booking', 'Order update email', 'Order update email', 'edit_posts', 'ew-order-update', 'ew_ou_page' );
}, 23 );

/** Everyone claimed or paid for an event: one row per booking. */
function ew_ou_people( $event ) {
	$out = array();
	foreach ( get_posts( array( 'post_type' => 'ewt_booking', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'date', 'order' => 'ASC', 'meta_query' => array( array( 'key' => '_ewt_event', 'value' => $event ) ) ) ) as $id ) { // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( ! in_array( (string) get_post_meta( $id, '_ewt_status', true ), array( 'claimed', 'paid' ), true ) ) {
			continue;
		}
		$em = strtolower( trim( (string) get_post_meta( $id, '_ewt_email', true ) ) );
		if ( ! is_email( $em ) ) {
			continue;
		}
		$out[ $id ] = $em;
	}
	return $out;
}

function ew_ou_page() {
	$o      = ew_ou_get();
	$events = function_exists( 'ew_eb_events' ) ? ew_eb_events() : array();
	$event  = sanitize_text_field( wp_unslash( $_POST['event'] ?? ( $_GET['event'] ?? '' ) ) );
	$step   = sanitize_key( $_POST['step'] ?? '' );
	echo '<div class="wrap"><h1>Order update email</h1>';

	if ( 'words' === $step && check_admin_referer( 'ew_ou' ) ) {
		$new = array();
		foreach ( array_keys( ew_ou_defaults() ) as $k ) {
			if ( isset( $_POST[ 'ou_' . $k ] ) ) {
				$new[ $k ] = in_array( $k, array( 'rows', 'logos', 'intro' ), true )
					? sanitize_textarea_field( wp_unslash( $_POST[ 'ou_' . $k ] ) )
					: sanitize_text_field( wp_unslash( $_POST[ 'ou_' . $k ] ) );
			}
		}
		update_option( 'ew_ou', $new );
		$o = ew_ou_get();
		echo '<div class="notice notice-success"><p>Saved.</p></div>';
	}

	$people = $event ? ew_ou_people( $event ) : array();

	if ( 'test' === $step && check_admin_referer( 'ew_ou' ) && $people ) {
		$to = sanitize_email( wp_unslash( $_POST['test_to'] ?? '' ) );
		$ok = is_email( $to ) && ew_ou_send( $to, (int) array_key_first( $people ), 'testtest' );
		echo '<div class="notice notice-' . ( $ok ? 'success' : 'error' ) . '"><p>' . ( $ok ? 'Test sent to ' . esc_html( $to ) . ', written as the first booking on the list.' : 'The test could not be sent.' ) . '</p></div>';
	}

	if ( 'send' === $step && check_admin_referer( 'ew_ou' ) && $people ) {
		$pick = array_map( 'intval', (array) ( $_POST['to'] ?? array() ) );
		$sent = 0;
		$fail = array();
		foreach ( $people as $bid => $em ) {
			if ( ! in_array( $bid, $pick, true ) || get_post_meta( $bid, '_ewt_ou_sent', true ) ) {
				continue;
			}
			if ( ew_ou_send( $em, $bid ) ) {
				update_post_meta( $bid, '_ewt_ou_sent', time() );
				$evs = (array) get_option( 'ew_ou_events', array() );
				if ( ! in_array( $event, $evs, true ) ) {
					$evs[] = $event;
					update_option( 'ew_ou_events', $evs, false );
				}
				$sent++;
			} else {
				$fail[] = $em;
			}
		}
		echo '<div class="notice notice-' . ( $fail ? 'warning' : 'success' ) . '"><p>Sent ' . (int) $sent . '.' . ( $fail ? ' Could not send to: ' . esc_html( implode( ', ', $fail ) ) . '.' : '' ) . '</p></div>';
	}

	echo '<form method="post">';
	wp_nonce_field( 'ew_ou' );
	echo '<p><select name="event">';
	foreach ( $events as $ev => $n ) {
		echo '<option value="' . esc_attr( $ev ) . '"' . selected( $ev, $event, false ) . '>' . esc_html( $ev ) . '</option>';
	}
	echo '</select> <button class="button" name="step" value="show">Show who it goes to</button></p>';

	if ( $people ) {
		$n    = array( 'yes' => 0, 'no' => 0, 'none' => 0, 'sent' => 0, 'app' => 0 );
		$rows = '';
		foreach ( $people as $bid => $em ) {
			$ans  = (string) get_post_meta( $bid, '_ewt_answer', true );
			$sent = (int) get_post_meta( $bid, '_ewt_ou_sent', true );
			$n[ '' === $ans ? 'none' : $ans ]++;
			$n['sent'] += $sent ? 1 : 0;
			$inapp      = (int) get_post_meta( $bid, '_ewt_in_app', true );
			$n['app']  += ( $sent && $inapp >= $sent ) ? 1 : 0;
			$rows .= '<tr><td><input type="checkbox" name="to[]" value="' . (int) $bid . '"' . ( $sent ? '' : ' checked' ) . '></td><td>' . esc_html( get_post_meta( $bid, '_ewt_name', true ) ) . '</td><td>' . esc_html( $em ) . '</td><td>'
				. esc_html( function_exists( 'ew_booking_code' ) ? ew_booking_code( $bid ) : '' ) . '</td><td>' . esc_html( get_post_meta( $bid, '_ewt_lines', true ) ) . '</td><td>'
				. ( $sent ? esc_html( wp_date( 'j M, g:ia', $sent ) ) : '' ) . '</td><td>' . ( 'yes' === $ans ? '<strong>Coming</strong>' : ( 'no' === $ans ? "Can't make it" : '' ) ) . '</td><td>' . ( $inapp ? 'Yes' : '' ) . '</td></tr>';
		}
		echo '<p style="font-size:16px"><strong>Coming ' . (int) $n['yes'] . " · Can't make it " . (int) $n['no'] . ' · No answer ' . (int) $n['none'] . ' · Got the app since the email ' . (int) $n['app'] . '</strong> · Sent ' . (int) $n['sent'] . ' of ' . count( $people ) . '</p>';
		$me = wp_get_current_user();
		echo '<p><label>Send a test to <input type="email" name="test_to" value="' . esc_attr( $me ? $me->user_email : '' ) . '" class="regular-text"></label> <button class="button" name="step" value="test">Send a test</button></p>';
		echo '<table class="widefat striped"><thead><tr><th></th><th>Name</th><th>Email</th><th>Reference</th><th>Party</th><th>Sent</th><th>Answer</th><th>In the app</th></tr></thead><tbody>' . $rows . '</tbody></table>';
		echo '<p><button class="button button-primary" name="step" value="send">Send to the ticked bookings</button></p>';
	}

	echo '<h2 style="margin-top:36px">Words and pictures</h2><table class="form-table">';
	foreach ( $o as $k => $v ) {
		$big = in_array( $k, array( 'rows', 'logos', 'intro' ), true );
		echo '<tr><th>' . esc_html( $k ) . '</th><td>' . ( $big ? '<textarea name="ou_' . esc_attr( $k ) . '" rows="' . ( 'rows' === $k ? 8 : 3 ) . '" class="large-text">' . esc_textarea( $v ) . '</textarea>' : '<input type="text" name="ou_' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '" class="large-text">' ) . '</td></tr>';
	}
	echo '</table><p class="description">{ref} and {first_name} are filled in for each booking. Getting here: one row per line, title | text.</p>';
	echo '<p><button class="button" name="step" value="words">Save words</button></p></form></div>';
}
