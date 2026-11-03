<?php
/**
 * [eastwood_feedback] Trial day feedback from parents, in four short steps.
 *
 * Every word on it is a field on the block. A reply is kept as a booking
 * (event "Trial day feedback", status "feedback") and the academy team is
 * emailed a summary. Anonymous keeps the answers with no name or email.
 *
 * A link with ?k= (made by ew_fb_key for a trial booking) fills the names in.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ew_fb_defaults() {
	return array(
		'event'        => 'Trial day feedback',
		'notify_to'    => '',
		'from'         => 'academy@eastwoodfc.com',
		'title'        => 'Your feedback',
		'note'         => 'Four short steps. Only the academy team sees this.',
		'step1'        => 'The day',
		'step2'        => 'What worked',
		'step3'        => 'Your options',
		'step4'        => 'About you',
		'q_score'      => 'How was the day overall?',
		'low'          => 'Poor',
		'high'         => 'Brilliant',
		'q_felt'       => 'How did {player} find it?',
		'felt'         => 'Loved it|Enjoyed it|It was OK|Did not enjoy it',
		'q_liked'      => 'What did you like most?',
		'q_better'     => 'What could we do better?',
		'q_info'       => 'Did you get all the information you needed?',
		'info'         => 'Yes|Partly|No',
		'q_place'      => 'Would {player} like a place with us?',
		'place'        => 'Yes|Maybe|No',
		'q_progs'      => 'Which other football programmes have you looked at or been to?',
		'progs_note'   => 'Tick any. It helps us know what families are comparing us with.',
		'programmes'   => 'Basford United Academy|G3A Football Academy (Ilkeston)|Steven Gerrard Academy (Stapleford)|A school sixth form team::Which school?|A college team::Which college?|None, Eastwood is the only one|Somewhere else::Where?',
		'q_who'        => 'Put your name to it, or stay anonymous',
		'named'        => 'Use my name',
		'anon'         => 'Stay anonymous',
		'anon_note'    => 'Anonymous means we keep your answers without your name or email.',
		'parent_label' => 'Parent or guardian name',
		'player_label' => "Player's name",
		'email_label'  => 'Your email',
		'q_else'       => 'Anything else, or a question?',
		'consent'      => 'Happy for us to use what you said on our website, without your name',
		'next'         => 'Next',
		'back'         => 'Back',
		'send'         => 'Send feedback',
		'need_score'   => 'Pick a score from 1 to 5.',
		'failed'       => 'Something went wrong. Please try again.',
		'done_title'   => 'Thank you',
		'done_note'    => 'We have got your feedback. Lewis and the academy team will read it.',
		'done_place'   => 'You said {player} might like a place.',
		'done_btn'     => 'Register your interest',
		'interest_url' => '/academy/register-interest/',
		'player_word'  => 'your child',
		'subject'      => 'Trial day feedback: {score}/5 from {who}',
		// The email that asks a trial family for feedback.
		'mail_subject' => 'How was the trial day?',
		'mail_name'    => 'Eastwood FC Academy',
		'mail_crest'   => '/wp-content/uploads/ew-badges/2059.png',
		'mail_photo'   => '/wp-content/plugins/eastwood-fwp/assets/email/academy.jpg',
		'mail_title'   => 'How was the trial day?',
		'mail_hi'      => 'Hi {parent},',
		'mail_date'    => 'Wednesday 28 October',
		'mail_body'    => 'Thank you for bringing {player} to the Eastwood FC Academy trial day on {date}.\n\nWe would love to know how it went for you both. It takes two minutes.',
		'mail_button'  => 'Give your feedback',
		'mail_sign'    => 'Thank you,\nLewis McGugan\nHead of Academy, Eastwood FC',
		'mail_footer'  => 'Eastwood Football Club · Coronation Park, Chewton Street, Eastwood NG16 3HB',
		'mail_there'   => 'there',
	);
}

/** The feedback request email for one family. $who: parent, player, bid. */
function ew_fb_request_mail( $a, $post, $who ) {
	$vals = array(
		'parent' => '' !== trim( (string) ( $who['parent'] ?? '' ) ) ? trim( $who['parent'] ) : $a['mail_there'],
		'player' => '' !== trim( (string) ( $who['player'] ?? '' ) ) ? trim( $who['player'] ) : $a['player_word'],
		'date'   => $a['mail_date'],
	);
	$link = get_permalink( $post );
	if ( ! empty( $who['bid'] ) ) {
		$link = add_query_arg( 'k', ew_fb_key( (int) $who['bid'] ), $link );
	}
	$paras = array_merge(
		array( ew_acad_fill( $a['mail_hi'], $vals ) ),
		preg_split( '/\n\s*\n/', ew_acad_fill( str_replace( '\n', "\n", (string) $a['mail_body'] ), $vals ) )
	);
	$m = array(
		'crest'  => $a['mail_crest'],
		'name'   => $a['mail_name'],
		'photo'  => $a['mail_photo'],
		'title'  => $a['mail_title'],
		'paras'  => $paras,
		'button' => $a['mail_button'],
		'link'   => $link,
		'sign'   => $a['mail_sign'],
		'footer' => $a['mail_footer'],
	);
	return array( ew_mail_card( $m ), ew_mail_card_text( $m ) );
}

