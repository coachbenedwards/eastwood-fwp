<?php
/**
 * The next home game, and the club it is against.
 *
 * One answer for every page about "the game coming up": the claim page, the
 * half-time sign-up, the match-day banner, the away fans page, and when paid
 * tickets go on sale. A home game stays next until forty minutes after its
 * kick-off (half time, 3:40pm for a 3pm game); then the one after takes over.
 *
 * A block opts in with the field follow="next". A block without it keeps the
 * game typed into it, which is how a link made for one game (an email to the
 * Clay Cross families) stays on that game.
 *
 * Each club's look (white crest, colour, text colour) is set once at
 * Bookings > Club colours. A club with nothing set shows its own crest from
 * the league badges, on Eastwood red.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** When a game stops being the next one: forty minutes after kick-off, UK time. */
function ew_ng_switch_at( $m ) {
	$d = (string) ( $m['date'] ?? '' );
	$t = (string) ( $m['time'] ?? '' );
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) {
		return 0;
	}
	if ( ! preg_match( '/^\d{1,2}:\d{2}$/', $t ) ) {
		$t = '15:00';
	}
	try {
		$k = new DateTimeImmutable( $d . ' ' . $t, new DateTimeZone( 'Europe/London' ) );
	} catch ( Exception $e ) {
		return 0;
	}
	return $k->getTimestamp() + 40 * MINUTE_IN_SECONDS;
}

/** Home games from the league feed, corrected, oldest first. */
function ew_ng_home_games() {
	$out = array();
	if ( ! function_exists( 'ew_hts_fixtures' ) || ! function_exists( 'ew_hts_is_home' ) ) {
		return $out;
	}
	foreach ( ew_hts_fixtures() as $m ) {
		if ( ew_hts_is_home( $m ) ) {
			$out[] = $m;
		}
	}
	return $out;
}

/** The next home game ($skip 1 = the one after it), or null. */
function ew_ng_next( $skip = 0 ) {
	$now = time();
	$i   = 0;
	foreach ( ew_ng_home_games() as $m ) {
		$at = ew_ng_switch_at( $m );
		if ( $at && $at > $now ) {
			if ( $i === (int) $skip ) {
				return $m;
			}
			$i++;
		}
	}
	return null;
}

/** Is this fixture id the next home game right now? */
function ew_ng_is_next( $id ) {
	$m = ew_ng_next();
	return $m && (int) ( $m['id'] ?? 0 ) === (int) $id;
}

/** One fixture by feed id. */
function ew_ng_match( $id ) {
	if ( ! function_exists( 'ew_hts_fixtures' ) ) {
		return null;
	}
	foreach ( ew_hts_fixtures() as $m ) {
		if ( (int) ( $m['id'] ?? 0 ) === (int) $id ) {
			return $m;
		}
	}
	return null;
}

/** A club's look: white crest, colour and the text colour that sits on it. */
function ew_ng_club( $id ) {
	$all   = (array) get_option( 'ew_club_style', array() );
	$row   = (array) ( $all[ (int) $id ] ?? array() );
	$crest = function_exists( 'ew_home_badge' ) ? (string) ew_home_badge( (int) $id ) : '';
	$white = trim( (string) ( $row['white'] ?? '' ) );
	$col   = sanitize_hex_color( (string) ( $row['colour'] ?? '' ) );
	$ink   = sanitize_hex_color( (string) ( $row['ink'] ?? '' ) );
	return array(
		// The ticket picture for the game against this club (empty: the standard one).
		'bg'     => trim( (string) ( $row['bg'] ?? '' ) ),
		// The sponsor of the game against this club, shown on every ticket for it (empty: no sponsor).
		'sponsor' => trim( (string) ( $row['sponsor'] ?? '' ) ),
		'crest'  => $crest,
		'white'  => '' !== $white ? $white : $crest,
		'colour' => $col ? $col : '#CC0000',
		'ink'    => $ink ? $ink : '#FFFFFF',
	);
}

/** Everything a page needs to say about one game. */
function ew_ng_game( $m ) {
	if ( ! $m ) {
		return null;
	}
	$away = (array) ( $m['away-team'] ?? array() );
	$name = (string) ( $away['name'] ?? '' );
	$ts   = ew_ng_switch_at( $m ) - 40 * MINUTE_IN_SECONDS;
	$tz   = new DateTimeZone( 'Europe/London' );
	$d    = ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz );
	$min  = (int) $d->format( 'i' );
	$time = $d->format( 'g' ) . ( $min ? ':' . $d->format( 'i' ) : '' ) . $d->format( 'A' );
	$club = ew_ng_club( (int) ( $away['id'] ?? 0 ) );
	return array(
		'id'         => (int) ( $m['id'] ?? 0 ),
		'away'       => $name,
		'away_id'    => (int) ( $away['id'] ?? 0 ),
		'kickoff'    => $ts,
		'ymd'        => $d->format( 'Y-m-d' ),
		'day'        => $d->format( 'l' ),
		'date'       => $d->format( 'j F Y' ),
		'short'      => $d->format( 'l j F' ),
		'time'       => $time,
		'stub_day'   => $d->format( 'jS' ),
		'stub_month' => $d->format( 'F Y' ),
		'event'      => 'Eastwood FC v ' . $name . ', ' . $d->format( 'l j F Y' ),
		'crest'      => $club['crest'],
		'white'      => $club['white'],
		'colour'     => $club['colour'],
		'ink'        => $club['ink'],
		'bg'         => $club['bg'],
		'sponsor'    => $club['sponsor'],
	);
}

/** The next home game, ready to show; null when none is fixed. */
function ew_ng_now() {
	return ew_ng_game( ew_ng_next() );
}

