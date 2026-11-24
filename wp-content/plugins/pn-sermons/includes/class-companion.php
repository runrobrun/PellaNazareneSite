<?php
namespace PN_Sermons;

defined( 'ABSPATH' ) || exit;

/**
 * Drafts the weekly blog post from a sermon (FEAT-9).
 *
 * Flow: the daily import finds Sunday's video (or staff publish a recent sermon) → a background job reads
 * the captions with yt-dlp (class-transcript.php) → Claude writes a standalone post on the sermon's theme
 * → a Pexels photo becomes its featured image (class-pexels.php) → the post is saved as a DRAFT under the
 * preacher's account → the preacher (and the "notify" addresses) get an email to review and publish it.
 *
 * When the captions cannot be read (YouTube blocks the server, or has none yet after a few tries), the
 * same people get an email asking them to paste the transcript into the sermon's Transcript box instead;
 * saving it starts the draft. Nothing is ever published automatically. Setup: docs/sermons.md.
 */
class Companion {

	const HOOK          = 'pn_sermons_draft_companion';
	const CATEGORY      = 'Sermon Companion';
	const API_URL       = 'https://api.anthropic.com/v1/messages';
	const DEFAULT_MODEL = 'claude-sonnet-5-5';
	const RECENT_DAYS   = 14;      // Only sermons this recent get a post, so a backfill never drafts hundreds.
	const RETRY_DELAY   = 6 * HOUR_IN_SECONDS;
	const MAX_TRIES     = 4;       // Caption tries (about a day) before asking a person.
	const MAX_WAITS     = 12;      // Times a web request may hand the job back to cron before asking a person.
	const PASTE_PAGE    = 'pn-paste-transcript';
	const LEGACY_PROMPT = '82545729b71cae03249960f651992def'; // md5 of the FEAT-1 default prompt, whitespace collapsed.

	public static function init(): void {
		add_action( 'pn_sermons_imported', array( __CLASS__, 'on_import' ), 10, 1 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_publish' ), 10, 3 );
		add_action( self::HOOK, array( __CLASS__, 'draft' ) );

		add_action( 'add_meta_boxes_' . Post_Types::POST_TYPE, array( __CLASS__, 'add_transcript_box' ) );
		add_action( 'save_post_' . Post_Types::POST_TYPE, array( __CLASS__, 'save_transcript' ), 10, 2 );
		add_action( 'admin_menu', array( __CLASS__, 'add_paste_page' ) );
		add_action( 'admin_post_' . self::PASTE_PAGE, array( __CLASS__, 'handle_paste' ) );

		add_action( 'pn_speaker_edit_form_fields', array( __CLASS__, 'speaker_user_field' ) );
		add_action( 'edited_pn_speaker', array( __CLASS__, 'save_speaker_user' ) );

		register_post_meta(
			Post_Types::POST_TYPE,
			'pn_transcript',
			array(
				'type'          => 'string',
				'single'        => true,
				'show_in_rest'  => false,
				'auth_callback' => fn() => current_user_can( 'edit_posts' ),
			)
		);
	}

	public static function default_prompt(): string {
		return <<<'PROMPT'
You write blog posts for the website of Pella Nazarene, a Church of the Nazarene congregation in Pella, Iowa. The church's mission is "to love people where they are and grow together in Jesus." Its voice is neighborly, warm, personal and encouraging, like a small-town church family. Avoid corporate language and churchy jargon.

You are given the machine-made captions of a Sunday worship service. Find the sermon in it (ignore the welcome, announcements, song lyrics, prayers, communion and benediction) and write a standalone blog post on the same theme. The preacher will review it and post it under their own name.

- It is a blog post, not a report of the sermon. Never mention the sermon, Sunday, the service or the preacher ("Pastor Cindy said..."). Speak to the reader directly with "you" and "we", so it reads well weeks later to someone who never heard the message.
- Four or five paragraphs, about 500 to 650 words. Open with something from everyday life, carry the sermon's main scripture passage and two or three of its key ideas, and end with one question for the reader to sit with, in bold.
- Use the sermon's own illustrations and quotations where they help, credited to their authors. Quote scripture only as it is read in the transcript, with its reference; never quote from memory.
- Captions mishear names, places and verse references. Fix obvious mistakes, and list every fix and every doubt for the preacher to check.
- Leave out announcements, prayer requests, song lyrics, and the names of anyone except the authors quoted.
PROMPT;
	}

	/** The prompt in use; a site still on the FEAT-1 default gets the current default. */
	public static function prompt(): string {
		$prompt = (string) Settings::get( 'companion_prompt' );
		if ( '' === trim( $prompt ) || self::LEGACY_PROMPT === md5( trim( (string) preg_replace( '/\s+/', ' ', $prompt ) ) ) ) {
			return self::default_prompt();
		}
		return $prompt;
	}

	/** A sermon the daily import just created: draft now if we know who preached; otherwise wait for publish. */
	public static function on_import( $sermon_id ): void {
		$sermon_id = (int) $sermon_id;
		if ( ! Settings::get( 'companion_enabled' ) || 'rss' !== get_post_meta( $sermon_id, 'pn_import_source', true ) ) {
			return;
		}
		if ( self::recent( $sermon_id ) && self::speaker_term( $sermon_id ) ) {
			self::schedule( $sermon_id, MINUTE_IN_SECONDS );
		}
	}

	public static function on_publish( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'publish' !== $new_status || 'publish' === $old_status || Post_Types::POST_TYPE !== $post->post_type ) {
			return;
		}
		if ( Settings::get( 'companion_enabled' ) && self::recent( $post->ID ) ) {
			self::schedule( $post->ID, 30 );
		}
	}

