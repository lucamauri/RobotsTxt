<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * The block of "Sitemap:" lines at the end of robots.txt.
 *
 * @file
 */

namespace MediaWiki\Extension\RobotsTxt;

/**
 * Turns the list from $wgRobotsTxtSitemaps into the lines to append to
 * robots.txt.
 *
 * Why this is a class of its own: like DefaultRulesGenerator, it works on
 * plain values (the list and the text so far) and needs no wiki, so it can be
 * tested with exact expected output. It is a pure function, which is why it
 * has no constructor and no state, and render() is static.
 *
 * Why "Sitemap:" lines can simply be appended: unlike the rules, a Sitemap
 * line belongs to no user-agent group and may appear anywhere in the file, so
 * adding them at the end can never change which rules apply to a crawler.
 *
 * Rules (design decisions D4 to D6):
 *  - An entry is valid when it is a string holding an absolute http or https
 *    URL with no whitespace and no control characters. The sitemap protocol
 *    asks for full URLs, and a line break inside a value could add directives
 *    of its own to the file, so both are checked.
 *  - A valid URL is written exactly as given, once, in list order.
 *  - A URL that the text already lists as a "Sitemap:" line is not repeated.
 *  - An invalid entry is skipped, never an error: a failing /robots.txt can
 *    make crawlers treat the whole site as disallowed (RFC 9309), so one
 *    typo in LocalSettings.php must not be able to cause that. Instead, a
 *    comment line says how many entries were skipped, so an administrator who
 *    runs curl on /robots.txt can see why a line is missing.
 */
class SitemapBlock {

	/**
	 * What a valid entry looks like.
	 *
	 * The scheme may be in any letter case. After "://" at least one
	 * character follows, and none of them is whitespace or a control
	 * character. The "D" modifier makes "$" match only at the very end of the
	 * string; without it a trailing newline would slip through.
	 */
	private const VALID_URL = '~^https?://[^\s\x00-\x1F\x7F]+$~iD';

	/**
	 * What a "Sitemap:" line in the text looks like: the directive name in
	 * any letter case, optional spaces, a colon, then the URL. A commented-out
	 * line starts with "#" and so does not match.
	 */
	private const SITEMAP_LINE = '~^\s*sitemap\s*:\s*(\S+)\s*$~i';

	/**
	 * Build the block to append to the text of robots.txt.
	 *
	 * The result, when it is not empty, starts with an empty line (so the
	 * block is separated from the rules above it) and ends with a newline.
	 * The comment line about skipped entries, when there is one, comes first,
	 * then the "Sitemap:" lines.
	 *
	 * @param array $entries Value of $wgRobotsTxtSitemaps. Any value may be
	 *   in it; anything that is not a valid URL string is skipped.
	 * @param string $text The text of robots.txt so far, with "\n" line
	 *   endings, used to avoid repeating a URL it already lists.
	 * @return string The block to append, or the empty string when there is
	 *   nothing to add and nothing to report.
	 */
	public static function render( array $entries, string $text ): string {
		// Keys of this array are the URLs already written or already in the
		// text; a lookup by key is the simplest "have I seen this" check.
		$seen = self::findListedUrls( $text );
		$lines = '';
		$skipped = 0;

		foreach ( $entries as $entry ) {
			// is_string() first: preg_match() would raise a warning for an
			// array and quietly convert a number, and neither is wanted.
			if ( !is_string( $entry ) || !preg_match( self::VALID_URL, $entry ) ) {
				$skipped++;
				continue;
			}

			if ( isset( $seen[$entry] ) ) {
				continue;
			}

			$seen[$entry] = true;
			$lines .= "Sitemap: $entry\n";
		}

		if ( $lines === '' && $skipped === 0 ) {
			return '';
		}

		$block = "\n";
		if ( $skipped > 0 ) {
			$block .= "# RobotsTxt: invalid \$wgRobotsTxtSitemaps entries ignored: $skipped\n";
		}

		return $block . $lines;
	}

	/**
	 * Find the URLs that the text already lists as "Sitemap:" lines.
	 *
	 * @param string $text The text of robots.txt so far
	 * @return array<string,true> The URLs, as the keys of the array
	 */
	private static function findListedUrls( string $text ): array {
		$listed = [];
		foreach ( explode( "\n", $text ) as $line ) {
			if ( preg_match( self::SITEMAP_LINE, $line, $matches ) ) {
				$listed[$matches[1]] = true;
			}
		}

		return $listed;
	}
}
