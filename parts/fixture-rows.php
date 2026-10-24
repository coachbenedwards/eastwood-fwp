<?php
/**
 * The fixture rows on the hospitality page.
 *
 * These were a block of hand-written HTML in the builder with three games
 * typed into it, a countdown that had to be told the kick-off by hand, and
 * two links that went nowhere useful: Book hospitality opened an email, and
 * Buy tickets left the site for app.fanbaseclub.com. So the page invited
 * somebody to buy and then sent them somewhere the club's own ticketing
 * knows nothing about -- no booking, no quantity counted, no release
 * respected -- and the three games would have gone stale by hand.
 *
 * It is a block now, on the same fixture data and the same sales settings
 * as everywhere else. The rows come from the league feed, the buttons are
 * the ones the match card uses, and pressing the ticket one turns the row
 * over into the same ticket. The row shape itself is kept exactly: every
 * measurement below was read off the markup it replaces, not redrawn.
 *
 * @package eastwood-fwp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The block's fields and what they say until somebody changes them. */
function ew_fxr_defaults() {
	$words = function_exists( 'ew_sales_words' ) ? ew_sales_words() : array();
	return array_merge(
		array(
			'count'      => '3',
			'label'      => 'Upcoming Match',
			'kick'       => 'First Team',
			'venue'      => 'Coronation Park',
			'home_only'  => 'yes',
			'empty'      => 'Fixtures to be confirmed',
			'days'       => 'Days',
			'hours'      => 'Hours',
			'mins'       => 'Mins',
			'secs'       => 'Secs',
			'cta'        => 'Unlock Ticket Access',
			'hosp'       => 'Book Hospitality',
			'hosp_url'   => '/hospitality/',
			'centre_url' => '/match/',
			'centre_lab' => 'Match details',
		),
		$words
	);
}

function ew_fxr_shortcode( $atts ) {
	$a = shortcode_atts( ew_fxr_defaults(), $atts, 'eastwood_fixture_rows' );

	if ( ! function_exists( 'ew_fixtures_league_list' ) ) {
		return '';
	}

	$today = current_time( 'Y-m-d' );
	$rows  = array();
	foreach ( ew_fixtures_league_list() as $m ) {
		if ( (string) ( $m['date'] ?? '' ) < $today ) {
			continue;
		}
		$m = function_exists( 'ew_fixture_apply' ) ? ew_fixture_apply( $m ) : $m;
		if ( ! $m ) {
			continue;
		}
		if ( 'no' !== $a['home_only']
			&& (int) ( ( (array) ( $m['home-team'] ?? array() ) )['id'] ?? 0 ) !== (int) EW_TEAM ) {
			continue;
		}
		$rows[] = $m;
		if ( count( $rows ) >= max( 1, (int) $a['count'] ) ) {
			break;
		}
	}

	if ( ! $rows ) {
		return '<div class="ewhx"><p class="ewhx-empty">' . esc_html( $a['empty'] ) . '</p></div>';
	}

	$h = '<div class="ewhx">';
	foreach ( $rows as $m ) {
		$h .= ew_fxr_row( $m, $a );
	}
	return $h . '</div>';
}
add_shortcode( 'eastwood_fixture_rows', 'ew_fxr_shortcode' );

