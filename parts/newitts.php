<?php
/**
 * Bookings -> Newitts orders: every website shirt order goes to Newitts for next-day delivery to the club.
 *
 * Kitlocker makes the badged, pressed shirts but not quickly. Newitts sells
 * the same Nike Park 26 shirt blank and delivers next working day, so each
 * shirt sold online is reordered from Newitts the same afternoon and is at
 * Coronation Park the next working day.
 *
 * How a day runs (Monday to Friday):
 *
 *   14:40  The robot (tools/newitts-robot) asks this site for the batch:
 *          POST /eastwood/v1/newitts/batch. Every paid shirt order not yet
 *          sent to Newitts is locked into today's batch and returned as
 *          Newitts item codes and quantities.
 *          The robot checks Newitts' stock, fills the basket and, in Live
 *          mode only, checks out with Express delivery. It reports back:
 *          POST /eastwood/v1/newitts/result.
 *   14:50  If the robot has not reported, or reported a failure, or the
 *          mode is not Live, the list is emailed to orders@ so a person can
 *          place it by hand before Newitts' cut-off (3pm in winter,
 *          4pm in summer). The same email can go to Newitts directly once they
 *          agree to take emailed orders.
 *   Next   Staff mark the batch Arrived here when the parcel lands.
 *
 * Nothing orders itself by accident:
 *   - Mode starts at "Email only". The robot only presses Place order in
 *     "Live", and never for more than the daily shirt cap or the price cap.
 *   - Only sizes in the map below are sent to the robot. Anything else
 *     (women's fit, 3XL, a sold-out size) is listed in the email for a person.
 *   - Orders from before the start date are never sent.
 *
 * The site never sees the Newitts password or card: those live with the
 * robot, set by Ben.
 *
 * @package eastwood-fwp
 */

defined( 'ABSPATH' ) || exit;

/** Settings, with defaults. */
function ew_nw_opts() {
	return wp_parse_args( (array) get_option( 'ew_nw', array() ), array(
		'mode'     => 'email',            // email | basket | live
		'cap'      => 10,                 // most shirts in one day's order
		'max_each' => 20,                 // most £ a single shirt may cost at Newitts
		'to'       => 'orders@eastwoodfc.com',
		'supplier' => '',                 // Newitts' address, once they take emailed orders
		'since'    => '2026-11-05 00:00:00',
		'map'      => ew_nw_default_map(),
	) );
}

/** The robot's key, made once. */
function ew_nw_token() {
	$t = (string) get_option( 'ew_nw_token', '' );
	if ( strlen( $t ) < 32 ) {
		$t = wp_generate_password( 40, false, false );
		update_option( 'ew_nw_token', $t, false );
	}
	return $t;
}

/**
 * Website size -> Newitts item. One line each:
 *   shop sku | shop size | Newitts item code | product page | colour | size as Newitts names it
 * Item codes read off each product page's Availability tab, 5 Nov 2026.
 */
function ew_nw_default_map() {
	$a = 'nike-park-26-senior-short-sleeve-top';
	$k = 'nike-park-26-junior-short-sleeve-top';
	return implode( "\n", array(
		"home-shirt-2627 | ADULT S | IT109171 | $a | Red/White | Small",
		"home-shirt-2627 | ADULT M | IT109172 | $a | Red/White | Medium",
		"home-shirt-2627 | ADULT L | IT109173 | $a | Red/White | Large",
		"home-shirt-2627 | ADULT XL | IT109174 | $a | Red/White | X/Large",
		"home-shirt-2627 | ADULT XXL | IT109175 | $a | Red/White | XX/Large",
		"home-shirt-2627-kids | KIDS XS | IT109220 | $k | Red/White | XS/Kids",
		"home-shirt-2627-kids | KIDS S | IT109221 | $k | Red/White | S/Kids",
		"home-shirt-2627-kids | KIDS M | IT109222 | $k | Red/White | M/Kids",
		"home-shirt-2627-kids | KIDS L | IT109223 | $k | Red/White | L/Kids",
		"home-shirt-2627-kids | KIDS XL | IT109224 | $k | Red/White | XL/Kids",
	) );
}

