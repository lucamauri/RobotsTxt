<?php
/**
 * Unit tests for the hook handlers of the RobotsTxt extension.
 *
 * @file
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\RobotsTxt\Tests\Unit\Hooks;

use MediaWiki\Extension\RobotsTxt\Hooks\RobotsTxtHooks;
use MediaWiki\Output\OutputPage;
use MediaWiki\Request\WebRequest;
use MediaWiki\Title\Title;
use MediaWikiUnitTestCase;

/**
 * Tests for {@see RobotsTxtHooks}.
 *
 * This is a unit test: it extends MediaWikiUnitTestCase, so it runs without a
 * wiki, a database or any MediaWiki service. Everything MediaWiki would
 * normally hand to the hook (the request, the title, the output page) is
 * replaced by a "mock": a stand-in object created by PHPUnit that answers
 * method calls with values we choose. That makes each test fast and means a
 * failure can only come from the code under test.
 *
 * @group RobotsTxt
 * @covers \MediaWiki\Extension\RobotsTxt\Hooks\RobotsTxtHooks
 */
class RobotsTxtHooksTest extends MediaWikiUnitTestCase {

	/**
	 * Cases for onTestCanonicalRedirect().
	 *
	 * Each case is [ what Title::isSpecial( 'RobotsTxt' ) answers, what the
	 * hook must return ]. The hook returns false to abort MediaWiki's
	 * redirect, which is wanted only for Special:RobotsTxt itself, and true
	 * for every other page so that their normal redirect behaviour is kept.
	 *
	 * @return array<string,array{0:bool,1:bool}>
	 */
	public static function provideCanonicalRedirectCases(): array {
		return [
			'our special page: abort the redirect' => [ true, false ],
			'any other page: let MediaWiki continue' => [ false, true ],
		];
	}

	/**
	 * The hook must abort the redirect only for Special:RobotsTxt.
	 *
	 * The mock Title also checks that the handler asks the right question,
	 * namely isSpecial() with the canonical English name 'RobotsTxt'. That
	 * name is what makes the check work on wikis of every language, because
	 * MediaWiki resolves local aliases such as "Speciale:RobotsTxt" to it.
	 *
	 * @dataProvider provideCanonicalRedirectCases
	 * @param bool $isRobotsTxtPage What the mock Title answers to isSpecial()
	 * @param bool $expected The value the hook must return
	 */
	public function testOnTestCanonicalRedirect( bool $isRobotsTxtPage, bool $expected ): void {
		$title = $this->createMock( Title::class );
		$title->expects( $this->once() )
			->method( 'isSpecial' )
			->with( 'RobotsTxt' )
			->willReturn( $isRobotsTxtPage );

		// The handler ignores the request and the output page, so plain mocks
		// with no configured behaviour are enough.
		$request = $this->createMock( WebRequest::class );
		$output = $this->createMock( OutputPage::class );

		$hooks = new RobotsTxtHooks();

		$this->assertSame(
			$expected,
			$hooks->onTestCanonicalRedirect( $request, $title, $output )
		);
	}
}
