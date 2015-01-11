<?php
/**
 * MediaWikiAuth extension -- imports logins from an external MediaWiki instance
 * Requires Snoopy.class.php.
 *
 * @file
 * @ingroup Extensions
 * @version 0.7.1
 * @author Laurence "GreenReaper" Parry
 * @author Jack Phoenix
 * @author Kim Schoonover
 * @author Ryan Schmidt
 * @copyright © 2009-2010 Laurence "GreenReaper" Parry
 * @copyright © 2010-2013 Jack Phoenix, Ryan Schmidt
 * @copyright © 2012-2013 Kim Schoonover
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 * @link https://www.mediawiki.org/wiki/Extension:MediaWikiAuth Documentation
 */
# This program is free software; you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation; either version 2 of the License, or
# (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License along
# with this program; if not, write to the Free Software Foundation, Inc.,
# 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
# http://www.gnu.org/copyleft/gpl.html

if ( !defined( 'MEDIAWIKI' ) ) {
	die( "This is not a valid entry point.\n" );
}

# Extension credits
$wgExtensionCredits['other'][] = array(
	'path' => __FILE__,
	'name' => 'MediaWikiAuth',
	'author' => array( 'Laurence Parry', 'Jack Phoenix', 'Kim Schoonover', 'Ryan Schmidt' ),
	'version' => '0.8.0',
	'url' => 'https://www.mediawiki.org/wiki/Extension:MediaWikiAuth',
	'description' => 'Authenticates against and imports logins from an external MediaWiki instance',
);

# Stuff
$wgMessagesDirs['MediaWikiAuth'] = __DIR__ . '/i18n';
$wgExtensionMessagesFiles['MediaWikiAuth'] = dirname( __FILE__ ) . '/MediaWikiAuth.i18n.php';

$wgAutoloadClasses['MediaWikiAuthPlugin'] = __DIR__ . '/MediaWikiAuthPlugin.class.php';