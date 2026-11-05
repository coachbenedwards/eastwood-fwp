<?php
/**
 * Bring Club Subs onto the push-to-update route.
 *
 * Club Subs 0.2.0 onwards updates itself from github.com/coachbenedwards/club-subs.
 * Versions before that have no updater of their own, so this part offers the
 * update for them, once. As soon as an installed Club Subs carries its own
 * updater (it defines CS_UPDATER) this part does nothing at all.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ew_cs_bootstrap_needed() {
	return defined( 'CS_VERSION' ) && ! defined( 'CS_UPDATER' ) && defined( 'CS_FILE' );
}

add_filter( 'pre_set_site_transient_update_plugins', function ( $t ) {
	if ( ! ew_cs_bootstrap_needed() ) {
		return $t;
	}
	if ( ! is_object( $t ) ) {
		$t = new stdClass();
	}
	$info = get_transient( 'ew_cs_remote' );
	if ( isset( $_GET['force-check'] ) || ! is_array( $info ) || empty( $info['version'] ) || version_compare( $info['version'], CS_VERSION, '<=' ) ) {
		$res  = wp_remote_get( 'https://raw.githubusercontent.com/coachbenedwards/club-subs/main/plugin.json', array( 'timeout' => 15 ) );
		$info = array();
		if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
			$d = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( is_array( $d ) && ! empty( $d['version'] ) ) {
				$info = $d;
			}
		}
		set_transient( 'ew_cs_remote', $info, empty( $info ) ? 5 * MINUTE_IN_SECONDS : HOUR_IN_SECONDS );
	}
	if ( empty( $info['version'] ) || ! version_compare( $info['version'], CS_VERSION, '>' ) ) {
		return $t;
	}
	if ( ! isset( $t->response ) || ! is_array( $t->response ) ) {
		$t->response = array();
	}
	$t->response[ plugin_basename( CS_FILE ) ] = (object) array(
		'slug'        => 'club-subs',
		'plugin'      => plugin_basename( CS_FILE ),
		'new_version' => $info['version'],
		'url'         => 'https://github.com/coachbenedwards/club-subs',
		'package'     => 'https://github.com/coachbenedwards/club-subs/archive/refs/heads/main.zip',
	);
	return $t;
} );

add_action( 'delete_site_transient_update_plugins', function () {
	delete_transient( 'ew_cs_remote' );
} );

/** The branch zip unpacks to club-subs-main/; install it as club-subs/. */
add_filter( 'upgrader_source_selection', function ( $source, $remote_source ) {
	if ( ! ew_cs_bootstrap_needed() || 0 !== strpos( basename( untrailingslashit( $source ) ), 'club-subs-' ) ) {
		return $source;
	}
	$fixed = trailingslashit( $remote_source ) . 'club-subs/';
	global $wp_filesystem;
	if ( $wp_filesystem && $wp_filesystem->move( $source, $fixed, true ) ) {
		return $fixed;
	}
	return $source;
}, 10, 2 );
