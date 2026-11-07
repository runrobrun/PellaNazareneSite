<?php
/**
 * Title: Page: New Here
 * Slug: pella-nazarene/page-new-here
 * Categories: pella-nazarene
 * Inserter: false
 * Description: What to expect on a Sunday, plan a visit, and common questions. In the look of the home page (FE-6).
 */
?>
<?php
echo pn_page_header( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside pn_page_header().
	'New here',
	'We’d love to meet you',
	'Whether it’s your first Sunday or you’re just looking around, here’s everything you need to feel at home.',
	array(
		array( 'Plan my visit now', '/new-here/#plan-your-visit', 'rust' ),
		array( 'View map & directions', 'https://www.google.com/maps/search/?api=1&query=303+University+Street+Pella+IA+50219', 'white' ),
	)
);
?>
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|70","bottom":"var:preset|spacing|70"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--70);padding-bottom:var(--wp--preset--spacing--70)">
<!-- wp:columns {"verticalAlignment":"center","align":"wide"} -->
<div class="wp-block-columns alignwide are-vertically-aligned-center">
<!-- wp:column {"verticalAlignment":"center"} -->
<div class="wp-block-column is-vertically-aligned-center">
<!-- wp:shortcode -->
[pn_sermon_video id="EK0MuwDegjI"]
<!-- /wp:shortcode -->
</div>
<!-- /wp:column -->
<!-- wp:column {"verticalAlignment":"center"} -->
<div class="wp-block-column is-vertically-aligned-center">
<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
<p class="is-style-eyebrow">Welcome</p>
<!-- /wp:paragraph -->
<!-- wp:heading -->
<h2 class="wp-block-heading">Welcome to Pella Nazarene</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>Hey there! Thanks for checking out Pella Nazarene. If you are searching for a good church, we know we are the place for you. If you have never been to our church before, come by this Sunday! We look forward to meeting you! God bless.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph -->
<p>We’re so glad you’re here. Whether you’re visiting for the first time, exploring faith, or looking for a church home, we hope you feel welcomed just as you are. Our mission is simple: to love people where they are and to grow together in Jesus, and we strive to live that out in our worship, our community, and our service. As you explore this page, you’ll find helpful information about what to expect on a Sunday and how to get connected. If you have questions or want to take a next step, we’d love to help you along the way.</p>
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
<p class="is-style-eyebrow">Sundays</p>
<!-- /wp:paragraph -->
<!-- wp:heading -->
<h2 class="wp-block-heading">Two ways to join on Sunday</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>Pella Nazarene gathers in person and online every Sunday morning! There is something for the whole family every single week, and you can plan a visit below. We would love to meet you!</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
<!-- wp:columns {"align":"wide","className":"pn-cards"} -->
<div class="wp-block-columns alignwide pn-cards">
<!-- wp:column {"className":"pn-card","backgroundColor":"base"} -->
<div class="wp-block-column pn-card has-base-background-color has-background">
<?php echo pn_photo_block( 'new-here-welcome', 'card', '3/2' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside pn_photo_block(). ?>
<!-- wp:heading {"level":3,"fontSize":"large"} -->
<h3 class="wp-block-heading has-large-font-size">In person</h3>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>Join us in person for a welcoming, come-as-you-are worship experience with music, scripture, and a meaningful message. We’d love to greet you, worship together, and help you feel right at home.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"className":"pn-more"} -->
<p class="pn-more"><a href="#plan-your-visit">Plan a Visit →</a></p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
<!-- wp:column {"className":"pn-card","backgroundColor":"base"} -->
<div class="wp-block-column pn-card has-base-background-color has-background">
<?php echo pn_photo_block( 'new-here-online', 'card', '3/2' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside pn_photo_block(). ?>
<!-- wp:heading {"level":3,"fontSize":"large"} -->
<h3 class="wp-block-heading has-large-font-size">Online</h3>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>Can’t join us in person? Our online services allow you to worship with us from wherever you are and stay connected through livestreamed services and messages you can watch anytime.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"className":"pn-more"} -->
<p class="pn-more"><a href="https://www.youtube.com/@pellanazarene">Watch online →</a></p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</div>
<!-- /wp:group -->
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|70","bottom":"var:preset|spacing|70"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--70);padding-bottom:var(--wp--preset--spacing--70)">
<!-- wp:columns {"verticalAlignment":"center","align":"wide"} -->
<div class="wp-block-columns alignwide are-vertically-aligned-center">
<!-- wp:column {"verticalAlignment":"center"} -->
<div class="wp-block-column is-vertically-aligned-center">
<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
<p class="is-style-eyebrow">Your first visit</p>
<!-- /wp:paragraph -->
<!-- wp:heading {"anchor":"plan-your-visit"} -->
<h2 class="wp-block-heading" id="plan-your-visit">Plan your visit</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>We’d love to see you on Sunday. Here is everything you need to find us.</p>
<!-- /wp:paragraph -->
<!-- wp:buttons -->
<div class="wp-block-buttons">
<!-- wp:button {"backgroundColor":"rust","textColor":"base"} -->
<div class="wp-block-button"><a class="wp-block-button__link has-base-color has-rust-background-color has-text-color has-background wp-element-button" href="https://pella-nazarene.churchcenter.com/people/forms/962834">Tell us you’re coming</a></div>
<!-- /wp:button -->
<!-- wp:button {"className":"is-style-outline"} -->
<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="https://www.google.com/maps/search/?api=1&amp;query=303+University+Street+Pella+IA+50219">View map &amp; directions</a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->
</div>
<!-- /wp:column -->
<!-- wp:column {"verticalAlignment":"center"} -->
<div class="wp-block-column is-vertically-aligned-center">
<!-- wp:group {"className":"pn-panel","backgroundColor":"stone","layout":{"type":"default"}} -->
<div class="wp-block-group pn-panel has-stone-background-color has-background">
<!-- wp:template-part {"slug":"service-info","theme":"pella-nazarene"} /-->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</div>
<!-- /wp:group -->
<!-- wp:group {"align":"full","backgroundColor":"stone","style":{"spacing":{"padding":{"top":"var:preset|spacing|70","bottom":"var:preset|spacing|70"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-stone-background-color has-background" style="padding-top:var(--wp--preset--spacing--70);padding-bottom:var(--wp--preset--spacing--70)">
<!-- wp:columns {"verticalAlignment":"center","align":"wide"} -->
<div class="wp-block-columns alignwide are-vertically-aligned-center">
<!-- wp:column {"verticalAlignment":"center"} -->
<div class="wp-block-column is-vertically-aligned-center">
<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
<p class="is-style-eyebrow">For your family</p>
<!-- /wp:paragraph -->
<!-- wp:heading -->
<h2 class="wp-block-heading">Pella Nazarene Kids</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>We love our kids and believe they are an important part of our church family. Our kids ministry provides a safe, welcoming, and age-appropriate environment where children can learn about Jesus, build friendships, and have fun.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"className":"pn-more"} -->
<p class="pn-more"><a href="/kids/">Learn more about Kids →</a></p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
<!-- wp:column {"verticalAlignment":"center"} -->
<div class="wp-block-column is-vertically-aligned-center">
<?php echo pn_photo_block( 'kids-celebrate', 'half', '4/3' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside pn_photo_block(). ?>
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</div>
<!-- /wp:group -->
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|70","bottom":"var:preset|spacing|70"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--70);padding-bottom:var(--wp--preset--spacing--70)">
<!-- wp:columns {"align":"wide"} -->
<div class="wp-block-columns alignwide">
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
<p class="is-style-eyebrow">Questions</p>
<!-- /wp:paragraph -->
<!-- wp:heading {"anchor":"sunday-questions"} -->
<h2 class="wp-block-heading" id="sunday-questions">Sunday morning questions</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>Find out what you need to know before visiting.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:group {"className":"pn-faq","layout":{"type":"default"}} -->
<div class="wp-block-group pn-faq">
<!-- wp:details -->
<details class="wp-block-details"><summary>Where are you located?</summary><!-- wp:paragraph -->
<p>303 University Street, Pella, IA 50219.</p>
<!-- /wp:paragraph -->
</details>
<!-- /wp:details -->
<!-- wp:details -->
<details class="wp-block-details"><summary>What time is service?</summary><!-- wp:paragraph -->
<p>Small Groups at 9:30 AM, and Sunday Worship at 10:30 AM.</p>
<!-- /wp:paragraph -->
</details>
<!-- /wp:details -->
<!-- wp:details -->
<details class="wp-block-details"><summary>Where do I park?</summary><!-- wp:paragraph -->
<p>Parking is easy! Our main lot is on the south side of the building and can be accessed from University Street or Prairie Street. We also have a small lot on the west side with a few extra spaces.</p>
<!-- /wp:paragraph -->
</details>
<!-- /wp:details -->
<!-- wp:details -->
<details class="wp-block-details"><summary>Is there something for my kids?</summary><!-- wp:paragraph -->
<p>Yes! We love our kids and provide safe, age-appropriate environments where children can learn about Jesus, build friendships, and have fun. Our volunteers are trained and ready to welcome your family.</p>
<!-- /wp:paragraph -->
</details>
<!-- /wp:details -->
<!-- wp:details -->
<details class="wp-block-details"><summary>What does a typical service look like?</summary><!-- wp:paragraph -->
<p>Our services are blended, with a mix of hymns and worship songs, scripture reading, and a relevant message. Services last under 90 minutes, participation is always optional, and we often offer open communion with clear guidance. Come as you are and feel free to engage at your own pace.</p>
<!-- /wp:paragraph -->
</details>
<!-- /wp:details -->
<!-- wp:details -->
<details class="wp-block-details"><summary>How do I join a ministry?</summary><!-- wp:paragraph -->
<p>We love (and need) help! The easiest way to join a ministry is to fill out a Connect card when you arrive, or talk with a member of our staff. We’d love to help you find a place to serve that fits your gifts and interests.</p>
<!-- /wp:paragraph -->
</details>
<!-- /wp:details -->
<!-- wp:details -->
<details class="wp-block-details"><summary>How do I join a small group?</summary><!-- wp:paragraph -->
<p>We would love for you to join in on our small group ministry. Fill out a Connect card or touch base with one of our staff, and we’ll help you find a group where you can belong and grow.</p>
<!-- /wp:paragraph -->
</details>
<!-- /wp:details -->
<!-- wp:details -->
<details class="wp-block-details"><summary>How can I get involved in serving?</summary><!-- wp:paragraph -->
<p>Service is a big part of who we are. Join us by filling out a Connect card or touching base with a staff member to see where we can plug you in!</p>
<!-- /wp:paragraph -->
</details>
<!-- /wp:details -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</div>
<!-- /wp:group -->
