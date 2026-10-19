<?php
/**
 * The written pages, as a block.
 *
 * Eleven pages on this site are the same thing underneath: a title, then
 * headings and paragraphs. Academy, tickets, matchday, the venue, pitch
 * hire, sponsorship, club history, contact, safeguarding, privacy and
 * terms. No machinery, just writing.
 *
 * Their words have been living in this plugin, in a heredoc per page, and
 * that is the wrong home for them: nobody at the club can reach a heredoc.
 * This block moves the words onto the page itself, where the builder can
 * edit them and the command channel can address them, and leaves the shape
 * they sit in here where it belongs -- so a change to how a section looks
 * reaches all eleven at once instead of none of them.
 *
 * The wrapper is drawn once, around the whole body, because the column
 * rule that keeps a line of text readable is written as a direct-child
 * rule: .ew-prose > p. Put each section in its own box and that rule stops
 * matching and every paragraph runs the full width of the page. So this is
 * one block per page rather than one per section -- the words are the
 * field, and the section headings sit inside them.
 *
 * Nested blocks work: the sponsorship page has the gate figures and the
 * sponsor wall in the middle of its prose, and those still render.
 *
 * @package eastwood-fwp
 */

defined( 'ABSPATH' ) || exit;

/**
 * [eastwood_prose title="Academy"] ...the body... [/eastwood_prose]
 *
 * The title is drawn as the page's red band. Left empty, nothing is drawn
 * and the band comes from the filter that handles the pages still rendering
 * their own content, so both ways of reaching a page agree.
 */
function ew_prose_sc( $atts, $content = '' ) {
	$atts = shortcode_atts(
		array( 'title' => '' ),
		$atts,
		'eastwood_prose'
	);

	$body = trim( (string) $content );
	if ( '' === $body ) {
		return '';
	}

	$out = '';
	if ( '' !== trim( (string) $atts['title'] ) ) {
		$out .= '<h1 class="ew-title">' . esc_html( $atts['title'] ) . '</h1>';
	}

	// do_shortcode, because a block inside the writing has to run: the
	// sponsorship page carries the gate figures and the sponsor wall.
	return $out . '<div class="ew-prose">' . "\n\n" . do_shortcode( $body ) . "\n\n" . '</div>';
}
add_shortcode( 'eastwood_prose', 'ew_prose_sc' );
