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
function ew_eb_people( $event ) {
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
		if ( ! is_email( $em ) || preg_match( '/@(eastwoodfc\.com|benedwards\.com)$/', $em ) ) {
			continue;
		}
		if ( ! isset( $out[ $em ] ) ) {
			$out[ $em ] = array( 'name' => trim( (string) get_post_meta( $id, '_ewt_name', true ) ), 'ids' => array() );
		}
		$out[ $em ]['ids'][] = $id;
	}
	return $out;
}

function ew_eb_first( $name ) {
	$f = trim( strtok( (string) $name, ' ' ) );
	return '' === $f ? 'there' : ucfirst( strtolower( $f ) );
}

function ew_eb_page() {
	$froms  = ew_eb_froms();
	$events = ew_eb_events();
	$event  = sanitize_text_field( wp_unslash( $_POST['event'] ?? '' ) );
	$from   = sanitize_email( wp_unslash( $_POST['from'] ?? $froms[0] ) );
	$subj   = sanitize_text_field( wp_unslash( $_POST['subject'] ?? '' ) );
	$body   = sanitize_textarea_field( wp_unslash( $_POST['body'] ?? '' ) );
	$step   = sanitize_key( $_POST['step'] ?? '' );
	if ( ! in_array( $from, $froms, true ) ) {
		$from = $froms[0];
	}

	echo '<div class="wrap"><h1>Email bookers</h1>';

	if ( 'send' === $step && check_admin_referer( 'ew_eb_send' ) && $event && $subj && $body ) {
		$people = ew_eb_people( $event );
		$pick   = array_map( 'strtolower', (array) wp_unslash( $_POST['to'] ?? array() ) );
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
			$code = ew_eb_code();
			$msg  = ew_eb_render( str_replace( '{first_name}', ew_eb_first( $p['name'] ), $body ), $code );
			$alt  = function ( $mail ) use ( $msg ) {
				$mail->AltBody = $msg['text'];
			};
			add_action( 'phpmailer_init', $alt, 99 );
			$ok = wp_mail( $em, $subj, $msg['html'], array( 'From: Eastwood FC <' . $from . '>', 'Content-Type: text/html; charset=UTF-8' ) );
			remove_action( 'phpmailer_init', $alt, 99 );
			if ( $ok ) {
				update_post_meta( $p['ids'][0], $keys[0], time() );
				if ( $msg['tracked'] ) {
					add_post_meta( $p['ids'][0], '_ewt_r', $code );
					update_post_meta( $p['ids'][0], '_ewt_r_' . substr( $keys[0], 8 ), $code );
				}
				$done[ $em ] = time();
				$sent++;
			} else {
				$fail[] = $em;
			}
		}
		echo '<div class="notice notice-' . ( $fail ? 'warning' : 'success' ) . '"><p>Sent ' . (int) $sent . ' from ' . esc_html( $from ) . '.'
			. ( $skip ? ' ' . (int) $skip . ' already had this email, so were skipped.' : '' )
			. ( $fail ? ' Could not send to: ' . esc_html( implode( ', ', $fail ) ) . '.' : '' ) . '</p></div>';
		$step = '';
	}

	echo '<form method="post">';
	echo '<table class="form-table"><tr><th>Event</th><td><select name="event">';
	foreach ( $events as $ev => $n ) {
		echo '<option value="' . esc_attr( $ev ) . '"' . selected( $ev, $event, false ) . '>' . esc_html( $ev ) . '</option>';
	}
	echo '</select></td></tr>';
	echo '<tr><th>From</th><td><select name="from">';
	foreach ( $froms as $f ) {
		echo '<option' . selected( $f, $from, false ) . '>' . esc_html( $f ) . '</option>';
	}
	echo '</select></td></tr>';
	echo '<tr><th>Subject</th><td><input type="text" name="subject" class="large-text" value="' . esc_attr( $subj ) . '"></td></tr>';
	echo '<tr><th>Message</th><td><textarea name="body" rows="14" class="large-text">' . esc_textarea( $body ) . '</textarea><p class="description">{first_name} is replaced with each person\'s first name. [Book your tickets](https://eastwoodfc.com/...) shows the words as a link. Every eastwoodfc.com link is tracked for each person.</p></td></tr></table>';

	if ( 'preview' === $step && $event ) {
		wp_nonce_field( 'ew_eb_send' );
		$people = ew_eb_people( $event );
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
		echo '<input type="hidden" name="step" value="send">';
		submit_button( 'Send to the ticked people' );
	} else {
		echo '<input type="hidden" name="step" value="preview">';
		submit_button( 'Show who it goes to' );
	}
	echo '</form></div>';
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
function ew_eb_render( $body, $code ) {
	$links   = array();
	$tracked = false;
	$body    = preg_replace_callback(
		'~\[([^\]\n]+)\]\((https?://[^\s)]+)\)|(https?://[^\s<>"]+)~',
		function ( $m ) use ( &$links, $code, &$tracked ) {
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
				'html' => '<a href="' . esc_url( $href ) . '" style="color:#cc0000;font-weight:600">' . esc_html( $show ) . '</a>',
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
