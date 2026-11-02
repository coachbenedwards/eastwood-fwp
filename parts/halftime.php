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
		// Step one: who is filling it in, and their email.
		'form_title'  => 'Put your child forward',
		'name_label'  => 'Your full name',
		'email_label' => 'Your email',
		'send'        => 'Count us in',
		// Step two: the child or children, the parent, the mobile.
		'next_title'  => 'Nearly there',
		'who_q'       => 'Is {name} the child taking part, or you?',
		'who_child'   => 'That’s my child',
		'who_parent'  => 'That’s me, the parent',
		'child_label' => 'Child {n} · name',
		'age_label'   => 'Age',
		'more'        => 'Add another child',
		'remove'      => 'Remove',
		'parent_label' => 'Your full name',
		'phone_label' => 'Your mobile',
		'phone_note'  => 'For a text on the day if anything changes.',
		'consent'     => 'Tell me about the next one too',
		'send2'       => 'Next',
		// Step three: bring a friend. A reward box shows only when it is filled in.
		'invite_title' => 'Bring a friend',
		'invite_note' => 'Invite a family friend or another child to the half time show. When they’re in, you both get a thank you from the club.',
		'reward_money' => '',
		'reward_money_fmt' => '£{x}',
		'reward_money_note' => 'to spend at the ground',
		'reward_points' => '',
		'reward_points_fmt' => '{x} pts',
		'reward_points_note' => 'fan points',
		'reward_freebie' => '',
		'reward_freebie_note' => 'for the kids',
		'friend_label' => 'Their name',
		'contact_label' => 'Their mobile or email',
		'invite_more' => 'Invite someone else',
		'invite_send' => 'Send the invite',
		'invite_skip' => 'Skip for now',
		'invite_max'  => '5',
		'invite_subject' => '{inviter} has invited you to the Eastwood FC half time show',
		'invite_body' => "Hi {friend},\n\n{inviter} has put their name down for the half time show at {event}, and thought you would want to come too.\n\nYou can put your child forward here:\n{link}\n\nThank you,\nEastwood FC",
		// Errors.
		'need_child'  => 'We need at least one child, with a name and an age.',
		'need_name'   => 'We need your full name.',
		'need_email'  => 'We need an email to get back to you on.',
		'need_phone'  => 'We need a mobile number for the day.',
		'need_friend' => 'We need their name, and a mobile number or an email.',
		'failed'      => 'Something went wrong. Please try again.',
		'done_title'  => "You're in",
		'done_note'   => "We'll be in touch before the game with where to be and when.",
		// The last page.
		'app_title'   => 'Your place is in the app',
		'app_title_many' => 'Your places are in the app',
		'app_note_one' => '{children} is down for {day}.',
		'app_note_many' => '{children} are down for {day}.',
		'app_invited_one' => '{friends}’s invite is on its way.',
		'app_invited_many' => 'Invites to {friends} are on their way.',
		'and'         => 'and',
		'ticket_title' => 'Half time show',
		'ticket_time' => '3pm',
		'track'       => 'Half-time show',
		'max'         => '6',
		'crm_hook'    => '',
	);
}

/** The thank-you boxes on step three; nothing at all when none is filled in. */
function ew_halftime_rewards( $a ) {
	$box = array();
	$m   = trim( ltrim( trim( (string) $a['reward_money'] ), '£' ) );
	if ( '' !== $m ) {
		$box[] = array( str_replace( '{x}', $m, (string) $a['reward_money_fmt'] ), $a['reward_money_note'] );
	}
	$pt = trim( (string) $a['reward_points'] );
	if ( '' !== $pt ) {
		$box[] = array( str_replace( '{x}', $pt, (string) $a['reward_points_fmt'] ), $a['reward_points_note'] );
	}
	$fr = trim( (string) $a['reward_freebie'] );
	if ( '' !== $fr ) {
		$box[] = array( $fr, $a['reward_freebie_note'] );
	}
	if ( ! $box ) {
		return '';
	}
	$h = '<div class="ewh-rewards">';
	foreach ( $box as $b ) {
		$h .= '<div class="ewh-reward"><p class="ewh-reward-v">' . esc_html( $b[0] ) . '</p><p class="ewh-reward-n">' . esc_html( (string) $b[1] ) . '</p></div>';
	}
	return $h . '</div>';
}