/** One row, in the shape it already had. */
function ew_fxr_row( $m, $a ) {
	$id      = (int) ( $m['id'] ?? 0 );
	$home    = (array) ( $m['home-team'] ?? array() );
	$away    = (array) ( $m['away-team'] ?? array() );
	$at_home = (int) ( $home['id'] ?? 0 ) === (int) EW_TEAM;
	$opp     = $at_home ? $away : $home;
	$ts      = strtotime( (string) ( $m['date'] ?? '' ) . ' ' . (string) ( $m['time'] ?? '15:00' ) );
	$comp    = (string) ( $m['competition']['name'] ?? '' );
	$comp_id = (int) ( $m['competition']['id'] ?? 0 );
	$badge   = ( $comp_id && function_exists( 'ew_comp_badge' ) ) ? ew_comp_badge( $comp_id ) : '';
	$crest   = function_exists( 'ew_home_badge' ) ? ew_home_badge( $opp['id'] ?? 0 ) : '';
	$venue   = $at_home ? (string) $a['venue'] : (string) ( $m['venue'] ?? '' );

	// A home game with something behind the ticket button turns over, the
	// same way the card on the matches page does.
	$turn = $at_home && $id && function_exists( 'ew_sales_panel' );

	$r  = '<div class="ewhx-row">';
	$r .= '<div class="ewhx-lab">' . esc_html( $a['label'] ) . '</div>';

	$r .= '<div class="ewhx-when"><span class="ewhx-comp">';
	if ( '' !== $badge ) {
		$r .= '<img decoding="async" src="' . esc_url( $badge ) . '" alt=""'
			. ' onerror="this.style.display=\'none\'">';
	}
	$r .= '<em>' . esc_html( $comp ) . '</em></span>';
	$r .= '<span class="ewhx-dt">' . esc_html( $ts ? date_i18n( 'D j', $ts ) : '' ) . '<br>'
		. esc_html( $ts ? date_i18n( 'M', $ts ) : '' )
		. '<b>' . esc_html( $ts ? date_i18n( 'H:i', $ts ) : '' ) . '</b></span>';
	$r .= '<span class="ewhx-ha">' . ( $at_home ? 'H' : 'A' ) . '</span></div>';

	$r .= '<div class="ewhx-team">';
	if ( '' !== $crest ) {
		$r .= '<img decoding="async" class="ewhx-crest" src="' . esc_url( $crest ) . '"'
			. ' alt="' . esc_attr( (string) ( $opp['name'] ?? '' ) ) . '"'
			. ' onerror="this.style.visibility=\'hidden\'">';
	}
	$r .= '<div><div class="ewhx-kick">' . esc_html( $a['kick'] ) . '</div>'
		. '<div class="ewhx-name">' . esc_html( (string) ( $opp['name'] ?? '' ) ) . '</div>'
		. '<div class="ewhx-ven">' . esc_html( $venue ) . '</div></div></div>';

	// The same countdown the homepage banner uses, told the kick-off rather
	// than having today's numbers written into it.
	$r .= '<div class="ewhx-cd" data-ko="' . (int) $ts . '" aria-hidden="true">';
	foreach ( array( 'd' => $a['days'], 'h' => $a['hours'], 'm' => $a['mins'], 's' => $a['secs'] ) as $u => $word ) {
		$r .= '<div><b data-u="' . esc_attr( $u ) . '">--</b><span>' . esc_html( $word ) . '</span></div>';
	}
	$r .= '</div>';

	if ( $at_home && function_exists( 'ew_sales_actions' ) ) {
		$r .= ew_sales_actions( $m, $a, 'ewhx-act', 'ewhx-link' );
	} else {
		$r .= '<div class="ewhx-act"></div>';
	}

	$r .= '<a class="ewhx-end" href="'
		. esc_url( home_url( (string) $a['centre_url'] . $id . '/' ) ) . '"'
		. ' aria-label="' . esc_attr( $a['centre_lab'] ) . '">' . ew_fxr_ball() . '</a>';
	$r .= '</div>';

	if ( ! $turn ) {
		return $r;
	}
	return '<div class="ewf-card"><div class="ewf-face"><div data-ewf-front>' . $r . '</div>'
		. '<div data-ewf-back class="ewf-off">' . ew_sales_panel( $m, $a ) . '</div></div></div>';
}

/** The red ball on the end of the row, as it was drawn. */
function ew_fxr_ball() {
	return '<svg viewBox="0 0 24 24" aria-hidden="true">'
		. '<defs><clipPath id="ewfxb"><circle cx="12" cy="12" r="9.2"/></clipPath></defs>'
		. '<circle cx="12" cy="12" r="9.2" fill="#CC0000"/>'
		. '<g clip-path="url(#ewfxb)" fill="#fff">'
		. '<path d="M12.0 8.5L8.7 10.9L9.9 14.8L14.1 14.8L15.3 10.9Z"/>'
		. '<path d="M10.1 1.4L8.9 5.1L12.0 7.4L15.1 5.1L13.9 1.4Z"/>'
		. '<path d="M1.3 10.6L4.5 12.9L7.6 10.6L6.4 6.9L2.5 6.9Z"/>'
		. '<path d="M7.4 21.7L10.5 19.4L9.3 15.7L5.4 15.7L4.2 19.4Z"/>'
		. '<path d="M19.8 19.4L18.6 15.7L14.7 15.7L13.5 19.4L16.6 21.7Z"/>'
		. '<path d="M21.5 6.9L17.6 6.9L16.4 10.6L19.5 12.9L22.7 10.6Z"/>'
		. '</g></svg>';
}

/**
 * The row's styling, read off the markup this block replaces rather than
 * redrawn, so the page looks the same the moment it changes hands.
 */
function ew_fxr_css() {
	return '
.ewhx{--ink:#101010;--red:#CC0000;--line:rgba(17,17,17,.12);
 font-family:"Instrument Sans",system-ui,sans-serif}
.ewhx-row{display:flex;align-items:stretch;background:#fff;margin:0 0 24px;min-height:136px;
 border-radius:8px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.18)}
.ewhx-lab{flex:0 0 130px;display:flex;align-items:center;padding:18px;font-size:14px;
 font-weight:700;color:var(--ink)}
