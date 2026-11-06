<?php
/**
 * Join the team: the match-day hospitality application.
 *
 * The same card and the same two-step machine as the half-time show
 * (optin.php): the email on its own first, saved as a started Booking and
 * sent to the CRM the moment Next is pressed. Then the rest -- full name,
 * mobile, the role, volunteer or paid, and a short video -- and a thank
 * you that points them at the app.
 *
 *   [eastwood_jobs]
 *
 * The video never passes through WordPress (they run to hundreds of MB).
 * The browser asks this site for a signed upload address on the fan app
 * Supabase project, sends the file straight there into the private bucket
 * EW_JOBS_BUCKET, and tells this site when it has landed. The service key
 * is the one the School runs screen already holds (ew_runs_key()).
 *
 * The finished application is a Booking with status "application". The
 * notify part emails it (route "application", jobs@ by default) with a
 * link to an admin address that signs a fresh viewing link each time it
 * is opened, so the link in the email never runs out.
 *
 * Every word the block shows is a field on the block. The heading, the line
 * under it and the hero photo are the page widgets, not this block.
 *
 * @package eastwood-fwp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EW_JOBS_BUCKET = 'job-videos';

/** The block fields and what they say until someone changes them. */
function ew_jobs_defaults() {
	return array(
		'event'        => 'Join the team',
		// Step one: the email, on its own.
		'form_title'   => 'Apply to join',
		'email_label'  => 'Your email',
		'send'         => 'Next',
		// Step two: about you, and the video.
		'next_title'   => 'About you',
		'name_label'   => 'Your full name',
		'phone_label'  => 'Your mobile',
		'role_label'   => 'The role',
		'roles'        => 'Hospitality lead|Match-day helper|Eastwood Flex runner (ticket and flyer drops)',
		'pay_label'    => 'Volunteer or paid',
		'pays'         => 'Volunteer|Paid|Either',
		'video_label'  => 'Your 2–3 minute video',
		'video_prompt' => '[Video prompt — your wording]',
		'record'       => 'Record now',
		'upload'       => 'Upload a video',
		'rec_start'    => 'Start recording',
		'rec_stop'     => 'Stop',
		'rec_cancel'   => 'Cancel',
		'uploading'    => 'Uploading {p}%',
		'uploaded'     => 'Video uploaded',
		'change'       => 'Choose a different video',
		'retry'        => 'Try again',
		'waiting'      => 'Finishing your video upload…',
		'send2'        => 'Send my application',
		// Step three: thank you, and the app.
		'done_title'   => 'Thank you',
		'done_note'    => "We've got your application and your video.",
		'next_note'    => '[What happens next — your wording]',
		'app_note'     => 'Download the Eastwood FC app to keep up with the club.',
		// Errors.
		'need_email'   => 'We need an email to get back to you on.',
		'need_name'    => 'We need your full name.',
		'need_phone'   => 'We need a mobile number.',
		'need_role'    => 'Pick the role you want.',
		'need_pay'     => 'Pick volunteer, paid or either.',
		'need_video'   => 'We need your video.',
		'not_video'    => 'That file is not a video.',
		'too_big'      => 'That video is too big. The most we can take is {mb}MB.',
		'no_camera'    => "We couldn't use your camera. Try Upload a video instead.",
		'upload_failed' => 'Your video did not upload. Please try again.',
		'failed'       => 'Something went wrong. Please try again.',
		'max_mb'       => '500',
		'max_secs'     => '180',
		'track'        => 'Job application',
		'crm_hook'     => '',
	);
}

/** A pipe list in a field, trimmed, empties dropped. */
function ew_jobs_pipes( $s ) {
	return array_values( array_filter( array_map( 'trim', explode( '|', (string) $s ) ), 'strlen' ) );
}

/** One row of choice buttons, in the half-time show look. */
function ew_jobs_pick( $group, $label, $options ) {
	$h  = '<p class="ewh-lab" id="ewj-' . esc_attr( $group ) . '-l">' . esc_html( $label ) . '</p>';
	$h .= '<div class="ewh-pick" role="group" aria-labelledby="ewj-' . esc_attr( $group ) . '-l" data-ewj-pick="' . esc_attr( $group ) . '">';
	foreach ( $options as $o ) {
		$h .= '<button type="button" class="ewh-opt" data-v="' . esc_attr( $o ) . '" aria-pressed="false">' . esc_html( $o ) . '</button>';
	}
	return $h . '</div>';
}

