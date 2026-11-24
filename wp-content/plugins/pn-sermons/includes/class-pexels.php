<?php
namespace PN_Sermons;

defined( 'ABSPATH' ) || exit;

/**
 * Finds a featured image for a drafted blog post on Pexels (FEAT-9) and adds it to the media library.
 *
 * Pexels photos are free to use without attribution; we still credit the photographer in the caption.
 * Needs PN_PEXELS_API_KEY in wp-config.php. The key is sent only to the Pexels API and never logged.
 */
class Pexels {

	const API_BASE    = 'https://api.pexels.com/v1';
	const IMAGE_HOST  = 'images.pexels.com';
	const USED_OPTION = 'pn_sermons_pexels_used';
	const MIN_WIDTH   = 1600;

	public static function configured(): bool {
		return '' !== self::key();
	}

	/**
	 * Search, download the best landscape photo not used before, and attach it to $post_id.
	 * Returns the attachment ID, or 0 when nothing suitable was found (the caller falls back).
	 */
	public static function featured_image( string $query, int $post_id, string $title ): int {
		$query = trim( sanitize_text_field( $query ) );
		if ( ! self::configured() || '' === $query ) {
			return 0;
		}
		$photo = self::choose( self::search( $query ) );
		if ( ! $photo ) {
			YouTube_Import::log( "Pexels had no suitable photo for \"{$query}\"." );
			return 0;
		}
		return self::attach( $photo, $post_id, $title );
	}

	/** Photos from the Pexels search API, or an empty list on any problem. */
	public static function search( string $query ): array {
		$base     = defined( 'PN_PEXELS_API_BASE' ) && 'production' !== wp_get_environment_type() ? untrailingslashit( (string) PN_PEXELS_API_BASE ) : self::API_BASE; // Tests only.
		$url      = add_query_arg(
			array(
				'query'       => rawurlencode( $query ),
				'orientation' => 'landscape',
				'size'        => 'large',
				'per_page'    => 15,
			),
			$base . '/search'
		);
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array( 'Authorization' => self::key() ),
			)
		);
		if ( is_wp_error( $response ) ) {
			YouTube_Import::log( 'Could not reach Pexels: ' . $response->get_error_message() );
			return array();
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			YouTube_Import::log( "Pexels search failed (HTTP {$code})." );
			return array();
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return ( is_array( $body ) && isset( $body['photos'] ) && is_array( $body['photos'] ) ) ? $body['photos'] : array();
	}

	/** The first wide, large photo we have not used before, with a safe image address. */
	public static function choose( array $photos ): ?array {
		$used = array_map( 'intval', (array) get_option( self::USED_OPTION, array() ) );
		foreach ( $photos as $photo ) {
			if ( ! is_array( $photo ) || empty( $photo['id'] ) || in_array( (int) $photo['id'], $used, true ) ) {
				continue;
			}
			$width  = (int) ( $photo['width'] ?? 0 );
			$height = (int) ( $photo['height'] ?? 0 );
			$src    = (string) ( $photo['src']['large2x'] ?? '' );
			if ( $width < self::MIN_WIDTH || $width <= $height || ! self::safe_image_url( $src ) ) {
				continue;
			}
			return $photo;
		}
		return null;
	}

	/** Only https addresses on Pexels' own image host are downloaded. */
	public static function safe_image_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		return is_array( $parts )
			&& 'https' === ( $parts['scheme'] ?? '' )
			&& self::IMAGE_HOST === strtolower( (string) ( $parts['host'] ?? '' ) )
			&& empty( $parts['user'] ) && empty( $parts['port'] );
	}

	private static function attach( array $photo, int $post_id, string $title ): int {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( (string) $photo['src']['large2x'], 30 );
		if ( is_wp_error( $tmp ) ) {
			YouTube_Import::log( 'Could not download the Pexels photo: ' . $tmp->get_error_message() );
			return 0;
		}
		if ( 'image/jpeg' !== wp_get_image_mime( $tmp ) ) {
			wp_delete_file( $tmp );
			YouTube_Import::log( 'The Pexels download was not a JPEG; skipped.' );
			return 0;
		}

		$photographer = sanitize_text_field( (string) ( $photo['photographer'] ?? '' ) );
		/* translators: %s: the photographer's name. */
		$caption = '' !== $photographer ? sprintf( __( 'Photo by %s on Pexels', 'pn-sermons' ), $photographer ) : __( 'Photo from Pexels', 'pn-sermons' );
		$file    = array(
			'name'     => 'pexels-' . absint( $photo['id'] ) . '.jpg',
			'tmp_name' => $tmp,
		);
		$id      = media_handle_sideload( $file, $post_id, $title, array( 'post_excerpt' => $caption ) );
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp );
			YouTube_Import::log( 'Could not add the Pexels photo to the media library: ' . $id->get_error_message() );
			return 0;
		}

		$alt = sanitize_text_field( (string) ( $photo['alt'] ?? '' ) );
		update_post_meta( $id, '_wp_attachment_image_alt', '' !== $alt ? $alt : $title );
		update_post_meta( $id, 'pn_pexels_id', absint( $photo['id'] ) );
		$page = esc_url_raw( (string) ( $photo['url'] ?? '' ) );
		if ( '' !== $page && str_starts_with( $page, 'https://www.pexels.com/' ) ) {
			update_post_meta( $id, 'pn_pexels_url', $page );
		}

		$used   = array_map( 'intval', (array) get_option( self::USED_OPTION, array() ) );
		$used[] = absint( $photo['id'] );
		update_option( self::USED_OPTION, array_slice( array_unique( $used ), -300 ), false );
		return (int) $id;
	}

	private static function key(): string {
		return defined( 'PN_PEXELS_API_KEY' ) ? (string) PN_PEXELS_API_KEY : '';
	}
}
