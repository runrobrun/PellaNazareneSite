<?php
namespace PN_Sermons;

defined( 'ABSPATH' ) || exit;

/**
 * Sermon post type plus series, speaker, and scripture taxonomies.
 */
class Post_Types {

	const POST_TYPE = 'pn_sermon';

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register' ] );
	}

	public static function register(): void {
		register_post_type( self::POST_TYPE, [
			'labels'        => [
				'name'          => __( 'Sermons', 'pn-sermons' ),
				'singular_name' => __( 'Sermon', 'pn-sermons' ),
				'add_new_item'  => __( 'Add New Sermon', 'pn-sermons' ),
				'edit_item'     => __( 'Edit Sermon', 'pn-sermons' ),
				'all_items'     => __( 'All Sermons', 'pn-sermons' ),
			],
			'public'        => true,
			'has_archive'   => 'sermons',
			'rewrite'       => [ 'slug' => 'sermons', 'with_front' => false ],
			'menu_icon'     => 'dashicons-microphone',
			'menu_position' => 5,
			'show_in_rest'  => true,
			'supports'      => [ 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields' ],
			'template'      => [
				[ 'core/paragraph', [ 'placeholder' => __( 'Optional: a short summary of the message.', 'pn-sermons' ) ] ],
			],
		] );

		$taxonomies = [
			'pn_series'    => [ __( 'Series', 'pn-sermons' ), __( 'Series', 'pn-sermons' ), 'sermons/series', true ],
			'pn_speaker'   => [ __( 'Speakers', 'pn-sermons' ), __( 'Speaker', 'pn-sermons' ), 'sermons/speaker', false ],
			'pn_scripture' => [ __( 'Scripture', 'pn-sermons' ), __( 'Book', 'pn-sermons' ), 'sermons/scripture', false ],
		];

		foreach ( $taxonomies as $slug => [ $plural, $singular, $rewrite, $hierarchical ] ) {
			register_taxonomy( $slug, self::POST_TYPE, [
				'labels'            => [ 'name' => $plural, 'singular_name' => $singular ],
				'hierarchical'      => $hierarchical,
				'public'            => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => [ 'slug' => $rewrite, 'with_front' => false ],
			] );
		}

		$meta = [
			'pn_youtube_id'      => 'YouTube video ID',
			'pn_scripture_ref'   => 'Main passage, e.g. "Acts 1–3"',
			'pn_companion_post'  => 'ID of the companion blog post, if drafted',
		];
		foreach ( $meta as $key => $description ) {
			register_post_meta( self::POST_TYPE, $key, [
				'type'          => $key === 'pn_companion_post' ? 'integer' : 'string',
				'single'        => true,
				'show_in_rest'  => true,
				'description'   => $description,
				'auth_callback' => fn() => current_user_can( 'edit_posts' ),
			] );
		}
	}
}
