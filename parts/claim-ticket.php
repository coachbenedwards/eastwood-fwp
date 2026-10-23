<?php
/**
 * Claim a school ticket: eastwoodfc.com/claimticket.
 *
 * Every printed school ticket is the same, so the page asks instead: which
 * school, and how many are coming. Then a name and an email, then the pass
 * to show at the turnstile -- a QR code and a short code, unique to the claim.
 *
 * [eastwood_claim home="Eastwood FC" away="Deeping Rangers" ...]
 * Lawrence View Primary
 * Another School
 * [/eastwood_claim]
 *
 * The body holds the schools we already know, one per line. Type and it
 * searches them; a school that is not there is added once ("add it") and
 * shows in the search for everyone after, so each school is one entry for
 * the stats however people spell it.
 *
 * Every word the block shows is a field on the block. Claims are Bookings
 * (Bookings in the admin menu), status "claimed", with the school and the
 * numbers on them. The QR opens the same page on the pass; someone logged in
 * to the site sees a "Let them in" button that marks the claim as in.
 *
 * Nothing here takes money. The shirt offer comes after the claim in a later
 * version, once the claim itself is live.
 *
 * @package eastwood-fwp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The block's fields and what they say until someone changes them. */
function ew_claim_defaults() {
	return array(
		'event'         => 'Eastwood FC v Deeping Rangers, Saturday 24 October 2026',
		'home'          => 'Eastwood FC',
		'versus'        => 'v',
		'away'          => 'Deeping Rangers',
		'home_crest'    => 'claim/eastwood-white.png',
		'away_crest'    => 'claim/deeping-rangers-white.png',
		'background'    => 'claim/ticket-bg.jpg',
		'day'           => 'Saturday',
		'date'          => '24 October 2026',
		'time'          => '3PM',
		'stub_day'      => '24th',
		'stub_month'    => 'October 2026',
		'step_of'       => 'Step {n} of 2',
		'who_title'     => "Who's coming",
		'school_label'  => 'Which school gave you the ticket?',
		'school_add'    => "My school isn't listed, add it",
		'count_label'   => 'How many are coming?',
		'adults'        => 'Adults',
		'children'      => 'Children',
		'children_note' => 'Under 16',
		'continue'      => 'Continue',
		'need_school'   => 'Pick your school, or add it.',
		'need_people'   => 'Add at least one person.',
		'details_title' => 'Your details',
		'name_label'    => 'Your Full Name',
		'email_label'   => 'Your email',
		'consent'       => 'Tell me about when you do these tickets again',
		'change'        => 'Change',
		'claim'         => 'Claim tickets',
		'need_name'     => 'We need your full name for the list.',
		'need_email'    => 'We need an email to send your pass to.',
		'failed'        => 'Something went wrong. Please try again.',
		'adult_one'     => 'adult',
		'adult_many'    => 'adults',
		'child_one'     => 'child',
		'child_many'    => 'children',
		'done_title'    => "You're in",
		'done_note'     => 'Show this at the turnstile.',
		'extras_title'  => 'Make a day of it',
		'extras'        => 'Halloween Family Party | Fri 30 Oct | /events/halloween-family-party/ | discover/events-halloween.jpg; Peaky Blinders Casino Night | Sat 14 Nov | /events/peaky-blinders-night/ | discover/events-peaky-casino.jpg; Home shirt 2026/27 | Adult, women\'s and kids | /shop/home-shirt/ | claim/home-shirt-front.jpg',
		'view'          => 'View',
		'pass_none'     => "We can't find that pass. Check the link, or ask at the turnstile.",
		'let_in'        => 'Let them in',
		'is_in'         => 'In at {time}',
		'shirt_page'     => '107',
		'offer_tick'     => "You're on the list for Saturday.",
		'offer_title'    => 'Be truly part of it',
		'offer_label'    => 'Shirts',
		'offer_rows'     => "KIDS | Kids home shirt | home-shirt-2627-kids | Kids size | kids; ADULT | Adult home shirt | home-shirt-2627 | Adult size | adult; WOMENS | Women's home shirt | home-shirt-2627 | Women's size | women's",
		'offer_img'      => 'claim/home-shirt-front.jpg',
		'offer_pay'      => 'Pay {price}',
		'offer_skip'     => "No thanks, I'm not an Eastwood fan.",
		'offer_loading'  => 'Loading the payment form…',
		'paid_tick'      => 'Paid {price}. Your {word} shirt is ordered.',
		'print_shirt'    => 'Home shirt 2026/27',
		'print_sub'      => '{what} · {size}',
		'print_back'     => 'shop/home-shirt-back.png',
		'print_title'    => 'Roster or Name & Number',
		'print_players'  => 'Players',
		'print_custom'   => 'Custom',
		'print_none'     => 'None',
		'print_pick'     => 'Select Player',
		'print_name'     => 'Name',
		'print_num'      => 'Number',
		'print_chars'    => '{n} of {max} characters',
		'print_range'    => '1 to 99',
		'print_prints'   => 'Prints {what} on the back.',
		'print_and'      => 'and',
		'print_add'      => 'Add printing {price} · one tap',
		'print_go'       => 'Continue',
		'print_card'     => 'Charged to the card you just used',
		'print_other'    => 'Use a different card',
		'print_warn'     => 'Printed shirts cannot be returned.',
		'terms_word'     => 'Full terms',
		'terms_url'      => '/terms/',
		'print_bad'      => 'Pick a player, or give a name or a number.',
		'print_late'     => 'This offer has closed.',
		'print_declined' => 'Your bank wanted another check, so nothing was taken. Try a different card.',
		'rec_free'       => 'Free',
		'rec_shirt'      => '{what}, {size}',
		'rec_print'      => 'Printed {what}',
		'rec_total'      => 'Total',
		'crm_hook'      => '',
		/* The game after this one. At next_at (site time, "2026-10-24 15:40")
		   the page turns over to it: the match, the stub, the background,
		   and the event written on every claim from then on. Empty = never. */
		'next_at'         => '',
		'next_event'      => '',
		'next_away'       => '',
		'next_away_crest' => '',
		'next_background' => '',
		'next_day'        => '',
		'next_date'       => '',
		'next_time'       => '',
		'next_stub_day'   => '',
		'next_stub_month' => '',
	);
}

