<?php
/**
 * Academy: ask a question, register interest, book the 1:1.
 *
 * Three blocks for the Academy funnel that runs after a trial day.
 *
 * [eastwood_ask]       A question sent privately, answered at the end of the
 *                      session. Name and email are both optional. Booking
 *                      status "question".
 * [eastwood_interest]  A family registers interest in a place on the
 *                      programme. Booking status "interest". The email the
 *                      academy gets carries an "Accept and invite" link; one
 *                      press sends the family their own link to book a 1:1.
 *                      Status then "invited".
 * [eastwood_meeting]   The page that link opens. The family picks one of the
 *                      times written on the block (one per line), and the
 *                      booking becomes status "meeting" with that time on it.
 *                      A time one family has taken is not offered to the next.
 *
 * Nothing is charged anywhere here. Every word shown is a field on the block,
 * and the slot times are the block body, so the academy changes them on the
 * page. Every one of these lands in Bookings next to the tickets and trials.
 *
 * @package eastwood-fwp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ------------------------------------------------------------------ */
/* Fields and their words until somebody changes them                  */
/* ------------------------------------------------------------------ */

function ew_acad_ask_defaults() {
	return array(
		'event'         => 'Academy trial day',
		'form_title'    => 'Ask us anything',
		'form_note'     => 'Nobody else sees this. We will answer it at the end of the session.',
		'q_label'       => 'Your question',
		'name_label'    => 'Your name (optional)',
		'email_label'   => 'Your email (optional, if you would like a reply)',
		'send'          => 'Send my question',
		'need_q'        => 'Type your question first.',
		'bad_email'     => 'That email does not look right.',
		'failed'        => 'Something went wrong. Please try again.',
		'done_title'    => 'Got it',
		'done_note'     => 'Thank you. We will answer it at the end of the session.',
		'again'         => 'Ask another question',
		'crm_hook'      => '',
	);
}

function ew_acad_interest_defaults() {
	return array(
		'event'          => 'Academy interest',
		'form_title'     => 'Register your interest',
		'form_note'      => 'We will look at every one and invite you to a 1:1 meeting.',
		'name_label'     => "Player's full name",
		'dob_label'      => 'Date of birth',
		'school_label'   => 'Current school',
		'club_label'     => 'Current football club',
		'loc_label'      => 'Where you live',
		'parent_label'   => 'Parent or guardian name',
		'email_label'    => 'Your email',
		'phone_label'    => 'Your mobile',
		'show_dob'       => '1',
		'show_school'    => '1',
		'show_club'      => '1',
		'show_loc'       => '1',
		'show_parent'    => '1',
		'consent'        => 'Keep me up to date about the Academy',
		'send'           => 'Register interest',
		'free_note'      => 'Free. Nothing is taken from you on this page.',
		'need_name'      => 'We need the player name.',
		'need_email'     => 'We need an email to get back to you on.',
		'need_phone'     => 'We need a mobile number.',
		'failed'         => 'Something went wrong. Please try again.',
		'done_title'     => 'Thank you',
		'done_note'      => 'We will be in touch by email to invite you to a 1:1 meeting.',
		'meeting_page'   => '/academy/meeting/',
		'invite_subject' => 'Your invitation to a 1:1 with the Eastwood FC Academy',
		'invite_body'    => "Hi {parent},\n\nThank you for registering {player} with the Eastwood FC Academy. We would like to invite you to a 1:1 meeting with Lewis McGugan, Head of Academy, and Owen Betts, Head Tutor.\n\nPick a time that suits you here:\n{link}\n\nThank you,\nEastwood FC Academy",
		'crm_hook'       => '',
	);
}

function ew_acad_meeting_defaults() {
	return array(
		'event'           => 'Academy 1:1 meeting',
		'title'           => 'Book your 1:1',
		'note'            => 'With Lewis McGugan, Head of Academy, and Owen Betts, Head Tutor.',
		'where'           => '',
		'hello'           => 'Hi {parent}, pick a time for {player}.',
		'pick'            => 'Book this time',
		'none'            => 'There are no times free right now. We will email you as soon as there are.',
		'no_key'          => 'This page is for families we have invited. Use the link in your email to book.',
		'booked_title'    => 'You are booked',
		'booked_note'     => 'We have sent the details to your email. See you then.',
		'taken'           => 'Somebody has just taken that time. Please pick another.',
		'loading'         => 'Loading the times',
		'failed'          => 'Something went wrong. Please try again.',
		'confirm_subject' => 'Your Eastwood FC Academy 1:1: {time}',
		'confirm_body'    => "Hi {parent},\n\nYou are booked in for a 1:1 meeting about {player} with Lewis McGugan, Head of Academy, and Owen Betts, Head Tutor.\n\nWhen: {time}\n{where}\n\nIf you need to change it, reply to this email.\n\nThank you,\nEastwood FC Academy",
		'crm_hook'        => '',
	);
}

/* ------------------------------------------------------------------ */
/* Shared helpers                                                      */
/* ------------------------------------------------------------------ */

function ew_acad_on( $v ) {
	return '' !== trim( (string) $v ) && '0' !== trim( (string) $v );
}

/**
 * One block as it is written on a page, with its body.
 *
 * Read from the page itself (Elementor's copy first, then the content), so
 * whatever the academy typed into the block is what a submission is checked
 * against -- never what the browser says.
 *
 * @return array|null array( atts, body )
 */
