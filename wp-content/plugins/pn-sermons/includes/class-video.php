<?php
namespace PN_Sermons;

defined( 'ABSPATH' ) || exit;

/**
 * Fast YouTube embed: shows the thumbnail and loads the real player only on click.
 *
 * Usage in templates or content: [pn_sermon_video] (current sermon) or [pn_sermon_video id="Nll1UzWZHQA"].
 */
class Video {

	public static function init(): void {
		add_shortcode( 'pn_sermon_video', [ __CLASS__, 'shortcode' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'assets' ] );
	}

	public static function assets(): void {
		wp_register_style( 'pn-sermon-video', PN_SERMONS_URL . 'assets/video.css', [], PN_SERMONS_VERSION );
		wp_register_script( 'pn-sermon-video', PN_SERMONS_URL . 'assets/video.js', [], PN_SERMONS_VERSION, [ 'strategy' => 'defer', 'in_footer' => true ] );
	}

	public static function shortcode( $atts ): string {
		$atts = shortcode_atts( [ 'id' => '' ], $atts, 'pn_sermon_video' );
		$id   = $atts['id'] ?: (string) get_post_meta( get_the_ID(), 'pn_youtube_id', true );
		if ( ! preg_match( '/^[\w-]{11}$/', $id ) ) {
			return '';
		}

		wp_enqueue_style( 'pn-sermon-video' );
		wp_enqueue_script( 'pn-sermon-video' );

		$title = get_the_title() ?: __( 'Sermon video', 'pn-sermons' );

		return sprintf(
			'<div class="pn-video" data-id="%1$s"><img src="https://i.ytimg.com/vi/%1$s/hqdefault.jpg" alt="" loading="lazy" decoding="async" width="480" height="360"><button type="button" class="pn-video__play" aria-label="%2$s"></button></div>',
			esc_attr( $id ),
			esc_attr( sprintf( __( 'Play video: %s', 'pn-sermons' ), wp_strip_all_tags( $title ) ) )
		);
	}
}
