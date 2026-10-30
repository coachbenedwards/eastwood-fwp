<?php
/**
 * The printed list.
 *
 * Everything sold on the site -- match tickets, food tokens, shirts --
 * ends up as one ewt_booking, so there is one list rather than a list per
 * funnel, and one place to print it from. The Bookings screen already
 * downloads a spreadsheet; this adds the other two things somebody at a
 * gate or behind a bar actually needs: a sheet of paper, and a file to
 * keep.
 *
 * The look is lifted from the OS agenda sheet (agenda-sheet.css.ts in the
 * PodCut front end) rather than invented again, so a list printed out of
 * the website and an agenda printed out of the OS are recognisably the
 * same document: the red rule under the masthead, Bebas Neue over Barlow
 * Condensed, mono for anything that is a figure, hairlines between rows,
 * reference and date along the bottom. The rows use that sheet's own
 * actions grammar -- the back-sheet table that carries a tick box down
 * the right -- because a door list is an actions list.
 *
 * Printing works the way the OS does it: @media print hides the screen
 * around the sheet and promotes the sheet to the page, so File > Print
 * produces the sheet and nothing else. There is no PDF library here and
 * adding one to a plugin that goes through a WAF is not worth the
 * trouble; every print dialog saves a PDF, which is the same thing by a
 * shorter road. "Word" serves the identical sheet as a .doc, which Word
 * and Google Docs both open and edit.
 *
 * One deliberate difference from the agenda: that sheet sets @page margin
 * to nought and pads each fixed A4 block itself, which it can do because
 * it knows how many pages it is. A list does not, so the margin goes on
 * @page and the rows flow, and page two gets the same edge as page one.
 *
 * The Event box above the list matters more than it looks: without it the
 * printed sheet is every booking the club has ever taken. Picking a game
 * or a food event narrows the screen AND what prints, together.
 *
 * @package eastwood-fwp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The event a booking is for, whichever funnel sold it.
 *
 * Match tickets write the fixture into _ewt_event; food passes write the
 * event's name into _ewt_fname at the moment of sale, and the live event
 * can be renamed afterwards, so the pass is asked for its own copy first.
 */
function ew_pl_event_of( $id ) {
	$food = trim( (string) get_post_meta( $id, '_ewt_fname', true ) );
	if ( '' !== $food ) {
		return $food;
	}
	return trim( (string) get_post_meta( $id, '_ewt_event', true ) );
}

/** Every event that has at least one booking against it, A to Z. */
function ew_pl_events() {
	global $wpdb;
	$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"SELECT DISTINCT m.meta_value
		   FROM {$wpdb->postmeta} m
		   JOIN {$wpdb->posts} p ON p.ID = m.post_id
		  WHERE p.post_type = 'ewt_booking'
		    AND m.meta_key IN ( '_ewt_event', '_ewt_fname' )
		    AND m.meta_value <> ''"
	);
	$rows = array_values( array_unique( array_map( 'strval', (array) $rows ) ) );
	sort( $rows, SORT_NATURAL | SORT_FLAG_CASE );
	return $rows;
}

/** A food pass's tokens, or nothing for anything that is not one. */
function ew_pl_tokens( $id ) {
	if ( ! function_exists( 'ew_food_tokens' ) ) {
		return array();
	}
	return (array) ew_food_tokens( (int) $id );
}

/** What a booking bought, in one line. */
function ew_pl_what( $id ) {
	$bits = array();
	foreach ( ew_pl_tokens( $id ) as $t ) {
		$bits[] = (int) $t['n'] . ' × ' . (string) $t['name'];
	}
	if ( ! $bits ) {
		$lines = trim( (string) get_post_meta( $id, '_ewt_lines', true ) );
		if ( '' !== $lines ) {
			$bits[] = $lines;
		}
		$extra = trim( (string) get_post_meta( $id, '_ewt_extra', true ) );
		if ( '' !== $extra ) {
			$bits[] = $extra;
		}
	}
	return implode( ' · ', $bits );
}

