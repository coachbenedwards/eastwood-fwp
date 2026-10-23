<?php
/**
 * Bookings -> Analytics: what the website has sold, given away and drawn in.
 *
 * Read straight from the Bookings record (tickets, shirts, school claims,
 * community tickets) plus a small visitor count of our own. Nothing here
 * is stored twice: the figures are worked out each time the screen opens.
 *
 * Visitors are counted without cookies. Each page view sends one small
 * request; a visitor is a one-way hash of address, browser and day, so the
 * same person is one visitor per day and nobody can be identified from it.
 * Logged-in staff and known bots are not counted.
 *
 * @package eastwood-fwp
 */

defined( 'ABSPATH' ) || exit;

/* --- Visitor count ------------------------------------------------------ */

function ew_an_table() {
	global $wpdb;
	return $wpdb->prefix . 'ew_hits';
}

/** Make the table once, and again only if this part changes its shape. */
add_action( 'init', function () {
	if ( '1' === get_option( 'ew_an_db' ) ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( 'CREATE TABLE ' . ew_an_table() . ' (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		day date NOT NULL,
		path varchar(191) NOT NULL,
		ref varchar(100) NOT NULL DEFAULT \'\',
		vh char(16) NOT NULL,
		PRIMARY KEY  (id),
		KEY day (day)
	) ' . $wpdb->get_charset_collate() . ';' );
	update_option( 'ew_an_db', '1' );
} );

/** The beacon: one line on every public page, for visitors who are not staff. */
add_action( 'wp_footer', function () {
	if ( is_user_logged_in() || is_admin() ) {
		return;
	}
	$url = esc_url_raw( rest_url( 'eastwood/v1/hit' ) );
	echo '<script>(function(){try{var r=document.referrer,h="";if(r){h=new URL(r).hostname;if(h===location.hostname)h="";}'
		. 'fetch(' . wp_json_encode( $url ) . ',{method:"POST",keepalive:true,headers:{"Content-Type":"application/json"},'
		. 'body:JSON.stringify({p:location.pathname,r:h})});}catch(e){}})();</script>';
}, 99 );

add_action( 'rest_api_init', function () {
	register_rest_route( 'eastwood/v1', '/hit', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) {
			$ua = (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ); // phpcs:ignore
			if ( '' === $ua || preg_match( '/bot|crawl|spider|slurp|preview|monitor|headless|lighthouse|facebookexternalhit|whatsapp/i', $ua ) ) {
				return array( 'ok' => true );
			}
			$path = substr( '/' . ltrim( (string) wp_parse_url( (string) $req->get_param( 'p' ), PHP_URL_PATH ), '/' ), 0, 191 );
			$ref  = substr( strtolower( preg_replace( '/[^a-z0-9.\-]/i', '', (string) $req->get_param( 'r' ) ) ), 0, 100 );
			$ref  = preg_replace( '/^(www\.|m\.|l\.|lm\.)/', '', $ref );
			$ip   = (string) ( $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore
			$day  = gmdate( 'Y-m-d' );
			$vh   = substr( hash( 'sha256', $ip . '|' . $ua . '|' . $day . '|' . wp_salt( 'auth' ) ), 0, 16 );
			global $wpdb;
			$wpdb->insert( ew_an_table(), array( 'day' => $day, 'path' => $path, 'ref' => $ref, 'vh' => $vh ) ); // phpcs:ignore
			return array( 'ok' => true );
		},
	) );
} );

/* --- The screen --------------------------------------------------------- */

add_action( 'admin_menu', function () {
	add_submenu_page( 'edit.php?post_type=ewt_booking', 'Analytics', 'Analytics', 'edit_posts', 'ew-analytics', 'ew_an_page' );
}, 20 );

/** The start of each range, as a timestamp. */
function ew_an_since( $range ) {
	$now = current_time( 'timestamp' );
	switch ( $range ) {
		case '7':
			return $now - 7 * DAY_IN_SECONDS;
		case '30':
			return $now - 30 * DAY_IN_SECONDS;
		case 'season':
			// The season starts on 1 July.
			$y = (int) gmdate( 'Y', $now );
			$s = strtotime( $y . '-07-01' );
			return $s > $now ? strtotime( ( $y - 1 ) . '-07-01' ) : $s;
	}
	return 0;
}

