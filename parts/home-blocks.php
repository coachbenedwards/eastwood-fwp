<?php
/**
 * The front page, as editable blocks on a real WordPress page.
 *
 * The first version of this was a route — a URL the plugin answered
 * directly, with the layout and every word of it written in PHP. That was
 * wrong, and the live front page has the same fault: the most important
 * page on the site did not appear in Pages and could not be edited by
 * anybody who runs the club.
 *
 * So it is a page now. It is in the Pages list, it uses a Full width
 * template the plugin registers, and its content is four shortcodes the
 * club can move, retitle, or take out. The parts that must stay live —
 * fixtures, results, the league table, the news — come from the feed and
 * from this site's own posts. The parts that are words — the section
 * headings, the six Discover tiles and where they point — are written in
 * the editor, not in here.
 *
 *   [eastwood_home_hero]
 *   [eastwood_home_fixtures title="Fixtures & Results"]
 *   [eastwood_home_discover title="Discover Eastwood"]
 *   Matchday | /eastwood-tickets/ | Tickets, prices and hospitality
 *   [/eastwood_home_discover]
 *   [eastwood_home_news title="Latest News" count="4"]
 *
 * The installer writes that content once. After anybody edits the page by
 * hand it is left alone for good — same rule as every other page the
 * plugin owns.
 */

const EW_HOME_TPL = 'ew-fullwidth.php';

/* ------------------------------------------------------------------
 * A full width page template, offered in the editor's Template menu.
 *
 * The theme's page.php puts content in a 900px column inside the editor's
 * own wrapper, which no front page can live in: the hero has to reach the
 * edges of the window and the dark band has to span it. Registering the
 * template here rather than adding a file to the theme means the choice
 * shows up in the page editor, where somebody can see it and change it.
 * ------------------------------------------------------------------ */

add_filter( 'theme_page_templates', function ( $templates ) {
	$templates[ EW_HOME_TPL ] = 'Full width — no margins';
	return $templates;
} );

add_filter( 'template_include', function ( $template ) {
	if ( ! is_singular( 'page' ) ) {
		return $template;
	}
	if ( get_page_template_slug( get_queried_object_id() ) !== EW_HOME_TPL ) {
		return $template;
	}
	$file = __DIR__ . '/tpl-fullwidth.php';
	return file_exists( $file ) ? $file : $template;
}, 20 );

/**
 * A page can be marked "not for search engines" by the installer. Used
 * while a design is being looked at, so a second front page does not
 * start competing with the real one in Google.
 */
add_action( 'wp_head', function () {
	if ( is_singular( 'page' ) && get_post_meta( get_queried_object_id(), '_ew_noindex', true ) ) {
		echo '<meta name="robots" content="noindex,nofollow">' . "\n";
	}
}, 1 );

/* ------------------------------------------------------------------
 * Assets, printed only on a page that actually uses one of these blocks.
 * ------------------------------------------------------------------ */

function ew_home_blocks_present() {
	$post = get_post();
	if ( ! $post ) {
		return false;
	}
	foreach ( array( 'eastwood_home_hero', 'eastwood_home_fixtures',
		'eastwood_home_discover', 'eastwood_home_news' ) as $tag ) {
		if ( has_shortcode( (string) $post->post_content, $tag ) ) {
			return true;
		}
	}
	return false;
}

add_action( 'wp_head', function () {
	if ( ! ew_home_blocks_present() ) {
		return;
	}
	echo '<style id="ew-home-blocks">' . ew_hb_css() . '</style>'; // phpcs:ignore
}, 20 );

add_action( 'wp_footer', function () {
	if ( ! ew_home_blocks_present() ) {
		return;
	}
	echo '<script id="ew-home-blocks-js">' . ew_hb_js() . '</script>'; // phpcs:ignore
}, 20 );

/* ------------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------------ */

/**
 * Lines typed into the editor come back with wpautop's paragraphs and
 * line breaks wrapped round them. Strip all of that back to plain lines
 * before reading them as "Label | /url/ | Description".
 */
