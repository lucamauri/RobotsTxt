<?php
/**
 * Hook handlers of the RobotsTxt extension.
 *
 * @file
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\RobotsTxt\Hooks;

use MediaWiki\Hook\TestCanonicalRedirectHook;

/**
 * Hook handlers of the RobotsTxt extension.
 *
 * All hooks of the extension live in this one class and are registered in
 * extension.json (keys "HookHandlers" and "Hooks"), not in PHP code. The class
 * has no constructor and no injected services on purpose: today every handler
 * can decide using only the arguments MediaWiki passes in. If a future handler
 * needs a service, add a "services" list to the HookHandlers entry.
 */
class RobotsTxtHooks implements TestCanonicalRedirectHook {

	/**
	 * Stop MediaWiki from redirecting the English name of Special:RobotsTxt.
	 *
	 * Why this exists: on a wiki whose content language is not English, the
	 * special page has a local name (for example "Speciale:RobotsTxt" in
	 * Italian). A request for "index.php?title=Special:RobotsTxt", which is
	 * what a web-server rewrite of /robots.txt would naturally target, makes
	 * MediaWiki answer with a 301 redirect to the local name. Crawlers would
	 * then receive an empty redirect instead of the rules. Aborting the
	 * redirect for this one page lets the same rewrite work on every wiki,
	 * whatever its language.
	 *
	 * How it works: MediaWiki calls this hook just before it would send the
	 * canonical-URL redirect, and only for plain page views (no query
	 * parameters other than "title" and "action"). Returning false aborts the
	 * redirect; returning true lets MediaWiki continue as usual.
	 *
	 * Because the hook runs for every such page view on the wiki, the check
	 * must stay cheap and narrow: it only inspects the title it is given,
	 * with no database access, and returns true for every other page so that
	 * their normal redirect behaviour is untouched.
	 *
	 * @param \MediaWiki\Request\WebRequest $request Current request (unused)
	 * @param \MediaWiki\Title\Title $title Title MediaWiki resolved for the request
	 * @param \MediaWiki\Output\OutputPage $output Output page (unused)
	 * @return bool False to abort the redirect (our page), true to continue
	 */
	public function onTestCanonicalRedirect( $request, $title, $output ) {
		// isSpecial() resolves aliases, so it matches both the English name
		// and any local name of the page.
		if ( $title->isSpecial( 'RobotsTxt' ) ) {
			return false;
		}

		return true;
	}
}
