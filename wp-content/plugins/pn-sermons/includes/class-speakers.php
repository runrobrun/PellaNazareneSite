<?php
namespace PN_Sermons;

defined( 'ABSPATH' ) || exit;

/**
 * A photo for each speaker, chosen on the Speaker edit screen. The theme shows it on the sermon cards.
 *
 * A speaker with no photo chosen here falls back to whatever the `pn_sermons_speaker_photo_id` filter
 * returns (the theme uses the matching staff photo from the About page). No photo at all: no badge.
 */
class Speakers {

	const META  = 'pn_photo_id';
	const NONCE = 'pn_speaker_photo';

	public static function init(): void {
		add_action( 'pn_speaker_edit_form_fields', array( __CLASS__, 'photo_field' ), 5 );
		add_action( 'edited_pn_speaker', array( __CLASS__, 'save_photo' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );

		register_term_meta(
			'pn_speaker',
			self::META,
			array(
				'type'              => 'integer',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => 'absint',
				'auth_callback'     => fn() => current_user_can( 'manage_categories' ),
			)
		);
	}

	/**
	 * The media-library image to show for a speaker, or 0 for none.
	 *
	 * @param \WP_Term $term A pn_speaker term.
	 */
	public static function photo_id( \WP_Term $term ): int {
		$id = (int) get_term_meta( $term->term_id, self::META, true );
		if ( ! $id || ! wp_attachment_is_image( $id ) ) {
			/**
			 * The photo for a speaker who has none chosen on the Speaker screen.
			 *
			 * @param int      $id   Attachment ID, 0 for none.
			 * @param \WP_Term $term The speaker.
			 */
			$id = (int) apply_filters( 'pn_sermons_speaker_photo_id', 0, $term );
		}
		return ( $id && wp_attachment_is_image( $id ) ) ? $id : 0;
	}

	public static function admin_assets( string $hook ): void {
		if ( 'term.php' !== $hook || 'pn_speaker' !== get_current_screen()?->taxonomy ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script( 'pn-speaker-photo', PN_SERMONS_URL . 'assets/speaker-photo.js', array( 'media-editor' ), PN_SERMONS_VERSION, true );
	}

	public static function photo_field( \WP_Term $term ): void {
		$chosen   = (int) get_term_meta( $term->term_id, self::META, true );
		$fallback = $chosen ? 0 : self::photo_id( $term );
		$shown    = $chosen ? $chosen : $fallback;
		?>
		<tr class="form-field">
			<th scope="row"><label for="pn-speaker-photo-choose"><?php esc_html_e( 'Photo', 'pn-sermons' ); ?></label></th>
			<td>
				<div class="pn-speaker-photo" data-title="<?php esc_attr_e( 'Speaker photo', 'pn-sermons' ); ?>" data-button="<?php esc_attr_e( 'Use this photo', 'pn-sermons' ); ?>">
					<p class="pn-speaker-photo__preview"<?php echo $shown ? '' : ' hidden'; ?>>
						<?php echo $shown ? wp_get_attachment_image( $shown, 'thumbnail', false, array( 'style' => 'width:96px;height:96px;border-radius:50%;object-fit:cover' ) ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core builds and escapes the tag. ?>
					</p>
					<input type="hidden" name="pn_photo_id" value="<?php echo esc_attr( (string) $chosen ); ?>">
					<button type="button" class="button pn-speaker-photo__choose" id="pn-speaker-photo-choose"><?php esc_html_e( 'Choose photo', 'pn-sermons' ); ?></button>
					<button type="button" class="button-link pn-speaker-photo__remove" style="margin-left:8px"<?php echo $chosen ? '' : ' hidden'; ?>><?php esc_html_e( 'Remove', 'pn-sermons' ); ?></button>
					<?php wp_nonce_field( self::NONCE, 'pn_speaker_photo_nonce' ); ?>
				</div>
				<p class="description">
					<?php
					if ( $fallback ) {
						esc_html_e( 'Shown on the sermon cards. This is their staff photo from the About page; choose another to replace it here.', 'pn-sermons' );
					} else {
						esc_html_e( 'A small round photo shown on this speaker\'s sermon cards. Leave empty for no photo. A square head-and-shoulders picture works best.', 'pn-sermons' );
					}
					?>
				</p>
			</td>
		</tr>
		<?php
	}

	public static function save_photo( int $term_id ): void {
		if ( ! isset( $_POST['pn_speaker_photo_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['pn_speaker_photo_nonce'] ), self::NONCE ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}
		$id = absint( $_POST['pn_photo_id'] ?? 0 );
		if ( $id && wp_attachment_is_image( $id ) ) {
			update_term_meta( $term_id, self::META, $id );
		} else {
			delete_term_meta( $term_id, self::META );
		}
	}
}
