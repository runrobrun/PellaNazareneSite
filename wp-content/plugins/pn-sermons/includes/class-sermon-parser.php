<?php
namespace PN_Sermons;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a YouTube video's title and description and works out what the sermon is: its date, speaker, title,
 * series, and scripture.
 *
 * Pella's uploads are whole Sunday services titled only with the date ("Sunday Morning Service Oct. 18, 2026").
 * The sermon details, when anyone fills them in, are in the description in a loose format, in any order:
 *
 *   Pastor Rob Hammann - I AM: I AM the bread of life - John 6
 *   Pentecost Promised, Proclaimed, Practiced - Associate Pastor Rob Hammann
 *   Holiness in the House: The Crisis and Process of Grace - Pastor Cindy Olson   (scripture on the next line)
 *
 * So this works on pieces ("segments") separated by dashes and colons, recognises the speaker, the scripture,
 * and known series by what they are, and treats what is left as the title. It never invents anything: when it is
 * unsure it leaves a field empty and the draft is reviewed by a person before it is published.
 *
 * Pure PHP with no WordPress calls, so it can be tested on its own.
 */
class Sermon_Parser {

	/** Book name or abbreviation (lower case) mapped to the name used on the site. */
	const BOOKS = array(
		'Genesis'         => array( 'gen', 'ge', 'gn' ),
		'Exodus'          => array( 'exod', 'exo', 'ex' ),
		'Leviticus'       => array( 'lev', 'lv' ),
		'Numbers'         => array( 'num', 'nm', 'nu' ),
		'Deuteronomy'     => array( 'deut', 'dt' ),
		'Joshua'          => array( 'josh', 'jos' ),
		'Judges'          => array( 'judg', 'jdg' ),
		'Ruth'            => array( 'ru' ),
		'1 Samuel'        => array( '1 sam', '1sam', 'i samuel', '1st samuel', 'first samuel' ),
		'2 Samuel'        => array( '2 sam', '2sam', 'ii samuel', '2nd samuel', 'second samuel' ),
		'1 Kings'         => array( '1 kgs', '1kgs', '1 ki', 'i kings', '1st kings', 'first kings' ),
		'2 Kings'         => array( '2 kgs', '2kgs', '2 ki', 'ii kings', '2nd kings', 'second kings' ),
		'1 Chronicles'    => array( '1 chron', '1 chr', '1chr', 'i chronicles', '1st chronicles', 'first chronicles' ),
		'2 Chronicles'    => array( '2 chron', '2 chr', '2chr', 'ii chronicles', '2nd chronicles', 'second chronicles' ),
		'Ezra'            => array( 'ezr' ),
		'Nehemiah'        => array( 'neh' ),
		'Esther'          => array( 'esth', 'est' ),
		'Job'             => array(),
		'Psalms'          => array( 'psalm', 'ps', 'psa', 'pss' ),
		'Proverbs'        => array( 'prov', 'pr', 'prv' ),
		'Ecclesiastes'    => array( 'eccl', 'eccles', 'ecc', 'qoheleth' ),
		'Song of Songs'   => array( 'song of solomon', 'song', 'sos', 'songs' ),
		'Isaiah'          => array( 'isa', 'is' ),
		'Jeremiah'        => array( 'jer' ),
		'Lamentations'    => array( 'lam' ),
		'Ezekiel'         => array( 'ezek', 'eze' ),
		'Daniel'          => array( 'dan', 'dn' ),
		'Hosea'           => array( 'hos' ),
		'Joel'            => array( 'jl' ),
		'Amos'            => array( 'am' ),
		'Obadiah'         => array( 'obad', 'ob' ),
		'Jonah'           => array( 'jon' ),
		'Micah'           => array( 'mic' ),
		'Nahum'           => array( 'nah', 'na' ),
		'Habakkuk'        => array( 'hab' ),
		'Zephaniah'       => array( 'zeph', 'zep' ),
		'Haggai'          => array( 'hag' ),
		'Zechariah'       => array( 'zech', 'zec' ),
		'Malachi'         => array( 'mal' ),
		'Matthew'         => array( 'matt', 'mt' ),
		'Mark'            => array( 'mk', 'mrk' ),
		'Luke'            => array( 'lk', 'luk' ),
		'John'            => array( 'jn', 'jhn' ),
		'Acts'            => array( 'act' ),
		'Romans'          => array( 'rom', 'rm' ),
		'1 Corinthians'   => array( '1 cor', '1cor', 'i corinthians', '1st corinthians', 'first corinthians' ),
		'2 Corinthians'   => array( '2 cor', '2cor', 'ii corinthians', '2nd corinthians', 'second corinthians' ),
		'Galatians'       => array( 'gal' ),
		'Ephesians'       => array( 'eph' ),
		'Philippians'     => array( 'phil', 'php' ),
		'Colossians'      => array( 'col' ),
		'1 Thessalonians' => array( '1 thess', '1 thes', '1thess', 'i thessalonians', '1st thessalonians', 'first thessalonians' ),
		'2 Thessalonians' => array( '2 thess', '2 thes', '2thess', 'ii thessalonians', '2nd thessalonians', 'second thessalonians' ),
		'1 Timothy'       => array( '1 tim', '1tim', 'i timothy', '1st timothy', 'first timothy' ),
		'2 Timothy'       => array( '2 tim', '2tim', 'ii timothy', '2nd timothy', 'second timothy' ),
		'Titus'           => array( 'tit' ),
		'Philemon'        => array( 'philem', 'phlm' ),
		'Hebrews'         => array( 'heb' ),
		'James'           => array( 'jas', 'jm' ),
		'1 Peter'         => array( '1 pet', '1pet', 'i peter', '1st peter', 'first peter' ),
		'2 Peter'         => array( '2 pet', '2pet', 'ii peter', '2nd peter', 'second peter' ),
		'1 John'          => array( '1 jn', '1jn', 'i john', '1st john', 'first john' ),
		'2 John'          => array( '2 jn', '2jn', 'ii john', '2nd john', 'second john' ),
		'3 John'          => array( '3 jn', '3jn', 'iii john', '3rd john', 'third john' ),
		'Jude'            => array(),
		'Revelation'      => array( 'revelations', 'rev', 'rv' ),
	);