/** The link that fills a trial family's names in. */
function ew_fb_key( $booking_id ) {
	$id = (int) $booking_id;
	return $id . '-' . substr( wp_hash( 'fb|' . $id ), 0, 12 );
}

function ew_fb_from_key( $k ) {
	$k = preg_replace( '/[^0-9a-zA-Z-]/', '', (string) $k );
	$p = explode( '-', $k, 2 );
	if ( 2 !== count( $p ) || ! hash_equals( substr( wp_hash( 'fb|' . (int) $p[0] ), 0, 12 ), $p[1] ) || 'ewt_booking' !== get_post_type( (int) $p[0] ) ) {
		return 0;
	}
	return (int) $p[0];
}

function ew_fb_list( $s ) {
	return array_values( array_filter( array_map( 'trim', explode( '|', (string) $s ) ), 'strlen' ) );
}

function ew_fb_shortcode( $atts ) {
	$a    = shortcode_atts( ew_fb_defaults(), (array) $atts, 'eastwood_feedback' );
	$post = get_post();
	$w    = $a;
	unset( $w['notify_to'], $w['from'], $w['subject'] );
	foreach ( array_keys( $w ) as $k ) {
		if ( 0 === strpos( $k, 'mail_' ) ) {
			unset( $w[ $k ] );
		}
	}
	foreach ( array( 'felt', 'info', 'place' ) as $k ) {
		$w[ $k ] = ew_fb_list( $a[ $k ] );
	}
	$w['programmes'] = array_map( function ( $p ) {
		$x = explode( '::', $p, 2 );
		return array( trim( $x[0] ), trim( $x[1] ?? '' ) );
	}, ew_fb_list( $a['programmes'] ) );
	$w['rest']         = esc_url_raw( rest_url( 'eastwood/v1/feedback' ) );
	$w['interest_url'] = 0 === strpos( $a['interest_url'], 'http' ) ? $a['interest_url'] : home_url( $a['interest_url'] );
	return '<section class="ewtr ewfb" data-page="' . (int) ( $post ? $post->ID : 0 ) . '"><div class="ewfb-root"></div>'
		. '<script type="application/json" class="ewfb-words">' . wp_json_encode( $w ) . '</script></section>';
}
add_shortcode( 'eastwood_feedback', 'ew_fb_shortcode' );

add_filter( 'ew_blocks_allowed', function ( $list ) {
	return array_merge( (array) $list, array( 'eastwood_feedback' ) );
} );

