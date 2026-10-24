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
add_action(
	'admin_menu',
	function () {
		if ( current_user_can( 'manage_options' ) ) {
			return;
		}
		foreach ( array( 'tools.php', 'edit-comments.php', 'index.php' ) as $slug ) {
			remove_menu_page( $slug );
		}
	},
	999
);

// Send non-admins to the Posts list instead of the Dashboard.
add_filter(
	'login_redirect',
	function ( $redirect, $requested, $user ) {
		if ( $user instanceof WP_User && ! user_can( $user, 'manage_options' ) && empty( $requested ) ) {
			return admin_url( 'edit.php' );
		}
		return $redirect;
	},
	10,
	3
);

// Remove dashboard clutter for everyone.
add_action(
	'wp_dashboard_setup',
	function () {
		remove_meta_box( 'dashboard_primary', 'dashboard', 'side' );
		remove_meta_box( 'dashboard_quick_press', 'dashboard', 'side' );
		remove_meta_box( 'dashboard_site_health', 'dashboard', 'normal' );
	}
);

// Staff don't need to pick layouts; hide the pattern directory of remote patterns.
add_filter( 'should_load_remote_block_patterns', '__return_false' );

/*
 * ---------------------------------------------------------------------------
 * Pastors write, an Editor publishes
 * ---------------------------------------------------------------------------
 * WordPress lets an Author publish their own posts. Here an Author can write and save posts and sermons but
 * cannot publish them (they are submitted for review); Editors and administrators publish.
 */
add_filter(
	'user_has_cap',
	static function ( $allcaps ) {
		if ( empty( $allcaps['publish_pages'] ) ) { // Only Editors and administrators have this.
			$allcaps['publish_posts'] = false;
		}
		return $allcaps;
	}
);

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
add_filter(
	'rest_endpoints',
	function ( $endpoints ) {
		if ( ! is_user_logged_in() ) {
			unset( $endpoints['/wp/v2/users'], $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
		}
		return $endpoints;
	}
);

// Block ?author=N enumeration.
add_action(
	'template_redirect',
	function () {
		if ( isset( $_GET['author'] ) && ! is_admin() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presence check only; nothing is processed.
			wp_safe_redirect( home_url( '/' ), 301 );
			exit;
		}
	}
);

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
add_filter(
	'image_editor_output_format',
	function ( $formats ) {
		$formats['image/jpeg'] = 'image/webp';
		$formats['image/png']  = 'image/webp';
		return $formats;
	}
);

/*
 * ---------------------------------------------------------------------------
 * Redirects from the old Ministry Designs URLs
 * ---------------------------------------------------------------------------
 * The full list and the expected outcome of each URL: deploy/redirects/expected.csv (checked by
 * scripts/check-redirects.sh). Nginx answers the fixed rules before PHP runs (deploy/nginx/pn-redirects.conf);
 * the same fixed rules are repeated here as a safety net for local development, and the rules that depend on
 * what exists (a sermon, a speaker, a series) can only be answered here.
 */

/**
 * What to do with a request for an old Ministry Designs URL.
 *
 * @param string $path The URL path without the leading and trailing slash, e.g. "sermons-t/u_speaker/rob-hammann".
 * @return array{status:int,url?:string}|null Null if it is not an old URL.
 */
function pn_legacy_url_action( string $path ): ?array {
	// Archives that never had any content: gone for good (HTTP 410), so search engines drop them quickly.
	if ( preg_match( '#^(?:sermons-t/(?:u_topic|u_scripture)|events-2/(?:category|tag)|small-groups/(?:tag|category|u_group-details))(?:/|$)#i', $path ) ) {
		return array( 'status' => 410 );
	}

	// Old sections with a fixed replacement.
	$fixed = array(
		'#^events-2(?:/.*)?$#i' => '/events/',
		'#^news(?:/.*)?$#i'     => '/blog/',
		'#^sermons-t/?$#i'      => '/sermons/',
		'#^small-groups/.+$#i'  => '/small-groups/',
	);
	foreach ( $fixed as $pattern => $target ) {
		if ( preg_match( $pattern, $path ) ) {
			return array(
				'status' => 301,
				'url'    => home_url( $target ),
			);
		}
	}

	// Sermons, speakers, and series: go to the new page if it exists, otherwise to the sermons list, so a
	// visitor is never sent from one missing page to another.
	$fallback = array(
		'status' => 301,
		'url'    => home_url( '/sermons/' ),
	);
	$term_url = static function ( string $slug, string $taxonomy ) {
		$term = get_term_by( 'slug', sanitize_title( $slug ), $taxonomy );
		$url  = $term ? get_term_link( $term ) : '';
		return is_string( $url ) ? $url : '';
	};

	if ( preg_match( '#^sermons-t/u_speaker/([^/]+)/?$#i', $path, $m ) ) {
		$url = $term_url( $m[1], 'pn_speaker' );
		return '' !== $url ? array(
			'status' => 301,
			'url'    => $url,
		) : $fallback;
	}
	if ( preg_match( '#^sermons-t/category/([^/]+)/?$#i', $path, $m ) ) {
		$url = $term_url( $m[1], 'pn_series' );
		return '' !== $url ? array(
			'status' => 301,
			'url'    => $url,
		) : $fallback;
	}
	if ( preg_match( '#^sermons-t/category(?:/.*)?$#i', $path ) ) {
		return $fallback;
	}
	if ( preg_match( '#^sermons-t/([^/]+)/?$#i', $path, $m ) ) {
		$slug  = sanitize_title( $m[1] );
		$found = get_posts(
			array(
				'post_type'      => 'pn_sermon',
				'post_status'    => 'publish',
				'name'           => $slug,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		if ( ! $found ) {
			// The sermon importer records a sermon's old address here when its new slug differs.
			$found = get_posts(
				array(
					'post_type'      => 'pn_sermon',
					'post_status'    => 'publish',
					'meta_key'       => 'pn_legacy_slug', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- only runs for a request that is already a 404 for an old URL.
					'meta_value'     => $slug, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- same.
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
		}
		return $found ? array(
			'status' => 301,
			'url'    => get_permalink( $found[0] ),
		) : $fallback;
	}
	return null;
}

add_action(
	'template_redirect',
	function () {
		if ( ! is_404() ) {
			return;
		}
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path   = trim( (string) wp_parse_url( $uri, PHP_URL_PATH ), '/' );
		$action = pn_legacy_url_action( $path );
		if ( ! $action ) {
			return;
		}
		if ( 410 === $action['status'] ) {
			// The theme's friendly "not found" page is shown, with the honest status.
			status_header( 410 );
			nocache_headers();
			return;
		}
		wp_safe_redirect( $action['url'], 301 );
		exit;
	}
);

/*
 * ---------------------------------------------------------------------------
 * Non-production safety (local and staging)
 * ---------------------------------------------------------------------------
 * Set WP_ENVIRONMENT_TYPE in wp-config.php ('local', 'staging'; production
 * is the default). Outside production, never email real people and keep
 * search engines out.
 */
if ( 'production' !== wp_get_environment_type() ) {
	// Short-circuit wp_mail(): report failure, send nothing.
	add_filter( 'pre_wp_mail', '__return_false' );
	add_filter( 'wp_robots', 'wp_robots_no_robots' );
	add_action(
		'send_headers',
		function () {
			header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
		}
	);
	add_action(
		'admin_notices',
		function () {
			printf(
				'<div class="notice notice-warning"><p><strong>%s environment.</strong> Email is disabled and the site is hidden from search engines.</p></div>',
				esc_html( wp_get_environment_type() )
			);
		}
	);
}
