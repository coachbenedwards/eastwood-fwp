<?php
/**
 * Community tickets: eastwoodfc.com/community and /communitytickets.
 *
 * Free tickets for anyone who asks, for any home game. /community lists the
 * home games (the next one featured, the rest under it) straight from the
 * fixtures, so nobody types them in. /communitytickets is where someone asks:
 * which game, how many, a name and an email, and an optional note. A school,
 * food bank or family worker can have their own link (?partner=key) that puts
 * their name at the top and asks for the family instead.
 *
 * A request is a Booking, status "community". Someone at the club approves or
 * declines it at Bookings -> Community tickets. Approving emails the pass: the
 * same ticket and QR as everyone else, so nobody can tell it was free.
 *
 * [eastwood_community_games mode="next"]   the featured next home game
 * [eastwood_community_games mode="list"]   the home games after it
 * [eastwood_community_request]             the request form and the pass
 * key | Partner name                        (one partner per line, in the body)
 * [/eastwood_community_request]
 *
 * Every word the blocks show is a field on the block.
 *
 * @package eastwood-fwp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* --- The home games, from the fixtures --------------------------------- */

/** Home games still to play, soonest first. */
function ew_cm_games() {
	static $out = null;
	if ( null !== $out ) {
		return $out;
	}
	$out = array();
	if ( ! function_exists( 'ew_fwp_fetch' ) ) {
		return $out;
	}
	$fx    = ew_fwp_fetch( 'fixtures-results' );
	$today = wp_date( 'Y-m-d' );
	foreach ( (array) ( $fx['fixtures-results']['matches'] ?? array() ) as $m ) {
		$home = (array) ( $m['home-team'] ?? array() );
		if ( (int) ( $home['id'] ?? 0 ) !== (int) EW_TEAM ) {
			continue;
		}
		if ( ( function_exists( 'ew_home_played' ) && ew_home_played( $m ) ) || (string) ( $m['date'] ?? '' ) < $today ) {
			continue;
		}
		$out[] = $m;
	}
	usort( $out, function ( $a, $b ) {
		return strcmp( (string) $a['date'] . (string) ( $a['time'] ?? '' ), (string) $b['date'] . (string) ( $b['time'] ?? '' ) );
	} );
	return $out;
}

/** One home game by its fixture id. */
function ew_cm_game( $id ) {
	foreach ( ew_cm_games() as $m ) {
		if ( (int) ( $m['id'] ?? 0 ) === (int) $id ) {
			return $m;
		}
	}
	return null;
}

/** The words for one game: {day} {date} {time} {home} {away} {venue}. */
function ew_cm_vars( $m, $venue ) {
	$dt   = date_create( (string) $m['date'] . ' ' . ( (string) ( $m['time'] ?? '' ) ?: '15:00' ), wp_timezone() );
	$ts   = $dt ? $dt->getTimestamp() : time();
	$time = (string) ( $m['time'] ?? '' );
	$tlab = '' === $time ? '' : strtolower( wp_date( '00' === wp_date( 'i', $ts ) ? 'ga' : 'g:ia', $ts ) );
	return array(
		'{day}'   => wp_date( 'l', $ts ),
		'{dow}'   => wp_date( 'D', $ts ),
		'{date}'  => wp_date( 'j F', $ts ),
		'{d}'     => wp_date( 'j', $ts ),
		'{mon}'   => wp_date( 'M', $ts ),
		'{year}'  => wp_date( 'Y', $ts ),
		'{time}'  => $tlab,
		'{home}'  => (string) ( $m['home-team']['name'] ?? '' ),
		'{away}'  => (string) ( $m['away-team']['name'] ?? '' ),
		'{venue}' => $venue,
	);
}

function ew_cm_fill( $text, $vars ) {
	return strtr( (string) $text, $vars );
}

/** Places asked for or given for a game (declined ones do not count). */
function ew_cm_taken( $game_id ) {
	$ids = get_posts( array(
		'post_type'   => 'ewt_booking',
		'post_status' => 'any',
		'numberposts' => -1,
		'fields'      => 'ids',
		'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery
			array( 'key' => '_ewt_game', 'value' => (string) (int) $game_id ),
			array( 'key' => '_ewt_status', 'value' => array( 'community', 'community_ok' ), 'compare' => 'IN' ),
		),
	) );
	$n = 0;
	foreach ( $ids as $id ) {
		$n += (int) get_post_meta( $id, '_ewt_adults', true ) + (int) get_post_meta( $id, '_ewt_children', true );
	}
	return $n;
}

/* --- /community: the games ---------------------------------------------- */

function ew_cm_games_defaults() {
	return array(
		'mode'     => 'next',
		'venue'    => 'Coronation Park',
		'kicker'   => '{day} {date} · {time} · {venue}',
		'title'    => '{home} v {away}',
		'text'     => 'Free tickets for you and your family. Ask by Friday and we\'ll email your pass. You come in through the "Tickets" queue like everyone else.',
		'button'   => 'Request tickets',
		'price'    => 'Free',
		'row_title'=> '{home} v {away}',
		'row_sub'  => '{venue} · {time} · {price}',
		'full'     => 'Full',
		'limit'    => '4',
		'cap'      => '40',
		'url'      => '/communitytickets/',
		'image'    => 'claim/ticket-bg.jpg',
		'none'     => 'No home games are listed yet. Check back soon.',
	);
}

