<?php

require_once("config.inc");
require_once("functions.inc");
require_once("notices.inc");

define('FRP_CONFIG_PATH', '/usr/local/etc/frpc.toml');
define('FRP_BINARY_PATH', '/usr/local/bin/frpc');
define('FRP_RCCONF_DIR', '/usr/local/etc/rc.conf.d');
define('FRP_RCCONF_PATH', FRP_RCCONF_DIR . '/frpc_pfsense');
define('FRP_RC_SCRIPT', '/usr/local/etc/rc.d/frpc-pfsense');
define('FRP_PID_PATH', '/var/run/frpc-pfsense.pid');
define('FRP_LOG_PATH', '/var/log/frp.log');
define('FRP_DOC_URL', 'https://github.com/fatedier/frp');
define('FRP_MAX_CONFIG_BYTES', 262144);
define('FRP_STATE_DIR', '/usr/local/etc/frp');
define('FRP_CERT_DIR', FRP_STATE_DIR . '/certificates');
define('FRP_APPLIED_PATH', FRP_STATE_DIR . '/applied.toml');
define('FRP_MONITOR_PATH', '/var/run/frp-monitor.json');
define('FRP_CARP_PATH', '/var/run/frp-carp.json');
define('FRP_LOCK_PATH', '/var/run/frp-config.lock');
define('FRP_MONITOR_COMMAND', '/usr/local/bin/php -f /usr/local/pkg/frp/monitor.php');

require_once(__DIR__ . '/frp_config.inc');
require_once(__DIR__ . '/frp_runtime.inc');
require_once(__DIR__ . '/frp_operations.inc');
require_once(__DIR__ . '/frp_ha.inc');
require_once(__DIR__ . '/frp_carp.inc');

function frp_get_config() {
	return config_get_path('installedpackages/frp/config/0', []);
}

function frp_is_enabled($settings = null) {
	if ($settings === null) {
		$settings = frp_get_config();
	}

	return isset($settings['enable']) && ($settings['enable'] == 'on');
}

function frp_decode_toml($settings) {
	$toml = $settings['toml'] ?? '';
	if (!is_string($toml)) {
		return '';
	}
	$encoded = preg_replace('/\s+/', '', $toml);
	$decoded = base64_decode($encoded, true);

	if (($decoded !== false) && (base64_encode($decoded) === $encoded)) {
		return $decoded;
	}

	return $toml;
}

function frp_normalize_toml($toml) {
	return rtrim(unixnewlines($toml)) . "\n";
}

