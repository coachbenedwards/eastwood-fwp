<?php
/**
 * What happens around a ticket payment: the booker's details first, a
 * record of every booking, the CRM told at each step, and the one-tap extra
 * straight after paying.
 *
 * 1. Checkout asks for name and email before the card form. Pressing
 *    Continue saves a Booking (Bookings in the admin menu), makes the Stripe
 *    customer, and tells the CRM "started", so a booker who never pays is
 *    still known.
 * 2. Stripe takes the card and keeps it for this customer.
 * 3. Back on the page, the payment is checked with Stripe, the Booking is
 *    marked paid and the CRM is told "paid". If the block has an upsell,
 *    it is offered there and then; one tap charges the same card.
 *
 * The CRM is whatever answers the block's crm_hook field: a GoHighLevel
 * "Inbound Webhook" workflow, which files the contact and sends the emails.
 * Every word the booker sees is a field on the tickets block.
 *
 * @package eastwood-fwp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* --- Bookings in the admin ----------------------------------------------- */

add_action( 'init', function () {
	register_post_type( 'ewt_booking', array(
		'labels'          => array(
			'name'          => 'Bookings',
			'singular_name' => 'Booking',
			'menu_name'     => 'Bookings',
			'all_items'     => 'All bookings',
		),
		'public'          => false,
		'show_ui'         => true,
		'show_in_menu'    => true,
		'menu_icon'       => 'dashicons-tickets-alt',
		'menu_position'   => 26,
		'supports'        => array( 'title' ),
		'capability_type' => 'post',
		'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
		'map_meta_cap'    => true,
	) );
} );

add_filter( 'manage_ewt_booking_posts_columns', function () {
	return array(
		'cb'      => '<input type="checkbox" />',
		'title'   => 'Name',
		'email'   => 'Email',
		'event'   => 'Event',
		'tickets' => 'Tickets',
		'status'  => 'Status',
		'total'   => 'Paid',
		'date'    => 'Started',
	);
} );

add_action( 'manage_ewt_booking_posts_custom_column', function ( $col, $id ) {
	$m = function ( $k ) use ( $id ) {
		return (string) get_post_meta( $id, '_ewt_' . $k, true );
	};
	switch ( $col ) {
		case 'email':
			echo esc_html( $m( 'email' ) );
			break;
		case 'event':
			echo esc_html( $m( 'event' ) );
			break;
		case 'tickets':
			echo esc_html( $m( 'lines' ) );
			$extra = $m( 'extra' );
			if ( '' !== $extra ) {
				echo '<br>' . esc_html( $extra );
			}
			break;
		case 'status':
			echo esc_html( ucfirst( $m( 'status' ) ) );
			break;
		case 'total':
			$p = (int) $m( 'paid' );
			echo $p ? esc_html( ew_tix_money( $p ) ) : '—';
			break;
	}
}, 10, 2 );

/* Download all bookings as a spreadsheet. */
add_action( 'restrict_manage_posts', function ( $type ) {
	if ( 'ewt_booking' === $type ) {
		echo '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ewt_bookings_csv' ), 'ewt_csv' ) ) . '">Download spreadsheet</a>';
	}
} );

add_action( 'admin_post_ewt_bookings_csv', function () {
	if ( ! current_user_can( 'edit_posts' ) || ! check_admin_referer( 'ewt_csv' ) ) {
		wp_die( 'Not allowed.' );
	}
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="bookings-' . gmdate( 'Y-m-d' ) . '.csv"' );
	$out = fopen( 'php://output', 'w' );
	fputcsv( $out, array( 'Started', 'Name', 'Email', 'Event', 'Tickets', 'Extra', 'Status', 'Paid' ) );
	foreach ( get_posts( array( 'post_type' => 'ewt_booking', 'post_status' => 'any', 'numberposts' => -1 ) ) as $b ) {
		$m = function ( $k ) use ( $b ) {
			return (string) get_post_meta( $b->ID, '_ewt_' . $k, true );
		};
		fputcsv( $out, array( $b->post_date, $m( 'name' ), $m( 'email' ), $m( 'event' ), $m( 'lines' ), $m( 'extra' ), $m( 'status' ), number_format( (int) $m( 'paid' ) / 100, 2 ) ) );
	}
	fclose( $out );
	exit;
} );

/* --- Stripe and the CRM ------------------------------------------------- */

