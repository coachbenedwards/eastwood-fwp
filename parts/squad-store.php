<?php
/**
 * The first-team squad, kept on this site as the club's one list of who is
 * in it.
 *
 * Until now the squad page was built from the league's appearance feed, with
 * numbers and positions held in code. That meant a signing did not exist
 * anywhere until he had played, and every signing or departure had to be
 * typed again into the managers' team sheet and the finance sheet by hand.
 *
 * This keeps the list in one place, edited from the Squad screen in wp-admin:
 * name, squad number, position, and whether he is in the squad, on loan or
 * has left. Three things read it:
 *
 *   - the squad page on this site (numbers, positions, and who has left),
 *   - the managers' team sheet, which takes its player rows from it,
 *   - the finance sheet, which takes squad numbers from it and lists anyone
 *     it does not yet have a wage for.
 *
 * Wages and contracts never live here. They stay on the private finance
 * sheet, matched to this list by name.
 *
 * Rows keep the order they were added in, and a player who leaves stays in
 * the list marked as left rather than being taken out. The managers fill in
 * each game against a row, so a row that moved would carry those entries
 * onto the wrong player.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The three sheets this list feeds, shown on the Squad screen. */
function ew_squad_sheets() {
	return array(
		array( 'Team sheet (the managers fill in each game)', 'https://docs.google.com/spreadsheets/d/1Kbqg3jqr-FxBWIZxVqFQtFCpuKcvOhtDTgTn3DGuKcs/edit' ),
		array( 'First Team Finance (wages, contracts, the 16)', 'https://docs.google.com/spreadsheets/d/1eI6LNpkxA9ySWNAZrvO-MPV4a4wnM_YhpscrICYwF3o/edit' ),
		array( 'P&L v4 MASTER (cash flow and P&L)', 'https://docs.google.com/spreadsheets/d/13yc-93GITeshAE2hW8EPUIvMko3tbve54PEHWD-JTzw/edit' ),
	);
}

/** The positions a player can be listed under. */
function ew_squad_positions() {
	return array(
		''   => '',
		'gk' => 'Goalkeeper',
		'df' => 'Defender',
		'mf' => 'Midfielder',
		'fw' => 'Forward',
	);
}

/**
 * The club's sides, as the Teams page already names them.
 *
 * A player belongs to as many of these as he plays for, so an academy lad
 * who gets into the first team is ticked for both rather than typed in
 * twice. Two copies of a name is how one of them ends up with the wrong
 * number, or on a wage sheet he was never meant to be on.
 *
 * Editable on the Squad screen, so a side the club adds -- a girls' team,
 * a second development side -- needs no code.
 */
function ew_squad_teams() {
	$saved = get_option( 'ew_squad_teams', null );
	if ( is_array( $saved ) && $saved ) {
		return $saved;
	}
	return array(
		'first'       => "Men's First Team",
		'women'       => "Women's First Team",
		'development' => 'Development',
		'u21'         => 'Under 21s',
		'academy'     => 'Academy',
		'grassroots'  => 'Grassroots',
	);
}

/**
 * A slug for a side the club has just named.
 *
 * sanitize_key() drops spaces rather than bridging them, which would turn
 * "Girls Under 15s" into girlsunder15s -- legible enough, but a word break
 * reads better in a URL, so spaces become hyphens first.
 */
function ew_squad_team_slug( $label, $taken = array() ) {
	$slug = strtolower( str_replace( "'", '', (string) $label ) );
	$slug = trim( preg_replace( '/[^a-z0-9]+/', '-', $slug ), '-' );
	if ( '' === $slug ) {
		return '';
	}
	$try = $slug;
	$n   = 2;
	while ( isset( $taken[ $try ] ) ) {
		$try = $slug . '-' . $n;
		++$n;
	}
	return $try;
}

/**
 * The sides a row belongs to.
 *
 * A row written before sides existed is a first-team player, because the
 * first team is the only list there was.
 */
function ew_squad_row_teams( $row ) {
	$teams = $row['teams'] ?? null;
	if ( ! is_array( $teams ) ) {
		return array( 'first' );
	}
	return array_values( array_intersect( array_keys( ew_squad_teams() ), $teams ) );
}

