<?php
/**
 * Title: Header
 * Slug: pella-nazarene/header
 * Categories: header
 * Block Types: core/template-part/header
 * Description: Church logo, main menu, and the Plan a Visit button.
 * Inserter: no
 */

defined( 'ABSPATH' ) || exit;

$pn_home = esc_url( home_url( '/' ) );
$pn_logo = esc_url( get_theme_file_uri( 'assets/images/logo-primary.svg' ) );

// One menu link as block markup. Absolute URLs come from home_url(), so staging and production each link to themselves.
$pn_link = static function ( string $label, string $path, string $class_name = '' ): string {
	$attrs = array(
		'label' => $label,
		'url'   => home_url( $path ),
		'kind'  => 'custom',
	);
	if ( '' !== $class_name ) {
		$attrs['className'] = $class_name;
	}
	return '<!-- wp:navigation-link ' . serialize_block_attributes( $attrs ) . ' /-->';
};
?>
<!-- wp:group {"align":"full","className":"pn-header","style":{"spacing":{"padding":{"top":"var:preset|spacing|20","bottom":"var:preset|spacing|20","left":"var:preset|spacing|30","right":"var:preset|spacing|30"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull pn-header" style="padding-top:var(--wp--preset--spacing--20);padding-right:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--20);padding-left:var(--wp--preset--spacing--30)">
	<!-- wp:group {"align":"wide","layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between"}} -->
	<div class="wp-block-group alignwide">
		<!-- wp:html -->
		<a class="pn-logo" href="<?php echo $pn_home; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped with esc_url() above. ?>"><img src="<?php echo $pn_logo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped with esc_url() above. ?>" alt="<?php esc_attr_e( 'Pella Church of the Nazarene, home page', 'pella-nazarene' ); ?>" width="220" height="75"></a>
		<!-- /wp:html -->

		<!-- wp:navigation {"ariaLabel":"<?php esc_attr_e( 'Primary', 'pella-nazarene' ); ?>","overlayMenu":"mobile","overlayBackgroundColor":"base","overlayTextColor":"accent-3","textColor":"accent-3","icon":"menu","layout":{"type":"flex","justifyContent":"right","flexWrap":"wrap"},"style":{"spacing":{"blockGap":"var:preset|spacing|30"}}} -->
			<?php
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- block markup built from fixed labels and home_url() above.
			echo $pn_link( __( 'New Here', 'pella-nazarene' ), '/new-here/' ), "\n\t\t\t";
			echo $pn_link( __( 'About Us', 'pella-nazarene' ), '/about-us/' ), "\n\t\t\t";
			echo $pn_link( __( 'Sermons', 'pella-nazarene' ), '/sermons/' ), "\n\t\t\t";
			echo $pn_link( __( 'Events', 'pella-nazarene' ), '/events/' ), "\n\t\t\t";
			echo $pn_link( __( 'Kids', 'pella-nazarene' ), '/kids/' ), "\n\t\t\t";
			echo $pn_link( __( 'Giving', 'pella-nazarene' ), '/giving/' ), "\n\t\t\t";
			echo $pn_link( __( 'Contact', 'pella-nazarene' ), '/contact/' ), "\n\t\t\t";
			echo $pn_link( __( 'Plan a Visit', 'pella-nazarene' ), '/new-here/', 'pn-cta' ), "\n";
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
		<!-- /wp:navigation -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