function ew_hb_lines( $content ) {
	$content = str_ireplace( array( '<br>', '<br/>', '<br />', '</p>', '</div>' ), "\n", (string) $content );
	$content = wp_strip_all_tags( $content );
	$content = html_entity_decode( $content, ENT_QUOTES, 'UTF-8' );

	$out = array();
	foreach ( preg_split( '/\r\n|\r|\n/', $content ) as $line ) {
		$line = trim( $line );
		if ( '' !== $line ) {
			$out[] = $line;
		}
	}
	return $out;
}

/** The post used by the hero, so the news grid below does not repeat it. */
function ew_hb_hero_id( $set = null ) {
	static $id = 0;
	if ( null !== $set ) {
		$id = (int) $set;
	}
	return $id;
}

/** One fixture card, in the captured design, from live data. */
function ew_hb_card( $m, $done = false ) {
	$home  = (int) ( $m['home-team']['id'] ?? 0 ) === (int) EW_TEAM;
	$opp   = $home ? ( $m['away-team'] ?? array() ) : ( $m['home-team'] ?? array() );
	$ts    = strtotime( $m['date'] ?? '' );
	$venue = $home ? 'Coronation Park' : ( $m['venue'] ?? 'Away' );

	// Scores come back home-first. A card that names only the opponent and
	// then prints "3 - 6" is read as the opponent's score first, which is
	// wrong half the time. Show it from Eastwood's side.
	$us_goals  = (int) ( $home ? ( $m['home-team']['score'] ?? 0 ) : ( $m['away-team']['score'] ?? 0 ) );
	$opp_goals = (int) ( $home ? ( $m['away-team']['score'] ?? 0 ) : ( $m['home-team']['score'] ?? 0 ) );
	$res       = $us_goals === $opp_goals ? 'D' : ( $us_goals > $opp_goals ? 'W' : 'L' );

	ob_start();
	?>
<li class="ewp-slide">
	<a class="ewp-card" href="<?php echo esc_url( home_url( '/match/' . (int) ( $m['id'] ?? 0 ) . '/' ) ); ?>">
		<span class="ewp-cardTop">
			<span class="ewp-when">
				<b><?php echo esc_html( $ts ? date_i18n( 'D j M', $ts ) : '' ); ?></b>
				<?php if ( $done ) : ?>
				<i>FT</i>
				<?php else : ?>
				<i><?php echo esc_html( ! empty( $m['time'] ) ? substr( $m['time'], 0, 5 ) : '' ); ?></i>
				<?php endif; ?>
			</span>
			<span class="ewp-ha" title="<?php echo $home ? 'Home' : 'Away'; ?>"><?php echo $home ? 'H' : 'A'; ?></span>
		</span>
		<span class="ewp-cardBody">
			<img src="<?php echo esc_url( ew_home_badge( $opp['id'] ?? 0 ) ); ?>" alt=""
				onerror="this.style.visibility='hidden'">
			<span class="ewp-cardText">
				<span class="ewp-kicker">First Team</span>
				<b><?php echo esc_html( $opp['name'] ?? '' ); ?></b>
				<?php if ( $done ) : ?>
				<span class="ewp-score is-<?php echo esc_attr( $res ); ?>"><i><?php echo esc_html( $res ); ?></i>
					Eastwood <?php echo esc_html( $us_goals . '–' . $opp_goals ); ?></span>
				<?php else : ?>
				<span class="ewp-venue" title="<?php echo esc_attr( $venue ); ?>"><?php echo esc_html( $venue ); ?></span>
				<?php endif; ?>
			</span>
		</span>
	</a>
</li>
	<?php
	return ob_get_clean();
}

/**
 * A horizontal rail of fixture cards.
 *
 * Deliberately NOT built on the theme's splide classes. eastwood-ui.js
 * mounts every .splide on every page, and a carousel sitting inside a
 * hidden tab measures zero width, so it is written off as "not needed"
 * and never mounts — the moment somebody clicked Results they would get a
 * flex row wider than the page. This rail scrolls natively at every width
 * and owns its own arrows.
 */
