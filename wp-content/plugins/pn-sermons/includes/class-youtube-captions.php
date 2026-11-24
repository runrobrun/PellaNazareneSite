<?php
namespace PN_Sermons;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a sermon's captions through the official YouTube Data API, signed in as the channel (FEAT-10).
 *
 * YouTube shows data-center servers a "confirm you're not a bot" page, so yt-dlp fails on the Linode. The API's
 * captions.download is allowed for an account that may edit the video, so the church authorizes the site once
 * (OAuth, scope youtube.force-ssl) and keeps a refresh token. Setup: docs/sermons.md, "Captions from YouTube".
 *
 * The OAuth client ID, client secret and refresh token come only from wp-config.php constants. The short-lived
 * access token is fetched for each run and never stored. None of them is logged, printed or saved.
 */
class YouTube_Captions {

	const TOKEN_URL = 'https://oauth2.googleapis.com/token';
	const SCOPE     = 'https://www.googleapis.com/auth/youtube.force-ssl';

	public static function configured(): bool {
		return '' !== self::client_id() && '' !== self::client_secret() && '' !== self::refresh_token();
	}

	/**
	 * The captions of one of the channel's videos.
	 *
	 * @return array{status:string,text:string,detail:string} status: ok; no-captions (none yet, try later);
	 *   auth (Google refused the sign-in: renew it, see the docs); failed (anything else).
	 */
	public static function fetch( string $video_id ): array {
		if ( ! preg_match( '/^[\w-]{11}$/', $video_id ) ) {
			return self::result( 'failed', '', 'Not a YouTube video ID.' );
		}
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return self::result( 'invalid_grant' === $token->get_error_code() ? 'auth' : 'failed', '', $token->get_error_message() );
		}

		$list = self::get(
			'/captions',
			array(
				'part'    => 'snippet',
				'videoId' => $video_id,
			),
			$token
		);
		if ( is_wp_error( $list ) ) {
			return self::result( self::status_for( $list ), '', 'Listing captions: ' . $list->get_error_message() );
		}
		$track = self::choose( (array) ( json_decode( $list, true )['items'] ?? array() ) );
		if ( '' === $track ) {
			return self::result( 'no-captions', '', 'YouTube has no English captions for this video yet.' );
		}

