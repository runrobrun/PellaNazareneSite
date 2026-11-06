<?php
/**
 * Title: Page: Small Groups
 * Slug: pella-nazarene/page-small-groups
 * Categories: pella-nazarene
 * Inserter: false
 * Description: What small groups are and how to join one through Church Center. In the look of the home page (FE-6).
 */

defined( 'ABSPATH' ) || exit;
?>
<?php
echo pn_page_header( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside pn_page_header().
	'Small groups',
	'Find your people',
	'Life is better when we don’t do it alone.'
);
?>
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|70","bottom":"var:preset|spacing|70"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--70);padding-bottom:var(--wp--preset--spacing--70)">
<!-- wp:columns {"verticalAlignment":"center","align":"wide"} -->
<div class="wp-block-columns alignwide are-vertically-aligned-center">
<!-- wp:column {"verticalAlignment":"center"} -->
<div class="wp-block-column is-vertically-aligned-center">
<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
<p class="is-style-eyebrow">Sundays at 9:30 AM</p>
<!-- /wp:paragraph -->
<!-- wp:heading -->
<h2 class="wp-block-heading">Find a group for you</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>Small groups are where people belong, build relationships, and grow together. Our groups meet on Sunday mornings at 9:30&nbsp;AM, just before worship at 10:30&nbsp;AM. You can see the groups, read about each one, and join right from Church Center.</p>
<!-- /wp:paragraph -->
<!-- wp:buttons -->
<div class="wp-block-buttons">
<!-- wp:button {"backgroundColor":"rust","textColor":"base"} -->
<div class="wp-block-button"><a class="wp-block-button__link has-base-color has-rust-background-color has-text-color has-background wp-element-button" href="https://pella-nazarene.churchcenter.com/groups">Find a group</a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->
</div>
<!-- /wp:column -->
<!-- wp:column {"verticalAlignment":"center"} -->
<div class="wp-block-column is-vertically-aligned-center">
<?php echo pn_photo_block( 'about-community', 'half', '4/3' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside pn_photo_block(). ?>
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</div>
<!-- /wp:group -->
<!-- wp:group {"align":"full","backgroundColor":"stone","style":{"spacing":{"padding":{"top":"var:preset|spacing|70","bottom":"var:preset|spacing|70"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-stone-background-color has-background" style="padding-top:var(--wp--preset--spacing--70);padding-bottom:var(--wp--preset--spacing--70)">
<!-- wp:group {"layout":{"type":"constrained"}} -->
<div class="wp-block-group">
<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
<p class="is-style-eyebrow">We’ll help</p>
<!-- /wp:paragraph -->
<!-- wp:heading -->
<h2 class="wp-block-heading">Not sure where you fit?</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>That’s okay. Fill out a Connect card when you arrive on Sunday, or talk with one of our staff, and we’ll help you find a group where you can belong. You can also <a href="/contact/">contact us</a> any time.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph -->
<p>New to Pella Nazarene? <a href="/new-here/">Here’s what to expect on a Sunday</a>.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:group -->
