<?php
/**
 * Tests for the rules merger, part A: split, merge by agent, duplicates, re-join, render
 * (step E, batch E-3a).
 *
 * @license GPL-2.0-or-later
 * @file
 */

namespace MediaWiki\Extension\RobotsTxt\Tests\Unit;

use MediaWiki\Extension\RobotsTxt\RobotsTxtParser;
use MediaWiki\Extension\RobotsTxt\RulesMerger;
use MediaWikiUnitTestCase;

/**
 * Every case gives the central rules (already in the shape that CentralRulesValidator returns),
 * the text of a page, and the exact text the merger must produce. The page text goes through the
 * real parser first, exactly as in production, so these tests also pin how the two classes fit
 * together. The expected texts are derived by hand from the rules in the class documentation
 * of RulesMerger (decisions 27 and 28), not by running the code.
 *
 * Inheritance is not part of this batch: every case passes `false` as the third argument.
 *
 * @group RobotsTxt
 * @covers \MediaWiki\Extension\RobotsTxt\RulesMerger
 * @uses \MediaWiki\Extension\RobotsTxt\RobotsTxtParser
 */
class RulesMergerTest extends MediaWikiUnitTestCase {

	/**
	 * @dataProvider provideMerge
	 * @param array $central
	 * @param string $pageText
	 * @param string $expected
	 */
	public function testMerge( array $central, string $pageText, string $expected ): void {
		$this->assertSame(
			$expected,
			RulesMerger::merge( $central, RobotsTxtParser::parse( $pageText ), false )
		);
	}

