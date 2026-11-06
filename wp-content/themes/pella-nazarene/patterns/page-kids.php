<?php
/**
 * Title: Page: Kids
 * Slug: pella-nazarene/page-kids
 * Categories: pella-nazarene
 * Inserter: false
 * Description: Kids ministry: safety, first visit, what we teach, pre-registration. In the look of the home page (FE-6).
 */
?>
<?php
echo pn_page_header( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside pn_page_header().
	'Kids',
	'A place kids love',
	'Your kids will love their time at Pella Nazarene Kids!'
);
?>
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|70","bottom":"var:preset|spacing|70"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--70);padding-bottom:var(--wp--preset--spacing--70)">
<!-- wp:columns {"verticalAlignment":"center","align":"wide"} -->
<div class="wp-block-columns alignwide are-vertically-aligned-center">
<!-- wp:column {"verticalAlignment":"center"} -->
<div class="wp-block-column is-vertically-aligned-center">
<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
<p class="is-style-eyebrow">Birth through age 12</p>
<!-- /wp:paragraph -->
<!-- wp:heading -->
<h2 class="wp-block-heading">About Pella Nazarene Kids</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>Our Kids Ministry is a safe, joyful place where children from birth through age 12 are loved right where they are and encouraged to grow in their faith. Everything we do reflects our mission to love people where they are and grow together in Jesus, using age-appropriate teaching, worship, and hands-on activities. We create fun, engaging environments where kids can discover who God is and how much He loves them. Our caring volunteers partner with parents to help lay a strong foundation of faith at every stage. We can’t wait to welcome your family and grow together with your kids.</p>
<!-- /wp:paragraph -->
<!-- wp:buttons -->
<div class="wp-block-buttons">
<!-- wp:button {"backgroundColor":"rust","textColor":"base"} -->
<div class="wp-block-button"><a class="wp-block-button__link has-base-color has-rust-background-color has-text-color has-background wp-element-button" href="/contact/">Contact us to pre-register</a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->
</div>
<!-- /wp:column -->
<!-- wp:column {"verticalAlignment":"center"} -->
<div class="wp-block-column is-vertically-aligned-center">
<?php echo pn_photo_block( 'kids-welcome', 'half', '4/3' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside pn_photo_block(). ?>
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</div>
<!-- /wp:group -->
<!-- wp:group {"align":"full","backgroundColor":"stone","style":{"spacing":{"padding":{"top":"var:preset|spacing|70","bottom":"var:preset|spacing|70"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-stone-background-color has-background" style="padding-top:var(--wp--preset--spacing--70);padding-bottom:var(--wp--preset--spacing--70)">
<!-- wp:group {"align":"wide","layout":{"type":"default"}} -->
<div class="wp-block-group alignwide">
<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
<p class="is-style-eyebrow">Sunday mornings</p>
<!-- /wp:paragraph -->
<!-- wp:heading -->
<h2 class="wp-block-heading">What to expect</h2>
<!-- /wp:heading -->
</div>
<!-- /wp:group -->
<!-- wp:group {"align":"wide","className":"pn-tiles","layout":{"type":"grid","columnCount":2,"minimumColumnWidth":"20rem"}} -->
<div class="wp-block-group alignwide pn-tiles">
<!-- wp:group {"className":"pn-tile","backgroundColor":"base","layout":{"type":"default"}} -->
<div class="wp-block-group pn-tile has-base-background-color has-background">
<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Your first visit</h3>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>Visiting us for the first time? No worries, we’re glad you’re here! When you arrive, a friendly volunteer will help you find Kids Check-In and walk you through the process. We’ll get your child checked into a safe, age-appropriate classroom and answer any questions you have along the way. Our goal is to help your family feel welcomed, comfortable, and confident from the moment you arrive. We can’t wait to meet you and your kids!</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
<!-- wp:group {"className":"pn-tile","backgroundColor":"base","layout":{"type":"default"}} -->
<div class="wp-block-group pn-tile has-base-background-color has-background">
<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Safety</h3>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>Your child’s safety is our top priority in Kids Ministry. All volunteers and team members complete background checks and receive regular training, including CPR certification where required. We use a secure check-in and check-out system to ensure children are released only to approved adults. Classrooms are age-appropriate, well-staffed, and monitored by trusted leaders who are committed to providing a safe and caring environment. Our team works diligently so you can worship with peace of mind, knowing your child is in good hands.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:group -->
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|70","bottom":"var:preset|spacing|70"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--70);padding-bottom:var(--wp--preset--spacing--70)">
<!-- wp:group {"align":"wide","layout":{"type":"default"}} -->
<div class="wp-block-group alignwide">
<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
<p class="is-style-eyebrow">Every week</p>
<!-- /wp:paragraph -->
<!-- wp:heading -->
<h2 class="wp-block-heading">What we teach</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>Your kids will experience an age-appropriate lesson that will teach them about God and His love for them. The teaching is interactive and uses multiple approaches for different learning styles. The goal at Pella Nazarene is for kids to understand who Jesus is and how He can make a difference in their lives. They will also have a lot of fun!</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
<!-- wp:columns {"align":"wide"} -->
<div class="wp-block-columns alignwide">
<!-- wp:column -->
<div class="wp-block-column">
<?php echo pn_photo_block( 'kids-celebrate', 'col', '4/3' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside pn_photo_block(). ?>
</div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column">
<?php echo pn_photo_block( 'kids-play', 'col', '4/3' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside pn_photo_block(). ?>
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</div>
<!-- /wp:group -->
<!-- wp:group {"align":"full","className":"pn-band-navy","backgroundColor":"accent-3","textColor":"base","style":{"spacing":{"padding":{"top":"var:preset|spacing|70","bottom":"var:preset|spacing|70"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull pn-band-navy has-base-color has-accent-3-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--70);padding-bottom:var(--wp--preset--spacing--70)">
<!-- wp:group {"layout":{"type":"constrained"}} -->
<div class="wp-block-group">
<!-- wp:paragraph {"className":"is-style-eyebrow","textColor":"rust-light"} -->
<p class="is-style-eyebrow has-rust-light-color has-text-color">Before you come</p>
<!-- /wp:paragraph -->
<!-- wp:heading -->
<h2 class="wp-block-heading">Pre-register your kids</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>We’re raising up the next generation of world changers to love God and His house! Let us know you’re coming and we’ll have everything ready for your family.</p>
<!-- /wp:paragraph -->
<!-- wp:buttons -->
<div class="wp-block-buttons">
<!-- wp:button {"backgroundColor":"rust","textColor":"base"} -->
<div class="wp-block-button"><a class="wp-block-button__link has-base-color has-rust-background-color has-text-color has-background wp-element-button" href="/contact/">Contact us to pre-register</a></div>
<!-- /wp:button -->
<!-- wp:button {"backgroundColor":"base","textColor":"accent-3"} -->
<div class="wp-block-button"><a class="wp-block-button__link has-accent-3-color has-base-background-color has-text-color has-background wp-element-button" href="/new-here/#plan-your-visit">Plan a Visit</a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:group -->