function ew_cm_games_sc( $atts ) {
	$a     = shortcode_atts( ew_cm_games_defaults(), (array) $atts, 'eastwood_community_games' );
	$games = ew_cm_games();
	$cap   = max( 0, (int) $a['cap'] );
	if ( 'list' === $a['mode'] ) {
		$games = array_slice( $games, 1, max( 1, (int) $a['limit'] ) );
	} else {
		$games = array_slice( $games, 0, 1 );
	}
	if ( ! $games ) {
		return '<p class="ewcm-none">' . esc_html( $a['none'] ) . '</p>';
	}
	$url = function ( $m ) use ( $a ) {
		return add_query_arg( 'game', (int) $m['id'], home_url( $a['url'] ) );
	};

	if ( 'list' !== $a['mode'] ) {
		$m   = $games[0];
		$v   = ew_cm_vars( $m, $a['venue'] ) + array( '{price}' => $a['price'] );
		$img = function_exists( 'ew_claim_img' ) ? ew_claim_img( $a['image'] ) : '';
		$full = $cap && ew_cm_taken( $m['id'] ) >= $cap;
		$h  = '<div class="ewcm-feat">';
		$h .= '<div class="ewcm-ph"' . ( '' !== $img ? ' style="background-image:url(' . esc_url( $img ) . ')"' : '' ) . '></div>';
		$h .= '<div class="ewcm-body"><p class="ewcm-kick">' . esc_html( ew_cm_fill( $a['kicker'], $v ) ) . '</p>';
		$h .= '<h2 class="ewcm-title">' . esc_html( ew_cm_fill( $a['title'], $v ) ) . '</h2>';
		$h .= '<p class="ewcm-text">' . esc_html( ew_cm_fill( $a['text'], $v ) ) . '</p>';
		$h .= '<p class="ewcm-acts">' . ( $full ? '<span class="ewcm-full">' . esc_html( $a['full'] ) . '</span>' : '<a class="ewcm-btn" href="' . esc_url( $url( $m ) ) . '">' . esc_html( $a['button'] ) . '</a>' ) . '<span class="ewcm-free">' . esc_html( $a['price'] ) . '</span></p>';
		return $h . '</div></div>';
	}

	$h = '<div class="ewcm-list">';
	foreach ( $games as $m ) {
		$v    = ew_cm_vars( $m, $a['venue'] ) + array( '{price}' => $a['price'] );
		$full = $cap && ew_cm_taken( $m['id'] ) >= $cap;
		$h   .= '<div class="ewcm-row"><div class="ewcm-date"><b>' . esc_html( $v['{d}'] ) . '</b><span>' . esc_html( $v['{mon}'] ) . '</span><small>' . esc_html( $v['{dow}'] ) . '</small></div>';
		$h   .= '<div class="ewcm-rt"><p class="ewcm-rtitle">' . esc_html( ew_cm_fill( $a['row_title'], $v ) ) . '</p><p class="ewcm-rsub">' . esc_html( ew_cm_fill( $a['row_sub'], $v ) ) . '</p></div>';
		$h   .= $full ? '<span class="ewcm-full">' . esc_html( $a['full'] ) . '</span>' : '<a class="ewcm-btn" href="' . esc_url( $url( $m ) ) . '">' . esc_html( $a['button'] ) . '</a>';
		$h   .= '</div>';
	}
	return $h . '</div>';
}
add_shortcode( 'eastwood_community_games', 'ew_cm_games_sc' );

/* --- /communitytickets: the request, and the pass -------------------------- */

function ew_cm_req_defaults() {
	return array(
		'venue'          => 'Coronation Park',
		'cap'            => '40',
		'title'          => "Everyone's welcome",
		'lede'           => "If money's tight, ask us for free tickets. No forms, no proof, no questions. You'll get a pass like everyone else.",
		'head'           => 'Request tickets',
		'game_label'     => 'Which game?',
		'game_option'    => '{dow} {d} {mon} v {away}',
		'game_full'      => '(full)',
		'count_label'    => 'How many are coming?',
		'adults'         => 'Adults',
		'children'       => 'Children',
		'children_note'  => 'Under 16',
		'name_label'     => 'Your name',
		'email_label'    => 'Your email',
		'note_label'     => "Anything you'd like us to know?",
		'optional'       => 'Optional',
		'consent'        => 'Tell me about community tickets for other games',
		'send'           => 'Request tickets',
		'need_people'    => 'Add at least one person.',
		'need_name'      => 'We need a name for the list.',
		'need_email'     => 'We need an email to send the pass to.',
		'failed'         => 'Something went wrong. Please try again.',
		'full'           => 'Sorry, that game is full. Try another one.',
		'none'           => 'No home games are listed yet. Check back soon.',
		'partner_head'   => 'Requesting through',
		'partner_family' => "Family's first name",
		'partner_email'  => 'Where should we send the pass?',
		'partner_you'    => 'Your name',
		'sent_title'     => 'Request received',
		'sent_line'      => '{party} for {game}.',
		'sent_note'      => "We'll email the pass to {email} by Friday.",
		'adult_one'      => 'adult',
		'adult_many'     => 'adults',
		'child_one'      => 'child',
		'child_many'     => 'children',
		'wait_title'     => 'Waiting for approval',
		'wait_note'      => "We'll email your pass as soon as it's approved.",
		'no_title'       => 'Sorry',
		'no_note'        => "We couldn't fit you in for this one. Please do ask for another game.",
		'pass_title'     => "You're in",
		'pass_note'      => 'Show this at the "Tickets" queue.',
		'pass_note2'     => 'Come and say hello at half-time.',
		'pass_none'      => "We can't find that pass. Check the link, or ask at the Tickets queue.",
		'let_in'         => 'Let them in',
		'is_in'          => 'In at {time}',
		'mail_subject'   => 'Your tickets for {game}',
		'mail_body'      => "Hi {name},\n\nYou're in. Here's your pass for {game}:\n{pass}\n\nShow it at the \"Tickets\" queue on the day.\n\nSee you there,\nTeam EastwoodFC",
		'no_subject'     => 'Community tickets for {game}',
		'no_body'        => "Hi {name},\n\nThanks for asking. Sorry, we couldn't fit you in for {game}. Please do ask for another game.\n\nTeam EastwoodFC",
	);
}

