<?php
namespace PN_Sermons;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a sermon video's captions and turns them into plain text: first through the YouTube API signed in as
 * the channel (class-youtube-captions.php, FEAT-10) when that is set up, otherwise with yt-dlp (FEAT-9).
 *
 * The yt-dlp program only runs where PHP may start programs: WP-CLI cron on the server.
 * PHP-FPM forbids it (deploy/php/pn-pool.conf), and then fetch() says "no-runner" so the job waits for cron.
 * No shell is involved: the command is an argument list, and the video ID is checked first.
 */
class Transcript {

	const TIMEOUT    = 150;      // Seconds before yt-dlp is stopped.
	const MAX_OUTPUT = 65536;    // Bytes of yt-dlp messages kept.
	const MAX_CHARS  = 150000;   // A long service is about 70,000 characters.

	/** Seconds yt-dlp may run; PN_YTDLP_TIMEOUT overrides it (tests). */
	public static function timeout(): int {
		return defined( 'PN_YTDLP_TIMEOUT' ) ? max( 1, (int) PN_YTDLP_TIMEOUT ) : self::TIMEOUT;
	}

	/** Path to yt-dlp; PN_YTDLP_PATH in wp-config.php overrides it. */
	public static function binary(): string {
		return defined( 'PN_YTDLP_PATH' ) ? (string) PN_YTDLP_PATH : '/usr/local/bin/yt-dlp';
	}

	/** Whether this PHP may start programs at all. */
	public static function can_run(): bool {
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		return function_exists( 'proc_open' ) && ! in_array( 'proc_open', $disabled, true );
	}

	/** Whether yt-dlp is installed where we look for it. */
	public static function installed(): bool {
		return is_file( self::binary() ) && is_executable( self::binary() );
	}

	/**
	 * Fetch the captions of a YouTube video: the YouTube API first when it is set up, then yt-dlp.
	 *
	 * @return array{status:string,text:string,detail:string,api:string} api is the API's own status ('' when it
	 *   is not set up; "auth" means its sign-in needs renewing). status is one of:
	 *   ok          text holds the transcript;
	 *   no-captions YouTube has no English captions (yet: they can take hours after a live stream);
	 *   otherwise   the yt-dlp result below.
	 */
	public static function fetch( string $video_id ): array {
		if ( ! preg_match( '/^[\w-]{11}$/', $video_id ) ) {
			return self::result( 'failed', '', 'Not a YouTube video ID.' ) + array( 'api' => '' );
		}
		$api = null;
		if ( YouTube_Captions::configured() ) {
			$api = YouTube_Captions::fetch( $video_id );
			if ( in_array( $api['status'], array( 'ok', 'no-captions' ), true ) ) {
				return $api + array( 'api' => $api['status'] );
			}
			YouTube_Import::log( "YouTube API captions failed ({$api['status']}): {$api['detail']} Trying yt-dlp." );
		}
		$result        = self::fetch_ytdlp( $video_id );
		$result['api'] = $api ? $api['status'] : '';
		if ( $api && 'ok' !== $result['status'] ) {
			$result['detail'] = 'YouTube API: ' . $api['detail'] . ' yt-dlp: ' . $result['detail'];
		}
		return $result;
	}

	/**
	 * Fetch the captions of a YouTube video with yt-dlp.
	 *
	 * @return array{status:string,text:string,detail:string} status is one of:
	 *   ok          text holds the transcript;
	 *   no-runner   PHP may not start programs here (PHP-FPM); try again from cron;
	 *   missing     yt-dlp is not installed;
	 *   no-captions YouTube has no English captions (yet: they can take hours after a live stream);
	 *   blocked     YouTube refused the server ("confirm you're not a bot"), so asking a person is quicker;
	 *   failed      anything else (network, timeout); worth another try.
	 */
	private static function fetch_ytdlp( string $video_id ): array {
		if ( ! self::can_run() ) {
			return self::result( 'no-runner', '', 'PHP here may not start programs; cron will try.' );
		}
		if ( ! self::installed() ) {
			return self::result( 'missing', '', 'yt-dlp is not installed at ' . self::binary() . ' (scripts/install-yt-dlp.sh).' );
		}

		$dir = trailingslashit( get_temp_dir() ) . 'pn-captions-' . wp_generate_password( 12, false );
		if ( ! wp_mkdir_p( $dir ) ) {
			return self::result( 'failed', '', 'Could not make a temporary folder.' );
		}

		try {
			$cmd  = array(
				self::binary(),
				'--ignore-config',
				'--no-cache-dir',
				'--no-playlist',
				'--skip-download',
				'--write-subs',
				'--write-auto-subs',
				'--sub-langs',
				'en,en-US,en-orig',
				'--sub-format',
				'json3',
				'--socket-timeout',
				'30',
				'--no-progress',
				'--paths',
				$dir,
				'--output',
				'%(id)s.%(ext)s',
			);
			$deno = dirname( self::binary() ) . '/deno';
			if ( is_executable( $deno ) ) {
				$cmd[] = '--js-runtimes';
				$cmd[] = 'deno:' . $deno;
			}
			$cmd[] = '--';
			$cmd[] = 'https://www.youtube.com/watch?v=' . $video_id;

			$env                 = array(
				'PATH'           => '/usr/local/bin:/usr/bin:/bin',
				'HOME'           => $dir,
				'XDG_CACHE_HOME' => $dir,
				'DENO_DIR'       => $dir . '/deno',
				'LANG'           => 'C.UTF-8',
			);
			[ $code, $messages ] = self::run( $cmd, $env );

			$file = self::caption_file( $dir, $video_id );
			if ( '' !== $file ) {
				$text = self::from_json3( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local temp file.
				if ( strlen( $text ) >= 500 ) {
					return self::result( 'ok', $text, 'with yt-dlp' );
				}
				return self::result( 'no-captions', '', 'The captions are almost empty.' );
			}
			return self::result( self::classify( $code, $messages ), '', self::summary( $messages, $code ) );
		} finally {
			self::remove_dir( $dir );
		}
	}

	/** Plain text from YouTube's json3 caption format; sound tags like [Music] are dropped. */
	public static function from_json3( string $json ): string {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || empty( $data['events'] ) || ! is_array( $data['events'] ) ) {
			return '';
		}
		$parts = array();
		foreach ( $data['events'] as $event ) {
			foreach ( (array) ( $event['segs'] ?? array() ) as $seg ) {
				if ( isset( $seg['utf8'] ) && is_string( $seg['utf8'] ) ) {
					$parts[] = $seg['utf8'];
				}
			}
		}
		return self::clean( implode( '', $parts ) );
	}

