<?php
/**
 * Doc links.
 *
 * A short address to print on a sheet, a poster or the bottom of an
 * email -- /doc/home-shirt -- which counts the click and then hands the
 * visitor straight on to the real page. They never see the doc link.
 *
 * Three numbers come out of it. How many opened the link. How many
 * actually arrived at the other end, which is the interesting one,
 * because a QR scanned on a cold terrace and abandoned before the page
 * loads is the difference between the two. And, once the shop takes
 * payments through Square, how many went on to buy.
 *
 * The links are rows in an option rather than lines in this file, so
 * anyone can add one from the screen under Doc links without a deploy.
 *
 * @package eastwood-fwp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** How long a visitor carries the marker that says where they came from. */
function ew_doc_days() {
	/**
	 * Ninety days, not thirty. A programme handed out in August should
	 * still be credited with the shirt somebody buys in October.
	 */
	return 90;
}

function ew_doc_links() {
	$rows = get_option( 'ew_doc_links', null );
	if ( ! is_array( $rows ) ) {
		// The first one, because it is the one Ben asked for.
		$rows = array(
			'home-shirt' => array(
				'dest'  => '/shop/home-shirt/',
				'note'  => 'Home shirt',
				'clicks'  => 0,
				'reached' => 0,
				'sales'   => 0,
				'revenue' => 0,
				'last'    => 0,
			),
		);
		update_option( 'ew_doc_links', $rows, false );
	}
	return $rows;
}

function ew_doc_save( $rows ) {
	update_option( 'ew_doc_links', $rows, false );
}

/** A row with every key present, whatever shape it was saved in. */
function ew_doc_row( $row ) {
	return array_merge(
		array( 'dest' => '', 'note' => '', 'clicks' => 0, 'reached' => 0,
			'sales' => 0, 'revenue' => 0, 'last' => 0 ),
		(array) $row
	);
}

/** Where a link points, as an address a browser can follow. */
function ew_doc_dest_url( $dest ) {
	$dest = trim( (string) $dest );
	if ( '' === $dest ) {
		return home_url( '/' );
	}
	if ( 0 === strpos( $dest, 'http://' ) || 0 === strpos( $dest, 'https://' ) ) {
		return $dest;
	}
	return home_url( '/' . ltrim( $dest, '/' ) );
}

/**
 * The redirect.
 *
 * Read straight off the request rather than through a rewrite rule, so
 * it works the moment the plugin updates and never depends on anyone
 * remembering to flush permalinks. A slug that is not on the list is
 * left alone and WordPress serves its own 404, which is the right
 * answer for a link that does not exist.
 */
add_action( 'init', function () {
	if ( is_admin() ) {
		return;
	}
	$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore
	if ( ! preg_match( '#^/doc/([a-z0-9][a-z0-9\-_]*)/?$#i', $path, $m ) ) {
		return;
	}

	$slug = sanitize_key( $m[1] );
	$rows = ew_doc_links();
	if ( ! isset( $rows[ $slug ] ) ) {
		return;
	}

	$row              = ew_doc_row( $rows[ $slug ] );
	$row['clicks']    = (int) $row['clicks'] + 1;
	$row['last']      = time();
	$rows[ $slug ]    = $row;
	ew_doc_save( $rows );

	// The marker. It holds the slug and nothing else -- no name, no
	// address, nothing that says who this is.
	setcookie(
		'ew_doc',
		$slug,
		time() + ( ew_doc_days() * DAY_IN_SECONDS ),
		COOKIEPATH ? COOKIEPATH : '/',
		COOKIE_DOMAIN,
		is_ssl(),
		false
	);

	/*
	 * The slug travels on the address as well as in the cookie.
	 *
	 * Set-Cookie does not survive the way out: the host rewrites the
	 * caching headers on this response and the cookie goes with them,
	 * which was tested live and came back empty every time. So the
	 * landing page is told who sent them, and the first thing the page
	 * does is move that into a cookie and wipe it off the address bar.
	 * It is on screen for less time than the page takes to paint.
	 */
	$dest = add_query_arg( 'ewdoc', $slug, ew_doc_dest_url( $row['dest'] ) );

	nocache_headers();
	wp_redirect( $dest, 302 );

	/*
	 * The host puts s-maxage=2592000 on everything, including this.
	 * Left alone, the edge would answer the redirect for a month
	 * without ever reaching here -- the click counted once and then
	 * never again -- and Cloudflare strips Set-Cookie off anything it
	 * might cache, which takes the marker with it. These say no, in
	 * the three spellings Cloudflare reads, and they go after
	 * wp_redirect so that nothing else gets the last word.
	 */
	header_remove( 'Pragma' );
	header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true );
	header( 'CDN-Cache-Control: no-store', true );
	header( 'Cloudflare-CDN-Cache-Control: no-store', true );
	exit;
}, 1 );

