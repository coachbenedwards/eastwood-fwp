<?php
/**
 * Academy trial sign-ups: one page per trial date, under /academy/.
 *
 * The Academy page lists the trials the way the Events page lists nights, and
 * each date has its own page carrying this block. Somebody puts their name
 * down for that date and it becomes a Booking (Bookings in the admin menu),
 * status "trial", so the list for the day sits with every other list.
 *
 * [eastwood_trial event="October open trial, Wednesday 28 October 2026"]
 *
 * Nothing is charged here. Every word the block shows is a field on the
 * block; the heading and the paragraphs around it are the page widgets.
 *
 * @package eastwood-fwp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The block fields and what they say until someone changes them. */
function ew_trial_defaults() {
	return array(
		'event'       => '',
		'form_title'  => 'Put your name down',
		'form_note'   => 'We will send you the arrival time and anything that changes.',
		'name_label'  => "Player's full name",
		'pos_label'   => 'Position',
		'dob_label'   => 'Date of birth',
		'club_label'  => 'Current football club',
		'loc_label'   => 'Where you live',
		'school_label'=> 'Current school',
		'email_label' => 'Your email',
		'phone_label' => 'Your mobile',
		'guard_label' => 'Parent or guardian number',
		'show_pos'    => '1',
		'show_dob'    => '1',
		'show_club'   => '1',
		'show_loc'    => '1',
		'show_school' => '1',
		'show_phone'  => '1',
		'show_guard'  => '1',
		'consent'     => 'Tell me about the next trial too',
		'send'        => 'Put my name down',
		'free_note'   => 'Free. Nothing is taken from you on this page.',
		'need_name'   => 'We need the player name for the list.',
		'need_email'  => 'We need an email to get back to you on.',
		'need_phone'  => 'We need a mobile number for the day.',
		'failed'      => 'Something went wrong. Please try again.',
		'done_title'  => 'You are down',
		'done_note'   => 'We will be in touch before the day with the time to arrive and what to bring.',
		'crm_hook'    => '',
		/* The Google Form this also fills in, and which of its boxes takes what.
		   Leave either empty and no form is filled. */
		'gform'       => '',
		'gmap'        => '',
	);
}

/** Is a yes-or-no field switched on. */
function ew_trial_on( $v ) {
	return '' !== trim( (string) $v ) && '0' !== trim( (string) $v );
}

function ew_trial_shortcode( $atts ) {
	$a    = shortcode_atts( ew_trial_defaults(), (array) $atts, 'eastwood_trial' );
	$post = get_post();

	$words = array(
		'need_name'  => $a['need_name'],
		'need_email' => $a['need_email'],
		'need_phone' => $a['need_phone'],
		'failed'     => $a['failed'],
		'phone'      => ew_trial_on( $a['show_phone'] ) ? 1 : 0,
		'rest'       => esc_url_raw( rest_url( 'eastwood/v1/trial' ) ),
	);

	$field = function ( $id, $label, $type, $auto ) {
		return '<label class="ewtr-lab" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>'
			. '<input class="ewtr-in" id="' . esc_attr( $id ) . '" type="' . esc_attr( $type ) . '" autocomplete="' . esc_attr( $auto ) . '">';
	};

	$h  = '<section class="ewtr" data-page="' . (int) ( $post ? $post->ID : 0 ) . '">';
	$h .= '<form class="ewtr-form" novalidate>';
	$h .= '<p class="ewtr-head">' . esc_html( $a['form_title'] ) . '</p>';
	if ( '' !== trim( (string) $a['form_note'] ) ) {
		$h .= '<p class="ewtr-note">' . esc_html( $a['form_note'] ) . '</p>';
	}
	$h .= $field( 'ewtrName', $a['name_label'], 'text', 'name' );

	/*
	 * Position and date of birth share a row only when both are asked for.
	 * The row is 1fr plus a fixed 150, so one field on its own used to sit
	 * in the wide column with 150 pixels of nothing beside it.
	 *
	 * Date of birth is a date field rather than a text box. A text box
	 * collects "12/03/08", "March 08" and "12th March 2008" in the same
	 * column, and nothing downstream can read all three.
	 */
	$pos = ew_trial_on( $a['show_pos'] );
	$dob = ew_trial_on( $a['show_dob'] );
	if ( $pos && $dob ) {
		$h .= '<div class="ewtr-two">';
		$h .= '<div>' . $field( 'ewtrPos', $a['pos_label'], 'text', 'off' ) . '</div>';
		$h .= '<div>' . $field( 'ewtrDob', $a['dob_label'], 'date', 'bday' ) . '</div>';
		$h .= '</div>';
	} elseif ( $pos ) {
		$h .= $field( 'ewtrPos', $a['pos_label'], 'text', 'off' );
	} elseif ( $dob ) {
		$h .= $field( 'ewtrDob', $a['dob_label'], 'date', 'bday' );
	}
	if ( ew_trial_on( $a['show_loc'] ) ) {
		$h .= $field( 'ewtrLoc', $a['loc_label'], 'text', 'address-level2' );
	}
	if ( ew_trial_on( $a['show_club'] ) ) {
		$h .= $field( 'ewtrClub', $a['club_label'], 'text', 'off' );
	}
	if ( ew_trial_on( $a['show_school'] ) ) {
		$h .= $field( 'ewtrSchool', $a['school_label'], 'text', 'off' );
	}
	$h .= $field( 'ewtrEmail', $a['email_label'], 'email', 'email' );
	if ( ew_trial_on( $a['show_phone'] ) ) {
		$h .= $field( 'ewtrPhone', $a['phone_label'], 'tel', 'tel' );
	}
	if ( ew_trial_on( $a['show_guard'] ) ) {
		$h .= $field( 'ewtrGuard', $a['guard_label'], 'tel', 'off' );
	}
	$h .= '<label class="ewtr-tick"><input type="checkbox" id="ewtrConsent" checked><span>' . esc_html( $a['consent'] ) . '</span></label>';
	$h .= '<p class="ewtr-err" role="alert"></p>';
	$h .= '<button type="submit" class="ewtr-btn">' . esc_html( $a['send'] ) . '</button>';
	if ( '' !== trim( (string) $a['free_note'] ) ) {
		$h .= '<p class="ewtr-free">' . esc_html( $a['free_note'] ) . '</p>';
	}
	$h .= '</form>';
	$h .= '<div class="ewtr-done" hidden><p class="ewtr-done-t">' . esc_html( $a['done_title'] ) . '</p><p class="ewtr-done-n">' . esc_html( $a['done_note'] ) . '</p></div>';
	$h .= '<script type="application/json" class="ewtr-words">' . wp_json_encode( $words ) . '</script>';
	return $h . '</section>';
}
add_shortcode( 'eastwood_trial', 'ew_trial_shortcode' );

