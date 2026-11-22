<?php
/**
 * Tests for the central rules validator (step E, batch E-2).
 *
 * @license GPL-2.0-or-later
 * @file
 */

namespace MediaWiki\Extension\RobotsTxt\Tests\Unit;

use MediaWiki\Extension\RobotsTxt\CentralRulesValidator;
use MediaWikiUnitTestCase;

/**
 * Every case gives a raw setting and the exact result the validator must return. The expected
 * values are derived by hand from the rules in the class documentation of
 * CentralRulesValidator (decision 31), not by running the code.
 *
 * @group RobotsTxt
 * @covers \MediaWiki\Extension\RobotsTxt\CentralRulesValidator
 */
class CentralRulesValidatorTest extends MediaWikiUnitTestCase {

	/**
	 * Build the expected result of validateRules().
	 *
	 * @param array $rules
	 * @param int $skipped
	 * @return array
	 */
	private static function rules( array $rules, int $skipped = 0 ): array {
		return [ 'rules' => $rules, 'skipped' => $skipped ];
	}

	/**
	 * Build the expected result of validateInherit().
	 *
	 * @param bool|string[] $inherit
	 * @param int $skipped
	 * @return array
	 */
	private static function inherit( $inherit, int $skipped = 0 ): array {
		return [ 'inherit' => $inherit, 'skipped' => $skipped ];
	}

	/**
	 * @dataProvider provideValidateRules
	 * @param mixed $setting
	 * @param array $expected
	 */
	public function testValidateRules( $setting, array $expected ): void {
		$this->assertSame( $expected, CentralRulesValidator::validateRules( $setting ) );
	}

