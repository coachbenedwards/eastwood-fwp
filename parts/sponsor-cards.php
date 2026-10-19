<?php
/**
 * The sponsorship cards on the hospitality page.
 *
 * The same card as the hospitality packages above them: a name set in
 * Anton, a line of fact, a short list of what is included and a price,
 * with the list and the price left as ruled blanks for Ben to write in.
 * The blanks are deliberate -- he fills these in himself, and a card
 * with an invented price in it is worse than a card with a line to
 * write one on.
 *
 * Every word the card shows is a field on the block, so a person can
 * change any of it in Elementor without opening a file: the names and
 * the fact lines are the body of the shortcode, one card per line, and
 * the small print above the price is an attribute.
 *
 *   [eastwood_sponsor_cards label="From" lines="4"]
 *   Man of the Match | Chosen by the dugout, presented on the pitch.
 *   Matchday Sponsor | The whole game carries your name.
 *   Match Ball Sponsor | Your ball, your photograph with the captains.
 *   [/eastwood_sponsor_cards]
 *
 * @package eastwood-fwp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turn the body of the shortcode into cards.
 *
 * One card per line, "name | fact", and the fact is optional. A line
 * with nothing on it is skipped rather than drawing an empty column,
 * so a person can leave a blank line between cards while editing.
 *
 * @param string $body The shortcode body.
 * @return array<int,array{name:string,fact:string}>
 */
function ew_sponsor_cards_parse( $body ) {
	$out = array();
	foreach ( preg_split( '/\r\n|\r|\n/', (string) $body ) as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}
		$bits = array_map( 'trim', explode( '|', $line ) );
		$name = (string) array_shift( $bits );
		if ( '' === $name ) {
			continue;
		}
		$out[] = array(
			'name' => $name,
			'fact' => trim( implode( ' | ', $bits ) ),
		);
	}
	return $out;
}

/**
 * The stylesheet for the cards.
 *
 * Written against the hospitality packages above, measured rather than
 * guessed: same 1180px column, same hairline between columns, same
 * 86px of space above the name so three cards line up whether the name
 * runs to one line or two, same dashed rules.
 *
 * @return string
 */
function ew_sponsor_cards_css() {
	return '.ewsp{font-family:"Instrument Sans",system-ui,sans-serif}'
		. '.ewsp-cols{display:flex;gap:0;max-width:1180px;margin:0 auto}'
		. '.ewsp-col{box-sizing:border-box;flex:1 1 0;min-width:0;padding:0 34px;'
		. 'border-left:1px solid rgba(255,255,255,.14);display:flex;flex-direction:column}'
		. '.ewsp-col:first-child{border-left:0}'
		. '.ewsp-mark{min-height:86px;display:flex;flex-direction:column;'
		. 'justify-content:flex-end;margin-bottom:26px}'
		. '.ewsp-mark b{display:block;font-family:Anton,Impact,sans-serif;'
		. 'font-size:clamp(24px,2.5vw,34px);line-height:.98;letter-spacing:.5px;'
		. 'text-transform:uppercase;color:#fff;font-weight:400}'
		. '.ewsp-fact{font-size:14px;line-height:1.55;color:#9a9aa3;margin:0 0 22px;'
		. 'padding-bottom:20px;border-bottom:1px solid rgba(255,255,255,.10);min-height:44px}'
		. '.ewsp-fact b{color:#ededf0;font-weight:600}'
		. '.ewsp-inc{list-style:none;margin:0 0 26px;padding:0;flex:1 1 auto}'
		. '.ewsp-inc li{position:relative;padding-left:18px;height:21px;margin-bottom:13px}'
		. '.ewsp-inc li:before{content:"";position:absolute;left:0;top:7px;width:7px;'
		. 'height:7px;background:rgba(255,255,255,.22)}'
		. '.ewsp-inc li:after{content:"";position:absolute;left:18px;right:12px;bottom:4px;'
		. 'border-bottom:1px dashed rgba(255,255,255,.22)}'
		. '.ewsp-price{margin-top:auto;padding-top:8px}'
		. '.ewsp-price span{display:block;font-size:12px;letter-spacing:.16em;'
		. 'text-transform:uppercase;color:#9a9aa3;margin-bottom:8px}'
		. '.ewsp-price i{display:inline-block;min-width:86px;height:38px;'
		. 'border-bottom:2px dashed rgba(255,255,255,.3)}'
		. '@media(max-width:900px){.ewsp-cols{flex-direction:column}'
		. '.ewsp-col{border-left:0;border-top:1px solid rgba(255,255,255,.14);padding:30px 0}'
		. '.ewsp-col:first-child{border-top:0;padding-top:0}}';
}

/**
 * [eastwood_sponsor_cards]
 *
 * @param array       $atts    Attributes.
 * @param string|null $content Body: one card per line.
 * @return string
 */
function ew_sponsor_cards_shortcode( $atts, $content = null ) {
	$atts = shortcode_atts(
		array(
			'label' => 'From',
			'lines' => '4',
		),
		(array) $atts,
		'eastwood_sponsor_cards'
	);

	$cards = ew_sponsor_cards_parse( $content );
	if ( ! $cards ) {
		return '';
	}

	$blanks = max( 0, min( 8, (int) $atts['lines'] ) );

	$out = '<div class="ewsp"><div class="ewsp-cols">';
	foreach ( $cards as $card ) {
		$fact = '' === $card['fact'] ? '&nbsp;' : esc_html( $card['fact'] );

		$out .= '<div class="ewsp-col">';
		$out .= '<div class="ewsp-mark"><b>' . esc_html( $card['name'] ) . '</b></div>';
		$out .= '<p class="ewsp-fact">' . $fact . '</p>';
		$out .= '<ul class="ewsp-inc">' . str_repeat( '<li></li>', $blanks ) . '</ul>';
		$out .= '<div class="ewsp-price"><span>' . esc_html( $atts['label'] ) . '</span><i></i></div>';
		$out .= '</div>';
	}
	$out .= '</div></div>';

	return '<style>' . ew_sponsor_cards_css() . '</style>' . $out;
}
add_shortcode( 'eastwood_sponsor_cards', 'ew_sponsor_cards_shortcode' );
