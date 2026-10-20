<?php
namespace PN_Sermons;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the channel's public RSS feed (latest 15 uploads, no API key) and
 * creates a draft sermon for each video not already imported.
 *
 * Backfilling older sermons needs the YouTube Data API (uploads playlist);
 * see docs/setup.md. The feed covers everything going forward.
 */
class YouTube_Import {

	const HOOK            = 'pn_sermons_youtube_import';
	const LAST_RUN_OPTION = 'pn_sermons_last_import';
	const FEED_URL        = 'https://www.youtube.com/feeds/videos.xml?channel_id=%s';

	public static function init(): void {
		add_action( self::HOOK, [ __CLASS__, 'run' ] );
		// Self-heal if the schedule was lost (e.g. plugin copied in without activation).
		add_action( 'init', function () {
			if ( ! wp_next_scheduled( self::HOOK ) ) {
				self::schedule();
			}
		} );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'twicedaily', self::HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * @return int Number of sermon drafts created.
	 */
	public static function run(): int {
		update_option( self::LAST_RUN_OPTION, time(), false );

		$channel = (string) Settings::get( 'channel_id' );
		if ( ! preg_match( '/^UC[\w-]{22}$/', $channel ) ) {
			self::log( 'No valid channel ID configured.' );
			return 0;
		}

		$response = wp_remote_get( sprintf( self::FEED_URL, rawurlencode( $channel ) ), [ 'timeout' => 15 ] );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			self::log( 'Feed request failed: ' . ( is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_response_code( $response ) ) );
			return 0;
		}

		$videos = self::parse_feed( wp_remote_retrieve_body( $response ) );
		$filter = trim( (string) Settings::get( 'title_filter' ) );
		$count  = 0;

		// Oldest first, so drafts appear in preaching order.
		foreach ( array_reverse( $videos ) as $video ) {
			if ( $filter && false === stripos( $video['title'], $filter ) ) {
				continue;
			}
			if ( self::already_imported( $video['id'] ) ) {
				continue;
			}
			if ( self::create_draft( $video ) ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * @return array<int, array{id:string,title:string,published:string,description:string,thumbnail:string}>
	 */
	public static function parse_feed( string $xml ): array {
		$previous = libxml_use_internal_errors( true );
		$feed     = simplexml_load_string( $xml );
		libxml_use_internal_errors( $previous );
		if ( ! $feed ) {
			return [];
		}

		$videos = [];
		foreach ( $feed->entry as $entry ) {
			$yt    = $entry->children( 'http://www.youtube.com/xml/schemas/2015' );
			$media = $entry->children( 'http://search.yahoo.com/mrss/' )->group;
			$thumb = $media ? $media->children( 'http://search.yahoo.com/mrss/' )->thumbnail : null;

			$id = (string) $yt->videoId;
			if ( ! preg_match( '/^[\w-]{11}$/', $id ) ) {
				continue;
			}
			$videos[] = [
				'id'          => $id,
				'title'       => trim( (string) $entry->title ),
				'published'   => (string) $entry->published,
				'description' => $media ? trim( (string) $media->children( 'http://search.yahoo.com/mrss/' )->description ) : '',
				'thumbnail'   => $thumb ? (string) $thumb->attributes()->url : '',
			];
		}
		return $videos;
	}

	private static function already_imported( string $video_id ): bool {
		$existing = get_posts( [
			'post_type'      => Post_Types::POST_TYPE,
			'post_status'    => 'any',
			'meta_key'       => 'pn_youtube_id',
			'meta_value'     => $video_id,
			'fields'         => 'ids',
			'posts_per_page' => 1,
			'no_found_rows'  => true,
		] );
		return ! empty( $existing );
	}

	private static function create_draft( array $video ): int {
		$timestamp = strtotime( $video['published'] ) ?: time();

		$post_id = wp_insert_post( [
			'post_type'     => Post_Types::POST_TYPE,
			'post_status'   => 'draft',
			'post_title'    => $video['title'],
			'post_excerpt'  => wp_trim_words( $video['description'], 55 ),
			'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $timestamp ),
			'post_date'     => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $timestamp ) ),
			'meta_input'    => [ 'pn_youtube_id' => $video['id'] ],
		], true );

		if ( is_wp_error( $post_id ) ) {
			self::log( 'Could not create draft for ' . $video['id'] . ': ' . $post_id->get_error_message() );
			return 0;
		}

		if ( $video['thumbnail'] ) {
			self::attach_thumbnail( $post_id, 'https://i.ytimg.com/vi/' . $video['id'] . '/maxresdefault.jpg', $video['thumbnail'], $video['title'] );
		}

		/** Fires after a sermon draft is created from YouTube. */
		do_action( 'pn_sermons_imported', $post_id, $video );

		return (int) $post_id;
	}

	private static function attach_thumbnail( int $post_id, string $preferred, string $fallback, string $title ): void {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		foreach ( [ $preferred, $fallback ] as $url ) {
			$id = media_sideload_image( $url, $post_id, $title, 'id' );
			if ( ! is_wp_error( $id ) ) {
				set_post_thumbnail( $post_id, (int) $id );
				return;
			}
		}
	}

	public static function log( string $message ): void {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( '[pn-sermons] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}
}
