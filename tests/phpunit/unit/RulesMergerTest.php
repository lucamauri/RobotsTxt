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
 * Two test methods share the class. testMerge() is batch E-3a (split, merge by agent, duplicates,
 * re-join, render) and always passes `false` for inheritance. testMergeWithOptions() is batch
 * E-3b: wildcard inheritance, the empty `Disallow:` rule and the `Crawl-delay` warning.
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
	 * @dataProvider provideMergeWithOptions
	 * @param array $central
	 * @param string $pageText
	 * @param bool|string[] $inherit
	 * @param string $expected
	 */
	public function testMergeWithOptions(
		array $central, string $pageText, $inherit, string $expected
	): void {
		$this->assertSame(
			$expected,
			RulesMerger::merge( $central, RobotsTxtParser::parse( $pageText ), $inherit )
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

	/**
	 * Batch E-3b. The pipeline is: merge by agent, inherit from `*`, drop the empty `Disallow:`,
	 * join identical agents, add the `Crawl-delay` warning.
	 *
	 * @return iterable
	 */
	public static function provideMergeWithOptions(): iterable {
		$wild = "User-agent: *\nDisallow: /w/\nAllow: /w/load.php\n";

		// Inheritance: who inherits.
		yield 'inherit true: a named agent gets the wildcard rules first' => [
			[],
			$wild . "\nUser-agent: GPTBot\nDisallow: /g/\n",
			true,
			$wild . "\nUser-agent: GPTBot\nDisallow: /w/\nAllow: /w/load.php\nDisallow: /g/\n",
		];
		yield 'inherit false: nothing is copied' => [
			[],
			$wild . "\nUser-agent: GPTBot\nDisallow: /g/\n",
			false,
			$wild . "\nUser-agent: GPTBot\nDisallow: /g/\n",
		];
		yield 'inherit list: only the listed agents, ignoring case' => [
			[],
			$wild . "\nUser-agent: GPTBot\nDisallow: /g/\n"
				. "\nUser-agent: ClaudeBot\nDisallow: /c/\n",
			[ 'GPTBOT' ],
			$wild . "\nUser-agent: GPTBot\nDisallow: /w/\nAllow: /w/load.php\nDisallow: /g/\n"
				. "\nUser-agent: ClaudeBot\nDisallow: /c/\n",
		];
		yield 'inherit list: an agent that does not exist is not created' => [
			[],
			$wild,
			[ 'Nobody' ],
			$wild,
		];
		yield 'inherit list: the wildcard does not inherit from itself' => [
			[],
			$wild,
			[ '*' ],
			$wild,
		];
		yield 'inherit true: the wildcard alone is unchanged' => [
			[],
			$wild,
			true,
			$wild,
		];
		yield 'inherit true: no wildcard group, nothing to copy' => [
			[],
			"User-agent: GPTBot\nDisallow: /g/\n",
			true,
			"User-agent: GPTBot\nDisallow: /g/\n",
		];

		// Inheritance: what is copied and how.
		yield 'only Allow and Disallow are copied' => [
			[],
			"User-agent: *\nCrawl-delay: 5\nDisallow: /w/\njunk\n"
				. "\nUser-agent: GPTBot\nDisallow: /g/\n",
			true,
			"User-agent: *\nCrawl-delay: 5\nDisallow: /w/\njunk\n"
				. "\nUser-agent: GPTBot\nDisallow: /w/\nDisallow: /g/\n",
		];
		yield 'a rule the agent already has is not repeated, the copy comes first' => [
			[],
			"User-agent: *\nDisallow: /w/\n\nUser-agent: GPTBot\nDisallow: /g/\nDisallow: /w/\n",
			true,
			"User-agent: *\nDisallow: /w/\n\nUser-agent: GPTBot\nDisallow: /w/\nDisallow: /g/\n",
		];
		yield 'comments are not copied with the rules' => [
			[],
			"User-agent: *\n# keep out\nDisallow: /w/ # wiki\n"
				. "\nUser-agent: GPTBot\nDisallow: /g/\n",
			true,
			"User-agent: *\n# keep out\nDisallow: /w/ # wiki\n"
				. "\nUser-agent: GPTBot\nDisallow: /w/\nDisallow: /g/\n",
		];
		yield 'central wildcard rules are inherited too' => [
			[ '*' => [ 'Disallow' => [ '/admin/' ] ] ],
			"User-agent: GPTBot\nDisallow: /g/\n",
			true,
			"User-agent: *\nDisallow: /admin/\n"
				. "\nUser-agent: GPTBot\nDisallow: /admin/\nDisallow: /g/\n",
		];
		yield 'an agent that inherits everything joins the wildcard group' => [
			[],
			"User-agent: *\nDisallow: /w/\n\nUser-agent: A\nUser-agent: B\n",
			true,
			"User-agent: *\nUser-agent: A\nUser-agent: B\nDisallow: /w/\n",
		];

		// The empty Disallow.
		yield 'empty Disallow is dropped when another rule exists' => [
			[],
			"User-agent: *\nDisallow:\nDisallow: /a/\n",
			false,
			"User-agent: *\nDisallow: /a/\n",
		];
		yield 'empty Disallow is dropped whatever its spelling, with its comment' => [
			[],
			"User-agent: *\ndisallow: # open\nDisallow: /a\n",
			false,
			"User-agent: *\nDisallow: /a\n",
		];
		yield 'empty Disallow is dropped next to an Allow' => [
			[],
			"User-agent: *\nDisallow:\nAllow: /a\n",
			false,
			"User-agent: *\nAllow: /a\n",
		];
		yield 'empty Disallow alone is kept' => [
			[],
			"User-agent: *\nDisallow:\n",
			false,
			"User-agent: *\nDisallow:\n",
		];
		yield 'other directives do not count as rules' => [
			[],
			"User-agent: *\nDisallow:\nCrawl-delay: 5\n",
			false,
			"User-agent: *\nDisallow:\nCrawl-delay: 5\n",
		];
		yield 'an empty Allow is never dropped' => [
			[],
			"User-agent: *\nAllow:\nDisallow: /a\n",
			false,
			"User-agent: *\nAllow:\nDisallow: /a\n",
		];
		yield 'the rule works group by group' => [
			[],
			"User-agent: A\nDisallow:\n\nUser-agent: B\nDisallow: /b\n",
			false,
			"User-agent: A\nDisallow:\n\nUser-agent: B\nDisallow: /b\n",
		];
		yield 'an empty central Disallow gives way to a page rule' => [
			[ '*' => [ 'Disallow' => [ '' ] ] ],
			"User-agent: *\nDisallow: /a/\n",
			false,
			"User-agent: *\nDisallow: /a/\n",
		];
		yield 'an empty wildcard Disallow is inherited like any other Disallow' => [
			[],
			"User-agent: *\nDisallow:\n\nUser-agent: GPTBot\nCrawl-delay: 3\n",
			true,
			"User-agent: *\nDisallow:\n\nUser-agent: GPTBot\nDisallow:\nCrawl-delay: 3\n",
		];
		yield 'the empty Disallow is dropped after inheriting, not before' => [
			[],
			"User-agent: *\nDisallow:\n\nUser-agent: GPTBot\nAllow: /a\n",
			true,
			"User-agent: *\nDisallow:\n\nUser-agent: GPTBot\nAllow: /a\n",
		];

		// The Crawl-delay warning.
		yield 'two Crawl-delay values give a warning, both lines kept, central first' => [
			[ '*' => [ 'Crawl-delay' => [ '10' ] ] ],
			"User-agent: *\nCrawl-delay: 5\n",
			false,
			"User-agent: *\n# RobotsTxt: conflicting Crawl-delay values in this group (10, 5)\n"
				. "Crawl-delay: 10\nCrawl-delay: 5\n",
		];
		yield 'three values are all listed, in order' => [
			[],
			"User-agent: *\nCrawl-delay: 10\nCrawl-delay: 5\nCrawl-delay: 2\n",
			false,
			"User-agent: *\n# RobotsTxt: conflicting Crawl-delay values in this group (10, 5, 2)\n"
				. "Crawl-delay: 10\nCrawl-delay: 5\nCrawl-delay: 2\n",
		];
		yield 'the same value twice is only a duplicate, no warning' => [
			[],
			"User-agent: *\nCrawl-delay: 5\nCrawl-delay: 5\n",
			false,
			"User-agent: *\nCrawl-delay: 5\n",
		];
		yield 'one value gives no warning' => [
			[],
			"User-agent: *\nCrawl-delay: 5\n",
			false,
			"User-agent: *\nCrawl-delay: 5\n",
		];
		yield 'different values in different groups give no warning' => [
			[],
			"User-agent: A\nCrawl-delay: 5\n\nUser-agent: B\nCrawl-delay: 10\n",
			false,
			"User-agent: A\nCrawl-delay: 5\n\nUser-agent: B\nCrawl-delay: 10\n",
		];
		yield 'the directive name is matched ignoring case, spelling kept' => [
			[],
			"User-agent: *\ncrawl-delay: 5\nCRAWL-DELAY: 7\n",
			false,
			"User-agent: *\n# RobotsTxt: conflicting Crawl-delay values in this group (5, 7)\n"
				. "crawl-delay: 5\nCRAWL-DELAY: 7\n",
		];
		yield 'a joined group gets one warning, after all its agent lines' => [
			[
				'A' => [ 'Crawl-delay' => [ '1', '2' ] ],
				'B' => [ 'Crawl-delay' => [ '1', '2' ] ],
			],
			'',
			false,
			"User-agent: A\nUser-agent: B\n"
				. "# RobotsTxt: conflicting Crawl-delay values in this group (1, 2)\n"
				. "Crawl-delay: 1\nCrawl-delay: 2\n",
		];

		// All three together.
		yield 'realistic file with central rules, inheritance and an empty Disallow' => [
			[
				'ClaudeBot' => [ 'Disallow' => [ '/' ] ],
				'GPTBot' => [ 'Disallow' => [ '/' ] ],
			],
			$wild . "\nUser-agent: Googlebot\nDisallow:\n",
			true,
			"User-agent: ClaudeBot\nUser-agent: GPTBot\n"
				. "Disallow: /w/\nAllow: /w/load.php\nDisallow: /\n"
				. "\nUser-agent: *\nUser-agent: Googlebot\nDisallow: /w/\nAllow: /w/load.php\n",
		];
	}
}
