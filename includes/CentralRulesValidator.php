<?php
/**
 * Validation of the central rules settings (step E, batch E-2).
 *
 * @license GPL-2.0-or-later
 * @file
 */

namespace MediaWiki\Extension\RobotsTxt;

/**
 * Cleans the values of `$wgRobotsTxtCentralRules` and `$wgRobotsTxtInheritWildcard`.
 *
 * Policy (decision 31, same as decision 25 for sitemaps): skip the bad part, count it, and let
 * the caller report it in one comment line; never throw. Why never throw: crawlers may treat a
 * robots.txt that fails with a server error as "disallow everything" for a while (RFC 9309),
 * so a typo in `LocalSettings.php` must not be able to break the file. The class is pure: it
 * reads no configuration itself and has no MediaWiki dependency, so it is unit-tested alone.
 *
 * What is invalid in `$wgRobotsTxtCentralRules` (the setting is an array keyed by user agent,
 * each value an array from directive name to a value or a list of values):
 *
 * - The whole setting, when it is not an array (it is ignored, counted once).
 * - An agent key that is not a string (for example a plain list was written instead of a map),
 *   is empty, or contains whitespace, a control character or `#`. A line break in a name
 *   could inject extra directives, and `#` would turn the rest of the line into a comment.
 *   The test is deliberately lenient, not the strict RFC token characters. The agent's
 *   contents are skipped with it and counted once.
 * - An agent whose value is not an array (counted once).
 * - A directive name that is not a string, is empty, or contains whitespace, a control
 *   character, `:` or `#`; also `User-agent` and `Sitemap` in any case: the first would break
 *   the group structure, the second has its own setting (counted once per directive).
 * - A value that is not a string, an int or a finite float; a string value containing a
 *   control character (this includes line breaks, tabs and NUL) or `#`. In a list, each bad
 *   element is skipped and counted on its own, and the good ones are kept.
 *
 * Not invalid: an empty string value (it is meaningful for `Disallow`: allow everything), an
 * empty list (it contributes nothing and is not counted), spaces inside a value, and the same
 * agent or directive written with different capitalisation (they are kept as separate keys;
 * merging them is the job of the merger, which compares names case-insensitively).
 *
 * What is invalid in `$wgRobotsTxtInheritWildcard` (`false`, `true`, or a list of agent names):
 * a value of any other type means `false` (counted once); in a list, each element that is not
 * a valid agent name (same test as for an agent key) is skipped and counted.
 */
class CentralRulesValidator {

	/**
	 * Directive names that are not accepted inside the central array, compared in lower case.
	 * `user-agent` would break the group structure (the array key is the agent), and `sitemap`
	 * has its own setting because it belongs to no agent.
	 */
	private const RESERVED_DIRECTIVES = [ 'user-agent', 'sitemap' ];

	/**
	 * Clean the value of `$wgRobotsTxtCentralRules`.
	 *
	 * The result keeps the order of the setting. Values are strings (numbers are converted,
	 * and a single value becomes a list of one). Directives left with no values are dropped,
	 * but an agent that is left with no directives is kept: a group that only names its agent
	 * still has a meaning (a named group is not bound by the `*` group, trap 3).
	 *
	 * @param mixed $setting The raw setting
	 * @return array{rules: array<string, array<string, list<string>>>, skipped: int}
	 *  `rules` maps agent to directive name to list of values; `skipped` counts the ignored parts
	 */
	public static function validateRules( $setting ): array {
		if ( !is_array( $setting ) ) {
			return [ 'rules' => [], 'skipped' => 1 ];
		}

		$rules = [];
		$skipped = 0;
		foreach ( $setting as $agent => $directives ) {
			// An integer key means a list was written where a map was expected.
			if ( !self::isValidAgentName( $agent ) || !is_array( $directives ) ) {
				$skipped++;
				continue;
			}

			$cleanDirectives = [];
			foreach ( $directives as $directive => $values ) {
				if ( !self::isValidDirectiveName( $directive ) ) {
					$skipped++;
					continue;
				}
				$cleanValues = self::cleanValues( $values, $skipped );
				// A directive with no values contributes nothing, so it is not kept.
				if ( $cleanValues !== [] ) {
					$cleanDirectives[$directive] = $cleanValues;
				}
			}
			$rules[$agent] = $cleanDirectives;
		}

		return [ 'rules' => $rules, 'skipped' => $skipped ];
	}

