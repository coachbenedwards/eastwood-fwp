<?php
/**
 * The other side's line-up on the match page, for our home games.
 *
 * Staff type it into the Team EastwoodFC app (Their line-up) from the team
 * post the visitors put out before kick-off. The app saves it to the club's
 * app database, which Media Centre OS reads for the big screen. This reads
 * the same row and keeps its own copy here, so the match page still has it
 * if the app database cannot be reached.
 *
 * Football Web Pages carries line-ups only once somebody enters them there,
 * often after the game, so the feed's line-up wins whenever it has one.
 *
 * The address and key below are the app database's public ones (the same
 * the app itself ships with); the table only lets anyone read, and only
 * signed-in club staff write.
 *
 * @package eastwood-fwp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EW_OPP_DB  = 'https://gimtavaqkkowjwffeefm.supabase.co';
const EW_OPP_KEY = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImdpbXRhdmFxa2tvd2p3ZmZlZWZtIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NjI5NDU2OTAsImV4cCI6MjA3ODUyMTY5MH0.YQ8YU3Updk25rIkufNcuVOiaLI7Gt-AekH2VGGOzaMI';

/** The saved line-up for one match: array( opponent, xi, subs ) or null. */
function ew_opp_lineup( $match_id ) {
	$match_id = (int) $match_id;
	if ( ! $match_id ) {
		return null;
	}
	$copies = (array) get_option( 'ew_opp_lineups', array() );
	$cached = get_transient( 'ew_opp_lu_' . $match_id );
	if ( false !== $cached ) {
		return $copies[ $match_id ] ?? null;
	}
	$res = wp_remote_get(
		EW_OPP_DB . '/rest/v1/match_opponent_lineups?select=opponent,xi,subs,updated_at&match_id=eq.' . $match_id,
		array(
			'timeout' => 5,
			'headers' => array( 'apikey' => EW_OPP_KEY, 'Authorization' => 'Bearer ' . EW_OPP_KEY ),
		)
	);
	// Look again in two minutes either way; a failed look keeps the copy already here.
	set_transient( 'ew_opp_lu_' . $match_id, 1, 2 * MINUTE_IN_SECONDS );
	if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		return $copies[ $match_id ] ?? null;
	}
	$rows = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	$row  = is_array( $rows ) && ! empty( $rows[0] ) ? $rows[0] : null;
	if ( $row && ! empty( $row['xi'] ) ) {
		$copies[ $match_id ] = array(
			'opponent' => (string) ( $row['opponent'] ?? '' ),
			'xi'       => array_values( (array) $row['xi'] ),
			'subs'     => array_values( (array) ( $row['subs'] ?? array() ) ),
			'saved'    => (string) ( $row['updated_at'] ?? '' ),
		);
		update_option( 'ew_opp_lineups', $copies, false );
	}
	return $copies[ $match_id ] ?? null;
}

/** One saved player in the shape the feed uses, so the match page draws it the same way. */
function ew_opp_as_feed( $p, $sort ) {
	$name  = trim( (string) ( $p['name'] ?? '' ) );
	$parts = preg_split( '/\s+/', $name, 2 );
	return array(
		'player' => array( 'first-name' => $parts[0] ?? '', 'last-name' => $parts[1] ?? '' ),
		'shirt'  => isset( $p['number'] ) && null !== $p['number'] ? (int) $p['number'] : '',
		'sort'   => $sort,
	);
}

/**
 * Give the visitors the saved line-up when the feed has none for them.
 * Returns array( home, away ), changed or not.
 */
function ew_opp_fill( $m, $home, $away ) {
	if ( (string) ( $home['id'] ?? '' ) !== EW_TEAM || ! empty( $away['line-up'] ) ) {
		return array( $home, $away );
	}
	$saved = ew_opp_lineup( $m['id'] ?? 0 );
	if ( ! $saved ) {
		return array( $home, $away );
	}
	$list = array();
	foreach ( $saved['xi'] as $i => $p ) {
		$list[] = ew_opp_as_feed( $p, $i + 1 );
	}
	foreach ( $saved['subs'] as $i => $p ) {
		$list[] = ew_opp_as_feed( $p, 12 + $i );
	}
	$away['line-up'] = $list;
	return array( $home, $away );
}
