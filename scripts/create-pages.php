<?php
/**
 * Creates the seven site pages from the theme's page patterns. Run it through scripts/create-pages.sh.
 *
 * Safe to re-run: a page that already exists is left alone, so nothing staff have edited is overwritten.
 * To deliberately replace a page with the pattern's current text, name it: create-pages.sh --replace about-us
 *
 * @package pella-nazarene
 */

defined( 'ABSPATH' ) || exit;

$pn_pages = array(
	'home'      => 'A church in Pella, Iowa',
	'new-here'  => 'New Here',
	'about-us'  => 'About Us',
	'kids'      => 'Kids',
	'giving'    => 'Giving',
	'contact'   => 'Contact',
	'resources' => 'Resources',
	'events'    => 'Events',
	'small-groups' => 'Small Groups',
	'blog'      => 'Blog',
	'prayer'    => 'Prayer Request',
	'privacy'   => 'Privacy',
);

if ( 'pella-nazarene' !== get_stylesheet() ) {
	WP_CLI::error( 'The Pella Nazarene theme is not active here.' );
}

$pn_replace = array_map( 'strval', (array) ( $args ?? array() ) );
foreach ( $pn_replace as $pn_slug ) {
	if ( ! isset( $pn_pages[ $pn_slug ] ) ) {
		WP_CLI::error( "Unknown page '$pn_slug'. Pages: " . implode( ', ', array_keys( $pn_pages ) ) );
	}
}

// Photos first, so the page patterns can use them. Already-loaded photos are left alone.
$pn_photo_errors = array();
$pn_photos       = pn_import_photos( $pn_photo_errors );
WP_CLI::log( count( $pn_photos ) . ' of ' . count( pn_photo_manifest() ) . ' photos are in the media library.' );
if ( $pn_photo_errors ) {
	foreach ( $pn_photo_errors as $pn_slug => $pn_reason ) {
		WP_CLI::warning( "Photo '$pn_slug': $pn_reason" );
	}
	$pn_hint = '';
	if ( false !== stripos( implode( ' ', $pn_photo_errors ), 'writable' ) || false !== stripos( implode( ' ', $pn_photo_errors ), 'permission' ) ) {
		$pn_hint = ' The uploads folder is probably owned by the web user: run this as that user, for example PN_WP_USER=www-data scripts/create-pages.sh ...';
	}
	WP_CLI::error( 'Could not load ' . count( $pn_photo_errors ) . ' photo(s). No pages were created or replaced.' . $pn_hint );
}

$pn_ids = array();
foreach ( $pn_pages as $pn_slug => $pn_title ) {
	$pn_file = get_theme_file_path( "patterns/page-$pn_slug.php" );
	if ( ! is_file( $pn_file ) ) {
		WP_CLI::error( "Missing pattern file for '$pn_slug'." );
	}
	ob_start();
	include $pn_file;
	$pn_content = trim( (string) ob_get_clean() );

	// WordPress renames a trashed page's address to "<slug>__trashed".
	if ( get_page_by_path( $pn_slug . '__trashed', OBJECT, 'page' ) && ! get_page_by_path( $pn_slug, OBJECT, 'page' ) ) {
		WP_CLI::warning( "'$pn_slug' is in the trash; restore or delete it first. Skipped." );
		continue;
	}
	$pn_existing = get_page_by_path( $pn_slug, OBJECT, 'page' );
	if ( $pn_existing ) {
		if ( 'page' !== $pn_existing->post_type ) {
			WP_CLI::error( "'$pn_slug' conflicts with an attachment; rename or delete the media item first." );
		}
		$pn_ids[ $pn_slug ] = $pn_existing->ID;
		if ( in_array( $pn_slug, $pn_replace, true ) ) {
			$pn_updated = wp_update_post(
				array(
					'ID'           => $pn_existing->ID,
					'post_content' => wp_slash( $pn_content ),
				),
				true
			);
			if ( is_wp_error( $pn_updated ) ) {
				WP_CLI::error( "Could not replace '$pn_slug': " . $pn_updated->get_error_message() );
			}
			WP_CLI::log( "Replaced the text of '$pn_slug'." );
		} else {
			WP_CLI::log( "Kept '$pn_slug' (already exists)." );
		}
		continue;
	}
	$pn_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_name'    => $pn_slug,
			'post_title'   => $pn_title,
			'post_content' => wp_slash( $pn_content ),
		),
		true
	);
	if ( is_wp_error( $pn_id ) ) {
		WP_CLI::error( "Could not create '$pn_slug': " . $pn_id->get_error_message() );
	}
	$pn_ids[ $pn_slug ] = $pn_id;
	WP_CLI::log( "Created '$pn_slug'." );
}

// Make Home the front page, unless staff have already chosen one.
if (
	isset( $pn_ids['home'] )
	&& 'publish' === get_post_status( $pn_ids['home'] )
	&& 'page' !== get_option( 'show_on_front' )
) {
	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', $pn_ids['home'] );
	WP_CLI::log( 'Home is now the front page.' );
}

// The blog lives at /blog/: make that page the posts page, unless staff have already chosen one.
if ( isset( $pn_ids['blog'] ) && 'publish' === get_post_status( $pn_ids['blog'] ) && ! (int) get_option( 'page_for_posts' ) ) {
	update_option( 'page_for_posts', $pn_ids['blog'] );
	WP_CLI::log( 'The Blog page is now the posts page.' );
}

// The menu and every link use addresses like /new-here/. With WordPress's "Plain" permalinks every one of them
// shows the home page, so set pretty links if none are set (a choice staff already made is left alone).
if ( '' === (string) get_option( 'permalink_structure' ) ) {
	global $wp_rewrite;
	$wp_rewrite->set_permalink_structure( '/%postname%/' );
	WP_CLI::log( 'Permalinks were "Plain"; set them to /%postname%/.' );
}
wp_cache_flush();
WP_CLI::success( 'Pages are in place.' );