function ew_an_money( $pence ) {
	return '£' . number_format( $pence / 100, ( 0 === $pence % 100 ) ? 0 : 2 );
}

function ew_an_page() {
	$ranges = array( '7' => 'Last 7 days', '30' => 'Last 30 days', 'season' => 'This season', 'all' => 'All time' );
	$range  = isset( $_GET['range'] ) && isset( $ranges[ $_GET['range'] ] ) ? sanitize_key( $_GET['range'] ) : 'all'; // phpcs:ignore
	$since  = ew_an_since( $range );

	$q = array( 'post_type' => 'ewt_booking', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' );
	if ( $since ) {
		$q['date_query'] = array( array( 'after' => gmdate( 'Y-m-d H:i:s', $since ), 'inclusive' => true ) );
	}
	$ids = get_posts( $q );

	$money = 0; $orders = 0; $free_people = 0; $free_claims = 0; $unfinished = 0;
	$events = array(); $shop = array(); $sizes = array(); $comm = array();

	foreach ( $ids as $id ) {
		$m      = function ( $k ) use ( $id ) {
			return (string) get_post_meta( $id, '_ewt_' . $k, true );
		};
		$status = $m( 'status' );
		$event  = '' !== $m( 'event' ) ? $m( 'event' ) : '(no name)';
		$event  = preg_replace( '/^Community tickets:\s*/', '', $event );
		$lines  = $m( 'lines' );
		$people = (int) $m( 'adults' ) + (int) $m( 'children' );
		if ( ! $people && preg_match_all( '/(\d+)\s+(adult|child)/i', $lines, $mm ) ) {
			$people = array_sum( array_map( 'intval', $mm[1] ) );
		}
		$is_shop = 'shop' === $m( 'kind' ) || false !== stripos( $event, 'shirt' );

		if ( 'paid' === $status ) {
			$money += (int) $m( 'paid' );
			++$orders;
		}
		if ( 'started' === $status ) {
			++$unfinished;
		}

		if ( $is_shop ) {
			$s = &$shop[ $event ];
			$s = (array) $s + array( 'paid' => 0, 'taken' => 0, 'open' => 0 );
			if ( 'paid' === $status ) {
				++$s['paid'];
				$s['taken'] += (int) $m( 'paid' );
				$bits = array_map( 'trim', explode( '·', $lines ) );
				$how  = false !== stripos( $lines, 'posted' ) ? 'Post' : 'Collect';
				foreach ( array_map( 'trim', explode( ',', $bits[0] ?? '' ) ) as $one ) {
					$size = trim( preg_replace( '/\s+printed.*$/i', '', $one ) );
					if ( '' === $size ) {
						continue;
					}
					$k = $size . '|' . $how;
					$z = &$sizes[ $k ];
					$z = (array) $z + array( 'n' => 0, 'printed' => 0 );
					++$z['n'];
					if ( false !== stripos( $one, 'printed' ) || ( 1 === count( explode( ',', $bits[0] ) ) && false !== stripos( $lines, 'printed' ) ) ) {
						++$z['printed'];
					}
					unset( $z );
				}
			} elseif ( 'started' === $status ) {
				++$s['open'];
			}
			unset( $s );
			continue;
		}

		if ( 0 === strpos( $status, 'community' ) ) {
			$c = &$comm[ $event ];
			$c = (array) $c + array( 'req' => 0, 'ok' => 0, 'no' => 0, 'wait' => 0 );
			++$c['req'];
			if ( 'community_ok' === $status ) {
				++$c['ok'];
				$free_people += $people;
				++$free_claims;
			} elseif ( 'community_no' === $status ) {
				++$c['no'];
			} else {
				++$c['wait'];
			}
			unset( $c );
			$type = 'Community tickets';
		} elseif ( 'claimed' === $status ) {
			$free_people += $people;
			++$free_claims;
			$type = 'School tickets';
		} elseif ( in_array( $status, array( 'paid', 'started' ), true ) ) {
			$type = 'Event night';
		} else {
			$type = ucfirst( str_replace( '_', ' ', $status ) );
		}
		if ( 'community_no' === $status || ( 0 === strpos( $status, 'community' ) && 'community_ok' !== $status ) ) {
			continue; // only approved community tickets count as bookings
		}
		$e = &$events[ $event . '|' . $type ];
		$e = (array) $e + array( 'event' => $event, 'type' => $type, 'n' => 0, 'people' => 0, 'paid' => 0, 'open' => 0, 'free' => 'Event night' !== $type );
		if ( 'started' === $status ) {
			++$e['open'];
		} else {
			++$e['n'];
			$e['people'] += $people;
			$e['paid']   += (int) $m( 'paid' );
		}
		unset( $e );
	}

	// Visitors.
	global $wpdb;
	$t     = ew_an_table();
	$where = $since ? $wpdb->prepare( 'WHERE day >= %s', gmdate( 'Y-m-d', $since ) ) : '';
	$have  = $wpdb->get_var( "SHOW TABLES LIKE '" . esc_sql( $t ) . "'" ) === $t; // phpcs:ignore
	$visitors = $have ? (int) $wpdb->get_var( "SELECT COUNT(DISTINCT day, vh) FROM $t $where" ) : 0; // phpcs:ignore
	$pages    = $have ? $wpdb->get_results( "SELECT path, COUNT(DISTINCT day, vh) v, COUNT(*) n FROM $t $where GROUP BY path ORDER BY v DESC LIMIT 15" ) : array(); // phpcs:ignore
	$refs     = $have ? $wpdb->get_results( "SELECT IF(ref='','Direct or unknown',ref) ref, COUNT(DISTINCT day, vh) v FROM $t $where GROUP BY ref ORDER BY v DESC LIMIT 10" ) : array(); // phpcs:ignore
	$counting = $have ? (string) $wpdb->get_var( "SELECT MIN(day) FROM $t" ) : ''; // phpcs:ignore

	$dash = '<span style="color:#8c8f94">—</span>';
	?>
<div class="wrap ew-an">
<style>
.ew-an .tiles{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin:16px 0 8px}
.ew-an .tile{background:#fff;border:1px solid #c3c4c7;padding:14px 16px}
.ew-an .tile .k{color:#50575e;font-size:12px}.ew-an .tile .v{font-size:28px;margin-top:4px;line-height:1.2}.ew-an .tile .s{color:#646970;font-size:12px;margin-top:2px}
.ew-an h2{margin-top:28px}.ew-an .grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.ew-an td.n,.ew-an th.n{text-align:right}
@media(max-width:900px){.ew-an .tiles{grid-template-columns:1fr 1fr}.ew-an .grid2{grid-template-columns:1fr}}
</style>
<h1>Analytics</h1>
<nav class="nav-tab-wrapper">
	<?php foreach ( $ranges as $k => $label ) : ?>
	<a class="nav-tab<?php echo $k === $range ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'range', $k ) ); ?>"><?php echo esc_html( $label ); ?></a>
	<?php endforeach; ?>
</nav>

<div class="tiles">
	<div class="tile"><div class="k">Money taken online</div><div class="v"><?php echo esc_html( ew_an_money( $money ) ); ?></div><div class="s"><?php echo (int) $orders; ?> paid order<?php echo 1 === $orders ? '' : 's'; ?></div></div>
	<div class="tile"><div class="k">Free tickets given</div><div class="v"><?php echo (int) $free_people; ?></div><div class="s">people · <?php echo (int) $free_claims; ?> claim<?php echo 1 === $free_claims ? '' : 's'; ?></div></div>
	<div class="tile"><div class="k">Checkouts not finished</div><div class="v"><?php echo (int) $unfinished; ?></div><div class="s">started, never paid</div></div>
	<div class="tile"><div class="k">Website visitors</div><div class="v"><?php echo $counting ? (int) $visitors : $dash; // phpcs:ignore ?></div><div class="s"><?php echo $counting ? 'counting since ' . esc_html( mysql2date( 'j M Y', $counting ) ) : 'counting starts with the first visit'; ?></div></div>
</div>

<h2>Tickets</h2>
<table class="widefat striped">
	<thead><tr><th>Event</th><th>Type</th><th class="n">Bookings</th><th class="n">People</th><th class="n">Paid</th><th class="n">Not finished</th></tr></thead>
	<tbody>
	<?php if ( ! $events ) : ?><tr><td colspan="6"><?php echo $dash; // phpcs:ignore ?></td></tr><?php endif; ?>
	<?php foreach ( $events as $e ) : ?>
	<tr><td><?php echo esc_html( $e['event'] ); ?></td><td><?php echo esc_html( $e['type'] ); ?></td>
	<td class="n"><?php echo (int) $e['n']; ?></td><td class="n"><?php echo $e['people'] ? (int) $e['people'] : $dash; // phpcs:ignore ?></td>
	<td class="n"><?php echo $e['free'] ? 'free' : esc_html( ew_an_money( $e['paid'] ) ); ?></td><td class="n"><?php echo $e['open'] ? (int) $e['open'] : $dash; // phpcs:ignore ?></td></tr>
	<?php endforeach; ?>
	</tbody>
</table>

<div class="grid2">
<div>
<h2>Shop</h2>
<table class="widefat striped">
	<thead><tr><th>Item</th><th class="n">Paid</th><th class="n">Taken</th><th class="n">Not finished</th></tr></thead>
	<tbody>
	<?php if ( ! $shop ) : ?><tr><td colspan="4"><?php echo $dash; // phpcs:ignore ?></td></tr><?php endif; ?>
	<?php foreach ( $shop as $name => $s ) : ?>
	<tr><td><?php echo esc_html( $name ); ?></td><td class="n"><?php echo (int) $s['paid']; ?></td><td class="n"><?php echo esc_html( ew_an_money( $s['taken'] ) ); ?></td><td class="n"><?php echo (int) $s['open']; ?></td></tr>
	<?php endforeach; ?>
	</tbody>
</table>
<table class="widefat striped" style="margin-top:10px">
	<thead><tr><th>Sizes sold</th><th class="n">Shirts</th><th>Collect / post</th><th class="n">Printed</th></tr></thead>
	<tbody>
	<?php if ( ! $sizes ) : ?><tr><td colspan="4"><?php echo $dash; // phpcs:ignore ?></td></tr><?php endif; ?>
	<?php foreach ( $sizes as $k => $z ) : list( $size, $how ) = explode( '|', $k ); ?>
	<tr><td><?php echo esc_html( $size ); ?></td><td class="n"><?php echo (int) $z['n']; ?></td><td><?php echo esc_html( $how ); ?></td><td class="n"><?php echo (int) $z['printed']; ?></td></tr>
	<?php endforeach; ?>
	</tbody>
</table>
</div>
<div>
<h2>Website visitors</h2>
<table class="widefat striped">
	<thead><tr><th>Page</th><th class="n">Visitors</th><th class="n">Views</th></tr></thead>
	<tbody>
	<?php if ( ! $pages ) : ?><tr><td colspan="3"><?php echo $dash; // phpcs:ignore ?></td></tr><?php endif; ?>
	<?php foreach ( $pages as $p ) : ?>
	<tr><td><?php echo esc_html( $p->path ); ?></td><td class="n"><?php echo (int) $p->v; ?></td><td class="n"><?php echo (int) $p->n; ?></td></tr>
	<?php endforeach; ?>
	</tbody>
</table>
<table class="widefat striped" style="margin-top:10px">
	<thead><tr><th>Where they came from</th><th class="n">Visitors</th></tr></thead>
	<tbody>
	<?php if ( ! $refs ) : ?><tr><td colspan="2"><?php echo $dash; // phpcs:ignore ?></td></tr><?php endif; ?>
	<?php foreach ( $refs as $r ) : ?>
	<tr><td><?php echo esc_html( $r->ref ); ?></td><td class="n"><?php echo (int) $r->v; ?></td></tr>
	<?php endforeach; ?>
	</tbody>
</table>
</div>
</div>

<h2>Community tickets</h2>
<table class="widefat striped">
	<thead><tr><th>Game</th><th class="n">Requests</th><th class="n">Approved</th><th class="n">Turned down</th><th class="n">Waiting</th></tr></thead>
	<tbody>
	<?php if ( ! $comm ) : ?><tr><td colspan="5"><?php echo $dash; // phpcs:ignore ?></td></tr><?php endif; ?>
	<?php foreach ( $comm as $game => $c ) : ?>
	<tr><td><?php echo esc_html( $game ); ?></td><td class="n"><?php echo (int) $c['req']; ?></td><td class="n"><?php echo (int) $c['ok']; ?></td><td class="n"><?php echo (int) $c['no']; ?></td><td class="n"><?php echo (int) $c['wait']; ?></td></tr>
	<?php endforeach; ?>
	</tbody>
</table>
</div>
	<?php
}
