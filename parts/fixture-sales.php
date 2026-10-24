<?php
/**
 * What is on sale, game by game.
 *
 * Every fixture on the site comes from the league feed, and until now the
 * Buy tickets and Book hospitality buttons were fixed strings on the block:
 * the same two links on every home game whether the club was selling it or
 * not. A game nobody had priced still invited a booking, and a game held
 * back for a release could not say so.
 *
 * So each fixture now carries two answers of its own -- one for tickets,
 * one for hospitality -- and each is one of four states:
 *
 *   off      Not announced yet. Where every new fixture starts, so a game
 *            arriving from the feed can never be sellable by accident.
 *   soon     On sale from a moment you set. The site works this out as it
 *            draws the page by comparing that moment to now, so nothing
 *            has to run on a schedule and nobody has to be at a desk at
 *            9am -- and until then the fixture advertises its own drop.
 *   on       Selling.
 *   out      Sold out, or hospitality full. Reached by hand, or on its own
 *            once a release number has gone.
 *
 * Prices and the room list are the club's, held once, and a game overrides
 * only what differs about it -- The Venue at 80 places for a big night
 * rather than its usual 60.
 *
 * Nothing here charges anybody. It decides what the fixture card offers and
 * what the tickets page lists; the money still goes through the Stripe
 * checkout in parts/tickets-flow.php, which is Ventures'.
 *
 * @package eastwood-fwp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* --- The club's standing answers ---------------------------------------- */

/**
 * The option, whole. One option rather than a scatter of them, for the same
 * reason the fixture corrections are one option: everything about a game is
 * read and written together or the parts fall out of step.
 */
function ew_sales_opt() {
	$o = get_option( 'ew_sales', array() );
	return is_array( $o ) ? $o : array();
}

/**
 * The ticket types, in the order they are offered, priced in pence.
 *
 * The option holds what somebody typed -- pounds, as "7.00" -- and pence is
 * worked out here. That is deliberate: WordPress runs a registered
 * setting's sanitise callback more than once on the save that first creates
 * the option, so anything the callback CHANGES gets changed twice. Pounds
 * in and pounds out survives that; pounds in and pence out multiplied every
 * price by a hundred the first time this was saved.
 */
function ew_sales_types() {
	$p = (array) ( ew_sales_opt()['prices'] ?? array() );
	$of = function ( $key, $fallback ) use ( $p ) {
		return isset( $p[ $key ] ) && '' !== $p[ $key ]
			? ew_sales_pence( $p[ $key ] ) : $fallback;
	};
	return array(
		'adult'   => array( 'label' => 'Adult', 'note' => '', 'pence' => $of( 'adult', 700 ) ),
		'conc'    => array( 'label' => 'Concession', 'note' => '65 and over', 'pence' => $of( 'conc', 500 ) ),
		'student' => array( 'label' => 'Student', 'note' => 'With valid ID on the gate', 'pence' => $of( 'student', 500 ) ),
		'u16'     => array( 'label' => 'Under 16', 'note' => 'Free with a paying adult', 'pence' => $of( 'u16', 0 ) ),
	);
}

/** What somebody typed, as a settled "0.00" string. Running it again is safe. */
function ew_sales_pounds( $v ) {
	$v = preg_replace( '/[^0-9.]/', '', trim( (string) $v ) );
	return '' === $v ? '' : number_format( (float) $v, 2, '.', '' );
}

/**
 * The club's rooms, with the price and capacity each usually has.
 *
 * Stored as a list so the order on the screen is the order on the page, and
 * so a room can be added without touching any code. The key is made from
 * the name once and then kept, because a game's ticks point at it.
 */
function ew_sales_rooms_std() {
	$rows = (array) ( ew_sales_opt()['rooms'] ?? array() );
	if ( ! $rows ) {
		return array();
	}
	$out = array();
	foreach ( $rows as $key => $r ) {
		$key = sanitize_key( (string) $key );
		if ( '' === $key ) {
			continue;
		}
		$out[ $key ] = array(
			'name'  => (string) ( $r['name'] ?? $key ),
			'note'  => (string) ( $r['note'] ?? '' ),
			'pence' => ew_sales_pence( $r['pence'] ?? 0 ),
			'qty'   => max( 0, (int) ( $r['qty'] ?? 0 ) ),
			'raw'   => (string) ( $r['pence'] ?? '' ),
		);
	}
	return $out;
}

/** Everything saved against one fixture. */
function ew_sales_fixture( $id ) {
	$all = (array) ( ew_sales_opt()['fixtures'] ?? array() );
	$row = (array) ( $all[ (int) $id ] ?? array() );
	return $row;
}

/* --- What state a fixture is in ---------------------------------------- */

/**
 * Resolve one side of a fixture -- 'tix' or 'hosp' -- to what the page
 * should actually do about it.
 *
 * The saved state is what a person chose; this is what is true now. A game
 * set to open at nine this morning reads as on, and one whose release has
 * all gone reads as out, without anybody having been back to the screen.
 *
 * @return array state off|soon|on|out, when (timestamp or 0), left, cap, sold.
 */
function ew_sales_state( $id, $kind = 'tix' ) {
	$kind = 'hosp' === $kind ? 'hosp' : 'tix';
	$row  = ew_sales_fixture( $id );
	$set  = (string) ( $row[ $kind . '_state' ] ?? 'off' );
	$set  = in_array( $set, array( 'off', 'soon', 'on', 'out' ), true ) ? $set : 'off';
	$when = (int) ( $row[ $kind . '_when' ] ?? 0 );
	$cap  = max( 0, (int) ( $row[ $kind . '_cap' ] ?? 0 ) );
	$sold = 'tix' === $kind ? ew_sales_sold( $id ) : 0;
	$left = $cap ? max( 0, $cap - $sold ) : 0;

	// A release that has come round is simply on. One still ahead keeps its
	// date, because the date is the thing worth saying.
	if ( 'soon' === $set ) {
		if ( ! $when ) {
			$set = 'off';
		} elseif ( $when <= current_time( 'timestamp' ) ) {
			$set = 'on';
		}
	}

	// Counting closes the game whatever the switch says, so an oversell
	// cannot happen between somebody buying the last one and somebody
	// noticing.
	if ( 'on' === $set && $cap && $left < 1 ) {
		$set = 'out';
	}

	return array(
		'state' => $set,
		'when'  => $when,
		'cap'   => $cap,
		'sold'  => $sold,
		'left'  => $left,
	);
}

