<?php

require_once("config.inc");
require_once("functions.inc");
require_once("notices.inc");

define('FRP_CONFIG_PATH', '/usr/local/etc/frpc.toml');
define('FRP_RCCONF_DIR', '/usr/local/etc/rc.conf.d');
define('FRP_RCCONF_PATH', FRP_RCCONF_DIR . '/frpc_pfsense');
define('FRP_RC_SCRIPT', '/usr/local/etc/rc.d/frpc-pfsense');
define('FRP_PID_PATH', '/var/run/frpc-pfsense.pid');
define('FRP_LOG_PATH', '/var/log/frp.log');
define('FRP_DOC_URL', 'https://github.com/fatedier/frp');
define('FRP_MAX_CONFIG_BYTES', 262144);
define('LOG_PREFIX_PKG_FRP', 'FRP');

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
	return preg_replace(
		[
			'/(auth\.token\s*=\s*")[^"]*(")/i',
			'/(token\s*=\s*")[^"]*(")/i',
			'/(password\s*=\s*")[^"]*(")/i',
			'/(secret\s*=\s*")[^"]*(")/i',
		],
		'$1<redacted>$2',
		$text
	);
}

function frp_toml_hash($toml) {
	return hash('sha256', frp_normalize_toml($toml));
}

function frp_get_runtime_toml() {
	if (!file_exists(FRP_CONFIG_PATH)) {
		return null;
	}

	if (is_link(FRP_CONFIG_PATH) || !is_file(FRP_CONFIG_PATH)) {
		return null;
	}

	$size = @filesize(FRP_CONFIG_PATH);
	if (($size === false) || ($size > FRP_MAX_CONFIG_BYTES)) {
		return null;
	}

	return unixnewlines(@file_get_contents(FRP_CONFIG_PATH));
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
	$stored_hash = $settings['toml_hash'] ?? '';
	if ($stored_hash != '') {
		return !hash_equals($stored_hash, $runtime_hash);
	}

	return !hash_equals(frp_toml_hash(frp_decode_toml($settings)), $runtime_hash);
}

function frp_log($message) {
	$line = date('M j H:i:s') . ' frp: ' . $message . "\n";
	@file_put_contents(FRP_LOG_PATH, $line, FILE_APPEND);
	@chmod(FRP_LOG_PATH, 0600);

	if (function_exists('log_error')) {
		log_error(LOG_PREFIX_PKG_FRP . ': ' . $message);
	}
}

function frp_validate_toml($toml, &$output = '') {
	if (strlen($toml) > FRP_MAX_CONFIG_BYTES) {
		$output = sprintf(gettext('Configuration is larger than %d bytes.'), FRP_MAX_CONFIG_BYTES);
		return false;
	}

	$tmpfile = tempnam('/tmp', 'frp-');
	if ($tmpfile === false) {
		$output = gettext('Unable to create a temporary file for validation.');
		return false;
	}

	@file_put_contents($tmpfile, frp_normalize_toml($toml));
	@chmod($tmpfile, 0600);

	$cmd = '/usr/local/bin/frpc verify -c ' . escapeshellarg($tmpfile) . ' 2>&1';
	$lines = [];
	$rc = 1;
	@exec($cmd, $lines, $rc);
	@unlink($tmpfile);

	$output = frp_redact_sensitive(trim(implode("\n", $lines)));
	return ($rc == 0);
}

function frp_validate_input($post, &$input_errors) {
	if (!is_array($input_errors)) {
		$input_errors = [];
	}

	$toml = $post['toml'] ?? '';
	$encoded = preg_replace('/\s+/', '', $toml);
	$decoded = base64_decode($encoded, true);
	if (($decoded !== false) && (base64_encode($decoded) === $encoded)) {
		$toml = $decoded;
	}

	if (trim($toml) == '') {
		$input_errors[] = gettext('The frpc.toml configuration must not be empty.');
		return;
	}

	if (strlen($toml) > FRP_MAX_CONFIG_BYTES) {
		$input_errors[] = sprintf(gettext('The frpc.toml configuration is larger than %d bytes.'), FRP_MAX_CONFIG_BYTES);
		return;
	}

	$output = '';
	if (!frp_validate_toml($toml, $output)) {
		$input_errors[] = sprintf(gettext('frpc.toml validation failed: %s'), $output);
	}
}

