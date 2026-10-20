<?php
/**
 * FA Full-Time as a second fixture source.
 *
 * Football Web Pages carries the United Counties League and so only ever
 * knew about the first team. The Development side and the two academy
 * sides play in leagues that publish on FA Full-Time instead, which has
 * no feed of any kind -- no calendar, no RSS, nothing to subscribe to --
 * so their pages are read directly.
 *
 * What comes out of here is shaped exactly like a Football Web Pages
 * match and then handed through ew_fwp_corrections(), which is the same
 * door every other fixture goes through. That matters: the Fixtures
 * screen can move, re-time or drop one of these games exactly as it can
 * a first-team game, and nothing downstream needs to know which league a
 * fixture came from.
 *
 * @package eastwood-fwp
 */

defined( 'ABSPATH' ) || exit;

/**
 * The sides we read from Full-Time, and where each one lives.
 *
 * `team` is the club name exactly as that league writes it, because the
 * three leagues do not agree with each other: the academy first side is
 * plain "Eastwood CFC" in the Super League while its second side is
 * "Eastwood CFC U19 Red" in Championship North. Matching on the word
 * Eastwood alone would also catch Eastwood & Kimberley, who are a
 * different club in the Development side division.
 *
 * Names here are written as the league writes them, CFC and all.
 * ew_fix_club_name() turns them into Eastwood FC on the way out.
 */
function ew_ft_sources() {
	return array(
		'development' => array(
			'label'    => 'Development',
			'team'     => 'Eastwood CFC Development',
			'league'   => '1854955',
			'season'   => '556342931',
			'division' => '195494265',
			'comp'     => 'Central Midlands Alliance',
		),
		'academy'     => array(
			'label'    => 'Academy',
			'team'     => 'Eastwood CFC',
			'league'   => '372178304',
			'season'   => '620209967',
			'division' => '682157962',
			'comp'     => 'Future Lions Super League',
		),
		'academy-u19' => array(
			'label'    => 'Academy U19 Red',
			'team'     => 'Eastwood CFC U19 Red',
			'league'   => '372178304',
			'season'   => '620209967',
			'division' => '207394627',
			'comp'     => 'Future Lions Championship North',
		),
	);
}

/** The division fixtures page for one source. */
function ew_ft_url( $src ) {
	return add_query_arg(
		array(
			'league'                      => $src['league'],
			'selectedSeason'              => $src['season'],
			'selectedDivision'            => $src['division'],
			'selectedCompetition'         => '0',
			'selectedFixtureGroupAgeGroup' => '0',
		),
		'https://fulltime.thefa.com/fixtures.html'
	);
}

/**
 * Full-Time\'s own id for a fixture, which every cell in the row links to.
 *
 * It is not an attribute on the row, which is why it looks at first as
 * though these fixtures have no id at all; it is in the href of the
 * links inside the cells, as /displayFixture.html?id=30791871. Using
 * theirs rather than inventing one means a correction typed on the
 * Fixtures screen stays attached to the right game however the league
 * moves it about.
 */
function ew_ft_row_id( $row ) {
	foreach ( $row->getElementsByTagName( 'a' ) as $a ) {
		if ( preg_match( '/displayFixture\.html\?id=(\d+)/', (string) $a->getAttribute( 'href' ), $m ) ) {
			return (int) $m[1];
		}
	}
	return 0;
}

/**
 * The fallback id, for a row whose link has gone.
 *
 * Built from the division and the two clubs and deliberately NOT the
 * date, because a moved game is the commonest correction there is and
 * keying on the date would detach the correction at the moment it was
 * needed. In a league division an ordered pair of clubs meets once a
 * season, so it is unique within one.
 *
 * The 97xxxxx band sits clear of the feed ids and of the 99xxxx ids
 * used for games added by hand.
 */
function ew_ft_id( $src, $home, $away ) {
	$key = $src['division'] . '|' . strtolower( $home ) . '|' . strtolower( $away );
	return 9700000 + ( crc32( $key ) % 99999 );
}

/**
 * A club id for the crest lookup, in a band of our own.
 *
 * Full-Time clubs are not in the feed numbering, and a club with no
 * crest file falls back to the initials disc, so an invented id costs
 * nothing and lets a crest be dropped in later under this name.
 */
function ew_ft_club_id( $name ) {
	return 990200 + ( crc32( strtolower( trim( $name ) ) ) % 9000 );
}

/** The text of one cell, whitespace collapsed. */
function ew_ft_cell( $row, $i ) {
	$tds = $row->getElementsByTagName( 'td' );
	if ( $i >= $tds->length ) {
		return '';
	}
	return trim( preg_replace( '/\s+/', ' ', $tds->item( $i )->textContent ) );
}

/**
 * Turn a division fixtures page into matches for one of our sides.
 *
 * Only rows involving the named club are kept -- the page lists the
 * whole division.
 */
