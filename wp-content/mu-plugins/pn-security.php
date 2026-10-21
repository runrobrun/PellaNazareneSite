<?php
/**
 * Plugin Name: Pella Nazarene Security
 * Description: Requires two-factor sign-in for everyone who can edit content, and closes username-leak paths. Must-use plugin (always on).
 * Version:     0.1.0
 *
 * Needs the "Two Factor" plugin (plugins.txt). If it is inactive this file fails OPEN so staff are never locked out,
 * and shows an admin warning.
 *
 * Emergency off-switch (lockouts, broken phone, plugin trouble): add to wp-config.php
 *     define( 'PN_REQUIRE_2FA', false );
 * Reset one person's 2FA: wp two-factor disable <user-id-or-login> --yes
 */

defined( 'ABSPATH' ) || exit;

/*
 * ---------------------------------------------------------------------------
 * Two-factor: methods
 * ---------------------------------------------------------------------------
 * Authenticator app (TOTP) and one-time backup codes only. No email codes (weaker, and they
 * depend on mail delivery) and no debug "dummy" provider. Priority 99 so we run after the
 * plugin's own filters.
 */
add_filter(
	'two_factor_providers',
	function ( $providers ) {
		return array_intersect_key( $providers, array_flip( array( 'Two_Factor_Totp', 'Two_Factor_Backup_Codes' ) ) );
	},
	99
);

/*
 * ---------------------------------------------------------------------------
 * Two-factor: enforcement
 * ---------------------------------------------------------------------------
 * Everyone who can edit content (Contributor and up: pastors, office staff, admins) must have
 * two-factor set up. Until they do, every admin page except their own profile redirects to it.
 */

/**
 * Whether this user must use two-factor sign-in.
 *
 * @param mixed $user A WP_User.
 * @return bool
 */
function pn_user_must_use_two_factor( $user ): bool {
	if ( defined( 'PN_REQUIRE_2FA' ) && ! PN_REQUIRE_2FA ) {
		return false;
	}
	return $user instanceof WP_User && $user->exists() && user_can( $user, 'edit_posts' );
}

/**
 * Whether this user still has to finish setting up two-factor.
 *
 * Fails open (false) if the Two Factor plugin is not loaded, so a plugin problem cannot lock everyone out.
 *
 * @param mixed $user A WP_User.
 * @return bool
 */
function pn_user_needs_two_factor_setup( $user ): bool {
	if ( ! class_exists( 'Two_Factor_Core' ) || ! pn_user_must_use_two_factor( $user ) ) {
		return false;
	}
	return ! Two_Factor_Core::is_user_using_two_factor( $user );
}

add_action(
	'admin_init',
	function () {
		global $pagenow;
		if ( ! pn_user_needs_two_factor_setup( wp_get_current_user() ) ) {
			return;
		}
		// admin-ajax.php is not used by the setup flow; refusing it stops a password-only session
		// from fetching a REST token (action=rest-nonce) and publishing without ever seeing a 2FA prompt.
		if ( wp_doing_ajax() ) {
			wp_send_json_error( array( 'code' => 'pn_two_factor_required' ), 403 );
		}
		// Setup itself happens on the profile page.
		if ( 'profile.php' === $pagenow ) {
			return;
		}
		wp_safe_redirect( admin_url( 'profile.php#two-factor-options' ) );
		exit;
	}
);

// The REST API is the other way in. Until two-factor is set up, only the plugin's own setup routes work.
add_filter(
	'rest_pre_dispatch',
	function ( $result, $server, $request ) {
		if ( null === $result
			&& 0 !== strpos( $request->get_route(), '/two-factor/' )
			&& pn_user_needs_two_factor_setup( wp_get_current_user() )
		) {
			return new WP_Error(
				'pn_two_factor_required',
				__( 'Set up two-factor sign-in on your profile page first.', 'pella-nazarene' ),
				array( 'status' => 403 )
			);
		}
		return $result;
	},
	10,
	3
);

add_action(
	'admin_notices',
	function () {
		global $pagenow;
		$user = wp_get_current_user();
		if ( 'profile.php' === $pagenow && pn_user_needs_two_factor_setup( $user ) ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'Two-factor sign-in is required.', 'pella-nazarene' ),
				esc_html__( 'Scroll to "Two-Factor Options", turn on the authenticator app, then save backup codes somewhere safe. The rest of the site unlocks as soon as it is on.', 'pella-nazarene' )
			);
		}
		if ( ! class_exists( 'Two_Factor_Core' ) && current_user_can( 'manage_options' ) && ! ( defined( 'PN_REQUIRE_2FA' ) && ! PN_REQUIRE_2FA ) ) {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'Two-factor is NOT being enforced.', 'pella-nazarene' ),
				esc_html__( 'The "Two Factor" plugin is not active. Activate it.', 'pella-nazarene' )
			);
		}
	}
);

/*
 * ---------------------------------------------------------------------------
 * Don't leak usernames
 * ---------------------------------------------------------------------------
 */

// One generic message for a wrong username or a wrong password.
add_filter(
	'wp_login_errors',
	function ( $errors ) {
		$leaky = array( 'invalid_username', 'incorrect_password', 'invalid_email' );
		$found = false;
		foreach ( $leaky as $code ) {
			if ( $errors->get_error_message( $code ) ) {
				$errors->remove( $code );
				$found = true;
			}
		}
		if ( $found ) {
			$errors->add( 'pn_login_failed', __( '<strong>Error:</strong> Incorrect username or password.', 'pella-nazarene' ) );
		}
		return $errors;
	}
);

// The church has no per-author pages, and an author URL contains the account's nicename.
add_action(
	'template_redirect',
	function () {
		if ( is_author() ) {
			wp_safe_redirect( home_url( '/' ), 301 );
			exit;
		}
	}
);
add_filter(
	'author_link',
	function () {
		return home_url( '/' );
	}
);

// No user list in the XML sitemaps, no author name in oEmbed data.
add_filter(
	'wp_sitemaps_add_provider',
	function ( $provider, $name ) {
		return 'users' === $name ? false : $provider;
	},
	10,
	2
);
add_filter(
	'oembed_response_data',
	function ( $data ) {
		unset( $data['author_name'], $data['author_url'] );
		return $data;
	}
);

// Nobody uses application passwords here, and they would bypass two-factor for REST requests.
add_filter( 'wp_is_application_passwords_available', '__return_false' );
