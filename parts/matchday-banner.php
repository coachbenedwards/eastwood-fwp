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
	), $atts, 'eastwood_matchday_banner' );

	$until = strtotime( (string) $a['until'] . ' Europe/London' );
	if ( $until && time() > $until ) {
		return '';
	}
	$col = sanitize_hex_color( $a['away_colour'] ) ? $a['away_colour'] : '#FFD200';
	$ink = sanitize_hex_color( $a['away_ink'] ) ? $a['away_ink'] : '#111111';

	return '<style>.ewmb-wrap{max-width:1200px;margin:16px auto;border-radius:14px;overflow:hidden;font-family:"Instrument Sans",system-ui,sans-serif}.ewmb-top{background:#CC0000;color:#fff;padding:14px 24px;text-align:center;font-family:Anton,Impact,sans-serif;font-size:24px;line-height:1.15;letter-spacing:.5px;text-transform:uppercase}.ewmb{background:#111;color:#fff;display:grid;grid-template-columns:1fr 360px}'
		. '.ewmb-main{padding:22px 24px}.ewmb-k{font-size:12px;font-weight:700;letter-spacing:2px;color:#ff4d4d}'
		. '.ewmb h2{font-family:Anton,Impact,sans-serif;font-weight:400;font-size:34px;line-height:1.05;margin:6px 0 4px;color:#fff;background:none;padding:0}'
		. '.ewmb-w{font-size:15px;color:#c9c9cf}'
		. '.ewmb-btns{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}.ewmb a.ewmb-b{display:inline-flex;align-items:center;justify-content:center;height:48px;padding:0 20px;border-radius:10px;text-decoration:none;font-family:Anton,Impact,sans-serif;font-size:17px;letter-spacing:.5px}'
		. '.ewmb a.ewmb-red{background:#CC0000;color:#fff}.ewmb a.ewmb-line{border:1px solid rgba(255,255,255,.4);color:#fff}'
		. '.ewmb-away{padding:22px 24px;display:flex;flex-direction:column;justify-content:center;gap:10px;border-left:6px solid ' . esc_attr( $col ) . ';background:#1b1b1d}'
		. '.ewmb-away .t{display:flex;align-items:center;gap:12px;font-family:Anton,Impact,sans-serif;font-size:24px;line-height:1.1;color:' . esc_attr( $col ) . '}.ewmb-away img{width:48px;height:48px;object-fit:contain}'
		. '.ewmb-away p{margin:0;font-size:15px;color:#ededf0}.ewmb a.ewmb-aw{background:' . esc_attr( $col ) . ';color:' . esc_attr( $ink ) . ';height:52px}'
		. '@media(max-width:820px){.ewmb-wrap{margin:12px 16px}.ewmb-top{font-size:19px;padding:12px 18px}.ewmb-top span+span{margin-top:8px}.ewmb{grid-template-columns:1fr}.ewmb h2{font-size:28px}.ewmb-away{border-left:0;border-top:6px solid ' . esc_attr( $col ) . '}.ewmb-btns a.ewmb-b{flex:1}.ewmb-away a.ewmb-b{flex:none}}</style>'
		. '<div class="ewmb-wrap"><div class="ewmb-top"><span style="display:block">' . esc_html( $a['crowd'] ) . '</span>' . ( '' !== trim( (string) $a['crowd2'] ) ? '<span style="display:block">' . esc_html( $a['crowd2'] ) . '</span>' : '' ) . '</div><div class="ewmb"><div class="ewmb-main"><div class="ewmb-k">' . esc_html( $a['kicker'] ) . '</div><h2>' . esc_html( $a['title'] ) . '</h2><div class="ewmb-w">' . esc_html( $a['when'] ) . '</div>'
		. '<div class="ewmb-btns"><a class="ewmb-b ewmb-red" href="' . esc_url( $a['home_url'] ) . '">' . esc_html( $a['home_btn'] ) . '</a><a class="ewmb-b ewmb-line" href="' . esc_url( $a['travel_url'] ) . '">' . esc_html( $a['travel_btn'] ) . '</a></div></div>'
		. '<div class="ewmb-away"><div class="t"><img src="' . esc_url( $a['away_badge'] ) . '" alt="">' . esc_html( $a['away_title'] ) . '</div><p>' . esc_html( $a['away_text'] ) . '</p>'
		. '<a class="ewmb-b ewmb-aw" href="' . esc_url( $a['away_url'] ) . '">' . esc_html( $a['away_btn'] ) . '</a></div></div></div>';
} );