/** When the block turns over to the next game, as a timestamp; 0 if never. */
function ew_claim_next_at( $a ) {
	$v = trim( (string) ( $a['next_at'] ?? '' ) );
	if ( '' === $v ) {
		return 0;
	}
	try {
		$d = new DateTimeImmutable( $v, wp_timezone() );
	} catch ( Exception $e ) {
		return 0;
	}
	return $d->getTimestamp();
}

/** The next game's fields, keyed by the field they replace. */
function ew_claim_next_fields( $a ) {
	$out = array();
	foreach ( array( 'event', 'away', 'away_crest', 'background', 'day', 'date', 'time', 'stub_day', 'stub_month' ) as $k ) {
		$v = trim( (string) ( $a[ 'next_' . $k ] ?? '' ) );
		if ( '' !== $v ) {
			$out[ $k ] = $v;
		}
	}
	return $out;
}

/** The block as it reads right now: this game, or the next one once its time has come. */
function ew_claim_live( $a ) {
	$at = ew_claim_next_at( $a );
	if ( $at && time() >= $at ) {
		foreach ( ew_claim_next_fields( $a ) as $k => $v ) {
			$a[ $k ] = $v;
		}
	}
	return $a;
}

/** An image field: a full address, or a file in the plugin's assets. */
function ew_claim_img( $v ) {
	$v = trim( (string) $v );
	if ( '' === $v ) {
		return '';
	}
	if ( preg_match( '#^(https?:)?//#', $v ) || 0 === strpos( $v, '/' ) ) {
		return $v;
	}
	return ew_hb_asset( $v );
}

/** The schools: the ones written on the block, then the ones people added. */
function ew_claim_schools( $content ) {
	$all = array();
	foreach ( preg_split( '/\r\n|\r|\n|;/', wp_strip_all_tags( (string) $content ) ) as $s ) {
		$s = trim( $s );
		if ( '' !== $s ) {
			$all[ ew_claim_school_key( $s ) ] = $s;
		}
	}
	foreach ( (array) get_option( 'ew_claim_schools', array() ) as $s ) {
		$s = trim( (string) $s );
		if ( '' !== $s && ! isset( $all[ ew_claim_school_key( $s ) ] ) ) {
			$all[ ew_claim_school_key( $s ) ] = $s;
		}
	}
	$out = array_values( $all );
	natcasesort( $out );
	return array_values( $out );
}

/** Two spellings of one school come out the same here. */
function ew_claim_school_key( $s ) {
	$s = strtolower( (string) $s );
	$s = str_replace( array( '&', ' st.', ' st ' ), array( 'and', ' saint', ' saint ' ), $s );
	$s = preg_replace( '/\b(the|school|academy|primary|junior|infant|infants|juniors|c of e|cofe|ce)\b/', ' ', $s );
	return preg_replace( '/[^a-z0-9]+/', '', $s );
}

/** "1 adult, 2 children" in the block's own words. */
function ew_claim_party( $a, $adults, $children ) {
	$bits = array();
	if ( $adults ) {
		$bits[] = $adults . ' ' . ( 1 === $adults ? $a['adult_one'] : $a['adult_many'] );
	}
	if ( $children ) {
		$bits[] = $children . ' ' . ( 1 === $children ? $a['child_one'] : $a['child_many'] );
	}
	return implode( ', ', $bits );
}

/** The claim a pass link points at, if the link is right. */
function ew_claim_find( $code, $key ) {
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
	if ( ! $ids ) {
		return 0;
	}
	return hash_equals( (string) get_post_meta( $ids[0], '_ewt_key', true ), $key ) ? (int) $ids[0] : 0;
}

/** The ticket's left half: the match, as it is printed. */
function ew_claim_match_html( $a ) {
	$h  = '<div class="ewc-match">';
	$h .= '<div class="ewc-teams"><div class="ewc-names">';
	$h .= '<p class="ewc-team">' . esc_html( $a['home'] ) . '</p>';
	$h .= '<p class="ewc-v">' . esc_html( $a['versus'] ) . '</p>';
	$h .= '<p class="ewc-team">' . esc_html( $a['away'] ) . '</p></div>';
	$h .= '<div class="ewc-crests">';
	foreach ( array( 'home', 'away' ) as $side ) {
		$src = ew_claim_img( $a[ $side . '_crest' ] );
		if ( '' !== $src ) {
			$h .= '<img src="' . esc_url( $src ) . '" alt="' . esc_attr( $a[ $side ] ) . '" width="128" height="128">';
		}
	}
	$h .= '</div></div>';
	$h .= '<div class="ewc-when"><div><p class="ewc-day">' . esc_html( $a['day'] ) . '</p><p class="ewc-date">' . esc_html( $a['date'] ) . '</p></div>';
	$h .= '<p class="ewc-time">' . esc_html( $a['time'] ) . '</p></div>';
	$h .= '</div>';
	return $h;
}

