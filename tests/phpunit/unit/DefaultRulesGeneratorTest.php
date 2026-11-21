<?php
/**
 * Unit tests for DefaultRulesGenerator.
 *
 * @file
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\RobotsTxt\Tests\Unit;

use MediaWiki\Config\Config;
use MediaWiki\Extension\RobotsTxt\DefaultRulesGenerator;
use MediaWiki\Language\Language;
use MediaWiki\Title\NamespaceInfo;
use MediaWikiUnitTestCase;

/**
 * Tests for {@see DefaultRulesGenerator}.
 *
 * The generator turns four plain values (the paths of index.php and load.php,
 * the article path template and the names of the Special namespace) into the
 * text of a robots.txt. Because it needs no wiki, these are pure unit tests
 * with exact expected output.
 *
 * Two kinds of tests are used together:
 *  - one "golden" test that spells out the complete expected file, so that
 *    any accidental change to the generated text makes it fail;
 *  - small targeted tests, one per edge case, that check only the lines they
 *    are about, so a failure points straight at what broke.
 *
 * @group RobotsTxt
 * @covers \MediaWiki\Extension\RobotsTxt\DefaultRulesGenerator
 */
class DefaultRulesGeneratorTest extends MediaWikiUnitTestCase {

	/**
	 * The three Disallow lines that are always present, whatever the wiki's
	 * settings. Used to tell them apart from the special-page rules.
	 */
	private const FIXED_DISALLOW_LINES = [
		'Disallow: /*action=edit',
		'Disallow: /*action=history',
		'Disallow: /*returnto=',
	];

	/**
	 * Build a generator from plain values and return its output.
	 *
	 * @param string $script Value of $wgScript
	 * @param string $articlePath Value of $wgArticlePath
	 * @param string $loadScript Value of $wgLoadScript
	 * @param string[] $prefixes Names of the Special namespace
	 * @return string The generated robots.txt
	 */
	private function generate(
		string $script,
		string $articlePath,
		string $loadScript,
		array $prefixes
	): string {
		return ( new DefaultRulesGenerator( $script, $articlePath, $loadScript, $prefixes ) )
			->generate();
	}

	/**
	 * Extract only the special-page rules from a generated file.
	 *
	 * These are the "Disallow" lines that are not one of the three fixed
	 * ones. Keeping them separate lets the edge-case tests ignore the rest of
	 * the file, which the golden test already covers in full.
	 *
	 * @param string $output A generated robots.txt
	 * @return string[] The special-page "Disallow" lines, in output order
	 */
	private function getSpecialPageRules( string $output ): array {
		$rules = [];
		foreach ( explode( "\n", $output ) as $line ) {
			if ( str_starts_with( $line, 'Disallow: ' )
				&& !in_array( $line, self::FIXED_DISALLOW_LINES, true )
			) {
				$rules[] = $line;
			}
		}
		return $rules;
	}

	/**
	 * Golden test: the complete output for a WikiTrek-like configuration.
	 *
	 * An Italian wiki installed under /wt/, with short article URLs under
	 * /wiki/. Every line is spelled out on purpose. If this test fails after
	 * an intended change, update the expectation; if the change was not
	 * intended, it has just caught a regression in the generated file.
	 */
	public function testGoldenOutput(): void {
		$expected = implode( "\n", [
			'# RobotsTxt extension: generated defaults.'
				. ' Create MediaWiki:Robots.txt to replace them.',
			'User-agent: *',
			'Allow: /wt/load.php',
			'Disallow: /*action=edit',
			'Disallow: /*action=history',
			'Disallow: /*returnto=',
			'Disallow: /wt/index.php?title=Special:',
			'Disallow: /wt/index.php?title=Special%3A',
			'Disallow: /wt/index.php?title=Speciale:',
			'Disallow: /wt/index.php?title=Speciale%3A',
			'Disallow: /wiki/Special:',
			'Disallow: /wiki/Special%3A',
			'Disallow: /wiki/Speciale:',
			'Disallow: /wiki/Speciale%3A',
		] ) . "\n";

		$this->assertSame(
			$expected,
			$this->generate( '/wt/index.php', '/wiki/$1', '/wt/load.php', [ 'Special', 'Speciale' ] )
		);
	}

	/**
	 * The file layout rules that every output must follow.
	 *
	 * - It starts with a comment that tells the administrator where the text
	 *   comes from.
	 * - It ends with exactly one newline.
	 * - The Allow line for load.php comes before every Disallow line: very
	 *   simple crawlers use the first matching rule, so the order matters.
	 */
	public function testFileLayout(): void {
		$output = $this->generate( '/index.php', '/wiki/$1', '/load.php', [ 'Special' ] );

		$this->assertStringStartsWith( '# RobotsTxt extension: generated defaults.', $output );
		$this->assertSame( rtrim( $output ) . "\n", $output, 'Exactly one trailing newline' );

		$lines = explode( "\n", $output );
		$this->assertContains( 'User-agent: *', $lines );

		$allowIndex = array_search( 'Allow: /load.php', $lines, true );
		$this->assertNotFalse( $allowIndex, 'The Allow line for load.php is present' );

		$disallowIndexes = array_keys( array_filter(
			$lines,
			static fn ( string $line ): bool => str_starts_with( $line, 'Disallow:' )
		) );
		$this->assertNotEmpty( $disallowIndexes );
		$this->assertLessThan( min( $disallowIndexes ), $allowIndex, 'Allow comes first' );
	}

