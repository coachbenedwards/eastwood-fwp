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
 *   Matchday | /matchday/ | Coming to a game at Coronation Park
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
	// NOT in parts/. Everything in parts/ is require'd at plugin load, and a
	// page template run at plugin load calls get_header() before WordPress
	// is ready — which took the whole site down on 2.10.0.
	$file = dirname( __DIR__ ) . '/template-fullwidth.php';
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

	/**
	 * A page built in Elementor keeps its widgets in _elementor_data and
	 * leaves post_content empty, so has_shortcode() on post_content alone
	 * reports false and this stylesheet never prints — every block then
	 * renders as unstyled markup. Search both.
	 */
	$hay = array( (string) $post->post_content );

	$elementor = get_post_meta( $post->ID, '_elementor_data', true );
	if ( ! empty( $elementor ) ) {
		$hay[] = is_string( $elementor ) ? $elementor : (string) wp_json_encode( $elementor );
	}

	foreach ( array( 'eastwood_home_banner', 'eastwood_home_hero', 'eastwood_home_fixtures',
		'eastwood_home_upcoming', 'eastwood_home_shop', 'eastwood_home_tiles',
		'eastwood_home_sponsors', 'eastwood_home_discover', 'eastwood_home_news',
		'eastwood_home_player', 'eastwood_home_tv',
		'eastwood_home_strip', 'eastwood_home_live',
		'eastwood_matches', 'eastwood_teams', 'eastwood_squad' ) as $tag ) {
		foreach ( $hay as $text ) {
			if ( '' !== $text && false !== strpos( $text, '[' . $tag ) ) {
				return true;
			}
		}
	}
	return false;
}