	/**
	 * Clean the value of `$wgRobotsTxtInheritWildcard`.
	 *
	 * `true` and `false` are kept. A list becomes the list of its valid agent names (keys are
	 * ignored, spelling and duplicates are kept as written); a list that ends up empty is
	 * `false`, because nobody inherits.
	 *
	 * @param mixed $setting The raw setting
	 * @return array{inherit: bool|list<string>, skipped: int}
	 */
	public static function validateInherit( $setting ): array {
		if ( is_bool( $setting ) ) {
			return [ 'inherit' => $setting, 'skipped' => 0 ];
		}
		if ( !is_array( $setting ) ) {
			return [ 'inherit' => false, 'skipped' => 1 ];
		}

		$names = [];
		$skipped = 0;
		foreach ( $setting as $name ) {
			if ( self::isValidAgentName( $name ) ) {
				$names[] = $name;
			} else {
				$skipped++;
			}
		}

		return [ 'inherit' => $names === [] ? false : $names, 'skipped' => $skipped ];
	}

	/**
	 * The comment line that reports ignored entries of one setting.
	 *
	 * Same wording as the sitemap report of step D, for example
	 * `# RobotsTxt: invalid $wgRobotsTxtCentralRules entries ignored: 3`. Empty when nothing
	 * was skipped, so the caller can add the result without checking.
	 *
	 * @param string $settingName The setting name including its `$`, for example `$wgRobotsTxtCentralRules`
	 * @param int $skipped How many parts were ignored
	 * @return string One comment line without a line break, or the empty string
	 */
	public static function warningLine( string $settingName, int $skipped ): string {
		if ( $skipped <= 0 ) {
			return '';
		}
		return '# RobotsTxt: invalid ' . $settingName . ' entries ignored: ' . $skipped;
	}

	/**
	 * Whether a value can be used as a user agent name.
	 *
	 * Lenient on purpose: any non-empty string without whitespace, control characters or `#`.
	 * Whitespace and control characters (a line break in particular) could end the line and
	 * start a new directive; `#` would turn the rest of the line into a comment. Array keys
	 * that PHP made integers fail the string test, which is how a plain list is caught.
	 *
	 * @param mixed $name
	 * @return bool
	 */
	private static function isValidAgentName( $name ): bool {
		return is_string( $name ) && preg_match( '/^[^\s#\x00-\x1F\x7F]+$/D', $name ) === 1;
	}

	/**
	 * Whether a value can be used as a directive name inside an agent's array.
	 *
	 * Same test as for an agent name, plus `:` (it would end the name), and without the
	 * reserved names (see RESERVED_DIRECTIVES).
	 *
	 * @param mixed $name
	 * @return bool
	 */
	private static function isValidDirectiveName( $name ): bool {
		return is_string( $name )
			&& preg_match( '/^[^\s:#\x00-\x1F\x7F]+$/D', $name ) === 1
			&& !in_array( strtolower( $name ), self::RESERVED_DIRECTIVES, true );
	}

	/**
	 * Clean the value or list of values of one directive.
	 *
	 * A single value is treated as a list of one. Each element is checked on its own: a bad
	 * one is skipped and counted, and the others are kept in order (keys of the list are
	 * ignored). Strings must not contain a control character (a line break would inject
	 * directives) or `#` (it would cut the line). Numbers must be finite and become strings.
	 *
	 * @param mixed $values A value or a list of values
	 * @param int &$skipped Incremented for every element that is skipped
	 * @return string[] The clean values, possibly empty
	 */
	private static function cleanValues( $values, int &$skipped ): array {
		if ( !is_array( $values ) ) {
			$values = [ $values ];
		}

		$clean = [];
		foreach ( $values as $value ) {
			if ( is_string( $value ) && preg_match( '/[#\x00-\x1F\x7F]/', $value ) !== 1 ) {
				$clean[] = $value;
			} elseif ( is_int( $value ) || ( is_float( $value ) && is_finite( $value ) ) ) {
				$clean[] = (string)$value;
			} else {
				$skipped++;
			}
		}
		return $clean;
	}
}