	/** @var array<string,mixed> */
	private array $rules;

	/** @var array<string,string>|null lower-case alias => canonical book name */
	private static ?array $book_index = null;

	/**
	 * @param array<string,mixed> $rules Contents of data/sermon-rules.json.
	 */
	public function __construct( array $rules = array() ) {
		$this->rules = $rules + array(
			'speakers'        => array(),
			'series'          => array(),
			'ignore_segments' => array(),
		);
	}

	/**
	 * @param array{id?:string,title?:string,description?:string,published?:string} $video One video.
	 * @return array{date:string,title:string,speaker:string,series_name:string,series_slug:string,scripture_ref:string,books:string[],excerpt:string,notes:string[],raw_description:string,parsed:bool}
	 */
	public function parse( array $video ): array {
		// Text from YouTube is untrusted: no markup survives into a title, speaker, or series.
		$video_title = trim(
			strip_tags( // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- this class runs without WordPress in its unit test.
				(string) ( $video['title'] ?? '' )
			)
		);
		$description = trim(
			strip_tags( // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- same.
				(string) ( $video['description'] ?? '' )
			)
		);
		$published   = (string) ( $video['published'] ?? '' );

		$out = array(
			'date'            => self::service_date( $video_title, $published ),
			'title'           => $video_title,
			'speaker'         => '',
			'series_name'     => '',
			'series_slug'     => '',
			'scripture_ref'   => '',
			'books'           => array(),
			'excerpt'         => '',
			'notes'           => array(),
			'raw_description' => $description,
			'parsed'          => false,
		);

		// Only the first few short lines can be sermon details; a long paragraph is announcements or links.
		$lines = array();
		foreach ( preg_split( '/\R/u', $description ) ?: array() as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}
		$unknown     = array();
		$scriptures  = array();
		$speaker     = '';
		$series_hit  = null;
		$extra_lines = array();

		foreach ( array_slice( $lines, 0, 4 ) as $line ) {
			if ( mb_strlen( $line ) > 200 || preg_match( '#https?://#i', $line ) ) {
				continue;
			}
			foreach ( $this->segments( $line ) as $segment ) {
				if ( $this->ignored( $segment ) ) {
					continue;
				}
				$refs = self::scripture_refs( $segment, true );
				if ( $refs ) {
					$scriptures = array_merge( $scriptures, $refs );
					continue;
				}
				$name = $this->speaker_name( $segment );
				if ( '' !== $name ) {
					$speaker = '' === $speaker ? $name : $speaker;
					continue;
				}
				$known = $this->known_series( $segment );
				if ( $known ) {
					$series_hit = $series_hit ?? $known;
					continue;
				}
				$unknown[] = $segment;
			}
		}

		// Does it look like sermon details at all? If not, leave the description alone.
		if ( '' === $speaker && ! $scriptures && ! $series_hit ) {
			$out['notes'][] = 'No sermon details found in the description.';
			return $out;
		}

		$out['parsed']  = true;
		$out['speaker'] = $speaker;
		if ( $series_hit ) {
			$out['series_name'] = $series_hit['name'];
			$out['series_slug'] = $series_hit['slug'];
		}

		$title = '';
		$count = count( $unknown );
		if ( 1 === $count ) {
			$title = $unknown[0];
		} elseif ( 2 === $count && ! $series_hit ) {
			// "Series: Message title" is the usual order.
			$out['series_name'] = $unknown[0];
			$title              = $unknown[1];
			$out['notes'][]     = 'Series guessed from the first part of the description; check it.';
		} elseif ( $count >= 2 ) {
			$title          = '';
			$extra_lines    = $unknown;
			$out['notes'][] = 'The description has more parts than expected; the title may need fixing.';
		}
		if ( '' !== $title ) {
			$out['title'] = self::clean_title( $title );
		}
		if ( $extra_lines ) {
			$out['excerpt'] = implode( ' - ', $extra_lines );
		}

		if ( $scriptures ) {
			$seen = array();
			$text = array();
			foreach ( $scriptures as $ref ) {
				$seen[ $ref['book'] ] = true;
				$text[]               = $ref['text'];
			}
			$out['books']         = array_keys( $seen );
			$out['scripture_ref'] = implode( '; ', $text );
		}
		return $out;
	}

