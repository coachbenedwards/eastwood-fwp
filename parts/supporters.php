<?php
/**
 * Supporters: every MyEastwood account in one wp-admin screen.
 *
 * The accounts live in the fan app Supabase project (auth users plus the
 * public.supporters table that holds each one's EFC- number). This page reads
 * them server side with the service key the School runs screen already holds
 * (ew_runs_key()); the key never reaches the browser.
 *
 * Numbers: EFC-100001..EFC-101000 are kept back for staff to hand out (old
 * season ticket numbers), new accounts are numbered from EFC-101001 by the
 * database. Every change of number is logged in supporter_number_changes.
 *
 * Four levels, as capabilities given per WordPress user on the Staff access
 * tab (only Super admin can change them; administrators are Super admin):
 *   ew_sup_super    everything, staff access, export
 *   ew_sup_member   view, edit names, hand out reserved numbers, export
 *   ew_sup_box      search and view a supporter and their tickets
 *   ew_sup_reports  totals only, no personal details
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EW_SUP_RESERVED_FROM = 100001;
const EW_SUP_RESERVED_TO   = 101000;

function ew_sup_levels() {
	return array(
		'ew_sup_super'   => 'Super admin',
		'ew_sup_member'  => 'Membership admin',
		'ew_sup_box'     => 'Matchday / box office',
		'ew_sup_reports' => 'Reports',
	);
}

/** Administrators hold Super admin without being given it. */
add_filter( 'user_has_cap', function ( $all ) {
	if ( ! empty( $all['manage_options'] ) ) {
		$all['ew_sup_super'] = true;
	}
	return $all;
} );

/** The strongest level a user holds, or '' for none. */
function ew_sup_level( $user_id = 0 ) {
	$u = $user_id ? get_userdata( $user_id ) : wp_get_current_user();
	if ( ! $u || ! $u->exists() ) {
		return '';
	}
	foreach ( array_keys( ew_sup_levels() ) as $cap ) {
		if ( user_can( $u, $cap ) ) {
			return $cap;
		}
	}
	return '';
}

function ew_sup_can( $what ) {
	$l     = ew_sup_level();
	$rules = array(
		'access' => array( 'ew_sup_super' ),
		'export' => array( 'ew_sup_super', 'ew_sup_member' ),
		'edit'   => array( 'ew_sup_super', 'ew_sup_member' ),
		'view'   => array( 'ew_sup_super', 'ew_sup_member', 'ew_sup_box' ),
		'totals' => array( 'ew_sup_super', 'ew_sup_member', 'ew_sup_box', 'ew_sup_reports' ),
	);
	return '' !== $l && in_array( $l, $rules[ $what ] ?? array(), true );
}

function ew_sup_fmt( $n ) {
	return $n ? 'EFC-' . (int) $n : '';
}

/** "EFC-101005", "efc101005" or "101005" -> 101005; anything else 0. */
function ew_sup_parse_number( $s ) {
	$d = preg_replace( '/[^0-9]/', '', (string) $s );
	return ( '' !== $d && strlen( $d ) <= 6 ) ? (int) $d : 0;
}

/* ------------------------------------------------------------------ data */

/**
 * Every account, joined to its number, linked emails, bookings and last
 * scan. Kept for a minute so paging and searching stay quick.
 */
