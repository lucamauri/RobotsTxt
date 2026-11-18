<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Generates the default robots.txt rules from the wiki's own settings.
 *
 * @file
 */

namespace MediaWiki\Extension\RobotsTxt;

use MediaWiki\Config\Config;
use MediaWiki\Language\Language;
use MediaWiki\Title\NamespaceInfo;

/**
 * Produces conservative default rules, used when MediaWiki:Robots.txt is
 * missing or empty and $wgRobotsTxtUseDefaults is true.
 *
 * Design choices:
 *  - Conservative: only URLs that are never content are blocked (edit and
 *    history views, login redirects, and every special page). Ordinary
 *    article URLs are never blocked.
 *  - No hardcoded paths: everything is derived from the wiki's own settings,
 *    so the same code works on /wt/index.php, /w/index.php or /index.php.
 *  - Wildcards for the "action" rules. A Disallow rule is a prefix match on
 *    path plus query string, and MediaWiki puts "title" first
 *    (index.php?title=Page&action=edit), so a rule such as
 *    "Disallow: /wt/index.php?action=edit" would silently never match.
 *    Wildcards are supported by Google, Bing and RFC 9309.
 *  - Every special page prefix is written in all its spellings: the English
 *    and the local name, each with a plain and a percent-encoded colon,
 *    because crawlers request both forms.
 *
 * The class only needs plain values, so it can be tested without MediaWiki.
 * Use newFromWiki() to build it from the real configuration.
 */
class DefaultRulesGenerator {

	/**
	 * The two ways a colon appears in a URL: plain, and percent-encoded
	 * (what many crawlers and browsers actually send).
	 */
	private const COLONS = [ ':', '%3A' ];

	/**
	 * @param string $script Path of the index.php entry point ($wgScript),
	 *   for example "/wt/index.php".
	 * @param string $articlePath Article path template ($wgArticlePath),
	 *   for example "/wiki/$1".
	 * @param string $loadScript Path of load.php ($wgLoadScript), which
	 *   serves the scripts and styles that crawlers need to render pages.
	 * @param string[] $specialPrefixes Names of the Special namespace in
	 *   every spelling the wiki accepts, for example [ "Special",
	 *   "Speciale" ].
	 */
	public function __construct(
		private readonly string $script,
		private readonly string $articlePath,
		private readonly string $loadScript,
		private readonly array $specialPrefixes
	) {
	}

	/**
	 * Build the generator from the wiki's real settings and language.
	 *
	 * Kept here, and not in the special page, so that all knowledge about
	 * where the values come from lives in one place.
	 *
	 * @param Config $config Site configuration.
	 * @param Language $contentLanguage The wiki's content language, which
	 *   knows the local name of the Special namespace and its aliases.
	 * @param NamespaceInfo $namespaceInfo Knows the canonical (English)
	 *   name of the Special namespace.
	 * @return self
	 */
	public static function newFromWiki(
		Config $config,
		Language $contentLanguage,
		NamespaceInfo $namespaceInfo
	): self {
		$prefixes = [
			(string)$namespaceInfo->getCanonicalName( NS_SPECIAL ),
			(string)$contentLanguage->getNsText( NS_SPECIAL ),
		];
		// Extra aliases of the Special namespace, if the wiki defines any.
		foreach ( $contentLanguage->getNamespaceAliases() as $alias => $index ) {
			if ( $index === NS_SPECIAL ) {
				$prefixes[] = (string)$alias;
			}
		}

		return new self(
			(string)$config->get( 'Script' ),
			(string)$config->get( 'ArticlePath' ),
			(string)$config->get( 'LoadScript' ),
			$prefixes
		);
	}

	/**
	 * Generate the rules.
	 *
	 * @return string A complete robots.txt, ending with one newline. The
	 *   first line says where the text comes from, so an administrator who
	 *   runs curl on /robots.txt knows how to replace it.
	 */
	public function generate(): string {
		$lines = [
			'# RobotsTxt extension: generated defaults.'
				. ' Create MediaWiki:Robots.txt to replace them.',
			'User-agent: *',
			// Listed first on purpose: very simple crawlers use the first
			// matching rule, while Google and Bing use the most specific
			// one. Either way load.php stays reachable.
			'Allow: ' . $this->loadScript,
			'Disallow: /*action=edit',
			'Disallow: /*action=history',
			'Disallow: /*returnto=',
		];

		foreach ( $this->getSpecialPagePaths() as $path ) {
			$lines[] = 'Disallow: ' . $path;
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * URL prefixes of every special page, in every spelling.
	 *
	 * @return string[] Unique, in a stable order.
	 */
	private function getSpecialPagePaths(): array {
		// The two URL shapes that lead to a page: the long form
		// (index.php?title=NAME) and the short form from $wgArticlePath
		// (for example /wiki/NAME).
		$bases = [ $this->script . '?title=' ];
		$shortBase = $this->getShortUrlBase();
		if ( $shortBase !== null ) {
			$bases[] = $shortBase;
		}

		$paths = [];
		foreach ( $bases as $base ) {
			foreach ( $this->getUniquePrefixes() as $prefix ) {
				foreach ( self::COLONS as $colon ) {
					$paths[] = $base . $prefix . $colon;
				}
			}
		}

		// With $wgArticlePath = "/index.php?title=$1" the two bases are
		// equal, so remove the duplicates.
		return array_values( array_unique( $paths ) );
	}

	/**
	 * The part of $wgArticlePath before "$1", without scheme and host.
	 *
	 * @return string|null Null when the template has no "$1".
	 */
	private function getShortUrlBase(): ?string {
		// $wgArticlePath may be a full URL; robots.txt rules are paths.
		$path = preg_replace( '~^(?:https?:)?//[^/]+~', '', $this->articlePath );
		$base = strstr( (string)$path, '$1', true );
		return $base === false ? null : $base;
	}

	/**
	 * The special namespace names, as they appear in a URL.
	 *
	 * Spaces become underscores, and non-ASCII characters are
	 * percent-encoded as UTF-8, which is how crawlers send them and how
	 * RFC 9309 says rules must be written.
	 *
	 * @return string[] Unique, empty names removed.
	 */
	private function getUniquePrefixes(): array {
		$prefixes = [];
		foreach ( $this->specialPrefixes as $name ) {
			if ( $name !== '' ) {
				$prefixes[] = rawurlencode( str_replace( ' ', '_', $name ) );
			}
		}
		return array_values( array_unique( $prefixes ) );
	}
}