/** The map as sku|size => row. */
function ew_nw_map() {
	$out = array();
	foreach ( preg_split( '/\r?\n/', (string) ew_nw_opts()['map'] ) as $line ) {
		$p = array_map( 'trim', explode( '|', $line ) );
		if ( count( $p ) < 6 || ! preg_match( '/^IT\d+$/', $p[2] ) ) {
			continue;
		}
		$out[ strtolower( $p[0] . '|' . $p[1] ) ] = array(
			'code' => $p[2], 'path' => ltrim( $p[3], '/' ), 'colour' => $p[4], 'size' => $p[5],
		);
	}
	return $out;
}

/** Which shirts a booking holds, as array( array( sku, size ) ). */
function ew_nw_shirts( $bid ) {
	if ( function_exists( 'ew_sq_booking_shirts' ) ) {
		return (array) ew_sq_booking_shirts( $bid );
	}
	$s = json_decode( (string) get_post_meta( $bid, '_ewt_shirts', true ), true );
	return is_array( $s ) ? $s : array();
}

/** Paid shirt orders not yet sent to Newitts. */
function ew_nw_pending() {
	$ids = get_posts( array(
		'post_type'      => 'ewt_booking',
		'post_status'    => 'any',
		'posts_per_page' => 200,
		'fields'         => 'ids',
		'orderby'        => 'date',
		'order'          => 'ASC',
		'date_query'     => array( array( 'after' => ew_nw_opts()['since'], 'inclusive' => true ) ),
		'meta_query'     => array(
			array( 'key' => '_ewt_status', 'value' => 'paid' ),
			array( 'key' => '_ewt_nw_state', 'compare' => 'NOT EXISTS' ),
		),
	) );
	return array_values( array_filter( $ids, function ( $id ) {
		return (bool) ew_nw_shirts( $id );
	} ) );
}

/** One line per booking for people to read. */
function ew_nw_who( $bid ) {
	$name = trim( (string) get_post_meta( $bid, '_ewt_name', true ) );
	return ( '' !== $name ? $name : (string) get_post_meta( $bid, '_ewt_email', true ) )
		. ' -- ' . trim( (string) get_post_meta( $bid, '_ewt_lines', true ) ) . ' (booking ' . $bid . ')';
}

/** Group bookings into Newitts lines, and the shirts Newitts can't supply. */
function ew_nw_lines( $bids ) {
	$map   = ew_nw_map();
	$lines = array();
	$hand  = array();
	foreach ( $bids as $bid ) {
		foreach ( ew_nw_shirts( $bid ) as $s ) {
			$key = strtolower( ( $s[0] ?? '' ) . '|' . ( $s[1] ?? '' ) );
			if ( empty( $map[ $key ] ) ) {
				$hand[] = array( 'booking' => (int) $bid, 'sku' => (string) ( $s[0] ?? '' ), 'size' => (string) ( $s[1] ?? '' ), 'why' => 'not on Newitts' );
				continue;
			}
			$m = $map[ $key ];
			if ( empty( $lines[ $m['code'] ] ) ) {
				$lines[ $m['code'] ] = $m + array( 'url' => 'https://www.newitts.com/' . $m['path'], 'qty' => 0, 'bookings' => array(), 'ours' => (string) $s[1] );
			}
			$lines[ $m['code'] ]['qty']++;
			$lines[ $m['code'] ]['bookings'][] = (int) $bid;
		}
	}
	return array( array_values( $lines ), $hand );
}

/** All batches, newest first. */
function ew_nw_batches() {
	$b = (array) get_option( 'ew_nw_batches', array() );
	krsort( $b );
	return $b;
}

