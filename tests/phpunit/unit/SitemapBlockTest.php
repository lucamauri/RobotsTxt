<?php
/**
 * Unit tests for SitemapBlock.
 *
 * @file
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\RobotsTxt\Tests\Unit;

use MediaWiki\Extension\RobotsTxt\SitemapBlock;
use MediaWikiUnitTestCase;

/**
 * Tests for {@see SitemapBlock}.
 *
 * SitemapBlock turns the list from $wgRobotsTxtSitemaps, plus the text that
 * robots.txt already has, into the block of "Sitemap:" lines to append at
 * the end of the file. It needs no wiki and no service, so these are pure
 * unit tests with exact expected output.
 *
 * The rules under test (design decisions D4 to D6):
 *  - an entry is valid when it is a string holding an absolute http or https
 *    URL with no whitespace and no control characters;
 *  - a valid URL is written as given, once, in list order;
 *  - a URL that the text already lists as a "Sitemap:" line is not repeated;
 *  - an invalid entry is skipped, and one comment line says how many were;
 *  - when there is nothing to add, the block is the empty string.
 *
 * The block, when it is not empty, starts with an empty line (so it is
 * separated from the rules above it) and ends with a newline. The comment
 * line, when there is one, comes first, then the "Sitemap:" lines.
 *
 * @group RobotsTxt
 * @covers \MediaWiki\Extension\RobotsTxt\SitemapBlock
 */
class SitemapBlockTest extends MediaWikiUnitTestCase {

	/**
	 * The text robots.txt has so far, as the builder passes it: clean, ending
	 * with exactly one newline. Most cases do not depend on its content.
	 */
	private const TEXT = "User-agent: *\nDisallow:\n";

	/**
	 * The comment line that reports skipped entries.
	 *
	 * Spelled out in one place so that the wording is pinned once and every
	 * case that expects it shows only the count.
	 *
	 * @param int $count How many entries were skipped
	 * @return string
	 */
	private static function skippedNote( int $count ): string {
		return "# RobotsTxt: invalid \$wgRobotsTxtSitemaps entries ignored: $count\n";
	}

	/**
	 * Cases for render(): [ the list, the text so far, the block expected ].
	 *
	 * @return array<string,array{0:array,1:string,2:string}>
	 */
	public static function provideRenderCases(): array {
		return [
			'an empty list adds nothing' => [
				[],
				self::TEXT,
				'',
			],
			'one URL' => [
				[ 'https://example.org/sitemap.xml' ],
				self::TEXT,
				"\nSitemap: https://example.org/sitemap.xml\n",
			],
			'several URLs keep the order of the list' => [
				[ 'https://example.org/b.xml', 'https://example.org/a.xml' ],
				self::TEXT,
				"\nSitemap: https://example.org/b.xml\nSitemap: https://example.org/a.xml\n",
			],
			'a URL repeated in the list is written once' => [
				[ 'https://example.org/a.xml', 'https://example.org/a.xml' ],
				self::TEXT,
				"\nSitemap: https://example.org/a.xml\n",
			],
			'a URL the text already lists is skipped' => [
				[ 'https://example.org/a.xml', 'https://example.org/b.xml' ],
				"User-agent: *\nDisallow:\n\nSitemap: https://example.org/a.xml\n",
				"\nSitemap: https://example.org/b.xml\n",
			],
			'case of the directive name and spacing in the text are ignored' => [
				[ 'https://example.org/a.xml' ],
				"  SITEMAP:https://example.org/a.xml  \n",
				'',
			],
			'the comparison is exact: a trailing slash makes another URL' => [
				[ 'https://example.org/a/' ],
				"Sitemap: https://example.org/a\n",
				"\nSitemap: https://example.org/a/\n",
			],
			'a commented-out Sitemap line does not count as listed' => [
				[ 'https://example.org/a.xml' ],
				"# Sitemap: https://example.org/a.xml\n",
				"\nSitemap: https://example.org/a.xml\n",
			],
			'every URL already in the text: nothing to add' => [
				[ 'https://example.org/a.xml', 'https://example.org/b.xml' ],
				"Sitemap: https://example.org/a.xml\nSitemap: https://example.org/b.xml\n",
				'',
			],
			'the scheme is accepted in any letter case and written as given' => [
				[ 'HTTPS://example.org/a.xml' ],
				self::TEXT,
				"\nSitemap: HTTPS://example.org/a.xml\n",
			],
			'valid and invalid entries together: comment first, then the lines' => [
				[ 'https://example.org/a.xml', 'nope', 'ftp://example.org/b.xml' ],
				self::TEXT,
				"\n" . self::skippedNote( 2 ) . "Sitemap: https://example.org/a.xml\n",
			],
			'an invalid entry is reported even when the valid ones are already listed' => [
				[ 'https://example.org/a.xml', 'nope' ],
				"Sitemap: https://example.org/a.xml\n",
				"\n" . self::skippedNote( 1 ),
			],
		];
	}

	/**
	 * The block for a list and a text matches the expected block exactly.
	 *
	 * @dataProvider provideRenderCases
	 * @param array $entries Value of $wgRobotsTxtSitemaps
	 * @param string $text The text robots.txt has so far
	 * @param string $expected The block that must be appended
	 */
	public function testRender( array $entries, string $text, string $expected ): void {
		$this->assertSame( $expected, SitemapBlock::render( $entries, $text ) );
	}

	/**
	 * Entries that must be rejected, one per way of being wrong.
	 *
	 * The two newline cases matter most: a value with a line break in it
	 * could add directives of its own to the file. A trailing newline is
	 * listed separately because a plain "$" in a regular expression would let
	 * it through.
	 *
	 * @return array<string,array{0:mixed}>
	 */
	public static function provideInvalidEntries(): array {
		return [
			'relative path' => [ '/sitemap.xml' ],
			'other scheme' => [ 'ftp://example.org/s.xml' ],
			'space inside' => [ 'https://example.org/my sitemap.xml' ],
			'space around' => [ ' https://example.org/s.xml ' ],
			'line break inside, would add a directive' => [ "https://example.org/s.xml\nDisallow: /" ],
			'trailing newline' => [ "https://example.org/s.xml\n" ],
			'carriage return' => [ "https://example.org/s.xml\r" ],
			'empty string' => [ '' ],
			'scheme only' => [ 'https://' ],
			'integer' => [ 123 ],
			'null' => [ null ],
			'array' => [ [ 'https://example.org/s.xml' ] ],
		];
	}

	/**
	 * An invalid entry is skipped, and the comment line reports it.
	 *
	 * @dataProvider provideInvalidEntries
	 * @param mixed $entry The invalid entry
	 */
	public function testInvalidEntryIsSkippedWithComment( mixed $entry ): void {
		$this->assertSame(
			"\n" . self::skippedNote( 1 ),
			SitemapBlock::render( [ $entry ], self::TEXT )
		);
	}
}
