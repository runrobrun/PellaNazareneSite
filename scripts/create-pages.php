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
	'home'      => 'Home',
	'new-here'  => 'New Here',
	'about-us'  => 'About Us',
	'kids'      => 'Kids',
	'giving'    => 'Giving',
	'contact'   => 'Contact',
	'resources' => 'Resources',
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
		$pn_ids[ $pn_slug ] = $pn_existing->ID;
		if ( in_array( $pn_slug, $pn_replace, true ) ) {
			wp_update_post(
				array(
					'ID'           => $pn_existing->ID,
					'post_content' => wp_slash( $pn_content ),
				)
			);
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
if ( isset( $pn_ids['home'] ) && 'page' !== get_option( 'show_on_front' ) ) {
	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', $pn_ids['home'] );
	WP_CLI::log( 'Home is now the front page.' );
}
WP_CLI::success( 'Pages are in place.' );
