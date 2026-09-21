<?php
/**
 * Loaded by the parts loader in eastwood-fwp.php.
 */
/* ------------------------------------------------------------------
 * /home-preview/ — the Forest-shaped front page, as a proposal.
 *
 * Lives at its own URL so the live homepage does not move while this is
 * looked at. Nothing here touches the front page.
 *
 * Built from the captured replica's own components rather than
 * restoring the capture wholesale, because the capture still carries
 * Nottingham Forest's press-conference videos and a hero with no image.
 * Every class used below was checked against the pruned stylesheet
 * first; anything that did not survive the prune is styled here instead.
 *
 * The fixture cards are Forest's design, populated from the live feed
 * rather than the hardcoded dates the capture shipped with.
 * ------------------------------------------------------------------ */

const EW_PREVIEW_SLUG  = 'home-preview';
const EW_PREVIEW_RULES = '1';

add_action( 'init', function () {
	add_rewrite_rule( '^' . EW_PREVIEW_SLUG . '/?$', 'index.php?ew_preview=1', 'top' );
	if ( get_option( 'ew_preview_rules' ) !== EW_PREVIEW_RULES ) {
		flush_rewrite_rules( false );
		update_option( 'ew_preview_rules', EW_PREVIEW_RULES );
	}
}, 23 );

add_filter( 'query_vars', function ( $vars ) {
	$vars[] = 'ew_preview';
	return $vars;
} );