function ew_nw_save_batch( $id, $batch ) {
	$all        = (array) get_option( 'ew_nw_batches', array() );
	$all[ $id ] = $batch;
	krsort( $all );
	update_option( 'ew_nw_batches', array_slice( $all, 0, 120, true ), false );
}

/** Set every booking in a batch to a state. */
function ew_nw_mark( $batch, $state, $order = '' ) {
	$ids = array();
	foreach ( (array) $batch['lines'] as $l ) {
		$ids = array_merge( $ids, (array) $l['bookings'] );
	}
	foreach ( array_unique( $ids ) as $bid ) {
		update_post_meta( $bid, '_ewt_nw_state', $state );
		if ( '' !== $order ) {
			update_post_meta( $bid, '_ewt_nw_order', $order );
		}
	}
}

/**
 * Lock today's batch. Called by the robot at 14:45 and by the 15:00 safety
 * net. Calling it again the same day returns the same batch plus anything
 * paid since, so a robot retry never orders a shirt twice.
 */
function ew_nw_cut() {
	$id    = wp_date( 'Y-m-d' );
	$all   = (array) get_option( 'ew_nw_batches', array() );
	$batch = $all[ $id ] ?? array( 'id' => $id, 'at' => time(), 'state' => 'queued', 'lines' => array(), 'hand' => array(), 'log' => array() );
	if ( in_array( $batch['state'], array( 'ordered', 'arrived' ), true ) ) {
		return $batch; // Today's is placed; anything later waits for tomorrow's.
	}
	$new = ew_nw_pending();
	if ( $new ) {
		list( $lines, $hand ) = ew_nw_lines( $new );
		foreach ( $lines as $l ) {
			$found = false;
			foreach ( $batch['lines'] as &$have ) {
				if ( $have['code'] === $l['code'] ) {
					$have['qty']     += $l['qty'];
					$have['bookings'] = array_merge( $have['bookings'], $l['bookings'] );
					$found            = true;
				}
			}
			unset( $have );
			if ( ! $found ) {
				$batch['lines'][] = $l;
			}
		}
		$batch['hand'] = array_merge( $batch['hand'], $hand );
		foreach ( $new as $bid ) {
			update_post_meta( $bid, '_ewt_nw_state', 'queued' );
			update_post_meta( $bid, '_ewt_nw_batch', $id );
		}
		foreach ( $hand as $h ) {
			update_post_meta( $h['booking'], '_ewt_nw_state', 'by hand' );
		}
	}
	ew_nw_save_batch( $id, $batch );
	return $batch;
}

function ew_nw_qty( $batch ) {
	return array_sum( array_column( (array) $batch['lines'], 'qty' ) );
}

/** The batch as text: for orders@, and for Newitts once they take emailed orders. */
function ew_nw_text( $batch, $why = '' ) {
	$o   = array();
	$o[] = 'Newitts order for ' . wp_date( 'l j F', strtotime( $batch['id'] . ' 12:00' ) );
	if ( '' !== $why ) {
		$o[] = '';
		$o[] = $why;
	}
	if ( $batch['lines'] ) {
		$o[] = '';
		$o[] = 'Order on newitts.com with Express (next working day) delivery to Coronation Park. Newitts\' cut-off is ' . ( '1' === wp_date( 'I' ) ? '4pm' : '3pm' ) . ' today:';
		foreach ( $batch['lines'] as $l ) {
			$stock = isset( $l['stock'] ) ? ' [Newitts stock ' . (int) $l['stock'] . ']' : '';
			$o[]   = '  ' . $l['qty'] . ' x ' . $l['code'] . '  ' . $l['colour'] . ' ' . $l['size'] . $stock . '  ' . $l['url'];
		}
		$o[] = '';
		$o[] = 'For:';
		foreach ( $batch['lines'] as $l ) {
			foreach ( array_unique( $l['bookings'] ) as $bid ) {
				$o[] = '  ' . ew_nw_who( $bid );
			}
		}
	}
	if ( $batch['hand'] ) {
		$o[] = '';
		$o[] = 'Newitts can\'t supply these. They need shelf stock or Kitlocker:';
		foreach ( $batch['hand'] as $h ) {
			$o[] = '  ' . $h['size'] . ' (' . $h['why'] . ') -- ' . ew_nw_who( $h['booking'] );
		}
	}
	$o[] = '';
	$o[] = 'Once it is placed, put the Newitts order number in: ' . admin_url( 'edit.php?post_type=ewt_booking&page=ew-newitts' );
	return implode( "\n", $o );
}

