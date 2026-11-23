<?php
/**
 * Tests for the page linter (step E, batch E-5).
 *
 * @license GPL-2.0-or-later
 * @file
 */

namespace MediaWiki\Extension\RobotsTxt\Tests\Unit;

use MediaWiki\Extension\RobotsTxt\PageLinter;
use MediaWikiUnitTestCase;

/**
 * Every case gives a text and the exact list of problems the linter must report. The expected
 * lists are derived by hand from the rules in the class documentation of PageLinter
 * (decision 32), not by running the code.
 *
 * @group RobotsTxt
 * @covers \MediaWiki\Extension\RobotsTxt\PageLinter
 */
class PageLinterTest extends MediaWikiUnitTestCase {

	/**
	 * Build one expected problem.
	 *
	 * @param string $type
	 * @param int $line
	 * @param string $text
	 * @return array
	 */
	private static function problem( string $type, int $line, string $text ): array {
		return [ 'type' => $type, 'line' => $line, 'text' => $text ];
	}

	/**
	 * @dataProvider provideLint
	 * @param string $text
	 * @param array $expected
	 */
	public function testLint( string $text, array $expected ): void {
		$this->assertSame( $expected, PageLinter::lint( $text ) );
	}

	/**
	 * @return iterable
	 */
	public static function provideLint(): iterable {
		// Nothing to report.
		yield 'empty text' => [ '', [] ];
		yield 'blank lines and comments only' => [ "\n  \n# a comment\n   # another\n", [] ];
		yield 'a clean file' => [
			"User-agent: *\nDisallow: /w/\nAllow: /w/load.php\nDisallow: /*action=edit\n",
			[],
		];
		yield 'a star is a valid start of a value' => [ "User-agent: *\nDisallow: *.pdf\n", [] ];
		yield 'an empty Disallow means allow everything' => [ "User-agent: *\nDisallow:\n", [] ];
		yield 'a trailing comment is not part of the value' => [
			"User-agent: *\nDisallow: /a # not /b\nDisallow: # only a comment\n",
			[],
		];
		yield 'other directives are accepted as written' => [
			"Sitemap: https://x.example/s.xml\nUser-agent: *\nCrawl-delay: 5\nContent-Signal: ai-train=no\n",
			[],
		];
		yield 'names are matched ignoring case' => [
			"USER-AGENT: *\ndisallow: /a\nALLOW: /b\n",
			[],
		];
		yield 'spaces around the colon and the value' => [
			"User-agent : *\nDisallow  :   /a  \n",
			[],
		];

		// Mistake 1: no colon.
		yield 'a line with no colon' => [
			"User-agent: *\nDisallow /a\n",
			[ self::problem( 'nocolon', 2, 'Disallow /a' ) ],
		];
		yield 'a line with no colon before the first group' => [
			"just words\nUser-agent: *\n",
			[ self::problem( 'nocolon', 1, 'just words' ) ],
		];
		yield 'the reported text is trimmed' => [
			"User-agent: *\n   oops   \n",
			[ self::problem( 'nocolon', 2, 'oops' ) ],
		];

		// Mistake 2: an orphan rule.
		yield 'a Disallow before the first User-agent' => [
			"Disallow: /a\nUser-agent: *\nDisallow: /b\n",
			[ self::problem( 'orphan', 1, 'Disallow: /a' ) ],
		];
		yield 'an Allow before the first User-agent, any case, indented' => [
			"  allow: /a\nUser-agent: *\n",
			[ self::problem( 'orphan', 1, 'allow: /a' ) ],
		];
		yield 'every orphan rule is reported' => [
			"Disallow: /a\nAllow: /b\nUser-agent: *\n",
			[ self::problem( 'orphan', 1, 'Disallow: /a' ), self::problem( 'orphan', 2, 'Allow: /b' ) ],
		];
		yield 'rules after the first User-agent are not orphans' => [
			"User-agent: A\nDisallow: /a\n\nUser-agent: B\nDisallow: /b\n",
			[],
		];

		// Mistake 3: a value that does not start with / or *.
		yield 'a Disallow value without a leading slash' => [
			"User-agent: *\nDisallow: private\n",
			[ self::problem( 'path', 2, 'Disallow: private' ) ],
		];
		yield 'an Allow value without a leading slash' => [
			"User-agent: *\nAllow: load.php\n",
			[ self::problem( 'path', 2, 'Allow: load.php' ) ],
		];
		yield 'a full URL is not a path' => [
			"User-agent: *\nDisallow: https://x.example/a\n",
			[ self::problem( 'path', 2, 'Disallow: https://x.example/a' ) ],
		];
		yield 'an empty Allow is reported, only an empty Disallow is fine' => [
			"User-agent: *\nAllow:\n",
			[ self::problem( 'path', 2, 'Allow:' ) ],
		];

		// Several mistakes.
		yield 'one line can have two mistakes' => [
			"Disallow: x\nUser-agent: *\n",
			[ self::problem( 'orphan', 1, 'Disallow: x' ), self::problem( 'path', 1, 'Disallow: x' ) ],
		];
		yield 'problems come in the order of the lines' => [
			"User-agent: *\nbad line\nDisallow: nope\nAllow\n",
			[
				self::problem( 'nocolon', 2, 'bad line' ),
				self::problem( 'path', 3, 'Disallow: nope' ),
				self::problem( 'nocolon', 4, 'Allow' ),
			],
		];

		// Line numbers and line endings.
		yield 'blank lines count in the line numbers' => [
			"\n\nbad\n",
			[ self::problem( 'nocolon', 3, 'bad' ) ],
		];
		yield 'CRLF line endings' => [
			"User-agent: *\r\nbad line\r\nDisallow: /a\r\n",
			[ self::problem( 'nocolon', 2, 'bad line' ) ],
		];
		yield 'lone CR line endings' => [
			"User-agent: *\rbad line\rDisallow: /a\r",
			[ self::problem( 'nocolon', 2, 'bad line' ) ],
		];

		// A long line is shortened for display.
		yield 'a long line is cut at 80 characters' => [
			"User-agent: *\n" . str_repeat( 'x', 200 ) . "\n",
			[ self::problem( 'nocolon', 2, str_repeat( 'x', 80 ) . '...' ) ],
		];
		yield 'a line of exactly 80 characters is kept whole' => [
			"User-agent: *\n" . str_repeat( 'x', 80 ) . "\n",
			[ self::problem( 'nocolon', 2, str_repeat( 'x', 80 ) ) ],
		];
	}
}
