<?php
/**
 * Plugin Name: Pella Nazarene Search and Sharing
 * Description: Page titles, a short description for each page, the picture and text shown when a page is shared (Facebook, texts, email), and the church's details for Google (address, phone, hours, services). Must-use plugin (always on).
 * Version:     0.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * The church's facts, in one place, for Google. The hours must match the Service info block shown on the site
 * (parts/service-info.html); the page tests compare the two, so they cannot drift apart unnoticed.
 */
function pn_church_info(): array {
	return array(
		'name'         => 'Pella Church of the Nazarene',
		'phone'        => '+1-641-628-1818',
		'email'        => 'pellanazarene@gmail.com',
		'address'      => array(
			'streetAddress'   => '303 University Street',
			'addressLocality' => 'Pella',
			'addressRegion'   => 'IA',
			'postalCode'      => '50219',
			'addressCountry'  => 'US',
		),
		// Office hours: day, opens, closes (24-hour, Central time).
		'office_hours' => array(
			array( 'Monday', '13:00', '16:00' ),
			array( 'Wednesday', '09:00', '12:00' ),
			array( 'Friday', '09:00', '12:00' ),
		),
		// Weekly services: name, day, starts (24-hour, Central time).
		'services'     => array(
			array( 'Sunday Worship', 'Sunday', '10:30' ),
			array( 'Small Groups', 'Sunday', '09:30' ),
		),
		'social'       => array(
			'https://www.facebook.com/PellaNazarene',
			'https://www.instagram.com/pellanazarene',
			'https://www.youtube.com/@pellanazarene',
		),
		'home_text'    => 'Pella Church of the Nazarene in Pella, Iowa. Join us Sundays: small groups at 9:30 AM, worship at 10:30 AM. Come as you are and grow together in Jesus.',
	);
}

// Pages can carry their own short description (the Excerpt box in the editor): it is what search results show.
add_action(
	'init',
	static function () {
		add_post_type_support( 'page', 'excerpt' );
	}
);

/*
 * ---------------------------------------------------------------------------
 * Page titles: "Kids | Pella Nazarene", and the home page "Pella Church of the Nazarene | Pella, Iowa"
 * ---------------------------------------------------------------------------
 */
add_filter( 'document_title_separator', static fn() => '|' );
add_filter(
	'document_title_parts',
	static function ( $parts ) {
		if ( is_front_page() ) {
			return array(
				'title'   => pn_church_info()['name'],
				'tagline' => 'Pella, Iowa',
			);
		}
		return $parts;
	}
);

/** Shortens text to a search-result length at a word boundary. */
function pn_seo_shorten( string $text, int $max = 155 ): string {
	$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) ) );
	if ( mb_strlen( $text ) <= $max ) {
		return $text;
	}
	$cut = mb_substr( $text, 0, $max - 1 );
	$pos = mb_strrpos( $cut, ' ' );
	return rtrim( false !== $pos && $pos > 80 ? mb_substr( $cut, 0, $pos ) : $cut, ' ,.;:-' ) . '…';
}

/** The description for the page being shown, or '' when there should be none (search, 404). */
function pn_seo_description(): string {
	if ( is_front_page() ) {
		return pn_church_info()['home_text'];
	}
	if ( is_home() ) {
		return 'News and encouragement from Pella Church of the Nazarene.';
	}
	if ( is_post_type_archive( 'pn_sermon' ) ) {
		return 'Sermons from Pella Church of the Nazarene: messages from our pastors, by series, speaker and scripture.';
	}
	if ( is_tax() ) {
		$term = get_queried_object();
		if ( $term instanceof WP_Term ) {
			return pn_seo_shorten( '' !== trim( (string) $term->description ) ? $term->description : sprintf( 'Sermons from Pella Church of the Nazarene: %s.', $term->name ) );
		}
	}
	if ( is_singular() ) {
		$post = get_queried_object();
		if ( $post instanceof WP_Post && ! post_password_required( $post ) ) {
			$text = '' !== trim( (string) $post->post_excerpt )
				? $post->post_excerpt
				: wp_strip_all_tags( strip_shortcodes( excerpt_remove_blocks( $post->post_content ) ) );
			return pn_seo_shorten( $text );
		}
	}
	return '';
}