	/**
	 * @return iterable
	 */
	public static function provideValidateRules(): iterable {
		// What is accepted.
		yield 'empty array' => [ [], self::rules( [] ) ];

		// The example of decision 21; the number becomes a string.
		yield 'the documented example' => [
			[
				'*' => [
					'Disallow' => [ '/dt/', '/dt/*action=edit' ],
					'Allow' => [ '/dt/load.php' ],
				],
				'GPTBot' => [
					'Disallow' => [ '/dt/' ],
					'Crawl-delay' => 10,
				],
			],
			self::rules( [
				'*' => [
					'Disallow' => [ '/dt/', '/dt/*action=edit' ],
					'Allow' => [ '/dt/load.php' ],
				],
				'GPTBot' => [
					'Disallow' => [ '/dt/' ],
					'Crawl-delay' => [ '10' ],
				],
			] ),
		];

		yield 'a single string value becomes a list of one' => [
			[ '*' => [ 'Disallow' => '/a' ] ],
			self::rules( [ '*' => [ 'Disallow' => [ '/a' ] ] ] ),
		];

		yield 'numbers become strings' => [
			[ '*' => [ 'Crawl-delay' => 2.5, 'Foo' => [ 7 ] ] ],
			self::rules( [ '*' => [ 'Crawl-delay' => [ '2.5' ], 'Foo' => [ '7' ] ] ] ),
		];

		yield 'an empty string is a valid value' => [
			[ '*' => [ 'Disallow' => '' ] ],
			self::rules( [ '*' => [ 'Disallow' => [ '' ] ] ] ),
		];

		yield 'an empty list contributes nothing and is not counted' => [
			[ '*' => [ 'Disallow' => [], 'Allow' => [ '/a' ] ] ],
			self::rules( [ '*' => [ 'Allow' => [ '/a' ] ] ] ),
		];

		yield 'an agent with no directives is kept' => [
			[ 'GPTBot' => [] ],
			self::rules( [ 'GPTBot' => [] ] ),
		];

		yield 'spaces inside a value are allowed' => [
			[ '*' => [ 'Disallow' => '/a b' ] ],
			self::rules( [ '*' => [ 'Disallow' => [ '/a b' ] ] ] ),
		];

		yield 'keys of a list of values are ignored' => [
			[ '*' => [ 'Disallow' => [ 5 => '/a', 9 => '/b' ] ] ],
			self::rules( [ '*' => [ 'Disallow' => [ '/a', '/b' ] ] ] ),
		];

		yield 'spelling is kept and case variants stay separate keys' => [
			[
				'GPTBot' => [ 'disallow' => [ '/a' ] ],
				'gptbot' => [ 'Disallow' => [ '/b' ] ],
			],
			self::rules( [
				'GPTBot' => [ 'disallow' => [ '/a' ] ],
				'gptbot' => [ 'Disallow' => [ '/b' ] ],
			] ),
		];

		// The whole setting.
		yield 'the setting is a string' => [ 'text', self::rules( [], 1 ) ];
		yield 'the setting is an integer' => [ 5, self::rules( [], 1 ) ];
		yield 'the setting is true' => [ true, self::rules( [], 1 ) ];
		yield 'the setting is false' => [ false, self::rules( [], 1 ) ];
		yield 'the setting is null' => [ null, self::rules( [], 1 ) ];

		// Agent keys and values.
		yield 'a list instead of a map (integer agent key)' => [
			[ [ 'Disallow' => [ '/a' ] ] ],
			self::rules( [], 1 ),
		];

		yield 'an empty agent key' => [
			[ '' => [ 'Disallow' => [ '/a' ] ] ],
			self::rules( [], 1 ),
		];

		yield 'an agent key with a space' => [
			[ 'Google bot' => [ 'Disallow' => [ '/a' ] ] ],
			self::rules( [], 1 ),
		];

		yield 'an agent key with a line break' => [
			[ "bad\nbot" => [ 'Disallow' => [ '/a' ] ] ],
			self::rules( [], 1 ),
		];

		yield 'an agent key with a hash' => [
			[ 'bad#bot' => [ 'Disallow' => [ '/a' ] ] ],
			self::rules( [], 1 ),
		];

		yield 'an agent whose value is not an array' => [
			[ 'GPTBot' => 'Disallow', '*' => [ 'Disallow' => [ '/a' ] ] ],
			self::rules( [ '*' => [ 'Disallow' => [ '/a' ] ] ], 1 ),
		];

		// Directive names.
		yield 'a plain list of paths instead of a map counts each element' => [
			[ '*' => [ '/a', '/b' ] ],
			self::rules( [ '*' => [] ], 2 ),
		];

		yield 'invalid directive names are skipped and counted' => [
			[ '*' => [
				'' => [ '/a' ],
				'Dis allow' => [ '/a' ],
				'Dis:allow' => [ '/a' ],
				'Dis#allow' => [ '/a' ],
				"Dis\nallow" => [ '/a' ],
				'Allow' => [ '/b' ],
			] ],
			self::rules( [ '*' => [ 'Allow' => [ '/b' ] ] ], 5 ),
		];

		yield 'User-agent and Sitemap are not accepted inside the array' => [
			[ '*' => [
				'User-agent' => [ 'x' ],
				'SITEMAP' => [ 'https://e.org/s.xml' ],
				'sitemap' => 'y',
				'Disallow' => [ '/a' ],
			] ],
			self::rules( [ '*' => [ 'Disallow' => [ '/a' ] ] ], 3 ),
		];

		// Values.
		yield 'boolean and null values' => [
			[ '*' => [ 'A' => true, 'B' => false, 'C' => null, 'Disallow' => [ '/a' ] ] ],
			self::rules( [ '*' => [ 'Disallow' => [ '/a' ] ] ], 3 ),
		];

		yield 'a line break in a value would inject directives' => [
			[ '*' => [ 'Disallow' => "/a\nUser-agent: x" ] ],
			self::rules( [ '*' => [] ], 1 ),
		];

		yield 'a carriage return, a tab and a NUL in a value' => [
			[ '*' => [ 'Disallow' => [ "/a\r", "/b\t", "/c\0", '/ok' ] ] ],
			self::rules( [ '*' => [ 'Disallow' => [ '/ok' ] ] ], 3 ),
		];

		yield 'a hash in a value' => [
			[ '*' => [ 'Disallow' => [ '/a#b', '/ok' ] ] ],
			self::rules( [ '*' => [ 'Disallow' => [ '/ok' ] ] ], 1 ),
		];

		yield 'bad elements inside a list are skipped one by one' => [
			[ '*' => [ 'Disallow' => [ '/a', true, '/b', [ 'x' ], "bad\n" ] ] ],
			self::rules( [ '*' => [ 'Disallow' => [ '/a', '/b' ] ] ], 3 ),
		];

		yield 'non-finite numbers' => [
			[ '*' => [ 'Crawl-delay' => INF, 'Disallow' => [ '/a', NAN ] ] ],
			self::rules( [ '*' => [ 'Disallow' => [ '/a' ] ] ], 2 ),
		];

		// Several problems at once: counted across agents, the good parts keep their order.
		yield 'invalid parts are counted across agents and valid parts keep their order' => [
			[
				'*' => [ 'Disallow' => [ '/a' ], 'Bad name' => [ '/x' ] ],
				5 => [ 'Disallow' => [ '/y' ] ],
				'GPTBot' => [ 'Disallow' => [ '/b' ] ],
			],
			self::rules( [
				'*' => [ 'Disallow' => [ '/a' ] ],
				'GPTBot' => [ 'Disallow' => [ '/b' ] ],
			], 2 ),
		];
	}

