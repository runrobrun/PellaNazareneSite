<?php
/**
 * Pella Nazarene child theme.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style( 'pella-nazarene', get_stylesheet_uri(), [], wp_get_theme()->get( 'Version' ) );
} );

// Pattern category so staff find church patterns first in the inserter.
add_action( 'init', function () {
	register_block_pattern_category( 'pella-nazarene', [ 'label' => __( 'Pella Nazarene', 'pella-nazarene' ) ] );
} );