/**
 * Arrivals.
 *
 * Counted from the browser rather than from the page, because the site
 * sits behind a cache: a visitor can be served a page that never runs
 * any of this. A few lines of script on the page do not get cached away,
 * and they only fire once per visitor per link.
 */
add_action( 'rest_api_init', function () {
	register_rest_route( 'eastwood/v1', '/doc-reached', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) {
			$slug = sanitize_key( (string) $req->get_param( 'slug' ) );
			$seen = isset( $_COOKIE['ew_doc'] ) ? sanitize_key( wp_unslash( $_COOKIE['ew_doc'] ) ) : ''; // phpcs:ignore
			if ( '' === $slug || $slug !== $seen ) {
				return array( 'ok' => false );
			}
			$rows = ew_doc_links();
			if ( ! isset( $rows[ $slug ] ) ) {
				return array( 'ok' => false );
			}
			$row             = ew_doc_row( $rows[ $slug ] );
			$row['reached']  = (int) $row['reached'] + 1;
			$rows[ $slug ]   = $row;
			ew_doc_save( $rows );
			return array( 'ok' => true );
		},
	) );
} );

add_action( 'wp_footer', function () {
	if ( is_admin() ) {
		return;
	}
	$url = esc_url_raw( rest_url( 'eastwood/v1/doc-reached' ) );
	?>
<script id="ew-doc">
(function(){
 var get = function(n){
  var m = document.cookie.match('(^|; )' + n + '=([^;]*)');
  return m ? decodeURIComponent(m[2]) : '';
 };
 /* Arriving on a doc link: take the slug off the address, keep it in
    a cookie, and tidy the address bar before anyone reads it. */
 var here = new URL(window.location.href);
 var sent = here.searchParams.get('ewdoc');
 if (sent) {
  sent = sent.replace(/[^a-z0-9_-]/gi, '').toLowerCase();
  if (sent) {
   document.cookie = 'ew_doc=' + encodeURIComponent(sent) +
     ';path=/;max-age=' + (60 * 60 * 24 * <?php echo (int) ew_doc_days(); ?>);
  }
  here.searchParams.delete('ewdoc');
  history.replaceState(null, '', here.pathname + (here.search || '') + here.hash);
 }

 var slug = sent || get('ew_doc');
 if (!slug || get('ew_doc_seen') === slug) { return; }
 /* Mark it first. A visitor who refreshes while the request is in
    flight should still only ever be one arrival. */
 document.cookie = 'ew_doc_seen=' + encodeURIComponent(slug) +
   ';path=/;max-age=' + (60 * 60 * 24 * <?php echo (int) ew_doc_days(); ?>);
 fetch(<?php echo wp_json_encode( $url ); ?>, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ slug: slug }),
  credentials: 'same-origin',
  keepalive: true
 }).catch(function(){});
})();
</script>
	<?php
} );

/* --- The screen someone else uses ---------------------------------- */

add_action( 'admin_menu', function () {
	add_menu_page(
		'Doc links', 'Doc links', 'edit_pages', 'ew-doc-links',
		'ew_doc_admin_page', 'dashicons-admin-links', 32
	);
} );

add_action( 'admin_post_ew_doc_save', function () {
	if ( ! current_user_can( 'edit_pages' ) ) {
		wp_die( 'Not allowed.' );
	}
	check_admin_referer( 'ew_doc_save' );

	$rows = ew_doc_links();

	$drop = isset( $_POST['drop'] ) ? sanitize_key( wp_unslash( $_POST['drop'] ) ) : '';
	if ( '' !== $drop ) {
		unset( $rows[ $drop ] );
		ew_doc_save( $rows );
		wp_safe_redirect( admin_url( 'admin.php?page=ew-doc-links&done=removed' ) );
		exit;
	}

	$slug = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
	$dest = isset( $_POST['dest'] ) ? trim( (string) wp_unslash( $_POST['dest'] ) ) : '';
	$note = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';

	if ( '' === $slug || '' === $dest ) {
		wp_safe_redirect( admin_url( 'admin.php?page=ew-doc-links&done=missing' ) );
		exit;
	}

	$row         = isset( $rows[ $slug ] ) ? ew_doc_row( $rows[ $slug ] ) : ew_doc_row( array() );
	$row['dest'] = $dest;
	$row['note'] = $note;
	$rows[ $slug ] = $row;
	ew_doc_save( $rows );

	wp_safe_redirect( admin_url( 'admin.php?page=ew-doc-links&done=saved' ) );
	exit;
} );

