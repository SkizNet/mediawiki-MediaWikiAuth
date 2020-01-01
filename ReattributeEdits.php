<?php


namespace MediaWikiAuth;


use Config;
use Wikimedia\Rdbms\IDatabase;

class ReattributeEdits {
	public static function getActorMigrationData( IDatabase $db, $username ) {
		static $cached = [];
		if ( array_key_exists( $username, $cached ) ) {
			return $cached[$username];
		}

		$conds = ['a1.actor_name = a2.actor_name', 'a1.actor_user IS NULL', 'a2.actor_user IS NOT NULL'];
		if ( $username !== false ) {
			$conds['a1.actor_name'] = $username;
		}

		$res = $db->select(
			['a1' => 'actor', 'a2' => 'actor'],
			['old_actor' => 'a1.actor_id', 'new_actor' => 'a2.actor_id'],
			$conds
		);

		$cached[$username] = [];
		foreach ( $res as $row ) {
			$cached[$username][$row['old_actor']] = $row['new_actor'];
		}

		return $cached[$username];
	}

	public static function getTableMetadata( $table = null ) {
		// Note that only tables which are used in the XML dump import process (plus recentchanges) are updated.
		$metadata = [
			'archive' => [ 'ar_id', 'ar_actor', 'ar_user_text', 'ar_user' ],
			'filearchive' => [ 'fa_id', 'fa_actor', 'fa_user_text', 'fa_user' ],
			'image' => [ 'img_name', 'img_actor', 'img_user_text', 'img_user' ],
			'logging' => [ 'log_id', 'log_actor', 'log_user_text', 'log_user' ],
			'oldimage' => [ 'oi_name', 'oi_actor', 'oi_user_text', 'oi_user' ],
			'recentchanges' => [ 'rc_id', 'rc_actor', 'rc_user_text', 'rc_user' ],
			'revision' => [ 'rev_id', null, 'rev_user_text', 'rev_user' ],
			'revision_actor_temp' => [ 'revactor_rev', 'revactor_actor', null, null ]
		];

		if ( $table !== null ) {
			return $metadata[$table];
		}

		return $metadata;
	}

	public static function useActorSchema( Config $config ) {
		if ( $config->has( 'ActorTableSchemaMigrationStage' ) ) {
			// 1.31-1.33
			$actorMigrationStage = $config->get( 'ActorTableSchemaMigrationStage' );
		} else {
			// 1.34+
			$actorMigrationStage = SCHEMA_COMPAT_NEW;
		}

		if ( MIGRATION_OLD === 0 ) {
			// 1.31
			return $actorMigrationStage === MIGRATION_WRITE_NEW || $actorMigrationStage === MIGRATION_NEW;
		} else {
			// 1.32+
			return $actorMigrationStage === SCHEMA_COMPAT_NEW;
		}
	}
}