	/** Caption text tidied: sound tags like [Music] dropped, tags stripped, spaces collapsed, length capped. */
	public static function clean( string $text ): string {
		$text = preg_replace( '/\[(?:music|applause|laughter|singing|snorts|__)[^\]]*\]/i', ' ', $text );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $text ) ) );
		return mb_substr( $text, 0, self::MAX_CHARS );
	}

	/** Which outcome yt-dlp's messages describe. */
	public static function classify( int $code, string $messages ): string {
		if ( preg_match( '/confirm you.?re not a bot|Sign in to confirm|HTTP Error 429|Too Many Requests/i', $messages ) ) {
			return 'blocked';
		}
		if ( preg_match( '/no subtitles|There are no .*subtitles|Requested format is not available|video is not available|Premieres in|live event will begin/i', $messages ) || 0 === $code ) {
			return 'no-captions';
		}
		return 'failed';
	}

	private static function caption_file( string $dir, string $video_id ): string {
		$found = glob( $dir . '/' . $video_id . '.*.json3' );
		if ( ! $found ) {
			return '';
		}
		// Prefer captions a person wrote ("en", "en-US") over YouTube's automatic ones ("en-orig").
		usort( $found, fn( $a, $b ) => (int) str_contains( $a, 'en-orig' ) <=> (int) str_contains( $b, 'en-orig' ) );
		return $found[0];
	}

	/** Run a command with no shell and a time limit. Returns [exit code, stderr+stdout (trimmed)]. */
	private static function run( array $cmd, array $env ): array {
		$spec  = array(
			0 => array( 'file', '/dev/null', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$pipes = array();
		$proc  = proc_open( $cmd, $spec, $pipes, null, $env ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- argument list, no shell, CLI only; see class comment.
		if ( ! is_resource( $proc ) ) {
			return array( -1, 'Could not start yt-dlp.' );
		}
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );
		$out      = '';
		$deadline = time() + self::timeout();
		$open     = array( $pipes[1], $pipes[2] );
		while ( $open && time() < $deadline ) {
			$read   = $open;
			$write  = null;
			$except = null;
			if ( false === stream_select( $read, $write, $except, 1 ) ) {
				break;
			}
			foreach ( $read as $pipe ) {
				$chunk = fread( $pipe, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				if ( false === $chunk || ( '' === $chunk && feof( $pipe ) ) ) {
					$open = array_values( array_filter( $open, fn( $p ) => $p !== $pipe ) );
					continue;
				}
				if ( strlen( $out ) < self::MAX_OUTPUT ) {
					$out .= $chunk;
				}
			}
		}
		$timed_out = (bool) $open;
		if ( $timed_out ) {
			proc_terminate( $proc, 9 );
			$out .= "\nStopped after " . self::timeout() . ' seconds.';
		}
		fclose( $pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $pipes[2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$code = proc_close( $proc );
		return array( $timed_out ? -1 : (int) $code, $out );
	}

	/** The last few lines of yt-dlp's messages, for the log. */
	private static function summary( string $messages, int $code ): string {
		$lines = array_filter( array_map( 'trim', explode( "\n", wp_strip_all_tags( $messages ) ) ) );
		return sprintf( 'yt-dlp exit %d: %s', $code, mb_substr( implode( ' | ', array_slice( $lines, -3 ) ), 0, 500 ) );
	}

	private static function remove_dir( string $dir ): void {
		if ( ! str_starts_with( basename( $dir ), 'pn-captions-' ) || ! is_dir( $dir ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : wp_delete_file( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	private static function result( string $status, string $text, string $detail ): array {
		return array(
			'status' => $status,
			'text'   => $text,
			'detail' => $detail,
		);
	}
}
