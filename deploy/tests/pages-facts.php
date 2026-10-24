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
	'/events/'    => array( 'Events', 'Events', array( 'Sunday School', 'Connections Sunday School Class', 'Lighthouse Student Ministry', 'Midweek Prayer Meeting', 'Coffee Time', 'Windmill Cafe', '9:30–10:15 AM', '5:30–7:00 PM', '10:30–11:30 AM', '7:00–8:00 PM', 'Central', 'See the full calendar' ) ),
	'/small-groups/' => array( 'Small Groups', 'Small Groups', array( 'Find a group', 'Connect card', '9:30' ) ),
	'/blog/'      => array( 'Blog', 'Blog', array( 'News and encouragement', 'Welcome to our new website' ) ),
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
	// Photos: described, sized, responsive, and only the first one on the home page loads eagerly.
	$imgs = $x->query( "//main//img[not(ancestor::*[contains(@class, 'pn-video')])]" );
	$i    = 0;
	foreach ( $imgs as $img ) {
		$src  = $img->getAttribute( 'src' );
		$name = basename( parse_url( $src, PHP_URL_PATH ) );
		check( "$path photo $name has alt text", '' !== trim( $img->getAttribute( 'alt' ) ), 'alt is empty' );
		check( "$path photo $name has width and height", ctype_digit( $img->getAttribute( 'width' ) ) && ctype_digit( $img->getAttribute( 'height' ) ), 'no explicit size' );
		check( "$path photo $name has srcset and sizes", '' !== $img->getAttribute( 'srcset' ) && '' !== $img->getAttribute( 'sizes' ), 'not responsive' );
		check( "$path photo $name is from our own site", str_starts_with( $src, $base . '/' ) || str_starts_with( $src, '/' ), $src );
		// A photo drawn in half a column must not be offered at full-page size: sizes follows the layout.
		$want = array( 'pn-photo--hero' => '100vw', 'pn-photo--col' => '(max-width: 781px) 100vw, 340px', 'pn-photo--avatar' => '(max-width: 781px) 100vw, 216px' );
		foreach ( $want as $cls => $sizes ) {
			if ( false !== strpos( (string) $img->parentNode->getAttribute( 'class' ), $cls ) ) {
				check( "$path photo $name sizes match its layout ($cls)", $sizes === preg_replace( '/^auto,\s*/', '', $img->getAttribute( 'sizes' ) ), "sizes='" . $img->getAttribute( 'sizes' ) . "'" );
			}
		}
		$eager = '/' === $path && 0 === $i;
		check( "$path photo $name " . ( $eager ? 'is not lazy (it is the hero)' : 'is lazy-loaded' ), $eager ? 'lazy' !== $img->getAttribute( 'loading' ) : 'lazy' === $img->getAttribute( 'loading' ), 'loading=' . $img->getAttribute( 'loading' ) );
		$i++;
	}
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
$planned = array(); // addresses that are linked before their page exists (none: every menu and page link resolves)
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
// Events and small groups: the live calendar, sign-ups, and groups are all in Church Center; no filler from the old site.
$events_links = array_column( $all_links['/events/'] ?? array(), 0 );
check( 'Events links to the Church Center calendar', in_array( 'https://pella-nazarene.churchcenter.com/calendar', $events_links, true ), 'no calendar link' );
check( 'Events links to Church Center sign-ups', in_array( 'https://pella-nazarene.churchcenter.com/registrations/events', $events_links, true ), 'no sign-up link' );
check( 'Events has a real directions link for Coffee Time', (bool) array_filter( $events_links, fn( $h ) => str_contains( $h, 'google.com/maps' ) && str_contains( $h, 'Windmill' ) ), 'no map link' );
$events_text = main_text( dom( $html_of['/events/'] ) );
foreach ( array( 'Kids Event', 'Family Event', 'Youth Event', 'Worship Night', 'Baptism Sunday', 'Movie Night', 'Wedensday' ) as $filler ) {
	check( "Events has no old filler '$filler'", false === stripos( $events_text, $filler ), 'filler event found' );
}
check( 'Events lists Wednesday prayer (spelled correctly)', false !== strpos( $events_text, 'Wednesday, 7:00–8:00 PM' ), 'Wednesday time missing' );
check( 'Small Groups sends people to Church Center Groups', in_array( 'https://pella-nazarene.churchcenter.com/groups', array_column( $all_links['/small-groups/'] ?? array(), 0 ), true ), 'no groups link' );
check( 'Small Groups has no old demo group names', false === stripos( main_text( dom( $html_of['/small-groups/'] ) ), 'Brunson' ), 'demo names present' );
// Giving: every give button goes to Church Center.
$give = array_filter( array_column( $all_links['/giving/'] ?? array(), 0 ), fn( $h ) => str_contains( $h, 'churchcenter' ) );
check( 'Giving has 3 give buttons', 3 === count( $give ), count( $give ) . ' found' );
foreach ( $give as $h ) {
	check( 'Giving button goes to Church Center giving', 'https://pella-nazarene.churchcenter.com/giving' === $h, $h );
}
// Contact: office hours come from the one shared source and say Central.
$contact = main_text( dom( $html_of['/contact/'] ) );
check( 'Contact hours are Mon/Wed 1-4 and Fri 9-12', (bool) preg_match( '/Monday 1.{1,3}4\s*PM, Wednesday 1.{1,3}4\s*PM, Friday 9\s*AM.{1,3}12\s*PM Central/u', $contact ), 'hours text not found' );
// Photos in total, the hero preload, and that the files really are served as WebP.
$expected_photos = array( '/' => 1, '/new-here/' => 2, '/about-us/' => 6, '/kids/' => 3, '/giving/' => 0, '/contact/' => 0, '/resources/' => 0, '/events/' => 0, '/small-groups/' => 0, '/blog/' => 0 );
foreach ( $expected_photos as $path => $n ) {
	$found = dom( $html_of[ $path ] )->query( "//main//img[not(ancestor::*[contains(@class, 'pn-video')])]" )->length;
	check( "$path shows $n photo(s)", $n === $found, "$found found" );
}
check( 'Home preloads the hero photo with its srcset', (bool) preg_match( '#<link rel="preload" as="image" href="[^"]+" imagesrcset="[^"]+" imagesizes="[^"]+" fetchpriority="high">#', $html_of['/'] ), 'no hero preload' );
foreach ( array( '/new-here/', '/about-us/', '/kids/' ) as $path ) {
	check( "$path does not preload an image", false === strpos( $html_of[ $path ], 'rel="preload" as="image"' ), 'preload on a page without the hero' );
}
$hero_img = dom( $html_of['/'] )->query( "//main//img[not(ancestor::*[contains(@class, 'pn-video')])]" )->item( 0 );
$hero_src = (string) $hero_img?->getAttribute( 'src' );
$hero_preload = dom( $html_of['/'] )->query( '//head/link[@rel="preload"][@as="image"]' )->item( 0 );
check( 'Hero is fetched first (fetchpriority high)', 'high' === $hero_img?->getAttribute( 'fetchpriority' ), 'fetchpriority=' . $hero_img?->getAttribute( 'fetchpriority' ) );
$ctx      = stream_context_create( array( 'http' => array( 'ignore_errors' => true, 'timeout' => 30 ) ) );
$bytes    = (string) @file_get_contents( $hero_src, false, $ctx );
$ctype    = '';
foreach ( (array) ( $http_response_header ?? array() ) as $line ) {
	if ( 0 === stripos( $line, 'content-type:' ) ) {
		$ctype = trim( substr( $line, 13 ) );
	}
}
check( 'Hero photo is served as WebP', 'image/webp' === $ctype && 'RIFF' === substr( $bytes, 0, 4 ) && 'WEBP' === substr( $bytes, 8, 4 ), "type '$ctype'" );
check( 'Hero photo is under 250 KB', strlen( $bytes ) > 0 && strlen( $bytes ) < 250 * 1024, strlen( $bytes ) . ' bytes' );
$srcset = (string) $hero_img?->getAttribute( 'srcset' );
check( 'Hero preload matches the rendered hero photo', $hero_preload && $hero_src === $hero_preload->getAttribute( 'href' ) && $srcset === $hero_preload->getAttribute( 'imagesrcset' ), 'preload does not match the hero image' );
check( 'Hero offers several sizes', substr_count( $srcset, ' ' ) >= 5, $srcset );
preg_match( '/ (\d+)w/', $srcset, $first_w );
check( 'Hero has a small size for phones (600px or less)', (bool) preg_match( '/ ([1-5]\d\d|600)w/', $srcset ), 'smallest sizes missing' );