	/** Queue the background job once (WP-Cron; on the server, system cron runs it through WP-CLI). */
	public static function schedule( int $sermon_id, int $delay ): void {
		if ( get_post_meta( $sermon_id, 'pn_companion_post', true ) || wp_next_scheduled( self::HOOK, array( $sermon_id ) ) ) {
			return;
		}
		wp_schedule_single_event( time() + max( 1, $delay ), self::HOOK, array( $sermon_id ) );
	}

	/** Whether the sermon's service date is within the last RECENT_DAYS days. */
	public static function recent( int $sermon_id ): bool {
		// The local service date: an unpublished post can have no GMT date yet.
		$date = get_post_datetime( $sermon_id, 'date', 'local' );
		return $date && $date->getTimestamp() >= time() - self::RECENT_DAYS * DAY_IN_SECONDS;
	}

	/** The background job. Safe to run more than once: a sermon only ever gets one draft. */
	public static function draft( $sermon_id ): void {
		$sermon_id = (int) $sermon_id;
		$sermon    = get_post( $sermon_id );
		if ( ! $sermon || Post_Types::POST_TYPE !== $sermon->post_type || 'trash' === $sermon->post_status ) {
			return;
		}
		if ( ! Settings::get( 'companion_enabled' ) || get_post_meta( $sermon_id, 'pn_companion_post', true ) ) {
			return;
		}
		// An atomic database lock, so cron and `draft-post` can never both draft. It is per site (staging and
		// production share the database server) and is released if the process dies.
		global $wpdb;
		$lock = 'pn_companion_' . substr( md5( DB_NAME . '|' . $wpdb->prefix ), 0, 12 ) . '_' . $sermon_id;
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a lock, not data; must not be cached.
			return;
		}
		try {
			// Another run may have finished while we waited for the lock: read the meta fresh, not from the cache.
			wp_cache_delete( $sermon_id, 'post_meta' );
			if ( ! get_post_meta( $sermon_id, 'pn_companion_post', true ) ) {
				self::draft_unlocked( $sermon_id, $sermon );
			}
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- releases the lock above.
		}
	}

	private static function draft_unlocked( int $sermon_id, \WP_Post $sermon ): void {
		$transcript = self::transcript( $sermon_id );
		if ( '' === $transcript ) {
			return; // transcript() has already rescheduled or asked a person.
		}

		$api_key = defined( 'PN_ANTHROPIC_API_KEY' ) ? (string) PN_ANTHROPIC_API_KEY : '';
		if ( '' === $api_key ) {
			YouTube_Import::log( 'PN_ANTHROPIC_API_KEY is not defined; blog draft skipped.' );
			return;
		}

		$post = self::write( $sermon_id, $sermon, $transcript, $api_key );
		if ( ! $post ) {
			$tries = (int) get_post_meta( $sermon_id, 'pn_companion_tries', true ) + 1;
			update_post_meta( $sermon_id, 'pn_companion_tries', $tries );
			if ( $tries < 3 ) {
				wp_schedule_single_event( time() + HOUR_IN_SECONDS, self::HOOK, array( $sermon_id ) );
			}
			return;
		}

		$author  = self::speaker_user( $sermon_id ) ?: (int) $sermon->post_author;
		$post_id = wp_insert_post(
			array(
				'post_type'     => 'post',
				'post_status'   => 'draft',
				'post_author'   => $author,
				'post_title'    => sanitize_text_field( $post['title'] ),
				'post_excerpt'  => sanitize_text_field( $post['excerpt'] ),
				'post_content'  => self::to_blocks( $post['body_html'] ) . self::watch_link( $sermon ),
				'post_category' => array( self::category_id() ),
				'meta_input'    => array( 'pn_sermon_id' => $sermon_id ),
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			YouTube_Import::log( 'Could not save the blog draft: ' . $post_id->get_error_message() );
			return;
		}
		update_post_meta( $sermon_id, 'pn_companion_post', (int) $post_id );

		$image = Pexels::featured_image( $post['image_query'], (int) $post_id, sanitize_text_field( $post['title'] ) );
		$stock = $image > 0;
		$image = $stock ? $image : (int) get_post_thumbnail_id( $sermon );
		if ( $image ) {
			set_post_thumbnail( (int) $post_id, $image );
		}
		self::notify( (int) $post_id, $author, $post['pastor_check'], $stock );
	}

	/** The transcript: pasted or saved earlier, or read from YouTube now. '' means "not yet" (already handled). */
	private static function transcript( int $sermon_id ): string {
		$saved = (string) apply_filters( 'pn_sermons_transcript', (string) get_post_meta( $sermon_id, 'pn_transcript', true ), $sermon_id );
		if ( strlen( $saved ) >= 500 ) {
			return $saved;
		}

		$video_id = (string) get_post_meta( $sermon_id, 'pn_youtube_id', true );
		$result   = Transcript::fetch( $video_id );
		if ( 'ok' === $result['status'] ) {
			update_post_meta( $sermon_id, 'pn_transcript', wp_slash( $result['text'] ) );
			return $result['text'];
		}
		YouTube_Import::log( "No transcript for sermon {$sermon_id} yet ({$result['status']}): {$result['detail']}" );

		if ( 'no-runner' === $result['status'] ) {
			$waits = (int) get_post_meta( $sermon_id, 'pn_transcript_waits', true ) + 1;
			update_post_meta( $sermon_id, 'pn_transcript_waits', $waits );
			if ( $waits < self::MAX_WAITS ) {
				wp_schedule_single_event( time() + 10 * MINUTE_IN_SECONDS, self::HOOK, array( $sermon_id ) );
				return '';
			}
		} elseif ( in_array( $result['status'], array( 'no-captions', 'failed' ), true ) ) {
			$tries = (int) get_post_meta( $sermon_id, 'pn_transcript_tries', true ) + 1;
			update_post_meta( $sermon_id, 'pn_transcript_tries', $tries );
			if ( $tries < self::MAX_TRIES ) {
				wp_schedule_single_event( time() + self::RETRY_DELAY, self::HOOK, array( $sermon_id ) );
				return '';
			}
		}
		self::ask_for_transcript( $sermon_id, $result['status'], isset( $result['api'] ) && 'auth' === $result['api'] );
		return '';
	}

	/** Ask Claude for the post. Returns the checked fields, or null (logged). */
	private static function write( int $sermon_id, \WP_Post $sermon, string $transcript, string $api_key ): ?array {
		$context = sprintf(
			"Sermon title: %s\nPreacher: %s\nMain passage: %s\n\nReturn ONLY a JSON object with these keys:\n- \"title\": the post's title, short and inviting.\n- \"excerpt\": one sentence for search results and the blog list.\n- \"body_html\": the post as <p> paragraphs only; inside them use only <strong>, <em> and <a>.\n- \"image_query\": two to four plain words to search a stock photo site for a picture that fits the post's mood. Describe a scene or object (for example \"anchor harbor morning\"), not people, text or religious clip art.\n- \"pastor_check\": a list of short notes on names, quotes and references to verify, or an empty list.\n\n<transcript>\n%s\n</transcript>",
			get_the_title( $sermon ),
			self::speaker_name( $sermon_id ) ?: 'unknown',
			(string) get_post_meta( $sermon_id, 'pn_scripture_ref', true ) ?: 'not specified',
			$transcript
		);

		$response = wp_remote_post(
			defined( 'PN_ANTHROPIC_API_URL' ) && 'production' !== wp_get_environment_type() ? (string) PN_ANTHROPIC_API_URL : self::API_URL, // Tests only; never on production.
			array(
				'timeout' => 180,
				'headers' => array(
					'x-api-key'         => $api_key,
					'anthropic-version' => '2023-06-01',
					'content-type'      => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'      => apply_filters( 'pn_sermons_model', self::DEFAULT_MODEL ),
						'max_tokens' => 4000,
						'system'     => self::prompt(),
						'messages'   => array(
							array(
								'role'    => 'user',
								'content' => $context,
							),
						),
					)
				),
			)
		);

		$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$why = is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . $code . ' ' . mb_substr( wp_strip_all_tags( wp_remote_retrieve_body( $response ) ), 0, 300 );
			YouTube_Import::log( "Claude API error for sermon {$sermon_id}: {$why}" );
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$text = '';
		foreach ( (array) ( $data['content'] ?? array() ) as $block ) {
			if ( is_array( $block ) && 'text' === ( $block['type'] ?? '' ) ) {
				$text .= (string) ( $block['text'] ?? '' );
			}
		}
		$post = self::parse_json( $text );
		if ( ! $post ) {
			YouTube_Import::log( "Could not read Claude's answer for sermon {$sermon_id} (stop reason: " . sanitize_key( (string) ( $data['stop_reason'] ?? '?' ) ) . ').' );
		}
		return $post;
	}

	/** The JSON answer, checked and with every field present. */
	public static function parse_json( string $text ): ?array {
		// Tolerate a fenced code block or words around the JSON.
		$text  = trim( (string) preg_replace( '/^```(?:json)?\s*|\s*```$/', '', trim( $text ) ) );
		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );
		if ( false === $start || false === $end ) {
			return null;
		}
		$json = json_decode( substr( $text, $start, $end - $start + 1 ), true );
		if ( ! is_array( $json ) || empty( $json['title'] ) || empty( $json['body_html'] ) || ! is_string( $json['title'] ) || ! is_string( $json['body_html'] ) ) {
			return null;
		}
		$checks = array_values( array_filter( array_map( fn( $c ) => is_string( $c ) ? sanitize_text_field( $c ) : '', (array) ( $json['pastor_check'] ?? array() ) ) ) );
		return array(
			'title'        => $json['title'],
			'excerpt'      => is_string( $json['excerpt'] ?? null ) ? $json['excerpt'] : '',
			'body_html'    => $json['body_html'],
			'image_query'  => is_string( $json['image_query'] ?? null ) ? $json['image_query'] : '',
			'pastor_check' => array_slice( $checks, 0, 20 ),
		);
	}

	/** Paragraph blocks for the block editor; anything that is not a <p> stays as cleaned HTML. */
	public static function to_blocks( string $html ): string {
		$html = wp_kses(
			$html,
			array(
				'p'      => array(),
				'strong' => array(),
				'em'     => array(),
				'a'      => array( 'href' => true ),
			)
		);
		if ( ! preg_match_all( '#<p>(.*?)</p>#is', $html, $m ) ) {
			return wpautop( $html );
		}
		$out = '';
		foreach ( $m[1] as $inner ) {
			$inner = trim( $inner );
			if ( '' !== $inner ) {
				$out .= "<!-- wp:paragraph -->\n<p>" . $inner . "</p>\n<!-- /wp:paragraph -->\n\n";
			}
		}
		return $out;
	}

	/** Closing line linking to the full message: the sermon page once it is published, else YouTube. */
	private static function watch_link( \WP_Post $sermon ): string {
		$video = (string) get_post_meta( $sermon->ID, 'pn_youtube_id', true );
		$url   = 'publish' === $sermon->post_status ? get_permalink( $sermon ) : ( preg_match( '/^[\w-]{11}$/', $video ) ? 'https://www.youtube.com/watch?v=' . $video : '' );
		if ( ! $url ) {
			return '';
		}
		return sprintf(
			"<!-- wp:paragraph -->\n<p><em>%s <a href=\"%s\">%s</a></em></p>\n<!-- /wp:paragraph -->\n",
			esc_html__( 'This post grew out of a recent Sunday message.', 'pn-sermons' ),
			esc_url( $url ),
			esc_html__( 'Watch the full sermon.', 'pn-sermons' )
		);
	}

	private static function category_id(): int {
		$term = get_term_by( 'name', self::CATEGORY, 'category' );
		if ( $term ) {
			return (int) $term->term_id;
		}
		$new = wp_insert_term( self::CATEGORY, 'category' );
		return is_wp_error( $new ) ? (int) get_option( 'default_category' ) : (int) $new['term_id'];
	}

	private static function speaker_term( int $sermon_id ): ?\WP_Term {
		$terms = get_the_terms( $sermon_id, 'pn_speaker' );
		return ( $terms && ! is_wp_error( $terms ) ) ? $terms[0] : null;
	}

	private static function speaker_name( int $sermon_id ): string {
		$term = self::speaker_term( $sermon_id );
		return $term ? $term->name : '';
	}

	private static function speaker_user( int $sermon_id ): int {
		$term = self::speaker_term( $sermon_id );
		$user = $term ? (int) get_term_meta( $term->term_id, 'pn_user_id', true ) : 0;
		return ( $user && user_can( $user, 'edit_posts' ) ) ? $user : 0;
	}

	/** The preacher (when linked to a user) plus the "notify" addresses from Settings → Sermons. */
	private static function recipients( int $user_id ): array {
		$to   = array_filter( array_map( 'trim', explode( ',', (string) Settings::get( 'notify_emails' ) ) ), 'is_email' );
		$user = $user_id ? get_userdata( $user_id ) : false;
		if ( $user && is_email( $user->user_email ) ) {
			$to[] = $user->user_email;
		}
		return array_values( array_unique( $to ) );
	}

	private static function notify( int $post_id, int $author, array $checks, bool $stock_photo ): void {
		$to = self::recipients( $author );
		if ( ! $to ) {
			return;
		}
		/* translators: %s: title of the drafted blog post. */
		$subject = sprintf( __( 'Blog post ready for your review: %s', 'pn-sermons' ), wp_specialchars_decode( get_the_title( $post_id ) ) );
		$lines   = array(
			__( 'This week\'s sermon has been turned into a blog post draft. It is not on the website yet.', 'pn-sermons' ),
			'',
			__( 'Read it, change anything you like, and publish it (or click "Submit for Review" if you don\'t see Publish):', 'pn-sermons' ),
			admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
		);
		if ( $checks ) {
			$lines[] = '';
			$lines[] = __( 'Please check these, because the captions it was written from can mishear things:', 'pn-sermons' );
			foreach ( $checks as $check ) {
				$lines[] = '- ' . $check;
			}
		}
		$lines[] = '';
		$lines[] = $stock_photo
			? __( 'The picture is a free stock photo from Pexels. To use a different one, click the featured image in the post settings and choose or upload another.', 'pn-sermons' )
			: __( 'No stock photo was found, so the sermon\'s picture is used. You can change it under Featured image in the post settings.', 'pn-sermons' );
		wp_mail( $to, $subject, implode( "\n", $lines ) );
	}

	/** Captions could not be read: ask a person to paste the transcript. Sent once per sermon. */
	private static function ask_for_transcript( int $sermon_id, string $why, bool $renew = false ): void {
		if ( get_post_meta( $sermon_id, 'pn_transcript_asked', true ) ) {
			return;
		}
		update_post_meta( $sermon_id, 'pn_transcript_asked', time() );
		$to = self::recipients( self::speaker_user( $sermon_id ) );
		if ( ! $to ) {
			YouTube_Import::log( "Sermon {$sermon_id} needs a pasted transcript, but there is nobody to email (Settings > Sermons)." );
			return;
		}
		$video = (string) get_post_meta( $sermon_id, 'pn_youtube_id', true );
		/* translators: %s: sermon title. */
		$subject = sprintf( __( 'Transcript needed for this week\'s blog post: %s', 'pn-sermons' ), wp_specialchars_decode( get_the_title( $sermon_id ) ) );
		$lines   = array(
			'blocked' === $why
				? __( 'YouTube would not let the website read this week\'s sermon captions, so the blog post could not be drafted automatically.', 'pn-sermons' )
				: __( 'The website could not read captions for this week\'s sermon from YouTube, so the blog post could not be drafted automatically.', 'pn-sermons' ),
			'',
			__( 'To finish it, copy the transcript from YouTube (under the video: "...more", then "Show transcript"):', 'pn-sermons' ),
			preg_match( '/^[\w-]{11}$/', $video ) ? 'https://www.youtube.com/watch?v=' . $video : '',
			'',
			__( 'Then paste it on this page and click Save. The draft blog post will be ready a few minutes later, and you will get another email.', 'pn-sermons' ),
			self::paste_url( $sermon_id ),
		);
		if ( $renew ) {
			$lines[] = '';
			$lines[] = __( 'For the website administrator: Google refused the site\'s YouTube sign-in, so captions could not be read the usual way. Renew it (docs/sermons.md, "Captions from YouTube") and this will be automatic again.', 'pn-sermons' );
		}
		wp_mail( $to, $subject, implode( "\n", $lines ) );
	}

	/** The page where the preacher (or anyone who may edit the sermon) pastes its transcript. */
	public static function paste_url( int $sermon_id ): string {
		return admin_url( 'admin.php?page=' . self::PASTE_PAGE . '&sermon=' . $sermon_id );
	}

	/**
	 * Who may paste a sermon's transcript: anyone who may edit the sermon, and its linked preacher. Pastors are
	 * Authors, who cannot edit imported (or published) sermons, so the transcript has its own small page.
	 */
	public static function can_paste( int $sermon_id ): bool {
		if ( Post_Types::POST_TYPE !== get_post_type( $sermon_id ) || 'trash' === get_post_status( $sermon_id ) ) {
			return false;
		}
		$user = get_current_user_id();
		return $user && ( current_user_can( 'edit_post', $sermon_id ) || self::speaker_user( $sermon_id ) === $user );
	}

	public static function add_paste_page(): void {
		// Not in the menu: reached from the email link or the Transcript box.
		add_submenu_page( '', __( 'Paste transcript', 'pn-sermons' ), __( 'Paste transcript', 'pn-sermons' ), 'edit_posts', self::PASTE_PAGE, array( __CLASS__, 'render_paste_page' ) );
	}

	public static function render_paste_page(): void {
		$id = absint( $_GET['sermon'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only; access is checked below and saving is nonce-checked.
		if ( ! self::can_paste( $id ) ) {
			wp_die( esc_html__( 'You can\'t paste a transcript for this sermon.', 'pn-sermons' ), 403 );
		}
		$video = (string) get_post_meta( $id, 'pn_youtube_id', true );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Paste the sermon transcript', 'pn-sermons' ); ?></h1>
			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice. ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Saved. The blog post draft will be ready in a few minutes, and you will get an email.', 'pn-sermons' ); ?></p></div>
			<?php endif; ?>
			<p><strong><?php echo esc_html( get_the_title( $id ) ); ?></strong></p>
			<?php if ( preg_match( '/^[\w-]{11}$/', $video ) ) : ?>
				<p><?php esc_html_e( 'On YouTube, under the video, click "...more", then "Show transcript", and copy it:', 'pn-sermons' ); ?> <a href="<?php echo esc_url( 'https://www.youtube.com/watch?v=' . $video ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'open the video', 'pn-sermons' ); ?></a></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::PASTE_PAGE ); ?>">
				<input type="hidden" name="sermon" value="<?php echo esc_attr( (string) $id ); ?>">
				<?php wp_nonce_field( self::PASTE_PAGE . '_' . $id ); ?>
				<textarea name="pn_transcript" class="large-text" rows="16" aria-label="<?php esc_attr_e( 'Transcript', 'pn-sermons' ); ?>"><?php echo esc_textarea( (string) get_post_meta( $id, 'pn_transcript', true ) ); ?></textarea>
				<?php submit_button( __( 'Save', 'pn-sermons' ) ); ?>
			</form>
		</div>
		<?php
	}

	public static function handle_paste(): void {
		$id = absint( $_POST['sermon'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified on the next line.
		check_admin_referer( self::PASTE_PAGE . '_' . $id );
		if ( ! self::can_paste( $id ) ) {
			wp_die( esc_html__( 'You can\'t paste a transcript for this sermon.', 'pn-sermons' ), 403 );
		}
		self::accept_transcript( $id, wp_unslash( (string) ( $_POST['pn_transcript'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in accept_transcript().
		wp_safe_redirect( add_query_arg( 'saved', 1, self::paste_url( $id ) ) );
		exit;
	}

	/**
	 * Store a transcript a person pasted. When it is long enough, start the draft now: any caption retry still
	 * queued (up to six hours out) is cancelled first, so the draft doesn't wait for it.
	 */
	public static function accept_transcript( int $sermon_id, string $raw ): void {
		$text = mb_substr( sanitize_textarea_field( $raw ), 0, Transcript::MAX_CHARS );
		if ( (string) get_post_meta( $sermon_id, 'pn_transcript', true ) === $text ) {
			return;
		}
		update_post_meta( $sermon_id, 'pn_transcript', wp_slash( $text ) );
		if ( strlen( $text ) >= 500 && Settings::get( 'companion_enabled' ) && 'trash' !== get_post_status( $sermon_id ) && ! get_post_meta( $sermon_id, 'pn_companion_post', true ) ) {
			delete_post_meta( $sermon_id, 'pn_companion_tries' );
			wp_clear_scheduled_hook( self::HOOK, array( $sermon_id ) );
			self::schedule( $sermon_id, 30 );
		}
	}

	public static function add_transcript_box(): void {
		add_meta_box( 'pn-transcript', __( 'Transcript', 'pn-sermons' ), array( __CLASS__, 'render_transcript_box' ), null, 'normal', 'low' );
	}

	public static function render_transcript_box( \WP_Post $post ): void {
		wp_nonce_field( 'pn_transcript', 'pn_transcript_nonce' );
		?>
		<p class="description"><?php esc_html_e( 'Filled in automatically from YouTube\'s captions. If it is empty, paste the transcript here and save; when blog drafts are switched on, a draft post is made from it a few minutes later. Not shown on the website.', 'pn-sermons' ); ?></p>
		<textarea name="pn_transcript" class="large-text" rows="8" aria-label="<?php esc_attr_e( 'Transcript', 'pn-sermons' ); ?>"><?php echo esc_textarea( (string) get_post_meta( $post->ID, 'pn_transcript', true ) ); ?></textarea>
		<?php
	}

	public static function save_transcript( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST['pn_transcript_nonce'], $_POST['pn_transcript'] ) || ! wp_verify_nonce( sanitize_key( $_POST['pn_transcript_nonce'] ), 'pn_transcript' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || 'trash' === $post->post_status || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		self::accept_transcript( $post_id, wp_unslash( (string) $_POST['pn_transcript'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in accept_transcript().
	}

	public static function speaker_user_field( \WP_Term $term ): void {
		$current = (int) get_term_meta( $term->term_id, 'pn_user_id', true );
		?>
		<tr class="form-field">
			<th scope="row"><label for="pn_user_id"><?php esc_html_e( 'WordPress user', 'pn-sermons' ); ?></label></th>
			<td>
				<?php
				wp_dropdown_users(
					array(
						'name'              => 'pn_user_id',
						'id'                => 'pn_user_id',
						'selected'          => $current,
						'show_option_none'  => __( '— None —', 'pn-sermons' ),
						'option_none_value' => 0,
						'capability'        => array( 'edit_posts' ),
					)
				);
				wp_nonce_field( 'pn_speaker_user', 'pn_speaker_user_nonce' );
				?>
				<p class="description"><?php esc_html_e( 'Blog posts drafted from this speaker\'s sermons are saved under this account, and they get the review email.', 'pn-sermons' ); ?></p>
			</td>
		</tr>
		<?php
	}

	public static function save_speaker_user( int $term_id ): void {
		if ( ! isset( $_POST['pn_speaker_user_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['pn_speaker_user_nonce'] ), 'pn_speaker_user' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}
		update_term_meta( $term_id, 'pn_user_id', absint( $_POST['pn_user_id'] ?? 0 ) );
	}
}