/**
 * How many tickets are already sold for a fixture.
 *
 * Paid bookings only: a booking saved at the details step and then
 * abandoned must not hold a seat, or a release would close itself on
 * people who never paid.
 */
function ew_sales_sold( $id ) {
	$id = (int) $id;
	if ( ! $id ) {
		return 0;
	}
	$found = get_posts(
		array(
			'post_type'              => 'ewt_booking',
			'post_status'            => 'any',
			'numberposts'            => 400,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_query'             => array(            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array( 'key' => '_ewt_fixture', 'value' => $id ),
				array( 'key' => '_ewt_status', 'value' => 'paid' ),
			),
		)
	);
	$n = 0;
	foreach ( $found as $bid ) {
		$n += max( 0, (int) get_post_meta( $bid, '_ewt_seats', true ) );
	}
	return $n;
}

/** The rooms this game is actually offering, with its own numbers applied. */
function ew_sales_rooms( $id ) {
	$std = ew_sales_rooms_std();
	$row = ew_sales_fixture( $id );
	$on  = (array) ( $row['rooms'] ?? array() );
	$out = array();
	foreach ( $std as $key => $r ) {
		if ( empty( $on[ $key ]['on'] ) ) {
			continue;
		}
		$r['pence'] = isset( $on[ $key ]['pence'] ) && '' !== $on[ $key ]['pence']
			? ew_sales_pence( $on[ $key ]['pence'] ) : $r['pence'];
		$r['qty']   = isset( $on[ $key ]['qty'] ) && '' !== $on[ $key ]['qty']
			? max( 0, (int) $on[ $key ]['qty'] ) : $r['qty'];
		$out[ $key ] = $r;
	}
	return $out;
}

/**
 * The words for a state, as the fixture card should say them.
 *
 * The block owns the wording; this only picks which of its fields applies
 * and fills the date in. {when} is the one token, because the date is the
 * only thing about a release the editor cannot type in advance.
 */
function ew_sales_say( $st, $a, $kind = 'tix' ) {
	$f = 'hosp' === $kind ? 'hosp_' : 'tix_';
	switch ( $st['state'] ) {
		case 'soon':
			$word = (string) ( $a[ $f . 'soon' ] ?? '' );
			$date = $st['when'] ? date_i18n( 'D j M, ga', $st['when'] ) : '';
			return str_replace( '{when}', $date, $word );
		case 'out':
			return (string) ( $a[ $f . 'out' ] ?? '' );
		case 'off':
			return (string) ( $a[ $f . 'off' ] ?? '' );
	}
	return '';
}

/** Is anything at all sellable on this fixture? */
function ew_sales_any( $id ) {
	return 'on' === ew_sales_state( $id, 'tix' )['state']
		|| 'on' === ew_sales_state( $id, 'hosp' )['state'];
}

/* --- The words ---------------------------------------------------------- */

/**
 * Every word the turn and its states can show, with what it says until
 * somebody changes it.
 *
 * Held here rather than in each block so that the fixture card reads the
 * same wherever it appears, and added to the blocks that draw a card as
 * fields, so none of this is a string in the code by the time it reaches
 * the page. {when} is the release date and {n} the number left -- the two
 * things an editor cannot type in advance.
 */
function ew_sales_words() {
	return array(
		'tix_off'     => 'Not announced yet',
		'tix_off_sub' => "Tickets for this game haven't been released. They appear here the moment they are.",
		'tix_soon'    => 'On sale {when}',
		'tix_soon_sub' => 'Come back then and this is where you buy them.',
		'tix_out'     => 'Sold out',
		'tix_out_sub' => 'This release has gone.',
		'tix_gate'    => 'You can always pay on the gate on the day. Under-16s come in free with a paying adult.',
		'tix_url'     => '/tickets/',
		'tix_bg'      => 'claim/ticket-bg.jpg',
		'tix_head'    => 'Tickets',
		'tix_step'    => 'Step 1 of 2',
		'tix_who'     => "Who's coming?",
		'tix_free'    => 'Free',
		'tix_none'    => 'Nobody yet',
		'tix_left'    => '{n} left of this release',
		'tix_go'      => 'Continue',
		'tix_back'    => 'Back to the fixture',
		'tix_swap'    => 'Lunch and a table instead?',
		'tix_less'    => 'One fewer',
		'tix_more'    => 'One more',
	);
}

/**
 * What a home fixture offers, as the row of actions under the crests.
 *
 * Both buttons, on every home game, always -- which is the thing Forest get
 * right and the first version of this got wrong. Replacing the button with
 * the words "not announced yet" takes away the only thing on the card worth
 * pressing, and a fixture with nothing to press looks like a fixture nobody
 * is doing anything about.
 *
 * So the button stays and the card answers. Press it and the fixture turns
 * over, and the other side says what is true: the counters if the game is
 * selling, the day it drops if it is not yet, sold out if it has gone. The
 * state is never a reason to show less; it only changes what is behind the
 * press.
 */
