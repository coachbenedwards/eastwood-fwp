<?php
/**
 * Plugin Name: Eastwood — club data
 * Description: Everything the Eastwood site needs from outside WordPress: the Football Web Pages proxy (live fixtures, results, league table and full match detail), the club-badge store, and the importer that pulls the club's news across from Pitchero.
 * Version: 3.2.0
 * Author: Eastwood FC
 *
 * INSTALL: a normal plugin at wp-content/plugins/eastwood-fwp/. Updates come
 * from the public repository (see the bottom of this file) and appear under
 * Plugins like any other. TO REMOVE: deactivate and delete.
 *
 * The API key is NOT in this file. Paste it once at Settings → Eastwood FWP;
 * it lives in the options table.
 *
 * Eastwood is team 2059. United Counties Premier Division North is comp 143.
 * Responses are cached for five minutes, which keeps us inside the ten
 * requests a minute the licence allows however busy the page gets.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EW_TEAM = '2059';
const EW_COMP = '143';

/* ------------------------------------------------------------------ *
 * The writer's credit and where an article came from.
 *
 * Articles are imported from the club's own site, written by people who
 * have no WordPress account, so the byline travels with the post.
 * ------------------------------------------------------------------ */

add_action( 'init', function () {
	foreach ( array( 'ew_byline', 'ew_source' ) as $key ) {
		register_post_meta( 'post', $key, array(
			'type'         => 'string',
			'single'       => true,
			'default'      => '',
			'show_in_rest' => true,
		) );
	}
} );

/* ------------------------------------------------------------------ *
 * Settings → Eastwood FWP
 * ------------------------------------------------------------------ */

add_action( 'admin_menu', function () {
	add_options_page( 'Eastwood FWP', 'Eastwood FWP', 'manage_options', 'eastwood-fwp', 'ew_fwp_settings_page' );
} );

/**
 * Every club we might need a crest for, by Football Web Pages team id.
 *
 * The league list alone is not enough: cup ties and friendlies put clubs on the
 * fixture list that are in no competition we subscribe to. Cotgrave (3453) in
 * the Notts Senior Cup was the case that found this — a crest-shaped hole on the
 * front page on the morning of the tie.
 *
 * 'ew-store' says where a club's crest actually comes from. There are two
 * stores and they are NOT interchangeable:
 *
 *   club   — ew_badge_install() pulled it from the club's own website at up to
 *            512x512 and remembers the URL in ew_badge_sources, so the weekly
 *            refresh keeps it current. This is the good one.
 *   fwp    — the Football Web Pages graphics host, 64px. The fallback for a
 *            club we have no proper source for.
 *
 * The Football Web Pages sweep must therefore SKIP anything in the club store.
 * It did not, once: the 2.11.0 sweep replaced 23 crests pulled from clubs' own
 * sites with 64px copies, Eastwood's own among them (166KB -> 7KB). The sources
 * survived in the option, so a refresh put them back, but nothing in the code
 * stopped it. Now something does.
 *
 * @return array List of array( 'id', 'label', 'names', 'ew-source' => league|cup, 'ew-store' => club|fwp ).
 */
function ew_badge_candidates( $key ) {
	if ( '' === $key ) {
		return array();
	}

	$sources = (array) get_option( 'ew_badge_sources', array() );

	$add = function ( &$out, $tm, $source ) use ( $sources ) {
		$id = (int) ( $tm['id'] ?? 0 );
		if ( ! $id || isset( $out[ $id ] ) ) {
			return;
		}
		// Short name first: the graphics host files crests under it.
		$names = array_values( array_unique( array_filter( array(
			trim( (string) ( $tm['name'] ?? '' ) ),
			trim( (string) ( $tm['full-name'] ?? '' ) ),
		) ) ) );
		if ( ! $names ) {
			return;
		}
		$out[ $id ] = array(
			'id'        => $id,
			'label'     => $names[0],
			'names'     => $names,
			'ew-source' => $source,
			'ew-store'  => empty( $sources[ $id ]['url'] ) ? 'fwp' : 'club',
		);
	};

	$out = array();

	$t = wp_remote_get( 'https://api.footballwebpages.co.uk/v2/teams.json?comp=' . EW_COMP, array(
		'timeout' => 15,
		'headers' => array( 'FWP-API-Key' => $key ),
	) );
	if ( ! is_wp_error( $t ) ) {
		$j = json_decode( wp_remote_retrieve_body( $t ), true );
		foreach ( (array) ( $j['teams'] ?? array() ) as $tm ) {
			$add( $out, $tm, 'league' );
		}
	}

	$fx = ew_fwp_fetch( 'fixtures-results' );
	foreach ( (array) ( $fx['fixtures-results']['matches'] ?? array() ) as $m ) {
		foreach ( array( 'home-team', 'away-team' ) as $side ) {
			$add( $out, (array) ( $m[ $side ] ?? array() ), 'cup' );
		}
	}

	// And the clubs in ties we add by hand. The feed has never heard of them,
	// so without this they never reach this screen and never get a crest --
	// which is exactly what happened to Lye Town in the Vase.
	foreach ( ew_fixture_adds() as $m ) {
		foreach ( array( 'home-team', 'away-team' ) as $side ) {
			$add( $out, (array) ( $m[ $side ] ?? array() ), 'cup' );
		}
	}

	$out = array_values( $out );
	usort( $out, function ( $a, $b ) {
		return $a['ew-source'] === $b['ew-source']
			? strcasecmp( $a['label'], $b['label'] )
			: ( 'league' === $a['ew-source'] ? -1 : 1 );
	} );
	return $out;
}

/**
 * The crest bytes for a club, or null if Football Web Pages publishes none.
 *
 * Two things measured on the graphics host, both of which the first importer
 * got wrong:
 *
 * 1. The crest is filed under the club's SHORT name, not its full one.
 *    "Eastwood CFC" gives eastwood-cfc.png, which is the crest; "Eastwood
 *    Community" gives nothing. So try every name the API hands us.
 * 2. It answers 200 to any slug at all. For one it does not know it generates
 *    a grey shield carrying the first letter. Saving that is worse than saving
 *    nothing, because the front end already falls back to an initials disc in
 *    the site's own styling. So fetch the shield for a slug that cannot exist
 *    but starts with the same letter, and treat a byte-identical answer as
 *    "no crest". The decoy is per-letter and cached for the request.
 *
 * @param string[] $names Candidate club names.
 * @return string|null PNG bytes, or null.
 */
function ew_badge_download( $names ) {
	static $decoys = array();

	$get = function ( $s ) {
		$g = wp_remote_get( 'https://www.footballwebpages.co.uk/graphics/teams/64/' . $s . '.png', array( 'timeout' => 20 ) );
		if ( is_wp_error( $g ) || 200 !== (int) wp_remote_retrieve_response_code( $g ) ) {
			return '';
		}
		return (string) wp_remote_retrieve_body( $g );
	};

	$tried = array();
	foreach ( (array) $names as $name ) {
		$slug = trim( preg_replace( '/-+/', '-', preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $name ) ) ), '-' );
		if ( '' === $slug || isset( $tried[ $slug ] ) ) {
			continue;
		}
		$tried[ $slug ] = true;

		$body = $get( $slug );
		if ( '' === $body ) {
			continue;
		}

		$initial = substr( $slug, 0, 1 );
		if ( ! isset( $decoys[ $initial ] ) ) {
			$decoys[ $initial ] = md5( $get( $initial . 'qxzj-no-such-club' ) );
		}
		if ( md5( $body ) !== $decoys[ $initial ] ) {
			return $body;
		}
	}

	return null;
}

function ew_fwp_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( isset( $_POST['ew_fwp_key'] ) && check_admin_referer( 'ew_fwp_save' ) ) {
		update_option( 'ew_fwp_key', sanitize_text_field( wp_unslash( $_POST['ew_fwp_key'] ) ) );
		echo '<div class="notice notice-success"><p>Saved.</p></div>';
	}

	$key = (string) get_option( 'ew_fwp_key', '' );
	$set = '' !== $key ? 'set (' . strlen( $key ) . ' characters)' : 'not set';
	$dir = WP_CONTENT_DIR . '/uploads/ew-badges/';

	echo '<div class="wrap"><h1>Eastwood FWP</h1>';
	echo '<p>Football Web Pages API key: <strong>' . esc_html( $set ) . '</strong></p>';
	echo '<form method="post">';
	wp_nonce_field( 'ew_fwp_save' );
	echo '<p><input type="password" name="ew_fwp_key" size="50" autocomplete="off" placeholder="paste key here"></p>';
	submit_button( 'Save key' );
	echo '</form>';
	echo '<p>Test it: <code>' . esc_html( home_url( '/wp-json/eastwood/v1/fwp?endpoint=league-table&comp=' . EW_COMP ) ) . '</code></p>';

	/* ------------------------------------------------------------------
	 * Eastwood TV reads the club YouTube channel. It does that with no
	 * key at all, by reading the channel own pages, and that works --
	 * but YouTube can change those pages under us, and the fallback
	 * carries no running times and no way to tell a short from a clip.
	 * A Data API key is their supported way in and fixes both. It is
	 * pasted here rather than put in the code, so it never reaches the
	 * repository.
	 * ------------------------------------------------------------------ */

	if ( isset( $_POST['ew_yt_key'] ) && check_admin_referer( 'ew_yt_save' ) ) {
		update_option( 'ew_yt_api_key', sanitize_text_field( wp_unslash( $_POST['ew_yt_key'] ) ) );
		if ( function_exists( 'ew_yt_forget' ) ) {
			ew_yt_forget();
		}
		echo '<div class="notice notice-success"><p>Saved. Eastwood TV will read the channel again on the next page view.</p></div>';
	}

	if ( isset( $_POST['ew_yt_refresh'] ) && check_admin_referer( 'ew_yt_refresh' ) ) {
		if ( function_exists( 'ew_yt_forget' ) ) {
			ew_yt_forget();
		}
		echo '<div class="notice notice-success"><p>Cleared. The next page view goes and looks at the channel again.</p></div>';
	}

	/* ------------------------------------------------------------------
	 * Square. The shop plugin takes the card payment, and that needs
	 * three values from the Square dashboard. They are pasted here, the
	 * same way the other keys are, so none of them reaches the
	 * repository. The token is write-once: the screen never shows it
	 * back, only whether one is set and how long it is.
	 * ------------------------------------------------------------------ */

	if ( isset( $_POST['ew_sq_save'] ) && check_admin_referer( 'ew_sq_save' ) ) {
		foreach ( array( 'ew_sq_app_id', 'ew_sq_location_id', 'ew_sq_token' ) as $field ) {
			if ( isset( $_POST[ $field ] ) && '' !== trim( (string) wp_unslash( $_POST[ $field ] ) ) ) {
				update_option( $field, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ), false );
			}
		}
		if ( isset( $_POST['ew_sq_env'] ) ) {
			update_option( 'ew_sq_env', 'live' === $_POST['ew_sq_env'] ? 'live' : 'sandbox', false );
		}
		echo '<div class="notice notice-success"><p>Saved.</p></div>';
	}

	$sq_app = (string) get_option( 'ew_sq_app_id', '' );
	$sq_loc = (string) get_option( 'ew_sq_location_id', '' );
	$sq_tok = (string) get_option( 'ew_sq_token', '' );
	$sq_env = 'live' === get_option( 'ew_sq_env', 'sandbox' ) ? 'live' : 'sandbox';

	echo '<h2>Square</h2>';
	echo '<p>Card payments in the club shop. These three come from the Square dashboard, under Developer, and are stored on this site only.</p>';
	echo '<form method="post"><table class="form-table"><tbody>';
	wp_nonce_field( 'ew_sq_save' );
	printf(
		'<tr><th scope="row">Application ID</th><td><input type="text" name="ew_sq_app_id" size="50" value="%s" autocomplete="off"></td></tr>',
		esc_attr( $sq_app )
	);
	printf(
		'<tr><th scope="row">Location ID</th><td><input type="text" name="ew_sq_location_id" size="30" value="%s" autocomplete="off"></td></tr>',
		esc_attr( $sq_loc )
	);
	printf(
		'<tr><th scope="row">Access token</th><td><input type="password" name="ew_sq_token" size="50" autocomplete="off" placeholder="%s"><p class="description">%s</p></td></tr>',
		esc_attr( '' !== $sq_tok ? 'set (' . strlen( $sq_tok ) . ' characters) -- paste a new one to replace it' : 'paste token here' ),
		esc_html( 'Left blank, the token already saved is kept.' )
	);
	printf(
		'<tr><th scope="row">Mode</th><td><label><input type="radio" name="ew_sq_env" value="sandbox"%s> Sandbox</label> &nbsp; <label><input type="radio" name="ew_sq_env" value="live"%s> Live</label></td></tr>',
		'sandbox' === $sq_env ? ' checked' : '',
		'live' === $sq_env ? ' checked' : ''
	);
	echo '</tbody></table>';
	submit_button( 'Save Square settings', 'primary', 'ew_sq_save', false );
	echo '</form>';

	$yt_key   = function_exists( 'ew_yt_api_key' ) ? ew_yt_api_key() : '';
	$yt_route = (string) get_option( 'ew_yt_route', '' );
	$yt_says  = array(
		'api'  => 'the YouTube Data API',
		'page' => 'the channel videos page (no key)',
		'feed' => 'the channel public feed (no key, no running times)',
	);

	echo '<h2>Eastwood TV</h2>';
	echo '<p>Channel: <code>' . esc_html( function_exists( 'ew_yt_handle' ) ? ew_yt_handle() : '' ) . '</code>';
	if ( isset( $yt_says[ $yt_route ] ) ) {
		echo ' &mdash; last read through ' . esc_html( $yt_says[ $yt_route ] ) . '.';
	}
	echo '</p>';
	echo '<p>YouTube Data API key: <strong>' .
		esc_html( '' !== $yt_key ? 'set (' . strlen( $yt_key ) . ' characters)' : 'not set' ) .
		'</strong></p>';
	echo '<form method="post">';
	wp_nonce_field( 'ew_yt_save' );
	echo '<p><input type="password" name="ew_yt_key" size="50" autocomplete="off" placeholder="paste key here"></p>';
	submit_button( 'Save YouTube key', 'primary', 'submit', false );
	echo '</form>';
	echo '<form method="post" style="margin:8px 0 16px">';
	wp_nonce_field( 'ew_yt_refresh' );
	submit_button( 'Read the channel again now', 'secondary', 'ew_yt_refresh', false );
	echo '</form>';

	$teams = ew_badge_candidates( $key );

	// Put the club-sourced crests back, at full resolution, from their own sites.
	if ( isset( $_POST['ew_badge_resync'] ) && check_admin_referer( 'ew_badge_resync' ) ) {
		$urls = array();
		foreach ( (array) get_option( 'ew_badge_sources', array() ) as $team => $row ) {
			if ( ! empty( $row['url'] ) ) {
				$urls[ $team ] = $row['url'];
			}
		}
		$done = 0;
		$bad  = array();
		foreach ( $urls as $team => $url ) {
			// Force it: ew_badge_install() skips a source whose bytes match the
			// stored hash, which would leave an overwritten file exactly as it is.
			// Re-read each time — ew_badge_install() writes this option itself.
			$meta = (array) get_option( 'ew_badge_sources', array() );
			unset( $meta[ $team ]['hash'] );
			update_option( 'ew_badge_sources', $meta, false );
			$r = ew_badge_install( $team, $url );
			if ( is_wp_error( $r ) ) {
				$bad[] = $team . ' (' . $r->get_error_message() . ')';
			} else {
				$done++;
			}
		}
		echo '<div class="notice notice-success"><p>Re-fetched ' . (int) $done . ' crests from the clubs\' own sites.</p></div>';
		if ( $bad ) {
			echo '<div class="notice notice-error"><p>Could not re-fetch: ' . esc_html( implode( ', ', $bad ) ) . '</p></div>';
		}
		$teams = ew_badge_candidates( $key );
	}

	// The API carries no crests, so they are stored by Football Web Pages team id.
	if ( $teams && isset( $_POST['ew_fwp_import'] ) && check_admin_referer( 'ew_fwp_import' ) ) {
		wp_mkdir_p( $dir );
		$got     = 0;
		$skipped = array();
		$kept    = array();
		foreach ( $teams as $tm ) {
			// Never write 64px over a crest pulled from the club's own site.
			if ( 'club' === $tm['ew-store'] ) {
				$kept[] = $tm['label'];
				continue;
			}
			$body = ew_badge_download( $tm['names'] );
			if ( null === $body ) {
				$skipped[] = $tm['label'];
				continue;
			}
			if ( false !== file_put_contents( $dir . $tm['id'] . '.png', $body ) ) {
				$got++;
			}
		}
		echo '<div class="notice notice-success"><p>Imported ' . (int) $got . ' badges from Football Web Pages.</p></div>';
		if ( $kept ) {
			echo '<div class="notice notice-info"><p>Left alone, because they come from the club\'s own site at a higher resolution: ' .
				esc_html( implode( ', ', $kept ) ) . '</p></div>';
		}
		if ( $skipped ) {
			echo '<div class="notice notice-warning"><p>No real crest published for: ' .
				esc_html( implode( ', ', $skipped ) ) . '. These fall back to the initials disc.</p></div>';
		}
	}

	echo '<h2>Club badges</h2>';
	echo '<p>Served from <code>wp-content/uploads/ew-badges/</code>, named after the club id. A club without one falls back to an initials disc.</p>';

	if ( $teams ) {
		echo '<form method="post" style="margin-bottom:8px">';
		wp_nonce_field( 'ew_badge_resync' );
		submit_button( 'Re-fetch crests from the clubs\' own sites', 'primary', 'ew_badge_resync', false );
		echo '</form>';
		echo '<form method="post" style="margin-bottom:16px">';
		wp_nonce_field( 'ew_fwp_import' );
		submit_button( 'Fill the gaps from Football Web Pages', 'secondary', 'ew_fwp_import', false );
		echo '</form>';

		echo '<table class="widefat striped" style="max-width:860px"><thead><tr><th>Club</th><th>Fixture list</th><th>Crest comes from</th><th>File name</th><th>Status</th></tr></thead><tbody>';
		foreach ( $teams as $tm ) {
			$file = $tm['id'] . '.png';
			$path = $dir . $file;
			$have = file_exists( $path );
			$px   = '';
			if ( $have ) {
				$g  = @getimagesize( $path );
				$px = $g ? $g[0] . '&times;' . $g[1] : '';
			}
			echo '<tr><td>' . esc_html( $tm['label'] ) . '</td><td>' .
				esc_html( 'cup' === $tm['ew-source'] ? 'cup or friendly' : 'league' ) . '</td><td>' .
				( 'club' === $tm['ew-store'] ? 'the club&rsquo;s own site' : 'Football Web Pages' ) . '</td><td><code>' .
				esc_html( $file ) . '</code></td><td>' .
				( $have ? '&#10003; in place ' . $px : '&mdash; missing' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p>The competition logo above each fixture uses <code>comp-' . esc_html( EW_COMP ) . '.png</code> in the same folder, and stays hidden until one is there.</p>';
	} else {
		echo '<p><em>Save the key above to list the clubs.</em></p>';
	}

	do_action( 'ew_fwp_after_badges' );
	do_action( 'ew_fwp_after_settings' );

	echo '</div>';
}

/* ------------------------------------------------------------------ *
 * /claimtickets/
 *
 * Until October 2026 the whole domain was a GoDaddy forward to a Fanbase
 * fixture page, so eastwoodfc.com WAS the ticket link. Pointing the domain at
 * this site ends that forward, and the ticket link has to survive it — it is
 * on printed matter and in posts that are already out.
 *
 * A DNS record cannot redirect one path, so it lives here instead. The target
 * is an option rather than a constant because it is a SINGLE FIXTURE on
 * Fanbase: the id changes every home game. Hard-coding it would mean a plugin
 * release before every match, which is exactly the trap the homepage was in.
 * ------------------------------------------------------------------ */

const EW_CLAIM_SLUG    = 'claimtickets';
const EW_CLAIM_DEFAULT = 'https://app.fanbaseclub.com/fixture/eastwoodfc/23383';

function ew_claim_url() {
	$url = trim( (string) get_option( 'ew_claim_url', '' ) );
	return '' !== $url ? $url : EW_CLAIM_DEFAULT;
}

add_action( 'template_redirect', function () {
	if ( is_admin() ) {
		return;
	}

	$path = trim( (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );
	if ( EW_CLAIM_SLUG !== strtolower( $path ) ) {
		return;
	}

	$url = ew_claim_url();
	if ( '' === $url ) {
		return;
	}

	// 302, never 301. The fixture changes; a permanent redirect would stay in
	// people's browsers pointing at last month's game and could not be undone.
	nocache_headers();
	wp_redirect( $url, 302 );
	exit;
}, 1 );

add_action( 'ew_fwp_after_settings', function () {
	if ( isset( $_POST['ew_claim_url'] ) && check_admin_referer( 'ew_claim_save' ) ) {
		$raw = trim( (string) wp_unslash( $_POST['ew_claim_url'] ) );
		update_option( 'ew_claim_url', '' === $raw ? '' : esc_url_raw( $raw ) );
		echo '<div class="notice notice-success"><p>Ticket link saved.</p></div>';
	}

	echo '<h2>Ticket link</h2>';
	echo '<p><code>' . esc_html( home_url( '/' . EW_CLAIM_SLUG . '/' ) ) . '</code> sends people straight to wherever you point it below. '
		. 'Change it when the fixture changes &mdash; no one needs to touch the website.</p>';
	echo '<form method="post">';
	wp_nonce_field( 'ew_claim_save' );
	echo '<p><input type="url" name="ew_claim_url" size="70" value="' . esc_attr( ew_claim_url() ) . '"></p>';
	submit_button( 'Save ticket link' );
	echo '</form>';
	echo '<p>Leave it as it is and it keeps going to the fixture it is on now. '
		. 'It redirects temporarily, so changing it here takes effect straight away.</p>';
} );

/* ------------------------------------------------------------------ *
 * The Football Web Pages proxy.
 *
 * Their API sends no CORS headers, so a browser cannot call it at all.
 * ------------------------------------------------------------------ */

add_action( 'rest_api_init', function () {
	register_rest_route( 'eastwood/v1', '/fwp', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => 'ew_fwp_proxy',
	) );

	register_rest_route( 'eastwood/v1', '/sideload', array(
		'methods'             => 'POST',
		'permission_callback' => function () { return current_user_can( 'upload_files' ); },
		'callback'            => 'ew_fwp_sideload',
	) );

	register_rest_route( 'eastwood/v1', '/import-article', array(
		'methods'             => 'POST',
		'permission_callback' => function () { return current_user_can( 'publish_posts' ); },
		'callback'            => 'ew_import_article',
	) );
} );

/**
 * Fixtures the league has changed that the feed has not caught up with.
 *
 * Football Web Pages is the source for everything on the fixtures page, and
 * when the league moves a game the feed can keep the old one for a while. A
 * match id listed here is dropped everywhere fixtures are read, so the site
 * never shows a game that is not happening.
 *
 * Take an id out again once the feed agrees: leaving one here after the fact
 * would hide a real fixture. The id is in the match-centre address for that
 * game, /match/<id>/.
 */
function ew_stale_matches() {
	return array(
		// 24 Oct 2026, Clay Cross Town v Eastwood. The league moved it; the
		// same afternoon now holds Eastwood v Deeping Rangers, which the feed
		// also carries, so both were showing.
		585757,
	);
}

/**
 * Games hidden only while the feed still has them on the wrong day.
 *
 * A plain drop by id is a trap: when the league finally re-dates the game
 * the feed carries it again on its real date, and the drop is still there
 * hiding a fixture that is now correct -- and nobody is watching for that.
 *
 * So a drop here names the date it is for. The game disappears while the
 * feed says that date and comes back by itself the moment the feed says
 * anything else. Nothing to remember and nothing to undo.
 *
 * @return array id => the date (Y-m-d) the game is hidden on.
 */
function ew_moved_matches() {
	return array(
		// Eastwood v Newark Town. Eastwood are away at Lye Town in the FA
		// Vase that afternoon instead; the league game will be given a new
		// date, and will reappear on its own when it is.
		585774 => '2026-10-31',
	);
}

/**
 * Games the feed does not carry at all.
 *
 * Football Web Pages covers the United Counties League. A cup run outside
 * it -- the FA Vase -- is simply absent, so the club's own fixture list
 * would show a free Saturday on the day of a cup tie. These are merged in
 * and then sorted with the rest, so one entry here reaches the strip, the
 * homepage, the matches page and the hospitality page together.
 *
 * Ids are ours and start well above anything the feed issues, so they can
 * never collide with a real match. Take one out once the feed carries it.
 */
function ew_fixture_adds() {
	return array(
		array(
			'id'          => 990001,
			'date'        => '2026-10-31',
			'time'        => '15:00',
			'status'      => array( 'short' => '' ),
			'venue'       => 'The Sports Ground (Lye)',
			// 30 is the FA Vase. The id is what puts the competition badge
			// on a fixture -- assets/comps/30.png -- so a cup tie added by
			// hand needs it as much as a game the feed carries, or it is
			// the one fixture on the site with a blank corner.
			'competition' => array( 'id' => 30, 'name' => 'FA Vase Second Round' ),
			// Team ids for a club the feed does not carry start at 990101, so
			// they sit clear of both the feed and the 9900xx fixture ids. The
			// id is the crest file name, so a club with id 0 can never have
			// one: it falls back to the initials disc for good.
			'home-team'   => array( 'id' => 990101, 'name' => 'Lye Town' ),
			'away-team'   => array( 'id' => (int) EW_TEAM, 'name' => 'Eastwood FC' ),
		),
	);
}

/**
 * Apply every correction we keep over the feed, in one place, so server-side
 * reads and the browser-side proxy cannot drift apart.
 */
/**
 * Corrections to the fixture feed, kept in one place.
 *
 * Football Web Pages is the source for every fixture on the site, and when
 * the league moves a game the feed can carry the old one for weeks. Until
 * now the only answer was a match id written into the code here, which
 * meant a release and a push to take one game down, and could only delete
 * a fixture, never amend one -- so a game moved to a new date vanished
 * instead of moving.
 *
 * Corrections now live in an option that Fixtures in the admin menu
 * writes, and they are applied at the one point every block reads
 * through. Change a kick-off once and the strip, the homepage, the
 * matches page and the match page all agree, because they are all reading
 * the same corrected answer rather than each being patched.
 *
 * @return array id => array( 'drop' => bool, 'date', 'time', 'venue', 'note' )
 */
function ew_fixture_fixes() {
	$fixes = array();

	// The ids that were written into the code before there was a screen.
	// They stay until the feed agrees, and read as a plain removal.
	foreach ( ew_stale_matches() as $id ) {
		$fixes[ (int) $id ] = array( 'drop' => true );
	}

	// Hidden only while the feed still has them on the wrong day.
	foreach ( ew_moved_matches() as $id => $on ) {
		$fixes[ (int) $id ] = array( 'drop' => true, 'on' => (string) $on );
	}

	foreach ( (array) get_option( 'ew_fixture_fixes', array() ) as $id => $row ) {
		$id = (int) $id;
		if ( ! $id ) {
			continue;
		}
		$fixes[ $id ] = array_merge( isset( $fixes[ $id ] ) ? $fixes[ $id ] : array(), (array) $row );
	}

	return $fixes;
}

/**
 * Corrections the feed has since disagreed with.
 *
 * A correction records what the feed said at the moment it was made. If
 * the feed later says something else, the two have fallen out, and the
 * club's answer is the one the site keeps showing -- but somebody is told,
 * rather than the change being swallowed.
 *
 * This is the rule Ben picked: the edit holds, the row goes red, a person
 * settles it. The alternatives were the feed overwriting the edit
 * overnight with nobody told, and the edit winning for ever so that a
 * game genuinely moved a second time could never reach the site.
 *
 * @return array id => array( field => array( 'mine' => string, 'theirs' => string ) )
 */
/**
 * The league's own list of our games, and nothing of ours on top of it.
 *
 * Two things are not corrections and always apply: the feed calls us
 * "Eastwood CFC", and a cup tie the feed does not carry at all has to be
 * in the list or it cannot be seen. Everything else -- a kick-off we have
 * changed, a game we have taken off -- is ours, and is deliberately NOT
 * applied here, because this is the copy our own answers get compared
 * against.
 *
 * @return array
 */
function ew_fixtures_league_list() {
	$feed = ew_fwp_fetch( 'fixtures-results', array( 'team' => EW_TEAM ), true );
	$all  = (array) ( $feed['fixtures-results']['matches'] ?? array() );
	if ( ! $all ) {
		return array();
	}

	$all  = ew_fwp_fix_names( $all );
	$have = array();
	foreach ( $all as $m ) {
		$have[ (int) ( $m['id'] ?? 0 ) ] = true;
	}
	foreach ( ew_fixture_adds() as $add ) {
		if ( empty( $have[ (int) $add['id'] ] ) ) {
			$all[] = $add;
		}
	}

	usort( $all, function ( $a, $b ) {
		return strcmp(
			(string) ( $a['date'] ?? '' ) . (string) ( $a['time'] ?? '' ),
			(string) ( $b['date'] ?? '' ) . (string) ( $b['time'] ?? '' )
		);
	} );

	return $all;
}

function ew_fixture_clashes() {
	$out   = array();
	$saved = (array) get_option( 'ew_fixture_fixes', array() );
	if ( ! $saved ) {
		return $out;
	}

	$all = ew_fixtures_league_list();
	if ( ! $all ) {
		return $out;
	}

	$by = array();
	foreach ( $all as $m ) {
		$by[ (int) ( $m['id'] ?? 0 ) ] = $m;
	}

	foreach ( $saved as $id => $row ) {
		$id  = (int) $id;
		$row = (array) $row;
		if ( ! isset( $by[ $id ] ) ) {
			continue;
		}
		$seen = isset( $row['seen'] ) ? (array) $row['seen'] : array();

		foreach ( array( 'date', 'time', 'venue' ) as $k ) {
			if ( ! isset( $row[ $k ] ) || '' === trim( (string) $row[ $k ] ) ) {
				continue;
			}
			// A correction made before the screen started remembering has
			// nothing to compare against. It is not a disagreement.
			if ( ! isset( $seen[ $k ] ) ) {
				continue;
			}
			$now = trim( (string) ( $by[ $id ][ $k ] ?? '' ) );
			if ( '' === $now || $now === trim( (string) $seen[ $k ] ) ) {
				continue;
			}
			$out[ $id ][ $k ] = array(
				'mine'   => (string) $row[ $k ],
				'theirs' => $now,
			);
		}
	}

	return $out;
}

/**
 * Settle one disagreement.
 *
 * Keeping ours moves the mark to what the feed says now, so the row goes
 * quiet until the feed changes again. Taking theirs drops our correction
 * for that field and the feed's answer flows through on its own.
 */
add_action( 'admin_post_ew_fixture_resolve', function () {
	if ( ! current_user_can( 'edit_pages' ) ) {
		wp_die( 'Not allowed.' );
	}
	check_admin_referer( 'ew_fixture_resolve' );

	$id    = (int) ( $_REQUEST['id'] ?? 0 );
	$field = sanitize_key( (string) ( $_REQUEST['field'] ?? '' ) );
	$keep  = 'keep' === sanitize_key( (string) ( $_REQUEST['choice'] ?? '' ) );

	if ( $id && in_array( $field, array( 'date', 'time', 'venue' ), true ) ) {
		$saved = (array) get_option( 'ew_fixture_fixes', array() );
		if ( isset( $saved[ $id ] ) ) {
			$row = (array) $saved[ $id ];

			if ( $keep ) {
				foreach ( ew_fixtures_league_list() as $m ) {
					if ( (int) ( $m['id'] ?? 0 ) === $id ) {
						$seen           = isset( $row['seen'] ) ? (array) $row['seen'] : array();
						$seen[ $field ] = (string) ( $m[ $field ] ?? '' );
						$row['seen']    = $seen;
						break;
					}
				}
			} else {
				unset( $row[ $field ] );
				if ( isset( $row['seen'] ) ) {
					$seen = (array) $row['seen'];
					unset( $seen[ $field ] );
					$row['seen'] = $seen;
				}
			}

			$bare = array_filter( $row, function ( $k ) {
				return 'seen' !== $k;
			}, ARRAY_FILTER_USE_KEY );

			if ( $bare ) {
				$saved[ $id ] = $row;
			} else {
				unset( $saved[ $id ] );
			}
			update_option( 'ew_fixture_fixes', $saved );
		}
	}

	wp_safe_redirect( admin_url( 'admin.php?page=ew-fixtures&settled=1' ) );
	exit;
} );

/** One match, with whatever correction applies to it. Null means removed. */
function ew_fixture_apply( $m ) {
	$fixes = ew_fixture_fixes();
	$id    = (int) ( $m['id'] ?? 0 );
	if ( ! $id || ! isset( $fixes[ $id ] ) ) {
		return $m;
	}

	$fix = $fixes[ $id ];

	// A fix can name the date it is for. If the feed has since moved the
	// game, the fix no longer applies and the real fixture comes back.
	if ( ! empty( $fix['on'] ) && (string) $fix['on'] !== (string) ( $m['date'] ?? '' ) ) {
		return $m;
	}

	if ( ! empty( $fix['drop'] ) ) {
		return null;
	}

	// Only the fields somebody actually filled in. An empty box in the
	// screen means "leave the feed alone", not "blank it".
	foreach ( array( 'date', 'time', 'venue' ) as $k ) {
		if ( isset( $fix[ $k ] ) && '' !== trim( (string) $fix[ $k ] ) ) {
			$m[ $k ] = (string) $fix[ $k ];
		}
	}

	return $m;
}

/**
 * Every match in a payload, corrected.
 *
 * The feed hands matches back in more than one shape -- a list under
 * fixtures-results, a single game under match -- and a correction has to
 * reach all of them, or the match page disagrees with the page that linked
 * to it. So this walks the payload rather than naming one path.
 */
function ew_fixture_apply_all( $node ) {
	if ( ! is_array( $node ) ) {
		return $node;
	}

	// A list of matches: correct each, drop the removed, keep it in date order
	// so a game moved to a new date sits where it now belongs.
	$is_list = isset( $node[0] ) && is_array( $node[0] ) && isset( $node[0]['id'] );
	if ( $is_list ) {
		$out  = array();
		$have = array();
		$ours = false;
		foreach ( $node as $m ) {
			$m = ew_fixture_apply( (array) $m );
			if ( null !== $m ) {
				$out[] = $m;
				$have[ (int) ( $m['id'] ?? 0 ) ] = true;
				if ( (int) ( $m['home-team']['id'] ?? 0 ) === (int) EW_TEAM
					|| (int) ( $m['away-team']['id'] ?? 0 ) === (int) EW_TEAM ) {
					$ours = true;
				}
			}
		}

		// Only a list that is already Eastwood's own fixtures, so a league
		// table or another club's list is never handed a game of ours.
		if ( $ours && isset( $node[0]['home-team'] ) ) {
			foreach ( ew_fixture_adds() as $add ) {
				if ( empty( $have[ (int) $add['id'] ] ) ) {
					$out[] = $add;
				}
			}
		}
		usort( $out, function ( $a, $b ) {
			return strcmp(
				(string) ( $a['date'] ?? '' ) . (string) ( $a['time'] ?? '' ),
				(string) ( $b['date'] ?? '' ) . (string) ( $b['time'] ?? '' )
			);
		} );
		return $out;
	}

	// A single match.
	if ( isset( $node['id'] ) && ( isset( $node['date'] ) || isset( $node['home-team'] ) ) ) {
		$fixed = ew_fixture_apply( $node );
		return null === $fixed ? $node : $fixed;
	}

	foreach ( $node as $k => $v ) {
		$node[ $k ] = ew_fixture_apply_all( $v );
	}
	return $node;
}

/* --- Fixtures: the screen that writes those corrections ------------- */

add_action( 'admin_menu', function () {
	// A red row nobody opens the screen to see is not a flag. The count
	// rides on the menu the way the plugin-update count does.
	$n     = count( ew_fixture_clashes() );
	$title = $n
		? 'Fixtures <span class="update-plugins count-' . (int) $n . '"><span class="update-count">'
			. (int) $n . '</span></span>'
		: 'Fixtures';

	add_menu_page(
		'Fixtures', $title, 'edit_pages', 'ew-fixtures',
		'ew_fixtures_admin_page', 'dashicons-calendar-alt', 30
	);
} );

add_action( 'admin_init', function () {
	register_setting( 'ew_fixtures_group', 'ew_fixture_fixes', array(
		'type'              => 'array',
		'sanitize_callback' => 'ew_fixture_fixes_sanitise',
		'default'           => array(),
	) );
} );

add_filter( 'option_page_capability_ew_fixtures_group', function () {
	return 'edit_pages';
} );

/**
 * Keep only the rows somebody actually filled in.
 *
 * A screen with a box against every fixture posts a row for every fixture,
 * nearly all of them empty. Storing those would turn a short list of
 * corrections into a wall of nothing, and worse, would look like a
 * correction when it is not one.
 */
function ew_fixture_fixes_sanitise( $in ) {
	$out = array();

	// What is stored right now, so that a field nobody touched keeps the
	// mark it already had. Without this, opening the screen and pressing
	// save would quietly settle every disagreement on it.
	$was = (array) get_option( 'ew_fixture_fixes', array() );

	foreach ( (array) $in as $id => $row ) {
		$id  = (int) $id;
		$row = (array) $row;
		if ( ! $id ) {
			continue;
		}

		$keep = array();
		$seen = array();
		$was_row  = isset( $was[ $id ] ) ? (array) $was[ $id ] : array();
		$was_seen = isset( $was_row['seen'] ) ? (array) $was_row['seen'] : array();
		$now_seen = isset( $row['seen'] ) ? (array) $row['seen'] : array();

		/**
		 * Remember what the feed said about this field as the box was
		 * filled in -- unless the box has not changed, in which case the
		 * mark it already carries is the one that matters.
		 *
		 * @param string $k     Field name.
		 * @param string $value What is being saved.
		 */
		$mark = function ( $k, $value ) use ( $was_row, $was_seen, $now_seen, &$seen ) {
			if ( isset( $was_row[ $k ] ) && (string) $was_row[ $k ] === (string) $value
				&& isset( $was_seen[ $k ] ) ) {
				$seen[ $k ] = (string) $was_seen[ $k ];
				return;
			}
			if ( isset( $now_seen[ $k ] ) ) {
				$seen[ $k ] = sanitize_text_field( (string) $now_seen[ $k ] );
			}
		};

		if ( ! empty( $row['drop'] ) ) {
			$keep['drop'] = true;
		}
		// A date the feed can read back: 2026-11-07. Anything else is
		// dropped rather than written, because a date the feed cannot
		// parse would sort the fixture to the top of the season.
		$date = trim( (string) ( $row['date'] ?? '' ) );
		if ( '' !== $date && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$keep['date'] = $date;
			$mark( 'date', $date );
		}
		$time = trim( (string) ( $row['time'] ?? '' ) );
		if ( '' !== $time && preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
			$keep['time'] = $time;
			$mark( 'time', $time );
		}
		$venue = sanitize_text_field( (string) ( $row['venue'] ?? '' ) );
		if ( '' !== trim( $venue ) ) {
			$keep['venue'] = $venue;
			$mark( 'venue', $venue );
		}
		$note = sanitize_text_field( (string) ( $row['note'] ?? '' ) );
		if ( '' !== trim( $note ) ) {
			$keep['note'] = $note;
		}

		if ( $seen ) {
			$keep['seen'] = $seen;
		}

		if ( array_diff_key( $keep, array( 'seen' => 1 ) ) ) {
			$out[ $id ] = $keep;
		}
	}

	return $out;
}

function ew_fixtures_admin_page() {
	if ( ! current_user_can( 'edit_pages' ) ) {
		return;
	}

	$saved   = (array) get_option( 'ew_fixture_fixes', array() );
	$clashes = ew_fixture_clashes();
	$live    = ew_fixture_fixes();
	$all     = ew_fixtures_league_list();

	// The feed as it arrived, not as the site shows it. The boxes hold
	// what we say; behind each box is what the league says, and the two
	// can only be told apart if this screen reads the league's own copy.
	$today = current_time( 'Y-m-d' );
	$next  = array();
	foreach ( $all as $m ) {
		if ( (string) ( $m['date'] ?? '' ) >= $today ) {
			$next[] = $m;
		}
	}
	$next = array_slice( $next, 0, 14 );
	?>
	<div class="wrap">
		<h1>Fixtures</h1>
		<p style="max-width:48em;font-size:14px">
			These come from the league feed. When the league moves a game the
			feed can carry the old one for weeks, and this is where you say so.
			A change here reaches the whole site at once &mdash; the strip at the
			top of the homepage, the matches page and the match page itself.
			<b>Leave a box empty to keep what the feed says.</b>
		</p>
		<p style="max-width:48em;font-size:14px">
			If the league later changes something you have corrected, your
			answer stays on the site and the row below turns red so somebody
			can settle it.
		</p>

		<?php if ( isset( $_GET['settled'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p>Settled.</p></div>
		<?php endif; ?>

		<?php if ( $clashes ) : ?>
			<div class="notice notice-error"><p>
				<b><?php echo count( $clashes ); ?></b>
				<?php echo 1 === count( $clashes ) ? 'fixture has' : 'fixtures have'; ?>
				a correction the league now disagrees with. They are marked below.
			</p></div>
		<?php endif; ?>

		<?php if ( ! $next ) : ?>
			<p><em>No fixtures came back from the feed just now. Reload in a
				minute &mdash; it rate-limits.</em></p>
		<?php else : ?>
		<form method="post" action="options.php">
			<?php settings_fields( 'ew_fixtures_group' ); ?>
			<table class="widefat striped" style="max-width:1100px;margin-top:18px">
				<thead><tr>
					<th style="width:30%">Fixture</th>
					<th style="width:14%">Date</th>
					<th style="width:11%">Kick-off</th>
					<th style="width:27%">Venue</th>
					<th style="width:18%">Not happening</th>
				</tr></thead>
				<tbody>
				<?php foreach ( $next as $m ) :
					$id   = (int) ( $m['id'] ?? 0 );
					if ( ! $id ) { continue; }
					$row  = isset( $saved[ $id ] ) ? (array) $saved[ $id ] : array();
					$home = (string) ( $m['home-team']['name'] ?? '' );
					$away = (string) ( $m['away-team']['name'] ?? '' );
					$ts   = strtotime( (string) ( $m['date'] ?? '' ) );
					$clash = isset( $clashes[ $id ] ) ? $clashes[ $id ] : array();
					?>
				<tr<?php echo $clash ? ' style="background:#fcf0f1;box-shadow:inset 4px 0 0 #d63638"' : ''; ?>>
					<td>
						<strong><?php echo esc_html( $home . ' v ' . $away ); ?></strong><br>
						<span style="color:#646970;font-size:12px">
							<?php echo esc_html( $ts ? date_i18n( 'D j M Y', $ts ) : '' ); ?>
							<?php echo esc_html( (string) ( $m['time'] ?? '' ) ); ?>
							&nbsp;·&nbsp; id <?php echo (int) $id; ?>
						</span>
					</td>
					<td><input type="text" style="width:100%"
						name="ew_fixture_fixes[<?php echo (int) $id; ?>][date]"
						value="<?php echo esc_attr( $row['date'] ?? '' ); ?>"
						placeholder="<?php echo esc_attr( (string) ( $m['date'] ?? '' ) ); ?>"></td>
					<td><input type="text" style="width:100%"
						name="ew_fixture_fixes[<?php echo (int) $id; ?>][time]"
						value="<?php echo esc_attr( $row['time'] ?? '' ); ?>"
						placeholder="<?php echo esc_attr( (string) ( $m['time'] ?? '' ) ); ?>"></td>
					<td><input type="text" style="width:100%"
						name="ew_fixture_fixes[<?php echo (int) $id; ?>][venue]"
						value="<?php echo esc_attr( $row['venue'] ?? '' ); ?>"
						placeholder="<?php echo esc_attr( (string) ( $m['venue'] ?? '' ) ); ?>"></td>
					<?php foreach ( array( 'date', 'time', 'venue' ) as $ew_f ) : ?>
					<input type="hidden"
						name="ew_fixture_fixes[<?php echo (int) $id; ?>][seen][<?php echo esc_attr( $ew_f ); ?>]"
						value="<?php echo esc_attr( (string) ( $m[ $ew_f ] ?? '' ) ); ?>">
					<?php endforeach; ?>
					<?php
					$in_code = ! empty( $live[ $id ]['drop'] ) && empty( $row['drop'] );
					?>
					<td><label><input type="checkbox" value="1"
						name="ew_fixture_fixes[<?php echo (int) $id; ?>][drop]"
						<?php checked( ! empty( $row['drop'] ) || $in_code ); ?>
						<?php disabled( $in_code ); ?>>
						Take it off the site</label>
						<?php if ( $in_code ) : ?>
						<span style="display:block;color:#646970;font-size:12px;margin-top:4px">
							Already off, by a rule in the plugin
						</span>
						<?php endif; ?>
					</td>
				</tr>
				<?php if ( $clash ) : ?>
				<tr style="background:#fcf0f1;box-shadow:inset 4px 0 0 #d63638">
					<td colspan="5" style="padding-top:0">
						<?php foreach ( $clash as $field => $pair ) :
							$label = array(
								'date'  => 'date',
								'time'  => 'kick-off',
								'venue' => 'venue',
							)[ $field ];
							$keep  = wp_nonce_url( admin_url( 'admin-post.php?action=ew_fixture_resolve&choice=keep&id='
								. $id . '&field=' . $field ), 'ew_fixture_resolve' );
							$take  = wp_nonce_url( admin_url( 'admin-post.php?action=ew_fixture_resolve&choice=theirs&id='
								. $id . '&field=' . $field ), 'ew_fixture_resolve' );
							?>
						<p style="margin:0 0 8px;font-size:13px">
							The league now says the <?php echo esc_html( $label ); ?> is
							<b><?php echo esc_html( $pair['theirs'] ); ?></b>.
							The site is showing <b><?php echo esc_html( $pair['mine'] ); ?></b>.
							&nbsp;
							<a class="button button-primary button-small"
								href="<?php echo esc_url( $keep ); ?>">Keep
								<?php echo esc_html( $pair['mine'] ); ?></a>
							<a class="button button-small"
								href="<?php echo esc_url( $take ); ?>">Use
								<?php echo esc_html( $pair['theirs'] ); ?></a>
						</p>
						<?php endforeach; ?>
					</td>
				</tr>
				<?php endif; ?>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description" style="max-width:48em;margin-top:12px">
				Dates are written <code>2026-11-07</code> and kick-offs
				<code>15:00</code>. Anything else is ignored rather than saved,
				because a date the feed cannot read would throw the fixture to
				the wrong end of the season.
			</p>
			<?php submit_button( 'Save the corrections' ); ?>
		</form>
		<?php endif; ?>

		<?php
		$live = ew_fixture_fixes();
		if ( $live ) : ?>
		<h2 style="margin-top:34px">In force now</h2>
		<ul style="font-size:14px">
			<?php foreach ( $live as $id => $row ) : ?>
			<li><code>#<?php echo (int) $id; ?></code> &mdash;
				<?php echo empty( $row['drop'] )
					? esc_html( implode( ', ', array_filter( array(
						isset( $row['date'] ) ? 'date ' . $row['date'] : '',
						isset( $row['time'] ) ? 'kick-off ' . $row['time'] : '',
						isset( $row['venue'] ) ? 'at ' . $row['venue'] : '',
					) ) ) )
					: 'off the site'; ?></li>
			<?php endforeach; ?>
		</ul>
		<p class="description">A game taken off should come back on once the
			feed agrees, or a real fixture stays hidden.</p>
		<?php endif; ?>
	</div>
	<?php
}

function ew_fwp_corrections( $data ) {
	$data = ew_fwp_fix_names( $data );
	$data = ew_fixture_apply_all( $data );
	return $data;
}

function ew_fwp_proxy( WP_REST_Request $req ) {
	$allowed = array(
		'fixtures-results', 'league-table', 'team', 'teams', 'goalscorers',
		'appearances', 'form-guide', 'match', 'vidiprinter', 'competitions',
		'rounds', 'attendances', 'records', 'sequences', 'league-progress',
	);

	$ep = sanitize_text_field( (string) $req->get_param( 'endpoint' ) );
	if ( ! in_array( $ep, $allowed, true ) ) {
		return new WP_Error( 'fwp_bad_endpoint', 'Endpoint not allowed', array( 'status' => 400 ) );
	}

	$key = (string) get_option( 'ew_fwp_key', '' );
	if ( '' === $key ) {
		return new WP_Error( 'fwp_no_key', 'No API key saved. Settings > Eastwood FWP.', array( 'status' => 503 ) );
	}

	$team  = preg_replace( '/[^0-9]/', '', (string) $req->get_param( 'team' ) );
	$comp  = preg_replace( '/[^0-9]/', '', (string) $req->get_param( 'comp' ) );
	$match = preg_replace( '/[^0-9]/', '', (string) $req->get_param( 'match' ) );
	$date  = preg_replace( '/[^0-9-]/', '', (string) $req->get_param( 'date' ) );

	// The match endpoint is addressed by match id alone. Everything else falls
	// back to Eastwood when the caller names neither team nor competition.
	if ( 'match' !== $ep && '' === $team && '' === $comp ) {
		$team = EW_TEAM;
	}

	$args = array();
	if ( 'match' === $ep ) {
		if ( '' === $match ) {
			return new WP_Error( 'fwp_no_match', 'The match endpoint needs a match id.', array( 'status' => 400 ) );
		}
		$args['match'] = $match;
	} else {
		if ( '' !== $team ) { $args['team'] = $team; }
		if ( '' !== $comp ) { $args['comp'] = $comp; }
		if ( '' !== $date ) { $args['date'] = $date; }
	}

	$url   = add_query_arg( $args, 'https://api.footballwebpages.co.uk/v2/' . $ep . '.json' );
	$cache = 'ewfwp_' . md5( $url );

	$hit = get_transient( $cache );
	if ( false !== $hit ) {
		return new WP_REST_Response( ew_fwp_corrections( (array) json_decode( $hit, true ) ), 200 );
	}

	$res = wp_remote_get( $url, array(
		'timeout' => 15,
		'headers' => array( 'FWP-API-Key' => $key ),
	) );
	if ( is_wp_error( $res ) ) {
		return new WP_Error( 'fwp_unreachable', $res->get_error_message(), array( 'status' => 502 ) );
	}

	$status = (int) wp_remote_retrieve_response_code( $res );
	$body   = (string) wp_remote_retrieve_body( $res );
	if ( 200 !== $status ) {
		return new WP_Error( 'fwp_upstream', 'Football Web Pages returned ' . $status, array( 'status' => 502 ) );
	}

	set_transient( $cache, $body, 5 * MINUTE_IN_SECONDS );
	return new WP_REST_Response( ew_fwp_corrections( (array) json_decode( $body, true ) ), 200 );
}

/**
 * Pull a remote image into the media library and attach it to a post.
 * The browser cannot: the image hosts send no CORS headers.
 */
function ew_fwp_sideload( WP_REST_Request $req ) {
	$url  = esc_url_raw( (string) $req->get_param( 'url' ) );
	$post = absint( $req->get_param( 'post' ) );
	if ( ! $url || ! $post ) {
		return new WP_Error( 'ew_bad_args', 'A url and a post id are both required.', array( 'status' => 400 ) );
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$tmp = download_url( $url, 30 );
	if ( is_wp_error( $tmp ) ) { return $tmp; }

	$name = sanitize_file_name( (string) $req->get_param( 'name' ) );
	if ( ! preg_match( '/\.(jpe?g|png|gif|webp)$/i', $name ) ) {
		$name = 'eastwood-' . $post . '.jpg';
	}

	$id = media_handle_sideload( array( 'name' => $name, 'tmp_name' => $tmp ), $post );
	if ( is_wp_error( $id ) ) { @unlink( $tmp ); return $id; }

	set_post_thumbnail( $post, $id );
	return array( 'attachment' => $id, 'url' => wp_get_attachment_url( $id ) );
}

/* ------------------------------------------------------------------ *
 * The news importer.
 * ------------------------------------------------------------------ */

function ew_import_text( $html ) {
	$t = preg_replace( '#<br\s*/?>#i', "\n", $html );
	$t = wp_strip_all_tags( $t );
	$t = html_entity_decode( $t, ENT_QUOTES, 'UTF-8' );
	return trim( preg_replace( '#[ \t]+#', ' ', $t ) );
}

function ew_import_find_title( $doc ) {
	if ( preg_match_all( '#<h1[^>]*>(.*?)</h1>#si', $doc, $m ) ) {
		foreach ( $m[1] as $h ) {
			$h = ew_import_text( $h );
			if ( '' !== $h && false === stripos( $h, 'Eastwood Football Club' ) ) {
				return $h;
			}
		}
	}
	return '';
}

/**
 * Import one article from the club's own site.
 *
 * The browser cannot read those pages - Pitchero sends no CORS headers - so
 * WordPress fetches and parses them itself.
 */
function ew_import_article( WP_REST_Request $req ) {
	$src = esc_url_raw( (string) $req->get_param( 'source' ) );
	$cat = sanitize_text_field( (string) $req->get_param( 'category' ) );
	if ( '' === $cat ) { $cat = 'Club News'; }
	if ( 0 !== strpos( $src, 'https://www.eastwoodcfc.co.uk/' ) ) {
		return new WP_Error( 'ew_bad_source', 'Only the club site can be imported from.', array( 'status' => 400 ) );
	}

	$res = wp_remote_get( $src, array( 'timeout' => 30, 'user-agent' => 'Mozilla/5.0 (compatible; EastwoodImporter/1.0)' ) );
	if ( is_wp_error( $res ) ) { return $res; }
	$doc = (string) wp_remote_retrieve_body( $res );
	if ( '' === $doc ) {
		return new WP_Error( 'ew_empty', 'That article page came back empty.', array( 'status' => 502 ) );
	}

	$title = ew_import_find_title( $doc );
	if ( '' === $title ) {
		return new WP_Error( 'ew_no_title', 'No headline found on that page.', array( 'status' => 422 ) );
	}

	// Never import the same article twice. Two club updates can share a
	// headline, so the source URL identifies an article, not its title.
	$dupe = get_posts( array(
		'post_type'      => 'post',
		'posts_per_page' => 1,
		'post_status'    => 'any',
		'fields'         => 'ids',
		'meta_key'       => 'ew_source',
		'meta_value'     => $src,
	) );
	if ( $dupe ) {
		return array( 'skipped' => true, 'post' => (int) $dupe[0], 'title' => $title );
	}

	$excerpt = '';
	if ( preg_match_all( '#<h2[^>]*>(.*?)</h2>#si', $doc, $m2 ) ) {
		foreach ( $m2[1] as $h ) {
			$h = ew_import_text( $h );
			if ( strlen( $h ) > 40 ) { $excerpt = $h; break; }
		}
	}

	$paras = array();
	if ( preg_match( '#<div class="bbcode-content">(.*?)</div>#si', $doc, $b ) ) {
		if ( preg_match_all( '#<p>(.*?)</p>#si', $b[1], $ps ) ) {
			foreach ( $ps[1] as $p ) {
				$t = ew_import_text( $p );
				if ( '' !== $t ) { $paras[] = $t; }
			}
		}
	}
	if ( ! $paras ) {
		return new WP_Error( 'ew_no_body', 'No article body found on that page.', array( 'status' => 422 ) );
	}

	$content = '';
	foreach ( $paras as $t ) {
		$content .= "<!-- wp:paragraph -->\n<p>" . str_replace( "\n", '<br>', esc_html( $t ) ) . "</p>\n<!-- /wp:paragraph -->\n\n";
	}

	$when = '';
	if ( preg_match( '#<time dateTime="([^"]+)"#i', $doc, $d ) ) {
		$stamp = strtotime( $d[1] );
		if ( $stamp ) { $when = gmdate( 'Y-m-d H:i:s', $stamp ); }
	}

	$byline = '';
	if ( preg_match( '#<span[^>]*>([^<]{2,60})</span><span[^>]*><time#i', $doc, $a ) ) {
		$byline = ew_import_text( $a[1] );
	}

	$post = array(
		'post_title'   => $title,
		'post_excerpt' => $excerpt,
		'post_content' => trim( $content ),
		'post_status'  => 'publish',
		'post_type'    => 'post',
	);
	if ( '' !== $when ) { $post['post_date_gmt'] = $when; }

	$id = wp_insert_post( $post, true );
	if ( is_wp_error( $id ) ) { return $id; }

	$term = term_exists( $cat, 'category' );
	if ( ! $term ) { $term = wp_insert_term( $cat, 'category' ); }
	if ( ! is_wp_error( $term ) && isset( $term['term_id'] ) ) {
		wp_set_post_terms( $id, array( (int) $term['term_id'] ), 'category', false );
	}

	if ( '' !== $byline ) { update_post_meta( $id, 'ew_byline', $byline ); }
	update_post_meta( $id, 'ew_source', $src );

	$att = ew_import_photo( $doc, $id, $title );

	return array(
		'post'       => (int) $id,
		'title'      => $title,
		'byline'     => $byline,
		'date'       => $when,
		'paragraphs' => count( $paras ),
		'attachment' => (int) $att,
	);
}

/**
 * The article photo. Pitchero serves images through a resizing proxy, so we
 * strip whatever size the page asked for and request a full-width one.
 */
function ew_import_photo( $doc, $id, $title ) {
	if ( ! preg_match( '#https://img-res\.pitchero\.com/\?url=images\.pitchero\.com%2Fui%2F[^",\s]+#i', $doc, $i ) ) {
		return 0;
	}
	$img = html_entity_decode( $i[0], ENT_QUOTES, 'UTF-8' );
	$img = preg_replace( '#&(h|w|t|o)=[^&]*#i', '', $img );
	$img .= '&h=1100&w=1960&t=fit&o=jpg';

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$tmp = download_url( $img, 30 );
	if ( is_wp_error( $tmp ) ) { return 0; }

	$att = media_handle_sideload( array(
		'name'     => sanitize_title( $title ) . '.jpg',
		'tmp_name' => $tmp,
	), $id );
	if ( is_wp_error( $att ) ) { @unlink( $tmp ); return 0; }

	set_post_thumbnail( $id, $att );
	return $att;
}

/* ------------------------------------------------------------------ *
 * Club crests.
 *
 * Football Web Pages only holds 64px crests, which are soft on a retina
 * screen. The good artwork lives on each club's own site, so we keep a
 * local copy, remember where it came from, and re-check once a week. If a
 * club redesigns their crest ours follows within the week; if their site
 * breaks we keep serving the last good copy, because a stale crest beats a
 * hole in the fixture list.
 * ------------------------------------------------------------------ */

function ew_badge_dir() {
	$dir = WP_CONTENT_DIR . '/uploads/ew-badges/';
	wp_mkdir_p( $dir );
	return $dir;
}

function ew_badge_install( $team, $url ) {
	$team = absint( $team );
	$url  = esc_url_raw( $url );
	if ( ! $team || ! $url ) {
		return new WP_Error( 'ew_badge_args', 'A team id and a url are both required.', array( 'status' => 400 ) );
	}

	$res = wp_remote_get( $url, array(
		'timeout'    => 30,
		'user-agent' => 'Mozilla/5.0 (compatible; EastwoodBadges/1.0)',
		'headers'    => array( 'Accept' => 'image/png,image/*' ),
	) );
	if ( is_wp_error( $res ) ) { return $res; }

	$code = (int) wp_remote_retrieve_response_code( $res );
	if ( 200 !== $code ) {
		return new WP_Error( 'ew_badge_http', 'The source returned ' . $code . '.', array( 'status' => 502 ) );
	}

	$body = wp_remote_retrieve_body( $res );
	if ( '' === $body ) {
		return new WP_Error( 'ew_badge_empty', 'The source returned nothing.', array( 'status' => 502 ) );
	}

	$hash = md5( $body );
	$meta = get_option( 'ew_badge_sources', array() );
	$file = ew_badge_dir() . $team . '.png';

	// Unchanged at source and already on disk: just note that we looked.
	if ( isset( $meta[ $team ]['hash'] ) && $meta[ $team ]['hash'] === $hash && file_exists( $file ) ) {
		$meta[ $team ]['checked'] = time();
		update_option( 'ew_badge_sources', $meta, false );
		return array( 'team' => $team, 'unchanged' => true );
	}

	// Not wp_tempnam(): that lives in wp-admin/includes/file.php, which a REST
	// request does not load.
	$tmp = tempnam( get_temp_dir(), 'ewbadge' );
	file_put_contents( $tmp, $body );

	$size = @getimagesize( $tmp );
	if ( ! $size ) {
		@unlink( $tmp );
		return new WP_Error( 'ew_badge_notimage', 'That URL is not an image WordPress can read.', array( 'status' => 422 ) );
	}

	// The image editor sniffs the extension as well as the bytes.
	$types = array( 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp' );
	$ext   = isset( $types[ $size['mime'] ] ) ? $types[ $size['mime'] ] : 'png';
	if ( @rename( $tmp, $tmp . '.' . $ext ) ) {
		$tmp = $tmp . '.' . $ext;
	}

	$editor = wp_get_image_editor( $tmp );
	if ( is_wp_error( $editor ) ) { @unlink( $tmp ); return $editor; }

	// 512 is plenty: the largest slot on the site is 96 CSS pixels.
	if ( $size[0] > 512 || $size[1] > 512 ) {
		$editor->resize( 512, 512, false );
	}
	$saved = $editor->save( $file, 'image/png' );
	@unlink( $tmp );
	if ( is_wp_error( $saved ) ) { return $saved; }

	$meta[ $team ] = array(
		'url'     => $url,
		'hash'    => $hash,
		'checked' => time(),
		'source'  => $size[0] . 'x' . $size[1],
	);
	update_option( 'ew_badge_sources', $meta, false );

	return array(
		'team'   => $team,
		'stored' => $saved['width'] . 'x' . $saved['height'],
		'source' => $size[0] . 'x' . $size[1],
	);
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'eastwood/v1', '/badge', array(
		'methods'             => 'POST',
		'permission_callback' => function () { return current_user_can( 'manage_options' ); },
		'callback'            => function ( WP_REST_Request $req ) {
			return ew_badge_install( $req->get_param( 'team' ), (string) $req->get_param( 'url' ) );
		},
	) );
} );

// Once a week is right for crests: clubs change them between seasons, not
// between fixtures. The yearly sweep sets the sources; this keeps them honest.
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'ew_badge_refresh' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', 'ew_badge_refresh' );
	}
} );

add_action( 'ew_badge_refresh', function () {
	$meta = get_option( 'ew_badge_sources', array() );
	foreach ( $meta as $team => $row ) {
		if ( empty( $row['url'] ) ) { continue; }
		// A failure here leaves the existing file untouched, on purpose.
		ew_badge_install( $team, $row['url'] );
	}
} );

register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'ew_badge_refresh' );
} );

