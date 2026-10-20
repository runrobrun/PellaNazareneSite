<?php
/**
 * Title: Staff member
 * Slug: pella-nazarene/staff-member
 * Categories: pella-nazarene
 * Description: Photo, name, title, short bio, and email for one staff member.
 */
?>
<!-- wp:columns {"verticalAlignment":"top"} -->
<div class="wp-block-columns are-vertically-aligned-top">
	<!-- wp:column {"width":"30%"} -->
	<div class="wp-block-column" style="flex-basis:30%">
		<!-- wp:image {"aspectRatio":"1","scale":"cover","style":{"border":{"radius":"8px"}}} -->
		<figure class="wp-block-image has-custom-border"><img alt="" style="border-radius:8px;aspect-ratio:1;object-fit:cover"/></figure>
		<!-- /wp:image -->
	</div>
	<!-- /wp:column -->
	<!-- wp:column {"width":"70%"} -->
	<div class="wp-block-column" style="flex-basis:70%">
		<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Name</h3><!-- /wp:heading -->
		<!-- wp:paragraph {"fontSize":"small"} --><p class="has-small-font-size"><strong>Title</strong></p><!-- /wp:paragraph -->
		<!-- wp:paragraph --><p>A few sentences about this person.</p><!-- /wp:paragraph -->
		<!-- wp:paragraph {"fontSize":"small"} --><p class="has-small-font-size"><a href="mailto:">Email</a></p><!-- /wp:paragraph -->
	</div>
	<!-- /wp:column -->
</div>
<!-- /wp:columns -->
