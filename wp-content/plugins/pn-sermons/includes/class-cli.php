<?php
namespace PN_Sermons;

defined( 'ABSPATH' ) || exit;

/**
 * Sermon commands for the server: `wp pn-sermons backfill`, `status` and `draft-post`.
 * Full instructions: docs/sermons.md.
 */
class CLI {

	/**
	 * Show whether the importer is ready and what is on the site.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pn-sermons status
	 */
	public function status(): void {
		$channel = $this->channel( array() );
		\WP_CLI::log( 'YouTube API key:   ' . ( YouTube_API::configured() ? 'set in wp-config.php (not shown)' : 'NOT SET: add PN_YOUTUBE_API_KEY to wp-config.php' ) );
		\WP_CLI::log( 'Channel ID:        ' . ( '' !== $channel ? $channel : 'NOT SET: add PN_YOUTUBE_CHANNEL_ID to wp-config.php or set it under Settings > Sermons' ) );
		\WP_CLI::log( 'Uploads playlist:  ' . ( '' !== $channel ? YouTube_API::uploads_playlist( $channel ) : '-' ) );
		\WP_CLI::log( 'Blog drafts:       ' . ( Settings::get( 'companion_enabled' ) ? 'on' : 'off (Settings > Sermons)' ) );
		\WP_CLI::log( 'Claude API key:    ' . ( defined( 'PN_ANTHROPIC_API_KEY' ) && PN_ANTHROPIC_API_KEY ? 'set in wp-config.php (not shown)' : 'NOT SET: add PN_ANTHROPIC_API_KEY to wp-config.php' ) );
		\WP_CLI::log( 'Pexels API key:    ' . ( Pexels::configured() ? 'set in wp-config.php (not shown)' : 'NOT SET: posts use the sermon picture; add PN_PEXELS_API_KEY' ) );
		\WP_CLI::log( 'yt-dlp:            ' . ( Transcript::installed() ? Transcript::binary() : 'NOT FOUND at ' . Transcript::binary() . ' (scripts/install-yt-dlp.sh)' ) . ( Transcript::can_run() ? '' : ' (this PHP may not run it)' ) );
		$counts = wp_count_posts( Post_Types::POST_TYPE );
		\WP_CLI::log( sprintf( 'Sermons on site:   %d published, %d drafts, %d in the trash', $counts->publish ?? 0, $counts->draft ?? 0, $counts->trash ?? 0 ) );
	}

	/**
	 * Draft the blog post for one sermon now, instead of waiting for the background job.
	 *
	 * Reads the captions with yt-dlp unless a transcript is already saved, asks Claude for the post, adds a
	 * Pexels photo, saves it as a draft under the preacher's account and emails them. Blog drafts must be
	 * switched on under Settings > Sermons. A sermon only ever gets one draft.
	 *
	 * ## OPTIONS
	 *
	 * <sermon-id>
	 * : The sermon's post ID.
	 *
	 * [--transcript-only]
	 * : Only fetch and show the start of the transcript; draft nothing and email nobody.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pn-sermons draft-post 123
	 *     wp pn-sermons draft-post 123 --transcript-only
	 *
	 * @subcommand draft-post
	 */
	public function draft_post( $args, $assoc_args ): void {
		$id = absint( $args[0] ?? 0 );
		if ( ! $id || Post_Types::POST_TYPE !== get_post_type( $id ) ) {
			\WP_CLI::error( 'That is not a sermon ID.' );
		}
		if ( ! empty( $assoc_args['transcript-only'] ) ) {
			$result = Transcript::fetch( (string) get_post_meta( $id, 'pn_youtube_id', true ) );
			\WP_CLI::log( 'Result: ' . $result['status'] . ( '' !== $result['detail'] ? ' (' . $result['detail'] . ')' : '' ) );
			if ( 'ok' === $result['status'] ) {
				\WP_CLI::log( strlen( $result['text'] ) . ' characters. Starts: ' . mb_substr( $result['text'], 0, 300 ) . '...' );
			}
			return;
		}
		if ( ! Settings::get( 'companion_enabled' ) ) {
			\WP_CLI::error( 'Blog drafts are switched off (Settings > Sermons).' );
		}
		$existing = (int) get_post_meta( $id, 'pn_companion_post', true );
		if ( $existing ) {
			\WP_CLI::error( "This sermon already has a blog draft (post {$existing})." );
		}
		Companion::draft( $id );
		$post = (int) get_post_meta( $id, 'pn_companion_post', true );
		if ( $post ) {
			\WP_CLI::success( "Drafted post {$post}: " . get_the_title( $post ) );
		} else {
			\WP_CLI::warning( 'No draft yet. With WP_DEBUG_LOG on, the log says why; a retry or a "transcript needed" email may have been scheduled.' );
		}
	}

