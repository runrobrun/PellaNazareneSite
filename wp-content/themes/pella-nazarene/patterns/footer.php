<?php
/**
 * Title: Footer
 * Slug: pella-nazarene/footer
 * Categories: footer
 * Block Types: core/template-part/footer
 * Description: Logo and mission, service times and contact details, links, and social media.
 * Inserter: no
 */

defined( 'ABSPATH' ) || exit;

$pn_home = esc_url( home_url( '/' ) );
$pn_logo = esc_url( get_theme_file_uri( 'assets/images/logo-primary-white.svg' ) );

$pn_link = static function ( string $label, string $path ): string {
	return '<!-- wp:navigation-link ' . serialize_block_attributes(
		array(
			'label' => $label,
			'url'   => home_url( $path ),
			'kind'  => 'custom',
		)
	) . ' /-->';
};
?>
<!-- wp:group {"align":"full","className":"pn-footer","backgroundColor":"accent-3","textColor":"base","style":{"spacing":{"padding":{"top":"var:preset|spacing|60","bottom":"var:preset|spacing|40","left":"var:preset|spacing|30","right":"var:preset|spacing|30"}},"elements":{"link":{"color":{"text":"var:preset|color|base"}}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull pn-footer has-base-color has-accent-3-background-color has-text-color has-background has-link-color" style="padding-top:var(--wp--preset--spacing--60);padding-right:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--40);padding-left:var(--wp--preset--spacing--30)">
	<!-- wp:columns {"align":"wide"} -->
	<div class="wp-block-columns alignwide">
		<!-- wp:column {"width":"40%"} -->
		<div class="wp-block-column" style="flex-basis:40%">
			<!-- wp:html -->
			<a class="pn-logo" href="<?php echo $pn_home; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped with esc_url() above. ?>"><img src="<?php echo $pn_logo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped with esc_url() above. ?>" alt="<?php esc_attr_e( 'Pella Church of the Nazarene, home page', 'pella-nazarene' ); ?>" width="200" height="68"></a>
			<!-- /wp:html -->

			<!-- wp:paragraph -->
			<p><?php esc_html_e( 'Our mission is to love people where they are and grow together in Jesus.', 'pella-nazarene' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:social-links {"iconColor":"base","iconColorValue":"#FFFFFF","className":"is-style-logos-only"} -->
			<ul class="wp-block-social-links has-icon-color is-style-logos-only">
				<!-- wp:social-link {"url":"https://www.facebook.com/PellaNazarene","service":"facebook","label":"Facebook"} /-->

				<!-- wp:social-link {"url":"https://www.instagram.com/pellanazarene","service":"instagram","label":"Instagram"} /-->

				<!-- wp:social-link {"url":"https://www.youtube.com/@pellanazarene","service":"youtube","label":"YouTube"} /-->
			</ul>
			<!-- /wp:social-links -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"width":"35%"} -->
		<div class="wp-block-column" style="flex-basis:35%">
			<!-- wp:heading {"level":2,"textColor":"base","fontSize":"large"} -->
			<h2 class="wp-block-heading has-base-color has-text-color has-large-font-size"><?php esc_html_e( 'Visit us', 'pella-nazarene' ); ?></h2>
			<!-- /wp:heading -->

			<!-- wp:template-part {"slug":"service-info"} /-->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"width":"25%"} -->
		<div class="wp-block-column" style="flex-basis:25%">
			<!-- wp:heading {"level":2,"textColor":"base","fontSize":"large"} -->
			<h2 class="wp-block-heading has-base-color has-text-color has-large-font-size"><?php esc_html_e( 'Explore', 'pella-nazarene' ); ?></h2>
			<!-- /wp:heading -->

			<!-- wp:navigation {"ariaLabel":"<?php esc_attr_e( 'Footer', 'pella-nazarene' ); ?>","textColor":"base","overlayMenu":"never","layout":{"type":"flex","orientation":"vertical"}} -->
				<?php
				// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- block markup built from fixed labels and home_url() above.
				echo $pn_link( __( 'Plan a Visit', 'pella-nazarene' ), '/new-here/' ), "\n\t\t\t\t";
				echo $pn_link( __( 'Resources', 'pella-nazarene' ), '/resources/' ), "\n\t\t\t\t";
				echo $pn_link( __( 'Blog', 'pella-nazarene' ), '/blog/' ), "\n\t\t\t\t";
				echo $pn_link( __( 'Contact', 'pella-nazarene' ), '/contact/' ), "\n";
				// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			<!-- /wp:navigation -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->

	<!-- wp:group {"align":"wide","className":"pn-footer-bottom","layout":{"type":"constrained"}} -->
	<div class="wp-block-group alignwide pn-footer-bottom">
		<!-- wp:paragraph {"align":"center","fontSize":"small","className":"pn-copyright"} -->
		<p class="has-text-align-center pn-copyright has-small-font-size">
			<?php
			/* translators: %s: the current year. */
			echo esc_html( sprintf( __( '© %s Pella Church of the Nazarene', 'pella-nazarene' ), wp_date( 'Y' ) ) );
			?>
		</p>
		<!-- /wp:paragraph -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
