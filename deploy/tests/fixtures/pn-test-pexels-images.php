<?php
/**
 * Test only (deploy/tests/companion-check.sh): answers downloads from images.pexels.com with a generated JPEG,
 * so no real image is fetched. Every intercepted address is logged to /tmp/pexels-downloads.log.
 */
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( ! str_starts_with( (string) $url, 'https://images.pexels.com/' ) ) {
			return $pre;
		}
		file_put_contents( '/tmp/pexels-downloads.log', $url . "\n", FILE_APPEND ); // phpcs:ignore
		$img = imagecreatetruecolor( 1880, 1253 );
		imagefill( $img, 0, 0, imagecolorallocate( $img, 45, 61, 84 ) );
		ob_start();
		imagejpeg( $img, null, 80 );
		$jpg = (string) ob_get_clean();
		if ( ! empty( $args['filename'] ) ) {
			file_put_contents( $args['filename'], $jpg ); // phpcs:ignore
		}
		return array(
			'headers'  => array( 'content-type' => 'image/jpeg' ),
			'body'     => empty( $args['filename'] ) ? $jpg : '',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => $args['filename'] ?? null,
		);
	},
	10,
	3
);
