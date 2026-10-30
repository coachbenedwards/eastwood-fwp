<?php
/**
 * Putting the old names right.
 *
 * ew_person_name() went onto every point a name is taken, but only from
 * the moment it landed: everything sold before that is still in the
 * database exactly as it was typed, and MISS KATE SHIRLEY was still
 * shouting off the goalie camp sheet a fortnight later.
 *
 * This is the one-off that walks back over them. It is safe to run more
 * than once and safe to run on everything, because the decision about
 * what to change is ew_person_name()'s own: a word already carrying a
 * capital inside it is left alone, particles stay down, and an email
 * typed into the name box is left as an email. Nothing is guessed at
 * here that is not guessed at on the way in.
 *
 * It touches the three places a person's name is kept on a booking: the
 * booker, the child the pass is for, and the post title the Bookings
 * screen lists it under.
 *
 * @package eastwood-fwp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Re-case every name on every booking.
 *
 * @param bool $dry Count what would change without changing it.
 * @return array How many names were looked at, and how many moved.
 */
function ew_tidy_names( $dry = false ) {
	$seen    = 0;
	$changed = 0;
	$ids     = get_posts( array(
		'post_type'   => 'ewt_booking',
		'post_status' => 'any',
		'numberposts' => -1,
		'fields'      => 'ids',
	) );

	foreach ( $ids as $id ) {
		$title = (string) get_post_field( 'post_title', $id );
		foreach ( array( '_ewt_name', '_ewt_child' ) as $key ) {
			$was = (string) get_post_meta( $id, $key, true );
			if ( '' === trim( $was ) ) {
				continue;
			}
			$seen++;
			$now = ew_person_name( $was );
			if ( $now === $was ) {
				continue;
			}
			$changed++;
			if ( ! $dry ) {
				update_post_meta( $id, $key, $now );
			}
		}
		// The screen lists a booking under its title, so that moves too.
		if ( '' !== trim( $title ) ) {
			$fixed = ew_person_name( $title );
			if ( $fixed !== $title ) {
				$changed++;
				if ( ! $dry ) {
					wp_update_post( array( 'ID' => (int) $id, 'post_title' => $fixed ) );
				}
			}
			$seen++;
		}
	}
	return array( 'bookings' => count( $ids ), 'names' => $seen, 'changed' => $changed );
}

/* --- The button, on the Bookings screen --------------------------------- */

add_action( 'restrict_manage_posts', function ( $type ) {
	if ( 'ewt_booking' !== $type ) {
		return;
	}
	$url = wp_nonce_url( admin_url( 'admin-post.php?action=ew_tidy_names' ), 'ew_tidy_names' );
	echo '<a class="button" href="' . esc_url( $url ) . '">Fix shouted names</a> ';
}, 30 );

add_action( 'admin_post_ew_tidy_names', function () {
	if ( ! current_user_can( 'edit_posts' ) || ! check_admin_referer( 'ew_tidy_names' ) ) {
		wp_die( 'Not allowed.' );
	}
	$done = ew_tidy_names( false );
	wp_safe_redirect( add_query_arg(
		array( 'post_type' => 'ewt_booking', 'ew_tidied' => (int) $done['changed'] ),
		admin_url( 'edit.php' )
	) );
	exit;
} );

add_action( 'admin_notices', function () {
	// phpcs:ignore WordPress.Security.NonceVerification
	if ( ! isset( $_GET['ew_tidied'] ) ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification
	$n = (int) $_GET['ew_tidied'];
	echo '<div class="notice notice-success"><p>'
		. esc_html( 0 === $n ? 'Every name was already written properly.' : sprintf( '%d name%s put back into case.', $n, 1 === $n ? '' : 's' ) )
		. '</p></div>';
} );