		$vtt = self::get( '/captions/' . rawurlencode( $track ), array( 'tfmt' => 'vtt' ), $token );
		if ( is_wp_error( $vtt ) ) {
			return self::result( self::status_for( $vtt ), '', 'Downloading captions: ' . $vtt->get_error_message() );
		}
		$text = Transcript::clean( self::from_vtt( $vtt ) );
		if ( strlen( $text ) < 500 ) {
			return self::result( 'no-captions', '', 'The captions are almost empty.' );
		}
		return self::result( 'ok', $text, 'from the YouTube API, signed in as the channel' );
	}

	/**
	 * The best English track ID: one a person wrote before YouTube's automatic one; never a failed one.
	 *
	 * @param array $items captions.list items.
	 */
	public static function choose( array $items ): string {
		$rank = array(
			'standard' => 0,
			'forced'   => 1,
			'asr'      => 2,
		);
		$best = '';
		$top  = PHP_INT_MAX;
		foreach ( $items as $item ) {
			$id      = (string) ( $item['id'] ?? '' );
			$snippet = (array) ( $item['snippet'] ?? array() );
			$kind    = strtolower( (string) ( $snippet['trackKind'] ?? '' ) );
			$lang    = strtolower( (string) ( $snippet['language'] ?? '' ) );
			if ( ! preg_match( '/^[\w.-]{1,128}$/', $id ) || 'failed' === ( $snippet['status'] ?? '' ) || ! isset( $rank[ $kind ] ) ) {
				continue;
			}
			if ( 'en' !== $lang && ! str_starts_with( $lang, 'en-' ) ) {
				continue;
			}
			$score = $rank[ $kind ] * 2 + ( empty( $snippet['isDraft'] ) ? 0 : 1 );
			if ( $score < $top ) {
				$best = $id;
				$top  = $score;
			}
		}
		return $best;
	}

	/** Plain text from WebVTT: cue numbers, times, settings and tags dropped; repeated lines once. */
	public static function from_vtt( string $vtt ): string {
		$out  = array();
		$last = '';
		foreach ( preg_split( '/\R/u', $vtt ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || str_contains( $line, '-->' ) || ctype_digit( $line ) || preg_match( '/^(WEBVTT|NOTE|Kind:|Language:|STYLE|REGION)\b/', $line ) ) {
				continue;
			}
			$line = trim( html_entity_decode( wp_strip_all_tags( $line ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			if ( '' !== $line && $line !== $last ) {
				$out[] = $line;
				$last  = $line;
			}
		}
		return implode( ' ', $out );
	}

	/** A fresh access token from the refresh token, or WP_Error (code invalid_grant when the sign-in must be renewed). */
	private static function access_token() {
		$response = wp_remote_post(
			self::token_url(),
			array(
				'timeout'     => 20,
				'redirection' => 0, // Secrets never follow a redirect anywhere else.
				'body'        => array(
					'client_id'     => self::client_id(),
					'client_secret' => self::client_secret(),
					'refresh_token' => self::refresh_token(),
					'grant_type'    => 'refresh_token',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'network', 'Could not reach Google: ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 === $code && is_array( $body ) && ! empty( $body['access_token'] ) && is_string( $body['access_token'] ) ) {
			return $body['access_token'];
		}
		$error = is_array( $body ) ? sanitize_key( (string) ( $body['error'] ?? '' ) ) : '';
		if ( in_array( $error, array( 'invalid_grant', 'invalid_client', 'unauthorized_client' ), true ) ) {
			return new \WP_Error( 'invalid_grant', "Google refused the site's YouTube sign-in ({$error}); renew it (docs/sermons.md)." );
		}
		return new \WP_Error( 'token', "Google token request failed (HTTP {$code}" . ( $error ? ", {$error}" : '' ) . ').' );
	}

	/** GET from the YouTube API. Returns the body, or WP_Error with the HTTP status as its data. */
	private static function get( string $path, array $args, string $token ) {
		$response = wp_remote_get(
			add_query_arg( array_map( 'rawurlencode', $args ), YouTube_API::base() . $path ),
			array(
				'timeout'     => 30,
				'redirection' => 0, // The access token never follows a redirect anywhere else.
				'headers'     => array( 'Authorization' => 'Bearer ' . $token ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'network', 'could not reach YouTube: ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$reason = json_decode( wp_remote_retrieve_body( $response ), true )['error']['errors'][0]['reason'] ?? '';
			return new \WP_Error( 'http', "HTTP {$code}" . ( $reason ? ' (' . sanitize_key( (string) $reason ) . ')' : '' ), $code );
		}
		return (string) wp_remote_retrieve_body( $response );
	}

	/** 401/403 mean the signed-in account can't read this channel's captions: a setup problem, not "try later". */
	private static function status_for( \WP_Error $error ): string {
		return in_array( (int) $error->get_error_data(), array( 401, 403 ), true ) ? 'auth' : 'failed';
	}

	/** The token address. Tests point it at a fake server; ignored on production, like PN_YOUTUBE_API_BASE. */
	private static function token_url(): string {
		if ( defined( 'PN_GOOGLE_TOKEN_URL' ) && 'production' !== wp_get_environment_type() ) {
			return (string) PN_GOOGLE_TOKEN_URL;
		}
		return self::TOKEN_URL;
	}

	private static function client_id(): string {
		return defined( 'PN_YOUTUBE_OAUTH_CLIENT_ID' ) ? trim( (string) PN_YOUTUBE_OAUTH_CLIENT_ID ) : '';
	}

	private static function client_secret(): string {
		return defined( 'PN_YOUTUBE_OAUTH_CLIENT_SECRET' ) ? trim( (string) PN_YOUTUBE_OAUTH_CLIENT_SECRET ) : '';
	}

	private static function refresh_token(): string {
		return defined( 'PN_YOUTUBE_OAUTH_REFRESH_TOKEN' ) ? trim( (string) PN_YOUTUBE_OAUTH_REFRESH_TOKEN ) : '';
	}

	private static function result( string $status, string $text, string $detail ): array {
		return array(
			'status' => $status,
			'text'   => $text,
			'detail' => $detail,
		);
	}
}
