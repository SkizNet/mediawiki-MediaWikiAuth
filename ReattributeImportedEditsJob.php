<?php

namespace MediaWikiAuth;

use User;

class ReattributeImportedEditsJob extends \Job {
	/**
	 * Construct a new edit reattribution job.
	 *
	 * @param $title Title unused
	 * @param $params Array of the format [
	 *     'username' => string username of the user whose edits we are reattributing
	 *     'id_start' => mixed id of the revision/log we're starting at to reattribute
	 *     'id_end' => mixed id of the revision/log we're ending at (inclusive)
	 *     'table' => string table name to operate on (without prefix)
	 *     'idkey' => string field containing table id
	 *     'namekey' => string field containing username to look up
	 *     'fields' => array of string fields containing user ids to modify
	 * ]
	 */
	public function __construct( $title, $params ) {
		parent::__construct( 'reattributeImportedEdits', $title, $params );
	}

	public function run() {
		$user = User::newFromName( $this->params['username'] );
		if ( $user === null || $user->getId() === 0 ) {
			throw new \BadMethodCallException( "Attempting to reattribute edits for nonexistent user {$this->params['username']}." );
		}

		$updateFields = array_fill_keys( $this->params['fields'], $user->getId() );

		$dbw = wfGetDB( DB_MASTER );
		$conds = [ $this->params['namekey'] => $user->getName() ];
		$id1 = $dbw->addQuotes( $this->params['id_start'] );
		$id2 = $dbw->addQuotes( $this->params['id_end'] );

		if ( $this->params['id_start'] === false && $this->params['id_end'] !== false ) {
			$conds[] = "{$this->params['idkey']} <= {$id2}";
		} elseif ( $this->params['id_start'] !== false && $this->params['id_end'] === false ) {
			$conds[] = "{$this->params['idkey']} >= {$id1}";
		} elseif ( $this->params['id_start'] !== false && $this->params['id_end'] !== false ) {
			$conds[] = "{$this->params['idkey']} BETWEEN {$id1} AND {$id2}";
		}

		$dbw->update( $this->params['table'], $updateFields, $conds, __METHOD__ );

		return true;
	}

}