/** Email the batch to orders@ so a person can place it. */
function ew_nw_email( $batch, $why ) {
	if ( ! $batch['lines'] && ! $batch['hand'] ) {
		return false;
	}
	$o  = ew_nw_opts();
	$ok = wp_mail(
		$o['to'],
		'Newitts order to place today: ' . ew_nw_qty( $batch ) . ' shirt' . ( 1 === ew_nw_qty( $batch ) ? '' : 's' ),
		ew_nw_text( $batch, $why ),
		array( 'From: Eastwood FC <orders@eastwoodfc.com>' )
	);
	$batch['emailed'] = time();
	$batch['log'][]   = wp_date( 'H:i' ) . ' emailed ' . $o['to'] . ( $ok ? '' : ' (FAILED)' );
	ew_nw_save_batch( $batch['id'], $batch );
	return $ok;
}

/* --- The robot's two calls ------------------------------------------------ */

function ew_nw_robot_ok( $req ) {
	return hash_equals( ew_nw_token(), (string) $req->get_header( 'x-ew-robot' ) );
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'eastwood/v1', '/newitts/batch', array(
		'methods'             => 'POST',
		'permission_callback' => 'ew_nw_robot_ok',
		'callback'            => function () {
			$o     = ew_nw_opts();
			$batch = ew_nw_cut();
			$batch['log'][] = wp_date( 'H:i' ) . ' robot collected the batch';
			ew_nw_save_batch( $batch['id'], $batch );
			$r = new WP_REST_Response( array(
				'id'       => $batch['id'],
				'state'    => $batch['state'],
				'mode'     => $o['mode'],
				'cap'      => (int) $o['cap'],
				'max_each' => (float) $o['max_each'],
				'lines'    => array_map( function ( $l ) {
					return array_intersect_key( $l, array_flip( array( 'code', 'url', 'colour', 'size', 'qty' ) ) );
				}, 'queued' === $batch['state'] || 'failed' === $batch['state'] ? $batch['lines'] : array() ),
			) );
			$r->header( 'Cache-Control', 'no-store' );
			return $r;
		},
	) );

	register_rest_route( 'eastwood/v1', '/newitts/result', array(
		'methods'             => 'POST',
		'permission_callback' => 'ew_nw_robot_ok',
		'callback'            => function ( $req ) {
			$id    = (string) $req->get_param( 'id' );
			$all   = (array) get_option( 'ew_nw_batches', array() );
			if ( empty( $all[ $id ] ) ) {
				return new WP_Error( 'ew_nw', 'No such batch.', array( 'status' => 404 ) );
			}
			$batch = $all[ $id ];
			$state = (string) $req->get_param( 'state' ); // ordered | basket | failed
			$note  = sanitize_text_field( (string) $req->get_param( 'note' ) );
			$order = sanitize_text_field( (string) $req->get_param( 'order' ) );
			$total = sanitize_text_field( (string) $req->get_param( 'total' ) );
			foreach ( (array) $req->get_param( 'stock' ) as $code => $n ) {
				foreach ( $batch['lines'] as &$l ) {
					if ( $l['code'] === $code ) {
						$l['stock'] = (int) $n;
					}
				}
				unset( $l );
			}
			$batch['robot'] = compact( 'state', 'note', 'order', 'total' ) + array( 'at' => time() );
			$batch['log'][] = wp_date( 'H:i' ) . ' robot: ' . $state . ( '' !== $order ? ' #' . $order : '' ) . ( '' !== $total ? ' ' . $total : '' ) . ( '' !== $note ? ' -- ' . $note : '' );

			if ( 'ordered' === $state && '' !== $order && 'live' === ew_nw_opts()['mode'] ) {
				$batch['state'] = 'ordered';
				$batch['order'] = $order;
				ew_nw_save_batch( $id, $batch );
				ew_nw_mark( $batch, 'ordered', $order );
				if ( $batch['hand'] ) {
					ew_nw_email( $batch, 'The robot ordered the shirts Newitts has (order ' . $order . ', ' . $total . '). The ones at the bottom still need a person.' );
				}
				return array( 'ok' => true );
			}
			$batch['state'] = 'basket' === $state ? 'in basket' : 'failed';
			ew_nw_save_batch( $id, $batch );
			ew_nw_email( $batch, 'basket' === $state
				? 'The robot has put these in the Newitts basket (' . $total . '). Sign in to newitts.com, check the basket and pay with Express delivery.' . ( '' !== $note ? ' Robot says: ' . $note : '' )
				: 'THE ROBOT COULD NOT PLACE THIS ORDER: ' . ( '' !== $note ? $note : 'no reason given' ) . '. Please place it by hand.' );
			return array( 'ok' => true );
		},
	) );
} );