function ew_tix_stripe( $method, $path, $body = array() ) {
	$args = array(
		'method'  => $method,
		'timeout' => 20,
		'headers' => array(
			'Authorization'  => 'Bearer ' . ew_tix_sk(),
			'Stripe-Version' => '2026-03-25.dahlia',
		),
	);
	if ( $body ) {
		$args['body'] = $body;
	}
	$res = wp_remote_request( 'https://api.stripe.com/v1/' . $path, $args );
	if ( is_wp_error( $res ) ) {
		return array( 'error' => array( 'message' => 'Could not reach Stripe.' ) );
	}
	return (array) json_decode( (string) wp_remote_retrieve_body( $res ), true );
}

/** Tell the CRM what just happened. Never holds the booker up. */
function ew_tix_crm( $hook, $booking_id, $step ) {
	$hook = trim( (string) $hook );
	if ( '' === $hook || 0 !== strpos( $hook, 'https://' ) ) {
		do_action( 'ew_tix_step', (int) $booking_id, (string) $step );
		return;
	}
	$m    = function ( $k ) use ( $booking_id ) {
		return (string) get_post_meta( $booking_id, '_ewt_' . $k, true );
	};
	$name = trim( $m( 'name' ) );
	$sp   = strpos( $name, ' ' );
	wp_remote_post( $hook, array(
		'timeout'  => 5,
		'blocking' => false,
		'headers'  => array( 'Content-Type' => 'application/json' ),
		'body'     => wp_json_encode( array(
			'step'       => $step,
			'greeting'   => '' !== $m( 'greeting' ) ? $m( 'greeting' ) : 'Hi Eastwood Fan',
			'first_name' => false === $sp ? $name : substr( $name, 0, $sp ),
			'last_name'  => false === $sp ? '' : trim( substr( $name, $sp + 1 ) ),
			'name'       => $name,
			'email'      => $m( 'email' ),
			'event'      => $m( 'event' ),
			'event_url'  => $m( 'url' ),
			'tickets'    => $m( 'lines' ),
			'extra'      => $m( 'extra' ),
			'total'      => number_format( (int) $m( 'total' ) / 100, 2, '.', '' ),
			'paid'       => number_format( (int) $m( 'paid' ) / 100, 2, '.', '' ),
			'status'     => $m( 'status' ),
			'booking_id' => (int) $booking_id,
			/* Claims and half-time sign-ups carry these; paid bookings leave them empty. */
			'phone'      => $m( 'phone' ),
			'school'     => $m( 'school' ),
			'adults'     => $m( 'adults' ),
			'children'   => $m( 'children' ),
			'consent'    => $m( 'consent' ),
		) ),
	) );
	// The website's own emails for this step (order-mail.php).
	do_action( 'ew_tix_step', (int) $booking_id, (string) $step );
}

/** Save the booker as soon as they press Continue. */
function ew_tix_booking_start( $post, $event, $name, $email, $lines, $total, $hook, $greeting = '' ) {
	$id = wp_insert_post( array(
		'post_type'   => 'ewt_booking',
		'post_status' => 'publish',
		'post_title'  => '' !== (string) $name ? $name : $email,
	) );
	if ( ! $id || is_wp_error( $id ) ) {
		return 0;
	}
	foreach ( array(
		'name'   => $name,
		'email'  => $email,
		'event'  => $event,
		'page'   => $post->ID,
		'url'    => get_permalink( $post ),
		'lines'  => $lines,
		'total'  => $total,
		'status' => 'started',
		// How the emails open. A field on the block, so each event can
		// have its own; unpaid bookers have given no name.
		'greeting' => trim( (string) $greeting ),
	) as $k => $v ) {
		update_post_meta( $id, '_ewt_' . $k, $v );
	}
	update_post_meta( $id, '_ewt_hook', (string) $hook );
	ew_tix_crm( $hook, $id, 'started' );
	wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'ew_tix_remind', array( (int) $id ) );
	return (int) $id;
}

/**
 * An hour after someone starts checkout: if they still have not paid, tell
 * the CRM "reminder" so it can send the nudge email. Stripe is asked first,
 * so someone who paid but closed the tab before coming back is not nudged.
 */
add_action( 'ew_tix_remind', function ( $id ) {
	$id = (int) $id;
	if ( 'ewt_booking' !== get_post_type( $id ) || 'started' !== get_post_meta( $id, '_ewt_status', true ) ) {
		return;
	}
	$sid = (string) get_post_meta( $id, '_ewt_open_session', true );
	if ( '' !== $sid && '' !== ew_tix_sk() ) {
		$s = ew_tix_stripe( 'GET', 'checkout/sessions/' . rawurlencode( $sid ) );
		if ( empty( $s['id'] ) ) {
			return; // Stripe could not be asked; better no nudge than a wrong one.
		}
		if ( 'paid' === ( $s['payment_status'] ?? '' ) ) {
			return;
		}
	}
	update_post_meta( $id, '_ewt_reminded', time() );
	ew_tix_crm( (string) get_post_meta( $id, '_ewt_hook', true ), $id, 'reminder' );
} );