/** The block on a page, read from the page itself, so a sign-up carries its words. */
function ew_trial_block_on( $post ) {
	$found = null;
	$data  = json_decode( (string) get_post_meta( $post->ID, '_elementor_data', true ), true );
	if ( is_array( $data ) ) {
		$walk = function ( $nodes ) use ( &$walk, &$found ) {
			foreach ( (array) $nodes as $n ) {
				if ( $found ) {
					return;
				}
				$sc = (string) ( $n['settings']['shortcode'] ?? '' );
				if ( 0 === strpos( ltrim( $sc ), '[eastwood_trial' ) ) {
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
	if ( ! $found && preg_match( '/\[eastwood_trial[^\]]*\]/', (string) $post->post_content, $mm ) ) {
		$found = $mm[0];
	}
	if ( ! $found ) {
		return null;
	}
	$atts = array();
	if ( preg_match( '/\[eastwood_trial([^\]]*)\]/', $found, $o ) ) {
		$atts = (array) shortcode_parse_atts( $o[1] );
	}
	return shortcode_atts( ew_trial_defaults(), $atts, 'eastwood_trial' );
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'eastwood/v1', '/trial', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) {
			$post = get_post( (int) $req->get_param( 'page' ) );
			$a    = $post ? ew_trial_block_on( $post ) : null;
			if ( ! $a ) {
				return new WP_REST_Response( array( 'message' => 'no block' ), 400 );
			}
			$name  = trim( sanitize_text_field( (string) $req->get_param( 'name' ) ) );
			$email = sanitize_email( (string) $req->get_param( 'email' ) );
			$phone = trim( sanitize_text_field( (string) $req->get_param( 'phone' ) ) );
			$pos   = trim( sanitize_text_field( (string) $req->get_param( 'pos' ) ) );
			$dob   = trim( sanitize_text_field( (string) $req->get_param( 'dob' ) ) );
			$club  = trim( sanitize_text_field( (string) $req->get_param( 'club' ) ) );
			$loc    = trim( sanitize_text_field( (string) $req->get_param( 'loc' ) ) );
			$school = trim( sanitize_text_field( (string) $req->get_param( 'school' ) ) );
			$guard  = trim( sanitize_text_field( (string) $req->get_param( 'guard' ) ) );
			if ( '' === $name ) {
				return new WP_REST_Response( array( 'message' => $a['need_name'] ), 400 );
			}
			if ( ! is_email( $email ) ) {
				return new WP_REST_Response( array( 'message' => $a['need_email'] ), 400 );
			}
			if ( ew_trial_on( $a['show_phone'] ) && strlen( preg_replace( '/\D/', '', $phone ) ) < 10 ) {
				return new WP_REST_Response( array( 'message' => $a['need_phone'] ), 400 );
			}
			$about = array();
			if ( '' !== $pos ) {
				$about[] = $pos;
			}
			if ( '' !== $dob ) {
				$about[] = 'born ' . $dob;
			}
			if ( '' !== $club ) {
				$about[] = $club;
			}
			if ( '' !== $loc ) {
				$about[] = $loc;
			}
			if ( '' !== $school ) {
				$about[] = $school;
			}
			$about = implode( ', ', $about );
			$id    = wp_insert_post( array(
				'post_type'   => 'ewt_booking',
				'post_status' => 'publish',
				'post_title'  => $name,
			) );
			if ( ! $id || is_wp_error( $id ) ) {
				return new WP_REST_Response( array( 'message' => $a['failed'] ), 500 );
			}
			$event = '' !== trim( (string) $a['event'] ) ? $a['event'] : get_the_title( $post );
			foreach ( array(
				'name'    => $name,
				'email'   => $email,
				'phone'   => $phone,
				'loc'     => $loc,
				'school'  => $school,
				'guardian'=> $guard,
				'event'   => $event,
				'page'    => $post->ID,
				'url'     => get_permalink( $post ),
				'lines'   => '' !== $about ? $about : $name,
				'extra'   => '' !== $about ? 'Trial: ' . $about : 'Trial',
				'total'   => 0,
				'status'  => 'trial',
				'consent' => $req->get_param( 'consent' ) ? 'yes' : 'no',
				'hook'    => (string) $a['crm_hook'],
			) as $k => $v ) {
				update_post_meta( $id, '_ewt_' . $k, $v );
			}
			if ( function_exists( 'ew_tix_crm' ) ) {
				ew_tix_crm( (string) $a['crm_hook'], $id, 'trial' );
			}
			ew_trial_gform( $a, array(
				'name'  => $name,
				'email' => $email,
				'phone' => $phone,
				'pos'   => $pos,
				'dob'   => $dob,
				'club'   => $club,
				'loc'    => $loc,
				'school' => $school,
				'guard'  => $guard,
				'event'  => $event,
			) );
			return array( 'ok' => 1 );
		},
	) );
} );