add_action( 'rest_api_init', function () {
	// Send the feedback request. Admins only. Either a list of trial bookings
	// (names and the fill-in link come from each), or test addresses.
	register_rest_route( 'eastwood/v1', '/feedback/send', array(
		'methods'             => 'POST',
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
		'callback'            => function ( $req ) {
			$post = get_post( (int) $req->get_param( 'page' ) );
			$blk  = $post ? ew_acad_block_on( $post, 'eastwood_feedback' ) : null;
			if ( ! $blk ) {
				return new WP_REST_Response( array( 'message' => 'No feedback block on that page.' ), 400 );
			}
			$a    = shortcode_atts( ew_fb_defaults(), $blk['atts'], 'eastwood_feedback' );
			$test = (bool) $req->get_param( 'test' );
			$list = array();
			foreach ( (array) $req->get_param( 'bids' ) as $bid ) {
				$bid   = (int) $bid;
				$email = 'ewt_booking' === get_post_type( $bid ) ? sanitize_email( ew_acad_m( $bid, 'email' ) ) : '';
				if ( is_email( $email ) ) {
					$list[] = array( 'email' => $email, 'parent' => ew_acad_m( $bid, 'parent' ), 'player' => ew_acad_m( $bid, 'name' ), 'bid' => $bid );
				}
			}
			foreach ( (array) $req->get_param( 'to' ) as $email ) {
				$email = sanitize_email( (string) $email );
				if ( is_email( $email ) ) {
					$list[] = array( 'email' => $email );
				}
			}
			$from = ew_acad_from( array( 'from' => $a['from'] ) );
			$out  = array();
			foreach ( $list as $who ) {
				list( $html, $text ) = ew_fb_request_mail( $a, $post, $who );
				$alt = function ( $mail ) use ( $text ) {
					$mail->AltBody = $text;
				};
				add_action( 'phpmailer_init', $alt, 99 );
				$ok = wp_mail(
					$who['email'],
					( $test ? '[Test] ' : '' ) . wp_specialchars_decode( $a['mail_subject'], ENT_QUOTES ),
					$html,
					array( 'From: ' . $a['mail_name'] . ' <' . $from . '>', 'Content-Type: text/html; charset=UTF-8' )
				);
				remove_action( 'phpmailer_init', $alt, 99 );
				if ( $ok && ! empty( $who['bid'] ) ) {
					update_post_meta( (int) $who['bid'], '_ewt_fb_asked', time() );
				}
				$out[] = array( 'email' => $who['email'], 'sent' => (bool) $ok );
			}
			return array( 'ok' => 1, 'from' => $from, 'sent' => $out );
		},
	) );

	register_rest_route( 'eastwood/v1', '/feedback', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => function ( $req ) {
				nocache_headers();
				$id = ew_fb_from_key( $req->get_param( 'k' ) );
				if ( ! $id ) {
					return array( 'ok' => 0 );
				}
				$parent = ew_acad_m( $id, 'parent' );
				return array( 'ok' => 1, 'player' => ew_acad_m( $id, 'name' ), 'parent' => $parent, 'email' => ew_acad_m( $id, 'email' ) );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => function ( $req ) {
				$post = get_post( (int) $req->get_param( 'page' ) );
				$blk  = $post ? ew_acad_block_on( $post, 'eastwood_feedback' ) : null;
				if ( ! $blk ) {
					return new WP_REST_Response( array( 'message' => 'no' ), 400 );
				}
				$a     = shortcode_atts( ew_fb_defaults(), $blk['atts'], 'eastwood_feedback' );
				$t     = function ( $k, $max = 2000 ) use ( $req ) {
					return mb_substr( trim( sanitize_textarea_field( (string) $req->get_param( $k ) ) ), 0, $max );
				};
				$score = (int) $req->get_param( 'score' );
				if ( $score < 1 || $score > 5 ) {
					return new WP_REST_Response( array( 'message' => $a['need_score'] ), 400 );
				}
				$anon   = (bool) $req->get_param( 'anon' );
				$from   = ew_fb_from_key( $req->get_param( 'k' ) );
				$progs  = array();
				foreach ( (array) $req->get_param( 'progs' ) as $p ) {
					$label = mb_substr( sanitize_text_field( (string) ( $p['label'] ?? '' ) ), 0, 120 );
					$which = mb_substr( sanitize_text_field( (string) ( $p['which'] ?? '' ) ), 0, 120 );
					if ( '' !== $label ) {
						$progs[] = $label . ( '' !== $which ? ': ' . $which : '' );
					}
				}
				$f = array(
					'score'  => $score,
					'felt'   => $t( 'felt', 60 ),
					'liked'  => $t( 'liked' ),
					'better' => $t( 'better' ),
					'info'   => $t( 'info', 20 ),
					'place'  => $t( 'place', 20 ),
					'progs'  => implode( '; ', $progs ),
					'else'   => $t( 'else' ),
					'quote'  => $req->get_param( 'consent' ) ? 'yes' : 'no',
				);
				$parent = $anon ? '' : $t( 'parent', 120 );
				$player = $anon ? '' : $t( 'player', 120 );
				$email  = $anon ? '' : sanitize_email( (string) $req->get_param( 'email' ) );
				$who    = $anon ? 'Anonymous' : ( '' !== $parent ? $parent : ( '' !== $player ? $player : 'a parent' ) );
				$id     = ew_acad_booking( 'Feedback: ' . ( $anon ? 'Anonymous' : ( '' !== $player ? $player : $who ) ), array(
					'name'     => $player,
					'parent'   => $parent,
					'email'    => $email,
					'event'    => $a['event'],
					'page'     => $post->ID,
					'url'      => get_permalink( $post ),
					'lines'    => $score . '/5 · ' . $f['felt'] . ' · place: ' . $f['place'],
					'extra'    => 'Feedback',
					'total'    => 0,
					'anon'     => $anon ? 'yes' : 'no',
					'from_bid' => $anon ? '' : (string) $from,
					'feedback' => wp_json_encode( $f ),
					'status'   => 'feedback',
				) );
				if ( ! $id ) {
					return new WP_REST_Response( array( 'message' => $a['failed'] ), 500 );
				}
				$to = '' !== trim( $a['notify_to'] ) ? preg_split( '/[\s,;]+/', $a['notify_to'] ) : ( function_exists( 'ew_notify_to_route' ) ? ew_notify_to_route( 'interest' ) : array() );
				$to = array_values( array_filter( array_map( 'sanitize_email', (array) $to ), 'is_email' ) );
				if ( $to ) {
					$lines = array(
						'From: ' . $who . ( $anon ? '' : ( '' !== $player ? ' (player: ' . $player . ')' : '' ) . ( '' !== $email ? ', ' . $email : '' ) ),
						'',
						$a['q_score'] . ' ' . $score . '/5',
						str_replace( '{player}', 'the player', $a['q_felt'] ) . ' ' . $f['felt'],
						$a['q_liked'] . "\n" . $f['liked'],
						$a['q_better'] . "\n" . $f['better'],
						$a['q_info'] . ' ' . $f['info'],
						str_replace( '{player}', 'the player', $a['q_place'] ) . ' ' . $f['place'],
						$a['q_progs'] . ' ' . ( '' !== $f['progs'] ? $f['progs'] : 'none ticked' ),
						$a['q_else'] . "\n" . $f['else'],
						'Happy to be quoted on the website: ' . $f['quote'],
					);
					$sender = function_exists( 'ew_acad_from' ) ? ew_acad_from( array( 'from' => $a['from'] ) ) : 'info@eastwoodfc.com';
					wp_mail( $to, ew_acad_fill( $a['subject'], array( 'score' => $score, 'who' => $who ) ), implode( "\n\n", $lines ), array( 'From: Eastwood FC Academy <' . $sender . '>' ) );
				}
				return array( 'ok' => 1 );
			},
		),
	) );
} );

