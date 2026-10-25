<?php
/**
 * Creates the site's two forms (Contact and Prayer Request) in Fluent Forms from deploy/forms/*.json, and records
 * their IDs so pages can use [pn_form name="contact"]. Run it through scripts/create-forms.sh.
 *
 * Safe to re-run: a form that already exists is left alone, so staff edits are never overwritten. To reset one to
 * the file's current version, name it: create-forms.sh --replace contact
 *
 * Who receives the email comes from the environment (not the repo): PN_CONTACT_TO and PN_PRAYER_TO, each one or more
 * addresses separated by commas. Without them the site's admin email is used.
 *
 * @package pella-nazarene
 */

defined( 'ABSPATH' ) || exit;

global $wpdb;

if ( ! defined( 'FLUENTFORM' ) ) {
	WP_CLI::error( 'Fluent Forms is not active here. Install it first (scripts/install-plugins.sh).' );
}

$pn_dir     = dirname( __DIR__ ) . '/deploy/forms';
$pn_names   = array( 'contact', 'prayer' );
$pn_replace = array_map( 'strval', (array) ( $args ?? array() ) );
foreach ( $pn_replace as $pn_name ) {
	if ( ! in_array( $pn_name, $pn_names, true ) ) {
		WP_CLI::error( "Unknown form '$pn_name'. Forms: " . implode( ', ', $pn_names ) );
	}
}

$pn_forms_table = $wpdb->prefix . 'fluentform_forms';
$pn_meta_table  = $wpdb->prefix . 'fluentform_form_meta';
$pn_now         = current_time( 'mysql' );

/** Addresses from an environment variable, validated; falls back to the admin email. */
$pn_recipients = static function ( string $variable ): string {
	$list = array();
	foreach ( explode( ',', (string) getenv( $variable ) ) as $address ) {
		$address = sanitize_email( trim( $address ) );
		if ( is_email( $address ) ) {
			$list[] = $address;
		}
	}
	if ( ! $list ) {
		WP_CLI::warning( "$variable is not set; using the site admin email." );
		$list[] = (string) get_option( 'admin_email' );
	}
	return implode( ',', array_unique( $list ) );
};
$pn_to = array(
	'contact' => $pn_recipients( 'PN_CONTACT_TO' ),
	'prayer'  => $pn_recipients( 'PN_PRAYER_TO' ),
);