/**
 * Fill in a Google Form as well, where the block names one.
 *
 * gform is the form address with /formResponse on the end, and gmap says
 * which of our answers goes in which of its boxes:
 * "name=entry.123; email=entry.456". Sent without waiting, so a slow or a
 * changed form never holds up the person registering.
 */
function ew_trial_gform( $a, $vals ) {
	$url = trim( (string) ( $a['gform'] ?? '' ) );
	$map = trim( (string) ( $a['gmap'] ?? '' ) );
	if ( '' === $url || '' === $map || 0 !== strpos( $url, 'https://' ) ) {
		return;
	}
	$body = array();
	foreach ( preg_split( '/[;\n]+/', $map ) as $pair ) {
		$pair = trim( (string) $pair );
		if ( '' === $pair || false === strpos( $pair, '=' ) ) {
			continue;
		}
		$bits   = explode( '=', $pair, 2 );
		$ours   = trim( $bits[0] );
		$theirs = trim( $bits[1] );
		if ( '' === $theirs || empty( $vals[ $ours ] ) ) {
			continue;
		}
		$body[ $theirs ] = (string) $vals[ $ours ];
	}
	if ( ! $body ) {
		return;
	}
	wp_remote_post( $url, array(
		'timeout'  => 5,
		'blocking' => false,
		'body'     => $body,
	) );
}

