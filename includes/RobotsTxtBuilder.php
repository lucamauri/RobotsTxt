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
 * them apart means this logic can be unit tested without a web request.
 *
 * Rules, in order:
 *  1. If MediaWiki:Robots.txt has content, serve it and add nothing of our
 *     own rules. This includes a page with only comments: writing the page is
 *     a deliberate choice by an administrator, so we do not second-guess it.
 *  2. If the page is missing or empty and generated defaults are enabled,
 *     serve the defaults made by DefaultRulesGenerator.
 *  3. Otherwise serve an allow-all file, which is also what crawlers assume
 *     when a site has no robots.txt at all.
 *
 * Then two things are applied to whichever of the three was chosen:
 *  - The central rules ($wgRobotsTxtCentralRules) and the wildcard
 *    inheritance ($wgRobotsTxtInheritWildcard), but only when one of them is
 *    in use. See buildRules() for the "fast path" that keeps the old output
 *    byte for byte when neither is.
 *  - The block of "Sitemap:" lines made from $wgRobotsTxtSitemaps (see
 *    SitemapBlock). That is the one addition to a page "served as written":
 *    the setting is explicit, and without it the setting would silently do
 *    nothing as soon as a page exists.
 *
 * Invalid values in the two new settings never cause an error: the bad part
 * is skipped and one comment line per setting, at the top of the file, says
 * how many parts were skipped (CentralRulesValidator explains why).
 */
class RobotsTxtBuilder {

	/**
	 * Title (without namespace) of the on-wiki page that holds the rules.
	 * The page lives in the MediaWiki namespace, so editing it requires the
	 * "editinterface" right.
	 *
	 * Public because it is the single source of truth for the page name: the
	 * content model hook (RobotsTxtHooks) reads it to recognise the page it
	 * must create as plain text, so the two can never drift apart.
	 */
	public const PAGE_NAME = 'Robots.txt';

	/**
	 * A valid robots.txt that allows everything. An empty "Disallow" means
	 * "nothing is disallowed".
	 */
	private const ALLOW_ALL = "User-agent: *\nDisallow:\n";

	/**
	 * @param RevisionLookup $revisionLookup Used to read the latest
	 *   revision of the rules page.
	 * @param TitleFactory $titleFactory Used to build the page title.
	 * @param DefaultRulesGenerator $defaultRules Produces the generated
	 *   defaults. Injected so that tests can supply a fake.
	 * @param bool $useDefaults Value of $wgRobotsTxtUseDefaults. It is passed
	 *   in, rather than read from the configuration here, so tests can set it
	 *   without a MediaWiki configuration.
	 * @param array $sitemaps Value of $wgRobotsTxtSitemaps, passed in for the
	 *   same reason. It may hold anything: SitemapBlock skips what is not a
	 *   valid URL. The empty list means "no Sitemap lines".
	 * @param mixed $centralRules Value of $wgRobotsTxtCentralRules, passed in
	 *   raw for the same reason. It may hold anything: CentralRulesValidator
	 *   skips what is not valid. The empty array means "no central rules".
	 * @param mixed $inheritWildcard Value of $wgRobotsTxtInheritWildcard,
	 *   raw as well: false, true, or a list of agent names. False means that
	 *   nobody inherits.
	 */
	public function __construct(
		private readonly RevisionLookup $revisionLookup,
		private readonly TitleFactory $titleFactory,
		private readonly DefaultRulesGenerator $defaultRules,
		private readonly bool $useDefaults,
		private readonly array $sitemaps = [],
		private readonly mixed $centralRules = [],
		private readonly mixed $inheritWildcard = false
	) {
	}

	/**
	 * Build the complete text of robots.txt.
	 *
	 * @return string Always ends with exactly one newline.
	 */
	public function build(): string {
		$central = CentralRulesValidator::validateRules( $this->centralRules );
		$inherit = CentralRulesValidator::validateInherit( $this->inheritWildcard );

		// The report about invalid settings goes first, so it is the first
		// thing an administrator sees when running curl on /robots.txt. It is
		// empty (and so changes nothing) when both settings are valid.
		$text = self::reportLines( $central['skipped'], $inherit['skipped'] )
			. $this->buildRules( $central['rules'], $inherit['inherit'] );

		// The block is made from the final rules text, so that a URL the
		// page already lists is recognised and not written twice.
		return $text . SitemapBlock::render( $this->sitemaps, $text );
	}

	/**
	 * The comment lines that report invalid settings.
	 *
	 * @param int $centralSkipped Parts of $wgRobotsTxtCentralRules ignored
	 * @param int $inheritSkipped Parts of $wgRobotsTxtInheritWildcard ignored
	 * @return string Zero, one or two comment lines, each ending in a newline
	 */
	private static function reportLines( int $centralSkipped, int $inheritSkipped ): string {
		$text = '';
		foreach ( [
			'$wgRobotsTxtCentralRules' => $centralSkipped,
			'$wgRobotsTxtInheritWildcard' => $inheritSkipped,
		] as $name => $skipped ) {
			$line = CentralRulesValidator::warningLine( $name, $skipped );
			if ( $line !== '' ) {
				$text .= $line . "\n";
			}
		}
		return $text;
	}

	/**
	 * Decide the rules part of the file: the page, the defaults or allow-all.
	 *
	 * Two paths:
	 *  - The fast path, when there are no valid central rules and nobody
	 *    inherits: the text is exactly what the extension served before these
	 *    settings existed (the page normalised, or the defaults, or the
	 *    allow-all file with its comment). The parser is not even run, so a
	 *    wiki that does not use the new settings gets the same bytes as ever.
	 *  - The merge path otherwise: the chosen text is parsed and merged with
	 *    the central rules by RulesMerger. The allow-all file is then used
	 *    without its comment line, which says "no rules defined" and would be
	 *    wrong once there are central rules.
	 *
	 * @param array<string, array<string, list<string>>> $central Valid central rules
	 * @param bool|string[] $inherit Valid inheritance setting
	 * @return string Always ends with exactly one newline.
	 */
	private function buildRules( array $central, $inherit ): string {
		$merge = $central !== [] || $inherit !== false;

		$pageText = $this->getPageText();
		if ( $pageText !== null ) {
			return $merge
				? self::merge( $pageText, $central, $inherit )
				: $this->normalise( $pageText );
		}

		if ( $this->useDefaults ) {
			$defaults = $this->buildDefaults();
			return $merge ? self::merge( $defaults, $central, $inherit ) : $defaults;
		}

		return $merge
			? self::merge( self::ALLOW_ALL, $central, $inherit )
			: "# RobotsTxt extension: no rules defined, everything is allowed.\n" . self::ALLOW_ALL;
	}

	/**
	 * Merge a robots.txt text with the central rules.
	 *
	 * @param string $text The page text, the defaults or the allow-all file
	 * @param array<string, array<string, list<string>>> $central Valid central rules
	 * @param bool|string[] $inherit Valid inheritance setting
	 * @return string
	 */
	private static function merge( string $text, array $central, $inherit ): string {
		return RulesMerger::merge( $central, RobotsTxtParser::parse( $text ), $inherit );
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
	 * The generator's first line says where the text comes from, so an
	 * administrator who runs curl on /robots.txt knows why it looks like this.
	 *
	 * @return string
	 */
	private function buildDefaults(): string {
		return $this->defaultRules->generate();
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