// Where each crest came from, and when we last looked.
add_action( 'ew_fwp_after_badges', function () {
	$meta = get_option( 'ew_badge_sources', array() );
	if ( ! $meta ) { return; }
	$next = wp_next_scheduled( 'ew_badge_refresh' );
	echo '<h2>Where the crests came from</h2>';
	echo '<p>Re-checked weekly. Next check: <strong>' .
		esc_html( $next ? wp_date( 'j M Y, H:i', $next ) : 'not scheduled' ) . '</strong></p>';
	echo '<table class="widefat striped" style="max-width:860px"><thead><tr>' .
		'<th>Club id</th><th>Source</th><th>Original</th><th>Last checked</th></tr></thead><tbody>';
	foreach ( $meta as $team => $row ) {
		$host = wp_parse_url( isset( $row['url'] ) ? $row['url'] : '', PHP_URL_HOST );
		echo '<tr><td>' . (int) $team . '</td>' .
			'<td><a href="' . esc_url( $row['url'] ) . '" target="_blank" rel="noreferrer">' . esc_html( $host ) . '</a></td>' .
			'<td>' . esc_html( isset( $row['source'] ) ? $row['source'] : '' ) . '</td>' .
			'<td>' . esc_html( isset( $row['checked'] ) ? human_time_diff( $row['checked'] ) . ' ago' : '' ) . '</td></tr>';
	}
	echo '</tbody></table>';
} );

/**
 * Crests keep their filenames when they are replaced, so a browser — or a CDN —
 * would happily go on serving yesterday's copy, and the weekly refresh would
 * never be seen. This stamps every crest URL with the time the newest one
 * changed, so a new crest is a new URL and an unchanged one stays cached.
 *
 * Done here rather than in the theme's JavaScript because it then covers every
 * crest on the page whichever script drew it.
 */
add_action( 'wp_enqueue_scripts', function () {
	$newest = 0;
	foreach ( (array) glob( ew_badge_dir() . '*.png' ) as $file ) {
		$newest = max( $newest, (int) filemtime( $file ) );
	}
	if ( ! $newest ) {
		return;
	}

	$js = 'window.EW_BADGE_V=' . $newest . ';'
		. '(function(){'
		. 'var v=' . $newest . ';'
		. 'function stamp(n){'
		. 'if(!n||n.nodeType!==1){return;}'
		. 'var list=n.tagName==="IMG"?[n]:(n.querySelectorAll?n.querySelectorAll("img"):[]);'
		. 'Array.prototype.forEach.call(list,function(img){'
		// srcset wins over src when both are present, so stamp both.
		. '["src","srcset"].forEach(function(a){'
		. 'var s=img.getAttribute(a)||"";'
		. 'if(s.indexOf("/ew-badges/")>-1&&s.indexOf("?v=")<0){'
		. 'img.setAttribute(a,s.split("?")[0]+"?v="+v);'
		. '}});'
		. '});}'
		. 'function sweep(){stamp(document.body);}'
		. 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",sweep);}else{sweep();}'
		. 'new MutationObserver(function(list){list.forEach(function(m){'
		. 'if(m.type==="attributes"){stamp(m.target);}'
		. 'Array.prototype.forEach.call(m.addedNodes||[],stamp);'
		. '});}).observe(document.documentElement,'
		. '{subtree:true,childList:true,attributes:true,attributeFilter:["src","srcset"]});'
		. '})();';

	wp_register_script( 'eastwood-badge-version', false, array(), null, false );
	wp_enqueue_script( 'eastwood-badge-version' );
	wp_add_inline_script( 'eastwood-badge-version', $js );
}, 5 );

/* ------------------------------------------------------------------
 * Icons, sponsors, and getting Forest's assets off the site.
 *
 * The theme's markup was captured from a reference build, so it still
 * points at that club's icon sprite (/gc-icons/...) and at their CDN for
 * sponsor logos. The icon paths 404 here; the CDN images are somebody
 * else's bandwidth and somebody else's sponsors.
 *
 * Rather than edit the theme's markup in a dozen places, the rewrite
 * happens once on the way out. Two benefits: the theme stays a faithful
 * copy of what was captured, and anything else that inherits the same
 * markup is covered by the same pass.
 * ------------------------------------------------------------------ */

/**
 * The club's sponsors, as they appear on the Pitchero site.
 *
 * `src` is where the artwork comes from, so the weekly refresh can pick
 * up a replacement file without anyone touching this list.
 */
function ew_sponsor_roster() {
	$base = 'https://images.pitchero.com/club_sponsors/43098/';
	return array(
		// PodCut and Kam Servicing carry 'ours' as a fifth field. It means
		// the file on disk was put there deliberately and is better than
		// whatever Pitchero holds, so the weekly job must leave it alone.
		// Without it the refresh quietly pulls the old artwork back every
		// seven days and nobody connects the two events.
		'podcut'               => array( 'PodCut',                     'Shirt Sponsor', 'https://podcut.com',                                    $base . '1781094797_large.jpg', 'ours' ),
		'kam-servicing'        => array( 'Kam Servicing',              'Stand Sponsor', 'https://kamservicing.com/',                             $base . '1719140087_large.jpg', 'ours' ),
		'evolve-scaffolding'   => array( 'Evolve Scaffolding',         'Sponsor',       '',                                                      $base . '1723676265_large.jpg', 'ours' ),
	);
}

/**
 * Sponsors who are no longer with the club.
 *
 * The wall is captured markup with a fixed run of slots, so dropping a
 * name from the roster is not enough on its own -- the slot stays, and
 * it stays holding whatever the capture put there. Named here, the
 * whole anchor comes off the page, which also takes the click with it:
 * a wall is a list of people paying, and a lapsed one should not still
 * be sending the club's visitors to their website.
 */
function ew_sponsor_retired() {
	return array( 'evo-waste', 'short-stitch', 'swift-sports-massage', 'stadia-signs', 'eastwood-cars' );
}

/** The whole anchor around one sponsor's mark, or '' if it is not there. */
function ew_sponsor_anchor( $html, $slug ) {
	$at = strpos( $html, 'ew-sponsors/' . $slug . '.png' );
	if ( false === $at ) {
		return '';
	}
	$open = strrpos( substr( $html, 0, $at ), '<a ' );
	if ( false === $open ) {
		return '';
	}
	$shut = strpos( $html, '</a>', $at );
	if ( false === $shut ) {
		return '';
	}
	return substr( $html, $open, ( $shut + 4 ) - $open );
}

function ew_sponsor_dir() {
	$dir = WP_CONTENT_DIR . '/uploads/ew-sponsors/';
	wp_mkdir_p( $dir );
	return $dir;
}

/**
 * A sponsor's artwork.
 *
 * A file shipped in the plugin wins over the uploads copy. Shipping one
 * IS the deliberate act -- it is in the repository, it survives the
 * weekly Pitchero refresh that rewrites uploads, and a fresh install
 * has it. The folder is named ew-sponsors rather than sponsors because
 * the link lookup and the pass that retires a sponsor both read the
 * slug out of the path.
 */
function ew_sponsor_url( $slug ) {
	$ship = plugin_dir_path( __FILE__ ) . 'assets/ew-sponsors/' . $slug . '.png';
	if ( file_exists( $ship ) ) {
		return plugins_url( 'assets/ew-sponsors/' . $slug . '.png', __FILE__ );
	}
	return content_url( '/uploads/ew-sponsors/' . $slug . '.png' );
}

/**
 * A second file for a sponsor, where one reads better than the other.
 *
 * The wall in the footer sits on the red band, and a logo supplied as a
 * white-backgrounded jpeg lands on it as a white tile. The nav already
 * uses a white version of PodCut for the same reason, so the wall uses
 * the same file. Anything with no second version keeps its own.
 */
function ew_sponsor_alt() {
	return array( 'podcut' => 'podcut-white' );
}

/**
 * The right version of a mark for the background it is going on.
 *
 * A brand with one mark has one file. A brand with a white-only mark --
 * PodCut -- needs the white one on the red header and a dark one on a
 * white section, and until now the white section quietly fell back to
 * an older logo that is no longer the brand. A mark can also travel
 * with the plugin, the way crests do, so a fresh install has it.
 *
 * @param string $slug Sponsor slug.
 * @param string $tone 'light' background or 'dark'.
 * @return string
 */
function ew_sponsor_tone_url( $slug, $tone = 'light' ) {
	$want = 'dark' === $tone ? $slug . '-white' : $slug . '-dark';
	if ( file_exists( ew_sponsor_dir() . $want . '.png' ) ) {
		return ew_sponsor_url( $want );
	}
	$ship = plugin_dir_path( __FILE__ ) . 'assets/ew-sponsors/' . $want . '.png';
	if ( file_exists( $ship ) ) {
		return plugins_url( 'assets/ew-sponsors/' . $want . '.png', __FILE__ );
	}
	return ew_sponsor_wall_url( $slug );
}

function ew_sponsor_wall_url( $slug ) {
	$alt = ew_sponsor_alt();
	if ( isset( $alt[ $slug ] ) && file_exists( ew_sponsor_dir() . $alt[ $slug ] . '.png' ) ) {
		return ew_sponsor_url( $alt[ $slug ] );
	}
	return ew_sponsor_url( $slug );
}

/**
 * The sponsor a file belongs to, whichever of its files it is.
 *
 * Both the link lookup and the pass that removes a retired sponsor read
 * the slug straight out of the filename. Pointing the wall at
 * podcut-white.png without this would have made PodCut look like a
 * sponsor nobody has heard of: no link, and then the anchor deleted
 * outright as a slot belonging to nobody on the roster.
 */
