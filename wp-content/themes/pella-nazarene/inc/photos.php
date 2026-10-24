<?php
/**
 * Page photos: the web-sized files in assets/images/photos (see manifest.json) are loaded into the media
 * library when the pages are created, so staff can swap them in the editor and WordPress makes the smaller
 * sizes, srcset and width/height itself.
 *
 * @package pella-nazarene
 */

defined( 'ABSPATH' ) || exit;

/** The photos listed in the manifest, keyed by slug. */
function pn_photo_manifest(): array {
	static $photos = null;
	if ( null === $photos ) {
		$photos = array();
		$json   = file_get_contents( get_theme_file_path( 'assets/images/photos/manifest.json' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file in the theme.
		$data   = json_decode( (string) $json, true );
		foreach ( (array) ( $data['photos'] ?? array() ) as $photo ) {
			$photos[ (string) $photo['slug'] ] = $photo;
		}
	}
	return $photos;
}

/** The media-library item for a photo, or 0 if it has not been loaded yet. */
function pn_photo_id( string $slug ): int {
	$found = get_posts(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_key'       => '_pn_photo', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one small lookup; the front page is page-cached.
			'meta_value'     => $slug, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- same.
		)
	);
	return $found ? (int) $found[0] : 0;
}

/**
 * Loads every manifest photo that is not in the media library yet. Safe to repeat; a photo that is already
 * there is left alone, so staff changes to its title, caption or alt text are kept.
 *
 * @return array<string,int> slug => media ID
 */
function pn_import_photos(): array {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$ids = array();
	foreach ( pn_photo_manifest() as $slug => $photo ) {
		$id = pn_photo_id( $slug );
		if ( ! $id ) {
			$file = get_theme_file_path( "assets/images/photos/$slug.webp" );
			if ( ! is_file( $file ) ) {
				continue;
			}
			$tmp = wp_tempnam( $file );
			copy( $file, $tmp );
			$id = media_handle_sideload(
				array(
					'name'     => "$slug.webp",
					'tmp_name' => $tmp,
				),
				0,
				ucfirst( str_replace( '-', ' ', $slug ) )
			);
			if ( is_wp_error( $id ) ) {
				wp_delete_file( $tmp );
				continue;
			}
			update_post_meta( $id, '_pn_photo', $slug );
			update_post_meta( $id, '_wp_attachment_image_alt', (string) $photo['alt'] );
		}
		$ids[ $slug ] = (int) $id;
	}
	return $ids;
}

/** How wide a photo is drawn at desktop width, so phones are not sent more pixels than they need. */
function pn_photo_sizes( string $variant ): string {
	$sizes = array(
		'hero'   => '(max-width: 781px) 100vw, 580px',
		'col'    => '(max-width: 781px) 100vw, 340px',
		'avatar' => '(max-width: 781px) 30vw, 216px',
	);
	return $sizes[ $variant ] ?? '';
}

/**
 * Block markup for one photo (an Image block in the page). Empty if the photo has not been loaded.
 *
 * @param string $slug    Photo slug from manifest.json.
 * @param string $variant full (content width), hero, col (half a column of text), or avatar.
 * @param string $ratio   Optional crop, like 3/2; the picture fills it.
 */
function pn_photo_block( string $slug, string $variant = 'full', string $ratio = '' ): string {
	$id = pn_photo_id( $slug );
	if ( ! $id ) {
		return '';
	}
	$class = 'pn-photo pn-photo--' . $variant;
	$attrs = array(
		'id'              => $id,
		'sizeSlug'        => 'large',
		'linkDestination' => 'none',
		'className'       => $class,
	);
	$style = '';
	if ( '' !== $ratio ) {
		$attrs['aspectRatio'] = $ratio;
		$attrs['scale']       = 'cover';
		$style                = sprintf( ' style="aspect-ratio:%s;object-fit:cover"', esc_attr( $ratio ) );
	}
	return sprintf(
		"<!-- wp:image %s -->\n<figure class=\"wp-block-image size-large %s\"><img src=\"%s\" alt=\"%s\" class=\"wp-image-%d\"%s/></figure>\n<!-- /wp:image -->\n",
		wp_json_encode( $attrs ),
		esc_attr( $class ),
		esc_url( (string) wp_get_attachment_image_url( $id, 'large' ) ),
		esc_attr( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ),
		$id,
		$style
	);
}

// Tell the browser how wide each page photo really is on screen.
add_filter(
	'render_block_core/image',
	function ( $html ) {
		$tags = new WP_HTML_Tag_Processor( (string) $html );
		if ( ! $tags->next_tag( 'img' ) ) {
			return $html;
		}
		$class          = (string) $tags->get_attribute( 'class' );
		$figure_variant = '';
		if ( preg_match( '/pn-photo--(hero|col|avatar)/', (string) $html, $m ) ) {
			$figure_variant = $m[1];
		}
		if ( '' !== $figure_variant && ! $tags->get_attribute( 'sizes' ) && false !== strpos( $class, 'wp-image-' ) ) {
			$tags->set_attribute( 'sizes', pn_photo_sizes( $figure_variant ) );
		}
		return $tags->get_updated_html();
	}
);

// WordPress skips lazy-loading for the first three images on every page. Here only the home page's hero loads
// right away; every other photo waits until it is near the screen.
add_filter(
	'wp_omit_loading_attr_threshold',
	function () {
		return is_front_page() ? 1 : 0;
	}
);

// The home page photo is the largest thing above the fold: fetch it first, at the size the page will use.
add_action(
	'wp_head',
	function () {
		if ( ! is_front_page() ) {
			return;
		}
		$id = pn_photo_id( 'home-hero' );
		if ( ! $id ) {
			return;
		}
		printf(
			'<link rel="preload" as="image" href="%s" imagesrcset="%s" imagesizes="%s" fetchpriority="high">' . "\n",
			esc_url( (string) wp_get_attachment_image_url( $id, 'large' ) ),
			esc_attr( (string) wp_get_attachment_image_srcset( $id, 'large' ) ),
			esc_attr( pn_photo_sizes( 'hero' ) )
		);
	},
	2
);
