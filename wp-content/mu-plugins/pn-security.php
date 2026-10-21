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
 * two-factor set up. Until both methods are configured, every admin page except their own profile
 * redirects to it; setup-era sessions are revoked when enrollment completes.
 */

/**
 * Whether the Two Factor plugin is loaded AND exposes everything this file relies on.
 *
 * This file uses several internals of the plugin (pinned in plugins.txt). If an update ever renames one,
 * calling it would be a fatal error on every admin page, so instead we treat the plugin as unavailable:
 * fail open, and show administrators a red warning (see admin_notices below).
 *
 * @return bool
 */
function pn_two_factor_api_ready(): bool {
	return class_exists( 'Two_Factor_Core' ) && class_exists( 'Two_Factor_Totp' ) && class_exists( 'Two_Factor_Backup_Codes' )
		&& method_exists( 'Two_Factor_Core', 'get_enabled_providers_for_user' )
		&& method_exists( 'Two_Factor_Core', 'is_current_user_session_two_factor' )
		&& method_exists( 'Two_Factor_Core', 'get_user_two_factor_revalidate_url' )
		&& method_exists( 'Two_Factor_Core', 'update_current_user_session' )
		&& method_exists( 'Two_Factor_Totp', 'get_instance' )
		&& method_exists( 'Two_Factor_Totp', 'get_user_totp_key' )
		&& method_exists( 'Two_Factor_Backup_Codes', 'get_instance' )
		&& defined( 'Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY' )
		&& defined( 'Two_Factor_Totp::SECRET_META_KEY' )
		&& defined( 'Two_Factor_Backup_Codes::BACKUP_CODES_META_KEY' );
}

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
	if ( ! pn_two_factor_api_ready() || ! pn_user_must_use_two_factor( $user ) ) {
		return false;
	}
	$enabled_providers = Two_Factor_Core::get_enabled_providers_for_user( $user );
	return ! in_array( 'Two_Factor_Totp', $enabled_providers, true )
		|| '' === Two_Factor_Totp::get_instance()->get_user_totp_key( $user->ID )
		|| ! in_array( 'Two_Factor_Backup_Codes', $enabled_providers, true )
		|| ! metadata_exists( 'user', $user->ID, Two_Factor_Backup_Codes::BACKUP_CODES_META_KEY );
}

/**
 * Whether an enrolled user's current session has completed two-factor authentication.
 *
 * @param mixed $user A WP_User.
 * @return bool
 */
function pn_user_needs_two_factor_reauthentication( $user ): bool {
	return pn_two_factor_api_ready()
		&& pn_user_must_use_two_factor( $user )
		&& ! pn_user_needs_two_factor_setup( $user )
		&& ! Two_Factor_Core::is_current_user_session_two_factor();
}

/**
 * Revoke setup-era sessions when both required providers become fully configured.
 *
 * @param int    $meta_id  The user meta ID.
 * @param int    $user_id  The user ID.
 * @param string $meta_key The updated user meta key.
 * @return void
 */
function pn_revoke_sessions_after_two_factor_setup( $meta_id, $user_id, $meta_key ): void {
	unset( $meta_id );
	if ( ! pn_two_factor_api_ready() ) {
		return;
	}
	$watched_meta = array(
		Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY,
		Two_Factor_Totp::SECRET_META_KEY,
		Two_Factor_Backup_Codes::BACKUP_CODES_META_KEY,
	);
	if ( ! in_array( $meta_key, $watched_meta, true ) ) {
		return;
	}

	$user = get_user_by( 'id', $user_id );
	if ( ! pn_user_must_use_two_factor( $user ) ) {
		return;
	}

	$revoked_meta = 'pn_security_2fa_enrollment_sessions_revoked';
	if ( pn_user_needs_two_factor_setup( $user ) ) {
		delete_user_meta( $user_id, $revoked_meta );
		$enabled_providers = Two_Factor_Core::get_enabled_providers_for_user( $user );
		if ( get_current_user_id() === (int) $user_id
			&& in_array( 'Two_Factor_Totp', $enabled_providers, true )
			&& '' !== Two_Factor_Totp::get_instance()->get_user_totp_key( $user_id )
			&& ! Two_Factor_Core::is_current_user_session_two_factor()
		) {
			// Allow setup APIs in this setup-only session; the gates below still block normal admin and REST access.
			Two_Factor_Core::update_current_user_session(
				array(
					'two-factor-provider' => '',
					'two-factor-login'    => time(),
				)
			);
		}
		return;
	}
	if ( get_user_meta( $user_id, $revoked_meta, true ) ) {
		return;
	}

	update_user_meta( $user_id, $revoked_meta, '1' );
	WP_Session_Tokens::get_instance( $user_id )->destroy_all();
}
add_action( 'added_user_meta', 'pn_revoke_sessions_after_two_factor_setup', 10, 3 );
add_action( 'updated_user_meta', 'pn_revoke_sessions_after_two_factor_setup', 10, 3 );
add_action( 'deleted_user_meta', 'pn_revoke_sessions_after_two_factor_setup', 10, 3 );

add_action(
	'admin_init',
	function () {
		global $pagenow;
		$user = wp_get_current_user();
		if ( pn_user_needs_two_factor_setup( $user ) ) {
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
		if ( ! pn_user_needs_two_factor_reauthentication( $user ) ) {
			return;
		}
		if ( wp_doing_ajax() ) {
			wp_send_json_error( array( 'code' => 'pn_two_factor_required' ), 403 );
		}
		wp_safe_redirect( Two_Factor_Core::get_user_two_factor_revalidate_url() );
		exit;
	}
);

// The REST API is the other way in. Only the plugin's setup routes stay open during enrollment.
add_filter(
	'rest_pre_dispatch',
	function ( $result, $server, $request ) {
		$user        = wp_get_current_user();
		$setup_route = 0 === strpos( $request->get_route(), '/two-factor/' );
		if ( null === $result && pn_user_needs_two_factor_setup( $user ) && ! $setup_route ) {
			return new WP_Error(
				'pn_two_factor_required',
				__( 'Set up two-factor sign-in on your profile page first.', 'pella-nazarene' ),
				array( 'status' => 403 )
			);
		}
		if ( null === $result && pn_user_needs_two_factor_reauthentication( $user ) ) {
			return new WP_Error(
				'pn_two_factor_required',
				__( 'Complete two-factor sign-in before using the REST API.', 'pella-nazarene' ),
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
				esc_html__( 'Set up and enable the authenticator app, generate and save backup codes, then sign in again with a two-factor code to unlock the rest of the site.', 'pella-nazarene' )
			);
		}
		if ( ! pn_two_factor_api_ready() && current_user_can( 'manage_options' ) && ! ( defined( 'PN_REQUIRE_2FA' ) && ! PN_REQUIRE_2FA ) ) {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'Two-factor is NOT being enforced.', 'pella-nazarene' ),
				esc_html__( 'The "Two Factor" plugin is not active, or it is a version this site does not support. Activate version 0.17.0.', 'pella-nazarene' )
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