/** The picture for sharing: the page's own picture, a sermon's video picture, or the church's default. */
function pn_seo_image(): array {
	$default = array(
		'url'    => get_theme_file_uri( 'assets/images/photos/og-default.jpg' ),
		'width'  => 1200,
		'height' => 630,
		'type'   => 'image/jpeg',
	);
	if ( is_singular() ) {
		$id = (int) get_queried_object_id();
		if ( has_post_thumbnail( $id ) ) {
			$src = wp_get_attachment_image_src( (int) get_post_thumbnail_id( $id ), 'large' );
			if ( $src ) {
				return array(
					'url'    => $src[0],
					'width'  => (int) $src[1],
					'height' => (int) $src[2],
					'type'   => '',
				);
			}
		}
		if ( 'pn_sermon' === get_post_type( $id ) ) {
			$video = (string) get_post_meta( $id, 'pn_youtube_id', true );
			if ( 1 === preg_match( '/^[\w-]{11}$/', $video ) ) {
				return array(
					'url'    => 'https://i.ytimg.com/vi/' . $video . '/maxresdefault.jpg',
					'width'  => 1280,
					'height' => 720,
					'type'   => 'image/jpeg',
				);
			}
		}
	}
	return $default;
}

/*
 * ---------------------------------------------------------------------------
 * The tags in the head of each page
 * ---------------------------------------------------------------------------
 */
add_action(
	'wp_head',
	static function () {
		if ( is_feed() || is_404() || is_search() || is_robots() ) {
			return;
		}
		$description = pn_seo_description();
		$title       = wp_get_document_title();
		$url         = is_singular() ? (string) wp_get_canonical_url() : '';
		if ( '' === $url ) {
			$url = home_url( add_query_arg( array() ) );
		}
		$article = is_singular( array( 'post', 'pn_sermon' ) );
		$image   = pn_seo_image();

		$tags = array(
			array( 'name', 'description', $description ),
			array( 'property', 'og:site_name', pn_church_info()['name'] ),
			array( 'property', 'og:locale', 'en_US' ),
			array( 'property', 'og:type', $article ? 'article' : 'website' ),
			array( 'property', 'og:title', $title ),
			array( 'property', 'og:description', $description ),
			array( 'property', 'og:url', $url ),
			array( 'property', 'og:image', $image['url'] ),
			array( 'property', 'og:image:width', (string) $image['width'] ),
			array( 'property', 'og:image:height', (string) $image['height'] ),
			array( 'property', 'og:image:type', $image['type'] ),
			array( 'name', 'twitter:card', 'summary_large_image' ),
		);
		if ( $article ) {
			$tags[] = array( 'property', 'article:published_time', (string) get_post_time( 'c', true ) );
		}
		foreach ( $tags as list( $attr, $key, $value ) ) {
			if ( '' !== trim( $value ) ) {
				printf( '<meta %s="%s" content="%s">' . "\n", esc_attr( $attr ), esc_attr( $key ), esc_attr( $value ) );
			}
		}
	},
	5
);

/*
 * ---------------------------------------------------------------------------
 * The church's details for Google (home page only): address, phone, office hours, weekly services, social pages
 * ---------------------------------------------------------------------------
 */

/** The church's details as schema.org "Church" data. */
function pn_seo_church_schema(): array {
	$church = pn_church_info();
	$hours  = array();
	foreach ( $church['office_hours'] as list( $day, $opens, $closes ) ) {
		$hours[] = array(
			'@type'     => 'OpeningHoursSpecification',
			'dayOfWeek' => $day,
			'opens'     => $opens,
			'closes'    => $closes,
		);
	}
	$events = array();
	foreach ( $church['services'] as list( $name, $day, $starts ) ) {
		$events[] = array(
			'@type'         => 'Event',
			'name'          => $name,
			'eventSchedule' => array(
				'@type'            => 'Schedule',
				'repeatFrequency'  => 'P1W',
				'byDay'            => 'https://schema.org/' . $day,
				'startTime'        => $starts . ':00',
				'scheduleTimezone' => 'America/Chicago',
			),
			'location'      => array(
				'@type'   => 'Place',
				'name'    => $church['name'],
				'address' => array_merge( array( '@type' => 'PostalAddress' ), $church['address'] ),
			),
		);
	}
	return array(
		'@context'                  => 'https://schema.org',
		'@type'                     => 'Church',
		'@id'                       => home_url( '/#church' ),
		'name'                      => $church['name'],
		'url'                       => home_url( '/' ),
		'description'               => $church['home_text'],
		'image'                     => pn_seo_image()['url'],
		'telephone'                 => $church['phone'],
		'email'                     => $church['email'],
		'address'                   => array_merge( array( '@type' => 'PostalAddress' ), $church['address'] ),
		'openingHoursSpecification' => $hours,
		'event'                     => $events,
		'sameAs'                    => $church['social'],
	);
}

add_action(
	'wp_head',
	static function () {
		if ( ! is_front_page() ) {
			return;
		}
		echo '<script type="application/ld+json">' . wp_json_encode( pn_seo_church_schema(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP ) . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON built from fixed values, with tag and ampersand characters escaped.
	},
	6
);