function frp_redact_sensitive($text) {
	// Cover TOML/JSON quoting, escaped quotes, and common FRP secret fields.
	$pattern = <<<'REGEX'
~(\b(?:token|password|secret|secretKey|groupKey|clientSecret|httpPwd|sk|accessKeySecret)["']?\s*[=:]\s*)(?:"""[\s\S]*?(?:"""|$)|'''[\s\S]*?(?:'''|$)|"(?:\\.|[^"\\])*(?:"|$)|'[^']*(?:'|$)|[^\s,;}]+)~i
REGEX;
	return preg_replace($pattern, '$1<redacted>', $text);
}

function frp_toml_hash($toml) {
	return hash('sha256', frp_normalize_toml($toml));
}

function frp_get_runtime_toml() {
	return frp_read_file(FRP_CONFIG_PATH);
}

function frp_read_file($path) {
	clearstatcache(true, $path);
	if (is_link($path) || !is_file($path)) { return null; }
	$data = @file_get_contents($path, false, null, 0, FRP_MAX_CONFIG_BYTES + 1);
	return ($data === false || strlen($data) > FRP_MAX_CONFIG_BYTES) ? null : unixnewlines($data);
}

function frp_runtime_config_differs($settings = null) {
	if ($settings === null) {
		$settings = frp_get_config();
	}

	$runtime_toml = frp_get_runtime_toml();
	if ($runtime_toml === null) {
		return false;
	}

	$runtime_hash = frp_toml_hash($runtime_toml);
	$stored_hash = $settings['runtime_hash'] ?? $settings['toml_hash'] ?? '';
	if ($stored_hash != '') {
		return !hash_equals($stored_hash, $runtime_hash);
	}

	return !hash_equals(frp_toml_hash(frp_decode_toml($settings)), $runtime_hash);
}

function frp_log($message) {
	// syslogd owns the file and reopens it after pfSense rotates package logs.
	mwexec('/usr/bin/logger -p daemon.info -t frpc -- ' .
	    escapeshellarg(frp_redact_sensitive($message)));
}

function frp_validate_toml($toml, &$output = '') {
	if (!is_string($toml) || (trim($toml) === '')) {
		$output = gettext('The frpc.toml configuration must not be empty.');
		return false;
	}
	$toml = frp_normalize_toml($toml);
	if (strlen($toml) > FRP_MAX_CONFIG_BYTES) {
		$output = sprintf(gettext('Configuration is larger than %d bytes.'), FRP_MAX_CONFIG_BYTES);
		return false;
	}
	if (!is_executable(FRP_BINARY_PATH)) {
		$output = gettext('frpc is not installed or is not executable. Install the net/frp dependency (version 0.52.0 or newer).');
		return false;
	}

	$tmpfile = tempnam('/tmp', 'frp-');
	if ($tmpfile === false) {
		$output = gettext('Unable to create a temporary file for validation.');
		return false;
	}

	if (!@chmod($tmpfile, 0600) || (@file_put_contents($tmpfile, $toml) !== strlen($toml))) {
		@unlink($tmpfile);
		$output = gettext('Unable to write the temporary configuration for validation.');
		return false;
	}

	// Use the same working directory as the rc.d wrapper.
	[$rc, $output] = frp_exec([FRP_BINARY_PATH, 'verify', '-c', $tmpfile]);
	@unlink($tmpfile);
	return ($rc == 0);
}

function frp_validate_input($post, &$input_errors) {
	// The generic pkg_edit.php form lacks revision checks and package options.
	// Keep old bookmarks readable, but route all saves through the dedicated UI.
	if (!is_array($input_errors)) { $input_errors = []; }
	$input_errors[] = gettext('Use Services > FRP Client to review and save this configuration.');
}

function frp_atomic_write($path, $contents, $mode) {
	clearstatcache(true, $path);
	if (is_link($path) || (file_exists($path) && !is_file($path))) {
		return false;
	}
	// Stage beside the destination: rename stays atomic on the same filesystem.
	$tmpfile = @tempnam(dirname($path), '.frp-');
	if ($tmpfile === false) {
		return false;
	}
	$ok = (realpath(dirname($tmpfile)) === realpath(dirname($path))) &&
	    @chmod($tmpfile, $mode) &&
	    (@file_put_contents($tmpfile, $contents) === strlen($contents)) &&
	    @rename($tmpfile, $path);
	if (!$ok) {
		@unlink($tmpfile);
	}
	return $ok;
}

function frp_write_config($settings = null) {
	if ($settings === null) {
		$settings = frp_get_config();
	}

	$toml = frp_decode_toml($settings);
	$has_toml = trim($toml) !== '';

	$toml = frp_normalize_toml($toml);
	if (strlen($toml) > FRP_MAX_CONFIG_BYTES) {
		frp_log('Refused to write oversized configuration.');
		return false;
	}

	$enabled = frp_is_enabled($settings) ? 'YES' : 'NO';
	if (!is_dir(FRP_RCCONF_DIR) && !@mkdir(FRP_RCCONF_DIR, 0755, true)) {
		frp_log('Unable to create the service configuration directory.');
		return false;
	}
	// On first install leave any console-managed frpc.toml available for import.
	if (($has_toml && !frp_atomic_write(FRP_CONFIG_PATH, $toml, 0600)) ||
	    !frp_atomic_write(FRP_RCCONF_PATH, "frpc_pfsense_enable=\"{$enabled}\"\n", 0644)) {
		frp_log('Unable to write the runtime or service configuration; service not restarted.');
		return false;
	}
	frp_log('Configuration written.');
	return true;
}

function frp_store_settings($settings, $description) {
	$previous = frp_get_config();
	config_set_path('installedpackages/frp/config/0', $settings);
	$result = write_config($description);
	// pfSense returns false for denied writes and -1 for disk write failures.
	if (($result === false) || ($result === -1)) {
		config_set_path('installedpackages/frp/config/0', $previous);
		return false;
	}
	return true;
}

function frp_stop() {
	if (!is_executable(FRP_RC_SCRIPT)) {
		return false;
	}
	if (mwexec(escapeshellarg(FRP_RC_SCRIPT) . ' onestatus >/dev/null 2>&1') !== 0) {
		return true;
	}
	// Disabling writes rcvar=NO before stopping; onestop bypasses rcvar.
	if (mwexec(escapeshellarg(FRP_RC_SCRIPT) . ' onestop') !== 0) {
		// The CARP watchdog can finish stopping the supervisor between the
		// status check and onestop. An already stopped service is success.
		if (mwexec(escapeshellarg(FRP_RC_SCRIPT) . ' onestatus >/dev/null 2>&1') !== 0) { return true; }
		frp_log('Unable to stop the service.');
		return false;
	}
	frp_log('Service stopped.');
	return true;
}

function frp_start() {
	$carp = frp_carp_status();
	$carp['allowed'] = frp_is_enabled() && $carp['allowed'];
	if (!frp_carp_record($carp)) { return false; }
	if (!$carp['allowed']) { return frp_stop(); }
	if (!is_executable(FRP_RC_SCRIPT) ||
	    (mwexec(escapeshellarg(FRP_RC_SCRIPT) . ' start') !== 0)) {
		// A role change between this check and the rc pre-start gate is standby,
		// not a failed configuration apply that should restore old settings.
		if (!frp_carp_status()['allowed']) { return frp_stop(); }
		frp_log('Unable to start the service.');
		return false;
	}
	frp_log('Service start requested.');
	return true;
}

function frp_restart() {
	// Validate the file that will actually be started, including console edits.
	$output = '';
	$runtime = frp_get_runtime_toml();
	if (!frp_validate_toml($runtime, $output)) {
		frp_log('Runtime configuration validation failed; service not restarted.');
		return false;
	}
	return frp_stop() && frp_start() && frp_atomic_write(FRP_APPLIED_PATH, $runtime, 0600);
}

function frp_configure() {
	try {
		return frp_with_lock(function () {
			$settings = frp_get_config();
			if (trim(frp_decode_toml($settings)) === '') { return frp_write_config($settings); }
			frp_apply_settings($settings, 'restart');
			return true;
		});
	} catch (Throwable $e) {
		frp_log('Unable to configure service: ' . $e->getMessage());
		return false;
	}
}

function frp_is_running() {
	$pid = trim(frp_read_file(FRP_PID_PATH) ?? '');
	if (!ctype_digit($pid) || (int) $pid < 2) { return false; }
	[$rc, $name] = frp_exec(['/bin/ps', '-p', $pid, '-o', 'comm=']);
	return $rc === 0 && basename(trim($name)) === 'frpc';
}

function frp_get_recent_log($lines = 80) {
	if (!file_exists(FRP_LOG_PATH)) {
		return '';
	}

	@exec('/usr/bin/tail -n ' . intval($lines) . ' ' . escapeshellarg(FRP_LOG_PATH) . ' 2>/dev/null', $out, $rc);
	if ($rc != 0) {
		return '';
	}

	return frp_redact_sensitive(preg_replace('/\x1b\[[0-9;]*m/', '', implode("\n", $out)));
}

function frp_get_version() {
	if (!is_executable(FRP_BINARY_PATH)) {
		return gettext('not installed');
	}

	@exec(escapeshellarg(FRP_BINARY_PATH) . ' --version 2>/dev/null', $out, $rc);
	if (($rc != 0) || empty($out)) {
		return gettext('unknown');
	}

	return trim($out[0]);
}

function frp_get_connection_status() {
	return frp_connection_status_from_log(frp_is_running(), frp_get_recent_log());
}

function frp_connection_status_from_log($running, $log) {
	$status = [
		'running' => $running,
		'connected' => false,
		'state' => $running ? gettext('Running') : gettext('Stopped'),
		'detail' => $running ? gettext('The frpc process is running.') : gettext('The frpc process is not running.'),
	];

	if (!$running) {
		return $status;
	}

	$status['state'] = gettext('Running, connection unknown');
	$status['detail'] = gettext('The process is running. Recent logs do not establish its current connection state.');
	foreach (array_reverse(explode("\n", $log)) as $line) {
		if (strpos($line, 'Service starting.') !== false) {
			break;
		}
		if (preg_match('/(login to server failed|connect to server.*(?:failed|error)|connection refused|i\/o timeout|authentication failed|token.*error|connection (?:closed|lost)|session shutdown|heartbeat.*timeout|reconnect to server|try to reconnect)/i', $line, $match)) {
			$status['state'] = gettext('Connection problem');
			$status['detail'] = sprintf(gettext('Latest relevant FRP log event: %s'), $match[0]);
			break;
		}
		if (preg_match('/(login to server success|start proxy success|work connection established)/i', $line)) {
			$status['connected'] = true;
			$status['state'] = gettext('Connected (last log event)');
			$status['detail'] = gettext('The latest relevant log event indicates a successful connection. This is not a live connection check.');
			break;
		}
	}

	return $status;
}

function frp_custom_php_install_command() {
	// Forced upgrades from 0.1.0 retain this obsolete service entry.
	$services = config_get_path('installedpackages/service', []);
	$services = array_values(array_filter($services, fn($s) =>
	    !(($s['name'] ?? '') === 'frpc' && ($s['rcfile'] ?? '') === 'frpc-pfsense')));
	config_set_path('installedpackages/service', $services);
	$settings = frp_get_config();
	$toml = frp_decode_toml($settings);
	if (!empty($toml) && empty($settings['toml_hash'])) {
		$settings['toml_hash'] = frp_toml_hash($toml);
		config_set_path('installedpackages/frp/config/0', $settings);
	}
	// The following resync rereads config.xml under its lock. Persist migration
	// even when the monitor cron entry already exists and needs no update.
	$result = write_config(gettext('FRP Client package metadata migrated.'));
	if ($result === false || $result === -1) {
		throw new RuntimeException(gettext('Unable to save FRP package metadata.'));
	}
	// The package framework invokes frp_configure() after the install hook.
	frp_sync_cron(true);
}

function frp_custom_php_deinstall_command() {
	frp_stop();
	frp_sync_cron(false);
	@unlink(FRP_RCCONF_PATH);
	@unlink(FRP_MONITOR_PATH);
	@unlink(FRP_CARP_PATH);
}
