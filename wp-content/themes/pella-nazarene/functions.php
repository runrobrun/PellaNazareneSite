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
