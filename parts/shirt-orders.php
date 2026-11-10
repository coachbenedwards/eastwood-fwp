<?php
/**
 * Bookings -> Shirt orders: every paid shirt order, sorted by what still needs doing.
 *
 *   To print    the shirt has a name or number to press on it
 *   To post     printed (or plain), going in the post: the address is shown
 *   To collect  printed (or plain), picked up at the ground
 *   Done        posted or collected
 *
 * Staff move an order along with one button. The same list is served to the
 * staff app at /eastwood/v1/shirt-orders. Because it holds people's home
 * addresses, the app call is only answered for a signed-in staff member whose
 * app group allows shirt orders (owner or admin). The website checks that with
 * the app's own database, using the person's own app sign-in, so no key is
 * stored here.
 *
 * @package eastwood-fwp
 */

defined( 'ABSPATH' ) || exit;

const EW_SO_SUPABASE  = 'https://gimtavaqkkowjwffeefm.supabase.co';
const EW_SO_ROLES     = array( 'owner', 'admin' );
const EW_SO_OWNERS    = array( 'ben@benedwards.com', 'ben@eastwoodfc.com' );
const EW_SO_SINCE     = '2026-10-01 00:00:00';

/** The shirts on a booking, as array( array( sku, size ) ). */
function ew_so_shirts( $bid ) {
	$s = json_decode( (string) get_post_meta( $bid, '_ewt_shirts', true ), true );
	return is_array( $s ) ? $s : array();
}

/** True when the booking is a shirt order. */
function ew_so_is_shirt( $bid ) {
	if ( ew_so_shirts( $bid ) ) {
		return true;
	}
	if ( '' !== (string) get_post_meta( $bid, '_ewt_delivery', true ) || '' !== (string) get_post_meta( $bid, '_ewt_printing', true ) ) {
		return true;
	}
	return (bool) preg_match( '/\bshirt\b/i', (string) get_post_meta( $bid, '_ewt_lines', true ) );
}

/** True when the order has a name or number to press. */
function ew_so_needs_print( $printing ) {
	$p = trim( (string) $printing );
	return '' !== $p && ! preg_match( '/^(none|no|blank|plain|-|n\/a)$/i', $p );
}

/** True when the order goes in the post. */
function ew_so_is_post( $bid ) {
	if ( 'post' === (string) get_post_meta( $bid, '_ewt_delivery', true ) ) {
		return true;
	}
	return false !== stripos( (string) get_post_meta( $bid, '_ewt_lines', true ), 'posted' );
}

/** Where an order is up to: print, post, collect or done. */
function ew_so_stage( $bid ) {
	if ( '' !== (string) get_post_meta( $bid, '_ewt_so_done', true ) ) {
		return 'done';
	}
	if ( ew_so_needs_print( get_post_meta( $bid, '_ewt_printing', true ) ) && '' === (string) get_post_meta( $bid, '_ewt_so_printed', true ) ) {
		return 'print';
	}
	return ew_so_is_post( $bid ) ? 'post' : 'collect';
}

/** One order, as the admin page and the app see it. */
function ew_so_row( $bid ) {
	$post    = ew_so_is_post( $bid );
	$printed = (string) get_post_meta( $bid, '_ewt_so_printed', true );
	$done    = (string) get_post_meta( $bid, '_ewt_so_done', true );
	return array(
		'id'        => (int) $bid,
		'date'      => get_the_date( 'c', $bid ),
		'name'      => trim( (string) get_post_meta( $bid, '_ewt_name', true ) ),
		'email'     => (string) get_post_meta( $bid, '_ewt_email', true ),
		'phone'     => (string) get_post_meta( $bid, '_ewt_phone', true ),
		'lines'     => trim( (string) get_post_meta( $bid, '_ewt_lines', true ) ),
		'shirts'    => array_map( function ( $s ) {
			return array( 'sku' => (string) ( $s[0] ?? '' ), 'size' => (string) ( $s[1] ?? '' ) );
		}, ew_so_shirts( $bid ) ),
		'printing'  => trim( (string) get_post_meta( $bid, '_ewt_printing', true ) ),
		'post'      => $post,
		'address'   => $post ? (string) get_post_meta( $bid, '_ewt_address', true ) : '',
		'paid'      => (int) get_post_meta( $bid, '_ewt_paid', true ),
		'stage'     => ew_so_stage( $bid ),
		'printedAt' => $printed ? explode( '|', $printed )[0] : '',
		'printedBy' => $printed ? (string) ( explode( '|', $printed )[1] ?? '' ) : '',
		'doneAt'    => $done ? explode( '|', $done )[0] : '',
		'doneBy'    => $done ? (string) ( explode( '|', $done )[1] ?? '' ) : '',
	);
}

