<?php
// Reads the seven migrated pages over HTTP and prints one line per check: "ok|name" or "FAIL|name|detail".
// Run by pages-check-inner.sh inside the test container: php pages-facts.php BASE
$base = rtrim( $argv[1] ?? 'http://127.0.0.1:8080', '/' );
$out  = array();
function check( string $name, bool $pass, string $detail = '' ): void {
	echo ( $pass ? 'ok|' . $name : 'FAIL|' . $name . '|' . $detail ), "\n";
}
function fetch( string $url ): array {
	$ctx  = stream_context_create( array( 'http' => array( 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 30 ) ) );
	$body = (string) @file_get_contents( $url, false, $ctx );
	$code = 0;
	foreach ( (array) ( $http_response_header ?? array() ) as $line ) {
		if ( preg_match( '#^HTTP/\S+ (\d{3})#', $line, $m ) ) {
			$code = (int) $m[1];
		}
	}
	return array( $code, $body );
}
function dom( string $html ): DOMXPath {
	$d = new DOMDocument();
	libxml_use_internal_errors( true );
	$d->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
	libxml_clear_errors();
	return new DOMXPath( $d );
}
// True if an element in the page body has this class (the same words in the page's CSS do not count).
function has_class( DOMXPath $x, string $class ): bool {
	return $x->query( "//body//*[contains(concat(' ', normalize-space(@class), ' '), ' $class ')]" )->length > 0;
}
function main_text( DOMXPath $x ): string {
	$n = $x->query( '//main' )->item( 0 );
	return $n ? preg_replace( '/\s+/u', ' ', $n->textContent ) : '';
}

// The brand colors, straight from theme.json: nothing else may appear in page content.
$theme_json = json_decode( (string) file_get_contents( '/repo/wp-content/themes/pella-nazarene/theme.json' ), true );
$brand      = array();
foreach ( $theme_json['settings']['color']['palette'] ?? array() as $swatch ) {
	if ( str_starts_with( (string) $swatch['color'], '#' ) ) {
		$brand[] = strtolower( $swatch['color'] );
	}
}
check( 'brand palette was read from theme.json', count( $brand ) >= 5, count( $brand ) . ' colors' );

$pages = array(
	'/'           => array( 'Home', 'A church in Pella, Iowa', array( 'Plan a Visit', 'Watch Online', 'Todd &amp; Cindy Olson' ) ),
	'/new-here/'  => array( 'New Here', 'New Here', array( 'Plan your visit', 'Where do I park?', 'Prairie Street', 'Connect card' ) ),
	'/about-us/'  => array( 'About Us', 'About Us', array( 'Honest Fellowship', 'Whitlatch', 'Vermeer', 'Floyd Ware', 'Statement of beliefs' ) ),
	'/kids/'      => array( 'Kids', 'Kids', array( 'birth through age 12', 'background checks' ) ),
	'/giving/'    => array( 'Giving', 'Giving', array( 'Colossians 3:23-24', 'Building fund', 'World missions' ) ),
	'/contact/'   => array( 'Contact', 'Contact', array( '(641) 628-1818', 'pellanazarene@gmail.com', 'Monday 1', 'Central' ) ),
	'/resources/' => array( 'Resources', 'Resources', array( 'Nazarene Discipleship International', 'The Preaching Collaborative' ) ),
);
$bad_words = array( 'lorem ipsum', 'omega church', 'brunson', 'will & gloria', 'gloria jackson', 'eastern', '8:30', '8:00 -', 'onine', 'activites', 'link to be added', 'wedensday', 'text-to-give', 'text to give' );

$all_links = array();
$html_of   = array();
foreach ( $pages as $path => list( $title, $h1, $musts ) ) {
	list( $code, $html ) = fetch( $base . $path );
	$html_of[ $path ]    = $html;
	check( "$path answers 200", 200 === $code, "got $code" );
	$x    = dom( $html );
	$text = main_text( $x );
	check( "$path has the site header", has_class( $x, 'pn-header' ), 'no header on the page' );
	check( "$path has the site footer", has_class( $x, 'pn-footer' ), 'no footer on the page' );
	check( "$path has exactly one h1", 1 === $x->query( '//h1' )->length, $x->query( '//h1' )->length . ' found' );
	$h1t = trim( (string) $x->query( '//h1' )->item( 0 )?->textContent );
	check( "$path h1 reads '$h1'", $h1 === $h1t, "got '$h1t'" );
	foreach ( $musts as $must ) {
		check( "$path contains '$must'", false !== stripos( html_entity_decode( $text ), html_entity_decode( $must ) ) || false !== stripos( $html, $must ), 'missing' );
	}
	foreach ( $bad_words as $w ) {
		check( "$path has no '$w'", false === stripos( $text, $w ), 'found in the page text' );
	}
	check( "$path has no heading level skipped", ( function () use ( $x ) {
		$prev = 1;
		foreach ( $x->query( '//main//h1|//main//h2|//main//h3|//main//h4' ) as $hn ) {
			$lvl = (int) substr( $hn->nodeName, 1 );
			if ( $lvl > $prev + 1 ) {
				return false;
			}
			$prev = $lvl;
		}
		return true;
	} )(), 'a heading jumps more than one level' );
	check( "$path has no autoloading iframe", 0 === $x->query( '//main//iframe' )->length, 'iframe in page' );
	$main_html = $x->query( '//main' )->item( 0 ) ? $x->document->saveHTML( $x->query( '//main' )->item( 0 ) ) : '';
	preg_match_all( '/#[0-9a-f]{3,8}\b/i', $main_html, $hex );
	$off_brand = array_values( array_diff( array_map( 'strtolower', $hex[0] ), $brand ) );
	check( "$path uses only brand colors", '' !== $main_html && ! $off_brand && ! preg_match( '/rgba?\(|hsla?\(/i', $main_html ), 'colors not in theme.json: ' . implode( ' ', $off_brand ) );
	foreach ( $x->query( '//main//a[@href]' ) as $a ) {
		$all_links[ $path ][] = array( $a->getAttribute( 'href' ), trim( preg_replace( '/\s+/u', ' ', $a->textContent ) ) );
	}
}