/** What a row can say about a player. */
function ew_squad_statuses() {
	return array(
		'squad' => 'In the squad',
		'loan'  => 'On loan',
		'left'  => 'Left the club',
	);
}

/**
 * The list as it stood when this screen was first opened: the squad numbers
 * and positions that were held in code on 19 October, in the order the
 * managers' team sheet already had its rows, plus the four players signed
 * since who had no entry there.
 */
function ew_squad_seed() {
	$rows = array(
		array( 'Scott Loach', 1, 'gk' ),
		array( 'Charlie Marzano', 2, 'df' ),
		array( 'Louis Nicholson', 3, 'df' ),
		array( 'Ryan Wilson', 8, 'mf' ),
		array( 'Connor Dimaio', 6, 'mf' ),
		array( 'Jacob Pearce', 7, 'mf' ),
		array( 'Curtis Thompson', 4, 'mf' ),
		array( 'Luke Rawson', 10, 'fw' ),
		array( 'Jamie Walker', 11, 'mf' ),
		array( 'Jordan Hallam', 17, 'mf' ),
		array( 'Patrick Webb', 16, 'fw' ),
		array( 'Jake Wright', 12, 'df' ),
		array( 'Tyler Blake', 9, 'fw' ),
		array( 'Tyree Wilson', 14, 'mf' ),
		array( 'Callum Chettle', 23, 'mf' ),
		array( 'Toby Moore', 15, 'df' ),
		array( "Sam O'Rioye", null, '' ),
		array( 'Jack Duffy', null, '' ),
		array( "Ce'an Winn", null, '' ),
		array( 'Keaton August', null, '' ),
		array( 'Tom Marshall', 5, 'df' ),
	);
	$out = array();
	foreach ( $rows as $r ) {
		$out[] = array(
			'name'   => $r[0],
			'number' => $r[1],
			'pos'    => $r[2],
			'status' => 'squad',
		);
	}
	return $out;
}

/** The list, oldest row first. Seeded on first read. */
function ew_squad_list() {
	$list = get_option( 'ew_squad_list', null );
	if ( ! is_array( $list ) ) {
		$list = ew_squad_seed();
		add_option( 'ew_squad_list', $list, '', false );
	}
	return $list;
}

/**
 * The list in the shape ew_squad_details() has always returned:
 * name => array( number, position ). Players marked as left are not in it.
 */
function ew_squad_store_details() {
	$out = array();
	foreach ( ew_squad_list() as $row ) {
		if ( 'left' === ( $row['status'] ?? '' ) ) {
			continue;
		}
		$out[ $row['name'] ] = array(
			isset( $row['number'] ) && '' !== $row['number'] ? (int) $row['number'] : null,
			(string) ( $row['pos'] ?? '' ) !== '' ? (string) $row['pos'] : null,
		);
	}
	return $out;
}

/** The names marked as left, for the squad page to leave off. */
function ew_squad_store_departed() {
	$out = array();
	foreach ( ew_squad_list() as $row ) {
		if ( 'left' === ( $row['status'] ?? '' ) ) {
			$out[] = $row['name'];
		}
	}
	return $out;
}

/* ------------------------------------------------------------------ *
 * The feed the sheets read: plain CSV, one row per player, in list order.
 * Google Sheets reads it with IMPORTDATA. Only what the public squad page
 * already shows is in it.
 * ------------------------------------------------------------------ */

add_action( 'rest_api_init', function () {
	register_rest_route( 'eastwood/v1', '/squad', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => 'ew_squad_feed',
	) );
} );