/* --- The 15:00 safety net -------------------------------------------------- */

add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'ew_nw_watch' ) ) {
		wp_schedule_event( time() + 300, 'ew_nw_five', 'ew_nw_watch' );
	}
} );

add_filter( 'cron_schedules', function ( $s ) {
	$s['ew_nw_five'] = array( 'interval' => 300, 'display' => 'Every 5 minutes' );
	return $s;
} );

/**
 * Weekdays from 14:50: if the robot hasn't reported today and there are
 * shirts waiting, email the list. Once a day only. Newitts' Express cut-off
 * is 15:00 UK time in winter (their site gives 15:00 UTC, so 16:00 in summer).
 */
add_action( 'ew_nw_watch', function () {
	$now = (int) wp_date( 'Hi' );
	if ( (int) wp_date( 'N' ) > 5 || $now < 1450 || $now > 1630 ) {
		return;
	}
	$all   = (array) get_option( 'ew_nw_batches', array() );
	$today = $all[ wp_date( 'Y-m-d' ) ] ?? null;
	if ( $today && ( ! empty( $today['robot'] ) || ! empty( $today['emailed'] ) ) ) {
		return;
	}
	if ( ! ew_nw_pending() && ! $today ) {
		return;
	}
	$batch = ew_nw_cut();
	ew_nw_email( $batch, 'email' === ew_nw_opts()['mode']
		? 'The robot is switched off (Email only), so this needs placing by hand.'
		: 'THE ROBOT DID NOT RUN TODAY. Please place this by hand.' );
} );

/* --- Admin: Bookings -> Newitts orders -------------------------------------- */

add_action( 'admin_menu', function () {
	add_submenu_page( 'edit.php?post_type=ewt_booking', 'Newitts orders', 'Newitts orders', 'edit_posts', 'ew-newitts', 'ew_nw_page' );
}, 22 );