	/**
	 * The Sunday the service was held. Taken from the video title when it has a date, otherwise from the
	 * publish date, so a service uploaded on Monday morning is still dated Sunday.
	 */
	public static function service_date( string $video_title, string $published ): string {
		$pub = null;
		try {
			$pub = '' !== $published ? new \DateTimeImmutable( $published ) : null;
		} catch ( \Exception $e ) {
			$pub = null;
		}

		$months = array(
			'jan' => 1,
			'feb' => 2,
			'mar' => 3,
			'apr' => 4,
			'may' => 5,
			'jun' => 6,
			'jul' => 7,
			'aug' => 8,
			'sep' => 9,
			'oct' => 10,
			'nov' => 11,
			'dec' => 12,
		);
		$regex  = '/\b(Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|June?|July?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\.?\s+(\d{1,2})(?:st|nd|rd|th)?(?:,?\s*(\d{4}))?\b/i';
		if ( preg_match( $regex, $video_title, $m ) ) {
			$month = $months[ strtolower( substr( $m[1], 0, 3 ) ) ];
			$day   = (int) $m[2];
			if ( ! empty( $m[3] ) ) {
				$year = (int) $m[3];
			} else {
				// No year in the title: the latest year that is not after the day it was uploaded.
				$year = $pub ? (int) $pub->format( 'Y' ) : (int) gmdate( 'Y' );
				if ( $pub && gmmktime( 0, 0, 0, $month, $day, $year ) > $pub->getTimestamp() + 2 * 86400 ) {
					--$year;
				}
			}
			if ( checkdate( $month, $day, $year ) ) {
				return sprintf( '%04d-%02d-%02d', $year, $month, $day );
			}
		}
		if ( $pub ) {
			return $pub->setTimezone( new \DateTimeZone( 'America/Chicago' ) )->format( 'Y-m-d' );
		}
		return '';
	}

	/**
	 * Scripture references in a piece of text, e.g. "John 14:1-4; Revelation 21:1-4".
	 *
	 * @param string $text       The text to search.
	 * @param bool   $whole_only When true, return references only if the whole text is nothing but references.
	 * @return array<int,array{book:string,text:string}>
	 */
	public static function scripture_refs( string $text, bool $whole_only = false ): array {
		$index = self::book_index();
		$names = array_keys( $index );
		usort( $names, static fn( $a, $b ) => strlen( $b ) <=> strlen( $a ) );
		$alt   = implode( '|', array_map( static fn( $n ) => str_replace( ' ', '\s*', preg_quote( $n, '/' ) ), $names ) );
		$verse = '\d{1,3}(?:\s*[-–—]\s*\d{1,3})?';
		$ref   = '(?:\d{1,3}(?:\s*[:.]\s*' . $verse . '(?:\s*,\s*' . $verse . ')*)?(?:\s*[-–—]\s*\d{1,3}(?:\s*:\s*\d{1,3})?)?)';
		$one   = '(?<![A-Za-z0-9])(' . $alt . ')\.?\s+(' . $ref . ')(?![A-Za-z0-9])';

		if ( $whole_only && ! preg_match( '/^\s*' . $one . '(?:\s*;\s*' . $one . ')*\s*$/iu', $text ) ) {
			return array();
		}
		if ( ! preg_match_all( '/' . $one . '/iu', $text, $matches, PREG_SET_ORDER ) ) {
			return array();
		}
		$refs = array();
		foreach ( $matches as $m ) {
			$key    = strtolower( preg_replace( '/\s+/', ' ', $m[1] ) );
			$book   = $index[ $key ] ?? $index[ preg_replace( '/^(\d)(?=[a-z])/', '$1 ', $key ) ] ?? '';
			$verses = trim( preg_replace( '/\s*([:,])\s*/', '$1 ', preg_replace( '/\s*[-–—]\s*/u', '–', $m[2] ) ) );
			$verses = str_replace( ': ', ':', rtrim( $verses ) );
			if ( '' !== $book ) {
				$refs[] = array(
					'book' => $book,
					'text' => $book . ' ' . $verses,
				);
			}
		}
		return $refs;
	}