add_action( 'wp_head', function () {
	if ( ! ew_home_blocks_present() ) {
		return;
	}
	echo '<link rel="stylesheet" href="https://fonts.googleapis.com/css2'
		. '?family=Oswald:wght@700&amp;display=swap">' . "\n";
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

/**
 * A competition's own badge, the way the reference site puts a league mark
 * in the corner of a fixture. Football Web Pages publishes club crests but
 * no competition graphics, so these are dropped in by hand at
 * wp-content/uploads/ew-comps/<competition-id>.png. Until one exists the
 * markup hides the image and the competition's name carries it instead.
 */
function ew_comp_badge( $id ) {
	$id   = (int) $id;
	$over = WP_CONTENT_DIR . '/uploads/ew-comps/' . $id . '.png';
	if ( file_exists( $over ) ) {
		return content_url( '/uploads/ew-comps/' . $id . '.png' );
	}
	$ship = dirname( __DIR__ ) . '/assets/comps/' . $id . '.png';
	return file_exists( $ship ) ? ew_hb_asset( 'comps/' . $id . '.png' ) : '';
}

/**
 * The small line icons the big fixture uses. Drawn inline so they take the
 * colour of the text beside them and need no extra request.
 */
function ew_hb_icon( $name ) {
	$open = '<svg class="ewp-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
		. ' stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">';
	$paths = array(
		// a lowercase i in a ring, the way a site marks more detail
		'info'   => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 7.6v.1"/>',
		// a ticket with a torn middle
		'ticket' => '<path d="M3 8.5V6.6A1.6 1.6 0 0 1 4.6 5h14.8A1.6 1.6 0 0 1 21 6.6v1.9'
			. 'a3.5 3.5 0 0 0 0 7v1.9a1.6 1.6 0 0 1-1.6 1.6H4.6A1.6 1.6 0 0 1 3 17.4v-1.9'
			. 'a3.5 3.5 0 0 0 0-7Z"/><path d="M14 7.5v2"/><path d="M14 14.5v2"/>',
		// a cloche, for hospitality
		'dine'   => '<path d="M3.5 17.5h17"/><path d="M4.8 14.5a7.2 7.2 0 0 1 14.4 0"/>'
			. '<path d="M12 7.3V5.6"/><circle cx="12" cy="4.4" r="1.1"/>',
		// a football, for the match centre
		'ball'   => '<circle cx="12" cy="12" r="9"/><path d="m12 7.4 3.6 2.6-1.4 4.3H9.8L8.4 10Z"/>'
			. '<path d="M12 3v4.4"/><path d="m3.4 9.6 5 .4"/><path d="m20.6 9.6-5 .4"/>'
			. '<path d="m6.6 19.3 3.2-4.9"/><path d="m17.4 19.3-3.2-4.9"/>',
	);
	return isset( $paths[ $name ] ) ? $open . $paths[ $name ] . '</svg>' : '';
}

/** A file shipped inside the plugin, addressed from parts/. */
function ew_hb_asset( $rel ) {
	return plugins_url( 'assets/' . ltrim( $rel, '/' ), dirname( __DIR__ ) . '/eastwood-fwp.php' );
}

/**
 * "Label | football-web-pages-team-id" lines from the editor, as the club's
 * sides. A team with no id is still a team — it renders, and says its
 * fixtures are not on the feed, rather than being quietly dropped.
 */
function ew_hb_teams( $content ) {
	$teams = array();
	foreach ( ew_hb_lines( $content ) as $line ) {
		$parts = array_map( 'trim', explode( '|', $line ) );
		if ( '' === $parts[0] ) {
			continue;
		}
		$teams[] = array(
			'label' => $parts[0],
			'id'    => isset( $parts[1] ) ? (int) preg_replace( '/[^0-9]/', '', $parts[1] ) : 0,
		);
	}
	foreach ( $teams as $i => $t ) {
		if ( 0 === strcasecmp( trim( (string) $t['label'] ), 'Reserves' ) ) {
			$teams[ $i ]['label'] = 'Development';
		}
	}

	if ( ! $teams ) {
		$teams = array(
			array( 'label' => "Men's First Team", 'id' => (int) EW_TEAM ),
			array( 'label' => "Women's First Team", 'id' => 0 ),
			array( 'label' => 'Development', 'id' => 0 ),
			array( 'label' => 'Under 21s', 'id' => 0 ),
			array( 'label' => 'Academy', 'id' => 0 ),
		);
	}
	return $teams;
}

/** Every match the feed holds for one team, split into done and to come. */
function ew_hb_team_matches( $id ) {
	if ( ! $id ) {
		return array( 'played' => array(), 'todo' => array() );
	}
	$fx  = ew_fwp_fetch( 'fixtures-results', array( 'team' => $id ) );
	$all = (array) ( $fx['fixtures-results']['matches'] ?? array() );

	$played = array_values( array_filter( $all, 'ew_home_played' ) );
	$todo   = array_values( array_filter( $all, function ( $m ) { return ! ew_home_played( $m ); } ) );
	usort( $played, function ( $a, $b ) { return strcmp( (string) $b['date'], (string) $a['date'] ); } );
	usort( $todo, function ( $a, $b ) { return strcmp( (string) $a['date'], (string) $b['date'] ); } );

	return array( 'played' => $played, 'todo' => $todo );
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
function ew_hb_card( $m, $done = false, $cta = array(), $label = '' ) {
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
			<?php
			/*
			 * The bigger card -- the one in Upcoming Matches -- carries the
			 * competition badge and a line saying what the card is, with the
			 * date underneath. The strip's narrow cell carries neither. Same
			 * shape as the reference, with our league in place of theirs.
			 */
			$comp_id  = (int) ( $m['competition']['id'] ?? 0 );
			$comp_img = ( '' !== $label && $comp_id ) ? ew_comp_badge( $comp_id ) : '';
			?>
			<?php if ( '' !== $comp_img ) : ?>
			<img class="ewp-cardComp" src="<?php echo esc_url( $comp_img ); ?>" alt=""
				loading="lazy" decoding="async">
			<?php endif; ?>
			<span class="ewp-when">
				<?php if ( '' !== $label ) : ?>
				<em class="ewp-cardLabel"><?php echo esc_html( $label ); ?></em>
				<?php endif; ?>
				<b><?php echo esc_html( $ts ? date_i18n( 'D j M', $ts ) : '' ); ?></b>
				<?php if ( $done ) : ?>
				<i>FT</i>
				<?php else : ?>
				<i><?php echo esc_html( ! empty( $m['time'] ) ? substr( $m['time'], 0, 5 ) : '' ); ?></i>
				<?php endif; ?>
			</span>
			<span class="ewp-ha is-<?php echo $home ? 'home' : 'away'; ?>"
				title="<?php echo $home ? 'Home' : 'Away'; ?>"><?php echo $home ? 'H' : 'A'; ?></span>
		</span>
		<span class="ewp-cardBody">
			<img src="<?php echo esc_url( ew_home_badge( $opp['id'] ?? 0 ) ); ?>" alt=""
				loading="lazy" decoding="async"
				onerror="this.style.visibility='hidden'">
			<span class="ewp-cardText">
				<span class="ewp-kicker">Men&rsquo;s First Team</span>
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
	<?php if ( $home && ! $done && ! empty( $cta['label'] ) && ! empty( $cta['url'] ) ) : ?>
	<a class="ewp-cardCta" href="<?php echo esc_url( $cta['url'] ); ?>"><?php
		echo esc_html( $cta['label'] ); ?></a>
	<?php endif; ?>
</li>
	<?php
	return ob_get_clean();
}


/**
 * One fixture as a full-width panel — the shape Forest run down their
 * matches page, and the same shape the banner uses with a photo behind it:
 * date across the top, then NAME crest V crest NAME, then whatever there is
 * to do about it.
 *
 * $opts: done, cta, cta_url, hosp, hosp_url, centre, centre_url.
 */
function ew_hb_panel( $m, $opts = array() ) {
	$opts = array_merge(
		array(
			'done'       => false,
			'cta'        => '',
			'cta_url'    => '',
			'hosp'       => '',
			'hosp_url'   => '',
			'centre'     => '',
			'centre_url' => '',
			// the strip across the foot, where the reference names a broadcaster
			'featured'       => '',
			'featured_icon'  => '',
			'featured_label' => '',
			'featured_note'  => '',
		),
		$opts
	);

	$home    = (array) ( $m['home-team'] ?? array() );
	$away    = (array) ( $m['away-team'] ?? array() );
	$at_home = (int) ( $home['id'] ?? 0 ) === (int) EW_TEAM;
	$ts      = strtotime( (string) ( $m['date'] ?? '' ) . ' ' . (string) ( $m['time'] ?? '15:00' ) );
	$comp    = (string) ( $m['competition']['name'] ?? '' );
	$comp_id = (int) ( $m['competition']['id'] ?? 0 );
	$badge   = $comp_id ? ew_comp_badge( $comp_id ) : '';
	$venue   = $at_home ? 'Coronation Park' : (string) ( $m['venue'] ?? '' );

	ob_start();
	?>
<li class="ewp-row">
	<div class="ewp-rowTop">
		<?php if ( '' !== $badge ) : ?>
		<span class="ewp-rowBadge"><img src="<?php echo esc_url( $badge ); ?>" alt=""
			loading="lazy" decoding="async"
			onerror="this.parentNode.remove()"></span>
		<?php endif; ?>
		<b><?php echo esc_html( $ts ? date_i18n( 'D j M', $ts ) : '' ); ?></b>
		<?php if ( $opts['done'] ) : ?>
		<i>FT</i>
		<?php elseif ( $ts ) : ?>
		<i><?php echo esc_html( date_i18n( 'H:i', $ts ) ); ?></i>
		<?php endif; ?>
		<span class="ewp-rowRight">
			<?php if ( '' !== $comp ) : ?>
			<span class="ewp-rowComp"><?php echo esc_html( $comp ); ?></span>
			<?php endif; ?>
			<a class="ewp-rowMore" title="More info" aria-label="More info" href="<?php
				echo esc_url( home_url( $opts['centre_url'] . (int) ( $m['id'] ?? 0 ) . '/' ) ); ?>"><?php
				echo ew_hb_icon( 'info' ); // phpcs:ignore ?></a>
		</span>
	</div>

	<div class="ewp-rowBody">
		<p class="ewp-rowWhere">
			<em><?php echo $at_home ? 'Home' : 'Away'; ?></em>
			<?php echo esc_html( $venue ); ?>
		</p>

		<div class="ewp-tie">
			<span class="ewp-tieName"><?php echo esc_html( (string) ( $home['name'] ?? '' ) ); ?></span>
			<span class="ewp-tieMid">
				<img src="<?php echo esc_url( ew_home_badge( $home['id'] ?? 0 ) ); ?>" alt=""
					loading="lazy" decoding="async"
					onerror="this.style.visibility='hidden'">
				<?php if ( $opts['done'] ) : ?>
				<b class="ewp-tieScore"><?php
					echo esc_html( (int) ( $home['score'] ?? 0 ) . ' – ' . (int) ( $away['score'] ?? 0 ) );
				?></b>
				<?php else : ?>
				<b class="ewp-tieV">V</b>
				<?php endif; ?>
				<img src="<?php echo esc_url( ew_home_badge( $away['id'] ?? 0 ) ); ?>" alt=""
					loading="lazy" decoding="async"
					onerror="this.style.visibility='hidden'">
			</span>
			<span class="ewp-tieName"><?php echo esc_html( (string) ( $away['name'] ?? '' ) ); ?></span>
		</div>

		<?php if ( ! $opts['done'] && $at_home && ( '' !== $opts['cta'] || '' !== $opts['hosp'] ) ) : ?>
		<p class="ewp-rowCta">
			<?php if ( '' !== $opts['hosp'] ) : ?>
			<a href="<?php echo esc_url( home_url( $opts['hosp_url'] ) ); ?>"><?php
				echo ew_hb_icon( 'dine' ); // phpcs:ignore ?><span><?php
				echo esc_html( $opts['hosp'] ); ?></span></a>
			<?php endif; ?>
			<?php if ( '' !== $opts['cta'] ) : ?>
			<a class="is-primary" href="<?php echo esc_url( home_url( $opts['cta_url'] ) ); ?>"><?php
				echo ew_hb_icon( 'ticket' ); // phpcs:ignore ?><span><?php
				echo esc_html( $opts['cta'] ); ?></span></a>
			<?php endif; ?>
		</p>
		<?php endif; ?>

		<?php if ( '' !== $opts['centre'] ) : ?>
		<p class="ewp-rowCentre"><a href="<?php
			echo esc_url( home_url( $opts['centre_url'] . (int) ( $m['id'] ?? 0 ) . '/' ) ); ?>"><?php
			echo ew_hb_icon( 'ball' ); // phpcs:ignore ?><span><?php
			echo esc_html( $opts['centre'] ); ?></span></a></p>
		<?php endif; ?>
	</div>

	<?php if ( ! $opts['done'] && $at_home && '' !== $opts['featured'] ) : ?>
	<div class="ewp-rowFoot">
		<span><?php echo esc_html( $opts['featured'] ); ?></span>
		<?php if ( '' !== $opts['featured_icon'] ) : ?>
		<img src="<?php echo esc_url( $opts['featured_icon'] ); ?>" alt=""
			onerror="this.remove()">
		<?php endif; ?>
		<?php if ( '' !== $opts['featured_label'] ) : ?>
		<b><?php echo esc_html( $opts['featured_label'] ); ?></b>
		<?php endif; ?>
		<?php if ( '' !== $opts['featured_note'] ) : ?>
		<i><?php echo esc_html( $opts['featured_note'] ); ?></i>
		<?php endif; ?>
	</div>
	<?php endif; ?>
</li>
	<?php
	return ob_get_clean();
}

/** A season's worth of panels, broken by month the way a calendar reads. */
/**
 * A season, grouped by month.
 *
 * Only the first few are rendered open. The reference shows seven cards on
 * this page, not a season, and for good reason: each card asks for two club
 * crests and a competition badge, and the host answers a burst of those with
 * 429, so the crests inside the cards quietly fail to arrive. The remainder
 * sits in a closed block — a lazy image inside a hidden element is never
 * requested — and opens on a click.
 */
const EW_LIST_OPEN = 8;

function ew_hb_list( $matches, $opts, $empty ) {
	if ( ! $matches ) {
		return '<p class="ewp-empty is-center">' . esc_html( $empty ) . '</p>';
	}

	$total = count( $matches );
	$limit = EW_LIST_OPEN;
	$rest  = max( 0, $total - $limit );
	$i     = 0;
	$split = false;

	$out   = '';
	$month = '';
	$open  = false;
	foreach ( $matches as $m ) {
		// Everything past the limit goes behind the control, starting on a
		// clean month heading so the closed block reads as a continuation.
		if ( ! $split && $rest && $i === $limit ) {
			if ( $open ) { $out .= '</ul>'; $open = false; }
			$out  .= '<p class="ewp-moreWrap"><button type="button" class="ewp-more"'
				. ' data-ew-more aria-expanded="false">'
				. 'Show the rest of the season <b>' . (int) $rest . '</b></button></p>';
			$out  .= '<div class="ewp-rest" data-ew-rest hidden>';
			$month = '';
			$split = true;
		}
		$i++;
		$ts  = strtotime( (string) ( $m['date'] ?? '' ) );
		$key = $ts ? date_i18n( 'F Y', $ts ) : '';
		if ( $key !== $month ) {
			if ( $open ) {
				$out .= '</ul>';
			}
			$month = $key;
			$out  .= '<p class="ewp-month">' . esc_html( $ts ? date_i18n( 'F', $ts ) : '' ) . '</p>';
			$out  .= '<ul class="ewp-rows">';
			$open  = true;
		}
		$out .= ew_hb_panel( $m, $opts );
	}
	if ( $open ) {
		$out .= '</ul>';
	}
	if ( $split ) {
		$out .= '</div>';
	}
	return $out;
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
		<img src="<?php echo esc_url( get_the_post_thumbnail_url( $lead, 'full' ) ); ?>" alt=""
			decoding="async">
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
 * [eastwood_home_banner cta="Buy Tickets" cta_url="/tickets/"]
 * The next fixture, full width, directly under the header. The words on
 * the buttons are editor-side; everything else comes from the feed.
 * ------------------------------------------------------------------ */

function ew_hb_banner_sc( $atts ) {
	$atts = shortcode_atts(
		array(
			'cta'            => 'Buy Tickets',
			'cta_url'        => '/tickets/',
			'hosp'           => 'Book Hospitality',
			'hosp_url'       => '/hospitality/',
			'centre'         => 'Match Centre',
			'centre_url'     => '/match/',
			'image'          => ew_hb_asset( 'banner/scarf.jpg' ),
			'countdown'      => 'yes',
			'featured'       => 'Live streamed on',
			'featured_url'   => 'https://www.youtube.com/@eastwoodfootballclub',
			'featured_icon'  => ew_hb_asset( 'banner/play.png' ),
			'featured_label' => 'YouTube',
			// Which home games are streamed is not published yet, so the card
			// says so rather than implying every tie is covered.
			'featured_note'  => 'Not announced yet',
		),
		$atts,
		'eastwood_home_banner'
	);

	$fx   = ew_fwp_fetch( 'fixtures-results' );
	$all  = (array) ( $fx['fixtures-results']['matches'] ?? array() );
	$todo = array_values( array_filter( $all, function ( $m ) { return ! ew_home_played( $m ); } ) );
	usort( $todo, function ( $a, $b ) { return strcmp( (string) $a['date'], (string) $b['date'] ); } );
	$next = $todo ? $todo[0] : null;
	if ( ! $next ) {
		return '';
	}

	$home    = (array) ( $next['home-team'] ?? array() );
	$away    = (array) ( $next['away-team'] ?? array() );
	$at_home = (int) ( $home['id'] ?? 0 ) === (int) EW_TEAM;
	$ts      = strtotime( (string) ( $next['date'] ?? '' ) . ' ' . (string) ( $next['time'] ?? '15:00' ) );
	$comp     = (string) ( $next['competition']['name'] ?? '' );
	$comp_id  = (int) ( $next['competition']['id'] ?? 0 );
	$match_id = (int) ( $next['id'] ?? 0 );

	ob_start();
	?>
<section class="ewp ewp-bannerWrap">
	<div class="ewp-banner<?php echo '' !== $atts['image'] ? ' has-img' : ''; ?>"<?php
	echo '' !== $atts['image']
		? ' style="background-image:url(' . esc_url( $atts['image'] ) . ')"'
		: ''; ?>>
	<div class="ewp-bannerBar">
		<span class="ewp-bannerComp">
			<?php $comp_img = $comp_id ? ew_comp_badge( $comp_id ) : ''; ?>
			<?php if ( '' !== $comp_img ) : ?>
			<img src="<?php echo esc_url( $comp_img ); ?>" alt=""
				onerror="this.remove()">
			<?php endif; ?>
		</span>
		<?php if ( $ts ) : ?>
		<span class="ewp-bannerWhen"><b><?php echo esc_html( date_i18n( 'D j M', $ts ) ); ?></b>
			<i><?php echo esc_html( date_i18n( 'H:i', $ts ) ); ?></i></span>
		<?php endif; ?>
		<span class="ewp-bannerRight">
			<?php if ( '' !== $comp ) : ?>
			<em class="ewp-bannerCompName"><?php echo esc_html( $comp ); ?></em>
			<?php endif; ?>
			<?php if ( $match_id && '' !== $atts['centre'] ) : ?>
			<a class="ewp-bannerMore" title="More info" aria-label="More info" href="<?php
				echo esc_url( home_url( $atts['centre_url'] . $match_id . '/' ) ); ?>"><?php
				echo ew_hb_icon( 'info' ); // phpcs:ignore ?></a>
			<?php endif; ?>
		</span>
	</div>

	<div class="ewp-bannerInner">
		<p class="ewp-bannerWhere">
			<em><?php echo $at_home ? 'Home' : 'Away'; ?></em>
			<?php echo esc_html( (string) ( $next['venue'] ?? '' ) ); ?>
		</p>

		<div class="ewp-bannerTie">
			<span class="ewp-bannerName"><?php echo esc_html( (string) ( $home['name'] ?? '' ) ); ?></span>
			<img src="<?php echo esc_url( ew_home_badge( $home['id'] ?? 0 ) ); ?>" alt=""
				onerror="this.style.visibility='hidden'">
			<span class="ewp-bannerV">V</span>
			<img src="<?php echo esc_url( ew_home_badge( $away['id'] ?? 0 ) ); ?>" alt=""
				onerror="this.style.visibility='hidden'">
			<span class="ewp-bannerName"><?php echo esc_html( (string) ( $away['name'] ?? '' ) ); ?></span>
		</div>

		<?php if ( $ts && 'no' !== $atts['countdown'] ) : ?>
		<div class="ewp-bannerClock" data-ko="<?php echo esc_attr( (string) $ts ); ?>" aria-hidden="true">
			<span><b data-u="d">--</b><i>Days</i></span>
			<span><b data-u="h">--</b><i>Hours</i></span>
			<span><b data-u="m">--</b><i>Mins</i></span>
			<span><b data-u="s">--</b><i>Secs</i></span>
		</div>
		<?php endif; ?>

		<p class="ewp-bannerCta">
			<?php if ( $at_home && '' !== $atts['cta'] ) : ?>
			<a class="is-primary" href="<?php echo esc_url( home_url( $atts['cta_url'] ) ); ?>"><?php
				echo ew_hb_icon( 'ticket' ); // phpcs:ignore ?><span><?php
				echo esc_html( $atts['cta'] ); ?></span></a>
			<?php endif; ?>
			<?php if ( $at_home && '' !== $atts['hosp'] ) : ?>
			<a href="<?php echo esc_url( home_url( $atts['hosp_url'] ) ); ?>"><?php
				echo ew_hb_icon( 'dine' ); // phpcs:ignore ?><span><?php
				echo esc_html( $atts['hosp'] ); ?></span></a>
			<?php endif; ?>
		</p>

		<?php if ( $match_id && '' !== $atts['centre'] ) : ?>
		<p class="ewp-bannerCentre"><a href="<?php
			echo esc_url( home_url( $atts['centre_url'] . $match_id . '/' ) ); ?>"><?php
			echo ew_hb_icon( 'ball' ); // phpcs:ignore ?><span><?php
			echo esc_html( $atts['centre'] ); ?></span></a></p>
		<?php endif; ?>

		<?php if ( $at_home && '' !== $atts['featured']
			&& ( '' !== $atts['featured_icon'] || '' !== $atts['featured_label'] ) ) : ?>
		<p class="ewp-bannerFeat">
			<span><?php echo esc_html( $atts['featured'] ); ?></span>
			<?php
			$feat_url = '' !== $atts['featured_url'] ? $atts['featured_url'] : '';
			if ( '' !== $feat_url && 0 !== strpos( $feat_url, 'http' ) ) {
				$feat_url = home_url( $feat_url );
			}
			?>
			<?php if ( '' !== $feat_url ) : ?><a href="<?php echo esc_url( $feat_url ); ?>"
				rel="noopener" target="_blank"><?php endif; ?>
				<?php if ( '' !== $atts['featured_icon'] ) : ?>
				<img src="<?php echo esc_url( $atts['featured_icon'] ); ?>" alt=""
					onerror="this.style.display='none'">
				<?php endif; ?>
				<?php if ( '' !== $atts['featured_label'] ) : ?>
				<b><?php echo esc_html( $atts['featured_label'] ); ?></b>
				<?php endif; ?>
			<?php if ( '' !== $feat_url ) : ?></a><?php endif; ?>
			<?php if ( '' !== $atts['featured_note'] ) : ?>
			<i><?php echo esc_html( $atts['featured_note'] ); ?></i>
			<?php endif; ?>
		</p>
		<?php endif; ?>
	</div>
	</div>
</section>
	<?php
	return ob_get_clean();
}
add_shortcode( 'eastwood_home_banner', 'ew_hb_banner_sc' );

/* ------------------------------------------------------------------
 * [eastwood_home_fixtures title="..." count="8" open="fixtures"]
 * Men's First Team | 2059
 * Women's First Team |
 * [/eastwood_home_fixtures]
 *
 * Two levels of tab, as Forest run their matches page: the club's sides
 * along the top on white, then Fixtures / Results / Table underneath.
 * ------------------------------------------------------------------ */

function ew_hb_fixtures_sc( $atts, $content = '' ) {
	$atts = shortcode_atts(
		array(
			'title'      => 'Fixtures & Results',
			'count'      => '0',
			'open'       => 'fixtures',
			'layout'     => 'list',
			'hero'       => 'no',
			'cta'        => 'Buy Tickets',
			'cta_url'    => '/tickets/',
			'hosp'       => 'Book Hospitality',
			'hosp_url'   => '/hospitality/',
			'centre'     => 'Match Centre',
			'centre_url' => '/match/',
			'featured'       => 'Live streamed on',
			'featured_icon'  => ew_hb_asset( 'banner/play.png' ),
			'featured_label' => 'YouTube',
			// Which home games are streamed is not published yet, so the card
			// says so rather than implying every tie is covered.
			'featured_note'  => 'Not announced yet',
		),
		$atts,
		'eastwood_home_fixtures'
	);
	$n     = max( 0, (int) $atts['count'] );
	$teams = ew_hb_teams( $content );
	$list  = 'rail' !== $atts['layout'];

	$opts = array(
		'cta'        => $atts['cta'],
		'cta_url'    => $atts['cta_url'],
		'hosp'       => $atts['hosp'],
		'hosp_url'   => $atts['hosp_url'],
		'centre'     => $atts['centre'],
		'centre_url' => $atts['centre_url'],
		'featured'       => $atts['featured'],
		'featured_icon'  => $atts['featured_icon'],
		'featured_label' => $atts['featured_label'],
		'featured_note'  => $atts['featured_note'],
	);

	$tabs = array( 'fixtures' => 'Fixtures', 'results' => 'Results', 'table' => 'Table' );
	$open = isset( $tabs[ $atts['open'] ] ) ? $atts['open'] : 'fixtures';

	// Open on the first side that has anything, so nobody lands on a tab
	// that is empty only because the feed does not carry that team.
	$t_open = 0;
	foreach ( $teams as $i => $team ) {
		$teams[ $i ]['split'] = ew_hb_team_matches( $team['id'] );
		if ( ! $t_open && ( $teams[ $i ]['split']['todo'] || $teams[ $i ]['split']['played'] ) ) {
			$t_open = $i + 1;
		}
	}
	$t_open = $t_open ? $t_open - 1 : 0;

	ob_start();
	?>
<section class="ewp ewp-fixtures" data-pv-group>
	<?php if ( count( $teams ) > 1 ) : ?>
	<div class="ewp-teamBar">
		<div class="ewp-wrap">
			<div class="ewp-teamTabs" role="tablist">
				<?php foreach ( $teams as $i => $team ) : ?>
				<button type="button" data-pv="team<?php echo (int) $i; ?>"
					aria-selected="<?php echo $i === $t_open ? 'true' : 'false'; ?>"><?php
					echo esc_html( $team['label'] ); ?></button>
				<?php endforeach; ?>
			</div>
		</div>
	</div>
	<?php endif; ?>

	<div class="ewp-fixturesBody">
		<?php if ( '' !== $atts['title'] ) : ?>
		<div class="ewp-wrap"><h2 class="ewp-h2 is-center"><?php echo esc_html( $atts['title'] ); ?></h2></div>
		<?php endif; ?>

		<?php foreach ( $teams as $i => $team ) :
			$todo   = $team['split']['todo'];
			$played = $team['split']['played'];
			?>
		<div data-pv-panel="team<?php echo (int) $i; ?>"<?php echo $i === $t_open ? '' : ' hidden'; ?>>
			<?php if ( ! $todo && ! $played ) : ?>
			<p class="ewp-empty is-center"><?php
				echo esc_html( $team['id']
					? 'Nothing published for this team yet.'
					: $team['label'] . ' fixtures are not on the feed yet.' ); ?></p>
			<?php else : ?>
			<div data-pv-group>
				<div class="ewp-wrap">
					<div class="ewp-tabs" role="tablist">
						<?php foreach ( $tabs as $key => $label ) : ?>
						<button type="button" data-pv="<?php echo esc_attr( $key ); ?>"
							aria-selected="<?php echo $key === $open ? 'true' : 'false'; ?>"><?php
							echo esc_html( $label ); ?></button>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="ewp-panel<?php echo $list ? ' ewp-wrap' : ''; ?>"
					data-pv-panel="fixtures"<?php echo 'fixtures' === $open ? '' : ' hidden'; ?>>
					<?php
					// Forest put the next game, enlarged, between the tabs and
					// the list — inside Fixtures, not above the whole section.
					if ( 'no' !== $atts['hero'] && (int) $team['id'] === (int) EW_TEAM ) {
						echo ew_hb_banner_sc( array() ); // phpcs:ignore
					}
					$show = $n ? array_slice( $todo, 0, $n ) : $todo;
					echo $list // phpcs:ignore
						? ew_hb_list( $show, $opts, 'No fixtures published yet.' )
						: ew_hb_rail( $show, false, 'No fixtures published yet.' );
					?>
				</div>

				<div class="ewp-panel<?php echo $list ? ' ewp-wrap' : ''; ?>"
					data-pv-panel="results"<?php echo 'results' === $open ? '' : ' hidden'; ?>>
					<?php
					$show = $n ? array_slice( $played, 0, $n ) : $played;
					echo $list // phpcs:ignore
						? ew_hb_list( $show, array_merge( $opts, array( 'done' => true ) ), 'No results yet this season.' )
						: ew_hb_rail( $show, true, 'No results yet this season.' );
					?>
				</div>

				<div class="ewp-panel ewp-wrap" data-pv-panel="table"<?php echo 'table' === $open ? '' : ' hidden'; ?>>
					<?php echo ew_hb_table( $team['id'] ); // phpcs:ignore ?>
				</div>
			</div>
			<?php endif; ?>
		</div>
		<?php endforeach; ?>
	</div>
</section>
	<?php
	return ob_get_clean();
}

/** The league table the given team sits in. */
function ew_hb_table( $team_id ) {
	if ( ! $team_id ) {
		return '<p class="ewp-empty is-center">No league table for this team yet.</p>';
	}
	$lt   = ew_fwp_fetch( 'league-table', array( 'team' => $team_id ) );
	$rows = (array) ( $lt['league-table']['teams'] ?? array() );
	if ( ! $rows ) {
		return '<p class="ewp-empty is-center">The league table is not available right now.</p>';
	}

	ob_start();
	?>
<table class="ewp-table">
	<thead><tr><th>#</th><th class="ewp-club">Club</th><th>P</th><th class="ewp-opt">W</th>
		<th class="ewp-opt">D</th><th class="ewp-opt">L</th><th>GD</th><th>Pts</th></tr></thead>
	<tbody>
	<?php foreach ( $rows as $t ) :
		$a  = (array) ( $t['all-matches'] ?? array() );
		$us = (int) ( $t['id'] ?? 0 ) === (int) $team_id; ?>
		<tr<?php echo $us ? ' class="is-us"' : ''; ?>>
			<td><?php echo esc_html( $t['position'] ?? '' ); ?></td>
			<td class="ewp-club">
				<img src="<?php echo esc_url( ew_home_badge( $t['id'] ?? 0 ) ); ?>" alt=""
					loading="lazy" decoding="async"
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
	<?php
	return ob_get_clean();
}
add_shortcode( 'eastwood_home_fixtures', 'ew_hb_fixtures_sc' );

/* ------------------------------------------------------------------
 * [eastwood_home_upcoming title="..." count="3" all="View all matches"
 *                         all_url="/matches/" cta="Buy tickets" cta_url="/tickets/"]
 * Men's First Team | 2059
 * Women's First Team |
 * [/eastwood_home_upcoming]
 *
 * One tab per team, "Label | football-web-pages-team-id". Only the men's
 * first team is on the feed today; a tab with no id still renders, and
 * says so, rather than being quietly dropped.
 *
 * The next few fixtures on a red band, the way Forest run theirs under
 * the news. Three cards, not a rail: at three there is nothing to scroll
 * and an arrow that does nothing looks broken. The ticket button shows on
 * home games only — there is nothing to sell for an away trip.
 * ------------------------------------------------------------------ */

function ew_hb_upcoming_sc( $atts, $content = '' ) {
	$atts = shortcode_atts(
		array(
			'title'   => 'Upcoming Matches',
			'label'   => 'Upcoming Match',
			'count'   => '3',
			'all'     => 'View all matches',
			'all_url' => '/matches/',
			'cta'     => 'Buy tickets',
			'cta_url' => '/tickets/',
		),
		$atts,
		'eastwood_home_upcoming'
	);
	$n = max( 1, (int) $atts['count'] );

	$teams = ew_hb_teams( $content );
	$cta   = array( 'label' => $atts['cta'], 'url' => home_url( $atts['cta_url'] ) );

	// Work out each tab's fixtures up front, so the first tab with any can
	// be the one that opens. A page that lands on an empty tab looks broken
	// even when every other tab is full.
	$open = 0;
	foreach ( $teams as $i => $team ) {
		$split = ew_hb_team_matches( $team['id'] );
		$teams[ $i ]['matches'] = array_slice( $split['todo'], 0, $n );
		if ( $teams[ $i ]['matches'] && ! $open ) {
			$open = $i + 1;
		}
	}
	$open = $open ? $open - 1 : 0;

	ob_start();
	?>
<section class="ewp ewp-upcoming" data-pv-group>
	<div class="ewp-wrap">
		<?php if ( '' !== $atts['title'] ) : ?>
		<h2 class="ewp-h2 is-light is-center"><?php echo esc_html( $atts['title'] ); ?></h2>
		<?php endif; ?>

		<?php if ( count( $teams ) > 1 ) : ?>
		<div class="ewp-pills" role="tablist" data-pv-tabs>
			<?php foreach ( $teams as $i => $team ) : ?>
			<button type="button" data-pv="t<?php echo (int) $i; ?>"
				aria-selected="<?php echo $i === $open ? 'true' : 'false'; ?>"><?php
				echo esc_html( $team['label'] ); ?></button>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>

		<?php foreach ( $teams as $i => $team ) : ?>
		<div data-pv-panel="t<?php echo (int) $i; ?>"<?php echo $i === $open ? '' : ' hidden'; ?>>
			<?php if ( ! $team['matches'] ) : ?>
			<p class="ewp-empty is-light"><?php
				echo esc_html( $team['id']
					? 'No fixtures published for this team yet.'
					: $team['label'] . ' fixtures are not on the feed yet.' ); ?></p>
			<?php else : ?>
			<ul class="ewp-upcomingGrid">
				<?php
				foreach ( $team['matches'] as $m ) {
					echo ew_hb_card( $m, false, $cta, $atts['label'] ); // phpcs:ignore
				}
				?>
			</ul>
			<?php endif; ?>
		</div>
		<?php endforeach; ?>

		<?php if ( '' !== $atts['all'] ) : ?>
		<p class="ewp-upcomingAll"><a href="<?php echo esc_url( home_url( $atts['all_url'] ) ); ?>"><?php
			echo esc_html( $atts['all'] ); ?> <span aria-hidden="true">&rarr;</span></a></p>
		<?php endif; ?>
	</div>
</section>
	<?php
	return ob_get_clean();
}
add_shortcode( 'eastwood_home_upcoming', 'ew_hb_upcoming_sc' );

/* ------------------------------------------------------------------
 * [eastwood_home_discover title="Discover Eastwood"]
 * Label | /where-it-goes/ | https://.../photograph.jpg
 * [/eastwood_home_discover]
 *
 * The reference runs this as a rail of portrait photographs, not a row
 * of text tiles, and every number here is measured off it: a 1512 wide
 * page, cards 300 by 450 with an 8px corner and a 16px gap, the first
 * one landing on the 1248 column at x=132, the label Anton 20 in white
 * 16px in from the bottom left corner with an arrow after it, and a
 * 32px round white chevron centred on the edge of that column. The
 * heading is Anton 56 in red with 48px of air under it, and the band
 * behind the whole thing is the same #f3f3f3 as the news band.
 *
 * The third field is the photograph. A line without one still renders
 * -- the card falls back to flat black and the label still reads.
 * ------------------------------------------------------------------ */

function ew_hb_discover_sc( $atts, $content = '' ) {
	$atts = shortcode_atts( array( 'title' => 'Discover Eastwood' ), $atts, 'eastwood_home_discover' );

	$cards = array();
	foreach ( ew_hb_lines( $content ) as $line ) {
		$parts = array_map( 'trim', explode( '|', $line ) );
		if ( '' === $parts[0] || ! isset( $parts[1] ) || '' === $parts[1] ) {
			continue;
		}
		$url = $parts[1];
		if ( 0 !== strpos( $url, 'http' ) ) {
			$url = home_url( '/' . ltrim( $url, '/' ) );
		}
		$img = isset( $parts[2] ) ? $parts[2] : '';
		/*
		 * stock:academy is a football photograph that travels with the
		 * plugin, there to hold the shape of the rail until the club has
		 * its own. Writing a real picture over it means putting its address
		 * in this field in the builder -- nothing in the code changes, and
		 * nothing here pretends a stock photograph is ours.
		 */
		if ( 0 === strpos( $img, 'stock:' ) ) {
			$name = preg_replace( '/[^a-z0-9\-]/', '', strtolower( substr( $img, 6 ) ) );
			$file = dirname( __DIR__ ) . '/assets/discover/' . $name . '.jpg';
			$img  = file_exists( $file ) ? ew_hb_asset( 'discover/' . $name . '.jpg' ) : '';
		}
		// The field used to hold a line of prose. Anything that is not a
		// path to a picture is ignored rather than printed somewhere it
		// was never designed to go.
		if ( '' !== $img && ! preg_match( '#^(https?:)?/.*\.(jpe?g|png|webp|avif|gif)$#i', $img ) ) {
			$img = '';
		}
		$cards[] = array( $parts[0], $url, $img );
	}

	if ( ! $cards ) {
		return '';
	}

	ob_start();
	?>
<section class="ewp ewp-disc">
	<?php if ( '' !== $atts['title'] ) : ?>
	<h2 class="ewp-discH"><?php echo esc_html( $atts['title'] ); ?></h2>
	<?php endif; ?>
	<div class="ewp-discRail">
		<ul class="ewp-stripList ewp-discList">
			<?php foreach ( $cards as $card ) : ?>
			<li class="ewp-discSlide">
				<a class="ewp-discCard" href="<?php echo esc_url( $card[1] ); ?>">
					<?php if ( '' !== $card[2] ) : ?>
					<img class="ewp-discShot" src="<?php echo esc_url( $card[2] ); ?>" alt="" loading="lazy">
					<?php endif; ?>
					<p class="ewp-discLabel"><?php echo esc_html( $card[0] ); ?><span aria-hidden="true">&rarr;</span></p>
				</a>
			</li>
			<?php endforeach; ?>
		</ul>
		<button type="button" class="ewp-stripNav is-prev" aria-label="Back" hidden>
			<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
				stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg>
		</button>
		<button type="button" class="ewp-stripNav is-next" aria-label="More">
			<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
				stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5l7 7-7 7"/></svg>
		</button>
	</div>
</section>
	<?php
	return ob_get_clean();
}
add_shortcode( 'eastwood_home_discover', 'ew_hb_discover_sc' );

/* ------------------------------------------------------------------
 * [eastwood_home_tv mark="Eastwood TV" heading="Latest"
 *                   channel="@eastwoodfootballclub" count="8"
 *                   cats="Match Highlights=Highlights|This Is Eastwood=The Series"
 *                   all="Visit Eastwood TV" all_url="https://..."]
 * Title | https://watch/here | Category | 24:10 | https://thumb.jpg | 3 months ago
 * [/eastwood_home_tv]
 *
 * Left empty, the clips come straight off the club YouTube channel
 * named in the channel field: the newest uploads, with their own
 * stills, running times and ages, held for half an hour at a time.
 * Shorts are left out, since the cards are landscape. Type lines in
 * and the typed lines win, which is the way to pin a particular set
 * of clips for a week.
 *
 * cats turns a phrase in a title into the small grey label above it,
 * and takes that phrase back out of the title so a card does not say
 * Match Highlights twice. Set trim_cat="no" to leave titles whole.
 *
 * The reference runs its video band as its own thing and nothing else
 * on their page looks like it: a red band the width of the window, the
 * channel name across the middle, a section heading hard against the
 * left of the column, and a rail of white cards. Measured off their
 * page at 1512: cards 300 by 279 with an 8px corner, 16 apart, the
 * first on the column at x=132; the still 300 by 169; the category 12
 * in grey, the title Anton 16 in near-black, and the age of the clip
 * on the last line. The heading is Anton 40 in white with 40 under it.
 *
 * Ours was a grid of thumbnails on black, which is the one band on the
 * homepage that looked like nothing on theirs.
 * ------------------------------------------------------------------ */

function ew_hb_tv_sc( $atts, $content = '' ) {
	$atts = shortcode_atts(
		array(
			'mark'     => 'Eastwood TV',
			'heading'  => 'Latest',
			'all'      => 'Visit Eastwood TV',
			'all_url'  => '/eastwood-tv/',
			'channel'  => '@eastwoodfootballclub',
			'count'    => '8',
			'cats'     => 'Match Highlights=Highlights|This Is Eastwood=The Series',
			'trim_cat' => 'yes',
			'shorts'   => 'no',
		),
		$atts,
		'eastwood_home_tv'
	);

	$clips = array();
	foreach ( ew_hb_lines( $content ) as $line ) {
		$parts = array_map( 'trim', explode( '|', $line ) );
		if ( '' === $parts[0] || ! isset( $parts[1] ) || '' === $parts[1] ) {
			continue;
		}
		$url = $parts[1];
		if ( 0 !== strpos( $url, 'http' ) ) {
			$url = home_url( '/' . ltrim( $url, '/' ) );
		}
		$clips[] = array(
			'title' => $parts[0],
			'url'   => $url,
			'cat'   => isset( $parts[2] ) ? $parts[2] : '',
			'time'  => isset( $parts[3] ) ? $parts[3] : '',
			'img'   => isset( $parts[4] ) ? $parts[4] : '',
			'when'  => isset( $parts[5] ) ? $parts[5] : '',
		);
	}

	/* Nothing typed in, so go and get the channel. */
	if ( ! $clips && '' !== trim( $atts['channel'] ) && function_exists( 'ew_yt_videos' ) ) {
		$map  = ew_yt_cat_map( $atts['cats'] );
		$trim = 'no' !== $atts['trim_cat'];
		$live = ew_yt_videos(
			$atts['channel'],
			$atts['count'],
			'yes' === $atts['shorts'] ? 0 : 61
		);

		foreach ( $live as $clip ) {
			$read          = ew_yt_label( $clip['title'], $map, $trim );
			$clip['title'] = $read['title'];
			$clip['cat']   = $read['cat'];
			$clips[]       = $clip;
		}
	}

	if ( ! $clips ) {
		return '';
	}

	ob_start();
	?>
<section class="ewp ewp-tv">
	<?php if ( '' !== $atts['mark'] ) : ?>
	<p class="ewp-tvMark"><?php echo esc_html( $atts['mark'] ); ?></p>
	<?php endif; ?>
	<?php if ( '' !== $atts['heading'] ) : ?>
	<h2 class="ewp-tvHead"><?php echo esc_html( $atts['heading'] ); ?></h2>
	<?php endif; ?>
	<div class="ewp-tvRail">
		<ul class="ewp-stripList ewp-tvList">
			<?php foreach ( $clips as $clip ) : ?>
			<li class="ewp-tvSlide">
				<a class="ewp-tvCard" href="<?php echo esc_url( $clip['url'] ); ?>">
					<span class="ewp-tvShot">
						<?php if ( '' !== $clip['img'] ) : ?>
						<img src="<?php echo esc_url( $clip['img'] ); ?>" alt="" loading="lazy">
						<?php endif; ?>
						<span class="ewp-tvPlay" aria-hidden="true">
							<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
						</span>
						<?php if ( '' !== $clip['time'] ) : ?>
						<i class="ewp-tvTime"><?php echo esc_html( $clip['time'] ); ?></i>
						<?php endif; ?>
					</span>
					<span class="ewp-tvCat"><?php echo esc_html( $clip['cat'] ); ?></span>
					<b class="ewp-tvTitle"><?php echo esc_html( $clip['title'] ); ?></b>
					<?php if ( '' !== $clip['when'] ) : ?>
					<i class="ewp-tvWhen"><?php echo esc_html( $clip['when'] ); ?></i>
					<?php endif; ?>
				</a>
			</li>
			<?php endforeach; ?>
		</ul>
		<button type="button" class="ewp-stripNav is-prev" aria-label="Back" hidden>
			<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
				stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg>
		</button>
		<button type="button" class="ewp-stripNav is-next" aria-label="More">
			<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
				stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5l7 7-7 7"/></svg>
		</button>
	</div>
	<?php if ( '' !== $atts['all'] ) : ?>
	<p class="ewp-tvAll"><a href="<?php echo esc_url(
		0 === strpos( $atts['all_url'], 'http' ) ? $atts['all_url'] : home_url( $atts['all_url'] ) ); ?>"><?php
		echo esc_html( $atts['all'] ); ?> <span aria-hidden="true">&rarr;</span></a></p>
	<?php endif; ?>
</section>
	<?php
	return ob_get_clean();
}
add_shortcode( 'eastwood_home_tv', 'ew_hb_tv_sc' );

/* ------------------------------------------------------------------
 * [eastwood_home_shop title="..." all="Shop all" all_url="/shop/"]
 * Home Shirt 26/27 | /shop/home-shirt/ | £45.00 | https://…/shirt.jpg
 * [/eastwood_home_shop]
 *
 * Forest pull this row straight out of their store. We have no store on
 * this site yet, so the tiles are typed in and point wherever the shirts
 * are actually being sold. Price and image are both optional — a tile
 * with neither still renders as a link.
 * ------------------------------------------------------------------ */

function ew_hb_shop_sc( $atts, $content = '' ) {
	$atts = shortcode_atts(
		array(
			'title'   => 'Get Kitted Out',
			'all'     => 'Shop all',
			'all_url' => '/shop/',
		),
		$atts,
		'eastwood_home_shop'
	);

	$items = array();
	foreach ( ew_hb_lines( $content ) as $line ) {
		$parts = array_map( 'trim', explode( '|', $line ) );
		if ( '' === $parts[0] || ! isset( $parts[1] ) || '' === $parts[1] ) {
			continue;
		}
		$url = $parts[1];
		if ( 0 !== strpos( $url, 'http' ) ) {
			$url = home_url( '/' . ltrim( $url, '/' ) );
		}
		$items[] = array(
			$parts[0],
			$url,
			isset( $parts[2] ) ? $parts[2] : '',
			isset( $parts[3] ) ? $parts[3] : '',
		);
	}

	if ( ! $items ) {
		return '';
	}

	ob_start();
	?>
<section class="ewp ewp-shop">
	<div class="ewp-wrap">
		<?php if ( '' !== $atts['title'] ) : ?>
		<h2 class="ewp-h2 is-center is-red"><?php echo esc_html( $atts['title'] ); ?></h2>
		<?php endif; ?>

		<ul class="ewp-shopGrid">
			<?php foreach ( $items as $item ) : ?>
			<li>
				<a href="<?php echo esc_url( $item[1] ); ?>">
					<span class="ewp-shopImg">
						<?php if ( '' !== $item[3] ) : ?>
						<img src="<?php echo esc_url( $item[3] ); ?>" alt="" loading="lazy"
							onerror="this.style.display='none'">
						<?php endif; ?>
					</span>
					<b><?php echo esc_html( $item[0] ); ?></b>
					<?php if ( '' !== $item[2] ) : ?>
					<i><?php echo esc_html( $item[2] ); ?></i>
					<?php endif; ?>
				</a>
			</li>
			<?php endforeach; ?>
		</ul>

		<?php if ( '' !== $atts['all'] ) : ?>
		<p class="ewp-shopAll"><a href="<?php echo esc_url( home_url( $atts['all_url'] ) ); ?>"><?php
			echo esc_html( $atts['all'] ); ?> <span aria-hidden="true">&rarr;</span></a></p>
		<?php endif; ?>
	</div>
</section>
	<?php
	return ob_get_clean();
}
add_shortcode( 'eastwood_home_shop', 'ew_hb_shop_sc' );

/* ------------------------------------------------------------------
 * [eastwood_home_player kicker="Shop by player" ask="..." cta="Shop %s"
 *                       all="View all players" all_url="/teams/"
 *                       shop_url="/shop/" shirt="https://.../back.png"]
 * Gibbs-White | 10 | /shop/the-shirt/
 * [/eastwood_home_player]
 *
 * The reference sits this inside Get Kitted Out: a panel with the
 * player name in Anton black, the number huge in red under it, a
 * button that names the player, and a stage on the right holding the
 * shirt with two more either side of it, faded back. A row of tabs
 * along the bottom carries every player, name over number, the one
 * showing underlined in red.
 *
 * They have a photograph of every shirt with a name and number already
 * printed on it. We have one shirt and a printer, which is also what
 * the club actually sells, so the name and number are set over the
 * shirt here rather than baked into seventeen photographs. One picture
 * serves the whole squad and a change of squad changes nothing else.
 *
 * A player line carries its own number; leave it off and the number
 * the squad page already uses is filled in. No line, no block -- this
 * never invents a squad.
 * ------------------------------------------------------------------ */

function ew_hb_player_sc( $atts, $content = '' ) {
	$atts = shortcode_atts(
		array(
			'title'    => '',
			'kicker'   => 'Shop by player',
			'ask'      => "Who's your pick?",
			'cta'      => 'Shop %s',
			'all'      => 'View all players',
			'all_url'  => '/teams/',
			'shop_url' => '/shop/',
			// Empty means the club shirt that travels with the plugin --
			// the back of the home shirt, cut out of the photograph Ben
			// supplied. Putting an address in the field uses that
			// instead, which is how a change of kit gets made.
			'shirt'    => '',
			'bg'       => '',
			// Where the printing sits on whatever shirt is supplied.
			// Fields rather than numbers in the stylesheet, because the
			// right answer depends on the photograph.
			'name_top' => '17%',
			'num_top'  => '40%',
		),
		$atts,
		'eastwood_home_player'
	);

	if ( '' === $atts['shirt'] ) {
		$ship = dirname( __DIR__ ) . '/assets/shop/home-shirt-back.png';
		if ( file_exists( $ship ) ) {
			$atts['shirt'] = ew_hb_asset( 'shop/home-shirt-back.png' );
		}
	}

	$numbers = function_exists( 'ew_player_numbers' ) ? (array) ew_player_numbers() : array();

	$players = array();
	foreach ( ew_hb_lines( $content ) as $line ) {
		$parts = array_map( 'trim', explode( '|', $line ) );
		if ( '' === $parts[0] ) {
			continue;
		}
		$name = $parts[0];
		$num  = isset( $parts[1] ) && '' !== $parts[1] ? $parts[1] : '';
		if ( '' === $num ) {
			foreach ( $numbers as $who => $n ) {
				if ( 0 === strcasecmp( (string) $who, $name ) && $n ) {
					$num = (string) $n;
				}
			}
		}
		$url = isset( $parts[2] ) && '' !== $parts[2] ? $parts[2] : $atts['shop_url'];
		if ( 0 !== strpos( $url, 'http' ) ) {
			$url = home_url( '/' . ltrim( $url, '/' ) );
		}
		$players[] = array( 'name' => $name, 'num' => $num, 'url' => $url );
	}

	if ( ! $players ) {
		return '';
	}

	$surname = function ( $name ) {
		$bits = preg_split( '/\s+/', trim( $name ) );
		return strtoupper( (string) end( $bits ) );
	};

	ob_start();
	?>
<section class="ewp ewp-picker" data-shirt="<?php echo esc_attr( $atts['shirt'] ); ?>">
	<div class="ewp-wrap">
		<?php if ( '' !== $atts['title'] ) : ?>
		<h2 class="ewp-h2 is-center is-red"><?php echo esc_html( $atts['title'] ); ?></h2>
		<?php endif; ?>

		<div class="ewp-pickPanel" style="<?php
			if ( '' !== $atts['bg'] ) { echo 'background-image:url(' . esc_url( $atts['bg'] ) . ');'; }
			echo '--ew-name-top:' . esc_attr( $atts['name_top'] ) . ';';
			echo '--ew-num-top:' . esc_attr( $atts['num_top'] ) . ';'; ?>">

			<div class="ewp-pickSide">
				<p class="ewp-pickKicker"><?php echo esc_html( $atts['kicker'] ); ?></p>
				<p class="ewp-pickName" data-name><?php echo esc_html( $surname( $players[0]['name'] ) ); ?></p>
				<p class="ewp-pickNum" data-num><?php echo esc_html( $players[0]['num'] ); ?></p>
				<p class="ewp-pickAsk"><?php echo esc_html( $atts['ask'] ); ?></p>
				<p class="ewp-pickCta"><a data-cta href="<?php echo esc_url( $players[0]['url'] ); ?>"><?php
					echo esc_html( sprintf( $atts['cta'], $surname( $players[0]['name'] ) ) ); ?></a></p>
				<?php if ( '' !== $atts['all'] ) : ?>
				<p class="ewp-pickAll"><a href="<?php echo esc_url( home_url( $atts['all_url'] ) ); ?>"><?php
					echo esc_html( $atts['all'] ); ?> <span aria-hidden="true">&rarr;</span></a></p>
				<?php endif; ?>
			</div>

			<div class="ewp-pickRight">
			<div class="ewp-pickStage">
				<button type="button" class="ewp-pickNav is-prev" aria-label="Previous player">
					<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
						stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg>
				</button>
				<?php foreach ( array( 'is-left', 'is-mid', 'is-right' ) as $slot ) : ?>
				<span class="ewp-pickShirt <?php echo esc_attr( $slot ); ?>">
					<?php if ( '' !== $atts['shirt'] ) : ?>
					<img src="<?php echo esc_url( $atts['shirt'] ); ?>" alt="" loading="lazy">
					<?php endif; ?>
					<b data-slot-name></b>
					<i data-slot-num></i>
				</span>
				<?php endforeach; ?>
				<button type="button" class="ewp-pickNav is-next" aria-label="Next player">
					<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
						stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5l7 7-7 7"/></svg>
				</button>
			</div>

			<ul class="ewp-pickTabs">
				<?php foreach ( $players as $i => $pl ) : ?>
				<li>
					<button type="button" class="ewp-pickTab<?php echo 0 === $i ? ' is-on' : ''; ?>"
						data-i="<?php echo (int) $i; ?>"
						data-player="<?php echo esc_attr( $surname( $pl['name'] ) ); ?>"
						data-number="<?php echo esc_attr( $pl['num'] ); ?>"
						data-url="<?php echo esc_url( $pl['url'] ); ?>"
						data-cta="<?php echo esc_attr( sprintf( $atts['cta'], $surname( $pl['name'] ) ) ); ?>">
						<b><?php echo esc_html( $surname( $pl['name'] ) ); ?></b>
						<i><?php echo esc_html( $pl['num'] ); ?></i>
					</button>
				</li>
				<?php endforeach; ?>
			</ul>
			</div>
		</div>
	</div>
</section>
	<?php
	return ob_get_clean();
}
add_shortcode( 'eastwood_home_player', 'ew_hb_player_sc' );

/* ------------------------------------------------------------------
 * [eastwood_home_tiles title="Hospitality" all="Book now" all_url="/..."
 *                      tone="light" cols="4"]
 * Matchday Hospitality | /hospitality/ | From £45 a head | https://…/img.jpg
 * [/eastwood_home_tiles]
 *
 * Forest run the same band four times over — Discover, Memberships,
 * Hospitality, Forest Looks — a heading, a row of photographs with a label
 * across the bottom, and one link underneath. One block does all of them,
 * and the video row too, where the third field is the running time.
 * ------------------------------------------------------------------ */

function ew_hb_tiles_sc( $atts, $content = '' ) {
	$atts = shortcode_atts(
		array(
			'title'   => '',
			'all'     => '',
			'all_url' => '/',
			'tone'    => 'light',
			'cols'    => '4',
			'play'    => 'no',
		),
		$atts,
		'eastwood_home_tiles'
	);

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
		$tiles[] = array(
			$parts[0],
			$url,
			isset( $parts[2] ) ? $parts[2] : '',
			isset( $parts[3] ) ? $parts[3] : '',
		);
	}
	if ( ! $tiles ) {
		return '';
	}

	// Red is a dark tone with a different background -- every rule about
	// white type and a lifted thumbnail is the same, so it rides along.
	$red  = 'red' === $atts['tone'];
	$dark = $red || 'dark' === $atts['tone'];
	$cols = max( 2, min( 6, (int) $atts['cols'] ) );
	$ext  = false !== strpos( $atts['all_url'], 'http' );

	ob_start();
	?>
<section class="ewp ewp-tiles<?php echo $red ? ' is-red' : ( $dark ? ' is-dark' : '' ); ?>">
	<div class="ewp-wrap">
		<?php if ( '' !== $atts['title'] ) : ?>
		<h2 class="ewp-h2<?php echo $dark ? ' is-light' : ' is-red'; ?>"><?php
			echo esc_html( $atts['title'] ); ?></h2>
		<?php endif; ?>

		<ul class="ewp-tileGrid" style="--ewp-cols:<?php echo (int) $cols; ?>">
			<?php foreach ( $tiles as $t ) : ?>
			<li>
				<a href="<?php echo esc_url( $t[1] ); ?>"<?php
					echo false !== strpos( $t[1], 'http' ) && false === strpos( $t[1], 'eastwoodfc.com' )
						? ' target="_blank" rel="noopener"' : ''; ?>>
					<span class="ewp-tileImg">
						<?php if ( '' !== $t[3] ) : ?>
						<img src="<?php echo esc_url( $t[3] ); ?>" alt="" loading="lazy"
							onerror="this.style.display='none'">
						<?php endif; ?>
						<?php if ( 'no' !== $atts['play'] ) : ?>
						<span class="ewp-tilePlay" aria-hidden="true"></span>
						<?php endif; ?>
						<?php if ( '' !== $t[2] && 'no' !== $atts['play'] ) : ?>
						<i class="ewp-tileMeta"><?php echo esc_html( $t[2] ); ?></i>
						<?php endif; ?>
					</span>
					<b><?php echo esc_html( $t[0] ); ?> <span aria-hidden="true">&rarr;</span></b>
					<?php if ( '' !== $t[2] && 'no' === $atts['play'] ) : ?>
					<i class="ewp-tileSub"><?php echo esc_html( $t[2] ); ?></i>
					<?php endif; ?>
				</a>
			</li>
			<?php endforeach; ?>
		</ul>

		<?php if ( '' !== $atts['all'] ) : ?>
		<p class="ewp-allRow<?php echo $dark ? ' is-light' : ''; ?>"><a class="ewp-all"
			href="<?php echo esc_url( $ext ? $atts['all_url'] : home_url( $atts['all_url'] ) ); ?>"><?php
			echo esc_html( $atts['all'] ); ?> <span aria-hidden="true">&rarr;</span></a></p>
		<?php endif; ?>
	</div>
</section>
	<?php
	return ob_get_clean();
}
add_shortcode( 'eastwood_home_tiles', 'ew_hb_tiles_sc' );

/* ------------------------------------------------------------------
 * [eastwood_home_sponsors title="..." lead="Shirt Sponsor,Event Sponsor"]
 *
 * Straight off ew_sponsor_roster(), so the homepage cannot drift from the
 * store the rest of the site serves. Forest split theirs into principal
 * partners and the rest; "lead" names the tiers that go in the top row.
 * ------------------------------------------------------------------ */

function ew_hb_sponsors_sc( $atts ) {
	$atts = shortcode_atts(
		array(
			'title'      => 'Our Partners',
			'tone'       => 'light',
			'lead'       => 'Shirt Sponsor,Event Sponsor',
			'lead_label' => 'Principal Partners',
			'rest_label' => 'Club Partners',
		),
		$atts,
		'eastwood_home_sponsors'
	);

	if ( ! function_exists( 'ew_sponsor_roster' ) || ! function_exists( 'ew_sponsor_url' ) ) {
		return '';
	}

	$lead_tiers = array_filter( array_map( 'trim', explode( ',', $atts['lead'] ) ) );
	$rows       = array( 'lead' => array(), 'rest' => array() );
	foreach ( ew_sponsor_roster() as $slug => $row ) {
		$where = in_array( (string) ( $row[1] ?? '' ), $lead_tiers, true ) ? 'lead' : 'rest';
		$rows[ $where ][] = array(
			'name' => (string) ( $row[0] ?? '' ),
			'tier' => (string) ( $row[1] ?? '' ),
			'url'  => (string) ( $row[2] ?? '' ),
			'img'  => function_exists( 'ew_sponsor_tone_url' )
				? ew_sponsor_tone_url( $slug, $atts['tone'] )
				: ew_sponsor_url( $slug ),
		);
	}
	if ( ! $rows['lead'] && ! $rows['rest'] ) {
		return '';
	}

	ob_start();
	?>
<section class="ewp ewp-sponsors<?php echo 'red' === $atts['tone'] ? ' is-red' : ''; ?>">
	<div class="ewp-wrap">
		<?php if ( '' !== $atts['title'] ) : ?>
		<h2 class="ewp-h2 <?php echo 'red' === $atts['tone'] ? 'is-light' : 'is-red'; ?>"><?php
			echo esc_html( $atts['title'] ); ?></h2>
		<?php endif; ?>

		<?php foreach ( array( 'lead' => $atts['lead_label'], 'rest' => $atts['rest_label'] ) as $k => $label ) : ?>
			<?php if ( ! $rows[ $k ] ) { continue; } ?>
		<p class="ewp-sponsorLabel"><?php echo esc_html( $label ); ?></p>
		<ul class="ewp-sponsorRow<?php echo 'lead' === $k ? ' is-lead' : ''; ?>">
			<?php foreach ( $rows[ $k ] as $sp ) : ?>
			<li>
				<?php if ( '' !== $sp['url'] ) : ?>
				<a href="<?php echo esc_url( $sp['url'] ); ?>" target="_blank" rel="noopener sponsored"
					title="<?php echo esc_attr( $sp['name'] . ' — ' . $sp['tier'] ); ?>">
				<?php endif; ?>
					<img src="<?php echo esc_url( $sp['img'] ); ?>"
						alt="<?php echo esc_attr( $sp['name'] ); ?>" loading="lazy"
						onerror="this.closest('li').style.display='none'">
				<?php if ( '' !== $sp['url'] ) : ?></a><?php endif; ?>
			</li>
			<?php endforeach; ?>
		</ul>
		<?php endforeach; ?>
	</div>
</section>
	<?php
	return ob_get_clean();
}
add_shortcode( 'eastwood_home_sponsors', 'ew_hb_sponsors_sc' );

/* ------------------------------------------------------------------
 * [eastwood_home_news title="..." count="4" link="/news/"]
 * ------------------------------------------------------------------ */

/**
 * The news section, built to the reference.
 *
 * Measured off it rather than eyeballed: the heading is Anton 56 in the
 * club red with its own icon, the tab bar is a white 40px rail with 4px
 * of padding and 4px between tabs, the active tab is white on red at a
 * 2px radius, and a card is 405x228 with the photograph filling the
 * whole of it and the headline laid over the bottom.
 *
 * The reference fetches each tab from its own news service on click. We
 * have ten articles, so every tab is written into the page at once and
 * the click only swaps which one shows -- same thing to look at, no
 * round trip. The address bar still carries ?tab= the way theirs does,
 * so a tab can be linked to and the back button works.
 *
 * Every word is a field, the tab row included: a team added or renamed
 * is one line in the builder, not a release.
 */
function ew_hb_news_sc( $atts ) {
	$atts = shortcode_atts(
		array(
			'title'     => 'Eastwood News',
			'icon'      => '',
			'tabs'      => 'Latest | Men’s First Team | Women’s First Team | Club | Academy',
			'empty'     => 'Nothing here yet.',
			'count'     => '6',
			'link'      => '/news/',
			'link_text' => 'View all news',
		),
		$atts,
		'eastwood_home_news'
	);

	$tabs = array_values( array_filter( array_map( 'trim', explode( '|', (string) $atts['tabs'] ) ) ) );
	if ( ! $tabs ) {
		$tabs = array( 'Latest' );
	}

	$skip  = ew_hb_hero_id();
	$count = max( 1, (int) $atts['count'] );

	/*
	 * The first tab is everything. The rest are categories, matched on the
	 * words in the tab itself, so naming a tab after a category is all it
	 * takes to fill it -- no second list to keep in step.
	 */
	$panels = array();
	foreach ( $tabs as $i => $label ) {
		$args = array(
			'numberposts' => $count,
			'exclude'     => $skip ? array( $skip ) : array(),
		);
		if ( 0 !== $i ) {
			$term = get_term_by( 'name', $label, 'category' );
			if ( ! $term ) {
				$term = get_term_by( 'slug', sanitize_title( $label ), 'category' );
			}
			if ( ! $term ) {
				$panels[] = array( 'label' => $label, 'slug' => sanitize_title( $label ), 'posts' => array() );
				continue;
			}
			$args['category'] = (int) $term->term_id;
		}
		$panels[] = array(
			'label' => $label,
			'slug'  => sanitize_title( $label ),
			'posts' => get_posts( $args ),
		);
	}

	if ( empty( $panels[0]['posts'] ) ) {
		return '';
	}

	ob_start();
	?>
<section class="ewp ewp-news">
	<div class="ewp-wrap">
		<h2 class="ewp-newsH"><?php
			if ( '' !== $atts['icon'] ) {
				echo '<span class="ewp-newsIcon" aria-hidden="true">' . esc_html( $atts['icon'] ) . '</span> ';
			}
			echo esc_html( $atts['title'] ); ?></h2>

		<?php if ( count( $panels ) > 1 ) : ?>
		<div class="ewp-newsTabs" role="tablist" aria-label="<?php echo esc_attr( $atts['title'] ); ?>">
			<?php foreach ( $panels as $i => $panel ) : ?>
			<button type="button" role="tab" class="ewp-newsTab<?php echo 0 === $i ? ' is-on' : ''; ?>"
				data-tab="<?php echo esc_attr( $panel['slug'] ); ?>"
				aria-selected="<?php echo 0 === $i ? 'true' : 'false'; ?>"><?php
				echo esc_html( $panel['label'] ); ?></button>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>

		<?php foreach ( $panels as $i => $panel ) : ?>
		<div class="ewp-newsPanel" role="tabpanel" data-panel="<?php echo esc_attr( $panel['slug'] ); ?>"<?php
			echo 0 === $i ? '' : ' hidden'; ?>>
			<?php if ( empty( $panel['posts'] ) ) : ?>
			<p class="ewp-newsEmpty"><?php echo esc_html( $atts['empty'] ); ?></p>
			<?php else : ?>
			<div class="ewp-newsGrid">
				<?php foreach ( $panel['posts'] as $post_item ) : ?>
				<a class="ewp-newsCard" href="<?php echo esc_url( get_permalink( $post_item ) ); ?>">
					<?php if ( has_post_thumbnail( $post_item ) ) : ?>
					<img class="ewp-newsShot" src="<?php
						echo esc_url( get_the_post_thumbnail_url( $post_item, 'large' ) ); ?>" alt=""
						loading="lazy" decoding="async">
					<?php endif; ?>
					<span class="ewp-newsOver">
						<b><?php echo esc_html( get_the_title( $post_item ) ); ?></b>
						<i><?php
							$cats = get_the_category( $post_item->ID );
							echo esc_html( $cats ? $cats[0]->name : '' ); ?>
							<span aria-hidden="true">&middot;</span>
							<?php echo esc_html( human_time_diff( get_the_time( 'U', $post_item ),
								current_time( 'timestamp' ) ) ); ?> ago</i>
					</span>
				</a>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
		</div>
		<?php endforeach; ?>

		<?php if ( '' !== $atts['link'] ) : ?>
		<p class="ewp-allRow"><a class="ewp-all" href="<?php
			echo esc_url( home_url( '/' . ltrim( $atts['link'], '/' ) ) ); ?>"><?php
			echo esc_html( $atts['link_text'] ); ?> <span aria-hidden="true">&rarr;</span></a></p>
		<?php endif; ?>
	</div>
</section>
	<?php
	return ob_get_clean();
}
add_shortcode( 'eastwood_home_news', 'ew_hb_news_sc' );

/* ------------------------------------------------------------------ */

function ew_hb_css() {
	return '
/* The Forest next-match panel is a card on the page grey, not a full-bleed
   band: 1248px wide, with the background showing either side. */
.ewp-bannerWrap{background:#f3f3f3;padding:26px 16px 30px}
.ewp-banner{max-width:1248px;margin:0 auto;border-radius:6px;overflow:hidden;
 background:#CC0000;color:#fff;text-align:center}
.ewp-banner.has-img{position:relative;background-size:cover;background-position:center;
 background-repeat:no-repeat}
.ewp-banner.has-img::before{content:"";position:absolute;inset:0;background:rgba(10,8,8,.5)}
.ewp-banner .ewp-bannerInner{position:relative;padding:14px 16px 28px}

/* The strip across the top: whose competition, when, and the way in. */
.ewp-bannerBar{position:relative;display:flex;align-items:center;gap:14px;
 padding:16px 22px;text-align:left}
/* The competition and kick-off also appear lower down in the older layout,
   where they carry margins. Inside the strip they must not. */
.ewp-bannerBar .ewp-bannerComp,.ewp-bannerBar .ewp-bannerWhen{margin:0}
.ewp-bannerComp{display:flex;align-items:center;gap:11px;min-width:0;flex:none}
.ewp-bannerComp img{height:40px;width:auto;display:block;flex:none}
.ewp-bannerRight{display:flex;align-items:center;gap:14px;margin-left:auto;min-width:0}
.ewp-bannerCompName{font-style:normal;font-size:10px;font-weight:600;letter-spacing:.16em;
 text-transform:uppercase;color:rgba(255,255,255,.72);text-align:right;line-height:1.3;
 white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0}
.ewp-ico{width:19px;height:19px;flex:none;display:block}
.ewp-bannerComp em{font-style:normal;font-size:10px;font-weight:600;letter-spacing:.14em;
 text-transform:uppercase;color:rgba(255,255,255,.72);line-height:1.3}
.ewp-bannerWhen{display:flex;align-items:baseline;gap:9px;white-space:nowrap}
.ewp-bannerWhen b{font-family:Anton,"Instrument Sans",sans-serif;font-size:20px;
 letter-spacing:.03em;text-transform:uppercase;color:#fff;font-weight:400}
.ewp-bannerWhen i{font-style:normal;font-size:20px;font-weight:700;color:#fff}
.ewp-bannerMore{display:flex;align-items:center;flex:none;
 text-transform:uppercase;color:rgba(255,255,255,.78);text-decoration:none;white-space:nowrap}
.ewp-bannerMore:hover{color:#fff}
.ewp-bannerCentre{position:relative;margin:28px 0 0}
.ewp-bannerCentre a{display:inline-flex;align-items:center;gap:10px;
 font-size:15px;font-weight:700;letter-spacing:.03em;text-transform:uppercase;
 color:#fff;text-decoration:none}
.ewp-bannerCentre a:hover span{text-decoration:underline}
.ewp-bannerCentre .ewp-ico{width:21px;height:21px}
.ewp-bannerClock{display:flex;justify-content:center;gap:34px;margin:40px 0 28px}
.ewp-bannerClock span{display:block;text-align:center}
.ewp-bannerClock b{display:block;font-family:Anton,"Instrument Sans",sans-serif;font-size:24px;
 line-height:1;color:#fff}
.ewp-bannerClock i{display:block;margin-top:7px;font-style:normal;font-size:12px;letter-spacing:.14em;
 text-transform:uppercase;color:rgba(255,255,255,.62)}
@media(max-width:760px){
 .ewp-bannerClock{gap:18px}
 .ewp-bannerClock b{font-size:20px}
 .ewp-bannerBar{flex-wrap:wrap;gap:8px 12px;padding:14px 16px}
 .ewp-bannerComp img{height:26px}
 .ewp-bannerRight{margin-left:0;width:100%;justify-content:space-between}
 .ewp-bannerCompName{white-space:normal;text-align:left}
 .ewp-bannerCta a{min-height:46px;padding:0 20px}
 .ewp-banner .ewp-bannerInner{padding:10px 16px 16px}
 .ewp-bannerFeat{margin-top:28px}
}
.ewp-bannerInner{max-width:1180px;margin:0 auto}
.ewp-bannerComp{margin:0 0 14px;font-size:11px;font-weight:700;letter-spacing:.14em;
 text-transform:uppercase;color:rgba(255,255,255,.78)}
.ewp-bannerWhere{margin:0 0 30px;font-size:11px;font-weight:600;letter-spacing:.2em;
 text-transform:uppercase;color:rgba(255,255,255,.72)}
.ewp-bannerWhere em{font-style:normal;color:#fff;margin-right:10px}
.ewp-bannerTie{display:flex;align-items:center;justify-content:center;gap:24px;flex-wrap:wrap}
.ewp-bannerName{font-family:Anton,"Instrument Sans",sans-serif;font-size:48px;line-height:1;
 letter-spacing:.01em;text-transform:uppercase}
.ewp-bannerTie img{width:80px;height:80px;object-fit:contain}
.ewp-bannerV{font-family:Anton,sans-serif;font-size:22px;color:rgba(255,255,255,.75)}
.ewp-bannerWhen{margin:20px 0 22px;font-size:13px;letter-spacing:.12em;text-transform:uppercase;
 color:rgba(255,255,255,.85)}
.ewp-bannerWhen b{margin-left:6px}
.ewp-bannerCta{margin:0;display:flex;gap:14px;justify-content:center;flex-wrap:wrap}
.ewp-bannerCta a{display:inline-flex;align-items:center;justify-content:center;gap:11px;
 min-height:50px;padding:0 28px;font-size:13px;font-weight:700;
 letter-spacing:.12em;text-transform:uppercase;text-decoration:none;color:#fff;
 border:1px solid rgba(255,255,255,.55)}
.ewp-bannerCta a.is-primary{background:#fff;color:#CC0000;border-color:#fff}
.ewp-bannerFeat{display:flex;align-items:center;justify-content:center;gap:11px;margin:46px 0 0}
.ewp-bannerFeat > span{font-size:10px;font-weight:600;letter-spacing:.2em;text-transform:uppercase;
 color:rgba(255,255,255,.62)}
.ewp-bannerFeat a{display:inline-flex;align-items:center;gap:8px;text-decoration:none;color:#fff}
.ewp-bannerFeat img{height:22px;width:auto;display:block}
.ewp-bannerFeat b{font-size:13px;font-weight:700;letter-spacing:.04em}
.ewp-bannerFeat > i{font-style:normal;font-size:12px;color:rgba(255,255,255,.6)}
.ewp-bannerFeat > i::before{content:"\2014";margin-right:7px;color:rgba(255,255,255,.35)}
.ewp-bannerFeat a:hover b{text-decoration:underline}
.ewp-bannerCta a:hover{border-color:#fff}
@media(max-width:760px){
 .ewp-bannerName{font-size:26px}
 .ewp-bannerTie{gap:12px}
 .ewp-bannerTie img{width:52px;height:52px}
}
.ewp{font-family:"Instrument Sans",system-ui,sans-serif;color:#111}
.ewp *{box-sizing:border-box}
.ewp-wrap{max-width:1180px;margin:0 auto;padding:0 16px}
/* Break out of whatever container the page puts this in, so the column is
   1248px and lines up with the title band above it. */
.ewp-fixtures{width:100vw;margin-left:calc(50% - 50vw);margin-right:calc(50% - 50vw)}
.ewp-fixtures .ewp-wrap{max-width:1280px}
.ewp-h2{font-family:Anton,"Instrument Sans",sans-serif;font-size:54px;letter-spacing:.02em;
 line-height:1.02;text-transform:uppercase;margin:0 0 26px;text-align:center;color:#111}
.ewp-h2.is-light{color:#fff}
.ewp-h2.is-red{color:#CC0000}

/* Four section titles on the homepage are typed as Elementor headings
   rather than drawn by the block below them, so they came out 40px and
   ranged left while every title the blocks draw is 54px and centred, and
   the reference centres all of its. Two systems on one page is the fault,
   not the alignment, so the h2 headings typed onto the page
   are brought to the ones the blocks draw. Only h2: the h3s are card titles and belong ranged left.
   Colour is left alone -- a title over the dark section is white and
   that is set on the widget.

   The selector is long because it has to be. Elementor writes a size per
   widget at three classes; the first attempt at this rule was two classes
   and a tag, lost the tie, and the four stayed 40px while picking up the
   centring, which nothing was competing for. Size now comes from here for
   every section title, so the size box on these four headings in the
   editor no longer does anything -- which is the point of having one. */
.elementor-template-full-width .elementor-widget.elementor-widget-heading
 h2.elementor-heading-title{
 font-family:Anton,"Instrument Sans",sans-serif;font-size:54px;letter-spacing:.02em;
 line-height:1.02;text-transform:uppercase;margin:0 0 26px;text-align:center}
@media(max-width:820px){
 .elementor-template-full-width .elementor-widget.elementor-widget-heading
  h2.elementor-heading-title{font-size:30px;margin-bottom:20px}
}
.ewp-allRow{margin:30px 0 0;text-align:center}
.ewp-all{font-family:Anton,"Instrument Sans",sans-serif;font-size:14px;letter-spacing:.09em;
 text-transform:uppercase;color:#CC0000;text-decoration:none;
 border-bottom:1px solid rgba(204,0,0,.4);padding-bottom:3px}
.ewp-all:hover{border-bottom-color:#CC0000}
.ewp-allRow.is-light .ewp-all{color:#fff;border-bottom-color:rgba(255,255,255,.45)}
.ewp-allRow.is-light .ewp-all:hover{border-bottom-color:#fff}

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

.ewp-fixtures{background:#f3f3f3}
.ewp-fixturesBody{padding:36px 0 48px}

/* Photo tiles — Discover, Hospitality, the video row, anything of that shape. */
.ewp-tiles{background:#f3f3f3;padding:48px 0 54px}
.ewp-tiles.is-dark{background:#111}
/* The reference runs its video band in the same red as the fixtures
   band rather than in black. Everything else about the dark tone is
   right for it, so the red tone borrows all of it. */
.ewp-tiles.is-red{background:#990000}
.ewp-tileGrid{display:grid;grid-template-columns:repeat(var(--ewp-cols,4),1fr);gap:14px;
 list-style:none;margin:0;padding:0}
.ewp-tileGrid a{display:block;text-decoration:none;color:inherit}
.ewp-tileImg{display:block;position:relative;aspect-ratio:3/4;border-radius:5px;overflow:hidden;
 background:#1a1a1d;margin:0 0 11px}
.ewp-tiles.is-dark .ewp-tileImg,.ewp-tiles.is-red .ewp-tileImg{background:#17171a}
.ewp-tileImg img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .35s}
.ewp-tileGrid a:hover .ewp-tileImg img{transform:scale(1.04)}
.ewp-tileImg::after{content:"";position:absolute;inset:0;
 background:linear-gradient(to top,rgba(0,0,0,.72) 0%,rgba(0,0,0,0) 48%)}
.ewp-tileMeta{position:absolute;right:9px;top:9px;z-index:2;font-style:normal;font-size:11px;
 font-weight:700;letter-spacing:.04em;color:#fff;background:rgba(0,0,0,.72);
 padding:4px 8px;border-radius:3px}
.ewp-tilePlay{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);z-index:2;
 width:46px;height:46px;border-radius:50%;border:2px solid rgba(255,255,255,.9)}
.ewp-tilePlay::before{content:"";position:absolute;left:52%;top:50%;transform:translate(-50%,-50%);
 border-style:solid;border-width:9px 0 9px 15px;border-color:transparent transparent transparent #fff}
.ewp-tileGrid b{display:block;font-family:Anton,"Instrument Sans",sans-serif;font-size:16px;
 letter-spacing:.04em;text-transform:uppercase;color:#111;line-height:1.2}
.ewp-tiles.is-dark .ewp-tileGrid b,.ewp-tiles.is-red .ewp-tileGrid b{color:#fff}
.ewp-tileGrid b span{color:#CC0000;margin-left:4px}
.ewp-tileSub{display:block;font-style:normal;font-size:13px;color:#6b6b6b;margin-top:5px;line-height:1.4}
.ewp-tiles.is-dark .ewp-tileSub,.ewp-tiles.is-red .ewp-tileSub{color:rgba(255,255,255,.78)}

/* Partners, pulled from the sponsor store rather than typed again. */
.ewp-sponsors{background:#fff;padding:46px 0 52px}
.ewp-sponsorLabel{margin:26px 0 18px;text-align:center;font-size:11px;font-weight:700;
 letter-spacing:.16em;text-transform:uppercase;color:#9a9aa3}
.ewp-sponsorRow{display:flex;flex-wrap:wrap;align-items:center;justify-content:center;
 gap:26px 40px;list-style:none;margin:0;padding:0}
/* The theme puts a marker on every li, which beat list-style on the
   list itself and left a bullet beside each logo. */
.ewp-sponsorRow li{list-style:none;margin:0;padding:0}
.ewp-sponsorRow li::marker{content:""}
.ewp-sponsorRow img{height:46px;width:auto;max-width:170px;object-fit:contain;display:block;
 opacity:.88;transition:opacity .15s}
.ewp-sponsorRow.is-lead img{height:66px;max-width:230px}
.ewp-sponsorRow a:hover img{opacity:1}
/* Only worth turning red once every logo exists as a white version —
   a colour logo on red flattens to a blank rectangle. */
.ewp-sponsors.is-red{background:#CC0000}
.ewp-sponsors.is-red .ewp-sponsorLabel{color:rgba(255,255,255,.75)}
.ewp-sponsors.is-red .ewp-sponsorRow img{filter:brightness(0) invert(1)}
/* The .com under KAM, same reasoning as the rail and the wall in the
   chrome stylesheet: their artwork has no web address that survives
   being shrunk, so it is typeset in the heavy condensed italic their
   own lettering is cut in, right aligned under the badge. The badge
   gives up the height, so the row does not grow. */
.ewp-sponsorRow li:has(img[src*="/ew-sponsors/kam"]){display:flex;flex-direction:column;
 justify-content:flex-start}
.ewp-sponsorRow li:has(img[src*="/ew-sponsors/kam"]) img{height:32px}
.ewp-sponsorRow.is-lead li:has(img[src*="/ew-sponsors/kam"]) img{height:46px}
.ewp-sponsorRow li:has(img[src*="/ew-sponsors/kam"])::after{content:".com";display:block;
 font:italic 700 20px/11px "Barlow Condensed","Instrument Sans",sans-serif;
 color:#101010;text-align:right;margin-top:3px}
.ewp-sponsorRow.is-lead li:has(img[src*="/ew-sponsors/kam"])::after{
 font-size:30px;line-height:15px;margin-top:5px}
.ewp-sponsors.is-red .ewp-sponsorRow li:has(img[src*="/ew-sponsors/kam"])::after{color:#fff}

/* The matches page: a month, then one full-width panel per fixture.
   Same tie layout as the banner, white instead of a photograph. */
.ewp-month{margin:30px 0 14px;text-align:center;font-size:18px;font-weight:700;color:#212121}
.ewp-panel > .ewp-bannerWrap{margin:0 0 6px;padding-left:0;padding-right:0}
.ewp-credit{max-width:1248px;margin:0 auto;padding:26px 16px 44px;text-align:center;
 font-size:12px;color:#9a9aa3}
.ewp-rows{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:14px}
.ewp-row{background:#fff;border-radius:8px;overflow:hidden}
/* The strip is 81px on the reference, with the competition crest in a 48px
   box, the date at 20px regular and the kick-off at 20px bold. */
.ewp-rowBadge{display:flex;align-items:center;justify-content:center;
 width:48px;height:48px;flex:none;margin-right:4px}
.ewp-rowBadge img{max-width:100%;max-height:100%;width:auto;height:auto;object-fit:contain;display:block}
.ewp-rowRight{display:flex;align-items:center;gap:12px;margin-left:auto;min-width:0}
.ewp-rowMore{display:flex;align-items:center;flex:none;color:#212121;opacity:.6}
.ewp-rowMore:hover{opacity:1}
.ewp-rowFoot{display:flex;align-items:center;justify-content:center;gap:10px;
 min-height:49px;padding:10px 16px;background:#fafafa;border-top:1px solid rgba(200,200,200,.3)}
.ewp-rowFoot span{font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#101010}
/* The play mark is white artwork, drawn for the dark hero. On this strip
   it sits on near-white and disappears, so it is flattened to black.
   brightness(0) keeps the alpha and ignores the source colour. */
.ewp-rowFoot img{height:18px;width:auto;display:block;filter:brightness(0)}
.ewp-rowFoot b{font-size:13px;font-weight:700;letter-spacing:.02em;color:#101010}
.ewp-rowFoot i{font-style:normal;font-size:12px;color:#8a8a8a}
.ewp-rowFoot i::before{content:"\2014";margin-right:7px;color:#c9c9c9}
.ewp-moreWrap{margin:18px 0 0;text-align:center}
.ewp-more{display:inline-flex;align-items:center;gap:9px;min-height:50px;padding:12px 26px;
 background:#fff;border:1px solid #d9d9dd;border-radius:8px;cursor:pointer;
 font-family:"Instrument Sans",system-ui,sans-serif;font-size:15px;font-weight:700;
 letter-spacing:.02em;text-transform:uppercase;color:#101010}
.ewp-more:hover{border-color:#CC0000;color:#CC0000}
.ewp-more b{font-weight:700;color:#8a8a8a}
.ewp-more:hover b{color:#CC0000}
.ewp-rest[hidden]{display:none}
.ewp-rowTop{display:flex;align-items:center;gap:16px;min-height:81px;padding:16px;background:#fafafa;
 border-bottom:1px solid #efefef}
.ewp-rowTop b{font-family:"Instrument Sans",system-ui,sans-serif;font-weight:400;font-size:20px;
 letter-spacing:normal;
 text-transform:uppercase;color:#111}
.ewp-rowTop i{font-style:normal;font-size:20px;font-weight:700;color:#101010;margin-left:-4px}
.ewp-rowComp{font-size:14px;font-weight:400;letter-spacing:.025em;white-space:nowrap;
 overflow:hidden;text-overflow:ellipsis;min-width:0;
 text-transform:uppercase;color:#9a9aa3;text-align:right}
.ewp-rowBody{padding:26px 22px 30px;text-align:center}
.ewp-rowWhere{margin:0 0 22px;font-size:10px;font-weight:400;letter-spacing:normal;
 text-transform:uppercase;color:#9a9aa3}
.ewp-rowWhere em{font-style:normal;font-weight:700;color:#101010;margin-right:9px}

.ewp-tie{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:18px}
.ewp-tieName{font-family:Anton,"Instrument Sans",sans-serif;font-weight:700;font-size:40px;
 line-height:1.02;letter-spacing:normal;
 letter-spacing:.01em;text-transform:uppercase;color:#111}
.ewp-tieName{text-align:center}
.ewp-tieMid{display:flex;align-items:center;gap:22px}
.ewp-tieMid img{width:80px;height:80px;object-fit:contain;flex:none}
.ewp-tieV{font-family:"Instrument Sans",system-ui,sans-serif;font-size:48px;line-height:1;
 color:#101010;font-weight:700}
.ewp-tieScore{font-family:Anton,sans-serif;font-size:26px;color:#111;font-weight:400;white-space:nowrap}

.ewp-rowCta{margin:26px 0 0;display:flex;gap:8px;justify-content:center;flex-wrap:wrap}
.ewp-rowCta a{display:inline-flex;align-items:center;justify-content:center;gap:10px;
 min-height:50px;padding:12px 14px;border-radius:8px;text-transform:uppercase;font-size:16px;
 font-weight:700;letter-spacing:.03em;text-decoration:none;border:1px solid #CC0000;color:#CC0000}
.ewp-rowCta a.is-primary{background:#CC0000;color:#fff}
.ewp-rowCta a:hover{background:#CC0000;color:#fff}
.ewp-rowCentre{margin:16px 0 0}
.ewp-rowCentre a{display:inline-flex;align-items:center;justify-content:center;gap:10px;
 min-height:50px;padding:12px 14px;color:#3d0000;
 font-size:16px;font-weight:700;letter-spacing:normal;text-transform:uppercase;
 color:#111;text-decoration:none}
.ewp-rowCentre a:hover span{text-decoration:underline}
.ewp-rowCentre .ewp-ico,.ewp-rowCta .ewp-ico{width:24px;height:24px}
.ewp-rowMore .ewp-ico{width:24px;height:24px}

/* The next fixtures as one thin band directly under the nav, which is the
   first thing the reference puts on its homepage.

   Measured off the reference twice, at 1440 and at 1512. The band is 109px
   and full width. Four cells show at a time, each a quarter of a track that
   starts 52px in and stops 102px short of the right edge, so a cell is
   322px at 1440 and 340px at 1512 -- a share, not a fixed width, which the
   first pass got wrong. They butt together with a hairline between them.
   The date runs across the whole top of each cell on a 41px tint: 18px
   light for the day, 18px bold for the kick-off. The crest is 48px. Home
   and away is a 16px disc carrying a glyph, red for home and grey for away.
   There is no red rule under the next game -- that was my own invention,
   and it is gone. Every number below is one of those measurements. */
.ewp-strip{position:relative;background:#fff;
 margin:0 calc(50% - 50vw);padding:0 0 0 52px;
 border-bottom:1px solid rgba(0,0,0,.09)}
/* The 52px the cards are pushed in by was left white, so the grey band
   across the dates stopped short of the left edge. The reference fills
   that gutter with the same two tones the card is made of -- grey down
   to 41px, white under it -- so the band reads as one strip the width
   of the window. Theirs is .fixture-carousel__fill-before. */
.ewp-strip::before{content:"";position:absolute;left:0;top:0;bottom:0;width:52px;
 background:linear-gradient(to bottom,rgba(0,0,0,.03) 41px,#fff 41px);
 pointer-events:none}
.ewp-stripList{display:flex;list-style:none;margin:0;padding:0;overflow-x:auto;cursor:grab;
 scroll-behavior:smooth;scroll-snap-type:x proximity;scrollbar-width:none;-ms-overflow-style:none}
.ewp-stripList::-webkit-scrollbar{display:none}
.ewp-strip .ewp-stripNav{position:absolute;top:38px;z-index:20;
 width:32px;height:32px;padding:0;border:0;border-radius:50%;background:#fff;color:#101010;
 display:flex;align-items:center;justify-content:center;cursor:pointer;
 box-shadow:0 20px 25px -5px rgba(0,0,0,.1),0 8px 10px -6px rgba(0,0,0,.1)}
.ewp-strip .ewp-stripNav[hidden]{display:none}
.ewp-strip .ewp-stripNav.is-prev{left:36px}
.ewp-strip .ewp-stripNav.is-next{right:68px}
.ewp-strip .ewp-slide{flex:0 0 calc((100% - 102px)/4);max-width:calc((100% - 102px)/4);
 scroll-snap-align:start;
 /* The line between cells is a grey the reference states outright --
    #9ca3af -- not black at nine percent, which came out far paler. */
 border-right:1px solid #9ca3af;position:relative}
.ewp-strip .ewp-card{display:flex;flex-direction:column;gap:0;min-height:109px;height:100%;
 padding:0;background:#fff;border:0;border-radius:0;box-shadow:none;position:relative}
/* The red line under a card you are pointing at.
 *
 * The reference pins a 4px bar of club red to the bottom of the cell,
 * holds it at scaleX(0) and runs it out to full width from the centre
 * over half a second. Measured off it: height, colour, origin, duration
 * and the easing curve are all theirs. */
.ewp-strip .ewp-card::after{content:"";position:absolute;left:0;right:0;bottom:0;height:4px;
 background:#CC0000;transform:scaleX(0);transform-origin:50% 50%;
 transition:transform .5s cubic-bezier(.4,0,.2,1)}
.ewp-strip .ewp-card:hover::after,
.ewp-strip .ewp-card:focus-visible::after{transform:scaleX(1)}
@media (prefers-reduced-motion:reduce){
 .ewp-strip .ewp-card::after{transition:none}
}
/* The date runs the full width of the cell on a tint, not in an inset box. */
.ewp-strip .ewp-cardTop{display:flex;align-items:center;justify-content:space-between;
 gap:10px;margin:0;padding:0 16px;min-height:41px;background:rgba(0,0,0,.03);border:0;border-radius:0}
.ewp-strip .ewp-when{display:flex;align-items:baseline;gap:8px}
/* The day is NOT in Anton. The reference sets it in the body face at its
   lightest weight, with no letter-spacing, against the bold kick-off
   beside it -- light then heavy is the whole effect of that line. Ours
   was picking up Anton and .03em from the rule the match banner uses,
   further down this sheet, which the font-size here never overrode. */
.ewp-strip .ewp-when b{font-family:"Instrument Sans",system-ui,sans-serif;
 font-size:18px;font-weight:100;letter-spacing:normal;line-height:25px;color:#101010}
/* margin-left:0 because .ewp-when i further down this sheet, which the
   match banner uses, adds 7px of its own -- so the kick-off was sitting
   15px after the day where the reference puts it 8px after. */
.ewp-strip .ewp-when i{font-family:"Instrument Sans",system-ui,sans-serif;
 font-size:18px;font-weight:700;font-style:normal;line-height:25px;color:#101010;
 margin-left:0}
/* The disc carries a LETTER, not a picture.
 *
 * The reference draws an H or an A in it -- its two icon files, which look
 * like pictograms from their names, are each a single glyph path, the
 * letter. This drew a little house for home and an arrow for away, which
 * is what Ben has been looking at and calling the arrows. The letters were
 * in our markup all along, hidden behind font-size:0 while the background
 * image sat on top of them.
 *
 * Away is a grey disc with a near-black letter. Only home is red. */
.ewp-strip .ewp-ha{width:16px;height:16px;flex:none;border:0;border-radius:50%;
 display:flex;align-items:center;justify-content:center;
 font-family:"Instrument Sans",system-ui,sans-serif;
 font-size:11px;font-weight:700;line-height:1;letter-spacing:normal;
 background-image:none;
 /* The disc is centred in a 41px band, which puts it on the half pixel;
    the reference rounds that up the way this margin does. */
 margin-bottom:1px}
.ewp-strip .ewp-ha.is-home{background-color:#CC0000;color:#fff;background-image:none}
.ewp-strip .ewp-ha.is-away{background-color:#e8e8e8;color:#101010;background-image:none}
.ewp-strip .ewp-cardBody{display:flex;align-items:center;gap:16px;margin:0;padding:0 16px;flex:1}
.ewp-strip .ewp-cardBody img{width:48px;height:48px;object-fit:contain;flex:none}
/* The three lines of the card: kicker, opponent, ground. The reference
   leaves 4px between them and the gaps here were falling out of the line
   boxes at 3, which shifted the block a pixel and left it two short. A
   column with a stated gap says it once. */
.ewp-strip .ewp-cardText{min-width:0;display:flex;flex-direction:column;gap:4px}
/* margin:0 as well as the gap. The kicker and the opponent each carry
   3px of bottom margin from elsewhere, which was adding to the 4px gap
   and making it 7. */
.ewp-strip .ewp-cardText > *{display:block;margin:0}
.ewp-strip .ewp-kicker{font-size:10px;font-weight:700;letter-spacing:1px;line-height:10px;
 color:#212121;text-transform:uppercase}
.ewp-strip .ewp-cardText b{font-size:18px;line-height:1;display:block;
 white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ewp-strip .ewp-venue{font-size:14px;line-height:16px;color:#101010}
/* Elementor wraps every widget in a container that adds 10px above and
   below. On the reference nothing sits between the nav, the band and the
   bar under it, and that 10px was reading as a stray line under the
   strip. The block cancels its own wrapper rather than relying on anyone
   remembering to zero it in the editor. */
.e-con-inner:has(> .elementor-widget .ewp-strip),
.e-con-inner:has(> .elementor-widget .ewp-promo){padding-top:0;padding-bottom:0;gap:0}
.e-con:has(> .e-con-inner > .elementor-widget .ewp-strip),
.e-con:has(> .e-con-inner > .elementor-widget .ewp-promo){padding-top:0;padding-bottom:0}


@media(max-width:1100px){
 .ewp-strip .ewp-slide{flex:0 0 calc((100% - 72px)/3);max-width:calc((100% - 72px)/3)}
}
@media(max-width:700px){
 .ewp-strip{padding:0}
 .ewp-strip .ewp-slide{flex:0 0 264px;max-width:264px}
}

/* The bar the reference runs under the strip. One line, one link, and
   both come from Promo bar in the admin menu so anyone can change them.
   Measured off the reference: 36px tall, 18px Anton in caps on #CC0000,
   7px above and below the line. */
.ewp-promo{display:block;background:#CC0000;color:#fff;text-decoration:none;
 margin:0 calc(50% - 50vw)}
.ewp-promo b{display:block;padding:7px 16px;text-align:center;font-weight:400;
 font-family:Anton,sans-serif;font-size:18px;line-height:22px;
 text-transform:uppercase;letter-spacing:.01em;color:#fff}
.ewp-promo:hover b{text-decoration:underline}
.ewp-promo:focus-visible{outline:3px solid #fff;outline-offset:-5px}

/* The slot the reference gives over to one big promotional graphic. Ours is
   type rather than artwork, because artwork would have to be made for every
   game. Red while the game is on, black while it is still to come. */
.ewp-live{background:#101010;color:#fff}
.ewp-live.is-live{background:#CC0000}
.ewp-liveInner{max-width:1280px;margin:0 auto;padding:38px 16px;
 display:flex;align-items:stretch;gap:26px;flex-wrap:wrap}
.ewp-liveLeft{flex:1 1 380px;min-width:0;display:flex;flex-direction:column;justify-content:center}
.ewp-liveEyebrow{display:flex;align-items:center;gap:10px;margin:0 0 12px;
 font-size:12px;letter-spacing:.2em;text-transform:uppercase;color:rgba(255,255,255,.85)}
.ewp-liveDot{width:9px;height:9px;border-radius:50%;background:#CC0000;flex:none}
.ewp-live.is-live .ewp-liveDot{background:#fff;box-shadow:0 0 0 4px rgba(255,255,255,.28)}
.ewp-liveHead{font-family:Anton,"Instrument Sans",sans-serif;font-weight:400;
 font-size:clamp(40px,7vw,86px);line-height:.84;letter-spacing:.004em;
 text-transform:uppercase;margin:0;color:#fff}
.ewp-liveHead em{display:block;font-style:normal;font-size:.3em;letter-spacing:.26em;margin:.55em 0 .2em}
.ewp-liveHead strong{display:block;font-weight:400;font-size:1.46em;line-height:.78}
.ewp-liveLines{display:flex;flex-wrap:wrap;gap:0;margin:24px 0 0;padding:0;list-style:none}
.ewp-liveLines li{font-size:15px;font-weight:600;padding:0 15px;border-left:1px solid rgba(255,255,255,.4)}
.ewp-liveLines li:first-child{padding-left:0;border-left:0}
.ewp-liveRight{flex:0 0 auto;display:flex;flex-direction:column;gap:14px;justify-content:center}
.ewp-liveFix{background:rgba(0,0,0,.3);padding:20px 24px;min-width:250px}
.ewp-live.is-live .ewp-liveFix{background:#101010}
.ewp-liveComp{margin:0 0 9px;font-size:10px;letter-spacing:.16em;text-transform:uppercase;color:rgba(255,255,255,.72)}
.ewp-liveTie{font-family:Anton,"Instrument Sans",sans-serif;font-size:25px;line-height:1.1;
 text-transform:uppercase;margin:0;color:#fff}
.ewp-liveWhen{margin:9px 0 0;font-size:14px;color:rgba(255,255,255,.88);font-weight:500}
.ewp-liveCta{display:inline-flex;align-items:center;justify-content:center;min-height:50px;
 padding:0 26px;border-radius:8px;background:#fff;color:#101010;text-decoration:none;
 font-weight:700;font-size:16px;text-transform:uppercase;letter-spacing:.02em;align-self:flex-start}
@media(max-width:700px){.ewp-liveInner{padding:28px 16px}.ewp-liveRight{width:100%}}

/* The club sides, on white above everything else, the Forest shape. */
.ewp-teamBar{background:#fff}
.ewp-teamTabs{display:flex;flex-wrap:wrap;gap:26px;overflow-x:auto;scrollbar-width:none}
.ewp-teamTabs::-webkit-scrollbar{display:none}
.ewp-teamTabs button{appearance:none;background:none;border:0;cursor:pointer;white-space:nowrap;
 padding:16px 0 13px;border-bottom:3px solid transparent;margin-bottom:-1px;
 font-family:Anton,"Instrument Sans",sans-serif;font-size:18px;letter-spacing:.04em;
 text-transform:uppercase;color:#6f6f6f;transition:color .15s}
.ewp-teamTabs button:hover{color:#101010}
.ewp-teamTabs button[aria-selected="true"]{color:#101010;border-bottom-color:#CC0000}
/* Clicking a tab left a blue focus ring from the browser sitting on it.
   Keyboard users still need to see where they are, so the ring is kept for
   them and drawn in club red rather than that blue. */
.ewp-teamTabs button:focus{outline:none}
.ewp-teamTabs button:focus-visible{outline:2px solid #CC0000;outline-offset:3px;border-radius:2px}

/* Fixtures / Results / Table, centred under them. */
.ewp-tabs{display:flex;justify-content:center;gap:26px;margin:0 0 26px}
.ewp-tabs button{appearance:none;background:none;border:0;cursor:pointer;
 padding:6px 4px 9px;border-bottom:3px solid transparent;
 font-family:"Instrument Sans",system-ui,sans-serif;font-size:18px;font-weight:700;
 color:#1e293b;transition:color .15s}
.ewp-tabs button:hover{color:#000}
.ewp-tabs button[aria-selected="true"]{color:#000;border-bottom-color:#CC0000}
.ewp-panel[hidden]{display:none}
[data-pv-panel][hidden]{display:none}
.ewp-empty{max-width:1180px;margin:0 auto;padding:30px 16px;color:#6b6b6b;font-size:15px}
.ewp-empty.is-center{text-align:center}

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
.ewp-ha{width:19px;height:19px;border-radius:50%;background:#CC0000;color:#fff;font-size:10px;
 font-weight:700;display:flex;align-items:center;justify-content:center;flex:none;
 border:1.5px solid #CC0000}
.ewp-ha.is-away{background:transparent;color:#CC0000}
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

.ewp-h2.is-center{justify-content:center;text-align:center}
.ewp-h2.is-red{color:#CC0000}
.ewp-empty.is-light{color:rgba(255,255,255,.72);text-align:center}

/* Upcoming Matches — the red band under the news. */
.ewp-upcoming{background:#990000;padding:44px 0 48px}
/* The reference lays these three cards in the same 1248 column as the rest
   of the site, which makes each one 405 wide. Ours was in the 1148 column
   this sheet uses by default, so they came out 375. */
.ewp-upcoming .ewp-wrap{max-width:1280px}
/* The Upcoming Matches card.
 *
 * Measured off the reference, which runs a different and bigger card here
 * than in the strip at the top: 405 by 215 in a 1248 column, three across
 * with 16px between them. It carries the competition badge, a line saying
 * what the card is, the date and kick-off under that, and a ring at the
 * right rather than the filled disc the strip uses. The crest is 72, not 48.
 *
 * Ours was the narrow cell from the strip blown up, with no badge, no label and
 * the day set in Anton where the reference uses the body face.
 */
.ewp-upcomingGrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(272px,1fr));
 gap:16px;list-style:none;margin:0;padding:0}
.ewp-upcoming .ewp-card:hover{box-shadow:0 3px 18px rgba(0,0,0,.28)}
/* Square, like the reference. The 8px rounding was clipping the top
   corners of the grey band, so the red behind the section showed
   through where the band should reach the edge. */
.ewp-upcomingGrid .ewp-slide{display:flex;flex-direction:column;flex:1 1 auto;max-width:none;
 background:#fff;border-radius:0;overflow:hidden}
/* A flex column, so the body below can take the height the row gives the
   card. As a plain block it could not: the card stretched to fill the row
   and left the extra as empty space under the venue. */
.ewp-upcomingGrid .ewp-card{flex:1 1 auto;border-radius:0;display:flex;flex-direction:column}

.ewp-upcomingGrid .ewp-cardTop{display:flex;align-items:center;gap:8px;
 padding:12px 24px;min-height:65px;background:#fff;border-bottom:1px solid #f3f3f3}
.ewp-upcomingGrid .ewp-cardComp{width:32px;height:40px;object-fit:contain;flex:none}
/* The label on its own line, then the day and the kick-off side by side
   under it -- a wrapping row, not a column, or the two end up stacked. */
.ewp-upcomingGrid .ewp-when{display:flex;flex-wrap:wrap;align-items:baseline;
 gap:4px 7px;flex:1 1 auto;min-width:0}
.ewp-upcomingGrid .ewp-cardLabel{display:block;font-family:"Instrument Sans",system-ui,sans-serif;
 font-style:normal;font-size:12px;font-weight:700;line-height:16px;color:#101010}
.ewp-upcomingGrid .ewp-when b{font-family:"Instrument Sans",system-ui,sans-serif;
 font-size:14px;font-weight:400;line-height:16px;letter-spacing:normal;color:#212121}
.ewp-upcomingGrid .ewp-when i{font-family:"Instrument Sans",system-ui,sans-serif;
 font-size:14px;font-weight:700;font-style:normal;line-height:16px;color:#212121;margin-left:7px}
.ewp-upcomingGrid .ewp-cardLabel{flex:0 0 100%}
.ewp-upcomingGrid .ewp-when i{margin-left:0}
/* A ring here, not a filled disc. */
.ewp-upcomingGrid .ewp-ha{width:24px;height:24px;flex:none;border-radius:50%;
 display:flex;align-items:center;justify-content:center;align-self:flex-start;margin-top:8px;
 font-family:"Instrument Sans",system-ui,sans-serif;font-size:12px;font-weight:400;line-height:1;
 background:#fff;border:1px solid #CC0000;color:#CC0000;background-image:none}
/* Home is filled, away is the outline. The reference leaves only the
   away mark hollow; both were hollow here, so the two read the same. */
.ewp-upcomingGrid .ewp-ha.is-home{background:#CC0000;color:#fff;border-color:#CC0000}
.ewp-upcomingGrid .ewp-ha.is-away{background:#fff;color:#CC0000;border-color:#CC0000}

/* flex:1 so the body takes whatever height the row gives the card and the
   crest sits in the middle of it. Without it the body was only as tall as
   the crest, which left the whole lower half of a card pinned to the top
   with white space under it -- the reference centres it.

   Then measured properly, twice. The reference card is 215px and ours,
   once the stray border below was gone, is 216 -- so the height was
   never the problem. What was: the reference centres the crest in a box
   8px shorter than the lower half, so its crest sits 23px down where
   ours sat 27. Twenty at the foot instead of twelve reproduces that,
   and the eight it adds to the height of a card carrying a Tickets
   button is taken back off that button below, so every card stays the
   height of the reference. Crest 100, lines 111, 125 and 149. */
.ewp-upcomingGrid .ewp-cardBody{display:flex;align-items:center;gap:16px;
 padding:12px 24px 20px;flex:1 1 auto}
/* The eight the foot of the body just took, given back, so a card with a
   button is the same height as a card without one. Six, not seven:
   measured live the card came out at 216 against the reference 215. */
.ewp-upcomingGrid .ewp-cardCta{margin-bottom:6px}
/* No border here: the reference card has none, and this one was adding
   3px to the height of every card. The hover line is drawn with ::after. */
.ewp-upcomingGrid .ewp-card{border-bottom:0}
.ewp-upcomingGrid .ewp-cardBody img{width:72px;height:72px;object-fit:contain;flex:none}
.ewp-upcomingGrid .ewp-cardText{display:flex;flex-direction:column;gap:4px;min-width:0;
 flex:1 1 auto}
.ewp-upcomingGrid .ewp-cardText > *{display:block;margin:0}
.ewp-upcomingGrid .ewp-kicker{font-size:10px;font-weight:700;letter-spacing:1px;line-height:10px;
 color:#212121;text-transform:uppercase}
.ewp-upcomingGrid .ewp-cardText b{font-family:Anton,"Instrument Sans",sans-serif;
 font-size:20px;line-height:20px;color:#101010}
.ewp-upcomingGrid .ewp-venue{font-size:12px;line-height:12px;font-weight:400;color:#212121}
.ewp-cardCta{display:block;margin:0 14px 15px;padding:11px 14px;background:#CC0000;color:#fff;
 font-size:12px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;text-align:center;
 text-decoration:none;border-radius:3px;transition:background .15s}
.ewp-cardCta:hover{background:#b00000}
/* The team pills, as Forest run them on the red band. */
.ewp-pills{display:flex;flex-wrap:wrap;gap:2px;margin:0 auto 22px;padding:3px;
 background:rgba(255,255,255,.14);border-radius:4px;width:fit-content;max-width:100%}
.ewp-pills button{appearance:none;border:0;background:none;cursor:pointer;border-radius:3px;
 padding:9px 17px;font-family:Anton,"Instrument Sans",sans-serif;font-size:14px;
 letter-spacing:.07em;text-transform:uppercase;color:rgba(255,255,255,.72);
 transition:background .15s,color .15s}
.ewp-pills button:hover{color:#fff}
.ewp-pills button[aria-selected="true"]{background:#fff;color:#CC0000}
.ewp-upcomingAll{margin:28px 0 0;text-align:center}
.ewp-upcomingAll a{font-family:Anton,"Instrument Sans",sans-serif;font-size:14px;letter-spacing:.09em;
 text-transform:uppercase;color:#fff;text-decoration:none;border-bottom:1px solid rgba(255,255,255,.45);
 padding-bottom:3px}
.ewp-upcomingAll a:hover{border-bottom-color:#fff}

/* Get Kitted Out — typed-in tiles until there is a real store. */
.ewp-shop{background:#f3f3f3;padding:44px 0 50px}
.ewp-shopGrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));
 gap:14px;list-style:none;margin:0;padding:0}
.ewp-shopGrid a{display:block;text-decoration:none;color:#111;text-align:center}
.ewp-shopImg{display:flex;align-items:center;justify-content:center;aspect-ratio:1/1;
 background:#e8e8e8;border-radius:4px;overflow:hidden;margin:0 0 12px;transition:background .15s}
.ewp-shopGrid a:hover .ewp-shopImg{background:#dedede}
.ewp-shopImg img{width:100%;height:100%;object-fit:cover;display:block}
.ewp-shopGrid b{display:block;font-size:14px;font-weight:600;line-height:1.35;margin:0 0 4px}
.ewp-shopGrid i{font-style:normal;font-size:14px;color:#6b6b6b}
.ewp-shopAll{margin:30px 0 0;text-align:center}
.ewp-shopAll a{font-family:Anton,"Instrument Sans",sans-serif;font-size:14px;letter-spacing:.09em;
 text-transform:uppercase;color:#CC0000;text-decoration:none;border-bottom:1px solid rgba(204,0,0,.4);
 padding-bottom:3px}
.ewp-shopAll a:hover{border-bottom-color:#CC0000}

.ewp-discover{background:#111;padding:46px 0 52px}
.ewp-discoverGrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:10px}
.ewp-discoverGrid a{display:block;position:relative;background:#17171a;border-radius:4px;
 padding:22px 46px 22px 22px;text-decoration:none;transition:background .15s}
.ewp-discoverGrid a:hover{background:#1f1f24}
.ewp-discoverGrid b{display:block;font-family:Anton,"Instrument Sans",sans-serif;font-size:21px;
 letter-spacing:.03em;text-transform:uppercase;color:#fff;margin:0 0 5px}
.ewp-discoverGrid i{font-style:normal;font-size:13px;color:#9a9aa3}
.ewp-discoverGrid span{position:absolute;right:20px;top:24px;color:#CC0000;font-size:19px}

/* Measured off the reference, not eyeballed. Section on the page grey,
   a 1248 column, the heading Anton 56 in the club red with its own icon
   above a white 40px tab rail. */
/* The reference runs the grey the full width of the window with a 1248
   column inside it. Ours sat in an Elementor row 1180 wide, so the grey
   stopped short either side and the cards came out 372 instead of 405.
   The row cannot be widened from here without moving every other
   section in it, so this section steps outside its own column. */
/* Discover: a rail of portrait photographs, measured off the reference
   at 1512 wide -- cards 300 by 450 with an 8px corner and a 16px gap,
   the first landing on the 1248 column, the label Anton 20 in white 16
   in from the bottom left, a 32px round chevron centred on the edge of
   that column, and the heading Anton 56 in red with 48 of air under it.
   The band is the same #f3f3f3 as the news band and runs the full width
   of the window, so it steps out of the Elementor column the same way. */
.ewp-disc{position:relative;background:#f3f3f3;padding:32px 0;
 width:100vw;margin-left:calc(50% - 50vw);margin-right:calc(50% - 50vw)}
.ewp-discH{font-family:Anton,"Instrument Sans",sans-serif;font-size:56px;line-height:56px;
 font-weight:400;color:#CC0000;text-align:center;text-transform:uppercase;margin:0 0 48px}
.ewp-discRail{position:relative}
.ewp-discList{display:flex;gap:16px;list-style:none;margin:0;
 padding:0 max(16px,calc(50vw - 624px));overflow-x:auto;cursor:grab;
 scroll-behavior:smooth;scrollbar-width:none;-ms-overflow-style:none}
.ewp-discList::-webkit-scrollbar{display:none}
.ewp-discSlide{flex:0 0 300px;max-width:300px;list-style:none;margin:0;padding:0}
.ewp-discSlide::marker{content:""}
.ewp-discCard{position:relative;display:block;width:300px;height:450px;border-radius:8px;
 overflow:hidden;background:#101010;text-decoration:none;color:#fff}
.ewp-discCard img.ewp-discShot{position:absolute;inset:0;width:100%;height:100%;
 object-fit:cover;max-width:none;display:block;
 transition:transform .5s cubic-bezier(.4,0,.2,1)}
/* The reference darkens the foot of every card so a white label reads
   over grass as well as it does over a floodlit crowd: sampled down one
   of their cards it falls from 50 to 17 in the last 90 pixels. */
.ewp-discCard::after{content:"";position:absolute;inset:0;
 background:linear-gradient(to top,rgba(0,0,0,.72) 0%,rgba(0,0,0,.35) 26%,rgba(0,0,0,0) 55%)}
.ewp-discCard:hover img.ewp-discShot{transform:scale(1.04)}
.ewp-discLabel{position:absolute;left:16px;right:16px;bottom:16px;z-index:2;margin:0;
 font-family:Anton,"Instrument Sans",sans-serif;font-size:20px;line-height:20px;
 font-weight:400;color:#fff;text-transform:uppercase}
.ewp-discLabel span{margin-left:8px;font-family:"Instrument Sans",sans-serif;font-weight:400}
.ewp-disc .ewp-stripNav{position:absolute;top:209px;z-index:20;
 width:32px;height:32px;padding:0;border:0;border-radius:50%;background:#fff;color:#101010;
 display:flex;align-items:center;justify-content:center;cursor:pointer;
 box-shadow:0 20px 25px -5px rgba(0,0,0,.1),0 8px 10px -6px rgba(0,0,0,.1)}
.ewp-disc .ewp-stripNav[hidden]{display:none}
.ewp-disc .ewp-stripNav.is-prev{left:max(16px,calc(50vw - 640px))}
.ewp-disc .ewp-stripNav.is-next{right:max(16px,calc(50vw - 640px))}
@media(max-width:820px){
 .ewp-discH{font-size:38px;line-height:38px;margin:0 0 26px}
 .ewp-discSlide{flex:0 0 232px;max-width:232px}
 .ewp-discCard{width:232px;height:348px}
 .ewp-disc .ewp-stripNav{display:none}
}

/* Shop by player, measured off the reference panel rather than judged.
   Every figure below was read off their page at 1512 wide: the panel
   is the full 1248 column and 570 tall with 68 of padding down the
   left and 62 across the top; the kicker is 14 with 26 under it, the
   name Anton at a 39 cap with 30 under it, the number Anton at a 131
   cap -- four times the kicker, which is what gives the panel its
   weight -- with 73 under it, the question 19 with 29, the button 56
   tall by 218, and the link under that with 21 above it. The right
   half starts at 634: the shirt on a 371 stage, then 49 of air, then
   the tabs. Line heights are set to the cap heights so those gaps are
   the gaps that actually appear.

   Ours was built at 120 for the number against 40 of padding, which is
   why it read as a grey box with some type in it rather than as the
   panel it is. */
/* The panel is the full 1248 column on the reference. Inside an
   Elementor column it came out 1148, because the column pads itself, so
   this band steps out to the window the way the news and discover bands
   do and takes its width from its own wrap. */
.ewp-picker{padding:0 0 8px;width:100vw;margin-left:calc(50% - 50vw);margin-right:calc(50% - 50vw)}
/* The shared wrap on this page is 1180. The reference column is 1248,
   which is what the panel is measured against, so this band carries its
   own wrap the way the news band does rather than moving everyone. */
.ewp-picker .ewp-wrap{max-width:1280px;margin:0 auto;padding:0 16px;box-sizing:border-box}
.ewp-pickPanel{position:relative;min-height:570px;background:#e9e9e9;
 background-size:cover;background-position:center;border-radius:10px;overflow:hidden;
 padding:62px 24px 22px 68px;
 display:grid;grid-template-columns:434px minmax(0,1fr);gap:0;align-items:start}
.ewp-pickPanel::before{content:"";position:absolute;inset:0;background:rgba(233,233,233,.8)}
.ewp-pickSide,.ewp-pickRight{position:relative;z-index:2}
.ewp-pickKicker{margin:0 0 26px;font-family:"Instrument Sans",sans-serif;font-size:14px;line-height:11px;
 font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#CC0000}
.ewp-pickName{margin:0 0 30px;font-family:Anton,"Instrument Sans",sans-serif;font-size:54px;
 line-height:39px;font-weight:400;text-transform:uppercase;color:#101010}
.ewp-pickNum{margin:0 0 73px;font-family:Anton,"Instrument Sans",sans-serif;font-size:180px;
 line-height:132px;font-weight:400;color:#CC0000}
.ewp-pickAsk{margin:0 0 29px;font-size:19px;line-height:16px;color:#4a4a4a}
.ewp-pickCta{margin:0 0 21px}
.ewp-pickCta a{display:inline-flex;align-items:center;justify-content:center;height:56px;
 min-width:218px;padding:0 28px;background:#CC0000;color:#fff;border-radius:2px;text-decoration:none;
 font-family:Anton,"Instrument Sans",sans-serif;font-size:16px;letter-spacing:.09em;
 text-transform:uppercase;transition:background-color .2s}
.ewp-pickCta a:hover{background:#a80000}
.ewp-pickAll{margin:0}
.ewp-pickAll a{display:inline-block;font-family:Anton,"Instrument Sans",sans-serif;font-size:14px;
 line-height:11px;letter-spacing:.1em;text-transform:uppercase;color:#101010;text-decoration:none;
 border-bottom:2px solid #101010;padding-bottom:7px}
.ewp-pickStage{position:relative;display:flex;align-items:center;justify-content:center;height:371px}
.ewp-pickShirt{position:relative;display:block;width:306px;flex:0 0 auto}
.ewp-pickShirt img{display:block;width:100%;height:auto}
/* Until there is a photograph of the shirt, the printing sits on a
   plain panel of club red in the same proportions. The moment a
   picture goes in the field it takes over and nothing else moves. */
.ewp-pickShirt:not(:has(img)){background:#CC0000;border-radius:8px;aspect-ratio:306/371}
.ewp-pickShirt.is-left,.ewp-pickShirt.is-right{transform:scale(.62);opacity:.3;z-index:1}
.ewp-pickShirt.is-left{margin-right:-108px}
.ewp-pickShirt.is-right{margin-left:-108px}
.ewp-pickShirt.is-mid{z-index:3}
/* The printing. Oswald rather than Anton, which is the closest thing
   to a shirt face among the ones we can licence -- the reference wears
   the Premier League house font, which is theirs and not for sale. The
   dark outline is what stops white type disappearing into a red shirt,
   and the reference carries one too. The gap between name and number
   is the point of these numbers: the name sits up on the shoulders and
   the number drops to the middle of the back. */
.ewp-pickShirt b{position:absolute;left:0;right:0;top:var(--ew-name-top,17%);text-align:center;
 font-family:Oswald,"Instrument Sans",sans-serif;font-weight:700;font-size:36px;line-height:26px;
 color:#fff;text-transform:uppercase;
 -webkit-text-stroke:3px rgba(40,0,6,.9);paint-order:stroke fill}
.ewp-pickShirt i{position:absolute;left:0;right:0;top:var(--ew-num-top,40%);text-align:center;
 font-family:Oswald,"Instrument Sans",sans-serif;font-weight:700;font-style:normal;
 font-size:134px;line-height:98px;color:#fff;
 -webkit-text-stroke:3px rgba(40,0,6,.9);paint-order:stroke fill}
.ewp-pickNav{position:absolute;top:50%;transform:translateY(-50%);z-index:5;
 width:44px;height:44px;border:0;border-radius:6px;background:#fff;color:#101010;cursor:pointer;
 display:flex;align-items:center;justify-content:center;
 box-shadow:0 20px 25px -5px rgba(0,0,0,.12),0 8px 10px -6px rgba(0,0,0,.1)}
.ewp-pickNav.is-prev{left:8%}
.ewp-pickNav.is-next{right:8%}
.ewp-pickTabs{display:flex;list-style:none;margin:49px 0 0;padding:0}
.ewp-pickTabs li{flex:1 1 0;min-width:0;list-style:none;margin:0;padding:0;
 border-left:1px solid rgba(0,0,0,.12)}
.ewp-pickTabs li::marker{content:""}
.ewp-pickTabs li:first-child{border-left:0}
.ewp-pickTab{width:100%;background:none;border:0;cursor:pointer;padding:0 6px 0;
 display:flex;flex-direction:column;align-items:center;gap:12px;color:#9a9a9a;
 border-bottom:3px solid transparent;transition:color .2s,border-color .2s}
.ewp-pickTab b{font-family:"Instrument Sans",sans-serif;font-size:12px;line-height:9px;font-weight:600;
 letter-spacing:.1em;text-transform:uppercase;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
 max-width:100%}
.ewp-pickTab i{font-family:Anton,"Instrument Sans",sans-serif;font-style:normal;font-size:46px;
 line-height:34px;padding-bottom:10px}
.ewp-pickTab.is-on{color:#101010;border-bottom-color:#CC0000}
/* The squad has no shirt numbers yet. Rather than leave a 132px hole
   where the number belongs and blank space under every tab, the number
   lines come out until there is one, so the panel reads as finished
   either way and fills itself in the moment the numbers are typed. */
.ewp-pickNum:empty{display:none}
.ewp-pickTab i:empty{display:none}
.ewp-pickShirt i:empty{display:none}
@media(max-width:1100px){
 .ewp-pickPanel{grid-template-columns:1fr;padding:32px 20px 24px;min-height:0}
 .ewp-pickName{font-size:40px;line-height:30px;margin-bottom:20px}
 .ewp-pickNum{font-size:120px;line-height:88px;margin-bottom:40px}
 .ewp-pickStage{height:300px}
 .ewp-pickShirt{width:240px}
 .ewp-pickShirt b{font-size:28px;line-height:20px}
 .ewp-pickShirt i{font-size:104px;line-height:76px}
 .ewp-pickTabs{margin-top:28px;overflow-x:auto}
 .ewp-pickTabs li{flex:0 0 128px}
}

/* Eastwood TV. Every figure read off the reference at 1512 wide: a red
   band the width of the window, the channel name across the middle, the
   section heading hard against the left of the 1248 column with 40 of
   air under it, cards 300 by 279 with an 8px corner and 16 between
   them, the still 300 by 169, the category 12 in grey, the title Anton
   16 in near-black and the age of the clip on the last line. */
.ewp-tv{position:relative;background:#990000;padding:40px 0 48px;
 width:100vw;margin-left:calc(50% - 50vw);margin-right:calc(50% - 50vw)}
.ewp-tvMark{margin:0 0 30px;text-align:center;color:#fff;
 font-family:Anton,"Instrument Sans",sans-serif;font-size:44px;line-height:44px;
 font-weight:400;text-transform:uppercase;letter-spacing:.01em}
.ewp-tvHead{margin:0 0 40px;padding:0 max(16px,calc(50vw - 624px));color:#fff;text-align:left;
 font-family:Anton,"Instrument Sans",sans-serif;font-size:40px;line-height:40px;
 font-weight:400;text-transform:uppercase}
.ewp-tvRail{position:relative}
.ewp-tvList{display:flex;gap:16px;list-style:none;margin:0;
 padding:0 max(16px,calc(50vw - 624px));overflow-x:auto;cursor:grab;
 scroll-behavior:smooth;scrollbar-width:none;-ms-overflow-style:none}
.ewp-tvList::-webkit-scrollbar{display:none}
.ewp-tvSlide{flex:0 0 300px;max-width:300px;list-style:none;margin:0;padding:0}
.ewp-tvSlide::marker{content:""}
.ewp-tvCard{display:flex;flex-direction:column;width:300px;height:279px;background:#fff;
 border-radius:8px;overflow:hidden;text-decoration:none;color:#101010}
.ewp-tvShot{position:relative;display:block;height:169px;flex:0 0 169px;background:#101010;
 overflow:hidden}
.ewp-tvCard img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;
 max-width:none;display:block;transition:transform .5s cubic-bezier(.4,0,.2,1)}
.ewp-tvCard:hover img{transform:scale(1.04)}
.ewp-tvPlay{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);z-index:2;
 width:44px;height:44px;border-radius:50%;background:rgba(16,16,16,.55);color:#fff;
 display:flex;align-items:center;justify-content:center}
.ewp-tvTime{position:absolute;right:8px;bottom:8px;z-index:2;font-style:normal;
 font-family:"Instrument Sans",sans-serif;font-size:11px;line-height:11px;color:#fff;
 background:rgba(16,16,16,.75);border-radius:3px;padding:4px 6px}
.ewp-tvCat{display:block;margin:8px 8px 0;font-family:"Instrument Sans",sans-serif;
 font-size:12px;line-height:12px;min-height:12px;color:#6b6b6b}
.ewp-tvTitle{display:block;margin:1px 8px 0;font-family:Anton,"Instrument Sans",sans-serif;
 font-size:16px;line-height:19px;font-weight:400;color:#101010;text-transform:uppercase;
 overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
.ewp-tvWhen{display:block;margin:auto 8px 8px;font-style:normal;
 font-family:"Instrument Sans",sans-serif;font-size:12px;line-height:12px;color:#6b6b6b}
.ewp-tv .ewp-stripNav{position:absolute;top:123px;z-index:20;
 width:32px;height:32px;padding:0;border:0;border-radius:50%;background:#fff;color:#101010;
 display:flex;align-items:center;justify-content:center;cursor:pointer;
 box-shadow:0 20px 25px -5px rgba(0,0,0,.18),0 8px 10px -6px rgba(0,0,0,.15)}
.ewp-tv .ewp-stripNav[hidden]{display:none}
.ewp-tv .ewp-stripNav.is-prev{left:max(16px,calc(50vw - 640px))}
.ewp-tv .ewp-stripNav.is-next{right:max(16px,calc(50vw - 640px))}
.ewp-tvAll{margin:36px 0 0;text-align:center}
.ewp-tvAll a{font-family:Anton,"Instrument Sans",sans-serif;font-size:14px;letter-spacing:.1em;
 text-transform:uppercase;color:#fff;text-decoration:none;
 border-bottom:2px solid rgba(255,255,255,.9);padding-bottom:5px}
@media(max-width:820px){
 .ewp-tvMark{font-size:32px;line-height:32px;margin-bottom:20px}
 .ewp-tvHead{font-size:28px;line-height:28px;margin-bottom:24px}
 .ewp-tv .ewp-stripNav{display:none}
}

.ewp-news{background:#f3f3f3;padding:32px 0 32px;
 width:100vw;margin-left:calc(50% - 50vw);margin-right:calc(50% - 50vw)}
/* A heading widget emptied in the builder should draw nothing rather
   than leave its margins behind. The reference carries its heading
   inside the grey band, so ours comes from the block. */
.elementor-widget-heading:has(> .elementor-widget-container > .elementor-heading-title:empty){display:none}
.ewp-news .ewp-wrap{max-width:1280px;margin:0 auto;padding:0 16px;box-sizing:border-box}
/* The band paints its own top edge, so the padding the builder puts
   above it is white space between the picture and the grey. Keyed on
   our own section rather than the ids of one page, so it holds
   wherever the block is put. */
.elementor-element:has(> .elementor-shortcode > .ewp-news),
.e-con:has(> .elementor-element > .elementor-shortcode > .ewp-news),
.e-con:has(> .e-con > .elementor-element > .elementor-shortcode > .ewp-news){padding-top:0}
/* Capitals: on the reference the heading is capitals in the words
   themselves, so a title typed in sentence case is lifted here rather
   than shouted into the field. */
.ewp-newsH{font-family:Anton,"Instrument Sans",sans-serif;font-size:56px;line-height:56px;
 font-weight:400;color:#CC0000;text-align:center;text-transform:uppercase;margin:0 0 32px}
.ewp-newsIcon{font-family:"Instrument Sans",system-ui,sans-serif;font-size:48px;line-height:56px}
/* 4px of padding, 4px between tabs, 2px corners: the rail on the
   reference, to the pixel. */
.ewp-newsTabs{display:flex;flex-wrap:wrap;gap:4px;padding:4px;background:#fff;border-radius:2px;
 width:fit-content;max-width:100%;margin:0 0 24px}
.ewp-newsTab{appearance:none;border:0;cursor:pointer;background:none;border-radius:2px;
 padding:8px 16px;font-family:Anton,"Instrument Sans",sans-serif;font-size:16px;line-height:16px;
 font-weight:400;color:#101010;text-transform:uppercase;
 transition:color .2s cubic-bezier(.4,0,.2,1),background-color .2s cubic-bezier(.4,0,.2,1)}
.ewp-newsTab:hover{color:#CC0000}
.ewp-newsTab.is-on{background:#CC0000;color:#fff}
.ewp-newsEmpty{font-size:15px;color:#5c5c5c;margin:0 0 8px}
/* Three across the 1248 column with 16px between: 405 each, the card the
   reference draws. The photograph is the card; the words sit on it. */
.ewp-newsGrid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
.ewp-newsCard{position:relative;display:block;overflow:hidden;border-radius:8px;
 text-decoration:none;color:#fff;aspect-ratio:405/228;background:#101010}
/* The theme sets height:auto on every img, which beat a single class
   and left the photograph its own height inside a fixed-ratio card.
   Named through the card so this rule wins. */
.ewp-newsCard img.ewp-newsShot{position:absolute;inset:0;width:100%;height:100%;
 object-fit:cover;display:block;max-width:none;
 transition:transform .5s cubic-bezier(.4,0,.2,1)}
.ewp-newsCard:hover img.ewp-newsShot{transform:scale(1.04)}
/* Dark at the foot so a white headline holds on any photograph. */
.ewp-newsOver{position:absolute;inset:0;display:flex;flex-direction:column;justify-content:flex-end;
 gap:8px;padding:12px;background:linear-gradient(to top,rgba(0,0,0,.78) 0%,rgba(0,0,0,.45) 34%,rgba(0,0,0,0) 68%)}
.ewp-newsOver b{display:block;font-family:Anton,"Instrument Sans",sans-serif;font-size:18px;
 line-height:1.1;font-weight:400;text-transform:uppercase;color:#fff;
 display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.ewp-newsOver i{font-style:normal;font-size:12px;line-height:12px;color:rgba(255,255,255,.82)}
@media(max-width:900px){.ewp-newsGrid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:620px){.ewp-newsGrid{grid-template-columns:1fr}
 .ewp-newsH{font-size:34px;line-height:36px;margin-bottom:20px}
 .ewp-newsIcon{font-size:28px}}

/* The editor wraps loose text in paragraphs. Blocks sit flush; stray empty
   paragraphs between them would otherwise open white gaps in the stack. */
.ew-full > p:empty{display:none}
.ew-full > p{max-width:1180px;margin:0 auto;padding:18px 16px;font-size:15px;color:#444}

@media(max-width:820px){
 .ewp-hero a{min-height:380px}
 .ewp-heroText b{font-size:30px;max-width:none}
 .ewp-h2{font-size:30px;margin-bottom:20px}
 .ewp-news{padding:32px 0 36px}
 .ewp-fixturesBody{padding:26px 0 36px}
 .ewp-teamTabs{gap:18px;flex-wrap:nowrap}
 .ewp-teamTabs button{font-size:15px}
 .ewp-tabs{gap:18px}
 .ewp-tabs button{font-size:15px}
 .ewp-tie{grid-template-columns:1fr;gap:10px}
 .ewp-tieName,.ewp-tieName:first-child,.ewp-tieName:last-child{text-align:center;font-size:24px}
 .ewp-tieMid{justify-content:center}
 .ewp-tieMid img{width:46px;height:46px}
 .ewp-rowBody{padding:18px 16px 20px}
 .ewp-rowTop{padding:12px 16px}
 .ewp-rowComp{display:none}
 .ewp-upcoming,.ewp-shop{padding:32px 0 38px}
 .ewp-shopGrid{grid-template-columns:repeat(auto-fit,minmax(140px,1fr))}
 .ewp-tiles{padding:32px 0 38px}
 .ewp-tileGrid{grid-template-columns:repeat(2,1fr)}
 .ewp-tileGrid b{font-size:14px}
 .ewp-sponsors{padding:32px 0 36px}
 .ewp-sponsorRow{gap:22px 30px}
 .ewp-sponsorRow img{height:32px;max-width:120px}
 .ewp-sponsorRow.is-lead img{height:44px;max-width:150px}
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
/* The news tabs. The reference goes back to its news service on every
   click; with ten articles every tab is already in the page, so a click
   only swaps which one shows. The address bar still carries ?tab= the
   way theirs does, so a tab can be linked to and the back button works,
   and a tab arrived at by link opens on load. */
(function(){
 var wrap = document.querySelector('.ewp-news');
 if (!wrap) { return; }
 var tabs = wrap.querySelectorAll('.ewp-newsTab');
 var panels = wrap.querySelectorAll('.ewp-newsPanel');
 if (!tabs.length) { return; }

 function show(slug, push){
  var found = false;
  for (var i = 0; i < panels.length; i++) {
   var on = panels[i].getAttribute('data-panel') === slug;
   panels[i].hidden = !on;
   if (on) { found = true; }
  }
  if (!found) { return false; }
  for (var j = 0; j < tabs.length; j++) {
   var isOn = tabs[j].getAttribute('data-tab') === slug;
   tabs[j].classList.toggle('is-on', isOn);
   tabs[j].setAttribute('aria-selected', isOn ? 'true' : 'false');
  }
  if (push && window.history && history.pushState) {
   var u = new URL(window.location.href);
   if (slug === tabs[0].getAttribute('data-tab')) { u.searchParams.delete('tab'); }
   else { u.searchParams.set('tab', slug); }
   history.pushState({ ewTab: slug }, '', u);
  }
  return true;
 }

 for (var k = 0; k < tabs.length; k++) {
  tabs[k].addEventListener('click', function(){
   show(this.getAttribute('data-tab'), true);
  });
 }

 window.addEventListener('popstate', function(){
  var want = new URL(window.location.href).searchParams.get('tab');
  show(want || tabs[0].getAttribute('data-tab'), false);
 });

 var start = new URL(window.location.href).searchParams.get('tab');
 if (start) { show(start, false); }
})();

/* A crest that came back 429 is not a missing crest, it is a throttled one.
   Give each failed badge a single retry a moment later before giving up,
   so a burst of requests does not leave a card permanently blank. */
(function(){
 document.addEventListener('error', function(e){
  var img = e.target;
  if (!img || img.tagName !== 'IMG' || img.dataset.ewRetried) { return; }
  if (!/\/(ew-badges|ew-comps|comps)\//.test(img.src)) { return; }
  img.dataset.ewRetried = '1';
  var src = img.src;
  setTimeout(function(){
   img.style.visibility = '';
   img.src = src + (src.indexOf('?') > -1 ? '&' : '?') + 'r=1';
  }, 1200 + Math.random() * 1800);
 }, true);
})();
(function(){
 var roots=[].slice.call(document.querySelectorAll('.ewp-fixtures, .ewp-upcoming, .ew-teams-index'));
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

  /* Tabs can nest: a team tab holds a Fixtures/Results/Table set inside it.
     Each set lives in its own [data-pv-group], and a button only ever moves
     the panels belonging to the same group — otherwise clicking a team
     would hide the inner panels too and the section would go blank. */
  var groups=[].slice.call(root.querySelectorAll('[data-pv-group]'));
  if(root.hasAttribute('data-pv-group')){groups.unshift(root);}
  groups.forEach(function(g){
   function mine(sel){
    return [].slice.call(g.querySelectorAll(sel)).filter(function(el){
     return el.closest('[data-pv-group]')===g;
    });
   }
   var tabs=mine('[data-pv]');
   var panels=mine('[data-pv-panel]');
   tabs.forEach(function(b){
    b.addEventListener('click',function(){
     var want=b.getAttribute('data-pv');
     tabs.forEach(function(t){t.setAttribute('aria-selected', t===b ? 'true':'false');});
     panels.forEach(function(p){
      var keys=(p.getAttribute('data-pv-panel')||'').split(/\s+/);
      if(keys.indexOf(want)>-1){p.removeAttribute('hidden');}
      else{p.setAttribute('hidden','');}
     });
     rails.forEach(sync);
    });
   });
  });
 });
})();
 /* Shop by player. The tabs along the bottom and the two chevrons both
    move the same cursor: the panel reads the player off the live tab, so
    the name, the number, the button and the three shirts on the stage
    never get a chance to disagree with each other. */
 [].forEach.call(document.querySelectorAll('.ewp-picker'), function(sec){
  var tabs = [].slice.call(sec.querySelectorAll('.ewp-pickTab'));
  if (!tabs.length) { return; }
  var name  = sec.querySelector('[data-name]');
  var num   = sec.querySelector('[data-num]');
  var cta   = sec.querySelector('[data-cta]');
  var slots = [].slice.call(sec.querySelectorAll('.ewp-pickShirt'));
  var at    = 0;

  var wrap = function(k){ return (k % tabs.length + tabs.length) % tabs.length; };

  var show = function(k){
   at = wrap(k);
   var t = tabs[at];
   if (name) { name.textContent = t.getAttribute('data-player'); }
   if (num)  { num.textContent  = t.getAttribute('data-number'); }
   if (cta)  { cta.textContent  = t.getAttribute('data-cta');
               cta.setAttribute('href', t.getAttribute('data-url')); }
   tabs.forEach(function(x){ x.classList.remove('is-on'); });
   t.classList.add('is-on');
   [-1, 0, 1].forEach(function(d, n){
    var slot = slots[n];
    if (!slot) { return; }
    var q = tabs[wrap(at + d)];
    var sn = slot.querySelector('[data-slot-name]');
    var sv = slot.querySelector('[data-slot-num]');
    if (sn) { sn.textContent = q.getAttribute('data-player'); }
    if (sv) { sv.textContent = q.getAttribute('data-number'); }
   });
  };

  tabs.forEach(function(t, k){ t.addEventListener('click', function(){ show(k); }); });
  var prev = sec.querySelector('.ewp-pickNav.is-prev');
  var next = sec.querySelector('.ewp-pickNav.is-next');
  if (prev) { prev.addEventListener('click', function(){ show(at - 1); }); }
  if (next) { next.addEventListener('click', function(){ show(at + 1); }); }
  show(0);
 });

 /* Open the rest of the season. The cards are already in the page but
    hidden, so their crests have not been requested yet; revealing them
    lets the browser fetch that handful rather than the whole season. */
 [].forEach.call(document.querySelectorAll('[data-ew-more]'), function(btn){
  btn.addEventListener('click', function(){
   var box = btn.parentNode.nextElementSibling;
   if (!box || !box.hasAttribute('data-ew-rest')) { return; }
   var open = !box.hasAttribute('hidden');
   if (open) { box.setAttribute('hidden',''); } else { box.removeAttribute('hidden'); }
   btn.setAttribute('aria-expanded', open ? 'false' : 'true');
   btn.firstChild.nodeValue = open ? 'Show the rest of the season ' : 'Hide the rest of the season ';
  });
 });

 /* Drag the strip, the way the reference does. There is no scrollbar,
    so this and the chevrons above are how a mouse reaches the games off
    the right-hand side -- a trackpad and a finger already do.
    A press that moves less than five pixels is a click on the card, not
    a drag, so the fixture links keep working. */
 /* The chevrons. One press is one card; each hides when there is
    nothing that way, which is what the reference does. */
 [].forEach.call(document.querySelectorAll('.ewp-strip, .ewp-disc, .ewp-tv'), function(strip){
  var list = strip.querySelector('.ewp-stripList');
  var prev = strip.querySelector('.ewp-stripNav.is-prev');
  var next = strip.querySelector('.ewp-stripNav.is-next');
  if (!list || !prev || !next) { return; }

  /* One press is one card. The fixture strip butts its cards together
     and the discover rail sets them 16 apart, so the gap is read off
     the list rather than assumed either way. */
  var step = function(){
   var card = list.firstElementChild;
   if (!card) { return 340; }
   var gap = parseFloat(getComputedStyle(list).columnGap) || 0;
   return Math.round(card.getBoundingClientRect().width + gap);
  };

  var sync = function(){
   var max = list.scrollWidth - list.clientWidth;
   prev.hidden = list.scrollLeft <= 1;
   next.hidden = list.scrollLeft >= max - 1;
  };

  prev.addEventListener('click', function(){ list.scrollLeft -= step(); });
  next.addEventListener('click', function(){ list.scrollLeft += step(); });
  list.addEventListener('scroll', sync);
  window.addEventListener('resize', sync);
  sync();
 });

 [].forEach.call(document.querySelectorAll('.ewp-stripList'), function(list){
  var down = false, moved = 0, startX = 0, startLeft = 0;

  list.addEventListener('pointerdown', function(e){
   if (e.button) { return; }
   down = true; moved = 0;
   startX = e.clientX; startLeft = list.scrollLeft;
   list.style.scrollBehavior = 'auto';
  });

  list.addEventListener('pointermove', function(e){
   if (!down) { return; }
   var dx = e.clientX - startX;
   if (Math.abs(dx) > moved) { moved = Math.abs(dx); }
   if (moved > 5) {
    if (list.setPointerCapture && e.pointerId !== undefined) {
     try { list.setPointerCapture(e.pointerId); } catch (err) {}
    }
    list.style.cursor = 'grabbing';
    list.scrollLeft = startLeft - dx;
   }
  });

  var stop = function(){
   down = false;
   list.style.cursor = '';
   list.style.scrollBehavior = '';
  };
  list.addEventListener('pointerup', stop);
  list.addEventListener('pointercancel', stop);
  list.addEventListener('pointerleave', stop);

  // The click that ends a drag would otherwise open whichever fixture
  // happened to be under the finger when it stopped.
  list.addEventListener('click', function(e){
   if (moved > 5) { e.preventDefault(); e.stopPropagation(); moved = 0; }
  }, true);
 });

 /* next-match countdown */
 [].forEach.call(document.querySelectorAll('.ewp-bannerClock'), function(el){
  var ko = parseInt(el.getAttribute('data-ko'), 10) * 1000;
  if (!ko) { return; }
  function pad(n){ return (n<10?'0':'') + n; }
  function tick(){
   var left = ko - Date.now();
   if (left <= 0) { el.style.display='none'; return; }
   var s = Math.floor(left/1000);
   var parts = { d: Math.floor(s/86400), h: Math.floor(s/3600)%24,
                 m: Math.floor(s/60)%60, s: s%60 };
   Object.keys(parts).forEach(function(k){
    var b = el.querySelector('[data-u="'+k+'"]');
    if (b) { b.textContent = pad(parts[k]); }
   });
  }
  tick();
  setInterval(tick, 1000);
 });

JS;
}

/* ------------------------------------------------------------------
 * The page itself, and what is written on it to begin with.
 * ------------------------------------------------------------------ */

function ew_home_preview_content() {
	return "[eastwood_home_hero]\n\n"
		. "[eastwood_home_fixtures title=\"Fixtures &amp; Results\" count=\"8\"]\n"
		. "Men's First Team | 2059\n"
		. "Women's First Team |\n"
		. "Development |\n"
		. "Under 21s |\n"
		. "[/eastwood_home_fixtures]\n\n"
		. "[eastwood_home_upcoming title=\"Upcoming Matches\" count=\"3\"]\n"
		. "Men's First Team | 2059\n"
		. "Women's First Team |\n"
		. "Development |\n"
		. "Under 21s |\n"
		. "[/eastwood_home_upcoming]\n\n"
		. "[eastwood_home_shop title=\"Get Kitted Out\" all_url=\"/shop/\"]\n"
		. "Home Shirt 26/27 | /shop/ | \u00a345.00\n"
		. "Away Shirt 26/27 | /shop/ | \u00a345.00\n"
		. "Junior Home Shirt | /shop/ | \u00a335.00\n"
		. "Training Tee | /shop/ | \u00a325.00\n"
		. "Club Scarf | /shop/ | \u00a312.00\n"
		. "[/eastwood_home_shop]\n\n"
		. "[eastwood_home_discover title=\"Discover Eastwood\"]\n"
		. "Matchday | /matchday/ | Coming to a game at Coronation Park\n"
		. "The Venue | /thevenue/ | Function room and bar hire\n"
		. "Pitch Hire | /pitch-hire/ | Floodlit 3G, all year round\n"
		. "Academy | /academy/ | Full-time football and education\n"
		. "Sponsorship | /sponsorship/ | Partner with the club\n"
		. "Eastwood TV | /eastwood-tv/ | Highlights and the documentary\n"
		. "[/eastwood_home_discover]\n\n"
		. "[eastwood_home_news title=\"Latest News\" count=\"4\"]";
}


/* ------------------------------------------------------------------
 * [eastwood_home_strip count="6"]
 *
 * The next few fixtures as one thin full-width band directly under the
 * nav, which is the first thing the reference puts on its homepage: 109px,
 * one cell per game, scrolling sideways rather than wrapping.
 *
 * Same card as the rail inside the fixtures block, so a change to how a
 * fixture reads happens once. The strip only flattens it.
 * ------------------------------------------------------------------ */

function ew_hb_strip_sc( $atts ) {
	$atts = shortcode_atts( array( 'count' => '6' ), $atts, 'eastwood_home_strip' );

	$fx  = ew_fwp_fetch( 'fixtures-results', array( 'team' => EW_TEAM ) );
	$all = (array) ( $fx['fixtures-results']['matches'] ?? array() );

	$today = current_time( 'Y-m-d' );
	$next  = array();
	foreach ( $all as $m ) {
		if ( (string) ( $m['date'] ?? '' ) >= $today ) {
			$next[] = $m;
		}
	}
	usort( $next, function ( $a, $b ) {
		return strcmp( (string) ( $a['date'] ?? '' ), (string) ( $b['date'] ?? '' ) );
	} );
	$next = array_slice( $next, 0, max( 1, (int) $atts['count'] ) );
	if ( ! $next ) {
		return '';
	}

	$out  = '<section class="ewp ewp-strip">';
	$out .= '<ul class="ewp-stripList">';
	foreach ( $next as $m ) {
		$out .= ew_hb_card( $m, false );
	}
	$out .= '</ul>';

	/*
	 * The two chevrons. The reference carries them and we did not, so on a
	 * mouse the only way past the fourth game was to drag the strip --
	 * which nothing on the page told you was possible.
	 *
	 * Measured off the reference: a 32px white disc, the left one 36px in
	 * from the edge of the band and the right one 68px in from the other
	 * edge, both centred on the 109px band, with an 18px chevron in near
	 * black. One press moves the strip exactly one card, 340px at this
	 * width. Each hides itself when there is nothing that way.
	 */
	$chev = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">'
		. '<path d="%s" fill="none" stroke="currentColor" stroke-width="1.9"'
		. ' stroke-linecap="round" stroke-linejoin="round"/></svg>';

	$out .= '<button type="button" class="ewp-stripNav is-prev" aria-label="Earlier fixtures" hidden>'
		. sprintf( $chev, 'M16.1 1.9 5.9 12.1 16.1 22.3' ) . '</button>';
	$out .= '<button type="button" class="ewp-stripNav is-next" aria-label="Later fixtures">'
		. sprintf( $chev, 'M7.9 1.9 18.1 12.1 7.9 22.3' ) . '</button>';

	$out .= '</section>';
	$out .= ew_promo_bar_html();
	return $out;
}
add_shortcode( 'eastwood_home_strip', 'ew_hb_strip_sc' );

/* ------------------------------------------------------------------
 * The promo bar.
 *
 * One line of type on a red band, the whole thing a link. The reference
 * runs one directly under its fixtures strip and changes it whenever the
 * shop does, so the point of this one is that changing it needs nobody
 * technical: Promo bar in the admin menu has a switch, a line of text and
 * a link, and that is the whole screen.
 *
 * It draws itself under the fixtures strip, where the reference puts it.
 * [eastwood_promo_bar] puts the same bar anywhere else.
 * ------------------------------------------------------------------ */

/** The three things anyone can change, with sane empties. */
function ew_promo_settings() {
	$saved = get_option( 'ew_promo', array() );
	return array(
		'on'   => ! empty( $saved['on'] ),
		'text' => isset( $saved['text'] ) ? (string) $saved['text'] : '',
		'url'  => isset( $saved['url'] ) ? (string) $saved['url'] : '',
	);
}

/**
 * The bar, or nothing at all.
 *
 * Nothing is the right answer more often than not: switched off, or on
 * with no words in it. A bar with no text is a red stripe nobody meant.
 */
function ew_promo_bar_html() {
	static $drawn = false;

	$cfg = ew_promo_settings();
	if ( ! $cfg['on'] || '' === trim( $cfg['text'] ) ) {
		return '';
	}

	// Twice on one page is always a mistake, never a layout.
	if ( $drawn ) {
		return '';
	}
	$drawn = true;

	$url = '' === trim( $cfg['url'] ) ? '#' : $cfg['url'];

	return '<a class="ewp ewp-promo" href="' . esc_url( $url ) . '">'
		. '<b>' . esc_html( $cfg['text'] ) . '</b></a>';
}

function ew_promo_bar_sc() {
	return ew_promo_bar_html();
}
add_shortcode( 'eastwood_promo_bar', 'ew_promo_bar_sc' );

/* --- The screen someone else uses ---------------------------------- */

add_action( 'admin_menu', function () {
	// Its own item in the sidebar rather than a tab inside Settings.
	// Somebody who has been told "change the red bar" should be able to
	// find it without being told where to look.
	add_menu_page(
		'Promo bar', 'Promo bar', 'edit_pages', 'ew-promo',
		'ew_promo_admin_page', 'dashicons-megaphone', 31
	);
} );

add_action( 'admin_init', function () {
	register_setting( 'ew_promo_group', 'ew_promo', array(
		'type'              => 'array',
		'sanitize_callback' => 'ew_promo_sanitise',
		'default'           => array(),
	) );
} );

// Saving goes through options.php, which asks for manage_options unless
// told otherwise. The people who will actually use this screen are
// editors, so the save has to accept the same capability the menu does.
add_filter( 'option_page_capability_ew_promo_group', function () {
	return 'edit_pages';
} );

function ew_promo_sanitise( $in ) {
	$in = (array) $in;
	$url = trim( (string) ( $in['url'] ?? '' ) );

	return array(
		'on'   => empty( $in['on'] ) ? 0 : 1,
		'text' => sanitize_text_field( (string) ( $in['text'] ?? '' ) ),
		// A path typed as "/shop/" is the common case and is kept as one.
		'url'  => '' === $url ? '' : esc_url_raw( $url ),
	);
}

function ew_promo_admin_page() {
	if ( ! current_user_can( 'edit_pages' ) ) {
		return;
	}
	$cfg = ew_promo_settings();
	?>
	<div class="wrap">
		<h1>Promo bar</h1>
		<p style="max-width:46em;font-size:14px">
			The red bar across the top of the homepage, under the fixtures.
			Switch it on, write the line, point it at a page. It changes the
			moment you save &mdash; there is nothing else to do.
		</p>

		<h2 style="margin-top:28px">What it looks like now</h2>
		<?php if ( $cfg['on'] && '' !== trim( $cfg['text'] ) ) : ?>
			<div style="max-width:900px;border:1px solid #c3c4c7;background:#fff">
				<a href="#" onclick="return false" style="display:block;background:#CC0000;
					color:#fff;text-decoration:none">
					<b style="display:block;padding:7px 16px;text-align:center;font-weight:400;
						font-family:Anton,Impact,sans-serif;font-size:18px;line-height:22px;
						text-transform:uppercase;color:#fff"><?php
						echo esc_html( $cfg['text'] ); ?></b>
				</a>
			</div>
		<?php else : ?>
			<p style="color:#646970"><em>Nothing is showing on the site at the
				moment.</em></p>
		<?php endif; ?>

		<form method="post" action="options.php" style="margin-top:28px">
			<?php settings_fields( 'ew_promo_group' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Show the bar</th>
					<td>
						<label>
							<input type="checkbox" name="ew_promo[on]" value="1"
								<?php checked( $cfg['on'] ); ?>>
							Yes, show it on the site
						</label>
						<p class="description">Untick to take it down. Nothing is
							lost &mdash; the words and the link stay here.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ew-promo-text">The line</label></th>
					<td>
						<input type="text" id="ew-promo-text" name="ew_promo[text]"
							class="regular-text" style="width:28em"
							value="<?php echo esc_attr( $cfg['text'] ); ?>"
							placeholder="20% off training wear | Shop now">
						<p class="description">It is printed in capitals whatever you
							type. Keep it short &mdash; it has to fit on one line on a
							phone.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ew-promo-url">Where it goes</label></th>
					<td>
						<input type="text" id="ew-promo-url" name="ew_promo[url]"
							class="regular-text" style="width:28em"
							value="<?php echo esc_attr( $cfg['url'] ); ?>"
							placeholder="/shop/">
						<p class="description">A page on this site, written as
							<code>/shop/</code>, or a full web address somewhere
							else.</p>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Save the bar' ); ?>
		</form>
	</div>
	<?php
}



/* ------------------------------------------------------------------
 * [eastwood_home_live url="https://youtu.be/..." from="14:30"]
 *
 * The slot the reference gives to one big promotional graphic, straight
 * under the strip. Ours is type rather than artwork, because artwork would
 * have to be made for every game.
 *
 * Three states, decided by the stream link and the clock:
 *
 *   no url            nothing renders at all, no empty state
 *   url, before       black, the game is coming
 *   url, during       red, the game is on
 *
 * "During" runs from the hour before kick-off to three hours after, which
 * covers build-up and full time without anybody having to take it down.
 * ------------------------------------------------------------------ */

function ew_hb_live_sc( $atts ) {
	$atts = shortcode_atts( array(
		'url'   => '',
		'from'  => '',
		'label' => 'Eastwood TV',
	), $atts, 'eastwood_home_live' );

	if ( '' === trim( (string) $atts['url'] ) ) {
		return '';
	}

	$fx  = ew_fwp_fetch( 'fixtures-results', array( 'team' => EW_TEAM ) );
	$all = (array) ( $fx['fixtures-results']['matches'] ?? array() );

	$today = current_time( 'Y-m-d' );
	$game  = null;
	foreach ( $all as $m ) {
		if ( (string) ( $m['date'] ?? '' ) < $today ) {
			continue;
		}
		if ( (int) ( $m['home-team']['id'] ?? 0 ) !== (int) EW_TEAM ) {
			continue;
		}
		if ( null === $game || (string) $m['date'] < (string) $game['date'] ) {
			$game = $m;
		}
	}
	if ( ! $game ) {
		return '';
	}

	$kick = strtotime( $game['date'] . ' ' . substr( (string) ( $game['time'] ?? '15:00' ), 0, 5 ) );
	$now  = (int) current_time( 'timestamp' );
	$live = $kick && $now >= ( $kick - HOUR_IN_SECONDS ) && $now <= ( $kick + 3 * HOUR_IN_SECONDS );

	$opp  = (string) ( $game['away-team']['name'] ?? '' );
	$comp = (string) ( $game['competition']['name'] ?? '' );

	ob_start();
	?>
<section class="ewp ewp-live<?php echo $live ? ' is-live' : ''; ?>">
	<div class="ewp-liveInner">
		<div class="ewp-liveLeft">
			<p class="ewp-liveEyebrow"><span class="ewp-liveDot"></span><?php
				echo esc_html( $live ? 'Live now on ' . $atts['label'] : 'Streaming on ' . $atts['label'] ); ?></p>
			<?php if ( $live ) : ?>
			<h2 class="ewp-liveHead">Follow<em>the action</em><strong>Live</strong></h2>
			<?php else : ?>
			<h2 class="ewp-liveHead">Watch it<em>from</em><strong>Anywhere</strong></h2>
			<?php endif; ?>
			<ul class="ewp-liveLines">
				<?php if ( '' !== $atts['from'] ) : ?>
				<li>Coverage from <?php echo esc_html( $atts['from'] ); ?></li>
				<?php endif; ?>
				<li>Full match, free to watch</li>
				<li>Wherever you are</li>
			</ul>
		</div>
		<div class="ewp-liveRight">
			<div class="ewp-liveFix">
				<?php if ( '' !== $comp ) : ?>
				<p class="ewp-liveComp"><?php echo esc_html( $comp ); ?></p>
				<?php endif; ?>
				<p class="ewp-liveTie">Eastwood v<br><?php echo esc_html( $opp ); ?></p>
				<p class="ewp-liveWhen"><?php echo esc_html(
					date_i18n( 'D j M', $kick ) . ' · ' . date_i18n( 'H:i', $kick )
					. ' · Coronation Park' ); ?></p>
			</div>
			<a class="ewp-liveCta" href="<?php echo esc_url( $atts['url'] ); ?>"
				target="_blank" rel="noopener"><?php
				echo esc_html( $live ? 'Watch now' : 'Set a reminder' ); ?></a>
		</div>
	</div>
</section>
	<?php
	return trim( ob_get_clean() );
}
add_shortcode( 'eastwood_home_live', 'ew_hb_live_sc' );