function ew_jobs_shortcode( $atts ) {
	$a    = shortcode_atts( ew_jobs_defaults(), (array) $atts, 'eastwood_jobs' );
	$post = get_post();
	$cam  = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="3" y="6" width="13" height="12" rx="2"/><path d="M16 10l5-3v10l-5-3z"/></svg>';
	$up   = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M12 16V4M7 9l5-5 5 5M4 20h16"/></svg>';

	$words = array(
		'need_name'    => $a['need_name'],
		'need_phone'   => $a['need_phone'],
		'need_role'    => $a['need_role'],
		'need_pay'     => $a['need_pay'],
		'need_video'   => $a['need_video'],
		'not_video'    => $a['not_video'],
		'too_big'      => str_replace( '{mb}', (string) (int) $a['max_mb'], (string) $a['too_big'] ),
		'no_camera'    => $a['no_camera'],
		'upload_failed' => $a['upload_failed'],
		'uploading'    => $a['uploading'],
		'uploaded'     => $a['uploaded'],
		'rec_start'    => $a['rec_start'],
		'rec_stop'     => $a['rec_stop'],
		'waiting'      => $a['waiting'],
		'send2'        => $a['send2'],
		'max_mb'       => max( 1, (int) $a['max_mb'] ),
		'max_secs'     => max( 10, (int) $a['max_secs'] ),
		'rest'         => esc_url_raw( rest_url( 'eastwood/v1/jobs' ) ),
		'vurl'         => esc_url_raw( rest_url( 'eastwood/v1/jobs/video-url' ) ),
		'vdone'        => esc_url_raw( rest_url( 'eastwood/v1/jobs/video-done' ) ),
	) + ( function_exists( 'ew_optin_words' ) ? ew_optin_words( 'jobs', $a ) : array() );

	$h  = '<section class="ewh ewj" data-page="' . (int) ( $post ? $post->ID : 0 ) . '">';
	$h .= '<form class="ewh-form" data-step="1" novalidate>';
	$h .= '<div class="ewh-bar" aria-hidden="true"><span></span><span></span><span></span></div>';
	// Step one: the email, saved the moment Next is pressed.
	$h .= function_exists( 'ew_optin_step_one' ) ? ew_optin_step_one( 'ewh', $a ) : '';
	// Step two: about you, and the video.
	$h .= '<div class="ewh-two" data-ewo-two hidden>';
	$h .= '<p class="ewh-head">' . esc_html( $a['next_title'] ) . '</p>';
	$h .= '<label class="ewh-lab" for="ewjName">' . esc_html( $a['name_label'] ) . '</label><input class="ewh-in" id="ewjName" type="text" autocomplete="name" required data-ewo-save="name">';
	$h .= '<label class="ewh-lab" for="ewjPhone">' . esc_html( $a['phone_label'] ) . '</label><input class="ewh-in" id="ewjPhone" type="tel" autocomplete="tel" required data-ewo-save="phone">';
	$h .= ew_jobs_pick( 'role', $a['role_label'], ew_jobs_pipes( $a['roles'] ) );
	$h .= ew_jobs_pick( 'pay', $a['pay_label'], ew_jobs_pipes( $a['pays'] ) );
	$h .= '<p class="ewh-lab">' . esc_html( $a['video_label'] ) . '</p>';
	if ( '' !== trim( (string) $a['video_prompt'] ) ) {
		$h .= '<p class="ewj-prompt">' . esc_html( $a['video_prompt'] ) . '</p>';
	}
	$h .= '<div class="ewj-vids" data-ewj-tiles>';
	$h .= '<button type="button" class="ewj-tile" data-ewj-rec>' . $cam . '<span>' . esc_html( $a['record'] ) . '</span></button>';
	$h .= '<button type="button" class="ewj-tile" data-ewj-up>' . $up . '<span>' . esc_html( $a['upload'] ) . '</span></button>';
	$h .= '</div>';
	$h .= '<input class="ewj-file" type="file" accept="video/*" data-ewj-file tabindex="-1" aria-hidden="true">';
	$h .= '<input class="ewj-file" type="file" accept="video/*" capture="user" data-ewj-cam tabindex="-1" aria-hidden="true">';
	// Recording in the page, on a computer.
	$h .= '<div class="ewj-recbox" data-ewj-recbox hidden><video class="ewj-live" muted playsinline autoplay></video>';
	$h .= '<p class="ewj-clock" data-ewj-clock>0:00</p>';
	$h .= '<div class="ewj-recrow"><button type="button" class="ewj-recbtn" data-ewj-recgo>' . esc_html( $a['rec_start'] ) . '</button>';
	$h .= '<button type="button" class="ewh-skip" data-ewj-reccancel>' . esc_html( $a['rec_cancel'] ) . '</button></div></div>';
	// The chosen video and how far its upload has got.
	$h .= '<div class="ewj-vstate" data-ewj-vstate hidden><p class="ewj-vname" data-ewj-vname></p>';
	$h .= '<p class="ewj-vmsg" data-ewj-vmsg></p><div class="ewj-prog" aria-hidden="true"><i data-ewj-bar></i></div>';
	$h .= '<button type="button" class="ewh-fx" data-ewj-change>' . esc_html( $a['change'] ) . '</button>';
	$h .= '<button type="button" class="ewh-fx" data-ewj-retry hidden>' . esc_html( $a['retry'] ) . '</button></div>';
	$h .= '<p class="ewh-err" role="alert" data-ewj-err></p>';
	$h .= '<button type="submit" class="ewh-btn" data-ewj-send>' . esc_html( $a['send2'] ) . '</button>';
	$h .= '</div>';
	// Step three: thank you, and the app.
	$h .= '<div class="ewj-three" data-ewj-three hidden>';
	$h .= '<p class="ewh-head">' . esc_html( $a['done_title'] ) . '</p>';
	$h .= '<p class="ewj-done">' . esc_html( trim( $a['done_note'] . ' ' . $a['next_note'] ) ) . '</p>';
	if ( '' !== trim( (string) $a['app_note'] ) ) {
		$h .= '<p class="ewj-done">' . esc_html( $a['app_note'] ) . '</p>';
	}
	$h .= '<div class="ewi ewj-apps">' . ( function_exists( 'ew_inapp_stores' ) ? ew_inapp_stores() : '' ) . '</div>';
	$h .= '</div>';
	$h .= '</form>';
	$h .= '<script type="application/json" class="ewj-words">' . wp_json_encode( $words ) . '</script>';
	return $h . '</section>';
}
add_shortcode( 'eastwood_jobs', 'ew_jobs_shortcode' );