/** Does a block follow the next game? */
function ew_ng_follows( $a ) {
	return 'next' === strtolower( trim( (string) ( $a['follow'] ?? '' ) ) );
}

/* --- Club colours screen ---------------------------------------------- */

add_action( 'admin_menu', function () {
	add_submenu_page( 'edit.php?post_type=ewt_booking', 'Club colours', 'Club colours', 'edit_pages', 'ew-club-colours', 'ew_ng_admin' );
} );

function ew_ng_admin() {
	if ( ! current_user_can( 'edit_pages' ) ) {
		return;
	}
	$all = (array) get_option( 'ew_club_style', array() );
	if ( isset( $_POST['ew_cc'] ) && check_admin_referer( 'ew_cc' ) ) {
		$all = array();
		foreach ( (array) wp_unslash( $_POST['ew_cc'] ) as $id => $r ) {
			$id = (int) $id;
			$w  = esc_url_raw( trim( (string) ( $r['white'] ?? '' ) ) );
			$c  = sanitize_hex_color( trim( (string) ( $r['colour'] ?? '' ) ) );
			$k  = sanitize_hex_color( trim( (string) ( $r['ink'] ?? '' ) ) );
			$b  = esc_url_raw( trim( (string) ( $r['bg'] ?? '' ) ) );
			$sp = esc_url_raw( trim( (string) ( $r['sponsor'] ?? '' ) ) );
			if ( $id && ( $w || $c || $k || $b || $sp ) ) {
				$all[ $id ] = array( 'white' => $w, 'colour' => (string) $c, 'ink' => (string) $k, 'bg' => $b, 'sponsor' => $sp );
			}
		}
		update_option( 'ew_club_style', $all, false );
		echo '<div class="updated"><p>Saved.</p></div>';
	}
	$next = ew_ng_now();
	echo '<div class="wrap"><h1>Club colours</h1>';
	echo '<p>Next home game: <strong>' . esc_html( $next ? $next['event'] : 'none fixed' ) . '</strong></p>';
	echo '<form method="post">';
	wp_nonce_field( 'ew_cc' );
	echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Date</th><th>Club</th><th>Crest</th><th>White crest (address)</th><th>Colour</th><th>Text on colour</th><th>Ticket picture (address)</th><th>Ticket sponsor logo (address, blank for none)</th></tr></thead><tbody>';
	$seen = array();
	foreach ( ew_ng_home_games() as $m ) {
		$g = ew_ng_game( $m );
		if ( ! $g || isset( $seen[ $g['away_id'] ] ) ) {
			continue;
		}
		$seen[ $g['away_id'] ] = 1;
		$r = (array) ( $all[ $g['away_id'] ] ?? array() );
		printf(
			'<tr><td>%1$s</td><td>%2$s</td><td><span style="display:inline-block;background:%7$s;padding:4px;border-radius:4px"><img src="%3$s" style="height:36px;width:auto;display:block"></span></td>'
			. '<td><input type="url" name="ew_cc[%4$d][white]" value="%5$s" style="width:100%%"></td>'
			. '<td><input type="text" name="ew_cc[%4$d][colour]" value="%6$s" placeholder="#CC0000" size="9"></td>'
			. '<td><input type="text" name="ew_cc[%4$d][ink]" value="%8$s" placeholder="#FFFFFF" size="9"></td>'
			. '<td><input type="url" name="ew_cc[%4$d][bg]" value="%9$s" style="width:100%%"></td>'
			. '<td><input type="url" name="ew_cc[%4$d][sponsor]" value="%10$s" style="width:100%%"></td></tr>',
			esc_html( $g['short'] ),
			esc_html( $g['away'] ),
			esc_url( $g['white'] ),
			(int) $g['away_id'],
			esc_attr( (string) ( $r['white'] ?? '' ) ),
			esc_attr( (string) ( $r['colour'] ?? '' ) ),
			esc_attr( $g['colour'] ),
			esc_attr( (string) ( $r['ink'] ?? '' ) ),
			esc_attr( (string) ( $r['bg'] ?? '' ) ),
			esc_attr( (string) ( $r['sponsor'] ?? '' ) )
		);
	}
	echo '</tbody></table><p><button class="button button-primary">Save</button></p></form></div>';
}

/* --- Keep these pages fresh in the CDN ---------------------------------- */

/**
 * Cloudflare holds a page for thirty days and ignores a shorter header from
 * here, so a page that follows the next game checks it in the browser: it
 * asks the site which game is next (the REST answer is never cached) and,
 * when that is not the game it was drawn for, loads itself again with the
 * game in the address. A new address is a fresh page, drawn for the new game.
 */
add_action( 'rest_api_init', function () {
	register_rest_route( 'eastwood/v1', '/next-game', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => function () {
			$g = ew_ng_now();
			return new WP_REST_Response( array( 'id' => $g ? $g['id'] : 0 ), 200 );
		},
	) );
} );

/** The few lines a following block prints, once per page. */
function ew_ng_guard( $id ) {
	static $done = false;
	if ( $done ) {
		return '';
	}
	$done = true;
	$url  = esc_url_raw( rest_url( 'eastwood/v1/next-game' ) );
	return '<script>(function(){var mine=' . (int) $id . ';fetch(' . wp_json_encode( $url ) . ',{cache:"no-store"}).then(function(r){return r.json()}).then(function(j){var id=+(j&&j.id)||0;if(!id||id===mine)return;var u=new URL(location.href);if(u.searchParams.get("ng")===String(id))return;u.searchParams.set("ng",id);location.replace(u.toString());}).catch(function(){});})();</script>';
}
