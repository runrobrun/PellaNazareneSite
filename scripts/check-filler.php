<?php
/**
 * Looks through every table in the database for leftovers listed in deploy/filler.txt. Run it through
 * scripts/check-filler.sh. Prints one line per finding: FOUND|kind|where|id|what|context. Changes nothing.
 *
 * @package pella-nazarene
 */

defined( 'ABSPATH' ) || exit;

global $wpdb;

/** Lower-case and straighten curly quotes so "Men’s Group" matches "men's group". */
$pn_norm = static fn( string $s ): string => strtolower( str_replace( array( '’', '‘', '“', '”' ), array( "'", "'", '"', '"' ), $s ) );

$pn_file = (string) ( $args[0] ?? '' );
if ( '' === $pn_file || ! is_readable( $pn_file ) ) {
	WP_CLI::error( 'Pass the path to deploy/filler.txt.' );
}
$pn_text   = array();
$pn_titles = array();
foreach ( (array) file( $pn_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $pn_line ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file -- a local list in the repo.
	if ( '#' === substr( ltrim( $pn_line ), 0, 1 ) ) {
		continue;
	}
	$pn_parts = array_map( 'trim', explode( '|', $pn_line, 3 ) );
	if ( count( $pn_parts ) < 2 || '' === $pn_parts[1] ) {
		continue;
	}
	if ( 'text' === $pn_parts[0] || 'site' === $pn_parts[0] ) {
		$pn_text[ $pn_norm( $pn_parts[1] ) ] = $pn_parts[1];
	} elseif ( 'title' === $pn_parts[0] ) {
		$pn_titles[ $pn_norm( $pn_parts[1] ) ] = $pn_parts[1];
	}
}
if ( ! $pn_text && ! $pn_titles ) {
	WP_CLI::error( 'The list of leftovers is empty.' );
}

$pn_report = static function ( string $kind, string $where, $id, string $what, string $context ): void {
	echo 'FOUND|', $kind, '|', $where, '|', $id, '|', $what, '|', preg_replace( '/\s+/', ' ', substr( $context, 0, 90 ) ), "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text report for a terminal.
};

// 1. Words anywhere: every text column of every table that belongs to this site.
$pn_like = array();
foreach ( array_keys( $pn_text ) as $pn_needle ) {
	$pn_like[] = '%' . $wpdb->esc_like( $pn_needle ) . '%';
}
$pn_tables = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$pn_scanned = 0;
foreach ( $pn_tables as $pn_table ) {
	$pn_columns = (array) $wpdb->get_results( "SHOW COLUMNS FROM `$pn_table`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	$pn_key     = '';
	foreach ( $pn_columns as $pn_column ) {
		if ( 'PRI' === $pn_column['Key'] ) {
			$pn_key = $pn_column['Field'];
			break;
		}
	}
	foreach ( $pn_columns as $pn_column ) {
		if ( ! preg_match( '/char|text|json/i', (string) $pn_column['Type'] ) ) {
			continue;
		}
		$pn_col = $pn_column['Field'];
		$pn_sql = array();
		foreach ( $pn_like as $pn_pattern ) {
			$pn_sql[] = $wpdb->prepare( "`$pn_col` LIKE %s", $pn_pattern ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$pn_select = '' !== $pn_key ? "`$pn_key` AS id, `$pn_col` AS value" : "'' AS id, `$pn_col` AS value";
		$pn_rows   = (array) $wpdb->get_results( "SELECT $pn_select FROM `$pn_table` WHERE " . implode( ' OR ', $pn_sql ) . ' LIMIT 50', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		++$pn_scanned;
		foreach ( $pn_rows as $pn_row ) {
			$pn_value = $pn_norm( (string) $pn_row['value'] );
			foreach ( $pn_text as $pn_needle => $pn_original ) {
				if ( false === strpos( $pn_value, $pn_needle ) ) {
					continue;
				}
				// Show the words as they are written on the site (capitals and all), with a little around them.
				$pn_found = preg_match( '/' . preg_quote( $pn_original, '/' ) . '/iu', (string) $pn_row['value'], $pn_match, PREG_OFFSET_CAPTURE );
				$pn_text_at = $pn_found ? (int) $pn_match[0][1] : 0;
				$pn_report( 'text', "$pn_table.$pn_col", $pn_row['id'], $pn_found ? (string) $pn_match[0][0] : $pn_original, substr( (string) $pn_row['value'], max( 0, $pn_text_at - 30 ), 90 ) );
			}
		}
	}
}

// 2. Titles: any post, page, sermon, menu item or revision (in any state, including the trash) with exactly this title.
$pn_posts = (array) $wpdb->get_results( "SELECT ID, post_type, post_status, post_title FROM {$wpdb->posts} WHERE post_title <> ''", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
foreach ( $pn_posts as $pn_post ) {
	$pn_title = $pn_norm( trim( html_entity_decode( (string) $pn_post['post_title'] ) ) );
	if ( isset( $pn_titles[ $pn_title ] ) ) {
		$pn_report( 'title', $wpdb->posts . '.post_title', $pn_post['ID'], trim( html_entity_decode( (string) $pn_post['post_title'] ) ), $pn_post['post_type'] . ' (' . $pn_post['post_status'] . ')' );
	}
}

// 3. Terms (categories, series, speakers...): their names must not be a leftover title either.
foreach ( (array) $wpdb->get_results( "SELECT term_id, name FROM {$wpdb->terms}", ARRAY_A ) as $pn_term ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	if ( isset( $pn_titles[ $pn_norm( trim( html_entity_decode( (string) $pn_term['name'] ) ) ) ] ) ) {
		$pn_report( 'title', $wpdb->terms . '.name', $pn_term['term_id'], trim( html_entity_decode( (string) $pn_term['name'] ) ), 'category or tag' );
	}
}
echo 'SCANNED|', (int) $pn_scanned, ' text columns in ', count( $pn_tables ), " tables\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text report.
