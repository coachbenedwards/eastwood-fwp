<?php
/**
 * Template Name: Full width — no margins
 *
 * Used by the front page and anything else that has to reach the edges of
 * the window. The theme's page.php puts content in a 900px column inside
 * the editor's wrapper, which is right for a text page and impossible for
 * a hero. This renders the same editable page content with nothing round
 * it, so the blocks can be full bleed and still be edited in WordPress.
 */

get_header();
?>
<main class="ew-full">
<?php
while ( have_posts() ) {
	the_post();
	the_content();
}
?>
</main>
<?php
get_footer();
