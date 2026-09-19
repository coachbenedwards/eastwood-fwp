<?php
/**
 * Plugin Name: Eastwood — club data
 * Description: Everything the Eastwood site needs from outside WordPress: the Football Web Pages proxy (live fixtures, results, league table and full match detail), the club-badge store, and the importer that pulls the club's news across from Pitchero.
 * Version: 2.1.3
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

	return $html;
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
.ew-match{display:grid;grid-template-columns:96px 1fr auto;gap:16px;align-items:center;
 background:#fff;border:1px solid #e6e6e6;border-radius:4px;padding:14px 18px;margin-bottom:8px}
.ew-match.is-home{border-left:3px solid #CC0000}
.ew-when{font-size:13px;line-height:1.35;color:#6b6b6b}
.ew-when strong{display:block;font-size:15px;color:#111}
.ew-teams{display:flex;align-items:center;gap:12px;min-width:0}
.ew-side{display:flex;align-items:center;gap:10px;flex:1;min-width:0}
.ew-side.ewc-away{flex-direction:row-reverse;text-align:right}
.ew-side img{width:34px;height:34px;object-fit:contain;flex:none}
.ew-side span{font-weight:600;font-size:15px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ew-side.ewc-us span{color:#CC0000}
.ew-score{font-family:Anton,sans-serif;font-size:22px;letter-spacing:.05em;white-space:nowrap;padding:0 4px}
.ew-ko{font-family:Anton,sans-serif;font-size:16px;color:#6b6b6b;white-space:nowrap;padding:0 4px}
.ew-meta{font-size:12px;color:#8a8a8a;text-align:right;white-space:nowrap}
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
 .ew-meta{text-align:left}
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
  out+='<div class="ew-match'+(home.id===US?' is-home':'')+'">'
     +'<div class="ew-when"><strong>'+esc(n.date)+'</strong>'+esc(n.day)+'</div>'
     +'<div class="ew-teams">'+side(home,'ewc-home')+mid+side(away,'ewc-away')+'</div>'
     +'<div class="ew-meta">'+esc(m.venue||'')
     +(m.attendance?'<br>Att '+esc(m.attendance):'')+'</div>'
     +'</div>';
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

const EW_FWP_VERSION = '2.1.3';
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
	// A forced check must actually re-check. This is tested here rather than
	// on admin_init, because core runs the forced update check on
	// load-update-core.php, which fires first — clearing the cache later is
	// always one page load too late.
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
	// cached briefly, not for six hours. Caching an empty result for a whole
	// working day means one blip hides every release until tomorrow.
	set_transient( 'ew_fwp_remote', $info, empty( $info ) ? 5 * MINUTE_IN_SECONDS : 6 * HOUR_IN_SECONDS );
	return $info;
}

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