function ew_squad_feed( $request ) {
	$pos    = ew_squad_positions();
	$status = ew_squad_statuses();
	$teams  = ew_squad_teams();

	// ?team=academy gives that side alone. No team named gives the lot, so
	// anything reading this feed before sides existed reads it unchanged.
	$want = trim( (string) $request->get_param( 'team' ) );
	$want = isset( $teams[ $want ] ) ? $want : '';

	$lines   = array();
	$lines[] = array( 'Name', 'Squad no.', 'Position', 'Status', 'Teams' );
	foreach ( ew_squad_list() as $row ) {
		$in = ew_squad_row_teams( $row );
		if ( '' !== $want && ! in_array( $want, $in, true ) ) {
			continue;
		}
		$lines[] = array(
			$row['name'],
			isset( $row['number'] ) && null !== $row['number'] ? (string) $row['number'] : '',
			$pos[ $row['pos'] ?? '' ] ?? '',
			$status[ $row['status'] ?? 'squad' ] ?? '',
			implode( ', ', array_map( function ( $k ) use ( $teams ) {
				return $teams[ $k ];
			}, $in ) ),
		);
	}

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Cache-Control: no-store, max-age=0' );
	$fh = fopen( 'php://output', 'w' );
	foreach ( $lines as $l ) {
		fputcsv( $fh, $l );
	}
	fclose( $fh );
	exit;
}

/* ------------------------------------------------------------------ *
 * The Squad screen in wp-admin.
 * ------------------------------------------------------------------ */

add_action( 'admin_menu', function () {
	add_menu_page(
		'Squad', 'Squad', 'edit_pages', 'ew-squad',
		'ew_squad_admin_page', 'dashicons-groups', 31
	);
} );

add_action( 'admin_post_ew_squad_save', function () {
	if ( ! current_user_can( 'edit_pages' ) ) {
		wp_die( 'Not allowed.' );
	}
	check_admin_referer( 'ew_squad_save' );

	$pos    = ew_squad_positions();
	$status = ew_squad_statuses();
	$in     = isset( $_POST['ew_squad'] ) ? (array) wp_unslash( $_POST['ew_squad'] ) : array();
	$out    = array();

	// The sides first, because the rows are checked against them.
	//
	// A side keeps the slug it already had, matched on its label, because
	// every player's ticks point at that slug. Minting fresh slugs from the
	// labels on each save would empty every side the first time anybody
	// pressed Save -- so only a line that is genuinely new gets a new slug.
	$was   = ew_squad_teams();
	$byLab = array_flip( $was );
	$sides = array();
	foreach ( preg_split( '/\r\n|\r|\n/', (string) wp_unslash( $_POST['ew_squad_teams'] ?? '' ) ) as $line ) {
		$label = trim( sanitize_text_field( $line ) );
		if ( '' === $label ) {
			continue;
		}
		if ( in_array( $label, $sides, true ) ) {
			continue;   // the same side typed twice is one side, not two
		}
		$slug = $byLab[ $label ] ?? ew_squad_team_slug( $label, $sides );
		if ( '' !== $slug && ! isset( $sides[ $slug ] ) ) {
			$sides[ $slug ] = $label;
		}
	}
	if ( $sides ) {
		update_option( 'ew_squad_teams', $sides, false );
	}
	$teams = ew_squad_teams();

	foreach ( $in as $row ) {
		$row  = (array) $row;
		$name = trim( sanitize_text_field( (string) ( $row['name'] ?? '' ) ) );
		if ( '' === $name ) {
			continue;
		}
		$num = trim( (string) ( $row['number'] ?? '' ) );
		$p   = (string) ( $row['pos'] ?? '' );
		$s   = (string) ( $row['status'] ?? 'squad' );
		$t   = array_values( array_intersect(
			array_keys( $teams ),
			array_map( 'strval', (array) ( $row['teams'] ?? array() ) )
		) );
		$out[] = array(
			'name'   => $name,
			'number' => ( '' !== $num && ctype_digit( $num ) ) ? (int) $num : null,
			'pos'    => isset( $pos[ $p ] ) ? $p : '',
			'status' => isset( $status[ $s ] ) ? $s : 'squad',
			'teams'  => $t,
		);
	}

	update_option( 'ew_squad_list', $out, false );
	if ( function_exists( 'ew_purge_cache' ) ) {
		ew_purge_cache();
	}
	wp_safe_redirect( admin_url( 'admin.php?page=ew-squad&saved=1' ) );
	exit;
} );