function ew_ft_parse( $html, $src ) {
	if ( '' === trim( (string) $html ) ) {
		return array();
	}

	$doc = new DOMDocument();
	$prev = libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
	libxml_clear_errors();
	libxml_use_internal_errors( $prev );

	$xp   = new DOMXPath( $doc );
	$rows = $xp->query( '//tr[td[contains(@class,"home-team")]]' );
	$want = strtolower( $src['team'] );
	$out  = array();

	foreach ( $rows as $row ) {
		$home = ew_ft_cell( $row, 2 );
		$away = ew_ft_cell( $row, 6 );
		if ( strtolower( $home ) !== $want && strtolower( $away ) !== $want ) {
			continue;
		}

		// "04/11/26 11:00". A kick-off that has not been set yet comes
		// through as 00:00, which is not midnight, it is unknown -- so it
		// is left empty and the fixture reads as time to be confirmed
		// until somebody sets it on the Fixtures screen.
		$when = ew_ft_cell( $row, 1 );
		if ( ! preg_match( '#(\d{2})/(\d{2})/(\d{2})\D*?(\d{2}):(\d{2})#', $when, $m ) ) {
			continue;
		}
		$date = '20' . $m[3] . '-' . $m[2] . '-' . $m[1];
		$time = ( '00' === $m[4] && '00' === $m[5] ) ? '' : $m[4] . ':' . $m[5];

		// "VS" before kick-off, otherwise the score.
		$score = ew_ft_cell( $row, 4 );
		$hs    = '';
		$as    = '';
		$done  = false;
		if ( preg_match( '/^(\d+)\s*-\s*(\d+)$/', $score, $s ) ) {
			$hs   = (int) $s[1];
			$as   = (int) $s[2];
			$done = true;
		}

		$id = ew_ft_row_id( $row );

		$out[] = array(
			'id'          => $id ? $id : ew_ft_id( $src, $home, $away ),
			'date'        => $date,
			'time'        => $time,
			'status'      => array( 'short' => $done ? 'FT' : '' ),
			'venue'       => ew_fix_club_name( ew_ft_cell( $row, 7 ) ),
			'competition' => array( 'id' => 0, 'name' => $src['comp'] ),
			'home-team'   => array(
				'id'    => ew_ft_club_id( $home ),
				'name'  => $home,
				'score' => $hs,
			),
			'away-team'   => array(
				'id'    => ew_ft_club_id( $away ),
				'name'  => $away,
				'score' => $as,
			),
		);
	}

	usort( $out, function ( $a, $b ) {
		return strcmp( $a['date'] . $a['time'], $b['date'] . $b['time'] );
	} );

	return $out;
}

/**
 * One side's fixtures, cached, corrected, ready to render.
 *
 * The cache holds the page as it arrived and the corrections go on
 * afterwards, the same way round as the Football Web Pages reader, so a
 * correction typed on the Fixtures screen shows immediately instead of
 * waiting for the cache to turn over.
 *
 * @param string $key One of the keys in ew_ft_sources().
 * @param bool   $raw True to skip the corrections layer.
 * @return array Matches, or empty when the page could not be read.
 */
function ew_ft_matches( $key, $raw = false ) {
	$sources = ew_ft_sources();
	if ( ! isset( $sources[ $key ] ) ) {
		return array();
	}
	$src = $sources[ $key ];

	$cache = 'ew_ft_' . md5( ew_ft_url( $src ) );
	$html  = get_transient( $cache );

	if ( false === $html ) {
		$res = wp_remote_get( ew_ft_url( $src ), array(
			'timeout' => 15,
			'headers' => array( 'Accept' => 'text/html' ),
		) );
		$html = ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) )
			? wp_remote_retrieve_body( $res )
			: '';
		// A page that did not come back is retried soon; one that did is
		// left alone for a while, the same shape as the other reader.
		set_transient( $cache, $html, '' === $html ? 5 * MINUTE_IN_SECONDS : 30 * MINUTE_IN_SECONDS );
	}

	$matches = ew_ft_parse( $html, $src );
	return $raw ? $matches : ew_fwp_corrections( $matches );
}

/**
 * What the Fixtures screen shows about this source.
 *
 * Full-Time has no feed and no contract with us, so the honest thing is
 * to say on screen whether the last read worked and what came back,
 * rather than letting an empty tab be the only sign that their markup
 * moved.
 */
function ew_ft_report() {
	$out = array();
	foreach ( ew_ft_sources() as $key => $src ) {
		$matches = ew_ft_matches( $key );
		$next    = '';
		$today   = current_time( 'Y-m-d' );
		foreach ( $matches as $m ) {
			if ( $m['date'] >= $today ) {
				$next = $m['date'] . ( '' !== $m['time'] ? ' ' . $m['time'] : ' (time to be confirmed)' )
					. ' — ' . $m['home-team']['name'] . ' v ' . $m['away-team']['name'];
				break;
			}
		}
		$out[ $key ] = array(
			'label' => $src['label'],
			'comp'  => $src['comp'],
			'found' => count( $matches ),
			'next'  => $next,
		);
	}
	return $out;
}
