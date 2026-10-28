<?php
/**
 * Player photographs, in every finish, for anything on the site to use.
 *
 * Each first-team player shot in the studio has the same picture in four
 * finishes under assets/players/, named after him as the squad list
 * spells him (Luke Rawson → luke-rawson):
 *
 *   transparent/<name>.png  the cut-out on nothing, for laying over any background
 *   black/<name>.jpg        on near-black (#111111)
 *   white/<name>.jpg        on white
 *   textured/<name>.jpg     on the greyed Coronation Park backdrop the home page
 *                           shirt panel uses
 *   <name>.jpg              the textured one at squad-card size (600×758),
 *                           which the squad page shows
 *
 * The four finishes are 1200×1516 (the squad card's shape, at twice its
 * size) apart from the cut-out, which keeps the player's own outline.
 *
 * @package eastwood-fwp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** A player's name as his photo files are named. */
function ew_player_slug( $name ) {
	return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $name ) ), '-' );
}

/**
 * The address of a player's photo in one finish, or '' if he has none yet.
 *
 * @param string $name  As the squad list has him, e.g. 'Luke Rawson'.
 * @param string $style 'transparent', 'black', 'white', 'textured' or 'card'.
 */
function ew_player_photo( $name, $style = 'card' ) {
	$slug = ew_player_slug( $name );
	$dir  = dirname( __DIR__ ) . '/assets/players/';
	$rel  = 'card' === $style ? $slug . '.jpg'
		: ( 'transparent' === $style ? 'transparent/' . $slug . '.png' : sanitize_key( $style ) . '/' . $slug . '.jpg' );
	if ( '' === $slug || ! file_exists( $dir . $rel ) ) {
		return '';
	}
	return plugins_url( 'assets/players/' . $rel, dirname( __DIR__ ) . '/eastwood-fwp.php' ) . '?v=' . EW_FWP_VERSION;
}
