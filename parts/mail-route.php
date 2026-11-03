<?php
/**
 * Website emails go out through GoHighLevel.
 *
 * The host cannot send mail as info@eastwoodfc.com: the domain's SPF does not
 * list it and DMARC is p=quarantine, so Gmail drops whatever wp_mail() sends.
 * GoHighLevel already sends as info@ through the verified replies.eastwoodfc.com
 * domain (the ticket and shop emails), so every wp_mail() on the site is handed
 * to its "Website – emails" workflow instead: one webhook call per recipient,
 * carrying the subject and the body as HTML.
 *
 * The workflow address is a setting (Settings -> Eastwood FWP -> Website emails).
 * Empty it and the site falls back to sending mail itself.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EW_MAIL_HOOK_DEFAULT = 'https://services.leadconnectorhq.com/hooks/2D41NppRa8yLVkV5uSv8/webhook-trigger/226047a8-7d5e-44bc-897c-2cba7eec010e';

function ew_mail_hook() {
	$v = get_option( 'ew_mail_hook', null );
	return null === $v ? EW_MAIL_HOOK_DEFAULT : trim( (string) $v );
}

/** Plain text bodies become simple HTML: escaped, links clickable, line breaks kept. */
function ew_mail_html( $body, $headers ) {
	$is_html = false;
	foreach ( (array) $headers as $h ) {
		if ( is_string( $h ) && preg_match( '/^content-type:\s*text\/html/i', trim( $h ) ) ) {
			$is_html = true;
		}
	}
	if ( is_string( $headers ) && preg_match( '/content-type:\s*text\/html/i', $headers ) ) {
		$is_html = true;
	}
	if ( $is_html ) {
		return (string) $body;
	}
	$t = esc_html( (string) $body );
	$t = preg_replace( '~(https?://[^\s<]+)~', '<a href="$1">$1</a>', $t );
	$paras = preg_split( "/\n\s*\n/", str_replace( "\r", '', $t ) );
	$out   = '';
	foreach ( $paras as $p ) {
		if ( '' !== trim( $p ) ) {
			$out .= '<p>' . nl2br( trim( $p ), false ) . '</p>';
		}
	}
	return $out;
}

add_filter( 'pre_wp_mail', function ( $null, $atts ) {
	$hook = ew_mail_hook();
	if ( null !== $null || '' === $hook || ew_smtp_ready() ) {
		return $null;
	}
	$to = $atts['to'];
	if ( ! is_array( $to ) ) {
		$to = explode( ',', (string) $to );
	}
	$list = array();
	foreach ( $to as $one ) {
		$one = trim( (string) $one );
		if ( preg_match( '/<([^>]+)>/', $one, $mm ) ) {
			$one = $mm[1];
		}
		if ( is_email( $one ) ) {
			$list[] = $one;
		}
	}
	if ( ! $list ) {
		return $null;
	}
	$html = ew_mail_html( $atts['message'], $atts['headers'] );
	$ok   = true;
	foreach ( array_unique( $list ) as $email ) {
		$r = wp_remote_post( $hook, array(
			'timeout' => 15,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'email'   => $email,
				'subject' => wp_specialchars_decode( (string) $atts['subject'], ENT_QUOTES ),
				'html'    => $html,
				'kind'    => 'website',
				'site'    => wp_parse_url( home_url(), PHP_URL_HOST ),
			) ),
		) );
		$code = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
		if ( $code < 200 || $code >= 300 ) {
			$ok = false;
		}
		update_option( 'ew_mail_last', array(
			'at'      => time(),
			'to'      => $email,
			'subject' => (string) $atts['subject'],
			'code'    => $code,
			'error'   => is_wp_error( $r ) ? $r->get_error_message() : '',
		), false );
	}
	return $ok;
}, 10, 2 );

