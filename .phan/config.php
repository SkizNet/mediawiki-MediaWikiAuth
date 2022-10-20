<?php

// use phan config shipped with mediawiki core
$cfg = require __DIR__ . '/../../../vendor/mediawiki/mediawiki-phan-config/src/config.php';

// we suppress things for backwards compat reasons, so suppressions may not apply to all phan test runs
// as part of dropping support for old versions, old suppressions will be removed
$cfg['suppress_issue_types'][] = 'UnusedSuppression';
$cfg['suppress_issue_types'][] = 'UnusedPluginSuppression';

// there are some methods we always throw exceptions in,
// but do not wish to modify the original doc comment's return type
$cfg['suppress_issue_types'][] = 'PhanPluginNeverReturnMethod';

// needed for backwards compatibility checks while still enabling good IDE completion
$cfg['suppress_issue_types'][] = 'PhanUndeclaredTypeProperty';
$cfg['suppress_issue_types'][] = 'PhanUndeclaredTypeParameter';

return $cfg;
