<?php

namespace MediaWikiAuth;

use Maintenance;
use User;

if ( getenv( 'MW_INSTALL_PATH' ) ) {
	$IP = getenv( 'MW_INSTALL_PATH' );
} else {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

class ReattributeImportedEdits extends Maintenance {
	const OPT_USER = 'user';

	public function __construct() {
		parent::__construct();

		$this->addOption(
			self::OPT_USER,
			'Username to update. If not specified, all users will be updated.',
			false, // not required
			true // requires argument
		);
		$this->requireExtension( 'MediaWikiAuth' );
	}

	public function execute() {
		$dbw = wfGetDB( DB_MASTER );
		$singleUser = false;

		if ( $this->hasOption( self::OPT_USER ) ) {
			$user = User::newFromName( $this->getOption( self::OPT_USER ) );

			if ( $user === null || $user->getId() === 0 ) {
				$this->fatalError( "User {$user} does not exist.\n", 1 );
				return; // never actually get here; error() calls die()
			}

			$singleUser = $user->getName();
		}

		foreach ( ReattributeEdits::getTableMetadata() as $table => $metadata ) {
			[ $tableKey, $actorKey, $userText, $userKey ] = $metadata;

			// not every DMBS supports joins on update, and those that do all
			// do it different ways. Subqueries are therefore more portable.
			$conds = [];
			$setList = [];

			if ( ReattributeEdits::useActorSchema( $this->getConfig() ) ) {
				$actorData = ReattributeEdits::getActorMigrationData( $dbw, $singleUser );
				if ( $actorData === [] ) {
					$this->output( "Nothing needs to be done.\n" );
					return;
				}

				$case = "CASE {$actorKey}";
				foreach ( $actorData as $old => $new ) {
					$case .= " WHEN {$old} THEN {$new}";
				}
				$case .= " ELSE {$actorKey} END";

				$setList[] = "{$actorKey} = {$case}";
				$conds[$actorKey] = array_keys( $actorData );
			} else {
				$conds[$userKey] = 0;
				$subquery = $dbw->selectSQLText('user', 'user_id', "user_name = $userText", __METHOD__ . ':subquery');

				if ($singleUser !== false) {
					$conds[$userText] = $singleUser;
				}
				else {
					$conds[] = "EXISTS({$subquery})";
				}

				$setList[] = "{$userKey} = ({$subquery})";
			}

			$this->output( "Updating {$table} (this may take a few minutes)...\n" );
			$success = $dbw->update( $table, $setList, $conds, __METHOD__ . ':update' );

			if ( $success ) {
				$rows = $dbw->affectedRows();
				$this->output( "Updated {$rows} records on {$table}.\n" );
			} else {
				$this->error( "Unable to update table {$table}.\n" );
			}
		}

		// fix up the log_search table too. This has a much different table layout, so cannot be easily rolled into
		// the above loop. We need to look at ls_type, it's either target_author_ip for pre-actor or
		// target_author_actor for post-actor schemas.
		$this->output( "Updating log_search (this may take a few minutes)...\n" );
		$conds = [];
		$setList = [];
		if ( ReattributeEdits::useActorSchema( $this->getConfig() ) ) {
			$migrateData = ReattributeEdits::getActorMigrationData( $dbw, $singleUser );
			$conds['ls_type'] = 'target_author_actor';
		} else {
			$migrateData = [];
			$res = $dbw->select(
				[ 'log_search', 'user' ],
				[ 'user_id', 'user_name' ],
				[ 'ls_value = user_name', 'ls_type' => 'target_author_ip' ]
			);

			foreach ( $res as $row ) {
				$migrateData[$row['user_name']] = $row['user_id'];
			}

			if ( $migrateData === [] ) {
				$this->output( "Updated 0 records on log_search.\n" );
				return;
			}

			$setList['ls_type'] = 'target_author_id';
			$conds['ls_type'] = 'target_author_ip';
		}

		$case = "CASE ls_value";
		foreach ( $migrateData as $old => $new ) {
			$case .= " WHEN {$old} THEN {$new}";
		}
		$case .= " ELSE ls_value END";

		$setList[] = "ls_value = {$case}";
		$conds['ls_value'] = array_keys( $migrateData );

		$success = $dbw->update( 'log_search', $setList, $conds, __METHOD__ . ':update' );

		if ( $success ) {
			$rows = $dbw->affectedRows();
			$this->output( "Updated {$rows} records on log_search.\n" );
		} else {
			$this->error( "Unable to update table log_search.\n" );
		}
	}
}

$maintClass = 'MediaWikiAuth\ReattributeImportedEdits';
require_once RUN_MAINTENANCE_IF_MAIN;
