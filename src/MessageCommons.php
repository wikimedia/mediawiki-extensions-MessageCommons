<?php
/**
 * MessageCommons - Shared MediaWiki messages for wiki farms
 *
 * @file
 * @ingroup Extensions
 * @author Daniel Friesen <dan_the_man@telus.net>
 * @author Nathaniel Herman <pinky@shoutwiki.com>
 * @author Jack Phoenix <jack@shoutwiki.com>
 * @license GPL-2.0-or-later
 * @note Some techniques borrowed from Wikia's SharedMessages system
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;

class MessageCommons {

	/**
	 * @param string $title
	 * @param string &$message
	 * @param string $code
	 * @return bool
	 */
	public static function onMessagesPreLoad( $title, &$message, $code ) {
		global $wgLanguageCode, $wgMessageCommonsIsCommons, $wgMessageCommonsLang;

		if ( $wgMessageCommonsIsCommons ) {
			return true;
		}

		$text = null;

		$msgName = $title;
		if ( strpos( $msgName, '/' ) === false ) {
			$msgNames[] = sprintf( '%s/%s', $msgName, $code );
			// if $code is the same language as message commons, check the global msg before checking
			// if $wgLanguageCode has a message on the wiki
			if ( $code == $wgMessageCommonsLang ) {
				$msgNames[] = $msgName;
			}
			$msgNames[] = sprintf( '%s/%s', $msgName, $wgLanguageCode );
		}
		// do this last
		$msgNames[] = $msgName;
		$msgNames = array_unique( $msgNames );

		foreach ( $msgNames as $msgName ) {
			$text = self::getMsg( $msgName );
			if ( isset( $text ) ) {
				break;
			}
		}

		if ( isset( $text ) ) {
			$message = $text;
			return false;
		}

		return true;
	}

	/**
	 * Fetches a MediaWiki message from $wgMessageCommonsDatabase DB
	 *
	 * @param string $msg Name of a MediaWiki: message that we want to fetch
	 * @return string|false Text the text requested or false on failure
	 */
	public static function getMsg( $msg ) {
		global $wgMessageCommonsDatabase;

		$title = Title::makeTitle( NS_MEDIAWIKI, $msg );
		$dbr = wfGetDB( DB_REPLICA, [], $wgMessageCommonsDatabase );
		$row = $dbr->selectRow(
			[ 'page', 'revision', 'text', 'slots', 'content' ],
			[ '*' ],
			[
				'page_namespace' => $title->getNamespace(),
				'page_title' => $title->getDBkey(),
				'page_latest = rev_id',
				'page_latest = slot_revision_id',
				'old_id = slot_content_id'
			],
			__METHOD__
		);

		if ( !$row ) {
			return null;
		}

		$revisionStore = MediaWikiServices::getInstance()->getRevisionStoreFactory()->getRevisionStore( $wgMessageCommonsDatabase );
		$rev = $revisionStore->getRevisionByTitle( $title );
		$content = $rev->getSlot( SlotRecord::MAIN )->getContent();
		return $content instanceof TextContent ? $content->getText() : false;
	}

	/**
	 * Preload the shared MediaWiki: message text to the edit area
	 *
	 * @param EditPage &$editPage
	 * @return bool
	 */
	public static function onEditPage( &$editPage ) {
		global $wgLang;
		global $wgMessageCommonsIsCommons, $wgMessageCommonsLang;

		// don't initialize for MessageCommons wiki
		if ( $wgMessageCommonsIsCommons ) {
			return true;
		}

		$title = $editPage->getTitle();
		// only initialize this when editing pages in MediaWiki namespace
		if ( $title->getNamespace() != 8 ) {
			return true;
		}

		// Make sure that this variable is initialized
		// If not, viewing a MW: page in edit mode in your language and in the wiki's content
		// language will work, but viewing it in some other language fails
		//
		// Only do this for NONEXISTENT messages - otherwise we'll have a customized message
		// that we really can't customize because this crap loads the shared stuff from
		// MessageCommons wiki, preventing local customization x__x
		if ( !$title->exists() ) {
			$page = $title->getDBkey();
			if ( strpos( $title->getDBkey(), '/' ) === false ) {
				$contLang = MediaWikiServices::getInstance()->getContentLanguage();
				// if $wgLang is the same language as MessageCommons, check the
				// global msg before checking if $contLang has a message on the wiki
				if ( $wgLang->getCode() == $wgMessageCommonsLang ) {
					$page = sprintf( '%s/%s', $title->getDBkey(), $wgLang->getCode() );
				}
				$page = sprintf( '%s/%s', $title->getDBkey(), $contLang->getCode() );
			}

			// fetch text from MessageCommons wiki
			$text = self::getMsg( $page );

			// show text in textarea if we have something to show and we're not in preview mode
			if ( $text && !$editPage->preview ) {
				$editPage->textbox1 = $text;
			}
		}

		return true;
	}

	/**
	 * Extension registration callback
	 */
	public static function onRegistration() {
		global $wgWikimediaJenkinsCI, $wgMessageCommonsDatabase, $wgDBname;

		// Override $wgMessageCommonsDatabase for Wikimedia Jenkins.
		if ( isset( $wgWikimediaJenkinsCI ) && $wgWikimediaJenkinsCI ) {
			$wgMessageCommonsDatabase = $wgDBname;
		}
	}
}