add_filter( 'ew_optin_forms', function ( $forms ) {
	$forms['jobs'] = 'ew_jobs_block_on';
	return $forms;
} );

/** The block on a page, read from the page itself, so a request carries its words. */
function ew_jobs_block_on( $post ) {
	$found = null;
	$data  = json_decode( (string) get_post_meta( $post->ID, '_elementor_data', true ), true );
	if ( is_array( $data ) ) {
		$walk = function ( $nodes ) use ( &$walk, &$found ) {
			foreach ( (array) $nodes as $n ) {
				if ( $found ) {
					return;
				}
				$sc = (string) ( $n['settings']['shortcode'] ?? '' );
				if ( 0 === strpos( ltrim( $sc ), '[eastwood_jobs' ) ) {
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
	if ( ! $found && preg_match( '/\[eastwood_jobs[^\]]*\]/', (string) $post->post_content, $mm ) ) {
		$found = $mm[0];
	}
	if ( ! $found ) {
		return null;
	}
	$atts = array();
	if ( preg_match( '/\[eastwood_jobs([^\]]*)\]/', $found, $o ) ) {
		$atts = (array) shortcode_parse_atts( $o[1] );
	}
	return shortcode_atts( ew_jobs_defaults(), $atts, 'eastwood_jobs' );
}

/* --- The video store ----------------------------------------------------- */

/** Call the fan app Supabase with the service key. [code, body]. */
function ew_jobs_sb( $method, $path, $body = null ) {
	if ( ! function_exists( 'ew_runs_sb' ) ) {
		return array( 0, array( 'message' => 'No Supabase connection.' ) );
	}
	return ew_runs_sb( $method, $path, $body );
}

/** The object path, written safely into a Storage URL. */
function ew_jobs_obj( $path ) {
	return EW_JOBS_BUCKET . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', (string) $path ) ) );
}

/** A fresh viewing link for a stored video, or empty when it is not there. */
function ew_jobs_signed( $path, $secs = 3600 ) {
	if ( '' === (string) $path ) {
		return '';
	}
	list( $c, $j ) = ew_jobs_sb( 'POST', '/storage/v1/object/sign/' . ew_jobs_obj( $path ), array( 'expiresIn' => (int) $secs ) );
	$u = is_array( $j ) ? (string) ( $j['signedURL'] ?? $j['signedUrl'] ?? '' ) : '';
	return ( 200 === $c && '' !== $u ) ? EW_FAN_SUPABASE . '/storage/v1' . $u : '';
}

/** The extension a file is kept under: from its name if sensible, else from its type. */
function ew_jobs_ext( $name, $type ) {
	$ext = strtolower( (string) pathinfo( (string) $name, PATHINFO_EXTENSION ) );
	if ( in_array( $ext, array( 'mp4', 'mov', 'm4v', 'webm', '3gp', 'mkv', 'avi' ), true ) ) {
		return $ext;
	}
	$map = array( 'video/quicktime' => 'mov', 'video/webm' => 'webm', 'video/3gpp' => '3gp', 'video/x-matroska' => 'mkv' );
	$t   = strtolower( (string) strtok( (string) $type, ';' ) );
	return $map[ $t ] ?? 'mp4';
}

/** The admin address that opens one application video: it signs a fresh link each time. */
function ew_jobs_video_link( $bid ) {
	return admin_url( 'admin-post.php?action=ew_job_video&booking=' . (int) $bid );
}

add_action( 'admin_post_ew_job_video', function () {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( 'Please log in as club staff to watch this video.', '', array( 'response' => 403 ) );
	}
	$bid  = (int) ( $_GET['booking'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
	$path = 'ewt_booking' === get_post_type( $bid ) ? (string) get_post_meta( $bid, '_ewt_video', true ) : '';
	$url  = ew_jobs_signed( $path, 3600 );
	if ( '' === $url ) {
		wp_die( 'That video could not be found.', '', array( 'response' => 404 ) );
	}
	wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect
	exit;
} );

/* No website emails to an applicant: what they are told is Ben's to write. */
add_filter( 'ew_om_skip', function ( $skip, $bid, $step ) {
	return 'jobs' === get_post_meta( $bid, '_ewt_form', true ) ? true : $skip;
}, 10, 3 );

/* Applications reach the jobs inbox unless somebody sets a different one. */
add_filter( 'default_option_ew_notify_to_application', function () {
	return 'jobs@eastwoodfc.com';
} );

add_action( 'rest_api_init', function () {
	/* A signed address the browser sends the video straight to. */
	register_rest_route( 'eastwood/v1', '/jobs/video-url', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) {
			$bid = function_exists( 'ew_optin_claim' ) ? ew_optin_claim( $req ) : 0;
			$a   = ew_jobs_defaults();
			if ( $bid ) {
				$post = get_post( (int) get_post_meta( $bid, '_ewt_page', true ) );
				$a    = ( $post ? ew_jobs_block_on( $post ) : null ) ?: $a;
			}
			if ( ! $bid || 'jobs' !== get_post_meta( $bid, '_ewt_form', true ) ) {
				return new WP_REST_Response( array( 'message' => $a['failed'] ), 403 );
			}
			$type = sanitize_text_field( (string) $req->get_param( 'type' ) );
			$size = (int) $req->get_param( 'size' );
			if ( '' !== $type && 0 !== strpos( $type, 'video/' ) ) {
				return new WP_REST_Response( array( 'message' => $a['not_video'] ), 400 );
			}
			if ( $size > max( 1, (int) $a['max_mb'] ) * 1048576 ) {
				return new WP_REST_Response( array( 'message' => str_replace( '{mb}', (string) (int) $a['max_mb'], $a['too_big'] ) ), 400 );
			}
			// A second go replaces the first: the old file is taken away.
			foreach ( array( '_ewt_video_pending', '_ewt_video' ) as $k ) {
				$old = (string) get_post_meta( $bid, $k, true );
				if ( '' !== $old ) {
					ew_jobs_sb( 'DELETE', '/storage/v1/object/' . ew_jobs_obj( $old ) );
					delete_post_meta( $bid, $k );
				}
			}
			$path = 'applications/' . gmdate( 'Y-m' ) . '/' . $bid . '-' . strtolower( wp_generate_password( 12, false, false ) ) . '.' . ew_jobs_ext( (string) $req->get_param( 'name' ), $type );
			list( $c, $j ) = ew_jobs_sb( 'POST', '/storage/v1/object/upload/sign/' . ew_jobs_obj( $path ), new stdClass() );
			$u = is_array( $j ) ? (string) ( $j['url'] ?? '' ) : '';
			if ( 200 !== $c || '' === $u ) {
				return new WP_REST_Response( array( 'message' => $a['upload_failed'] ), 502 );
			}
			update_post_meta( $bid, '_ewt_video_pending', $path );
			return array( 'url' => EW_FAN_SUPABASE . '/storage/v1' . $u );
		},
	) );

	/* The browser says the video has landed: check it is there, and record it. */
	register_rest_route( 'eastwood/v1', '/jobs/video-done', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) {
			$bid  = function_exists( 'ew_optin_claim' ) ? ew_optin_claim( $req ) : 0;
			$path = $bid ? (string) get_post_meta( $bid, '_ewt_video_pending', true ) : '';
			if ( '' === $path || '' === ew_jobs_signed( $path, 60 ) ) {
				return new WP_REST_Response( array( 'ok' => 0 ), 400 );
			}
			update_post_meta( $bid, '_ewt_video', $path );
			update_post_meta( $bid, '_ewt_video_at', time() );
			delete_post_meta( $bid, '_ewt_video_pending' );
			if ( function_exists( 'ew_tix_crm' ) ) {
				ew_tix_crm( (string) get_post_meta( $bid, '_ewt_hook', true ), $bid, 'video' );
			}
			return array( 'ok' => 1 );
		},
	) );

	/* Send my application: the started booking becomes the application. */
	register_rest_route( 'eastwood/v1', '/jobs', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) {
			$post = get_post( (int) $req->get_param( 'page' ) );
			$a    = $post ? ew_jobs_block_on( $post ) : null;
			if ( ! $a ) {
				return new WP_REST_Response( array( 'message' => 'no block' ), 400 );
			}
			$bid = function_exists( 'ew_optin_claim' ) ? ew_optin_claim( $req ) : 0;
			if ( ! $bid || (int) get_post_meta( $bid, '_ewt_page', true ) !== (int) $post->ID ) {
				return new WP_REST_Response( array( 'message' => $a['failed'] ), 400 );
			}
			$name  = sanitize_text_field( (string) $req->get_param( 'name' ) );
			$name  = function_exists( 'ew_person_name' ) ? ew_person_name( $name ) : trim( $name );
			$phone = trim( sanitize_text_field( (string) $req->get_param( 'phone' ) ) );
			$role  = trim( sanitize_text_field( (string) $req->get_param( 'role' ) ) );
			$pay   = trim( sanitize_text_field( (string) $req->get_param( 'pay' ) ) );
			$video = (string) get_post_meta( $bid, '_ewt_video', true );
			if ( '' === $name ) {
				return new WP_REST_Response( array( 'message' => $a['need_name'] ), 400 );
			}
			if ( strlen( preg_replace( '/\D/', '', $phone ) ) < 10 ) {
				return new WP_REST_Response( array( 'message' => $a['need_phone'] ), 400 );
			}
			if ( ! in_array( $role, ew_jobs_pipes( $a['roles'] ), true ) ) {
				return new WP_REST_Response( array( 'message' => $a['need_role'] ), 400 );
			}
			if ( ! in_array( $pay, ew_jobs_pipes( $a['pays'] ), true ) ) {
				return new WP_REST_Response( array( 'message' => $a['need_pay'] ), 400 );
			}
			if ( '' === $video ) {
				return new WP_REST_Response( array( 'message' => $a['need_video'] ), 400 );
			}
			wp_update_post( array( 'ID' => $bid, 'post_title' => $name ) );
			foreach ( array(
				'name'   => $name,
				'phone'  => $phone,
				'role'   => $role,
				'pay'    => $pay,
				'event'  => $a['event'],
				'lines'  => $role . ' · ' . $pay,
				'extra'  => 'Job application: ' . $role . ', ' . $pay,
				'total'  => 0,
				'status' => 'application',
			) as $k => $v ) {
				update_post_meta( $bid, '_ewt_' . $k, $v );
			}
			if ( function_exists( 'ew_tix_crm' ) ) {
				ew_tix_crm( (string) get_post_meta( $bid, '_ewt_hook', true ), $bid, 'application' );
			}
			return function_exists( 'ew_optin_finished' ) ? ew_optin_finished( $bid ) : array( 'ok' => 1 );
		},
	) );
} );