add_action( 'admin_post_ew_nw', function () {
	if ( ! current_user_can( 'edit_posts' ) || ! check_admin_referer( 'ew_nw' ) ) {
		wp_die( 'No.' );
	}
	$do  = sanitize_key( $_POST['do'] ?? '' );
	$id  = sanitize_text_field( wp_unslash( $_POST['batch'] ?? '' ) );
	$all = (array) get_option( 'ew_nw_batches', array() );
	$msg = '';
	if ( 'settings' === $do && current_user_can( 'manage_options' ) ) {
		$o = ew_nw_opts();
		update_option( 'ew_nw', array(
			'mode'     => in_array( $_POST['mode'] ?? '', array( 'email', 'basket', 'live' ), true ) ? $_POST['mode'] : 'email',
			'cap'      => max( 1, (int) ( $_POST['cap'] ?? 10 ) ),
			'max_each' => max( 1, (float) ( $_POST['max_each'] ?? 20 ) ),
			'to'       => sanitize_text_field( wp_unslash( $_POST['to'] ?? $o['to'] ) ),
			'supplier' => sanitize_email( wp_unslash( $_POST['supplier'] ?? '' ) ),
			'since'    => preg_match( '/^\d{4}-\d\d-\d\d( \d\d:\d\d(:\d\d)?)?$/', (string) ( $_POST['since'] ?? '' ) ) ? $_POST['since'] : $o['since'],
			'map'      => sanitize_textarea_field( wp_unslash( $_POST['map'] ?? '' ) ),
		), false );
		$msg = 'Saved.';
	} elseif ( 'send' === $do ) {
		$batch = ew_nw_cut();
		$msg   = ew_nw_email( $batch, 'Sent by hand from the Newitts orders screen.' ) ? 'Emailed.' : 'Nothing to send.';
	} elseif ( 'ordered' === $do && ! empty( $all[ $id ] ) ) {
		$order               = sanitize_text_field( wp_unslash( $_POST['order'] ?? '' ) );
		$all[ $id ]['state'] = 'ordered';
		$all[ $id ]['order'] = $order;
		$all[ $id ]['log'][] = wp_date( 'H:i' ) . ' marked ordered by ' . wp_get_current_user()->display_name . ( '' !== $order ? ' #' . $order : '' );
		ew_nw_save_batch( $id, $all[ $id ] );
		ew_nw_mark( $all[ $id ], 'ordered', $order );
		$msg = 'Marked ordered.';
	} elseif ( 'arrived' === $do && ! empty( $all[ $id ] ) ) {
		$all[ $id ]['state'] = 'arrived';
		$all[ $id ]['log'][] = wp_date( 'D H:i' ) . ' arrived, booked in by ' . wp_get_current_user()->display_name;
		ew_nw_save_batch( $id, $all[ $id ] );
		ew_nw_mark( $all[ $id ], 'arrived' );
		$msg = 'Marked arrived.';
	}
	wp_safe_redirect( add_query_arg( 'msg', rawurlencode( $msg ), admin_url( 'edit.php?post_type=ewt_booking&page=ew-newitts' ) ) );
	exit;
} );