function ew_halftime_shortcode( $atts ) {
	$a      = shortcode_atts( ew_halftime_defaults(), (array) $atts, 'eastwood_halftime' );
	$post   = get_post();
	$max    = max( 1, min( 12, (int) $a['max'] ) );
	$ticket = ew_halftime_ticket( $a );
	$plus   = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>';

	$words = array(
		'need_child'  => $a['need_child'],
		'need_name'   => $a['need_name'],
		'need_email'  => $a['need_email'],
		'need_phone'  => $a['need_phone'],
		'need_friend' => $a['need_friend'],
		'failed'      => $a['failed'],
		'child'       => $a['child_label'],
		'age'         => $a['age_label'],
		'remove'      => $a['remove'],
		'who_q'       => $a['who_q'],
		'friend'      => $a['friend_label'],
		'contact'     => $a['contact_label'],
		'app_title'   => $a['app_title'],
		'app_title_many' => $a['app_title_many'],
		'app_note_one' => $a['app_note_one'],
		'app_note_many' => $a['app_note_many'],
		'app_invited_one' => $a['app_invited_one'],
		'app_invited_many' => $a['app_invited_many'],
		'and'         => $a['and'],
		'day'         => $ticket['day'],
		'max'         => $max,
		'invite_max'  => max( 1, min( 10, (int) $a['invite_max'] ) ),
		'ref'         => 1,
		'rest'        => esc_url_raw( rest_url( 'eastwood/v1/halftime' ) ),
		'invite'      => esc_url_raw( rest_url( 'eastwood/v1/halftime/invite' ) ),
	) + ( function_exists( 'ew_optin_words' ) ? ew_optin_words( 'halftime', $a ) : array() );

	$h  = '<section class="ewh" data-page="' . (int) ( $post ? $post->ID : 0 ) . '">';
	$h .= '<form class="ewh-form" data-step="1" novalidate>';
	// Three steps; the bar fills as they go.
	$h .= '<div class="ewh-bar" aria-hidden="true"><span></span><span></span><span></span></div>';
	// Step one: their name and email, saved the moment the button is pressed.
	$h .= ew_optin_step_one( 'ewh', $a, array( 'name' => $a['name_label'] ) );
	// Step two: the child or children, then the mobile, each box saved as they leave it.
	$h .= '<div class="ewh-two" data-ewo-two hidden>';
	$h .= '<p class="ewh-head">' . esc_html( $a['next_title'] ) . '</p>';
	$h .= '<div class="ewh-who" data-ewh-who><p class="ewh-q" data-ewh-q></p>';
	$h .= '<div class="ewh-pick" role="group">';
	$h .= '<button type="button" class="ewh-opt" data-who="child" aria-pressed="false">' . esc_html( $a['who_child'] ) . '</button>';
	$h .= '<button type="button" class="ewh-opt" data-who="parent" aria-pressed="true">' . esc_html( $a['who_parent'] ) . '</button>';
	$h .= '</div></div>';
	$h .= '<div class="ewh-kids"></div>';
	$h .= '<button type="button" class="ewh-more" data-ewh-more>' . $plus . esc_html( $a['more'] ) . '</button>';
	$h .= '<div class="ewh-parent" hidden><label class="ewh-lab" for="ewhName">' . esc_html( $a['parent_label'] ) . '</label><input class="ewh-in" id="ewhName" type="text" autocomplete="name" data-ewo-save="name"></div>';
	$h .= '<label class="ewh-lab" for="ewhPhone">' . esc_html( $a['phone_label'] ) . '</label><input class="ewh-in" id="ewhPhone" type="tel" autocomplete="tel" required data-ewo-save="phone">';
	$h .= '<p class="ewh-note">' . esc_html( $a['phone_note'] ) . '</p>';
	$h .= '<label class="ewh-tick"><input type="checkbox" id="ewhConsent" checked><span>' . esc_html( $a['consent'] ) . '</span></label>';
	$h .= '<p class="ewh-err" role="alert"></p>';
	$h .= '<button type="submit" class="ewh-btn">' . esc_html( $a['send2'] ) . '</button>';
	$h .= '</div>';
	// Step three: bring a friend.
	$h .= '<div class="ewh-three" data-ewh-three hidden>';
	$h .= '<p class="ewh-head">' . esc_html( $a['invite_title'] ) . '</p>';
	$h .= '<p class="ewh-lead">' . esc_html( $a['invite_note'] ) . '</p>';
	$h .= ew_halftime_rewards( $a );
	$h .= '<div class="ewh-friends"></div>';
	$h .= '<button type="button" class="ewh-more" data-ewh-fmore>' . $plus . esc_html( $a['invite_more'] ) . '</button>';
	$h .= '<p class="ewh-err" role="alert" data-ewh-err3></p>';
	$h .= '<button type="button" class="ewh-btn" data-ewh-send>' . esc_html( $a['invite_send'] ) . '</button>';
	$h .= '<button type="button" class="ewh-skip" data-ewh-skip>' . esc_html( $a['invite_skip'] ) . '</button>';
	$h .= '</div>';
	$h .= '</form>';
	// The last page: their place, in the app.
	$h .= ew_inapp_panel( $a['app_title'], $ticket, '<p class="ewh-after" data-ewh-after></p>' );
	$h .= '<script type="application/json" class="ewh-words">' . wp_json_encode( $words ) . '</script>';
	return $h . '</section>';
}
add_shortcode( 'eastwood_halftime', 'ew_halftime_shortcode' );

