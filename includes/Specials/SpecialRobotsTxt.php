<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Special:RobotsTxt, the endpoint that serves the robots.txt file.
 *
 * @file
 */

namespace MediaWiki\Extension\RobotsTxt\Specials;

use MediaWiki\Config\Config;
use MediaWiki\SpecialPage\SpecialPage;

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
 * file actually contains is decided elsewhere, so that it can be tested
 * without a web request. In this first version the body is a fixed test
 * string.
 */
class SpecialRobotsTxt extends SpecialPage {

	/**
	 * @var Config Site configuration, used to read $wgRobotsTxtCacheMaxAge.
	 */
	private Config $config;

	/**
	 * @param Config $config Injected by MediaWiki from the "services" list
	 *   of this special page in extension.json ("MainConfig").
	 */
	public function __construct( Config $config ) {
		// The name must match the key in "SpecialPages" in extension.json
		// and the alias in i18n/RobotsTxt.alias.php.
		parent::__construct( 'RobotsTxt' );
		$this->config = $config;
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
	 * The content of the file.
	 *
	 * Phase 1, step 1: a fixed test string, so that the headers can be
	 * verified on a live wiki before any rule logic exists. It is an
	 * "allow everything" file, so it is harmless if a crawler ever sees it.
	 * It will be replaced by the output of RobotsTxtBuilder.
	 *
	 * @return string
	 */
	private function getBody(): string {
		return "# RobotsTxt extension: test output\n"
			. "User-agent: *\n"
			. "Disallow:\n";
	}
}