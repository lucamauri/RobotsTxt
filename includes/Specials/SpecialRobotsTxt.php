<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Special:RobotsTxt, the endpoint that serves the robots.txt file.
 *
 * @file
 */

namespace MediaWiki\Extension\RobotsTxt\Specials;

use MediaWiki\Config\Config;
use MediaWiki\Extension\RobotsTxt\DefaultRulesGenerator;
use MediaWiki\Extension\RobotsTxt\RobotsTxtBuilder;
use MediaWiki\Language\Language;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\NamespaceInfo;
use MediaWiki\Title\TitleFactory;

/**
 * Serves robots.txt as plain text.
 *
 * Why a special page and not action=raw: action=raw only allows a short list
 * of content types and silently falls back to "text/x-wiki" for anything
 * else, including text/plain. Crawlers expect text/plain, so we send our own
 * headers instead. The web server rewrites /robots.txt to
 * index.php?title=Special:RobotsTxt (see the project documentation).
 *
 * This class only deals with the HTTP side (headers and output). What the
 * file actually contains is decided by RobotsTxtBuilder, so that it can be
 * tested without a web request.
 */
class SpecialRobotsTxt extends SpecialPage {

	/**
	 * @param Config $config Site configuration, used to read the
	 *   RobotsTxt* settings.
	 * @param RevisionLookup $revisionLookup Passed on to the builder.
	 * @param TitleFactory $titleFactory Passed on to the builder.
	 * @param Language $contentLanguage Passed on to the rules generator.
	 * @param NamespaceInfo $namespaceInfo Passed on to the rules generator.
	 *   All five are injected by MediaWiki from the "services" list of this
	 *   special page in extension.json, in that order.
	 */
	public function __construct(
		private readonly Config $config,
		private readonly RevisionLookup $revisionLookup,
		private readonly TitleFactory $titleFactory,
		private readonly Language $contentLanguage,
		private readonly NamespaceInfo $namespaceInfo
	) {
		// The name must match the key in "SpecialPages" in extension.json
		// and the alias in i18n/RobotsTxt.alias.php.
		parent::__construct( 'RobotsTxt' );
	}

	/**
	 * Send the robots.txt file and stop MediaWiki from rendering a page.
	 *
	 * @param string|null $subPage Unused; the endpoint has no subpages.
	 */
	public function execute( $subPage ) {
		// Without this, MediaWiki would wrap our text in the skin (HTML).
		// Once output is disabled we are responsible for everything that is
		// sent to the client.
		$this->getOutput()->disable();

		$response = $this->getRequest()->response();

		// Plain text, UTF-8: what crawlers expect (RFC 9309).
		$response->header( 'Content-Type: text/plain; charset=utf-8' );

		// How long crawlers and caches may reuse the file. A negative value
		// in LocalSettings.php would be invalid, so it is clamped to zero.
		$maxAge = max( 0, (int)$this->config->get( 'RobotsTxtCacheMaxAge' ) );
		$response->header( 'Cache-Control: public, max-age=' . $maxAge );

		echo $this->getBody();
	}

	/**
	 * The content of the file, as decided by the builder.
	 *
	 * @return string
	 */
	private function getBody(): string {
		$builder = new RobotsTxtBuilder(
			$this->revisionLookup,
			$this->titleFactory,
			DefaultRulesGenerator::newFromWiki(
				$this->config,
				$this->contentLanguage,
				$this->namespaceInfo
			),
			(bool)$this->config->get( 'RobotsTxtUseDefaults' ),
			// The cast is a courtesy for a common slip: a single URL written
			// as a string instead of a list becomes a list of one, and an
			// unset value becomes an empty list. Whether each entry is a
			// valid URL is decided later, by SitemapBlock.
			(array)$this->config->get( 'RobotsTxtSitemaps' ),
			// These two are passed raw: whatever an administrator wrote in
			// LocalSettings.php is checked by CentralRulesValidator inside
			// the builder, which skips what is invalid and reports it in a
			// comment line instead of failing.
			$this->config->get( 'RobotsTxtCentralRules' ),
			$this->config->get( 'RobotsTxtInheritWildcard' )
		);
		return $builder->build();
	}
}