function ew_sales_actions( $m, $a, $class = 'ewp-rowCta', $link = '' ) {
	$id = (int) ( $m['id'] ?? 0 );
	if ( ! $id ) {
		return '';
	}
	$ico  = function_exists( 'ew_hb_icon' ) ? 'ew_hb_icon' : null;
	$hosp = '';
	$tix  = '';

	$plain = '' !== $link ? ' class="' . esc_attr( $link ) . '"' : '';
	$prime = '' !== $link ? ' class="' . esc_attr( $link ) . '"' : ' class="is-primary"';

	if ( '' !== (string) ( $a['hosp'] ?? '' ) ) {
		$hosp = '<a' . $plain . ' href="' . esc_url( home_url( (string) ( $a['hosp_url'] ?? '/hospitality/' ) ) ) . '">'
			. ( $ico ? $ico( 'dine' ) : '' )
			. '<span>' . esc_html( (string) $a['hosp'] ) . '</span></a>';
	}

	if ( '' !== (string) ( $a['cta'] ?? '' ) ) {
		// An anchor, not a button: it works with JavaScript off and can be
		// opened in a new tab. The turn intercepts the click when it can.
		$tix = '<a' . $prime . ' data-ewf-open href="'
			. esc_url( add_query_arg( 'fx', $id, home_url( (string) ( $a['tix_url'] ?? '/tickets/' ) ) ) ) . '">'
			. ( $ico ? $ico( 'ticket' ) : '' )
			. '<span>' . esc_html( (string) $a['cta'] ) . '</span></a>';
	}

	// Hospitality first, everywhere. The hero used to lead with tickets and
	// the rows with hospitality, so the same two buttons sat in a different
	// order twice on one page.
	$bits = array_filter( array( $hosp, $tix ) );

	return $bits ? '<p class="' . esc_attr( $class ) . '">' . implode( '', $bits ) . '</p>' : '';
}

/* --- The ticket panel: what a fixture card turns into ------------------- */

/**
 * The back of the fixture card.
 *
 * Built here rather than reusing the claim page's ticket because the
 * footprints are different -- that one is a whole page, this one has to sit
 * inside a row in a list -- but it is the same object: the match across the
 * top, a perforated stub down the right, counters, a total, Continue.
 *
 * Continue hands off to the tickets page with the fixture and the numbers
 * on the link, so the card never has to hold a checkout of its own and
 * there is still a URL somebody can send to a friend.
 */
function ew_sales_panel( $m, $a ) {
	$id = (int) ( $m['id'] ?? 0 );
	if ( ! $id ) {
		return '';
	}
	$st = ew_sales_state( $id, 'tix' );

	$home = (array) ( $m['home-team'] ?? array() );
	$away = (array) ( $m['away-team'] ?? array() );
	$ts   = strtotime( (string) ( $m['date'] ?? '' ) . ' ' . (string) ( $m['time'] ?? '15:00' ) );
	$url  = home_url( (string) ( $a['tix_url'] ?? '/tickets/' ) );

	$bg = function_exists( 'ew_hb_asset' ) ? ew_hb_asset( (string) ( $a['tix_bg'] ?? '' ) ) : '';
	$h  = '<div class="ewf-tk" data-ewf-panel data-fx="' . $id . '" data-url="' . esc_url( $url ) . '"'
		. ( '' !== $bg ? ' style="background-image:url(' . esc_url( $bg ) . ')"' : '' ) . '>';

	$h .= '<div class="ewf-tkMatch">';
	$h .= '<p class="ewf-tkTie"><span>' . esc_html( (string) ( $home['name'] ?? '' ) ) . '</span>'
		. '<em>v</em><span>' . esc_html( (string) ( $away['name'] ?? '' ) ) . '</span></p>';
	$h .= '<p class="ewf-tkWhen"><b>' . esc_html( $ts ? date_i18n( 'l j F Y', $ts ) : '' ) . '</b>'
		. '<i>' . esc_html( $ts ? date_i18n( 'ga', $ts ) : '' ) . '</i></p>';
	if ( $st['cap'] ) {
		$h .= '<p class="ewf-tkLeft">' . esc_html( str_replace(
			'{n}',
			number_format_i18n( $st['left'] ),
			(string) ( $a['tix_left'] ?? '{n} left of this release' )
		) ) . '</p>';
	}
	$h .= '</div>';

	$h .= '<div class="ewf-tkStub">';

	// Not selling: the stub says so and why, rather than showing counters
	// that cannot add up to anything. The card still turned, so the press
	// was answered -- which is the whole point of keeping the button.
	if ( 'on' !== $st['state'] ) {
		$word = 'soon' === $st['state'] ? 'tix_soon' : ( 'out' === $st['state'] ? 'tix_out' : 'tix_off' );
		$h   .= '<p class="ewf-tkHead"><span>' . esc_html( (string) ( $a['tix_head'] ?? 'Tickets' ) ) . '</span></p>';
		$h   .= '<p class="ewf-tkState">' . esc_html( ew_sales_say( $st, $a, 'tix' ) ) . '</p>';
		$sub  = (string) ( $a[ $word . '_sub' ] ?? '' );
		if ( '' !== $sub ) {
			$h .= '<p class="ewf-tkSub">' . esc_html( $sub ) . '</p>';
		}
		if ( '' !== (string) ( $a['tix_gate'] ?? '' ) ) {
			$h .= '<p class="ewf-tkSub ewf-tkGate">' . esc_html( (string) $a['tix_gate'] ) . '</p>';
		}
		$h .= '<div class="ewf-tkFill"></div>';
		$h .= '<p class="ewf-tkBack"><button type="button" data-ewf-close>'
			. esc_html( (string) ( $a['tix_back'] ?? 'Back to the fixture' ) ) . '</button></p>';
		return $h . '</div></div>';
	}

	$h .= '<p class="ewf-tkHead"><span>' . esc_html( (string) ( $a['tix_head'] ?? 'Tickets' ) ) . '</span>'
		. '<small>' . esc_html( (string) ( $a['tix_step'] ?? 'Step 1 of 2' ) ) . '</small></p>';
	$h .= '<p class="ewf-tkLab">' . esc_html( (string) ( $a['tix_who'] ?? "Who's coming?" ) ) . '</p>';

	$h .= '<div class="ewf-tkRows">';
	foreach ( ew_sales_types() as $key => $t ) {
		$h .= '<div class="ewf-tkRow" data-t="' . esc_attr( $key ) . '" data-p="' . (int) $t['pence'] . '">';
		$h .= '<span class="ewf-tkWho"><b>' . esc_html( $t['label'] ) . '</b>'
			. ( '' !== $t['note'] ? '<small>' . esc_html( $t['note'] ) . '</small>' : '' ) . '</span>';
		$h .= '<span class="ewf-tkPick"><span class="ewf-tkP">'
			. esc_html( $t['pence'] ? ew_sales_money( $t['pence'] ) : (string) ( $a['tix_free'] ?? 'Free' ) )
			. '</span><span class="ewf-tkPm">'
			. '<button type="button" data-d="-1" aria-label="' . esc_attr( (string) ( $a['tix_less'] ?? 'One fewer' ) ) . '" disabled>&minus;</button>'
			. '<output>0</output>'
			. '<button type="button" data-d="1" aria-label="' . esc_attr( (string) ( $a['tix_more'] ?? 'One more' ) ) . '">+</button>'
			. '</span></span>';
		$h .= '</div>';
	}
	$h .= '</div>';

	$h .= '<p class="ewf-tkSum"><span data-ewf-who>' . esc_html( (string) ( $a['tix_none'] ?? 'Nobody yet' ) )
		. '</span><span data-ewf-tot>' . esc_html( ew_sales_money( 0 ) ) . '</span></p>';
	$h .= '<button type="button" class="ewf-tkGo" data-ewf-go disabled>'
		. esc_html( (string) ( $a['tix_go'] ?? 'Continue' ) ) . '</button>';

	$hosp_st = ew_sales_state( $id, 'hosp' );
	if ( 'on' === $hosp_st['state'] && '' !== (string) ( $a['tix_swap'] ?? '' ) ) {
		$h .= '<p class="ewf-tkSwap"><span>' . esc_html( (string) $a['tix_swap'] ) . '</span>'
			. '<a href="' . esc_url( home_url( (string) ( $a['hosp_url'] ?? '/hospitality/' ) ) ) . '">'
			. esc_html( (string) ( $a['hosp'] ?? 'Book hospitality' ) ) . '</a></p>';
	}

	$h .= '<p class="ewf-tkBack"><button type="button" data-ewf-close>'
		. esc_html( (string) ( $a['tix_back'] ?? 'Back to the fixture' ) ) . '</button></p>';
	$h .= '</div>';

	return $h . '</div>';
}

