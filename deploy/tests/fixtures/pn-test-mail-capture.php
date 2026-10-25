<?php
/**
 * Test only: records every email the site tries to send (one JSON line each in /tmp/mail.log) and sends none.
 */
add_filter(
	'pre_wp_mail',
	static function ( $short, $atts ) {
		file_put_contents( '/tmp/mail.log', wp_json_encode( $atts ) . "\n", FILE_APPEND ); // phpcs:ignore
		return true;
	},
	1,
	2
);

// Keep the tests deterministic: Fluent Forms' own burst limit (5 per 30 seconds) is out of the way, and our hourly
// limit is whatever the test sets in the pn_test_rate_limit option.
add_filter( 'fluentform/max_submission_count', static fn() => 1000 );
add_filter( 'pn_forms_rate_limit', static fn() => (int) get_option( 'pn_test_rate_limit', 100 ) );
