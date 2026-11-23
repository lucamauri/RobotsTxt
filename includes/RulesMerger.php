<?php
/**
 * Merging of the central rules with the rules of the page (step E, batches E-3a and E-3b).
 *
 * @license GPL-2.0-or-later
 * @file
 */

namespace MediaWiki\Extension\RobotsTxt;

/**
 * Builds the final rules text from the central rules setting and a parsed robots.txt text (the
 * page, or the generated defaults).
 *
 * The steps, in order (decisions 27 and 28):
 *
 * 1. Split every group with several `User-agent` lines into one group per agent. The rules of
 *    the shared group are copied to each of its agents.
 * 2. Merge by agent. Names are compared case-insensitively and the first spelling is kept. The
 *    central rules are added first, then the rules of the page, so the central rules come first
 *    inside each group. Groups are kept in the order in which their agent first appears, which
 *    puts central agents before the agents that only the page has.
 * 3. Remove exact duplicate rules while merging: same directive name (case-insensitive) and same
 *    value (case-sensitive); the first one wins. Conflicting rules, such as an Allow and a
 *    Disallow for the same path, are all kept, because a crawler resolves them by the longest
 *    match, not by order. Lines the parser could not understand (directive null) are never
 *    removed: we do not know what they mean.
 * 4. Inheritance (decision 29), only when `$inherit` asks for it: every chosen agent (all agents
 *    except `*` for `true`, or the listed ones, ignoring case) receives the `Allow` and
 *    `Disallow` rules of the merged `*` group, placed before its own rules. The copies carry
 *    no comments. A rule the agent already has is not repeated. Why: a crawler that finds a
 *    group naming it ignores the `*` group completely (trap 3), so without this a named agent
 *    silently loses every general restriction.
 * 5. The empty `Disallow:` rule (decision 30): an empty `Disallow:` means "allow everything",
 *    so it is dropped from a group that has any other `Allow` or `Disallow` line, and kept when
 *    it is the group's only such line. This runs after inheritance, because a copied empty
 *    `Disallow:` is judged against the rules the agent has in the end.
 * 6. Join again the agents whose rule lists are identical, comments included, into one group
 *    placed where the first of them was. Without this step, every shared group would come out
 *    split in one group per agent.
 * 7. Warn about conflicting values of a directive that can have only one (see
 *    SINGLE_VALUED_DIRECTIVES): a comment line is put after the agent lines of the final group.
 *    Both rules are kept, because we cannot know which one the author meant.
 * 8. Render: preamble, groups, closing comments (the footer), then the `Sitemap:` lines of the
 *    page. Sections are separated by one blank line and the text ends with one line break.
 *
 * Comments: a comment above or beside a line stays with that line. When a page group is merged
 * into an agent that already exists, its `User-agent` line disappears, and the comments of that
 * line (above and inline) move onto the first rule the group adds. If the group adds no rule
 * (all duplicates, or none), those comments are dropped. The comments of a removed duplicate
 * rule are dropped with it.
 *
 * The class is pure (no MediaWiki dependency), so it is unit-tested alone.
 *
 * An "entry" below is one line as RobotsTxtParser::parse() describes it: an array with the keys
 * `directive` (?string, null for a line that could not be understood), `value` (string),
 * `comment` (?string, including the `#`) and `before` (list of full-line comments above).
 */
class RulesMerger {

	/**
	 * Directives that make sense only once per group, in lower case, with the name used in the
	 * warning. Two different values of one of them in the same group get a warning comment.
	 */
	private const SINGLE_VALUED_DIRECTIVES = [ 'crawl-delay' => 'Crawl-delay' ];

