<?php
/**
 * Plugin Name: Pella Nazarene Analytics
 * Description: Google Analytics 4 on the live site only. Never on staging or local copies, never for signed-in staff, and not for visitors whose browser says "do not track". Must-use plugin (always on).
 * Version:     0.1.0
 */

defined( 'ABSPATH' ) || exit;

/*
 * The measurement ID is a public identifier (it appears in every page's source), not a secret. It lives here, in one
 * place. A server can override it with PN_GA_MEASUREMENT_ID in wp-config.php (the tests use a made-up ID so they can
 * never record a visit in the real account).
 */
if ( ! defined( 'PN_GA_MEASUREMENT_ID' ) ) {
	define( 'PN_GA_MEASUREMENT_ID', 'G-3E45KJPG3G' );
}

/**
 * Whether this request should carry the analytics tag: the live site, a public page, an anonymous visitor.
 */
function pn_analytics_enabled(): bool {
	return 'production' === wp_get_environment_type()
		&& 1 === preg_match( '/^G-[A-Z0-9]{6,12}$/', (string) PN_GA_MEASUREMENT_ID )
		&& ! is_admin()
		&& ! is_user_logged_in()
		&& ! is_preview()
		&& ! is_customize_preview()
		&& ! is_feed()
		&& ! is_robots();
}

/*
 * Printed at the very end of the page, after the content, so it can never delay what the visitor came to read or
 * move anything on the screen. The script is added by a few lines of JavaScript, and only if the browser has not
 * sent "Global Privacy Control" or "Do Not Track": for those visitors Google is never contacted at all.
 */
add_action(
	'wp_footer',
	static function () {
		if ( ! pn_analytics_enabled() ) {
			return;
		}
		$id = (string) PN_GA_MEASUREMENT_ID;
		?>
<!-- Google Analytics (live site only) -->
<script>
(function () {
	if ( navigator.globalPrivacyControl === true || navigator.doNotTrack === '1' || window.doNotTrack === '1' ) { return; }
	window.dataLayer = window.dataLayer || [];
	function gtag() { window.dataLayer.push( arguments ); }
	gtag( 'js', new Date() );
	gtag( 'config', <?php echo wp_json_encode( $id ); ?> );
	var s = document.createElement( 'script' );
	s.async = true;
	s.src = <?php echo wp_json_encode( 'https://www.googletagmanager.com/gtag/js?id=' . rawurlencode( $id ), JSON_UNESCAPED_SLASHES ); // The ID is checked to be letters and digits only, so nothing here can close the script tag. ?>;
	document.head.appendChild( s );
}());
</script>
		<?php
	},
	99
);
