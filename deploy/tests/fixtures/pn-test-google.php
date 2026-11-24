<?php
/**
 * Test only (deploy/tests/companion-check.sh): signs the site in to the fake Google while /tmp/gapi-on exists,
 * so one run can test captions both with and without the YouTube API.
 */
if ( file_exists( '/tmp/gapi-on' ) ) {
	define( 'PN_YOUTUBE_OAUTH_CLIENT_ID', 'test-client-id' );
	define( 'PN_YOUTUBE_OAUTH_CLIENT_SECRET', 'test-client-secret' );
	define( 'PN_YOUTUBE_OAUTH_REFRESH_TOKEN', 'test-refresh-token' );
}