/** Partners written in the block body: "key | Name". */
function ew_cm_partners( $content ) {
	$out = array();
	foreach ( preg_split( '/\r\n|\r|\n|;/', wp_strip_all_tags( (string) $content ) ) as $line ) {
		$b   = array_map( 'trim', explode( '|', $line ) );
		$key = strtolower( preg_replace( '/[^A-Za-z0-9-]/', '', (string) ( $b[0] ?? '' ) ) );
		if ( '' !== $key && '' !== (string) ( $b[1] ?? '' ) ) {
			$out[ $key ] = $b[1];
		}
	}
	return $out;
}

/** The request block on a page, read from the page itself. */
function ew_cm_block_on( $post ) {
	$found = null;
	$data  = json_decode( (string) get_post_meta( $post->ID, '_elementor_data', true ), true );
	if ( is_array( $data ) ) {
		$walk = function ( $nodes ) use ( &$walk, &$found ) {
			foreach ( (array) $nodes as $n ) {
				if ( $found ) {
					return;
				}
				$sc = (string) ( $n['settings']['shortcode'] ?? '' );
				if ( 0 === strpos( ltrim( $sc ), '[eastwood_community_request' ) ) {
					$found = $sc;
					return;
				}
				if ( ! empty( $n['elements'] ) ) {
					$walk( $n['elements'] );
				}
			}
		};
		$walk( $data );
	}
	if ( ! $found && preg_match( '/\[eastwood_community_request[^\]]*\](.*?\[\/eastwood_community_request\])?/s', (string) $post->post_content, $mm ) ) {
		$found = $mm[0];
	}
	if ( ! $found ) {
		return null;
	}
	$atts = array();
	$body = '';
	if ( preg_match( '/\[eastwood_community_request([^\]]*)\]/', $found, $o ) ) {
		$atts = (array) shortcode_parse_atts( $o[1] );
	}
	if ( preg_match( '/\](.*)\[\/eastwood_community_request\]/s', $found, $c ) ) {
		$body = $c[1];
	}
	return array( 'atts' => shortcode_atts( ew_cm_req_defaults(), $atts, 'eastwood_community_request' ), 'body' => $body );
}

function ew_cm_party( $a, $adults, $children ) {
	$bits = array();
	if ( $adults ) {
		$bits[] = $adults . ' ' . ( 1 === $adults ? $a['adult_one'] : $a['adult_many'] );
	}
	if ( $children ) {
		$bits[] = $children . ' ' . ( 1 === $children ? $a['child_one'] : $a['child_many'] );
	}
	return implode( ', ', $bits );
}

/** The left of the ticket for one game. */
function ew_cm_match_html( $m, $a ) {
	$v    = ew_cm_vars( $m, $a['venue'] );
	$home = function_exists( 'ew_claim_img' ) ? ew_claim_img( 'claim/eastwood-white.png' ) : '';
	$away = function_exists( 'ew_home_badge' ) ? ew_home_badge( (int) ( $m['away-team']['id'] ?? 0 ) ) : '';
	$h  = '<div class="ewc-match"><div class="ewc-teams"><div class="ewc-names">';
	$h .= '<p class="ewc-team">' . esc_html( $v['{home}'] ) . '</p><p class="ewc-v">v</p><p class="ewc-team">' . esc_html( $v['{away}'] ) . '</p></div>';
	$h .= '<div class="ewc-crests">';
	if ( '' !== $home ) {
		$h .= '<img src="' . esc_url( $home ) . '" alt="" width="128" height="128">';
	}
	if ( '' !== $away ) {
		$h .= '<img src="' . esc_url( $away ) . '" alt="" width="128" height="128" onerror="this.style.visibility=\'hidden\'">';
	}
	$h .= '</div></div><div class="ewc-when"><div><p class="ewc-day">' . esc_html( $v['{day}'] ) . '</p><p class="ewc-date">' . esc_html( $v['{date}'] . ' ' . $v['{year}'] ) . '</p></div>';
	$h .= '<p class="ewc-time">' . esc_html( strtoupper( $v['{time}'] ) ) . '</p></div></div>';
	return $h;
}

/** A pass link for one request. */
function ew_cm_pass_url( $id ) {
	return add_query_arg(
		array(
			'pass' => (string) get_post_meta( $id, '_ewt_code', true ),
			'k'    => (string) get_post_meta( $id, '_ewt_key', true ),
		),
		get_permalink( (int) get_post_meta( $id, '_ewt_page', true ) )
	);
}

/** The stub when someone opens their pass link. */
function ew_cm_pass_html( $a, $id ) {
	$m      = function ( $k ) use ( $id ) {
		return (string) get_post_meta( $id, '_ewt_' . $k, true );
	};
	$status = $m( 'status' );
	$party  = ew_cm_party( $a, (int) $m( 'adults' ), (int) $m( 'children' ) );
	if ( 'community_no' === $status ) {
		return '<div class="ewcm-msg"><p class="ewc-done">' . esc_html( $a['no_title'] ) . '</p><p class="ewc-small">' . esc_html( $a['no_note'] ) . '</p></div>';
	}
	if ( 'community_ok' !== $status ) {
		return '<div class="ewcm-msg"><p class="ewc-done">' . esc_html( $a['wait_title'] ) . '</p><p class="ewc-who">' . esc_html( $m( 'name' ) ) . '<br>' . esc_html( $party ) . '</p><p class="ewc-small">' . esc_html( $a['wait_note'] ) . '</p></div>';
	}
	$code = $m( 'code' );
	$h    = '<p class="ewc-done">' . esc_html( $a['pass_title'] ) . '</p>';
	$h   .= '<p class="ewc-who">' . esc_html( $m( 'name' ) ) . '<br>' . esc_html( $party ) . '</p>';
	$h   .= '<div class="ewc-qr" data-qr="' . esc_attr( ew_cm_pass_url( $id ) ) . '" role="img" aria-label="' . esc_attr( $code ) . '"></div>';
	$h   .= '<p class="ewc-code">' . esc_html( $code ) . '</p>';
	$in   = (int) $m( 'in' );
	if ( $in ) {
		$h .= '<p class="ewc-arrived">' . esc_html( str_replace( '{time}', wp_date( 'g:ia', $in ), $a['is_in'] ) ) . '</p>';
	} elseif ( current_user_can( 'edit_posts' ) ) {
		$h .= '<button type="button" class="ewc-btn ewc-letin" data-done="' . esc_attr( $a['is_in'] ) . '" data-code="' . esc_attr( $code ) . '" data-k="' . esc_attr( $m( 'key' ) ) . '">' . esc_html( $a['let_in'] ) . '</button>';
	}
	$h .= '<div class="ewc-grow"></div><p class="ewc-small">' . esc_html( $a['pass_note'] ) . '</p>';
	if ( '' !== $a['pass_note2'] ) {
		$h .= '<p class="ewc-small">' . esc_html( $a['pass_note2'] ) . '</p>';
	}
	return $h;
}