.ewhx-when{flex:0 0 270px;display:flex;align-items:center;gap:12px;padding:18px 10px}
.ewhx-comp{display:flex;flex-direction:column;align-items:center;gap:5px;flex:0 0 146px;
 min-width:0;text-align:center}
.ewhx-comp img{width:28px;height:40px;object-fit:contain}
.ewhx-comp em{font-style:normal;font-size:10px;line-height:1.2;font-weight:700;letter-spacing:.04em;
 text-transform:uppercase;color:#555;overflow-wrap:anywhere}
.ewhx-dt{font-size:15px;color:var(--ink);line-height:1.3}
.ewhx-dt b{display:block;font-weight:700}
.ewhx-ha{width:28px;height:28px;border-radius:50%;background:var(--red);color:#fff;font-size:12px;
 font-weight:700;display:flex;align-items:center;justify-content:center;flex:0 0 28px}
.ewhx-team{flex:1 1 auto;min-width:0;display:flex;align-items:center;gap:16px;padding:18px;
 border-left:1px solid var(--line)}
.ewhx-crest{width:82px;height:82px;object-fit:contain;flex:0 0 82px}
.ewhx-kick{font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;
 color:#212121;margin-bottom:6px}
.ewhx-name{font-size:30px;font-weight:700;line-height:1.05;color:var(--ink)}
.ewhx-ven{font-size:16px;color:#555;margin-top:4px}
.ewhx-cd{flex:0 0 198px;display:flex;align-items:center;justify-content:center;gap:12px;padding:18px 6px}
.ewhx-cd div{text-align:center;min-width:38px}
.ewhx-cd b{display:block;font-size:26px;font-weight:700;line-height:1;color:var(--ink)}
.ewhx-cd span{display:block;font-size:13px;color:#777;margin-top:5px}
.ewhx-act{flex:0 0 224px;display:flex;flex-direction:column;justify-content:center;gap:22px;padding:18px;margin:0}
.ewhx-link{display:flex;align-items:center;gap:10px;font-size:16px;font-weight:400;color:var(--ink);
 text-decoration:none;white-space:nowrap}
.ewhx-link svg{flex:0 0 22px;width:22px;height:22px;stroke:var(--red);fill:none;stroke-width:1.7;
 stroke-linecap:round;stroke-linejoin:round}
.ewhx-link:hover{text-decoration:underline}
.ewhx-end{flex:0 0 64px;display:flex;align-items:center;justify-content:center;
 border-left:1px solid var(--line)}
.ewhx-end svg{width:32px;height:32px}
.ewhx-empty{color:#bbb;font-size:15px;padding:16px 0}
@media(max-width:1180px){
 .ewhx-row{flex-wrap:wrap}
 .ewhx-lab{flex:1 1 100%;padding-bottom:0}
 .ewhx-cd{flex:1 1 100%;justify-content:flex-start;padding:0 18px 14px}
 .ewhx-act{flex:1 1 100%;flex-direction:row;gap:28px;padding:0 18px 18px}
 .ewhx-end{display:none}
}
@media(max-width:560px){
 .ewhx-when{flex:1 1 100%}
 .ewhx-team{border-left:0}
 .ewhx-crest{width:64px;height:64px;flex:0 0 64px}
 .ewhx-name{font-size:24px}
 .ewhx-act{flex-direction:column;gap:12px}
}';
}

add_action( 'wp_head', function () {
	$post = is_singular() ? get_post() : null;
	if ( $post && function_exists( 'ew_post_contains' )
		&& ew_post_contains( $post, 'eastwood_fixture_rows' ) ) {
		echo '<style id="ew-fixture-rows">' . ew_fxr_css() . '</style>'; // phpcs:ignore
		if ( function_exists( 'ew_sales_css' ) ) {
			echo '<style id="ew-fixture-rows-turn">' . ew_sales_css() . '</style>'; // phpcs:ignore
		}
	}
}, 21 );

add_action( 'wp_footer', function () {
	$post = is_singular() ? get_post() : null;
	if ( $post && function_exists( 'ew_post_contains' )
		&& ew_post_contains( $post, 'eastwood_fixture_rows' )
		&& ! ( function_exists( 'ew_home_blocks_present' ) && ew_home_blocks_present() )
		&& function_exists( 'ew_sales_js' ) ) {
		// Only when the shared block script is not already on the page, or
		// the turn would be wired up twice and every press would count twice.
		echo '<script id="ew-fixture-rows-js">' . ew_sales_js() . '</script>'; // phpcs:ignore
		echo '<script id="ew-fixture-rows-cd">' . ew_fxr_clock() . '</script>'; // phpcs:ignore
	}
}, 21 );

/** The countdown, for pages that do not already carry the shared one. */
function ew_fxr_clock() {
	return <<<'JS'
[].forEach.call(document.querySelectorAll('[data-ko]'), function(el){
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
