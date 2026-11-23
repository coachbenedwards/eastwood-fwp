<?php
/**
 * Bookings -> Email bookers: one email to everyone who booked or claimed for an event.
 *
 * Staff pick an event, write a subject and a message ({first_name} is filled
 * in for each person), see exactly who it will go to, untick anyone, and send.
 * Each person gets their own email from a confirmed send-as address (orders@
 * by default). Club addresses are left off, each email address gets one copy,
 * and someone already sent the same send is never sent it twice -- across
 * events too, so a family that booked two games gets one copy.
 *
 * Links: [the words](https://...) in the message becomes a link showing the
 * words. Every eastwoodfc.com link is tracked per person: it carries a short
 * random code (?r=k7f2q9xa) stored on that person's booking. When the page
 * opens, a small script reports the code (the pages are cached, so the server
 * never sees the visit itself), the click is written on the booking, and the
 * checkout email is filled in. A booking made after the click carries the
 * code, so this screen can show Sent, Clicked and Booked for each person.
 *
 * @package eastwood-fwp
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
	add_submenu_page( 'edit.php?post_type=ewt_booking', 'Email bookers', 'Email bookers', 'edit_posts', 'ew-email-bookers', 'ew_eb_page' );
}, 22 );

/** Addresses the site may send as: orders@ plus any confirmed extras. */
function ew_eb_froms() {
	$list = array( ew_smtp_from() );
	// The sending mailbox itself (noreply@) can always be the From.
	if ( function_exists( 'ew_smtp_user' ) && is_email( ew_smtp_user() ) ) {
		$list[] = strtolower( ew_smtp_user() );
	}
	foreach ( preg_split( '/[\s,]+/', (string) get_option( 'ew_smtp_aliases', '' ) ) as $a ) {
		$a = sanitize_email( $a );
		if ( is_email( $a ) && ! in_array( $a, $list, true ) ) {
			$list[] = $a;
		}
	}
	return $list;
}

/** Events that have bookings, newest first: [event => count]. */
function ew_eb_events() {
	global $wpdb;
	$rows = $wpdb->get_results( "SELECT pm.meta_value AS ev, COUNT(*) AS n, MAX(p.post_date) AS d FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = 'ewt_booking' AND p.post_status <> 'trash' AND pm.meta_key = '_ewt_event' GROUP BY pm.meta_value ORDER BY d DESC" );
	$out = array();
	foreach ( (array) $rows as $r ) {
		$out[ (string) $r->ev ] = (int) $r->n;
	}
	return $out;
}

/** One row per email address for an event, claimed or paid only, club addresses left off. */
function ew_eb_people( $event, $who = '' ) {
	$q   = new WP_Query( array(
		'post_type'      => 'ewt_booking',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'orderby'        => 'date',
		'order'          => 'ASC',
		'meta_query'     => array( array( 'key' => '_ewt_event', 'value' => $event ) ),
		'fields'         => 'ids',
	) );
	$out = array();
	foreach ( $q->posts as $id ) {
		$st = (string) get_post_meta( $id, '_ewt_status', true );
		if ( ! in_array( $st, array( 'claimed', 'paid' ), true ) ) {
			continue;
		}
		$em = strtolower( trim( (string) get_post_meta( $id, '_ewt_email', true ) ) );
		// Club addresses, and anyone who has unsubscribed from the club's emails, are left off.
		if ( ! is_email( $em ) || preg_match( '/@(eastwoodfc\.com|benedwards\.com)$/', $em ) || ( function_exists( 'ew_is_unsub' ) && ew_is_unsub( $em ) ) ) {
			continue;
		}
		if ( ! isset( $out[ $em ] ) ) {
			$out[ $em ] = array( 'name' => trim( (string) get_post_meta( $id, '_ewt_name', true ) ), 'ids' => array() );
		}
		$out[ $em ]['ids'][] = $id;
	}
	// Who: 'in' = scanned in at the gate on any of their bookings, 'out' = never scanned in.
	if ( 'in' === $who || 'out' === $who ) {
		foreach ( $out as $em => $p ) {
			$in = false;
			foreach ( $p['ids'] as $id ) {
				if ( '' !== (string) get_post_meta( $id, '_ewt_in', true ) ) {
					$in = true;
					break;
				}
			}
			if ( ( 'in' === $who ) !== $in ) {
				unset( $out[ $em ] );
			}
		}
	}
	return $out;
}