$pn_ids = (array) get_option( 'pn_forms', array() );
foreach ( $pn_names as $pn_name ) {
	$pn_spec = json_decode( (string) file_get_contents( "$pn_dir/$pn_name.json" ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file in the repo.
	if ( ! is_array( $pn_spec ) ) {
		WP_CLI::error( "deploy/forms/$pn_name.json is not valid." );
	}

	$pn_id     = (int) ( $pn_ids[ $pn_name ] ?? 0 );
	$pn_exists = $pn_id && (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$pn_forms_table} WHERE id = %d", $pn_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
	if ( $pn_exists && ! in_array( $pn_name, $pn_replace, true ) ) {
		WP_CLI::log( "Kept the '$pn_name' form (already exists, #$pn_id)." );
		continue;
	}

	$pn_fields = wp_json_encode( $pn_spec['fields'] );
	if ( $pn_exists ) {
		$wpdb->update( $pn_forms_table, array( 'title' => (string) $pn_spec['title'], 'form_fields' => $pn_fields, 'updated_at' => $pn_now ), array( 'id' => $pn_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $pn_meta_table, array( 'form_id' => $pn_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		WP_CLI::log( "Replaced the '$pn_name' form (#$pn_id)." );
	} else {
		$pn_made = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$pn_forms_table,
			array(
				'title'       => (string) $pn_spec['title'],
				'status'      => 'published',
				'form_fields' => $pn_fields,
				'has_payment' => 0,
				'type'        => 'form',
				'created_by'  => get_current_user_id(),
				'created_at'  => $pn_now,
				'updated_at'  => $pn_now,
			)
		);
		if ( false === $pn_made ) {
			WP_CLI::error( "Could not create the '$pn_name' form: " . $wpdb->last_error );
		}
		$pn_id = (int) $wpdb->insert_id;
		WP_CLI::log( "Created the '$pn_name' form (#$pn_id)." );
	}

	$pn_settings = array(
		'confirmation'              => array(
			'redirectTo'            => 'samePage',
			'messageToShow'         => (string) $pn_spec['confirmation'],
			'customPage'            => null,
			'samePageFormBehavior'  => 'hide_form',
			'customUrl'             => null,
		),
		'restrictions'              => array(
			'limitNumberOfEntries'  => array( 'enabled' => false, 'numberOfEntries' => null, 'period' => 'total', 'limitReachedMsg' => 'Maximum number of entries exceeded.' ),
			'scheduleForm'          => array( 'enabled' => false, 'start' => null, 'end' => null, 'selectedDays' => array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' ), 'pendingMsg' => 'Form submission is not started yet.', 'expiredMsg' => 'Form submission is now closed.' ),
			'requireLogin'          => array( 'enabled' => false, 'requireLoginMsg' => 'You must be logged in to submit the form.' ),
			'denyEmptySubmission'   => array( 'enabled' => true, 'message' => 'Please write a message before sending.' ),
		),
		'layout'                    => array( 'labelPlacement' => 'top', 'helpMessagePlacement' => 'with_label', 'errorMessagePlacement' => 'inline', 'cssClassName' => '', 'asteriskPlacement' => 'asterisk-right' ),
		'delete_entry_on_submission' => (string) $pn_spec['delete_entry_on_submission'],
		'appendSurveyResult'        => array( 'enabled' => false, 'showLabel' => false, 'showCount' => false ),
	);
	$pn_notification = array(
		'name'         => (string) $pn_spec['notification']['name'],
		'sendTo'       => array(
			'type'    => 'email',
			'email'   => $pn_to[ $pn_name ],
			'field'   => 'email',
			'routing' => array( array( 'email' => null, 'field' => null, 'operator' => '=', 'value' => null ) ),
		),
		'fromName'     => 'Pella Nazarene website',
		'fromEmail'    => '',
		'replyTo'      => '{inputs.email}',
		'bcc'          => '',
		'subject'      => (string) $pn_spec['notification']['subject'],
		'message'      => '<p>{all_data}</p>',
		'conditionals' => array( 'status' => false, 'type' => 'all', 'conditions' => array( array( 'field' => null, 'operator' => '=', 'value' => null ) ) ),
		'enabled'      => true,
		'email_template' => '',
	);
	foreach ( array(
		'formSettings'         => $pn_settings,
		'notifications'        => $pn_notification,
		'_primary_email_field' => 'email',
	) as $pn_key => $pn_value ) {
		$pn_saved = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$pn_meta_table,
			array(
				'form_id'  => $pn_id,
				'meta_key' => $pn_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- the plugin's own table.
				'value'    => is_array( $pn_value ) ? wp_json_encode( $pn_value ) : $pn_value,
			)
		);
		if ( false === $pn_saved ) {
			WP_CLI::error( "Could not save the '$pn_key' setting of the '$pn_name' form: " . $wpdb->last_error );
		}
	}
	$pn_ids[ $pn_name ] = $pn_id;
}
update_option( 'pn_forms', $pn_ids );

// Fluent Forms' hidden spam trap is off until switched on. Turn it on for every form (the part bots fall for).
$pn_global = (array) get_option( '_fluentform_global_form_settings', array() );
if ( 'yes' !== ( $pn_global['misc']['honeypotStatus'] ?? '' ) ) {
	$pn_global['misc']['honeypotStatus'] = 'yes';
	update_option( '_fluentform_global_form_settings', $pn_global );
	WP_CLI::log( 'Turned on the hidden spam trap.' );
}

// Fluent Forms adds two sample forms when it is first switched on; they are not wanted on this site.
foreach ( (array) $wpdb->get_col( "SELECT id FROM {$pn_forms_table} WHERE title IN ('Contact Form Demo','Subscription Form')" ) as $pn_demo ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	if ( in_array( (int) $pn_demo, array_map( 'intval', $pn_ids ), true ) ) {
		continue;
	}
	$wpdb->delete( $pn_meta_table, array( 'form_id' => (int) $pn_demo ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->delete( $pn_forms_table, array( 'id' => (int) $pn_demo ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	WP_CLI::log( "Removed the sample form #$pn_demo." );
}
WP_CLI::success( 'Forms are in place.' );
