<?php
/**
 * Tests for the robots.txt parser (step E, batch E-1).
 *
 * @license GPL-2.0-or-later
 * @file
 */

namespace MediaWiki\Extension\RobotsTxt\Tests\Unit;

use MediaWiki\Extension\RobotsTxt\RobotsTxtParser;
use MediaWikiUnitTestCase;

/**
 * Every case gives a text and the exact structure the parser must return. The expected values
 * are derived by hand from the rules in the class documentation of RobotsTxtParser (decision
 * 27), not by running the code, so a wrong parser cannot make a wrong test pass.
 *
 * @group RobotsTxt
 * @covers \MediaWiki\Extension\RobotsTxt\RobotsTxtParser
 */
class RobotsTxtParserTest extends MediaWikiUnitTestCase {

	/**
	 * Build an expected entry. Arguments in the order of the structure, so a test reads like
	 * the line it describes.
	 *
	 * @param ?string $directive Directive as written, or null for an unparseable line
	 * @param string $value Value, or the whole line for an unparseable one
	 * @param ?string $comment Trailing comment including its `#`
	 * @param string[] $before Full-line comments above the line, each including its `#`
	 * @return array
	 */
	private static function entry(
		?string $directive, string $value, ?string $comment = null, array $before = []
	): array {
		return [
			'directive' => $directive,
			'value' => $value,
			'comment' => $comment,
			'before' => $before,
		];
	}

	/**
	 * Build an expected group.
	 *
	 * @param array[] $agents The `User-agent` entries of the group
	 * @param array[] $rules The other entries of the group
	 * @return array
	 */
	private static function group( array $agents, array $rules = [] ): array {
		return [ 'agents' => $agents, 'rules' => $rules ];
	}

	/**
	 * Build the expected result of parse().
	 *
	 * @param array[] $preamble
	 * @param array[] $groups
	 * @param array[] $sitemaps
	 * @param string[] $footer
	 * @return array
	 */
	private static function result(
		array $preamble = [], array $groups = [], array $sitemaps = [], array $footer = []
	): array {
		return [
			'preamble' => $preamble,
			'groups' => $groups,
			'sitemaps' => $sitemaps,
			'footer' => $footer,
		];
	}

	/**
	 * @dataProvider provideParse
	 * @param string $text
	 * @param array $expected
	 */
	public function testParse( string $text, array $expected ): void {
		$this->assertSame( $expected, RobotsTxtParser::parse( $text ) );
	}

