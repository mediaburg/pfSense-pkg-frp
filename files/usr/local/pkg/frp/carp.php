#!/usr/local/bin/php
<?php
// Read-only role gate except for the small node-local eligibility marker.
if (PHP_SAPI !== 'cli' || !in_array($argv[1] ?? '', ['check', 'guard'], true)) { exit(1); }
require_once('config.inc');
require_once('functions.inc');
require_once('/usr/local/pkg/frp/frp.inc');
try {
	$status = frp_carp_status();
	$status['allowed'] = frp_is_enabled() && $status['allowed'];
	// Pre-start records eligibility. A watchdog records only lost eligibility,
	// leaving the next MASTER transition for the CARP callback/monitor to start.
	if (($argv[1] === 'check' || !$status['allowed']) && !frp_carp_record($status)) { exit(1); }
	exit($status['allowed'] ? 0 : 1);
} catch (Throwable $error) { exit(1); }