function ew_doc_admin_page() {
	$rows = ew_doc_links();
	$edit = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : ''; // phpcs:ignore
	$now  = ew_doc_row( isset( $rows[ $edit ] ) ? $rows[ $edit ] : array() );
	?>
<div class="wrap">
<h1>Doc links</h1>
<p style="max-width:760px;font-size:14px;line-height:1.6">
Short links for printed sheets, QR codes and emails. Someone opens
<code><?php echo esc_html( home_url( '/doc/home-shirt' ) ); ?></code>, the click is counted
here, and they land on the real page without noticing anything. A link added on this screen
works straight away.</p>

<?php if ( isset( $_GET['done'] ) ) : // phpcs:ignore ?>
<div class="notice notice-success is-dismissible"><p><?php
	echo 'missing' === $_GET['done'] // phpcs:ignore
		? 'A link needs both a name and somewhere to go.'
		: 'Saved.'; ?></p></div>
<?php endif; ?>

<table class="widefat striped" style="margin:18px 0">
<thead><tr>
	<th>Link</th><th>Goes to</th><th>Clicks</th><th>Reached it</th>
	<th>Bought</th><th>Taken</th><th>Last used</th><th></th>
</tr></thead>
<tbody>
<?php if ( ! $rows ) : ?>
<tr><td colspan="8">No links yet.</td></tr>
<?php endif; ?>
<?php foreach ( $rows as $slug => $raw ) : $row = ew_doc_row( $raw ); ?>
<tr>
	<td><code>/doc/<?php echo esc_html( $slug ); ?></code>
		<?php if ( '' !== $row['note'] ) : ?>
		<div style="color:#8c8f94;font-size:12px"><?php echo esc_html( $row['note'] ); ?></div>
		<?php endif; ?>
	</td>
	<td><?php echo esc_html( $row['dest'] ); ?></td>
	<td><strong style="font-size:16px"><?php echo (int) $row['clicks']; ?></strong></td>
	<td><strong style="font-size:16px"><?php echo (int) $row['reached']; ?></strong>
		<?php if ( $row['clicks'] ) : ?>
		<div style="color:#8c8f94;font-size:12px"><?php
			echo (int) round( 100 * $row['reached'] / max( 1, (int) $row['clicks'] ) ); ?>%</div>
		<?php endif; ?>
	</td>
	<td title="Waiting on the shop moving to Square">&mdash;</td>
	<td title="Waiting on the shop moving to Square">&mdash;</td>
	<td><?php echo $row['last']
		? esc_html( human_time_diff( (int) $row['last'] ) . ' ago' )
		: '&mdash;'; // phpcs:ignore ?></td>
	<td>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=ew-doc-links&edit=' . $slug ) ); ?>">Edit</a>
		&nbsp;
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			style="display:inline" onsubmit="return confirm('Remove this link?')">
			<?php wp_nonce_field( 'ew_doc_save' ); ?>
			<input type="hidden" name="action" value="ew_doc_save">
			<input type="hidden" name="drop" value="<?php echo esc_attr( $slug ); ?>">
			<button type="submit" class="button-link" style="color:#b32d2e">Remove</button>
		</form>
	</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<h2><?php echo $edit ? 'Edit this link' : 'Add a link'; ?></h2>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
<?php wp_nonce_field( 'ew_doc_save' ); ?>
<input type="hidden" name="action" value="ew_doc_save">
<table class="form-table"><tbody>
<tr>
	<th scope="row"><label for="ew-doc-slug">The link</label></th>
	<td><?php echo esc_html( home_url( '/doc/' ) ); ?><input id="ew-doc-slug" name="slug" type="text"
		value="<?php echo esc_attr( $edit ); ?>" class="regular-text" style="width:220px"
		<?php echo $edit ? 'readonly' : ''; ?>>
	<p class="description">Letters, numbers and dashes. This is what gets printed.</p></td>
</tr>
<tr>
	<th scope="row"><label for="ew-doc-dest">Goes to</label></th>
	<td><input id="ew-doc-dest" name="dest" type="text" class="regular-text"
		value="<?php echo esc_attr( $now['dest'] ); ?>" placeholder="/shop/home-shirt/">
	<p class="description">A path on this site, or a full address somewhere else.</p></td>
</tr>
<tr>
	<th scope="row"><label for="ew-doc-note">What it is on</label></th>
	<td><input id="ew-doc-note" name="note" type="text" class="regular-text"
		value="<?php echo esc_attr( $now['note'] ); ?>" placeholder="Match programme">
	<p class="description">Just for you, so the list reads clearly later.</p></td>
</tr>
</tbody></table>
<?php submit_button( $edit ? 'Save link' : 'Add link' ); ?>
</form>

<p style="color:#50575e;font-size:13px;max-width:760px;line-height:1.6">
The marker a visitor carries lasts <?php echo (int) ew_doc_days(); ?> days, so a scan on match
day that turns into a purchase weeks later is still credited to the sheet it came from. It holds
only which link they came through &mdash; no name and no address. Bought and Taken stay empty
until the shop takes payments through Square.</p>
</div>
	<?php
}
