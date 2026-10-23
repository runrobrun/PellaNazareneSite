<?php
// Router for `php -S`: a fake YouTube Data API v3 for deploy/tests/sermon-import-check.sh.
// /tmp/yt-mode picks a failure: quota, badjson, or empty for normal. Requests are logged to /tmp/yt-requests.log.
$mode = is_file( '/tmp/yt-mode' ) ? trim( (string) file_get_contents( '/tmp/yt-mode' ) ) : '';
file_put_contents( '/tmp/yt-requests.log', $_SERVER['REQUEST_URI'] . "\n", FILE_APPEND );
header( 'Content-Type: application/json' );

function fail( int $code, string $reason ): void {
	http_response_code( $code );
	echo json_encode( array( 'error' => array( 'code' => $code, 'errors' => array( array( 'reason' => $reason ) ) ) ) );
	exit;
}
if ( 'quota' === $mode ) {
	fail( 403, 'quotaExceeded' );
}
if ( 'badjson' === $mode ) {
	echo '<html>oops</html>';
	exit;
}
if ( ( $_GET['key'] ?? '' ) !== 'TESTKEYTESTKEYTESTKEY1234' ) {
	fail( 400, 'keyInvalid' );
}

// Newest first, like YouTube. 112 ordinary Sunday services, a week apart, ending 2026-10-18.
$videos = array();
$sunday = new DateTimeImmutable( '2026-10-18' );
for ( $i = 0; $i < 112; $i++ ) {
	$d        = $sunday->modify( "-$i weeks" );
	$videos[] = array(
		'id'    => sprintf( 'SVC%08d', $i ),
		'title' => 'Sunday Morning Service ' . $d->format( 'M j, Y' ),
		'desc'  => 0 === $i ? 'Pastor Cindy Olson - Holiness in the House: Set Free - Titus 2:11-14' : '',
		'when'  => $d->modify( '+1 day' )->format( 'Y-m-d' ) . 'T03:00:00Z',
		'dur'   => 'PT1H5M2S',
	);
}
$videos = array_merge(
	array(
		array( 'id' => 'SHORT000001', 'title' => 'Sunday Morning Service Oct 25, 2026 #shorts', 'desc' => '', 'when' => '2026-10-25T20:00:00Z', 'dur' => 'PT58S' ),
		array( 'id' => 'LIVE0000001', 'title' => 'Sunday Morning Service Nov 1, 2026', 'desc' => '', 'when' => '2026-10-31T20:00:00Z', 'dur' => 'PT0S', 'live' => 'upcoming' ),
		array( 'id' => 'YOUTH000001', 'title' => 'Youth Group Highlights', 'desc' => '', 'when' => '2026-09-01T20:00:00Z', 'dur' => 'PT31M' ),
		array( 'id' => 'PRIV0000001', 'title' => 'Sunday Morning Service Aug 2, 2026', 'desc' => '', 'when' => '2026-08-03T03:00:00Z', 'dur' => 'PT1H', 'privacy' => 'private' ),
	),
	$videos,
	array(
		array( 'id' => 'Nll1UzWZHQA', 'title' => 'Sunday Morning Service May 24, 2026', 'desc' => 'Pentecost Promised, Proclaimed, Practiced - Associate Pastor Rob Hammann - Acts 1-3', 'when' => '2026-05-25T06:46:42Z', 'dur' => 'PT58M' ),
		array( 'id' => 'vTKuOza2c68', 'title' => 'Sunday AM Worship - May 3, 2026', 'desc' => "Holiness in the House: The Crisis and Process of Grace - Pastor Cindy Olson\nTitus 3:1-8", 'when' => '2026-05-04T05:05:41Z', 'dur' => 'PT47M' ),
		array( 'id' => '3JdIij39S2g', 'title' => 'Sunday Morning Service Jan 12, 2025', 'desc' => '', 'when' => '2025-01-13T03:00:00Z', 'dur' => 'PT50M' ),
		array( 'id' => 'VISION00001', 'title' => 'Sunday Morning Service May 7, 2023', 'desc' => '', 'when' => '2023-05-08T03:00:00Z', 'dur' => 'PT52M' ),
	)
);

$endpoint = basename( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) );
if ( 'playlistItems' === $endpoint ) {
	if ( 'UUtest00000000000000000A' !== ( $_GET['playlistId'] ?? '' ) ) {
		fail( 404, 'playlistNotFound' );
	}
	$list = $videos;
	$list[] = $videos[5]; // the same video listed twice.
	$from  = (int) ( $_GET['pageToken'] ?? 0 );
	$slice = array_slice( $list, $from, 50 );
	$out   = array( 'items' => array() );
	foreach ( $slice as $v ) {
		$out['items'][] = array(
			'snippet' => array(
				'title'       => $v['title'],
				'description' => $v['desc'],
				'publishedAt' => $v['when'],
				'resourceId'  => array( 'videoId' => $v['id'] ),
				'thumbnails'  => array( 'high' => array( 'url' => 'http://127.0.0.1:8099/thumb.jpg' ) ),
			),
			'status'  => array( 'privacyStatus' => $v['privacy'] ?? 'public' ),
		);
	}
	if ( $from + 50 < count( $list ) ) {
		$out['nextPageToken'] = (string) ( $from + 50 );
	}
	echo json_encode( $out );
	exit;
}
if ( 'videos' === $endpoint ) {
	$ids = explode( ',', (string) ( $_GET['id'] ?? '' ) );
	$out = array( 'items' => array() );
	foreach ( $videos as $v ) {
		if ( in_array( $v['id'], $ids, true ) && ( $v['privacy'] ?? '' ) !== 'private' ) {
			$out['items'][] = array(
				'id'             => $v['id'],
				'contentDetails' => array( 'duration' => $v['dur'] ),
				'snippet'        => array( 'liveBroadcastContent' => $v['live'] ?? 'none' ),
				'status'         => array( 'privacyStatus' => $v['privacy'] ?? 'public', 'embeddable' => true ),
			);
		}
	}
	echo json_encode( $out );
	exit;
}
fail( 404, 'notFound' );