function ew_acad_block_on( $post, $tag ) {
	if ( ! $post ) {
		return null;
	}
	$found = null;
	$data  = json_decode( (string) get_post_meta( $post->ID, '_elementor_data', true ), true );
	if ( is_array( $data ) ) {
		$walk = function ( $nodes ) use ( &$walk, &$found, $tag ) {
			foreach ( (array) $nodes as $n ) {
				if ( $found ) {
					return;
				}
				$sc = (string) ( $n['settings']['shortcode'] ?? '' );
				if ( 0 === strpos( ltrim( $sc ), '[' . $tag ) ) {
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
	if ( ! $found ) {
		$found = (string) $post->post_content;
	}
	if ( ! preg_match( '/\[' . preg_quote( $tag, '/' ) . '(\s[^\]]*)?\]/', $found, $o, PREG_OFFSET_CAPTURE ) ) {
		return null;
	}
	$atts = isset( $o[1][0] ) ? (array) shortcode_parse_atts( $o[1][0] ) : array();
	$body = '';
	$from = $o[0][1] + strlen( $o[0][0] );
	$end  = strpos( $found, '[/' . $tag . ']', $from );
	if ( false !== $end ) {
		$body = substr( $found, $from, $end - $from );
	}
	return array( 'atts' => $atts, 'body' => $body );
}

/** Fill {name} style holes in a line of words. */
function ew_acad_fill( $text, $vals ) {
	foreach ( $vals as $k => $v ) {
		$text = str_replace( '{' . $k . '}', (string) $v, $text );
	}
	return $text;
}

/** Write a booking and its fields. Returns the id or 0. */
function ew_acad_booking( $title, $fields ) {
	$id = wp_insert_post( array(
		'post_type'   => 'ewt_booking',
		'post_status' => 'publish',
		'post_title'  => $title,
	) );
	if ( ! $id || is_wp_error( $id ) ) {
		return 0;
	}
	// Status last, so the email about it carries everything else.
	$status = $fields['status'] ?? '';
	unset( $fields['status'] );
	foreach ( $fields as $k => $v ) {
		update_post_meta( $id, '_ewt_' . $k, $v );
	}
	update_post_meta( $id, '_ewt_status', $status );
	return (int) $id;
}

function ew_acad_m( $id, $k ) {
	return trim( (string) get_post_meta( $id, '_ewt_' . $k, true ) );
}

/** The two field rows a form is made of. */
function ew_acad_field( $id, $label, $type, $auto, $extra = '' ) {
	return '<label class="ewtr-lab" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>'
		. '<input class="ewtr-in" id="' . esc_attr( $id ) . '" type="' . esc_attr( $type ) . '" autocomplete="' . esc_attr( $auto ) . '"' . $extra . '>';
}

/* ------------------------------------------------------------------ */
/* [eastwood_ask]                                                      */
/* ------------------------------------------------------------------ */

function ew_acad_ask_shortcode( $atts ) {
	$a    = shortcode_atts( ew_acad_ask_defaults(), (array) $atts, 'eastwood_ask' );
	$post = get_post();
	$w    = array(
		'need_q'    => $a['need_q'],
		'bad_email' => $a['bad_email'],
		'failed'    => $a['failed'],
		'rest'      => esc_url_raw( rest_url( 'eastwood/v1/academy/ask' ) ),
	);
	$h  = '<section class="ewtr ewac ewac-ask" data-page="' . (int) ( $post ? $post->ID : 0 ) . '">';
	$h .= '<form class="ewtr-form" novalidate>';
	$h .= '<p class="ewtr-head">' . esc_html( $a['form_title'] ) . '</p>';
	if ( '' !== trim( (string) $a['form_note'] ) ) {
		$h .= '<p class="ewtr-note">' . esc_html( $a['form_note'] ) . '</p>';
	}
	$h .= '<label class="ewtr-lab" for="ewacQ">' . esc_html( $a['q_label'] ) . '</label>';
	$h .= '<textarea class="ewtr-in ewac-ta" id="ewacQ" rows="5"></textarea>';
	$h .= ew_acad_field( 'ewacName', $a['name_label'], 'text', 'name' );
	$h .= ew_acad_field( 'ewacEmail', $a['email_label'], 'email', 'email' );
	$h .= '<p class="ewtr-err" role="alert"></p>';
	$h .= '<button type="submit" class="ewtr-btn">' . esc_html( $a['send'] ) . '</button>';
	$h .= '</form>';
	$h .= '<div class="ewtr-done" hidden><p class="ewtr-done-t">' . esc_html( $a['done_title'] ) . '</p><p class="ewtr-done-n">' . esc_html( $a['done_note'] ) . '</p>';
	$h .= '<button type="button" class="ewac-again">' . esc_html( $a['again'] ) . '</button></div>';
	$h .= '<script type="application/json" class="ewac-words">' . wp_json_encode( $w ) . '</script>';
	return $h . '</section>';
}
add_shortcode( 'eastwood_ask', 'ew_acad_ask_shortcode' );

/* ------------------------------------------------------------------ */
/* [eastwood_interest]                                                 */
/* ------------------------------------------------------------------ */

function ew_acad_interest_shortcode( $atts ) {
	$a    = shortcode_atts( ew_acad_interest_defaults(), (array) $atts, 'eastwood_interest' );
	$post = get_post();
	$w    = array(
		'need_name'  => $a['need_name'],
		'need_email' => $a['need_email'],
		'need_phone' => $a['need_phone'],
		'failed'     => $a['failed'],
		'rest'       => esc_url_raw( rest_url( 'eastwood/v1/academy/interest' ) ),
	);
	$h  = '<section class="ewtr ewac ewac-int" data-page="' . (int) ( $post ? $post->ID : 0 ) . '">';
	$h .= '<form class="ewtr-form" novalidate>';
	$h .= '<p class="ewtr-head">' . esc_html( $a['form_title'] ) . '</p>';
	if ( '' !== trim( (string) $a['form_note'] ) ) {
		$h .= '<p class="ewtr-note">' . esc_html( $a['form_note'] ) . '</p>';
	}
	$h .= ew_acad_field( 'ewacName', $a['name_label'], 'text', 'off' );
	if ( ew_acad_on( $a['show_dob'] ) ) {
		$h .= ew_acad_field( 'ewacDob', $a['dob_label'], 'date', 'off' );
	}
	if ( ew_acad_on( $a['show_school'] ) ) {
		$h .= ew_acad_field( 'ewacSchool', $a['school_label'], 'text', 'off' );
	}
	if ( ew_acad_on( $a['show_club'] ) ) {
		$h .= ew_acad_field( 'ewacClub', $a['club_label'], 'text', 'off' );
	}
	if ( ew_acad_on( $a['show_loc'] ) ) {
		$h .= ew_acad_field( 'ewacLoc', $a['loc_label'], 'text', 'address-level2' );
	}
	if ( ew_acad_on( $a['show_parent'] ) ) {
		$h .= ew_acad_field( 'ewacParent', $a['parent_label'], 'text', 'name' );
	}
	$h .= ew_acad_field( 'ewacEmail', $a['email_label'], 'email', 'email' );
	$h .= ew_acad_field( 'ewacPhone', $a['phone_label'], 'tel', 'tel' );
	$h .= '<label class="ewtr-tick"><input type="checkbox" id="ewacConsent" checked><span>' . esc_html( $a['consent'] ) . '</span></label>';
	$h .= '<p class="ewtr-err" role="alert"></p>';
	$h .= '<button type="submit" class="ewtr-btn">' . esc_html( $a['send'] ) . '</button>';
	if ( '' !== trim( (string) $a['free_note'] ) ) {
		$h .= '<p class="ewtr-free">' . esc_html( $a['free_note'] ) . '</p>';
	}
	$h .= '</form>';
	$h .= '<div class="ewtr-done" hidden><p class="ewtr-done-t">' . esc_html( $a['done_title'] ) . '</p><p class="ewtr-done-n">' . esc_html( $a['done_note'] ) . '</p></div>';
	$h .= '<script type="application/json" class="ewac-words">' . wp_json_encode( $w ) . '</script>';
	return $h . '</section>';
}
add_shortcode( 'eastwood_interest', 'ew_acad_interest_shortcode' );

/* ------------------------------------------------------------------ */
/* [eastwood_meeting]                                                  */
/* ------------------------------------------------------------------ */

/**
 * The times written on the block, one per line: "2026-11-04 17:00".
 * Anything that does not read as a date and time is skipped.
 *
 * @return array<string,string> key "Y-m-d H:i" => label "Wed 4 Nov, 5:00pm"
 */
function ew_acad_slots( $body ) {
	$out  = array();
	$tz   = wp_timezone();
	$body = wp_strip_all_tags( str_replace( array( '<br>', '<br />', '</p>' ), "\n", (string) $body ) );
	foreach ( preg_split( '/[\r\n;|]+/', $body ) as $line ) {
		$line = trim( $line );
		if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2})\s+(\d{1,2}:\d{2})$/', $line, $m ) ) {
			continue;
		}
		$d = DateTime::createFromFormat( 'Y-m-d H:i', $m[1] . ' ' . $m[2], $tz );
		if ( ! $d ) {
			continue;
		}
		$out[ $d->format( 'Y-m-d H:i' ) ] = $d->format( 'D j M, g:ia' );
	}
	ksort( $out );
	return $out;
}

/** Times already booked on a meeting page, by anybody. */
function ew_acad_taken( $page_id ) {
	$q = new WP_Query( array(
		'post_type'      => 'ewt_booking',
		'post_status'    => 'publish',
		'posts_per_page' => 500,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'meta_query'     => array(
			array( 'key' => '_ewt_mpage', 'value' => (string) (int) $page_id ),
			array( 'key' => '_ewt_status', 'value' => 'meeting' ),
		),
	) );
	$taken = array();
	foreach ( $q->posts as $bid ) {
		$s = ew_acad_m( $bid, 'slot' );
		if ( '' !== $s ) {
			$taken[ $s ] = (int) $bid;
		}
	}
	return $taken;
}

/** The interest booking a family link belongs to. */
function ew_acad_by_key( $key ) {
	$key = preg_replace( '/[^A-Za-z0-9]/', '', (string) $key );
	if ( strlen( $key ) < 16 ) {
		return 0;
	}
	$q = new WP_Query( array(
		'post_type'      => 'ewt_booking',
		'post_status'    => 'publish',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'meta_query'     => array( array( 'key' => '_ewt_mkey', 'value' => $key ) ),
	) );
	return $q->posts ? (int) $q->posts[0] : 0;
}

function ew_acad_meeting_shortcode( $atts, $content = '' ) {
	$a    = shortcode_atts( ew_acad_meeting_defaults(), (array) $atts, 'eastwood_meeting' );
	$post = get_post();
	$w    = array(
		'pick'    => $a['pick'],
		'none'    => $a['none'],
		'no_key'  => $a['no_key'],
		'taken'   => $a['taken'],
		'failed'  => $a['failed'],
		'booked_title' => $a['booked_title'],
		'booked_note'  => $a['booked_note'],
		'rest'    => esc_url_raw( rest_url( 'eastwood/v1/academy/meeting' ) ),
	);
	$h  = '<section class="ewtr ewac ewac-meet" data-page="' . (int) ( $post ? $post->ID : 0 ) . '">';
	$h .= '<div class="ewtr-form">';
	$h .= '<p class="ewtr-head">' . esc_html( $a['title'] ) . '</p>';
	if ( '' !== trim( (string) $a['note'] ) ) {
		$h .= '<p class="ewtr-note">' . esc_html( $a['note'] ) . '</p>';
	}
	if ( '' !== trim( (string) $a['where'] ) ) {
		$h .= '<p class="ewtr-note">' . esc_html( $a['where'] ) . '</p>';
	}
	$h .= '<p class="ewac-hello" hidden></p>';
	$h .= '<div class="ewac-slots"><p class="ewac-msg">' . esc_html( $a['loading'] ) . '</p></div>';
	$h .= '<p class="ewtr-err" role="alert"></p>';
	$h .= '</div>';
	$h .= '<div class="ewtr-done" hidden><p class="ewtr-done-t"></p><p class="ewac-when"></p><p class="ewtr-done-n"></p></div>';
	$h .= '<script type="application/json" class="ewac-words">' . wp_json_encode( $w ) . '</script>';
	return $h . '</section>';
}
add_shortcode( 'eastwood_meeting', 'ew_acad_meeting_shortcode' );

/* ------------------------------------------------------------------ */
/* Accepting an interest and sending the family their link             */
/* ------------------------------------------------------------------ */

/** The one-press link in the academy email. Signed, so it cannot be guessed. */
function ew_acad_accept_url( $id ) {
	$id = (int) $id;
	return add_query_arg( array(
		'ew_acad_accept' => $id,
		't'              => substr( wp_hash( 'acad-accept|' . $id ), 0, 20 ),
	), home_url( '/' ) );
}

/** The interest block on the page a booking came from. */
function ew_acad_interest_atts_for( $id ) {
	$post = get_post( (int) ew_acad_m( $id, 'page' ) );
	$blk  = $post ? ew_acad_block_on( $post, 'eastwood_interest' ) : null;
	return shortcode_atts( ew_acad_interest_defaults(), $blk ? $blk['atts'] : array(), 'eastwood_interest' );
}

/** Accept one interest: make the family a link, email it, mark it invited. */
function ew_acad_invite( $id ) {
	$id = (int) $id;
	if ( 'ewt_booking' !== get_post_type( $id ) ) {
		return false;
	}
	$a   = ew_acad_interest_atts_for( $id );
	$key = ew_acad_m( $id, 'mkey' );
	if ( '' === $key ) {
		$key = wp_generate_password( 24, false, false );
		update_post_meta( $id, '_ewt_mkey', $key );
	}
	$page = trim( (string) $a['meeting_page'] );
	$link = add_query_arg( 'k', $key, 0 === strpos( $page, 'http' ) ? $page : home_url( $page ) );
	$vals = array(
		'parent' => '' !== ew_acad_m( $id, 'parent' ) ? ew_acad_m( $id, 'parent' ) : 'there',
		'player' => ew_acad_m( $id, 'name' ),
		'link'   => $link,
	);
	$sent = wp_mail(
		ew_acad_m( $id, 'email' ),
		wp_specialchars_decode( ew_acad_fill( $a['invite_subject'], $vals ), ENT_QUOTES ),
		ew_acad_fill( str_replace( '\n', "\n", (string) $a['invite_body'] ), $vals ),
		array( 'From: Eastwood FC <info@eastwoodfc.com>' )
	);
	update_post_meta( $id, '_ewt_invited', time() );
	if ( 'meeting' !== ew_acad_m( $id, 'status' ) ) {
		update_post_meta( $id, '_ewt_status', 'invited' );
	}
	if ( function_exists( 'ew_tix_crm' ) ) {
		ew_tix_crm( (string) $a['crm_hook'], $id, 'invited' );
	}
	return (bool) $sent;
}

/** A plain page for the accept link: look first, then press. */
function ew_acad_accept_page( $title, $body ) {
	status_header( 200 );
	nocache_headers();
	header( 'Content-Type: text/html; charset=utf-8' );
	echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">';
	echo '<title>' . esc_html( $title ) . '</title><style>body{margin:0;background:#f3f3f3;font:17px/1.55 system-ui,sans-serif;color:#101010}main{max-width:560px;margin:48px auto;padding:0 20px}.c{background:#fff;border-radius:14px;padding:32px}h1{margin:0 0 16px;font-size:28px}dl{margin:0 0 24px}dt{font-size:13px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#666;margin-top:12px}dd{margin:2px 0 0}button{width:100%;height:56px;border:0;border-radius:8px;background:#CC0000;color:#fff;font-size:18px;font-weight:700;cursor:pointer}p.ok{font-size:19px}</style></head><body><main><div class="c">';
	echo '<h1>' . esc_html( $title ) . '</h1>' . $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts below
	echo '</div></main></body></html>';
	exit;
}

add_action( 'template_redirect', function () {
	if ( empty( $_GET['ew_acad_accept'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	$id = (int) $_GET['ew_acad_accept']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$t  = isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( $_GET['t'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( 'ewt_booking' !== get_post_type( $id ) || ! hash_equals( substr( wp_hash( 'acad-accept|' . $id ), 0, 20 ), $t ) ) {
		ew_acad_accept_page( 'Link not recognised', '<p>This accept link is not valid. Open the booking in Bookings instead.</p>' );
	}
	$player = ew_acad_m( $id, 'name' );
	$status = ew_acad_m( $id, 'status' );
	$rows   = array(
		'Player'           => $player,
		'Date of birth'    => ew_acad_m( $id, 'dob' ),
		'School'           => ew_acad_m( $id, 'school' ),
		'Current club'     => ew_acad_m( $id, 'club' ),
		'Where they live'  => ew_acad_m( $id, 'loc' ),
		'Parent or guardian' => ew_acad_m( $id, 'parent' ),
		'Email'            => ew_acad_m( $id, 'email' ),
		'Mobile'           => ew_acad_m( $id, 'phone' ),
	);
	$dl = '<dl>';
	foreach ( $rows as $k => $v ) {
		if ( '' !== $v ) {
			$dl .= '<dt>' . esc_html( $k ) . '</dt><dd>' . esc_html( $v ) . '</dd>';
		}
	}
	$dl .= '</dl>';

	// A press, not a visit: mail scanners open links, and must not invite anyone.
	if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( 'meeting' === $status ) {
			ew_acad_accept_page( 'Already booked', $dl . '<p class="ok">' . esc_html( $player ) . ' already has a 1:1 booked: ' . esc_html( ew_acad_m( $id, 'slot_label' ) ) . '.</p>' );
		}
		$ok = ew_acad_invite( $id );
		ew_acad_accept_page(
			$ok ? 'Invitation sent' : 'Accepted, but the email did not send',
			$dl . '<p class="ok">' . ( $ok
				? 'The family has been emailed a link to book their 1:1.'
				: 'The booking is marked invited, but the email failed. Check Bookings and send it again from there.' ) . '</p>'
		);
	}
	if ( 'meeting' === $status ) {
		ew_acad_accept_page( 'Already booked', $dl . '<p class="ok">1:1 booked: ' . esc_html( ew_acad_m( $id, 'slot_label' ) ) . '.</p>' );
	}
	$again = 'invited' === $status ? '<p>Already invited. Pressing again sends the same link again.</p>' : '';
	ew_acad_accept_page(
		'Accept and invite to a 1:1',
		$dl . $again . '<form method="post"><button type="submit">Accept and send the invitation</button></form>'
	);
} );

/* Bookings list: the same action, for whoever is in the admin. */
add_filter( 'post_row_actions', function ( $actions, $post ) {
	if ( 'ewt_booking' === $post->post_type && in_array( ew_acad_m( $post->ID, 'status' ), array( 'interest', 'invited' ), true ) ) {
		$actions['ewac_accept'] = '<a href="' . esc_url( ew_acad_accept_url( $post->ID ) ) . '" target="_blank">Accept and invite to 1:1</a>';
	}
	return $actions;
}, 10, 2 );

/* ------------------------------------------------------------------ */
/* REST                                                                */
/* ------------------------------------------------------------------ */

add_action( 'rest_api_init', function () {

	register_rest_route( 'eastwood/v1', '/academy/ask', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) {
			$post = get_post( (int) $req->get_param( 'page' ) );
			$blk  = $post ? ew_acad_block_on( $post, 'eastwood_ask' ) : null;
			if ( ! $blk ) {
				return new WP_REST_Response( array( 'message' => 'no block' ), 400 );
			}
			$a     = shortcode_atts( ew_acad_ask_defaults(), $blk['atts'], 'eastwood_ask' );
			$q     = trim( sanitize_textarea_field( (string) $req->get_param( 'q' ) ) );
			$name  = trim( sanitize_text_field( (string) $req->get_param( 'name' ) ) );
			$email = trim( (string) $req->get_param( 'email' ) );
			if ( '' === $q ) {
				return new WP_REST_Response( array( 'message' => $a['need_q'] ), 400 );
			}
			if ( '' !== $email && ! is_email( $email ) ) {
				return new WP_REST_Response( array( 'message' => $a['bad_email'] ), 400 );
			}
			$q  = mb_substr( $q, 0, 2000 );
			$id = ew_acad_booking( '' !== $name ? $name : 'Question', array(
				'name'   => $name,
				'email'  => sanitize_email( $email ),
				'event'  => '' !== trim( (string) $a['event'] ) ? $a['event'] : get_the_title( $post ),
				'page'   => $post->ID,
				'url'    => get_permalink( $post ),
				'lines'  => $q,
				'extra'  => 'Question: ' . $q,
				'total'  => 0,
				'hook'   => (string) $a['crm_hook'],
				'status' => 'question',
			) );
			if ( ! $id ) {
				return new WP_REST_Response( array( 'message' => $a['failed'] ), 500 );
			}
			if ( '' !== $email && function_exists( 'ew_tix_crm' ) ) {
				ew_tix_crm( (string) $a['crm_hook'], $id, 'question' );
			}
			return array( 'ok' => 1 );
		},
	) );

	register_rest_route( 'eastwood/v1', '/academy/interest', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) {
			$post = get_post( (int) $req->get_param( 'page' ) );
			$blk  = $post ? ew_acad_block_on( $post, 'eastwood_interest' ) : null;
			if ( ! $blk ) {
				return new WP_REST_Response( array( 'message' => 'no block' ), 400 );
			}
			$a = shortcode_atts( ew_acad_interest_defaults(), $blk['atts'], 'eastwood_interest' );
			$v = array();
			foreach ( array( 'name', 'dob', 'school', 'club', 'loc', 'parent', 'phone' ) as $k ) {
				$v[ $k ] = trim( sanitize_text_field( (string) $req->get_param( $k ) ) );
			}
			$email = sanitize_email( (string) $req->get_param( 'email' ) );
			if ( '' === $v['name'] ) {
				return new WP_REST_Response( array( 'message' => $a['need_name'] ), 400 );
			}
			if ( ! is_email( $email ) ) {
				return new WP_REST_Response( array( 'message' => $a['need_email'] ), 400 );
			}
			if ( strlen( preg_replace( '/\D/', '', $v['phone'] ) ) < 10 ) {
				return new WP_REST_Response( array( 'message' => $a['need_phone'] ), 400 );
			}
			$about = array_filter( array(
				'' !== $v['dob'] ? 'born ' . $v['dob'] : '',
				$v['school'],
				$v['club'],
				$v['loc'],
			) );
			$about = implode( ', ', $about );
			$id    = ew_acad_booking( $v['name'], array(
				'name'     => $v['name'],
				'dob'      => $v['dob'],
				'school'   => $v['school'],
				'club'     => $v['club'],
				'loc'      => $v['loc'],
				'parent'   => $v['parent'],
				'guardian' => $v['parent'],
				'email'    => $email,
				'phone'    => $v['phone'],
				'event'    => '' !== trim( (string) $a['event'] ) ? $a['event'] : get_the_title( $post ),
				'page'     => $post->ID,
				'url'      => get_permalink( $post ),
				'lines'    => '' !== $about ? $about : $v['name'],
				'extra'    => 'Academy interest' . ( '' !== $about ? ': ' . $about : '' ),
				'total'    => 0,
				'consent'  => $req->get_param( 'consent' ) ? 'yes' : 'no',
				'hook'     => (string) $a['crm_hook'],
				'status'   => 'interest',
			) );
			if ( ! $id ) {
				return new WP_REST_Response( array( 'message' => $a['failed'] ), 500 );
			}
			if ( function_exists( 'ew_tix_crm' ) ) {
				ew_tix_crm( (string) $a['crm_hook'], $id, 'interest' );
			}
			return array( 'ok' => 1 );
		},
	) );

	/* The family page reads its times here, so nothing personal is ever in cached HTML. */
	register_rest_route( 'eastwood/v1', '/academy/meeting', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => function ( $req ) {
				nocache_headers();
				$post = get_post( (int) $req->get_param( 'page' ) );
				$blk  = $post ? ew_acad_block_on( $post, 'eastwood_meeting' ) : null;
				$id   = ew_acad_by_key( $req->get_param( 'k' ) );
				if ( ! $blk || ! $id ) {
					return array( 'ok' => 0 );
				}
				$a    = shortcode_atts( ew_acad_meeting_defaults(), $blk['atts'], 'eastwood_meeting' );
				$vals = array(
					'parent' => '' !== ew_acad_m( $id, 'parent' ) ? ew_acad_m( $id, 'parent' ) : 'there',
					'player' => ew_acad_m( $id, 'name' ),
				);
				if ( 'meeting' === ew_acad_m( $id, 'status' ) && '' !== ew_acad_m( $id, 'slot' ) ) {
					return array( 'ok' => 1, 'booked' => ew_acad_m( $id, 'slot_label' ), 'hello' => ew_acad_fill( $a['hello'], $vals ) );
				}
				$now   = current_time( 'Y-m-d H:i' );
				$taken = ew_acad_taken( $post->ID );
				$free  = array();
				foreach ( ew_acad_slots( $blk['body'] ) as $k => $label ) {
					if ( $k > $now && ! isset( $taken[ $k ] ) ) {
						$free[] = array( 'k' => $k, 'label' => $label );
					}
				}
				return array( 'ok' => 1, 'hello' => ew_acad_fill( $a['hello'], $vals ), 'slots' => $free );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => function ( $req ) {
				$post = get_post( (int) $req->get_param( 'page' ) );
				$blk  = $post ? ew_acad_block_on( $post, 'eastwood_meeting' ) : null;
				$id   = ew_acad_by_key( $req->get_param( 'k' ) );
				if ( ! $blk || ! $id ) {
					return new WP_REST_Response( array( 'message' => 'no' ), 400 );
				}
				$a     = shortcode_atts( ew_acad_meeting_defaults(), $blk['atts'], 'eastwood_meeting' );
				$slots = ew_acad_slots( $blk['body'] );
				$slot  = (string) $req->get_param( 'slot' );
				if ( 'meeting' === ew_acad_m( $id, 'status' ) ) {
					return array( 'ok' => 1, 'booked' => ew_acad_m( $id, 'slot_label' ) );
				}
				if ( ! isset( $slots[ $slot ] ) || $slot <= current_time( 'Y-m-d H:i' ) ) {
					return new WP_REST_Response( array( 'message' => $a['taken'] ), 409 );
				}
				// Hold the time with a lock so two families pressing together cannot both get it.
				$lock = 'ew_acad_lock_' . md5( $post->ID . '|' . $slot );
				if ( get_transient( $lock ) ) {
					return new WP_REST_Response( array( 'message' => $a['taken'] ), 409 );
				}
				set_transient( $lock, $id, 30 );
				$taken = ew_acad_taken( $post->ID );
				if ( isset( $taken[ $slot ] ) && $taken[ $slot ] !== $id ) {
					delete_transient( $lock );
					return new WP_REST_Response( array( 'message' => $a['taken'] ), 409 );
				}
				$label = $slots[ $slot ];
				update_post_meta( $id, '_ewt_slot', $slot );
				update_post_meta( $id, '_ewt_slot_label', $label );
				update_post_meta( $id, '_ewt_mpage', (string) $post->ID );
				update_post_meta( $id, '_ewt_lines', ew_acad_m( $id, 'lines' ) . ' -- 1:1 ' . $label );
				update_post_meta( $id, '_ewt_status', 'meeting' );
				delete_transient( $lock );

				$vals = array(
					'parent' => '' !== ew_acad_m( $id, 'parent' ) ? ew_acad_m( $id, 'parent' ) : 'there',
					'player' => ew_acad_m( $id, 'name' ),
					'time'   => $label,
					'where'  => '' !== trim( (string) $a['where'] ) ? 'Where: ' . $a['where'] : '',
				);
				wp_mail(
					ew_acad_m( $id, 'email' ),
					wp_specialchars_decode( ew_acad_fill( $a['confirm_subject'], $vals ), ENT_QUOTES ),
					preg_replace( "/\n{3,}/", "\n\n", ew_acad_fill( str_replace( '\n', "\n", (string) $a['confirm_body'] ), $vals ) ),
					array( 'From: Eastwood FC <info@eastwoodfc.com>' )
				);
				if ( function_exists( 'ew_tix_crm' ) ) {
					ew_tix_crm( (string) $a['crm_hook'], $id, 'meeting' );
				}
				return array( 'ok' => 1, 'booked' => $label );
			},
		),
	) );
} );