	/**
	 * @dataProvider provideValidateInherit
	 * @param mixed $setting
	 * @param array $expected
	 */
	public function testValidateInherit( $setting, array $expected ): void {
		$this->assertSame( $expected, CentralRulesValidator::validateInherit( $setting ) );
	}

	/**
	 * @return iterable
	 */
	public static function provideValidateInherit(): iterable {
		yield 'false' => [ false, self::inherit( false ) ];
		yield 'true' => [ true, self::inherit( true ) ];
		yield 'an empty list means nobody inherits' => [ [], self::inherit( false ) ];

		yield 'a list of agent names' => [
			[ 'GPTBot', 'ClaudeBot' ],
			self::inherit( [ 'GPTBot', 'ClaudeBot' ] ),
		];

		yield 'invalid names are skipped and counted' => [
			[ 'GPTBot', '', 'bad bot', 5, null, "x\n", 'a#b' ],
			self::inherit( [ 'GPTBot' ], 6 ),
		];

		yield 'a list with only invalid names is false' => [
			[ '', 'bad bot' ],
			self::inherit( false, 2 ),
		];

		yield 'null is a wrong type' => [ null, self::inherit( false, 1 ) ];
		yield 'a single string is a wrong type' => [ 'GPTBot', self::inherit( false, 1 ) ];
		yield 'an integer is a wrong type' => [ 0, self::inherit( false, 1 ) ];
		yield 'a float is a wrong type' => [ 1.5, self::inherit( false, 1 ) ];

		yield 'duplicates and case variants are kept as written' => [
			[ 'GPTBot', 'gptbot', 'GPTBot' ],
			self::inherit( [ 'GPTBot', 'gptbot', 'GPTBot' ] ),
		];

		yield 'keys of the list are ignored' => [
			[ 3 => 'A', 7 => 'B' ],
			self::inherit( [ 'A', 'B' ] ),
		];
	}

	/**
	 * @dataProvider provideWarningLine
	 * @param string $settingName
	 * @param int $skipped
	 * @param string $expected
	 */
	public function testWarningLine( string $settingName, int $skipped, string $expected ): void {
		$this->assertSame( $expected, CentralRulesValidator::warningLine( $settingName, $skipped ) );
	}

	/**
	 * @return iterable
	 */
	public static function provideWarningLine(): iterable {
		yield 'nothing skipped gives no line' => [ '$wgRobotsTxtCentralRules', 0, '' ];
		yield 'a negative count gives no line' => [ '$wgRobotsTxtCentralRules', -1, '' ];

		yield 'central rules' => [
			'$wgRobotsTxtCentralRules',
			3,
			'# RobotsTxt: invalid $wgRobotsTxtCentralRules entries ignored: 3',
		];

		yield 'inheritance setting' => [
			'$wgRobotsTxtInheritWildcard',
			1,
			'# RobotsTxt: invalid $wgRobotsTxtInheritWildcard entries ignored: 1',
		];
	}
}