/** The pass itself: who, the QR and the code. */
function ew_claim_pass_html( $a, $id ) {
	$m     = function ( $k ) use ( $id ) {
		return (string) get_post_meta( $id, '_ewt_' . $k, true );
	};
	$code  = $m( 'code' );
	$url   = add_query_arg( array( 'pass' => $code, 'k' => $m( 'key' ) ), get_permalink( (int) $m( 'page' ) ) );
	$party = ew_claim_party( $a, (int) $m( 'adults' ), (int) $m( 'children' ) );
	$h     = '<p class="ewc-done">' . esc_html( $a['done_title'] ) . '</p>';
	$h    .= '<p class="ewc-who">' . esc_html( $m( 'name' ) ) . '<br>' . esc_html( $party ) . ' &middot; ' . esc_html( $m( 'school' ) ) . '</p>';
	$h    .= '<div class="ewc-qr" data-qr="' . esc_attr( $url ) . '" role="img" aria-label="' . esc_attr( $code ) . '"></div>';
	$h    .= '<p class="ewc-code">' . esc_html( $code ) . '</p>';
	$in    = (int) $m( 'in' );
	if ( $in ) {
		$h .= '<p class="ewc-arrived">' . esc_html( str_replace( '{time}', wp_date( 'g:ia', $in ), $a['is_in'] ) ) . '</p>';
	} elseif ( current_user_can( 'edit_posts' ) ) {
		$h .= '<button type="button" class="ewc-btn ewc-letin" data-done="' . esc_attr( $a['is_in'] ) . '" data-code="' . esc_attr( $code ) . '" data-k="' . esc_attr( $m( 'key' ) ) . '">' . esc_html( $a['let_in'] ) . '</button>';
	}
	if ( function_exists( 'ew_claim_receipt_html' ) ) {
		$h .= ew_claim_receipt_html( $a, $id );
	}
	$h .= '<div class="ewc-grow"></div><p class="ewc-small">' . esc_html( $a['done_note'] ) . '</p>';
	return $h;
}

/** The cards under the pass: real events and the shirt. */
function ew_claim_extras_html( $a ) {
	$rows = array_filter( array_map( 'trim', explode( ';', (string) $a['extras'] ) ) );
	if ( ! $rows ) {
		return '';
	}
	$h = '<div class="ewc-extras"><h2>' . esc_html( $a['extras_title'] ) . '</h2><div class="ewc-cards">';
	foreach ( $rows as $r ) {
		$b   = array_map( 'trim', explode( '|', $r ) );
		$img = ew_claim_img( $b[3] ?? '' );
		$h  .= '<div class="ewc-card">';
		if ( '' !== $img ) {
			$h .= '<img src="' . esc_url( $img ) . '" alt="">';
		}
		$h .= '<span class="ewc-card-t"><b>' . esc_html( $b[0] ?? '' ) . '</b><span>' . esc_html( $b[1] ?? '' ) . '</span></span>';
		$h .= '<a href="' . esc_url( $b[2] ?? '#' ) . '">' . esc_html( $a['view'] ) . '</a></div>';
	}
	return $h . '</div></div>';
}