function ew_cm_find( $code, $key ) {
	$code = strtoupper( preg_replace( '/[^A-Za-z0-9-]/', '', (string) $code ) );
	$key  = preg_replace( '/[^A-Za-z0-9]/', '', (string) $key );
	if ( '' === $code || '' === $key ) {
		return 0;
	}
	$ids = get_posts( array(
		'post_type'   => 'ewt_booking',
		'post_status' => 'any',
		'numberposts' => 1,
		'fields'      => 'ids',
		'meta_key'    => '_ewt_code', // phpcs:ignore WordPress.DB.SlowDBQuery
		'meta_value'  => $code, // phpcs:ignore WordPress.DB.SlowDBQuery
	) );
	return ( $ids && hash_equals( (string) get_post_meta( $ids[0], '_ewt_key', true ), $key ) ) ? (int) $ids[0] : 0;
}

function ew_cm_req_sc( $atts, $content = null ) {
	$a     = shortcode_atts( ew_cm_req_defaults(), (array) $atts, 'eastwood_community_request' );
	$post  = get_post();
	$bg    = function_exists( 'ew_claim_img' ) ? ew_claim_img( 'claim/ticket-bg.jpg' ) : '';
	$games = ew_cm_games();
	$open  = '<section class="ewc ewcm" data-page="' . (int) ( $post ? $post->ID : 0 ) . '"><div class="ewc-tk"' . ( '' !== $bg ? ' style="background-image:url(' . esc_url( $bg ) . ')"' : '' ) . '>';

	// phpcs:disable WordPress.Security.NonceVerification
	if ( isset( $_GET['pass'] ) ) {
		$id = ew_cm_find( wp_unslash( $_GET['pass'] ), wp_unslash( $_GET['k'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$m  = $id ? ew_cm_game( (int) get_post_meta( $id, '_ewt_game', true ) ) : null;
		$h  = $open . ( $m ? ew_cm_match_html( $m, $a ) : '<div class="ewc-match"></div>' ) . '<div class="ewc-stub">';
		$h .= $id ? ew_cm_pass_html( $a, $id ) : '<p class="ewc-small">' . esc_html( $a['pass_none'] ) . '</p>';
		return $h . '</div></div></section>';
	}
	if ( ! $games ) {
		return '<p class="ewcm-none">' . esc_html( $a['none'] ) . '</p>';
	}
	$want = (int) ( $_GET['game'] ?? 0 );
	$pkey = strtolower( preg_replace( '/[^A-Za-z0-9-]/', '', (string) wp_unslash( $_GET['partner'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	// phpcs:enable
	$game     = ew_cm_game( $want ) ?: $games[0];
	$partners = ew_cm_partners( $content );
	$partner  = $partners[ $pkey ] ?? '';
	$cap      = max( 0, (int) $a['cap'] );

	$words = array(
		'need_people' => $a['need_people'],
		'need_name'   => $a['need_name'],
		'need_email'  => $a['need_email'],
		'failed'      => $a['failed'],
		'one'         => array( $a['adult_one'], $a['child_one'] ),
		'many'        => array( $a['adult_many'], $a['child_many'] ),
		'sent_line'   => $a['sent_line'],
		'sent_note'   => $a['sent_note'],
		'rest'        => esc_url_raw( rest_url( 'eastwood/v1/community' ) ),
		'partner'     => '' !== $partner ? $pkey : '',
	);

	$h  = $open . ew_cm_match_html( $game, $a ) . '<div class="ewc-stub">';
	$h .= '<form class="ewc-step ewcm-form" novalidate>';
	if ( '' !== $partner ) {
		$h .= '<div class="ewcm-partner"><small>' . esc_html( $a['partner_head'] ) . '</small><b>' . esc_html( $partner ) . '</b></div>';
	} else {
		$h .= '<p class="ewcm-t">' . esc_html( $a['title'] ) . '</p><p class="ewcm-lede">' . esc_html( $a['lede'] ) . '</p>';
	}
	$h .= '<div class="ewc-head"><span>' . esc_html( $a['head'] ) . '</span></div>';
	$h .= '<label class="ewc-lab" for="ewcmGame">' . esc_html( $a['game_label'] ) . '</label><select id="ewcmGame" class="ewc-in">';
	foreach ( array_slice( $games, 0, 8 ) as $g ) {
		$v    = ew_cm_vars( $g, $a['venue'] );
		$full = $cap && ew_cm_taken( $g['id'] ) >= $cap;
		$h   .= '<option value="' . (int) $g['id'] . '"' . selected( (int) $g['id'], (int) $game['id'], false ) . ( $full ? ' disabled' : '' ) . '>' . esc_html( ew_cm_fill( $a['game_option'], $v ) . ( $full ? ' ' . $a['game_full'] : '' ) ) . '</option>';
	}
	$h .= '</select>';
	$h .= '<p class="ewc-lab">' . esc_html( $a['count_label'] ) . '</p><div class="ewc-counts">';
	foreach ( array( 'adults' => '', 'children' => $a['children_note'] ) as $k => $note ) {
		$h .= '<div class="ewc-count" data-k="' . esc_attr( $k ) . '"><span><b>' . esc_html( $a[ $k ] ) . '</b>' . ( '' !== $note ? '<small>' . esc_html( $note ) . '</small>' : '' ) . '</span>';
		$h .= '<span class="ewc-pm"><button type="button" data-d="-1" aria-label="&minus; ' . esc_attr( $a[ $k ] ) . '">&minus;</button><output>' . ( 'adults' === $k ? 1 : 0 ) . '</output><button type="button" data-d="1" aria-label="+ ' . esc_attr( $a[ $k ] ) . '">+</button></span></div>';
	}
	$h .= '</div>';
	if ( '' !== $partner ) {
		$h .= '<label class="ewc-lab" for="ewcmName">' . esc_html( $a['partner_family'] ) . '</label><input class="ewc-in" id="ewcmName" type="text" autocomplete="off">';
		$h .= '<label class="ewc-lab" for="ewcmEmail">' . esc_html( $a['partner_email'] ) . '</label><input class="ewc-in" id="ewcmEmail" type="email">';
		$h .= '<label class="ewc-lab" for="ewcmYou">' . esc_html( $a['partner_you'] ) . '</label><input class="ewc-in" id="ewcmYou" type="text" autocomplete="name">';
	} else {
		$h .= '<label class="ewc-lab" for="ewcmName">' . esc_html( $a['name_label'] ) . '</label><input class="ewc-in" id="ewcmName" type="text" autocomplete="name">';
		$h .= '<label class="ewc-lab" for="ewcmEmail">' . esc_html( $a['email_label'] ) . '</label><input class="ewc-in" id="ewcmEmail" type="email" autocomplete="email">';
	}
	$h .= '<label class="ewc-lab" for="ewcmNote">' . esc_html( $a['note_label'] ) . ' <span class="ewcm-opt">' . esc_html( $a['optional'] ) . '</span></label><textarea class="ewc-in ewcm-note" id="ewcmNote" rows="2"></textarea>';
	$h .= '<label class="ewc-tick"><input type="checkbox" id="ewcmConsent"><span>' . esc_html( $a['consent'] ) . '</span></label>';
	$h .= '<p class="ewc-err" role="alert"></p><button type="submit" class="ewc-btn">' . esc_html( $a['send'] ) . '</button></form>';
	$h .= '<div class="ewcm-sent" hidden><svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.6" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M7 12.5l3.2 3.2L17 9"/></svg><p class="ewc-done">' . esc_html( $a['sent_title'] ) . '</p><p class="ewc-small ewcm-line"></p><p class="ewc-small ewcm-note2"></p></div>';
	$h .= '</div></div><script type="application/json" class="ewcm-words">' . wp_json_encode( $words ) . '</script></section>';
	return $h;
}
add_shortcode( 'eastwood_community_request', 'ew_cm_req_sc' );

/* --- Asking ---------------------------------------------------------------- */

add_action( 'rest_api_init', function () {
	register_rest_route( 'eastwood/v1', '/community', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) {
			$post  = get_post( (int) $req->get_param( 'page' ) );
			$block = $post ? ew_cm_block_on( $post ) : null;
			if ( ! $block ) {
				return new WP_REST_Response( array( 'message' => 'no block' ), 400 );
			}
			$a        = $block['atts'];
			$game     = ew_cm_game( (int) $req->get_param( 'game' ) );
			$name     = ew_person_name( sanitize_text_field( (string) $req->get_param( 'name' ) ) );
			$email    = sanitize_email( (string) $req->get_param( 'email' ) );
			$note     = trim( sanitize_textarea_field( (string) $req->get_param( 'note' ) ) );
			$adults   = max( 0, min( 10, (int) $req->get_param( 'adults' ) ) );
			$children = max( 0, min( 10, (int) $req->get_param( 'children' ) ) );
			$pkey     = strtolower( preg_replace( '/[^A-Za-z0-9-]/', '', (string) $req->get_param( 'partner' ) ) );
			$partners = ew_cm_partners( $block['body'] );
			$partner  = $partners[ $pkey ] ?? '';
			if ( ! $game ) {
				return new WP_REST_Response( array( 'message' => $a['failed'] ), 400 );
			}
			if ( $adults + $children < 1 ) {
				return new WP_REST_Response( array( 'message' => $a['need_people'] ), 400 );
			}
			if ( '' === $name ) {
				return new WP_REST_Response( array( 'message' => $a['need_name'] ), 400 );
			}
			if ( ! is_email( $email ) ) {
				return new WP_REST_Response( array( 'message' => $a['need_email'] ), 400 );
			}
			$cap = max( 0, (int) $a['cap'] );
			if ( $cap && ew_cm_taken( $game['id'] ) + $adults + $children > $cap ) {
				return new WP_REST_Response( array( 'message' => $a['full'] ), 409 );
			}
			do {
				$code = 'EFC-' . wp_rand( 1000, 9999 );
			} while ( get_posts( array( 'post_type' => 'ewt_booking', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_ewt_code', 'meta_value' => $code ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
			$id = wp_insert_post( array( 'post_type' => 'ewt_booking', 'post_status' => 'publish', 'post_title' => $name ) );
			if ( ! $id || is_wp_error( $id ) ) {
				return new WP_REST_Response( array( 'message' => $a['failed'] ), 500 );
			}
			$v     = ew_cm_vars( $game, $a['venue'] );
			$label = $v['{home}'] . ' v ' . $v['{away}'] . ', ' . $v['{day}'] . ' ' . $v['{date}'] . ' ' . $v['{year}'];
			$party = ew_cm_party( $a, $adults, $children );
			foreach ( array(
				'kind'     => 'community',
				'name'     => $name,
				'email'    => $email,
				'event'    => 'Community tickets: ' . $label,
				'game'     => (int) $game['id'],
				'gamedate' => (string) $game['date'],
				'page'     => $post->ID,
				'url'      => get_permalink( $post ),
				'lines'    => $party,
				'adults'   => $adults,
				'children' => $children,
				'extra'    => $note,
				'note'     => $note,
				'partner'  => $partner,
				'partner_contact' => '' !== $partner ? trim( sanitize_text_field( (string) $req->get_param( 'you' ) ) ) : '',
				'total'    => 0,
				'consent'  => $req->get_param( 'consent' ) ? 'yes' : 'no',
				'code'     => $code,
				'key'      => wp_generate_password( 12, false ),
				'status'   => 'community',
			) as $k => $val ) {
				update_post_meta( $id, '_ewt_' . $k, $val );
			}
			return array(
				'ok'    => true,
				'line'  => strtr( $a['sent_line'], array( '{party}' => $party, '{game}' => $label ) ),
				'note'  => strtr( $a['sent_note'], array( '{email}' => $email, '{game}' => $label ) ),
			);
		},
	) );
} );

/* --- Approving: Bookings -> Community tickets ------------------------------ */

/** The page that holds the request block, for its words and its pass links. */
function ew_cm_request_page() {
	$q = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 's' => '' ) );
	foreach ( $q as $pid ) {
		$p = get_post( $pid );
		if ( $p && function_exists( 'ew_post_contains' ) && ew_post_contains( $p, 'eastwood_community_request' ) ) {
			return $p;
		}
	}
	return null;
}

function ew_cm_mail( $id, $ok ) {
	$page  = get_post( (int) get_post_meta( $id, '_ewt_page', true ) );
	$block = $page ? ew_cm_block_on( $page ) : null;
	$a     = $block ? $block['atts'] : ew_cm_req_defaults();
	$game  = (string) get_post_meta( $id, '_ewt_event', true );
	$game  = preg_replace( '/^Community tickets:\s*/', '', $game );
	$vars  = array(
		'{name}' => (string) get_post_meta( $id, '_ewt_name', true ),
		'{game}' => $game,
		'{pass}' => ew_cm_pass_url( $id ),
	);
	$to = (string) get_post_meta( $id, '_ewt_email', true );
	if ( ! is_email( $to ) ) {
		return false;
	}
	return wp_mail(
		$to,
		strtr( $ok ? $a['mail_subject'] : $a['no_subject'], $vars ),
		strtr( $ok ? $a['mail_body'] : $a['no_body'], $vars ),
		array( 'From: Eastwood FC <info@eastwoodfc.com>', 'Reply-To: info@eastwoodfc.com' )
	);
}

add_action( 'admin_menu', function () {
	add_submenu_page( 'edit.php?post_type=ewt_booking', 'Community tickets', 'Community tickets', 'edit_posts', 'ew-community', 'ew_cm_admin_page' );
} );

add_action( 'admin_post_ew_cm_decide', function () {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( 'Not allowed.' );
	}
	$id = (int) ( $_POST['id'] ?? 0 );
	check_admin_referer( 'ew_cm_decide_' . $id );
	$ok = 'approve' === sanitize_key( wp_unslash( $_POST['do'] ?? '' ) );
	if ( 'ewt_booking' === get_post_type( $id ) && 'community' === get_post_meta( $id, '_ewt_status', true ) ) {
		update_post_meta( $id, '_ewt_status', $ok ? 'community_ok' : 'community_no' );
		update_post_meta( $id, '_ewt_decided', time() );
		update_post_meta( $id, '_ewt_decided_by', get_current_user_id() );
		$sent = ew_cm_mail( $id, $ok );
		update_post_meta( $id, '_ewt_mailed', $sent ? 'yes' : 'failed' );
	}
	wp_safe_redirect( admin_url( 'edit.php?post_type=ewt_booking&page=ew-community&done=' . ( $ok ? 'ok' : 'no' ) ) );
	exit;
} );

function ew_cm_admin_page() {
	$games = ew_cm_games();
	$page  = ew_cm_request_page();
	$cap   = 40;
	if ( $page ) {
		$b   = ew_cm_block_on( $page );
		$cap = max( 0, (int) ( $b['atts']['cap'] ?? 40 ) );
	}
	echo '<div class="wrap"><h1>Community tickets</h1>';
	if ( isset( $_GET['done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		echo '<div class="notice notice-success"><p>' . ( 'ok' === $_GET['done'] ? 'Approved. The pass has been emailed.' : 'Declined. They have been emailed.' ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
	}
	if ( $page ) {
		echo '<p>Requests come from <a href="' . esc_url( get_permalink( $page ) ) . '" target="_blank">' . esc_html( get_permalink( $page ) ) . '</a>. Approve sends the pass; decline sends a polite no.</p>';
	}
	if ( ! $games ) {
		echo '<p>No home games are listed.</p></div>';
		return;
	}
	foreach ( $games as $g ) {
		$rows = get_posts( array(
			'post_type'   => 'ewt_booking',
			'post_status' => 'any',
			'numberposts' => -1,
			'orderby'     => 'date',
			'order'       => 'ASC',
			'meta_query'  => array( array( 'key' => '_ewt_game', 'value' => (string) (int) $g['id'] ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
		) );
		$v     = ew_cm_vars( $g, 'Coronation Park' );
		$taken = ew_cm_taken( $g['id'] );
		echo '<h2 style="margin-top:28px">' . esc_html( $v['{day}'] . ' ' . $v['{date}'] . ' v ' . $v['{away}'] ) . ' <span style="font-weight:400;color:#666">· ' . (int) max( 0, $cap - $taken ) . ' of ' . (int) $cap . ' places left</span></h2>';
		if ( ! $rows ) {
			echo '<p style="color:#666">No requests yet.</p>';
			continue;
		}
		echo '<table class="widefat striped"><thead><tr><th>Name</th><th>Coming</th><th>Through</th><th>Note</th><th>Email</th><th>Status</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$m      = function ( $k ) use ( $r ) {
				return (string) get_post_meta( $r->ID, '_ewt_' . $k, true );
			};
			$status = $m( 'status' );
			$label  = array( 'community' => 'Waiting', 'community_ok' => 'Approved', 'community_no' => 'Declined' )[ $status ] ?? $status;
			if ( 'community_ok' === $status && $m( 'in' ) ) {
				$label .= ', in at ' . wp_date( 'g:ia', (int) $m( 'in' ) );
			}
			if ( 'failed' === $m( 'mailed' ) ) {
				$label .= ' (email failed)';
			}
			$through = '' !== $m( 'partner' ) ? $m( 'partner' ) . ( '' !== $m( 'partner_contact' ) ? ', ' . $m( 'partner_contact' ) : '' ) : 'Public page';
			echo '<tr><td><strong>' . esc_html( $m( 'name' ) ) . '</strong><br><small>' . esc_html( $m( 'code' ) ) . '</small></td><td>' . esc_html( $m( 'lines' ) ) . '</td><td>' . esc_html( $through ) . '</td><td>' . esc_html( $m( 'note' ) ) . '</td><td>' . esc_html( $m( 'email' ) ) . '</td><td>' . esc_html( $label ) . '</td><td>';
			if ( 'community' === $status ) {
				foreach ( array( 'approve' => 'Approve', 'decline' => 'Decline' ) as $do => $word ) {
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:6px">';
					wp_nonce_field( 'ew_cm_decide_' . $r->ID );
					echo '<input type="hidden" name="action" value="ew_cm_decide"><input type="hidden" name="id" value="' . (int) $r->ID . '"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
					echo '<button class="button' . ( 'approve' === $do ? ' button-primary' : '' ) . '">' . esc_html( $word ) . '</button></form>';
				}
			} elseif ( 'community_ok' === $status ) {
				echo '<a href="' . esc_url( ew_cm_pass_url( $r->ID ) ) . '" target="_blank">Pass</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}
	echo '</div>';
}

/* --- Look and behaviour ------------------------------------------------------ */

function ew_cm_css() {
	return '
.ewcm-feat{display:grid;grid-template-columns:440px minmax(0,1fr);background:#0b0b0b;border-radius:14px;overflow:hidden;color:#fff;min-height:520px;font-family:"Instrument Sans",system-ui,sans-serif}
.ewcm-ph{background:#111 center/cover no-repeat}
.ewcm-body{padding:70px;display:flex;flex-direction:column;justify-content:center}
.ewcm-feat p{margin:0}
.ewcm-feat .ewcm-kick{font-family:Anton,Impact,sans-serif;text-transform:uppercase;letter-spacing:.08em;font-size:22px;line-height:1.2;color:#ff4a3d}
.ewcm-feat .ewcm-title{font-family:Anton,Impact,sans-serif;font-weight:400;text-transform:uppercase;font-size:clamp(44px,5vw,76px);line-height:.95;color:#ead8b4;margin:14px 0 0;background:none;padding:0}
.ewcm-feat .ewcm-text{font-size:21px;line-height:1.5;color:#cfcfcf;margin:22px 0 0}
.ewcm-feat .ewcm-acts{display:flex;align-items:center;gap:28px;flex-wrap:wrap;margin-top:36px}
.ewcm-btn{display:inline-block;background:#cc0000;color:#fff!important;font-family:Anton,Impact,sans-serif;text-transform:uppercase;letter-spacing:.03em;font-size:20px;line-height:1;padding:18px 34px;border-radius:6px;text-decoration:none!important;white-space:nowrap}
.ewcm-btn:hover{background:#b30000}
.ewcm-free{font-family:Anton,Impact,sans-serif;font-size:30px;color:#fff}
.ewcm-full{font-family:Anton,Impact,sans-serif;text-transform:uppercase;font-size:20px;color:#9a9aa3}
.ewcm-list{display:flex;flex-direction:column;gap:14px;font-family:"Instrument Sans",system-ui,sans-serif}
.ewcm-row{background:#fff;border:1.5px solid #e2e2e2;border-radius:10px;display:grid;grid-template-columns:120px minmax(0,1fr) auto;align-items:center;gap:28px;padding:18px 28px 18px 18px}
.ewcm-date{background:#101010;color:#fff;border-radius:8px;padding:12px 0;text-align:center;display:flex;flex-direction:column}
.ewcm-date b{font-family:Anton,Impact,sans-serif;font-weight:400;font-size:40px;line-height:1}
.ewcm-date span{font-family:Anton,Impact,sans-serif;font-size:16px;color:#e01b1b;text-transform:uppercase;margin-top:2px}
.ewcm-date small{font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#cfcfcf;margin-top:2px}
.ewcm-row p{margin:0}
.ewcm-row .ewcm-rtitle{font-family:Anton,Impact,sans-serif;font-size:28px;line-height:1.05;text-transform:uppercase;color:#101010}
.ewcm-row .ewcm-rsub{font-size:15px;color:#555;margin-top:6px}
.ewcm-row .ewcm-btn{font-size:17px;padding:14px 22px}
.ewcm-none{font-family:"Instrument Sans",system-ui,sans-serif;font-size:17px;color:#555}
.ewcm .ewcm-t{margin:0 0 6px;font-family:Anton,Impact,sans-serif;font-size:34px;line-height:1.05;text-transform:uppercase;color:#fff}
.ewcm .ewcm-lede{margin:0 0 16px;font-size:15px;line-height:1.5;color:#e6e6e6}
.ewcm-partner{background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.3);border-radius:8px;padding:12px 14px;margin-bottom:16px;display:flex;flex-direction:column}
.ewcm-partner small{font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#cfcfcf}
.ewcm-partner b{font-family:Anton,Impact,sans-serif;font-weight:400;font-size:24px;text-transform:uppercase;margin-top:2px}
.ewcm select.ewc-in{appearance:auto}
.ewcm .ewcm-note{resize:none}
.ewcm-opt{font-weight:400;color:#cfcfcf}
.ewcm .ewc-head{margin-bottom:14px}
.ewcm-sent,.ewcm-msg{flex:1 1 auto;display:flex;flex-direction:column;justify-content:center;text-align:center;gap:10px}
.ewcm-sent svg{align-self:center;margin-bottom:8px}
.ewcm-sent[hidden]{display:none!important}
@media(max-width:860px){
 .ewcm-feat{grid-template-columns:1fr;min-height:0}
 .ewcm-ph{aspect-ratio:3/2}
 .ewcm-body{padding:28px 24px 32px}
 .ewcm-row{grid-template-columns:84px minmax(0,1fr);gap:16px;padding:14px}
 .ewcm-row .ewcm-btn,.ewcm-row .ewcm-full{grid-column:1/-1;text-align:center}
 .ewcm-date b{font-size:30px}
 .ewcm-row .ewcm-rtitle{font-size:22px}
}';
}

function ew_cm_js() {
	return <<<'JS'
(function(){
 var root = document.querySelector('.ewcm'); if(!root) return;
 function esc(s){ return String(s).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
 root.querySelectorAll('.ewc-qr[data-qr]').forEach(function(el){
   if(!window.qrcode || el.firstChild) return;
   var q = qrcode(0, 'M'); q.addData(el.getAttribute('data-qr')); q.make();
   el.innerHTML = q.createSvgTag({cellSize: 4, margin: 0, scalable: true});
 });
 root.querySelectorAll('.ewc-letin').forEach(function(b){
   b.addEventListener('click', function(){
     b.disabled = true;
     fetch((window.wpApiSettings ? wpApiSettings.root : '/wp-json/') + 'eastwood/v1/claim/in', {method:'POST', credentials:'same-origin',
       headers:{'Content-Type':'application/json','X-WP-Nonce': (window.wpApiSettings ? wpApiSettings.nonce : '')},
       body: JSON.stringify({code: b.getAttribute('data-code'), k: b.getAttribute('data-k')})})
     .then(function(r){ return r.json(); }).then(function(j){ if(j.at){ b.outerHTML = '<p class="ewc-arrived">' + esc(String(b.getAttribute('data-done') || 'In at {time}').replace('{time}', j.at)) + '</p>'; } else { b.disabled = false; } })
     .catch(function(){ b.disabled = false; });
   });
 });
 var wordsEl = root.querySelector('.ewcm-words'); if(!wordsEl) return;
 var W = JSON.parse(wordsEl.textContent), page = root.getAttribute('data-page');
 var form = root.querySelector('.ewcm-form'), sent = root.querySelector('.ewcm-sent'), err = form.querySelector('.ewc-err');
 var count = {adults: 1, children: 0};
 root.querySelectorAll('.ewc-count').forEach(function(row){
   var k = row.getAttribute('data-k'), out = row.querySelector('output'), btns = row.querySelectorAll('button');
   function paint(){ out.textContent = count[k]; btns[0].disabled = count[k] < 1; btns[1].disabled = count[k] > 9; }
   btns.forEach(function(b){ b.addEventListener('click', function(){ count[k] = Math.max(0, Math.min(10, count[k] + Number(b.getAttribute('data-d')))); paint(); }); });
   paint();
 });
 var sel = root.querySelector('#ewcmGame');
 sel.addEventListener('change', function(){
   var u = new URL(location.href); u.searchParams.set('game', sel.value); location.href = u.toString();
 });
 form.addEventListener('submit', function(e){
   e.preventDefault();
   var v = function(id){ var el = root.querySelector('#' + id); return el ? el.value.trim() : ''; };
   var name = v('ewcmName'), email = v('ewcmEmail');
   if(count.adults + count.children < 1){ err.textContent = W.need_people; return; }
   if(!name){ err.textContent = W.need_name; return; }
   if(email.indexOf('@') < 1 || email.indexOf('.', email.indexOf('@')) < 0){ err.textContent = W.need_email; return; }
   err.textContent = '';
   var go = form.querySelector('button[type="submit"]'); go.disabled = true;
   fetch(W.rest, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({
     page: page, game: sel.value, adults: count.adults, children: count.children, name: name, email: email,
     you: v('ewcmYou'), note: v('ewcmNote'), partner: W.partner, consent: root.querySelector('#ewcmConsent').checked})})
   .then(function(r){ return r.json().then(function(j){ return {ok: r.ok, j: j}; }); })
   .then(function(res){
     if(!res.ok || !res.j.ok){ err.textContent = (res.j && res.j.message) || W.failed; go.disabled = false; return; }
     form.hidden = true; sent.hidden = false;
     if(window.ewTrack) ewTrack('Lead', {what: 'Community tickets'});
     sent.querySelector('.ewcm-line').textContent = res.j.line;
     sent.querySelector('.ewcm-note2').textContent = res.j.note;
     root.scrollIntoView({behavior:'smooth', block:'start'});
   })
   .catch(function(){ err.textContent = W.failed; go.disabled = false; });
 });
})();
JS;
}

add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_singular() ) {
		return;
	}
	$post = get_post();
	if ( ! $post || ! function_exists( 'ew_post_contains' ) ) {
		return;
	}
	$games = ew_post_contains( $post, 'eastwood_community_games' );
	$req   = ew_post_contains( $post, 'eastwood_community_request' );
	if ( ! $games && ! $req ) {
		return;
	}
	wp_enqueue_style( 'ewc-fonts', 'https://fonts.googleapis.com/css2?family=Anton&family=Instrument+Sans:wght@400;600;700&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
	wp_register_style( 'ew-community', false, array(), EW_FWP_VERSION );
	wp_enqueue_style( 'ew-community' );
	wp_add_inline_style( 'ew-community', ( $req && function_exists( 'ew_claim_css' ) ? ew_claim_css() : '' ) . ew_cm_css() );
	if ( $req ) {
		wp_enqueue_script( 'ew-qrcode', ew_hb_asset( 'claim/qrcode.js' ), array(), EW_FWP_VERSION, true );
		if ( current_user_can( 'edit_posts' ) ) {
			wp_enqueue_script( 'wp-api' );
		}
		wp_register_script( 'ew-community', '', array( 'ew-qrcode' ), EW_FWP_VERSION, true );
		wp_enqueue_script( 'ew-community' );
		wp_add_inline_script( 'ew-community', ew_cm_js() );
	}
}, 24 );