function frp_write_config($settings = null) {
	if ($settings === null) {
		$settings = frp_get_config();
	}

	$toml = frp_decode_toml($settings);
	if (trim($toml) == '') {
		$toml = "# Managed by pfSense FRP package\n";
	}

	if (strlen($toml) > FRP_MAX_CONFIG_BYTES) {
		frp_log('Refused to write oversized configuration.');
		return false;
	}

	@file_put_contents(FRP_CONFIG_PATH, frp_normalize_toml($toml), LOCK_EX);
	@chmod(FRP_CONFIG_PATH, 0600);

	$enabled = frp_is_enabled($settings) ? 'YES' : 'NO';
	if (!is_dir(FRP_RCCONF_DIR)) {
		@mkdir(FRP_RCCONF_DIR, 0755, true);
	}
	@file_put_contents(FRP_RCCONF_PATH, "frpc_pfsense_enable=\"{$enabled}\"\n", LOCK_EX);
	@chmod(FRP_RCCONF_PATH, 0644);
	frp_log('Configuration written.');
	return true;
}

function frp_stop() {
	if (file_exists(FRP_RC_SCRIPT)) {
		mwexec(escapeshellarg(FRP_RC_SCRIPT) . ' stop');
	}

	if (file_exists(FRP_PID_PATH)) {
		mwexec('/bin/rm -f ' . escapeshellarg(FRP_PID_PATH));
	}
	frp_log('Service stopped.');
}

function frp_start() {
	if (file_exists(FRP_RC_SCRIPT)) {
		mwexec(escapeshellarg(FRP_RC_SCRIPT) . ' start');
	}
	frp_log('Service start requested.');
}

function frp_restart() {
	frp_stop();
	frp_start();
	frp_log('Service restart requested.');
}

function frp_configure() {
	frp_write_config();
	frp_stop();

	if (frp_is_enabled()) {
		frp_start();
	}
}

function frp_is_running() {
	if (!file_exists(FRP_PID_PATH)) {
		return false;
	}

	$pid = trim(@file_get_contents(FRP_PID_PATH));
	if (!is_numeric($pid)) {
		return false;
	}

	@exec('/bin/kill -0 ' . escapeshellarg($pid) . ' 2>/dev/null', $out, $rc);
	return ($rc == 0);
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
	if (!is_executable('/usr/local/bin/frpc')) {
		return gettext('not installed');
	}

	@exec('/usr/local/bin/frpc --version 2>/dev/null', $out, $rc);
	if (($rc != 0) || empty($out)) {
		return gettext('unknown');
	}

	return trim($out[0]);
}

function frp_get_connection_status() {
	$running = frp_is_running();
	$log = frp_get_recent_log();
	$status = [
		'running' => $running,
		'connected' => false,
		'state' => $running ? gettext('Running') : gettext('Stopped'),
		'detail' => $running ? gettext('The frpc process is running.') : gettext('The frpc process is not running.'),
	];

	if (!$running) {
		return $status;
	}

	if (preg_match('/(login to server success|start proxy success|proxy .* started|work connection established)/i', $log)) {
		$status['connected'] = true;
		$status['state'] = gettext('Connected');
		$status['detail'] = gettext('Recent FRP logs indicate a successful server login/proxy start.');
	} elseif (preg_match('/(login to server failed|connect to server.*failed|connection refused|i\/o timeout|authentication failed|token.*error)/i', $log, $match)) {
		$status['state'] = gettext('Connection problem');
		$status['detail'] = sprintf(gettext('Recent FRP logs contain: %s'), $match[0]);
	} else {
		$status['state'] = gettext('Running, connection unknown');
		$status['detail'] = gettext('The process is running, but no successful server connection message was found in recent logs yet.');
	}

	return $status;
}

function frp_custom_php_install_command() {
	$settings = frp_get_config();
	$toml = frp_decode_toml($settings);
	if (!empty($toml) && empty($settings['toml_hash'])) {
		$settings['toml_hash'] = frp_toml_hash($toml);
		config_set_path('installedpackages/frp/config/0', $settings);
	}
	frp_write_config($settings);
}

function frp_custom_php_deinstall_command() {
	frp_stop();
	@unlink(FRP_RCCONF_PATH);
}
