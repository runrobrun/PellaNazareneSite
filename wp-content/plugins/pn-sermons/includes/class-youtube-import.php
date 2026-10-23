<?php
namespace PN_Sermons;

defined( 'ABSPATH' ) || exit;

/**
 * Turns YouTube videos into sermons.
 *
 * Two sources feed the same pipeline: the channel's public RSS feed (the latest 15 uploads, no API key, run twice
 * a day to catch new sermons) and the YouTube Data API (the whole history, run once by `wp pn-sermons backfill`,
 * see docs/sermons.md). Each video not already on the site becomes a sermon, as a draft for a person to review,
 * with its date, speaker, title, series, and scripture filled in where the description says so.
 */
class YouTube_Import {

	const HOOK                   = 'pn_sermons_youtube_import';
	const LAST_RUN_OPTION        = 'pn_sermons_last_import';
	const IMPORT_LOCK            = 'pn_sermons_youtube_import_lock';
	const REJECTED_OPTION_PREFIX = 'pn_sermons_rejected_youtube_';
	const FEED_URL               = 'https://www.youtube.com/feeds/videos.xml?channel_id=%s';

	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'wp_trash_post', array( __CLASS__, 'remember_trashed_video' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'remember_purged_video' ) );
		// Self-heal if the schedule was lost (e.g. plugin copied in without activation).
		add_action(
			'init',
			function () {
				if ( ! wp_next_scheduled( self::HOOK ) ) {
					self::schedule();
				}
			}
		);
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'twicedaily', self::HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public static function remember_trashed_video( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post || Post_Types::POST_TYPE !== $post->post_type ) {
			return;
		}
		$video_id = (string) get_post_meta( $post_id, 'pn_youtube_id', true );
		if ( 1 === preg_match( '/^[\w-]{11}$/', $video_id ) ) {
			add_option( self::REJECTED_OPTION_PREFIX . $video_id, $video_id, '', false );
		}
	}

	public static function remember_purged_video( int $post_id ): void {
		$post = get_post( $post_id );
		if ( $post && Post_Types::POST_TYPE === $post->post_type && 'trash' === $post->post_status ) {
			self::remember_trashed_video( $post_id );
		}
	}

	/**
	 * The scheduled job: the latest uploads from the RSS feed.
	 *
	 * @return int Number of sermons created.
	 */
	public static function run(): int {
		update_option( self::LAST_RUN_OPTION, time(), false );

		$channel = (string) Settings::get( 'channel_id' );
		if ( ! preg_match( '/^UC[\w-]{22}$/', $channel ) ) {
			self::log( 'No valid channel ID configured.' );
			return 0;
		}

		$response = wp_remote_get( sprintf( self::FEED_URL, rawurlencode( $channel ) ), array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			self::log( 'Feed request failed: ' . ( is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_response_code( $response ) ) );
			return 0;
		}

		$rows = self::import(
			self::parse_feed( wp_remote_retrieve_body( $response ) ),
			array(
				'filter' => (string) Settings::get( 'title_filter' ),
				'source' => 'rss',
			)
		);
		return count( array_filter( $rows, static fn( $row ) => 'created' === $row['action'] ) );
	}

	/**
	 * Create a sermon for each video that is not on the site yet.
	 *
	 * @param array<int,array<string,mixed>> $videos Videos as returned by YouTube_API::fetch_uploads() or parse_feed().
	 * @param array<string,mixed>            $opts   status (draft), filter (title patterns separated by |), exclude (same),
	 *                                               min_seconds, since / until (Y-m-d service dates), limit, dry_run,
	 *                                               thumbnails (true), source (api).
	 * @return array<int,array<string,mixed>> One row per video: id, date, action, reason, title, speaker, series, scripture, post_id, notes.
	 */
	public static function import( array $videos, array $opts = array() ): array {
		$opts = $opts + array(
			'status'      => 'draft',
			'filter'      => '',
			'exclude'     => '',
			'min_seconds' => 0,
			'since'       => '',
			'until'       => '',
			'limit'       => 0,
			'dry_run'     => false,
			'thumbnails'  => true,
			'source'      => 'api',
		);
		if ( ! in_array( $opts['status'], array( 'draft', 'pending', 'publish' ), true ) ) {
			$opts['status'] = 'draft';
		}

		if ( ! add_option( self::IMPORT_LOCK, time(), '', false ) ) {
			self::log( 'A YouTube import is already running.' );
			return array();
		}

		try {
			return self::import_locked( $videos, $opts );
		} finally {
			delete_option( self::IMPORT_LOCK );
		}
	}

	/** Import videos while holding the shared lock. */
	private static function import_locked( array $videos, array $opts ): array {
		$parser    = new Sermon_Parser( Sermon_Data::rules() );
		$overrides = Sermon_Data::overrides();
		$existing  = array_flip( self::existing_ids() );
		$legacy    = array_flip( self::existing_legacy_slugs() );
		$used      = array();
		$created   = 0;
		$rows      = array();

		// Oldest first, so sermons are created in the order they were preached.
		$work = array();
		foreach ( $videos as $video ) {
			$video['parsed'] = $parser->parse( $video );
			$work[]          = $video;
		}
		usort( $work, static fn( $a, $b ) => strcmp( $a['parsed']['date'] . $a['id'], $b['parsed']['date'] . $b['id'] ) );

		foreach ( $work as $video ) {
			$parsed = $video['parsed'];
			$row    = array(
				'id'        => $video['id'],
				'date'      => $parsed['date'],
				'action'    => 'skipped',
				'reason'    => '',
				'title'     => $parsed['title'],
				'speaker'   => $parsed['speaker'],
				'series'    => $parsed['series_name'],
				'scripture' => $parsed['scripture_ref'],
				'post_id'   => 0,
				'notes'     => '',
			);

			$reason = self::skip_reason( $video, $opts, $existing, $created );
			if ( '' !== $reason ) {
				$row['reason'] = $reason;
				$rows[]        = $row;
				continue;
			}

			$plan = self::plan( $video, $parsed, $overrides, $used, $legacy, $opts );
			$row  = array_merge(
				$row,
				array(
					'title'     => $plan['title'],
					'speaker'   => $plan['speaker'],
					'series'    => $plan['series_name'],
					'scripture' => $plan['scripture_ref'],
					'notes'     => implode( ' ', $plan['notes'] ),
				)
			);

			if ( $opts['dry_run'] ) {
				$row['action'] = 'would create';
				$row['reason'] = $plan['status'];
				++$created;
			} else {
				$post_id = self::create_post( $video, $plan, $opts );
				if ( $post_id ) {
					$row['action']            = 'created';
					$row['reason']            = $plan['status'];
					$row['post_id']           = $post_id;
					$existing[ $video['id'] ] = true;
					if ( $plan['legacy_slug'] ) {
						$legacy[ $plan['legacy_slug'] ] = true;
					}
					++$created;
				} else {
					$row['action'] = 'failed';
					$row['reason'] = 'could not create the post (see the log)';
				}
			}
			$rows[] = $row;
		}
		return $rows;
	}

	/** Why a video should not be imported, or an empty string if it should. */
	private static function skip_reason( array $video, array $opts, array $existing, int $created ): string {
		if ( $opts['limit'] && $created >= (int) $opts['limit'] ) {
			return 'over the limit';
		}
		if ( isset( $existing[ $video['id'] ] ) ) {
			return 'already imported';
		}
		if ( isset( $video['privacy'] ) && 'public' !== $video['privacy'] ) {
			return 'not public (' . $video['privacy'] . ')';
		}
		if ( isset( $video['live'] ) && 'none' !== $video['live'] ) {
			return 'live stream not finished';
		}
		if ( isset( $video['embeddable'] ) && false === $video['embeddable'] ) {
			return 'cannot be embedded';
		}
		if ( $opts['min_seconds'] && isset( $video['duration'] ) && (int) $video['duration'] < (int) $opts['min_seconds'] ) {
			return 'too short (' . (int) round( (int) $video['duration'] / 60 ) . ' min)';
		}
		$date = $video['parsed']['date'];
		if ( '' !== $opts['since'] && '' !== $date && $date < $opts['since'] ) {
			return 'before the start date';
		}
		if ( '' !== $opts['until'] && '' !== $date && $date > $opts['until'] ) {
			return 'after the end date';
		}
		if ( '' !== trim( (string) $opts['filter'] ) && ! self::title_matches( $video['title'], (string) $opts['filter'] ) ) {
			return 'title does not match the filter';
		}
		if ( '' !== trim( (string) $opts['exclude'] ) && self::title_matches( $video['title'], (string) $opts['exclude'] ) ) {
			return 'excluded by title';
		}
		return '';
	}

	/** True if the title contains any of the patterns (separated by |, case-insensitive). */
	public static function title_matches( string $title, string $patterns ): bool {
		foreach ( explode( '|', $patterns ) as $pattern ) {
			$pattern = trim( $pattern );
			if ( '' !== $pattern && false !== stripos( $title, $pattern ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Decide everything about the new sermon: what was parsed, with any hand-written override on top.
	 *
	 * @return array<string,mixed>
	 */
	private static function plan( array $video, array $parsed, array $overrides, array &$used, array $legacy, array $opts ): array {
		$plan = array(
			'title'         => $parsed['title'],
			'slug'          => '',
			'speaker'       => $parsed['speaker'],
			'series_name'   => $parsed['series_name'],
			'series_slug'   => $parsed['series_slug'],
			'scripture_ref' => $parsed['scripture_ref'],
			'books'         => $parsed['books'],
			'excerpt'       => $parsed['excerpt'],
			'content'       => '',
			'legacy_slug'   => '',
			'status'        => $opts['status'],
			'date'          => $parsed['date'],
			'notes'         => $parsed['notes'],
		);

		$override = Sermon_Data::override_for( $overrides, $video['id'], $parsed['date'] );
		$key      = $override ? md5( wp_json_encode( $override ) ) : '';
		$override_claimed = $override && ! empty( $override['legacy_slug'] ) && isset( $legacy[ sanitize_title( (string) $override['legacy_slug'] ) ] );
		if ( $override && ! isset( $used[ $key ] ) && ! $override_claimed ) {
			$used[ $key ] = true;
			foreach ( array( 'title', 'slug', 'speaker' ) as $field ) {
				if ( ! empty( $override[ $field ] ) ) {
					$plan[ $field ] = (string) $override[ $field ];
				}
			}
			if ( ! empty( $override['series'] ) ) {
				$plan['series_name'] = (string) ( $override['series']['name'] ?? '' );
				$plan['series_slug'] = (string) ( $override['series']['slug'] ?? '' );
			}
			if ( ! empty( $override['scripture'] ) ) {
				$refs                  = Sermon_Parser::scripture_refs( (string) $override['scripture'] );
				$plan['scripture_ref'] = (string) $override['scripture'];
				$plan['books']         = array_values( array_unique( array_column( $refs, 'book' ) ) );
			}
			if ( ! empty( $override['description'] ) ) {
				$text            = trim( (string) $override['description'] );
				$plan['content'] = "<!-- wp:paragraph -->\n<p>" . esc_html( $text ) . "</p>\n<!-- /wp:paragraph -->";
				$plan['excerpt'] = $text;
			}
			if ( ! empty( $override['legacy_slug'] ) && ! isset( $legacy[ (string) $override['legacy_slug'] ] ) ) {
				$plan['legacy_slug'] = sanitize_title( (string) $override['legacy_slug'] );
			}
			if ( ! empty( $override['status'] ) && in_array( $override['status'], array( 'draft', 'pending', 'publish' ), true ) ) {
				$plan['status'] = (string) $override['status'];
			}
			$plan['notes'][] = 'Details come from the old website.';
		}

		// Untrusted text from YouTube: no markup, sensible lengths.
		$plan['title']       = mb_substr( wp_strip_all_tags( $plan['title'] ), 0, 150 );
		$plan['speaker']     = mb_substr( wp_strip_all_tags( $plan['speaker'] ), 0, 80 );
		$plan['series_name'] = mb_substr( wp_strip_all_tags( $plan['series_name'] ), 0, 120 );
		$plan['excerpt']     = wp_trim_words( wp_strip_all_tags( $plan['excerpt'] ), 55 );
		if ( '' === $plan['title'] ) {
			$plan['title'] = 'Sermon ' . $video['id'];
		}
		return $plan;
	}

	/** Create the sermon and fill in its terms and details. Returns the post ID, or 0. */
	private static function create_post( array $video, array $plan, array $opts ): int {
		$date  = '' !== $plan['date'] ? $plan['date'] : gmdate( 'Y-m-d' );
		$local = $date . ' 12:00:00';
		$meta  = array(
			'pn_youtube_id'    => $video['id'],
			'pn_youtube_title' => mb_substr( wp_strip_all_tags( (string) $video['title'] ), 0, 200 ),
			'pn_import_source' => (string) $opts['source'],
			'pn_import_note'   => implode( ' ', $plan['notes'] ),
		);
		if ( '' !== $plan['scripture_ref'] ) {
			$meta['pn_scripture_ref'] = sanitize_text_field( $plan['scripture_ref'] );
		}
		if ( '' !== $plan['legacy_slug'] ) {
			$meta['pn_legacy_slug'] = $plan['legacy_slug'];
		}
		if ( '' !== (string) $video['description'] ) {
			$meta['pn_youtube_description'] = mb_substr( wp_strip_all_tags( (string) $video['description'] ), 0, 5000 );
		}

		$post = array(
			'post_type'     => Post_Types::POST_TYPE,
			'post_status'   => $plan['status'],
			'post_title'    => $plan['title'],
			'post_excerpt'  => $plan['excerpt'],
			'post_content'  => $plan['content'],
			'post_date'     => $local,
			'post_date_gmt' => get_gmt_from_date( $local ),
			'meta_input'    => $meta,
		);
		if ( '' !== $plan['slug'] ) {
			$post['post_name'] = sanitize_title( $plan['slug'] );
		}
		$post_id = wp_insert_post( $post, true );
		if ( is_wp_error( $post_id ) ) {
			self::log( 'Could not create a sermon for ' . $video['id'] . ': ' . $post_id->get_error_message() );
			return 0;
		}

		if ( '' !== $plan['speaker'] ) {
			self::assign_term( (int) $post_id, 'pn_speaker', $plan['speaker'], self::speaker_slug( $plan['speaker'] ) );
		}
		if ( '' !== $plan['series_name'] ) {
			self::assign_term( (int) $post_id, 'pn_series', $plan['series_name'], $plan['series_slug'] );
		}
		foreach ( $plan['books'] as $book ) {
			self::assign_term( (int) $post_id, 'pn_scripture', (string) $book, '', true );
		}

		if ( $opts['thumbnails'] && ! empty( $video['thumbnail'] ) ) {
			self::attach_thumbnail( (int) $post_id, $video, $plan['title'] );
		}

		/** Fires after a sermon is created from YouTube. */
		do_action( 'pn_sermons_imported', $post_id, $video );

		return (int) $post_id;
	}

	private static function speaker_slug( string $name ): string {
		foreach ( (array) ( Sermon_Data::rules()['speakers'] ?? array() ) as $speaker ) {
			if ( 0 === strcasecmp( (string) $speaker['name'], $name ) && ! empty( $speaker['slug'] ) ) {
				return (string) $speaker['slug'];
			}
		}
		return sanitize_title( $name );
	}

	/** Find or create a term and add it to the post (keeping any terms it already has when $append). */
	private static function assign_term( int $post_id, string $taxonomy, string $name, string $slug = '', bool $append = false ): void {
		$slug = '' !== $slug ? $slug : sanitize_title( $name );
		$term = get_term_by( 'slug', $slug, $taxonomy );
		if ( ! $term ) {
			$result = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug ) );
			if ( is_wp_error( $result ) ) {
				$existing = $result->get_error_data( 'term_exists' );
				if ( ! $existing ) {
					self::log( "Could not create the $taxonomy term '$name': " . $result->get_error_message() );
					return;
				}
				$term_id = (int) $existing;
			} else {
				$term_id = (int) $result['term_id'];
			}
		} else {
			$term_id = (int) $term->term_id;
		}
		wp_set_object_terms( $post_id, array( $term_id ), $taxonomy, $append );
	}

	/**
	 * @return array<int, array{id:string,title:string,published:string,description:string,thumbnail:string}>
	 */
	public static function parse_feed( string $xml ): array {
		$previous = libxml_use_internal_errors( true );
		$feed     = simplexml_load_string( $xml );
		libxml_use_internal_errors( $previous );
		if ( ! $feed ) {
			return array();
		}

		$videos = array();
		foreach ( $feed->entry as $entry ) {
			$yt    = $entry->children( 'http://www.youtube.com/xml/schemas/2015' );
			$media = $entry->children( 'http://search.yahoo.com/mrss/' )->group;
			$thumb = $media ? $media->children( 'http://search.yahoo.com/mrss/' )->thumbnail : null;

			$id = (string) $yt->videoId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- element name is defined by YouTube's feed schema.
			if ( ! preg_match( '/^[\w-]{11}$/', $id ) ) {
				continue;
			}
			$videos[] = array(
				'id'          => $id,
				'title'       => trim( (string) $entry->title ),
				'published'   => (string) $entry->published,
				'description' => $media ? trim( (string) $media->children( 'http://search.yahoo.com/mrss/' )->description ) : '',
				'thumbnail'   => $thumb ? (string) $thumb->attributes()->url : '',
			);
		}
		return $videos;
	}

	/** YouTube IDs of every sermon on the site, including drafts and the trash (a sermon deleted on purpose must not come back). */
	private static function existing_ids(): array {
		global $wpdb;
		$ids = (array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one read of one meta key per import run.
			$wpdb->prepare(
				"SELECT pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = %s AND p.post_status <> 'auto-draft'",
				'pn_youtube_id',
				Post_Types::POST_TYPE
			)
		);
		$rejected = (array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- read the import rejection markers once per run.
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( self::REJECTED_OPTION_PREFIX ) . '%'
			)
		);
		return array_merge( $ids, $rejected );
	}

	/** Old addresses already recorded on a sermon, so two sermons never claim the same one. */
	private static function existing_legacy_slugs(): array {
		global $wpdb;
		return (array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- same.
			$wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", 'pn_legacy_slug' )
		);
	}

	private static function attach_thumbnail( int $post_id, array $video, string $title ): void {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$urls = (array) apply_filters(
			'pn_sermons_thumbnail_urls',
			array( 'https://i.ytimg.com/vi/' . $video['id'] . '/maxresdefault.jpg', (string) $video['thumbnail'] ),
			$video
		);
		foreach ( $urls as $url ) {
			$id = media_sideload_image( (string) $url, $post_id, $title, 'id' );
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
