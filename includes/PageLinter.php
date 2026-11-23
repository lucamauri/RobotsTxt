<?php
/**
 * Lint for the text of MediaWiki:Robots.txt (step E, batch E-5).
 *
 * @license GPL-2.0-or-later
 * @file
 */

namespace MediaWiki\Extension\RobotsTxt;

/**
 * Finds three common mistakes in a robots.txt text, so the edit form can warn about them.
 *
 * The warning never blocks a save (decision 32): blocking would lock out intentional use and
 * would go against the extension's rule that any directive is accepted. Crawlers ignore a line
 * they do not understand, so these mistakes do not break the file, they silently make a rule
 * do nothing, which is the kind of failure an administrator does not notice.
 *
 * The three mistakes:
 *
 * - `nocolon`: a line that is not blank, not a comment and has no colon. A crawler cannot read
 *   it. The usual cause is a forgotten colon: `Disallow /private/`.
 * - `orphan`: an `Allow` or `Disallow` rule that comes before the first `User-agent` line. It
 *   belongs to no group, so crawlers ignore it.
 * - `path`: an `Allow` or `Disallow` value that does not start with `/` or `*`. Paths in a
 *   robots.txt start with a slash; a value such as `private/` matches nothing. An empty
 *   `Disallow:` is fine (it means "allow everything"); an empty `Allow:` is reported.
 *
 * A line can have more than one mistake (an orphan rule with a bad path), and then every
 * mistake is reported. Directive names are compared ignoring case, and a trailing comment is
 * not part of the value.
 *
 * The class is pure: it has no MediaWiki dependency, reads no configuration, and is tested
 * alone. It does not use RobotsTxtParser because the parser drops blank lines, and the warning
 * must point to the line number the administrator sees in the edit box.
 */
class PageLinter {

	/**
	 * Longest piece of a line that is returned for display, in characters. A longer line is cut
	 * and `...` is added, so a huge pasted line cannot flood the edit form.
	 */
	public const MAX_TEXT_LENGTH = 80;

	/**
	 * Check a robots.txt text.
	 *
	 * @param string $text The text, with any line ending
	 * @return array[] One item per mistake, in the order of the lines. Each item has `type`
	 *  (`nocolon`, `orphan` or `path`), `line` (int, counted from 1 in the text as written,
	 *  blank lines included) and `text` (the trimmed line, shortened to MAX_TEXT_LENGTH).
	 */
	public static function lint( string $text ): array {
		$problems = [];
		$agentSeen = false;

		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $index => $rawLine ) {
			$line = trim( $rawLine );
			// Blank lines and full-line comments are always fine.
			if ( $line === '' || $line[0] === '#' ) {
				continue;
			}
			$lineNumber = $index + 1;

			$colon = strpos( $line, ':' );
			if ( $colon === false ) {
				$problems[] = self::problem( 'nocolon', $lineNumber, $line );
				continue;
			}

			$name = strtolower( rtrim( substr( $line, 0, $colon ) ) );
			if ( $name === 'user-agent' ) {
				$agentSeen = true;
				continue;
			}
			if ( $name !== 'allow' && $name !== 'disallow' ) {
				// Any other directive is accepted as written.
				continue;
			}

			if ( !$agentSeen ) {
				$problems[] = self::problem( 'orphan', $lineNumber, $line );
			}

			$value = self::valueOf( substr( $line, $colon + 1 ) );
			$emptyDisallow = $name === 'disallow' && $value === '';
			if ( !$emptyDisallow && !preg_match( '~^[/*]~', $value ) ) {
				$problems[] = self::problem( 'path', $lineNumber, $line );
			}
		}

		return $problems;
	}

	/**
	 * The value of a directive: what follows the colon, without a trailing comment and spaces.
	 *
	 * @param string $afterColon
	 * @return string
	 */
	private static function valueOf( string $afterColon ): string {
		$hash = strpos( $afterColon, '#' );
		if ( $hash !== false ) {
			$afterColon = substr( $afterColon, 0, $hash );
		}
		return trim( $afterColon );
	}

	/**
	 * Build one item of the result.
	 *
	 * @param string $type
	 * @param int $line
	 * @param string $text The trimmed line
	 * @return array
	 */
	private static function problem( string $type, int $line, string $text ): array {
		if ( mb_strlen( $text ) > self::MAX_TEXT_LENGTH ) {
			$text = mb_substr( $text, 0, self::MAX_TEXT_LENGTH ) . '...';
		}
		return [ 'type' => $type, 'line' => $line, 'text' => $text ];
	}
}
