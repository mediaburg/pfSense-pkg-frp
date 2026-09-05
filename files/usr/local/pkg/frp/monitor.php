#!/usr/local/bin/php
<?php
require_once('config.inc');
require_once('functions.inc');
require_once('/usr/local/pkg/frp/frp.inc');
try {
	frp_monitor_run();
} catch (Throwable $e) {
	// A concurrent save/restart owns the lock. The next cron tick checks again.
	exit(1);
}
