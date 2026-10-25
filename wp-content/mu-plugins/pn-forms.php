<?php
/**
 * Plugin Name: Pella Nazarene Forms
 * Description: The site's Contact and Prayer Request forms (built with Fluent Forms): where they appear, spam checks, and how long messages are kept. Must-use plugin (always on).
 * Version:     0.1.0
 */

defined( 'ABSPATH' ) || exit;

/*
 * ---------------------------------------------------------------------------
 * Showing a form on a page: [pn_form name="contact"] or [pn_form name="prayer"]
 * ---------------------------------------------------------------------------
 * The forms are created by scripts/create-forms.sh, which records their IDs. Pages use the name, so the same
 * page text works on staging and on the live site. Fluent Forms loads its own CSS and JS only on pages that
 * show a form.
 */

/** The IDs of our forms, by name (contact, prayer). */
function pn_form_ids(): array {
	return array_map( 'intval', (array) get_option( 'pn_forms', array() ) );
}

add_shortcode(
	'pn_form',
	static function ( $atts ): string {
		$atts = shortcode_atts( array( 'name' => '' ), $atts, 'pn_form' );
		$id   = pn_form_ids()[ sanitize_key( $atts['name'] ) ] ?? 0;
		if ( ! $id || ! shortcode_exists( 'fluentform' ) ) {
			return '';
		}
		// A person takes a few seconds to write a message; a bot does not. Note when the page loaded.
		wp_register_script( 'pn-forms', false, array(), '0.1.0', true );
		wp_enqueue_script( 'pn-forms' );
		wp_add_inline_script( 'pn-forms', 'document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll(\'input[name="pn_loaded"]\').forEach(function(i){i.value=String(Date.now());});});' );
		return do_shortcode( '[fluentform id="' . $id . '"]' );
	}
);

/*
 * ---------------------------------------------------------------------------
 * Spam checks (on top of Fluent Forms' hidden spam-trap field)
 * ---------------------------------------------------------------------------
 *  1. Too fast: a form sent less than three seconds after the page loaded, or without the page-load note, is a bot.
 *  2. Too many: one connection may send a few messages an hour (5 by default; filter pn_forms_rate_limit).
 * The message is shown beside the main text box so the visitor sees it.
 */
add_filter(
	'fluentform/validation_errors',
	static function ( $errors, $form_data, $form ) {
		$ids  = array_flip( pn_form_ids() );
		$name = $ids[ (int) ( $form->id ?? 0 ) ] ?? '';
		if ( '' === $name || ! empty( $errors ) ) {
			return $errors; // not one of our forms, or it already has a problem to show.
		}
		$field = 'prayer' === $name ? 'request' : 'message';

		$loaded = isset( $form_data['pn_loaded'] ) ? (string) $form_data['pn_loaded'] : '';
		$age_ms = ctype_digit( $loaded ) ? ( (int) round( microtime( true ) * 1000 ) - (int) $loaded ) : -1;
		if ( $age_ms < (int) apply_filters( 'pn_forms_min_milliseconds', 3000 ) ) {
			$errors[ $field ] = array( 'pn_fast' => __( 'That was a little quick. Please wait a moment and press send again.', 'pella-nazarene' ) );
			return $errors;
		}

		$limit = (int) apply_filters( 'pn_forms_rate_limit', 5 );
		$key   = 'pn_rl_' . md5( $name . '|' . (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- hashed, never output.
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			$errors[ $field ] = array( 'pn_rate' => __( 'We have received several messages from your connection already. Please try again in an hour, or call the church office at (641) 628-1818.', 'pella-nazarene' ) );
			return $errors;
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return $errors;
	},
	10,
	3
);

/**
 * Removes one saved form entry completely: the entry, its field values, its notes and its log lines.
 */
function pn_forms_delete_entry( int $id ): void {
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery -- the plugin's own tables.
	$wpdb->delete( $wpdb->prefix . 'fluentform_submission_meta', array( 'response_id' => $id ) );
	$wpdb->delete( $wpdb->prefix . 'fluentform_entry_details', array( 'submission_id' => $id ) );
	$wpdb->delete(
		$wpdb->prefix . 'fluentform_logs',
		array(
			'source_id'   => $id,
			'source_type' => 'submission_item',
		)
	);
	$wpdb->delete( $wpdb->prefix . 'fluentform_submissions', array( 'id' => $id ) );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery
}

/*
 * ---------------------------------------------------------------------------
 * Prayer requests are emailed to the pastors and not kept
 * ---------------------------------------------------------------------------
 * Fluent Forms emails the pastors while it saves the entry. Straight after that (this runs last), the entry is
 * erased, so the request exists only in the pastors' inboxes. (The plugin's own "delete entries" setting is a
 * paid feature and only changes the entries screen.)
 */
add_action(
	'fluentform/submission_inserted',
	static function ( $entry_id, $form_data, $form ) {
		$prayer = pn_form_ids()['prayer'] ?? 0;
		if ( $prayer && (int) ( $form->id ?? 0 ) === $prayer ) {
			pn_forms_delete_entry( (int) $entry_id );
		}
	},
	999,
	3
);

/*
 * ---------------------------------------------------------------------------
 * Keeping messages: Contact messages are kept 12 months, then deleted (daily)
 * ---------------------------------------------------------------------------
 * The prayer form keeps nothing (it is set to delete each entry once the email has gone to the pastors).
 */
add_action(
	'init',
	static function () {
		if ( ! wp_next_scheduled( 'pn_forms_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'pn_forms_cleanup' );
		}
	}
);

add_action(
	'pn_forms_cleanup',
	static function () {
		global $wpdb;
		$contact = pn_form_ids()['contact'] ?? 0;
		if ( ! $contact ) {
			return;
		}
		$months = max( 1, (int) apply_filters( 'pn_forms_keep_months', 12 ) );
		$before = gmdate( 'Y-m-d H:i:s', time() - $months * 30 * DAY_IN_SECONDS );
		$subs   = $wpdb->prefix . 'fluentform_submissions';
		$old    = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$subs} WHERE form_id = %d AND created_at < %s", $contact, $before ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table, read once a day.
		foreach ( array_map( 'intval', $old ) as $id ) {
			pn_forms_delete_entry( $id );
		}
	}
);