add_action( 'ew_fwp_after_settings', function () {
	if ( isset( $_POST['ew_mail_hook'] ) && check_admin_referer( 'ew_mail_save' ) ) {
		$raw = trim( (string) wp_unslash( $_POST['ew_mail_hook'] ) );
		update_option( 'ew_mail_hook', '' === $raw ? '' : esc_url_raw( $raw ) );
		echo '<div class="notice notice-success"><p>Website emails setting saved.</p></div>';
	}
	echo '<h2>Website emails</h2>';
	echo '<p>Every email the website sends (community ticket passes, staff alerts) goes out through this GoHighLevel workflow, as Eastwood FC from info@eastwoodfc.com. '
		. 'Empty it and the website tries to send mail itself, which Gmail drops.</p>';
	echo '<form method="post">';
	wp_nonce_field( 'ew_mail_save' );
	echo '<p><input type="url" name="ew_mail_hook" size="110" value="' . esc_attr( ew_mail_hook() ) . '"></p>';
	submit_button( 'Save website emails' );
	echo '</form>';
	$last = get_option( 'ew_mail_last' );
	if ( is_array( $last ) && ! empty( $last['at'] ) ) {
		echo '<p>Last sent: ' . esc_html( wp_date( 'j M Y, g:ia', (int) $last['at'] ) ) . ' to ' . esc_html( $last['to'] ) . ' &mdash; '
			. ( $last['code'] >= 200 && $last['code'] < 300 ? 'handed to GoHighLevel' : 'failed (' . esc_html( $last['code'] . ' ' . $last['error'] ) . ')' ) . '</p>';
	}
} );

/* ------------------------------------------------------------------ *
 * Sending through Google. orders@ is a group inbox and cannot sign in, so
 * calendar@ signs in and sends with orders@ as the From -- orders@ is a
 * confirmed "send mail as" address on calendar@, so Google keeps it.
 * Customers see orders@, and their replies land in the Orders inbox.
 * Once the app password is saved, every wp_mail() goes out this way,
 * with no unsubscribe line and no tracked links, and GoHighLevel is not used.
 * ------------------------------------------------------------------ */

function ew_smtp_user() {
	return trim( (string) get_option( 'ew_smtp_user', 'calendar@eastwoodfc.com' ) );
}

function ew_smtp_from() {
	$v = trim( (string) get_option( 'ew_smtp_from', 'orders@eastwoodfc.com' ) );
	return is_email( $v ) ? $v : ew_smtp_user();
}

function ew_smtp_ready() {
	return is_email( ew_smtp_user() ) && '' !== (string) get_option( 'ew_smtp_pass', '' );
}

add_action( 'phpmailer_init', function ( $mail ) {
	if ( ! ew_smtp_ready() ) {
		return;
	}
	$mail->isSMTP();
	$mail->Host       = 'smtp.gmail.com';
	$mail->Port       = 587;
	$mail->SMTPSecure = 'tls';
	$mail->SMTPAuth   = true;
	$mail->Username   = ew_smtp_user();
	$mail->Password   = (string) get_option( 'ew_smtp_pass', '' );
	// The site's emails are written "from info@"; they go from the send-as
	// address (orders@) instead, and replies come back to it.
	$from = strtolower( (string) $mail->From );
	$ok_from = function_exists( 'ew_eb_froms' ) ? array_map( 'strtolower', ew_eb_froms() ) : array();
	if ( '' === $from || ! in_array( $from, $ok_from, true ) ) {
		$mail->setFrom( ew_smtp_from(), 'Eastwood FC', false );
	}
	$mail->clearReplyTos();
	$mail->addReplyTo( $mail->From, 'Eastwood FC' );
	// No separate sender is named, so nothing anywhere reads "calendar@ on behalf of".
	$mail->Sender = '';
} );

add_action( 'wp_mail_failed', function ( $err ) {
	update_option( 'ew_mail_last', array(
		'at'      => time(),
		'to'      => implode( ', ', (array) ( $err->get_error_data()['to'] ?? array() ) ),
		'subject' => (string) ( $err->get_error_data()['subject'] ?? '' ),
		'code'    => 0,
		'error'   => $err->get_error_message(),
	), false );
} );

add_action( 'wp_mail_succeeded', function ( $data ) {
	if ( ! ew_smtp_ready() ) {
		return;
	}
	update_option( 'ew_mail_last', array(
		'at'      => time(),
		'to'      => implode( ', ', (array) ( $data['to'] ?? array() ) ),
		'subject' => (string) ( $data['subject'] ?? '' ),
		'code'    => 250,
		'error'   => '',
		'via'     => 'google',
	), false );
} );

