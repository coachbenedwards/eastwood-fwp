<?php
/**
 * The half-time show: eastwoodfc.com/halftimeshow.
 *
 * The red strip on the match tickets points here. A parent puts a child
 * forward for the half-time show at the next home game: the child's name and
 * age, then who they are and how to reach them. That is a Booking (Bookings in
 * the admin menu), status "halftime", with the child on it, so the list for
 * the day is the same list the tickets use.
 *
 * [eastwood_halftime event="Eastwood FC v Hucknall Town, Saturday 14 November 2026"]
 *
 * Every word the block shows is a field on the block. The heading and the
 * paragraphs around it are the page's own widgets, not this block.
 *
 * @package eastwood-fwp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The block's fields and what they say until someone changes them. */
function ew_halftime_defaults() {
	return array(
		'event'       => 'Eastwood FC v Hucknall Town, Saturday 14 November 2026',
		'form_title'  => 'Put your child forward',
		'child_label' => "Child's name",
		'age_label'   => 'Age',
		'more'        => 'Add another child',
		'remove'      => 'Remove',
		'name_label'  => 'Your full name',
		'email_label' => 'Your email',
		'phone_label' => 'Your mobile',
		'consent'     => 'Tell me about the next one too',
		'send'        => 'Count us in',
		'need_child'  => 'We need at least one child, with a name and an age.',
		'need_name'   => 'We need your full name.',
		'need_email'  => 'We need an email to get back to you on.',
		'need_phone'  => 'We need a mobile number for the day.',
		'failed'      => 'Something went wrong. Please try again.',
		'done_title'  => "You're in",
		'done_note'   => "We'll be in touch before the game with where to be and when.",
		'max'         => '6',
		'crm_hook'    => '',
	);
}

function ew_halftime_shortcode( $atts ) {
	$a    = shortcode_atts( ew_halftime_defaults(), (array) $atts, 'eastwood_halftime' );
	$post = get_post();
	$max  = max( 1, min( 12, (int) $a['max'] ) );

	$words = array(
		'need_child' => $a['need_child'],
		'need_name'  => $a['need_name'],
		'need_email' => $a['need_email'],
		'need_phone' => $a['need_phone'],
		'failed'     => $a['failed'],
		'child'      => $a['child_label'],
		'age'        => $a['age_label'],
		'remove'     => $a['remove'],
		'max'        => $max,
		'rest'       => esc_url_raw( rest_url( 'eastwood/v1/halftime' ) ),
	);

	$h  = '<section class="ewh" data-page="' . (int) ( $post ? $post->ID : 0 ) . '">';
	$h .= '<form class="ewh-form" novalidate>';
	$h .= '<p class="ewh-head">' . esc_html( $a['form_title'] ) . '</p>';
	$h .= '<div class="ewh-kids"></div>';
	$h .= '<button type="button" class="ewh-more"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>' . esc_html( $a['more'] ) . '</button>';
	$h .= '<label class="ewh-lab" for="ewhName">' . esc_html( $a['name_label'] ) . '</label><input class="ewh-in" id="ewhName" type="text" autocomplete="name" required>';
	$h .= '<label class="ewh-lab" for="ewhEmail">' . esc_html( $a['email_label'] ) . '</label><input class="ewh-in" id="ewhEmail" type="email" autocomplete="email" required>';
	$h .= '<label class="ewh-lab" for="ewhPhone">' . esc_html( $a['phone_label'] ) . '</label><input class="ewh-in" id="ewhPhone" type="tel" autocomplete="tel" required>';
	$h .= '<label class="ewh-tick"><input type="checkbox" id="ewhConsent" checked><span>' . esc_html( $a['consent'] ) . '</span></label>';
	$h .= '<p class="ewh-err" role="alert"></p>';
	$h .= '<button type="submit" class="ewh-btn">' . esc_html( $a['send'] ) . '</button>';
	$h .= '</form>';
	$h .= '<div class="ewh-done" hidden><p class="ewh-done-t">' . esc_html( $a['done_title'] ) . '</p><p class="ewh-done-n">' . esc_html( $a['done_note'] ) . '</p></div>';
	$h .= '<script type="application/json" class="ewh-words">' . wp_json_encode( $words ) . '</script>';
	return $h . '</section>';
}
add_shortcode( 'eastwood_halftime', 'ew_halftime_shortcode' );