/** Pence as the club writes money: no trailing pence on a round pound. */
function ew_sales_money( $pence ) {
	$pence = (int) $pence;
	return '£' . ( 0 === $pence % 100
		? number_format_i18n( $pence / 100 )
		: number_format_i18n( $pence / 100, 2 ) );
}

/* --- The turn ----------------------------------------------------------- */

/**
 * Styling and behaviour for the card that turns.
 *
 * The turn is in two halves on purpose. A card and a ticket are not the
 * same height, and matching them would mean a tall card with space under
 * its counters -- so instead the card goes edge-on, the faces swap while
 * nothing can be seen, and it comes back round. The height changes in the
 * middle of the turn, where it reads as the card being a different shape
 * on the other side rather than as the page jumping.
 */
function ew_sales_css() {
	return '
.ewf-card{perspective:2200px}
.ewf-card .ewf-face{transition:transform .22s ease-in;transform-style:preserve-3d}
.ewf-card.is-edge .ewf-face{transform:rotateY(-90deg)}
.ewf-card.is-snap .ewf-face{transition:none;transform:rotateY(90deg)}
.ewf-off{display:none}
@media (prefers-reduced-motion:reduce){.ewf-card .ewf-face{transition:none}}

.ewp-bannerWrap.ewf-card{perspective:2600px}

.ewf-tk{display:grid;grid-template-columns:minmax(0,1fr) 380px;color:#fff;border:2px solid #fff;
 border-radius:10px;overflow:hidden;text-align:left;background:#101010 left center/cover no-repeat}
.ewf-tkMatch{padding:30px 32px;display:flex;flex-direction:column;justify-content:center;gap:18px;
 background:rgba(0,0,0,.35)}
.ewf-tkTie{margin:0;display:flex;flex-direction:column;gap:4px;font-family:Anton,Impact,sans-serif;
 font-weight:400;font-size:46px;line-height:.98;text-transform:uppercase;letter-spacing:.5px}
.ewf-tkTie em{font-family:Georgia,serif;font-style:italic;font-size:26px;color:#e01b1b;line-height:1}
.ewf-tkWhen{margin:0;display:flex;align-items:baseline;gap:14px;flex-wrap:wrap}
.ewf-tkWhen b{font-size:16px;font-weight:700;letter-spacing:.04em;text-transform:uppercase}
.ewf-tkWhen i{font-family:Anton,Impact,sans-serif;font-style:normal;font-size:30px;color:#e01b1b}
.ewf-tkLeft{margin:0;font-size:13.5px;color:rgba(255,255,255,.6)}
.ewf-tkStub{border-left:3px dashed rgba(255,255,255,.85);padding:24px 26px;background:rgba(0,0,0,.8)}
.ewf-tkHead{margin:0 0 16px;display:flex;align-items:baseline;justify-content:space-between;gap:12px;
 border-bottom:1px solid rgba(255,255,255,.18);padding-bottom:10px}
.ewf-tkHead span{font-family:Anton,Impact,sans-serif;font-size:24px;text-transform:uppercase;letter-spacing:.5px}
.ewf-tkHead small{font-size:11.5px;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.5)}
.ewf-tkLab{margin:0 0 10px;font-size:12px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.55)}
.ewf-tkRow{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:11px 0;
 border-top:1px solid rgba(255,255,255,.1)}
.ewf-tkRow:first-child{border-top:0}
.ewf-tkWho b{display:block;font-size:15px;font-weight:700}
.ewf-tkWho small{display:block;font-size:12px;color:rgba(255,255,255,.5);margin-top:1px}
.ewf-tkPick{display:flex;align-items:center;gap:14px;flex:none}
.ewf-tkP{font-size:15px;font-weight:700;min-width:42px;text-align:right}
.ewf-tkPm{display:inline-flex;align-items:center;gap:2px;border:1px solid rgba(255,255,255,.3);border-radius:5px}
.ewf-tkPm button{appearance:none;background:none;border:0;color:#fff;font:inherit;font-size:17px;
 line-height:1;width:32px;height:32px;cursor:pointer}
.ewf-tkPm button[disabled]{color:rgba(255,255,255,.28);cursor:default}
.ewf-tkPm output{min-width:24px;text-align:center;font-size:15px;font-weight:700}
.ewf-tkSum{display:flex;align-items:baseline;justify-content:space-between;gap:12px;margin:16px 0 0;
 border-top:1px solid rgba(255,255,255,.18);padding-top:12px;font-size:14px}
.ewf-tkSum span:last-child{font-family:Anton,Impact,sans-serif;font-size:26px}
.ewf-tkGo{display:block;width:100%;margin:14px 0 0;appearance:none;border:0;border-radius:6px;
 background:#CC0000;color:#fff;font:inherit;font-size:15px;font-weight:700;letter-spacing:.05em;
 text-transform:uppercase;padding:15px;cursor:pointer}
.ewf-tkGo[disabled]{background:rgba(255,255,255,.14);color:rgba(255,255,255,.45);cursor:default}
.ewf-tkSwap{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:14px 0 0;
 border-top:1px solid rgba(255,255,255,.18);padding-top:12px;font-size:13.5px;color:rgba(255,255,255,.65)}
.ewf-tkSwap a{color:#fff;font-weight:700}
.ewf-tkState{margin:18px 0 10px;font-family:Anton,Impact,sans-serif;font-weight:400;font-size:34px;
 line-height:1.02;text-transform:uppercase;letter-spacing:.5px}
.ewf-tkSub{margin:0 0 10px;font-size:14.5px;line-height:1.6;color:rgba(255,255,255,.72)}
.ewf-tkGate{color:rgba(255,255,255,.5);font-size:13.5px}
.ewf-tkFill{flex:1 1 auto;min-height:10px}
.ewf-tkStub{display:flex;flex-direction:column}
.ewf-tkBack{margin:12px 0 0;text-align:center}
.ewf-tkBack button{appearance:none;background:none;border:0;padding:0;font:inherit;font-size:13px;
 color:rgba(255,255,255,.6);text-decoration:underline;cursor:pointer}
@media (max-width:860px){
.ewf-tk{grid-template-columns:minmax(0,1fr)}
.ewf-tkStub{border-left:0;border-top:3px dashed rgba(255,255,255,.85)}
.ewf-tkTie{font-size:34px}
}';
}

/**
 * The turn, the counters and the hand-off, in one listener on the document
 * so a row that opens out of the closed half of the season works too.
 */
function ew_sales_js() {
	return <<<'JS'
(function(){
 var MOTION = window.matchMedia && window.matchMedia('(prefers-reduced-motion:reduce)').matches;

 function money(p){
  return '£' + (p % 100 === 0 ? (p/100).toLocaleString() : (p/100).toFixed(2));
 }

 function faces(row){
  return [row.querySelector('[data-ewf-front]'), row.querySelector('[data-ewf-back]')];
 }

 function swap(row, toBack){
  var f = faces(row), front = f[0], back = f[1];
  if(!front || !back){ return; }
  front.classList.toggle('ewf-off', toBack);
  back.classList.toggle('ewf-off', !toBack);
  row.setAttribute('data-ewf-on', toBack ? '1' : '0');
  if(toBack){
   var first = back.querySelector('.ewf-tkPm button[data-d="1"]');
   if(first){ try{ first.focus({preventScroll:true}); }catch(e){ first.focus(); } }
  }
 }

 function turn(row, toBack){
  if(MOTION){ swap(row, toBack); return; }
  var face = row.querySelector('.ewf-face');
  if(!face){ swap(row, toBack); return; }
  var done = false;
  function half(){
   if(done){ return; }
   done = true;
   face.removeEventListener('transitionend', half);
   row.classList.remove('is-edge');
   row.classList.add('is-snap');
   swap(row, toBack);
   // One frame edge-on at the far side, so the height has already changed
   // before anything of the new face can be seen.
   requestAnimationFrame(function(){
    requestAnimationFrame(function(){ row.classList.remove('is-snap'); });
   });
  }
  face.addEventListener('transitionend', half);
  setTimeout(half, 400);
  row.classList.add('is-edge');
 }

 function count(panel){
  var n = 0, pence = 0, bits = [];
  panel.querySelectorAll('.ewf-tkRow').forEach(function(r){
   var q = parseInt(r.querySelector('output').textContent, 10) || 0;
   if(!q){ return; }
   n += q;
   pence += q * (parseInt(r.dataset.p, 10) || 0);
   var name = r.querySelector('.ewf-tkWho b').textContent.toLowerCase();
   bits.push(q + ' ' + name + (q > 1 ? 's' : ''));
  });
  var who = panel.querySelector('[data-ewf-who]');
  var tot = panel.querySelector('[data-ewf-tot]');
  var go  = panel.querySelector('[data-ewf-go]');
  if(who && bits.length){ who.textContent = bits.join(' · '); }
  if(tot){ tot.textContent = money(pence); }
  if(go){ go.disabled = n < 1; }
  return n;
 }

 document.addEventListener('click', function(e){
  var row, panel;

  var open = e.target.closest('[data-ewf-open]');
  if(open){
   row = open.closest('.ewf-card');
   if(row){ e.preventDefault(); turn(row, true); }
   return;
  }

  var back = e.target.closest('[data-ewf-close]');
  if(back){
   row = back.closest('.ewf-card');
   if(row){ turn(row, false); }
   return;
  }

  var pm = e.target.closest('.ewf-tkPm button');
  if(pm){
   var r = pm.closest('.ewf-tkRow'), out = r.querySelector('output');
   var q = Math.max(0, Math.min(20, (parseInt(out.textContent, 10) || 0) + (parseInt(pm.dataset.d, 10) || 0)));
   out.textContent = q;
   r.querySelector('[data-d="-1"]').disabled = q < 1;
   count(r.closest('[data-ewf-panel]'));
   return;
  }

  var go = e.target.closest('[data-ewf-go]');
  if(go && !go.disabled){
   panel = go.closest('[data-ewf-panel]');
   var q = [];
   panel.querySelectorAll('.ewf-tkRow').forEach(function(r){
    var v = parseInt(r.querySelector('output').textContent, 10) || 0;
    if(v){ q.push(r.dataset.t + ':' + v); }
   });
   // The numbers go on the link rather than into a form, so the tickets
   // page has an address of its own and somebody can send it on.
   window.location = panel.dataset.url
    + (panel.dataset.url.indexOf('?') < 0 ? '?' : '&')
    + 'fx=' + encodeURIComponent(panel.dataset.fx)
    + '&q=' + encodeURIComponent(q.join(','));
  }
 });
})();
JS;
}

/* --- The admin screen --------------------------------------------------- */

add_action( 'admin_menu', function () {
	// Under Fixtures, because it is the same list of games seen a second
	// way: Fixtures says what the league has arranged, On sale says what
	// we are willing to sell about it.
	add_submenu_page(
		'ew-fixtures',
		'On sale',
		'On sale',
		'edit_pages',
		'ew-on-sale',
		'ew_sales_admin'
	);
}, 20 );

add_action( 'admin_init', function () {
	register_setting(
		'ew_sales_group',
		'ew_sales',
		array(
			'type'              => 'array',
			'sanitize_callback' => 'ew_sales_sanitise',
			'default'           => array(),
		)
	);
} );

add_filter( 'option_page_capability_ew_sales_group', function () {
	return 'edit_pages';
} );

/* --- Getting the turn onto the page ------------------------------------- */

add_action( 'wp_head', function () {
	if ( function_exists( 'ew_home_blocks_present' ) && ew_home_blocks_present() ) {
		echo '<style id="ew-fixture-sales">' . ew_sales_css() . '</style>'; // phpcs:ignore
	}
}, 21 );

add_action( 'wp_footer', function () {
	if ( function_exists( 'ew_home_blocks_present' ) && ew_home_blocks_present() ) {
		echo '<script id="ew-fixture-sales-js">' . ew_sales_js() . '</script>'; // phpcs:ignore
	}
}, 21 );

/**
 * Keep only what the screen can mean.
 *
 * Prices and quantities arrive as typed pounds and are kept as pence, so
 * nothing downstream ever has to decide what "35.00" is. A blank override
 * stays blank rather than becoming nought, because blank means "whatever
 * the club list says" and nought means "none of these".
 */
function ew_sales_sanitise( $in ) {
	$in  = is_array( $in ) ? $in : array();
	$out = array( 'prices' => array(), 'rooms' => array(), 'fixtures' => array() );

	foreach ( array( 'adult', 'conc', 'student', 'u16' ) as $k ) {
		if ( isset( $in['prices'][ $k ] ) ) {
			$out['prices'][ $k ] = ew_sales_pounds( $in['prices'][ $k ] );
		}
	}

	foreach ( (array) ( $in['rooms'] ?? array() ) as $key => $r ) {
		$key = sanitize_key( (string) $key );
		$nm  = sanitize_text_field( (string) ( $r['name'] ?? '' ) );
		if ( '' === $nm ) {
			continue;
		}
		// A row typed into one of the blank lines comes in under a
		// placeholder key. Name it after itself once, here, so that the
		// games ticking it keep pointing at the same room afterwards.
		if ( '' === $key || 0 === strpos( $key, 'ewnew' ) ) {
			$key = sanitize_key( sanitize_title( $nm ) );
		}
		if ( '' === $key || isset( $out['rooms'][ $key ] ) ) {
			continue;
		}
		$out['rooms'][ $key ] = array(
			'name'  => $nm,
			'note'  => sanitize_text_field( (string) ( $r['note'] ?? '' ) ),
			'pence' => ew_sales_pounds( $r['pence'] ?? '' ),
			'qty'   => max( 0, (int) ( $r['qty'] ?? 0 ) ),
		);
	}

	foreach ( (array) ( $in['fixtures'] ?? array() ) as $id => $f ) {
		$id  = (int) $id;
		if ( ! $id ) {
			continue;
		}
		$row = array();
		foreach ( array( 'tix', 'hosp' ) as $kind ) {
			$st = (string) ( $f[ $kind . '_state' ] ?? 'off' );
			$row[ $kind . '_state' ] = in_array( $st, array( 'off', 'soon', 'on', 'out' ), true ) ? $st : 'off';
			$d = trim( (string) ( $f[ $kind . '_date' ] ?? '' ) );
			$t = trim( (string) ( $f[ $kind . '_time' ] ?? '' ) );
			if ( '' !== $d ) {
				$row[ $kind . '_when' ] = (int) strtotime( $d . ' ' . ( '' !== $t ? $t : '09:00' ) );
			} else {
				$row[ $kind . '_when' ] = (int) ( $f[ $kind . '_when' ] ?? 0 );
			}
			$row[ $kind . '_cap' ] = max( 0, (int) ( $f[ $kind . '_cap' ] ?? 0 ) );
		}
		$rooms = array();
		foreach ( (array) ( $f['rooms'] ?? array() ) as $key => $r ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			$rooms[ $key ] = array(
				'on'    => ! empty( $r['on'] ) ? 1 : 0,
				'pence' => ew_sales_pounds( $r['pence'] ?? '' ),
				'qty'   => '' === trim( (string) ( $r['qty'] ?? '' ) ) ? '' : max( 0, (int) $r['qty'] ),
			);
		}
		$row['rooms'] = $rooms;

		// A fixture with nothing said about it is not stored, so the option
		// does not grow a row for every game the feed has ever carried.
		$bare = 'off' === $row['tix_state'] && 'off' === $row['hosp_state']
			&& ! $row['tix_cap'] && ! $row['hosp_cap'] && ! array_filter( wp_list_pluck( $rooms, 'on' ) );
		if ( ! $bare ) {
			$out['fixtures'][ $id ] = $row;
		}
	}

	return $out;
}

/** Typed pounds to pence, without floating point deciding the pennies. */
function ew_sales_pence( $v ) {
	$v = trim( (string) $v );
	if ( '' === $v ) {
		return 0;
	}
	$v = preg_replace( '/[^0-9.]/', '', $v );
	return (int) round( ( (float) $v ) * 100 );
}

function ew_sales_admin() {
	if ( ! current_user_can( 'edit_pages' ) ) {
		return;
	}
	$types = ew_sales_types();
	$rooms = ew_sales_rooms_std();
	$today = current_time( 'Y-m-d' );
	$next  = array();
	if ( function_exists( 'ew_fixtures_league_list' ) ) {
		foreach ( ew_fixtures_league_list() as $m ) {
			if ( (string) ( $m['date'] ?? '' ) >= $today ) {
				$next[] = function_exists( 'ew_fixture_apply' ) ? ew_fixture_apply( $m ) : $m;
			}
		}
	}
	$next = array_slice( array_filter( $next ), 0, 12 );
	?>
	<div class="wrap">
	<h1>On sale</h1>
	<p style="max-width:52em;font-size:14px">
		What the site is selling, game by game. Nothing is on sale until you say
		so here &mdash; a fixture arriving from the league feed starts at
		<b>Not announced</b>, and the fixture card says so rather than taking a
		booking you are not ready for.
	</p>
	<p style="max-width:52em;font-size:14px">
		<b>Release on</b> a date and time turns the game on by itself when that
		moment passes, and until then the card names the day. Put a number in
		<b>Release</b> and the game closes itself when they have gone; leave it
		at 0 and it sells until you stop it.
	</p>

	<form method="post" action="options.php">
	<?php settings_fields( 'ew_sales_group' ); ?>

	<h2>What a game starts from</h2>
	<table class="widefat striped" style="max-width:900px">
	<tbody>
		<tr><th scope="row" style="width:160px">Ticket prices</th><td>
		<?php foreach ( $types as $k => $t ) : ?>
			<label style="display:inline-block;margin:0 18px 6px 0">
			<?php echo esc_html( $t['label'] ); ?>
			&nbsp;£<input type="text" size="5"
				name="ew_sales[prices][<?php echo esc_attr( $k ); ?>]"
				value="<?php echo esc_attr( ew_sales_pounds( $t['pence'] / 100 ) ); ?>">
			</label>
		<?php endforeach; ?>
			<p class="description">Every game uses these unless it says otherwise.</p>
		</td></tr>

		<tr><th scope="row">Hospitality rooms</th><td>
		<?php
		$blank = $rooms;
		for ( $i = 0; $i < 2; $i++ ) {
			$blank[ 'ewnew' . $i ] = array( 'name' => '', 'note' => '', 'pence' => 0, 'qty' => 0 );
		}
		foreach ( $blank as $key => $r ) :
			?>
			<p style="margin:0 0 7px">
			<input type="text" size="26" placeholder="Room or package"
				name="ew_sales[rooms][<?php echo esc_attr( $key ); ?>][name]"
				value="<?php echo esc_attr( $r['name'] ); ?>">
			<input type="text" size="20" placeholder="per head, includes 2 seats…"
				name="ew_sales[rooms][<?php echo esc_attr( $key ); ?>][note]"
				value="<?php echo esc_attr( $r['note'] ); ?>">
			£<input type="text" size="6"
				name="ew_sales[rooms][<?php echo esc_attr( $key ); ?>][pence]"
				value="<?php echo esc_attr( $r['pence'] ? ew_sales_pounds( $r['pence'] / 100 ) : '' ); ?>">
			<input type="number" min="0" style="width:72px" placeholder="places"
				name="ew_sales[rooms][<?php echo esc_attr( $key ); ?>][qty]"
				value="<?php echo esc_attr( $r['qty'] ? $r['qty'] : '' ); ?>">
			</p>
		<?php endforeach; ?>
			<p class="description">
				The club's list, with the price and number of places each room
				usually has. Add one here and it appears on every game to tick.
				A new row needs a name before it will save.
			</p>
		</td></tr>
	</tbody>
	</table>

	<h2 style="margin-top:28px">The next games</h2>
	<?php if ( ! $next ) : ?>
		<p><em>No fixtures came back from the feed just now. Reload in a minute
			&mdash; it rate-limits.</em></p>
	<?php else : ?>
	<table class="widefat striped" style="margin-top:8px">
	<thead><tr>
		<th style="width:18%">Fixture</th>
		<th style="width:34%">Tickets</th>
		<th>Hospitality</th>
	</tr></thead>
	<tbody>
	<?php
	foreach ( $next as $m ) :
		$id = (int) ( $m['id'] ?? 0 );
		if ( ! $id ) {
			continue;
		}
		$h_team  = (array) ( $m['home-team'] ?? array() );
		$a_team  = (array) ( $m['away-team'] ?? array() );
		$at_home = (int) ( $h_team['id'] ?? 0 ) === (int) EW_TEAM;
		$opp     = $at_home ? (string) ( $a_team['name'] ?? '' ) : (string) ( $h_team['name'] ?? '' );
		$ts      = strtotime( (string) ( $m['date'] ?? '' ) . ' ' . (string) ( $m['time'] ?? '15:00' ) );
		$row     = ew_sales_fixture( $id );
		$tix     = ew_sales_state( $id, 'tix' );
		$hos     = ew_sales_state( $id, 'hosp' );
		?>
	<tr>
		<td>
			<strong><?php echo esc_html( $opp ); ?></strong>
			<span style="font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;
				background:<?php echo $at_home ? '#1d2327' : '#8c8f94'; ?>;color:#fff;padding:2px 6px;
				border-radius:2px;margin-left:5px"><?php echo $at_home ? 'Home' : 'Away'; ?></span>
			<p style="color:#646970;font-size:12px;margin:3px 0 0">
				<?php echo esc_html( $ts ? date_i18n( 'D j M Y, H:i', $ts ) : '' ); ?><br>
				<?php echo esc_html( (string) ( $m['competition']['name'] ?? '' ) ); ?><br>
				id <?php echo (int) $id; ?>
			</p>
		</td>
		<?php if ( ! $at_home ) : ?>
		<td colspan="2"><p class="description" style="margin:4px 0">
			Away &mdash; the gate is theirs. Here so you can see the run of
			games, not so you can sell it.
		</p></td>
		<?php else : ?>
		<td>
			<?php ew_sales_state_fields( $id, 'tix', $row ); ?>
			<p style="margin:7px 0 0">
			Release <input type="number" min="0" style="width:80px"
				name="ew_sales[fixtures][<?php echo (int) $id; ?>][tix_cap]"
				value="<?php echo esc_attr( $tix['cap'] ? $tix['cap'] : '' ); ?>" placeholder="0">
			<span class="description">
				<?php
				echo $tix['cap']
					? esc_html( $tix['sold'] . ' sold, ' . $tix['left'] . ' left' )
					: 'no limit';
				?>
			</span>
			</p>
		</td>
		<td>
			<?php ew_sales_state_fields( $id, 'hosp', $row ); ?>
			<?php if ( ! $rooms ) : ?>
			<p class="description">Add a room to the club's list above first.</p>
			<?php else : ?>
			<table style="margin-top:6px;border-collapse:collapse">
			<?php foreach ( $rooms as $key => $r ) :
				$mine = (array) ( $row['rooms'][ $key ] ?? array() );
				?>
				<tr>
				<td style="padding:2px 10px 2px 0">
					<label><input type="checkbox" value="1"
						name="ew_sales[fixtures][<?php echo (int) $id; ?>][rooms][<?php echo esc_attr( $key ); ?>][on]"
						<?php checked( ! empty( $mine['on'] ) ); ?>>
						<?php echo esc_html( $r['name'] ); ?></label>
				</td>
				<td style="padding:2px 6px 2px 0">£<input type="text" size="6"
					name="ew_sales[fixtures][<?php echo (int) $id; ?>][rooms][<?php echo esc_attr( $key ); ?>][pence]"
					value="<?php echo esc_attr( (string) ( $mine['pence'] ?? '' ) ); ?>"
					placeholder="<?php echo esc_attr( ew_sales_pounds( $r['pence'] / 100 ) ); ?>"></td>
				<td style="padding:2px 0"><input type="number" min="0" style="width:76px"
					name="ew_sales[fixtures][<?php echo (int) $id; ?>][rooms][<?php echo esc_attr( $key ); ?>][qty]"
					value="<?php echo esc_attr( '' === ( $mine['qty'] ?? '' ) ? '' : (int) $mine['qty'] ); ?>"
					placeholder="<?php echo esc_attr( (string) $r['qty'] ); ?>"></td>
				</tr>
			<?php endforeach; ?>
			</table>
			<p class="description">
				Leave a price or a number empty to use the club's.
				<?php echo 'on' === $hos['state'] ? '' : 'Hospitality is not selling on this game yet.'; ?>
			</p>
			<?php endif; ?>
		</td>
		<?php endif; ?>
	</tr>
	<?php endforeach; ?>
	</tbody>
	</table>
	<?php endif; ?>

	<?php submit_button( 'Save' ); ?>
	</form>
	</div>
	<?php
}

/** The four states, as one set of radios. Drawn twice per game. */
function ew_sales_state_fields( $id, $kind, $row ) {
	$cur  = (string) ( $row[ $kind . '_state' ] ?? 'off' );
	$when = (int) ( $row[ $kind . '_when' ] ?? 0 );
	$name = 'ew_sales[fixtures][' . (int) $id . '][' . $kind . '_state]';
	$opts = array(
		'off'  => 'Not announced',
		'soon' => 'Release on',
		'on'   => 'On sale',
		'out'  => 'hosp' === $kind ? 'Full' : 'Sold out',
	);
	foreach ( $opts as $k => $label ) {
		printf(
			'<label style="display:inline-block;margin:0 16px 4px 0;white-space:nowrap">'
			. '<input type="radio" name="%s" value="%s"%s> %s</label>',
			esc_attr( $name ),
			esc_attr( $k ),
			checked( $cur, $k, false ),
			esc_html( $label )
		);
		if ( 'soon' === $k ) {
			printf(
				'<span style="white-space:nowrap">'
				. '<input type="text" size="12" name="ew_sales[fixtures][%1$d][%2$s_date]" value="%3$s" placeholder="31 Oct 2026"> '
				. '<input type="text" size="5" name="ew_sales[fixtures][%1$d][%2$s_time]" value="%4$s" placeholder="09:00">'
				. '</span> ',
				(int) $id,
				esc_attr( $kind ),
				esc_attr( $when ? date_i18n( 'j M Y', $when ) : '' ),
				esc_attr( $when ? date_i18n( 'H:i', $when ) : '' )
			);
		}
	}
}