/** Find the Booking a Stripe session belongs to. */
function ew_tix_booking_for( $session ) {
	$id = (int) ( $session['metadata']['booking'] ?? 0 );
	return ( $id && 'ewt_booking' === get_post_type( $id ) ) ? $id : 0;
}

/* --- After paying ------------------------------------------------------- */

/** The upsell options written on the block: "label|price|note; ...". */
function ew_tix_upsells( $raw ) {
	$out = array();
	foreach ( array_filter( array_map( 'trim', explode( ';', (string) $raw ) ) ) as $u ) {
		$b     = array_map( 'trim', explode( '|', $u ) );
		$pence = (int) round( (float) preg_replace( '/[^0-9.]/', '', (string) ( $b[1] ?? '' ) ) * 100 );
		if ( '' !== $b[0] && $pence > 0 ) {
			$out[] = array( 'label' => $b[0], 'pence' => $pence, 'note' => (string) ( $b[2] ?? '' ) );
		}
	}
	return $out;
}

/**
 * What the page shows when someone comes back from paying: the thank-you,
 * and the upsell if there is one. Empty if the session is not a paid one
 * for this page.
 */
function ew_tix_after_pay( $page_id, $a, $session_id ) {
	if ( ! preg_match( '/^cs_[A-Za-z0-9_]+$/', (string) $session_id ) || '' === ew_tix_sk() ) {
		return '';
	}
	$s = ew_tix_stripe( 'GET', 'checkout/sessions/' . rawurlencode( $session_id ) );
	if ( empty( $s['id'] ) || (string) ( $s['metadata']['page'] ?? '' ) !== (string) (int) $page_id ) {
		return '';
	}
	if ( 'paid' !== ( $s['payment_status'] ?? '' ) ) {
		return '';
	}
	$bid = ew_tix_booking_for( $s );
	if ( $bid && 'started' === get_post_meta( $bid, '_ewt_status', true ) ) {
		update_post_meta( $bid, '_ewt_status', 'paid' );
		update_post_meta( $bid, '_ewt_paid', (int) ( $s['amount_total'] ?? 0 ) );
		update_post_meta( $bid, '_ewt_session', $s['id'] );
		// Only the email is asked for, so the name comes from the card.
		$who = trim( (string) ( $s['customer_details']['name'] ?? '' ) );
		if ( '' !== $who ) {
			update_post_meta( $bid, '_ewt_name', $who );
			wp_update_post( array( 'ID' => $bid, 'post_title' => $who ) );
		}
		ew_tix_crm( $a['crm_hook'], $bid, 'paid' );
		if ( function_exists( 'ew_track_purchase' ) ) {
			ew_track_purchase( $bid );
		}
	}

	$h  = '<div class="ewt-after">';
	$h .= '<p class="ewt-thanks">' . esc_html( $a['thanks'] ) . '</p>';
	$ups = ew_tix_upsells( $a['upsell'] );
	$done = $bid && '' !== (string) get_post_meta( $bid, '_ewt_extra', true );
	if ( $ups && ! $done ) {
		$h .= '<div class="ewt-up" data-session="' . esc_attr( $s['id'] ) . '" data-rest="' . esc_url( rest_url( 'eastwood/v1/tickets/upsell' ) ) . '">';
		if ( '' !== $a['upsell_title'] ) {
			$h .= '<h3 class="ewt-co-title">' . esc_html( $a['upsell_title'] ) . '</h3>';
		}
		if ( '' !== $a['upsell_sub'] ) {
			$h .= '<p class="ewt-up-sub">' . esc_html( $a['upsell_sub'] ) . '</p>';
		}
		$h .= '<div class="ewt-up-opts">';
		foreach ( $ups as $k => $u ) {
			$h .= '<button type="button" class="ewt-up-o' . ( count( $ups ) - 1 === $k ? ' ewt-sel' : '' ) . '" data-o="' . (int) $k . '" data-label="' . esc_attr( $u['label'] . ' · ' . ew_tix_money( $u['pence'] ) ) . '"><b>' . esc_html( $u['label'] ) . '</b><small>' . esc_html( ew_tix_money( $u['pence'] ) . ( '' !== $u['note'] ? ' · ' . $u['note'] : '' ) ) . '</small></button>';
		}
		$h .= '</div>';
		$h .= '<button type="button" class="ewt-go ewt-up-buy" data-word="' . esc_attr( $a['upsell_button'] ) . '"></button>';
		if ( '' !== $a['upsell_note'] ) {
			$h .= '<p class="ewt-up-note">' . esc_html( $a['upsell_note'] ) . '</p>';
		}
		$h .= '<p class="ewt-up-msg"></p>';
		if ( '' !== $a['upsell_skip'] ) {
			$h .= '<button type="button" class="ewt-up-skip">' . esc_html( $a['upsell_skip'] ) . '</button>';
		}
		$h .= '</div>';
	}
	return $h . '</div>';
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'eastwood/v1', '/tickets/upsell', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) {
			$sid = (string) $req->get_param( 'session' );
			if ( ! preg_match( '/^cs_[A-Za-z0-9_]+$/', $sid ) || '' === ew_tix_sk() ) {
				return new WP_Error( 'ewt_up', 'That did not work. You can buy on the night.', array( 'status' => 400 ) );
			}
			$s = ew_tix_stripe( 'GET', 'checkout/sessions/' . rawurlencode( $sid ) . '?expand[]=payment_intent' );
			if ( empty( $s['id'] ) || 'paid' !== ( $s['payment_status'] ?? '' ) ) {
				return new WP_Error( 'ewt_up', 'That did not work. You can buy on the night.', array( 'status' => 400 ) );
			}
			// Only straight after paying, and only once.
			if ( time() - (int) ( $s['created'] ?? 0 ) > 2 * HOUR_IN_SECONDS ) {
				return new WP_Error( 'ewt_up', 'This offer has closed. You can buy on the night.', array( 'status' => 410 ) );
			}
			$bid = ew_tix_booking_for( $s );
			if ( ! $bid || '' !== (string) get_post_meta( $bid, '_ewt_extra', true ) ) {
				return new WP_Error( 'ewt_up', 'Already added to your booking.', array( 'status' => 409 ) );
			}
			$post  = get_post( (int) ( $s['metadata']['page'] ?? 0 ) );
			$block = $post ? ew_tix_block_on( $post ) : null;
			$ups   = ew_tix_upsells( $block['fields']['upsell'] ?? '' );
			$o     = (int) $req->get_param( 'option' );
			if ( empty( $ups[ $o ] ) ) {
				return new WP_Error( 'ewt_up', 'That did not work. You can buy on the night.', array( 'status' => 400 ) );
			}
			$pi  = (array) ( $s['payment_intent'] ?? array() );
			$pm  = is_array( $pi['payment_method'] ?? null ) ? ( $pi['payment_method']['id'] ?? '' ) : (string) ( $pi['payment_method'] ?? '' );
			$cus = is_array( $s['customer'] ?? null ) ? ( $s['customer']['id'] ?? '' ) : (string) ( $s['customer'] ?? '' );
			if ( '' === $pm || '' === $cus ) {
				return new WP_Error( 'ewt_up', 'That did not work. You can buy on the night.', array( 'status' => 400 ) );
			}
			$event = (string) ( $s['metadata']['event'] ?? '' );
			$p     = ew_tix_stripe( 'POST', 'payment_intents', array(
				'amount'                 => (string) $ups[ $o ]['pence'],
				'currency'               => 'gbp',
				'customer'               => $cus,
				'payment_method'         => $pm,
				'off_session'            => 'true',
				'confirm'                => 'true',
				'description'            => trim( $event . ' · ' . $ups[ $o ]['label'], ' ·' ),
				'receipt_email'          => (string) get_post_meta( $bid, '_ewt_email', true ),
				'metadata[page]'         => (string) $post->ID,
				'metadata[booking]'      => (string) $bid,
				'metadata[kind]'         => 'upsell',
			) );
			if ( 'succeeded' !== ( $p['status'] ?? '' ) ) {
				return new WP_Error( 'ewt_up', 'Your bank wanted another check, so nothing was taken. You can buy on the night.', array( 'status' => 402 ) );
			}
			update_post_meta( $bid, '_ewt_extra', $ups[ $o ]['label'] . ' · ' . ew_tix_money( $ups[ $o ]['pence'] ) );
			update_post_meta( $bid, '_ewt_paid', (int) get_post_meta( $bid, '_ewt_paid', true ) + $ups[ $o ]['pence'] );
			ew_tix_crm( $block['fields']['crm_hook'] ?? '', $bid, 'upsell' );
			return array(
				'ok'    => true,
				'label' => $ups[ $o ]['label'],
				'price' => ew_tix_money( $ups[ $o ]['pence'] ),
			);
		},
	) );
} );
