<?php
/**
 * Match day banner: the game, the crowd, travel info, and a clear way in for
 * away fans. Every word, colour and link is a field on the block, and it hides
 * itself after the time set in "until".
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'ew_blocks_allowed', function ( $list ) {
	$list[] = 'eastwood_matchday_banner';
	return $list;
} );

add_shortcode( 'eastwood_matchday_banner', function ( $atts ) {
	$img = plugins_url( 'assets/claim/', dirname( __DIR__ ) . '/eastwood-fwp.php' );
	$a   = shortcode_atts( array(
		'until'       => '2026-11-14 17:30',
		'kicker'      => 'MATCH DAY',
		'title'       => 'EASTWOOD FC v HUCKNALL TOWN',
		'when'        => 'Today · Gates open 1pm · Kick-off 3pm',
		'crowd'       => 'Capacity crowd expected, with over 3,000 tickets claimed.',
		'crowd2'      => 'Arrive early and check the travel info before you set off.',
		'travel_btn'  => 'TRAVEL INFO',
		'travel_url'  => home_url( '/matchday/' ),
		'home_btn'    => 'GET TICKETS',
		'home_url'    => home_url( '/tickets/?fan=home&fx=585791' ),
		'away_title'  => 'HUCKNALL TOWN FANS',
		'away_text'   => 'Get your away ticket here.',
		'away_btn'    => 'AWAY FAN TICKETS',
		'away_url'    => home_url( '/tickets/?fan=away&fx=585791' ),
		'away_badge'  => $img . 'hucknall-town-white.png',
		'away_colour' => '#FFD200',
		'away_ink'    => '#111111',
		'away_head'   => '#ffffff',
		'away_btn'    => '#ffffff',
		'away_btn_ink' => '#111111',
		// A photo behind the banner (an address, or a file in the plugin's assets); empty for plain black.
		'bg'          => '',
		// "next": the game, the away fans' crest, colours and links come from
		// the next home game; the banner shows from the Monday of match week
		// until "until_time" on the day. {away}, {day}, {time} and {claimed}
		// can be used in any of the words.
		'follow'      => '',
		'until_time'  => '17:30',
	), $atts, 'eastwood_matchday_banner' );

	$from = 0;
	if ( function_exists( 'ew_ng_follows' ) && ew_ng_follows( $a ) ) {
		$g = ew_ng_now();
		if ( ! $g ) {
			return '';
		}
		$tz   = new DateTimeZone( 'Europe/London' );
		$day  = new DateTimeImmutable( $g['ymd'] . ' 00:00', $tz );
		$from = $day->modify( '-' . ( (int) $day->format( 'N' ) - 1 ) . ' days' )->getTimestamp();
		$a['until']       = $g['ymd'] . ' ' . ( preg_match( '/^\d{1,2}:\d{2}$/', (string) $a['until_time'] ) ? $a['until_time'] : '17:30' );
		$a['away_badge']  = $g['white'];
		$a['away_colour'] = $g['colour'];
		$a['away_ink']    = $g['ink'];
		$a['home_url']    = add_query_arg( array( 'fan' => 'home', 'fx' => $g['id'] ), home_url( '/tickets/' ) );
		$a['away_url']    = add_query_arg( array( 'fan' => 'away', 'fx' => $g['id'] ), home_url( '/tickets/' ) );
		$claimed = function_exists( 'ew_ou_taken' ) ? number_format( (int) ew_ou_taken( $g['event'] ) ) : '';
		$swap    = array(
			'{away}'    => $g['away'],
			'{AWAY}'    => strtoupper( $g['away'] ),
			'{day}'     => $g['short'],
			'{time}'    => strtolower( $g['time'] ),
			'{claimed}' => $claimed,
		);
		foreach ( array( 'kicker', 'title', 'when', 'crowd', 'crowd2', 'away_title', 'away_text', 'home_btn', 'away_btn', 'travel_btn' ) as $k ) {
			$a[ $k ] = strtr( (string) $a[ $k ], $swap );
		}
		if ( time() < $from ) {
			return ew_ng_guard( $g['id'] );
		}
	}

	$until = strtotime( (string) $a['until'] . ' Europe/London' );
	if ( $until && time() > $until ) {
		return isset( $g['id'] ) ? ew_ng_guard( $g['id'] ) : '';
	}
	// The page can sit in the cache for days, so the browser checks the window too.
	$win = '<script>(function(){var n=Date.now()/1000,f=' . (int) $from . ',u=' . (int) $until . ',s=document.currentScript;if((f&&n<f)||(u&&n>u)){var w=s&&s.previousElementSibling;if(w)w.style.display="none";}})();</script>';
	$col = sanitize_hex_color( $a['away_colour'] ) ? $a['away_colour'] : '#FFD200';
	$bg  = trim( (string) $a['bg'] );
	if ( '' !== $bg && ! preg_match( '#^(https?:)?//#', $bg ) && 0 !== strpos( $bg, '/' ) ) {
		$bg = plugins_url( 'assets/' . ltrim( $bg, '/' ), dirname( __DIR__ ) . '/eastwood-fwp.php' );
	}
	$bgcss = '' !== $bg ? '.ewmb-stage{background:linear-gradient(rgba(10,10,11,.35),rgba(10,10,11,.55)),url(' . esc_url( $bg ) . ') center/cover no-repeat;padding:56px 24px}.ewmb-stage .ewmb-wrap{margin:0 auto}@media(max-width:820px){.ewmb-stage{padding:28px 16px}.ewmb-stage .ewmb-wrap{margin:0}}' : '';
	$ink  = sanitize_hex_color( $a['away_ink'] ) ? $a['away_ink'] : '#111111';
	$head = sanitize_hex_color( $a['away_head'] ) ? $a['away_head'] : '#ffffff';
	$bbg  = sanitize_hex_color( $a['away_btn'] ) ? $a['away_btn'] : '#ffffff';
	$bink = sanitize_hex_color( $a['away_btn_ink'] ) ? $a['away_btn_ink'] : '#111111';

	return '<style>.ewmb-wrap{max-width:1200px;margin:16px auto;border-radius:14px;overflow:hidden;font-family:"Instrument Sans",system-ui,sans-serif}.ewmb-top{background:#CC0000;color:#fff;padding:14px 24px;text-align:center;font-family:Anton,Impact,sans-serif;font-size:24px;line-height:1.15;letter-spacing:.5px;text-transform:uppercase}.ewmb{background:#111;color:#fff;display:grid;grid-template-columns:1fr 360px}'
		. '.ewmb-main{padding:22px 24px}.ewmb-k{font-size:12px;font-weight:700;letter-spacing:2px;color:#ff4d4d}'
		. '.ewmb h2{font-family:Anton,Impact,sans-serif;font-weight:400;font-size:34px;line-height:1.05;margin:6px 0 4px;color:#fff;background:none;padding:0}'
		. '.ewmb-w{font-size:15px;color:#c9c9cf}'
		. '.ewmb-btns{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}.ewmb a.ewmb-b{display:inline-flex;align-items:center;justify-content:center;height:48px;padding:0 20px;border-radius:10px;text-decoration:none;font-family:Anton,Impact,sans-serif;font-size:17px;letter-spacing:.5px}'
		. '.ewmb a.ewmb-red{background:#CC0000;color:#fff}.ewmb a.ewmb-line{border:1px solid rgba(255,255,255,.4);color:#fff}'
		. '.ewmb-away{padding:22px 24px;display:flex;flex-direction:column;justify-content:center;gap:10px;background:' . esc_attr( $col ) . '}'
		. '.ewmb-away .t{display:flex;align-items:center;gap:12px;font-family:Anton,Impact,sans-serif;font-size:24px;line-height:1.1;color:' . esc_attr( $head ) . '}.ewmb-away img{width:48px;height:48px;object-fit:contain}'
		. '.ewmb-away p{margin:0;font-size:15px;color:' . esc_attr( $head ) . '}.ewmb a.ewmb-aw{background:' . esc_attr( $bbg ) . ';color:' . esc_attr( $bink ) . ';height:52px}'
		. $bgcss
		. '@media(max-width:820px){.ewmb-wrap{margin:12px 16px}.ewmb-top{font-size:19px;padding:12px 18px}.ewmb-top span+span{margin-top:8px}.ewmb{grid-template-columns:1fr}.ewmb h2{font-size:28px}.ewmb-away{border-left:0;border-top:6px solid ' . esc_attr( $col ) . '}.ewmb-btns a.ewmb-b{flex:1}.ewmb-away a.ewmb-b{flex:none}}</style>'
		. ( '' !== $bg ? '<div class="ewmb-stage">' : '' ) . '<div class="ewmb-wrap">' . ( '' !== trim( (string) $a['crowd'] . (string) $a['crowd2'] ) ? '<div class="ewmb-top">' . ( '' !== trim( (string) $a['crowd'] ) ? '<span style="display:block">' . esc_html( $a['crowd'] ) . '</span>' : '' ) . ( '' !== trim( (string) $a['crowd2'] ) ? '<span style="display:block">' . esc_html( $a['crowd2'] ) . '</span>' : '' ) . '</div>' : '' ) . '<div class="ewmb"><div class="ewmb-main"><div class="ewmb-k">' . esc_html( $a['kicker'] ) . '</div><h2>' . esc_html( $a['title'] ) . '</h2><div class="ewmb-w">' . esc_html( $a['when'] ) . '</div>'
		. '<div class="ewmb-btns"><a class="ewmb-b ewmb-red" href="' . esc_url( $a['home_url'] ) . '">' . esc_html( $a['home_btn'] ) . '</a><a class="ewmb-b ewmb-line" href="' . esc_url( $a['travel_url'] ) . '">' . esc_html( $a['travel_btn'] ) . '</a></div></div>'
		. '<div class="ewmb-away"><div class="t"><img src="' . esc_url( $a['away_badge'] ) . '" alt="">' . esc_html( $a['away_title'] ) . '</div><p>' . esc_html( $a['away_text'] ) . '</p>'
		. '<a class="ewmb-b ewmb-aw" href="' . esc_url( $a['away_url'] ) . '">' . esc_html( $a['away_btn'] ) . '</a></div></div></div>' . ( '' !== $bg ? '</div>' : '' ) . $win . ( isset( $g['id'] ) ? ew_ng_guard( $g['id'] ) : '' );
} );