// Links: none empty or "#", every internal link resolves, every in-page anchor exists, phone and email are real.
$planned = array( '/events/', '/small-groups/' ); // pages other stories build (MIG-7); the menu already points here
foreach ( $all_links as $path => $links ) {
	foreach ( $links as list( $href, $label ) ) {
		check( "$path link '$label' has a real target", '' !== $href && '#' !== $href, "href='$href'" );
		if ( str_starts_with( $href, 'tel:' ) ) {
			check( "$path phone link is +1 international form", 'tel:+16416281818' === $href, $href );
		} elseif ( str_starts_with( $href, 'mailto:' ) ) {
			check( "$path mailto has an address", (bool) preg_match( '/^mailto:[^@\s]+@[^@\s]+\.[a-z]+$/i', $href ), $href );
		} elseif ( str_starts_with( $href, '/' ) || str_starts_with( $href, $base ) ) {
			$rel = str_starts_with( $href, $base ) ? substr( $href, strlen( $base ) ) : $href;
			list( $p, $frag ) = array_pad( explode( '#', $rel, 2 ), 2, '' );
			if ( in_array( $p, $planned, true ) ) {
				continue;
			}
			list( $c, $body ) = fetch( $base . $p );
			check( "$path link $p resolves", 200 === $c, "got $c" );
			if ( '' !== $frag ) {
				check( "$path link $rel: anchor exists", false !== strpos( $body, 'id="' . $frag . '"' ), 'no such id' );
			}
		} elseif ( str_starts_with( $href, '#' ) ) {
			check( "$path anchor $href exists on the page", false !== strpos( $html_of[ $path ], 'id="' . substr( $href, 1 ) . '"' ), 'no such id' );
		} else {
			check( "$path external link $href is https", str_starts_with( $href, 'https://' ), $href );
		}
	}
	$labels = array_column( $links, 1 );
	$hrefs  = array_column( $links, 0 );
	$dupe   = false;
	foreach ( array_count_values( $labels ) as $label => $n ) {
		$targets = array();
		foreach ( $links as list( $h, $l ) ) {
			if ( $l === $label ) {
				$targets[ $h ] = true;
			}
		}
		if ( $n > 1 && count( $targets ) > 1 ) {
			$dupe = $label;
		}
	}
	check( "$path: no two different links share the same words", false === $dupe, "'$dupe' goes to different places" );
}

// Resources: all eleven old links, still on the Nazarene sites.
$res = array_column( $all_links['/resources/'] ?? array(), 0 );
$ext = array_values( array_filter( $res, fn( $h ) => str_starts_with( $h, 'https://' ) ) );
check( 'Resources keeps all 11 outside links', 11 === count( $ext ), count( $ext ) . ' found' );
foreach ( $ext as $h ) {
	check( "Resources link $h is on a Nazarene site", (bool) preg_match( '#^https://(nazarene\.org|ncm\.org|usacanadaregion\.org)/#', $h ), $h );
}
// Giving: every give button goes to Church Center.
$give = array_filter( array_column( $all_links['/giving/'] ?? array(), 0 ), fn( $h ) => str_contains( $h, 'churchcenter' ) );
check( 'Giving has 3 give buttons', 3 === count( $give ), count( $give ) . ' found' );
foreach ( $give as $h ) {
	check( 'Giving button goes to Church Center giving', 'https://pella-nazarene.churchcenter.com/giving' === $h, $h );
}
// Contact: office hours come from the one shared source and say Central.
$contact = main_text( dom( $html_of['/contact/'] ) );
check( 'Contact hours are Mon/Wed 1-4 and Fri 9-12', (bool) preg_match( '/Monday 1.{1,3}4\s*PM, Wednesday 1.{1,3}4\s*PM, Friday 9\s*AM.{1,3}12\s*PM Central/u', $contact ), 'hours text not found' );
// The sermon pages use our own templates; they must carry the header and footer too.
foreach ( array( '/sermons/', '/sermons/latest-published-sermon/' ) as $sp ) {
	list( $c, $b ) = fetch( $base . $sp );
	check( "$sp answers 200", 200 === $c, "got $c" );
	$sx = dom( $b );
	check( "$sp has the site header and footer", has_class( $sx, 'pn-header' ) && has_class( $sx, 'pn-footer' ), 'header or footer missing' );
}
// Home: the latest published sermon is shown through the sermon plugin; a draft never is.
$home = $html_of['/'];
check( 'Home shows the latest published sermon', false !== strpos( $home, 'Latest published sermon' ), 'sermon title not on home' );
check( 'Home does not show the draft sermon', false === strpos( $home, 'A draft sermon' ), 'draft leaked onto home' );
check( 'Home sermon video is a click-to-load facade', false !== strpos( $home, 'class="pn-video"' ) && false === stripos( $home, '<iframe' ), 'no facade or iframe present' );
check( 'Home has its staff-written hero only once', 1 === substr_count( $home, 'A church in Pella, Iowa' ), 'hero repeated' );
