<?php
/**
 * Reads the palette from theme.json and checks the contrast of every text/background pairing the
 * theme actually uses against WCAG 2.2 AA (4.5:1 for text, 3:1 for interface elements and focus rings).
 * If someone changes a brand color to something unreadable, this fails.
 *
 * Usage: php brand-contrast.php path/to/theme.json
 */

$theme = json_decode( (string) file_get_contents( $argv[1] ?? '' ), true );
if ( ! is_array( $theme ) ) {
	fwrite( STDERR, "Cannot read theme.json\n" );
	exit( 2 );
}

$palette = array();
foreach ( $theme['settings']['color']['palette'] as $entry ) {
	if ( 0 === strpos( $entry['color'], '#' ) ) {
		$palette[ $entry['slug'] ] = $entry['color'];
	}
}

function pn_lin( $c ) {
	$c /= 255;
	return $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
}
function pn_lum( $hex ) {
	$hex = ltrim( $hex, '#' );
	return 0.2126 * pn_lin( hexdec( substr( $hex, 0, 2 ) ) ) + 0.7152 * pn_lin( hexdec( substr( $hex, 2, 2 ) ) ) + 0.0722 * pn_lin( hexdec( substr( $hex, 4, 2 ) ) );
}
function pn_ratio( $a, $b ) {
	$la = pn_lum( $a );
	$lb = pn_lum( $b );
	return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
}

// [ foreground slug, background slug, minimum ratio, what it is ]
$pairs = array(
	array( 'contrast', 'base', 4.5, 'body text on white' ),
	array( 'accent-3', 'base', 4.5, 'headings and links on white' ),
	array( 'primary-dark', 'base', 4.5, 'link hover on white' ),
	array( 'base', 'accent-3', 4.5, 'button text on navy' ),
	array( 'base', 'primary-dark', 4.5, 'button hover text on dark navy' ),
	array( 'accent-4', 'base', 4.5, 'muted text on white' ),
	array( 'accent-4', 'accent-5', 4.5, 'muted text on the lightest tint' ),
	array( 'accent-4', 'accent-2', 4.5, 'muted text on the 8% tint' ),
	array( 'contrast', 'accent-1', 4.5, 'black text on the 20% tint (parent patterns)' ),
	array( 'contrast', 'accent-2', 4.5, 'black text on the 8% tint' ),
	array( 'contrast', 'accent-5', 4.5, 'black text on the lightest tint' ),
	array( 'accent-3', 'accent-2', 4.5, 'navy text on the 8% tint' ),
	array( 'accent-3', 'accent-1', 4.5, 'navy text on the 20% tint' ),
	array( 'base', 'accent-3', 4.5, 'white text on navy (parent patterns)' ),
	array( 'accent-3', 'base', 3.0, 'focus ring and button edge against the page' ),
);

$failed = 0;
foreach ( $pairs as list( $fg, $bg, $need, $label ) ) {
	if ( ! isset( $palette[ $fg ], $palette[ $bg ] ) ) {
		printf( "  FAIL  palette is missing '%s' or '%s' (%s)\n", $fg, $bg, $label );
		++$failed;
		continue;
	}
	$ratio = pn_ratio( $palette[ $fg ], $palette[ $bg ] );
	printf( "  %s  %5.2f:1 (need %.1f)  %s\n", $ratio >= $need ? 'ok  ' : 'FAIL', $ratio, $need, $label );
	if ( $ratio < $need ) {
		++$failed;
	}
}
echo $failed ? "\n$failed pairing(s) fail WCAG AA.\n" : "\nAll pairings meet WCAG AA.\n";
exit( $failed ? 1 : 0 );
