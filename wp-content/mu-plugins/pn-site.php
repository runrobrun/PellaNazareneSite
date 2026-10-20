<?php
/**
 * Plugin Name: Pella Nazarene Site
 * Description: Site-wide behavior: simpler admin for staff, hardening, and performance tweaks. Must-use plugin (always on).
 * Version:     0.1.0
 */

defined( 'ABSPATH' ) || exit;

/*
 * ---------------------------------------------------------------------------
 * Simpler admin for staff
 * ---------------------------------------------------------------------------
 * Administrators see everything. Everyone else sees Posts, Sermons, Events,
 * Media, Pages (Editors), and their Profile.
 */
add_action( 'admin_menu', function () {
	if ( current_user_can( 'manage_options' ) ) {
		return;
	}
	foreach ( [ 'tools.php', 'edit-comments.php', 'index.php' ] as $slug ) {
		remove_menu_page( $slug );
	}
}, 999 );

// Send non-admins to the Posts list instead of the Dashboard.
add_filter( 'login_redirect', function ( $redirect, $requested, $user ) {
	if ( $user instanceof WP_User && ! user_can( $user, 'manage_options' ) && empty( $requested ) ) {
		return admin_url( 'edit.php' );
	}
	return $redirect;
}, 10, 3 );

// Remove dashboard clutter for everyone.
add_action( 'wp_dashboard_setup', function () {
	remove_meta_box( 'dashboard_primary', 'dashboard', 'side' );
	remove_meta_box( 'dashboard_quick_press', 'dashboard', 'side' );
	remove_meta_box( 'dashboard_site_health', 'dashboard', 'normal' );
} );

// Staff don't need to pick layouts; hide the pattern directory of remote patterns.
add_filter( 'should_load_remote_block_patterns', '__return_false' );

/*
 * ---------------------------------------------------------------------------
 * Comments off site-wide (the church doesn't moderate blog comments)
 * ---------------------------------------------------------------------------
 */
add_filter( 'comments_open', '__return_false', 20 );
add_filter( 'pings_open', '__return_false', 20 );
add_filter( 'comments_array', '__return_empty_array', 10 );
add_action( 'admin_bar_menu', fn( $bar ) => $bar->remove_node( 'comments' ), 999 );

/*
 * ---------------------------------------------------------------------------
 * Hardening
 * ---------------------------------------------------------------------------
 */
add_filter( 'xmlrpc_enabled', '__return_false' );
remove_action( 'wp_head', 'wp_generator' );

// Don't expose user lists to the public REST API.
add_filter( 'rest_endpoints', function ( $endpoints ) {
	if ( ! is_user_logged_in() ) {
		unset( $endpoints['/wp/v2/users'], $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
	}
	return $endpoints;
} );

// Block ?author=N enumeration.
add_action( 'template_redirect', function () {
	if ( isset( $_GET['author'] ) && ! is_admin() ) {
		wp_safe_redirect( home_url( '/' ), 301 );
		exit;
	}
} );

/*
 * ---------------------------------------------------------------------------
 * Performance
 * ---------------------------------------------------------------------------
 */
remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
remove_action( 'wp_print_styles', 'print_emoji_styles' );
remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
remove_action( 'admin_print_styles', 'print_emoji_styles' );
remove_action( 'wp_head', 'rsd_link' );
remove_action( 'wp_head', 'wlwmanifest_link' );

// Serve WebP for new uploads when the server supports it.
add_filter( 'image_editor_output_format', function ( $formats ) {
	$formats['image/jpeg'] = 'image/webp';
	$formats['image/png']  = 'image/webp';
	return $formats;
} );

/*
 * ---------------------------------------------------------------------------
 * Redirects from the old Ministry Designs URLs
 * ---------------------------------------------------------------------------
 * Nginx can do these too; keeping them here means they travel with the code.
 */
add_action( 'template_redirect', function () {
	if ( ! is_404() ) {
		return;
	}
	$path = trim( (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );

	$map = [
		'#^events-2(/.*)?$#'                        => '/events/',
		'#^sermons-t/(u_topic|u_scripture|category)(/.*)?$#' => '/sermons/',
		'#^sermons-t/u_speaker/([^/]+)/?$#'         => '/sermons/speaker/$1/',
		'#^sermons-t/([^/]+)/?$#'                   => '/sermons/$1/',
		'#^sermons-t/?$#'                           => '/sermons/',
		'#^news(/.*)?$#'                            => '/blog/',
		'#^small-groups/.+$#'                       => '/small-groups/',
	];

	foreach ( $map as $pattern => $target ) {
		if ( preg_match( $pattern, $path ) ) {
			wp_safe_redirect( home_url( preg_replace( $pattern, $target, $path ) ), 301 );
			exit;
		}
	}
} );
