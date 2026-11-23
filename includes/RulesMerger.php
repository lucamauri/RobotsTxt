<?php
/**
 * Merging of the central rules with the rules of the page (step E, batch E-3a).
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
 * 4. Join again the agents whose rule lists are identical, comments included, into one group
 *    placed where the first of them was. Without this step, every shared group would come out
 *    split in one group per agent.
 * 5. Render: preamble, groups, closing comments (the footer), then the `Sitemap:` lines of the
 *    page. Sections are separated by one blank line and the text ends with one line break.
 *
 * Comments: a comment above or beside a line stays with that line. When a page group is merged
 * into an agent that already exists, its `User-agent` line disappears, and the comments of that
 * line (above and inline) move onto the first rule the group adds. If the group adds no rule
 * (all duplicates, or none), those comments are dropped. The comments of a removed duplicate
 * rule are dropped with it.
 *
 * Not in this batch (E-3b): wildcard inheritance, the rule about the empty `Disallow:` line, and
 * the warning for conflicting `Crawl-delay` values.
 *
 * The class is pure (no MediaWiki dependency), so it is unit-tested alone.
 *
 * An "entry" below is one line as RobotsTxtParser::parse() describes it: an array with the keys
 * `directive` (?string, null for a line that could not be understood), `value` (string),
 * `comment` (?string, including the `#`) and `before` (list of full-line comments above).
 */
class RulesMerger {

	/**
	 * Merge the central rules with a parsed robots.txt text and render the result.
	 *
	 * @param array<string, array<string, list<string>>> $central Output of
	 *  CentralRulesValidator::validateRules()['rules']: agent to directive name to list of values
	 * @param array $parsed Output of RobotsTxtParser::parse() for the page or for the defaults
	 * @param bool|string[] $inherit Output of CentralRulesValidator::validateInherit()['inherit'].
	 *  Not used yet: it is accepted now so the signature does not change in batch E-3b.
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

		// Step 4: agents with the same rule lines share a group. The rendered rules are the
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
			$sections[] = self::renderLines( array_merge( $group['agents'], $group['rules'] ) );
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
				$duplicateKey = mb_strtolower( $rule['directive'] ) . "\0" . $rule['value'];
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
