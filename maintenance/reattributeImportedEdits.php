<?php

namespace MediaWikiAuth;

use Wikimedia\Rdbms\Database;

if ( getenv( 'MW_INSTALL_PATH' ) ) {
	$IP = getenv( 'MW_INSTALL_PATH' );
} else {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

class ReattributeImportedEdits extends \Maintenance {
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
			$user = \User::newFromName( $this->getOption( self::OPT_USER ) );

			if ( $user === null || $user->getId() === 0 ) {
				$this->error( "User {$user} does not exist.\n", 1 );
				return; // never actually get here; error() calls die()
			}

			$singleUser = $user->getName();
		}

		foreach ( self::getTableMetadata() as $table => $metadata ) {
			foreach ( $metadata[1] as $nameKey => $fields ) {
				// not every DMBS supports joins on update, and those that do all
				// do it different ways. Subqueries are therefore more portable.
				$conds = array_fill_keys( $fields, 0 );
				$setList = [];

				$subquery = $dbw->selectSQLText(
					'user',
					'user_id',
					"user_name = $nameKey",
					__METHOD__ . ':subquery'
				);

				if ( $singleUser !== false ) {
					$conds[$nameKey] = $singleUser;
				} else {
					$conds[] = "EXISTS($subquery)";
				}

				foreach ( $fields as $field ) {
					$setList[] = "$field = ($subquery)";
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
		}
	}

	public static function getTableMetadata() {
		// Note that only tables which are used in the XML dump import process (plus recentchanges) are updated.
		return [
			'archive' => [ 'ar_id', [ 'ar_user_text' => [ 'ar_user' ] ] ],
			'filearchive' => [ 'fa_id', [ 'fa_user_text' => [ 'fa_user' ] ] ],
			// img_name is the PK, and PKs are clustered on InnoDB, so we can sensibly use BETWEEN
			'image' => [ 'img_name', [ 'img_user_text' => [ 'img_user' ] ] ],
			'logging' => [ 'log_id', [ 'log_user_text' => [ 'log_user' ] ] ],
			'oldimage' => [ 'oi_name', [ 'oi_user_text' => [ 'oi_user' ] ] ],
			'recentchanges' => [ 'rc_id', [ 'rc_user_text' => [ 'rc_user' ] ] ],
			'revision' => [ 'rev_id', [ 'rev_user_text' => [ 'rev_user' ] ] ]
		];
	}
}

$maintClass = 'MediaWikiAuth\ReattributeImportedEdits';
require_once RUN_MAINTENANCE_IF_MAIN;