function ew_trial_css() {
	return '
.ewtr{font-family:"Instrument Sans",system-ui,sans-serif;color:#101010}
.ewtr *{box-sizing:border-box}
.ewtr [hidden]{display:none!important}
.ewtr-form{background:#0b0b0b;color:#fff;border-radius:14px;padding:40px 40px 44px}
.ewtr .ewtr-head{margin:0;font-family:Anton,Impact,sans-serif;font-size:36px;line-height:1;text-transform:uppercase;color:#fff}
.ewtr .ewtr-note{margin:12px 0 10px;font-size:16px;line-height:1.5;color:#bdbdbd}
.ewtr .ewtr-lab{display:block;margin:18px 0 7px;font-size:14px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#fff}
.ewtr-two{display:grid;grid-template-columns:minmax(0,1fr) 150px;gap:14px}
.ewtr-two .ewtr-lab{margin-top:18px}
.ewtr .ewtr-in{width:100%;height:52px;border:1px solid rgba(255,255,255,.35);border-radius:8px;background:#111;color:#fff;font:inherit;font-size:17px;padding:0 14px}
.ewtr .ewtr-in:focus{outline:none;border-color:#fff}
/* The native date picker draws its own icon and panel; without this it
   paints a light-theme control on the dark field and the icon vanishes. */
.ewtr .ewtr-in[type=date]{color-scheme:dark}
.ewtr .ewtr-tick{display:flex;gap:10px;align-items:flex-start;margin:20px 0 0;font-size:15px;line-height:1.45;color:#fff;cursor:pointer}
.ewtr .ewtr-tick input{margin:3px 0 0;width:18px;height:18px}
.ewtr .ewtr-err{margin:12px 0 0;min-height:1.2em;font-size:14px;color:#ff6b6b}
.ewtr .ewtr-btn{display:block;width:100%;height:56px;margin-top:8px;border:0;border-radius:8px;background:#CC0000;color:#fff;font-family:Anton,Impact,sans-serif;font-size:22px;letter-spacing:.04em;text-transform:uppercase;cursor:pointer}
.ewtr .ewtr-btn[disabled]{opacity:.6;cursor:default}
.ewtr .ewtr-free{margin:14px 0 0;font-size:14px;line-height:1.5;color:#8f8f8f}
.ewtr-done{background:#0b0b0b;color:#fff;border-radius:14px;padding:48px 40px;text-align:center}
.ewtr .ewtr-done-t{margin:0 0 10px;font-family:Anton,Impact,sans-serif;font-size:48px;line-height:1;text-transform:uppercase;color:#fff}
.ewtr .ewtr-done-n{margin:0;font-size:17px;line-height:1.5;color:#fff}
@media (max-width:640px){.ewtr-form{padding:28px 20px 32px}.ewtr-two{grid-template-columns:minmax(0,1fr)}}
';
}

function ew_trial_js() {
	return <<<'JS'
(function(){
 var root = document.querySelector('.ewtr'); if(!root) return;
 var wordsEl = root.querySelector('.ewtr-words'); if(!wordsEl) return;
 var W = JSON.parse(wordsEl.textContent), page = root.getAttribute('data-page');
 var form = root.querySelector('.ewtr-form'), err = root.querySelector('.ewtr-err');
 function val(id){ var el = root.querySelector('#' + id); return el ? el.value.trim() : ''; }
 form.addEventListener('submit', function(e){
   e.preventDefault(); err.textContent = '';
   var name = val('ewtrName'), email = val('ewtrEmail'), phone = val('ewtrPhone');
   if(!name){ err.textContent = W.need_name; return; }
   if(!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)){ err.textContent = W.need_email; return; }
   if(W.phone && phone.replace(/\D/g,'').length < 10){ err.textContent = W.need_phone; return; }
   var tick = root.querySelector('#ewtrConsent');
   var btn = form.querySelector('.ewtr-btn'); btn.disabled = true;
   fetch(W.rest, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({
     page: page, name: name, email: email, phone: phone,
     pos: val('ewtrPos'), dob: val('ewtrDob'), club: val('ewtrClub'),
     loc: val('ewtrLoc'), school: val('ewtrSchool'), guard: val('ewtrGuard'),
     consent: (tick && tick.checked) ? 1 : 0
   })})
   .then(function(r){ return r.json().then(function(j){ return {ok: r.ok, j: j}; }); })
   .then(function(x){
     if(!x.ok){ err.textContent = (x.j && x.j.message) || W.failed; btn.disabled = false; return; }
     form.hidden = true; root.querySelector('.ewtr-done').hidden = false;
     if(window.ewTrack) ewTrack('Lead', {what: 'Academy trial'});
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
	if ( ! $post || ! function_exists( 'ew_post_contains' ) || ! ew_post_contains( $post, 'eastwood_trial' ) ) {
		return;
	}
	wp_enqueue_style( 'ewc-fonts', 'https://fonts.googleapis.com/css2?family=Anton&family=Instrument+Sans:wght@400;600;700&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
	wp_register_style( 'ew-trial', false, array(), EW_FWP_VERSION );
	wp_enqueue_style( 'ew-trial' );
	wp_add_inline_style( 'ew-trial', ew_trial_css() );
	wp_register_script( 'ew-trial', '', array(), EW_FWP_VERSION, true );
	wp_enqueue_script( 'ew-trial' );
	wp_add_inline_script( 'ew-trial', ew_trial_js() );
}, 22 );