add_action( 'ew_fwp_after_settings', function () {
	if ( isset( $_POST['ew_smtp_user'] ) && check_admin_referer( 'ew_smtp_save' ) ) {
		update_option( 'ew_smtp_user', sanitize_email( wp_unslash( $_POST['ew_smtp_user'] ) ) );
		update_option( 'ew_smtp_from', sanitize_email( wp_unslash( $_POST['ew_smtp_from'] ?? '' ) ) );
		update_option( 'ew_smtp_aliases', sanitize_text_field( wp_unslash( $_POST['ew_smtp_aliases'] ?? '' ) ) );
		$pass = preg_replace( '/\s+/', '', (string) wp_unslash( $_POST['ew_smtp_pass'] ?? '' ) );
		if ( '' !== $pass ) {
			update_option( 'ew_smtp_pass', $pass, false );
		}
		if ( ! empty( $_POST['ew_smtp_clear'] ) ) {
			delete_option( 'ew_smtp_pass' );
		}
		echo '<div class="notice notice-success"><p>Google mailbox saved.</p></div>';
	}
	if ( isset( $_POST['ew_smtp_test'] ) && check_admin_referer( 'ew_smtp_test' ) ) {
		$to = sanitize_email( wp_unslash( $_POST['ew_smtp_test'] ) );
		$ok = is_email( $to ) && wp_mail( $to, 'Test from eastwoodfc.com', "This is a test email from the website.\n\nTeam EastwoodFC" );
		echo '<div class="notice ' . ( $ok ? 'notice-success' : 'notice-error' ) . '"><p>' . ( $ok ? 'Test email sent to ' . esc_html( $to ) . '.' : 'The test email could not be sent. See "Last sent" below.' ) . '</p></div>';
	}
	echo '<h2>Google mailbox</h2>';
	echo '<p>When a mailbox and its Google app password are saved here, every email the website sends goes out through it, and GoHighLevel is not used. '
		. 'Status: <strong>' . ( ew_smtp_ready() ? 'sending through ' . esc_html( ew_smtp_user() ) : 'not set up, so emails go through GoHighLevel' ) . '</strong>.</p>';
	echo '<form method="post">';
	wp_nonce_field( 'ew_smtp_save' );
	echo '<p><label>Mailbox<br><input type="email" name="ew_smtp_user" size="40" value="' . esc_attr( ew_smtp_user() ) . '"></label></p>';
	echo '<p><label>Send as<br><input type="email" name="ew_smtp_from" size="40" value="' . esc_attr( ew_smtp_from() ) . '"></label></p>';
	echo '<p><label>Also send as (only addresses confirmed as "Send mail as" on the mailbox, comma separated)<br><input type="text" name="ew_smtp_aliases" size="60" value="' . esc_attr( (string) get_option( 'ew_smtp_aliases', '' ) ) . '"></label></p>';
	echo '<p><label>App password' . ( '' !== (string) get_option( 'ew_smtp_pass', '' ) ? ' (saved; leave blank to keep it)' : '' ) . '<br><input type="password" name="ew_smtp_pass" size="40" autocomplete="new-password" value=""></label></p>';
	echo '<p><label><input type="checkbox" name="ew_smtp_clear" value="1"> Remove the saved app password</label></p>';
	submit_button( 'Save Google mailbox' );
	echo '</form>';
	echo '<form method="post">';
	wp_nonce_field( 'ew_smtp_test' );
	echo '<p><label>Send a test email to<br><input type="email" name="ew_smtp_test" size="40" value=""></label></p>';
	submit_button( 'Send test email', 'secondary' );
	echo '</form>';
}, 20 );

/* ------------------------------------------------------------------ *
 * One email look for the whole site: crest and name, a photo with the
 * headline on it, the words, one red button, the sign-off and the club
 * address. Every word comes in from the block that sends it.
 * ------------------------------------------------------------------ */

function ew_mail_abs( $u ) {
	$u = trim( (string) $u );
	if ( '' === $u ) {
		return '';
	}
	return 0 === strpos( $u, 'http' ) ? $u : home_url( '/' . ltrim( $u, '/' ) );
}

/**
 * $m: crest, name, photo, title, paras (array of plain text), button, link,
 * sign (plain text, lines), footer. Returns a full HTML email.
 */