	/**
	 * Merge the central rules with a parsed robots.txt text and render the result.
	 *
	 * @param array<string, array<string, list<string>>> $central Output of
	 *  CentralRulesValidator::validateRules()['rules']: agent to directive name to list of values
	 * @param array $parsed Output of RobotsTxtParser::parse() for the page or for the defaults
	 * @param bool|string[] $inherit Output of CentralRulesValidator::validateInherit()['inherit']:
	 *  false for nobody, true for every named agent, or a list of agent names
	 * @return string The rules text ending in one line break, or '' when there is nothing
	 */
	public static function merge( array $central, array $parsed, $inherit ): string {
		// Steps 1 to 3: collect the rules of every agent, central rules first.
		$agents = [];
		foreach ( $central as $agent => $directives ) {
			self::addAgent(
				$agents,
				self::makeEntry( 'User-agent', (string)$agent ),
				self::centralEntries( $directives )
			);
		}
		foreach ( $parsed['groups'] as $group ) {
			// A shared group is split here: the same rules are offered to each of its agents.
			foreach ( $group['agents'] as $agentEntry ) {
				self::addAgent( $agents, $agentEntry, $group['rules'] );
			}
		}

		// Steps 4 and 5: inheritance from `*`, then the empty `Disallow:` rule.
		self::inheritWildcard( $agents, $inherit );
		foreach ( $agents as $key => $agent ) {
			$agents[$key]['rules'] = self::dropEmptyDisallow( $agent['rules'] );
		}

		// Step 6: agents with the same rule lines share a group. The rendered rules are the
		// key, so lines that differ only by a comment are different (conservative).
		$groups = [];
		foreach ( $agents as $agent ) {
			$key = implode( "\n", array_map( [ self::class, 'renderEntry' ], $agent['rules'] ) );
			if ( !isset( $groups[$key] ) ) {
				$groups[$key] = [ 'agents' => [], 'rules' => $agent['rules'] ];
			}
			$groups[$key]['agents'][] = $agent['agent'];
		}

		// Step 5: build the sections and join them with one blank line.
		$sections = [];
		if ( $parsed['preamble'] !== [] ) {
			$sections[] = self::renderLines( $parsed['preamble'] );
		}
		foreach ( $groups as $group ) {
			// Step 7: the warnings are worked out on the final group, so a joined group gets one.
			$sections[] = self::renderLines( $group['agents'] )
				. self::conflictWarnings( $group['rules'] )
				. ( $group['rules'] === [] ? '' : "\n" . self::renderLines( $group['rules'] ) );
		}
		if ( $parsed['footer'] !== [] ) {
			$sections[] = implode( "\n", $parsed['footer'] );
		}
		if ( $parsed['sitemaps'] !== [] ) {
			$sections[] = self::renderLines( $parsed['sitemaps'] );
		}

		return $sections === [] ? '' : implode( "\n\n", $sections ) . "\n";
	}

	/**
	 * Copy the Allow and Disallow rules of the `*` group into the agents chosen by `$inherit`.
	 *
	 * The copies go first, then the agent's own rules, except those the agent already has.
	 * Comments are not copied: they describe the rule where it was written. Nothing happens when
	 * nobody inherits or when there is no `*` group.
	 *
	 * @param array &$agents The collection built by addAgent(), changed in place
	 * @param bool|string[] $inherit
	 */
	private static function inheritWildcard( array &$agents, $inherit ): void {
		if ( $inherit === false || $inherit === [] || !isset( $agents['*'] ) ) {
			return;
		}
		$chosen = $inherit === true ? null : array_map( 'mb_strtolower', $inherit );

		$copies = [];
		foreach ( $agents['*']['rules'] as $rule ) {
			if ( $rule['directive'] !== null && self::isAllowOrDisallow( $rule ) ) {
				$rule['comment'] = null;
				$rule['before'] = [];
				$copies[] = $rule;
			}
		}

		foreach ( $agents as $key => $agent ) {
			if ( $key === '*' || ( $chosen !== null && !in_array( $key, $chosen, true ) ) ) {
				continue;
			}
			$rules = $copies;
			$taken = [];
			foreach ( $copies as $copy ) {
				$taken[self::duplicateKey( $copy )] = true;
			}
			foreach ( $agent['rules'] as $rule ) {
				$alreadyCopied = $rule['directive'] !== null
					&& isset( $taken[self::duplicateKey( $rule )] );
				if ( !$alreadyCopied ) {
					$rules[] = $rule;
				}
			}
			$agents[$key]['rules'] = $rules;
		}
	}

	/**
	 * Remove the empty `Disallow:` lines from a rule list that has another Allow or Disallow line.
	 *
	 * An empty `Allow:` is not touched and counts as "another line"; an empty `Disallow:` alone
	 * stays, because it is the group's whole meaning.
	 *
	 * @param array[] $rules
	 * @return array[]
	 */
	private static function dropEmptyDisallow( array $rules ): array {
		$hasOther = false;
		foreach ( $rules as $rule ) {
			if ( self::isAllowOrDisallow( $rule ) && !self::isEmptyDisallow( $rule ) ) {
				$hasOther = true;
				break;
			}
		}
		if ( !$hasOther ) {
			return $rules;
		}
		return array_values( array_filter(
			$rules,
			static function ( array $rule ): bool {
				return !self::isEmptyDisallow( $rule );
			}
		) );
	}

	/**
	 * Build the warning comment lines for a final group, each one preceded by a line break.
	 *
	 * @param array[] $rules The final rules of the group
	 * @return string The lines to put after the agent lines, or '' when there is no conflict
	 */
	private static function conflictWarnings( array $rules ): string {
		$text = '';
		foreach ( self::SINGLE_VALUED_DIRECTIVES as $lower => $name ) {
			$values = [];
			foreach ( $rules as $rule ) {
				if ( self::hasDirective( $rule, $lower ) ) {
					$values[] = $rule['value'];
				}
			}
			// Exact duplicates are already gone, so two or more values are different values.
			if ( count( $values ) > 1 ) {
				$text .= "\n# RobotsTxt: conflicting " . $name . ' values in this group ('
					. implode( ', ', $values ) . ')';
			}
		}
		return $text;
	}