/** Every paid shirt order, newest first. */
function ew_so_orders() {
	$ids = get_posts( array(
		'post_type'      => 'ewt_booking',
		'post_status'    => 'any',
		'posts_per_page' => 400,
		'fields'         => 'ids',
		'orderby'        => 'date',
		'order'          => 'DESC',
		'date_query'     => array( array( 'after' => EW_SO_SINCE, 'inclusive' => true ) ),
		'meta_query'     => array( array( 'key' => '_ewt_status', 'value' => 'paid' ) ),
	) );
	return array_values( array_filter( $ids, 'ew_so_is_shirt' ) );
}

/** Move an order on, or back. $step: printed | done | undo. */
function ew_so_mark( $bid, $step, $who ) {
	$bid = (int) $bid;
	if ( 'ewt_booking' !== get_post_type( $bid ) || ! ew_so_is_shirt( $bid ) ) {
		return new WP_Error( 'ew_so_missing', 'That order was not found.' );
	}
	$stamp = current_time( 'c' ) . '|' . sanitize_text_field( (string) $who );
	if ( 'printed' === $step ) {
		update_post_meta( $bid, '_ewt_so_printed', $stamp );
	} elseif ( 'done' === $step ) {
		update_post_meta( $bid, '_ewt_so_done', $stamp );
	} elseif ( 'undo' === $step ) {
		if ( '' !== (string) get_post_meta( $bid, '_ewt_so_done', true ) ) {
			delete_post_meta( $bid, '_ewt_so_done' );
		} else {
			delete_post_meta( $bid, '_ewt_so_printed' );
		}
	} else {
		return new WP_Error( 'ew_so_step', 'Unknown step.' );
	}
	return ew_so_row( $bid );
}

/* ---------- the admin page ---------- */

add_action( 'admin_menu', function () {
	add_submenu_page( 'edit.php?post_type=ewt_booking', 'Shirt orders', 'Shirt orders', 'edit_posts', 'ew-shirt-orders', 'ew_so_page' );
} );

add_action( 'admin_post_ew_so', function () {
	if ( ! current_user_can( 'edit_posts' ) || ! check_admin_referer( 'ew_so' ) ) {
		wp_die( 'Not allowed.' );
	}
	$tab  = sanitize_key( wp_unslash( $_POST['tab'] ?? 'print' ) );
	$user = wp_get_current_user();
	$res  = ew_so_mark( (int) ( $_POST['id'] ?? 0 ), sanitize_key( wp_unslash( $_POST['step'] ?? '' ) ), $user->display_name );
	$msg  = is_wp_error( $res ) ? $res->get_error_message() : 'Order ' . (int) $_POST['id'] . ' updated.';
	wp_safe_redirect( add_query_arg( array( 'post_type' => 'ewt_booking', 'page' => 'ew-shirt-orders', 'tab' => $tab, 'msg' => rawurlencode( $msg ) ), admin_url( 'edit.php' ) ) );
	exit;
} );

function ew_so_button( $id, $step, $label, $tab, $primary = true ) {
	return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 0 0 6px">'
		. wp_nonce_field( 'ew_so', '_wpnonce', true, false )
		. '<input type="hidden" name="action" value="ew_so"><input type="hidden" name="id" value="' . (int) $id . '">'
		. '<input type="hidden" name="step" value="' . esc_attr( $step ) . '"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">'
		. '<button class="button' . ( $primary ? ' button-primary' : '' ) . '">' . esc_html( $label ) . '</button></form>';
}

