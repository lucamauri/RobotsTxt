<?php
/**
 * Parser that turns robots.txt text into a plain structure (step E, batch E-1).
 *
 * @license GPL-2.0-or-later
 * @file
 */

namespace MediaWiki\Extension\RobotsTxt;

/**
 * Turns robots.txt text into a plain array structure, without changing what the text means.
 *
 * Why this class exists: the merge of central and on-wiki rules (step E) needs to know which
 * rules belong to which user agent. Merging raw text would give two `User-agent: *` groups,
 * trailing blank lines in the middle of the file and rules that no crawler attributes to
 * anyone (the problem reported in Wikimedia's T17663). So the text is read into groups first.
 *
 * The parser is deliberately tolerant (decision 27): it never rejects a line, never throws and
 * never drops what an admin wrote. A line it does not understand is kept as it is, because
 * crawlers ignore such lines anyway. It does no merging, no validation of values and no
 * rendering; those belong to other classes.
 *
 * Structure returned by {@see RobotsTxtParser::parse()}:
 *
 * - `preamble`: a list of entries that appear before the first `User-agent` line and belong
 *   to no group (orphan rules such as a `Disallow` with no agent, and unparseable lines).
 * - `groups`: a list of groups, in file order. A group is
 *   `[ 'agents' => list<entry>, 'rules' => list<entry> ]`: the consecutive `User-agent` lines
 *   that share one rule set, and everything that follows them up to the next group.
 * - `sitemaps`: a list of entries, the `Sitemap:` lines wherever they appeared (they are not
 *   part of any group, so they are lifted out; decision 33).
 * - `footer`: a list of full-line comments at the very end of the text, with no line after them.
 *
 * An entry is always
 * `[ 'directive' => ?string, 'value' => string, 'comment' => ?string, 'before' => list<string> ]`:
 *
 * - `directive`: the directive name with the spelling used in the text (names are matched
 *   case-insensitively, but the spelling is kept). `null` means an unparseable line.
 * - `value`: the text after the first colon, trimmed, without a trailing comment. For an
 *   unparseable line it is the whole trimmed line.
 * - `comment`: the trailing comment of the line including its `#`, or `null`.
 * - `before`: the full-line comments directly above the line, each including its `#`.
 *   A full-line comment travels with the next line that is not a comment, whatever that line
 *   is (an agent line, a rule or a `Sitemap:` line), and blank lines do not detach it.
 *
 * Reading rules (decision 27, refined 2026-11-22):
 *
 * 1. A group is its consecutive `User-agent` lines plus everything up to the next `User-agent`
 *    line that follows a non-agent line. Comments, blank lines and lifted `Sitemap:` lines do
 *    not interrupt a run of agent lines; an unparseable line does.
 * 2. Directive names are recognised case-insensitively; the written spelling is kept.
 * 3. A line without a colon, with an invalid directive name, or a `User-agent` line with an
 *    empty value is kept as an unparseable entry (directive `null`) and never starts a group.
 * 4. Entries before the first `User-agent` line that belong to no group go to the preamble.
 * 5. `Sitemap:` lines are lifted out of the groups and the preamble into `sitemaps`.
 * 6. Blank lines are dropped (the spacing is regenerated when the text is rendered).
 * 7. Line endings may be CRLF, LF or a lone CR.
 */
class RobotsTxtParser {

	/**
	 * Read robots.txt text into groups.
	 *
	 * The text is read line by line in one pass. Two pieces of state drive the grouping:
	 * the comments waiting for the next real line (they travel with it), and whether the last
	 * line added to the current group was an agent line (a following agent line then joins the
	 * same group; after any other line it starts a new one).
	 *
	 * @param string $text The robots.txt text (page text, generated defaults, anything)
	 * @return array{preamble: list<array>, groups: list<array>, sitemaps: list<array>, footer: list<string>}
	 */
	public static function parse( string $text ): array {
		$preamble = [];
		$groups = [];
		$sitemaps = [];

		// Full-line comments waiting for the next line that is not a comment.
		$pendingComments = [];
		// Index in $groups of the group being filled, or null before the first agent line.
		$current = null;
		// True while the last line added to the current group was a User-agent line.
		$lastWasAgent = false;

		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
			$line = trim( $line );
			if ( $line === '' ) {
				continue;
			}
			if ( $line[0] === '#' ) {
				$pendingComments[] = $line;
				continue;
			}

			$entry = self::parseLine( $line, $pendingComments );
			$pendingComments = [];
			$name = $entry['directive'] === null ? null : strtolower( $entry['directive'] );

			if ( $name === 'sitemap' ) {
				// Not part of any group, and invisible to the grouping: it neither starts a
				// group nor interrupts a run of agent lines.
				$sitemaps[] = $entry;
			} elseif ( $name === 'user-agent' ) {
				if ( $current === null || !$lastWasAgent ) {
					$groups[] = [ 'agents' => [ $entry ], 'rules' => [] ];
					$current = array_key_last( $groups );
				} else {
					$groups[$current]['agents'][] = $entry;
				}
				$lastWasAgent = true;
			} elseif ( $current === null ) {
				// An orphan rule or an unparseable line before the first group.
				$preamble[] = $entry;
			} else {
				$groups[$current]['rules'][] = $entry;
				$lastWasAgent = false;
			}
		}

		return [
			'preamble' => $preamble,
			'groups' => $groups,
			'sitemaps' => $sitemaps,
			// Comments still waiting at the end had no line to travel with.
			'footer' => $pendingComments,
		];
	}

	/**
	 * Read one trimmed, non-empty line that does not start with `#`.
	 *
	 * Only the first colon splits the line, because values such as URLs and paths contain
	 * colons. A `#` in the value starts a trailing comment, as in the robots.txt format.
	 * Anything that is not a plausible `name: value` line, and a `User-agent` line without a
	 * name (it would start a group that no crawler can match), is returned as an unparseable
	 * entry holding the whole line.
	 *
	 * @param string $line The trimmed line
	 * @param string[] $before Full-line comments that were waiting for this line
	 * @return array The entry (see the class documentation)
	 */
	private static function parseLine( string $line, array $before ): array {
		$unparseable = [ 'directive' => null, 'value' => $line, 'comment' => null, 'before' => $before ];

		$colon = strpos( $line, ':' );
		if ( $colon === false ) {
			return $unparseable;
		}

		$directive = trim( substr( $line, 0, $colon ) );
		if ( !preg_match( '/^[A-Za-z][A-Za-z0-9_-]*$/D', $directive ) ) {
			return $unparseable;
		}

		$rest = substr( $line, $colon + 1 );
		$hash = strpos( $rest, '#' );
		if ( $hash === false ) {
			$value = trim( $rest );
			$comment = null;
		} else {
			$value = trim( substr( $rest, 0, $hash ) );
			$comment = trim( substr( $rest, $hash ) );
		}

		if ( $value === '' && strtolower( $directive ) === 'user-agent' ) {
			return $unparseable;
		}

		return [ 'directive' => $directive, 'value' => $value, 'comment' => $comment, 'before' => $before ];
	}
}
