<?php
/**
 * Sermon cards: the speaker's photo as a small round badge on the corner of the sermon artwork, so a page of
 * sermons from one series (all with the same graphic) still shows at a glance who preached each one.
 *
 * The photo is the one chosen on the Speaker screen (pn-sermons), or else the speaker's staff photo from the
 * About page (staff-<speaker slug> in assets/images/photos/manifest.json). No photo: no badge.
 *
 * @package pella-nazarene
 */

defined( 'ABSPATH' ) || exit;

// A speaker with no photo chosen gets their staff photo, if there is one.
add_filter(
	'pn_sermons_speaker_photo_id',
	function ( $id, $term ) {
		return $id ? $id : pn_photo_id( 'staff-' . $term->slug );
	},
	10,
	2
);

/**
 * The badge markup for a sermon's speaker, or '' for none. Looked up once per speaker per page.
 *
 * @param int $sermon_id Sermon post ID.
 */
function pn_speaker_badge( int $sermon_id ): string {
	static $badges = array();
	if ( ! class_exists( 'PN_Sermons\Speakers' ) ) {
		return '';
	}
	$terms = get_the_terms( $sermon_id, 'pn_speaker' );
	if ( ! $terms || is_wp_error( $terms ) ) {
		return '';
	}
	$term = $terms[0];
	if ( ! isset( $badges[ $term->term_id ] ) ) {
		$photo                    = PN_Sermons\Speakers::photo_id( $term );
		$badges[ $term->term_id ] = $photo ? wp_get_attachment_image(
			$photo,
			'thumbnail',
			false,
			array(
				'class'    => 'pn-speaker-badge',
				'alt'      => '', // The speaker's name is right below the picture.
				'sizes'    => '60px',
				'loading'  => 'lazy',
				'decoding' => 'async',
			)
		) : '';
	}
	return $badges[ $term->term_id ];
}

// Add the badge to each sermon's featured image in a list of sermons. Not on the sermon's own page, and not
// on a speaker's own page, where every card would show the same face.
add_filter(
	'render_block_core/post-featured-image',
	function ( $html, $block, $instance ) {
		$post_id = (int) ( $instance->context['postId'] ?? 0 );
		if (
			'' === trim( (string) $html )
			|| 'pn_sermon' !== get_post_type( $post_id )
			|| ( is_singular() && get_queried_object_id() === $post_id ) // the sermon's own page, not a card.
			|| is_tax( 'pn_speaker' )
		) {
			return $html;
		}
		$badge = pn_speaker_badge( $post_id );
		$end   = strrpos( (string) $html, '</figure>' );
		if ( '' === $badge || false === $end ) {
			return $html;
		}
		$tags = new WP_HTML_Tag_Processor( substr( (string) $html, 0, $end ) . $badge . substr( (string) $html, $end ) );
		if ( $tags->next_tag( 'figure' ) ) {
			$tags->add_class( 'pn-has-speaker' );
		}
		return $tags->get_updated_html();
	},
	10,
	3
);