function ew_so_page() {
	$tabs  = array( 'print' => 'To print', 'post' => 'To post', 'collect' => 'To collect', 'done' => 'Done' );
	$tab   = sanitize_key( wp_unslash( $_GET['tab'] ?? 'print' ) );
	$tab   = isset( $tabs[ $tab ] ) ? $tab : 'print';
	$rows  = array_map( 'ew_so_row', ew_so_orders() );
	$count = array_fill_keys( array_keys( $tabs ), 0 );
	foreach ( $rows as $r ) {
		$count[ $r['stage'] ]++;
	}
	echo '<div class="wrap"><h1>Shirt orders</h1>';
	if ( ! empty( $_GET['msg'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( wp_unslash( $_GET['msg'] ) ) . '</p></div>';
	}
	echo '<nav class="nav-tab-wrapper">';
	foreach ( $tabs as $k => $label ) {
		$url = add_query_arg( array( 'post_type' => 'ewt_booking', 'page' => 'ew-shirt-orders', 'tab' => $k ), admin_url( 'edit.php' ) );
		echo '<a class="nav-tab' . ( $k === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . ' <span class="count">(' . (int) $count[ $k ] . ')</span></a>';
	}
	echo '</nav>';
	$list = array_values( array_filter( $rows, function ( $r ) use ( $tab ) {
		return $r['stage'] === $tab;
	} ) );
	if ( ! $list ) {
		echo '<p style="margin-top:16px">Nothing here.</p></div>';
		return;
	}
	echo '<table class="widefat striped" style="margin-top:12px"><thead><tr><th>Order</th><th>Name</th><th>Shirt</th><th>Printing</th><th>Delivery</th><th>Paid</th><th></th></tr></thead><tbody>';
	foreach ( $list as $r ) {
		$shirt = $r['shirts'] ? implode( '<br>', array_map( function ( $s ) {
			return esc_html( trim( $s['sku'] . ' ' . $s['size'] ) );
		}, $r['shirts'] ) ) : esc_html( $r['lines'] );
		$deliv = $r['post']
			? '<strong>Post</strong><br>' . ( '' !== $r['address']
				? nl2br( esc_html( $r['address'] ) ) . '<br><button type="button" class="button-link" onclick="navigator.clipboard.writeText(this.dataset.a);this.textContent=\'Copied\'" data-a="' . esc_attr( $r['address'] ) . '">Copy address</button>'
				: '<span style="color:#b32d2e">No address. Check the order in Stripe.</span>' )
			: 'Collect at the ground';
		$who = esc_html( $r['name'] ?: $r['email'] ) . '<br><span style="color:#646970">' . esc_html( $r['email'] ) . ( $r['phone'] ? '<br>' . esc_html( $r['phone'] ) : '' ) . '</span>';
		$btn = '';
		if ( 'print' === $r['stage'] ) {
			$btn = ew_so_button( $r['id'], 'printed', 'Printed', $tab );
		} elseif ( 'post' === $r['stage'] ) {
			$btn = ew_so_button( $r['id'], 'done', 'Posted', $tab ) . ( $r['printedAt'] ? ew_so_button( $r['id'], 'undo', 'Undo printed', $tab, false ) : '' );
		} elseif ( 'collect' === $r['stage'] ) {
			$btn = ew_so_button( $r['id'], 'done', 'Collected', $tab ) . ( $r['printedAt'] ? ew_so_button( $r['id'], 'undo', 'Undo printed', $tab, false ) : '' );
		} else {
			$btn = '<span style="color:#646970">' . esc_html( ( $r['post'] ? 'Posted ' : 'Collected ' ) . wp_date( 'j M', strtotime( $r['doneAt'] ) ) . ( $r['doneBy'] ? ' by ' . $r['doneBy'] : '' ) ) . '</span>' . ew_so_button( $r['id'], 'undo', 'Undo', $tab, false );
		}
		echo '<tr><td><a href="' . esc_url( get_edit_post_link( $r['id'] ) ) . '">' . (int) $r['id'] . '</a><br><span style="color:#646970">' . esc_html( wp_date( 'j M', strtotime( $r['date'] ) ) ) . '</span></td>'
			. '<td>' . $who . '</td><td>' . $shirt . '</td>'
			. '<td>' . ( ew_so_needs_print( $r['printing'] ) ? '<strong>' . esc_html( $r['printing'] ) . '</strong>' : '<span style="color:#646970">None</span>' ) . '</td>'
			. '<td>' . $deliv . '</td><td>£' . esc_html( number_format( $r['paid'] / 100, 2 ) ) . '</td><td style="text-align:right;white-space:nowrap">' . $btn . '</td></tr>';
	}
	echo '</tbody></table></div>';
}

/* ---------- the staff app ---------- */

/**
 * Allowed: a website admin, or a staff app user in an allowed group.
 * The app sends its own sign-in as "Authorization: Bearer <token>" and the
 * app's public key as "apikey". The app's database answers who they are.
 */
function ew_so_rest_ok( WP_REST_Request $r ) {
	if ( current_user_can( 'edit_posts' ) ) {
		$GLOBALS['ew_so_who'] = wp_get_current_user()->display_name;
		return true;
	}
	$auth = (string) $r->get_header( 'authorization' );
	$key  = (string) $r->get_header( 'apikey' );
	if ( ! preg_match( '/^Bearer\s+([A-Za-z0-9._-]+)$/', $auth, $m ) || '' === $key ) {
		return false;
	}
	$token = $m[1];
	$cache = 'ew_so_ok_' . md5( $token );
	$hit   = get_transient( $cache );
	if ( is_array( $hit ) ) {
		$GLOBALS['ew_so_who'] = $hit['who'];
		return (bool) $hit['ok'];
	}
	$res = wp_remote_post( EW_SO_SUPABASE . '/rest/v1/rpc/my_eastwood_role', array(
		'timeout' => 8,
		'headers' => array( 'apikey' => $key, 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ),
		'body'    => '{}',
	) );
	if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		return false;
	}
	$role  = json_decode( wp_remote_retrieve_body( $res ), true );
	$parts = explode( '.', $token );
	$claim = isset( $parts[1] ) ? json_decode( base64_decode( strtr( $parts[1], '-_', '+/' ) ), true ) : array();
	$email = strtolower( trim( (string) ( $claim['email'] ?? '' ) ) );
	$ok    = in_array( $role, EW_SO_ROLES, true ) || in_array( $email, EW_SO_OWNERS, true );
	$who   = '' !== $email ? $email : 'app';
	set_transient( $cache, array( 'ok' => $ok, 'who' => $who ), 5 * MINUTE_IN_SECONDS );
	$GLOBALS['ew_so_who'] = $who;
	return $ok;
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'eastwood/v1', '/shirt-orders', array(
		'methods'             => 'GET',
		'permission_callback' => 'ew_so_rest_ok',
		'callback'            => function () {
			$rows   = array_map( 'ew_so_row', ew_so_orders() );
			$counts = array( 'print' => 0, 'post' => 0, 'collect' => 0, 'done' => 0 );
			foreach ( $rows as $r ) {
				$counts[ $r['stage'] ]++;
			}
			return array( 'orders' => $rows, 'counts' => $counts );
		},
	) );
	register_rest_route( 'eastwood/v1', '/shirt-orders/mark', array(
		'methods'             => 'POST',
		'permission_callback' => 'ew_so_rest_ok',
		'callback'            => function ( WP_REST_Request $r ) {
			$b   = (array) $r->get_json_params();
			$res = ew_so_mark( (int) ( $b['id'] ?? 0 ), sanitize_key( (string) ( $b['step'] ?? '' ) ), (string) ( $GLOBALS['ew_so_who'] ?? 'app' ) );
			return is_wp_error( $res ) ? new WP_REST_Response( array( 'error' => $res->get_error_message() ), 400 ) : $res;
		},
	) );
} );