	/**
	 * @return iterable
	 */
	public static function provideMerge(): iterable {
		// Nothing in, nothing out.
		yield 'nothing at all' => [ [], '', '' ];
		yield 'only blank lines' => [ [], "\n\n  \n", '' ];

		// A page with no central rules passes through, in a canonical layout.
		yield 'one group unchanged' => [
			[],
			"User-agent: *\nDisallow: /private/\n",
			"User-agent: *\nDisallow: /private/\n",
		];
		yield 'spelling kept, spacing normalised' => [
			[],
			"user-agent:  *\nDISALLOW:/a\n",
			"user-agent: *\nDISALLOW: /a\n",
		];
		yield 'empty value has no trailing space' => [
			[],
			"User-agent: *\nDisallow:\n",
			"User-agent: *\nDisallow:\n",
		];
		yield 'comments are kept where they were' => [
			[],
			"# about all\nUser-agent: * # everyone\n# no admin\nDisallow: /admin/ # secret\n",
			"# about all\nUser-agent: * # everyone\n# no admin\nDisallow: /admin/ # secret\n",
		];
		yield 'two groups are separated by a blank line' => [
			[],
			"User-agent: A\nDisallow: /a\nUser-agent: B\nDisallow: /b\n",
			"User-agent: A\nDisallow: /a\n\nUser-agent: B\nDisallow: /b\n",
		];
		yield 'an agent with no rules is kept' => [
			[],
			"User-agent: A\n",
			"User-agent: A\n",
		];
		yield 'a line before the first group is printed first' => [
			[],
			"Crawl-delay: 5\nUser-agent: *\nDisallow: /a\n",
			"Crawl-delay: 5\n\nUser-agent: *\nDisallow: /a\n",
		];
		yield 'a line the parser did not understand is kept' => [
			[],
			"User-agent: *\nthis is junk\nDisallow: /a\n",
			"User-agent: *\nthis is junk\nDisallow: /a\n",
		];
		yield 'repeated unparseable lines are never removed' => [
			[],
			"User-agent: *\njunk\njunk\n",
			"User-agent: *\njunk\njunk\n",
		];

		// Sitemap lines and closing comments.
		yield 'page sitemap goes after the groups' => [
			[],
			"User-agent: *\nDisallow: /a\nSitemap: https://x.example/sm.xml\n",
			"User-agent: *\nDisallow: /a\n\nSitemap: https://x.example/sm.xml\n",
		];
		yield 'sitemap only' => [
			[],
			"Sitemap: https://x.example/a.xml\n",
			"Sitemap: https://x.example/a.xml\n",
		];
		yield 'closing comment comes before the sitemap lines' => [
			[],
			"User-agent: *\nDisallow: /a\nSitemap: https://x.example/s.xml\n# end\n",
			"User-agent: *\nDisallow: /a\n\n# end\n\nSitemap: https://x.example/s.xml\n",
		];
		yield 'only a comment' => [
			[],
			"# hello\n",
			"# hello\n",
		];

		// Splitting a shared group and merging by agent inside the page.
		yield 'a shared group stays shared' => [
			[],
			"User-agent: A\nUser-agent: B\nDisallow: /x\n",
			"User-agent: A\nUser-agent: B\nDisallow: /x\n",
		];
		yield 'a shared group split when one agent has more rules' => [
			[],
			"User-agent: A\nUser-agent: B\nDisallow: /x\n\nUser-agent: B\nDisallow: /y\n",
			"User-agent: A\nDisallow: /x\n\nUser-agent: B\nDisallow: /x\nDisallow: /y\n",
		];
		yield 'same agent twice, any case, first spelling wins' => [
			[],
			"User-agent: GPTBot\nDisallow: /a\n\nUser-agent: gptbot\nDisallow: /b\n",
			"User-agent: GPTBot\nDisallow: /a\nDisallow: /b\n",
		];

		// Central rules.
		yield 'central rules alone' => [
			[ 'GPTBot' => [ 'Disallow' => [ '/' ] ] ],
			'',
			"User-agent: GPTBot\nDisallow: /\n",
		];
		yield 'central rules come first in the same agent' => [
			[ '*' => [ 'Disallow' => [ '/admin/' ] ] ],
			"User-agent: *\nDisallow: /tmp/\n",
			"User-agent: *\nDisallow: /admin/\nDisallow: /tmp/\n",
		];
		yield 'central agent absent from the page goes first' => [
			[ 'ClaudeBot' => [ 'Disallow' => [ '/' ] ] ],
			"User-agent: *\nDisallow: /a\n",
			"User-agent: ClaudeBot\nDisallow: /\n\nUser-agent: *\nDisallow: /a\n",
		];
		yield 'central agent matches the page ignoring case, central spelling wins' => [
			[ 'claudebot' => [ 'Disallow' => [ '/x' ] ] ],
			"User-agent: ClaudeBot\nDisallow: /a\n",
			"User-agent: claudebot\nDisallow: /x\nDisallow: /a\n",
		];
		yield 'central values: several directives, several values, in order' => [
			[ '*' => [ 'Disallow' => [ '/a', '/b' ], 'Allow' => [ '/c' ] ] ],
			'',
			"User-agent: *\nDisallow: /a\nDisallow: /b\nAllow: /c\n",
		];
		yield 'central directive spelling is kept' => [
			[ '*' => [ 'Crawl-delay' => [ '10' ] ] ],
			'',
			"User-agent: *\nCrawl-delay: 10\n",
		];
		yield 'central empty value' => [
			[ '*' => [ 'Disallow' => [ '' ] ] ],
			'',
			"User-agent: *\nDisallow:\n",
		];
		yield 'central agent with no directives' => [
			[ 'A' => [] ],
			'',
			"User-agent: A\n",
		];

		// Exact duplicates only.
		yield 'central rule repeated by the page is removed once' => [
			[ '*' => [ 'Disallow' => [ '/a/' ] ] ],
			"User-agent: *\nDisallow: /a/\nDisallow: /b/\n",
			"User-agent: *\nDisallow: /a/\nDisallow: /b/\n",
		];
		yield 'duplicate directive name in another case' => [
			[],
			"User-agent: *\nDisallow: /a\ndisallow: /a\n",
			"User-agent: *\nDisallow: /a\n",
		];
		yield 'values are case-sensitive' => [
			[],
			"User-agent: *\nDisallow: /A\nDisallow: /a\n",
			"User-agent: *\nDisallow: /A\nDisallow: /a\n",
		];
		yield 'same value under different directives is not a duplicate' => [
			[],
			"User-agent: *\nAllow: /a\nDisallow: /a\n",
			"User-agent: *\nAllow: /a\nDisallow: /a\n",
		];

		// Comments of a repeated agent line are not lost.
		yield 'comment above a merged agent line moves to its first rule' => [
			[ '*' => [ 'Disallow' => [ '/c' ] ] ],
			"# Page rules\nUser-agent: *\nDisallow: /p\n",
			"User-agent: *\nDisallow: /c\n# Page rules\nDisallow: /p\n",
		];
		yield 'carried comment goes above the first rule only' => [
			[ '*' => [ 'Disallow' => [ '/c' ] ] ],
			"# Page rules\nUser-agent: *\nDisallow: /p\nDisallow: /q\n",
			"User-agent: *\nDisallow: /c\n# Page rules\nDisallow: /p\nDisallow: /q\n",
		];
		yield 'inline comment of a merged agent line moves to its first rule' => [
			[],
			"User-agent: *\nDisallow: /a\n\nUser-agent: * # again\nDisallow: /b\n",
			"User-agent: *\nDisallow: /a\n# again\nDisallow: /b\n",
		];
		yield 'comment is dropped when the merged group adds nothing' => [
			[],
			"User-agent: *\nDisallow: /a\n\n# note\nUser-agent: *\nDisallow: /a\n",
			"User-agent: *\nDisallow: /a\n",
		];

		// Joining agents with the same rules again.
		yield 'identical rules from the centre are joined' => [
			[ 'A' => [ 'Disallow' => [ '/x' ] ], 'B' => [ 'Disallow' => [ '/x' ] ] ],
			'',
			"User-agent: A\nUser-agent: B\nDisallow: /x\n",
		];
		yield 'joined group sits where the first agent was' => [
			[],
			"User-agent: A\nDisallow: /x\n\nUser-agent: C\nDisallow: /z\n"
				. "\nUser-agent: B\nDisallow: /x\n",
			"User-agent: A\nUser-agent: B\nDisallow: /x\n\nUser-agent: C\nDisallow: /z\n",
		];
		yield 'same rules in another order are not joined' => [
			[],
			"User-agent: A\nDisallow: /x\nDisallow: /y\n"
				. "\nUser-agent: B\nDisallow: /y\nDisallow: /x\n",
			"User-agent: A\nDisallow: /x\nDisallow: /y\n"
				. "\nUser-agent: B\nDisallow: /y\nDisallow: /x\n",
		];
		yield 'same rules with different comments are not joined' => [
			[],
			"User-agent: A\nDisallow: /x # one\n\nUser-agent: B\nDisallow: /x\n",
			"User-agent: A\nDisallow: /x # one\n\nUser-agent: B\nDisallow: /x\n",
		];

		// A realistic file: central AI-crawler rules over a page with the wildcard group.
		yield 'realistic file' => [
			[
				'ClaudeBot' => [ 'Disallow' => [ '/' ] ],
				'GPTBot' => [ 'Disallow' => [ '/' ] ],
			],
			"User-agent: *\nDisallow: /w/\n\nUser-agent: GPTBot\nDisallow: /\n"
				. "Sitemap: https://wiki.example/sitemap.xml\n",
			"User-agent: ClaudeBot\nUser-agent: GPTBot\nDisallow: /\n"
				. "\nUser-agent: *\nDisallow: /w/\n"
				. "\nSitemap: https://wiki.example/sitemap.xml\n",
		];
	}
}
