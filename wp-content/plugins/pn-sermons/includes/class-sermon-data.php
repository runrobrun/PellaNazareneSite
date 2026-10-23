<?php
namespace PN_Sermons;

defined( 'ABSPATH' ) || exit;

/**
 * The importer's hand-edited knowledge, kept in two JSON files so it can be changed in a pull request without
 * touching code: data/sermon-rules.json (speakers, known series) and data/sermon-overrides.json (details for
 * specific videos, such as the sermons that existed on the old website).
 */
class Sermon_Data {

	/** @return array<string,mixed> */
	public static function rules(): array {
		return (array) apply_filters( 'pn_sermons_rules', self::read( 'sermon-rules.json' ) );
	}

	/** @return array<int,array<string,mixed>> */
	public static function overrides(): array {
		$data = self::read( 'sermon-overrides.json' );
		return (array) apply_filters( 'pn_sermons_overrides', (array) ( $data['overrides'] ?? array() ) );
	}

	/**
	 * The override for a video, matched by its YouTube ID or by the date of the service.
	 *
	 * @param array<int,array<string,mixed>> $overrides Result of overrides().
	 * @return array<string,mixed>|null
	 */
	public static function override_for( array $overrides, string $youtube_id, string $service_date ): ?array {
		foreach ( $overrides as $override ) {
			$match = (array) ( $override['match'] ?? array() );
			if ( ! empty( $match['youtube_id'] ) && $match['youtube_id'] === $youtube_id ) {
				return $override;
			}
		}
		foreach ( $overrides as $override ) {
			$match = (array) ( $override['match'] ?? array() );
			if ( ! empty( $match['service_date'] ) && '' !== $service_date && $match['service_date'] === $service_date ) {
				return $override;
			}
		}
		return null;
	}

	/** @return array<string,mixed> */
	private static function read( string $file ): array {
		$path = PN_SERMONS_DIR . 'data/' . $file;
		if ( ! is_readable( $path ) ) {
			return array();
		}
		$data = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file shipped with the plugin.
		return is_array( $data ) ? $data : array();
	}
}
