<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Special page aliases for the RobotsTxt extension.
 *
 * Why this is a separate PHP file: MediaWiki reads special page names from
 * a PHP array, not from the JSON message files. It is registered in
 * extension.json under ExtensionMessagesFiles with a key ("RobotsTxtAlias")
 * that is deliberately different from the MessagesDirs key ("RobotsTxt"),
 * because two registrations sharing a key silently mask each other in the
 * localisation cache.
 *
 * @file
 */

$specialPageAliases = [];

/** English */
$specialPageAliases['en'] = [
	'RobotsTxt' => [ 'RobotsTxt' ],
];