	/**
	 * Import the channel's whole upload history as sermons (drafts unless told otherwise).
	 *
	 * Safe to run again: a video already on the site (even one in the trash) is never imported twice, and
	 * sermons you have edited are never touched. Run with --dry-run first to see exactly what would happen.
	 *
	 * ## OPTIONS
	 *
	 * [--channel=<id>]
	 * : The YouTube channel ID (UC...). Defaults to PN_YOUTUBE_CHANNEL_ID or the Sermons setting.
	 *
	 * [--playlist=<id>]
	 * : A playlist to import instead of the channel's uploads.
	 *
	 * [--since=<date>]
	 * : Only services on or after this date (YYYY-MM-DD).
	 *
	 * [--until=<date>]
	 * : Only services on or before this date (YYYY-MM-DD).
	 *
	 * [--limit=<number>]
	 * : Stop after creating this many sermons.
	 *
	 * [--filter=<patterns>]
	 * : Only videos whose title contains one of these, separated by | (for example "Sunday Morning Service|Sunday AM Worship").
	 *
	 * [--exclude=<patterns>]
	 * : Skip videos whose title contains one of these, separated by |.
	 *
	 * [--min-minutes=<minutes>]
	 * : Skip videos shorter than this, which are clips and Shorts, not services.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--status=<status>]
	 * : Status for new sermons. Sermons with hand-written details on the old website are published regardless.
	 * ---
	 * default: draft
	 * options:
	 *   - draft
	 *   - pending
	 *   - publish
	 * ---
	 *
	 * [--dry-run]
	 * : Show what would be imported and change nothing.
	 *
	 * [--skip-thumbnails]
	 * : Do not download a picture for each sermon (faster).
	 *
	 * [--format=<format>]
	 * : How to show the report.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp pn-sermons backfill --dry-run
	 *     wp pn-sermons backfill --since=2024-01-01 --filter="Sunday Morning Service|Sunday AM Worship"
	 *     wp pn-sermons backfill
	 *
	 * @param array $args       Positional arguments (none).
	 * @param array $assoc_args Named arguments.
	 */
	public function backfill( $args, $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- WP-CLI always passes both.
		$channel           = $this->channel( $assoc_args );
		$playlist          = (string) ( $assoc_args['playlist'] ?? '' );
		$format            = (string) ( $assoc_args['format'] ?? 'table' );
		$structured_format = in_array( $format, array( 'csv', 'json' ), true );
		if ( '' === $playlist ) {
			if ( ! preg_match( '/^UC[\w-]{22}$/', $channel ) ) {
				\WP_CLI::error( 'No valid channel ID. Pass --channel=UC... or set PN_YOUTUBE_CHANNEL_ID in wp-config.php.' );
			}
			$playlist = YouTube_API::uploads_playlist( $channel );
		}
		foreach ( array( 'since', 'until' ) as $date_option ) {
			if ( isset( $assoc_args[ $date_option ] ) && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $assoc_args[ $date_option ] ) ) {
				\WP_CLI::error( "--$date_option must look like 2025-12-31." );
			}
		}

		if ( ! $structured_format ) {
			\WP_CLI::log( 'Reading the channel\'s uploads from YouTube...' );
		}
		$videos = YouTube_API::fetch_uploads(
			$playlist,
			static function ( int $page, int $count ) use ( $structured_format ): void {
				if ( ! $structured_format ) {
					\WP_CLI::log( sprintf( '  page %d: %d videos so far', $page, $count ) );
				}
			}
		);
		if ( is_wp_error( $videos ) ) {
			\WP_CLI::error( $videos->get_error_message() );
		}

		$dry  = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$rows = YouTube_Import::import(
			$videos,
			array(
				'status'      => (string) ( $assoc_args['status'] ?? 'draft' ),
				'filter'      => (string) ( $assoc_args['filter'] ?? '' ),
				'exclude'     => (string) ( $assoc_args['exclude'] ?? '' ),
				'min_seconds' => (int) round( (float) ( $assoc_args['min-minutes'] ?? 20 ) * 60 ),
				'since'       => (string) ( $assoc_args['since'] ?? '' ),
				'until'       => (string) ( $assoc_args['until'] ?? '' ),
				'limit'       => (int) ( $assoc_args['limit'] ?? 0 ),
				'dry_run'     => (bool) $dry,
				'thumbnails'  => ! \WP_CLI\Utils\get_flag_value( $assoc_args, 'skip-thumbnails', false ),
				'source'      => 'api',
			)
		);

		\WP_CLI\Utils\format_items( $format, $rows, array( 'date', 'id', 'action', 'reason', 'speaker', 'series', 'title', 'scripture' ) );

		$tally = array();
		foreach ( $rows as $row ) {
			$key           = 'skipped' === $row['action'] ? 'skipped: ' . preg_replace( '/ \(.*\)$/', '', $row['reason'] ) : $row['action'];
			$tally[ $key ] = ( $tally[ $key ] ?? 0 ) + 1;
		}
		ksort( $tally );
		if ( ! $structured_format ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( sprintf( '%d videos read from YouTube.', count( $videos ) ) );
			foreach ( $tally as $what => $n ) {
				\WP_CLI::log( sprintf( '  %4d  %s', $n, $what ) );
			}
			$flagged = array_filter( $rows, static fn( $row ) => '' !== $row['notes'] && in_array( $row['action'], array( 'created', 'would create' ), true ) );
			if ( $flagged ) {
				\WP_CLI::log( sprintf( '%d sermon(s) have a note for the reviewer (see the "pn_import_note" field on each).', count( $flagged ) ) );
			}
			if ( $dry ) {
				\WP_CLI::success( 'Dry run: nothing was created.' );
			} else {
				\WP_CLI::success( 'Done. Review the drafts under Sermons in the admin, then publish them.' );
			}
		}
	}

	private function channel( array $assoc_args ): string {
		$channel = (string) ( $assoc_args['channel'] ?? '' );
		if ( '' === $channel && defined( 'PN_YOUTUBE_CHANNEL_ID' ) ) {
			$channel = (string) PN_YOUTUBE_CHANNEL_ID;
		}
		if ( '' === $channel ) {
			$channel = (string) Settings::get( 'channel_id' );
		}
		return $channel;
	}
}