function ew_sup_all( $fresh = false ) {
	if ( ! $fresh ) {
		$hit = get_transient( 'ew_sup_all' );
		if ( is_array( $hit ) ) {
			return $hit;
		}
	}
	if ( '' === ew_runs_key() ) {
		return array( 'error' => 'The Supabase service key is not saved yet (Settings, School runs).', 'rows' => array() );
	}
	$users = array();
	for ( $page = 1; $page <= 20; $page++ ) {
		list( $c, $b ) = ew_runs_sb( 'GET', '/auth/v1/admin/users?page=' . $page . '&per_page=1000' );
		if ( 200 !== $c ) {
			return array( 'error' => 'Could not read the accounts (' . (int) $c . ').', 'rows' => array() );
		}
		$batch = (array) ( $b['users'] ?? array() );
		$users = array_merge( $users, $batch );
		if ( count( $batch ) < 1000 ) {
			break;
		}
	}
	$nums = array();
	list( $c, $b ) = ew_runs_sb( 'GET', '/rest/v1/supporters?select=user_id,efc_number,name,source&limit=100000' );
	$numbered = ( 200 === $c );
	if ( $numbered ) {
		foreach ( (array) $b as $r ) {
			if ( ! empty( $r['user_id'] ) ) {
				$nums[ $r['user_id'] ] = $r;
			}
		}
	}
	$links = function_exists( 'ew_fan_links_all' ) ? ew_fan_links_all() : array();
	$per   = ew_sup_booking_stats();
	$rows  = array();
	foreach ( $users as $u ) {
		$id     = (string) ( $u['id'] ?? '' );
		$email  = strtolower( (string) ( $u['email'] ?? '' ) );
		$meta   = (array) ( $u['user_metadata'] ?? array() );
		$sup    = $nums[ $id ] ?? array();
		$emails = array_values( array_unique( array_filter( array_merge( array( $email ), (array) ( $links[ sanitize_key( $id ) ] ?? array() ) ) ) ) );
		$bk     = 0;
		$last   = 0;
		foreach ( $emails as $e ) {
			$bk  += $per[ $e ]['n'] ?? 0;
			$last = max( $last, $per[ $e ]['in'] ?? 0 );
		}
		$prov   = (array) ( $u['app_metadata']['providers'] ?? array( $u['app_metadata']['provider'] ?? 'email' ) );
		$rows[] = array(
			'id'       => $id,
			'number'   => (int) ( $sup['efc_number'] ?? 0 ),
			'name'     => (string) ( $sup['name'] ?? ( $meta['full_name'] ?? ( $meta['name'] ?? '' ) ) ),
			'email'    => $email,
			'emails'   => $emails,
			'joined'   => strtotime( (string) ( $u['created_at'] ?? '' ) ) ?: 0,
			'seen'     => strtotime( (string) ( $u['last_sign_in_at'] ?? '' ) ) ?: 0,
			'source'   => implode( ', ', $prov ),
			'bookings' => $bk,
			'last_in'  => $last,
		);
	}
	usort( $rows, function ( $a, $b ) {
		return $b['joined'] <=> $a['joined'];
	} );
	$out = array( 'error' => $numbered ? '' : 'Numbers are not set up in the database yet, so no EFC- numbers show.', 'rows' => $rows );
	set_transient( 'ew_sup_all', $out, MINUTE_IN_SECONDS );
	return $out;
}

