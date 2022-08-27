<?php

namespace MediaWikiAuth;

use BadMethodCallException;
use Job;
use Title;
use User;

class ReattributeImportedEditsJob extends Job {
	/**
	 * Construct a new edit reattribution job.
	 *
	 * @param Title $title unused
	 * @param array $params Array of the format [
	 *     'username' => string username of the user whose edits we are reattributing
	 *     'table' => string table name to operate on (without prefix)
	 *     'ids' => array of row ids in the table to update this job
	 * ]
	 */
	public function __construct( $title, $params ) {
		parent::__construct( 'reattributeImportedEdits', $title, $params );
	}

	public function run() {
		$user = User::newFromName( $this->params['username'] );
		if ( $user === false || $user->getId() === 0 ) {
			throw new BadMethodCallException(
				"Attempting to reattribute edits for nonexistent user {$this->params['username']}."
			);
		}

		if ( $this->params['table'] === 'log_search' ) {
			// log_search is different enough to need its own logic. It's split into a separate function to avoid
			// making this one overly complicated.
			return $this->runLogSearch( $user );
		}

		$dbw = wfGetDB( DB_PRIMARY );
		[ $tableKey, $actorKey, $userText, $userKey ] = ReattributeEdits::getTableMetadata( $this->params['table'] );

		$setList = [];
		$conds = [ $tableKey => $this->params['ids'] ];

		if ( $actorKey === null ) {
			// table doesn't support actors, skip it
			return true;
		}

		[ $oldActor, $newActor ] = ReattributeEdits::getActorMigrationData( $dbw, $user->getName() );
		$setList[$actorKey] = $newActor;
		$conds[$actorKey] = $oldActor;

		$dbw->update( $this->params['table'], $setList, $conds, __METHOD__ );

		return true;
	}

	/**
	 * @param User $user
	 * @return bool
	 */
	private function runLogSearch( User $user ) {
		$dbw = wfGetDB( DB_PRIMARY );
		$setList = [];
		$conds = [ 'ls_log_id' => $this->params['ids'] ];

		[ $oldActor, $newActor ] = ReattributeEdits::getActorMigrationData( $dbw, $user->getName() );
		$setList['ls_value'] = $newActor;
		$conds['ls_value'] = $oldActor;
		$conds['ls_field'] = 'target_author_actor';

		$dbw->update( $this->params['table'], $setList, $conds, __METHOD__ );

		return true;
	}
}
