<?php
/**
 * Title: Page: Contact
 * Slug: pella-nazarene/page-contact
 * Categories: pella-nazarene
 * Inserter: false
 * Description: Contact form, phone, email, office hours and directions. In the look of the home page (FE-6).
 */
?>
<?php
echo pn_page_header( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside pn_page_header().
	'Contact',
	'We would love to hear from you',
	'Send a message, call the office, or stop by. We’ll get back to you as soon as we can.'
);
?>
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|70","bottom":"var:preset|spacing|70"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--70);padding-bottom:var(--wp--preset--spacing--70)">
<!-- wp:columns {"align":"wide"} -->
<div class="wp-block-columns alignwide">
<!-- wp:column {"width":"58%"} -->
<div class="wp-block-column" style="flex-basis:58%">
<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
<p class="is-style-eyebrow">Write to us</p>
<!-- /wp:paragraph -->
<!-- wp:heading -->
<h2 class="wp-block-heading">Send us a message</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>We read every message and will get back to you as soon as we can. We keep messages for a year, then delete them.</p>
<!-- /wp:paragraph -->
<!-- wp:shortcode -->
[pn_form name="contact"]
<!-- /wp:shortcode -->
<!-- wp:paragraph -->
<p>Need prayer? <a href="/prayer/">Send a prayer request</a>.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
<!-- wp:column {"width":"42%"} -->
<div class="wp-block-column" style="flex-basis:42%">
<!-- wp:group {"className":"pn-panel","backgroundColor":"stone","layout":{"type":"default"}} -->
<div class="wp-block-group pn-panel has-stone-background-color has-background">
<!-- wp:heading -->
<h2 class="wp-block-heading">Contact info</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p><strong>Address:</strong> 303 University Street, Pella, IA&nbsp;50219</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph -->
<p><strong>Phone:</strong> <a href="tel:+16416281818">(641)&nbsp;628-1818</a></p>
<!-- /wp:paragraph -->
<!-- wp:paragraph -->
<p><strong>Email:</strong> <a href="mailto:pellanazarene@gmail.com">pellanazarene@gmail.com</a></p>
<!-- /wp:paragraph -->
<!-- wp:heading -->
<h2 class="wp-block-heading">When we’re in the office</h2>
<!-- /wp:heading -->
<!-- wp:template-part {"slug":"service-info","theme":"pella-nazarene"} /-->
<!-- wp:buttons -->
<div class="wp-block-buttons">
<!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="https://www.google.com/maps/search/?api=1&amp;query=303+University+Street+Pella+IA+50219">View map &amp; directions</a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->
<!-- wp:heading -->
<h2 class="wp-block-heading">Follow us</h2>
<!-- /wp:heading -->
<!-- wp:social-links {"iconColor":"accent-3","iconColorValue":"#2D3D54","className":"is-style-logos-only"} -->
<ul class="wp-block-social-links has-icon-color is-style-logos-only">
<!-- wp:social-link {"url":"https://www.facebook.com/PellaNazarene","service":"facebook","label":"Facebook"} /-->
<!-- wp:social-link {"url":"https://www.instagram.com/pellanazarene","service":"instagram","label":"Instagram"} /-->
<!-- wp:social-link {"url":"https://www.youtube.com/@pellanazarene","service":"youtube","label":"YouTube"} /-->
</ul>
<!-- /wp:social-links -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</div>
<!-- /wp:group -->
