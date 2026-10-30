<?php
/**
 * The printed list.
 *
 * Everything sold on the site -- match tickets, food tokens, shirts --
 * ends up as one ewt_booking, so there is one list rather than a list per
 * funnel, and one place to print it from. The Bookings screen already
 * downloads a spreadsheet; this adds the other two things somebody at a
 * gate or behind a bar actually needs: a sheet of paper, and a file to
 * keep.
 *
 * There is no PDF library on this site and adding one to a plugin that
 * goes through a WAF is not worth the trouble, so "Print" opens a clean
 * A4 page and asks the browser to print it. Every browser's print dialog
 * will also save that page as a PDF, which is the same thing by a shorter
 * road. "Word" serves the identical page as a .doc, which Word and Google
 * Docs both open and edit.
 *
 * The Event box above the list matters more than it looks: without it the
 * printed sheet is every booking the club has ever taken. Picking a game
 * or a food event narrows the screen AND the two buttons together, so
 * what prints is what is on the screen.
 *
 * @package eastwood-fwp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The event a booking is for, whichever funnel sold it.
 *
 * Match tickets write the fixture into _ewt_event; food passes write the
 * event's name into _ewt_fname at the moment of sale, and the live event
 * can be renamed afterwards, so the pass is asked for its own copy first.
 */
function ew_pl_event_of( $id ) {
	$food = trim( (string) get_post_meta( $id, '_ewt_fname', true ) );
	if ( '' !== $food ) {
		return $food;
	}
	return trim( (string) get_post_meta( $id, '_ewt_event', true ) );
}

/** Every event that has at least one booking against it, A to Z. */
function ew_pl_events() {
	global $wpdb;
	$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"SELECT DISTINCT m.meta_value
		   FROM {$wpdb->postmeta} m
		   JOIN {$wpdb->posts} p ON p.ID = m.post_id
		  WHERE p.post_type = 'ewt_booking'
		    AND m.meta_key IN ( '_ewt_event', '_ewt_fname' )
		    AND m.meta_value <> ''"
	);
	$rows = array_values( array_unique( array_map( 'strval', (array) $rows ) ) );
	sort( $rows, SORT_NATURAL | SORT_FLAG_CASE );
	return $rows;
}

/** What a booking bought, in one line. */
function ew_pl_what( $id ) {
	$bits = array();
	foreach ( ew_pl_tokens( $id ) as $t ) {
		$bits[] = (int) $t['n'] . ' × ' . (string) $t['name'];
	}
	if ( ! $bits ) {
		$lines = trim( (string) get_post_meta( $id, '_ewt_lines', true ) );
		if ( '' !== $lines ) {
			$bits[] = $lines;
		}
		$extra = trim( (string) get_post_meta( $id, '_ewt_extra', true ) );
		if ( '' !== $extra ) {
			$bits[] = $extra;
		}
	}
	return implode( ' · ', $bits );
}

/** A food pass's tokens, or nothing for anything that is not one. */
function ew_pl_tokens( $id ) {
	if ( ! function_exists( 'ew_food_tokens' ) ) {
		return array();
	}
	return (array) ew_food_tokens( (int) $id );
}