/** One fixture card, in the captured design, from live data. */
function ew_pv_card( $m, $done = false ) {
	$home   = (int) ( $m['home-team']['id'] ?? 0 ) === (int) EW_TEAM;
	$opp    = $home ? ( $m['away-team'] ?? array() ) : ( $m['home-team'] ?? array() );
	$ts     = strtotime( $m['date'] ?? '' );
	$venue  = $home ? 'Coronation Park' : ( $m['venue'] ?? 'Away' );

	// Scores come back home-first. A card that names only the opponent and then
	// prints "3 - 6" is read as the opponent's score first, which is wrong half
	// the time. Show it from Eastwood's side, with the result spelled out.
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
			<span class="ewp-ha"><?php echo $home ? 'H' : 'A'; ?></span>
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
 * Deliberately NOT built on the theme's splide classes. eastwood-ui.js mounts
 * every .splide on every page, and a carousel sitting inside a hidden tab
 * measures zero width, so it is written off as "not needed" and never mounts —
 * the moment somebody clicks Results they would get a flex row wider than the
 * page. This rail scrolls natively at every width and owns its own arrows.
 */
function ew_pv_rail( $matches, $done, $empty ) {
	if ( ! $matches ) {
		return '<p class="ewp-empty">' . esc_html( $empty ) . '</p>';
	}
	$out  = '<div class="ewp-rail">';
	$out .= '<button type="button" class="ewp-arrow ewp-arrowPrev" data-pv-dir="-1" aria-label="Previous">&#8249;</button>';
	$out .= '<ul class="ewp-railList">';
	foreach ( $matches as $m ) {
		$out .= ew_pv_card( $m, $done );
	}
	$out .= '</ul>';
	$out .= '<button type="button" class="ewp-arrow ewp-arrowNext" data-pv-dir="1" aria-label="Next">&#8250;</button>';
	$out .= '</div>';
	return $out;
}

function ew_pv_markup() {
	$fx  = ew_fwp_fetch( 'fixtures-results' );
	$all = (array) ( $fx['fixtures-results']['matches'] ?? array() );

	$played = array_values( array_filter( $all, 'ew_home_played' ) );
	$todo   = array_values( array_filter( $all, function ( $m ) { return ! ew_home_played( $m ); } ) );
	usort( $played, function ( $a, $b ) { return strcmp( $b['date'], $a['date'] ); } );
	usort( $todo,   function ( $a, $b ) { return strcmp( $a['date'], $b['date'] ); } );

	$lt   = ew_fwp_fetch( 'league-table' );
	$rows = (array) ( $lt['league-table']['teams'] ?? array() );

	$posts = get_posts( array( 'numberposts' => 5 ) );
	$lead  = $posts ? array_shift( $posts ) : null;

	// Where DISCOVER points. Only sections that exist on this site.
	$discover = array(
		array( 'Matchday',    '/eastwood-tickets/',     'Tickets, prices and hospitality' ),
		array( 'The Venue',   '/eastwood-hospitality/', 'Function room and bar hire' ),
		array( 'Pitch Hire',  '/pitch-hire/',           'Floodlit 3G, all year round' ),
		array( 'Academy',     '/academy/',              'Full-time football and education' ),
		array( 'Sponsorship', '/sponsorship/',          'Partner with the club' ),
		array( 'Eastwood TV', '/eastwood-tv/',          'Highlights and the documentary' ),
	);

	ob_start();
	?>
<div class="ewp">

	<?php if ( $lead ) : ?>
	<section class="ewp-hero">
		<a href="<?php echo esc_url( get_permalink( $lead ) ); ?>">
			<?php if ( has_post_thumbnail( $lead ) ) : ?>
			<img src="<?php echo esc_url( get_the_post_thumbnail_url( $lead, 'full' ) ); ?>" alt="">
			<?php endif; ?>
			<span class="ewp-heroText">
				<span class="ewp-heroKicker"><?php
					$c = get_the_category( $lead->ID );
					echo esc_html( $c ? $c[0]->name : 'News' ); ?></span>
				<b><?php echo esc_html( get_the_title( $lead ) ); ?></b>
				<i><?php echo esc_html( human_time_diff( get_the_time( 'U', $lead ), current_time( 'timestamp' ) ) ); ?> ago</i>
			</span>
		</a>
	</section>
	<?php endif; ?>

	<section class="ewp-fixtures">
		<div class="ewp-wrap">
			<h2 class="ewp-h2">Fixtures &amp; Results</h2>
			<div class="ewp-tabs" role="tablist">
				<button type="button" data-pv="fixtures" aria-selected="true">Fixtures</button>
				<button type="button" data-pv="results" aria-selected="false">Results</button>
				<button type="button" data-pv="table" aria-selected="false">Table</button>
			</div>
		</div>

		<div class="ewp-panel" data-pv-panel="fixtures">
			<?php echo ew_pv_rail( array_slice( $todo, 0, 8 ), false, 'No fixtures published yet.' ); // phpcs:ignore ?>
		</div>

		<div class="ewp-panel" data-pv-panel="results" hidden>
			<?php echo ew_pv_rail( array_slice( $played, 0, 8 ), true, 'No results yet this season.' ); // phpcs:ignore ?>
		</div>

		<div class="ewp-panel ewp-wrap" data-pv-panel="table" hidden>
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
		</div>
	</section>

	<section class="ewp-discover">
		<div class="ewp-wrap">
			<h2 class="ewp-h2 is-light">Discover Eastwood</h2>
			<div class="ewp-discoverGrid">
				<?php foreach ( $discover as $d ) : ?>
				<a href="<?php echo esc_url( home_url( $d[1] ) ); ?>">
					<b><?php echo esc_html( $d[0] ); ?></b>
					<i><?php echo esc_html( $d[2] ); ?></i>
					<span aria-hidden="true">&rarr;</span>
				</a>
				<?php endforeach; ?>
			</div>
		</div>
	</section>

	<?php if ( $posts ) : ?>
	<section class="ewp-news">
		<div class="ewp-wrap">
			<h2 class="ewp-h2">Latest News
				<a class="ewp-all" href="<?php echo esc_url( home_url( '/eastwood-news/' ) ); ?>">View all news &rarr;</a></h2>
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
	<?php endif; ?>

	<p class="ewp-note">Preview only &mdash; the live front page is unchanged at
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>">the homepage</a>.</p>
</div>
	<?php
	return ob_get_clean();
}

function ew_pv_css() {
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
 font-weight:700;display:flex;align-items:center;justify-content:center}
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
.ewp-score i{font-style:normal;width:17px;height:17px;border-radius:3px;font-size:10px;
 display:flex;align-items:center;justify-content:center;color:#fff;background:#9a9aa3}
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

.ewp-note{max-width:1180px;margin:0 auto;padding:22px 16px 56px;font-size:13px;color:#8a8a8a}
.ewp-note a{color:#CC0000}

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

function ew_pv_js() {
	return <<<'JS'
(function(){
 var root=document.querySelector('.ewp-fixtures'); if(!root){return;}

 /* --- rails: native scroll, own arrows. Measured on demand, because a rail
        inside a hidden tab has no width until that tab is shown. --- */
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

 /* --- tabs --- */
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
})();
JS;
}

add_action( 'template_redirect', function () {
	if ( ! get_query_var( 'ew_preview' ) || is_admin() ) {
		return;
	}

	add_filter( 'pre_get_document_title', function () {
		return 'Front page preview — ' . get_bloginfo( 'name' );
	} );
	add_action( 'wp_head', function () {
		echo '<style id="ew-preview">' . ew_pv_css() . '</style>';
		echo '<meta name="robots" content="noindex">';
	}, 20 );

	get_header();
	echo ew_pv_markup(); // phpcs:ignore WordPress.Security.EscapeOutput
	echo '<script id="ew-preview-js">' . ew_pv_js() . '</script>'; // phpcs:ignore
	get_footer();
	exit;
}, 4 );