// The blog: published posts only, a readable post page, an RSS feed, and a menu link on every page.
$blog_x = dom( $html_of['/blog/'] );
check( 'Blog index does not show the draft post', false === strpos( $html_of['/blog/'], 'A draft post' ), 'draft leaked onto the blog' );
$post_link = (string) $blog_x->query( "//main//h2//a[contains(., 'Welcome to our new website')]" )->item( 0 )?->getAttribute( 'href' );
check( 'Blog index links to the post', '' !== $post_link, 'no link to the post' );
list( $pc, $post_html ) = fetch( $post_link );
$post_x = dom( $post_html );
check( 'Blog post answers 200', 200 === $pc, "got $pc" );
check( 'Blog post has one h1: its title', 1 === $post_x->query( '//h1' )->length && 'Welcome to our new website' === trim( (string) $post_x->query( '//h1' )->item( 0 )?->textContent ), 'title is not the h1' );
check( 'Blog post shows its text, a date, and a way back', false !== strpos( $post_html, 'We are glad you are here.' ) && $post_x->query( '//main//time' )->length >= 1 && false !== strpos( $post_html, 'All posts' ), 'post page is incomplete' );
check( 'Blog post has the site header and footer', has_class( $post_x, 'pn-header' ) && has_class( $post_x, 'pn-footer' ), 'header or footer missing' );
check( 'Blog post has comments off', false === strpos( $post_html, 'comment-form' ), 'comment form shown' );
list( $fc, $feed ) = fetch( $base . '/feed/' );
check( 'RSS feed answers 200', 200 === $fc, "got $fc" );
check( 'RSS feed lists the published post and not the draft', false !== strpos( $feed, 'Welcome to our new website' ) && false === strpos( $feed, 'A draft post' ), 'feed content wrong' );
check( 'RSS feed is advertised in the page head', (bool) preg_match( '#<link rel="alternate" type="application/rss\+xml"#', $html_of['/blog/'] ), 'no feed link' );
foreach ( array_keys( $pages ) as $path ) {
	check( "$path has a Blog link in the menu", dom( $html_of[ $path ] )->query( "//header//a[contains(@href, '/blog/')]" )->length >= 1, 'no Blog in the header' );
}

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
$home_x = dom( $html_of['/'] );
check( 'Home headline is the one h1 and sits on the hero photo', 1 === $home_x->query( '//h1' )->length && 1 === $home_x->query( "//*[contains(@class, 'pn-hero')]//h1[contains(@class, 'pn-hero__title')]" )->length, 'h1 is not inside the hero' );
check( 'Home headline is drawn (not hidden)', 0 === $home_x->query( "//h1[contains(@class, 'hidden')]" )->length, 'h1 hidden' );
check( 'Home photo runs edge to edge (alignfull)', $home_x->query( "//main//figure[contains(@class, 'alignfull')][contains(@class, 'pn-photo--hero')]" )->length === 1, 'hero is not full width' );
check( 'Nothing sits between the header and the hero', (bool) preg_match( '#</header>\s*<main[^>]*style="[^"]*margin-top:0;padding-top:0#', $html_of['/'] ), 'gap above the hero' );
check( 'Home has its staff-written hero only once', 1 === substr_count( $home, 'A church in Pella, Iowa' ), 'hero repeated' );
