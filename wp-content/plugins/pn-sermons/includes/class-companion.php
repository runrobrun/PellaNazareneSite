<?php
namespace PN_Sermons;

defined( 'ABSPATH' ) || exit;

/**
 * Drafts a companion blog post when a sermon is published.
 *
 * Flow: pastor publishes the imported sermon (after choosing speaker/series)
 * → a background job fetches the transcript → Claude drafts the post
 * → saved as a DRAFT under the speaker's user account → pastor is emailed.
 *
 * Transcripts: YouTube caption download needs OAuth as the channel owner and is
 * not built yet. Until then, paste the transcript into the sermon's
 * "pn_transcript" custom field, or hook the `pn_sermons_transcript` filter.
 */
class Companion {

	const HOOK          = 'pn_sermons_draft_companion';
	const CATEGORY      = 'Sermon Companion';
	const API_URL       = 'https://api.anthropic.com/v1/messages';
	const DEFAULT_MODEL = 'claude-sonnet-5-5';

	public static function init(): void {
		add_action( 'transition_post_status', array( __CLASS__, 'on_publish' ), 10, 3 );
		add_action( self::HOOK, array( __CLASS__, 'draft' ) );

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
Write a companion blog post for the sermon transcript below, for the website of Pella Nazarene, a Church of the Nazarene congregation in Pella, Iowa.

- 400 to 600 words, warm and plain, written for church members and curious visitors.
- Open with a short hook drawn from the sermon, name the main scripture passage, then cover two or three key points.
- Write in third person about the sermon ("Pastor Cindy reminded us…"), not as if the pastor is speaking.
- End with three short discussion questions for small groups, under the heading "For discussion".
- Quote scripture only as it appears in the transcript. Never quote from memory.
- Leave out prayer requests, announcements, and the names of anyone other than the preacher.
- If anything in the transcript is unclear or may be mis-transcribed (names, verse numbers), add a line at the very top starting "PASTOR CHECK:" listing what to verify.
PROMPT;
	}

	public static function on_publish( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'publish' !== $new_status || 'publish' === $old_status || Post_Types::POST_TYPE !== $post->post_type ) {
			return;
		}
		if ( ! Settings::get( 'companion_enabled' ) || get_post_meta( $post->ID, 'pn_companion_post', true ) ) {
			return;
		}
		// Run in the background so publishing stays fast.
		wp_schedule_single_event( time() + 30, self::HOOK, array( $post->ID ) );
	}

	public static function draft( int $sermon_id ): void {
		$sermon = get_post( $sermon_id );
		if ( ! $sermon || get_post_meta( $sermon_id, 'pn_companion_post', true ) ) {
			return;
		}

		$video_id   = (string) get_post_meta( $sermon_id, 'pn_youtube_id', true );
		$transcript = (string) apply_filters( 'pn_sermons_transcript', (string) get_post_meta( $sermon_id, 'pn_transcript', true ), $sermon_id, $video_id );
		if ( strlen( $transcript ) < 500 ) {
			YouTube_Import::log( "No usable transcript for sermon {$sermon_id}; companion post skipped." );
			return;
		}

		$api_key = defined( 'PN_ANTHROPIC_API_KEY' ) ? PN_ANTHROPIC_API_KEY : '';
		if ( ! $api_key ) {
			YouTube_Import::log( 'PN_ANTHROPIC_API_KEY is not defined; companion post skipped.' );
			return;
		}

		$speaker = self::speaker_name( $sermon_id );
		$context = sprintf(
			"Sermon title: %s\nPreacher: %s\nMain passage: %s\n\nReturn ONLY a JSON object with keys \"title\" (string), \"excerpt\" (one sentence), and \"body_html\" (the post as simple HTML using <p>, <h2>, <ul>, <li>, <strong>, <em>, <blockquote>).\n\n<transcript>\n%s\n</transcript>",
			get_the_title( $sermon ),
			$speaker ?: 'unknown',
			(string) get_post_meta( $sermon_id, 'pn_scripture_ref', true ) ?: 'not specified',
			$transcript
		);

		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => 120,
				'headers' => array(
					'x-api-key'         => $api_key,
					'anthropic-version' => '2023-06-01',
					'content-type'      => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'      => apply_filters( 'pn_sermons_model', self::DEFAULT_MODEL ),
						'max_tokens' => 2000,
						'system'     => (string) Settings::get( 'companion_prompt' ),
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

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			YouTube_Import::log( 'Claude API error: ' . ( is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_body( $response ) ) );
			return;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$text = $data['content'][0]['text'] ?? '';
		$post = self::parse_json( $text );
		if ( ! $post ) {
			YouTube_Import::log( "Could not parse companion post for sermon {$sermon_id}." );
			return;
		}

		$author = self::speaker_user( $sermon_id ) ?: (int) $sermon->post_author;
		$body   = wp_kses_post( $post['body_html'] );
		$body  .= sprintf( '<p><em>%s <a href="%s">%s</a></em></p>', esc_html__( 'Adapted from Sunday\'s message.', 'pn-sermons' ), esc_url( get_permalink( $sermon ) ), esc_html__( 'Watch the full sermon.', 'pn-sermons' ) );

		$post_id = wp_insert_post(
			array(
				'post_type'     => 'post',
				'post_status'   => 'draft',
				'post_author'   => $author,
				'post_title'    => sanitize_text_field( $post['title'] ),
				'post_excerpt'  => sanitize_text_field( $post['excerpt'] ?? '' ),
				'post_content'  => $body,
				'post_category' => array( self::category_id() ),
				'meta_input'    => array( 'pn_sermon_id' => $sermon_id ),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			YouTube_Import::log( 'Could not save companion post: ' . $post_id->get_error_message() );
			return;
		}

		if ( has_post_thumbnail( $sermon ) ) {
			set_post_thumbnail( $post_id, get_post_thumbnail_id( $sermon ) );
		}
		update_post_meta( $sermon_id, 'pn_companion_post', (int) $post_id );
		self::notify( (int) $post_id, $author );
	}

	private static function parse_json( string $text ): ?array {
		// Tolerate a fenced code block around the JSON.
		$text = trim( preg_replace( '/^```(?:json)?\s*|\s*```$/', '', trim( $text ) ) );
		$json = json_decode( $text, true );
		if ( ! is_array( $json ) || empty( $json['title'] ) || empty( $json['body_html'] ) ) {
			return null;
		}
		return $json;
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
		return $term ? (int) get_term_meta( $term->term_id, 'pn_user_id', true ) : 0;
	}

	private static function notify( int $post_id, int $author ): void {
		$to   = array_filter( explode( ',', (string) Settings::get( 'notify_emails' ) ) );
		$user = get_userdata( $author );
		if ( $user ) {
			$to[] = $user->user_email;
		}
		if ( ! $to ) {
			return;
		}
		/* translators: %s: title of the sermon the draft was written for. */
		$subject = sprintf( __( 'Draft ready for review: %s', 'pn-sermons' ), get_the_title( $post_id ) );
		$message = sprintf(
			"%s\n\n%s\n\n%s",
			__( 'A companion blog post for this week\'s sermon has been drafted. Please read it, edit as needed, and publish when you\'re happy with it.', 'pn-sermons' ),
			admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
			__( 'Look for a "PASTOR CHECK:" line at the top listing anything to verify, and delete it before publishing.', 'pn-sermons' )
		);
		wp_mail( array_unique( $to ), $subject, $message );
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
				<p class="description"><?php esc_html_e( 'Companion posts for this speaker are drafted under this account, and they get the review email.', 'pn-sermons' ); ?></p>
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