function ew_jobs_css() {
	return '
.ewj .ewh-pick{margin:0}
.ewj .ewj-prompt{margin:0 0 10px;font-size:14px;line-height:1.4;color:#9a9aa3}
.ewj-vids{display:flex;gap:8px}
.ewj .ewj-tile{flex:1;min-width:0;height:84px;border:1px dashed rgba(255,255,255,.3);border-radius:8px;background:transparent;color:#fff;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;font:inherit;font-size:15px;font-weight:600;cursor:pointer;padding:0 6px}
.ewj .ewj-tile:hover,.ewj .ewj-tile:focus-visible{border-color:rgba(255,255,255,.75);outline:none}
.ewj .ewj-file{position:absolute;width:1px;height:1px;opacity:0;pointer-events:none}
.ewj-recbox{border:1px solid rgba(255,255,255,.18);border-radius:8px;padding:10px}
.ewj .ewj-live{display:block;width:100%;aspect-ratio:4/3;background:#111;border-radius:6px;object-fit:cover;transform:scaleX(-1)}
.ewj .ewj-clock{margin:8px 0 0;font-family:Anton,Impact,sans-serif;font-size:20px;color:#fff;text-align:center}
.ewj-recrow{display:flex;gap:10px;align-items:center;justify-content:center;margin-top:6px}
.ewj .ewj-recbtn{height:44px;padding:0 18px;border:1px solid rgba(255,255,255,.75);border-radius:8px;background:#fff;color:#0b0b0b;font:inherit;font-size:15px;font-weight:700;cursor:pointer}
.ewj .ewj-recrow .ewh-skip{margin:0}
.ewj-vstate{border:1px solid rgba(255,255,255,.18);border-radius:8px;padding:12px 14px}
.ewj .ewj-vname{margin:0;font-size:15px;font-weight:600;color:#fff;overflow-wrap:anywhere}
.ewj .ewj-vmsg{margin:4px 0 8px;font-size:14px;color:#9a9aa3}
.ewj-prog{height:4px;border-radius:2px;background:rgba(255,255,255,.18);overflow:hidden}
.ewj-prog i{display:block;height:100%;width:0;background:#fff;transition:width .2s}
.ewj .ewj-vstate .ewh-fx{margin:8px 0 0}
.ewj .ewj-done{margin:0 0 14px;font-size:17px;line-height:1.5;color:#ddd}
.ewj .ewj-apps.ewi{background:none;border-radius:0;padding:0;margin-top:4px}
';
}

function ew_jobs_js() {
	return <<<'JS'
(function(){
 var root = document.querySelector('.ewj'); if(!root) return;
 var wordsEl = root.querySelector('.ewj-words'); if(!wordsEl) return;
 var W = JSON.parse(wordsEl.textContent), page = root.getAttribute('data-page');
 var form = root.querySelector('.ewh-form'), two = root.querySelector('[data-ewo-two]'), three = root.querySelector('[data-ewj-three]');
 var err = two.querySelector('[data-ewj-err]'), sendBtn = two.querySelector('[data-ewj-send]');
 var tiles = two.querySelector('[data-ewj-tiles]'), fileUp = two.querySelector('[data-ewj-file]'), fileCam = two.querySelector('[data-ewj-cam]');
 var recbox = two.querySelector('[data-ewj-recbox]'), live = recbox.querySelector('video'), clock = recbox.querySelector('[data-ewj-clock]');
 var recGo = recbox.querySelector('[data-ewj-recgo]'), recCancel = recbox.querySelector('[data-ewj-reccancel]');
 var vstate = two.querySelector('[data-ewj-vstate]'), vname = vstate.querySelector('[data-ewj-vname]'), vmsg = vstate.querySelector('[data-ewj-vmsg]');
 var vbar = vstate.querySelector('[data-ewj-bar]'), vchange = vstate.querySelector('[data-ewj-change]'), vretry = vstate.querySelector('[data-ewj-retry]');
 var pick = {role: '', pay: ''}, vid = {file: null, done: false, busy: false, xhr: null}, wantSend = false, claim = '';
 function step(n){ form.setAttribute('data-step', String(n)); }
 function post(url, body){
  return fetch(url, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)})
   .then(function(r){ return r.json().then(function(j){ return {ok: r.ok, j: j}; }, function(){ return {ok: false, j: {}}; }); });
 }
 var O = window.ewOptin ? ewOptin(root, W, {open: function(){ step(2); }}) : {bid: 0, key: '', email: ''};

 /* The two rows of choices. */
 two.querySelectorAll('[data-ewj-pick]').forEach(function(g){
  var k = g.getAttribute('data-ewj-pick');
  g.querySelectorAll('.ewh-opt').forEach(function(b){
   b.addEventListener('click', function(){
    pick[k] = b.getAttribute('data-v');
    g.querySelectorAll('.ewh-opt').forEach(function(x){ x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
    err.textContent = '';
   });
  });
 });

 /* The video: chosen, then sent straight to the store. */
 function showTiles(){ tiles.hidden = false; vstate.hidden = true; recbox.hidden = true; }
 function setBar(p){ vbar.style.width = Math.max(0, Math.min(100, p)) + '%'; }
 function sendState(){
  if(wantSend && vid.busy){ sendBtn.disabled = true; sendBtn.textContent = W.waiting; }
  else { sendBtn.disabled = false; sendBtn.textContent = W.send2; }
 }
 function uploadFailed(msg){
  vid.busy = false; vid.done = false; wantSend = false; sendState();
  vmsg.textContent = msg || W.upload_failed; vretry.hidden = false; setBar(0);
 }
 function upload(){
  var f = vid.file; if(!f) return;
  vid.busy = true; vid.done = false; vretry.hidden = true;
  vmsg.textContent = String(W.uploading).replace('{p}', '0'); setBar(0);
  post(W.vurl, {bid: O.bid, key: O.key, type: f.type || '', size: f.size, name: f.name || ''}).then(function(x){
   if(!x.ok || !x.j.url){ uploadFailed(x.j && x.j.message); return; }
   var xhr = new XMLHttpRequest(); vid.xhr = xhr;
   xhr.open('PUT', x.j.url);
   xhr.setRequestHeader('Content-Type', f.type || 'video/mp4');
   xhr.setRequestHeader('x-upsert', 'true');
   xhr.upload.onprogress = function(e){
    if(!e.lengthComputable) return;
    var p = Math.floor(e.loaded / e.total * 100);
    setBar(p); vmsg.textContent = String(W.uploading).replace('{p}', String(p));
   };
   xhr.onerror = function(){ if(vid.xhr === xhr) uploadFailed(); };
   xhr.onload = function(){
    if(vid.xhr !== xhr) return;
    if(xhr.status < 200 || xhr.status >= 300){ uploadFailed(); return; }
    post(W.vdone, {bid: O.bid, key: O.key}).then(function(y){
     if(!y.ok){ uploadFailed(); return; }
     vid.busy = false; vid.done = true; setBar(100); vmsg.textContent = W.uploaded;
     if(wantSend){ wantSend = false; send(); } else { sendState(); }
    }).catch(function(){ uploadFailed(); });
   };
   xhr.send(f);
  }).catch(function(){ uploadFailed(); });
 }
 function choose(f){
  err.textContent = '';
  if(!f) return;
  var isVid = /^video\//.test(f.type || '') || (!f.type && /\.(mp4|mov|m4v|webm|3gp|mkv|avi)$/i.test(f.name || ''));
  if(!isVid){ err.textContent = W.not_video; return; }
  if(f.size > W.max_mb * 1048576){ err.textContent = W.too_big; return; }
  if(vid.xhr){ try{ vid.xhr.abort(); }catch(e){} vid.xhr = null; }
  vid.file = f;
  tiles.hidden = true; recbox.hidden = true; vstate.hidden = false;
  vname.textContent = f.name || '';
  upload();
 }
 fileUp.addEventListener('change', function(){ choose(fileUp.files[0]); fileUp.value = ''; });
 fileCam.addEventListener('change', function(){ choose(fileCam.files[0]); fileCam.value = ''; });
 two.querySelector('[data-ewj-up]').addEventListener('click', function(){ fileUp.click(); });
 vchange.addEventListener('click', function(){
  if(vid.xhr){ try{ vid.xhr.abort(); }catch(e){} vid.xhr = null; }
  vid.file = null; vid.done = false; vid.busy = false; wantSend = false; sendState(); showTiles();
 });
 vretry.addEventListener('click', upload);

 /* Record now: the phone camera on a phone, the webcam in the page on a computer. */
 var stream = null, rec = null, chunks = [], t0 = 0, tick = 0;
 function coarse(){ try{ return matchMedia('(pointer:coarse)').matches; }catch(e){ return false; } }
 function fmt(s){ s = Math.floor(s); return Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2); }
 function stopCam(){
  if(tick){ clearInterval(tick); tick = 0; }
  if(stream){ stream.getTracks().forEach(function(t){ t.stop(); }); stream = null; }
  live.srcObject = null;
 }
 function mime(){
  var list = ['video/mp4', 'video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm'];
  for(var i = 0; i < list.length; i++){ try{ if(MediaRecorder.isTypeSupported(list[i])) return list[i]; }catch(e){} }
  return '';
 }
 two.querySelector('[data-ewj-rec]').addEventListener('click', function(){
  err.textContent = '';
  if(coarse() || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.MediaRecorder){ fileCam.click(); return; }
  navigator.mediaDevices.getUserMedia({video: true, audio: true}).then(function(s){
   stream = s; live.srcObject = s;
   tiles.hidden = true; recbox.hidden = false;
   recGo.textContent = W.rec_start; clock.textContent = fmt(0) + ' / ' + fmt(W.max_secs);
  }).catch(function(){ err.textContent = W.no_camera; });
 });
 recGo.addEventListener('click', function(){
  if(rec && rec.state === 'recording'){ rec.stop(); return; }
  if(!stream) return;
  var m = mime(); chunks = [];
  try{ rec = m ? new MediaRecorder(stream, {mimeType: m}) : new MediaRecorder(stream); }catch(e){ err.textContent = W.no_camera; return; }
  rec.ondataavailable = function(e){ if(e.data && e.data.size) chunks.push(e.data); };
  rec.onstop = function(){
   var type = (rec.mimeType || m || 'video/webm').split(';')[0];
   var ext = /mp4/.test(type) ? 'mp4' : 'webm';
   stopCam();
   var blob = new Blob(chunks, {type: type});
   var f; try{ f = new File([blob], 'recording.' + ext, {type: type}); }catch(e){ f = blob; f.name = 'recording.' + ext; }
   choose(f);
  };
  rec.start(1000);
  t0 = Date.now(); recGo.textContent = W.rec_stop;
  tick = setInterval(function(){
   var s = (Date.now() - t0) / 1000;
   clock.textContent = fmt(s) + ' / ' + fmt(W.max_secs);
   if(s >= W.max_secs && rec && rec.state === 'recording') rec.stop();
  }, 250);
 });
 recCancel.addEventListener('click', function(){
  if(rec && rec.state === 'recording'){ rec.onstop = null; rec.stop(); }
  stopCam(); showTiles();
 });

 /* Send my application. */
 function send(){
  err.textContent = '';
  var name = root.querySelector('#ewjName').value.trim(), phone = root.querySelector('#ewjPhone').value.trim();
  if(!name){ err.textContent = W.need_name; return; }
  if(phone.replace(/\D/g, '').length < 10){ err.textContent = W.need_phone; return; }
  if(!pick.role){ err.textContent = W.need_role; return; }
  if(!pick.pay){ err.textContent = W.need_pay; return; }
  if(!vid.file){ err.textContent = W.need_video; return; }
  if(vid.busy){ wantSend = true; sendState(); return; }
  if(!vid.done){ err.textContent = W.upload_failed; return; }
  sendBtn.disabled = true;
  post(W.rest, {page: page, bid: O.bid, key: O.key, name: name, phone: phone, role: pick.role, pay: pick.pay}).then(function(x){
   sendBtn.disabled = false; sendBtn.textContent = W.send2;
   if(!x.ok){ err.textContent = (x.j && x.j.message) || W.failed; return; }
   if(O.done) O.done();
   claim = x.j.claim || '';
   if(claim){
    three.querySelectorAll('[data-ewi-store]').forEach(function(a){
     try{ var u = new URL(a.href); u.searchParams.set('claim', claim); a.href = u.toString(); }catch(e){}
    });
   }
   two.hidden = true; three.hidden = false; step(3);
   try{ form.scrollIntoView({behavior: 'smooth', block: 'start'}); }catch(e){}
  }).catch(function(){ sendBtn.disabled = false; sendBtn.textContent = W.send2; err.textContent = W.failed; });
 }
 form.addEventListener('submit', function(e){ e.preventDefault(); if(form.getAttribute('data-step') !== '3') send(); });
})();
JS;
}

add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_singular() ) {
		return;
	}
	$post = get_post();
	if ( ! $post || ! function_exists( 'ew_uses_shortcode' ) || ! ew_uses_shortcode( $post, 'eastwood_jobs' ) ) {
		return;
	}
	wp_enqueue_style( 'ewc-fonts', 'https://fonts.googleapis.com/css2?family=Anton&family=Instrument+Sans:wght@400;600;700&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
	wp_register_style( 'ew-jobs', false, array(), EW_FWP_VERSION );
	wp_enqueue_style( 'ew-jobs' );
	wp_add_inline_style( 'ew-jobs', ( function_exists( 'ew_halftime_css' ) ? ew_halftime_css() : '' ) . ( function_exists( 'ew_inapp_css' ) ? ew_inapp_css() : '' ) . ew_jobs_css() );
	wp_register_script( 'ew-jobs', '', array(), EW_FWP_VERSION, true );
	wp_enqueue_script( 'ew-jobs' );
	wp_add_inline_script( 'ew-jobs', ( function_exists( 'ew_optin_js' ) ? ew_optin_js() : '' ) . ew_jobs_js() );
}, 22 );
