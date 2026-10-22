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
	if ( '' === $from || 'info@eastwoodfc.com' === $from || false !== strpos( $from, 'wordpress@' ) ) {
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