function ew_claim_shortcode( $atts, $content = null ) {
	$a    = ew_claim_live( shortcode_atts( ew_claim_defaults(), (array) $atts, 'eastwood_claim' ) );
	$post = get_post();
	$bg   = ew_claim_img( $a['background'] );
	$step = function ( $n ) use ( $a ) {
		return esc_html( str_replace( '{n}', (string) $n, $a['step_of'] ) );
	};

	$h = '<section class="ewc" data-page="' . (int) ( $post ? $post->ID : 0 ) . '">';
	$h .= '<div class="ewc-tk"' . ( '' !== $bg ? ' style="background-image:url(' . esc_url( $bg ) . ')"' : '' ) . '>';
	$h .= ew_claim_match_html( $a );
	$h .= '<div class="ewc-stub">';

	/* Opened from a pass link: show the pass, nothing else. */
	if ( isset( $_GET['pass'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$id = ew_claim_find( wp_unslash( $_GET['pass'] ), wp_unslash( $_GET['k'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
		/* Back from paying for a shirt: record it, and offer the printing next. */
		if ( $id && function_exists( 'ew_claim_shirt_page' ) ) {
			$alt = ew_claim_shirt_page( $a, $id );
			if ( '' !== $alt ) {
				return $alt;
			}
		}
		$h .= $id ? ew_claim_pass_html( $a, $id ) : '<p class="ewc-small">' . esc_html( $a['pass_none'] ) . '</p>';
		$h .= '</div></div>';
		if ( $id ) {
			$h .= ew_claim_extras_html( $a );
		}
		return $h . '</section>';
	}

	$words = array(
		'need_school' => $a['need_school'],
		'need_people' => $a['need_people'],
		'need_name'   => $a['need_name'],
		'need_email'  => $a['need_email'],
		'failed'      => $a['failed'],
		'add'         => $a['school_add'],
		'one'         => array( $a['adult_one'], $a['child_one'] ),
		'many'        => array( $a['adult_many'], $a['child_many'] ),
		'schools'     => ew_claim_schools( $content ),
		'rest'        => esc_url_raw( rest_url( 'eastwood/v1/claim' ) ),
	);
	$next_at = ew_claim_next_at( $a );
	if ( $next_at && time() < $next_at ) {
		$nf = ew_claim_next_fields( $a );
		if ( isset( $nf['away_crest'] ) ) {
			$nf['away_crest'] = ew_claim_img( $nf['away_crest'] );
		}
		if ( isset( $nf['background'] ) ) {
			$nf['background'] = ew_claim_img( $nf['background'] );
		}
		$words['next'] = array( 'at' => $next_at * 1000, 'fields' => $nf );
	}

	$h .= '<p class="ewc-big">' . esc_html( $a['stub_day'] ) . '</p><p class="ewc-month">' . esc_html( $a['stub_month'] ) . '</p>';

	/* Step 1: which school, how many. */
	$h .= '<div class="ewc-step" data-step="1">';
	$h .= '<div class="ewc-head"><span>' . esc_html( $a['who_title'] ) . '</span><small>' . $step( 1 ) . '</small></div>';
	$h .= '<label class="ewc-lab" for="ewcSchool">' . esc_html( $a['school_label'] ) . '</label>';
	$h .= '<div class="ewc-search"><div class="ewc-sbox"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#555" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>';
	$h .= '<input id="ewcSchool" type="text" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="ewcList"></div>';
	$h .= '<div class="ewc-list" id="ewcList" role="listbox" hidden></div></div>';
	$h .= '<p class="ewc-lab">' . esc_html( $a['count_label'] ) . '</p><div class="ewc-counts">';
	foreach ( array( 'adults' => '', 'children' => $a['children_note'] ) as $k => $note ) {
		$h .= '<div class="ewc-count" data-k="' . esc_attr( $k ) . '"><span><b>' . esc_html( $a[ $k ] ) . '</b>' . ( '' !== $note ? '<small>' . esc_html( $note ) . '</small>' : '' ) . '</span>';
		$h .= '<span class="ewc-pm"><button type="button" data-d="-1" aria-label="&minus; ' . esc_attr( $a[ $k ] ) . '">&minus;</button><output>' . ( 'adults' === $k ? 1 : 0 ) . '</output><button type="button" data-d="1" aria-label="+ ' . esc_attr( $a[ $k ] ) . '">+</button></span></div>';
	}
	$h .= '</div><p class="ewc-err" role="alert"></p><button type="button" class="ewc-btn ewc-next">' . esc_html( $a['continue'] ) . '</button></div>';

	/* Step 2: name and email. */
	$h .= '<form class="ewc-step" data-step="2" hidden novalidate>';
	$h .= '<div class="ewc-head"><span>' . esc_html( $a['details_title'] ) . '</span><small>' . $step( 2 ) . '</small></div>';
	$h .= '<label class="ewc-lab" for="ewcName">' . esc_html( $a['name_label'] ) . '</label><input class="ewc-in" id="ewcName" type="text" autocomplete="name" required>';
	$h .= '<label class="ewc-lab" for="ewcEmail">' . esc_html( $a['email_label'] ) . '</label><input class="ewc-in" id="ewcEmail" type="email" autocomplete="email" required>';
	$h .= '<label class="ewc-tick"><input type="checkbox" id="ewcConsent" checked><span>' . esc_html( $a['consent'] ) . '</span></label>';
	$h .= '<div class="ewc-recap"><span class="ewc-recap-t"></span><button type="button" class="ewc-back">' . esc_html( $a['change'] ) . '</button></div>';
	$h .= '<p class="ewc-err" role="alert"></p><button type="submit" class="ewc-btn">' . esc_html( $a['claim'] ) . '</button></form>';

	/* Step 3: the pass, filled in by the script from the claim. */
	$h .= '<div class="ewc-step ewc-passed" data-step="3" hidden></div>';

	$h .= '</div></div>';
	$h .= '<div class="ewc-after" hidden>' . ew_claim_extras_html( $a ) . '</div>';
	$h .= '<script type="application/json" class="ewc-words">' . wp_json_encode( $words ) . '</script>';
	return $h . '</section>';
}
add_shortcode( 'eastwood_claim', 'ew_claim_shortcode' );

/** The claim block on a page, read from the page itself. */
function ew_claim_block_on( $post ) {
	$found = null;
	$data  = json_decode( (string) get_post_meta( $post->ID, '_elementor_data', true ), true );
	if ( is_array( $data ) ) {
		$walk = function ( $nodes ) use ( &$walk, &$found ) {
			foreach ( (array) $nodes as $n ) {
				if ( $found ) {
					return;
				}
				$sc = (string) ( $n['settings']['shortcode'] ?? '' );
				if ( 0 === strpos( ltrim( $sc ), '[eastwood_claim' ) ) {
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
	if ( ! $found && preg_match( '/\[eastwood_claim[^\]]*\](.*?\[\/eastwood_claim\])?/s', (string) $post->post_content, $mm ) ) {
		$found = $mm[0];
	}
	if ( ! $found ) {
		return null;
	}
	$atts = array();
	$body = '';
	if ( preg_match( '/\[eastwood_claim([^\]]*)\]/', $found, $o ) ) {
		$atts = (array) shortcode_parse_atts( $o[1] );
	}
	if ( preg_match( '/\](.*)\[\/eastwood_claim\]/s', $found, $c ) ) {
		$body = $c[1];
	}
	return array( 'atts' => ew_claim_live( shortcode_atts( ew_claim_defaults(), $atts, 'eastwood_claim' ) ), 'body' => $body );
}

add_action( 'rest_api_init', function () {
	/* Add a school nobody has added before. */
	register_rest_route( 'eastwood/v1', '/claim/school', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) {
			$name = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $req->get_param( 'name' ) ) ) );
			if ( strlen( $name ) < 3 || strlen( $name ) > 80 ) {
				return new WP_REST_Response( array( 'message' => 'bad' ), 400 );
			}
			$post  = get_post( (int) $req->get_param( 'page' ) );
			$block = $post ? ew_claim_block_on( $post ) : null;
			$known = ew_claim_schools( $block ? $block['body'] : '' );
			foreach ( $known as $k ) {
				if ( ew_claim_school_key( $k ) === ew_claim_school_key( $name ) ) {
					return array( 'school' => $k );
				}
			}
			$added   = (array) get_option( 'ew_claim_schools', array() );
			$added[] = ucwords( $name );
			update_option( 'ew_claim_schools', array_values( array_unique( $added ) ), false );
			return array( 'school' => ucwords( $name ) );
		},
	) );

	/* Make the claim. */
	register_rest_route( 'eastwood/v1', '/claim', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) {
			$post  = get_post( (int) $req->get_param( 'page' ) );
			$block = $post ? ew_claim_block_on( $post ) : null;
			if ( ! $block ) {
				return new WP_REST_Response( array( 'message' => 'no block' ), 400 );
			}
			$a        = $block['atts'];
			$name     = trim( sanitize_text_field( (string) $req->get_param( 'name' ) ) );
			$email    = sanitize_email( (string) $req->get_param( 'email' ) );
			$school   = trim( sanitize_text_field( (string) $req->get_param( 'school' ) ) );
			$adults   = max( 0, min( 20, (int) $req->get_param( 'adults' ) ) );
			$children = max( 0, min( 20, (int) $req->get_param( 'children' ) ) );
			if ( '' === $name ) {
				return new WP_REST_Response( array( 'message' => $a['need_name'] ), 400 );
			}
			if ( ! is_email( $email ) ) {
				return new WP_REST_Response( array( 'message' => $a['need_email'] ), 400 );
			}
			if ( '' === $school ) {
				return new WP_REST_Response( array( 'message' => $a['need_school'] ), 400 );
			}
			if ( $adults + $children < 1 ) {
				return new WP_REST_Response( array( 'message' => $a['need_people'] ), 400 );
			}
			do {
				$code = 'EFC-' . wp_rand( 1000, 9999 );
			} while ( get_posts( array( 'post_type' => 'ewt_booking', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_ewt_code', 'meta_value' => $code ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
			$id = wp_insert_post( array(
				'post_type'   => 'ewt_booking',
				'post_status' => 'publish',
				'post_title'  => $name,
			) );
			if ( ! $id || is_wp_error( $id ) ) {
				return new WP_REST_Response( array( 'message' => $a['failed'] ), 500 );
			}
			$party = ew_claim_party( $a, $adults, $children );
			foreach ( array(
				'name'     => $name,
				'email'    => $email,
				'event'    => $a['event'],
				'page'     => $post->ID,
				'url'      => get_permalink( $post ),
				'lines'    => $party,
				'extra'    => $school,
				'school'   => $school,
				'adults'   => $adults,
				'children' => $children,
				'total'    => 0,
				'status'   => 'claimed',
				'consent'  => $req->get_param( 'consent' ) ? 'yes' : 'no',
				'code'     => $code,
				'key'      => wp_generate_password( 12, false ),
				'hook'     => (string) $a['crm_hook'],
			) as $k => $v ) {
				update_post_meta( $id, '_ewt_' . $k, $v );
			}
			if ( function_exists( 'ew_tix_crm' ) ) {
				ew_tix_crm( (string) $a['crm_hook'], $id, 'claimed' );
			}
			return array(
				'html'  => ew_claim_pass_html( $a, $id ),
				'offer' => function_exists( 'ew_claim_offer_html' ) ? ew_claim_offer_html( $a, $id ) : '',
			);
		},
	) );

	/* At the turnstile: someone logged in marks the claim as in. */
	register_rest_route( 'eastwood/v1', '/claim/in', array(
		'methods'             => 'POST',
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'callback'            => function ( $req ) {
			$id = ew_claim_find( (string) $req->get_param( 'code' ), (string) $req->get_param( 'k' ) );
			if ( ! $id ) {
				return new WP_REST_Response( array( 'message' => 'not found' ), 404 );
			}
			if ( ! get_post_meta( $id, '_ewt_in', true ) ) {
				update_post_meta( $id, '_ewt_in', time() );
			}
			return array( 'at' => wp_date( 'g:ia', (int) get_post_meta( $id, '_ewt_in', true ) ) );
		},
	) );
} );

/* Claims in the Bookings list: show the school, and whether they came. */
add_action( 'manage_ewt_booking_posts_custom_column', function ( $col, $id ) {
	if ( 'status' === $col && get_post_meta( $id, '_ewt_in', true ) ) {
		echo '<br>' . esc_html( 'In ' . wp_date( 'D g:ia', (int) get_post_meta( $id, '_ewt_in', true ) ) );
	}
}, 20, 2 );

function ew_claim_css() {
	return '
.ewc{font-family:"Instrument Sans",system-ui,sans-serif;color:#101010;max-width:1240px;margin:0 auto}
.ewc *{box-sizing:border-box}
.ewc [hidden]{display:none!important}
.ewc-tk{border-radius:14px;overflow:hidden;color:#fff;display:grid;grid-template-columns:minmax(0,1fr) 470px;min-height:660px;background:#000 left center/cover no-repeat}
.ewc-match{background:rgba(0,0,0,.35);padding:56px 52px 44px;display:flex;flex-direction:column;justify-content:space-between;gap:32px}
.ewc-teams{display:flex;gap:32px;align-items:center}
.ewc-names{flex:1 1 auto;min-width:0}
.ewc .ewc-team{margin:0;font-family:Anton,Impact,sans-serif;font-size:96px;line-height:.95;text-transform:uppercase;color:#fff}
.ewc .ewc-v{margin:2px 0;font-family:Georgia,serif;font-style:italic;font-size:52px;line-height:1;color:#e01b1b}
.ewc-crests{flex:0 0 auto;display:flex;flex-direction:column;gap:30px}
.ewc-crests img{width:128px;height:128px;object-fit:contain}
.ewc-when{display:flex;justify-content:space-between;align-items:flex-end;gap:16px}
.ewc .ewc-day{margin:0 0 4px;font-size:18px;font-weight:700;letter-spacing:.4em;text-transform:uppercase;color:#e01b1b}
.ewc .ewc-date{margin:0;font-size:34px;text-transform:uppercase;color:#fff}
.ewc .ewc-time{margin:0;font-family:Anton,Impact,sans-serif;font-size:72px;line-height:1;color:#fff}
.ewc-stub{background:rgba(0,0,0,.8);border-left:3px dashed rgba(255,255,255,.85);padding:40px 44px;display:flex;flex-direction:column}
.ewc .ewc-big{margin:0;font-family:Anton,Impact,sans-serif;font-size:72px;line-height:1;text-align:center;color:#fff}
.ewc .ewc-month{margin:0 0 18px;font-family:Anton,Impact,sans-serif;font-size:26px;color:#e01b1b;text-align:center;text-transform:uppercase}
.ewc-step{display:flex;flex-direction:column}
.ewc-head{display:flex;justify-content:space-between;align-items:baseline;border-top:1px solid rgba(255,255,255,.25);padding-top:14px;margin-bottom:16px}
.ewc-head span{font-family:Anton,Impact,sans-serif;font-size:28px;text-transform:uppercase}
.ewc-head small{font-size:13px;font-weight:700;color:#cfcfcf}
.ewc .ewc-lab{display:block;margin:0 0 8px;font-size:14px;font-weight:700;color:#fff}
.ewc-search{position:relative;margin-bottom:20px}
.ewc-sbox{background:#fff;color:#101010;border-radius:8px;padding:0 16px;display:flex;align-items:center;gap:10px}
.ewc-sbox input{flex:1 1 auto;border:0;outline:0;font:inherit;font-size:15px;padding:13px 0;background:transparent;color:#101010}
.ewc-search.open .ewc-sbox{border-radius:8px 8px 0 0;border-bottom:1px solid #e4e4e4}
.ewc-list{position:absolute;left:0;right:0;top:100%;background:#fff;color:#101010;border-radius:0 0 8px 8px;box-shadow:0 12px 28px rgba(0,0,0,.45);z-index:5;overflow:auto;max-height:260px}
.ewc-list button{display:block;width:100%;text-align:left;font:inherit;font-size:15px;background:#fff;color:#101010;border:0;border-top:1px solid #ececec;padding:12px 16px;cursor:pointer}
.ewc-list button:first-child{border-top:0}
.ewc-list button:hover,.ewc-list button.on{background:#f3f3f3}
.ewc-list .ewc-add{display:flex;align-items:center;gap:8px;color:#a30000;font-weight:600}
.ewc-counts{background:#fff;color:#101010;border-radius:8px;margin-bottom:22px}
.ewc-count{display:flex;align-items:center;justify-content:space-between;padding:10px 16px}
.ewc-count+.ewc-count{border-top:1px solid #ececec}
.ewc-count b{display:block;font-weight:600}
.ewc-count small{display:block;font-size:13px;color:#555}
.ewc-pm{display:flex;align-items:center;gap:14px}
.ewc-pm button{width:40px;height:40px;border-radius:999px;border:1.5px solid #101010;background:#fff;color:#101010;font:inherit;font-size:20px;cursor:pointer;padding:0}
.ewc-pm button:disabled{border-color:#cfcfcf;color:#9a9a9a;cursor:default}
.ewc-pm output{font-family:Anton,Impact,sans-serif;font-size:22px;width:22px;text-align:center}
.ewc-btn{display:block;width:100%;background:#CC0000;color:#fff;border:0;border-radius:6px;padding:15px;text-align:center;font:inherit;font-size:15px;font-weight:700;letter-spacing:.4px;cursor:pointer}
.ewc-btn:hover{background:#b30000}
.ewc-btn[disabled]{opacity:.6;cursor:default}
.ewc-in{width:100%;font:inherit;font-size:15px;background:#fff;color:#101010;border:0;border-radius:8px;padding:12px 16px;margin-bottom:14px}
.ewc-tick{display:flex;gap:10px;align-items:flex-start;font-size:14px;line-height:1.45;margin-bottom:18px;color:#fff;cursor:pointer}
.ewc-tick input{width:20px;height:20px;margin:0;accent-color:#CC0000;flex:0 0 auto}
.ewc-recap{display:flex;justify-content:space-between;align-items:baseline;gap:12px;font-size:14px;color:#e6e6e6;border-top:1px solid rgba(255,255,255,.25);border-bottom:1px solid rgba(255,255,255,.25);padding:12px 0;margin-bottom:18px}
.ewc-back{background:none;border:0;padding:0;color:#fff;font:inherit;font-size:13px;text-decoration:underline;cursor:pointer}
.ewc .ewc-err{margin:0 0 12px;font-size:14px;font-weight:600;color:#ff8a8a}
.ewc .ewc-err:empty{display:none}
.ewc-passed{flex:1 1 auto}
.ewc .ewc-done{margin:0;font-family:Anton,Impact,sans-serif;font-size:56px;line-height:1;text-align:center;text-transform:uppercase;color:#fff}
.ewc .ewc-who{margin:8px 0 22px;font-size:15px;line-height:1.5;color:#dcdcdc;text-align:center}
.ewc-qr{align-self:center;width:220px;height:220px;background:#fff;border-radius:6px;padding:14px}
.ewc-qr svg{display:block;width:100%;height:100%}
.ewc .ewc-code{margin:14px 0 0;font-family:Anton,Impact,sans-serif;font-size:26px;letter-spacing:1px;text-align:center;color:#fff}
.ewc .ewc-arrived{margin:12px 0 0;text-align:center;font-weight:700;color:#7fe3a0}
.ewc-letin{margin-top:16px}
.ewc-grow{flex:1 1 auto;min-height:16px}
.ewc .ewc-small{margin:0;font-size:15px;line-height:1.5;color:#e6e6e6;text-align:center}
.ewc-extras{margin-top:28px}
.ewc-extras h2{font-family:Anton,Impact,sans-serif;font-weight:400;font-size:26px;letter-spacing:.5px;text-transform:uppercase;margin:0 0 14px;color:#101010;background:none;padding:0}
.ewc-cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:20px}
.ewc-card{background:#fff;border:1.5px solid #e2e2e2;border-radius:10px;padding:14px 18px 14px 14px;display:flex;align-items:center;gap:16px}
.ewc-card img{width:64px;height:96px;object-fit:cover;border-radius:6px;flex:0 0 auto}
.ewc-card-t{flex:1 1 auto;min-width:0}
.ewc-card-t b{display:block;font-family:Anton,Impact,sans-serif;font-weight:400;font-size:20px;text-transform:uppercase}
.ewc-card-t span{font-size:14px;color:#555}
.ewc-card a{border:1.5px solid #101010;border-radius:6px;padding:9px 14px;font-weight:700;font-size:14px;text-decoration:none;color:#101010;white-space:nowrap}
@media(max-width:1100px){.ewc .ewc-team{font-size:68px}.ewc-crests img{width:96px;height:96px}}
@media(max-width:900px){
 .ewc-tk{grid-template-columns:minmax(0,1fr);min-height:0}
 .ewc-match{padding:28px 24px;gap:22px}
 .ewc .ewc-team{font-size:44px}.ewc .ewc-v{font-size:30px}
 .ewc-crests{gap:14px}.ewc-crests img{width:64px;height:64px}
 .ewc .ewc-date{font-size:22px}.ewc .ewc-time{font-size:44px}
 .ewc-stub{border-left:0;border-top:3px dashed rgba(255,255,255,.85);padding:28px 22px}
 .ewc .ewc-big{font-size:52px}
 .ewc-cards{grid-template-columns:1fr}
}';
}

function ew_claim_js() {
	return <<<'JS'
(function(){
 var root = document.querySelector('.ewc'); if(!root) return;
 function esc(s){ return String(s).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
 function draw(){ root.querySelectorAll('.ewc-qr[data-qr]').forEach(function(el){
   if(!window.qrcode || el.firstChild) return;
   var q = qrcode(0, 'M'); q.addData(el.getAttribute('data-qr')); q.make();
   el.innerHTML = q.createSvgTag({cellSize: 4, margin: 0, scalable: true});
 }); }
 function letin(){ root.querySelectorAll('.ewc-letin').forEach(function(b){
   b.addEventListener('click', function(){
     b.disabled = true;
     fetch((window.wpApiSettings ? wpApiSettings.root : '/wp-json/') + 'eastwood/v1/claim/in', {method:'POST', credentials:'same-origin',
       headers:{'Content-Type':'application/json','X-WP-Nonce': (window.wpApiSettings ? wpApiSettings.nonce : '')},
       body: JSON.stringify({code: b.getAttribute('data-code'), k: b.getAttribute('data-k')})})
     .then(function(r){ return r.json(); }).then(function(j){ if(j.at){ b.outerHTML = '<p class="ewc-arrived">' + esc(String(b.getAttribute('data-done') || 'In at {time}').replace('{time}', j.at)) + '</p>'; } else { b.disabled = false; } })
     .catch(function(){ b.disabled = false; });
   });
 }); }
 draw(); letin();
 window.ewcRedraw = function(){ draw(); letin(); };
 var wordsEl = root.querySelector('.ewc-words'); if(!wordsEl) return;
 var W = JSON.parse(wordsEl.textContent), page = root.getAttribute('data-page');
 var s1 = root.querySelector('[data-step="1"]'), s2 = root.querySelector('[data-step="2"]'), s3 = root.querySelector('[data-step="3"]');
 var input = root.querySelector('#ewcSchool'), list = root.querySelector('#ewcList'), box = root.querySelector('.ewc-search');
 var school = '', schools = W.schools || [];
 var count = {adults: 1, children: 0};

 /* The next game: once its time comes the ticket turns over, cached page or not. */
 if(W.next && W.next.at){
   var turned = false;
   var turn = function(){
     if(turned || Date.now() < W.next.at) return;
     turned = true;
     var f = W.next.fields || {}, teams = root.querySelectorAll('.ewc-team'), crests = root.querySelectorAll('.ewc-crests img');
     var set = function(sel, v){ var el = root.querySelector(sel); if(el && v) el.textContent = v; };
     if(f.away && teams[1]) teams[1].textContent = f.away;
     if(f.away_crest && crests[1]){ crests[1].src = f.away_crest; if(f.away) crests[1].alt = f.away; }
     if(f.background){ var tk = root.querySelector('.ewc-tk'); if(tk) tk.style.backgroundImage = 'url(' + f.background + ')'; }
     set('.ewc-day', f.day); set('.ewc-date', f.date); set('.ewc-time', f.time); set('.ewc-big', f.stub_day); set('.ewc-month', f.stub_month);
   };
   turn(); setInterval(turn, 15000);
 }

 function norm(s){ return String(s).toLowerCase().replace(/&/g,'and').replace(/[^a-z0-9]+/g,' ').trim(); }
 function close(){ list.hidden = true; box.classList.remove('open'); input.setAttribute('aria-expanded','false'); }
 function show(){
   var q = norm(input.value);
   if(q.length < 2){ close(); return; }
   var hits = schools.filter(function(s){ return norm(s).indexOf(q) > -1; }).slice(0, 8);
   var h = hits.map(function(s){
     var i = s.toLowerCase().indexOf(input.value.trim().toLowerCase());
     var t = i > -1 ? esc(s.slice(0,i)) + '<strong>' + esc(s.slice(i, i + input.value.trim().length)) + '</strong>' + esc(s.slice(i + input.value.trim().length)) : esc(s);
     return '<button type="button" role="option" data-s="' + esc(s) + '">' + t + '</button>';
   }).join('');
   h += '<button type="button" class="ewc-add" data-add="1"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>' + esc(W.add) + '</button>';
   list.innerHTML = h; list.hidden = false; box.classList.add('open'); input.setAttribute('aria-expanded','true');
 }
 input.addEventListener('input', function(){ school = ''; show(); });
 input.addEventListener('focus', show);
 document.addEventListener('click', function(e){ if(!box.contains(e.target)) close(); });
 list.addEventListener('click', function(e){
   var b = e.target.closest('button'); if(!b) return;
   if(b.getAttribute('data-add')){
     var name = input.value.trim(); if(name.length < 3){ input.focus(); return; }
     b.disabled = true;
     fetch(W.rest + '/school', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({name: name, page: page})})
       .then(function(r){ return r.json(); }).then(function(j){
         if(j.school){ school = j.school; input.value = j.school; if(schools.indexOf(j.school) < 0) schools.push(j.school); }
         close();
       }).catch(function(){ b.disabled = false; });
     return;
   }
   school = b.getAttribute('data-s'); input.value = school; close();
 });

 function party(){
   var bits = [];
   if(count.adults) bits.push(count.adults + ' ' + (count.adults === 1 ? W.one[0] : W.many[0]));
   if(count.children) bits.push(count.children + ' ' + (count.children === 1 ? W.one[1] : W.many[1]));
   return bits.join(', ');
 }
 root.querySelectorAll('.ewc-count').forEach(function(row){
   var k = row.getAttribute('data-k'), out = row.querySelector('output'), btns = row.querySelectorAll('button');
   function paint(){ out.textContent = count[k]; btns[0].disabled = count[k] < 1; btns[1].disabled = count[k] > 19; }
   btns.forEach(function(b){ b.addEventListener('click', function(){ count[k] = Math.max(0, Math.min(20, count[k] + Number(b.getAttribute('data-d')))); paint(); }); });
   paint();
 });

 function err(step, t){ step.querySelector('.ewc-err').textContent = t || ''; }
 root.querySelector('.ewc-next').addEventListener('click', function(){
   if(!school){
     var typed = norm(input.value), match = schools.filter(function(s){ return norm(s) === typed; })[0];
     if(match){ school = match; } else { err(s1, W.need_school); input.focus(); return; }
   }
   if(count.adults + count.children < 1){ err(s1, W.need_people); return; }
   err(s1, '');
   root.querySelector('.ewc-recap-t').textContent = school + ' · ' + party();
   s1.hidden = true; s2.hidden = false;
   root.querySelector('#ewcName').focus();
 });
 root.querySelector('.ewc-back').addEventListener('click', function(){ s2.hidden = true; s1.hidden = false; });
 s2.addEventListener('submit', function(e){
   e.preventDefault();
   var name = root.querySelector('#ewcName').value.trim(), email = root.querySelector('#ewcEmail').value.trim();
   if(!name){ err(s2, W.need_name); return; }
   if(email.indexOf('@') < 1 || email.indexOf('.', email.indexOf('@')) < 0){ err(s2, W.need_email); return; }
   err(s2, '');
   var go = s2.querySelector('button[type="submit"]'); go.disabled = true;
   fetch(W.rest, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({
     page: page, school: school, adults: count.adults, children: count.children,
     name: name, email: email, consent: root.querySelector('#ewcConsent').checked})})
   .then(function(r){ return r.json().then(function(j){ return {ok: r.ok, j: j}; }); })
   .then(function(res){
     if(!res.ok || !res.j.html){ err(s2, (res.j && res.j.message) || W.failed); go.disabled = false; return; }
     root.querySelector('.ewc-big').hidden = true; root.querySelector('.ewc-month').hidden = true;
     s2.hidden = true; s3.hidden = false;
     if(window.ewTrack) ewTrack('Lead', {what: 'School tickets'});
     if(res.j.offer && window.ewcOffer){
       /* The shirt offer first; No thanks goes to the pass. */
       s3.innerHTML = res.j.offer; window.ewcOffer(s3.querySelector('.ewc-offer'), res.j.html);
     } else {
       s3.innerHTML = res.j.html;
       var after = root.querySelector('.ewc-after'); if(after) after.hidden = false;
     }
     draw(); letin();
     root.scrollIntoView({behavior:'smooth', block:'start'});
   })
   .catch(function(){ err(s2, W.failed); go.disabled = false; });
 });
})();
JS;
}

add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_singular() ) {
		return;
	}
	$post = get_post();
	if ( ! $post || ! ew_post_contains( $post, 'eastwood_claim' ) ) {
		return;
	}
	wp_enqueue_style( 'ewc-fonts', 'https://fonts.googleapis.com/css2?family=Anton&family=Instrument+Sans:wght@400;600;700&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
	wp_register_style( 'ew-claim', false, array(), EW_FWP_VERSION );
	wp_enqueue_style( 'ew-claim' );
	wp_add_inline_style( 'ew-claim', ew_claim_css() );
	wp_enqueue_script( 'ew-qrcode', ew_hb_asset( 'claim/qrcode.js' ), array(), EW_FWP_VERSION, true );
	if ( current_user_can( 'edit_posts' ) ) {
		wp_enqueue_script( 'wp-api' );
	}
	wp_register_script( 'ew-claim', '', array( 'ew-qrcode' ), EW_FWP_VERSION, true );
	wp_enqueue_script( 'ew-claim' );
	wp_add_inline_script( 'ew-claim', ew_claim_js() );
}, 22 );