function ew_mail_card( $m ) {
	$m = wp_parse_args( (array) $m, array(
		'crest'  => '/wp-content/uploads/ew-badges/2059.png',
		'name'   => 'Eastwood FC',
		'photo'  => '',
		'title'  => '',
		'paras'  => array(),
		'button' => '',
		'link'   => '',
		'sign'   => '',
		'footer' => 'Eastwood Football Club · Coronation Park, Chewton Street, Eastwood NG16 3HB',
	) );
	$disp  = "font-family:Anton,Impact,'Arial Narrow Bold',sans-serif;text-transform:uppercase;font-weight:400";
	$body  = "font-family:'Instrument Sans',-apple-system,'Segoe UI',Helvetica,Arial,sans-serif";
	$p     = 'margin:0 0 20px;font-size:17px;line-height:1.6;color:#111;' . $body;
	$link  = esc_url( $m['link'] );
	$photo = esc_url( ew_mail_abs( $m['photo'] ) );

	$h  = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
	$h .= '<meta name="color-scheme" content="light"><title>' . esc_html( $m['title'] ) . '</title>';
	$h .= '<link href="https://fonts.googleapis.com/css2?family=Anton&family=Instrument+Sans:wght@400;600&display=swap" rel="stylesheet"></head>';
	$h .= '<body style="margin:0;padding:0;background:#e9e9ec">';
	$h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#e9e9ec"><tr><td align="center" style="padding:24px 12px">';
	$h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;background:#ffffff;border-radius:10px;overflow:hidden">';

	// Crest and name.
	$h .= '<tr><td style="padding:28px 32px 20px"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>';
	$h .= '<td style="padding-right:14px"><img src="' . esc_url( ew_mail_abs( $m['crest'] ) ) . '" width="56" height="56" alt="Eastwood FC crest" style="display:block;border:0"></td>';
	$h .= '<td style="' . $disp . ';font-size:22px;letter-spacing:.04em;color:#111">' . esc_html( $m['name'] ) . '</td>';
	$h .= '</tr></table></td></tr>';

	// Photo with the headline on it.
	if ( '' !== $m['title'] ) {
		$bg  = $photo ? 'background-image:url(' . $photo . ');background-size:cover;background-position:center;' : '';
		$h  .= '<tr><td style="padding:0 32px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>';
		$h  .= '<td height="200" valign="bottom" bgcolor="#111111" style="height:200px;border-radius:8px;' . $bg . 'padding:0 24px 20px">';
		$h  .= '<div style="' . $disp . ';font-size:40px;line-height:1.05;color:#ffffff">' . esc_html( $m['title'] ) . '</div>';
		$h  .= '</td></tr></table></td></tr>';
	}

	// Words, button, sign-off.
	$h .= '<tr><td style="padding:28px 32px 8px">';
	foreach ( (array) $m['paras'] as $para ) {
		if ( '' !== trim( (string) $para ) ) {
			$h .= '<p style="' . $p . '">' . nl2br( esc_html( trim( (string) $para ) ), false ) . '</p>';
		}
	}
	if ( '' !== $m['button'] && $link ) {
		$h .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:4px 0 24px"><tr>';
		$h .= '<td bgcolor="#CC0000" style="border-radius:8px"><a href="' . $link . '" style="display:inline-block;padding:16px 28px;' . $disp . ';font-size:20px;letter-spacing:.02em;color:#ffffff;text-decoration:none;border-radius:8px">' . esc_html( $m['button'] ) . '</a></td>';
		$h .= '</tr></table>';
	}
	if ( '' !== trim( (string) $m['sign'] ) ) {
		$lines = array_map( 'trim', explode( "\n", str_replace( '\n', "\n", (string) $m['sign'] ) ) );
		$last  = count( $lines ) > 1 ? array_pop( $lines ) : '';
		$h    .= '<p style="' . $p . '">' . implode( '<br>', array_map( 'esc_html', $lines ) )
			. ( '' !== $last ? '<br><span style="color:#555">' . esc_html( $last ) . '</span>' : '' ) . '</p>';
	}
	$h .= '</td></tr>';

	// Club address.
	$h .= '<tr><td style="padding:16px 32px;background:#f6f6f7;font-size:12px;line-height:1.5;color:#666;' . $body . '">' . esc_html( $m['footer'] ) . '</td></tr>';
	$h .= '</table></td></tr></table></body></html>';
	return $h;
}

/** The same email as plain text, for mail apps that will not show HTML. */
function ew_mail_card_text( $m ) {
	$out = array();
	foreach ( (array) ( $m['paras'] ?? array() ) as $para ) {
		$out[] = trim( (string) $para );
	}
	if ( ! empty( $m['button'] ) && ! empty( $m['link'] ) ) {
		$out[] = $m['button'] . ': ' . $m['link'];
	}
	if ( ! empty( $m['sign'] ) ) {
		$out[] = str_replace( '\n', "\n", (string) $m['sign'] );
	}
	if ( ! empty( $m['footer'] ) ) {
		$out[] = $m['footer'];
	}
	return implode( "\n\n", array_filter( $out, 'strlen' ) );
}
