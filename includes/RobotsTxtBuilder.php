<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Decides what text is served as robots.txt.
 *
 * @file
 */

namespace MediaWiki\Extension\RobotsTxt;

use MediaWiki\Content\TextContent;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\TitleFactory;

/**
 * Builds the content of robots.txt from the on-wiki page.
 *
 * Why this is separate from the special page: the special page deals with
 * HTTP (headers, output), this class only decides what the text is. Keeping
 * them apart means this logic can be unit tested without a web request, and
 * the merge logic planned for phase 2 has a natural home.
 *
 * Rules, in order (phase 1):
 *  1. If MediaWiki:Robots.txt has content, serve it as written and add
 *     nothing. This includes a page with only comments: writing the page is
 *     a deliberate choice by an administrator, so we do not second-guess it.
 *  2. If the page is missing or empty and generated defaults are enabled,
 *     serve the defaults. (Placeholder for now: an allow-all file.)
 *  3. Otherwise serve an allow-all file, which is also what crawlers assume
 *     when a site has no robots.txt at all.
 */
class RobotsTxtBuilder {

	/**
	 * Title (without namespace) of the on-wiki page that holds the rules.
	 * The page lives in the MediaWiki namespace, so editing it requires the
	 * "editinterface" right.
	 */
	private const PAGE_NAME = 'Robots.txt';

	/**
	 * A valid robots.txt that allows everything. An empty "Disallow" means
	 * "nothing is disallowed".
	 */
	private const ALLOW_ALL = "User-agent: *\nDisallow:\n";

	/**
	 * @param RevisionLookup $revisionLookup Used to read the latest
	 *   revision of the rules page.
	 * @param TitleFactory $titleFactory Used to build the page title.
	 * @param bool $useDefaults Value of $wgRobotsTxtUseDefaults. It is passed
	 *   in, rather than read from the configuration here, so tests can set it
	 *   without a MediaWiki configuration.
	 */
	public function __construct(
		private readonly RevisionLookup $revisionLookup,
		private readonly TitleFactory $titleFactory,
		private readonly bool $useDefaults
	) {
	}

	/**
	 * Build the complete text of robots.txt.
	 *
	 * @return string Always ends with exactly one newline.
	 */
	public function build(): string {
		$pageText = $this->getPageText();
		if ( $pageText !== null ) {
			return $this->normalise( $pageText );
		}

		if ( $this->useDefaults ) {
			return $this->buildDefaults();
		}

		return "# RobotsTxt extension: no rules defined, everything is allowed.\n"
			. self::ALLOW_ALL;
	}

	/**
	 * Read the text of MediaWiki:Robots.txt.
	 *
	 * @return string|null The text, or null when the page does not exist,
	 *   is not text, is hidden from the public, or contains only whitespace.
	 *   In all those cases there is nothing usable to serve, so the caller
	 *   falls back to the defaults.
	 */
	private function getPageText(): ?string {
		$title = $this->titleFactory->makeTitle( NS_MEDIAWIKI, self::PAGE_NAME );
		$revision = $this->revisionLookup->getRevisionByTitle( $title );
		if ( !$revision ) {
			return null;
		}

		// Public audience: if the revision text is hidden (revision
		// deletion), getContent() returns null and we treat the page as
		// missing instead of leaking hidden text.
		$content = $revision->getContent( SlotRecord::MAIN );

		// Both wikitext and the plain "text" content model are TextContent,
		// so the page works before and after the content model is changed.
		// Other models (JSON, CSS...) are not meant for this page.
		if ( !( $content instanceof TextContent ) ) {
			return null;
		}

		$text = $content->getText();
		return trim( $text ) === '' ? null : $text;
	}

	/**
	 * The text served when the page is missing or empty and defaults are on.
	 *
	 * Placeholder in this step: the real generated rules come in the next
	 * step. The first line says where the text comes from, so an
	 * administrator who runs curl on /robots.txt knows why it looks like this.
	 *
	 * @return string
	 */
	private function buildDefaults(): string {
		return "# RobotsTxt extension: generated defaults are not available yet,"
			. " everything is allowed.\n"
			. self::ALLOW_ALL;
	}

	/**
	 * Clean up the page text without changing its meaning.
	 *
	 * Windows line endings are converted to "\n", and trailing blank lines
	 * are replaced by one newline. Blank lines at the end of a file are what
	 * broke the merging of local and central rules at Wikimedia (T17663), so
	 * we make the output predictable.
	 *
	 * @param string $text
	 * @return string
	 */
	private function normalise( string $text ): string {
		$text = str_replace( [ "\r\n", "\r" ], "\n", $text );
		return rtrim( $text ) . "\n";
	}
}