/** The block on a page, read from the page itself, so a claim carries its words. */
function ew_halftime_block_on( $post ) {
	$found = null;
	$data  = json_decode( (string) get_post_meta( $post->ID, '_elementor_data', true ), true );
	if ( is_array( $data ) ) {
		$walk = function ( $nodes ) use ( &$walk, &$found ) {
			foreach ( (array) $nodes as $n ) {
				if ( $found ) {
					return;
				}
				$sc = (string) ( $n['settings']['shortcode'] ?? '' );
				if ( 0 === strpos( ltrim( $sc ), '[eastwood_halftime' ) ) {
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
	if ( ! $found && preg_match( '/\[eastwood_halftime[^\]]*\]/', (string) $post->post_content, $mm ) ) {
		$found = $mm[0];
	}
	if ( ! $found ) {
		return null;
	}
	$atts = array();
	if ( preg_match( '/\[eastwood_halftime([^\]]*)\]/', $found, $o ) ) {
		$atts = (array) shortcode_parse_atts( $o[1] );
	}
	return shortcode_atts( ew_halftime_defaults(), $atts, 'eastwood_halftime' );
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'eastwood/v1', '/halftime', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) {
			$post = get_post( (int) $req->get_param( 'page' ) );
			$a    = $post ? ew_halftime_block_on( $post ) : null;
			if ( ! $a ) {
				return new WP_REST_Response( array( 'message' => 'no block' ), 400 );
			}
			$name  = ew_person_name( sanitize_text_field( (string) $req->get_param( 'name' ) ) );
			$email = sanitize_email( (string) $req->get_param( 'email' ) );
			$phone = trim( sanitize_text_field( (string) $req->get_param( 'phone' ) ) );
			$kids  = array();
			foreach ( array_slice( (array) $req->get_param( 'children' ), 0, max( 1, min( 12, (int) $a['max'] ) ) ) as $k ) {
				$kn = ew_person_name( sanitize_text_field( (string) ( $k['name'] ?? '' ) ) );
				$ka = (int) ( $k['age'] ?? 0 );
				if ( '' !== $kn && $ka > 0 && $ka < 18 ) {
					$kids[] = $kn . ' (' . $ka . ')';
				}
			}
			if ( ! $kids ) {
				return new WP_REST_Response( array( 'message' => $a['need_child'] ), 400 );
			}
			if ( '' === $name ) {
				return new WP_REST_Response( array( 'message' => $a['need_name'] ), 400 );
			}
			if ( ! is_email( $email ) ) {
				return new WP_REST_Response( array( 'message' => $a['need_email'] ), 400 );
			}
			if ( strlen( preg_replace( '/\D/', '', $phone ) ) < 10 ) {
				return new WP_REST_Response( array( 'message' => $a['need_phone'] ), 400 );
			}
			$id = wp_insert_post( array(
				'post_type'   => 'ewt_booking',
				'post_status' => 'publish',
				'post_title'  => $name,
			) );
			if ( ! $id || is_wp_error( $id ) ) {
				return new WP_REST_Response( array( 'message' => $a['failed'] ), 500 );
			}
			foreach ( array(
				'name'     => $name,
				'email'    => $email,
				'phone'    => $phone,
				'event'    => $a['event'],
				'page'     => $post->ID,
				'url'      => get_permalink( $post ),
				'lines'    => implode( ', ', $kids ),
				'extra'    => 'Half-time show: ' . implode( ', ', $kids ),
				'children' => count( $kids ),
				'total'    => 0,
				'status'   => 'halftime',
				'consent'  => $req->get_param( 'consent' ) ? 'yes' : 'no',
				'hook'     => (string) $a['crm_hook'],
			) as $k => $v ) {
				update_post_meta( $id, '_ewt_' . $k, $v );
			}
			if ( function_exists( 'ew_tix_crm' ) ) {
				ew_tix_crm( (string) $a['crm_hook'], $id, 'halftime' );
			}
			return array( 'ok' => 1 );
		},
	) );
} );

