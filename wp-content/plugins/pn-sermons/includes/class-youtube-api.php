<?php
namespace PN_Sermons;

defined( 'ABSPATH' ) || exit;

/**
 * A small client for the YouTube Data API v3: the channel's full upload history (the RSS feed only has the
 * latest 15) plus each video's length and status.
 *
 * The API key is read from the PN_YOUTUBE_API_KEY constant in wp-config.php and nowhere else. It is never stored
 * in the database, never printed, and never put in an error message.
 */
class YouTube_API {

	const BASE      = 'https://www.googleapis.com/youtube/v3';
	const MAX_PAGES = 200; // 200 pages x 50 videos: a runaway guard, far above any real channel.

	public static function key(): string {
		return defined( 'PN_YOUTUBE_API_KEY' ) ? (string) PN_YOUTUBE_API_KEY : '';
	}

	public static function configured(): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{20,80}$/', self::key() );
	}

	/**
	 * The API address. Tests point it at a fake server with PN_YOUTUBE_API_BASE, which is ignored on a
	 * production site so nothing can redirect real requests (and the key) elsewhere.
	 */
	public static function base(): string {
		if ( defined( 'PN_YOUTUBE_API_BASE' ) && 'production' !== wp_get_environment_type() ) {
			return rtrim( (string) PN_YOUTUBE_API_BASE, '/' );
		}
		return self::BASE;
	}

	/** A channel's uploads live in a playlist whose ID is the channel ID with "UC" swapped for "UU". */
	public static function uploads_playlist( string $channel_id ): string {
		return 'UU' . substr( $channel_id, 2 );
	}

	/**
	 * Every video in a playlist, oldest data first as the API returns it (newest first), with length and status.
	 *
	 * @param string        $playlist_id Uploads playlist ID.
	 * @param callable|null $progress    Called with ( pages fetched, videos so far ).
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	public static function fetch_uploads( string $playlist_id, ?callable $progress = null ) {
		if ( ! self::configured() ) {
			return new \WP_Error( 'pn_youtube_no_key', 'No YouTube API key is set. Add define( \'PN_YOUTUBE_API_KEY\', \'...\' ); to wp-config.php (see docs/sermons.md).' );
		}
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{10,64}$/', $playlist_id ) ) {
			return new \WP_Error( 'pn_youtube_bad_playlist', 'That is not a valid YouTube playlist ID.' );
		}

		$items = array();
		$token = '';
		for ( $page = 1; $page <= self::MAX_PAGES; $page++ ) {
			$args = array(
				'part'       => 'snippet,status',
				'maxResults' => 50,
				'playlistId' => $playlist_id,
			);
			if ( '' !== $token ) {
				$args['pageToken'] = $token;
			}
			$body = self::get( 'playlistItems', $args );
			if ( is_wp_error( $body ) ) {
				return $body;
			}
			foreach ( (array) ( $body['items'] ?? array() ) as $item ) {
				$video = self::normalize_playlist_item( (array) $item );
				if ( $video ) {
					$items[ $video['id'] ] = $video; // keyed by ID: a video listed twice is one video.
				}
			}
			if ( $progress ) {
				$progress( $page, count( $items ) );
			}
			$token = (string) ( $body['nextPageToken'] ?? '' );
			if ( '' === $token ) {
				break;
			}
		}

		// Length and live/embed status are only in videos.list; ask 50 at a time.
		foreach ( array_chunk( array_keys( $items ), 50 ) as $ids ) {
			$body = self::get(
				'videos',
				array(
					'part'       => 'contentDetails,snippet,status',
					'id'         => implode( ',', $ids ),
					'maxResults' => 50,
				)
			);
			if ( is_wp_error( $body ) ) {
				return $body;
			}
			$found = array();
			foreach ( (array) ( $body['items'] ?? array() ) as $detail ) {
				$id = (string) ( $detail['id'] ?? '' );
				if ( ! isset( $items[ $id ] ) ) {
					continue;
				}
				$found[ $id ]               = true;
				$items[ $id ]['duration']   = self::seconds( (string) ( $detail['contentDetails']['duration'] ?? '' ) );
				$items[ $id ]['live']       = (string) ( $detail['snippet']['liveBroadcastContent'] ?? 'none' );
				$items[ $id ]['embeddable'] = ! isset( $detail['status']['embeddable'] ) || (bool) $detail['status']['embeddable'];
				$items[ $id ]['privacy']    = (string) ( $detail['status']['privacyStatus'] ?? $items[ $id ]['privacy'] );
			}
			// Listed in the playlist but not returned by videos.list: deleted, or private to everyone else.
			foreach ( $ids as $id ) {
				if ( empty( $found[ $id ] ) ) {
					$items[ $id ]['privacy'] = 'unavailable';
				}
			}
		}
		return array_values( $items );
	}

	/** @return array<string,mixed>|null */
	private static function normalize_playlist_item( array $item ): ?array {
		$snippet = (array) ( $item['snippet'] ?? array() );
		$id      = (string) ( $snippet['resourceId']['videoId'] ?? '' );
		if ( 1 !== preg_match( '/^[\w-]{11}$/', $id ) ) {
			return null;
		}
		$thumbs = (array) ( $snippet['thumbnails'] ?? array() );
		$thumb  = '';
		foreach ( array( 'maxres', 'standard', 'high', 'medium', 'default' ) as $size ) {
			if ( ! empty( $thumbs[ $size ]['url'] ) ) {
				$thumb = (string) $thumbs[ $size ]['url'];
				break;
			}
		}
		return array(
			'id'          => $id,
			'title'       => trim( (string) ( $snippet['title'] ?? '' ) ),
			'description' => trim( (string) ( $snippet['description'] ?? '' ) ),
			'published'   => (string) ( $snippet['publishedAt'] ?? '' ),
			'thumbnail'   => $thumb,
			'privacy'     => (string) ( $item['status']['privacyStatus'] ?? 'public' ),
			'duration'    => 0,
			'live'        => 'none',
			'embeddable'  => true,
		);
	}

	/** ISO 8601 duration ("PT1H21M39S") to seconds. */
	public static function seconds( string $iso ): int {
		if ( 1 !== preg_match( '/^P(?:(\d+)D)?T?(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/', $iso, $m ) ) {
			return 0;
		}
		return (int) ( $m[1] ?? 0 ) * 86400 + (int) ( $m[2] ?? 0 ) * 3600 + (int) ( $m[3] ?? 0 ) * 60 + (int) ( $m[4] ?? 0 );
	}

	/**
	 * One GET request with a single retry for a flaky network or a YouTube server error.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function get( string $endpoint, array $args ) {
		$args['key'] = self::key();
		$url         = add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $args ) ), self::base() . '/' . $endpoint );

		$response = null;
		for ( $attempt = 1; $attempt <= 2; $attempt++ ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => 20,
					'redirection' => 0,
					'headers'     => array( 'Accept' => 'application/json' ),
				)
			);
			$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			if ( is_wp_error( $response ) || $code >= 500 ) {
				if ( 1 === $attempt ) {
					sleep( 2 );
					continue;
				}
			}
			break;
		}

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'pn_youtube_network', 'Could not reach YouTube: ' . self::scrub( $response->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$reason = is_array( $body ) ? (string) ( $body['error']['errors'][0]['reason'] ?? '' ) : '';
			return new \WP_Error( 'pn_youtube_http', self::explain( $code, $reason ) );
		}
		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'pn_youtube_bad_json', 'YouTube answered with something that is not valid JSON.' );
		}
		return $body;
	}

	/** Plain-English message for a failed request. Never contains the key. */
	private static function explain( int $code, string $reason ): string {
		$known   = array(
			'quotaExceeded'       => 'The daily YouTube quota is used up. It resets at midnight Pacific time; run the command again tomorrow.',
			'rateLimitExceeded'   => 'YouTube asked us to slow down. Wait a few minutes and run the command again.',
			'keyInvalid'          => 'YouTube does not accept the API key. Check PN_YOUTUBE_API_KEY in wp-config.php.',
			'ipRefererBlocked'    => 'The API key is restricted to other addresses than this server. In Google Cloud, allow this server\'s IP address for the key.',
			'accessNotConfigured' => 'The YouTube Data API v3 is not enabled for the key\'s project. Enable it in Google Cloud.',
			'playlistNotFound'    => 'YouTube cannot find that playlist. Check the channel ID.',
			'forbidden'           => 'YouTube refused access (forbidden). Check the key\'s restrictions in Google Cloud.',
		);
		$message = $known[ $reason ] ?? 'YouTube refused the request.';
		return sprintf( '%s (HTTP %d%s)', $message, $code, '' !== $reason ? ", $reason" : '' );
	}

	/** Remove the key from any text before it can reach a log or screen. */
	private static function scrub( string $text ): string {
		$key = self::key();
		return '' === $key ? $text : str_replace( $key, '[key]', $text );
	}
}
