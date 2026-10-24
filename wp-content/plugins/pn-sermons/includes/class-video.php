<?php
namespace PN_Sermons;

defined( 'ABSPATH' ) || exit;

/**
 * Fast YouTube embed: shows the thumbnail and loads the real player only on click.
 *
 * Usage in templates or content: [pn_sermon_video] (current sermon) or [pn_sermon_video id="Nll1UzWZHQA"].
 * [pn_latest_sermon] shows the newest published sermon (video, title, date, speaker) for the home page.
 */
class Video {

	public static function init(): void {
		add_shortcode( 'pn_sermon_video', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'pn_latest_sermon', array( __CLASS__, 'latest' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function assets(): void {
		wp_register_style( 'pn-sermon-video', PN_SERMONS_URL . 'assets/video.css', array(), PN_SERMONS_VERSION );
		wp_register_script(
			'pn-sermon-video',
			PN_SERMONS_URL . 'assets/video.js',
			array(),
			PN_SERMONS_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
	}

	public static function shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'id' => '' ), $atts, 'pn_sermon_video' );
		$id   = $atts['id'] ?: (string) get_post_meta( get_the_ID(), 'pn_youtube_id', true );
		if ( ! preg_match( '/^[\w-]{11}$/', $id ) ) {
			return '';
		}

		return self::facade( $id, get_the_title() ?: __( 'Sermon video', 'pn-sermons' ) );
	}

	/** The click-to-load player for one video: a thumbnail and a play button, nothing from YouTube until it is clicked. */
	private static function facade( string $id, string $title ): string {
		wp_enqueue_style( 'pn-sermon-video' );
		wp_enqueue_script( 'pn-sermon-video' );

		return sprintf(
			'<div class="pn-video" data-id="%1$s"><img src="https://i.ytimg.com/vi/%1$s/hqdefault.jpg" alt="" loading="lazy" decoding="async" width="480" height="360"><button type="button" class="pn-video__play" aria-label="%2$s"></button></div>',
			esc_attr( $id ),
			/* translators: %s: sermon title. */
			esc_attr( sprintf( __( 'Play video: %s', 'pn-sermons' ), wp_strip_all_tags( $title ) ) )
		);
	}

	/** The newest published sermon, for the home page. Drafts are never shown. */
	public static function latest(): string {
		$found = get_posts(
			array(
				'post_type'      => Post_Types::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);
		if ( ! $found ) {
			return '<p>' . sprintf(
				/* translators: %s: link to the YouTube channel. */
				esc_html__( 'Our latest services are on %s.', 'pn-sermons' ),
				'<a href="https://www.youtube.com/@pellanazarene">YouTube</a>'
			) . '</p>';
		}
		$sermon   = $found[0];
		$video_id = (string) get_post_meta( $sermon->ID, 'pn_youtube_id', true );
		$speakers = get_the_terms( $sermon->ID, 'pn_speaker' );
		$byline   = array( get_the_date( '', $sermon ) );
		if ( is_array( $speakers ) ) {
			$byline[] = implode( ', ', wp_list_pluck( $speakers, 'name' ) );
		}

		$html = '<div class="pn-latest-sermon">';
		if ( preg_match( '/^[\w-]{11}$/', $video_id ) ) {
			$html .= self::facade( $video_id, get_the_title( $sermon ) );
		}
		$html .= sprintf(
			'<h3 class="wp-block-heading"><a href="%s">%s</a></h3><p class="has-small-font-size">%s</p></div>',
			esc_url( get_permalink( $sermon ) ),
			esc_html( get_the_title( $sermon ) ),
			esc_html( implode( ' · ', array_filter( $byline ) ) )
		);
		return $html;
	}
}