function ew_sponsor_slug_of( $file ) {
	$alt = array_flip( ew_sponsor_alt() );
	return isset( $alt[ $file ] ) ? $alt[ $file ] : $file;
}

/**
 * Download one sponsor logo and keep it locally. Same shape as
 * ew_badge_install(): unchanged bytes at source are a no-op, and a
 * failure leaves whatever is already on disk alone.
 */
function ew_sponsor_install( $slug, $url ) {
	$slug = sanitize_key( $slug );
	$url  = esc_url_raw( $url );
	if ( '' === $slug || '' === $url ) {
		return new WP_Error( 'ew_sponsor_args', 'A slug and a url are both required.', array( 'status' => 400 ) );
	}

	$res = wp_remote_get( $url, array(
		'timeout'    => 30,
		'user-agent' => 'Mozilla/5.0 (compatible; EastwoodSponsors/1.0)',
		'headers'    => array( 'Accept' => 'image/*' ),
	) );
	if ( is_wp_error( $res ) ) { return $res; }

	$code = (int) wp_remote_retrieve_response_code( $res );
	if ( 200 !== $code ) {
		return new WP_Error( 'ew_sponsor_http', 'The source returned ' . $code . '.', array( 'status' => 502 ) );
	}

	$body = wp_remote_retrieve_body( $res );
	if ( '' === $body ) {
		return new WP_Error( 'ew_sponsor_empty', 'The source returned nothing.', array( 'status' => 502 ) );
	}

	$hash = md5( $body );
	$meta = get_option( 'ew_sponsor_sources', array() );
	$file = ew_sponsor_dir() . $slug . '.png';

	if ( isset( $meta[ $slug ]['hash'] ) && $meta[ $slug ]['hash'] === $hash && file_exists( $file ) ) {
		$meta[ $slug ]['checked'] = time();
		update_option( 'ew_sponsor_sources', $meta, false );
		return array( 'slug' => $slug, 'unchanged' => true );
	}

	// See ew_badge_install(): wp_tempnam() is not loaded during REST.
	$tmp = tempnam( get_temp_dir(), 'ewsponsor' );
	file_put_contents( $tmp, $body );

	$size = @getimagesize( $tmp );
	if ( ! $size ) {
		@unlink( $tmp );
		return new WP_Error( 'ew_sponsor_notimage', 'That URL is not an image WordPress can read.', array( 'status' => 422 ) );
	}

	$types = array( 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp' );
	$ext   = isset( $types[ $size['mime'] ] ) ? $types[ $size['mime'] ] : 'png';
	if ( @rename( $tmp, $tmp . '.' . $ext ) ) {
		$tmp = $tmp . '.' . $ext;
	}

	$editor = wp_get_image_editor( $tmp );
	if ( is_wp_error( $editor ) ) { @unlink( $tmp ); return $editor; }

	// Wordmarks are wide, so cap the long edge rather than a square box.
	// 600 covers a retina render of the largest slot on the page.
	if ( $size[0] > 600 || $size[1] > 600 ) {
		$editor->resize( 600, 600, false );
	}
	$saved = $editor->save( $file, 'image/png' );
	@unlink( $tmp );
	if ( is_wp_error( $saved ) ) { return $saved; }

	$meta[ $slug ] = array(
		'url'     => $url,
		'hash'    => $hash,
		'checked' => time(),
		'source'  => $size[0] . 'x' . $size[1],
		'stored'  => $saved['width'] . 'x' . $saved['height'],
	);
	update_option( 'ew_sponsor_sources', $meta, false );

	return array( 'slug' => $slug, 'stored' => $meta[ $slug ]['stored'], 'source' => $meta[ $slug ]['source'] );
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'eastwood/v1', '/sponsors', array(
		'methods'             => 'POST',
		'permission_callback' => function () { return current_user_can( 'manage_options' ); },
		'callback'            => function () {
			$out = array();
			foreach ( ew_sponsor_roster() as $slug => $row ) {
				if ( 'ours' === ( $row[4] ?? '' ) ) {
					$out[ $slug ] = 'kept — artwork marked as ours';
					continue;
				}
				$r = ew_sponsor_install( $slug, $row[3] );
				$out[ $slug ] = is_wp_error( $r ) ? $r->get_error_message() : $r;
			}
			return $out;
		},
	) );
} );

// Sponsors change less often than crests, but the weekly crest sweep is
// already running, so ride along with it.
add_action( 'ew_badge_refresh', function () {
	$roster = ew_sponsor_roster();
	$meta   = get_option( 'ew_sponsor_sources', array() );
	foreach ( $meta as $slug => $row ) {
		if ( empty( $row['url'] ) ) { continue; }
		if ( 'ours' === ( $roster[ $slug ][4] ?? '' ) ) { continue; }
		ew_sponsor_install( $slug, $row['url'] );
	}
} );

/**
 * The icon set. Thin-stroke line icons at 24x24, drawn for this site so
 * nothing is borrowed from the reference build. They inherit colour from
 * their surroundings, so the same symbol works red on white and white on
 * red without a second copy.
 */
function ew_icon_sprite() {
	if ( is_admin() ) { return; }
	echo '<svg xmlns="http://www.w3.org/2000/svg" style="position:absolute;width:0;height:0;overflow:hidden" aria-hidden="true" focusable="false"><defs>'
		. '<symbol id="ew-home" viewBox="0 0 24 24"><path d="M12 3.5l2.6 5.3 5.9.85-4.25 4.15 1 5.85L12 16.9l-5.25 2.75 1-5.85L3.5 9.65l5.9-.85z"/></symbol>'
		. '<symbol id="ew-ticket" viewBox="0 0 24 24"><path d="M3.2 8.6a2 2 0 0 1 2-2h13.6a2 2 0 0 1 2 2v1.1a2.35 2.35 0 0 0 0 4.6v1.1a2 2 0 0 1-2 2H5.2a2 2 0 0 1-2-2v-1.1a2.35 2.35 0 0 0 0-4.6z"/><path d="M14.6 7.6v1.8M14.6 11.2v1.6M14.6 14.6v1.8"/></symbol>'
		. '<symbol id="ew-venue" viewBox="0 0 24 24"><path d="M7.6 4h8.8l-.7 4.4a3.7 3.7 0 0 1-7.4 0z"/><path d="M12 12.3v6.4M9 19.4h6"/></symbol>'
		. '<symbol id="ew-shop" viewBox="0 0 24 24"><path d="M5.4 8h13.2l-1 11.4a1.7 1.7 0 0 1-1.7 1.6H8.1a1.7 1.7 0 0 1-1.7-1.6z"/><path d="M9.1 8V6.2a2.9 2.9 0 0 1 5.8 0V8"/></symbol>'
		. '<symbol id="ew-sponsor" viewBox="0 0 24 24"><circle cx="12" cy="8.6" r="5.4"/><path d="M8.7 13.2 7.3 21l4.7-2.5 4.7 2.5-1.4-7.8"/></symbol>'
		. '<symbol id="ew-pitch" viewBox="0 0 24 24"><rect x="3.2" y="5.4" width="17.6" height="13.2" rx="1"/><path d="M12 5.4v13.2"/><circle cx="12" cy="12" r="2.4"/><path d="M3.2 9.2h3.1v5.6H3.2M20.8 9.2h-3.1v5.6h3.1"/></symbol>'
		. '<symbol id="ew-ball" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.6"/><path d="M12 8.1l3.3 2.4-1.26 3.9h-4.08L8.7 10.5z"/><path d="M12 8.1V3.5M15.3 10.5l4.35-1.45M14.04 14.4l2.7 3.7M9.96 14.4l-2.7 3.7M8.7 10.5 4.35 9.05"/></symbol>'
		. '<symbol id="ew-account" viewBox="0 0 24 24"><circle cx="12" cy="8.2" r="3.7"/><path d="M4.8 20.4a7.2 7.2 0 0 1 14.4 0"/></symbol>'
		. '<symbol id="ew-search" viewBox="0 0 24 24"><circle cx="10.6" cy="10.6" r="6.4"/><path d="m15.3 15.3 5.5 5.5"/></symbol>'
		. '<symbol id="ew-events" viewBox="0 0 24 24"><rect x="3.4" y="5.2" width="17.2" height="15.4" rx="1.6"/><path d="M3.4 9.8h17.2M8.4 3.4v3.6M15.6 3.4v3.6"/></symbol>'
		. '<symbol id="ew-menu" viewBox="0 0 24 24"><path d="M4 7.2h16M4 12h16M4 16.8h16"/></symbol>'
		. '</defs></svg>';
}
add_action( 'wp_body_open', 'ew_icon_sprite', 1 );

/* ------------------------------------------------------------------
 * Page chrome: the red title band, and the nav that comes back on scroll.
 *
 * Both are things the reference site does and the capture did not bring
 * across. The header markup for the scrolled nav is already in the theme —
 * it sits at top:0, fixed, translated out of sight — but nothing ever put
 * it back. Thirty lines here rather than a theme release.
 * ------------------------------------------------------------------ */

function ew_chrome_css() {
	return '
/* Every inner page has exactly one h1 and it is the page title. White on
   red, full bleed, text lined up with the 1248px column. */
/*
 * THE COLUMN.
 *
 * The reference lays 1248px of content between 96px margins at 1440. That
 * cannot come from a max-width here: every one of these pages sits inside an
 * Elementor row measuring 1200px, which clamps anything wider before it
 * starts. So each container runs the full width of the viewport and its
 * children carry the column padding instead -- which is exactly how the red
 * title band below already lands its text on 1248.
 *
 * The negative margin reads 50% of the Elementor row minus half the viewport,
 * so it works out to whatever that row happens to be and needs no number of
 * its own.
 */
.ew-prose > *,.ewshop > *,.ew-teams-index > *,
.ew-squad > *,.ew-matches > *,.ew-tv > *{
 padding-left:max(16px,calc(50vw - 624px));
 padding-right:max(16px,calc(50vw - 624px))}

h1.elementor-heading-title,
.ew-title,
.ewshop h1{
 background:#CC0000;color:#fff !important;
 font-family:Anton,"Instrument Sans",sans-serif !important;
 font-size:56px !important;font-weight:700 !important;line-height:1;
 letter-spacing:.01em;text-transform:uppercase;
 margin:0 calc(50% - 50vw) !important;
 /* Measured off the reference: the band is 160px and the title sits low in
    it, 88px of red above the cap height and 16px below the baseline. Ours
    was 70/34, which centred the title and read as floating. */
 padding:88px max(16px,calc(50vw - 624px)) 16px !important;
}
@media(max-width:820px){
 h1.elementor-heading-title,
 .ew-title,
 .ewshop h1{font-size:38px !important;padding:32px 16px 34px !important}
}

/* The Shop page keeps its markup in the page itself and arrived from
   elsewhere wearing Inter at 40px in an 1120px column — the only page on the
   site using neither the club typeface nor its width. The band selector
   above now covers its title; the rest is squared up here so the page
   content never has to be edited to keep up. */
.ewshop{max-width:none !important;margin:0 calc(50% - 50vw) !important;padding:0 0 64px !important;
 font-family:"Instrument Sans",system-ui,sans-serif !important}
.ewshop h1{margin:0 calc(50% - 50vw) 34px !important}
.ewshop .lede{font-size:17px;color:#5c5c5c;margin:0 0 34px}
.ewshop-grid{grid-template-columns:repeat(auto-fill,minmax(288px,1fr)) !important;gap:14px !important}
.ewshop-card{border-radius:8px !important}

/* The news hero collapses to 88px because its own min-height class never
   made it into the captured stylesheet — the image inside is absolutely
   positioned, so without it the anchor has nothing to stand on. The grid
   cards below use min-h-[320px], which did survive. */
.min-h-\[420px\]{min-height:420px}

/* The sponsor rail across the top of every page, and the wall in the
   footer. Both of these sat in the home-blocks stylesheet, which only
   prints on a page carrying one of those blocks -- so everywhere else
   the marks fell back to the captured 60px width and came out at
   different heights again. They are chrome, and they belong here.

   The rail: the captured header pins each mark to 60px wide, and a
   width is the wrong thing to pin. Two marks of different proportions
   set to the same width come out different heights, which is why the
   squarer KAM badge towered over PodCut. A rail is balanced on height,
   and the height is the one PodCut already had -- 26, a pixel up from
   25 since PodCut became the real sponsorship lockup, because that
   artwork carries .com under the tail and leaves the wordmark 62 per
   cent of the file where the bare mark was 65. KAM comes down to match
   rather than everything going up. */
a[class*="w-[60px]"]:has(img[src*="/ew-sponsors/"]){width:auto;height:26px}
a[class*="w-[60px]"]:has(img[src*="/ew-sponsors/"]) img{width:auto;height:100%;
 object-fit:contain}

/* The footer wall. The captured markup gives the two upper rows a 128px
   slot each, which is fine for a boxy logo with air around it and far
   too much for a wide wordmark: PodCut came out 427 across and KAM 277,
   towering over the rows beneath. Eighty-four tall with a ceiling on the
   width settles every mark on one line without picking on two of them,
   and leaves the hierarchy intact -- these rows still sit above the 40px
   partners row below. */
/* Sixty-four, down from eighty-four: the two marks at the top of the
   wall were louder than the band they sit in. The ceiling on the width
   comes in with them so a wide wordmark keeps its proportions. */
[class*="lg:h-32"]:has(img[src*="/ew-sponsors/"]){height:52px}
[class*="lg:h-32"]:has(img[src*="/ew-sponsors/"]) img{max-width:160px;margin:0 auto}
/* A wall slot is 52px tall, which suits a wordmark laid out in a line.
   the Evolve mark stacks a monogram over two lines of type, so at 52 the
   type came out around 8px and could not be read. At 84 those lines sit
   at about 14px, which is where the other two marks read. */
[class*="lg:h-32"]:has(img[src*="/ew-sponsors/evolve-scaffolding"]){height:84px}
[class*="lg:h-32"]:has(img[src*="/ew-sponsors/evolve-scaffolding"]) img{max-width:200px}
/* A group whose last sponsor has gone takes its heading with it, rather
   than leaving a title with nothing under it. The wall is not inside a
   <footer> -- it is a band at the foot of the page -- so the group is
   named by its own shape: a centred column with a heading at the top. */
/* An anchor counts only if it carries a mark. The captured wall has
   more slots than the club has sponsors, and an unfilled one is left
   as href="#" around an empty <picture> -- invisible, but enough to
   keep a heading standing over nothing. */
[class*="bg-primary-500"] a[href="#"]:not(:has(img)){display:none}
.flex.flex-col.gap-1.text-center:has(> h2,> h5,> h4):not(:has(a img)){display:none}
/* And it breathes. The captured markup sets 4px between a heading and
   the marks under it, and 16px between one group and the next, which
   reads as one crowded block rather than three tiers. */
[class*="bg-primary-500"] .flex.flex-col.gap-4.py-8{gap:48px}
[class*="bg-primary-500"] .flex.flex-col.gap-1.text-center{gap:20px}

/* The web address under KAM.

   The PodCut sponsorship artwork carries .com set under the tail of
   the wordmark. The KAM artwork does not: theirs is a line inside the
   badge, 16px of a 200px file, which comes out two pixels tall in the
   rail. So it is typeset -- the heavy condensed italic their own
   lettering is cut in, right aligned under the badge the way the
   PodCut one sits under the wordmark. The proportions are taken off
   the PodCut file: its .com is 24 per cent of the artwork height with
   a 7 per cent gap above it, so the KAM badge gives up exactly that
   much and neither the rail nor the wall row ends up any taller than
   it is now. Four characters are fetched, not a whole family. */
a:has(img[src*="/ew-sponsors/kam"]){text-decoration:none}
/* Not in the rail. At 26px the badge has to come down to 17 to make
   room for a line of type six pixels tall, which is a smudge rather
   than an address -- and it cost KAM a third of their mark to print
   it. The wall below has the room for it; the rail does not. */

/* No web address under KAM here either. At the size the wall now runs
   it would be a line of type three pixels tall, and it cost the badge
   the room to be read at all. */
';
}

add_action( 'wp_head', function () {
	/*
	 * Four characters of Barlow Condensed, the heavy condensed italic
	 * KAM's own lettering is cut in, for the .com typeset under their
	 * badge. The text= parameter means Google serves a subset holding
	 * only . c o m -- about two kilobytes -- rather than a family.
	 */
	echo '<link rel="stylesheet" href="https://fonts.googleapis.com/css2'
		. '?family=Barlow+Condensed:ital,wght@1,700&amp;text=.com&amp;display=swap">' . "\n";
	echo '<style id="ew-chrome">' . ew_chrome_css() . '</style>'; // phpcs:ignore
}, 21 );

add_action( 'wp_footer', function () {
	?>
<script id="ew-chrome-js">
(function(){
 var bar = null;
 var all = document.querySelectorAll('header,nav,div');
 for (var i = 0; i < all.length && !bar; i++) {
  var c = all[i].getAttribute('class') || '';
  if (c.indexOf('-translate-y-32') > -1 && c.indexOf('fixed') > -1) { bar = all[i]; }
 }
 if (!bar) { return; }
 var shown = null;
 function sync(){
  var want = (window.pageYOffset || document.documentElement.scrollTop) > 260;
  if (want === shown) { return; }
  shown = want;
  bar.style.transform = want ? 'translateY(0)' : '';
 }
 sync();
 window.addEventListener('scroll', sync, { passive: true });
 window.addEventListener('resize', sync, { passive: true });
})();
(function(){
 /* The title band has to meet the header with no white seam. Its wrappers
    carry the page's own top padding, and the amount differs page to page,
    so it is measured here rather than guessed in the stylesheet. */
 var h1 = document.querySelector('h1.elementor-heading-title');
 if (!h1) { return; }
 var root = document.querySelector('.elementor');
 var el = h1.parentElement, n = 0;
 while (el && el !== root && n < 8) { el.style.paddingTop = '0'; el = el.parentElement; n++; }
})();
</script>
	<?php
}, 21 );

/**
 * Favicons.
 *
 * WordPress's own Site Icon takes a single upload and regenerates every size
 * with its own resampler, which turns a detailed crest into a smear at 16px.
 * These files are tuned per size instead, so we emit the tags ourselves and
 * leave the site_icon option alone.
 */
function ew_favicons() {
	$base = plugins_url( 'assets/favicon/', __FILE__ );
	$v    = EW_FWP_VERSION;
	$tags = array(
		array( 'icon', '16x16', 'favicon-16.png', 'image/png' ),
		array( 'icon', '32x32', 'favicon-32.png', 'image/png' ),
		array( 'icon', '48x48', 'favicon-48.png', 'image/png' ),
		array( 'apple-touch-icon', '180x180', 'favicon-180.png', '' ),
	);
	foreach ( $tags as $t ) {
		printf(
			'<link rel="%s" sizes="%s"%s href="%s">' . "\n",
			esc_attr( $t[0] ),
			esc_attr( $t[1] ),
			$t[3] ? ' type="' . esc_attr( $t[3] ) . '"' : '',
			esc_url( $base . $t[2] . '?v=' . $v )
		);
	}
	printf( '<link rel="shortcut icon" href="%s">' . "\n", esc_url( $base . 'favicon.ico?v=' . $v ) );
}
add_action( 'wp_head', 'ew_favicons', 2 );

/**
 * Which captured icon path maps to which of ours.
 */
function ew_icon_map() {
	return array(
		'fan/star'                 => 'ew-home',
		'fan/ticket'               => 'ew-ticket',
		'fan/hospitality'          => 'ew-venue',
		'fan/store'                => 'ew-shop',
		'fan/account'              => 'ew-sponsor',
		'fan/cellphone'            => 'ew-pitch',
		'fan/ball'                 => 'ew-ball',
		'navigation/account'       => 'ew-account',
		'navigation/search'        => 'ew-search',
		'navigation/burgerSquare'  => 'ew-menu',
	);
}

/**
 * Which captured sponsor slot gets which of ours. Keyed on the file name
 * from the reference CDN, so a slot that moves in the markup still lands
 * on the right sponsor.
 */
function ew_sponsor_slot_map() {
	return array(
		// Header strip: the four paying tiers.
		'8a27fc10-9af4-11f1-bbaf-317542e39775.png' => 'podcut',
		'939f7c20-c9e2-11f0-86a8-912f64b9d4f8.png' => 'podfirst',
		'0a715740-9b13-11f1-a666-4794d3ffe780.png' => 'sb-outdoor-living',
		'93ab3bf0-c9e2-11f0-b1f1-39db3509b32d.png' => 'kam-servicing',
		// Footer rail: all eleven, in the order Pitchero lists them.
		'eaf2c400-9b0b-11f1-93ff-7915f41eff11.webp' => 'podcut',
		'0a715740-9b13-11f1-a666-4794d3ffe780.webp' => 'podfirst',
		'a2015b20-ceac-11f0-8b2a-51cd23d40448.webp' => 'sb-outdoor-living',
		'a1fcee50-ceac-11f0-a647-af2eccb11ff2.webp' => 'kam-servicing',
		'a1fcee50-ceac-11f0-9760-b3036bd583df.webp' => 'empire-scaffolding',
		// Evolve is the club's one sponsor, so it takes the first slot in
		// the sponsors row. The rest of the row, and the partners row
		// under it, belong to companies who are no longer with the club;
		// ew_sponsor_retired() takes those links off the page.
		'c0cb65d0-c099-11f0-9384-3df27b8e225c.webp' => 'evolve-scaffolding',
		'71416870-7c3c-11f1-8494-d5247a06fcb1.webp' => 'short-stitch',
		'683e54b0-00fc-11f1-8913-b7f1aeca7b09.webp' => 'swift-sports-massage',
		'5d6eec60-cead-11f0-a647-af2eccb11ff2.webp' => 'stadia-signs',
		'433c47b0-a7b1-11f1-9cc4-0bf0a35b648c.webp' => 'eastwood-cars',
	);
}

/**
 * The single pass over the finished page.
 */
function ew_rewrite_markup( $html ) {
	if ( '' === $html || false === stripos( $html, '<body' ) ) { return $html; }

	// The slugs lost their "eastwood-" prefix; the captured header and
	// footer still carry the old ones in markup we do not own.
	$find    = array();
	$replace = array();
	foreach ( ew_moved_slugs() as $from => $to ) {
		$find[]    = '/' . $from . '/';
		$replace[] = '/' . $to . '/';
	}
	$html = str_replace( $find, $replace, $html );

	// Icons. The captured markup hard-codes fill="red" on the <use>, which
	// would flood-fill an outline icon, so the stroke attributes go on in
	// the same move.
	$icons = ew_icon_map();
	$html  = preg_replace_callback(
		'#<use\s+fill="red"\s+href="/gc-icons/([a-zA-Z]+)/([a-zA-Z]+)\.svg\#icon"#',
		function ( $m ) use ( $icons ) {
			$key = $m[1] . '/' . $m[2];
			if ( ! isset( $icons[ $key ] ) ) { return $m[0]; }
			return '<use fill="none" stroke="currentColor" stroke-width="1.6"'
				. ' stroke-linecap="round" stroke-linejoin="round"'
				. ' href="#' . $icons[ $key ] . '"';
		},
		$html
	);

	// Sponsor artwork.
	$roster = ew_sponsor_roster();
	foreach ( ew_sponsor_slot_map() as $file => $slug ) {
		if ( false === strpos( $html, $file ) ) { continue; }
		if ( ! file_exists( ew_sponsor_dir() . $slug . '.png' ) ) { continue; }
		$html = str_replace(
			'https://images.gc.nffcservices.co.uk/fit-in/120x120/' . $file,
			esc_url( ew_sponsor_wall_url( $slug ) ),
			$html
		);
		$html = preg_replace(
			'#https://images\.gc\.nffcservices\.co\.uk/[^"\']*' . preg_quote( $file, '#' ) . '#',
			esc_url( ew_sponsor_wall_url( $slug ) ),
			$html
		);
		if ( isset( $roster[ $slug ] ) ) {
			$html = str_replace(
				'alt="' . $slug . '"',
				'alt="' . esc_attr( $roster[ $slug ][0] ) . '"',
				$html
			);
		}
	}

	// Sponsors who have left take their whole anchor with them.
	foreach ( ew_sponsor_retired() as $gone ) {
		for ( $i = 0; $i < 6; $i++ ) {
			$anchor = ew_sponsor_anchor( $html, $gone );
			if ( '' === $anchor ) {
				break;
			}
			$html = str_replace( $anchor, '', $html );
		}
	}

	/*
	 * A mark shipped in the plugin wins over the uploads copy, wherever
	 * the markup came from.
	 *
	 * ew_sponsor_url() already prefers the shipped file, but the sponsor
	 * rail plugin writes the header strip itself -- it runs in a buffer
	 * nested inside this one, so its output arrives here already pointing
	 * at uploads -- and it keeps its own copy of the white PodCut mark
	 * there. The rail was still on the bare wordmark while the footer wall
	 * carried the real sponsorship lockup. This sweep settles it: any
	 * uploads path this plugin also ships is redirected to the shipped
	 * file, so there is one answer to what a mark looks like.
	 */
	$up   = content_url( '/uploads/ew-sponsors/' );
	$html = preg_replace_callback(
		'#' . preg_quote( $up, '#' ) . '([a-z0-9\-]+)\.png#',
		function ( $m ) {
			$ship = plugin_dir_path( __FILE__ ) . 'assets/ew-sponsors/' . $m[1] . '.png';
			return file_exists( $ship )
				? esc_url( plugins_url( 'assets/ew-sponsors/' . $m[1] . '.png', __FILE__ ) )
				: $m[0];
		},
		$html
	);

	// A sponsor taken off the roster has to leave the wall, not just stop
	// being updated. The captured markup decides how many slots there are,
	// and this pass only ever filled them in, so PodFirst and SB Outdoor
	// Living were still on every page in the footer long after they came
	// off the list. Any slot whose artwork belongs to nobody on the roster
	// now goes, anchor and all. Anchors do not nest, so the lazy match
	// between one opening tag and the next </a> is the whole slot.
	$html = preg_replace_callback(
		'#<a\b[^>]*>(?:(?!</a>).)*?/ew-sponsors/([a-z0-9\-]+)\.png(?:(?!</a>).)*?</a>#s',
		function ( $m ) use ( $roster ) {
			return isset( $roster[ ew_sponsor_slug_of( $m[1] ) ] ) ? $m[0] : '';
		},
		$html
	);

	// The five social buttons in the captured footer are that club's, and
	// their icon files are dead links even at source. Eastwood has three
	// accounts, so wire those and drop the other two.
	//
	// These are written as wordmarks rather than icons on purpose: the
	// platforms' logos are their trademarks, and the official brand assets
	// are the right thing to drop in here if icons are wanted later.
	$socials = array(
		'8cfaffe0-bd39-11ef-9e59-6983f36cdfe4.png' => array( 'Facebook',  'https://www.facebook.com/eastwoodcfc' ),
		'8cfe8250-bd39-11ef-b90c-0f7ac1549ec6.png' => array( 'Instagram', 'https://www.instagram.com/eastwoodcommunityfootballclub/' ),
		'8d140620-bd39-11ef-ba23-df171f225609.png' => array( 'X',         'https://twitter.com/EastwoodCFCV2' ),
		'9c5fbc00-bd39-11ef-9130-d1f2c200ae5f.png' => array( '', '' ),
		'9c58de30-bd39-11ef-ba23-df171f225609.png' => array( '', '' ),
	);
	foreach ( $socials as $file => $row ) {
		$html = preg_replace_callback(
			'#<a\b[^>]*>\s*<img[^>]*' . preg_quote( $file, '#' ) . '[^>]*>\s*</a>#',
			function () use ( $row ) {
				if ( '' === $row[0] ) { return ''; }
				return '<a class="text-xs uppercase tracking-wider hover:underline"'
					. ' href="' . esc_url( $row[1] ) . '"'
					. ' rel="noopener noreferrer" target="_blank">'
					. esc_html( $row[0] ) . '</a>';
			},
			$html
		);
	}

	// Anything still pointing at that CDN is a slot we have nothing for.
	// An empty slot beats someone else's logo, so drop those images.
	$html = preg_replace(
		'#<img[^>]*images\.gc\.nffcservices\.co\.uk[^>]*>#',
		'',
		$html
	);

	// The captured footer writes srcset with no src. That is legal, but
	// combined with loading="lazy" the browser never fetches it, so the
	// logos sit there empty. Give every sponsor image a plain src too.
	$html = preg_replace_callback(
		'#<img\b[^>]*>#',
		function ( $m ) {
			$tag = $m[0];
			if ( false === strpos( $tag, 'ew-sponsors' ) ) { return $tag; }
			if ( preg_match( '#\ssrc=#', $tag ) ) {
				return preg_replace( '#\sloading="lazy"#', '', $tag );
			}
			if ( ! preg_match( '#\ssrcset="([^"]+)"#', $tag, $s ) ) { return $tag; }
			$url = trim( explode( ' ', trim( $s[1] ) )[0] );
			$tag = preg_replace( '#^<img#', '<img src="' . esc_url( $url ) . '"', $tag, 1 );
			// loading="lazy" never fires on these, so they stay blank. They
			// are small files and there are twenty-odd of them; load them.
			return preg_replace( '#\sloading="lazy"#', '', $tag );
		},
		$html
	);

	// Sponsors expect a click-through. The captured markup parks every
	// logo on href="#" with the click cancelled, so point each one at the
	// sponsor whose logo it now carries.
	$html = preg_replace_callback(
		'#<a\b([^>]*rel="sponsored"[^>]*)>(.*?)</a>#s',
		function ( $m ) use ( $roster ) {
			$open = $m[1];
			$body = $m[2];
			if ( ! preg_match( '#/ew-sponsors/([a-z0-9\-]+)\.png#', $body, $s ) ) { return $m[0]; }
			$slug = $s[1];
			if ( empty( $roster[ $slug ] ) ) { return $m[0]; }

			/*
			 * Name the logo first, before the click-through is considered. A
			 * sponsor with no URL on file still has a name, and this is the
			 * only text in the link, so without it the logo is announced as
			 * nothing at all.
			 */
			if ( false === stripos( $body, ' alt=' ) ) {
				$body = preg_replace( '#<img\b#',
					'<img alt="' . esc_attr( $roster[ $slug ][0] ) . '"', $body, 1 );
			}

			/*
			 * The new tab wants protecting whether or not there is anywhere
			 * for it to go. This used to sit after the check below, so the
			 * one sponsor with no URL on file kept an unprotected target.
			 */
			if ( false !== stripos( $open, 'target="_blank"' ) ) {
				if ( preg_match( '#\srel="([^"]*)"#', $open, $r ) ) {
					if ( false === stripos( $r[1], 'noopener' ) ) {
						$open = str_replace( $r[0], ' rel="' . esc_attr( $r[1] ) . ' noopener"', $open );
					}
				} else {
					$open .= ' rel="noopener"';
				}
			}

			// Nowhere to send the click yet, so the logo stays as it is.
			if ( empty( $roster[ $slug ][2] ) ) {
				return '<a' . $open . '>' . $body . '</a>';
			}
			$open = preg_replace( '#\shref="[^"]*"#', '', $open );
			$open = preg_replace( '#\sonclick="[^"]*"#', '', $open );

			return '<a href="' . esc_url( $roster[ $slug ][2] ) . '"'
				. ' title="' . esc_attr( $roster[ $slug ][1] . ' — ' . $roster[ $slug ][0] ) . '"'
				. $open . '>' . $body . '</a>';
		},
		$html
	);

	/*
	 * One shop card carries a stray </p> straight after its opening anchor,
	 * which closes the link immediately: the card ends up outside it, so the
	 * product is not clickable and the link has no name. The markup is in
	 * the shop plugin rather than this one, so it is corrected on the way
	 * out. Harmless once that source is fixed, because then there is nothing
	 * left to match.
	 */
	$html = preg_replace( '#(<a\b[^>]*class="ewshop-card"[^>]*>)</p>#', '$1', $html );

	return ew_rewrite_nav( ew_rewrite_topbar( $html ) );
}

add_action( 'template_redirect', function () {
	if ( is_admin() || is_feed() || is_embed() ) { return; }
	ob_start( 'ew_rewrite_markup' );
}, 1 );

/* ------------------------------------------------------------------
 * Letting the chosen homepage actually be the homepage.
 *
 * The theme was captured from a reference build and carries its own
 * front-page template. That template wins over everything else in the
 * WordPress hierarchy, so setting a static page as the homepage in
 * Settings changed the setting and nothing else: the address bar said
 * eastwoodfc.com and the captured front page kept rendering, which looks
 * exactly like the setting not having saved.
 *
 * With a page chosen, that template steps aside and the page renders
 * through its own, which is how the builder pages reach the screen.
 * Choose "Your latest posts" again and the captured front page comes
 * back, so this takes nothing away.
 * ------------------------------------------------------------------ */

add_filter( 'front_page_template', function ( $template ) {
	if ( 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) ) {
		return '';
	}
	return $template;
} );

/**
 * The homepage has one address.
 *
 * The page kept answering on its own slug as well as on the root, so the
 * same homepage sat at two addresses -- which search engines read as two
 * pages competing with each other, and which leaves the old link in
 * circulation. WordPress usually redirects this itself; it is not doing
 * it here, so this does.
 */
add_action( 'template_redirect', function () {
	$front = (int) get_option( 'page_on_front' );
	if ( ! $front || ! is_page( $front ) || is_admin() ) {
		return;
	}
	$path = trim( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH ), '/' );
	if ( '' === $path ) {
		return;
	}
	wp_safe_redirect( home_url( '/' ), 301 );
	exit;
}, 2 );



/* ------------------------------------------------------------------
 * The Matches page: fixtures, results and the league table.
 *
 * Delivered as a shortcode rather than a page template so it can be
 * dropped on any page, and so the whole thing ships with the plugin.
 *
 * Everything comes from the Football Web Pages feed through our own
 * proxy, which is public, so this works for logged-out visitors. There
 * is no content to maintain: results appear when the league records
 * them.
 * ------------------------------------------------------------------ */

function ew_matches_shortcode( $atts ) {
	$atts = shortcode_atts( array( 'open' => 'fixtures' ), $atts, 'eastwood_matches' );
	$open = in_array( $atts['open'], array( 'fixtures', 'results', 'table' ), true ) ? $atts['open'] : 'fixtures';

	// The matches page is the next game enlarged, then the whole season
	// underneath — the shape Forest use. Both come from parts/home-blocks.php
	// so this page and the homepage cannot drift apart.
	if ( ! function_exists( 'ew_hb_fixtures_sc' ) ) {
		return '';
	}

	$out = ew_hb_fixtures_sc(
		array( 'title' => '', 'open' => $open, 'hero' => 'yes' ),
		"Men's First Team | " . EW_TEAM . "\nWomen's First Team |\nDevelopment |\nUnder 21s |"
	);
	return $out;
}
add_shortcode( 'eastwood_matches', 'ew_matches_shortcode' );

/**
 * Does this post use a shortcode, wherever it lives?
 *
 * has_shortcode() only reads post_content. On an Elementor page the shortcodes
 * sit inside the _elementor_data meta instead, so the content check comes back
 * false, no CSS is printed, and the block renders unstyled and stacked while
 * still returning a clean 200. Search both.
 */
function ew_uses_shortcode( $post, $tag ) {
	if ( ! $post ) {
		return false;
	}
	if ( has_shortcode( (string) $post->post_content, $tag ) ) {
		return true;
	}
	$data = get_post_meta( $post->ID, '_elementor_data', true );
	return is_string( $data ) && false !== strpos( $data, '[' . $tag );
}

/**
 * Same problem, for markup rather than a shortcode: a class name written into
 * a page body lands in _elementor_data on an Elementor page, so a gate that
 * only reads post_content never fires and the stylesheet is never printed.
 */
/**
 * Is this one of the written pages, however it renders?
 *
 * A page that still prints its own content carries the class ew-prose. A
 * page converted to a block carries the block's name instead, and
 * eastwood_prose does not contain ew-prose -- an underscore against a
 * hyphen. Every gate that decides whether to send a page its styling has
 * to accept both, and there is more than one such gate: the first time
 * this was fixed, only one of them was, which left the price grid on the
 * tickets page and the gate figures on the sponsorship page unstyled.
 *
 * So the test lives here, once, rather than being written out at each
 * gate. A new gate calls this.
 */