function ew_halftime_css() {
	return '
.ewh{font-family:"Instrument Sans",system-ui,sans-serif;color:#101010;max-width:640px;margin:0 auto}
.ewh *{box-sizing:border-box}
.ewh [hidden]{display:none!important}
.ewh-form{background:#000;color:#fff;border-radius:14px;padding:36px 40px 40px}
.ewh .ewh-head{margin:0 0 18px;font-family:Anton,Impact,sans-serif;font-size:34px;line-height:1;text-transform:uppercase;color:#fff}
.ewh-kid{display:grid;grid-template-columns:minmax(0,1fr) 96px auto;gap:10px;align-items:end;margin-bottom:10px}
.ewh .ewh-lab{display:block;margin:16px 0 6px;font-size:14px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#fff}
.ewh-kid .ewh-lab{margin-top:0}
.ewh .ewh-in{width:100%;height:50px;border:1px solid rgba(255,255,255,.35);border-radius:8px;background:#111;color:#fff;font:inherit;font-size:17px;padding:0 14px}
.ewh .ewh-in:focus{outline:none;border-color:#fff}
.ewh .ewh-x{height:50px;border:1px solid rgba(255,255,255,.35);border-radius:8px;background:transparent;color:#fff;font:inherit;font-size:14px;padding:0 12px;cursor:pointer}
.ewh .ewh-more{display:inline-flex;align-items:center;gap:8px;margin:4px 0 8px;border:0;background:none;color:#fff;font:inherit;font-size:15px;font-weight:700;cursor:pointer;padding:0}
.ewh .ewh-tick{display:flex;gap:10px;align-items:flex-start;margin:18px 0 0;font-size:15px;color:#fff;cursor:pointer}
.ewh .ewh-tick input{margin:3px 0 0;width:18px;height:18px}
.ewh .ewh-err{margin:12px 0 0;min-height:1.2em;font-size:14px;color:#ff6b6b}
.ewh .ewh-btn{display:block;width:100%;height:54px;margin-top:14px;border:0;border-radius:8px;background:#CC0000;color:#fff;font-family:Anton,Impact,sans-serif;font-size:22px;letter-spacing:.04em;text-transform:uppercase;cursor:pointer}
.ewh .ewh-btn[disabled]{opacity:.6;cursor:default}
.ewh-done{background:#000;color:#fff;border-radius:14px;padding:48px 40px;text-align:center}
.ewh .ewh-done-t{margin:0 0 10px;font-family:Anton,Impact,sans-serif;font-size:48px;line-height:1;text-transform:uppercase;color:#fff}
.ewh .ewh-done-n{margin:0;font-size:17px;color:#fff}
@media (max-width:640px){.ewh-form{padding:28px 20px 30px}.ewh-kid{grid-template-columns:minmax(0,1fr) 80px auto}}
';
}

function ew_halftime_js() {
	return <<<'JS'
(function(){
 var root = document.querySelector('.ewh'); if(!root) return;
 var wordsEl = root.querySelector('.ewh-words'); if(!wordsEl) return;
 var W = JSON.parse(wordsEl.textContent), page = root.getAttribute('data-page');
 var form = root.querySelector('.ewh-form'), kids = root.querySelector('.ewh-kids'), more = root.querySelector('.ewh-more'), err = root.querySelector('.ewh-err');
 function esc(s){ return String(s).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
 var n = 0;
 function addKid(){
   if(kids.children.length >= W.max) return;
   n++;
   var row = document.createElement('div'); row.className = 'ewh-kid';
   row.innerHTML = '<div><label class="ewh-lab" for="ewhKid' + n + '">' + esc(W.child) + '</label><input class="ewh-in" id="ewhKid' + n + '" type="text" autocomplete="off"></div>'
     + '<div><label class="ewh-lab" for="ewhAge' + n + '">' + esc(W.age) + '</label><input class="ewh-in" id="ewhAge' + n + '" type="number" min="1" max="17" inputmode="numeric"></div>'
     + '<button type="button" class="ewh-x" aria-label="' + esc(W.remove) + '">&times;</button>';
   row.querySelector('.ewh-x').addEventListener('click', function(){ if(kids.children.length > 1) row.remove(); more.hidden = kids.children.length >= W.max; });
   kids.appendChild(row); more.hidden = kids.children.length >= W.max;
 }
 addKid();
 more.addEventListener('click', addKid);
 form.addEventListener('submit', function(e){
   e.preventDefault(); err.textContent = '';
   var children = [];
   kids.querySelectorAll('.ewh-kid').forEach(function(r){
     var i = r.querySelectorAll('input'); var name = i[0].value.trim(), age = parseInt(i[1].value, 10);
     if(name && age > 0) children.push({name: name, age: age});
   });
   var name = root.querySelector('#ewhName').value.trim(), email = root.querySelector('#ewhEmail').value.trim(), phone = root.querySelector('#ewhPhone').value.trim();
   if(!children.length){ err.textContent = W.need_child; return; }
   if(!name){ err.textContent = W.need_name; return; }
   if(!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)){ err.textContent = W.need_email; return; }
   if(phone.replace(/\D/g,'').length < 10){ err.textContent = W.need_phone; return; }
   var btn = form.querySelector('.ewh-btn'); btn.disabled = true;
   fetch(W.rest, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({page: page, children: children, name: name, email: email, phone: phone, consent: root.querySelector('#ewhConsent').checked ? 1 : 0})})
   .then(function(r){ return r.json().then(function(j){ return {ok: r.ok, j: j}; }); })
   .then(function(x){
     if(!x.ok){ err.textContent = (x.j && x.j.message) || W.failed; btn.disabled = false; return; }
     form.hidden = true; root.querySelector('.ewh-done').hidden = false;
     if(window.ewTrack) ewTrack('Lead', {what: 'Half-time show'});
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
	if ( ! $post || ! function_exists( 'ew_post_contains' ) || ! ew_post_contains( $post, 'eastwood_halftime' ) ) {
		return;
	}
	wp_enqueue_style( 'ewc-fonts', 'https://fonts.googleapis.com/css2?family=Anton&family=Instrument+Sans:wght@400;600;700&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
	wp_register_style( 'ew-halftime', false, array(), EW_FWP_VERSION );
	wp_enqueue_style( 'ew-halftime' );
	wp_add_inline_style( 'ew-halftime', ew_halftime_css() );
	wp_register_script( 'ew-halftime', '', array(), EW_FWP_VERSION, true );
	wp_enqueue_script( 'ew-halftime' );
	wp_add_inline_script( 'ew-halftime', ew_halftime_js() );
}, 22 );
