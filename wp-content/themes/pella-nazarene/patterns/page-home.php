<?php
/**
 * Title: Page: Home
 * Slug: pella-nazarene/page-home
 * Categories: pella-nazarene
 * Inserter: false
 * Description: The home page, laid out after the approved homepage mockup (FE-5). Create the page with scripts/create-pages.sh.
 */
?>
<!-- wp:group {"align":"full","className":"pn-hero","backgroundColor":"accent-3","textColor":"base","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull pn-hero has-base-color has-accent-3-background-color has-text-color has-background">
<?php echo pn_photo_block( 'home-hero', 'hero' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside pn_photo_block(). ?>
<!-- wp:group {"align":"wide","className":"pn-hero__content","layout":{"type":"default"}} -->
<div class="wp-block-group alignwide pn-hero__content">
<!-- wp:paragraph {"className":"is-style-eyebrow","textColor":"rust-light"} -->
<p class="is-style-eyebrow has-rust-light-color has-text-color">Welcome to Pella Nazarene</p>
<!-- /wp:paragraph -->
<!-- wp:heading {"level":1,"className":"pn-hero__title"} -->
<h1 class="wp-block-heading pn-hero__title">Loving people. <br>Growing together.</h1>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"pn-hero__lead","fontSize":"medium"} -->
<p class="pn-hero__lead has-medium-font-size">A Church of the Nazarene family in Pella, Iowa. Come as you are and find a place to belong.</p>
<!-- /wp:paragraph -->
<!-- wp:buttons -->
<div class="wp-block-buttons">
<!-- wp:button {"backgroundColor":"rust","textColor":"base"} -->
<div class="wp-block-button"><a class="wp-block-button__link has-base-color has-rust-background-color has-text-color has-background wp-element-button" href="/new-here/#plan-your-visit">Plan a Visit</a></div>
<!-- /wp:button -->
<!-- wp:button {"backgroundColor":"base","textColor":"accent-3"} -->
<div class="wp-block-button"><a class="wp-block-button__link has-accent-3-color has-base-background-color has-text-color has-background wp-element-button" href="https://www.youtube.com/@pellanazarene">Watch Online</a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:group -->
<!-- wp:group {"align":"full","className":"pn-infobar","backgroundColor":"navy-deep","textColor":"base","style":{"spacing":{"padding":{"top":"var:preset|spacing|30","bottom":"var:preset|spacing|30"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull pn-infobar has-base-color has-navy-deep-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--30)">
<!-- wp:group {"align":"wide","layout":{"type":"flex","flexWrap":"wrap"}} -->
<div class="wp-block-group alignwide">
<!-- wp:paragraph {"fontSize":"small"} -->
<p class="has-small-font-size"><strong>Sundays</strong> · Small Groups 9:30&nbsp;AM · Worship 10:30&nbsp;AM</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"fontSize":"small"} -->
<p class="has-small-font-size">303 University Street, Pella</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"fontSize":"small"} -->
<p class="has-small-font-size"><a href="https://www.google.com/maps/search/?api=1&amp;query=303+University+Street+Pella+IA+50219">Get directions</a></p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:group -->
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|70","bottom":"var:preset|spacing|70"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--70);padding-bottom:var(--wp--preset--spacing--70)">
<!-- wp:columns {"verticalAlignment":"center","align":"wide","style":{"spacing":{"blockGap":{"left":"var:preset|spacing|60"}}}} -->
<div class="wp-block-columns alignwide are-vertically-aligned-center">
<!-- wp:column {"verticalAlignment":"center"} -->
<div class="wp-block-column is-vertically-aligned-center">
<?php echo pn_photo_block( 'new-here-welcome', 'half', '4/3' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside pn_photo_block(). ?>
</div>
<!-- /wp:column -->
<!-- wp:column {"verticalAlignment":"center"} -->
<div class="wp-block-column is-vertically-aligned-center">
<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
<p class="is-style-eyebrow">Who we are</p>
<!-- /wp:paragraph -->
<!-- wp:heading -->
<h2 class="wp-block-heading">A church family with room for you</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>Whether you’re exploring faith, looking for community, or searching for a church home, we hope you’ll find a place where you’re welcomed just as you are. Our mission is simple: to love people where they are and grow together in Jesus.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph -->
<p><strong>Todd &amp; Cindy Olson, Co-Pastors</strong></p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"className":"pn-more"} -->
<p class="pn-more"><a href="/about-us/">Meet our pastors →</a></p>
<!-- /wp:paragraph -->
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
<p class="is-style-eyebrow">Your next step</p>
<!-- /wp:paragraph -->
<!-- wp:heading -->
<h2 class="wp-block-heading">Find your place</h2>
<!-- /wp:heading -->
</div>
<!-- /wp:group -->
<!-- wp:columns {"align":"wide","className":"pn-cards"} -->
<div class="wp-block-columns alignwide pn-cards">
<!-- wp:column {"className":"pn-card","backgroundColor":"base"} -->
<div class="wp-block-column pn-card has-base-background-color has-background">
<?php echo pn_photo_block( 'about-worship', 'card', '3/2' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside pn_photo_block(). ?>
<!-- wp:heading {"level":3,"fontSize":"large"} -->
<h3 class="wp-block-heading has-large-font-size">New here?</h3>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>What to expect on a Sunday, where to park, and how to plan your first visit.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"className":"pn-more"} -->
<p class="pn-more"><a href="/new-here/#plan-your-visit">Plan your visit →</a></p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
<!-- wp:column {"className":"pn-card","backgroundColor":"base"} -->
<div class="wp-block-column pn-card has-base-background-color has-background">
<?php echo pn_photo_block( 'kids-welcome', 'card', '3/2' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside pn_photo_block(). ?>
<!-- wp:heading {"level":3,"fontSize":"large"} -->
<h3 class="wp-block-heading has-large-font-size">Kids</h3>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>Safe, fun, age-appropriate classes from birth through 12, with trained volunteers.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"className":"pn-more"} -->
<p class="pn-more"><a href="/kids/">Pella Nazarene Kids →</a></p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
<!-- wp:column {"className":"pn-card","backgroundColor":"base"} -->
<div class="wp-block-column pn-card has-base-background-color has-background">
<?php echo pn_photo_block( 'about-community', 'card', '3/2' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside pn_photo_block(). ?>
<!-- wp:heading {"level":3,"fontSize":"large"} -->
<h3 class="wp-block-heading has-large-font-size">Groups</h3>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>Life is better together. Find a Sunday class or small group where you can belong.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"className":"pn-more"} -->
<p class="pn-more"><a href="/small-groups/">Find a group →</a></p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</div>
<!-- /wp:group -->
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|70","bottom":"var:preset|spacing|70"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--70);padding-bottom:var(--wp--preset--spacing--70)">
<!-- wp:shortcode -->
[pn_latest_sermon layout="feature"]
<!-- /wp:shortcode -->
</div>
<!-- /wp:group -->
<!-- wp:group {"align":"full","backgroundColor":"stone","style":{"spacing":{"padding":{"top":"var:preset|spacing|70","bottom":"var:preset|spacing|70"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-stone-background-color has-background" style="padding-top:var(--wp--preset--spacing--70);padding-bottom:var(--wp--preset--spacing--70)">
<!-- wp:group {"align":"wide","layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between","verticalAlignment":"bottom"}} -->
<div class="wp-block-group alignwide">
<!-- wp:group {"layout":{"type":"default"}} -->
<div class="wp-block-group">
<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
<p class="is-style-eyebrow">Every week</p>
<!-- /wp:paragraph -->
<!-- wp:heading -->
<h2 class="wp-block-heading">What’s happening</h2>
<!-- /wp:heading -->
</div>
<!-- /wp:group -->
<!-- wp:paragraph {"className":"pn-more"} -->
<p class="pn-more"><a href="https://pella-nazarene.churchcenter.com/calendar">Full calendar →</a></p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
<!-- wp:table {"hasFixedLayout":false,"align":"wide","className":"pn-week"} -->
<figure class="wp-block-table alignwide pn-week"><table><thead><tr><th>When</th><th>What</th><th>Where</th></tr></thead><tbody><tr><td>Sundays · 9:30 AM</td><td>Sunday School &amp; Connections Class</td><td>At the church</td></tr><tr><td>Sundays · 10:30 AM</td><td>Worship Service</td><td>At the church &amp; online</td></tr><tr><td>Sundays · 5:30 PM</td><td>Lighthouse Student Ministry</td><td>At the church</td></tr><tr><td>Tuesdays · 9:30 AM</td><td>Coffee Time</td><td>Windmill Cafe</td></tr><tr><td>Tue 10:30 AM · Wed 7 PM</td><td>Midweek Prayer</td><td>At the church</td></tr></tbody></table></figure>
<!-- /wp:table -->
</div>
<!-- /wp:group -->
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|70","bottom":"var:preset|spacing|70"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--70);padding-bottom:var(--wp--preset--spacing--70)">
<!-- wp:columns {"verticalAlignment":"center","align":"wide"} -->
<div class="wp-block-columns alignwide are-vertically-aligned-center">
<!-- wp:column {"verticalAlignment":"center","width":"66.66%"} -->
<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:66.66%">
<!-- wp:heading -->
<h2 class="wp-block-heading">Give generously</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>Your giving supports our ministries in Pella, our building fund, and Nazarene world missions. Give securely online in under a minute.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
<!-- wp:column {"verticalAlignment":"center","width":"33.33%"} -->
<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:33.33%">
<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"right"}} -->
<div class="wp-block-buttons">
<!-- wp:button {"backgroundColor":"rust","textColor":"base"} -->
<div class="wp-block-button"><a class="wp-block-button__link has-base-color has-rust-background-color has-text-color has-background wp-element-button" href="https://pella-nazarene.churchcenter.com/giving">Give Online</a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</div>
<!-- /wp:group -->
