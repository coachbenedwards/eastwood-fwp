<?php
/**
 * Plugin Name: Eastwood — club data
 * Description: Everything the Eastwood site needs from outside WordPress: the Football Web Pages proxy (live fixtures, results, league table and full match detail), the club-badge store, and the importer that pulls the club's news across from Pitchero.
 * Version: 2.10.0
 * Author: Eastwood CFC
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

	$teams = array();
	if ( '' !== $key ) {
		$t = wp_remote_get( 'https://api.footballwebpages.co.uk/v2/teams.json?comp=' . EW_COMP, array(
			'timeout' => 15,
			'headers' => array( 'FWP-API-Key' => $key ),
		) );
		if ( ! is_wp_error( $t ) ) {
			$j     = json_decode( wp_remote_retrieve_body( $t ), true );
			$teams = isset( $j['teams'] ) ? $j['teams'] : array();
		}
	}

	// The API carries no crests, so they are stored by Football Web Pages team id.
	if ( $teams && isset( $_POST['ew_fwp_import'] ) && check_admin_referer( 'ew_fwp_import' ) ) {
		wp_mkdir_p( $dir );
		$got = 0;
		foreach ( $teams as $tm ) {
			$slug = trim( preg_replace( '/-+/', '-', preg_replace( '/[^a-z0-9]+/', '-', strtolower( $tm['full-name'] ) ) ), '-' );
			$g    = wp_remote_get( 'https://www.footballwebpages.co.uk/graphics/teams/64/' . $slug . '.png', array( 'timeout' => 20 ) );
			if ( is_wp_error( $g ) || 200 !== (int) wp_remote_retrieve_response_code( $g ) ) {
				continue;
			}
			$body = wp_remote_retrieve_body( $g );
			if ( '' !== $body && false !== file_put_contents( $dir . $tm['id'] . '.png', $body ) ) {
				$got++;
			}
		}
		echo '<div class="notice notice-success"><p>Imported ' . (int) $got . ' badges.</p></div>';
	}

	echo '<h2>Club badges</h2>';
	echo '<p>Served from <code>wp-content/uploads/ew-badges/</code>, named after the club id. A club without one falls back to an initials disc.</p>';

	if ( $teams ) {
		echo '<form method="post" style="margin-bottom:16px">';
		wp_nonce_field( 'ew_fwp_import' );
		submit_button( 'Import badges from Football Web Pages', 'secondary', 'ew_fwp_import', false );
		echo '</form>';

		echo '<table class="widefat striped" style="max-width:640px"><thead><tr><th>Club</th><th>File name</th><th>Status</th></tr></thead><tbody>';
		foreach ( $teams as $tm ) {
			$file = $tm['id'] . '.png';
			$have = file_exists( $dir . $file );
			echo '<tr><td>' . esc_html( $tm['full-name'] ) . '</td><td><code>' . esc_html( $file ) . '</code></td><td>' .
				( $have ? '&#10003; in place' : '&mdash; missing' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p>The competition logo above each fixture uses <code>comp-' . esc_html( EW_COMP ) . '.png</code> in the same folder, and stays hidden until one is there.</p>';
	} else {
		echo '<p><em>Save the key above to list the clubs.</em></p>';
	}

	do_action( 'ew_fwp_after_badges' );

	echo '</div>';
}

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
		return new WP_REST_Response( json_decode( $hit, true ), 200 );
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
	return new WP_REST_Response( json_decode( $body, true ), 200 );
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
		'podcut'               => array( 'PodCut',                     'Shirt Sponsor', 'https://podcut.com',                                    $base . '1781094797_large.jpg' ),
		'podfirst'             => array( 'PodFirst',                   'Event Sponsor', 'https://podfirst.com',                                  $base . '1781096545_large.jpg' ),
		'sb-outdoor-living'    => array( 'SB Outdoor Living',          'Club Sponsor',  'https://www.instagram.com/sb_outdoorliving/',           $base . '1782811916_large.jpg' ),
		'kam-servicing'        => array( 'Kam Servicing',              'Stand Sponsor', 'https://kamservicing.com/',                             $base . '1719140087_large.jpg' ),
		'empire-scaffolding'   => array( 'Empire Scaffolding',         'Sponsor',       'https://empirescaffolding.co.uk/',                      $base . '1719140976_large.jpg' ),
		'evo-waste'            => array( 'EVO Waste Management',       'Sponsor',       'https://evowms.co.uk/',                                 $base . '1731070829_large.jpg' ),
		'short-stitch'         => array( 'Short Stitch',               'Sponsor',       'https://www.facebook.com/ShortStitchEmbroidery',        $base . '1719145984_large.jpg' ),
		'swift-sports-massage' => array( 'Swift Sports Massage',       'Sponsor',       'https://www.instagram.com/swiftsportsmassagetherapy/',  $base . '1719146646_large.jpg' ),
		'stadia-signs'         => array( 'Stadia Signs',               'Partner',       'https://stadiasigns.co.uk/',                            $base . '1719147209_large.jpg' ),
		'eastwood-cars'        => array( 'Eastwood Cars',              'Sponsor',       'https://eastwoodcars.co.uk/',                           $base . '1720123343_large.jpg' ),
		'evolve-scaffolding'   => array( 'Evolve Scaffolding',         'Partner',       '',                                                      $base . '1723676265_large.jpg' ),
	);
}

function ew_sponsor_dir() {
	$dir = WP_CONTENT_DIR . '/uploads/ew-sponsors/';
	wp_mkdir_p( $dir );
	return $dir;
}

function ew_sponsor_url( $slug ) {
	return content_url( '/uploads/ew-sponsors/' . $slug . '.png' );
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
	$meta = get_option( 'ew_sponsor_sources', array() );
	foreach ( $meta as $slug => $row ) {
		if ( empty( $row['url'] ) ) { continue; }
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
		. '<symbol id="ew-menu" viewBox="0 0 24 24"><path d="M4 7.2h16M4 12h16M4 16.8h16"/></symbol>'
		. '</defs></svg>';
}
add_action( 'wp_body_open', 'ew_icon_sprite', 1 );

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
		'c0cb65d0-c099-11f0-9384-3df27b8e225c.webp' => 'evo-waste',
		'71416870-7c3c-11f1-8494-d5247a06fcb1.webp' => 'short-stitch',
		'683e54b0-00fc-11f1-8913-b7f1aeca7b09.webp' => 'swift-sports-massage',
		'5d6eec60-cead-11f0-a647-af2eccb11ff2.webp' => 'stadia-signs',
		'433c47b0-a7b1-11f1-9cc4-0bf0a35b648c.webp' => 'eastwood-cars',
		'a6d66b80-9a4f-11f1-a3a5-d78e1898ef65.webp' => 'evolve-scaffolding',
	);
}

/**
 * The single pass over the finished page.
 */
