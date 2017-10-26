<?php

namespace MediaWikiAuth;

use Title;
use User;

class PopulateImportedWatchlistJob extends \Job {
	/**
	 * Construct a new watchlist import job.
	 *
	 * @param $title Title unused
	 * @param $params Array of the format [
	 *     'username' => string username of the user whose watchlist is being modified
	 *     'pages' => array of objects to add to the watchlist. The objects have an 'ns', 'title', and sometimes 'changed'
	 *                (ns is an int, title is the prefixed page name, changed is a datetime string)
	 * ]
	 */
	public function __construct( $title, $params ) {
		parent::__construct( 'populateImportedWatchlist', $title, $params );
	}

	public function run() {
		$user = User::newFromName( $this->params['username'] );
		if ( $user === null || $user->getId() === 0 ) {
			throw new \BadMethodCallException( "Attempting to import watchlist pages for nonexistent user {$this->params['username']}." );
		}

		foreach ( $this->params['pages'] as $page ) {
			$title = Title::makeTitleSafe( $page->ns, $page->title );
			if ( $title === null ) {
				// do not error out import on invalid titles, as it could just mean that config
				// is different between our wiki and the external wiki such that this title isn't valid.
				continue;
			}

			$user->addWatch( $title, User::IGNORE_USER_RIGHTS );
			// TODO: we currently do nothing with $page->changed (i.e. the title is added as cleared instead of dirty in all cases).
			// we may wish to import changed as well (as wl_notificationtimestamp in the db). Note that $page->changed may not exists,
			// need to test with if ( isset( $page->changed ) ) before doing anything with it.
		}
	}
}