/* ------------------------------------------------------------------ */
/* Look and behaviour                                                  */
/* ------------------------------------------------------------------ */

function ew_acad_css() {
	return '
.ewac textarea.ewtr-in{height:auto;min-height:140px;padding:12px 14px;line-height:1.5;resize:vertical}
.ewac .ewac-again{display:inline-block;margin-top:22px;height:48px;padding:0 22px;border:1px solid rgba(255,255,255,.5);border-radius:8px;background:transparent;color:#fff;font:inherit;font-size:16px;font-weight:700;cursor:pointer}
.ewac .ewac-hello{margin:18px 0 6px;font-size:18px;line-height:1.45;color:#fff}
.ewac .ewac-slots{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px;margin-top:16px}
.ewac .ewac-slot{height:56px;border:1px solid rgba(255,255,255,.35);border-radius:8px;background:#111;color:#fff;font:inherit;font-size:17px;font-weight:700;cursor:pointer}
.ewac .ewac-slot:hover,.ewac .ewac-slot:focus-visible{border-color:#fff;outline:none}
.ewac .ewac-slot[disabled]{opacity:.5;cursor:default}
.ewac .ewac-msg{grid-column:1/-1;margin:0;font-size:16px;line-height:1.5;color:#bdbdbd}
.ewac .ewac-when{margin:0 0 12px;font-family:Anton,Impact,sans-serif;font-size:30px;line-height:1.1;text-transform:uppercase;color:#fff}
';
}

function ew_acad_js() {
	return <<<'JS'
(function(){
 function post(url, body){
   return fetch(url, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)})
     .then(function(r){ return r.json().then(function(j){ return {ok:r.ok, j:j}; }); });
 }
 function setup(root){
   var wEl = root.querySelector('.ewac-words'); if(!wEl) return;
   var W = JSON.parse(wEl.textContent), page = root.getAttribute('data-page');
   var err = root.querySelector('.ewtr-err');
   function val(id){ var el = root.querySelector('#' + id); return el ? el.value.trim() : ''; }

   if(root.classList.contains('ewac-ask')){
     var f = root.querySelector('.ewtr-form'), done = root.querySelector('.ewtr-done');
     f.addEventListener('submit', function(e){
       e.preventDefault(); err.textContent = '';
       var q = val('ewacQ'), email = val('ewacEmail');
       if(!q){ err.textContent = W.need_q; return; }
       if(email && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)){ err.textContent = W.bad_email; return; }
       var b = f.querySelector('.ewtr-btn'); b.disabled = true;
       post(W.rest, {page:page, q:q, name:val('ewacName'), email:email}).then(function(x){
         b.disabled = false;
         if(!x.ok){ err.textContent = (x.j && x.j.message) || W.failed; return; }
         f.hidden = true; done.hidden = false;
         if(window.ewTrack) ewTrack('Lead', {what:'Academy question'});
       }).catch(function(){ b.disabled = false; err.textContent = W.failed; });
     });
     root.querySelector('.ewac-again').addEventListener('click', function(){
       root.querySelector('#ewacQ').value = ''; done.hidden = true; f.hidden = false;
       root.querySelector('#ewacQ').focus();
     });
     return;
   }

   if(root.classList.contains('ewac-int')){
     var g = root.querySelector('.ewtr-form');
     g.addEventListener('submit', function(e){
       e.preventDefault(); err.textContent = '';
       var name = val('ewacName'), email = val('ewacEmail'), phone = val('ewacPhone');
       if(!name){ err.textContent = W.need_name; return; }
       if(!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)){ err.textContent = W.need_email; return; }
       if(phone.replace(/\D/g,'').length < 10){ err.textContent = W.need_phone; return; }
       var tick = root.querySelector('#ewacConsent');
       var b = g.querySelector('.ewtr-btn'); b.disabled = true;
       post(W.rest, {page:page, name:name, dob:val('ewacDob'), school:val('ewacSchool'), club:val('ewacClub'),
         loc:val('ewacLoc'), parent:val('ewacParent'), email:email, phone:phone, consent:(tick && tick.checked) ? 1 : 0})
       .then(function(x){
         if(!x.ok){ b.disabled = false; err.textContent = (x.j && x.j.message) || W.failed; return; }
         g.hidden = true; root.querySelector('.ewtr-done').hidden = false;
         if(window.ewTrack) ewTrack('Lead', {what:'Academy interest'});
       }).catch(function(){ b.disabled = false; err.textContent = W.failed; });
     });
     return;
   }

   if(root.classList.contains('ewac-meet')){
     var key = (new URLSearchParams(location.search)).get('k') || '';
     var box = root.querySelector('.ewac-slots'), hello = root.querySelector('.ewac-hello');
     function msg(t){ box.innerHTML = ''; var p = document.createElement('p'); p.className = 'ewac-msg'; p.textContent = t; box.appendChild(p); }
     function booked(label){
       root.querySelector('.ewtr-form').hidden = true;
       var d = root.querySelector('.ewtr-done'); d.hidden = false;
       d.querySelector('.ewtr-done-t').textContent = W.booked_title;
       d.querySelector('.ewac-when').textContent = label;
       d.querySelector('.ewtr-done-n').textContent = W.booked_note;
     }
     if(!key){ msg(W.no_key); return; }
     function load(){
       fetch(W.rest + (W.rest.indexOf('?') < 0 ? '?' : '&') + 'page=' + encodeURIComponent(page) + '&k=' + encodeURIComponent(key), {cache:'no-store'})
       .then(function(r){ return r.json(); }).then(function(j){
         if(!j || !j.ok){ msg(W.no_key); return; }
         if(j.hello){ hello.textContent = j.hello; hello.hidden = false; }
         if(j.booked){ booked(j.booked); return; }
         if(!j.slots || !j.slots.length){ msg(W.none); return; }
         box.innerHTML = '';
         j.slots.forEach(function(s){
           var b = document.createElement('button'); b.type = 'button'; b.className = 'ewac-slot';
           b.textContent = s.label; b.setAttribute('aria-label', W.pick + ': ' + s.label);
           b.addEventListener('click', function(){
             err.textContent = '';
             Array.prototype.forEach.call(box.querySelectorAll('button'), function(x){ x.disabled = true; });
             post(W.rest, {page:page, k:key, slot:s.k}).then(function(x){
               if(x.ok && x.j && x.j.booked){ booked(x.j.booked); if(window.ewTrack) ewTrack('Schedule', {what:'Academy 1:1'}); return; }
               err.textContent = (x.j && x.j.message) || W.failed; load();
             }).catch(function(){ err.textContent = W.failed; load(); });
           });
           box.appendChild(b);
         });
       }).catch(function(){ msg(W.failed); });
     }
     load();
   }
 }
 Array.prototype.forEach.call(document.querySelectorAll('.ewac'), setup);
})();
JS;
}

add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_singular() || ! function_exists( 'ew_post_contains' ) ) {
		return;
	}
	$post = get_post();
	$on   = false;
	foreach ( array( 'eastwood_ask', 'eastwood_interest', 'eastwood_meeting' ) as $tag ) {
		if ( $post && ew_post_contains( $post, $tag ) ) {
			$on = true;
		}
	}
	if ( ! $on ) {
		return;
	}
	wp_enqueue_style( 'ewc-fonts', 'https://fonts.googleapis.com/css2?family=Anton&family=Instrument+Sans:wght@400;600;700&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
	wp_register_style( 'ew-academy', false, array(), EW_FWP_VERSION );
	wp_enqueue_style( 'ew-academy' );
	// The forms wear the trial form look, so the funnel reads as one thing.
	wp_add_inline_style( 'ew-academy', ( function_exists( 'ew_trial_css' ) ? ew_trial_css() : '' ) . ew_acad_css() );
	wp_register_script( 'ew-academy', '', array(), EW_FWP_VERSION, true );
	wp_enqueue_script( 'ew-academy' );
	wp_add_inline_script( 'ew-academy', ew_acad_js() );
}, 22 );

/* The command channel may lay these out like any other block. */
add_filter( 'ew_blocks_allowed', function ( $list ) {
	return array_merge( (array) $list, array( 'eastwood_ask', 'eastwood_interest', 'eastwood_meeting' ) );
} );