function ew_is_prose_page( $post ) {
	return ew_post_contains( $post, 'ew-prose' )
		|| ew_post_contains( $post, 'eastwood_prose' );
}

function ew_post_contains( $post, $needle ) {
	if ( ! $post ) {
		return false;
	}
	if ( false !== strpos( (string) $post->post_content, $needle ) ) {
		return true;
	}
	$data = get_post_meta( $post->ID, '_elementor_data', true );
	return is_string( $data ) && false !== strpos( $data, $needle );
}

function ew_matches_assets() {
	// The shortcode renders the shared blocks now; this sheet and its script
	// styled the old .ew-matches markup, which is gone.
	return;
	// phpcs:disable
	if ( ! is_singular() ) { return; }
	$post = get_post();
	if ( ! ew_uses_shortcode( $post, 'eastwood_matches' ) ) { return; }

	$css = '
.ew-matches{margin:0 calc(50% - 50vw);padding:0 0 64px;font-family:"Instrument Sans",system-ui,sans-serif;color:#111}
.ew-matches *{box-sizing:border-box}
.ew-tabs{display:flex;gap:2px;margin:0 0 24px;border-bottom:2px solid #e3e3e3}
.ew-tabs button{appearance:none;background:none;border:0;border-bottom:3px solid transparent;margin-bottom:-2px;
 padding:14px 22px;font-family:Anton,"Instrument Sans",sans-serif;font-size:18px;letter-spacing:.04em;
 text-transform:uppercase;color:#6b6b6b;cursor:pointer;transition:color .15s,border-color .15s}
.ew-tabs button:hover{color:#111}
.ew-tabs button[aria-selected="true"]{color:#CC0000;border-bottom-color:#CC0000}
.ew-panel[hidden]{display:none}
.ew-loading{padding:40px 0;text-align:center;color:#6b6b6b}
.ew-month{font-family:Anton,sans-serif;font-size:15px;letter-spacing:.08em;text-transform:uppercase;
 color:#6b6b6b;margin:28px 0 10px}
.ew-month:first-child{margin-top:0}
.ew-match{display:grid;grid-template-columns:92px minmax(0,1fr) 190px;gap:16px;align-items:center;
 background:#fff;border:1px solid #e6e6e6;border-radius:4px;padding:14px 18px;margin-bottom:8px}
.ew-match{text-decoration:none;color:inherit;transition:border-color .15s,box-shadow .15s}
a.ew-match:hover{border-color:#CC0000;box-shadow:0 2px 10px rgba(0,0,0,.07)}
.ew-match.is-home{border-left:3px solid #CC0000}
.ew-when{font-size:13px;line-height:1.35;color:#6b6b6b}
.ew-when strong{display:block;font-size:15px;color:#111}
.ew-teams{display:grid;grid-template-columns:minmax(0,1fr) auto minmax(0,1fr);
 align-items:center;gap:12px;min-width:0}
.ew-side{display:flex;align-items:center;gap:10px;min-width:0}
.ew-side.ewc-away{flex-direction:row-reverse;text-align:right}
.ew-side img{width:34px;height:34px;object-fit:contain;flex:none}
.ew-side span{font-weight:600;font-size:15px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ew-side.ewc-us span{color:#CC0000}
.ew-score{font-family:Anton,sans-serif;font-size:22px;letter-spacing:.05em;white-space:nowrap;padding:0 4px}
.ew-ko{font-family:Anton,sans-serif;font-size:16px;color:#6b6b6b;white-space:nowrap;padding:0 4px}
.ew-meta{font-size:12px;line-height:1.35;color:#8a8a8a;text-align:right;
 overflow-wrap:anywhere;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.ew-table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #e6e6e6;border-radius:4px;overflow:hidden}
.ew-table th{background:#f3f3f3;font-family:Anton,sans-serif;font-size:12px;letter-spacing:.06em;
 text-transform:uppercase;color:#6b6b6b;font-weight:400;padding:12px 8px;text-align:center}
.ew-table th.ewc-club,.ew-table td.ewc-club,
.ew-matches .ew-table th.ewc-club,.ew-matches .ew-table td.ewc-club{text-align:left}
.ew-table tbody tr{background:#fff}
/* The editor stylesheet puts a dark border on every td inside .tiptap, which
   is the wrapper WordPress renders page content in, and it marks that border
   !important. Matching it is the only way to draw our own hairline. */
.ew-matches .ew-table td,.ew-matches .ew-table th{border:0 !important}
.ew-matches .ew-table tbody td{border-top:1px solid #efefef !important}
.ew-matches .ew-table td{padding:11px 8px;text-align:center;font-size:14px;border-top:1px solid #efefef;background:transparent;color:#111}
.ew-table td{padding:11px 8px;text-align:center;font-size:14px;border-top:1px solid #efefef;background:transparent;color:#111}
.ew-table td.ewc-club,.ew-matches .ew-table td.ewc-club{display:flex;align-items:center;gap:10px;font-weight:600}
.ew-table td.ewc-club img{width:26px;height:26px;object-fit:contain;flex:none}
.ew-table td.ewc-pts,.ew-matches .ew-table td.ewc-pts{font-weight:700}
.ew-table tr.ewc-us,.ew-matches .ew-table tr.ewc-us{background:#fff5f5}
.ew-table tr.ewc-us td,.ew-matches .ew-table tr.ewc-us td{color:#CC0000}
.ew-pos{position:relative}
.ew-pos::before{content:"";position:absolute;left:0;top:6px;bottom:6px;width:3px;background:transparent}
.ew-pos.ewc-promotion::before{background:#CC0000}
.ew-pos.ewc-relegation::before{background:#111}
.ew-key{margin:14px 2px 0;font-size:12px;color:#6b6b6b;display:flex;gap:18px;flex-wrap:wrap}
.ew-key i{display:inline-block;width:3px;height:12px;margin-right:7px;vertical-align:-2px}
.ew-credit{margin:32px 0 0;font-size:12px;color:#8a8a8a}
@media(max-width:720px){
 .ew-match{grid-template-columns:1fr;gap:10px}
 .ew-meta{text-align:left;-webkit-line-clamp:none;display:block}
 .ew-side span{font-size:14px}
 .ew-table th.ewc-hide,.ew-table td.ewc-hide{display:none}
}';

	$js = <<<'JS'
(function(){
var root=document.querySelector('.ew-matches');
if(!root){return;}
var US=parseInt(root.getAttribute('data-team'),10);
var V=window.EW_BADGE_V?('?v='+window.EW_BADGE_V):'';
var BADGE='/wp-content/uploads/ew-badges/';
var MONTHS=['January','February','March','April','May','June','July','August','September','October','November','December'];

function esc(s){var d=document.createElement('div');d.textContent=s==null?'':String(s);return d.innerHTML;}
function crest(id,name){
 return '<img src="'+BADGE+id+'.png'+V+'" alt="" loading="lazy" onerror="this.style.visibility=\'hidden\'">';
}
function side(t,extra){
 var us=t.id===US?' ewc-us':'';
 return '<span class="ew-side '+extra+us+'">'+crest(t.id)+'<span>'+esc(t.name)+'</span></span>';
}
function niceDate(iso){
 var p=iso.split('-'),d=new Date(+p[0],+p[1]-1,+p[2]);
 return {day:d.toLocaleDateString('en-GB',{weekday:'short'}),
         date:d.getDate()+' '+d.toLocaleDateString('en-GB',{month:'short'}),
         month:MONTHS[d.getMonth()]+' '+d.getFullYear()};
}
function played(m){return m.status&&m.status.short==='FT';}

function renderMatches(list,showScore){
 if(!list.length){return '<p class="ew-loading">Nothing to show yet.</p>';}
 var out='',month='';
 list.forEach(function(m){
  var n=niceDate(m.date);
  if(n.month!==month){month=n.month;out+='<h3 class="ew-month">'+esc(month)+'</h3>';}
  var home=m['home-team'],away=m['away-team'];
  var mid=showScore
    ? '<span class="ew-score">'+esc(home.score)+' &ndash; '+esc(away.score)+'</span>'
    : '<span class="ew-ko">'+esc(m.time?m.time.slice(0,5):'')+'</span>';
  out+='<a class="ew-match'+(home.id===US?' is-home':'')+'" href="/match/'+encodeURIComponent(m.id)+'/">'
     +'<div class="ew-when"><strong>'+esc(n.date)+'</strong>'+esc(n.day)+'</div>'
     +'<div class="ew-teams">'+side(home,'ewc-home')+mid+side(away,'ewc-away')+'</div>'
     +'<div class="ew-meta">'+esc(m.venue||'')
     +(m.attendance?'<br>Att '+esc(m.attendance):'')+'</div>'
     +'</a>';
 });
 return out;
}

function renderTable(teams){
 var rows=teams.map(function(t){
  var a=t['all-matches']||{};
  var zone=(t.zone||'').toLowerCase();
  var cls=zone.indexOf('promotion')>-1?'ewc-promotion':(zone.indexOf('relegation')>-1?'ewc-relegation':'');
  return '<tr'+(t.id===US?' class="ewc-us"':'')+'>'
   +'<td class="ew-pos '+cls+'">'+esc(t.position)+'</td>'
   +'<td class="ewc-club">'+crest(t.id)+esc(t.name)+'</td>'
   +'<td>'+esc(a.played)+'</td><td>'+esc(a.won)+'</td><td>'+esc(a.drawn)+'</td><td>'+esc(a.lost)+'</td>'
   +'<td class="ewc-hide">'+esc(a['for'])+'</td><td class="ewc-hide">'+esc(a.against)+'</td>'
   +'<td>'+esc(a['goal-difference'])+'</td><td class="ewc-pts">'+esc(t['total-points'])+'</td></tr>';
 }).join('');
 return '<table class="ew-table"><thead><tr>'
  +'<th>#</th><th class="ewc-club">Club</th><th>P</th><th>W</th><th>D</th><th>L</th>'
  +'<th class="ewc-hide">F</th><th class="ewc-hide">A</th><th>GD</th><th>Pts</th></tr></thead>'
  +'<tbody>'+rows+'</tbody></table>'
  +'<p class="ew-key"><span><i style="background:#CC0000"></i>Promotion</span>'
  +'<span><i style="background:#111"></i>Relegation</span></p>';
}

function fail(panel,what){
 panel.innerHTML='<p class="ew-loading">Couldn\'t load the '+what+' just now. Please try again shortly.</p>';
}

var panels={};
root.querySelectorAll('.ew-panel').forEach(function(p){panels[p.getAttribute('data-panel')]=p;});
var buttons=root.querySelectorAll('.ew-tabs button');
function show(name){
 buttons.forEach(function(b){
  var on=b.getAttribute('data-tab')===name;
  b.setAttribute('aria-selected',on?'true':'false');
 });
 Object.keys(panels).forEach(function(k){
  if(k===name){panels[k].removeAttribute('hidden');}else{panels[k].setAttribute('hidden','');}
 });
 try{history.replaceState(null,'','#'+name);}catch(e){}
}
buttons.forEach(function(b){b.addEventListener('click',function(){show(b.getAttribute('data-tab'));});});
var start=(location.hash||'').replace('#','');
show(['fixtures','results','table'].indexOf(start)>-1?start:root.getAttribute('data-open'));

fetch('/wp-json/eastwood/v1/fwp?endpoint=fixtures-results').then(function(r){return r.json();}).then(function(d){
 var all=((d['fixtures-results']||{}).matches)||[];
 var done=all.filter(played).sort(function(a,b){return a.date<b.date?1:-1;});
 var next=all.filter(function(m){return !played(m);}).sort(function(a,b){return a.date<b.date?-1:1;});
 panels.fixtures.innerHTML=renderMatches(next,false);
 panels.results.innerHTML=renderMatches(done,true);
}).catch(function(){fail(panels.fixtures,'fixtures');fail(panels.results,'results');});

fetch('/wp-json/eastwood/v1/fwp?endpoint=league-table').then(function(r){return r.json();}).then(function(d){
 var t=((d['league-table']||{}).teams)||[];
 panels.table.innerHTML=t.length?renderTable(t):'<p class="ew-loading">No table published yet.</p>';
}).catch(function(){fail(panels.table,'table');});
})();
JS;

	wp_register_style( 'eastwood-matches', false );
	wp_enqueue_style( 'eastwood-matches' );
	wp_add_inline_style( 'eastwood-matches', $css );

	wp_register_script( 'eastwood-matches', false, array(), null, true );
	wp_enqueue_script( 'eastwood-matches' );
	wp_add_inline_script( 'eastwood-matches', $js );
}
add_action( 'wp_enqueue_scripts', 'ew_matches_assets', 20 );

/* ------------------------------------------------------------------
 * Teams and Squad.
 *
 * Two shortcodes:
 *
 *   [eastwood_teams]  the club's full team list, grouped as the club
 *                     groups it. Static, because no feed carries it.
 *   [eastwood_squad]  the first-team squad, built live from the
 *                     Football Web Pages appearances and goalscorers
 *                     feeds through our own proxy.
 *
 * There is no squad endpoint at Football Web Pages. What exists is a
 * per-player appearance record, which is better: it is maintained by
 * the league rather than by us, and a player appears on the page the
 * moment he plays. The cost is that it lists who HAS played, not who
 * is registered, and it carries no positions, photographs or dates of
 * birth. The page says so rather than pretending otherwise.
 * ------------------------------------------------------------------ */

/**
 * Every Eastwood team, in the club's own grouping.
 *
 * Taken from the club's Pitchero site, which is still the system of
 * record for the junior sides. `pitchero` is the team id there.
 * When a side's fixtures move onto this site, drop its id and give it
 * a `url` instead; ew_teams_link() will follow whichever is set.
 */
function ew_teams_roster() {
	return array(
		'Senior Men' => array(
			array( 'name' => '1st Team',         'pitchero' => 140091, 'url' => '/squad/' ),
			array( 'name' => 'U21',              'pitchero' => 188320 ),
			array( 'name' => 'Academy',          'pitchero' => 166249 ),  // page: /teams/academy/
			array( 'name' => 'Development Team', 'pitchero' => 286212 ),
			array( 'name' => 'Veterans',         'pitchero' => 153743 ),
		),
		'Junior' => array(
			array( 'name' => 'U18 Red',          'pitchero' => 159508 ),
			array( 'name' => 'U18 White',        'pitchero' => 207019 ),
			array( 'name' => 'U16',              'pitchero' => 207018 ),
			array( 'name' => 'U15 Red',          'pitchero' => 159510 ),
			array( 'name' => 'U15 Gold',         'pitchero' => 186922 ),
			array( 'name' => 'U14 Black',        'pitchero' => 207015 ),
			array( 'name' => 'U13 Aces',         'pitchero' => 207017 ),
			array( 'name' => 'U13 Black',        'pitchero' => 207022 ),
			array( 'name' => 'U13 Red',          'pitchero' => 159507 ),
			array( 'name' => 'U13 Badgers',      'pitchero' => 207016 ),
			array( 'name' => 'U12 Red',          'pitchero' => 207023 ),
			array( 'name' => 'U12 Cosmos',       'pitchero' => 195413 ),
			array( 'name' => 'U11 Gold',         'pitchero' => 186921 ),
			array( 'name' => 'U11 Black Sunday', 'pitchero' => 207020 ),
			array( 'name' => 'U10 Red',          'pitchero' => 207025 ),
			array( 'name' => 'U10 Black',        'pitchero' => 207024 ),
			array( 'name' => 'U9 Aces',          'pitchero' => 197464 ),
			array( 'name' => 'U9 Saturday',      'pitchero' => 195415 ),
			array( 'name' => 'U8 Sunday',        'pitchero' => 207030 ),
			array( 'name' => 'U7 Red',           'pitchero' => 207027 ),
			array( 'name' => 'U7 Badgers',       'pitchero' => 207028 ),
			array( 'name' => 'U7 Panthers',      'pitchero' => 207029 ),
		),
		'Mini' => array(
			array( 'name' => 'Soccer School',    'pitchero' => 167688 ),
		),
		'Ladies and Girls' => array(
			array( 'name' => "Under 12's",       'pitchero' => 293703 ),
		),
	);
}

/**
 * Where a team card points, and whether that destination is ours.
 * Returns array( url, is_external ).
 */
function ew_teams_link( $team ) {
	// Every side has a page on this site. The pitchero id stays in the roster
	// as a reference for anyone migrating content across, but nothing links
	// out to it: the club's website is the club's website.
	if ( ! empty( $team['url'] ) ) {
		return array( $team['url'], false );
	}
	return array( home_url( '/teams/' . ew_team_slug( $team['name'] ) . '/' ), false );
}

/**
 * The sides this page lists, as "Name | kind" lines.
 *
 * The kind decides what the tab shows: "squad" draws the squad block,
 * "grassroots" draws the welcome line, anything else says the squad is
 * being added. Left empty, it gives back exactly the six sides this page
 * has always carried, so a page that names none of them is unchanged.
 */
function ew_teams_sides( $content ) {
	$sides = array();
	$lines = function_exists( 'ew_hb_lines' )
		? ew_hb_lines( $content )
		: array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $content ) ) );

	foreach ( $lines as $line ) {
		$parts = array_map( 'trim', explode( '|', $line ) );
		if ( '' === $parts[0] ) {
			continue;
		}
		$sides[] = array(
			'label' => $parts[0],
			'kind'  => isset( $parts[1] ) ? strtolower( $parts[1] ) : '',
		);
	}

	if ( ! $sides ) {
		$sides = array(
			array( 'label' => "Men's First Team", 'kind' => 'squad' ),
			array( 'label' => "Women's First Team", 'kind' => '' ),
			array( 'label' => 'Development', 'kind' => '' ),
			array( 'label' => 'Under 21s', 'kind' => '' ),
			array( 'label' => 'Academy', 'kind' => '' ),
			array( 'label' => 'Grassroots', 'kind' => 'grassroots' ),
		);
	}
	return $sides;
}

function ew_teams_shortcode( $atts = array(), $content = '' ) {
	$atts = shortcode_atts(
		array(
			// {team} is replaced with whichever side the reader is looking at.
			'pending'    => '{team} squad details are being added.',
			'grassroots' => 'New players are welcome across every section —',
			'email'      => 'info@eastwoodfc.com',
		),
		$atts,
		'eastwood_teams'
	);

	// The senior sides across the top, the way the reference site opens its
	// Teams page. Only the first team has a squad in the league feed; the
	// others say so rather than showing an empty grid.
	$sides = ew_teams_sides( $content );

	ob_start();
	?>
<div class="ew-teams-index" data-pv-group>
	<div class="ewp ewp-teamBar ew-tm-bar">
		<div class="ewp-teamTabs" role="tablist">
			<?php foreach ( $sides as $si => $side ) : ?>
			<button type="button" data-pv="s<?php echo (int) $si; ?>"
				aria-selected="<?php echo 0 === $si ? 'true' : 'false'; ?>"><?php
				echo esc_html( $side['label'] ); ?></button>
			<?php endforeach; ?>
		</div>
	</div>

	<?php foreach ( $sides as $si => $side ) : ?>
	<div class="ew-tm-side" data-pv-panel="s<?php echo (int) $si; ?>"<?php
		echo 0 === $si ? '' : ' hidden'; ?>>
		<?php if ( 'squad' === $side['kind'] ) : ?>
		<?php echo ew_squad_shortcode(); // phpcs:ignore ?>
		<?php elseif ( 'grassroots' === $side['kind'] ) : ?>
		<p class="ew-credit"><?php echo esc_html( $atts['grassroots'] ); ?>
			<a href="mailto:<?php echo esc_attr( $atts['email'] ); ?>"><?php
				echo esc_html( $atts['email'] ); ?></a>.</p>
		<?php else : ?>
		<p class="ew-credit"><?php
			echo esc_html( str_replace( '{team}', $side['label'], $atts['pending'] ) ); ?></p>
		<?php endif; ?>
	</div>
	<?php endforeach; ?>

	<?php
	/*
	 * The full twenty-nine-team directory used to print here. It sat outside
	 * the panels, so every tab showed it underneath whichever side had been
	 * picked. It is out until it has a tab of its own to sit in.
	 */
	?>
</div>
	<?php
	return trim( ob_get_clean() );
}
add_shortcode( 'eastwood_teams', 'ew_teams_shortcode' );

/**
 * The squad: the number each player has been issued, and where they play.
 *
 * Both are kept here because the feed carries neither. Football Web Pages
 * returns a name, an id and the shirt worn in each individual match, and
 * nothing else.
 *
 * The club issues numbers for the season, so a number here is the player's
 * number and is shown as it stands. Before this list existed the page showed
 * whichever number a player had worn most often, which was the best that
 * could be done from match data and was wrong whenever somebody borrowed a
 * shirt. A player not listed here still falls back to that.
 *
 * Keys are the full name as the feed spells it, compared without case and
 * with straight and curly apostrophes treated alike. Values are the issued
 * number and one of gk, df, mf or fw. Either may be left null.
 *
 *   'Scott Loach' => array( 1, 'gk' ),
 *
 * Anyone missing from this list still appears on the page, grouped under
 * Squad at the bottom, so nobody is ever dropped for want of an entry.
 */
function ew_squad_details() {
	return array(
		// Positions as Ben gave them, 19 October 2026, and the squad numbers
		// the same day. He gave the numbers by the names the dressing room
		// uses, so they are matched back to the squad here: Loachy, Charlie
		// M, Louis, Curtis, Connor D, JP, Tyler, Raws, Walks, Wrighty, Tyree,
		// Toby, Paddy, Jordi H and Chetts all land on one player and one
		// only. Willow is read as Ryan Wilson, because Tyree Wilson is named
		// outright at 14.
		//
		// and Ben is checking whether he has a number or has left. A null
		// number means the page falls back to the shirt the player has worn
		// most often, which is what it did for everyone until today.
		//
		// Tom Marshall is 5. He has been injured a long while and has not
		// played this season, so he is not in the appearances feed the squad
		// page is built from and will not show there until he does. His row
		// is here so that the day he comes back he already has his number.
		// The position is the only guess on this list.
		'Scott Loach'        => array( 1, 'gk' ),
		'Tom Marshall'       => array( 5, 'df' ),
		'Louis Nicholson'    => array( 3, 'df' ),
		'Jake Wright'        => array( 12, 'df' ),
		'Toby Moore'         => array( 15, 'df' ),
		'Charlie Marzano'    => array( 2, 'df' ),
		'Connor Dimaio'      => array( 6, 'mf' ),
		'Jamie Walker'       => array( 11, 'mf' ),
		'Jacob Pearce'       => array( 7, 'mf' ),
		'Ryan Wilson'        => array( 8, 'mf' ),
		'Curtis Thompson'    => array( 4, 'mf' ),
		'Jordan Hallam'      => array( 17, 'mf' ),
		'Callum Chettle'     => array( 23, 'mf' ),
		'Tyree Wilson'       => array( 14, 'mf' ),
		'Luke Rawson'        => array( 10, 'fw' ),
		'Patrick Webb'       => array( 16, 'fw' ),
		'Tyler Blake'        => array( 9, 'fw' ),
	);
}

/**
 * Players who have featured this season but are no longer in the squad.
 *
 * The squad is built from the appearances feed, which by its nature keeps
 * anyone who has played a minute all season. A player who has left is still
 * in that data and has to be named here to come off the page. Their
 * appearances and goals are untouched and still count in the season record;
 * this list governs the squad page only.
 *
 * Matching ignores case and treats a curly apostrophe and a straight one as
 * the same character, because the feed uses the curly one.
 */
function ew_squad_departed() {
	return array(
		'Steven Johnson-Fikula',
		'Revarnelle James',
		'Kieren Fenton',
		'L’Varn Brandy',
		'Rashaan Frances',
		'Harry Wood',
		'Bradley Gascoigne',
		'Tony Gbopo',
	);
}

/** The position half of the list, for the grouping. */
function ew_player_positions() {
	$out = array();
	foreach ( ew_squad_details() as $name => $row ) {
		if ( ! empty( $row[1] ) ) {
			$out[ $name ] = $row[1];
		}
	}
	return $out;
}

/** The issued-number half, for the shirt on the card. */
function ew_player_numbers() {
	$out = array();
	foreach ( ew_squad_details() as $name => $row ) {
		if ( ! empty( $row[0] ) ) {
			$out[ $name ] = (int) $row[0];
		}
	}
	return $out;
}

/**
 * The headings the squad is listed under, as "code | heading | one player"
 * lines -- gk, df, mf, fw, and a last line with no code for anyone the
 * position list does not name. Left empty it gives back the four the
 * reference site uses, so a page that names none of them is unchanged.
 */
function ew_squad_groups( $content ) {
	$groups = array();
	$lines  = function_exists( 'ew_hb_lines' )
		? ew_hb_lines( $content )
		: array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $content ) ) );

	foreach ( $lines as $line ) {
		$parts = array_map( 'trim', explode( '|', $line ) );
		if ( ! isset( $parts[1] ) || '' === $parts[1] ) {
			continue;
		}
		$groups[] = array(
			strtolower( $parts[0] ),
			$parts[1],
			isset( $parts[2] ) && '' !== $parts[2] ? $parts[2] : $parts[1],
		);
	}

	if ( ! $groups ) {
		$groups = array(
			array( 'gk', 'Goalkeepers', 'Goalkeeper' ),
			array( 'df', 'Defenders', 'Defender' ),
			array( 'mf', 'Midfielders', 'Midfielder' ),
			array( 'fw', 'Forwards', 'Forward' ),
			array( '', 'Squad', 'Squad' ),
		);
	}
	return $groups;
}

function ew_squad_shortcode( $atts = array(), $content = '' ) {
	$atts = shortcode_atts(
		array(
			'loading' => 'Loading the squad…',
			'empty'   => 'No squad to show just now.',
		),
		$atts,
		'eastwood_squad'
	);

	ob_start();
	?>
<div class="ew-squad" data-team="<?php echo esc_attr( EW_TEAM ); ?>"
	data-positions="<?php echo esc_attr( (string) wp_json_encode( ew_player_positions() ) ); ?>"
	data-departed="<?php echo esc_attr( (string) wp_json_encode( ew_squad_departed() ) ); ?>"
	data-numbers="<?php echo esc_attr( (string) wp_json_encode( ew_player_numbers() ) ); ?>"
	data-groups="<?php echo esc_attr( (string) wp_json_encode( ew_squad_groups( $content ) ) ); ?>"
	data-empty="<?php echo esc_attr( $atts['empty'] ); ?>">
	<div class="ew-sq-top"><p class="ew-loading"><?php echo esc_html( $atts['loading'] ); ?></p></div>
	<div class="ew-sq-body"></div>
</div>
	<?php
	return trim( ob_get_clean() );
}
add_shortcode( 'eastwood_squad', 'ew_squad_shortcode' );

function ew_teams_assets() {
	if ( ! is_singular() ) { return; }
	$post = get_post();
	if ( ! $post ) { return; }
	$teams = ew_uses_shortcode( $post, 'eastwood_teams' );
	// The Teams page now opens with the first-team squad, so it needs the
	// squad script as well as its own.
	$squad = $teams || ew_uses_shortcode( $post, 'eastwood_squad' );
	if ( ! $teams && ! $squad ) { return; }

	$css = '
.ew-teams-index,.ew-squad{margin:0 calc(50% - 50vw);padding:0 0 64px;
 font-family:"Instrument Sans",system-ui,sans-serif;color:#111}
.ew-teams-index *,.ew-squad *{box-sizing:border-box}
.ew-tm-group{margin:0 0 40px}
.ew-tm-head{font-family:Anton,"Instrument Sans",sans-serif;font-size:20px;letter-spacing:.05em;
 text-transform:uppercase;color:#111;margin:0 0 16px;padding:0 0 10px;border-bottom:2px solid #e3e3e3;
 display:flex;align-items:center;gap:12px}
.ew-tm-head span{font-family:"Instrument Sans",sans-serif;font-size:12px;letter-spacing:0;font-weight:600;
 color:#6b6b6b;background:#f3f3f3;border-radius:10px;padding:2px 9px;text-transform:none}
.ew-tm-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:8px}
.ew-tm-card{display:flex;flex-direction:column;justify-content:center;gap:3px;min-height:64px;
 background:#fff;border:1px solid #e6e6e6;border-left:3px solid #CC0000;border-radius:4px;
 padding:12px 16px;text-decoration:none;color:#111;transition:border-color .15s,box-shadow .15s}
a.ew-tm-card:hover{border-color:#CC0000;box-shadow:0 2px 10px rgba(0,0,0,.07)}
.ew-tm-card.is-external{border-left-color:#c9c9c9}
a.ew-tm-card.is-external:hover{border-color:#b5b5b5;box-shadow:0 2px 10px rgba(0,0,0,.05)}
.ew-tm-name{font-weight:600;font-size:15px}
.ew-tm-note{font-size:11px;color:#8a8a8a}
/* The position headings, measured off the reference: Anton 40px bold in
   near-black, 24px of air under each one. */
.ew-sq-group{font-family:Anton,"Instrument Sans",sans-serif;font-size:40px;font-weight:700;
 line-height:1.05;letter-spacing:normal;text-transform:uppercase;color:#101010;margin:48px 0 24px}
.ew-sq-group:first-child{margin-top:0}
@media(max-width:820px){.ew-sq-group{font-size:30px;margin:36px 0 18px}}
/* The senior sides along the top, each with its own squad underneath. */
/* The reference carries the side tabs in a full-width white strip sitting
   flush under the red band, 62px tall, its contents on the same 1248 column
   as everything else. Ours sat inside the content column as a plain white
   box with a gap above it. */
.ew-tm-bar{background:#fff;min-height:62px;display:flex;align-items:center;
 margin:0 0 32px;box-shadow:inset 0 -1px 0 rgba(0,0,0,.08)}
.ew-tm-bar .ewp-teamTabs{width:100%}
.ew-teams-index [hidden]{display:none}
.ew-tm-side{margin:0 0 42px}
.ew-teams-index .ew-squad{max-width:none;margin:0;padding:0}

/* A player is a face first. Until there are photographs the frame holds
   its shape and shows the shirt number, rather than collapsing. */
.ew-sq-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(256px,1fr));gap:16px;
 list-style:none;margin:0;padding:0}
/* No border, no corner, no white box: the reference lets the photograph be
   the card and sets the name under it. Theirs is 300 wide with a 16px gap
   and four across a 1280 column; the minimum above lands on the same. */
.ew-sq-card{background:transparent;border:0;border-radius:0;overflow:hidden}
.ew-sq-shot{display:block;position:relative;aspect-ratio:300/379;background:#e9e9ec;overflow:hidden}
.ew-sq-shot img{width:100%;height:100%;object-fit:cover;display:block;position:relative;z-index:2}
.ew-sq-shot::before{content:"";position:absolute;left:50%;top:26%;transform:translateX(-50%);
 width:34%;aspect-ratio:1;border-radius:50%;background:#d6d6da}
.ew-sq-shot::after{content:"";position:absolute;left:50%;bottom:-14%;transform:translateX(-50%);
 width:64%;aspect-ratio:1;border-radius:50%;background:#d6d6da}
/* The number is white on the reference because it always sits on a
   photograph. Ours has to read on an empty frame too, until the shoot
   happens, so the foot of the frame darkens under it either way. */
.ew-sq-scrim{position:absolute;z-index:3;left:0;right:0;bottom:0;height:46%;
 background:linear-gradient(180deg,rgba(0,0,0,0),rgba(0,0,0,.62))}
.ew-sq-no{position:absolute;z-index:4;left:24px;bottom:14px;
 font-family:"Instrument Sans",system-ui,sans-serif;font-size:48px;font-weight:700;
 line-height:1;color:#fafafa;font-variant-numeric:tabular-nums}
.ew-sq-name{display:block;padding:14px 24px 0;font-family:Anton,"Instrument Sans",sans-serif;
 font-size:24px;font-weight:700;letter-spacing:.01em;color:#101010}
.ew-sq-pos{display:block;padding:6px 24px 0;font-size:12px;letter-spacing:.04em;
 text-transform:uppercase;color:#aaa}
.ew-sq-stats{display:block;padding:4px 24px 16px;font-size:12px;color:#8a8a8a}
.ew-sq-stats em{font-style:normal;font-weight:700;color:#111}
@media(max-width:620px){
 .ew-sq-grid{grid-template-columns:repeat(auto-fill,minmax(146px,1fr));gap:12px}
 .ew-sq-no{font-size:30px;left:14px;bottom:10px}
 .ew-sq-name{font-size:18px;padding:11px 14px 0}
 .ew-sq-pos,.ew-sq-stats{padding-left:14px;padding-right:14px}
}

.ew-sq-table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #e6e6e6;
 border-radius:4px;overflow:hidden}
.ew-sq-table th{background:#f3f3f3;font-family:Anton,sans-serif;font-size:12px;letter-spacing:.06em;
 text-transform:uppercase;color:#6b6b6b;font-weight:400;padding:12px 8px;text-align:center}
.ew-sq-table th.ewc-who,.ew-sq-table td.ewc-who{text-align:left}
/* Same trap as the matches table: the editor stylesheet marks a dark td
   border !important inside .tiptap, so ours has to be !important too. */
.ew-squad .ew-sq-table td,.ew-squad .ew-sq-table th{border:0 !important}
.ew-squad .ew-sq-table tbody td{border-top:1px solid #efefef !important}
.ew-sq-table td{padding:11px 8px;text-align:center;font-size:14px;background:transparent;color:#111}
.ew-sq-table td.ewc-shirt{font-family:Anton,sans-serif;font-size:16px;color:#6b6b6b;width:52px}
.ew-sq-table td.ewc-who{font-weight:600}
.ew-sq-table td.ewc-gls{font-weight:700}
.ew-sq-armband{display:inline-block;margin-left:7px;font-style:normal;font-size:10px;font-weight:700;
 letter-spacing:.06em;color:#CC0000;border:1px solid #CC0000;border-radius:3px;padding:1px 4px;
 vertical-align:1px}
@media(max-width:720px){
 .ew-sq-table th.ewc-hide,.ew-sq-table td.ewc-hide{display:none}
 .ew-tm-grid{grid-template-columns:repeat(auto-fill,minmax(150px,1fr))}
}';

	$js = <<<'JS'
(function(){
var root=document.querySelector('.ew-squad');
if(!root){return;}
var top=root.querySelector('.ew-sq-top'),body=root.querySelector('.ew-sq-body');

function esc(s){var d=document.createElement('div');d.textContent=s==null?'':String(s);return d.innerHTML;}
function name(p){return ((p['first-name']||'')+' '+(p['last-name']||'')).trim();}
function fail(msg){top.innerHTML='<p class="ew-loading">'+msg+'</p>';body.innerHTML='';}

function build(apps,gls){
 var players=(apps.players)||[];
 if(!players.length){return fail('No appearances have been recorded yet this season.');}

 var goals={};
 ((gls&&gls.players)||[]).forEach(function(p){goals[p.id]=(p.goals||[]).length;});

 var rows=players.map(function(p){
  var a=p.appearances||[];
  // Shirt numbers are per match at this level, not fixed squad numbers,
  // so the one shown is whichever the player has worn most often.
  var tally={},best=null;
  a.forEach(function(x){
   if(!x.shirt){return;}
   tally[x.shirt]=(tally[x.shirt]||0)+1;
   if(best===null||tally[x.shirt]>tally[best]){best=x.shirt;}
  });
  return {
   name:name(p),
   shirt:best,
   apps:a.length,
   goals:goals[p.id]||0,
   cards:a.filter(function(x){return x.cautioned;}).length,
   capt:a.filter(function(x){return x.captain;}).length
  };
 });

 top.innerHTML='';

 rows.sort(function(x,y){
  if(y.apps!==x.apps){return y.apps-x.apps;}
  return x.name<y.name?-1:1;
 });

 function slug(n){return n.toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');}

 // The reference lists the squad under Goalkeepers, Defenders, Midfielders
 // and Forwards. Anyone the position list does not name keeps their place on
 // the page in a group at the end rather than disappearing from it.
 // The feed spells at least one name with a curly apostrophe, so names are
 // compared with the apostrophes levelled and the spacing collapsed.
 function nkey(n){return String(n||'').toLowerCase()
   .replace(/[\u2018\u2019\u02bc\u00b4`]/g,"'").replace(/\s+/g,' ').trim();}

 var POS={};
 try{POS=JSON.parse(root.getAttribute('data-positions')||'{}');}catch(e){}
 var key={};
 Object.keys(POS).forEach(function(n){key[nkey(n)]=POS[n];});

 var NUM={};
 try{var rawNum=JSON.parse(root.getAttribute('data-numbers')||'{}');
  Object.keys(rawNum).forEach(function(n){NUM[nkey(n)]=rawNum[n];});}catch(e){}
 rows.forEach(function(r){
  // An issued number is the number. Only fall back to the one worn most
  // often for a player the club has not given one to yet.
  var issued=NUM[nkey(r.name)];
  if(issued){r.shirt=issued;}
 });

 var GONE={};
 try{(JSON.parse(root.getAttribute('data-departed')||'[]')||[])
   .forEach(function(n){GONE[nkey(n)]=1;});}catch(e){}
 rows=rows.filter(function(r){return !GONE[nkey(r.name)];});
 if(!rows.length){return fail(root.getAttribute('data-empty')||'No squad to show just now.');}

 // The headings come off the element, so they are a field on the block
 // rather than a line in here. The four below are only the fallback for a
 // page drawn before this attribute existed.
 var groups=[['gk','Goalkeepers'],['df','Defenders'],['mf','Midfielders'],
             ['fw','Forwards'],['','Squad']];
 var POSNAME={gk:'Goalkeeper',df:'Defender',mf:'Midfielder',fw:'Forward'};
 try{var G=JSON.parse(root.getAttribute('data-groups')||'[]');
  if(G&&G.length){groups=G.map(function(g){return [g[0],g[1]];});
   POSNAME={};G.forEach(function(g){if(g[0]){POSNAME[g[0]]=g[2]||g[1];}});}}catch(e){}
 var bucket={};
 groups.forEach(function(g){bucket[g[0]]=[];});
 rows.forEach(function(r){
  var k=key[nkey(r.name)];
  if(!bucket[k]){k='';}
  bucket[k].push(r);
 });

 function card(r){
    var pos=POSNAME[key[nkey(r.name)]]||'';
    return '<li class="ew-sq-card">'
     +'<span class="ew-sq-shot">'
       +'<img src="/wp-content/uploads/ew-players/'+slug(r.name)+'.jpg" alt="" loading="lazy"'
       +' onerror="this.remove()">'
       +'<i class="ew-sq-scrim"></i>'
       +(r.shirt?'<b class="ew-sq-no">'+r.shirt+'</b>':'')
     +'</span>'
     +'<span class="ew-sq-name">'+esc(r.name)
     +(r.capt?'<i class="ew-sq-armband" title="Has captained the side">C</i>':'')+'</span>'
     +(pos?'<span class="ew-sq-pos">'+pos+'</span>':'')
     +'<span class="ew-sq-stats"><em>'+r.apps+'</em> apps'
     +(r.goals?' &middot; <em>'+r.goals+'</em> '+(r.goals===1?'goal':'goals'):'')+'</span>'
     +'</li>';
 }

 body.innerHTML=groups.filter(function(g){return bucket[g[0]].length;})
  .map(function(g){
    return '<h2 class="ew-sq-group">'+g[1]+'</h2>'
      +'<ul class="ew-sq-grid">'+bucket[g[0]].map(card).join('')+'</ul>';
  }).join('');
}

/* The host rate-limits bursts of requests, and a single 429 on either of
   these left the page reading "could not load the squad" with no second
   attempt. One retry after a short random wait, the same treatment the
   crests already get. */
function grab(ep, retried){
 return fetch('/wp-json/eastwood/v1/fwp?endpoint=' + ep).then(function(r){
  if (r.status === 429 && !retried) {
   return new Promise(function(go){ setTimeout(go, 1200 + Math.random() * 1800); })
     .then(function(){ return grab(ep, true); });
  }
  if (!r.ok) { throw new Error(ep + ' ' + r.status); }
  return r.json();
 });
}

Promise.all([
 grab('appearances'),
 grab('goalscorers')
]).then(function(d){
 build(d[0].appearances||{},d[1].goalscorers||{});
}).catch(function(){fail('Couldn\'t load the squad just now. Please try again shortly.');});
})();
JS;

	wp_register_style( 'eastwood-teams', false );
	wp_enqueue_style( 'eastwood-teams' );
	wp_add_inline_style( 'eastwood-teams', $css );

	if ( $squad ) {
		wp_register_script( 'eastwood-teams', false, array(), null, true );
		wp_enqueue_script( 'eastwood-teams' );
		wp_add_inline_script( 'eastwood-teams', $js );
	}
}
add_action( 'wp_enqueue_scripts', 'ew_teams_assets', 20 );

/* ------------------------------------------------------------------
 * Updates.
 *
 * The host sits behind a firewall that rejects any POST body containing
 * PHP, so the plugin and theme editors cannot be used here and every
 * change used to mean a hand-chunked zip upload through the installer.
 *
 * Instead this tells WordPress where its own updates live and lets core
 * do the work — the update appears on the Plugins screen like any other.
 *
 * Two deliberate choices:
 *   - The source is hardcoded. There is no endpoint here that accepts a
 *     URL, so nothing outside this file can point the updater elsewhere.
 *   - The repository is public, so no credential is stored on the site.
 *     Nothing secret lives in this file; the API key is in the options
 *     table, pasted once at Settings → Eastwood FWP.
 * ------------------------------------------------------------------ */

/*
 * THREE PLACES CARRY THE VERSION AND ALL THREE MUST MATCH:
 *   1. the  * Version:  line in the plugin header, which is what
 *      WordPress shows as installed;
 *   2. this constant, which busts the inline CSS and JS cache;
 *   3. plugin.json in the repository root, which is the ONLY one the
 *      updater actually compares against.
 * Bumping the header alone ships nothing: plugin.json sat at 2.25.0
 * while the header reached 2.37.0, so the site was offered a version
 * older than the one it had and seven releases never landed.
 */
const EW_FWP_VERSION = '3.2.0';

/*
 * Parts loader.
 *
 * Every new section of this site has so far meant editing this one 185KB
 * file, which can only reach the site through a push from a terminal on
 * Ben's Mac. Anything dropped into parts/ in the same repo is loaded here
 * instead, so a new block is a new small file and nothing else has to move.
 *
 * Load order is alphabetical, before anything below runs, so a part can
 * define functions this file's hooks call and vice versa — both are loaded
 * before WordPress fires 'init'.
 */
foreach ( (array) glob( __DIR__ . '/parts/*.php' ) as $ew_part ) {
	// A page template is not a part. Templates render a page; running one at
	// plugin load calls get_header() before WordPress is ready and takes the
	// site down with a fatal on every request — which is exactly what 2.10.0
	// did. Templates live at the plugin root and are named template-*.php;
	// this check is the second line of defence if one is ever misfiled.
	if ( 0 === strpos( basename( $ew_part ), 'template-' ) ) {
		continue;
	}
	$ew_head = (string) file_get_contents( $ew_part, false, null, 0, 1024 );
	if ( false !== stripos( $ew_head, 'Template Name:' ) ) {
		continue;
	}
	require_once $ew_part;
}

const EW_FWP_REPO    = 'coachbenedwards/eastwood-fwp';
const EW_FWP_BRANCH  = 'main';

/**
 * The version published in the repository.
 *
 * Reads a plain file rather than the GitHub API on purpose:
 * raw.githubusercontent.com is reachable from this host, api.github.com
 * is not — it comes back as a firewall error page.
 */
function ew_fwp_remote_info() {
	// A forced check must actually re-check. Reading $_GET here rather than
	// clearing on admin_init, because core runs the forced check on
	// load-update-core.php, which fires first.
	//
	// $_GET alone is not enough, though. Core often runs the plugin check in
	// a separate wp-cron loopback request, which carries no query string at
	// all, so a forced check from the updates screen would quietly read this
	// cache and report "up to date" against a stale version. The
	// delete_site_transient_update_plugins hook below is the reliable half:
	// whenever core drops its own update cache, ours goes with it.
	$forced = isset( $_GET['force-check'] );

	$cached = get_transient( 'ew_fwp_remote' );

	// Today, 2.60 through 2.62 sat on GitHub while the updates screen said
	// the site was current, because this cache had been written an hour
	// earlier when 2.59 really was the latest, and core ran its check in a
	// cron loopback that carries no query string for $forced to see. A
	// cached version that merely matches what is installed is the one
	// answer that cannot be trusted to still be true: it is indistinguishable
	// from "nothing has been released since", which is what it then reports.
	// So re-read it rather than believe it. Every other value is honest and
	// still gets the full hour.
	$suspect = is_array( $cached ) && ! empty( $cached['version'] )
		&& version_compare( $cached['version'], EW_FWP_VERSION, '<=' );

	if ( ! $forced && ! $suspect && false !== $cached ) {
		return $cached;
	}

	$url = 'https://raw.githubusercontent.com/' . EW_FWP_REPO . '/' . EW_FWP_BRANCH . '/plugin.json';
	$res = wp_remote_get( $url, array(
		'timeout' => 15,
		'headers' => array( 'Accept' => 'application/json' ),
	) );

	$info = array();
	if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
		$decoded = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( is_array( $decoded ) && ! empty( $decoded['version'] ) ) {
			$info = $decoded;
		}
	}

	// Cached either way so a failure cannot hammer GitHub — but a failure is
	// cached briefly. Caching an empty result for a whole working day means
	// one blip hides every release until tomorrow. An hour on the happy path
	// keeps us well inside anyone's idea of polite for a CDN-served file and
	// means a push shows up on its own without anybody forcing anything.
	set_transient( 'ew_fwp_remote', $info, empty( $info ) ? 5 * MINUTE_IN_SECONDS : HOUR_IN_SECONDS );
	return $info;
}

// Core drops its own update cache whenever a check is forced, and again
// after an install. Ours has to go at the same moment or the next check
// reads a stale version and reports the site up to date when it is not.
add_action( 'delete_site_transient_update_plugins', function () {
	delete_transient( 'ew_fwp_remote' );
} );

add_action( 'upgrader_process_complete', function ( $upgrader, $extra ) {
	if ( isset( $extra['type'] ) && 'plugin' === $extra['type'] ) {
		delete_transient( 'ew_fwp_remote' );
	}
}, 10, 2 );

add_filter( 'pre_set_site_transient_update_plugins', function ( $transient ) {
	if ( ! is_object( $transient ) ) {
		$transient = new stdClass();
	}

	// Deliberately NOT guarding on empty( $transient->checked ). That guard is
	// in most updater tutorials and it is why they silently never fire: core
	// does not always populate `checked` before this filter runs.
	$info = ew_fwp_remote_info();
	if ( empty( $info['version'] ) ) {
		return $transient;
	}

	if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
		$transient->response = array();
	}

	if ( version_compare( $info['version'], EW_FWP_VERSION, '>' ) ) {
		$transient->response[ plugin_basename( __FILE__ ) ] = (object) array(
			'slug'        => 'eastwood-fwp',
			'plugin'      => plugin_basename( __FILE__ ),
			'new_version' => $info['version'],
			'url'         => 'https://github.com/' . EW_FWP_REPO,
			'package'     => 'https://github.com/' . EW_FWP_REPO
				. '/archive/refs/heads/' . EW_FWP_BRANCH . '.zip',
		);
	}

	return $transient;
} );

/**
 * A branch zip extracts to <repo>-<branch>/, which WordPress would install
 * as a second, differently named plugin. Rename it on the way through.
 */
add_filter( 'upgrader_source_selection', function ( $source, $remote_source, $upgrader, $args = array() ) {
	if ( false === strpos( basename( untrailingslashit( $source ) ), 'eastwood-fwp-' ) ) {
		return $source;
	}

	$fixed = trailingslashit( $remote_source ) . 'eastwood-fwp/';
	if ( trailingslashit( $source ) === $fixed ) {
		return $source;
	}

	global $wp_filesystem;
	if ( $wp_filesystem && $wp_filesystem->move( $source, $fixed, true ) ) {
		return $fixed;
	}

	return $source;
}, 10, 4 );

/**
 * Update-channel status, for when the update does not appear.
 *
 * Read-only and admin-only. Reports what the site can actually see, so the
 * next attempt is a measurement rather than a guess.
 */
add_action( 'rest_api_init', function () {
	register_rest_route( 'eastwood/v1', '/update-status', array(
		'methods'             => 'GET',
		'permission_callback' => function () { return current_user_can( 'update_plugins' ); },
		'callback'            => function () {
			$url = 'https://raw.githubusercontent.com/' . EW_FWP_REPO . '/' . EW_FWP_BRANCH . '/plugin.json';
			$res = wp_remote_get( $url, array( 'timeout' => 15 ) );

			$fetch = is_wp_error( $res )
				? array( 'ok' => false, 'error' => $res->get_error_message() )
				: array( 'ok' => true, 'http' => (int) wp_remote_retrieve_response_code( $res ),
					'body' => substr( wp_remote_retrieve_body( $res ), 0, 300 ) );

			$site = get_site_transient( 'update_plugins' );
			$me   = plugin_basename( __FILE__ );

			return array(
				'installed_version' => EW_FWP_VERSION,
				'plugin_basename'   => $me,
				'live_fetch'        => $fetch,
				'cached_remote'     => get_transient( 'ew_fwp_remote' ),
				'offered_update'    => isset( $site->response[ $me ] ) ? $site->response[ $me ] : null,
				'core_checked_me'   => isset( $site->checked[ $me ] ) ? $site->checked[ $me ] : null,
			);
		},
	) );
} );

/**
 * Every address that lost its "eastwood-" prefix, old slug to new.
 *
 * Eastwood TV keeps its prefix: the channel is called Eastwood TV, so the
 * word is part of the name rather than a leftover from the page tree.
 */
function ew_moved_slugs() {
	return array(
		'eastwood-matches'     => 'matches',
		'eastwood-news'        => 'news',
		'eastwood-teams'       => 'teams',
		'eastwood-squad'       => 'squad',
		'eastwood-tickets'     => 'tickets',
		// The function room and matchday hospitality are different businesses
		// that briefly shared an address. /hospitality/ is the matchday
		// product; the room for weddings and private hire is TheVenue.
		'eastwood-hospitality' => 'thevenue',
		'hospitality-matchday' => 'hospitality',
		'eastwood-home-v2'     => 'home-v2',
		// Not a slug this site ever used: it is simply the address people
		// type when they mean home-v2, and a 404 there reads as the page
		// being broken rather than missing.
		'homepage-v2'          => 'home-v2',
	);
}

/**
 * Rename the pages once, rather than asking someone to edit seven of them by
 * hand and hope the menus keep up. A page is only touched when it still
 * carries the old slug and nothing else has claimed the new one, so running
 * this again after the fact changes nothing.
 */
const EW_SLUG_RENAME = '1';

add_action( 'init', function () {
	if ( get_option( 'ew_slug_rename' ) === EW_SLUG_RENAME ) {
		return;
	}
	foreach ( ew_moved_slugs() as $from => $to ) {
		$page = get_page_by_path( $from );
		if ( ! $page ) {
			continue;
		}
		$taken = get_page_by_path( $to );
		if ( $taken && (int) $taken->ID !== (int) $page->ID ) {
			continue;
		}
		wp_update_post( array( 'ID' => $page->ID, 'post_name' => $to ) );
	}
	update_option( 'ew_slug_rename', EW_SLUG_RENAME );
	flush_rewrite_rules( false );
}, 20 );

/**
 * Pages taken down.
 *
 * The page is unpublished rather than deleted, so it is still in the editor
 * and can be put back with one click. This runs once and records that it
 * has, so republishing it by hand is not undone on the next page load.
 *
 * Runs at 21: after the one-time slug rename at 20, before the page install
 * at 30. The slug is also out of ew_owned_pages(), so nothing recreates it.
 */
const EW_RETIRE_V = '1';

function ew_retired_pages() {
	return array( 'company-details' );
}

add_action( 'init', function () {
	if ( get_option( 'ew_retired_pages' ) === EW_RETIRE_V ) {
		return;
	}
	foreach ( ew_retired_pages() as $slug ) {
		$page = get_page_by_path( $slug );
		if ( $page && 'publish' === $page->post_status ) {
			wp_update_post( array( 'ID' => $page->ID, 'post_status' => 'draft' ) );
		}
	}
	update_option( 'ew_retired_pages', EW_RETIRE_V );
}, 21 );

/**
 * A description for search engines, taken from the words already on the page.
 *
 * Not one page on the site had one, so a search engine wrote its own from
 * whatever text it found first. This takes the page lede instead, which is
 * the sentence each page already opens with, so nothing is invented and
 * nothing has to be kept in step by hand.
 *
 * A page with no lede, or one too short to say anything (the club history
 * page currently reads "Coming soon"), gets nothing rather than something
 * useless. Those need a line written for them.
 */
function ew_meta_description( $content ) {
	$text = '';
	if ( preg_match( '#<p[^>]*class="[^"]*ew-lede[^"]*"[^>]*>(.*?)</p>#s', $content, $m ) ) {
		$text = $m[1];
	} elseif ( preg_match( '#<p[^>]*>(.*?)</p>#s', $content, $m ) ) {
		$text = $m[1];
	}
	$text = trim( preg_replace( '#\s+#', ' ', wp_strip_all_tags( $text ) ) );
	if ( mb_strlen( $text ) < 50 ) {
		return '';
	}
	if ( mb_strlen( $text ) > 158 ) {
		$cut  = mb_substr( $text, 0, 158 );
		$stop = mb_strrpos( $cut, ' ' );
		$text = rtrim( false === $stop ? $cut : mb_substr( $cut, 0, $stop ), ' ,;:' ) . '…';
	}
	return $text;
}

add_action( 'wp_head', function () {
	if ( ! is_singular() ) {
		return;
	}
	$post = get_post();
	if ( ! $post ) {
		return;
	}
	$desc = ew_meta_description( (string) $post->post_content );
	if ( '' === $desc ) {
		return;
	}
	echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
}, 1 );

/* The old addresses, kept alive for anything already pointing at them. */
add_action( 'template_redirect', function () {
	$path = trim( (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );
	$moved = array();
	foreach ( ew_moved_slugs() as $from => $to ) {
		$moved[ $from ] = '/' . $to . '/';
	}
	$first = explode( '/', $path )[0];
	if ( isset( $moved[ $first ] ) ) {
		$rest = substr( $path, strlen( $first ) );
		wp_redirect( home_url( rtrim( $moved[ $first ], '/' ) . $rest . '/' ), 301 );
		exit;
	}
}, 1 );

/* ------------------------------------------------------------------
 * The News landing page.
 *
 * The captured theme already ships a complete news listing in
 * archive.php — hero, category tabs, three-column grid, pagination —
 * and its own navigation points at /eastwood-news/. Nothing renders
 * there, because that URL belongs to no page.
 *
 * The obvious fix is Settings -> Reading, but naming a Posts page
 * forces a static front page too, and this theme has no front-page.php,
 * so the home page would fall back to the plain page template and lose
 * its whole layout.
 *
 * So the URL is routed straight to the listing instead. No theme
 * change, no front-page change, and the listing stays the theme's.
 * ------------------------------------------------------------------ */

const EW_NEWS_SLUG  = 'news';
const EW_NEWS_RULES = '2';

add_action( 'init', function () {
	add_rewrite_rule( '^' . EW_NEWS_SLUG . '/?$', 'index.php?ew_news=1', 'top' );
	add_rewrite_rule(
		'^' . EW_NEWS_SLUG . '/page/([0-9]{1,})/?$',
		'index.php?ew_news=1&paged=$matches[1]',
		'top'
	);

	// Rewrite rules are cached in an option. A plugin update does not fire
	// the activation hook, so flush once per rule version instead.
	if ( get_option( 'ew_news_rules' ) !== EW_NEWS_RULES ) {
		flush_rewrite_rules( false );
		update_option( 'ew_news_rules', EW_NEWS_RULES );
	}
}, 20 );

add_filter( 'query_vars', function ( $vars ) {
	$vars[] = 'ew_news';
	return $vars;
} );

add_action( 'pre_get_posts', function ( $q ) {
	if ( is_admin() || ! $q->is_main_query() || ! $q->get( 'ew_news' ) ) {
		return;
	}

	$q->set( 'post_type', 'post' );
	$q->set( 'post_status', 'publish' );
	$q->set( 'ignore_sticky_posts', true );

	// Tell WordPress this is the posts index rather than a missing page,
	// which keeps the theme's "All" tab highlighted and lets
	// the_posts_pagination() build /eastwood-news/page/2/ correctly.
	$q->is_home     = true;
	$q->is_archive  = false;
	$q->is_page     = false;
	$q->is_singular = false;
	$q->is_404      = false;
} );

add_filter( 'template_include', function ( $template ) {
	if ( ! get_query_var( 'ew_news' ) ) {
		return $template;
	}
	$found = locate_template( array( 'archive.php', 'home.php', 'index.php' ) );
	return $found ? $found : $template;
} );

// Without this the browser tab reads "Eastwood Football Club" alone.
add_filter( 'pre_get_document_title', function ( $title ) {
	if ( get_query_var( 'ew_news' ) ) {
		return 'News — ' . get_bloginfo( 'name' );
	}
	return $title;
} );

/* ------------------------------------------------------------------
 * Pages the plugin owns.
 *
 * The navigation shipped with the replica points at slugs that had no
 * page behind them, so half the menu returned 404. Rather than ask
 * somebody to hand-create pages in wp-admin and paste shortcodes into
 * them, the plugin creates its own and keeps their content current.
 *
 * Idempotent: a page is created only when its slug is free, and its
 * content is refreshed only while it still matches what we last put
 * there. The moment somebody edits a page by hand, we leave it alone.
 * ------------------------------------------------------------------ */

const EW_PAGES_V = '11';

function ew_owned_pages() {
	$pages = array(
		'teams' => array(
			'title'   => 'Teams',
			'content' => '[eastwood_teams]',
		),
		'squad' => array(
			'title'   => 'First Team Squad',
			'content' => '[eastwood_squad]',
		),
		'eastwood-tv' => array(
			'title'   => 'Eastwood TV',
			'content' => '[eastwood_tv]',
		),
		'academy' => array(
			'title'   => 'Academy',
			'content' => ew_academy_content(),
		),
		'matches' => array(
			'title'   => 'Matches',
			'content' => '[eastwood_matches]',
		),
		'tickets' => array(
			'title'   => 'Tickets',
			'content' => ew_tickets_content(),
		),
		// What it costs, and what the day is like, are two different
		// questions and two different visitors. They were one page.
		'matchday' => array(
			'title'   => 'Matchday',
			'content' => ew_matchday_content(),
		),
		'thevenue' => array(
			'title'   => 'Venue Hire',
			'content' => ew_venue_content(),
		),
		'pitch-hire' => array(
			'title'   => 'Pitch Hire',
			'content' => ew_pitchhire_content(),
		),
		'sponsorship' => array(
			'title'   => 'Sponsorship',
			'content' => ew_sponsorship_content(),
		),
		'club-history' => array(
			'title'   => 'Club History',
			'content' => ew_history_content(),
		),
		'contact' => array(
			'title'   => 'Contact us',
			'content' => ew_contact_content(),
		),
		'safeguarding' => array(
			'title'   => 'Safeguarding',
			'content' => ew_safeguarding_content(),
		),
		'privacy-policy' => array(
			'title'   => 'Privacy Policy',
			'content' => ew_privacy_content(),
		),
		'terms' => array(
			'title'   => 'Terms',
			'content' => ew_terms_content(),
		),
	);

	// 'home-preview' used to be registered here. It was the shortcode draft of the
	// new homepage, and /eastwood-home-v2/ — a real Elementor page, editable in the
	// builder — replaced it. Trashing it was not enough: this spec re-runs whenever
	// EW_FWP_VERSION changes, so every release quietly resurrected a second
	// homepage in the Pages list. Removed rather than guarded.
	//
	// ew_home_preview_content() and EW_HOME_TPL stay in parts/home-blocks.php:
	// the template is what /eastwood-home-v2/ renders through, and the function is
	// the reference for what that page's sections contain.

	return $pages;
}

/* ------------------------------------------------------------------
 * The dead address, and why changing it in here did nothing.
 *
 * info@eastwoodcfc.co.uk is the old club domain. It was taken out of
 * this plugin a while ago, and it stayed on eleven live pages anyway.
 *
 * The reason is worth writing down, because it will catch the next
 * change too. Ten of those pages were rebuilt in Elementor at some
 * point. Elementor keeps its own copy of the words in post meta and
 * renders that, so what this plugin writes into post_content is never
 * shown on them. Every text fix made here since then has quietly gone
 * nowhere -- the earlier grassroots@ and safeguarding@ change among
 * them. The pages are not broken and they do not look stale; they are
 * simply a photograph of what the text said on the day they were
 * opened in the builder.
 *
 * So this reaches into the stored copy. One pass, one pattern: an
 * address at the old domain becomes info@eastwoodfc.com. It does NOT
 * touch https://www.eastwoodcfc.co.uk/... , which is where some
 * captured images still come from -- the pattern needs a name and an @
 * in front of the domain, which a URL does not have.
 * ------------------------------------------------------------------ */

const EW_EMAIL_FIX = 'v1';

function ew_dead_email_re() {
	return '/[A-Za-z0-9._%+-]+@eastwoodcfc\.co\.uk/';
}

/** How many it would change, and in what. Used by the settings screen. */
function ew_dead_email_scan() {
	global $wpdb;

	$posts = $wpdb->get_results(
		"SELECT ID, post_title FROM {$wpdb->posts}
		 WHERE post_status != 'trash' AND post_content LIKE '%eastwoodcfc.co.uk%'"
	);
	$meta = $wpdb->get_results(
		"SELECT p.ID, p.post_title FROM {$wpdb->postmeta} m
		 JOIN {$wpdb->posts} p ON p.ID = m.post_id
		 WHERE m.meta_key = '_elementor_data'
		   AND m.meta_value LIKE '%eastwoodcfc.co.uk%'
		   AND p.post_status != 'trash'"
	);

	return array( 'content' => $posts, 'builder' => $meta );
}

function ew_fix_dead_email() {
	global $wpdb;

	$re  = ew_dead_email_re();
	$new = 'info@eastwoodfc.com';
	$hit = array();

	foreach ( ew_dead_email_scan()['content'] as $row ) {
		$post = get_post( $row->ID );
		if ( ! $post ) {
			continue;
		}
		$fixed = preg_replace( $re, $new, $post->post_content );
		if ( null !== $fixed && $fixed !== $post->post_content ) {
			// Straight to the row: wp_update_post() on a page the builder
			// owns can set off a revision and a CSS regeneration for a
			// change the reader will never see in the builder anyway.
			$wpdb->update( $wpdb->posts, array( 'post_content' => $fixed ),
				array( 'ID' => $row->ID ) );
			clean_post_cache( $row->ID );
			$hit[] = 'content:' . $row->ID;
		}
	}

	foreach ( ew_dead_email_scan()['builder'] as $row ) {
		$data = get_post_meta( $row->ID, '_elementor_data', true );
		if ( ! is_string( $data ) || '' === $data ) {
			continue;
		}
		// Both strings are plain ASCII with nothing JSON treats as
		// special, so this is a safe swap inside the stored JSON.
		$fixed = preg_replace( $re, $new, $data );
		if ( null !== $fixed && $fixed !== $data ) {
			update_post_meta( $row->ID, '_elementor_data', wp_slash( $fixed ) );
			$hit[] = 'builder:' . $row->ID;
		}
	}

	update_option( 'ew_email_fix', EW_EMAIL_FIX );
	update_option( 'ew_email_fix_log', $hit );

	return $hit;
}

add_action( 'init', function () {
	if ( get_option( 'ew_email_fix' ) === EW_EMAIL_FIX ) {
		return;
	}
	ew_fix_dead_email();
}, 20 );

/* ------------------------------------------------------------------
 * Taking the prose pages back off the builder.
 *
 * Ten pages this plugin writes were opened in Elementor at some point.
 * Elementor keeps its own copy of the words and renders that, so from
 * then on the text in here was never what anyone saw. The emails were the
 * first symptom; the Academy staff list was the second, and there would
 * have been a third.
 *
 * Nothing was designed in the builder on any of them. Checked before
 * doing this: every one is headings and text blocks holding the same
 * ew-prose markup this plugin already writes, chopped up. So the builder
 * copy is not a design, it is a photocopy -- and a stale one.
 *
 * This switches those pages back to rendering their own content. The
 * builder data is NOT deleted, only switched off: putting
 * _elementor_edit_mode back to builder on a page returns the old copy
 * exactly as it was.
 *
 * Only pages this plugin owns and has written content for. Pages built
 * properly in the builder -- the homepage, hospitality, the shop -- are
 * not touched.
 * ------------------------------------------------------------------ */

const EW_UNBUILDER = 'v1';

function ew_unbuilder_pages() {
	$done = array();

	foreach ( array_keys( ew_owned_pages() ) as $slug ) {
		$page = get_page_by_path( $slug );
		if ( ! $page || '' === trim( (string) $page->post_content ) ) {
			continue;
		}
		if ( 'builder' !== get_post_meta( $page->ID, '_elementor_edit_mode', true ) ) {
			continue;
		}

		// Kept, not deleted: this is reversible by hand from the editor.
		update_post_meta( $page->ID, '_ew_was_builder', '1' );
		delete_post_meta( $page->ID, '_elementor_edit_mode' );
		clean_post_cache( $page->ID );
		$done[] = $slug;
	}

	update_option( 'ew_unbuilder', EW_UNBUILDER );
	update_option( 'ew_unbuilder_log', $done );

	return $done;
}

add_action( 'init', function () {
	if ( get_option( 'ew_unbuilder' ) === EW_UNBUILDER ) {
		return;
	}
	ew_unbuilder_pages();
}, 21 );

function ew_install_pages() {
	$owned = ew_owned_pages();
	$state = (array) get_option( 'ew_owned_pages', array() );

	foreach ( $owned as $slug => $spec ) {
		$existing = get_page_by_path( $slug );

		/**
		 * A page that has to render outside the theme's text column says so
		 * with a template. Set on create, and set on an existing page only
		 * while it is still on the theme's default — if somebody has chosen
		 * a template in the editor, that is their choice and it stands.
		 */
		$ew_apply_meta = function ( $id ) use ( $spec ) {
			if ( ! empty( $spec['template'] ) ) {
				$current = (string) get_post_meta( $id, '_wp_page_template', true );
				if ( '' === $current || 'default' === $current ) {
					update_post_meta( $id, '_wp_page_template', $spec['template'] );
				}
			}
			if ( ! empty( $spec['noindex'] ) ) {
				update_post_meta( $id, '_ew_noindex', '1' );
			}
		};

		// WordPress creates a draft Privacy Policy page on install. It matches
		// by slug, so the check below would skip creation and leave the URL
		// 404ing. An unpublished placeholder is ours to take over.
		if ( $existing && in_array( $existing->post_status, array( 'draft', 'auto-draft', 'pending' ), true ) ) {
			wp_update_post( array(
				'ID'           => $existing->ID,
				'post_status'  => 'publish',
				'post_title'   => $spec['title'],
				'post_content' => $spec['content'],
			) );
			$ew_apply_meta( $existing->ID );
			$state[ $slug ] = array( 'id' => $existing->ID, 'hash' => md5( $spec['content'] ) );
			continue;
		}

		if ( ! $existing ) {
			$id = wp_insert_post( array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_name'    => $slug,
				'post_title'   => $spec['title'],
				'post_content' => $spec['content'],
			) );
			if ( ! is_wp_error( $id ) ) {
				$ew_apply_meta( $id );
				$state[ $slug ] = array( 'id' => $id, 'hash' => md5( $spec['content'] ) );
			}
			continue;
		}

		$ew_apply_meta( $existing->ID );

		// Ours to update only while nobody has touched it.
		//
		// The hash that gets recorded has to be of what is ACTUALLY on the
		// page, never of what we wanted to be on it. It used to be stamped
		// with the new spec whether or not the update fired, so one skipped
		// release -- somebody saves the page once, the hashes stop matching
		// for that pass -- froze the page for good: from then on the stored
		// hash described a version that had never been written, so it could
		// never match again. Several pages were sitting like that.
		$known   = isset( $state[ $slug ]['hash'] ) ? $state[ $slug ]['hash'] : '';
		$content = $existing->post_content;

		if ( $known && md5( $content ) === $known && $content !== $spec['content'] ) {
			wp_update_post( array( 'ID' => $existing->ID, 'post_content' => $spec['content'] ) );
			$content = $spec['content'];
		}

		$state[ $slug ] = array( 'id' => $existing->ID, 'hash' => md5( $content ) );
	}

	update_option( 'ew_owned_pages', $state );
}

add_action( 'init', function () {
	if ( get_option( 'ew_pages_v' ) === EW_PAGES_V . '-' . EW_FWP_VERSION ) {
		return;
	}
	ew_install_pages();
	update_option( 'ew_pages_v', EW_PAGES_V . '-' . EW_FWP_VERSION );
}, 30 );

/* ------------------------------------------------------------------
 * Eastwood TV.
 *
 * The club already publishes everything this section needs: 271 videos
 * on its own YouTube channel, including the weekly "This Is Eastwood"
 * documentary and match highlights for every game. So Eastwood TV is
 * not a new content commitment, it is a shop window onto work that is
 * already being made.
 *
 * The channel's RSS feed carries the fifteen most recent uploads with
 * ids, titles and dates and needs no API key, so that is what we read.
 * Fetched server-side because the feed sends no CORS headers, and
 * cached, because it changes a few times a week at most.
 * ------------------------------------------------------------------ */

const EW_TV_CHANNEL = 'UCoEcO7-tgzIswpU_VDBUtAg';
const EW_TV_HANDLE  = 'eastwoodfootballclub';

/**
 * The latest uploads, newest first.
 * Each entry: id, title, published (unix), series.
 */
function ew_tv_feed() {
	$cached = get_transient( 'ew_tv_feed' );
	if ( false !== $cached ) {
		return $cached;
	}

	$res = wp_remote_get(
		'https://www.youtube.com/feeds/videos.xml?channel_id=' . EW_TV_CHANNEL,
		array( 'timeout' => 8 )
	);

	$videos = array();
	if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
		$prev = libxml_use_internal_errors( true );
		$xml  = simplexml_load_string( (string) wp_remote_retrieve_body( $res ) );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		if ( $xml ) {
			foreach ( $xml->entry as $entry ) {
				$yt = $entry->children( 'http://www.youtube.com/xml/schemas/2015' );
				$id = isset( $yt->videoId ) ? (string) $yt->videoId : '';
				if ( '' === $id ) { continue; }

				$title = ew_tv_clean_title( (string) $entry->title );
				$videos[] = array(
					'id'        => $id,
					'title'     => $title,
					'published' => strtotime( (string) $entry->published ),
					'series'    => ew_tv_series( $title ),
				);
			}
		}
	}

	// A short cache on failure so a blip doesn't leave the page empty for hours.
	set_transient( 'ew_tv_feed', $videos, empty( $videos ) ? 5 * MINUTE_IN_SECONDS : 2 * HOUR_IN_SECONDS );
	return $videos;
}

/**
 * Feed titles carry the social caption: line breaks, hashtags, emoji.
 * Only the first line is the title.
 */
function ew_tv_clean_title( $title ) {
	$title = trim( (string) $title );
	$parts = preg_split( '/\R/u', $title );
	$title = trim( (string) $parts[0] );
	$title = preg_replace( '/\s*#\w+/u', '', $title );
	return trim( $title );
}

function ew_tv_series( $title ) {
	if ( stripos( $title, 'this is eastwood' ) !== false ) { return 'documentary'; }
	if ( stripos( $title, 'highlights' ) !== false )       { return 'highlights'; }
	return 'clips';
}

/**
 * Every word Eastwood TV puts on the screen, as a field.
 *
 * The videos are live -- they come from the channel -- but the words
 * around them were written into this file, which meant nobody but me
 * could change "Latest episode" or the line under the documentary row.
 * They are attributes and body lines now:
 *
 *   [eastwood_tv kicker="Latest episode"]
 *   documentary | This Is Eastwood | The club's own documentary series...
 *   highlights | Match highlights | Every goal from every game...
 *   clips | More from the club |
 *   [/eastwood_tv]
 *
 * @param array       $atts    Attributes.
 * @param string|null $content Body: one row per line.
 * @return string
 */
/**
 * The rows of Eastwood TV, read from the block's body.
 *
 * "key | heading | the line under it", one per line, in the order they
 * appear on the page. The key is the series the video carries, which is
 * data; the other two are words, which is why they are here.
 *
 * @param string|null $body The shortcode body.
 * @return array
 */
function ew_tv_groups( $body ) {
	$out = array();
	foreach ( preg_split( '/\r\n|\r|\n/', (string) $body ) as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}
		$bits = array_map( 'trim', explode( '|', $line ) );
		$key  = (string) ( $bits[0] ?? '' );
		if ( '' === $key ) {
			continue;
		}
		$out[ $key ] = array( (string) ( $bits[1] ?? '' ), (string) ( $bits[2] ?? '' ) );
	}

	// Nothing in the body: the rows this page has always shown, so an
	// older page that has not been given its lines yet is unchanged.
	if ( ! $out ) {
		$out = array(
			'documentary' => array( 'This Is Eastwood', 'The club\'s own documentary series, a new episode every Thursday.' ),
			'highlights'  => array( 'Match highlights', 'Every goal from every game, usually up within a day or two.' ),
			'clips'       => array( 'More from the club', '' ),
		);
	}

	return $out;
}

function ew_tv_shortcode( $atts = array(), $content = null ) {
	$a = shortcode_atts(
		array(
			'kicker'  => 'Latest episode',
			'trouble' => 'Eastwood TV is having trouble reaching the channel just now. You can watch everything at',
			'credit'  => 'Eastwood TV shows the most recent uploads from the club\'s YouTube channel. The full archive is',
			'archive' => 'on YouTube',
		),
		(array) $atts,
		'eastwood_tv'
	);

	$videos = ew_tv_feed();

	ob_start();

	if ( empty( $videos ) ) {
		?>
<div class="ew-tv">
	<p class="ew-loading"><?php echo esc_html( $a['trouble'] ); ?>
		<a href="https://www.youtube.com/@<?php echo esc_attr( EW_TV_HANDLE ); ?>"
		target="_blank" rel="noopener">youtube.com/@<?php echo esc_html( EW_TV_HANDLE ); ?></a>.</p>
</div>
		<?php
		return trim( ob_get_clean() );
	}

	$hero = $videos[0];
	foreach ( $videos as $v ) {
		if ( 'documentary' === $v['series'] ) { $hero = $v; break; }
	}

	$groups = ew_tv_groups( $content );
	?>
<div class="ew-tv">
	<section class="ew-tv-hero">
		<div class="ew-tv-player">
			<iframe src="https://www.youtube-nocookie.com/embed/<?php echo esc_attr( $hero['id'] ); ?>"
				title="<?php echo esc_attr( $hero['title'] ); ?>" loading="lazy" allowfullscreen
				allow="accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
				referrerpolicy="strict-origin-when-cross-origin"></iframe>
		</div>
		<div class="ew-tv-heroText">
			<span class="ew-tv-kicker"><?php echo esc_html( $a['kicker'] ); ?></span>
			<h2><?php echo esc_html( $hero['title'] ); ?></h2>
			<p><?php echo esc_html( date_i18n( 'j F Y', $hero['published'] ) ); ?></p>
		</div>
	</section>

	<?php
	foreach ( $groups as $key => $meta ) :
		$list = array_values( array_filter( $videos, function ( $v ) use ( $key, $hero ) {
			return $v['series'] === $key && $v['id'] !== $hero['id'];
		} ) );
		if ( empty( $list ) ) { continue; }
		?>
	<section class="ew-tv-row">
		<h3 class="ew-tv-rowHead"><?php echo esc_html( $meta[0] ); ?></h3>
		<?php if ( $meta[1] ) : ?><p class="ew-tv-rowSub"><?php echo esc_html( $meta[1] ); ?></p><?php endif; ?>
		<div class="ew-tv-grid">
			<?php foreach ( $list as $v ) : ?>
			<a class="ew-tv-card" href="https://www.youtube.com/watch?v=<?php echo esc_attr( $v['id'] ); ?>"
				target="_blank" rel="noopener">
				<span class="ew-tv-thumb">
					<img src="https://i.ytimg.com/vi/<?php echo esc_attr( $v['id'] ); ?>/hqdefault.jpg"
						alt="" loading="lazy" width="480" height="360">
					<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5.5v13l11-6.5z"/></svg>
				</span>
				<span class="ew-tv-title"><?php echo esc_html( $v['title'] ); ?></span>
				<span class="ew-tv-date"><?php echo esc_html( date_i18n( 'j M Y', $v['published'] ) ); ?></span>
			</a>
			<?php endforeach; ?>
		</div>
	</section>
	<?php endforeach; ?>

	<p class="ew-credit"><?php echo esc_html( $a['credit'] ); ?>
		<a href="https://www.youtube.com/@<?php echo esc_attr( EW_TV_HANDLE ); ?>/videos"
		target="_blank" rel="noopener"><?php echo esc_html( $a['archive'] ); ?></a>.</p>
</div>
	<?php
	return trim( ob_get_clean() );
}
add_shortcode( 'eastwood_tv', 'ew_tv_shortcode' );

function ew_tv_assets() {
	if ( ! is_singular() ) { return; }
	$post = get_post();
	if ( ! ew_uses_shortcode( $post, 'eastwood_tv' ) ) { return; }

	$css = '
.ew-tv{margin:0 calc(50% - 50vw);padding:0 0 64px;font-family:"Instrument Sans",system-ui,sans-serif;color:#111}
.ew-tv *{box-sizing:border-box}
.ew-tv-hero{margin:0 0 44px}
.ew-tv-player{position:relative;padding-top:56.25%;background:#000;border-radius:4px;overflow:hidden}
.ew-tv-player iframe{position:absolute;inset:0;width:100%;height:100%;border:0}
.ew-tv-heroText{padding:18px 2px 0}
.ew-tv-kicker{display:inline-block;font-family:Anton,sans-serif;font-size:12px;letter-spacing:.1em;
 text-transform:uppercase;color:#CC0000;margin:0 0 8px}
.ew-tv-heroText h2{font-family:Anton,"Instrument Sans",sans-serif;font-size:30px;line-height:1.1;
 letter-spacing:.01em;margin:0 0 6px;color:#111}
.ew-tv-heroText p{margin:0;font-size:13px;color:#8a8a8a}
.ew-tv-row{margin:0 0 44px}
.ew-tv-rowHead{font-family:Anton,"Instrument Sans",sans-serif;font-size:20px;letter-spacing:.05em;
 text-transform:uppercase;margin:0 0 4px;color:#111}
.ew-tv-rowSub{margin:0 0 16px;font-size:14px;color:#6b6b6b}
.ew-tv-rowHead+.ew-tv-grid{margin-top:16px}
.ew-tv-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:18px}
.ew-tv-card{display:block;text-decoration:none;color:#111}
.ew-tv-thumb{position:relative;display:block;border-radius:4px;overflow:hidden;background:#111;aspect-ratio:16/9}
.ew-tv-thumb img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .25s,opacity .2s}
.ew-tv-card:hover .ew-tv-thumb img{transform:scale(1.04);opacity:.85}
.ew-tv-thumb svg{position:absolute;left:50%;top:50%;width:46px;height:46px;transform:translate(-50%,-50%);
 fill:#fff;filter:drop-shadow(0 2px 8px rgba(0,0,0,.6));transition:fill .2s}
.ew-tv-card:hover .ew-tv-thumb svg{fill:#CC0000}
.ew-tv-title{display:block;margin:10px 0 3px;font-weight:600;font-size:15px;line-height:1.35}
.ew-tv-card:hover .ew-tv-title{color:#CC0000}
.ew-tv-date{display:block;font-size:12px;color:#8a8a8a}
@media(max-width:720px){
 .ew-tv-heroText h2{font-size:23px}
 .ew-tv-grid{grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:14px}
 .ew-tv-title{font-size:14px}
}';

	wp_register_style( 'eastwood-tv', false );
	wp_enqueue_style( 'eastwood-tv' );
	wp_add_inline_style( 'eastwood-tv', $css );
}
add_action( 'wp_enqueue_scripts', 'ew_tv_assets', 20 );

/* ------------------------------------------------------------------
 * Navigation.
 *
 * Five items in the captured navigation — Eastwood TV, Shop,
 * Sponsorship, Pitch Hire and Academy — were exported as dead links:
 * href="#" with the click cancelled. They are hard-coded in the
 * theme's header.php rather than coming from a WordPress menu, so
 * rather than redeploy the theme (a separate and much slower channel)
 * we repoint them in the same output-buffer pass that already fixes
 * the icons, the sponsor logos and the social links.
 *
 * When the theme is next rebuilt these belong in header.php properly
 * and this can go.
 * ------------------------------------------------------------------ */

function ew_nav_destinations() {
	return array(
		'EASTWOOD TV' => home_url( '/eastwood-tv/' ),
		// The club's real storefront. The clubwebshop.com link in the old
		// footer only lands on that supplier's generic home page.
		'Shop'        => 'https://fanaticsteamwearnottingham.co.uk/collections/eastwood-cfc',
		'Sponsorship' => home_url( '/sponsorship/' ),
		'Pitch Hire'  => home_url( '/pitch-hire/' ),
		'Academy'     => home_url( '/academy/' ),

		// The footer row, dead on every page of the site until now.
		'Contact us'      => home_url( '/contact/' ),
		'Safeguarding'    => home_url( '/safeguarding/' ),
		'Privacy Policy'  => home_url( '/privacy-policy/' ),
		'Terms'           => home_url( '/terms/' ),
	);
}

/**
 * Labels whose link is taken out of the captured markup altogether.
 *
 * Dropping a label from ew_nav_destinations() is not enough on its own: an
 * unmapped label keeps the dead href="#" it was exported with and stays
 * sitting in the footer looking like a link. A label named here has its
 * whole anchor removed instead. The footer row is bare anchors in a flex
 * line with no list markup around them, so taking one out leaves no gap.
 */
function ew_retired_nav() {
	return array( 'Company Details' );
}

/* ------------------------------------------------------------------
 * The row above the nav: what each item is called and where it goes.
 *
 * The labels and the order came across in the captured markup, so they
 * were whatever the reference build had. Changing one meant editing the
 * theme, which is meant to stay a faithful copy. They are listed here
 * instead and applied on the way out, next to everything else that gets
 * corrected there.
 * ------------------------------------------------------------------ */

/** Captured label => array( what it should say, where it should go ). */
function ew_topbar_changes() {
	return array(
		// It was called Matchday and went to the ticket page, which is not
		// what anyone clicking it is after.
		'Matchday' => array( 'Hospitality', '/hospitality/' ),

		// The room is hired out; that is what the click is for. Hospitality
		// is the match-day product and is its own item two along.
		'The Venue' => array( 'Venue Hire', '/thevenue/' ),
	);
}

/**
 * Pairs that must read in this order, by the words they carry AFTER the
 * relabelling above. They are only swapped when they are the wrong way
 * round, so running the pass twice cannot put them back.
 */
function ew_topbar_order() {
	return array(
		array( 'Shop', 'Venue Hire' ),
		// Sponsorship earns the club money; hiring the room is a service.
		// The money comes first.
		array( 'Sponsorship', 'Venue Hire' ),
	);
}

/**
 * Nav items the captured header does not have at all.
 *
 * Keyed on the item the new one goes after, and cloned from it: an anchor
 * in this bar carries about a thousand characters of utility classes, and
 * a hand-written one would drift from its neighbours the first time the
 * theme is rebuilt. Only the three things that differ are changed -- the
 * words, where it goes, and which symbol it draws.
 *
 * @return array after-label => array( label, path, sprite id ).
 */
function ew_topbar_additions() {
	return array(
		// Beside the room the events happen in.
		'Venue Hire' => array( 'Events', '/events/', 'ew-events' ),
	);
}

/**
 * The whole anchor carrying one label, or '' if it is not on the page.
 *
 * Found by hand rather than by pattern. The first version matched the
 * anchor with a lazy "anything that is not a closing tag" and it worked
 * for the first item and silently failed for the second: each nav anchor
 * carries about a thousand characters of utility classes, and scanning a
 * seventy-kilobyte page that way runs into the backtracking limit, which
 * PCRE reports by returning no match rather than an error. Hospitality
 * changed, Venue Hire did not, and nothing said why. Walking back from
 * the label to the anchor that opens it is exact and costs nothing.
 */
function ew_topbar_anchor( $html, $label ) {
	$needle = '<p class="z-10">' . $label . '</p></a>';
	$at     = strpos( $html, $needle );
	if ( false === $at ) {
		return '';
	}
	$open = strrpos( substr( $html, 0, $at ), '<a ' );
	if ( false === $open ) {
		return '';
	}
	return substr( $html, $open, ( $at + strlen( $needle ) ) - $open );
}

function ew_rewrite_topbar( $html ) {
	foreach ( ew_topbar_changes() as $was => $now ) {
		list( $label, $link ) = $now;

		$anchor = ew_topbar_anchor( $html, $was );
		if ( '' === $anchor ) {
			continue;
		}

		$fixed = str_replace(
			'<p class="z-10">' . $was . '</p>',
			'<p class="z-10">' . esc_html( $label ) . '</p>',
			$anchor
		);
		if ( '' !== $link ) {
			$fixed = preg_replace( '#\shref="[^"]*"#',
				' href="' . esc_url( home_url( $link ) ) . '"', $fixed, 1 );
		}
		// The title attribute still announced the old word on hover and to
		// a screen reader, the same trap Safeguarding had in the footer.
		if ( preg_match( '#\stitle="([^"]*)"#', $fixed, $t )
			&& false === stripos( $t[1], $label ) ) {
			$fixed = preg_replace( '#\stitle="[^"]*"#',
				' title="' . esc_attr( $label ) . '"', $fixed, 1 );
		}

		$html = str_replace( $anchor, $fixed, $html );
	}

	foreach ( ew_topbar_order() as $pair ) {
		$a = ew_topbar_anchor( $html, $pair[0] );
		$b = ew_topbar_anchor( $html, $pair[1] );
		if ( '' === $a || '' === $b || $a === $b ) {
			continue;
		}
		// Already in the right order: leave it. Swapping unconditionally
		// means a second pass undoes the first, which is the kind of bug
		// that only shows up once something else starts running twice.
		if ( strpos( $html, $a ) < strpos( $html, $b ) ) {
			continue;
		}
		// Through a placeholder, or the second replace would find the
		// anchor the first one just wrote and put it straight back.
		$html = str_replace( $a, '<!--ew-swap-->', $html );
		$html = str_replace( $b, $a, $html );
		$html = str_replace( '<!--ew-swap-->', $b, $html );
	}

	/*
	 * A new item, cloned from the one it sits beside. Done after the
	 * order pass, so it follows the item it belongs to wherever that has
	 * ended up, and skipped when the page already carries the label, so a
	 * second pass cannot leave two of them.
	 */
	foreach ( ew_topbar_additions() as $after => $spec ) {
		list( $label, $path, $icon ) = $spec;
		if ( false !== strpos( $html, '<p class="z-10">' . $label . '</p>' ) ) {
			continue;
		}
		$source = ew_topbar_anchor( $html, $after );
		if ( '' === $source ) {
			continue;
		}
		$new = str_replace(
			'<p class="z-10">' . $after . '</p>',
			'<p class="z-10">' . esc_html( $label ) . '</p>',
			$source
		);
		// The anchor's own href comes before the symbol's, so one is one.
		$new = preg_replace( '#\shref="[^"]*"#',
			' href="' . esc_url( home_url( $path ) ) . '"', $new, 1 );
		$new = preg_replace( '#\shref="\#[a-z-]+"#',
			' href="#' . $icon . '"', $new, 1 );
		if ( preg_match( '#\stitle="[^"]*"#', $new ) ) {
			$new = preg_replace( '#\stitle="[^"]*"#',
				' title="' . esc_attr( $label ) . '"', $new, 1 );
		}
		$html = str_replace( $source, $source . $new, $html );
	}

	return $html;
}

function ew_rewrite_nav( $html ) {
	$map = ew_nav_destinations();

	return preg_replace_callback(
		'#<a\b([^>]*\bhref="\#"[^>]*)>(.*?)</a>#s',
		function ( $m ) use ( $map ) {
			// The label sits in a <span> or <p> inside the anchor.
			$label = trim( wp_strip_all_tags( $m[2] ) );

			// A sponsor's logo with nowhere to go. The header strip and the
			// footer wall are both wrapped like this, and a sponsor is
			// paying for the click.
			if ( '' === $label ) {
				$sponsor = ew_sponsor_link_for_markup( $m[2] );
				if ( $sponsor ) {
					$attrs = preg_replace( '#\shref="\#"#', ' href="' . esc_url( $sponsor ) . '"', $m[1], 1 );
					$attrs = preg_replace( '#\sonclick="return false"#', '', $attrs );
					if ( false === strpos( $attrs, 'target=' ) ) {
						$attrs .= ' target="_blank" rel="noopener"';
					}
					return '<a' . $attrs . '>' . $m[2] . '</a>';
				}
			}

			// The crest was exported as a dead link too. Clicking a club
			// badge should always go home.
			if ( '' === $label && false !== strpos( $m[2], '/ew-badges/' ) ) {
				$attrs = preg_replace( '#\shref="\#"#', ' href="' . esc_url( home_url( '/' ) ) . '"', $m[1], 1 );
				$attrs = preg_replace( '#\sonclick="return false"#', '', $attrs );
				return '<a' . $attrs . '>' . $m[2] . '</a>';
			}

			if ( '' !== $label && in_array( $label, ew_retired_nav(), true ) ) {
				return '';
			}

			if ( ! isset( $map[ $label ] ) ) {
				return $m[0];
			}

			$attrs = $m[1];
			$attrs = preg_replace( '#\shref="\#"#', ' href="' . esc_url( $map[ $label ] ) . '"', $attrs, 1 );
			$attrs = preg_replace( '#\sonclick="return false"#', '', $attrs );

			/*
			 * The exported title does not always match the words in the link.
			 * Safeguarding carried title="Be Safe", which is what a screen
			 * reader reads out and what shows on hover, so the link announced
			 * itself as something the page never says. A title that contains
			 * the label is left alone, because those add rather than
			 * contradict -- Terms keeping title="Terms of Use" is fine.
			 */
			if ( preg_match( '#\stitle="([^"]*)"#', $attrs, $t )
				&& false === stripos( $t[1], $label ) ) {
				$attrs = preg_replace( '#\stitle="[^"]*"#',
					' title="' . esc_attr( $label ) . '"', $attrs, 1 );
			}

			// The shop is somebody else's storefront.
			if ( 0 !== strpos( $map[ $label ], home_url() ) && false === strpos( $attrs, 'target=' ) ) {
				$attrs .= ' target="_blank" rel="noopener"';
			}

			return '<a' . $attrs . '>' . $m[2] . '</a>';
		},
		ew_footer_add_history( $html )
	);
}

/* ------------------------------------------------------------------
 * Editorial pages.
 *
 * Pages that are prose rather than a feed get a wrapper class instead
 * of a shortcode, so the words stay in the editor where somebody at
 * the club can change them without touching this plugin. The styling
 * loads whenever that wrapper appears.
 * ------------------------------------------------------------------ */

/**
 * The red band with the page name in it.
 *
 * It used to come from a heading widget, because these pages were in the
 * builder. Taking them back off it left them with no title at all -- a
 * page that opens straight into a paragraph. The title is the page title
 * either way, so it is printed here rather than typed into the content,
 * and a page that already carries its own h1 is left alone.
 */
add_filter( 'the_content', function ( $html ) {
	if ( is_admin() || ! is_singular() || is_front_page() ) {
		return $html;
	}
	if ( false === strpos( $html, 'ew-prose' ) || false !== stripos( $html, '<h1' ) ) {
		return $html;
	}
	$title = get_the_title();
	if ( '' === trim( (string) $title ) ) {
		return $html;
	}
	return '<h1 class="ew-title">' . esc_html( $title ) . '</h1>' . $html;
}, 9 );

/**
 * The rows this plugin writes into Elementor.
 *
 * Every one carries ew-blockrow, and all this does is stop the container
 * contributing spacing of its own: Elementor puts twenty pixels between
 * the widgets in a container, which on a page split into one widget per
 * section appeared seven or eight times down the page and made every
 * written page taller than it was.
 *
 * It lives here, printed on any page that has such a row, rather than in
 * the homepage stylesheet where it started. That stylesheet only prints
 * when a homepage block is on the page, so the written pages never
 * received it -- the same shape of fault as the prose styling gate, and
 * found the same way, by comparing the picture rather than the numbers.
 */
function ew_blockrow_css() {
	return '.e-con.ew-blockrow,.e-con.ew-blockrow>.e-con-inner{'
		. 'gap:0;--gap:0px;--row-gap:0px;--column-gap:0px;'
		. 'row-gap:0;column-gap:0;max-width:none}';
}

add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_singular() ) {
		return;
	}
	$post = get_post();
	if ( ! $post || ! ew_post_contains( $post, 'ew-blockrow' ) ) {
		return;
	}
	wp_register_style( 'ew-blockrow', false, array(), EW_FWP_VERSION );
	wp_enqueue_style( 'ew-blockrow' );
	wp_add_inline_style( 'ew-blockrow', ew_blockrow_css() );
}, 22 );

function ew_prose_css_inline() {
	return '
.ew-prose{margin:0 calc(50% - 50vw);padding:0 0 64px;
 font-family:"Instrument Sans",system-ui,sans-serif;color:#111}
/* The reference holds a 1248px column on every page, so these do too.
   A 1248px line of body copy is too long to read comfortably, so the
   column is wide and the paragraphs inside it are not.

   On a content box, because the gutter that centres the column is
   PADDING on every child, and border-box was taking it out of the
   measure: 72ch minus two 132px gutters left a 503px line hugging the
   left of a 1512px page, which is what "poor layout" looked like. */
.ew-prose > p,.ew-prose > ul,.ew-prose > ol,.ew-prose .ew-lede,
/* And the same again for a paragraph inside an Elementor text widget.
   Every section of these pages is its own editable widget now, so the
   paragraphs are one level further in and the direct-child rule above
   stopped reaching them -- which read as every line running the full
   width of the screen. The gutter stays on the widget itself, from the
   .ew-prose > * rule, so the sum is the same 1248 either way. */
.ew-prose .elementor-widget > p,
.ew-prose .elementor-widget > ul,
.ew-prose .elementor-widget > ol{
 box-sizing:content-box;max-width:72ch}
.ew-prose *{box-sizing:border-box}
.ew-prose .ew-lede{font-size:19px;line-height:1.55;color:#333;margin:0 0 28px}
/* padding-bottom, not a padding shorthand. The shorthand zeroed the
   left and right the column rule sets, so every heading on every prose
   page started at the window edge while its paragraphs started 132px
   in -- headings and text not even sharing a left edge. Same shape of
   fault as the away disc in the fixtures strip. */
.ew-prose h2,
/* Three classes, because Elementor styles its own heading widget with two
   and would otherwise win. */
.ew-prose .elementor-widget-heading h2.elementor-heading-title{
 font-family:Anton,"Instrument Sans",sans-serif;font-size:30px;letter-spacing:.03em;
 text-transform:uppercase;margin:40px 0 14px;padding-bottom:10px;
 border-bottom:2px solid #e3e3e3;color:#111}
/* A heading widget sits in a wrapper of its own, and the first section of a
   page should not start with 40px of air above it. */
.ew-prose > .elementor-element:first-child .elementor-widget-heading h2.elementor-heading-title{
 margin-top:0}
/* Descendant selectors, deliberately: these already reach a paragraph
   inside a text widget, and adding .elementor-widget-text-editor p made
   them MORE specific than .ew-prose .ew-lede, which quietly cost the
   opening paragraph of every page its 19px. */
.ew-prose p{font-size:16px;line-height:1.65;margin:0 0 16px}
.ew-prose ul{margin:0 0 20px;padding-left:20px}
.ew-prose li{font-size:16px;line-height:1.6;margin:0 0 8px}
/* Elementor gives a text widget its own colour and leading; the ones set
   here are the ones that matter. */
.ew-prose .elementor-widget-text-editor{color:#111;font-family:"Instrument Sans",system-ui,sans-serif}
/* Elementor lays a container out with flex, and flex does not collapse
   margins, so every gap on a converted page was the sum of two margins
   instead of the larger of them. The first attempt zeroed the bottom
   margin of whatever ended a widget, which fixed the gap before a
   heading and silently removed the gap everywhere else: eleven pages
   came out between 28 and 390 pixels shorter than the pages they
   replaced.

   A page of prose is not a flex layout. Put the container back into
   normal flow and the browser collapses the margins itself, exactly as
   it did before the page was converted -- a widget wrapper has no
   padding or border, so a margin collapses straight through it. With
   this one line all eleven pages measure identical to the originals,
   element for element. */
.e-con.ew-prose{display:block}
.ew-prose .ew-people{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:8px;margin:0 0 20px}
.ew-prose .ew-person{background:#fff;border:1px solid #e6e6e6;border-left:3px solid #CC0000;
 border-radius:4px;padding:14px 16px}
.ew-prose .ew-person b{display:block;font-size:15px}
.ew-prose .ew-person span{font-size:12px;color:#8a8a8a}
/* The same shorthand, and here it made a black band across the whole
   window instead of a box in the column. The gutter is a margin on this
   one, so the dark starts and stops with the text. */
.ew-prose .ew-cta{background:#111;color:#fff;border-radius:4px;box-sizing:content-box;
 padding:26px 28px;margin:36px max(16px,calc(50vw - 624px)) 0;max-width:72ch}
.ew-prose .ew-cta h2,.ew-prose .ew-cta h3{font-family:Anton,"Instrument Sans",sans-serif;font-size:20px;letter-spacing:.05em;
 text-transform:uppercase;margin:0 0 8px;color:#fff}
.ew-prose .ew-cta p{color:#d6d6d6;margin:0 0 4px;font-size:15px}
.ew-prose .ew-cta a{color:#fff;font-weight:600}
.ew-prose .ew-cta p a{text-decoration:underline}
@media(max-width:720px){
 .ew-prose .ew-lede{font-size:17px}
 /* The three-class selector again. Without it the desktop rule above,
    which needs three classes to beat Elementor, also beat this one, so
    on a phone every heading on a converted page stayed at 30px and
    wrapped onto two lines. */
 .ew-prose h2,
 .ew-prose .elementor-widget-heading h2.elementor-heading-title{font-size:19px}
}';
}

function ew_prose_assets() {
	if ( ! is_singular() ) { return; }
	$post = get_post();
	if ( ! ew_is_prose_page( $post ) ) { return; }

	$css = ew_prose_css_inline();

	wp_register_style( 'eastwood-prose', false );
	wp_enqueue_style( 'eastwood-prose' );
	wp_add_inline_style( 'eastwood-prose', $css );
}
add_action( 'wp_enqueue_scripts', 'ew_prose_assets', 20 );


/* ------------------------------------------------------------------
 * Commercial and supporter pages.
 *
 * Matchday, The Venue, Pitch Hire and Sponsorship. The words come from
 * the club's own Pitchero pages, which turned out to carry nearly all
 * of this already — prices, the accessible-supporters policy, the
 * events list, the 3G hire terms.
 *
 * Three things were corrected on the way across, deliberately:
 *
 *  - The ticket prices were published under a 2024/25 heading. They
 *    are carried over as current on Ben's instruction, to be confirmed
 *    with Steve and Zander at the next team meeting.
 *  - The 3G hire rules contained "Long Eaton United will eject any
 *    persons breaching these rules" — another club's terms, pasted in
 *    and never corrected. It says Eastwood here.
 *  - The full 3G rules run to nine thousand characters of prohibitions.
 *    The page carries what a hirer needs to know and offers the rest on
 *    request, rather than opening with a wall of NO.
 * ------------------------------------------------------------------ */

/**
 * A live read on the gate, for the sponsorship page. Real numbers beat
 * adjectives when you are asking somebody for money.
 */
function ew_gate_shortcode() {
	$res = wp_remote_get(
		'https://api.footballwebpages.co.uk/v2/attendances.json?team=' . EW_TEAM,
		array( 'timeout' => 8, 'headers' => array( 'FWP-API-Key' => (string) get_option( 'ew_fwp_key', '' ) ) )
	);

	$gates = array();
	if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		foreach ( (array) ( $data['attendances']['matches'] ?? array() ) as $m ) {
			// Home games only: an away gate is somebody else's audience.
			if ( (int) ( $m['home-team']['id'] ?? 0 ) === (int) EW_TEAM && ! empty( $m['attendance'] ) ) {
				$gates[] = (int) $m['attendance'];
			}
		}
	}

	if ( count( $gates ) < 2 ) {
		return '';
	}

	$avg  = (int) round( array_sum( $gates ) / count( $gates ) );
	$best = max( $gates );

	ob_start();
	?>
<div class="ew-gate">
	<div class="ew-gate-stat"><b><?php echo esc_html( number_format_i18n( $avg ) ); ?></b><span>average home gate</span></div>
	<div class="ew-gate-stat"><b><?php echo esc_html( number_format_i18n( $best ) ); ?></b><span>best this season</span></div>
	<div class="ew-gate-stat"><b><?php echo esc_html( count( $gates ) ); ?></b><span>home games played</span></div>
</div>
	<?php
	return trim( ob_get_clean() );
}
add_shortcode( 'eastwood_gate', 'ew_gate_shortcode' );

/**
 * The current sponsor wall, from the roster the plugin already keeps.
 */
function ew_sponsors_shortcode() {
	$roster = ew_sponsor_roster();
	if ( empty( $roster ) ) {
		return '';
	}

	ob_start();
	?>
<div class="ew-wall">
	<?php foreach ( $roster as $slug => $s ) :
		list( $name, $tier, $link ) = $s;
		$tag = $link ? 'a' : 'div';
		?>
	<<?php echo $tag; ?> class="ew-wall-item"<?php if ( $link ) : ?> href="<?php echo esc_url( $link ); ?>"
		target="_blank" rel="noopener"<?php endif; ?>>
		<img src="<?php echo esc_url( ew_sponsor_url( $slug ) ); ?>" alt="<?php echo esc_attr( $name ); ?>" loading="lazy">
		<span class="ew-wall-name"><?php echo esc_html( $name ); ?></span>
		<span class="ew-wall-tier"><?php echo esc_html( $tier ); ?></span>
	</<?php echo $tag; ?>>
	<?php endforeach; ?>
</div>
	<?php
	return trim( ob_get_clean() );
}
add_shortcode( 'eastwood_sponsors', 'ew_sponsors_shortcode' );

function ew_tickets_content() {
	return <<<'HTML'
<div class="ew-prose">

<p class="ew-lede">Pay on the gate at Coronation Park — there is no need to buy in advance. Under-16s get in free with an adult.</p>

<p>Coming to a game for the first time? <a href="/matchday/">Everything you need for matchday</a> — where we are, parking, and what to expect when you get here.</p>

<h2>First team, league matches</h2>

<div class="ew-prices">
	<div class="ew-price"><b>£7.00</b><span>Adults</span></div>
	<div class="ew-price"><b>£5.00</b><span>Concessions, 65 and over</span></div>
	<div class="ew-price"><b>£5.00</b><span>Students, with valid ID</span></div>
	<div class="ew-price"><b>Free</b><span>Under 16s, with an adult</span></div>
</div>

<h2>U21 matches</h2>

<div class="ew-prices">
	<div class="ew-price"><b>£3.00</b><span>Adults</span></div>
	<div class="ew-price"><b>£2.00</b><span>Concessions</span></div>
	<div class="ew-price"><b>Free</b><span>Under 14s</span></div>
</div>

<p>U21 matches are free to season ticket holders.</p>

<h2>Season tickets</h2>

<p>A season ticket covers every first team home league game. It can be paid in full or across three monthly instalments.</p>

<div class="ew-prices">
	<div class="ew-price"><b>£105</b><span>Adults, 16 to 65</span></div>
	<div class="ew-price"><b>£75</b><span>Concessions, 65 and over</span></div>
	<div class="ew-price"><b>£75</b><span>Students, with valid ID</span></div>
	<div class="ew-price"><b>Free</b><span>Under 16s, with an adult</span></div>
</div>

<p>Under-3s come in free with a paying adult and are not issued a season ticket. Season tickets cover league games only, run from August to the end of the following June, and are not transferable. Ask us for a copy of the full terms.</p>

<h2>Accessible tickets</h2>

<p>Accessible tickets, by the match or by the season, are available to supporters receiving any of the following: the middle or higher rate of Disability Living Allowance, Attendance Allowance, Severe Disablement Allowance, a War Disabled Pension, a Certificate of Visual Impairment, or enhanced Personal Independence Payment. Bring the letter or certificate to the Pitchside Bar &amp; Lounge — evidence is needed once a year.</p>

<p>Anyone qualifying gets the reduced rate and a free enabler ticket. The enabler is responsible for the supporter they accompany and should stay with them throughout; an enabler arriving without them needs to upgrade at the bar before kick-off.</p>

<p>What the ground itself is like to get around is on the <a href="/matchday/">matchday page</a>.</p>

<div class="ew-cta">
	<h3>Ask about tickets</h3>
	<p><a href="mailto:info@eastwoodfc.com">info@eastwoodfc.com</a> &nbsp;·&nbsp; <a href="tel:+441773432414">01773 432414</a></p>
</div>

</div>
HTML;
}

function ew_matchday_content() {
	return <<<'HTML'
<div class="ew-prose">

<p class="ew-lede">Eastwood play at Coronation Park on Chewton Street. Pay on the gate, park on site for nothing, and under-16s get in free with an adult.</p>

<p>What it costs is on the <a href="/tickets/">tickets page</a>. This is everything else.</p>

<h2>Getting here</h2>

<p>Coronation Park, Chewton Street, Eastwood. There is parking on site and it is free, unless we say otherwise for a particular fixture.</p>

<h2>On the day</h2>

<p>There is no need to buy in advance — pay on the gate. Under-16s get in free with an adult, and under-3s come in free too.</p>

<p>The Pitchside Bar &amp; Lounge is open on matchdays, and is where to go if you need to ask anyone anything.</p>

<h2>Getting around the ground</h2>

<p>The clubhouse is reached by a ramp at the entrance to the main building. There are four spaces at pitch level in front of the Main Stand for wheelchair users, for home and away supporters alike, with helpers able to sit alongside or stand at the fence. Supporters with specific access needs who are able to walk can sit pitch side in the first few rows of the Main Stand. There are two accessible toilets at the ground.</p>

<p>Please ring ahead on 01773 432414 if you need a particular space reserved, or an accessible parking space — those are first come, first served and must be booked in advance. There are always staff about who can help.</p>

<p>Reduced-rate tickets for supporters who qualify, and the free enabler ticket that comes with them, are explained on the <a href="/tickets/">tickets page</a>.</p>

<h2>Hospitality</h2>

<p>Hospitality is available for every home game in the Pitchside Bar &amp; Lounge. It usually includes admission, a reserved parking space, a team sheet, and food before the game and at half time. <a href="/hospitality/">See the packages</a>.</p>

<h2>Tell us how it went</h2>

<p>If something about your visit could have been better, tell us. We would rather hear it.</p>

<div class="ew-cta">
	<h3>Any questions before you come</h3>
	<p><a href="mailto:info@eastwoodfc.com">info@eastwoodfc.com</a> &nbsp;·&nbsp; <a href="tel:+441773432414">01773 432414</a></p>
</div>

</div>
HTML;
}


function ew_pitchhire_content() {
	return <<<'HTML'
<div class="ew-prose">

<p class="ew-lede">A floodlit, all-weather 3G pitch at Coronation Park, available all year round — and there is usually plenty of space in the diary.</p>

<p>The 3G represents a serious investment by Eastwood CFC, the Premier League and the FA. Built properly and looked after, the surface has a life of at least ten years, which is why the rules below matter.</p>

<h2>Booking</h2>

<p>Call 01773 432414 or email info@eastwoodfc.com and tell us what you need and when. Changing rooms and the Pitchside Bar can be hired alongside the pitch.</p>

<p>Bookings are honoured unless a rearranged Eastwood home game lands on a slot you have already booked. If that happens you get a credit and first refusal on a new date.</p>

<h2>Using the pitch</h2>

<ul>
	<li><b>Footwear:</b> moulded rubber studs only, coaches included. No flat soles, no metal studs, blades or spikes, and no astroboots. Boots must be clean — there are scrubbers in the spectator area and outside the entrance gate. Nobody goes on the pitch in the wrong footwear.</li>
	<li><b>Drinks:</b> bottled water only on the playing surface. No soft or fruit-based drinks.</li>
	<li><b>No food</b> on the surface, and please keep food and drink out of the spectator area.</li>
	<li><b>No chewing gum.</b></li>
	<li><b>No smoking or vaping</b> anywhere inside the green perimeter fencing. That is the law, not a house rule.</li>
	<li><b>No spectators</b> on the playing surface.</li>
	<li><b>No vehicles</b> on the 3G under any circumstances.</li>
	<li><b>Goals:</b> ask a staff member for an induction first. All goals are wheeled and must be raised onto all their wheels and moved by four people. Put them back where you found them.</li>
	<li><b>Finish on time</b> and leave the pitch clear of litter.</li>
</ul>

<p>Floodlights are automatic and switch off at closing time. The surface is rated for football and general sport, fitness and recreation — if you are unsure whether what you have planned is covered, ask us before the booking starts.</p>

<h2>Safety</h2>

<p>We do not provide first aid cover for hirers, so you need your own arrangements and a way to call the emergency services. There is a first aid box behind the bar and a defibrillator on the wall behind the dugouts. Any accident anywhere on the site must be reported to Eastwood CFC. Hirers play at their own risk.</p>

<p>Alcohol is permitted on site but not on or inside the 3G pitches without written permission for an event. Drugs are not permitted anywhere, and anyone breaching either will be ejected — drug incidents are reported to the police. Dogs are not allowed on the site, other than assistance dogs, which are not permitted on the playing surface.</p>

<p>These sit alongside our general terms and conditions of hire, which we will send you with your booking. Whoever makes the booking is responsible for everyone who comes with them.</p>

<div class="ew-cta">
	<h3>Check availability</h3>
	<p><a href="mailto:info@eastwoodfc.com">info@eastwoodfc.com</a> &nbsp;·&nbsp; <a href="tel:+441773432414">01773 432414</a></p>
</div>

</div>
HTML;
}


function ew_commercial_assets() {
	if ( ! is_singular() ) { return; }
	$post = get_post();
	if ( ! ew_is_prose_page( $post ) ) { return; }

	$css = '
.ew-prose .ew-prices{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:8px;margin:0 0 20px}
.ew-prose .ew-price{background:#fff;border:1px solid #e6e6e6;border-top:3px solid #CC0000;border-radius:4px;padding:16px 18px}
.ew-prose .ew-price b{display:block;font-family:Anton,"Instrument Sans",sans-serif;font-size:30px;line-height:1;color:#111}
.ew-prose .ew-price span{display:block;margin-top:6px;font-size:13px;color:#6b6b6b;line-height:1.35}
.ew-gate{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;margin:0 0 28px}
.ew-gate-stat{background:#111;border-radius:4px;padding:18px 20px}
.ew-gate-stat b{display:block;font-family:Anton,"Instrument Sans",sans-serif;font-size:34px;line-height:1;color:#fff}
.ew-gate-stat span{display:block;margin-top:5px;font-size:12px;color:#9a9a9a}
.ew-wall{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;margin:0 0 24px}
.ew-wall-item{display:block;background:#fff;border:1px solid #e6e6e6;border-radius:4px;padding:16px 14px;
 text-align:center;text-decoration:none;color:#111;transition:border-color .15s,box-shadow .15s}
a.ew-wall-item:hover{border-color:#CC0000;box-shadow:0 2px 10px rgba(0,0,0,.07)}
.ew-wall-item img{display:block;width:100%;height:58px;object-fit:contain;margin:0 auto 10px}
.ew-wall-name{display:block;font-weight:600;font-size:13px;line-height:1.3}
.ew-wall-tier{display:block;margin-top:2px;font-size:11px;color:#8a8a8a}
@media(max-width:720px){
 .ew-prose .ew-price b{font-size:26px}
 .ew-wall{grid-template-columns:repeat(auto-fill,minmax(125px,1fr))}
}';

	wp_register_style( 'eastwood-commercial', false );
	wp_enqueue_style( 'eastwood-commercial' );
	wp_add_inline_style( 'eastwood-commercial', $css );
}
add_action( 'wp_enqueue_scripts', 'ew_commercial_assets', 21 );

/**
 * The club history page.
 *
 * The previous version carried a founding date, a list of 2026 milestones,
 * the first team managers going back to 2014, an FA Youth Cup record season
 * by season and a count of the teams the club runs. Ben does not believe
 * that material is right, so none of it is published while it is unverified.
 * It is not lost: it is in this file up to and including the commit that
 * replaced it, and can be restored a section at a time as each is confirmed.
 */
function ew_history_content() {
	return <<<'HTML'
<div class="ew-prose">

<p class="ew-lede">Coming soon.</p>

</div>
HTML;
}

function ew_history_assets() {
	if ( ! is_singular() ) { return; }
	$post = get_post();
	if ( ! ew_post_contains( $post, 'ew-timeline' ) ) { return; }

	$css = '
.ew-prose .ew-timeline{margin:0 0 24px;border-left:2px solid #e3e3e3;padding-left:0}
.ew-prose .ew-tl{display:grid;grid-template-columns:96px 1fr;gap:16px;align-items:baseline;
 padding:10px 0 10px 20px;position:relative}
.ew-prose .ew-tl::before{content:"";position:absolute;left:-5px;top:17px;width:8px;height:8px;
 border-radius:50%;background:#fff;border:2px solid #c9c9c9}
.ew-prose .ew-tl:last-child::before{background:#CC0000;border-color:#CC0000}
.ew-prose .ew-tl b{font-family:Anton,"Instrument Sans",sans-serif;font-size:15px;letter-spacing:.03em;color:#6b6b6b}
.ew-prose .ew-tl span{font-size:16px;font-weight:600}
.ew-prose .ew-tl i{font-style:normal;font-weight:400;font-size:13px;color:#8a8a8a}
@media(max-width:720px){
 .ew-prose .ew-tl{grid-template-columns:78px 1fr;gap:12px}
 .ew-prose .ew-tl span{font-size:15px}
}';

	wp_register_style( 'eastwood-history', false );
	wp_enqueue_style( 'eastwood-history' );
	wp_add_inline_style( 'eastwood-history', $css );
}
add_action( 'wp_enqueue_scripts', 'ew_history_assets', 22 );

/* ------------------------------------------------------------------
 * The front page.
 *
 * The homepage the clone left behind was a hero and a news grid, and
 * the hero did not work: I hand-wrote home.php using Nottingham
 * Forest's Tailwind class names after the stylesheet had been pruned
 * to only the classes present in the captured HTML, so min-h-[420px],
 * text-clear and the stacking context the negative z-index relies on
 * were never in the CSS. The featured image loaded and then painted
 * behind the section's own opaque background. A club's front page was
 * a headline over eight hundred pixels of grey.
 *
 * Rebuilt here rather than patched, for two reasons. The front page of
 * a football club should open with the football — when the next game
 * is, how the last one went, where the club sits — and none of that
 * was on it. And this ships through the update channel, where a theme
 * change means the slow base64 route.
 *
 * Everything below carries its own CSS. Nothing depends on a utility
 * class surviving a prune. That is the actual lesson from the bug.
 * ------------------------------------------------------------------ */

/**
 * Server-side read of a Football Web Pages endpoint, cached.
 * The front page should not wait on a network round trip, and it
 * should render for a crawler that runs no JavaScript.
 */
function ew_fwp_fetch( $endpoint, $args = array(), $raw = false ) {
	$args = array_merge( array( 'team' => EW_TEAM ), $args );
	$url  = add_query_arg( $args, 'https://api.footballwebpages.co.uk/v2/' . $endpoint . '.json' );
	$key  = 'ewhome_' . md5( $url );

	$hit = get_transient( $key );
	if ( false !== $hit ) {
		return $raw ? $hit : ew_fwp_corrections( $hit );
	}

	$res = wp_remote_get( $url, array(
		'timeout' => 8,
		'headers' => array( 'FWP-API-Key' => (string) get_option( 'ew_fwp_key', '' ) ),
	) );

	$data = array();
	if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
		$decoded = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( is_array( $decoded ) ) {
			$data = $decoded;
		}
	}

	/*
	 * What is cached is the feed as it arrived, and the corrections go on
	 * afterwards. It used to cache the corrected answer, which meant a
	 * correction typed into the Fixtures screen did not show on the site
	 * for up to ten minutes -- and, now that a correction remembers what
	 * the feed said when it was made, there would be nothing left to
	 * compare it against.
	 */
	set_transient( $key, $data, empty( $data ) ? 2 * MINUTE_IN_SECONDS : 10 * MINUTE_IN_SECONDS );
	return $raw ? $data : ew_fwp_corrections( $data );
}

/**
 * The feed calls us "Eastwood CFC". Every other surface — the crest, the
 * header, the way anyone here says it out loud — is "Eastwood FC". Fix it
 * once on the way in rather than at each of the dozen places a team name
 * gets printed, so fixtures, the banner, the table and the match pages all
 * agree. Only our own club is touched; opponents keep whatever the feed
 * calls them.
 */
function ew_fwp_fix_names( $node ) {
	if ( is_array( $node ) ) {
		foreach ( $node as $k => $v ) {
			if ( 'name' === $k && is_string( $v ) && 'eastwood cfc' === strtolower( trim( $v ) ) ) {
				$node[ $k ] = 'Eastwood FC';
			} else {
				$node[ $k ] = ew_fwp_fix_names( $v );
			}
		}
	}
	return $node;
}

/**
 * EW_BADGE_V is a JavaScript global, not a PHP constant, so compute the
 * same stamp here — newest crest mtime — rather than leaving the first
 * server-rendered paint uncached-busted and waiting for the script.
 */
function ew_home_badge( $id ) {
	static $v = null;
	if ( null === $v ) {
		$newest = 0;
		foreach ( (array) glob( ew_badge_dir() . '*.png' ) as $file ) {
			$newest = max( $newest, (int) filemtime( $file ) );
		}
		$v = $newest ? '?v=' . $newest : '';
	}
	$id = (int) $id;

	// A crest can also travel with the plugin, the way competition badges do.
	// The uploads copy always wins when there is one -- that is what the
	// Badges screen writes -- but a club the feed has never heard of, in a
	// tie we added ourselves, has no other way of arriving on a fresh
	// install. Lye Town in the Vase is the case this was written for.
	if ( ! file_exists( ew_badge_dir() . $id . '.png' ) ) {
		$ship = plugin_dir_path( __FILE__ ) . 'assets/clubs/' . $id . '.png';
		if ( file_exists( $ship ) ) {
			return plugins_url( 'assets/clubs/' . $id . '.png', __FILE__ );
		}
	}

	return content_url( '/uploads/ew-badges/' . $id . '.png' ) . $v;
}

function ew_home_played( $m ) {
	return ! empty( $m['status']['short'] ) && 'FT' === $m['status']['short'];
}

/** One side of a fixture: crest, name, and whether it is us. */
function ew_home_side( $team, $score = null ) {
	$us = (int) ( $team['id'] ?? 0 ) === (int) EW_TEAM;
	ob_start();
	?>
<span class="ewh-side<?php echo $us ? ' is-us' : ''; ?>">
	<img src="<?php echo esc_url( ew_home_badge( $team['id'] ?? 0 ) ); ?>" alt=""
		onerror="this.style.visibility='hidden'">
	<span class="ewh-name"><?php echo esc_html( $team['name'] ?? '' ); ?></span>
	<?php if ( null !== $score ) : ?><span class="ewh-score"><?php echo esc_html( $score ); ?></span><?php endif; ?>
</span>
	<?php
	return ob_get_clean();
}

function ew_home_football() {
	$fx  = ew_fwp_fetch( 'fixtures-results' );
	$all = (array) ( $fx['fixtures-results']['matches'] ?? array() );
	if ( empty( $all ) ) {
		return '';
	}

	$played = array_values( array_filter( $all, 'ew_home_played' ) );
	$todo   = array_values( array_filter( $all, function ( $m ) { return ! ew_home_played( $m ); } ) );
	usort( $played, function ( $a, $b ) { return strcmp( $b['date'], $a['date'] ); } );
	usort( $todo,   function ( $a, $b ) { return strcmp( $a['date'], $b['date'] ); } );

	$next = $todo[0]   ?? null;
	$last = $played[0] ?? null;

	// Our row in the table, for the position panel.
	$lt  = ew_fwp_fetch( 'league-table' );
	$row = null;
	foreach ( (array) ( $lt['league-table']['teams'] ?? array() ) as $t ) {
		if ( (int) ( $t['id'] ?? 0 ) === (int) EW_TEAM ) { $row = $t; break; }
	}

	ob_start();
	?>
<section class="ewh-football">
	<div class="ewh-wrap">

		<?php if ( $next ) :
			$home = (int) ( $next['home-team']['id'] ?? 0 ) === (int) EW_TEAM;
			$ts   = strtotime( $next['date'] );
			?>
		<a class="ewh-panel ewh-next" href="<?php echo esc_url( home_url( '/matches/' ) ); ?>">
			<span class="ewh-kicker">Next match<?php echo $home ? ' · Home' : ' · Away'; ?></span>
			<div class="ewh-fixture">
				<?php
				echo ew_home_side( $next['home-team'] ); // phpcs:ignore
				echo '<span class="ewh-v">v</span>';
				echo ew_home_side( $next['away-team'] ); // phpcs:ignore
				?>
			</div>
			<span class="ewh-when">
				<b><?php echo esc_html( date_i18n( 'D j M', $ts ) ); ?></b>
				<?php echo esc_html( ! empty( $next['time'] ) ? substr( $next['time'], 0, 5 ) : '' ); ?>
				<?php if ( ! empty( $next['competition']['name'] ) ) : ?>
				<i><?php echo esc_html( $next['competition']['name'] ); ?></i>
				<?php endif; ?>
			</span>
		</a>
		<?php endif; ?>

		<?php if ( $last ) : $ts = strtotime( $last['date'] ); ?>
		<a class="ewh-panel ewh-last" href="<?php echo esc_url( home_url( '/matches/#results' ) ); ?>">
			<span class="ewh-kicker">Last result</span>
			<div class="ewh-fixture">
				<?php
				echo ew_home_side( $last['home-team'], $last['home-team']['score'] ?? '' ); // phpcs:ignore
				echo '<span class="ewh-v">&ndash;</span>';
				echo ew_home_side( $last['away-team'], $last['away-team']['score'] ?? '' ); // phpcs:ignore
				?>
			</div>
			<span class="ewh-when">
				<b><?php echo esc_html( date_i18n( 'D j M', $ts ) ); ?></b>
				<?php if ( ! empty( $last['attendance'] ) ) : ?>
				Att <?php echo esc_html( number_format_i18n( $last['attendance'] ) ); ?>
				<?php endif; ?>
			</span>
		</a>
		<?php endif; ?>

		<?php if ( $row ) : $a = (array) ( $row['all-matches'] ?? array() ); ?>
		<a class="ewh-panel ewh-pos" href="<?php echo esc_url( home_url( '/matches/#table' ) ); ?>">
			<span class="ewh-kicker">League</span>
			<span class="ewh-posn"><?php echo esc_html( $row['position'] ?? '' ); ?><sup><?php
				echo esc_html( ew_home_ordinal( (int) ( $row['position'] ?? 0 ) ) ); ?></sup></span>
			<span class="ewh-when">
				<b><?php echo esc_html( $row['total-points'] ?? '' ); ?> pts</b>
				<?php echo esc_html( $a['played'] ?? '' ); ?> played
				<i>United Counties Premier North</i>
			</span>
		</a>
		<?php endif; ?>

	</div>
</section>
	<?php
	return ob_get_clean();
}

function ew_home_ordinal( $n ) {
	if ( $n % 100 >= 11 && $n % 100 <= 13 ) { return 'th'; }
	switch ( $n % 10 ) {
		case 1: return 'st';
		case 2: return 'nd';
		case 3: return 'rd';
	}
	return 'th';
}

/** The lead news story, with an image treatment that actually paints. */
function ew_home_lead() {
	$posts = get_posts( array( 'numberposts' => 4 ) );
	if ( empty( $posts ) ) {
		return '';
	}
	$lead = array_shift( $posts );

	ob_start();
	?>
<section class="ewh-news">
	<div class="ewh-wrap">
		<h2 class="ewh-head">Latest news <a href="<?php echo esc_url( home_url( '/news/' ) ); ?>">All news</a></h2>
		<div class="ewh-newsGrid">
			<a class="ewh-lead" href="<?php echo esc_url( get_permalink( $lead ) ); ?>">
				<?php if ( has_post_thumbnail( $lead ) ) : ?>
				<img src="<?php echo esc_url( get_the_post_thumbnail_url( $lead, 'full' ) ); ?>" alt="">
				<?php endif; ?>
				<span class="ewh-leadText">
					<b><?php echo esc_html( get_the_title( $lead ) ); ?></b>
					<i><?php
						$c = get_the_category( $lead->ID );
						echo esc_html( $c ? $c[0]->name : '' );
						echo ' · ' . esc_html( human_time_diff( get_the_time( 'U', $lead ), current_time( 'timestamp' ) ) ) . ' ago';
					?></i>
				</span>
			</a>
			<div class="ewh-rest">
				<?php foreach ( $posts as $p ) : ?>
				<a class="ewh-item" href="<?php echo esc_url( get_permalink( $p ) ); ?>">
					<?php if ( has_post_thumbnail( $p ) ) : ?>
					<img src="<?php echo esc_url( get_the_post_thumbnail_url( $p, 'medium' ) ); ?>" alt="">
					<?php endif; ?>
					<span>
						<b><?php echo esc_html( get_the_title( $p ) ); ?></b>
						<i><?php echo esc_html( human_time_diff( get_the_time( 'U', $p ), current_time( 'timestamp' ) ) ); ?> ago</i>
					</span>
				</a>
				<?php endforeach; ?>
			</div>
		</div>
	</div>
</section>
	<?php
	return ob_get_clean();
}

/** The newest documentary episode, or failing that the newest upload. */
function ew_home_video() {
	$videos = function_exists( 'ew_tv_feed' ) ? ew_tv_feed() : array();
	if ( empty( $videos ) ) {
		return '';
	}
	$pick = $videos[0];
	foreach ( $videos as $v ) {
		if ( 'documentary' === $v['series'] ) { $pick = $v; break; }
	}

	ob_start();
	?>
<section class="ewh-video">
	<div class="ewh-wrap">
		<h2 class="ewh-head">Eastwood TV <a href="<?php echo esc_url( home_url( '/eastwood-tv/' ) ); ?>">All video</a></h2>
		<a class="ewh-videoCard" href="https://www.youtube.com/watch?v=<?php echo esc_attr( $pick['id'] ); ?>"
			target="_blank" rel="noopener">
			<span class="ewh-videoThumb">
				<img src="https://i.ytimg.com/vi/<?php echo esc_attr( $pick['id'] ); ?>/maxresdefault.jpg"
					alt="" loading="lazy"
					onerror="this.src='https://i.ytimg.com/vi/<?php echo esc_attr( $pick['id'] ); ?>/hqdefault.jpg'">
				<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5.5v13l11-6.5z"/></svg>
			</span>
			<span class="ewh-videoText">
				<b><?php echo esc_html( $pick['title'] ); ?></b>
				<i><?php echo esc_html( date_i18n( 'j F Y', $pick['published'] ) ); ?></i>
			</span>
		</a>
	</div>
</section>
	<?php
	return ob_get_clean();
}

function ew_home_css() {
	return '
.ewh-wrap{max-width:1180px;margin:0 auto;padding:0 16px}
.ewh-football,.ewh-news,.ewh-video{font-family:"Instrument Sans",system-ui,sans-serif;color:#111}
.ewh-football *,.ewh-news *,.ewh-video *{box-sizing:border-box}
.ewh-football{background:#111;padding:26px 0 30px}
.ewh-football .ewh-wrap{display:grid;grid-template-columns:1.35fr 1.35fr 1fr;gap:10px}
.ewh-panel{display:flex;flex-direction:column;gap:12px;background:#17171a;border-radius:5px;
 padding:18px 20px;text-decoration:none;color:#fff;border-top:3px solid #CC0000;
 transition:background .15s}
.ewh-panel:hover{background:#1f1f24}
.ewh-pos{border-top-color:#3a3a42}
.ewh-kicker{font-family:Anton,"Instrument Sans",sans-serif;font-size:11px;letter-spacing:.12em;
 text-transform:uppercase;color:#CC0000}
.ewh-pos .ewh-kicker{color:#9a9aa3}
.ewh-fixture{display:flex;flex-direction:column;gap:8px;flex:1;justify-content:center}
.ewh-side{display:flex;align-items:center;gap:10px}
.ewh-side img{width:26px;height:26px;object-fit:contain;flex:none}
.ewh-name{font-size:15px;font-weight:600;color:#ededf0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ewh-side.is-us .ewh-name{color:#fff}
.ewh-score{margin-left:auto;font-family:Anton,sans-serif;font-size:20px;color:#fff}
.ewh-v{font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:#5d5d66;padding-left:36px}
.ewh-when{font-size:12px;color:#9a9aa3;display:flex;align-items:baseline;gap:8px;flex-wrap:wrap}
.ewh-when b{font-family:Anton,sans-serif;font-size:14px;letter-spacing:.03em;color:#fff}
.ewh-when i{font-style:normal;color:#5d5d66;width:100%}
.ewh-posn{font-family:Anton,"Instrument Sans",sans-serif;font-size:56px;line-height:.9;color:#fff;flex:1;
 display:flex;align-items:center}
.ewh-posn sup{font-size:20px;vertical-align:super;line-height:1}
.ewh-news,.ewh-video{padding:40px 0 0}
.ewh-video{padding-bottom:56px}
.ewh-head{font-family:Anton,"Instrument Sans",sans-serif;font-size:22px;letter-spacing:.05em;
 text-transform:uppercase;margin:0 0 18px;display:flex;align-items:baseline;gap:16px;color:#111}
.ewh-head a{font-family:"Instrument Sans",sans-serif;font-size:13px;letter-spacing:0;text-transform:none;
 color:#CC0000;text-decoration:none;margin-left:auto;font-weight:600}
.ewh-head a:hover{text-decoration:underline}
.ewh-newsGrid{display:grid;grid-template-columns:1.6fr 1fr;gap:16px}
.ewh-lead{position:relative;display:block;min-height:400px;border-radius:5px;overflow:hidden;
 background:#111;text-decoration:none}
.ewh-lead img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;
 transition:transform .4s}
.ewh-lead:hover img{transform:scale(1.03)}
.ewh-lead::after{content:"";position:absolute;inset:0;
 background:linear-gradient(to top,rgba(0,0,0,.82) 0%,rgba(0,0,0,.35) 45%,rgba(0,0,0,0) 75%)}
.ewh-leadText{position:absolute;left:0;right:0;bottom:0;z-index:2;display:block;padding:24px}
.ewh-leadText b{display:block;font-family:Anton,"Instrument Sans",sans-serif;font-size:30px;
 line-height:1.08;color:#fff;margin:0 0 7px}
.ewh-leadText i{font-style:normal;font-size:12px;color:#d6d6d6}
.ewh-rest{display:flex;flex-direction:column;gap:10px}
.ewh-item{display:flex;gap:14px;align-items:center;background:#fff;border:1px solid #e6e6e6;
 border-radius:5px;overflow:hidden;text-decoration:none;color:#111;flex:1;min-height:0;
 transition:border-color .15s,box-shadow .15s}
.ewh-item:hover{border-color:#CC0000;box-shadow:0 2px 10px rgba(0,0,0,.07)}
.ewh-item img{width:118px;height:100%;min-height:92px;object-fit:cover;flex:none}
.ewh-item span{padding:12px 14px 12px 0;min-width:0}
.ewh-item b{display:block;font-size:15px;line-height:1.3;margin:0 0 4px}
.ewh-item i{font-style:normal;font-size:12px;color:#8a8a8a}
.ewh-videoCard{display:grid;grid-template-columns:1.6fr 1fr;gap:20px;align-items:center;
 text-decoration:none;color:#111}
.ewh-videoThumb{position:relative;display:block;border-radius:5px;overflow:hidden;background:#111;
 aspect-ratio:16/9}
.ewh-videoThumb img{width:100%;height:100%;object-fit:cover;display:block;transition:opacity .2s}
.ewh-videoCard:hover .ewh-videoThumb img{opacity:.85}
.ewh-videoThumb svg{position:absolute;left:50%;top:50%;width:62px;height:62px;
 transform:translate(-50%,-50%);fill:#fff;filter:drop-shadow(0 2px 10px rgba(0,0,0,.6))}
.ewh-videoCard:hover .ewh-videoThumb svg{fill:#CC0000}
.ewh-videoText b{display:block;font-family:Anton,"Instrument Sans",sans-serif;font-size:26px;
 line-height:1.1;margin:0 0 8px}
.ewh-videoText i{font-style:normal;font-size:13px;color:#8a8a8a}
@media(max-width:1000px){
 .ewh-football .ewh-wrap{grid-template-columns:1fr 1fr}
 .ewh-pos{grid-column:1/-1;flex-direction:row;align-items:center;gap:18px}
 .ewh-posn{flex:none;font-size:40px}
 .ewh-newsGrid,.ewh-videoCard{grid-template-columns:1fr}
 .ewh-lead{min-height:300px}
}
@media(max-width:640px){
 .ewh-football .ewh-wrap{grid-template-columns:1fr}
 .ewh-leadText b{font-size:23px}
 .ewh-videoText b{font-size:20px}
 .ewh-item img{width:96px}
}';
}

/**
 * Take over the front page.
 *
 * Rendered here rather than from a theme template so it ships with the
 * plugin through the update channel. Priority 5 so the output-buffer
 * rewrite registered at priority 1 is already in place and still runs
 * over what we print.
 */
add_action( 'template_redirect', function () {
	// Every custom route here sets no post type, so WordPress calls it the home
	// query and is_front_page() comes back true. Without this guard the front
	// page renders and exits before the real handler runs — which is exactly
	// what happened to /eastwood-news/ when this takeover was added.
	//
	// One list, checked in one place. Anything routed by a query var goes in
	// it at the moment the route is written, not after somebody notices.
	foreach ( array( 'ew_news', 'ew_match', 'ew_team' ) as $ew_route ) {
		if ( get_query_var( $ew_route ) ) {
			return;
		}
	}
	if ( is_admin() || ! is_front_page() || is_feed() || is_embed() ) {
		return;
	}

	// A page chosen as the homepage in Settings wins over the capture.
	// This takeover is the reason choosing one appeared to do nothing:
	// the setting saved, the template filter stood aside, and then this
	// printed the captured front page over the top of it anyway. Switch
	// Reading back to "Your latest posts" and the capture returns.
	if ( 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) ) {
		return;
	}

	// The captured replica front page — Forest's structure, Eastwood's
	// content — is the brief. It lives in its own file because it is 120KB
	// of markup, and it ships in the same repo so the updater installs it
	// alongside this one.
	$capture = __DIR__ . '/home-template.php';
	if ( file_exists( $capture ) ) {
		require_once $capture;
	}

	add_action( 'wp_head', function () {
		/*
		 * This used to be id="ew-home", which is also the id of the star in
		 * the icon sprite. The stylesheet is printed in the head and the
		 * sprite much further down, so the <use href="#ew-home"> drawing the
		 * star resolved to this style element and drew nothing at all.
		 */
		echo '<style id="ew-home-css">' . ew_home_css() . '</style>';
	}, 20 );

	get_header();

	// The captured replica is NOT fit to serve. Restored on 21 Sep it put
	// Nottingham Forest's own press-conference videos — their manager, their
	// crest, Forest TV branding — live on this club's front page, with a black
	// void where the hero image had been stripped and news cards with no
	// images at all. Off until the Forest blocks are replaced with Eastwood
	// ones and the imagery actually resolves.
	if ( false && function_exists( 'ew_home_capture_markup' ) ) {
		// The replica page. Its news cards are filled live by the theme's
		// fillNews(), its carousels mounted by mountCarousels(), and the
		// output-buffer rewrite cleans out what the clone left behind.
		echo ew_home_capture_markup(); // phpcs:ignore WordPress.Security.EscapeOutput

		// The one thing the capture cannot carry: today's fixture, result
		// and league position. Appended rather than replacing anything.
		echo ew_home_football(); // phpcs:ignore WordPress.Security.EscapeOutput
	} else {
		// Fallback if the template file did not install.
		echo ew_home_football(); // phpcs:ignore WordPress.Security.EscapeOutput
		echo ew_home_lead();     // phpcs:ignore WordPress.Security.EscapeOutput
		echo ew_home_video();    // phpcs:ignore WordPress.Security.EscapeOutput
	}

	get_footer();
	exit;
}, 5 );

/* ------------------------------------------------------------------
 * The match centre.
 *
 * Fixtures and results were dead ends: the feed carries line-ups,
 * goalscorers with minutes, red cards, the referee and the gate for
 * every match, and none of it was reachable. One page per match at
 * /match/<id>/, rendered server-side and linked from the fixtures and
 * results tabs.
 *
 * The feed records who came on and who they replaced, but marks no
 * yellow cards at this level, so the page shows what exists and does
 * not invent a bookings section.
 * ------------------------------------------------------------------ */

const EW_MATCH_SLUG  = 'match';
const EW_MATCH_RULES = '1';

add_action( 'init', function () {
	add_rewrite_rule( '^' . EW_MATCH_SLUG . '/([0-9]{1,})/?$', 'index.php?ew_match=$matches[1]', 'top' );
	if ( get_option( 'ew_match_rules' ) !== EW_MATCH_RULES ) {
		flush_rewrite_rules( false );
		update_option( 'ew_match_rules', EW_MATCH_RULES );
	}
}, 21 );

add_filter( 'query_vars', function ( $vars ) {
	$vars[] = 'ew_match';
	return $vars;
} );

function ew_match_data( $id ) {
	$id = (int) $id;
	if ( ! $id ) {
		return array();
	}
	$d = ew_fwp_fetch( 'match', array( 'match' => $id ) );
	unset( $d['team'] );
	return (array) ( $d['match'] ?? array() );
}

/** Starters, then those who came on, then the unused bench. */
function ew_match_lineup( $side ) {
	$on = $started = $bench = array();
	foreach ( (array) ( $side['line-up'] ?? array() ) as $p ) {
		if ( isset( $p['substitution'] ) )      { $on[] = $p; }
		elseif ( (int) ( $p['sort'] ?? 99 ) <= 11 ) { $started[] = $p; }
		else                                    { $bench[] = $p; }
	}
	return array( $started, $on, $bench );
}

function ew_match_name( $p ) {
	$pl = $p['player'] ?? array();
	return trim( ( $pl['first-name'] ?? '' ) . ' ' . ( $pl['last-name'] ?? '' ) );
}

function ew_match_player_row( $p, $goals ) {
	$name = ew_match_name( $p );
	$mine = array();
	foreach ( $goals as $g ) {
		if ( ew_match_name( $g ) === $name && isset( $g['minute'] ) ) {
			$mine[] = (int) $g['minute'] . "'";
		}
	}
	ob_start();
	?>
<li class="ewm-player">
	<span class="ewm-shirt"><?php echo esc_html( $p['shirt'] ?? '' ); ?></span>
	<span class="ewm-pname"><?php echo esc_html( $name ); ?><?php
		if ( ! empty( $p['captain'] ) ) : ?><b class="ewm-capt">C</b><?php endif; ?></span>
	<span class="ewm-marks">
		<?php if ( $mine ) : ?><i class="ewm-goal" title="Goal"><?php
			echo esc_html( implode( ' ', $mine ) ); ?></i><?php endif; ?>
		<?php if ( isset( $p['sent-off'] ) ) : ?><i class="ewm-red" title="Sent off"><?php
			echo esc_html( (int) $p['sent-off']['minute'] ); ?>'</i><?php endif; ?>
		<?php if ( isset( $p['substitution'] ) ) : ?><i class="ewm-sub" title="Came on for <?php
			echo esc_attr( ew_match_name( $p['substitution']['replaced'] ?? array() ) ); ?>">&#9650; <?php
			echo esc_html( (int) $p['substitution']['minute'] ); ?>'</i><?php endif; ?>
	</span>
</li>
	<?php
	return ob_get_clean();
}

function ew_match_side_column( $side, $label ) {
	list( $started, $on, $bench ) = ew_match_lineup( $side );
	$goals = (array) ( $side['goals'] ?? array() );

	ob_start();
	?>
<div class="ewm-col">
	<h3 class="ewm-colHead">
		<img src="<?php echo esc_url( ew_home_badge( $side['id'] ?? 0 ) ); ?>" alt=""
			onerror="this.style.visibility='hidden'">
		<?php echo esc_html( $side['name'] ?? '' ); ?>
		<span><?php echo esc_html( $label ); ?></span>
	</h3>

	<?php if ( $started ) : ?>
	<ul class="ewm-list">
		<?php foreach ( $started as $p ) { echo ew_match_player_row( $p, $goals ); } // phpcs:ignore ?>
	</ul>
	<?php endif; ?>

	<?php if ( $on ) : ?>
	<p class="ewm-sub-head">Substitutes used</p>
	<ul class="ewm-list">
		<?php foreach ( $on as $p ) { echo ew_match_player_row( $p, $goals ); } // phpcs:ignore ?>
	</ul>
	<?php endif; ?>

	<?php if ( $bench ) : ?>
	<p class="ewm-sub-head">Unused</p>
	<ul class="ewm-list ewm-dim">
		<?php foreach ( $bench as $p ) { echo ew_match_player_row( $p, $goals ); } // phpcs:ignore ?>
	</ul>
	<?php endif; ?>
</div>
	<?php
	return ob_get_clean();
}

function ew_match_markup( $m ) {
	$home = (array) ( $m['home-team'] ?? array() );
	$away = (array) ( $m['away-team'] ?? array() );
	$done = ! empty( $m['status']['short'] ) && 'FT' === $m['status']['short'];
	$ts   = strtotime( $m['date'] ?? '' );

	ob_start();
	?>
<div class="ewm">
	<section class="ewm-head">
		<div class="ewm-wrap">
			<span class="ewm-comp"><?php echo esc_html( $m['competition']['name'] ?? '' ); ?></span>
			<div class="ewm-score">
				<span class="ewm-team">
					<img src="<?php echo esc_url( ew_home_badge( $home['id'] ?? 0 ) ); ?>" alt=""
						onerror="this.style.visibility='hidden'">
					<b><?php echo esc_html( $home['name'] ?? '' ); ?></b>
				</span>
				<span class="ewm-nums">
					<?php if ( $done ) : ?>
					<?php echo esc_html( $home['score'] ?? '' ); ?><i>&ndash;</i><?php echo esc_html( $away['score'] ?? '' ); ?>
					<?php else : ?>
					<?php echo esc_html( ! empty( $m['time'] ) ? substr( $m['time'], 0, 5 ) : 'v' ); ?>
					<?php endif; ?>
				</span>
				<span class="ewm-team ewm-right">
					<img src="<?php echo esc_url( ew_home_badge( $away['id'] ?? 0 ) ); ?>" alt=""
						onerror="this.style.visibility='hidden'">
					<b><?php echo esc_html( $away['name'] ?? '' ); ?></b>
				</span>
			</div>
			<p class="ewm-meta">
				<?php echo esc_html( $ts ? date_i18n( 'l j F Y', $ts ) : '' ); ?>
				<?php if ( ! empty( $m['venue'] ) ) : ?>· <?php echo esc_html( $m['venue'] ); ?><?php endif; ?>
				<?php if ( $done && isset( $home['half-time-score'] ) ) : ?>
				· HT <?php echo esc_html( (int) $home['half-time-score'] ); ?>&ndash;<?php
					echo esc_html( (int) ( $away['half-time-score'] ?? 0 ) ); ?>
				<?php endif; ?>
				<?php if ( ! empty( $m['attendance'] ) ) : ?>· Att <?php
					echo esc_html( number_format_i18n( $m['attendance'] ) ); ?><?php endif; ?>
				<?php if ( ! empty( $m['referee'] ) ) : ?>· Referee <?php
					echo esc_html( $m['referee'] ); ?><?php endif; ?>
			</p>
		</div>
	</section>

	<?php
	$goals = array_merge(
		array_map( function ( $g ) { return array( 'g' => $g, 'side' => 'home' ); }, (array) ( $home['goals'] ?? array() ) ),
		array_map( function ( $g ) { return array( 'g' => $g, 'side' => 'away' ); }, (array) ( $away['goals'] ?? array() ) )
	);
	usort( $goals, function ( $a, $b ) { return (int) ( $a['g']['minute'] ?? 0 ) - (int) ( $b['g']['minute'] ?? 0 ); } );
	if ( $goals ) :
	?>
	<section class="ewm-goals">
		<div class="ewm-wrap">
			<h2 class="ewm-h2">Goals</h2>
			<ol class="ewm-goalList">
				<?php foreach ( $goals as $row ) : ?>
				<li class="ewm-goalRow is-<?php echo esc_attr( $row['side'] ); ?>">
					<span class="ewm-min"><?php echo esc_html( (int) ( $row['g']['minute'] ?? 0 ) ); ?>'</span>
					<span class="ewm-scorer"><?php echo esc_html( ew_match_name( $row['g'] ) ); ?></span>
					<span class="ewm-for"><?php echo esc_html(
						'home' === $row['side'] ? ( $home['name'] ?? '' ) : ( $away['name'] ?? '' ) ); ?></span>
				</li>
				<?php endforeach; ?>
			</ol>
		</div>
	</section>
	<?php endif; ?>

	<?php if ( ! empty( $home['line-up'] ) || ! empty( $away['line-up'] ) ) : ?>
	<section class="ewm-teams">
		<div class="ewm-wrap">
			<h2 class="ewm-h2">Line-ups</h2>
			<div class="ewm-cols">
				<?php
				echo ew_match_side_column( $home, 'Home' ); // phpcs:ignore
				echo ew_match_side_column( $away, 'Away' ); // phpcs:ignore
				?>
			</div>
			<p class="ewm-key">
				<i class="ewm-goal">40'</i> goal
				<i class="ewm-sub">&#9650;</i> came on
				<i class="ewm-red">1'</i> sent off
				<b class="ewm-capt">C</b> captain
			</p>
		</div>
	</section>
	<?php endif; ?>

</div>
	<?php
	return ob_get_clean();
}

function ew_match_css() {
	return '
.ewm{font-family:"Instrument Sans",system-ui,sans-serif;color:#111}
.ewm *{box-sizing:border-box}
.ewm-wrap{max-width:900px;margin:0 auto;padding:0 16px}
.ewm-head{background:#111;color:#fff;padding:30px 0 26px;margin:0 0 34px}
.ewm-comp{display:block;text-align:center;font-family:Anton,"Instrument Sans",sans-serif;font-size:11px;
 letter-spacing:.12em;text-transform:uppercase;color:#CC0000;margin:0 0 18px}
.ewm-score{display:grid;grid-template-columns:1fr auto 1fr;gap:18px;align-items:center}
.ewm-team{display:flex;flex-direction:column;align-items:center;gap:10px;text-align:center}
.ewm-team img{width:64px;height:64px;object-fit:contain}
.ewm-team b{font-size:17px;font-weight:600;color:#fff}
.ewm-nums{font-family:Anton,"Instrument Sans",sans-serif;font-size:46px;line-height:1;color:#fff;white-space:nowrap}
.ewm-nums i{font-style:normal;color:#5d5d66;padding:0 8px}
.ewm-meta{text-align:center;margin:20px 0 0;font-size:13px;color:#9a9aa3}
.ewm-h2{font-family:Anton,"Instrument Sans",sans-serif;font-size:19px;letter-spacing:.06em;
 text-transform:uppercase;margin:0 0 16px;color:#111}
.ewm-goals{margin:0 0 38px}
.ewm-goalList{list-style:none;margin:0;padding:0;border:1px solid #e6e6e6;border-radius:5px;background:#fff}
.ewm-goalRow{display:grid;grid-template-columns:56px 1fr auto;gap:14px;align-items:center;
 padding:11px 16px;border-top:1px solid #efefef;font-size:15px}
.ewm-goalRow:first-child{border-top:0}
.ewm-min{font-family:Anton,sans-serif;font-size:15px;color:#6b6b6b}
.ewm-scorer{font-weight:600}
.ewm-for{font-size:12px;color:#8a8a8a}
.ewm-goalRow.is-home .ewm-min{color:#CC0000}
.ewm-teams{margin:0 0 30px}
.ewm-cols{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.ewm-col{background:#fff;border:1px solid #e6e6e6;border-radius:5px;padding:16px 18px}
.ewm-colHead{display:flex;align-items:center;gap:10px;margin:0 0 14px;padding:0 0 12px;
 border-bottom:1px solid #efefef;font-size:15px;font-weight:700}
.ewm-colHead img{width:26px;height:26px;object-fit:contain}
.ewm-colHead span{margin-left:auto;font-size:10px;font-weight:400;letter-spacing:.08em;
 text-transform:uppercase;color:#9a9aa3}
.ewm-list{list-style:none;margin:0 0 6px;padding:0}
.ewm-player{display:grid;grid-template-columns:26px 1fr auto;gap:10px;align-items:baseline;padding:5px 0;font-size:14px}
.ewm-shirt{font-family:Anton,sans-serif;font-size:13px;color:#9a9aa3;text-align:right}
.ewm-pname{font-weight:500}
.ewm-dim .ewm-pname{color:#8a8a8a;font-weight:400}
.ewm-marks{display:flex;gap:6px;white-space:nowrap}
.ewm-capt{display:inline-block;margin-left:6px;font-size:9px;font-weight:700;letter-spacing:.06em;
 color:#6b6b6b;border:1px solid #c9c9c9;border-radius:3px;padding:0 3px;vertical-align:1px}
.ewm-goal,.ewm-sub,.ewm-red{font-style:normal;font-size:11px;border-radius:3px;padding:1px 5px}
.ewm-goal{background:#CC0000;color:#fff;font-weight:700}
.ewm-sub{background:#f1f1f1;color:#6b6b6b}
.ewm-red{background:#111;color:#fff;font-weight:700}
.ewm-sub-head{margin:14px 0 6px;font-size:10px;letter-spacing:.09em;text-transform:uppercase;color:#9a9aa3}
.ewm-key{margin:16px 2px 0;font-size:12px;color:#8a8a8a;display:flex;gap:16px;flex-wrap:wrap;align-items:center}
.ewm-key i,.ewm-key b{margin-right:5px}
.ewm-back{margin:0 0 6px}
.ewm-back a{color:#CC0000;font-weight:600;text-decoration:none}
.ewm-back a:hover{text-decoration:underline}
.ewm-credit{margin:0 0 56px;font-size:12px;color:#8a8a8a}
@media(max-width:720px){
 .ewm-cols{grid-template-columns:1fr}
 .ewm-nums{font-size:34px}
 .ewm-team img{width:48px;height:48px}
 .ewm-team b{font-size:14px}
 .ewm-goalRow{grid-template-columns:44px 1fr;gap:10px}
 .ewm-for{grid-column:2;font-size:11px}
}';
}

add_action( 'template_redirect', function () {
	$id = (int) get_query_var( 'ew_match' );
	if ( ! $id || is_admin() ) {
		return;
	}

	$m = ew_match_data( $id );
	if ( empty( $m ) ) {
		// Falling through here lands on the home query, which renders the front
		// page with a 200. Make it a real 404.
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
		get_header();
		echo '<div class="ewm"><div class="ewm-wrap"><p class="ewm-back" style="margin:60px 0">'
			. 'We have no record of that match. '
			. '<a href="' . esc_url( home_url( '/matches/' ) ) . '">All fixtures and results</a>'
			. '</p></div></div>';
		get_footer();
		exit;
	}

	$title = trim( ( $m['home-team']['name'] ?? '' ) . ' v ' . ( $m['away-team']['name'] ?? '' ) );

	add_filter( 'pre_get_document_title', function () use ( $title ) {
		return $title . ' — ' . get_bloginfo( 'name' );
	} );
	add_action( 'wp_head', function () {
		echo '<style id="ew-match">' . ew_match_css() . '</style>';
	}, 20 );

	get_header();
	echo ew_match_markup( $m ); // phpcs:ignore WordPress.Security.EscapeOutput
	get_footer();
	exit;
}, 6 );

/* ------------------------------------------------------------------
 * The footer pages.
 *
 * Privacy Policy, Terms, Company Details, Contact us and Safeguarding
 * were dead links on every page of the site. Written here from what is
 * actually true of this club and this website rather than pasted from
 * a template: the privacy page describes what this site really does
 * (no accounts, no analytics beyond the host's own logs, YouTube
 * embeds on two pages), and the safeguarding page names the club's
 * own officer.
 *
 * The privacy and terms pages are written in plain English and are
 * honest about the site's behaviour, but they have not been read by a
 * solicitor, and the safeguarding page should be checked against the
 * county FA's current guidance. Both are flagged to Ben.
 * ------------------------------------------------------------------ */

function ew_contact_content() {
	return <<<'HTML'
<div class="ew-prose">

<p class="ew-lede">Eastwood Football Club, Coronation Park, Chewton Street, Eastwood, Nottinghamshire NG16 3HB.</p>

<div class="ew-cta">
	<h2>Get in touch</h2>
	<p><a href="mailto:info@eastwoodfc.com">info@eastwoodfc.com</a> &nbsp;·&nbsp; <a href="tel:+441773432414">01773 432414</a></p>
</div>

<h2>Who's who</h2>

<div class="ew-people">
	<div class="ew-person"><b>Ben Edwards</b><span>Chair and Club Owner</span></div>
	<div class="ew-person"><b>Stephen Kirkham</b><span>Managing Director</span></div>
	<div class="ew-person"><b>Zander Shayler</b><span>Director of Operations and Safeguarding Officer</span></div>
</div>

<p>Everything reaches us at <a href="mailto:info@eastwoodfc.com">info@eastwoodfc.com</a> — hospitality and
tickets, venue and pitch hire, sponsorship, junior and youth team enquiries, academy places, and anything about
working here.</p>

<h2>Finding us</h2>

<p>Coronation Park is on Chewton Street in Eastwood, Nottinghamshire, NG16 3HB. Parking on site is free unless
we say otherwise for a particular fixture.</p>

<p>The nearest station is Langley Mill, about a mile and a half away — roughly half an hour on foot, or there are
taxis outside the station and at the rank in the town centre.</p>

</div>
HTML;
}


function ew_safeguarding_content() {
	return <<<'HTML'
<div class="ew-prose">

<p class="ew-lede">Eastwood runs football for hundreds of children every week. Keeping them safe matters more
than any result, and everyone at the club shares that responsibility.</p>

<h2>If a child is at immediate risk</h2>

<p><b>Call 999.</b> Do not wait to report it to the club first. Tell us afterwards so we can act on our side.</p>

<h2>Raising a concern with the club</h2>

<p>Our Safeguarding Officer is <b>Zander Shayler</b>. Any concern about a child's welfare, about the
behaviour of an adult at the club, or about anything that does not feel right, should go to him.</p>

<div class="ew-cta">
	<h3>Contact the Safeguarding Officer</h3>
	<p>Mark it for the attention of the Safeguarding Officer.</p>
	<p><a href="mailto:info@eastwoodfc.com">info@eastwoodfc.com</a> &nbsp;·&nbsp; <a href="tel:+441773432414">01773 432414</a></p>
</div>

<p>You do not need to be certain, and you do not need proof. If something is worrying you, tell us. It is our job
to look into it, not yours to be sure first.</p>

<h2>Outside the club</h2>

<p>You can always go outside the club, and sometimes you should — particularly if your concern is about how the
club itself has handled something.</p>

<ul>
	<li><b>NSPCC Helpline</b> — 0808 800 5000, for adults worried about a child.</li>
	<li><b>Childline</b> — 0800 1111, free and confidential, for anyone under 19.</li>
	<li><b>Nottinghamshire FA</b> — the county FA's Designated Safeguarding Officer handles concerns about
		clubs, coaches and officials in the county.</li>
	<li><b>The Football Association</b> — the FA's safeguarding team oversees safeguarding across the game.</li>
</ul>

<h2>What we expect of everyone here</h2>

<p>Coaches, volunteers and staff working with children at Eastwood hold an in-date FA DBS check and are expected
to follow the FA's Respect Code of Conduct. That applies to parents and spectators on the touchline as much as it
does to the people in the dugout.</p>

<p>Photography and filming at the ground: the club films matches for highlights and for its documentary series.
If you would prefer your child not to appear, tell us and we will work around it.</p>

</div>
HTML;
}

function ew_privacy_content() {
	return <<<'HTML'
<div class="ew-prose">

<p class="ew-lede">This page explains what this website does with information about you. It is deliberately
specific to this site rather than a general template.</p>

<h2>What this site collects</h2>

<p>You can read every page of this website without giving us anything. There are no accounts, no sign-up, no
newsletter form and no shop checkout on this site.</p>

<p>Like any website, our host records standard server logs — the address you connected from, the page you asked
for, the time, and what browser you used. These are used to keep the site running and secure.</p>

<h2>Things on this site that come from elsewhere</h2>

<ul>
	<li><b>Video.</b> The Eastwood TV page and the front page embed video from YouTube. We use YouTube's
		privacy-enhanced embed, which does not set advertising cookies until you press play. Once you play a
		video, YouTube's own terms and privacy policy apply.</li>
	<li><b>Fixtures, results, tables and squad data</b> come from Football Web Pages. Your browser does not
		contact them directly — this site fetches the data and serves it to you.</li>
	<li><b>The club shop</b> is run by a separate supplier on their own website. Once you follow that link you
		are on their site, under their terms.</li>
	<li><b>Sponsor links</b> take you to sponsors' own websites, which we do not control.</li>
</ul>

<h2>What we do not do</h2>

<p>We do not sell or share your information. We do not run advertising trackers on this site. We do not build a
profile of you.</p>

<h2>If you contact us</h2>

<p>When you email or call us, we keep what you send for as long as we need it to deal with your enquiry and to
keep a record of club business. We do not add you to a mailing list because you asked a question.</p>

<h2>Your rights</h2>

<p>Under UK data protection law you can ask us what information we hold about you, ask us to correct it, or ask
us to delete it. Write to <a href="mailto:info@eastwoodfc.com">info@eastwoodfc.com</a> and we will respond
within a month. If you are not satisfied with how we have handled it, you can complain to the Information
Commissioner's Office at ico.org.uk.</p>

<p>Eastwood Community Football Club CIC, company number 09196902, Coronation Park, Chewton Street, Eastwood,
Nottinghamshire NG16 3HB, is the data controller.</p>

</div>
HTML;
}

function ew_terms_content() {
	return <<<'HTML'
<div class="ew-prose">

<p class="ew-lede">The short version: this is the club's own website, we try to keep it accurate, and some of what
is on it comes from other people.</p>

<h2>Using this site</h2>

<p>You are welcome to read, link to and share anything here. The words, photographs, crest and club marks belong
to Eastwood Community Football Club CIC or to the photographers who took them, and should not be reused
commercially without asking us first.</p>

<h2>Accuracy</h2>

<p>We keep this site as accurate as we can, but things change. Kick-off times move, fixtures are rearranged and
prices are reviewed. Nothing on this site is a guarantee, and for anything that matters — travelling to a game,
booking the pitch, turning up for a trial — check with us first on 01773 432414 or
<a href="mailto:info@eastwoodfc.com">info@eastwoodfc.com</a>.</p>

<h2>Match data</h2>

<p>Fixtures, results, league tables, appearances and goalscorers are supplied by Football Web Pages and are
updated as the league records them. Where their records and ours differ, the league's own record is the one that
counts.</p>

<h2>Other people's sites</h2>

<p>This site links out to the club shop, to our sponsors, to YouTube and to Pitchero, where some of our junior
sections still keep their fixtures. We are not responsible for what is on those sites or what they do with your
information.</p>

<h2>Tickets, hire and hospitality</h2>

<p>Prices shown here are for information. Bookings for pitch hire, the function room and match day hospitality
are confirmed by us directly, and the terms we send with your booking are the ones that apply.</p>

<h2>Getting in touch</h2>

<p>If something on this site is wrong, tell us and we will fix it:
<a href="mailto:info@eastwoodfc.com">info@eastwoodfc.com</a>.</p>

</div>
HTML;
}

/* ------------------------------------------------------------------
 * Wiring up the last dead links, and the content from the club's own
 * brochures.
 *
 * Thirteen anchors in the header and footer wrapped a sponsor's logo
 * and went nowhere. The logos were localised weeks ago; nobody had
 * given them a destination. Sponsors pay for that click.
 *
 * The five footer links get their pages, and Club History — which had
 * no home in the navigation — is added beside them.
 * ------------------------------------------------------------------ */

/**
 * A sponsor's click-through, found from the local logo filename.
 * The header sponsor strip and the footer wall both use these.
 */
function ew_sponsor_link_for_markup( $html ) {
	if ( ! preg_match( '#/ew-sponsors/([a-z0-9\-]+)\.png#', $html, $m ) ) {
		return '';
	}
	$slug   = ew_sponsor_slug_of( $m[1] );
	$roster = ew_sponsor_roster();
	return isset( $roster[ $slug ][2] ) ? (string) $roster[ $slug ][2] : '';
}

/**
 * Club History has no label of its own in the captured footer, so it
 * is added next to Contact us rather than left unreachable.
 */
function ew_footer_add_history( $html ) {
	$needle = 'Contact us</a>';
	$pos    = strpos( $html, $needle );
	if ( false === $pos ) {
		return $html;
	}

	// Reuse the surrounding anchor's own classes so it matches its neighbours.
	$open = strrpos( substr( $html, 0, $pos ), '<a ' );
	if ( false === $open ) {
		return $html;
	}
	$anchor = substr( $html, $open, ( $pos + strlen( $needle ) ) - $open );
	$extra  = str_replace( 'Contact us</a>', 'Club History</a>', $anchor );
	$extra  = preg_replace( '#\shref="[^"]*"#', ' href="' . esc_url( home_url( '/club-history/' ) ) . '"', $extra, 1 );
	// The title came across with the rest of the anchor, so the new link was
	// announcing itself as Contact us to anyone hovering or using a reader.
	$extra  = preg_replace( '#\stitle="[^"]*"#', ' title="Club History"', $extra, 1 );
	$extra  = preg_replace( '#\sonclick="return false"#', '', $extra );

	return substr_replace( $html, $anchor . $extra, $open, strlen( $anchor ) );
}

function ew_sponsorship_content() {
	return <<<'HTML'
<div class="ew-prose">

<p class="ew-lede">Coronation Park is open twelve hours a day, seven days a week, with an average footfall of
3,500 to 4,000 people every week. If you want your name in front of that, talk to us.</p>

[eastwood_gate]

<p>Those gate figures are the first team's home league crowd. The wider number above is the ground itself —
junior and youth sides, the academy, the 3G, the bar and the function room. Most of the people who come through
Coronation Park in a given week are not here for a first team match.</p>

<h2>What we offer</h2>

<p><b>Matchday sponsorship.</b> Be the exclusive sponsor of a selected fixture, with your branding around the
stadium, in the clubhouse and on promotional material, recognition in match announcements, and ten premium seats
with gold package hospitality in The Venue.</p>

<p><b>Tournament sponsorship.</b> Our annual tournaments run across multiple days, with your logo on banners,
trophies and promotional material.</p>

<p><b>Website and social media.</b> Your logo on this site and across the club's channels — including a YouTube
channel that publishes every goal, every week, and a documentary series following the first team through the
season.</p>

<p><b>Community events.</b> Fundraisers, coaching clinics and awards evenings, where the audience is the town
rather than the terrace.</p>

<p>Programme advertising, player tunnel branding and fan experiences come up too. Packages are built around what
you are trying to achieve and what you have to spend, which is why there is no price list on this page.</p>

<h2>Why here</h2>

<p>Eastwood is a community club, and sponsoring one puts your name alongside something the town actually cares
about rather than buying an impression. You get visibility on kit, signage, this site and our channels; access to
players, families and supporters who turn up week after week; and a room full of other local businesses at our
events.</p>

<h2>Who already backs us</h2>

[eastwood_sponsors]

<div class="ew-cta">
	<h3>Start a conversation</h3>
	<p>Tell us about your business and what you want out of it, and we will come back with something specific
		rather than a tier.</p>
	<p><a href="mailto:info@eastwoodfc.com">info@eastwoodfc.com</a> &nbsp;·&nbsp; <a href="tel:+441773432414">01773 432414</a></p>
</div>

</div>
HTML;
}

function ew_venue_content() {
	return <<<'HTML'
<div class="ew-prose">

<p class="ew-lede">TheVenue@Eastwood is the club's function room at Coronation Park — a licensed bar, a dance
floor, a large dining area and a car park, available for hire whatever the occasion.</p>

<h2>What's here</h2>

<ul>
	<li>Dance floor</li>
	<li>Licensed bar</li>
	<li>Large dining area</li>
	<li>Large car park</li>
	<li>Flexible buffets — anything from chip cobs to a three-course meal, built around what you want</li>
	<li>Friendly service</li>
</ul>

<h2>What people hold here</h2>

<p>Birthdays, christenings, engagement parties, baby showers, wedding receptions, wakes and funerals, retirement
parties, charity events, live entertainment, school reunions, football parties, exercise classes, meetings and
conferences.</p>

<h2>Weddings</h2>

<p>We host wedding receptions, and we have a brochure that covers what a wedding here looks like — the room, the
food, and how we work with you on the day. Ask us for a copy and we will send it over.</p>

<h2>The Pitchside Bar</h2>

<p>Separately from the main room, the Pitchside Bar suits smaller bookings — lectures, meetings, conferences, and
gatherings that don't need a dance floor.</p>

<h2>Also available</h2>

<p>The floodlit all-weather 3G pitch can be hired alongside the Pitchside Bar and changing rooms, which is what
makes this work for a football party or a company tournament as easily as a birthday.</p>

<div class="ew-cta">
	<h3>Talk to us about your event</h3>
	<p>Tell us what you have in mind and we will work out whether we can do it properly.</p>
	<p><a href="mailto:info@eastwoodfc.com">info@eastwoodfc.com</a> &nbsp;·&nbsp; <a href="tel:+441773432414">01773 432414</a></p>
</div>

</div>
HTML;
}

function ew_academy_content() {
	return <<<'HTML'
<div class="ew-prose">

<p class="ew-lede">Eastwood runs a full-time football academy at Coronation Park: an elite programme for UK and international students that combines intensive football development with a full-time education.</p>

<p>Players train and are coached as full-time footballers while studying for a BTEC Level 3 qualification alongside it. The aim is straightforward — to give young players a serious environment to develop in, and a qualification behind them whatever happens next.</p>

<p>The Academy sits inside the senior side of the club, not the junior section. Academy players train at Coronation Park, the same ground the first team plays at, and the club has its own full-time coaching and teaching staff rather than borrowing them from elsewhere.</p>

<h2>Coaching and teaching staff</h2>

<div class="ew-people">
	<div class="ew-person"><b>Lewis McGugan</b><span>Head Coach</span></div>
	<div class="ew-person"><b>Owen Betts</b><span>Coach</span></div>
	<div class="ew-person"><b>Dave Plant</b><span>Coach</span></div>
</div>

<h2>Trials</h2>

<p>The Academy recruits through open trials at Coronation Park, most recently in July. Trial dates for the next intake are announced on the club's channels and here — if you would like to be told when the next one is set, call the club and ask to be added to the list.</p>

<h2>Finding out more</h2>

<p>We have a full Academy brochure covering the programme, the education side and what a week looks like. Ask us and we will send it to you.</p>

<p>For entry requirements, fees, term dates and anything else, speak to the club directly. We would rather answer your question properly than publish a page of generalities.</p>

<div class="ew-cta">
	<h3>Enquire about the Academy</h3>
	<p>Eastwood Football Club, Coronation Park, Chewton Street, Eastwood, Nottinghamshire NG16 3HB</p>
	<p><a href="mailto:info@eastwoodfc.com">info@eastwoodfc.com</a> &nbsp;·&nbsp; <a href="tel:+441773432414">01773 432414</a></p>
</div>

</div>
HTML;
}

/* ------------------------------------------------------------------
 * A page for every team, on this site.
 *
 * The teams index used to send twenty-eight of the twenty-nine sides
 * out to Pitchero. That was my call and it was wrong: the whole point
 * of this build is that the club's website is the club's website.
 * Every team now has a page here at /teams/<slug>/ and nothing links
 * out to Pitchero.
 *
 * What is honestly on those pages depends on the side:
 *
 *  - The first team has the full live squad, appearances and goals,
 *    because Football Web Pages covers the United Counties League.
 *  - The Academy has its own written page.
 *  - The other twenty-seven sides play in leagues that feed is not
 *    licensed for, so there is no fixture or squad data available to
 *    this site at all. Their pages carry what is true — who they are,
 *    where they sit in the club, and how to reach the people who run
 *    them — and say plainly that fixtures come through the club.
 *
 * Writing anything more than that would mean inventing it. The route
 * to richer junior pages is a real data source (FA Full-Time, which
 * runs these leagues) and that is a build of its own.
 * ------------------------------------------------------------------ */

const EW_TEAMS_SLUG  = 'teams';
const EW_TEAMS_RULES = '1';

add_action( 'init', function () {
	add_rewrite_rule( '^' . EW_TEAMS_SLUG . '/([a-z0-9\-]+)/?$', 'index.php?ew_team=$matches[1]', 'top' );
	if ( get_option( 'ew_teams_rules' ) !== EW_TEAMS_RULES ) {
		flush_rewrite_rules( false );
		update_option( 'ew_teams_rules', EW_TEAMS_RULES );
	}
}, 22 );

add_filter( 'query_vars', function ( $vars ) {
	$vars[] = 'ew_team';
	return $vars;
} );

function ew_team_slug( $name ) {
	return sanitize_title( $name );
}

/** Find a team in the roster by its slug. Returns array(group, team) or null. */
function ew_team_find( $slug ) {
	foreach ( ew_teams_roster() as $group => $teams ) {
		foreach ( $teams as $team ) {
			if ( ew_team_slug( $team['name'] ) === $slug ) {
				return array( $group, $team );
			}
		}
	}
	return null;
}

/**
 * What each section of the club is, in the club's own terms. Used on
 * every team page so a parent landing cold knows where they are.
 */
function ew_team_group_blurb( $group ) {
	switch ( $group ) {
		case 'Senior Men':
			return 'Our senior sides play out of Coronation Park, from the first team down through the '
				. 'academy and development squads.';
		case 'Junior':
			return 'Our junior section is the biggest part of the club — twenty-two sides from under-7s '
				. 'to under-18s, playing across Saturday and Sunday leagues.';
		case 'Mini':
			return 'Our youngest players, learning the game at Coronation Park.';
		case 'Ladies and Girls':
			return 'Girls\' and ladies\' football at Eastwood, and a section we are actively growing.';
	}
	return '';
}

function ew_team_markup( $group, $team ) {
	$name     = $team['name'];
	$is_first = '1st Team' === $name;
	$is_acad  = 'Academy' === $name;

	ob_start();
	?>
<div class="ew-prose ew-team">

	<p class="ew-teamCrumb"><a href="<?php echo esc_url( home_url( '/teams/' ) ); ?>">Teams</a>
		<span>/</span> <?php echo esc_html( $group ); ?></p>

	<h1 class="ew-teamTitle"><?php echo esc_html( $name ); ?></h1>

	<p class="ew-lede"><?php echo esc_html( ew_team_group_blurb( $group ) ); ?></p>

	<?php if ( $is_first ) : ?>

	<p>The first team plays in the United Counties League Premier Division North. Every appearance, goal and
		card is recorded as the league publishes it.</p>

	<div class="ew-teamLinks">
		<a href="<?php echo esc_url( home_url( '/squad/' ) ); ?>">Squad and season stats</a>
		<a href="<?php echo esc_url( home_url( '/matches/' ) ); ?>">Fixtures, results and table</a>
		<a href="<?php echo esc_url( home_url( '/tickets/' ) ); ?>">Matchday and tickets</a>
	</div>

	<?php elseif ( $is_acad ) : ?>

	<p>The Academy is a full-time elite programme combining intensive football development with a BTEC Level 3
		education, based at Coronation Park.</p>

	<div class="ew-teamLinks">
		<a href="<?php echo esc_url( home_url( '/academy/' ) ); ?>">About the Academy</a>
	</div>

	<?php else : ?>

	<h2>Fixtures and results</h2>

	<p><?php echo esc_html( $name ); ?> plays in a league our results feed does not cover, so fixtures and
		results for this side are not published on this page. Team managers circulate them directly, and they
		also appear on the club's Facebook and Instagram.</p>

	<p>If you would like fixtures for this side and are not getting them, let us know and we will sort it.</p>

	<?php endif; ?>

	<h2>Getting involved</h2>

	<?php if ( 'Junior' === $group || 'Mini' === $group || 'Ladies and Girls' === $group ) : ?>
	<p>New players are welcome across the junior, mini and girls' sections. Tell us your child's age and which
		day suits and we will point you at the right side.</p>
	<?php else : ?>
	<p>For anything about this side — playing, coaching or volunteering — get in touch with the club.</p>
	<?php endif; ?>

	<div class="ew-cta">
		<h3>Contact the club</h3>
		<p>Eastwood Football Club, Coronation Park, Chewton Street, Eastwood, Nottinghamshire NG16 3HB</p>
		<p><a href="mailto:info@eastwoodfc.com">info@eastwoodfc.com</a> &nbsp;·&nbsp;
			<a href="tel:+441773432414">01773 432414</a></p>
	</div>

	<?php
	// The rest of the section, so somebody landing here can move sideways.
	$siblings = array();
	foreach ( ew_teams_roster() as $g => $teams ) {
		if ( $g !== $group ) { continue; }
		foreach ( $teams as $t ) {
			if ( $t['name'] !== $name ) { $siblings[] = $t['name']; }
		}
	}
	if ( $siblings ) :
	?>
	<h2>Also in <?php echo esc_html( $group ); ?></h2>
	<div class="ew-teamSibs">
		<?php foreach ( $siblings as $sib ) : ?>
		<a href="<?php echo esc_url( home_url( '/teams/' . ew_team_slug( $sib ) . '/' ) ); ?>"><?php
			echo esc_html( $sib ); ?></a>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>

</div>
	<?php
	return ob_get_clean();
}

function ew_team_css() {
	return '
.ew-team .ew-teamCrumb{font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#8a8a8a;margin:0 0 10px}
.ew-team .ew-teamCrumb a{color:#CC0000;text-decoration:none;font-weight:600}
.ew-team .ew-teamCrumb a:hover{text-decoration:underline}
.ew-team .ew-teamCrumb span{padding:0 4px;color:#c9c9c9}
.ew-teamTitle{font-family:Anton,"Instrument Sans",sans-serif;font-size:40px;line-height:1.05;
 letter-spacing:.01em;margin:0 0 16px;color:#111}
.ew-teamLinks{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:8px;margin:0 0 24px}
.ew-teamLinks a{display:block;background:#fff;border:1px solid #e6e6e6;border-left:3px solid #CC0000;
 border-radius:4px;padding:14px 16px;text-decoration:none;color:#111;font-weight:600;font-size:15px;
 transition:border-color .15s,box-shadow .15s}
.ew-teamLinks a:hover{border-color:#CC0000;box-shadow:0 2px 10px rgba(0,0,0,.07)}
.ew-teamSibs{display:flex;flex-wrap:wrap;gap:7px;margin:0 0 20px}
.ew-teamSibs a{display:inline-block;background:#f3f3f3;border-radius:3px;padding:6px 11px;font-size:13px;
 text-decoration:none;color:#444;transition:background .15s,color .15s}
.ew-teamSibs a:hover{background:#CC0000;color:#fff}
@media(max-width:720px){ .ew-teamTitle{font-size:30px} }';
}

add_action( 'template_redirect', function () {
	$slug = (string) get_query_var( 'ew_team' );
	if ( '' === $slug || is_admin() ) {
		return;
	}

	$found = ew_team_find( $slug );

	add_action( 'wp_head', function () {
		echo '<style id="ew-team">' . ew_prose_css_inline() . ew_team_css() . '</style>';
	}, 20 );

	if ( ! $found ) {
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
		get_header();
		echo '<div class="ew-prose"><p style="margin:60px 0">We have no team by that name. '
			. '<a href="' . esc_url( home_url( '/teams/' ) ) . '">All teams</a></p></div>';
		get_footer();
		exit;
	}

	list( $group, $team ) = $found;

	add_filter( 'pre_get_document_title', function () use ( $team ) {
		return $team['name'] . ' — ' . get_bloginfo( 'name' );
	} );

	get_header();
	echo ew_team_markup( $group, $team ); // phpcs:ignore WordPress.Security.EscapeOutput
	get_footer();
	exit;
}, 7 );


/* ------------------------------------------------------------------
 * The page that says you are lost.
 *
 * The captured 404 renders the header, a news story and the footer, and
 * says "Page not found" only in the browser tab. Nothing on the page tells
 * anyone they have the wrong address, so a mistyped URL reads as a broken
 * site. That is not a hypothetical: it happened twice in one afternoon,
 * both times reported as the homepage being broken.
 *
 * This replaces it with the same red band every other page opens with, a
 * plain sentence, and the places somebody was most likely heading for.
 * ------------------------------------------------------------------ */

function ew_404_destinations() {
	return array(
		'Home'     => '/',
		'Matches'  => '/matches/',
		'Teams'    => '/teams/',
		'News'     => '/news/',
		'Tickets'  => '/tickets/',
		'Matchday' => '/matchday/',
		'Shop'     => '/shop/',
		'Contact'  => '/contact/',
	);
}

function ew_404_css() {
	return '
.ew-404 h1{background:#CC0000;color:#fff;font-family:Anton,"Instrument Sans",sans-serif;
 font-size:56px;font-weight:700;line-height:1;letter-spacing:.01em;text-transform:uppercase;
 margin:0 calc(50% - 50vw) 0;padding:88px max(16px,calc(50vw - 624px)) 16px}
.ew-404 .ew-prose{padding-top:40px}
.ew-404-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(186px,1fr));
 gap:8px;margin:0 0 32px}
.ew-404-link{display:flex;align-items:center;min-height:62px;padding:0 20px;background:#fff;
 border:1px solid #e6e6e6;border-left:3px solid #CC0000;border-radius:4px;
 font-weight:600;font-size:16px;color:#111;text-decoration:none;
 transition:border-color .15s,box-shadow .15s}
.ew-404-link:hover{border-color:#CC0000;box-shadow:0 2px 10px rgba(0,0,0,.07)}
.ew-404-link:focus-visible{outline:2px solid #CC0000;outline-offset:3px}
@media(max-width:820px){
 .ew-404 h1{font-size:38px;padding:34px 16px 30px}
 .ew-404 .ew-prose{padding-top:28px}
}
';
}

function ew_404_markup() {
	ob_start();
	?>
<div class="ew-404">
	<h1>Page not found</h1>
	<div class="ew-prose">
		<p class="ew-lede">There is nothing at this address. It may have moved, or the link that
			brought you here may have a mistake in it.</p>
		<h2>Where you might have been going</h2>
		<div class="ew-404-grid">
			<?php foreach ( ew_404_destinations() as $label => $path ) : ?>
			<a class="ew-404-link" href="<?php echo esc_url( home_url( $path ) ); ?>"><?php
				echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</div>
		<p class="ew-credit">If a link on this site sent you here, tell us at
			<a href="mailto:info@eastwoodfc.com">info@eastwoodfc.com</a> and we will put it right.</p>
	</div>
</div>
	<?php
	return trim( ob_get_clean() );
}

add_action( 'template_redirect', function () {
	if ( is_admin() || is_feed() || is_embed() || ! is_404() ) {
		return;
	}

	status_header( 404 );
	nocache_headers();

	add_action( 'wp_head', function () {
		echo '<style id="ew-404-css">' . ew_prose_css_inline() . ew_404_css() . '</style>'; // phpcs:ignore
	}, 20 );

	get_header();
	echo ew_404_markup(); // phpcs:ignore WordPress.Security.EscapeOutput
	get_footer();
	exit;
}, 9 );