function ew_hb_rail( $matches, $done, $empty ) {
	if ( ! $matches ) {
		return '<p class="ewp-empty">' . esc_html( $empty ) . '</p>';
	}
	$out  = '<div class="ewp-rail">';
	$out .= '<button type="button" class="ewp-arrow ewp-arrowPrev" data-pv-dir="-1" aria-label="Previous">&#8249;</button>';
	$out .= '<ul class="ewp-railList">';
	foreach ( $matches as $m ) {
		$out .= ew_hb_card( $m, $done );
	}
	$out .= '</ul>';
	$out .= '<button type="button" class="ewp-arrow ewp-arrowNext" data-pv-dir="1" aria-label="Next">&#8250;</button>';
	$out .= '</div>';
	return $out;
}

/* ------------------------------------------------------------------
 * [eastwood_home_hero]
 *
 *   post="123"      pin a particular article instead of the latest
 *   kicker="..."    override the category label
 *   heading="..."   override the headline
 * ------------------------------------------------------------------ */

function ew_hb_hero_sc( $atts ) {
	$atts = shortcode_atts(
		array( 'post' => '', 'kicker' => '', 'heading' => '' ),
		$atts,
		'eastwood_home_hero'
	);

	$lead = null;
	if ( '' !== $atts['post'] ) {
		$lead = get_post( (int) $atts['post'] );
	}
	if ( ! $lead || 'publish' !== $lead->post_status ) {
		$latest = get_posts( array( 'numberposts' => 1 ) );
		$lead   = $latest ? $latest[0] : null;
	}
	if ( ! $lead ) {
		return '';
	}
	ew_hb_hero_id( $lead->ID );

	$cats   = get_the_category( $lead->ID );
	$kicker = '' !== $atts['kicker'] ? $atts['kicker'] : ( $cats ? $cats[0]->name : 'News' );
	$head   = '' !== $atts['heading'] ? $atts['heading'] : get_the_title( $lead );

	ob_start();
	?>
<section class="ewp ewp-hero">
	<a href="<?php echo esc_url( get_permalink( $lead ) ); ?>">
		<?php if ( has_post_thumbnail( $lead ) ) : ?>
		<img src="<?php echo esc_url( get_the_post_thumbnail_url( $lead, 'full' ) ); ?>" alt="">
		<?php endif; ?>
		<span class="ewp-heroText">
			<span class="ewp-heroKicker"><?php echo esc_html( $kicker ); ?></span>
			<b><?php echo esc_html( $head ); ?></b>
			<i><?php echo esc_html( human_time_diff( get_the_time( 'U', $lead ), current_time( 'timestamp' ) ) ); ?> ago</i>
		</span>
	</a>
</section>
	<?php
	return ob_get_clean();
}
add_shortcode( 'eastwood_home_hero', 'ew_hb_hero_sc' );

/* ------------------------------------------------------------------
 * [eastwood_home_fixtures title="..." count="8" open="fixtures"]
 * ------------------------------------------------------------------ */

