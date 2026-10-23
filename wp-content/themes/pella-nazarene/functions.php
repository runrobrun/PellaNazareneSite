<?php
/**
 * Pella Nazarene child theme.
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'wp_enqueue_scripts',
	function () {
		wp_enqueue_style( 'pella-nazarene', get_stylesheet_uri(), array(), wp_get_theme()->get( 'Version' ) );
	}
);

// Pattern category so staff find church patterns first in the inserter.
add_action(
	'init',
	function () {
		register_block_pattern_category( 'pella-nazarene', array( 'label' => __( 'Pella Nazarene', 'pella-nazarene' ) ) );
	}
);

// Preload the regular font file so text paints in the brand typeface without a flash of fallback
// font. It is a variable font, so this one file also covers the Extra Bold headings.
add_action(
	'wp_head',
	function () {
		printf(
			'<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n",
			esc_url( get_theme_file_uri( 'assets/fonts/plus-jakarta-sans-normal.woff2' ) )
		);
	},
	1
);

// One look only: the brand guide. The parent theme ships whole-site "style variations" (Evening, Noon,
// Midnight, font pairings...) that an administrator could apply in the Site Editor to repaint the
// whole site in off-brand colors and fonts. Offer none.
add_filter(
	'rest_request_after_callbacks',
	function ( $response, $handler, $request ) {
		if ( $response instanceof WP_REST_Response
			&& 'GET' === $request->get_method()
			&& 1 === preg_match( '#^/wp/v2/global-styles/themes/[^/]+/variations$#', $request->get_route() )
		) {
			$response->set_data( array() );
		}
		return $response;
	},
	10,
	3
);

// WordPress's own default colors, gradients and duotone filters are never used here (see theme.json),
// so don't print their CSS variables and classes on every page.
add_filter(
	'wp_theme_json_data_default',
	function ( $theme_json ) {
		$data = $theme_json->get_data();
		unset(
			$data['settings']['color']['palette'],
			$data['settings']['color']['gradients'],
			$data['settings']['color']['duotone']
		);
		return new WP_Theme_JSON_Data( $data, 'default' );
	}
);

/**
 * Print the browser-tab, bookmark, and phone home-screen icon links: the brand symbol, shipped with the
 * theme. An icon an administrator chooses under Appearance > Customize > Site Identity still wins.
 *
 * @return void
 */
function pn_print_site_icons(): void {
	if ( has_site_icon() ) {
		return;
	}
	printf(
		'<link rel="icon" href="%1$s" sizes="any">' . "\n" . '<link rel="icon" href="%2$s" type="image/svg+xml">' . "\n" . '<link rel="apple-touch-icon" href="%3$s">' . "\n",
		esc_url( get_theme_file_uri( 'assets/images/favicon.ico' ) ),
		esc_url( get_theme_file_uri( 'assets/images/icon.svg' ) ),
		esc_url( get_theme_file_uri( 'assets/images/apple-touch-icon.png' ) )
	);
}
add_action( 'wp_head', 'pn_print_site_icons', 2 );
add_action( 'login_head', 'pn_print_site_icons' );
