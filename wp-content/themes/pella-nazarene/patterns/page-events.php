<?php
/**
 * Title: Page: Events
 * Slug: pella-nazarene/page-events
 * Categories: pella-nazarene
 * Inserter: false
 * Description: The regular weekly events, with Central times, and the Church Center calendar. In the look of the home page (FE-6).
 */

defined( 'ABSPATH' ) || exit;
?>
<?php
echo pn_page_header( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside pn_page_header().
	'Events',
	'What’s happening',
	'Here’s what happens every week at Pella Nazarene. All times are Central.',
	array(
		array( 'See the full calendar', 'https://pella-nazarene.churchcenter.com/calendar', 'rust' ),
	)
);
?>
<!-- wp:group {"align":"full","backgroundColor":"stone","style":{"spacing":{"padding":{"top":"var:preset|spacing|70","bottom":"var:preset|spacing|70"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-stone-background-color has-background" style="padding-top:var(--wp--preset--spacing--70);padding-bottom:var(--wp--preset--spacing--70)">
<!-- wp:group {"align":"wide","layout":{"type":"default"}} -->
<div class="wp-block-group alignwide">
<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
<p class="is-style-eyebrow">Weekly</p>
<!-- /wp:paragraph -->
<!-- wp:heading -->
<h2 class="wp-block-heading">Every week</h2>
<!-- /wp:heading -->
</div>
<!-- /wp:group -->
<!-- wp:table {"hasFixedLayout":false,"align":"wide","className":"pn-week"} -->
<figure class="wp-block-table alignwide pn-week"><table><thead><tr><th>When</th><th>What</th><th>Where</th></tr></thead><tbody><tr><td>Every Sunday, 9:30–10:15 AM</td><td>Sunday School</td><td>At the church</td></tr><tr><td>Every Sunday, 9:30–10:15 AM</td><td>Connections Sunday School Class</td><td>At the church</td></tr><tr><td>Every Sunday, 5:30–7:00 PM</td><td>Lighthouse Student Ministry</td><td>At the church</td></tr><tr><td>Every Tuesday, 10:30–11:30 AM<br>Every Wednesday, 7:00–8:00 PM</td><td>Midweek Prayer Meeting</td><td>At the church</td></tr><tr><td>Every Tuesday, 9:30–10:30 AM</td><td>Coffee Time</td><td>At the Windmill Cafe · <a href="https://www.google.com/maps/search/?api=1&amp;query=Windmill+Cafe+Pella+Iowa">Get directions</a></td></tr></tbody></table></figure>
<!-- /wp:table -->
<!-- wp:group {"align":"wide","layout":{"type":"default"}} -->
<div class="wp-block-group alignwide">
<!-- wp:paragraph -->
<p>Sunday worship is at 10:30&nbsp;AM, with small groups at 9:30&nbsp;AM. Plans for your first visit are on the <a href="/new-here/">New Here page</a>.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:group -->
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|70","bottom":"var:preset|spacing|70"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--70);padding-bottom:var(--wp--preset--spacing--70)">
<!-- wp:group {"layout":{"type":"constrained"}} -->
<div class="wp-block-group">
<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
<p class="is-style-eyebrow">Calendar</p>
<!-- /wp:paragraph -->
<!-- wp:heading -->
<h2 class="wp-block-heading">Everything else on the calendar</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>Special events, classes, and one-time gatherings are on our calendar in Church Center, where you can also sign up. It’s always the most up-to-date place to look.</p>
<!-- /wp:paragraph -->
<!-- wp:buttons -->
<div class="wp-block-buttons">
<!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="https://pella-nazarene.churchcenter.com/calendar">See the full calendar</a></div>
<!-- /wp:button -->
<!-- wp:button {"className":"is-style-outline"} -->
<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="https://pella-nazarene.churchcenter.com/registrations/events">Sign up for events</a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->
<!-- wp:paragraph -->
<p>Questions about an event? <a href="/contact/">Contact us</a> and we’ll help.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:group -->