	/**
	 * The load.php path is taken from the setting, not hardcoded.
	 */
	public function testLoadScriptIsUsedVerbatim(): void {
		$output = $this->generate( '/w/index.php', '/wiki/$1', '/w/load.php', [ 'Special' ] );

		$this->assertContains( 'Allow: /w/load.php', explode( "\n", $output ) );
	}

	/**
	 * Cases for the special-page rules.
	 *
	 * Each case is [ $wgScript, $wgArticlePath, names of the Special
	 * namespace, expected special-page "Disallow" lines in order ]. Per name
	 * the generator writes the plain colon first and the percent-encoded
	 * colon second, for the long form (index.php?title=) before the short
	 * form taken from the article path.
	 *
	 * @return array<string,array{0:string,1:string,2:string[],3:string[]}>
	 */
	public static function provideSpecialPageRuleCases(): array {
		$englishWiki = [
			'Disallow: /index.php?title=Special:',
			'Disallow: /index.php?title=Special%3A',
			'Disallow: /wiki/Special:',
			'Disallow: /wiki/Special%3A',
		];

		return [
			'english wiki, long and short forms' => [
				'/index.php', '/wiki/$1', [ 'Special' ], $englishWiki,
			],
			'article path equals the long form: duplicates removed' => [
				'/index.php', '/index.php?title=$1', [ 'Special' ], [
					'Disallow: /index.php?title=Special:',
					'Disallow: /index.php?title=Special%3A',
				],
			],
			'article path as a full URL is reduced to a path' => [
				'/index.php', 'https://example.org/wiki/$1', [ 'Special' ], $englishWiki,
			],
			'article path as a protocol-relative URL is reduced to a path' => [
				'/index.php', '//example.org/wiki/$1', [ 'Special' ], $englishWiki,
			],
			'article path without $1 gives only the long form' => [
				'/index.php', '/wiki/', [ 'Special' ], [
					'Disallow: /index.php?title=Special:',
					'Disallow: /index.php?title=Special%3A',
				],
			],
			'short URLs at the root of the site' => [
				'/index.php', '/$1', [ 'Special' ], [
					'Disallow: /index.php?title=Special:',
					'Disallow: /index.php?title=Special%3A',
					'Disallow: /Special:',
					'Disallow: /Special%3A',
				],
			],
			'non-ASCII name is percent-encoded as UTF-8' => [
				'/index.php', '/wiki/$1', [ 'Spécial' ], [
					'Disallow: /index.php?title=Sp%C3%A9cial:',
					'Disallow: /index.php?title=Sp%C3%A9cial%3A',
					'Disallow: /wiki/Sp%C3%A9cial:',
					'Disallow: /wiki/Sp%C3%A9cial%3A',
				],
			],
			'spaces in a name become underscores' => [
				// Not a real namespace name: it only exercises the conversion.
				'/index.php', '/wiki/$1', [ 'Special Pages' ], [
					'Disallow: /index.php?title=Special_Pages:',
					'Disallow: /index.php?title=Special_Pages%3A',
					'Disallow: /wiki/Special_Pages:',
					'Disallow: /wiki/Special_Pages%3A',
				],
			],
			'empty and repeated names are ignored' => [
				'/index.php', '/wiki/$1', [ 'Special', '', 'Special' ], $englishWiki,
			],
		];
	}

	/**
	 * Check the special-page rules for one configuration.
	 *
	 * @dataProvider provideSpecialPageRuleCases
	 * @param string $script Value of $wgScript
	 * @param string $articlePath Value of $wgArticlePath
	 * @param string[] $prefixes Names of the Special namespace
	 * @param string[] $expected Expected special-page "Disallow" lines, in order
	 */
	public function testSpecialPageRules(
		string $script,
		string $articlePath,
		array $prefixes,
		array $expected
	): void {
		$output = $this->generate( $script, $articlePath, '/load.php', $prefixes );

		$this->assertSame( $expected, $this->getSpecialPageRules( $output ) );
	}

	/**
	 * newFromWiki() reads the wiki's settings and collects every spelling of
	 * the Special namespace: the canonical name, the content-language name,
	 * and any extra aliases that point at the Special namespace. Aliases of
	 * other namespaces must be ignored.
	 *
	 * The collaborators are mocks. The expected text is the output of a
	 * generator built directly from the values the mocks provide, so this
	 * test checks the collecting logic and nothing else.
	 */
	public function testNewFromWikiCollectsSpecialNamespaceNames(): void {
		$config = $this->createMock( Config::class );
		$config->method( 'get' )->willReturnMap( [
			[ 'Script', '/wt/index.php' ],
			[ 'ArticlePath', '/wiki/$1' ],
			[ 'LoadScript', '/wt/load.php' ],
		] );

		$language = $this->createMock( Language::class );
		$language->method( 'getNsText' )->with( NS_SPECIAL )->willReturn( 'Speciale' );
		$language->method( 'getNamespaceAliases' )->willReturn( [
			'SpecialeAlias' => NS_SPECIAL,
			'UtenteAlias' => NS_USER,
		] );

		$namespaceInfo = $this->createMock( NamespaceInfo::class );
		$namespaceInfo->method( 'getCanonicalName' )->with( NS_SPECIAL )->willReturn( 'Special' );

		$output = DefaultRulesGenerator::newFromWiki( $config, $language, $namespaceInfo )->generate();

		$this->assertSame(
			$this->generate(
				'/wt/index.php',
				'/wiki/$1',
				'/wt/load.php',
				[ 'Special', 'Speciale', 'SpecialeAlias' ]
			),
			$output
		);
		$this->assertStringNotContainsString( 'UtenteAlias', $output );
	}
}