function ew_rewrite_markup( $html ) {
	if ( '' === $html || false === stripos( $html, '<body' ) ) { return $html; }

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
			esc_url( ew_sponsor_url( $slug ) ),
			$html
		);
		$html = preg_replace(
			'#https://images\.gc\.nffcservices\.co\.uk/[^"\']*' . preg_quote( $file, '#' ) . '#',
			esc_url( ew_sponsor_url( $slug ) ),
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
			if ( empty( $roster[ $slug ][2] ) ) { return $m[0]; }
			$open = preg_replace( '#\shref="[^"]*"#', '', $open );
			$open = preg_replace( '#\sonclick="[^"]*"#', '', $open );
			return '<a href="' . esc_url( $roster[ $slug ][2] ) . '"'
				. ' title="' . esc_attr( $roster[ $slug ][1] . ' — ' . $roster[ $slug ][0] ) . '"'
				. $open . '>' . $body . '</a>';
		},
		$html
	);

	return ew_rewrite_nav( $html );
}

add_action( 'template_redirect', function () {
	if ( is_admin() || is_feed() || is_embed() ) { return; }
	ob_start( 'ew_rewrite_markup' );
}, 1 );

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

	ob_start();
	?>
<div class="ew-matches" data-team="<?php echo esc_attr( EW_TEAM ); ?>" data-open="<?php echo esc_attr( $open ); ?>">
	<div class="ew-tabs" role="tablist">
		<button type="button" role="tab" data-tab="fixtures">Fixtures</button>
		<button type="button" role="tab" data-tab="results">Results</button>
		<button type="button" role="tab" data-tab="table">Table</button>
	</div>
	<div class="ew-panel" data-panel="fixtures"><p class="ew-loading">Loading fixtures…</p></div>
	<div class="ew-panel" data-panel="results"><p class="ew-loading">Loading results…</p></div>
	<div class="ew-panel" data-panel="table"><p class="ew-loading">Loading table…</p></div>
	<p class="ew-credit">Fixtures, results and standings supplied by Football Web Pages.</p>
</div>
	<?php
	return trim( ob_get_clean() );
}
add_shortcode( 'eastwood_matches', 'ew_matches_shortcode' );