/** What the two buttons and the screen are both filtered to. */
function ew_pl_filters() {
	// phpcs:disable WordPress.Security.NonceVerification
	return array(
		'event'  => isset( $_GET['ew_event'] ) ? sanitize_text_field( wp_unslash( $_GET['ew_event'] ) ) : '',
		'status' => isset( $_GET['ew_status'] ) ? sanitize_key( wp_unslash( $_GET['ew_status'] ) ) : '',
	);
	// phpcs:enable WordPress.Security.NonceVerification
}

/** The bookings a printed sheet should carry, in the order to read them. */
function ew_pl_rows( $event, $status ) {
	$args = array(
		'post_type'   => 'ewt_booking',
		'post_status' => 'any',
		'numberposts' => -1,
		'orderby'     => 'title',
		'order'       => 'ASC',
	);
	if ( '' !== $status ) {
		$args['meta_query'] = array( array( 'key' => '_ewt_status', 'value' => $status ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}
	$out = array();
	foreach ( get_posts( $args ) as $b ) {
		// Either key answering is enough, the same test the screen above
		// makes, so the sheet carries exactly what the screen showed.
		if ( '' !== $event
			&& (string) get_post_meta( $b->ID, '_ewt_event', true ) !== $event
			&& (string) get_post_meta( $b->ID, '_ewt_fname', true ) !== $event ) {
			continue;
		}
		$out[] = $b;
	}
	return $out;
}

/* --- The sheet ----------------------------------------------------------- */

/** Where the three faces come from. Mona Sans is not on Google Fonts. */
const EW_PL_FONTS = 'https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Barlow+Condensed:wght@600;700;800&family=Geist+Mono:wght@400;500&display=swap';

/**
 * The house style, ported from AGENDA_SHEET_CSS and scoped under
 * .ewpl-doc so that none of it reaches the admin screen around it.
 */
function ew_pl_css() {
	return <<<'CSS'
@page{size:210mm 297mm;margin:11mm}
.ewpl-doc{
 --bg:#ffffff;--hair:rgba(0,0,0,.11);--hair-2:rgba(0,0,0,.20);--rule:rgba(0,0,0,.42);
 --text:#111111;--muted:#4a4a52;--faint:#6b6b74;--faint-2:#9a9aa3;
 --red:#CC0000;--red-tint:rgba(204,0,0,.045);--fill:rgba(0,0,0,.3);
 --sans:'Mona Sans',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
 --mono:'Geist Mono','SF Mono',ui-monospace,monospace;
 --cond:'Barlow Condensed',sans-serif;
 --display:'Bebas Neue',sans-serif;
}
.ewpl-doc *{margin:0;padding:0;box-sizing:border-box}
.ewpl-doc{background:var(--bg);color:var(--text);font-family:var(--sans);-webkit-font-smoothing:antialiased}
.ewpl-doc .doc-hd{display:grid;grid-template-columns:1fr auto;gap:8mm;align-items:end;
 padding-bottom:3.5mm;border-bottom:2px solid var(--red)}
.ewpl-doc .doc-hd .kind{font-family:var(--cond);font-weight:700;font-size:11px;letter-spacing:.24em;
 text-transform:uppercase;color:var(--faint-2);margin-bottom:1.5mm}
.ewpl-doc .doc-hd .name{font-family:var(--display);font-size:33px;letter-spacing:.03em;line-height:1}
.ewpl-doc .doc-hd .name span{color:var(--red)}
.ewpl-doc .doc-hd .facts{font-family:var(--mono);font-size:9px;color:var(--faint);
 letter-spacing:.05em;text-align:right;line-height:1.95}
.ewpl-doc .doc-hd .facts b{color:var(--text);font-weight:500}
.ewpl-doc .zone{display:flex;align-items:baseline;gap:4mm;flex-wrap:wrap;padding:0 0 1.5mm;
 margin-top:4mm;border-bottom:1.5px solid var(--rule)}
.ewpl-doc .zone .z{font-family:var(--cond);font-weight:800;font-size:13.5px;letter-spacing:.17em;
 text-transform:uppercase;color:var(--text)}
.ewpl-doc .zone .sub{font-size:9.5px;color:var(--faint);flex:1 1 auto;line-height:1.4}
.ewpl-doc .zone .tot{font-family:var(--mono);font-size:9px;color:var(--faint-2);letter-spacing:.08em}
.ewpl-doc .acts{display:flex;flex-direction:column}
.ewpl-doc .acts .ah{display:grid;grid-template-columns:44mm 1fr 24mm 17mm 8mm;gap:0 4.5mm;
 padding:1.8mm 0 1.4mm;border-bottom:1px solid var(--hair-2)}
.ewpl-doc .acts .ah div{font-family:var(--mono);font-size:7.5px;letter-spacing:.14em;
 text-transform:uppercase;color:var(--faint-2)}
.ewpl-doc .acts .ah .r{text-align:right}
.ewpl-doc .acts .ar{display:grid;grid-template-columns:44mm 1fr 24mm 17mm 8mm;gap:0 4.5mm;
 padding:2.4mm 0;border-bottom:1px solid var(--hair);align-items:baseline;min-height:9mm;
 break-inside:avoid;page-break-inside:avoid}
.ewpl-doc .acts .ar .ow{font-size:11px;font-weight:600;line-height:1.3;min-width:0;overflow-wrap:anywhere}
.ewpl-doc .acts .ar .ow em{font-style:normal;font-family:var(--mono);font-size:8px;color:var(--faint-2);
 display:block;margin-top:.6mm;letter-spacing:.03em}
.ewpl-doc .acts .ar .ac{font-size:10.5px;color:var(--muted);line-height:1.4;min-width:0;overflow-wrap:anywhere}
.ewpl-doc .acts .ar .by{font-family:var(--mono);font-size:9px;color:var(--red);letter-spacing:.03em}
.ewpl-doc .acts .ar .mi{font-family:var(--mono);font-size:9.5px;color:var(--text);text-align:right}
.ewpl-doc .acts .ar .dn{justify-self:end;width:4mm;height:4mm;border:1px solid var(--hair-2)}
.ewpl-doc .acts .ar.unpaid{background:var(--red-tint)}
.ewpl-doc .acts .ar.unpaid .mi{color:var(--red)}
.ewpl-doc .acts .none{font-size:10.5px;color:var(--faint);padding:4mm 0}
.ewpl-doc .ft{padding-top:3mm;margin-top:4mm;border-top:1px solid var(--hair);
 display:flex;justify-content:space-between;align-items:baseline;gap:14px;
 font-family:var(--mono);font-size:8.5px;color:var(--faint-2);letter-spacing:.06em}
.ewpl-doc .ft b{color:var(--faint);font-weight:500}
CSS;
}

/** The sheet itself: masthead, one zone, the rows, the footer. */
function ew_pl_sheet( $rows, $event, $status ) {
	$title = '' !== $event ? $event : 'All bookings';
	$when  = wp_date( 'j F Y, H:i' );
	$paid  = 0;
	foreach ( $rows as $b ) {
		$paid += (int) get_post_meta( $b->ID, '_ewt_paid', true );
	}
	$money = function ( $p ) {
		return function_exists( 'ew_tix_money' ) ? ew_tix_money( $p ) : '£' . number_format( $p / 100, 2 );
	};

	$h  = '<div class="ewpl-doc">';
	$h .= '<div class="doc-hd"><div><p class="kind">Eastwood FC &middot; Bookings</p>'
		. '<p class="name">' . esc_html( $title ) . '</p></div>'
		. '<div class="facts"><b>' . count( $rows ) . '</b> booking' . ( 1 === count( $rows ) ? '' : 's' ) . '<br>'
		. ( '' !== $status ? '<b>' . esc_html( ucfirst( $status ) ) . '</b> only<br>' : '' )
		. esc_html( $when ) . '</div></div>';

	$h .= '<div class="zone"><span class="z">The list</span>'
		. '<span class="sub">Alphabetical by name. Tick them off as they come in.</span>'
		. '<span class="tot">' . esc_html( $money( $paid ) ) . ' taken</span></div>';

	$h .= '<div class="acts"><div class="ah"><div>Name</div><div>What</div><div>Code</div>'
		. '<div class="r">Paid</div><div></div></div>';

	if ( ! $rows ) {
		$h .= '<p class="none">Nothing sold against this yet.</p>';
	}
	foreach ( $rows as $b ) {
		$st    = (string) get_post_meta( $b->ID, '_ewt_status', true );
		$pence = (int) get_post_meta( $b->ID, '_ewt_paid', true );
		$name  = (string) get_post_meta( $b->ID, '_ewt_name', true );
		$email = (string) get_post_meta( $b->ID, '_ewt_email', true );
		$h    .= '<div class="ar' . ( 'paid' === $st ? '' : ' unpaid' ) . '">';
		$h    .= '<div class="ow">' . esc_html( '' !== $name ? $name : $b->post_title )
			. ( '' !== $email ? '<em>' . esc_html( $email ) . '</em>' : '' ) . '</div>';
		$h    .= '<div class="ac">' . esc_html( ew_pl_what( $b->ID ) ) . '</div>';
		$h    .= '<div class="by">' . esc_html( (string) get_post_meta( $b->ID, '_ewt_key', true ) ) . '</div>';
		$h    .= '<div class="mi">' . esc_html( $pence ? $money( $pence ) : ucfirst( $st ) ) . '</div>';
		$h    .= '<div class="dn"></div>';
		$h    .= '</div>';
	}
	$h .= '</div>';

	$h .= '<div class="ft"><span>Eastwood Community Football Club &middot; <b>bookings</b> &middot; printed from the website</span>'
		. '<span>' . esc_html( $when ) . '</span></div>';
	return $h . '</div>';
}

/* --- The boxes and the buttons above the Bookings list ------------------ */

add_action( 'restrict_manage_posts', function ( $type ) {
	if ( 'ewt_booking' !== $type ) {
		return;
	}
	$f = ew_pl_filters();

	echo '<select name="ew_event"><option value="">Every event</option>';
	foreach ( ew_pl_events() as $e ) {
		echo '<option value="' . esc_attr( $e ) . '"' . selected( $f['event'], $e, false ) . '>' . esc_html( $e ) . '</option>';
	}
	echo '</select> ';

	echo '<select name="ew_status"><option value="">Any status</option>';
	foreach ( array( 'paid' => 'Paid', 'started' => 'Started' ) as $k => $label ) {
		echo '<option value="' . esc_attr( $k ) . '"' . selected( $f['status'], $k, false ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select> ';

	// Print stays on this screen -- the sheet is rendered into it and the
	// screen around it is hidden at print time, the way the OS does it.
	$here = add_query_arg(
		array(
			'post_type' => 'ewt_booking',
			'ew_event'  => $f['event'],
			'ew_status' => $f['status'],
			'ew_print'  => '1',
		),
		admin_url( 'edit.php' )
	);
	$doc = wp_nonce_url(
		add_query_arg(
			array(
				'action'    => 'ew_print_bookings',
				'ew_event'  => $f['event'],
				'ew_status' => $f['status'],
			),
			admin_url( 'admin-post.php' )
		),
		'ew_print'
	);
	echo '<a class="button" href="' . esc_url( $here ) . '">Print list</a> ';
	echo '<a class="button" href="' . esc_url( $doc ) . '">Download Word</a> ';
}, 20 );

/** Narrow the screen itself to the event picked above it. */
add_action( 'pre_get_posts', function ( $q ) {
	if ( ! is_admin() || ! $q->is_main_query() || 'ewt_booking' !== $q->get( 'post_type' ) ) {
		return;
	}
	$f = ew_pl_filters();
	if ( '' !== $f['status'] ) {
		$q->set( 'meta_query', array( array( 'key' => '_ewt_status', 'value' => $f['status'] ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}
	if ( '' === $f['event'] ) {
		return;
	}
	// A food pass and a match ticket keep the event under different keys,
	// so both are asked, and either answering is enough.
	$meta   = (array) $q->get( 'meta_query' );
	$meta[] = array(
		'relation' => 'OR',
		array( 'key' => '_ewt_event', 'value' => $f['event'] ),
		array( 'key' => '_ewt_fname', 'value' => $f['event'] ),
	);
	$q->set( 'meta_query', $meta ); // phpcs:ignore WordPress.DB.SlowDBQuery
} );

/** Is this the Bookings screen, asked to print? */
function ew_pl_printing() {
	// phpcs:ignore WordPress.Security.NonceVerification
	if ( ! is_admin() || empty( $_GET['ew_print'] ) || ! function_exists( 'get_current_screen' ) ) {
		return false;
	}
	$screen = get_current_screen();
	return $screen && 'ewt_booking' === $screen->post_type && 'edit' === $screen->base;
}

/* --- Printing in place -------------------------------------------------- */

add_action( 'admin_head', function () {
	if ( ! ew_pl_printing() ) {
		return;
	}
	echo '<link rel="stylesheet" href="' . esc_url( EW_PL_FONTS ) . '">';
	echo '<style>' . ew_pl_css() . "\n"; // phpcs:ignore
	// The sheet sits on the screen as a preview, and at print time the
	// screen goes and the sheet becomes the page.
	echo '.ewpl-print-root{background:#fff;padding:24px;margin:20px 20px 20px 0;border:1px solid #c3c4c7}';
	echo '@media print{body > *{display:none !important}';
	echo 'body .ewpl-print-root{display:block !important;position:absolute;inset:0;margin:0;padding:0;border:0}';
	echo '.ewpl-doc *{-webkit-print-color-adjust:exact;print-color-adjust:exact}}';
	echo '</style>';
} );

add_action( 'admin_footer', function () {
	if ( ! ew_pl_printing() ) {
		return;
	}
	$f = ew_pl_filters();
	echo '<div class="ewpl-print-root">' . ew_pl_sheet( ew_pl_rows( $f['event'], $f['status'] ), $f['event'], $f['status'] ) . '</div>'; // phpcs:ignore
	echo '<script>window.addEventListener("load",function(){setTimeout(function(){window.print();},350);});</script>';
} );

/* --- The same sheet, as a file to keep ---------------------------------- */

add_action( 'admin_post_ew_print_bookings', function () {
	if ( ! current_user_can( 'edit_posts' ) || ! check_admin_referer( 'ew_print' ) ) {
		wp_die( 'Not allowed.' );
	}
	$f     = ew_pl_filters();
	$rows  = ew_pl_rows( $f['event'], $f['status'] );
	$slug  = sanitize_title( '' !== $f['event'] ? $f['event'] : 'all-bookings' );

	header( 'Content-Type: application/msword; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $slug . '-' . gmdate( 'Y-m-d' ) . '.doc"' );

	echo '<!doctype html><html><head><meta charset="utf-8">';
	echo '<title>' . esc_html( '' !== $f['event'] ? $f['event'] : 'All bookings' ) . '</title>';
	echo '<link rel="stylesheet" href="' . esc_url( EW_PL_FONTS ) . '">';
	echo '<style>body{margin:0}' . ew_pl_css() . '</style></head><body>'; // phpcs:ignore
	echo ew_pl_sheet( $rows, $f['event'], $f['status'] ); // phpcs:ignore
	echo '</body></html>';
	exit;
} );