/** The place that slides out of the app on the last page, read from the event line. */
function ew_halftime_ticket( $a ) {
	$parts = array_map( 'trim', explode( ',', (string) $a['event'], 2 ) );
	$when  = $parts[1] ?? '';
	$ts    = '' !== $when ? strtotime( $when ) : 0;
	return array(
		'title' => (string) $a['ticket_title'],
		'sub'   => $parts[0],
		'day'   => $ts ? date_i18n( 'l', $ts ) : '',
		'date'  => $ts ? strtoupper( date_i18n( 'j F Y', $ts ) ) : $when,
		'time'  => strtoupper( (string) $a['ticket_time'] ),
		'stub'  => $ts ? date_i18n( 'jS', $ts ) : '',
		'stub2' => $ts ? strtoupper( date_i18n( 'F Y', $ts ) ) : '',
	);
}

add_filter( 'ew_optin_forms', function ( $forms ) {
	$forms['halftime'] = 'ew_halftime_block_on';
	return $forms;
} );

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
			// Step one already saved the email on a started booking; carry that on.
			$bid   = function_exists( 'ew_optin_claim' ) ? ew_optin_claim( $req ) : 0;
			$email = $bid ? (string) get_post_meta( $bid, '_ewt_email', true ) : sanitize_email( (string) $req->get_param( 'email' ) );
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
			return function_exists( 'ew_optin_finished' ) ? ew_optin_finished( $id ) : array( 'ok' => 1 );
		},
	) );

	/* Step three: bring a friend. Carries the finished booking's claim. */
	register_rest_route( 'eastwood/v1', '/halftime/invite', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => 'ew_halftime_invite',
	) );
} );

/**
 * Bring a friend: {bid, key, invites: [{name, contact}]}. The booking must be
 * a finished half-time sign-up whose key matches. Each invite is kept on the
 * booking (_ewt_invites) with its own token; an email address gets a short
 * email with the page link and ?ref=token, a mobile number is kept for now.
 */