	/** @return array<string,string> */
	private static function book_index(): array {
		if ( null === self::$book_index ) {
			self::$book_index = array();
			foreach ( self::BOOKS as $canonical => $aliases ) {
				self::$book_index[ strtolower( $canonical ) ] = $canonical;
				foreach ( $aliases as $alias ) {
					self::$book_index[ $alias ] = $canonical;
				}
			}
			self::$book_index['psalm']           = 'Psalms';
			self::$book_index['song of solomon'] = 'Song of Songs';
		}
		return self::$book_index;
	}

	/**
	 * Pieces of one line: split at " - ", " – ", " — ", " | " and at ": " (a colon followed by a space, so
	 * "John 14:1-4" and "10:30" stay whole).
	 *
	 * @return string[]
	 */
	private function segments( string $line ): array {
		$parts = preg_split( '/\s+[-–—|]\s+|(?<!\d):\s+/u', $line ) ?: array();
		$out   = array();
		foreach ( $parts as $part ) {
			$part = trim( $part, " \t\"'“”‘’" );
			if ( '' !== $part ) {
				$out[] = $part;
			}
		}
		return $out;
	}

	private function ignored( string $segment ): bool {
		// The description sometimes repeats the video title, or labels a field ("Speaker:", "Scripture:").
		foreach ( (array) $this->rules['ignore_segments'] as $label ) {
			if ( 0 === strcasecmp( $segment, (string) $label ) ) {
				return true;
			}
		}
		return 1 === preg_match( '/^Sunday (?:AM Worship|Morning (?:Service|Worship))\b/i', $segment );
	}

	/** The speaker's name if this piece is a speaker, otherwise an empty string. */
	private function speaker_name( string $segment ): string {
		foreach ( (array) $this->rules['speakers'] as $speaker ) {
			foreach ( (array) ( $speaker['aliases'] ?? array() ) as $alias ) {
				if ( '' !== $alias && false !== stripos( $segment, (string) $alias ) && mb_strlen( $segment ) <= mb_strlen( (string) $alias ) + 24 ) {
					return (string) $speaker['name'];
				}
			}
		}
		// A guest named with a title, such as Pastor Jane Smith or Dr. Ann Lee.
		if ( preg_match( '/^(?:(?:Associate|Senior|Lead|Youth|Guest|Visiting)\s+)?(?:Pastor|Rev\.?|Reverend|Dr\.?)\s+([A-Z][\w\'’-]+(?:\s+[A-Z][\w\'’-]+){1,2})$/u', $segment, $m ) ) {
			return trim( $m[1] );
		}
		return '';
	}

	/** @return array{name:string,slug:string}|null */
	private function known_series( string $segment ): ?array {
		foreach ( (array) $this->rules['series'] as $series ) {
			foreach ( (array) ( $series['match'] ?? array() ) as $needle ) {
				$hit = ! empty( $series['exact'] ) ? 0 === strcasecmp( $segment, (string) $needle ) : false !== stripos( $segment, (string) $needle );
				if ( $hit ) {
					return array(
						'name' => (string) $series['name'],
						'slug' => (string) ( $series['slug'] ?? '' ),
					);
				}
			}
		}
		return null;
	}

	private static function clean_title( string $title ): string {
		$title = trim( preg_replace( '/\s+/u', ' ', $title ), " \t-–—|:\"“”" );
		return mb_substr( $title, 0, 150 );
	}
}
