<?php

namespace MediaWikiAuth;

class Setup {
	/**
	 * extension.json callback function
	 *
	 * Sets up constants required for backwards compatibility.
	 */
	public static function callback() {
		if ( !defined( 'DB_PRIMARY' ) ) {
			// 1.35 compat
			// phpcs:disable MediaWiki.Usage.DeprecatedConstantUsage.DB_MASTER
			define( 'DB_PRIMARY', DB_MASTER );
		}
	}
}