function ew_matches_assets() {
	if ( ! is_singular() ) { return; }
	$post = get_post();
	if ( ! $post || ! has_shortcode( (string) $post->post_content, 'eastwood_matches' ) ) { return; }

	$css = '
.ew-matches{max-width:1100px;margin:0 auto;padding:0 16px 56px;font-family:"Instrument Sans",system-ui,sans-serif;color:#111}
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
			array( 'name' => '1st Team',         'pitchero' => 140091, 'url' => '/eastwood-squad/' ),
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

function ew_teams_shortcode() {
	ob_start();
	?>
<div class="ew-teams-index">
	<?php foreach ( ew_teams_roster() as $group => $teams ) : ?>
	<section class="ew-tm-group">
		<h2 class="ew-tm-head"><?php echo esc_html( $group ); ?><span><?php echo count( $teams ); ?></span></h2>
		<div class="ew-tm-grid">
			<?php
			foreach ( $teams as $team ) :
				list( $url, $external ) = ew_teams_link( $team );
				$tag = $url ? 'a' : 'div';
				?>
			<<?php echo $tag; ?> class="ew-tm-card"
				<?php if ( $url ) : ?>href="<?php echo esc_url( $url ); ?>"<?php endif; ?>>
				<span class="ew-tm-name"><?php echo esc_html( $team['name'] ); ?></span>
			</<?php echo $tag; ?>>
			<?php endforeach; ?>
		</div>
	</section>
	<?php endforeach; ?>
	<p class="ew-credit">Twenty-nine teams play out of Coronation Park. New players are welcome across every
		section &mdash; <a href="mailto:info@eastwoodcfc.co.uk">info@eastwoodcfc.co.uk</a>.</p>
</div>
	<?php
	return trim( ob_get_clean() );
}
add_shortcode( 'eastwood_teams', 'ew_teams_shortcode' );

function ew_squad_shortcode() {
	ob_start();
	?>
<div class="ew-squad" data-team="<?php echo esc_attr( EW_TEAM ); ?>">
	<div class="ew-sq-top"><p class="ew-loading">Loading the squad…</p></div>
	<div class="ew-sq-body"></div>
	<p class="ew-credit">Appearances, goals and cards supplied by Football Web Pages and updated as the league
		records each match. The list covers every player who has featured for the first team this season.</p>
</div>
	<?php
	return trim( ob_get_clean() );
}
add_shortcode( 'eastwood_squad', 'ew_squad_shortcode' );

function ew_teams_assets() {
	if ( ! is_singular() ) { return; }
	$post = get_post();
	if ( ! $post ) { return; }
	$content = (string) $post->post_content;
	$teams   = has_shortcode( $content, 'eastwood_teams' );
	$squad   = has_shortcode( $content, 'eastwood_squad' );
	if ( ! $teams && ! $squad ) { return; }

	$css = '
.ew-teams-index,.ew-squad{max-width:1100px;margin:0 auto;padding:0 16px 56px;
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
.ew-sq-lead{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:8px;margin:0 0 32px}
.ew-sq-star{background:#fff;border:1px solid #e6e6e6;border-top:3px solid #CC0000;border-radius:4px;padding:18px 20px}
.ew-sq-star b{display:block;font-family:Anton,sans-serif;font-size:40px;line-height:1;color:#CC0000}
.ew-sq-star em{display:block;font-style:normal;font-weight:600;font-size:16px;margin:8px 0 2px}
.ew-sq-star i{display:block;font-style:normal;font-size:12px;color:#8a8a8a}
.ew-sq-sub{font-family:Anton,sans-serif;font-size:15px;letter-spacing:.08em;text-transform:uppercase;
 color:#6b6b6b;margin:0 0 10px}
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
.ew-sq-armband{display:inline-block;margin-left:7px;font-size:10px;font-weight:700;letter-spacing:.06em;
 color:#CC0000;border:1px solid #CC0000;border-radius:3px;padding:1px 4px;vertical-align:1px}
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
  // so show the one worn most often and say so on the page.
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

 var scorers=rows.slice().filter(function(r){return r.goals>0;})
   .sort(function(x,y){return y.goals-x.goals;}).slice(0,3);

 top.innerHTML=scorers.length
  ? '<div class="ew-sq-lead">'+scorers.map(function(r){
      return '<div class="ew-sq-star"><b>'+r.goals+'</b><em>'+esc(r.name)+'</em>'
           +'<i>'+r.goals+(r.goals===1?' goal':' goals')+' in '+r.apps
           +(r.apps===1?' appearance':' appearances')+'</i></div>';
    }).join('')+'</div>'
  : '';

 rows.sort(function(x,y){
  if(y.apps!==x.apps){return y.apps-x.apps;}
  return x.name<y.name?-1:1;
 });

 body.innerHTML='<h3 class="ew-sq-sub">First-team squad</h3>'
  +'<table class="ew-sq-table"><thead><tr>'
  +'<th>#</th><th class="ewc-who">Player</th><th>Apps</th><th>Goals</th>'
  +'<th class="ewc-hide">Yellow</th></tr></thead><tbody>'
  +rows.map(function(r){
    return '<tr>'
     +'<td class="ewc-shirt">'+(r.shirt||'&ndash;')+'</td>'
     +'<td class="ewc-who">'+esc(r.name)
     +(r.capt?'<span class="ew-sq-armband" title="Has captained the side">C</span>':'')+'</td>'
     +'<td>'+r.apps+'</td>'
     +'<td class="ewc-gls">'+(r.goals||'&ndash;')+'</td>'
     +'<td class="ewc-hide">'+(r.cards||'&ndash;')+'</td>'
     +'</tr>';
  }).join('')
  +'</tbody></table>'
  +'<p class="ew-key" style="margin-top:14px">Shirt numbers are the number each player has worn most often.'
  +' <span class="ew-sq-armband">C</span> marks a player who has captained the side.</p>';
}

Promise.all([
 fetch('/wp-json/eastwood/v1/fwp?endpoint=appearances').then(function(r){return r.json();}),
 fetch('/wp-json/eastwood/v1/fwp?endpoint=goalscorers').then(function(r){return r.json();})
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

const EW_FWP_VERSION = '2.10.0';

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
	if ( ! $forced && false !== $cached ) {
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

const EW_NEWS_SLUG  = 'eastwood-news';
const EW_NEWS_RULES = '1';

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

const EW_PAGES_V = '8';

function ew_owned_pages() {
	return array(
		'home-preview' => array(
			'title'    => 'Homepage (new design)',
			'content'  => ew_home_preview_content(),
			'template' => EW_HOME_TPL,
			'noindex'  => true,
		),
		'eastwood-teams' => array(
			'title'   => 'Teams',
			'content' => '[eastwood_teams]',
		),
		'eastwood-squad' => array(
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
		'eastwood-tickets' => array(
			'title'   => 'Matchday',
			'content' => ew_matchday_content(),
		),
		'eastwood-hospitality' => array(
			'title'   => 'The Venue',
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
		'company-details' => array(
			'title'   => 'Company Details',
			'content' => ew_company_content(),
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
}

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
		$known = isset( $state[ $slug ]['hash'] ) ? $state[ $slug ]['hash'] : '';
		if ( $known && md5( $existing->post_content ) === $known
			&& $existing->post_content !== $spec['content'] ) {
			wp_update_post( array( 'ID' => $existing->ID, 'post_content' => $spec['content'] ) );
		}
		if ( ! isset( $state[ $slug ] ) ) {
			$state[ $slug ] = array( 'id' => $existing->ID, 'hash' => md5( $existing->post_content ) );
		} else {
			$state[ $slug ]['hash'] = md5( $spec['content'] );
		}
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

function ew_tv_shortcode() {
	$videos = ew_tv_feed();

	ob_start();

	if ( empty( $videos ) ) {
		?>
<div class="ew-tv">
	<p class="ew-loading">Eastwood TV is having trouble reaching the channel just now.
		You can watch everything at <a href="https://www.youtube.com/@<?php echo esc_attr( EW_TV_HANDLE ); ?>"
		target="_blank" rel="noopener">youtube.com/@<?php echo esc_html( EW_TV_HANDLE ); ?></a>.</p>
</div>
		<?php
		return trim( ob_get_clean() );
	}

	$hero = $videos[0];
	foreach ( $videos as $v ) {
		if ( 'documentary' === $v['series'] ) { $hero = $v; break; }
	}

	$groups = array(
		'documentary' => array( 'This Is Eastwood', 'The club\'s own documentary series, a new episode every Thursday.' ),
		'highlights'  => array( 'Match highlights', 'Every goal from every game, usually up within a day or two.' ),
		'clips'       => array( 'More from the club', '' ),
	);
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
			<span class="ew-tv-kicker">Latest episode</span>
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

	<p class="ew-credit">Eastwood TV shows the most recent uploads from the club's YouTube channel.
		The full archive is <a href="https://www.youtube.com/@<?php echo esc_attr( EW_TV_HANDLE ); ?>/videos"
		target="_blank" rel="noopener">on YouTube</a>.</p>
</div>
	<?php
	return trim( ob_get_clean() );
}
add_shortcode( 'eastwood_tv', 'ew_tv_shortcode' );

function ew_tv_assets() {
	if ( ! is_singular() ) { return; }
	$post = get_post();
	if ( ! $post || ! has_shortcode( (string) $post->post_content, 'eastwood_tv' ) ) { return; }

	$css = '
.ew-tv{max-width:1100px;margin:0 auto;padding:0 16px 56px;font-family:"Instrument Sans",system-ui,sans-serif;color:#111}
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
		'Company Details' => home_url( '/company-details/' ),
		'Safeguarding'    => home_url( '/safeguarding/' ),
		'Privacy Policy'  => home_url( '/privacy-policy/' ),
		'Terms'           => home_url( '/terms/' ),
	);
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

			if ( ! isset( $map[ $label ] ) ) {
				return $m[0];
			}

			$attrs = $m[1];
			$attrs = preg_replace( '#\shref="\#"#', ' href="' . esc_url( $map[ $label ] ) . '"', $attrs, 1 );
			$attrs = preg_replace( '#\sonclick="return false"#', '', $attrs );

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

function ew_prose_css_inline() {
	return '
.ew-prose{max-width:820px;margin:0 auto;padding:0 16px 56px;
 font-family:"Instrument Sans",system-ui,sans-serif;color:#111}
.ew-prose *{box-sizing:border-box}
.ew-prose .ew-lede{font-size:19px;line-height:1.55;color:#333;margin:0 0 28px}
.ew-prose h2{font-family:Anton,"Instrument Sans",sans-serif;font-size:22px;letter-spacing:.04em;
 text-transform:uppercase;margin:40px 0 14px;padding:0 0 10px;border-bottom:2px solid #e3e3e3}
.ew-prose p{font-size:16px;line-height:1.65;margin:0 0 16px}
.ew-prose ul{margin:0 0 20px;padding-left:20px}
.ew-prose li{font-size:16px;line-height:1.6;margin:0 0 8px}
.ew-prose .ew-people{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:8px;margin:0 0 20px}
.ew-prose .ew-person{background:#fff;border:1px solid #e6e6e6;border-left:3px solid #CC0000;
 border-radius:4px;padding:14px 16px}
.ew-prose .ew-person b{display:block;font-size:15px}
.ew-prose .ew-person span{font-size:12px;color:#8a8a8a}
.ew-prose .ew-cta{background:#111;color:#fff;border-radius:4px;padding:26px 28px;margin:36px 0 0}
.ew-prose .ew-cta h3{font-family:Anton,"Instrument Sans",sans-serif;font-size:20px;letter-spacing:.05em;
 text-transform:uppercase;margin:0 0 8px;color:#fff}
.ew-prose .ew-cta p{color:#d6d6d6;margin:0 0 4px;font-size:15px}
.ew-prose .ew-cta a{color:#fff;font-weight:600}
@media(max-width:720px){
 .ew-prose .ew-lede{font-size:17px}
 .ew-prose h2{font-size:19px}
}';
}

function ew_prose_assets() {
	if ( ! is_singular() ) { return; }
	$post = get_post();
	if ( ! $post || false === strpos( (string) $post->post_content, 'ew-prose' ) ) { return; }

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

function ew_matchday_content() {
	return <<<'HTML'
<div class="ew-prose">

<p class="ew-lede">Eastwood play at Coronation Park on Chewton Street. Pay on the gate, park on site for nothing, and under-16s get in free with an adult.</p>

<h2>First team, league matches</h2>

<div class="ew-prices">
	<div class="ew-price"><b>£7.00</b><span>Adults</span></div>
	<div class="ew-price"><b>£5.00</b><span>Concessions, 65 and over</span></div>
	<div class="ew-price"><b>£5.00</b><span>Students, with valid ID</span></div>
	<div class="ew-price"><b>Free</b><span>Under 16s, with an adult</span></div>
</div>

<p>Parking on site is free unless we say otherwise for a particular fixture.</p>

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

<h2>Disabled supporters</h2>

<p>The clubhouse is reached by a ramp at the entrance to the main building. There are four spaces at pitch level in front of the Main Stand for wheelchair users, for home and away supporters alike, with helpers able to sit alongside or stand at the fence. Supporters with specific access needs who are able to walk can sit pitch side in the first few rows of the Main Stand. There are two accessible toilets at the ground.</p>

<p>Please ring ahead on 01773 432414 if you need a particular space reserved, or an accessible parking space — those are first come, first served and must be booked in advance. There are always staff about who can help.</p>

<p>Accessible tickets, by the match or by the season, are available to supporters receiving any of the following: the middle or higher rate of Disability Living Allowance, Attendance Allowance, Severe Disablement Allowance, a War Disabled Pension, a Certificate of Visual Impairment, or enhanced Personal Independence Payment. Bring the letter or certificate to the Pitchside Bar &amp; Lounge — evidence is needed once a year.</p>

<p>Anyone qualifying gets the reduced rate and a free enabler ticket. The enabler is responsible for the supporter they accompany and should stay with them throughout; an enabler arriving without them needs to upgrade at the bar before kick-off.</p>

<p>If something about your visit could have been better, tell us. We would rather hear it.</p>

<h2>Match day hospitality</h2>

<p>Hospitality is available for every home game in the Pitchside Bar &amp; Lounge. It usually includes admission, a reserved parking space, a team sheet, and food before the game and at half time.</p>

<div class="ew-cta">
	<h3>Book hospitality or ask about tickets</h3>
	<p><a href="mailto:info@eastwoodcfc.co.uk">info@eastwoodcfc.co.uk</a> &nbsp;·&nbsp; <a href="tel:+441773432414">01773 432414</a></p>
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

<p>Call 01773 432414 or email info@eastwoodcfc.co.uk and tell us what you need and when. Changing rooms and the Pitchside Bar can be hired alongside the pitch.</p>

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
	<p><a href="mailto:info@eastwoodcfc.co.uk">info@eastwoodcfc.co.uk</a> &nbsp;·&nbsp; <a href="tel:+441773432414">01773 432414</a></p>
</div>

</div>
HTML;
}


function ew_commercial_assets() {
	if ( ! is_singular() ) { return; }
	$post = get_post();
	if ( ! $post ) { return; }
	$c = (string) $post->post_content;
	if ( false === strpos( $c, 'ew-prose' ) ) { return; }

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

/* ------------------------------------------------------------------
 * Club History.
 *
 * Deliberately not carried over from the old Pitchero article, which
 * was written under the previous ownership and has aged badly: it
 * names a different owner, announces an academy as "scheduled for next
 * year" that now exists and runs, counts 28 junior teams against
 * today's 22, and presents the Benfica and Mango Football arrangements
 * as current without anybody having checked whether they still are.
 *
 * What is here instead comes from records rather than prose: the
 * manager list the club keeps, its FA Youth Cup results, and the
 * club's own announcements from this year. The one thing missing is
 * the story of the change of ownership, which is Ben's to tell and is
 * not invented here.
 *
 * Published without a navigation link on purpose — where it belongs in
 * the menu is still open.
 * ------------------------------------------------------------------ */

function ew_history_content() {
	return <<<'HTML'
<div class="ew-prose">

<p class="ew-lede">Eastwood Community Football Club was founded in 2014 and plays at Coronation Park on Chewton Street. The Red Badgers.</p>

<h2>Where the club is now</h2>

<p>2026 has been the busiest year in the club's short history.</p>

<ul>
	<li><b>A kit deal with Nike</b>, announced in May, covering the first team, training wear, the academy and the junior sides — the club's stated reason being that if the ambition is to build the best club in Nottinghamshire, the standards have to match at every level.</li>
	<li><b>A five-year ground-share with Punjab United</b>, who play their home games at Coronation Park. Punjab United were founded in 1966 and marked their 60th anniversary in the same year the partnership began.</li>
	<li><b>A new beer garden and outdoor viewing area</b>, opened in June for the World Cup and built as a social space for the town rather than only for matchdays.</li>
	<li><b>The academy</b>, a full-time elite programme combining football development with a BTEC Level 3, now running at Coronation Park.</li>
	<li><b>This Is Eastwood</b>, the club's own documentary series, following the first team through the season with a new episode every week.</li>
</ul>

<h2>First team managers</h2>

<div class="ew-timeline">
	<div class="ew-tl"><b>2014</b><span>Tony Clarke</span></div>
	<div class="ew-tl"><b>2014–16</b><span>Paul MacFarlane</span></div>
	<div class="ew-tl"><b>2016</b><span>Matt McCaul</span></div>
	<div class="ew-tl"><b>2016–17</b><span>Jez Corthorn &amp; Jonathan Wass</span></div>
	<div class="ew-tl"><b>2017–19</b><span>Dave Marlow &amp; Jonathan Wass</span></div>
	<div class="ew-tl"><b>2019</b><span>David Lilley <i>(interim)</i></span></div>
	<div class="ew-tl"><b>2019–21</b><span>James Jepson</span></div>
	<div class="ew-tl"><b>2021</b><span>Alexander Shayler <i>(interim)</i></span></div>
	<div class="ew-tl"><b>2021</b><span>Stephen Kirkham</span></div>
	<div class="ew-tl"><b>2021–22</b><span>Alexander Shayler &amp; Paul Rockley</span></div>
	<div class="ew-tl"><b>2022</b><span>Paul Rockley</span></div>
	<div class="ew-tl"><b>2022–23</b><span>Nick Labbate</span></div>
	<div class="ew-tl"><b>2023–25</b><span>Martin Ball &amp; Daryll Thomas</span></div>
	<div class="ew-tl"><b>2025</b><span>Aaron O'Connor <i>(interim)</i></span></div>
	<div class="ew-tl"><b>2025–26</b><span>Willis Francis</span></div>
</div>

<h2>FA Youth Cup</h2>

<p>The club's junior section has entered the FA Youth Cup every season since 2017 bar one, reaching the second qualifying round three times.</p>

<ul>
	<li><b>2017/18</b> — beat Deeping Rangers 5–2, out to Belper Town in the first qualifying round</li>
	<li><b>2018/19</b> — out to Cleethorpes Town in the preliminary round</li>
	<li><b>2019/20</b> — past Lutterworth Athletic and West Bridgford to the second qualifying round</li>
	<li><b>2020/21</b> — out to Mickleover Sports</li>
	<li><b>2021/22</b> — out to Grantham Town</li>
	<li><b>2023/24</b> — through against Dunkirk, beat Boston United 3–0 away, out to Heather St John</li>
	<li><b>2024/25</b> — beat Deeping Rangers 5–0 and Alfreton Town 2–0, out to Gresley in the second qualifying round</li>
	<li><b>2025/26</b> — beat Ilkeston Town 2–1, out to Aylestone Park</li>
</ul>

<h2>One club, one community</h2>

<p>Eastwood runs 29 teams across senior, junior, mini and girls' football, from the first team down to the Soccer School. That breadth is the point of the place: the first team is the shop window, but most of what happens at Coronation Park in a given week has nothing to do with it.</p>

</div>
HTML;
}

function ew_history_assets() {
	if ( ! is_singular() ) { return; }
	$post = get_post();
	if ( ! $post || false === strpos( (string) $post->post_content, 'ew-timeline' ) ) { return; }

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
function ew_fwp_fetch( $endpoint, $args = array() ) {
	$args = array_merge( array( 'team' => EW_TEAM ), $args );
	$url  = add_query_arg( $args, 'https://api.footballwebpages.co.uk/v2/' . $endpoint . '.json' );
	$key  = 'ewhome_' . md5( $url );

	$hit = get_transient( $key );
	if ( false !== $hit ) {
		return $hit;
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

	set_transient( $key, $data, empty( $data ) ? 2 * MINUTE_IN_SECONDS : 10 * MINUTE_IN_SECONDS );
	return $data;
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
	return content_url( '/uploads/ew-badges/' . (int) $id . '.png' ) . $v;
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
		<a class="ewh-panel ewh-next" href="<?php echo esc_url( home_url( '/eastwood-matches/' ) ); ?>">
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
		<a class="ewh-panel ewh-last" href="<?php echo esc_url( home_url( '/eastwood-matches/#results' ) ); ?>">
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
		<a class="ewh-panel ewh-pos" href="<?php echo esc_url( home_url( '/eastwood-matches/#table' ) ); ?>">
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
		<h2 class="ewh-head">Latest news <a href="<?php echo esc_url( home_url( '/eastwood-news/' ) ); ?>">All news</a></h2>
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

	// The captured replica front page — Forest's structure, Eastwood's
	// content — is the brief. It lives in its own file because it is 120KB
	// of markup, and it ships in the same repo so the updater installs it
	// alongside this one.
	$capture = __DIR__ . '/home-template.php';
	if ( file_exists( $capture ) ) {
		require_once $capture;
	}

	add_action( 'wp_head', function () {
		echo '<style id="ew-home">' . ew_home_css() . '</style>';
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

	<div class="ewm-wrap">
		<p class="ewm-back"><a href="<?php echo esc_url( home_url( '/eastwood-matches/' ) ); ?>">All fixtures and results</a></p>
		<p class="ewm-credit">Match detail supplied by Football Web Pages.</p>
	</div>
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
			. '<a href="' . esc_url( home_url( '/eastwood-matches/' ) ) . '">All fixtures and results</a>'
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
	<h3>Get in touch</h3>
	<p><a href="mailto:info@eastwoodcfc.co.uk">info@eastwoodcfc.co.uk</a> &nbsp;·&nbsp; <a href="tel:+441773432414">01773 432414</a></p>
</div>

<h2>Who's who</h2>

<div class="ew-people">
	<div class="ew-person"><b>Ben Edwards</b><span>Chair and Club Owner</span></div>
	<div class="ew-person"><b>Stephen Kirkham</b><span>Managing Director</span></div>
	<div class="ew-person"><b>Zander Shayler</b><span>Secretary, Fixture Secretary and Director of Operations</span></div>
	<div class="ew-person"><b>Sarah Robertson-Staples</b><span>Treasurer and Safeguarding Officer</span></div>
</div>

<p>Everything reaches us at <a href="mailto:info@eastwoodcfc.co.uk">info@eastwoodcfc.co.uk</a> — hospitality and
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

function ew_company_content() {
	return <<<'HTML'
<div class="ew-prose">

<p class="ew-lede">Eastwood Community Football Club CIC is a community interest company registered in England and
Wales, company number 09196902.</p>

<h2>Registered details</h2>

<ul>
	<li><b>Registered name:</b> Eastwood Community Football Club CIC</li>
	<li><b>Company number:</b> 09196902</li>
	<li><b>Registered office:</b> Coronation Park, Chewton Street, Eastwood, Nottinghamshire NG16 3HB</li>
	<li><b>Contact:</b> <a href="mailto:info@eastwoodcfc.co.uk">info@eastwoodcfc.co.uk</a>, 01773 432414</li>
</ul>

<h2>What a community interest company means</h2>

<p>A CIC is a company that exists to benefit a community rather than to enrich shareholders. Its assets are
locked to that purpose: they cannot simply be taken out of the business. For Eastwood that community is the town
and the people who play, coach, volunteer and watch here.</p>

<p>Twenty-nine teams run out of Coronation Park across senior, junior, mini and girls' football. The first team
is the part most people see, but it is a small share of what the ground does in a given week.</p>

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

<p>Our Safeguarding Officer is <b>Sarah Robertson-Staples</b>. Any concern about a child's welfare, about the
behaviour of an adult at the club, or about anything that does not feel right, should go to her.</p>

<div class="ew-cta">
	<h3>Contact the Safeguarding Officer</h3>
	<p>Mark it for the attention of the Safeguarding Officer.</p>
	<p><a href="mailto:info@eastwoodcfc.co.uk">info@eastwoodcfc.co.uk</a> &nbsp;·&nbsp; <a href="tel:+441773432414">01773 432414</a></p>
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
us to delete it. Write to <a href="mailto:info@eastwoodcfc.co.uk">info@eastwoodcfc.co.uk</a> and we will respond
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
<a href="mailto:info@eastwoodcfc.co.uk">info@eastwoodcfc.co.uk</a>.</p>

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
<a href="mailto:info@eastwoodcfc.co.uk">info@eastwoodcfc.co.uk</a>.</p>

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
	$roster = ew_sponsor_roster();
	return isset( $roster[ $m[1] ][2] ) ? (string) $roster[ $m[1] ][2] : '';
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
	<p><a href="mailto:info@eastwoodcfc.co.uk">info@eastwoodcfc.co.uk</a> &nbsp;·&nbsp; <a href="tel:+441773432414">01773 432414</a></p>
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
	<p><a href="mailto:info@eastwoodcfc.co.uk">info@eastwoodcfc.co.uk</a> &nbsp;·&nbsp; <a href="tel:+441773432414">01773 432414</a></p>
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
	<div class="ew-person"><b>John Cole</b><span>Coach</span></div>
	<div class="ew-person"><b>Lynden Joyce</b><span>Coach</span></div>
	<div class="ew-person"><b>Adam Fawcett</b><span>Tutor</span></div>
</div>

<h2>Trials</h2>

<p>The Academy recruits through open trials at Coronation Park, most recently in July. Trial dates for the next intake are announced on the club's channels and here — if you would like to be told when the next one is set, call the club and ask to be added to the list.</p>

<h2>Finding out more</h2>

<p>We have a full Academy brochure covering the programme, the education side and what a week looks like. Ask us and we will send it to you.</p>

<p>For entry requirements, fees, term dates and anything else, speak to the club directly. We would rather answer your question properly than publish a page of generalities.</p>

<div class="ew-cta">
	<h3>Enquire about the Academy</h3>
	<p>Eastwood Football Club, Coronation Park, Chewton Street, Eastwood, Nottinghamshire NG16 3HB</p>
	<p><a href="mailto:info@eastwoodcfc.co.uk">info@eastwoodcfc.co.uk</a> &nbsp;·&nbsp; <a href="tel:+441773432414">01773 432414</a></p>
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

	<p class="ew-teamCrumb"><a href="<?php echo esc_url( home_url( '/eastwood-teams/' ) ); ?>">Teams</a>
		<span>/</span> <?php echo esc_html( $group ); ?></p>

	<h1 class="ew-teamTitle"><?php echo esc_html( $name ); ?></h1>

	<p class="ew-lede"><?php echo esc_html( ew_team_group_blurb( $group ) ); ?></p>

	<?php if ( $is_first ) : ?>

	<p>The first team plays in the United Counties League Premier Division North. Every appearance, goal and
		card is recorded as the league publishes it.</p>

	<div class="ew-teamLinks">
		<a href="<?php echo esc_url( home_url( '/eastwood-squad/' ) ); ?>">Squad and season stats</a>
		<a href="<?php echo esc_url( home_url( '/eastwood-matches/' ) ); ?>">Fixtures, results and table</a>
		<a href="<?php echo esc_url( home_url( '/eastwood-tickets/' ) ); ?>">Matchday and tickets</a>
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
		<p><a href="mailto:info@eastwoodcfc.co.uk">info@eastwoodcfc.co.uk</a> &nbsp;·&nbsp;
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
			. '<a href="' . esc_url( home_url( '/eastwood-teams/' ) ) . '">All teams</a></p></div>';
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
