<?php
// Router for `php -S`: a fake Claude Messages API and a fake Pexels search API for deploy/tests/companion-check.sh.
// /tmp/claude-mode: ok (default), garbage, error. /tmp/pexels-mode: ok (default), none. Google: see the end.
// Every request is logged (one JSON line) to /tmp/companion-requests.log so the test can check what was sent.
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$body = (string) file_get_contents( 'php://input' );
file_put_contents(
	'/tmp/companion-requests.log',
	json_encode(
		array(
			'path'  => $path,
			'query' => $_SERVER['QUERY_STRING'] ?? '',
			'key'   => $_SERVER['HTTP_X_API_KEY'] ?? ( $_SERVER['HTTP_AUTHORIZATION'] ?? '' ),
			'body'  => json_decode( $body, true ),
		),
		JSON_UNESCAPED_SLASHES
	) . "\n",
	FILE_APPEND
);
header( 'Content-Type: application/json' );
$mode = static fn( string $f ) => is_file( $f ) ? trim( (string) file_get_contents( $f ) ) : 'ok';

if ( '/v1/messages' === $path ) {
	if ( ( $_SERVER['HTTP_X_API_KEY'] ?? '' ) !== 'sk-test-CLAUDEKEY-0000' ) {
		http_response_code( 401 );
		echo '{"type":"error","error":{"type":"authentication_error","message":"invalid x-api-key"}}';
		exit;
	}
	if ( 'error' === $mode( '/tmp/claude-mode' ) ) {
		http_response_code( 529 );
		echo '{"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}';
		exit;
	}
	if ( 'garbage' === $mode( '/tmp/claude-mode' ) ) {
		echo json_encode( array( 'content' => array( array( 'type' => 'text', 'text' => 'Sorry, here is a poem instead.' ) ), 'stop_reason' => 'end_turn' ) );
		exit;
	}
	$post = array(
		'title'        => 'What Kind of King Is Jesus?',
		'excerpt'      => 'A king who uses his power to come looking for us.',
		'body_html'    => '<p>Most of us do not think about kings much.</p><p>Hope is an <strong>anchor</strong> for the soul.</p><p>Nothing else fills us.</p><p>Bad bits <script>alert(1)</script><img src=x onerror=alert(2)> are removed.</p><p><strong>What would it look like to let Jesus be King?</strong></p>',
		'image_query'  => 'anchor harbor morning',
		'pastor_check' => array( 'Hebrews 6:19 (captions said Ephesians 6)', 'Pope Pius XI' ),
	);
	echo json_encode(
		array(
			'content'     => array( array( 'type' => 'text', 'text' => "```json\n" . json_encode( $post ) . "\n```" ) ),
			'stop_reason' => 'end_turn',
		)
	);
	exit;
}

if ( '/v1/search' === $path ) {
	if ( ( $_SERVER['HTTP_AUTHORIZATION'] ?? '' ) !== 'PEXELSTESTKEY0000' ) {
		http_response_code( 401 );
		echo '{"error":"bad key"}';
		exit;
	}
	if ( 'none' === $mode( '/tmp/pexels-mode' ) ) {
		echo '{"photos":[],"total_results":0}';
		exit;
	}
	$photo = static fn( int $id, int $w, int $h, string $src ) => array(
		'id'           => $id,
		'width'        => $w,
		'height'       => $h,
		'url'          => "https://www.pexels.com/photo/harbor-$id/",
		'photographer' => 'Jane Doe',
		'alt'          => 'Boats at anchor in a harbor at sunrise',
		'src'          => array( 'large2x' => $src ),
	);
	echo json_encode(
		array(
			'photos' => array(
				$photo( 501, 800, 533, 'https://images.pexels.com/photos/501/small.jpeg' ),           // too small
				$photo( 502, 4000, 6000, 'https://images.pexels.com/photos/502/tall.jpeg' ),          // portrait
				$photo( 503, 4000, 2667, 'http://images.pexels.com/photos/503/plain-http.jpeg' ),     // not https
				$photo( 504, 4000, 2667, 'https://evil.example/photos/504/elsewhere.jpeg' ),          // other host
				$photo( 777, 4000, 2667, 'https://images.pexels.com/photos/777/pexels-photo-777.jpeg' ),
				$photo( 778, 4000, 2667, 'https://images.pexels.com/photos/778/pexels-photo-778.jpeg' ),
			),
		)
	);
	exit;
}

// A fake Google: the OAuth token endpoint and YouTube's captions API (FEAT-10). /tmp/gapi-mode: ok, revoked, none, forbidden, quota, malformed.
if ( '/token' === $path ) {
	parse_str( $body, $f );
	$good = 'refresh_token' === ( $f['grant_type'] ?? '' ) && 'test-client-id' === ( $f['client_id'] ?? '' )
		&& 'test-client-secret' === ( $f['client_secret'] ?? '' ) && 'test-refresh-token' === ( $f['refresh_token'] ?? '' );
	if ( ! $good || 'revoked' === $mode( '/tmp/gapi-mode' ) ) {
		http_response_code( 400 );
		echo '{"error":"invalid_grant","error_description":"Token has been expired or revoked."}';
		exit;
	}
	echo '{"access_token":"test-access-token","expires_in":3599,"token_type":"Bearer"}';
	exit;
}
if ( str_starts_with( $path, '/youtube/v3/captions' ) ) {
	if ( ( $_SERVER['HTTP_AUTHORIZATION'] ?? '' ) !== 'Bearer test-access-token' ) {
		http_response_code( 401 );
		echo '{"error":{"errors":[{"reason":"authError"}]}}';
		exit;
	}
	if ( '/youtube/v3/captions' === $path ) {
		if ( 'none' === $mode( '/tmp/gapi-mode' ) ) {
			echo '{"items":[]}';
			exit;
		}
		if ( 'malformed' === $mode( '/tmp/gapi-mode' ) ) {
			echo '<html>Something went wrong</html>';
			exit;
		}
		$track = static fn( string $id, string $lang, string $kind, string $status ) => array(
			'id'      => $id,
			'snippet' => array( 'language' => $lang, 'trackKind' => $kind, 'status' => $status ),
		);
		echo json_encode( array( 'items' => array( $track( 'trk-es', 'es', 'standard', 'serving' ), $track( 'trk-failed', 'en', 'standard', 'failed' ), $track( 'trk-asr', 'en', 'ASR', 'serving' ) ) ) );
		exit;
	}
	if ( '/youtube/v3/captions/trk-asr' === $path ) {
		if ( 'forbidden' === $mode( '/tmp/gapi-mode' ) ) {
			http_response_code( 403 );
			echo '{"error":{"errors":[{"reason":"forbidden"}]}}';
			exit;
		}
		if ( 'quota' === $mode( '/tmp/gapi-mode' ) ) {
			http_response_code( 403 );
			echo '{"error":{"errors":[{"reason":"quotaExceeded"}]}}';
			exit;
		}
		header( 'Content-Type: text/vtt' );
		$vtt = "WEBVTT\nKind: captions\nLanguage: en\n\n";
		for ( $i = 0; $i < 30; $i++ ) {
			$vtt .= sprintf( "%d\n00:00:%02d.000 --> 00:00:%02d.500 align:start position:0%%\n[Music]\nWe have this <c>hope</c> as an anchor &amp; it holds, from the official API %d.\n\n", $i + 1, $i, $i, $i );
		}
		echo $vtt;
		exit;
	}
}

http_response_code( 404 );
echo '{"error":"not found"}';