function ew_halftime_invite( $req ) {
	$bid = function_exists( 'ew_optin_owner' ) ? ew_optin_owner( $req ) : 0;
	if ( ! $bid || 'halftime' !== get_post_meta( $bid, '_ewt_status', true ) ) {
		return new WP_REST_Response( array( 'message' => 'no booking' ), 403 );
	}
	$post = get_post( (int) get_post_meta( $bid, '_ewt_page', true ) );
	$a    = $post ? ew_halftime_block_on( $post ) : null;
	if ( ! $a ) {
		$a = ew_halftime_defaults();
	}
	$have = get_post_meta( $bid, '_ewt_invites', true );
	$have = is_array( $have ) ? $have : array();
	$room = max( 0, max( 1, min( 10, (int) $a['invite_max'] ) ) - count( $have ) );
	$new  = array();
	foreach ( array_slice( (array) $req->get_param( 'invites' ), 0, 10 ) as $in ) {
		$nm = trim( sanitize_text_field( (string) ( $in['name'] ?? '' ) ) );
		$ct = trim( sanitize_text_field( (string) ( $in['contact'] ?? '' ) ) );
		if ( '' === $nm && '' === $ct ) {
			continue;
		}
		if ( '' !== $nm && function_exists( 'ew_person_name' ) ) {
			$nm = ew_person_name( $nm );
		}
		$type = '';
		if ( is_email( $ct ) ) {
			$type = 'email';
			$ct   = sanitize_email( $ct );
		} elseif ( preg_match( '/^[0-9 +().-]+$/', $ct ) && strlen( preg_replace( '/\D/', '', $ct ) ) >= 10 ) {
			$type = 'phone';
		}
		if ( '' === $nm || '' === $type ) {
			return new WP_REST_Response( array( 'message' => (string) $a['need_friend'] ), 400 );
		}
		$new[] = array( 'name' => $nm, 'contact' => $ct, 'type' => $type );
	}
	if ( ! $new ) {
		return new WP_REST_Response( array( 'message' => (string) $a['need_friend'] ), 400 );
	}
	$new     = array_slice( $new, 0, $room );
	$inviter = (string) get_post_meta( $bid, '_ewt_name', true );
	$page    = $post ? get_permalink( $post ) : home_url( '/halftimeshow/' );
	$emailed = 0;
	foreach ( $new as $n ) {
		$token = strtolower( wp_generate_password( 20, false, false ) );
		$sent  = 0;
		if ( 'email' === $n['type'] ) {
			$sp   = strpos( $n['name'], ' ' );
			$vars = array(
				'{friend}'  => false === $sp ? $n['name'] : substr( $n['name'], 0, $sp ),
				'{inviter}' => $inviter,
				'{event}'   => (string) $a['event'],
				'{link}'    => add_query_arg( 'ref', $token, $page ),
			);
			$ok = wp_mail(
				$n['contact'],
				strtr( (string) $a['invite_subject'], $vars ),
				strtr( (string) $a['invite_body'], $vars ),
				array( 'From: Eastwood FC <info@eastwoodfc.com>', 'Reply-To: info@eastwoodfc.com' )
			);
			if ( $ok ) {
				$sent = time();
				$emailed++;
			}
		}
		$have[] = array(
			'name'        => $n['name'],
			'contact'     => $n['contact'],
			'type'        => $n['type'],
			'token'       => $token,
			'time'        => time(),
			'sent'        => $sent,
			'accepted'    => 0,
			'accepted_by' => 0,
		);
		add_post_meta( $bid, '_ewt_invite_token', $token );
	}
	update_post_meta( $bid, '_ewt_invites', $have );
	if ( function_exists( 'ew_tix_crm' ) ) {
		ew_tix_crm( (string) get_post_meta( $bid, '_ewt_hook', true ), $bid, 'invite' );
	}
	return array( 'ok' => 1, 'saved' => count( $new ), 'emailed' => $emailed );
}

/*
 * Somebody signing up from an invite link: step one carried ?ref= onto
 * their booking (_ewt_ref). Mark that invite on the inviter booking as
 * accepted, and nothing more: what the thank you is gets decided elsewhere.
 */
add_action( 'ew_optin_ref', function ( $bid, $ref ) {
	$from = get_posts( array(
		'post_type'   => 'ewt_booking',
		'post_status' => 'any',
		'numberposts' => 1,
		'fields'      => 'ids',
		'meta_key'    => '_ewt_invite_token', // phpcs:ignore WordPress.DB.SlowDBQuery
		'meta_value'  => $ref, // phpcs:ignore WordPress.DB.SlowDBQuery
	) );
	if ( ! $from || (int) $from[0] === (int) $bid ) {
		return;
	}
	$list = get_post_meta( (int) $from[0], '_ewt_invites', true );
	if ( ! is_array( $list ) ) {
		return;
	}
	foreach ( $list as $i => $e ) {
		if ( ( $e['token'] ?? '' ) === $ref && empty( $e['accepted'] ) ) {
			$list[ $i ]['accepted']    = time();
			$list[ $i ]['accepted_by'] = (int) $bid;
		}
	}
	update_post_meta( (int) $from[0], '_ewt_invites', $list );
}, 10, 2 );