function ew_nw_form( $do, $label, $extra = '', $batch = '' ) {
	$h  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 8px 0 0">';
	$h .= wp_nonce_field( 'ew_nw', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="ew_nw"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
	$h .= '<input type="hidden" name="batch" value="' . esc_attr( $batch ) . '">' . $extra . ' <button class="button">' . esc_html( $label ) . '</button></form>';
	return $h;
}

function ew_nw_page() {
	$o = ew_nw_opts();
	echo '<div class="wrap"><h1>Newitts orders</h1>';
	if ( ! empty( $_GET['msg'] ) ) {
		echo '<div class="notice notice-success"><p>' . esc_html( wp_unslash( $_GET['msg'] ) ) . '</p></div>';
	}
	$modes = array( 'email' => 'Email only: the list goes to ' . $o['to'] . ' at 2:50pm', 'basket' => 'Robot fills the basket, a person pays', 'live' => 'Robot orders and pays' );
	echo '<p>Website shirt orders are reordered from Newitts each weekday for next-day delivery to the club. Mode: <strong>' . esc_html( $modes[ $o['mode'] ] ?? $o['mode'] ) . '</strong>.</p>';

	list( $lines, $hand ) = ew_nw_lines( ew_nw_pending() );
	echo '<h2>Waiting for the next order</h2>';
	if ( ! $lines && ! $hand ) {
		echo '<p>Nothing waiting.</p>';
	} else {
		echo '<pre style="background:#fff;padding:12px;border:1px solid #ddd;white-space:pre-wrap">' . esc_html( ew_nw_text( array( 'id' => wp_date( 'Y-m-d' ), 'lines' => $lines, 'hand' => $hand ) ) ) . '</pre>';
		echo ew_nw_form( 'send', 'Email this list now' ); // phpcs:ignore
	}

	echo '<h2>Orders</h2><table class="widefat striped"><thead><tr><th>Day</th><th>Shirts</th><th>State</th><th>Newitts order</th><th>Log</th><th></th></tr></thead><tbody>';
	$any = false;
	foreach ( ew_nw_batches() as $id => $b ) {
		$any  = true;
		$acts = '';
		if ( ! in_array( $b['state'], array( 'ordered', 'arrived' ), true ) ) {
			$acts .= ew_nw_form( 'ordered', 'Mark ordered', '<input name="order" placeholder="Newitts order no." size="12">', $id );
		}
		if ( 'ordered' === $b['state'] ) {
			$acts .= ew_nw_form( 'arrived', 'Mark arrived', '', $id );
		}
		echo '<tr><td>' . esc_html( wp_date( 'D j M', strtotime( $id . ' 12:00' ) ) ) . '</td><td>' . (int) ew_nw_qty( $b ) . ( $b['hand'] ? ' + ' . count( $b['hand'] ) . ' by hand' : '' ) . '</td><td>' . esc_html( $b['state'] ) . '</td><td>' . esc_html( $b['order'] ?? '' ) . '</td><td style="font-size:12px">' . nl2br( esc_html( implode( "\n", (array) $b['log'] ) ) ) . '</td><td>' . $acts . '</td></tr>'; // phpcs:ignore
	}
	echo $any ? '' : '<tr><td colspan="6">None yet.</td></tr>';
	echo '</tbody></table>';

	if ( current_user_can( 'manage_options' ) ) {
		echo '<h2>Settings</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'ew_nw' );
		echo '<input type="hidden" name="action" value="ew_nw"><input type="hidden" name="do" value="settings"><table class="form-table">';
		echo '<tr><th>Mode</th><td>';
		foreach ( $modes as $k => $label ) {
			echo '<label style="display:block"><input type="radio" name="mode" value="' . esc_attr( $k ) . '"' . checked( $o['mode'], $k, false ) . '> ' . esc_html( $label ) . '</label>';
		}
		echo '</td></tr>';
		echo '<tr><th>Most shirts in a day</th><td><input name="cap" type="number" value="' . (int) $o['cap'] . '"> <span class="description">Over this, the robot stops and the list is emailed.</span></td></tr>';
		echo '<tr><th>Most a shirt may cost (£)</th><td><input name="max_each" type="number" step="0.01" value="' . esc_attr( $o['max_each'] ) . '"> <span class="description">If Newitts\' price is higher, the robot stops.</span></td></tr>';
		echo '<tr><th>Email the list to</th><td><input name="to" class="regular-text" value="' . esc_attr( $o['to'] ) . '"></td></tr>';
		echo '<tr><th>Newitts\' order address</th><td><input name="supplier" class="regular-text" value="' . esc_attr( $o['supplier'] ) . '"> <span class="description">Blank until Newitts agree to take emailed orders.</span></td></tr>';
		echo '<tr><th>Orders from</th><td><input name="since" value="' . esc_attr( $o['since'] ) . '"> <span class="description">Orders paid before this are never sent.</span></td></tr>';
		echo '<tr><th>Sizes</th><td><textarea name="map" rows="12" class="large-text code">' . esc_textarea( $o['map'] ) . '</textarea><p class="description">shop sku | shop size | Newitts item code | product page | colour | Newitts size. A size not listed is never sent to the robot.</p></td></tr>';
		echo '<tr><th>Robot key</th><td><code>' . esc_html( ew_nw_token() ) . '</code><p class="description">Goes in the robot\'s settings as EW_ROBOT_KEY.</p></td></tr>';
		echo '</table><p><button class="button button-primary">Save</button></p></form>';
	}
	echo '</div>';
}
