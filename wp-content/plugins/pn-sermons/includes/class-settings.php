<?php
namespace PN_Sermons;

defined( 'ABSPATH' ) || exit;

/**
 * Settings → Sermons. Secrets come from wp-config.php constants when defined.
 */
class Settings {

	const OPTION = 'pn_sermons_settings';

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_init', [ __CLASS__, 'register' ] );
		add_action( 'admin_post_pn_sermons_import_now', [ __CLASS__, 'handle_import_now' ] );
	}

	public static function defaults(): array {
		return [
			'channel_id'        => '',
			'title_filter'      => '',
			'companion_enabled' => 0,
			'companion_prompt'  => Companion::default_prompt(),
			'notify_emails'     => '',
		];
	}

	public static function get( string $key ) {
		if ( 'channel_id' === $key && defined( 'PN_YOUTUBE_CHANNEL_ID' ) ) {
			return PN_YOUTUBE_CHANNEL_ID;
		}
		$opts = wp_parse_args( get_option( self::OPTION, [] ), self::defaults() );
		return $opts[ $key ] ?? null;
	}

	public static function menu(): void {
		add_options_page( __( 'Sermons', 'pn-sermons' ), __( 'Sermons', 'pn-sermons' ), 'manage_options', 'pn-sermons', [ __CLASS__, 'render' ] );
	}

	public static function register(): void {
		register_setting( 'pn_sermons', self::OPTION, [
			'type'              => 'array',
			'sanitize_callback' => [ __CLASS__, 'sanitize' ],
			'default'           => self::defaults(),
		] );
	}

	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : [];
		return [
			'channel_id'        => sanitize_text_field( $input['channel_id'] ?? '' ),
			'title_filter'      => sanitize_text_field( $input['title_filter'] ?? '' ),
			'companion_enabled' => empty( $input['companion_enabled'] ) ? 0 : 1,
			'companion_prompt'  => sanitize_textarea_field( $input['companion_prompt'] ?? Companion::default_prompt() ),
			'notify_emails'     => implode( ',', array_filter( array_map( 'sanitize_email', explode( ',', $input['notify_emails'] ?? '' ) ) ) ),
		];
	}

	public static function render(): void {
		$o     = wp_parse_args( get_option( self::OPTION, [] ), self::defaults() );
		$name  = self::OPTION;
		$const = defined( 'PN_YOUTUBE_CHANNEL_ID' );
		$last  = get_option( YouTube_Import::LAST_RUN_OPTION );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Sermon settings', 'pn-sermons' ); ?></h1>
			<?php if ( isset( $_GET['imported'] ) ) : ?>
				<div class="notice notice-success"><p><?php printf( esc_html__( 'Import finished: %d new sermon draft(s).', 'pn-sermons' ), (int) $_GET['imported'] ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'pn_sermons' ); ?>
				<h2><?php esc_html_e( 'YouTube import', 'pn-sermons' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pn-channel"><?php esc_html_e( 'Channel ID', 'pn-sermons' ); ?></label></th>
						<td>
							<input id="pn-channel" class="regular-text" name="<?php echo esc_attr( $name ); ?>[channel_id]" value="<?php echo esc_attr( $const ? PN_YOUTUBE_CHANNEL_ID : $o['channel_id'] ); ?>" <?php disabled( $const ); ?>>
							<p class="description"><?php esc_html_e( 'Starts with "UC". Found in YouTube Studio → Settings → Channel → Advanced.', 'pn-sermons' ); ?><?php if ( $const ) { echo ' ' . esc_html__( '(Set in wp-config.php.)', 'pn-sermons' ); } ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pn-filter"><?php esc_html_e( 'Only import titles containing', 'pn-sermons' ); ?></label></th>
						<td>
							<input id="pn-filter" class="regular-text" name="<?php echo esc_attr( $name ); ?>[title_filter]" value="<?php echo esc_attr( $o['title_filter'] ); ?>">
							<p class="description"><?php esc_html_e( 'Optional. Skips non-sermon videos, e.g. "Pastor".', 'pn-sermons' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Companion blog posts', 'pn-sermons' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Draft companion posts', 'pn-sermons' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[companion_enabled]" value="1" <?php checked( $o['companion_enabled'] ); ?>> <?php esc_html_e( 'Draft a companion post for each new sermon (requires PN_ANTHROPIC_API_KEY in wp-config.php)', 'pn-sermons' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="pn-prompt"><?php esc_html_e( 'Writing instructions', 'pn-sermons' ); ?></label></th>
						<td><textarea id="pn-prompt" class="large-text" rows="12" name="<?php echo esc_attr( $name ); ?>[companion_prompt]"><?php echo esc_textarea( $o['companion_prompt'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="pn-notify"><?php esc_html_e( 'Also notify', 'pn-sermons' ); ?></label></th>
						<td>
							<input id="pn-notify" class="regular-text" name="<?php echo esc_attr( $name ); ?>[notify_emails]" value="<?php echo esc_attr( $o['notify_emails'] ); ?>">
							<p class="description"><?php esc_html_e( 'Comma-separated. The preaching pastor is always notified when their speaker term is linked to a user.', 'pn-sermons' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Run now', 'pn-sermons' ); ?></h2>
			<p><?php echo $last ? esc_html( sprintf( __( 'Last checked: %s', 'pn-sermons' ), wp_date( 'M j, Y g:i a', (int) $last ) ) ) : esc_html__( 'Not run yet.', 'pn-sermons' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pn_sermons_import_now">
				<?php wp_nonce_field( 'pn_sermons_import_now' ); ?>
				<?php submit_button( __( 'Check YouTube now', 'pn-sermons' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	public static function handle_import_now(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'pn-sermons' ) );
		}
		check_admin_referer( 'pn_sermons_import_now' );
		$count = YouTube_Import::run();
		wp_safe_redirect( add_query_arg( 'imported', (int) $count, admin_url( 'options-general.php?page=pn-sermons' ) ) );
		exit;
	}
}