function ew_halftime_css() {
	return '
.ewh{font-family:"Instrument Sans",system-ui,sans-serif;color:#101010;max-width:640px;margin:0 auto}
.ewh *{box-sizing:border-box}
.ewh [hidden]{display:none!important}
.ewh-form{background:#000;color:#fff;border-radius:14px;padding:36px 40px 40px}
.ewh .ewh-head{margin:0 0 18px;font-family:Anton,Impact,sans-serif;font-size:34px;line-height:1;text-transform:uppercase;color:#fff}
.ewh-bar{display:flex;gap:6px;margin:0 0 18px}
.ewh-bar span{flex:1;height:4px;border-radius:2px;background:rgba(255,255,255,.22)}
.ewh-form[data-step="1"] .ewh-bar span:nth-child(1),.ewh-form[data-step="2"] .ewh-bar span:nth-child(-n+2),.ewh-form[data-step="3"] .ewh-bar span{background:#fff}
.ewh-kid{display:grid;grid-template-columns:minmax(0,1fr) 96px;gap:10px;align-items:end;margin-bottom:10px}
.ewh-kid .ewh-fx{grid-column:1/-1;justify-self:end;margin:-4px 0 0}
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
.ewh .ewh-note{margin:6px 0 4px;font-size:14px;color:rgba(255,255,255,.7)}
.ewh-two .ewh-head,.ewh-three .ewh-head{margin-bottom:8px}
.ewh .ewh-q{margin:0 0 10px;font-size:16px;line-height:1.4;color:#fff}
.ewh .ewh-q b{font-weight:700}
.ewh-pick{display:flex;gap:8px;margin:0 0 18px}
.ewh .ewh-opt{flex:1;min-width:0;min-height:48px;border:1px solid rgba(255,255,255,.25);border-radius:6px;background:transparent;color:#fff;font:inherit;font-size:15px;font-weight:600;line-height:1.2;padding:6px 10px;cursor:pointer}
.ewh .ewh-opt[aria-pressed="true"]{background:#fff;border-color:#fff;color:#0b0b0b}
.ewh .ewh-opt:focus-visible{outline:2px solid #fff;outline-offset:2px}
.ewh .ewh-lead{margin:0 0 6px;font-size:15px;line-height:1.45;color:#c9c9cf}
.ewh-rewards{display:flex;gap:8px;margin:12px 0 4px}
.ewh-reward{flex:1;min-width:0;border:1px solid rgba(255,255,255,.18);border-radius:8px;padding:12px 8px;text-align:center}
.ewh .ewh-reward-v{margin:0;font-family:Anton,Impact,sans-serif;font-size:22px;line-height:1.1;color:#fff;overflow-wrap:anywhere}
.ewh .ewh-reward-n{margin:2px 0 0;font-size:12px;line-height:1.3;color:#9a9aa3}
.ewh-friend+.ewh-friend{margin-top:14px;padding-top:2px;border-top:1px solid rgba(255,255,255,.12)}
.ewh .ewh-fx{display:block;margin:6px 0 0 auto;border:0;background:none;color:#9a9aa3;font:inherit;font-size:14px;text-decoration:underline;cursor:pointer;padding:2px 0}
.ewh .ewh-skip{display:block;margin:12px auto 0;border:0;background:none;color:#9a9aa3;font:inherit;font-size:15px;text-decoration:underline;cursor:pointer;padding:4px 8px}
.ewh .ewh-after{margin:14px 0 0;font-size:14px;line-height:1.4;color:#9a9aa3}
.ewh-form,.ewh .ewi{scroll-margin-top:100px}
@media (max-width:640px){.ewh-form{padding:28px 20px 30px}.ewh-kid{grid-template-columns:minmax(0,1fr) 80px}.ewh .ewh-opt{font-size:14px;padding:6px 8px}.ewh-reward{padding:10px 6px}}
';
}

function ew_halftime_js() {
	return <<<'JS'
(function(){
 var root = document.querySelector('.ewh'); if(!root) return;
 var wordsEl = root.querySelector('.ewh-words'); if(!wordsEl) return;
 var W = JSON.parse(wordsEl.textContent), page = root.getAttribute('data-page');
 var form = root.querySelector('.ewh-form'), two = root.querySelector('[data-ewo-two]'), three = root.querySelector('[data-ewh-three]');
 var kids = two.querySelector('.ewh-kids'), more = two.querySelector('[data-ewh-more]'), err = two.querySelector('.ewh-err');
 var who = two.querySelector('[data-ewh-who]'), q = two.querySelector('[data-ewh-q]'), opts = two.querySelectorAll('.ewh-opt');
 var parentBox = two.querySelector('.ewh-parent'), parentIn = root.querySelector('#ewhName');
 var friends = three.querySelector('.ewh-friends'), fmore = three.querySelector('[data-ewh-fmore]'), err3 = three.querySelector('[data-ewh-err3]');
 var sendBtn = three.querySelector('[data-ewh-send]'), skipBtn = three.querySelector('[data-ewh-skip]');
 var mode = 'parent', claim = '', kidNames = [];
 function esc(s){ return String(s).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
 function step(n){ form.setAttribute('data-step', String(n)); }
 function first(s){ return String(s).trim().split(/\s+/)[0] || ''; }
 function join(list){
   if(list.length < 2) return list.join('');
   return list.slice(0, -1).join(', ') + ' ' + W.and + ' ' + list[list.length - 1];
 }
 /* Step two: the children. */
 var n = 0;
 function relabel(){
   kids.querySelectorAll('.ewh-kid').forEach(function(r, i){ r.querySelector('.ewh-lab').textContent = String(W.child).replace('{n}', i + 1); });
 }
 function addKid(){
   if(kids.children.length >= W.max) return;
   n++;
   var row = document.createElement('div'); row.className = 'ewh-kid';
   row.innerHTML = '<div><label class="ewh-lab" for="ewhKid' + n + '"></label><input class="ewh-in" id="ewhKid' + n + '" type="text" autocomplete="off"></div>'
     + '<div><label class="ewh-lab" for="ewhAge' + n + '">' + esc(W.age) + '</label><input class="ewh-in" id="ewhAge' + n + '" type="number" min="1" max="17" inputmode="numeric"></div>'
     + (kids.children.length ? '<button type="button" class="ewh-fx">' + esc(W.remove) + '</button>' : '');
   var x = row.querySelector('.ewh-fx');
   if(x) x.addEventListener('click', function(){ row.remove(); relabel(); more.hidden = kids.children.length >= W.max; });
   kids.appendChild(row); relabel(); more.hidden = kids.children.length >= W.max;
 }
 addKid();
 more.addEventListener('click', addKid);
 function firstKid(){ return kids.querySelector('.ewh-kid input[type=text]'); }
 function setMode(m, O){
   var nm = (O && O.name) || '';
   var k = firstKid();
   if(m === 'child' && k && !k.value.trim()) k.value = nm;
   if(m === 'parent' && k && nm && k.value.trim() === nm) k.value = '';
   mode = m;
   opts.forEach(function(b){ b.setAttribute('aria-pressed', b.getAttribute('data-who') === m ? 'true' : 'false'); });
   parentBox.hidden = (m !== 'child') && !!nm;
 }
 /* Step one, shared: name and email, saved straight away. */
 var O;
 O = window.ewOptin ? ewOptin(root, W, {open: function(o){
   step(2);
   var nm = o.name || '';
   who.hidden = !nm;
   q.innerHTML = esc(W.who_q).replace('{name}', '<b>' + esc(nm) + '</b>');
   setMode(mode, o);
 }}) : {bid: 0, key: '', email: '', name: ''};
 opts.forEach(function(b){ b.addEventListener('click', function(){ setMode(b.getAttribute('data-who'), O); }); });
 /* Step three: bring a friend. */
 var f = 0;
 function addFriend(){
   if(friends.children.length >= W.invite_max) return;
   f++;
   var row = document.createElement('div'); row.className = 'ewh-friend';
   row.innerHTML = '<label class="ewh-lab" for="ewhFn' + f + '">' + esc(W.friend) + '</label><input class="ewh-in" id="ewhFn' + f + '" type="text" autocomplete="off">'
     + '<label class="ewh-lab" for="ewhFc' + f + '">' + esc(W.contact) + '</label><input class="ewh-in" id="ewhFc' + f + '" type="text" autocomplete="off" inputmode="email">'
     + (friends.children.length ? '<button type="button" class="ewh-fx">' + esc(W.remove) + '</button>' : '');
   var x = row.querySelector('.ewh-fx');
   if(x) x.addEventListener('click', function(){ row.remove(); fmore.hidden = friends.children.length >= W.invite_max; });
   friends.appendChild(row); fmore.hidden = friends.children.length >= W.invite_max;
 }
 addFriend();
 fmore.addEventListener('click', addFriend);
 friends.addEventListener('input', function(){ err3.textContent = ''; });
 function finish(invited){
   form.hidden = true;
   var p = root.querySelector('[data-ewi]');
   if(p){
     var h = p.querySelector('.ewi-h'); if(h) h.textContent = kidNames.length > 1 ? W.app_title_many : W.app_title;
     var a = p.querySelector('[data-ewh-after]');
     if(a){
       var line = String(kidNames.length > 1 ? W.app_note_many : W.app_note_one);
       line = kidNames.length && W.day ? line.replace('{children}', join(kidNames)).replace('{day}', W.day) : '';
       var inv = invited.length ? String(invited.length > 1 ? W.app_invited_many : W.app_invited_one).replace('{friends}', join(invited)) : '';
       a.textContent = [line, inv].filter(Boolean).join(' ');
       a.hidden = !a.textContent;
     }
   }
   if(window.ewInapp) ewInapp(root, claim);
 }
 function sendInvites(){
   err3.textContent = '';
   var list = [], bad = false;
   friends.querySelectorAll('.ewh-friend').forEach(function(r){
     var i = r.querySelectorAll('input'), nm = i[0].value.trim(), ct = i[1].value.trim();
     if(!nm && !ct) return;
     var ok = /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(ct) || (/^[0-9 +().-]+$/.test(ct) && ct.replace(/\D/g, '').length >= 10);
     if(!nm || !ok){ bad = true; return; }
     list.push({name: nm, contact: ct});
   });
   if(bad || !list.length){ err3.textContent = W.need_friend; return; }
   var dot = claim.indexOf('.');
   sendBtn.disabled = true;
   fetch(W.invite, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({bid: claim.slice(0, dot), key: claim.slice(dot + 1), invites: list})})
   .then(function(r){ return r.json().then(function(j){ return {ok: r.ok, j: j}; }); })
   .then(function(x){
     sendBtn.disabled = false;
     if(!x.ok){ err3.textContent = (x.j && x.j.message) || W.failed; return; }
     finish(list.map(function(v){ return first(v.name); }));
   })
   .catch(function(){ sendBtn.disabled = false; err3.textContent = W.failed; });
 }
 sendBtn.addEventListener('click', sendInvites);
 skipBtn.addEventListener('click', function(){ finish([]); });
 /* Step two sends; the booking is finished there. */
 form.addEventListener('submit', function(e){
   e.preventDefault();
   if(form.getAttribute('data-step') === '3'){ sendInvites(); return; }
   err.textContent = '';
   var children = [];
   kids.querySelectorAll('.ewh-kid').forEach(function(r){
     var i = r.querySelectorAll('input'); var name = i[0].value.trim(), age = parseInt(i[1].value, 10);
     if(name && age > 0) children.push({name: name, age: age});
   });
   var name = (mode === 'child' || !O.name) ? parentIn.value.trim() : O.name;
   var phone = root.querySelector('#ewhPhone').value.trim();
   if(!children.length){ err.textContent = W.need_child; return; }
   if(!name){ err.textContent = W.need_name; return; }
   if(phone.replace(/\D/g,'').length < 10){ err.textContent = W.need_phone; return; }
   var btn = form.querySelector('.ewh-btn[type=submit]'); btn.disabled = true;
   fetch(W.rest, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({page: page, bid: O.bid, key: O.key, email: O.email, children: children, name: name, phone: phone, consent: root.querySelector('#ewhConsent').checked ? 1 : 0})})
   .then(function(r){ return r.json().then(function(j){ return {ok: r.ok, j: j}; }); })
   .then(function(x){
     if(!x.ok){ err.textContent = (x.j && x.j.message) || W.failed; btn.disabled = false; return; }
     if(O.done) O.done();
     claim = x.j.claim || '';
     kidNames = children.map(function(c){ return first(c.name); });
     if(!claim){ finish([]); return; }
     two.hidden = true; three.hidden = false; step(3);
     try{ form.scrollIntoView({behavior:'smooth', block:'start'}); }catch(e2){}
     var fi = friends.querySelector('input'); if(fi){ try{ fi.focus({preventScroll: true}); }catch(e3){} }
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
	wp_add_inline_style( 'ew-halftime', ew_halftime_css() . ( function_exists( 'ew_inapp_css' ) ? ew_inapp_css() : '' ) );
	wp_register_script( 'ew-halftime', '', array(), EW_FWP_VERSION, true );
	wp_enqueue_script( 'ew-halftime' );
	wp_add_inline_script( 'ew-halftime', ( function_exists( 'ew_optin_js' ) ? ew_optin_js() . ew_inapp_js() : '' ) . ew_halftime_js() );
}, 22 );