	/**
	 * The key that makes two rules exact duplicates: directive in lower case, value as written.
	 *
	 * @param array $rule A rule whose directive is not null
	 * @return string
	 */
	private static function duplicateKey( array $rule ): string {
		return mb_strtolower( $rule['directive'] ) . "\0" . $rule['value'];
	}

	/**
	 * @param array $rule
	 * @return bool Whether the line is an understood `Allow` or `Disallow`
	 */
	private static function isAllowOrDisallow( array $rule ): bool {
		return self::hasDirective( $rule, 'allow' ) || self::hasDirective( $rule, 'disallow' );
	}

	/**
	 * @param array $rule
	 * @return bool Whether the line is a `Disallow:` with no value
	 */
	private static function isEmptyDisallow( array $rule ): bool {
		return self::hasDirective( $rule, 'disallow' ) && $rule['value'] === '';
	}

	/**
	 * @param array $rule
	 * @param string $lowerName A directive name in lower case
	 * @return bool Whether the line is an understood directive with that name, in any case
	 */
	private static function hasDirective( array $rule, string $lowerName ): bool {
		return $rule['directive'] !== null && mb_strtolower( $rule['directive'] ) === $lowerName;
	}

	/**
	 * Turn the directives of one central agent into entries, in the order of the setting.
	 *
	 * @param array<string, list<string>> $directives Directive name to list of values
	 * @return array[] Entries without comments
	 */
	private static function centralEntries( array $directives ): array {
		$entries = [];
		foreach ( $directives as $name => $values ) {
			foreach ( $values as $value ) {
				$entries[] = self::makeEntry( (string)$name, $value );
			}
		}
		return $entries;
	}

	/**
	 * Build an entry that has no comments.
	 *
	 * @param string $directive
	 * @param string $value
	 * @return array
	 */
	private static function makeEntry( string $directive, string $value ): array {
		return [ 'directive' => $directive, 'value' => $value, 'comment' => null, 'before' => [] ];
	}

	/**
	 * Add one agent and its rules to the collection.
	 *
	 * An earlier agent of the same name is merged with, not duplicated.
	 *
	 * Each item of `$agents` is keyed by the lower-case agent name and holds `agent` (the entry of
	 * the first `User-agent` line, which fixes the spelling and the comments), `rules` (list of
	 * entries) and `seen` (the duplicate keys of the rules already added).
	 *
	 * @param array &$agents The collection, changed in place
	 * @param array $agentEntry The `User-agent` entry of this occurrence
	 * @param array[] $rules The rules that belong to this occurrence
	 */
	private static function addAgent( array &$agents, array $agentEntry, array $rules ): void {
		$key = mb_strtolower( $agentEntry['value'] );
		$carried = [];

		if ( !isset( $agents[$key] ) ) {
			$agents[$key] = [ 'agent' => $agentEntry, 'rules' => [], 'seen' => [] ];
		} else {
			// The agent line is dropped, but its comments must not be lost.
			$carried = $agentEntry['before'];
			if ( $agentEntry['comment'] !== null ) {
				$carried[] = $agentEntry['comment'];
			}
		}

		foreach ( $rules as $rule ) {
			// Only lines the parser understood can be duplicates.
			if ( $rule['directive'] !== null ) {
				$duplicateKey = self::duplicateKey( $rule );
				if ( isset( $agents[$key]['seen'][$duplicateKey] ) ) {
					continue;
				}
				$agents[$key]['seen'][$duplicateKey] = true;
			}
			// The carried comments go above the first rule that is really added.
			if ( $carried !== [] ) {
				$rule['before'] = array_merge( $carried, $rule['before'] );
				$carried = [];
			}
			$agents[$key]['rules'][] = $rule;
		}
	}

	/**
	 * Render several entries, one per line (comments above a line count as part of it).
	 *
	 * @param array[] $entries
	 * @return string No final line break
	 */
	private static function renderLines( array $entries ): string {
		return implode( "\n", array_map( [ self::class, 'renderEntry' ], $entries ) );
	}

	/**
	 * Render one entry: its comments above, then `Name: value` plus the inline comment.
	 *
	 * The directive keeps the spelling it had. A line the parser did not understand is printed as
	 * it was. An empty value prints as `Name:` with no trailing space.
	 *
	 * @param array $entry
	 * @return string No final line break
	 */
	private static function renderEntry( array $entry ): string {
		if ( $entry['directive'] === null ) {
			$line = $entry['value'];
		} else {
			$space = $entry['value'] === '' ? '' : ' ';
			$line = $entry['directive'] . ':' . $space . $entry['value'];
		}
		if ( $entry['comment'] !== null ) {
			$line .= ' ' . $entry['comment'];
		}

		$text = '';
		foreach ( $entry['before'] as $comment ) {
			$text .= $comment . "\n";
		}
		return $text . $line;
	}
}