/** The bookings a printed sheet should carry, in the order to read them. */
function ew_pl_rows( $event, $status ) {
	$args = array(
		'post_type'   => 'ewt_booking',
		'post_status' => 'any',
		'numberposts' => -1,
		'orderby'     => 'title',
		'order'       => 'ASC',
	);
	if ( '' !== $status ) {
		$args['meta_query'] = array( array( 'key' => '_ewt_status', 'value' => $status ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}
	$out = array();
	foreach ( get_posts( $args ) as $b ) {
		// Either key answering is enough, the same test the screen above
		// makes, so the sheet carries exactly what the screen showed.
		if ( '' !== $event
			&& (string) get_post_meta( $b->ID, '_ewt_event', true ) !== $event
			&& (string) get_post_meta( $b->ID, '_ewt_fname', true ) !== $event ) {
			continue;
		}
		$out[] = $b;
	}
	return $out;
}

/* --- The three boxes above the Bookings list ---------------------------- */

add_action( 'restrict_manage_posts', function ( $type ) {
	if ( 'ewt_booking' !== $type ) {
		return;
	}
	$now = isset( $_GET['ew_event'] ) ? sanitize_text_field( wp_unslash( $_GET['ew_event'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$st  = isset( $_GET['ew_status'] ) ? sanitize_key( wp_unslash( $_GET['ew_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

	echo '<select name="ew_event"><option value="">Every event</option>';
	foreach ( ew_pl_events() as $e ) {
		echo '<option value="' . esc_attr( $e ) . '"' . selected( $now, $e, false ) . '>' . esc_html( $e ) . '</option>';
	}
	echo '</select> ';

	echo '<select name="ew_status"><option value="">Any status</option>';
	foreach ( array( 'paid' => 'Paid', 'started' => 'Started' ) as $k => $label ) {
		echo '<option value="' . esc_attr( $k ) . '"' . selected( $st, $k, false ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select> ';

	$base = wp_nonce_url(
		admin_url( 'admin-post.php?action=ew_print_bookings&ew_event=' . rawurlencode( $now ) . '&ew_status=' . rawurlencode( $st ) ),
		'ew_print'
	);
	echo '<a class="button" target="_blank" href="' . esc_url( $base . '&fmt=print' ) . '">Print list</a> ';
	echo '<a class="button" href="' . esc_url( $base . '&fmt=doc' ) . '">Download Word</a> ';
}, 20 );

/** Narrow the screen itself to the event picked above it. */
add_action( 'pre_get_posts', function ( $q ) {
	if ( ! is_admin() || ! $q->is_main_query() || 'ewt_booking' !== $q->get( 'post_type' ) ) {
		return;
	}
	$st = isset( $_GET['ew_status'] ) ? sanitize_key( wp_unslash( $_GET['ew_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	if ( '' !== $st ) {
		$q->set( 'meta_query', array( array( 'key' => '_ewt_status', 'value' => $st ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}
	$ev = isset( $_GET['ew_event'] ) ? sanitize_text_field( wp_unslash( $_GET['ew_event'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	if ( '' === $ev ) {
		return;
	}
	// A food pass and a match ticket keep the event under different keys,
	// so both are asked, and either answering is enough.
	$meta   = (array) $q->get( 'meta_query' );
	$meta[] = array(
		'relation' => 'OR',
		array( 'key' => '_ewt_event', 'value' => $ev ),
		array( 'key' => '_ewt_fname', 'value' => $ev ),
	);
	$q->set( 'meta_query', $meta ); // phpcs:ignore WordPress.DB.SlowDBQuery
} );

/* --- The sheet itself --------------------------------------------------- */

add_action( 'admin_post_ew_print_bookings', function () {
	if ( ! current_user_can( 'edit_posts' ) || ! check_admin_referer( 'ew_print' ) ) {
		wp_die( 'Not allowed.' );
	}
	$event  = isset( $_GET['ew_event'] ) ? sanitize_text_field( wp_unslash( $_GET['ew_event'] ) ) : '';
	$status = isset( $_GET['ew_status'] ) ? sanitize_key( wp_unslash( $_GET['ew_status'] ) ) : '';
	$doc    = isset( $_GET['fmt'] ) && 'doc' === $_GET['fmt'];

	$rows  = ew_pl_rows( $event, $status );
	$title = '' !== $event ? $event : 'All bookings';
	$slug  = sanitize_title( $title );
	$when  = wp_date( 'j F Y, H:i' );

	if ( $doc ) {
		header( 'Content-Type: application/msword; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $slug . '-' . gmdate( 'Y-m-d' ) . '.doc"' );
	} else {
		header( 'Content-Type: text/html; charset=utf-8' );
	}

	echo '<!doctype html><html><head><meta charset="utf-8"><title>' . esc_html( $title ) . '</title><style>';
	echo '@page{size:A4;margin:14mm}';
	echo 'body{font-family:Arial,Helvetica,sans-serif;color:#111;font-size:11pt;margin:0}';
	echo 'h1{font-size:20pt;margin:0 0 2px}';
	echo '.sub{font-size:10pt;color:#555;margin:0 0 16px}';
	echo 'table{width:100%;border-collapse:collapse}';
	echo 'th{text-align:left;font-size:9pt;text-transform:uppercase;letter-spacing:.04em;color:#555;border-bottom:1.5px solid #111;padding:0 8px 5px 0}';
	echo 'td{padding:7px 8px 7px 0;border-bottom:1px solid #ddd;vertical-align:top}';
	echo 'tr{page-break-inside:avoid}';
	echo '.nm{font-weight:bold}';
	echo '.ft{margin-top:18px;font-size:9pt;color:#777;border-top:1px solid #ddd;padding-top:8px}';
	echo '</style></head><body>';

	echo '<h1>' . esc_html( $title ) . '</h1>';
	echo '<p class="sub">' . esc_html( sprintf( '%d booking%s', count( $rows ), 1 === count( $rows ) ? '' : 's' ) )
		. ( '' !== $status ? ' &middot; ' . esc_html( ucfirst( $status ) ) : '' )
		. ' &middot; ' . esc_html( $when ) . '</p>';

	echo '<table><tr><th>Name</th><th>Email</th><th>What</th><th>Code</th><th>Status</th><th>Paid</th></tr>';
	foreach ( $rows as $b ) {
		$paid = (int) get_post_meta( $b->ID, '_ewt_paid', true );
		echo '<tr>';
		echo '<td class="nm">' . esc_html( (string) get_post_meta( $b->ID, '_ewt_name', true ) ?: $b->post_title ) . '</td>';
		echo '<td>' . esc_html( (string) get_post_meta( $b->ID, '_ewt_email', true ) ) . '</td>';
		echo '<td>' . esc_html( ew_pl_what( $b->ID ) ) . '</td>';
		echo '<td>' . esc_html( (string) get_post_meta( $b->ID, '_ewt_key', true ) ) . '</td>';
		echo '<td>' . esc_html( ucfirst( (string) get_post_meta( $b->ID, '_ewt_status', true ) ) ) . '</td>';
		echo '<td>' . esc_html( $paid && function_exists( 'ew_tix_money' ) ? ew_tix_money( $paid ) : ( $paid ? '£' . number_format( $paid / 100, 2 ) : '' ) ) . '</td>';
		echo '</tr>';
	}
	echo '</table>';

	echo '<p class="ft">Eastwood Community Football Club &middot; printed ' . esc_html( $when ) . '</p>';
	if ( ! $doc ) {
		echo '<script>window.onload=function(){window.print();};</script>';
	}
	echo '</body></html>';
	exit;
} );
