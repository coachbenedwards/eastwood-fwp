<?php
/**
 * Merged passes: when a family claimed the same game twice, the spare pass is
 * merged into the one they keep instead of being deleted.
 *
 * The spare pass gets _ewt_status "merged" and _ewt_merged_into = the kept
 * pass's id. Nothing is removed, so either QR code still works: the gate,
 * the pass link and the app wallet all follow the merge to the kept pass,
 * and that pass checks in once. Counts and door lists that read "claimed"
 * leave merged passes out on their own.
 */

/** The pass a booking now answers to, following any merges. */
function ew_booking_resolve( $id ) {
	$id = (int) $id;
	for ( $i = 0; $id && $i < 5; $i++ ) {
		$to = (int) get_post_meta( $id, '_ewt_merged_into', true );
		if ( ! $to || $to === $id || 'ewt_booking' !== get_post_type( $to ) ) {
			break;
		}
		$id = $to;
	}
	return $id;
}

/** A booking by its EFC code, without following merges. */
function ew_booking_by_code( $code ) {
	$code = strtoupper( trim( (string) $code ) );
	if ( '' === $code ) {
		return 0;
	}
	$ids = get_posts( array(
		'post_type'   => 'ewt_booking',
		'post_status' => 'any',
		'numberposts' => 1,
		'fields'      => 'ids',
		'meta_key'    => '_ewt_code', // phpcs:ignore WordPress.DB.SlowDBQuery
		'meta_value'  => $code, // phpcs:ignore WordPress.DB.SlowDBQuery
	) );
	return $ids ? (int) $ids[0] : 0;
}

/** Merge pass $from into pass $into. Free claims only; paid bookings are Ben's in Stripe. */
function ew_booking_merge( $from, $into ) {
	$from = (int) $from;
	$into = (int) $into;
	if ( ! $from || ! $into || 'ewt_booking' !== get_post_type( $from ) || 'ewt_booking' !== get_post_type( $into ) ) {
		return new WP_Error( 'ew_merge', 'pass not found' );
	}
	if ( $from === $into || ew_booking_resolve( $into ) === $from ) {
		return new WP_Error( 'ew_merge', 'a pass cannot be merged into itself' );
	}
	$fs = (string) get_post_meta( $from, '_ewt_status', true );
	$is = (string) get_post_meta( $into, '_ewt_status', true );
	if ( 'claimed' !== $fs ) {
		return new WP_Error( 'ew_merge', 'only a claimed free pass can be merged (this one is ' . $fs . ')' );
	}
	if ( 'claimed' !== $is ) {
		return new WP_Error( 'ew_merge', 'the pass to keep must be a claimed free pass (it is ' . $is . ')' );
	}
	if ( (string) get_post_meta( $from, '_ewt_event', true ) !== (string) get_post_meta( $into, '_ewt_event', true ) ) {
		return new WP_Error( 'ew_merge', 'the two passes are for different games' );
	}
	$fcode = (string) get_post_meta( $from, '_ewt_code', true );
	$icode = (string) get_post_meta( $into, '_ewt_code', true );
	$when  = wp_date( 'j M Y' );

	if ( get_post_meta( $from, '_ewt_in', true ) && ! get_post_meta( $into, '_ewt_in', true ) ) {
		update_post_meta( $into, '_ewt_in', get_post_meta( $from, '_ewt_in', true ) );
	}
	update_post_meta( $from, '_ewt_merged_into', $into );
	update_post_meta( $from, '_ewt_status_before', $fs );
	update_post_meta( $from, '_ewt_status', 'merged' );

	$fn = trim( (string) get_post_meta( $from, '_ewt_note', true ) );
	update_post_meta( $from, '_ewt_note', trim( $fn . "\nMerged into " . $icode . ' on ' . $when . ' (duplicate claim). This QR still works and checks in ' . $icode . '.' ) );
	$in = trim( (string) get_post_meta( $into, '_ewt_note', true ) );
	update_post_meta( $into, '_ewt_note', trim( $in . "\n" . $fcode . ' merged into this pass on ' . $when . '; its QR checks in this pass.' ) );
	return true;
}