/** {first_name}, {code}, {claim_link} and {ref} filled for one person. '' code when they have none. */
function ew_eb_fill( $body, $name, $code, $ref = '' ) {
	return str_replace(
		array( '{first_name}', '{code}', '{claim_link}', '{ref}' ),
		array( ew_eb_first( $name ), $code, '' !== $code && function_exists( 'ew_cc_link' ) ? ew_cc_link( $code ) : '', $ref ),
		$body
	);
}

/** Their booking reference (the one in their confirmation email), from their first booking on the list. */
function ew_eb_ref( $ids ) {
	if ( ! $ids ) {
		return '';
	}
	return function_exists( 'ew_booking_code' ) ? ew_booking_code( (int) $ids[0] ) : (string) get_post_meta( (int) $ids[0], '_ewt_code', true );
}

/** Does the message use a personal claim code? */
function ew_eb_wants_code( $body ) {
	return false !== strpos( $body, '{code}' ) || false !== strpos( $body, '{claim_link}' );
}

function ew_eb_first( $name ) {
	$f = trim( strtok( (string) $name, ' ' ) );
	return '' === $f ? 'there' : ucfirst( strtolower( $f ) );
}

function ew_eb_page() {
	$froms  = ew_eb_froms();
	$events = ew_eb_events();
	$event  = sanitize_text_field( wp_unslash( $_POST['event'] ?? '' ) );
	// The sending mailbox (noreply@) is the From unless another is picked.
	$dflt   = function_exists( 'ew_smtp_user' ) && in_array( strtolower( ew_smtp_user() ), $froms, true ) ? strtolower( ew_smtp_user() ) : $froms[0];
	$from   = sanitize_email( wp_unslash( $_POST['from'] ?? $dflt ) );
	$subj   = sanitize_text_field( wp_unslash( $_POST['subject'] ?? '' ) );
	$body   = sanitize_textarea_field( wp_unslash( $_POST['body'] ?? '' ) );
	$step   = sanitize_key( $_POST['step'] ?? '' );
	$who    = sanitize_key( $_POST['who'] ?? '' );
	$look   = 'club' === ( $_POST['look'] ?? 'plain' ) ? 'club' : 'plain';
	$per    = max( 1, min( 100, (int) ( $_POST['per'] ?? 20 ) ) );
	$every  = max( 1, min( 120, (int) ( $_POST['every'] ?? 10 ) ) );
	if ( isset( $_POST['step_send'] ) ) {
		$step = 'send';
	}
	if ( isset( $_POST['step_drip'] ) ) {
		$step = 'drip';
	}
	if ( ! in_array( $from, $froms, true ) ) {
		$from = $froms[0];
	}

	echo '<div class="wrap"><h1>Email bookers</h1>';

	// Pause, carry on or stop a slow send.
	if ( isset( $_POST['drip_ctl'] ) && check_admin_referer( 'ew_eb_drip_ctl' ) ) {
		$d   = (array) get_option( 'ew_eb_drip', array() );
		$ctl = sanitize_key( $_POST['drip_ctl'] );
		if ( $d && 'pause' === $ctl ) {
			$d['paused'] = 1;
			wp_clear_scheduled_hook( 'ew_eb_drip_run' );
		} elseif ( $d && 'resume' === $ctl ) {
			$d['paused']  = 0;
			$d['stopped'] = '';
			wp_schedule_single_event( time() + 5, 'ew_eb_drip_run' );
		} elseif ( 'clear' === $ctl ) {
			$d = array();
			wp_clear_scheduled_hook( 'ew_eb_drip_run' );
		}
		update_option( 'ew_eb_drip', $d, false );
	}

	if ( 'drip' === $step && check_admin_referer( 'ew_eb_send' ) && $event && $subj && $body ) {
		$cur = (array) get_option( 'ew_eb_drip', array() );
		if ( ! empty( $cur['queue'] ) ) {
			echo '<div class="notice notice-error"><p>A slow send is already going. Stop it first.</p></div>';
		} else {
			$pick = array_map( 'strtolower', (array) wp_unslash( $_POST['to'] ?? array() ) );
			$done = ew_eb_done( ew_eb_keys( $event, $subj ) );
			$q    = array();
			foreach ( ew_eb_people( $event, $who ) as $em => $p ) {
				if ( in_array( $em, $pick, true ) && ! isset( $done[ $em ] ) ) {
					$q[] = $em;
				}
			}
			update_option( 'ew_eb_drip', array(
				'event' => $event, 'from' => $from, 'subj' => $subj, 'body' => $body, 'who' => $who, 'look' => $look,
				'per' => $per, 'every' => $every, 'queue' => $q, 'total' => count( $q ),
				'sent' => 0, 'skip' => 0, 'nocode' => 0, 'fail' => array(), 'started' => time(), 'paused' => 0, 'stopped' => '', 'last' => 0,
			), false );
			wp_clear_scheduled_hook( 'ew_eb_drip_run' );
			wp_schedule_single_event( time() + 5, 'ew_eb_drip_run' );
			echo '<div class="notice notice-success"><p>Started: ' . count( $q ) . ' emails, ' . (int) $per . ' every ' . (int) $every . ' minutes.</p></div>';
		}
		$step = '';
	}
	echo ew_eb_drip_box();

	if ( 'send' === $step && check_admin_referer( 'ew_eb_send' ) && $event && $subj && $body ) {
		$people = ew_eb_people( $event, $who );
		$pick   = array_map( 'strtolower', (array) wp_unslash( $_POST['to'] ?? array() ) );
		$start  = time();
		$more   = 0;
		$nocode = 0;
		// One Google sign-in for the whole turn, not one per email: signing in
		// for every message got the sending account locked out after about 100.
		$keep = function ( $mail ) {
			$mail->SMTPKeepAlive = true;
		};
		add_action( 'phpmailer_init', $keep, 100 );
		$row     = 0;
		$stopped = false;
		$keys   = ew_eb_keys( $event, $subj );
		$done   = ew_eb_done( $keys );
		$sent   = 0;
		$skip   = 0;
		$fail   = array();
		foreach ( $people as $em => $p ) {
			if ( ! in_array( $em, $pick, true ) ) {
				continue;
			}
			if ( isset( $done[ $em ] ) ) {
				$skip++;
				continue;
			}
			// Sends in turns of about 40 seconds so a long list never times out; the page carries on by itself.
			if ( time() - $start > 40 ) {
				$more++;
				continue;
			}
			$cc = function_exists( 'ew_cc_for_ids' ) ? ew_cc_for_ids( $p['ids'] ) : '';
			if ( ew_eb_wants_code( $body ) && '' === $cc ) {
				$nocode++;
				continue;
			}
			$ok = ew_eb_send_one( array( 'from' => $from, 'subj' => $subj, 'body' => $body, 'look' => $look, 'keys' => $keys ), $em, $p, $cc );
			if ( $ok ) {
				$done[ $em ] = time();
				$sent++;
				$row = 0;
				usleep( 500000 ); // about two a second, well inside Google's limits
			} else {
				$fail[] = $em;
				// Three failures in a row means Google is refusing: stop rather than fail everyone left.
				if ( ++$row >= 3 ) {
					$stopped = true;
					break;
				}
			}
		}
		remove_action( 'phpmailer_init', $keep, 100 );
		global $phpmailer;
		if ( is_object( $phpmailer ) && method_exists( $phpmailer, 'smtpClose' ) ) {
			$phpmailer->smtpClose();
		}
		echo '<div class="notice notice-' . ( $fail ? 'warning' : 'success' ) . '"><p>Sent ' . (int) $sent . ' from ' . esc_html( $from ) . '.'
			. ( $skip ? ' ' . (int) $skip . ' already had this email, so were skipped.' : '' )
			. ( $nocode ? ' ' . (int) $nocode . ' have no claim code, so were not sent it.' : '' )
			. ( $fail ? ' Could not send to: ' . esc_html( implode( ', ', $fail ) ) . '.' : '' )
			. ( $stopped ? ' Stopped: Google refused three in a row. Nobody else was tried; press Send again later and it carries on from here.' : '' ) . '</p></div>';
		if ( $more && ! $fail ) {
			// Carry on: the same send again; everyone already sent is skipped.
			echo '<p><strong>' . (int) $more . ' still to send. Carrying on, keep this page open.</strong></p>';
			echo '<form method="post" id="ewEbMore">';
			wp_nonce_field( 'ew_eb_send' );
			foreach ( array( 'event' => $event, 'from' => $from, 'subject' => $subj, 'body' => $body, 'who' => $who, 'look' => $look, 'step' => 'send' ) as $k => $v ) {
				echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
			}
			foreach ( $pick as $em ) {
				echo '<input type="hidden" name="to[]" value="' . esc_attr( $em ) . '">';
			}
			echo '<button class="button">Carry on</button></form><script>setTimeout(function(){document.getElementById("ewEbMore").submit();},1500);</script></div>';
			return;
		}
		$step = '';
	}

	// A test: the email exactly as the first person on the list would get it,
	// sent to any address (club ones included). The link carries a dummy code
	// that is stored nowhere, so it opens the page but counts as no one's click.
	if ( 'test' === $step && check_admin_referer( 'ew_eb_send' ) && $event && $subj && $body ) {
		$to     = sanitize_email( wp_unslash( $_POST['test_to'] ?? '' ) );
		$people = ew_eb_people( $event, $who );
		$first  = $people ? reset( $people ) : array( 'name' => '', 'ids' => array() );
		$as     = ew_eb_first( $first['name'] );
		// The test carries the test address's own claim code if it has one (so the link can be tried
		// end to end without using up someone else's), otherwise a dummy one.
		$tcc = '';
		if ( function_exists( 'ew_cc_for_ids' ) ) {
			$tids = get_posts( array( 'post_type' => 'ewt_booking', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_ewt_email', 'meta_value' => $to ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
			$tcc  = ew_cc_for_ids( $tids );
		}
		if ( is_email( $to ) ) {
			$tref = ew_eb_ref( $tids ?? array() );
			$tref = '' !== $tref ? $tref : 'EFC-0000';
			$msg  = ew_eb_render( ew_eb_fill( $body, $first['name'], '' !== $tcc ? $tcc : 'XX-TEST', $tref ), 'plain' === $look ? '' : 'testtest', $look );
			$alt = function ( $mail ) use ( $msg ) {
				$mail->AltBody = $msg['text'];
			};
			add_action( 'phpmailer_init', $alt, 99 );
			$ok = wp_mail( $to, '[Test] ' . ew_eb_fill( $subj, $first['name'], '' !== $tcc ? $tcc : 'XX-TEST', $tref ), $msg['html'], array( 'From: Eastwood FC <' . $from . '>', 'Content-Type: text/html; charset=UTF-8' ) );
			remove_action( 'phpmailer_init', $alt, 99 );
			echo '<div class="notice notice-' . ( $ok ? 'success' : 'error' ) . '"><p>' . ( $ok ? 'Test sent to ' . esc_html( $to ) . ', written as ' . esc_html( $first['name'] ? $first['name'] : 'someone with no name' ) . ' would get it ("' . esc_html( $as ) . '").' : 'The test could not be sent.' ) . '</p></div>';
		} else {
			echo '<div class="notice notice-error"><p>Add an address to send the test to.</p></div>';
		}
		$step = 'preview';
	}

	echo '<form method="post">';
	echo '<table class="form-table"><tr><th>Event</th><td><select name="event">';
	foreach ( $events as $ev => $n ) {
		echo '<option value="' . esc_attr( $ev ) . '"' . selected( $ev, $event, false ) . '>' . esc_html( $ev ) . '</option>';
	}
	echo '</select></td></tr>';
	echo '<tr><th>Who</th><td><select name="who">';
	foreach ( array( '' => 'Everyone', 'in' => 'Scanned in at the gate', 'out' => 'Not scanned in' ) as $k => $label ) {
		echo '<option value="' . esc_attr( $k ) . '"' . selected( $k, $who, false ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select></td></tr>';
	echo '<tr><th>From</th><td><select name="from">';
	foreach ( $froms as $f ) {
		echo '<option' . selected( $f, $from, false ) . '>' . esc_html( $f ) . '</option>';
	}
	echo '</select></td></tr>';
	echo '<tr><th>Look</th><td><select name="look">';
	foreach ( array( 'plain' => 'Plain, like an email typed in Gmail (black text, links not tracked)', 'club' => 'Club (red links, every eastwoodfc.com link tracked)' ) as $k => $label ) {
		echo '<option value="' . esc_attr( $k ) . '"' . selected( $k, $look, false ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select></td></tr>';
	echo '<tr><th>Subject</th><td><input type="text" name="subject" class="large-text" value="' . esc_attr( $subj ) . '"><p class="description">{ref} is replaced with each person\'s own booking reference, the one in their confirmation email. {first_name} works here too.</p></td></tr>';
	echo '<tr><th>Message</th><td><textarea name="body" rows="14" class="large-text">' . esc_textarea( $body ) . '</textarea><p class="description">{first_name} is replaced with each person\'s first name. {code} is their personal claim code and {claim_link} their own link to claim with it; anyone without a code is not sent a message that uses them. [Book your tickets](https://eastwoodfc.com/...) shows the words as a link. Every eastwoodfc.com link is tracked for each person.</p></td></tr></table>';

	if ( 'preview' === $step && $event ) {
		wp_nonce_field( 'ew_eb_send' );
		$people = ew_eb_people( $event, $who );
		$keys   = ew_eb_keys( $event, $subj );
		$done   = ew_eb_done( $keys );
		$rows   = '';
		$n      = array( 'sent' => 0, 'click' => 0, 'booked' => 0 );
		foreach ( $people as $em => $p ) {
			$when  = $done[ $em ] ?? 0;
			$code  = (string) get_post_meta( $p['ids'][0], '_ewt_r_' . substr( $keys[0], 8 ), true );
			$click = $code ? (int) get_post_meta( $p['ids'][0], '_ewt_rc_' . $code, true ) : 0;
			$bkd   = $code ? ew_eb_booked( $code ) : '';
			$n['sent']   += $when ? 1 : 0;
			$n['click']  += $click ? 1 : 0;
			$n['booked'] += '' !== $bkd ? 1 : 0;
			$rows .= '<tr><td><input type="checkbox" name="to[]" value="' . esc_attr( $em ) . '"' . ( $when ? '' : ' checked' ) . '></td><td>' . esc_html( $p['name'] ) . '</td><td>' . esc_html( $em ) . '</td><td>'
				. ( $when ? esc_html( wp_date( 'j M, g:ia', (int) $when ) ) : '' ) . '</td><td>' . ( $click ? esc_html( wp_date( 'j M, g:ia', $click ) ) : '' ) . '</td><td>' . esc_html( $bkd ) . '</td></tr>';
		}
		echo '<h2>Who it goes to (' . count( $people ) . ')</h2>';
		if ( $n['sent'] ) {
			echo '<p><strong>Sent ' . (int) $n['sent'] . ' · Clicked ' . (int) $n['click'] . ' · Booked ' . (int) $n['booked'] . '</strong></p>';
		}
		echo '<p>Untick anyone who should not get it.</p><table class="widefat striped"><thead><tr><th></th><th>Name</th><th>Email</th><th>Sent already</th><th>Clicked</th><th>Booked</th></tr></thead><tbody>' . $rows;
		echo '</tbody></table>';
		$me = wp_get_current_user();
		echo '<p style="margin-top:24px"><label>Send a test to <input type="email" name="test_to" value="' . esc_attr( $me ? $me->user_email : '' ) . '" class="regular-text"></label> '
			. '<button type="submit" name="step" value="test" class="button">Send a test</button></p>';
		echo '<p class="description">The test is written as the first person on the list would get it.</p>';
		echo '<h2 style="margin-top:28px">Send slowly</h2><p><label>Send <input type="number" name="per" value="' . (int) $per . '" min="1" max="100" style="width:70px"></label> '
			. '<label>every <input type="number" name="every" value="' . (int) $every . '" min="1" max="120" style="width:70px"> minutes</label></p>'
			. '<p class="description">Goes out by itself through Google, a few at a time, until everyone ticked has it. Anyone already sent this email is skipped. It pauses on its own if Google refuses three in a row.</p>';
		submit_button( 'Start sending slowly', 'primary', 'step_drip' );
		echo '<p class="description" style="margin-top:20px">Or send to everyone ticked straight away, from this page:</p>';
		submit_button( 'Send to the ticked people now', 'secondary', 'step_send' );
	} else {
		echo '<input type="hidden" name="step" value="preview">';
		submit_button( 'Show who it goes to' );
	}
	echo '</form></div>';
}

/* --- One email to one person, used by the instant send and the slow send --- */

/**
 * $job: from, subj, body, look, keys. $p: name, ids. $cc: their claim code.
 * Marks the booking as sent this email. True when Google took it.
 */
function ew_eb_send_one( $job, $em, $p, $cc ) {
	$look = 'club' === ( $job['look'] ?? 'club' ) ? 'club' : 'plain';
	$code = 'plain' === $look ? '' : ew_eb_code();
	$ref  = ew_eb_ref( $p['ids'] );
	$msg  = ew_eb_render( ew_eb_fill( $job['body'], $p['name'], $cc, $ref ), $code, $look );
	$alt  = function ( $mail ) use ( $msg ) {
		$mail->AltBody = $msg['text'];
	};
	add_action( 'phpmailer_init', $alt, 99 );
	$ok = wp_mail( $em, ew_eb_fill( $job['subj'], $p['name'], $cc, $ref ), $msg['html'], array( 'From: Eastwood FC <' . $job['from'] . '>', 'Content-Type: text/html; charset=UTF-8' ) );
	remove_action( 'phpmailer_init', $alt, 99 );
	if ( $ok ) {
		update_post_meta( $p['ids'][0], $job['keys'][0], time() );
		if ( $msg['tracked'] ) {
			add_post_meta( $p['ids'][0], '_ewt_r', $code );
			update_post_meta( $p['ids'][0], '_ewt_r_' . substr( $job['keys'][0], 8 ), $code );
		}
	}
	return $ok;
}

/* --- The slow send: a few at a time by itself until the list is done ------ */

add_action( 'ew_eb_drip_run', 'ew_eb_drip_run' );
function ew_eb_drip_run() {
	if ( get_transient( 'ew_eb_drip_lock' ) ) {
		return;
	}
	$d = (array) get_option( 'ew_eb_drip', array() );
	if ( empty( $d['queue'] ) || ! empty( $d['paused'] ) ) {
		return;
	}
	set_transient( 'ew_eb_drip_lock', 1, 170 );
	$people = ew_eb_people( $d['event'], $d['who'] );
	$keys   = ew_eb_keys( $d['event'], $d['subj'] );
	$done   = ew_eb_done( $keys );
	$job    = array( 'from' => $d['from'], 'subj' => $d['subj'], 'body' => $d['body'], 'look' => $d['look'], 'keys' => $keys );
	$keep   = function ( $mail ) {
		$mail->SMTPKeepAlive = true; // one Google sign-in per batch, not one per email
	};
	add_action( 'phpmailer_init', $keep, 100 );
	$n   = 0;
	$row = 0;
	while ( $d['queue'] && $n < (int) $d['per'] ) {
		$em = array_shift( $d['queue'] );
		if ( ! isset( $people[ $em ] ) || isset( $done[ $em ] ) ) {
			$d['skip']++;
			continue;
		}
		$p  = $people[ $em ];
		$cc = function_exists( 'ew_cc_for_ids' ) ? ew_cc_for_ids( $p['ids'] ) : '';
		if ( ew_eb_wants_code( $d['body'] ) && '' === $cc ) {
			$d['nocode']++;
			continue;
		}
		$n++;
		if ( ew_eb_send_one( $job, $em, $p, $cc ) ) {
			$d['sent']++;
			$row = 0;
			usleep( 500000 );
		} else {
			$d['fail'][] = $em;
			if ( ++$row >= 3 ) {
				// Google is refusing: put this one back, pause, and try nobody else.
				array_unshift( $d['queue'], $em );
				array_pop( $d['fail'] );
				$d['paused']  = 1;
				$d['stopped'] = 'Paused at ' . wp_date( 'j M, g:ia' ) . ': Google refused three in a row.';
				break;
			}
		}
	}
	remove_action( 'phpmailer_init', $keep, 100 );
	global $phpmailer;
	if ( is_object( $phpmailer ) && method_exists( $phpmailer, 'smtpClose' ) ) {
		$phpmailer->smtpClose();
	}
	$d['last'] = time();
	update_option( 'ew_eb_drip', $d, false );
	delete_transient( 'ew_eb_drip_lock' );
	if ( $d['queue'] && empty( $d['paused'] ) ) {
		wp_schedule_single_event( time() + 60 * (int) $d['every'], 'ew_eb_drip_run' );
	}
}

/** The slow send's progress, with Pause / Carry on / Stop. */
function ew_eb_drip_box() {
	$d = (array) get_option( 'ew_eb_drip', array() );
	if ( ! $d ) {
		return '';
	}
	$left = count( (array) ( $d['queue'] ?? array() ) );
	$next = wp_next_scheduled( 'ew_eb_drip_run' );
	$h    = '<div class="notice notice-info" style="padding:12px 16px"><p><strong>Sending slowly: "' . esc_html( $d['subj'] ) . '"</strong> from ' . esc_html( $d['from'] ) . ', ' . (int) $d['per'] . ' every ' . (int) $d['every'] . ' minutes</p>'
		. '<p>Sent ' . (int) $d['sent'] . ' of ' . (int) $d['total'] . ' · ' . $left . ' to go'
		. ( $d['skip'] ? ' · ' . (int) $d['skip'] . ' skipped (already had it)' : '' )
		. ( $d['nocode'] ? ' · ' . (int) $d['nocode'] . ' with no claim code, not sent' : '' )
		. ( $d['fail'] ? ' · could not send to ' . esc_html( implode( ', ', (array) $d['fail'] ) ) : '' ) . '</p>'
		. ( $d['stopped'] ? '<p><strong>' . esc_html( $d['stopped'] ) . '</strong></p>' : '' )
		. ( $left && ! $d['paused'] && $next ? '<p>Next batch about ' . esc_html( wp_date( 'g:ia', $next ) ) . '.</p>' : '' )
		. ( ! $left ? '<p>Finished.</p>' : '' );
	$h .= '<form method="post" style="display:flex;gap:8px">' . wp_nonce_field( 'ew_eb_drip_ctl', '_wpnonce', true, false );
	if ( $left && ! $d['paused'] ) {
		$h .= '<button class="button" name="drip_ctl" value="pause">Pause</button>';
	}
	if ( $left && $d['paused'] ) {
		$h .= '<button class="button button-primary" name="drip_ctl" value="resume">Carry on</button>';
	}
	$h .= '<button class="button" name="drip_ctl" value="clear">' . ( $left ? 'Stop and clear' : 'Clear' ) . '</button></form></div>';
	if ( $left && ! $d['paused'] ) {
		$h .= '<script>setTimeout(function(){location.reload();},60000);</script>';
	}
	return $h;
}

/* --- Sends and tracked links ------------------------------------------ */

/**
 * The meta keys that mark a send as done. The first is the one written now:
 * by subject alone, so a family on two events' lists gets one copy. The
 * second is the older per-event key, still honoured for sends made before.
 */
function ew_eb_keys( $event, $subj ) {
	return array( '_ewt_eb_' . substr( md5( '*|' . $subj ), 0, 10 ), '_ewt_eb_' . substr( md5( $event . '|' . $subj ), 0, 10 ) );
}

/** Everyone already sent this send: [email => time]. */
function ew_eb_done( $keys ) {
	$out = array();
	foreach ( $keys as $key ) {
		foreach ( get_posts( array( 'post_type' => 'ewt_booking', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => $key ) ) as $id ) {
			$em = strtolower( trim( (string) get_post_meta( $id, '_ewt_email', true ) ) );
			if ( '' !== $em && ! isset( $out[ $em ] ) ) {
				$out[ $em ] = (int) get_post_meta( $id, $key, true );
			}
		}
	}
	return $out;
}

/** A short random code: no look-alike characters, nothing about the person. */
function ew_eb_code() {
	$a = 'abcdefghjkmnpqrstuvwxyz23456789';
	$c = '';
	for ( $i = 0; $i < 8; $i++ ) {
		$c .= $a[ random_int( 0, strlen( $a ) - 1 ) ];
	}
	return $c;
}

function ew_eb_code_ok( $code ) {
	return (bool) preg_match( '/^[a-z0-9]{8}$/', (string) $code );
}

/** The booking a code was sent to, or 0. */
function ew_eb_by_code( $code ) {
	if ( ! ew_eb_code_ok( $code ) ) {
		return 0;
	}
	$ids = get_posts( array( 'post_type' => 'ewt_booking', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => '_ewt_r', 'meta_value' => $code ) );
	return $ids ? (int) $ids[0] : 0;
}

/** What was booked after clicking a code ('' if nothing paid yet), e.g. "1 × Family ticket". */
function ew_eb_booked( $code ) {
	$ids = get_posts( array(
		'post_type'      => 'ewt_booking',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_query'     => array(
			array( 'key' => '_ewt_from', 'value' => $code ),
			array( 'key' => '_ewt_status', 'value' => 'paid' ),
		),
	) );
	$out = array();
	foreach ( $ids as $id ) {
		$out[] = (string) get_post_meta( $id, '_ewt_lines', true );
	}
	return implode( '; ', array_filter( $out ) );
}

/** Adds the person's code to an eastwoodfc.com link; any other link is left alone. */
function ew_eb_track( $url, $code, &$tracked ) {
	$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	if ( '' === $code || ! in_array( $host, array( 'eastwoodfc.com', 'www.eastwoodfc.com' ), true ) ) {
		return $url;
	}
	$tracked = true;
	$frag    = '';
	if ( false !== ( $h = strpos( $url, '#' ) ) ) {
		$frag = substr( $url, $h );
		$url  = substr( $url, 0, $h );
	}
	return add_query_arg( 'r', $code, $url ) . $frag;
}

/**
 * The message as an email: ['html' => ..., 'text' => ..., 'tracked' => bool].
 * [words](url) shows the words as a link; a bare url shows itself.
 */
function ew_eb_render( $body, $code, $look = 'club' ) {
	$links   = array();
	$tracked = false;
	$body    = preg_replace_callback(
		'~\[([^\]\n]+)\]\((https?://[^\s)]+)\)|(https?://[^\s<>"]+)~',
		function ( $m ) use ( &$links, $code, &$tracked, $look ) {
			if ( ! empty( $m[3] ) ) {
				$url  = rtrim( $m[3], '.,;:!?)' );
				$rest = substr( $m[3], strlen( $url ) );
				$show = $url;
			} else {
				$url  = $m[2];
				$rest = '';
				$show = $m[1];
			}
			$href    = ew_eb_track( $url, $code, $tracked );
			$links[] = array(
				'html' => 'plain' === $look ? '<a href="' . esc_url( $href ) . '">' . esc_html( $show ) . '</a>' : '<a href="' . esc_url( $href ) . '" style="color:#cc0000;font-weight:600">' . esc_html( $show ) . '</a>',
				'text' => empty( $m[3] ) ? $show . ': ' . $href : $href,
			);
			return "\x01" . ( count( $links ) - 1 ) . "\x01" . $rest;
		},
		str_replace( "\r\n", "\n", (string) $body )
	);
	$put  = function ( $s, $which ) use ( $links ) {
		return preg_replace_callback( "~\x01(\d+)\x01~", function ( $m ) use ( $links, $which ) {
			return $links[ (int) $m[1] ][ $which ];
		}, $s );
	};
	$text = $put( $body, 'text' );
	if ( 'plain' === $look ) {
		// The way Gmail writes a typed email: no fonts or colours of our own, one line per <div>.
		$html = '';
		foreach ( explode( "\n", trim( $body ) ) as $line ) {
			$html .= '' === trim( $line ) ? '<div><br></div>' : '<div>' . $put( esc_html( $line ), 'html' ) . '</div>';
		}
		return array( 'html' => '<div dir="ltr">' . $html . '</div>', 'text' => $text, 'tracked' => $tracked );
	}
	$html = '';
	foreach ( preg_split( "/\n{2,}/", trim( $body ) ) as $para ) {
		$html .= '<p style="margin:0 0 16px">' . nl2br( $put( esc_html( $para ), 'html' ), false ) . '</p>';
	}
	$html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;color:#1a1a1a">' . $html . '</div>';
	return array( 'html' => $html, 'text' => $text, 'tracked' => $tracked );
}

/* A click: the page reports the code, the booking records it, and the
   checkout gets the email the link was sent to. */
add_action( 'rest_api_init', function () {
	register_rest_route( 'eastwood/v1', '/r', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) {
			$code = (string) $req->get_param( 'code' );
			$id   = ew_eb_by_code( $code );
			if ( ! $id ) {
				return array( 'ok' => false );
			}
			$now = time();
			if ( ! get_post_meta( $id, '_ewt_rc_' . $code, true ) ) {
				update_post_meta( $id, '_ewt_rc_' . $code, $now );
			}
			update_post_meta( $id, '_ewt_rl_' . $code, $now );
			update_post_meta( $id, '_ewt_rn_' . $code, 1 + (int) get_post_meta( $id, '_ewt_rn_' . $code, true ) );
			return array( 'ok' => true, 'email' => (string) get_post_meta( $id, '_ewt_email', true ) );
		},
	) );
} );

/* A booking started after a tracked click carries the code. */
add_action( 'ew_tix_started', function ( $bid, $block = null, $ev = '', $req = null ) {
	$code = $req ? (string) $req->get_param( 'r' ) : '';
	if ( $bid && ew_eb_by_code( $code ) ) {
		update_post_meta( $bid, '_ewt_from', $code );
	}
}, 10, 4 );

/* On every page: read ?r=, report it once, tidy the address bar so a copied
   link does not carry it, and fill the checkout email. */
add_action( 'wp_footer', function () {
	$rest = esc_url_raw( rest_url( 'eastwood/v1/r' ) );
	?>
<script>(function(){try{
var m=location.search.match(/[?&]r=([a-z0-9]{8})(?=&|$)/),c=null,e='';
try{if(m){sessionStorage.setItem('ew_r',m[1]);}c=sessionStorage.getItem('ew_r');e=sessionStorage.getItem('ew_r_mail')||'';}catch(x){c=m?m[1]:null;}
if(!c)return;window.ewR=c;
function fill(){if(!e)return;var f=document.querySelectorAll('.ewt-who [name=email]');for(var i=0;i<f.length;i++){if(!f[i].value)f[i].value=e;}}
if(m){
 try{var u=new URL(location.href);u.searchParams.delete('r');history.replaceState(null,'',u.toString());}catch(x){}
 fetch(<?php echo wp_json_encode( $rest ); ?>,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({code:c})})
  .then(function(r){return r.json();}).then(function(j){if(j&&j.email){e=j.email;try{sessionStorage.setItem('ew_r_mail',e);}catch(x){}fill();}}).catch(function(){});
}
if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',fill);}else{fill();}
}catch(x){}})();</script>
	<?php
}, 30 );