	/**
	 * @return iterable
	 */
	public static function provideParse(): iterable {
		$url = 'https://e.org/s.xml';

		yield 'empty input' => [ '', self::result() ];

		yield 'only blank lines' => [ "\n\n  \n", self::result() ];

		yield 'one group with rules' => [
			"User-agent: *\nDisallow: /a\nAllow: /b\n",
			self::result( [], [
				self::group( [ self::entry( 'User-agent', '*' ) ], [
					self::entry( 'Disallow', '/a' ),
					self::entry( 'Allow', '/b' ),
				] ),
			] ),
		];

		// The layout of WikiTrek's hand-written file for ClaudeBot and GPTBot (trap 3).
		yield 'two consecutive agent lines share one group' => [
			"User-agent: GPTBot\nUser-agent: ClaudeBot\nDisallow: /a\n",
			self::result( [], [
				self::group( [
					self::entry( 'User-agent', 'GPTBot' ),
					self::entry( 'User-agent', 'ClaudeBot' ),
				], [ self::entry( 'Disallow', '/a' ) ] ),
			] ),
		];

		yield 'an agent line after rules starts a new group' => [
			"User-agent: *\nDisallow: /a\nUser-agent: GPTBot\nDisallow: /b\n",
			self::result( [], [
				self::group( [ self::entry( 'User-agent', '*' ) ], [ self::entry( 'Disallow', '/a' ) ] ),
				self::group( [ self::entry( 'User-agent', 'GPTBot' ) ], [ self::entry( 'Disallow', '/b' ) ] ),
			] ),
		];

		yield 'directive names in any case, spelling kept' => [
			"USER-AGENT: Bot\ndisallow: /X\n",
			self::result( [], [
				self::group( [ self::entry( 'USER-AGENT', 'Bot' ) ], [ self::entry( 'disallow', '/X' ) ] ),
			] ),
		];

		yield 'trailing comment is split from the value' => [
			"User-agent: *\nDisallow: /a # private\nDisallow: /b#x\n",
			self::result( [], [
				self::group( [ self::entry( 'User-agent', '*' ) ], [
					self::entry( 'Disallow', '/a', '# private' ),
					self::entry( 'Disallow', '/b', '#x' ),
				] ),
			] ),
		];

		yield 'only the first colon splits a line' => [
			"User-agent: *\nDisallow: /a:b\nAllow: /x?y=z:w\n",
			self::result( [], [
				self::group( [ self::entry( 'User-agent', '*' ) ], [
					self::entry( 'Disallow', '/a:b' ),
					self::entry( 'Allow', '/x?y=z:w' ),
				] ),
			] ),
		];

		yield 'spaces around the colon and an empty value' => [
			"User-agent:*\nDisallow :   /a  \nDisallow:\n",
			self::result( [], [
				self::group( [ self::entry( 'User-agent', '*' ) ], [
					self::entry( 'Disallow', '/a' ),
					self::entry( 'Disallow', '' ),
				] ),
			] ),
		];

		yield 'orphan rule before the first agent goes to the preamble' => [
			"Disallow: /a\nUser-agent: *\nAllow: /b\n",
			self::result(
				[ self::entry( 'Disallow', '/a' ) ],
				[ self::group( [ self::entry( 'User-agent', '*' ) ], [ self::entry( 'Allow', '/b' ) ] ) ]
			),
		];

		yield 'line without a colon is kept inside its group' => [
			"User-agent: *\nthis is nonsense\nDisallow: /a\n",
			self::result( [], [
				self::group( [ self::entry( 'User-agent', '*' ) ], [
					self::entry( null, 'this is nonsense' ),
					self::entry( 'Disallow', '/a' ),
				] ),
			] ),
		];

		yield 'line without a colon before the first agent goes to the preamble' => [
			"nonsense\nUser-agent: *\n",
			self::result(
				[ self::entry( null, 'nonsense' ) ],
				[ self::group( [ self::entry( 'User-agent', '*' ) ] ) ]
			),
		];

		yield 'an agent line with an empty name is kept but starts no group' => [
			"User-agent:\nUser-agent: *\nDisallow: /a\n",
			self::result(
				[ self::entry( null, 'User-agent:' ) ],
				[ self::group( [ self::entry( 'User-agent', '*' ) ], [ self::entry( 'Disallow', '/a' ) ] ) ]
			),
		];

		yield 'an unparseable line between two agent lines splits the groups' => [
			"User-agent: A\nnonsense\nUser-agent: B\n",
			self::result( [], [
				self::group( [ self::entry( 'User-agent', 'A' ) ], [ self::entry( null, 'nonsense' ) ] ),
				self::group( [ self::entry( 'User-agent', 'B' ) ] ),
			] ),
		];

		yield 'sitemap line inside a group is lifted out' => [
			"User-agent: *\nSitemap: $url\nDisallow: /a\n",
			self::result(
				[],
				[ self::group( [ self::entry( 'User-agent', '*' ) ], [ self::entry( 'Disallow', '/a' ) ] ) ],
				[ self::entry( 'Sitemap', $url ) ]
			),
		];

		// A lifted line is invisible to the grouping: it must not split a shared group.
		yield 'a sitemap line between two agent lines does not split the group' => [
			"User-agent: A\nSitemap: $url\nUser-agent: B\nDisallow: /a\n",
			self::result(
				[],
				[
					self::group(
						[ self::entry( 'User-agent', 'A' ), self::entry( 'User-agent', 'B' ) ],
						[ self::entry( 'Disallow', '/a' ) ]
					),
				],
				[ self::entry( 'Sitemap', $url ) ]
			),
		];

		yield 'sitemap line before the first agent is lifted out of the preamble' => [
			"Sitemap: $url\nUser-agent: *\n",
			self::result(
				[],
				[ self::group( [ self::entry( 'User-agent', '*' ) ] ) ],
				[ self::entry( 'Sitemap', $url ) ]
			),
		];

		yield 'sitemap lines at the end keep their order and spelling' => [
			"User-agent: *\nDisallow: /a\n\nSitemap: https://e.org/a.xml\nSITEMAP: https://e.org/b.xml\n",
			self::result(
				[],
				[ self::group( [ self::entry( 'User-agent', '*' ) ], [ self::entry( 'Disallow', '/a' ) ] ) ],
				[
					self::entry( 'Sitemap', 'https://e.org/a.xml' ),
					self::entry( 'SITEMAP', 'https://e.org/b.xml' ),
				]
			),
		];

		yield 'a comment above an agent line travels with it' => [
			"# all bots\nUser-agent: *\nDisallow: /a\n",
			self::result( [], [
				self::group(
					[ self::entry( 'User-agent', '*', null, [ '# all bots' ] ) ],
					[ self::entry( 'Disallow', '/a' ) ]
				),
			] ),
		];

		yield 'a comment above a rule travels with the rule' => [
			"User-agent: *\n# no editing\nDisallow: /*action=edit\n",
			self::result( [], [
				self::group( [ self::entry( 'User-agent', '*' ) ], [
					self::entry( 'Disallow', '/*action=edit', null, [ '# no editing' ] ),
				] ),
			] ),
		];

		yield 'a comment above a sitemap line travels with it' => [
			"# index\nSitemap: $url\n",
			self::result( [], [], [ self::entry( 'Sitemap', $url, null, [ '# index' ] ) ] ),
		];

		yield 'a comment between two agent lines keeps the group shared' => [
			"User-agent: GPTBot\n# same rules\nUser-agent: ClaudeBot\nDisallow: /a\n",
			self::result( [], [
				self::group( [
					self::entry( 'User-agent', 'GPTBot' ),
					self::entry( 'User-agent', 'ClaudeBot', null, [ '# same rules' ] ),
				], [ self::entry( 'Disallow', '/a' ) ] ),
			] ),
		];

		yield 'comments at the end of the text go to the footer' => [
			"User-agent: *\nDisallow: /a\n# end\n# really\n",
			self::result(
				[],
				[ self::group( [ self::entry( 'User-agent', '*' ) ], [ self::entry( 'Disallow', '/a' ) ] ) ],
				[],
				[ '# end', '# really' ]
			),
		];

		yield 'blank lines are dropped and do not detach a comment' => [
			"User-agent: *\n\n\n# note\n\nDisallow: /a\n\n",
			self::result( [], [
				self::group( [ self::entry( 'User-agent', '*' ) ], [
					self::entry( 'Disallow', '/a', null, [ '# note' ] ),
				] ),
			] ),
		];

		yield 'CRLF line endings' => [
			"User-agent: *\r\nDisallow: /a\r\n",
			self::result( [], [
				self::group( [ self::entry( 'User-agent', '*' ) ], [ self::entry( 'Disallow', '/a' ) ] ),
			] ),
		];

		yield 'lone CR line endings' => [
			"User-agent: *\rDisallow: /a\r",
			self::result( [], [
				self::group( [ self::entry( 'User-agent', '*' ) ], [ self::entry( 'Disallow', '/a' ) ] ),
			] ),
		];

		// A realistic file: header comment, a `*` group, a shared group with a trailing comment,
		// and a sitemap line after a blank line.
		yield 'realistic file' => [
			implode( "\n", [
				'# WikiTrek',
				'User-agent: *',
				'Disallow: /wt/',
				'Disallow: /*action=edit',
				'',
				'User-agent: ClaudeBot',
				'User-agent: GPTBot',
				'Crawl-delay: 10',
				'Disallow: /wt/ # same list',
				'',
				'Sitemap: https://wikitrek.org/sitemap.xml',
				'',
			] ),
			self::result(
				[],
				[
					self::group(
						[ self::entry( 'User-agent', '*', null, [ '# WikiTrek' ] ) ],
						[
							self::entry( 'Disallow', '/wt/' ),
							self::entry( 'Disallow', '/*action=edit' ),
						]
					),
					self::group(
						[
							self::entry( 'User-agent', 'ClaudeBot' ),
							self::entry( 'User-agent', 'GPTBot' ),
						],
						[
							self::entry( 'Crawl-delay', '10' ),
							self::entry( 'Disallow', '/wt/', '# same list' ),
						]
					),
				],
				[ self::entry( 'Sitemap', 'https://wikitrek.org/sitemap.xml' ) ]
			),
		];
	}
}