function ew_squad_admin_page() {
	if ( ! current_user_can( 'edit_pages' ) ) {
		return;
	}
	$list   = ew_squad_list();
	$pos    = ew_squad_positions();
	$status = ew_squad_statuses();
	$teams  = ew_squad_teams();

	// Three empty rows at the bottom for new signings. Nothing is ticked on
	// them: an existing row with no sides recorded predates them and is a
	// first-team player, but a NEW row left untouched must not quietly
	// become one. Forgetting to tick leaves a player off a list, which is
	// visible; a wrong tick puts an academy lad on a wage sheet, which is
	// not.
	for ( $i = 0; $i < 3; $i++ ) {
		$list[] = array( 'name' => '', 'number' => null, 'pos' => '', 'status' => 'squad', 'teams' => array() );
	}
	?>
	<div class="wrap">
		<h1>Squad</h1>
		<p style="max-width:48em;font-size:14px">
			The first-team squad. A change here reaches the squad page on the
			website, the managers&rsquo; team sheet and the finance sheet.
			<b>When a player leaves, set him to &ldquo;Left the club&rdquo;
			rather than deleting his name</b> &mdash; the managers&rsquo; sheet
			keeps a row for him so nothing already filled in moves.
			New signings go in the empty rows at the bottom.
		</p>

		<h2 style="margin-top:24px">The sheets this feeds</h2>
		<ol style="font-size:14px">
			<?php foreach ( ew_squad_sheets() as $sheet ) : ?>
			<li><a href="<?php echo esc_url( $sheet[1] ); ?>" target="_blank" rel="noopener"><?php
				echo esc_html( $sheet[0] ); ?></a></li>
			<?php endforeach; ?>
		</ol>

		<?php if ( isset( $_GET['saved'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p>Saved. The sheets pick it up within the hour.</p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="ew_squad_save">
			<?php wp_nonce_field( 'ew_squad_save' ); ?>
			<table class="widefat striped" style="max-width:900px;margin-top:18px">
				<thead><tr>
					<th style="width:24%">Name</th>
					<th style="width:8%">Squad no.</th>
					<th style="width:15%">Position</th>
					<th style="width:17%">Status</th>
					<th>Teams</th>
				</tr></thead>
				<tbody>
				<?php foreach ( $list as $i => $row ) : ?>
					<tr>
						<td><input type="text" style="width:100%"
							name="ew_squad[<?php echo (int) $i; ?>][name]"
							value="<?php echo esc_attr( $row['name'] ); ?>"></td>
						<td><input type="number" min="1" max="99" style="width:5em"
							name="ew_squad[<?php echo (int) $i; ?>][number]"
							value="<?php echo esc_attr( null === $row['number'] ? '' : (string) $row['number'] ); ?>"></td>
						<td><select name="ew_squad[<?php echo (int) $i; ?>][pos]">
							<?php foreach ( $pos as $k => $label ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>"<?php
								selected( (string) ( $row['pos'] ?? '' ), $k ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select></td>
						<td><select name="ew_squad[<?php echo (int) $i; ?>][status]">
							<?php foreach ( $status as $k => $label ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>"<?php
								selected( (string) ( $row['status'] ?? 'squad' ), $k ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select></td>
						<td><?php
							$mine = ew_squad_row_teams( $row );
							foreach ( $teams as $k => $label ) : ?>
							<label style="display:inline-block;margin:0 14px 4px 0;white-space:nowrap">
								<input type="checkbox" value="<?php echo esc_attr( $k ); ?>"
									name="ew_squad[<?php echo (int) $i; ?>][teams][]"
									<?php checked( in_array( $k, $mine, true ) ); ?>>
								<?php echo esc_html( $label ); ?>
							</label>
							<?php endforeach; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2 style="margin-top:28px">The club's sides</h2>
			<p style="font-size:14px;max-width:760px">One per line. Adding a side here puts it against
				every player as something to tick. A player is ticked for as many as he plays
				for &mdash; an academy lad in the first team is both, not two rows &mdash; so he keeps
				one squad number and appears on one wage sheet.</p>
			<textarea name="ew_squad_teams" rows="7" style="width:360px;font-size:14px"><?php
				echo esc_textarea( implode( "\n", array_values( $teams ) ) ); ?></textarea>

			<?php submit_button( 'Save squad' ); ?>
		</form>
	</div>
	<?php
}