function ew_hb_fixtures_sc( $atts ) {
	$atts = shortcode_atts(
		array( 'title' => 'Fixtures & Results', 'count' => '8', 'open' => 'fixtures' ),
		$atts,
		'eastwood_home_fixtures'
	);
	$n = max( 1, (int) $atts['count'] );

	$fx  = ew_fwp_fetch( 'fixtures-results' );
	$all = (array) ( $fx['fixtures-results']['matches'] ?? array() );

	$played = array_values( array_filter( $all, 'ew_home_played' ) );
	$todo   = array_values( array_filter( $all, function ( $m ) { return ! ew_home_played( $m ); } ) );
	usort( $played, function ( $a, $b ) { return strcmp( $b['date'], $a['date'] ); } );
	usort( $todo, function ( $a, $b ) { return strcmp( $a['date'], $b['date'] ); } );

	$lt   = ew_fwp_fetch( 'league-table' );
	$rows = (array) ( $lt['league-table']['teams'] ?? array() );

	$tabs = array( 'fixtures' => 'Fixtures', 'results' => 'Results', 'table' => 'Table' );
	$open = isset( $tabs[ $atts['open'] ] ) ? $atts['open'] : 'fixtures';

	ob_start();
	?>
<section class="ewp ewp-fixtures">
	<div class="ewp-wrap">
		<?php if ( '' !== $atts['title'] ) : ?>
		<h2 class="ewp-h2"><?php echo esc_html( $atts['title'] ); ?></h2>
		<?php endif; ?>
		<div class="ewp-tabs" role="tablist">
			<?php foreach ( $tabs as $key => $label ) : ?>
			<button type="button" data-pv="<?php echo esc_attr( $key ); ?>"
				aria-selected="<?php echo $key === $open ? 'true' : 'false'; ?>"><?php
				echo esc_html( $label ); ?></button>
			<?php endforeach; ?>
		</div>
	</div>

	<div class="ewp-panel" data-pv-panel="fixtures"<?php echo 'fixtures' === $open ? '' : ' hidden'; ?>>
		<?php echo ew_hb_rail( array_slice( $todo, 0, $n ), false, 'No fixtures published yet.' ); // phpcs:ignore ?>
	</div>

	<div class="ewp-panel" data-pv-panel="results"<?php echo 'results' === $open ? '' : ' hidden'; ?>>
		<?php echo ew_hb_rail( array_slice( $played, 0, $n ), true, 'No results yet this season.' ); // phpcs:ignore ?>
	</div>

	<div class="ewp-panel ewp-wrap" data-pv-panel="table"<?php echo 'table' === $open ? '' : ' hidden'; ?>>
		<?php if ( ! $rows ) : ?>
		<p class="ewp-empty">The league table is not available right now.</p>
		<?php else : ?>
		<table class="ewp-table">
			<thead><tr><th>#</th><th class="ewp-club">Club</th><th>P</th><th class="ewp-opt">W</th>
				<th class="ewp-opt">D</th><th class="ewp-opt">L</th><th>GD</th><th>Pts</th></tr></thead>
			<tbody>
			<?php foreach ( $rows as $t ) :
				$a  = (array) ( $t['all-matches'] ?? array() );
				$us = (int) ( $t['id'] ?? 0 ) === (int) EW_TEAM; ?>
				<tr<?php echo $us ? ' class="is-us"' : ''; ?>>
					<td><?php echo esc_html( $t['position'] ?? '' ); ?></td>
					<td class="ewp-club">
						<img src="<?php echo esc_url( ew_home_badge( $t['id'] ?? 0 ) ); ?>" alt=""
							onerror="this.style.visibility='hidden'"><?php echo esc_html( $t['name'] ?? '' ); ?></td>
					<td><?php echo esc_html( $a['played'] ?? '' ); ?></td>
					<td class="ewp-opt"><?php echo esc_html( $a['won'] ?? '' ); ?></td>
					<td class="ewp-opt"><?php echo esc_html( $a['drawn'] ?? '' ); ?></td>
					<td class="ewp-opt"><?php echo esc_html( $a['lost'] ?? '' ); ?></td>
					<td><?php echo esc_html( $a['goal-difference'] ?? '' ); ?></td>
					<td class="ewp-pts"><?php echo esc_html( $t['total-points'] ?? '' ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
	</div>
</section>
	<?php
	return ob_get_clean();
}
add_shortcode( 'eastwood_home_fixtures', 'ew_hb_fixtures_sc' );

/* ------------------------------------------------------------------
 * [eastwood_home_discover title="..."]
 * Label | /where-it-goes/ | One line underneath
 * [/eastwood_home_discover]
 * ------------------------------------------------------------------ */

function ew_hb_discover_sc( $atts, $content = '' ) {
	$atts = shortcode_atts( array( 'title' => 'Discover Eastwood' ), $atts, 'eastwood_home_discover' );

	$tiles = array();
	foreach ( ew_hb_lines( $content ) as $line ) {
		$parts = array_map( 'trim', explode( '|', $line ) );
		if ( '' === $parts[0] || ! isset( $parts[1] ) || '' === $parts[1] ) {
			continue;
		}
		$url = $parts[1];
		if ( 0 !== strpos( $url, 'http' ) ) {
			$url = home_url( '/' . ltrim( $url, '/' ) );
		}
		$tiles[] = array( $parts[0], $url, isset( $parts[2] ) ? $parts[2] : '' );
	}

	if ( ! $tiles ) {
		return '';
	}

	ob_start();
	?>
<section class="ewp ewp-discover">
	<div class="ewp-wrap">
		<?php if ( '' !== $atts['title'] ) : ?>
		<h2 class="ewp-h2 is-light"><?php echo esc_html( $atts['title'] ); ?></h2>
		<?php endif; ?>
		<div class="ewp-discoverGrid">
			<?php foreach ( $tiles as $tile ) : ?>
			<a href="<?php echo esc_url( $tile[1] ); ?>">
				<b><?php echo esc_html( $tile[0] ); ?></b>
				<?php if ( '' !== $tile[2] ) : ?><i><?php echo esc_html( $tile[2] ); ?></i><?php endif; ?>
				<span aria-hidden="true">&rarr;</span>
			</a>
			<?php endforeach; ?>
		</div>
	</div>
</section>
	<?php
	return ob_get_clean();
}
add_shortcode( 'eastwood_home_discover', 'ew_hb_discover_sc' );

/* ------------------------------------------------------------------
 * [eastwood_home_news title="..." count="4" link="/eastwood-news/"]
 * ------------------------------------------------------------------ */

function ew_hb_news_sc( $atts ) {
	$atts = shortcode_atts(
		array(
			'title'     => 'Latest News',
			'count'     => '4',
			'link'      => '/eastwood-news/',
			'link_text' => 'View all news',
		),
		$atts,
		'eastwood_home_news'
	);

	$skip  = ew_hb_hero_id();
	$posts = get_posts( array(
		'numberposts' => max( 1, (int) $atts['count'] ),
		'exclude'     => $skip ? array( $skip ) : array(),
	) );
	if ( ! $posts ) {
		return '';
	}

	ob_start();
	?>
<section class="ewp ewp-news">
	<div class="ewp-wrap">
		<h2 class="ewp-h2"><?php echo esc_html( $atts['title'] ); ?>
			<?php if ( '' !== $atts['link'] ) : ?>
			<a class="ewp-all" href="<?php echo esc_url( home_url( '/' . ltrim( $atts['link'], '/' ) ) ); ?>"><?php
				echo esc_html( $atts['link_text'] ); ?> &rarr;</a>
			<?php endif; ?>
		</h2>
		<div class="ewp-newsGrid">
			<?php foreach ( $posts as $p ) : ?>
			<a class="ewp-newsCard" href="<?php echo esc_url( get_permalink( $p ) ); ?>">
				<span class="ewp-newsImg">
					<?php if ( has_post_thumbnail( $p ) ) : ?>
					<img src="<?php echo esc_url( get_the_post_thumbnail_url( $p, 'large' ) ); ?>" alt="">
					<?php endif; ?>
				</span>
				<span class="ewp-newsText">
					<b><?php echo esc_html( get_the_title( $p ) ); ?></b>
					<i><?php
						$c = get_the_category( $p->ID );
						echo esc_html( $c ? $c[0]->name : '' ); ?> ·
						<?php echo esc_html( human_time_diff( get_the_time( 'U', $p ), current_time( 'timestamp' ) ) ); ?> ago</i>
				</span>
			</a>
			<?php endforeach; ?>
		</div>
	</div>
</section>
	<?php
	return ob_get_clean();
}
add_shortcode( 'eastwood_home_news', 'ew_hb_news_sc' );

/* ------------------------------------------------------------------ */

function ew_hb_css() {
	return '
.ewp{font-family:"Instrument Sans",system-ui,sans-serif;color:#111}
.ewp *{box-sizing:border-box}
.ewp-wrap{max-width:1180px;margin:0 auto;padding:0 16px}
.ewp-h2{font-family:Anton,"Instrument Sans",sans-serif;font-size:26px;letter-spacing:.04em;
 text-transform:uppercase;margin:0 0 18px;display:flex;align-items:baseline;gap:14px;color:#111}
.ewp-h2.is-light{color:#fff}
.ewp-all{margin-left:auto;font-family:"Instrument Sans",sans-serif;font-size:13px;letter-spacing:0;
 text-transform:none;font-weight:600;color:#CC0000;text-decoration:none}
.ewp-all:hover{text-decoration:underline}

.ewp-hero{position:relative;background:#111}
.ewp-hero a{position:relative;display:block;min-height:520px;text-decoration:none;overflow:hidden}
.ewp-hero img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
.ewp-hero a::after{content:"";position:absolute;inset:0;
 background:linear-gradient(to top,rgba(0,0,0,.85) 0%,rgba(0,0,0,.25) 55%,rgba(0,0,0,0) 85%)}
.ewp-heroText{position:absolute;left:0;right:0;bottom:0;z-index:2;display:block;
 max-width:1180px;margin:0 auto;padding:0 16px 46px}
.ewp-heroKicker{display:inline-block;font-family:Anton,sans-serif;font-size:11px;letter-spacing:.14em;
 text-transform:uppercase;color:#fff;background:#CC0000;padding:4px 10px;margin:0 0 14px}
.ewp-heroText b{display:block;font-family:Anton,"Instrument Sans",sans-serif;font-size:52px;
 line-height:1.02;color:#fff;margin:0 0 10px;max-width:16ch;text-transform:uppercase}
.ewp-heroText i{font-style:normal;font-size:13px;color:#d6d6d6}

.ewp-fixtures{background:#f3f3f3;padding:44px 0 48px}
.ewp-tabs{display:flex;gap:0;border-bottom:2px solid #e0e0e0;margin:0 0 26px}
.ewp-tabs button{appearance:none;background:none;border:0;border-bottom:3px solid transparent;
 margin-bottom:-2px;padding:10px 20px 12px;font-family:Anton,sans-serif;font-size:16px;
 letter-spacing:.06em;text-transform:uppercase;color:#8a8a8a;cursor:pointer}
.ewp-tabs button[aria-selected="true"]{color:#CC0000;border-bottom-color:#CC0000}
.ewp-panel[hidden]{display:none}
.ewp-empty{max-width:1180px;margin:0 auto;padding:30px 16px;color:#6b6b6b;font-size:15px}

.ewp-rail{position:relative;max-width:1180px;margin:0 auto;padding:0 16px}
.ewp-railList{display:flex;gap:10px;list-style:none;margin:0;padding:0 0 4px;
 overflow-x:auto;scroll-behavior:smooth;scroll-snap-type:x proximity;
 scrollbar-width:none;-ms-overflow-style:none}
.ewp-railList::-webkit-scrollbar{display:none}
.ewp-slide{flex:0 0 272px;max-width:272px;scroll-snap-align:start}
.ewp-arrow{position:absolute;top:50%;transform:translateY(-50%);z-index:3;
 width:36px;height:36px;border:0;border-radius:50%;background:rgba(0,0,0,.72);color:#fff;
 font-size:20px;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center;
 transition:opacity .15s,background .15s}
.ewp-arrow:hover{background:rgba(0,0,0,.88)}
.ewp-arrow[disabled]{opacity:0;pointer-events:none}
.ewp-arrowPrev{left:2px}
.ewp-arrowNext{right:2px}
@media(max-width:820px){.ewp-arrow{display:none}}
.ewp-card{display:block;background:#fff;border-radius:4px;overflow:hidden;text-decoration:none;
 color:#111;height:100%;border-bottom:3px solid transparent;transition:border-color .25s,box-shadow .2s}
.ewp-card:hover{border-bottom-color:#CC0000;box-shadow:0 3px 14px rgba(0,0,0,.09)}
.ewp-cardTop{display:flex;align-items:center;justify-content:space-between;
 padding:9px 14px;background:rgba(0,0,0,.03)}
.ewp-when b{font-family:Anton,sans-serif;font-size:14px;letter-spacing:.03em}
.ewp-when i{font-style:normal;font-size:13px;color:#6b6b6b;margin-left:7px}
.ewp-ha{width:18px;height:18px;border-radius:50%;background:#CC0000;color:#fff;font-size:10px;
 font-weight:700;display:flex;align-items:center;justify-content:center;flex:none}
.ewp-cardBody{display:flex;align-items:center;gap:13px;padding:15px 14px 17px}
.ewp-cardBody img{width:44px;height:44px;object-fit:contain;flex:none}
.ewp-cardText{min-width:0}
.ewp-kicker{display:block;font-size:10px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;
 color:#9a9aa3;margin:0 0 3px}
.ewp-cardText b{display:block;font-family:Anton,"Instrument Sans",sans-serif;font-size:19px;
 line-height:1.15;margin:0 0 3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ewp-venue{display:block;font-size:12px;color:#8a8a8a;
 overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ewp-score{display:flex;align-items:center;gap:7px;font-family:Anton,sans-serif;font-size:15px;
 letter-spacing:.02em;color:#444;white-space:nowrap}
.ewp-score i{font-style:normal;width:17px;height:17px;border-radius:3px;font-size:11px;
 display:flex;align-items:center;justify-content:center;color:#fff;background:#9a9aa3;flex:none}
.ewp-score.is-W i{background:#CC0000}
.ewp-score.is-L i{background:#111}

.ewp-table{width:100%;border-collapse:collapse;background:#fff;border-radius:4px;overflow:hidden}
.ewp-table th{background:#ececec;font-family:Anton,sans-serif;font-size:11px;letter-spacing:.07em;
 text-transform:uppercase;color:#6b6b6b;font-weight:400;padding:11px 8px;text-align:center}
.ewp-table th.ewp-club,.ewp-table td.ewp-club{text-align:left}
.ewp .ewp-table td,.ewp .ewp-table th{border:0 !important}
.ewp .ewp-table tbody td{border-top:1px solid #efefef !important;padding:10px 8px;text-align:center;font-size:14px}
.ewp-table td.ewp-club{display:flex;align-items:center;gap:9px;font-weight:600}
.ewp-table td.ewp-club img{width:24px;height:24px;object-fit:contain}
.ewp-table td.ewp-pts{font-weight:700}
.ewp-table tr.is-us td{color:#CC0000}

.ewp-discover{background:#111;padding:46px 0 52px}
.ewp-discoverGrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:10px}
.ewp-discoverGrid a{display:block;position:relative;background:#17171a;border-radius:4px;
 padding:22px 46px 22px 22px;text-decoration:none;transition:background .15s}
.ewp-discoverGrid a:hover{background:#1f1f24}
.ewp-discoverGrid b{display:block;font-family:Anton,"Instrument Sans",sans-serif;font-size:21px;
 letter-spacing:.03em;text-transform:uppercase;color:#fff;margin:0 0 5px}
.ewp-discoverGrid i{font-style:normal;font-size:13px;color:#9a9aa3}
.ewp-discoverGrid span{position:absolute;right:20px;top:24px;color:#CC0000;font-size:19px}

.ewp-news{background:#f3f3f3;padding:44px 0 56px}
.ewp-newsGrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:14px}
.ewp-newsCard{display:block;background:#fff;border-radius:4px;overflow:hidden;text-decoration:none;color:#111;
 transition:box-shadow .2s,transform .2s}
.ewp-newsCard:hover{box-shadow:0 3px 14px rgba(0,0,0,.1);transform:translateY(-2px)}
.ewp-newsImg{display:block;aspect-ratio:16/10;background:#ddd;overflow:hidden}
.ewp-newsImg img{width:100%;height:100%;object-fit:cover;display:block}
.ewp-newsText{display:block;padding:14px 15px 17px}
.ewp-newsText b{display:block;font-size:16px;line-height:1.3;margin:0 0 6px}
.ewp-newsText i{font-style:normal;font-size:12px;color:#8a8a8a}

/* The editor wraps loose text in paragraphs. Blocks sit flush; stray empty
   paragraphs between them would otherwise open white gaps in the stack. */
.ew-full > p:empty{display:none}
.ew-full > p{max-width:1180px;margin:0 auto;padding:18px 16px;font-size:15px;color:#444}

@media(max-width:820px){
 .ewp-hero a{min-height:380px}
 .ewp-heroText b{font-size:30px;max-width:none}
 .ewp-h2{font-size:21px}
 .ewp-fixtures,.ewp-news{padding:32px 0 36px}
 .ewp-discover{padding:34px 0 38px}
 .ewp-slide{flex:0 0 240px;max-width:240px}
 .ewp-table th,.ewp .ewp-table tbody td{padding:9px 5px;font-size:13px}
 .ewp-table td.ewp-club img{width:20px;height:20px}
}
@media(max-width:560px){
 .ewp-table th.ewp-opt,.ewp-table td.ewp-opt{display:none}
}';
}

function ew_hb_js() {
	return <<<'JS'
(function(){
 var roots=[].slice.call(document.querySelectorAll('.ewp-fixtures'));
 roots.forEach(function(root){
  var rails=[].slice.call(root.querySelectorAll('.ewp-rail'));

  function sync(rail){
   var list=rail.querySelector('.ewp-railList');
   var max=list.scrollWidth-list.clientWidth;
   [].forEach.call(rail.querySelectorAll('.ewp-arrow'),function(btn){
    var dir=+btn.getAttribute('data-pv-dir');
    if(max<=1){btn.setAttribute('disabled','');return;}
    var atEnd = dir>0 ? (list.scrollLeft>=max-1) : (list.scrollLeft<=1);
    if(atEnd){btn.setAttribute('disabled','');}else{btn.removeAttribute('disabled');}
   });
  }

  rails.forEach(function(rail){
   var list=rail.querySelector('.ewp-railList');
   [].forEach.call(rail.querySelectorAll('.ewp-arrow'),function(btn){
    btn.addEventListener('click',function(){
     var card=list.querySelector('.ewp-slide');
     var step=(card?card.getBoundingClientRect().width:260)+10;
     list.scrollLeft += step*2*(+btn.getAttribute('data-pv-dir'));
    });
   });
   list.addEventListener('scroll',function(){sync(rail);});
   sync(rail);
  });
  window.addEventListener('resize',function(){rails.forEach(sync);});

  var tabs=[].slice.call(root.querySelectorAll('.ewp-tabs button'));
  var panels=[].slice.call(root.querySelectorAll('[data-pv-panel]'));
  tabs.forEach(function(b){
   b.addEventListener('click',function(){
    var want=b.getAttribute('data-pv');
    tabs.forEach(function(t){t.setAttribute('aria-selected', t===b ? 'true':'false');});
    panels.forEach(function(p){
     if(p.getAttribute('data-pv-panel')===want){p.removeAttribute('hidden');}
     else{p.setAttribute('hidden','');}
    });
    rails.forEach(sync);
   });
  });
 });
})();
JS;
}

/* ------------------------------------------------------------------
 * The page itself, and what is written on it to begin with.
 * ------------------------------------------------------------------ */

function ew_home_preview_content() {
	return "[eastwood_home_hero]\n\n"
		. "[eastwood_home_fixtures title=\"Fixtures &amp; Results\" count=\"8\"]\n\n"
		. "[eastwood_home_discover title=\"Discover Eastwood\"]\n"
		. "Matchday | /eastwood-tickets/ | Tickets, prices and hospitality\n"
		. "The Venue | /eastwood-hospitality/ | Function room and bar hire\n"
		. "Pitch Hire | /pitch-hire/ | Floodlit 3G, all year round\n"
		. "Academy | /academy/ | Full-time football and education\n"
		. "Sponsorship | /sponsorship/ | Partner with the club\n"
		. "Eastwood TV | /eastwood-tv/ | Highlights and the documentary\n"
		. "[/eastwood_home_discover]\n\n"
		. "[eastwood_home_news title=\"Latest News\" count=\"4\"]";
}
