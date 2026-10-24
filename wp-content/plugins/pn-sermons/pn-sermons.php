<?php
/**
 * Plugin Name:       Pella Nazarene Sermons
 * Description:       Sermons with series, speakers, and scripture; imports sermons from YouTube (new ones automatically, the whole history with a command) as drafts for review; drafts companion blog posts.
 * Version:           0.1.1
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            Pella Nazarene
 * Text Domain:       pn-sermons
 */

defined( 'ABSPATH' ) || exit;

define( 'PN_SERMONS_VERSION', '0.1.1' );
define( 'PN_SERMONS_DIR', plugin_dir_path( __FILE__ ) );
define( 'PN_SERMONS_URL', plugin_dir_url( __FILE__ ) );

require_once PN_SERMONS_DIR . 'includes/class-post-types.php';
require_once PN_SERMONS_DIR . 'includes/class-settings.php';
require_once PN_SERMONS_DIR . 'includes/class-sermon-parser.php';
require_once PN_SERMONS_DIR . 'includes/class-sermon-data.php';
require_once PN_SERMONS_DIR . 'includes/class-youtube-api.php';
require_once PN_SERMONS_DIR . 'includes/class-youtube-import.php';
require_once PN_SERMONS_DIR . 'includes/class-video.php';
require_once PN_SERMONS_DIR . 'includes/class-companion.php';

PN_Sermons\Post_Types::init();
PN_Sermons\Settings::init();
PN_Sermons\YouTube_Import::init();
PN_Sermons\Video::init();
PN_Sermons\Companion::init();

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once PN_SERMONS_DIR . 'includes/class-cli.php';
	WP_CLI::add_command( 'pn-sermons', PN_Sermons\CLI::class );
}

register_activation_hook(
	__FILE__,
	function () {
		PN_Sermons\Post_Types::register();
		flush_rewrite_rules();
		PN_Sermons\YouTube_Import::schedule();
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		PN_Sermons\YouTube_Import::unschedule();
		flush_rewrite_rules();
	}
);