add_action( 'wp_enqueue_scripts', function () {
	$post = is_singular() ? get_post() : null;
	if ( ! $post || ! function_exists( 'ew_post_contains' ) || ! ew_post_contains( $post, 'eastwood_feedback' ) ) {
		return;
	}
	wp_enqueue_style( 'ewc-fonts', 'https://fonts.googleapis.com/css2?family=Anton&family=Instrument+Sans:wght@400;600;700&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
	wp_register_style( 'ew-feedback', false, array(), EW_FWP_VERSION );
	wp_enqueue_style( 'ew-feedback' );
	wp_add_inline_style( 'ew-feedback', ( function_exists( 'ew_trial_css' ) ? ew_trial_css() : '' ) . ew_fb_css() );
	wp_register_script( 'ew-feedback', '', array(), EW_FWP_VERSION, true );
	wp_enqueue_script( 'ew-feedback' );
	wp_add_inline_script( 'ew-feedback', ew_fb_js() );
}, 22 );

function ew_fb_css() {
	return '
.ewfb .ewtr-form{padding:40px 44px}
.ewfb .ewfb-top{display:flex;align-items:flex-end;justify-content:space-between;gap:40px;flex-wrap:wrap}
.ewfb .ewfb-steps{display:flex;gap:8px;flex:0 1 640px;min-width:280px}
.ewfb .ewfb-st{flex:1;min-width:0}
.ewfb .ewfb-st i{display:block;height:4px;border-radius:2px;background:rgba(255,255,255,.18)}
.ewfb .ewfb-st.on i{background:#CC0000}
.ewfb .ewfb-st b{display:block;margin-top:8px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#6b6b72}
.ewfb .ewfb-st.on b{color:#bdbdbd}.ewfb .ewfb-st.now b{color:#fff}
.ewfb .ewfb-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:22px 48px;margin-top:10px}
.ewfb .ewfb-full{grid-column:1/-1}
.ewfb .ewfb-ch{display:grid;gap:8px}
.ewfb .ewfb-ch button{min-height:52px;border-radius:8px;background:#111;color:#fff;border:1px solid rgba(255,255,255,.18);font:inherit;font-size:15px;font-weight:700;cursor:pointer;padding:0 10px}
.ewfb .ewfb-ch button.sel{background:#fff;color:#111;border-color:#fff}
.ewfb .ewfb-ch.big button{font-family:Anton,Impact,sans-serif;font-size:22px;font-weight:400;min-height:60px}
.ewfb .ewfb-ends{display:flex;justify-content:space-between;margin-top:6px;font-size:13px;color:#9a9aa3}
.ewfb textarea.ewtr-in{height:110px;padding:12px 14px;line-height:1.5;resize:vertical}
.ewfb .ewfb-pg{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
.ewfb .ewfb-pg>div{border:1px solid rgba(255,255,255,.18);border-radius:8px;padding:0 14px}
.ewfb .ewfb-pg label{display:flex;gap:12px;align-items:center;min-height:48px;font-size:15px;line-height:1.35;color:#fff;cursor:pointer}
.ewfb .ewfb-pg input[type=checkbox]{width:18px;height:18px;margin:0;flex-shrink:0}
.ewfb .ewfb-pg .ewtr-in{height:40px;margin:0 0 10px;font-size:15px}
.ewfb .ewfb-nav{display:flex;gap:10px;justify-content:flex-end;margin-top:26px}
.ewfb .ewfb-nav .ewtr-btn{width:320px;margin:0}
.ewfb .ewfb-back{height:56px;padding:0 22px;border:1px solid rgba(255,255,255,.35);border-radius:8px;background:transparent;color:#fff;font:inherit;font-size:16px;font-weight:700;cursor:pointer}
.ewfb .ewfb-sub{margin:-2px 0 10px;font-size:14px;color:#9a9aa3}
.ewfb .ewfb-done{text-align:left}
.ewfb .ewfb-done .ewtr-btn{display:flex;align-items:center;justify-content:center;max-width:360px;text-decoration:none;margin-top:18px}
@media (max-width:760px){.ewfb .ewtr-form{padding:26px 20px}.ewfb .ewfb-grid,.ewfb .ewfb-pg{grid-template-columns:minmax(0,1fr)}.ewfb .ewfb-steps{flex-basis:100%}.ewfb .ewfb-nav .ewtr-btn{width:auto;flex:1}}
';
}

function ew_fb_js() {
	return <<<'JS'
(function(){
 var root = document.querySelector('.ewfb'); if(!root) return;
 var W = JSON.parse(root.querySelector('.ewfb-words').textContent), page = root.getAttribute('data-page');
 var box = root.querySelector('.ewfb-root');
 var S = {step:1, score:0, felt:'', info:'', place:'', progs:{}, anon:false, liked:'', better:'', else:'', parent:'', player:'', email:'', consent:false, k:''};
 var km = location.search.match(/[?&]k=([0-9A-Za-z-]+)/); if(km) S.k = km[1];
 function esc(s){ return String(s == null ? '' : s).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
 function pl(t){ return t.replace('{player}', S.player || W.player_word); }
 function lab(t, id){ return '<label class="ewtr-lab"' + (id ? ' for="' + id + '"' : '') + '>' + esc(t) + '</label>'; }
 function choice(key, opts, big, cols){
   return '<div class="ewfb-ch' + (big ? ' big' : '') + '" data-key="' + key + '" style="grid-template-columns:repeat(' + (cols || opts.length) + ',minmax(0,1fr))">'
     + opts.map(function(o){ return '<button type="button" class="' + (String(S[key]) === String(o) ? 'sel' : '') + '" data-v="' + esc(o) + '">' + esc(o) + '</button>'; }).join('') + '</div>';
 }
 function steps(){
   return '<div class="ewfb-steps">' + [W.step1, W.step2, W.step3, W.step4].map(function(t, i){
     var n = i + 1; return '<div class="ewfb-st' + (n <= S.step ? ' on' : '') + (n === S.step ? ' now' : '') + '"><i></i><b>' + n + ' · ' + esc(t) + '</b></div>';
   }).join('') + '</div>';
 }
 function body(){
   if(S.step === 1) return '<div>' + lab(W.q_score) + choice('score', ['1','2','3','4','5'], true) + '<div class="ewfb-ends"><span>' + esc(W.low) + '</span><span>' + esc(W.high) + '</span></div></div>'
     + '<div>' + lab(pl(W.q_felt)) + choice('felt', W.felt, false, 2) + '</div>';
   if(S.step === 2) return '<div>' + lab(W.q_liked, 'fbL') + '<textarea class="ewtr-in" id="fbL">' + esc(S.liked) + '</textarea></div>'
     + '<div>' + lab(W.q_better, 'fbB') + '<textarea class="ewtr-in" id="fbB">' + esc(S.better) + '</textarea></div>'
     + '<div class="ewfb-full">' + lab(W.q_info) + choice('info', W.info) + '</div>';
   if(S.step === 3) return '<div class="ewfb-full">' + lab(pl(W.q_place)) + choice('place', W.place) + '</div>'
     + '<div class="ewfb-full">' + lab(W.q_progs) + '<p class="ewfb-sub">' + esc(W.progs_note) + '</p><div class="ewfb-pg">'
     + W.programmes.map(function(p, i){
         var on = S.progs[i] !== undefined;
         return '<div><label><input type="checkbox" data-pi="' + i + '"' + (on ? ' checked' : '') + '>' + esc(p[0]) + '</label>'
           + (p[1] ? '<input class="ewtr-in" data-pw="' + i + '" placeholder="' + esc(p[1]) + '" value="' + esc(on ? S.progs[i] : '') + '"' + (on ? '' : ' hidden') + '>' : '') + '</div>';
       }).join('') + '</div></div>';
   return '<div class="ewfb-full">' + lab(W.q_who) + choice('who', [W.named, W.anon]) + '<p class="ewfb-sub" style="margin-top:8px">' + esc(W.anon_note) + '</p></div>'
     + (S.anon ? '' : '<div>' + lab(W.parent_label, 'fbP') + '<input class="ewtr-in" id="fbP" value="' + esc(S.parent) + '"></div><div>' + lab(W.player_label, 'fbN') + '<input class="ewtr-in" id="fbN" value="' + esc(S.player) + '"></div>'
       + '<div>' + lab(W.email_label, 'fbE') + '<input class="ewtr-in" id="fbE" type="email" value="' + esc(S.email) + '"></div><div></div>')
     + '<div class="ewfb-full">' + lab(W.q_else, 'fbX') + '<textarea class="ewtr-in" id="fbX">' + esc(S.else) + '</textarea>'
     + '<label class="ewtr-tick"><input type="checkbox" id="fbC"' + (S.consent ? ' checked' : '') + '>' + esc(W.consent) + '</label></div>';
 }
 function keep(){
   function v(id){ var e = box.querySelector('#' + id); return e ? e.value.trim() : null; }
   var x;
   if((x = v('fbL')) !== null) S.liked = x; if((x = v('fbB')) !== null) S.better = x; if((x = v('fbX')) !== null) S.else = x;
   if((x = v('fbP')) !== null) S.parent = x; if((x = v('fbN')) !== null) S.player = x; if((x = v('fbE')) !== null) S.email = x;
   var c = box.querySelector('#fbC'); if(c) S.consent = c.checked;
   Array.prototype.forEach.call(box.querySelectorAll('[data-pw]'), function(e){ var i = +e.getAttribute('data-pw'); if(S.progs[i] !== undefined) S.progs[i] = e.value.trim(); });
 }
 function draw(){
   S.who = S.anon ? W.anon : W.named;
   box.innerHTML = '<div class="ewtr-form"><div class="ewfb-top"><div><p class="ewtr-head">' + esc(W.title) + '</p><p class="ewtr-note" style="margin-bottom:0">' + esc(W.note) + '</p></div>' + steps() + '</div>'
     + '<div class="ewfb-grid">' + body() + '</div><p class="ewtr-err" role="alert"></p>'
     + '<div class="ewfb-nav">' + (S.step > 1 ? '<button type="button" class="ewfb-back">' + esc(W.back) + '</button>' : '') + '<button type="button" class="ewtr-btn ewfb-go">' + esc(S.step < 4 ? W.next : W.send) + '</button></div></div>';
   Array.prototype.forEach.call(box.querySelectorAll('.ewfb-ch'), function(g){
     g.addEventListener('click', function(e){
       var b = e.target.closest('button'); if(!b) return; keep();
       var key = g.getAttribute('data-key'), val = b.getAttribute('data-v');
       if(key === 'who'){ S.anon = val === W.anon; } else { S[key] = key === 'score' ? +val : val; }
       draw();
     });
   });
   Array.prototype.forEach.call(box.querySelectorAll('[data-pi]'), function(c){
     c.addEventListener('change', function(){
       keep(); var i = +c.getAttribute('data-pi');
       if(c.checked) S.progs[i] = ''; else delete S.progs[i];
       var w = box.querySelector('[data-pw="' + i + '"]'); if(w){ w.hidden = !c.checked; if(c.checked) w.focus(); }
     });
   });
   var back = box.querySelector('.ewfb-back'); if(back) back.addEventListener('click', function(){ keep(); S.step--; draw(); });
   box.querySelector('.ewfb-go').addEventListener('click', go);
 }
 function go(){
   keep(); var err = box.querySelector('.ewtr-err'); err.textContent = '';
   if(S.step === 1 && !S.score){ err.textContent = W.need_score; return; }
   if(S.step < 4){ S.step++; draw(); if(window.ewTrack) ewTrack('FeedbackStep', {step: S.step}); return; }
   var btn = box.querySelector('.ewfb-go'); btn.disabled = true;
   var progs = Object.keys(S.progs).map(function(i){ return {label: W.programmes[i][0], which: S.progs[i]}; });
   fetch(W.rest, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({
     page: page, k: S.k, score: S.score, felt: S.felt, liked: S.liked, better: S.better, info: S.info, place: S.place,
     progs: progs, anon: S.anon ? 1 : 0, parent: S.parent, player: S.player, email: S.email, else: S.else, consent: S.consent ? 1 : 0
   })}).then(function(r){ return r.json().then(function(j){ return {ok: r.ok, j: j}; }); }).then(function(x){
     btn.disabled = false;
     if(!x.ok){ err.textContent = (x.j && x.j.message) || W.failed; return; }
     var want = S.place && S.place !== W.place[W.place.length - 1];
     box.innerHTML = '<div class="ewtr-form ewfb-done"><p class="ewtr-done-t">' + esc(W.done_title) + '</p><p class="ewtr-done-n">' + esc(W.done_note) + '</p>'
       + (want ? '<p class="ewtr-done-n" style="margin-top:18px">' + esc(pl(W.done_place)) + '</p><a class="ewtr-btn" href="' + esc(W.interest_url) + '">' + esc(W.done_btn) + '</a>' : '') + '</div>';
     if(window.ewTrack) ewTrack('Lead', {what: 'Trial day feedback'});
   }).catch(function(){ btn.disabled = false; err.textContent = W.failed; });
 }
 if(S.k){
   fetch(W.rest + '?k=' + encodeURIComponent(S.k) + '&t=' + Date.now()).then(function(r){ return r.json(); }).then(function(j){
     if(j && j.ok){ S.player = j.player || ''; S.parent = j.parent || ''; S.email = j.email || ''; }
     draw();
   }).catch(draw);
 } else { draw(); }
})();
JS;
}
