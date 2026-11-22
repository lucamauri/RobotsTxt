<?php
/**
 * Hook handlers of the RobotsTxt extension.
 *
 * @file
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\RobotsTxt\Hooks;

use MediaWiki\Extension\RobotsTxt\RobotsTxtBuilder;
use MediaWiki\Hook\TestCanonicalRedirectHook;
use MediaWiki\Revision\Hook\ContentHandlerDefaultModelForHook;

/**
 * Hook handlers of the RobotsTxt extension.
 *
 * All hooks of the extension live in this one class and are registered in
 * extension.json (keys "HookHandlers" and "Hooks"), not in PHP code. The class
 * has no constructor and no injected services on purpose: today every handler
 * can decide using only the arguments MediaWiki passes in. If a future handler
 * needs a service, add a "services" list to the HookHandlers entry.
 */
class RobotsTxtHooks implements TestCanonicalRedirectHook, ContentHandlerDefaultModelForHook {

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

	/**
	 * Create MediaWiki:Robots.txt as plain text instead of wikitext.
	 *
	 * Why this exists: pages in the MediaWiki namespace default to the
	 * wikitext model, so an administrator who creates MediaWiki:Robots.txt
	 * gets a wikitext page and has to switch it by hand with
	 * Special:ChangeContentModel. Many then wrap the rules in <pre> tags to
	 * make them display, and those tags end up in the served file. Making
	 * "text" the default model for this one page removes that step and that
	 * trap.
	 *
	 * How it works: MediaWiki asks this hook for the default model of a page
	 * (MainSlotRoleHandler::getDefaultModel()) whenever no model is stored
	 * for it. $model is passed by reference. A handler that has decided
	 * writes the model into $model and returns false, which tells MediaWiki
	 * to use that value. Returning true means "no opinion": MediaWiki carries
	 * on with its own rules, and we must leave $model exactly as we found it.
	 *
	 * Scope: only the exact title MediaWiki:Robots.txt. Subpages (such as
	 * MediaWiki:Robots.txt/it, the translation variants of system messages)
	 * and the same name in other namespaces are not touched. For this title
	 * our answer wins over a model set earlier, for example by
	 * $wgNamespaceContentModels or another extension: a rule for one exact
	 * page is more specific than a rule for a whole namespace.
	 *
	 * Existing pages are not affected. MediaWiki stores the model of a page
	 * every time it is saved, and consults this hook only when nothing is
	 * stored. A page that already exists keeps the model it has.
	 *
	 * Because the hook runs whenever MediaWiki needs the default model of any
	 * page, the check must stay cheap: the cheapest comparison (the
	 * namespace) comes first, the title text is read only for pages in the
	 * MediaWiki namespace, and there is no database access.
	 *
	 * @param \MediaWiki\Title\Title $title Title in question
	 * @param string &$model Model name; set to the "text" model for our page
	 * @return bool False when we chose the model (our page), true for no opinion
	 */
	public function onContentHandlerDefaultModelFor( $title, &$model ) {
		if ( $title->getNamespace() !== NS_MEDIAWIKI ) {
			return true;
		}

		// The page name is shared with the builder that reads the page, so
		// the two cannot drift apart.
		if ( $title->getText() !== RobotsTxtBuilder::PAGE_NAME ) {
			return true;
		}

		$model = CONTENT_MODEL_TEXT;
		return false;
	}
}
