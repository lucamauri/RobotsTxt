<?php
/**
 * Unit tests for the edit-form hook handler of the RobotsTxt extension.
 *
 * @file
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\RobotsTxt\Tests\Unit\Hooks;

use MediaWiki\EditPage\EditPage;
use MediaWiki\Extension\RobotsTxt\Hooks\RobotsTxtHooks;
use MediaWiki\Message\Message;
use MediaWiki\Output\OutputPage;
use MediaWiki\Title\Title;
use MediaWikiUnitTestCase;

/**
 * Tests for {@see RobotsTxtHooks::onEditPage__showEditForm_initial()}.
 *
 * The handler writes a warning box above the edit form of MediaWiki:Robots.txt when
 * PageLinter finds a mistake in the text of the edit box. What counts as a mistake is tested in
 * PageLinterTest; here only the wiring is checked:
 *  - which pages get a warning (the exact title only, namespace compared first);
 *  - that nothing is written when the text is clean;
 *  - that the hook chain is never aborted (the handler must not return false);
 *  - that the box lists the problems and is capped.
 *
 * The edit form, the title and the output page are mocks. The mock Message answers "MSG" to
 * escaped(), so the tests do not depend on the wording of the messages.
 *
 * @group RobotsTxt
 * @covers \MediaWiki\Extension\RobotsTxt\Hooks\RobotsTxtHooks
 * @uses \MediaWiki\Extension\RobotsTxt\PageLinter
 */
class RobotsTxtHooksEditFormTest extends MediaWikiUnitTestCase {

	/**
	 * A text with exactly one mistake (line 2 has no colon).
	 */
	private const BAD_TEXT = "User-agent: *\nDisallow /a\n";

	/**
	 * A text with no mistakes.
	 */
	private const CLEAN_TEXT = "User-agent: *\nDisallow: /a\n";

	/**
	 * Create the edit form for a page.
	 *
	 * The mock title also pins how the handler decides: it asks for the namespace exactly once,
	 * and for the title text only when the namespace is the MediaWiki one (the cheapest check
	 * first, because the hook runs for every edit form on the wiki).
	 *
	 * @param int $namespace
	 * @param string $titleText Title without namespace
	 * @param string $boxText What the edit box holds ($textbox1)
	 * @return EditPage
	 */
	private function makeEditor( int $namespace, string $titleText, string $boxText ): EditPage {
		$title = $this->createMock( Title::class );
		$title->expects( $this->once() )->method( 'getNamespace' )->willReturn( $namespace );
		$title->expects( $namespace === NS_MEDIAWIKI ? $this->once() : $this->never() )
			->method( 'getText' )
			->willReturn( $titleText );

		$editor = $this->createMock( EditPage::class );
		$editor->method( 'getTitle' )->willReturn( $title );
		$editor->textbox1 = $boxText;

		return $editor;
	}

	/**
	 * Create the output page.
	 *
	 * @param mixed $htmlConstraint What addHTML() must receive, or null when it must not be
	 *   called at all
	 * @return OutputPage
	 */
	private function makeOutput( $htmlConstraint ): OutputPage {
		$message = $this->createMock( Message::class );
		$message->method( 'escaped' )->willReturn( 'MSG' );

		$out = $this->createMock( OutputPage::class );
		$out->method( 'msg' )->willReturn( $message );

		if ( $htmlConstraint === null ) {
			$out->expects( $this->never() )->method( 'addHTML' );
		} else {
			$out->expects( $this->once() )->method( 'addHTML' )->with( $htmlConstraint );
		}

		return $out;
	}

	/**
	 * Cases: [ namespace, title text, text of the edit box, whether a warning must be written ].
	 *
	 * @return array<string,array{0:int,1:string,2:string,3:bool}>
	 */
	public static function provideCases(): array {
		return [
			'our page with a mistake: one warning' => [
				NS_MEDIAWIKI, 'Robots.txt', self::BAD_TEXT, true,
			],
			'our page with a clean text: no warning' => [
				NS_MEDIAWIKI, 'Robots.txt', self::CLEAN_TEXT, false,
			],
			'our page, empty edit box (a new page): no warning' => [
				NS_MEDIAWIKI, 'Robots.txt', '', false,
			],
			'another MediaWiki page with a bad text: no warning' => [
				NS_MEDIAWIKI, 'Common.css', self::BAD_TEXT, false,
			],
			'subpage of our page: no warning' => [
				NS_MEDIAWIKI, 'Robots.txt/it', self::BAD_TEXT, false,
			],
			'same title in the main namespace: no warning' => [
				NS_MAIN, 'Robots.txt', self::BAD_TEXT, false,
			],
		];
	}

	/**
	 * A warning is written for our page and a bad text, and for nothing else; the handler
	 * returns true in every case, because false would abort the hook chain.
	 *
	 * @dataProvider provideCases
	 * @param int $namespace
	 * @param string $titleText
	 * @param string $boxText
	 * @param bool $expectWarning
	 */
	public function testWarningOnlyForOurPageAndBadText(
		int $namespace, string $titleText, string $boxText, bool $expectWarning
	): void {
		$editor = $this->makeEditor( $namespace, $titleText, $boxText );
		$out = $this->makeOutput( $expectWarning ? $this->stringContains( 'MSG' ) : null );

		$hooks = new RobotsTxtHooks();

		$this->assertTrue( $hooks->onEditPage__showEditForm_initial( $editor, $out ) );
	}

	/**
	 * The box is a warning box with the introduction and a list holding one item per problem.
	 */
	public function testBoxListsEveryProblem(): void {
		$editor = $this->makeEditor( NS_MEDIAWIKI, 'Robots.txt', "bad one\nUser-agent: *\nbad two\n" );
		$out = $this->makeOutput( $this->callback(
			static function ( string $html ): bool {
				return str_contains( $html, 'cdx-message--warning' )
					&& substr_count( $html, '<li>' ) === 2
					&& str_starts_with( strip_tags( $html ), 'MSG' );
			}
		) );

		$this->assertTrue( ( new RobotsTxtHooks() )->onEditPage__showEditForm_initial( $editor, $out ) );
	}

	/**
	 * More than 20 problems: the first 20 are listed and one more item says how many are not.
	 */
	public function testBoxIsCapped(): void {
		$editor = $this->makeEditor( NS_MEDIAWIKI, 'Robots.txt', str_repeat( "bad line\n", 25 ) );
		$out = $this->makeOutput( $this->callback(
			static function ( string $html ): bool {
				return substr_count( $html, '<li>' ) === 21;
			}
		) );

		$this->assertTrue( ( new RobotsTxtHooks() )->onEditPage__showEditForm_initial( $editor, $out ) );
	}

	/**
	 * Exactly 20 problems: all are listed and there is no "more" item.
	 */
	public function testBoxAtTheCapHasNoMoreItem(): void {
		$editor = $this->makeEditor( NS_MEDIAWIKI, 'Robots.txt', str_repeat( "bad line\n", 20 ) );
		$out = $this->makeOutput( $this->callback(
			static function ( string $html ): bool {
				return substr_count( $html, '<li>' ) === 20;
			}
		) );

		$this->assertTrue( ( new RobotsTxtHooks() )->onEditPage__showEditForm_initial( $editor, $out ) );
	}
}
