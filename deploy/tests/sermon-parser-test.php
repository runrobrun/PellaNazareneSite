<?php
/**
 * Tests Sermon_Parser against real YouTube titles and descriptions from the church's channel plus edge cases.
 * Plain PHP, no WordPress needed. Run from the repo root:
 *     docker run --rm -v "$PWD":/repo:ro php:8.3-cli php /repo/deploy/tests/sermon-parser-test.php
 */

define( 'ABSPATH', '/' );
$root = dirname( __DIR__, 2 );
require $root . '/wp-content/plugins/pn-sermons/includes/class-sermon-parser.php';

use PN_Sermons\Sermon_Parser;

$rules   = json_decode( (string) file_get_contents( $root . '/wp-content/plugins/pn-sermons/data/sermon-rules.json' ), true );
$samples = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/sermon-samples.json' ), true );
$parser  = new Sermon_Parser( $rules );

$passed = 0;
$failed = 0;
$report = static function ( bool $ok, string $label, string $detail = '' ) use ( &$passed, &$failed ) {
	if ( $ok ) {
		++$passed;
		echo "  ok    $label\n";
	} else {
		++$failed;
		echo "  FAIL  $label" . ( $detail ? "  ($detail)" : '' ) . "\n";
	}
};

echo "== Parsing real and edge-case descriptions\n";
foreach ( $samples['samples'] as $sample ) {
	$got = $parser->parse( $sample['video'] );
	foreach ( $sample['expect'] as $field => $want ) {
		if ( 'notes_contain' === $field ) {
			$report( false !== stripos( implode( ' ', $got['notes'] ), $want ), "{$sample['name']}: notes mention '$want'", implode( ' | ', $got['notes'] ) );
		} elseif ( 'title_has_no_tags' === $field ) {
			$report( $got['title'] === wp_strip_tags_like( $got['title'] ), "{$sample['name']}: title has no tags", $got['title'] );
		} else {
			$report( $got[ $field ] === $want, "{$sample['name']}: $field", 'got ' . json_encode( $got[ $field ], JSON_UNESCAPED_UNICODE ) . ', wanted ' . json_encode( $want, JSON_UNESCAPED_UNICODE ) );
		}
	}
}

echo "== Scripture references on their own\n";
foreach ( $samples['scripture_only'] as $case ) {
	$got = array_column( Sermon_Parser::scripture_refs( $case['text'], true ), 'text' );
	$report( $got === $case['refs'], "'{$case['text']}'", 'got ' . json_encode( $got, JSON_UNESCAPED_UNICODE ) . ', wanted ' . json_encode( $case['refs'], JSON_UNESCAPED_UNICODE ) );
}

echo "== Every book of the Bible is recognised under its own name\n";
$missing = array();
foreach ( array_keys( Sermon_Parser::BOOKS ) as $book ) {
	$refs = Sermon_Parser::scripture_refs( "$book 1:1", true );
	if ( ! $refs || $refs[0]['book'] !== $book ) {
		$missing[] = $book;
	}
}
$report( 66 === count( Sermon_Parser::BOOKS ), '66 books defined (' . count( Sermon_Parser::BOOKS ) . ')' );
$report( ! $missing, 'each one parses back to itself', implode( ', ', $missing ) );

function wp_strip_tags_like( string $s ): string {
	return strip_tags( $s );
}

echo "\nPassed: $passed   Failed: $failed\n";
exit( $failed ? 1 : 0 );
