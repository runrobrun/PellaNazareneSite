<?php
/**
 * Title: Page: Prayer Request
 * Slug: pella-nazarene/page-prayer
 * Categories: pella-nazarene
 * Inserter: false
 * Description: Prayer request form: emailed to the pastors and not kept on the website. In the look of the home page (FE-6).
 */
?>
<?php
echo pn_page_header( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside pn_page_header().
	'Prayer',
	'We would be honored to pray with you',
	'Share what is on your heart. Our pastors will pray for you.'
);
?>
<!-- wp:group {"align":"full","backgroundColor":"stone","style":{"spacing":{"padding":{"top":"var:preset|spacing|70","bottom":"var:preset|spacing|70"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-stone-background-color has-background" style="padding-top:var(--wp--preset--spacing--70);padding-bottom:var(--wp--preset--spacing--70)">
<!-- wp:columns {"align":"wide"} -->
<div class="wp-block-columns alignwide">
<!-- wp:column {"width":"40%"} -->
<div class="wp-block-column" style="flex-basis:40%">
<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
<p class="is-style-eyebrow">Only our pastors see it</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph -->
<p>Share what is on your heart and our pastors will pray for you. Your request goes only to our pastors, by email, and is not kept on this website. If you would like a reply, include your email address; otherwise we will simply pray.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"fontSize":"small"} -->
<p class="has-small-font-size">If this is an emergency, please call 911. To talk with someone, call the church office at <a href="tel:+16416281818">(641)&nbsp;628-1818</a>.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
<!-- wp:column {"width":"60%"} -->
<div class="wp-block-column" style="flex-basis:60%">
<!-- wp:group {"className":"pn-panel","backgroundColor":"base","layout":{"type":"default"}} -->
<div class="wp-block-group pn-panel has-base-background-color has-background">
<!-- wp:shortcode -->
[pn_form name="prayer"]
<!-- /wp:shortcode -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</div>
<!-- /wp:group -->
