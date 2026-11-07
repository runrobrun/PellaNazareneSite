<?php
/**
 * Shared page sections, in the look of the home page (FE-6): the navy header every page opens with.
 *
 * Patterns print these as ordinary block markup, so once a page is created staff edit every word in the
 * block editor like any other block.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Block markup for a page's navy header: an eyebrow label, the page's only h1, one line of text, and optional buttons.
 * The template hides its own title when the page has this h1 (see inc/photos.php).
 *
 * @param string $eyebrow Short label above the heading, usually the page's name ("New here").
 * @param string $title   The headline (the page's h1).
 * @param string $lead    One or two sentences under it.
 * @param array  $buttons Optional list of array( label, url, 'rust'|'white' ).
 */
function pn_page_header( string $eyebrow, string $title, string $lead, array $buttons = array() ): string {
	$html  = '<!-- wp:group {"align":"full","className":"pn-page-header","backgroundColor":"accent-3","textColor":"base","layout":{"type":"constrained"}} -->' . "\n";
	$html .= '<div class="wp-block-group alignfull pn-page-header has-base-color has-accent-3-background-color has-text-color has-background">' . "\n";
	$html .= '<!-- wp:group {"align":"wide","className":"pn-page-header__content","layout":{"type":"default"}} -->' . "\n";
	$html .= '<div class="wp-block-group alignwide pn-page-header__content">' . "\n";
	$html .= '<!-- wp:paragraph {"className":"is-style-eyebrow","textColor":"rust-light"} -->' . "\n";
	$html .= '<p class="is-style-eyebrow has-rust-light-color has-text-color">' . esc_html( $eyebrow ) . '</p>' . "\n";
	$html .= '<!-- /wp:paragraph -->' . "\n";
	$html .= '<!-- wp:heading {"level":1,"className":"pn-page-header__title"} -->' . "\n";
	$html .= '<h1 class="wp-block-heading pn-page-header__title">' . esc_html( $title ) . '</h1>' . "\n";
	$html .= '<!-- /wp:heading -->' . "\n";
	$html .= '<!-- wp:paragraph {"className":"pn-page-header__lead","fontSize":"medium"} -->' . "\n";
	$html .= '<p class="pn-page-header__lead has-medium-font-size">' . esc_html( $lead ) . '</p>' . "\n";
	$html .= '<!-- /wp:paragraph -->' . "\n";
	if ( $buttons ) {
		$html .= '<!-- wp:buttons -->' . "\n" . '<div class="wp-block-buttons">' . "\n";
		foreach ( $buttons as $button ) {
			list( $label, $url, $style ) = $button;
			// Rust with white text is the main call to action; white with navy text is the second one.
			$colors = 'white' === $style
				? array( 'base', 'accent-3', 'has-accent-3-color has-base-background-color' )
				: array( 'rust', 'base', 'has-base-color has-rust-background-color' );
			$html  .= '<!-- wp:button {"backgroundColor":"' . $colors[0] . '","textColor":"' . $colors[1] . '"} -->' . "\n";
			$html  .= '<div class="wp-block-button"><a class="wp-block-button__link ' . $colors[2] . ' has-text-color has-background wp-element-button" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></div>' . "\n";
			$html  .= '<!-- /wp:button -->' . "\n";
		}
		$html .= '</div>' . "\n" . '<!-- /wp:buttons -->' . "\n";
	}
	$html .= '</div>' . "\n" . '<!-- /wp:group -->' . "\n";
	$html .= '</div>' . "\n" . '<!-- /wp:group -->' . "\n";
	return $html;
}