/** Bookings held (not just started) and the latest gate scan, per email. */
function ew_sup_booking_stats() {
	global $wpdb;
	$q = "SELECT LOWER(e.meta_value) AS email, COUNT(*) AS n, MAX(CAST(COALESCE(i.meta_value,0) AS UNSIGNED)) AS lastin
		FROM {$wpdb->posts} p
		JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = '_ewt_email'
		LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_ewt_status'
		LEFT JOIN {$wpdb->postmeta} i ON i.post_id = p.ID AND i.meta_key = '_ewt_in'
		WHERE p.post_type = 'ewt_booking' AND p.post_status <> 'trash' AND COALESCE(s.meta_value,'') <> 'started'
		GROUP BY LOWER(e.meta_value)";
	$out = array();
	foreach ( (array) $wpdb->get_results( $q, ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
		$out[ $r['email'] ] = array( 'n' => (int) $r['n'], 'in' => (int) $r['lastin'] );
	}
	return $out;
}

function ew_sup_find( $id ) {
	foreach ( ew_sup_all()['rows'] as $r ) {
		if ( $r['id'] === $id ) {
			return $r;
		}
	}
	return null;
}

function ew_sup_filter( $rows, $q, $src, $has ) {
	$q   = strtolower( trim( (string) $q ) );
	$num = ew_sup_parse_number( $q );
	return array_values( array_filter( $rows, function ( $r ) use ( $q, $num, $src, $has ) {
		if ( '' !== $q ) {
			$hit = false !== strpos( strtolower( $r['name'] ), $q ) || false !== strpos( $r['email'], $q )
				|| ( $num && $r['number'] === $num );
			if ( ! $hit ) {
				return false;
			}
		}
		if ( '' !== $src && false === strpos( $r['source'], $src ) ) {
			return false;
		}
		if ( 'bookings' === $has && ! $r['bookings'] ) {
			return false;
		}
		if ( 'none' === $has && $r['bookings'] ) {
			return false;
		}
		if ( 'scanned' === $has && ! $r['last_in'] ) {
			return false;
		}
		return true;
	} ) );
}

/* ----------------------------------------------------------------- menu */

add_action( 'admin_menu', function () {
	if ( '' === ew_sup_level() ) {
		return;
	}
	// Any of the four levels opens the screen; each tab checks its own.
	$cap = ew_sup_level();
	add_menu_page( 'Supporters', 'Supporters', $cap, 'ew-supporters', 'ew_sup_page', 'dashicons-groups', 26 );
} );

function ew_sup_url( $args = array() ) {
	return add_query_arg( $args, admin_url( 'admin.php?page=ew-supporters' ) );
}

function ew_sup_page() {
	if ( '' === ew_sup_level() ) {
		wp_die( 'You do not have access to Supporters.' );
	}
	$tabs = array();
	if ( ew_sup_can( 'view' ) ) {
		$tabs['list'] = 'Supporters';
	}
	$tabs['totals'] = 'Totals';
	if ( ew_sup_can( 'edit' ) ) {
		$tabs['log'] = 'Number changes';
	}
	if ( ew_sup_can( 'access' ) ) {
		$tabs['access'] = 'Staff access';
	}
	$tab = sanitize_key( $_GET['tab'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! isset( $tabs[ $tab ] ) ) {
		$tab = array_key_first( $tabs );
	}
	echo '<div class="wrap ew-sup"><h1>Supporters</h1>';
	echo '<style>.ew-sup table{border-collapse:collapse;width:100%;background:#fff}.ew-sup th,.ew-sup td{padding:8px 10px;border-bottom:1px solid #e4e5e8;text-align:left;vertical-align:top}.ew-sup .num{font-weight:700;white-space:nowrap}.ew-sup .tot{display:flex;flex-wrap:wrap;gap:12px;margin:16px 0}.ew-sup .tot div{background:#fff;border:1px solid #e4e5e8;border-radius:8px;padding:14px 18px;min-width:150px}.ew-sup .tot b{display:block;font-size:26px}.ew-sup form.f{display:flex;flex-wrap:wrap;gap:8px;margin:14px 0}</style>';
	if ( isset( $_GET['msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		echo '<div class="notice notice-info"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['msg'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
	}
	echo '<nav class="nav-tab-wrapper">';
	foreach ( $tabs as $k => $label ) {
		echo '<a class="nav-tab' . ( $k === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( ew_sup_url( array( 'tab' => $k ) ) ) . '">' . esc_html( $label ) . '</a>';
	}
	echo '</nav>';
	if ( 'list' === $tab ) {
		$one = sanitize_text_field( wp_unslash( $_GET['sup'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		'' !== $one ? ew_sup_detail( $one ) : ew_sup_list();
	} elseif ( 'totals' === $tab ) {
		ew_sup_totals();
	} elseif ( 'log' === $tab ) {
		ew_sup_log();
	} else {
		ew_sup_access();
	}
	echo '</div>';
}

function ew_sup_list() {
	$data = ew_sup_all();
	if ( $data['error'] ) {
		echo '<div class="notice notice-warning"><p>' . esc_html( $data['error'] ) . '</p></div>';
	}
	$q    = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );   // phpcs:ignore WordPress.Security.NonceVerification
	$src  = sanitize_key( $_GET['src'] ?? '' );                       // phpcs:ignore WordPress.Security.NonceVerification
	$has  = sanitize_key( $_GET['has'] ?? '' );                       // phpcs:ignore WordPress.Security.NonceVerification
	$rows = ew_sup_filter( $data['rows'], $q, $src, $has );
	echo '<form class="f" method="get"><input type="hidden" name="page" value="ew-supporters"><input type="hidden" name="tab" value="list">';
	echo '<input type="search" name="q" value="' . esc_attr( $q ) . '" placeholder="Name, email or EFC- number" size="32">';
	echo '<select name="src"><option value="">Any sign-in</option>';
	foreach ( array( 'email' => 'Email', 'apple' => 'Apple', 'google' => 'Google', 'phone' => 'Mobile' ) as $k => $l ) {
		echo '<option value="' . esc_attr( $k ) . '"' . selected( $src, $k, false ) . '>' . esc_html( $l ) . '</option>';
	}
	echo '</select><select name="has"><option value="">Everyone</option>';
	foreach ( array( 'bookings' => 'Has bookings', 'none' => 'No bookings', 'scanned' => 'Been scanned in' ) as $k => $l ) {
		echo '<option value="' . esc_attr( $k ) . '"' . selected( $has, $k, false ) . '>' . esc_html( $l ) . '</option>';
	}
	echo '</select><button class="button">Search</button>';
	if ( ew_sup_can( 'export' ) ) {
		echo ' <a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ew_sup_export' ), 'ew_sup_export' ) ) . '">Export CSV</a>';
	}
	echo '</form><p>' . count( $rows ) . ' of ' . count( $data['rows'] ) . ' supporters</p>';
	echo '<table><thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Joined</th><th>Signs in with</th><th>Bookings</th><th>Last scanned in</th></tr></thead><tbody>';
	foreach ( array_slice( $rows, 0, 500 ) as $r ) {
		$url = ew_sup_url( array( 'tab' => 'list', 'sup' => $r['id'] ) );
		echo '<tr><td class="num"><a href="' . esc_url( $url ) . '">' . esc_html( ew_sup_fmt( $r['number'] ) ?: 'Open' ) . '</a></td>'
			. '<td>' . esc_html( $r['name'] ) . '</td><td>' . esc_html( $r['email'] ) . '</td>'
			. '<td>' . esc_html( $r['joined'] ? wp_date( 'j M Y', $r['joined'] ) : '' ) . '</td>'
			. '<td>' . esc_html( $r['source'] ) . '</td><td>' . (int) $r['bookings'] . '</td>'
			. '<td>' . esc_html( $r['last_in'] ? wp_date( 'j M Y, g:ia', $r['last_in'] ) : '' ) . '</td></tr>';
	}
	echo '</tbody></table>';
}

function ew_sup_detail( $id ) {
	$r = ew_sup_find( $id );
	if ( ! $r ) {
		echo '<p>That supporter was not found. <a href="' . esc_url( ew_sup_url( array( 'tab' => 'list' ) ) ) . '">Back to supporters</a></p>';
		return;
	}
	echo '<p><a href="' . esc_url( ew_sup_url( array( 'tab' => 'list' ) ) ) . '">Back to supporters</a></p>';
	echo '<h2>' . esc_html( ew_sup_fmt( $r['number'] ) . ( $r['name'] ? ' · ' . $r['name'] : '' ) ) . '</h2>';
	echo '<table style="max-width:720px"><tbody>'
		. '<tr><th>Email</th><td>' . esc_html( implode( ', ', $r['emails'] ) ) . '</td></tr>'
		. '<tr><th>Joined</th><td>' . esc_html( $r['joined'] ? wp_date( 'j M Y, g:ia', $r['joined'] ) : '' ) . '</td></tr>'
		. '<tr><th>Last signed in</th><td>' . esc_html( $r['seen'] ? wp_date( 'j M Y, g:ia', $r['seen'] ) : '' ) . '</td></tr>'
		. '<tr><th>Signs in with</th><td>' . esc_html( $r['source'] ) . '</td></tr>'
		. '</tbody></table>';
	if ( ew_sup_can( 'edit' ) ) {
		echo '<h3>Edit</h3><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'ew_sup_save' );
		echo '<input type="hidden" name="action" value="ew_sup_save"><input type="hidden" name="sup" value="' . esc_attr( $r['id'] ) . '">';
		echo '<p><label>Name<br><input name="name" value="' . esc_attr( $r['name'] ) . '" size="40"></label></p>';
		echo '<p><label>Number<br><input name="number" value="' . esc_attr( ew_sup_fmt( $r['number'] ) ) . '" size="14"></label><br><span class="description">Give a long-time supporter one of the kept-back numbers, EFC-' . (int) EW_SUP_RESERVED_FROM . ' to EFC-' . (int) EW_SUP_RESERVED_TO . ' (season ticket 57 is EFC-100057). Their old number is never given to anyone else.</span></p>';
		submit_button( 'Save' );
		echo '</form>';
	}
	$ids = function_exists( 'ew_wallet_ids_for' ) ? ew_wallet_ids_for( $r['emails'] ) : array();
	echo '<h3>Bookings and tickets (' . count( $ids ) . ')</h3>';
	if ( ! $ids ) {
		echo '<p>Nothing booked under these emails.</p>';
		return;
	}
	echo '<table><thead><tr><th>Booking</th><th>Booked</th><th>Status</th><th>Scanned in</th></tr></thead><tbody>';
	foreach ( $ids as $bid ) {
		$in = (int) get_post_meta( $bid, '_ewt_in', true );
		echo '<tr><td><a href="' . esc_url( get_edit_post_link( $bid ) ?: '#' ) . '">' . esc_html( get_the_title( $bid ) ) . '</a></td>'
			. '<td>' . esc_html( get_the_date( 'j M Y', $bid ) ) . '</td>'
			. '<td>' . esc_html( (string) get_post_meta( $bid, '_ewt_status', true ) ) . '</td>'
			. '<td>' . esc_html( $in ? wp_date( 'j M Y, g:ia', $in ) : '' ) . '</td></tr>';
	}
	echo '</tbody></table>';
}

function ew_sup_totals() {
	$rows = ew_sup_all()['rows'];
	$week = time() - WEEK_IN_SECONDS;
	$new  = 0;
	$book = 0;
	$in   = 0;
	$src  = array();
	foreach ( $rows as $r ) {
		$new  += $r['joined'] >= $week ? 1 : 0;
		$book += $r['bookings'] ? 1 : 0;
		$in   += $r['last_in'] ? 1 : 0;
		foreach ( array_map( 'trim', explode( ',', $r['source'] ) ) as $s ) {
			$src[ $s ] = ( $src[ $s ] ?? 0 ) + 1;
		}
	}
	$tiles = array(
		'Supporters'        => count( $rows ),
		'New this week'     => $new,
		'With bookings'     => $book,
		'Scanned in at a game' => $in,
	);
	foreach ( $src as $k => $n ) {
		$tiles[ 'Sign in with ' . $k ] = $n;
	}
	echo '<div class="tot">';
	foreach ( $tiles as $l => $n ) {
		echo '<div><b>' . (int) $n . '</b>' . esc_html( $l ) . '</div>';
	}
	echo '</div>';
}

function ew_sup_log() {
	list( $c, $b ) = ew_runs_sb( 'GET', '/rest/v1/supporter_number_changes?select=*&order=changed_at.desc&limit=200' );
	if ( 200 !== $c ) {
		echo '<p>The change log is not set up in the database yet.</p>';
		return;
	}
	echo '<table><thead><tr><th>When</th><th>Who</th><th>Old</th><th>New</th><th>Supporter</th></tr></thead><tbody>';
	foreach ( (array) $b as $r ) {
		echo '<tr><td>' . esc_html( wp_date( 'j M Y, g:ia', strtotime( (string) $r['changed_at'] ) ) ) . '</td><td>' . esc_html( (string) $r['changed_by'] ) . '</td>'
			. '<td>' . esc_html( ew_sup_fmt( $r['old_number'] ) ) . '</td><td>' . esc_html( ew_sup_fmt( $r['new_number'] ) ) . '</td>'
			. '<td><a href="' . esc_url( ew_sup_url( array( 'tab' => 'list', 'sup' => (string) $r['user_id'] ) ) ) . '">Open</a></td></tr>';
	}
	echo '</tbody></table>';
}

function ew_sup_access() {
	$levels = ew_sup_levels();
	echo '<p>Who can open Supporters, and what they can do. Administrators are always Super admin.</p><ul>'
		. '<li><b>Super admin:</b> everything, this tab and the export.</li>'
		. '<li><b>Membership admin:</b> view, edit names, hand out the kept-back numbers, export.</li>'
		. '<li><b>Matchday / box office:</b> search and view a supporter and their tickets. No editing, no export.</li>'
		. '<li><b>Reports:</b> totals only, no names or emails.</li></ul>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'ew_sup_access' );
	echo '<input type="hidden" name="action" value="ew_sup_access"><table style="max-width:720px"><thead><tr><th>Staff member</th><th>Access</th></tr></thead><tbody>';
	foreach ( get_users( array( 'role__not_in' => array( 'subscriber', 'customer' ), 'orderby' => 'display_name' ) ) as $u ) {
		$admin = user_can( $u, 'manage_options' );
		$cur   = '';
		foreach ( array_keys( $levels ) as $cap ) {
			if ( ! empty( $u->allcaps[ $cap ] ) && empty( $cur ) && ! $admin ) {
				$cur = $cap;
			}
		}
		echo '<tr><td>' . esc_html( $u->display_name . ' (' . $u->user_email . ')' ) . '</td><td>';
		if ( $admin ) {
			echo 'Super admin (administrator)';
		} else {
			echo '<select name="lvl[' . (int) $u->ID . ']"><option value="">No access</option>';
			foreach ( $levels as $cap => $label ) {
				echo '<option value="' . esc_attr( $cap ) . '"' . selected( $cur, $cap, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select>';
		}
		echo '</td></tr>';
	}
	echo '</tbody></table>';
	submit_button( 'Save access' );
	echo '</form>';
}

/* --------------------------------------------------------------- actions */

add_action( 'admin_post_ew_sup_access', function () {
	check_admin_referer( 'ew_sup_access' );
	if ( ! ew_sup_can( 'access' ) ) {
		wp_die( 'Only a Super admin can change access.' );
	}
	$levels = array_keys( ew_sup_levels() );
	foreach ( (array) wp_unslash( $_POST['lvl'] ?? array() ) as $uid => $want ) {
		$u = get_userdata( (int) $uid );
		if ( ! $u || user_can( $u, 'manage_options' ) ) {
			continue;
		}
		foreach ( $levels as $cap ) {
			$u->remove_cap( $cap );
		}
		if ( in_array( $want, $levels, true ) ) {
			$u->add_cap( $want );
		}
	}
	wp_safe_redirect( ew_sup_url( array( 'tab' => 'access', 'msg' => 'Access saved.' ) ) );
	exit;
} );

add_action( 'admin_post_ew_sup_save', function () {
	check_admin_referer( 'ew_sup_save' );
	if ( ! ew_sup_can( 'edit' ) ) {
		wp_die( 'You cannot edit supporters.' );
	}
	$id   = sanitize_text_field( wp_unslash( $_POST['sup'] ?? '' ) );
	$back = ew_sup_url( array( 'tab' => 'list', 'sup' => $id ) );
	$r    = ew_sup_find( $id );
	if ( ! $r ) {
		wp_safe_redirect( add_query_arg( 'msg', 'Supporter not found.', $back ) );
		exit;
	}
	$name  = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
	$want  = ew_sup_parse_number( wp_unslash( $_POST['number'] ?? '' ) );
	$patch = array( 'name' => $name, 'updated_at' => gmdate( 'c' ) );
	if ( $want && $want !== $r['number'] ) {
		if ( $want < EW_SUP_RESERVED_FROM || $want > EW_SUP_RESERVED_TO ) {
			wp_safe_redirect( add_query_arg( 'msg', 'Only the kept-back numbers EFC-100001 to EFC-101000 can be handed out.', $back ) );
			exit;
		}
		list( , $taken ) = ew_runs_sb( 'GET', '/rest/v1/supporters?select=user_id&efc_number=eq.' . $want );
		if ( ! empty( $taken ) ) {
			wp_safe_redirect( add_query_arg( 'msg', ew_sup_fmt( $want ) . ' already belongs to someone.', $back ) );
			exit;
		}
		$patch['efc_number'] = $want;
	}
	list( $c ) = ew_runs_sb( 'PATCH', '/rest/v1/supporters?user_id=eq.' . rawurlencode( $id ), $patch, array( 'Prefer' => 'return=minimal' ) );
	if ( $c < 200 || $c >= 300 ) {
		wp_safe_redirect( add_query_arg( 'msg', 'Could not save (' . (int) $c . ').', $back ) );
		exit;
	}
	if ( isset( $patch['efc_number'] ) ) {
		$me = wp_get_current_user();
		ew_runs_sb( 'POST', '/rest/v1/supporter_number_changes', array(
			'user_id'    => $id,
			'old_number' => $r['number'] ?: null,
			'new_number' => $want,
			'changed_by' => $me->display_name . ' (' . $me->user_email . ')',
		), array( 'Prefer' => 'return=minimal' ) );
	}
	delete_transient( 'ew_sup_all' );
	wp_safe_redirect( add_query_arg( 'msg', 'Saved.', $back ) );
	exit;
} );

add_action( 'admin_post_ew_sup_export', function () {
	check_admin_referer( 'ew_sup_export' );
	if ( ! ew_sup_can( 'export' ) ) {
		wp_die( 'You cannot export supporters.' );
	}
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="supporters-' . gmdate( 'Y-m-d' ) . '.csv"' );
	$f = fopen( 'php://output', 'w' );
	fputcsv( $f, array( 'ID', 'Name', 'Email', 'Joined', 'Signs in with', 'Bookings', 'Last scanned in' ) );
	foreach ( ew_sup_all( true )['rows'] as $r ) {
		fputcsv( $f, array( ew_sup_fmt( $r['number'] ), $r['name'], $r['email'], $r['joined'] ? wp_date( 'Y-m-d H:i', $r['joined'] ) : '', $r['source'], $r['bookings'], $r['last_in'] ? wp_date( 'Y-m-d H:i', $r['last_in'] ) : '' ) );
	}
	fclose( $f ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
} );
