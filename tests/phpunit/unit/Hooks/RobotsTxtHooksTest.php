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

	/**
	 * Cases for onContentHandlerDefaultModelFor().
	 *
	 * Each case is [ namespace of the title, title text without namespace,
	 * the value of $model when the hook is called, what the hook must
	 * return, what $model must hold afterwards ].
	 *
	 * How to read the expected values: MediaWiki calls the hook with $model
	 * passed by reference. A handler that has decided the model writes it
	 * into $model and returns false; MediaWiki then uses it (see
	 * MainSlotRoleHandler::getDefaultModel()). A handler with no opinion
	 * returns true and leaves $model exactly as it found it.
	 *
	 * @return array<string,array{0:int,1:string,2:?string,3:bool,4:?string}>
	 */
	public static function provideDefaultModelCases(): array {
		return [
			'our page, no model chosen yet: we choose text' => [
				NS_MEDIAWIKI, 'Robots.txt', null, false, CONTENT_MODEL_TEXT,
			],
			// Decision 2: the rule for this one title is more specific than a
			// namespace-wide setting or an earlier handler, so it wins.
			'our page, another model already set: we still choose text' => [
				NS_MEDIAWIKI, 'Robots.txt', CONTENT_MODEL_WIKITEXT, false, CONTENT_MODEL_TEXT,
			],
			'another MediaWiki page: no opinion' => [
				NS_MEDIAWIKI, 'Common.css', null, true, null,
			],
			'another MediaWiki page: a model set earlier is kept' => [
				NS_MEDIAWIKI, 'Common.css', CONTENT_MODEL_CSS, true, CONTENT_MODEL_CSS,
			],
			// Right title text, wrong namespace: not the page we manage.
			'same title in the main namespace: no opinion' => [
				NS_MAIN, 'Robots.txt', null, true, null,
			],
			// Decision 1: exact title only, not subpages (which are the
			// translation variants of MediaWiki system messages).
			'subpage of our page: no opinion' => [
				NS_MEDIAWIKI, 'Robots.txt/it', null, true, null,
			],
		];
	}

	/**
	 * The hook must choose the "text" model for MediaWiki:Robots.txt and for
	 * nothing else.
	 *
	 * The mock Title also pins how the handler decides. It asks for the
	 * namespace exactly once, and asks for the title text only when the
	 * namespace is the MediaWiki one. The hook runs whenever MediaWiki needs
	 * the default model of any page, so the check must stay cheap: cheapest
	 * comparison first, no database access.
	 *
	 * @dataProvider provideDefaultModelCases
	 * @param int $namespace Namespace the mock Title reports
	 * @param string $text Title text the mock Title reports
	 * @param string|null $incomingModel Value of $model when the hook is called
	 * @param bool $expectedReturn The value the hook must return
	 * @param string|null $expectedModel What $model must hold after the call
	 */
	public function testOnContentHandlerDefaultModelFor(
		int $namespace,
		string $text,
		?string $incomingModel,
		bool $expectedReturn,
		?string $expectedModel
	): void {
		$title = $this->createMock( Title::class );
		$title->expects( $this->once() )
			->method( 'getNamespace' )
			->willReturn( $namespace );
		$title->expects( $namespace === NS_MEDIAWIKI ? $this->once() : $this->never() )
			->method( 'getText' )
			->willReturn( $text );

		// $model is passed by reference, exactly as MediaWiki passes it, so
		// the handler can write its answer into it.
		$model = $incomingModel;

		$hooks = new RobotsTxtHooks();

		$this->assertSame(
			$expectedReturn,
			$hooks->onContentHandlerDefaultModelFor( $title, $model )
		);
		$this->assertSame( $expectedModel, $model );
	}
}
