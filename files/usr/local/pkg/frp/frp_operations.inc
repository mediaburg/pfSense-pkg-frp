<?php

function frp_with_lock($callback, $wait_seconds = 0) {
	if (is_link(FRP_LOCK_PATH)) { throw new RuntimeException('Invalid configuration lock.'); }
	$lock = @fopen(FRP_LOCK_PATH, 'c');
	if (!$lock || !@chmod(FRP_LOCK_PATH, 0600)) { throw new RuntimeException(gettext('Unable to lock FRP configuration.')); }
	$deadline = microtime(true) + $wait_seconds;
	while (!flock($lock, LOCK_EX | LOCK_NB)) {
		if (microtime(true) >= $deadline) {
			fclose($lock);
			throw new RuntimeException(gettext('Another FRP operation is running. Try again shortly.'));
		}
		usleep(50000);
	}
	try {
		if (function_exists('config_read_file')) { config_read_file(true, false); }
		return $callback();
	} finally {
		flock($lock, LOCK_UN);
		fclose($lock);
	}
}

function frp_restore_file($path, $contents, $mode) {
	if ($contents === null) { return !file_exists($path) || @unlink($path); }
	return frp_atomic_write($path, $contents, $mode);
}

function frp_apply_settings($settings, $method = 'restart') {
	$old = frp_get_config();
	$runtime = frp_get_runtime_toml();
	$rcconf = frp_read_file(FRP_RCCONF_PATH);
	$applied = frp_read_file(FRP_APPLIED_PATH);
	$old_running = frp_is_running();
	foreach ([FRP_CONFIG_PATH, FRP_RCCONF_PATH, FRP_APPLIED_PATH] as $path) {
		if (is_link($path) || (file_exists($path) && !is_file($path))) {
			throw new RuntimeException(gettext('A runtime path is a symlink or is not a regular file. No settings were changed.'));
		}
	}
	$next = frp_prepare_runtime($settings, $certificates);
	if ($method === 'reload' && (!$old_running || !frp_is_enabled($settings) || !frp_reload_compatible($applied ?? $runtime ?? '', $next))) {
		throw new RuntimeException(gettext('Reload is only available for proxy changes on a running client. Use Save and restart for common settings, includes or templates.'));
	}
	frp_write_certificates($certificates);
	if (!frp_validate_toml($next, $output)) { throw new RuntimeException(gettext('Configuration validation failed: ') . $output); }
	if (is_link(FRP_STATE_DIR) || (!is_dir(FRP_STATE_DIR) && !@mkdir(FRP_STATE_DIR, 0700, true))) {
		throw new RuntimeException(gettext('Unable to prepare the FRP state directory.'));
	}
	if (!@chmod(FRP_STATE_DIR, 0700)) { throw new RuntimeException(gettext('Unable to protect the FRP state directory.')); }
	if (!is_dir(FRP_RCCONF_DIR) && !@mkdir(FRP_RCCONF_DIR, 0755, true)) { throw new RuntimeException('Unable to prepare rc.conf.d.'); }
	$settings['runtime_hash'] = frp_toml_hash($next);
	// Keep one previous successful apply in config.xml, covered by pfSense backups.
	$previous = config_get_path('installedpackages/frp/previous', []);
	$config_saved = false;
	$service_touched = false;
	try {
		if (!frp_store_settings($settings, gettext('FRP Client configuration applied.'))) {
			throw new RuntimeException(gettext('Unable to save settings to config.xml.'));
		}
		$config_saved = true;
		$enable = frp_is_enabled($settings) ? 'YES' : 'NO';
		if (!frp_atomic_write(FRP_CONFIG_PATH, $next, 0600) ||
		    !frp_atomic_write(FRP_RCCONF_PATH, "frpc_pfsense_enable=\"{$enable}\"\n", 0644)) {
			throw new RuntimeException(gettext('Unable to write runtime configuration.'));
		}
		$service_touched = true;
		if (!frp_carp_status($settings)['allowed']) {
			if (!frp_stop()) { throw new RuntimeException(gettext('Unable to stop the client on CARP standby.')); }
			if (!frp_carp_record(array_merge(frp_carp_status($settings), ['allowed' => false]))) {
				throw new RuntimeException(gettext('Unable to record CARP standby state.'));
			}
		} elseif ($method === 'reload') {
			if (frp_admin_request('/api/reload', $error, $applied ?? $runtime) === null) { throw new RuntimeException($error); }
		} elseif (!frp_stop() || (frp_is_enabled($settings) && !frp_start())) {
			throw new RuntimeException(gettext('The service could not be updated.'));
		}
		if (!frp_atomic_write(FRP_APPLIED_PATH, $next, 0600)) { throw new RuntimeException(gettext('Unable to record the applied configuration.')); }
		// History must not grow recursively through repeated restores.
		if (!empty($old['toml']) && frp_revision($old) !== frp_revision($settings)) {
			config_set_path('installedpackages/frp/previous', $old);
			$result = write_config(gettext('FRP Client previous configuration retained.'));
			if ($result === false || $result === -1) { throw new RuntimeException(gettext('Unable to retain the previous configuration.')); }
		}
		frp_log($method === 'reload' ? 'Proxy configuration reloaded.' : 'Configuration applied.');
		return $method === 'reload' ? gettext('Proxy configuration reloaded.') : gettext('Configuration saved and service updated.');
	} catch (Throwable $e) {
		// Do not overwrite anything if the initial config.xml write was denied.
		if (!$config_saved) { throw $e; }
		$restored = frp_restore_file(FRP_CONFIG_PATH, $runtime, 0600);
		$restored = frp_restore_file(FRP_RCCONF_PATH, $rcconf, 0644) && $restored;
		$restored = frp_restore_file(FRP_APPLIED_PATH, $applied, 0600) && $restored;
		config_set_path('installedpackages/frp/previous', $previous);
		$restored = frp_store_settings($old, gettext('FRP Client failed apply rolled back.')) && $restored;
		if ($service_touched && $restored) {
			if (!frp_carp_status($old)['allowed']) {
				$restored = frp_stop();
			} elseif ($method === 'reload') {
				$restored = frp_admin_request('/api/reload', $error, $applied ?? $runtime) !== null;
			} else {
				$restored = frp_stop() && (!$old_running || frp_start());
			}
		}
		throw new RuntimeException($e->getMessage() . ' ' . ($restored ? gettext('Previous settings were restored.') : gettext('Rollback was incomplete. Check package logs and configuration before starting the service.')));
	}
}

function frp_sync_cron($enabled) {
	require_once('services.inc');
	install_cron_job(FRP_MONITOR_COMMAND, $enabled, '*', '*', '*', '*', '*');
}

function frp_monitor_run() {
	return frp_with_lock(function () {
		$settings = frp_get_config();
		$carp = frp_carp_reconcile();
		$state = json_decode(frp_read_file(FRP_MONITOR_PATH) ?? '{}', true) ?: [];
		$status = frp_live_status();
		[$state, $event] = frp_monitor_transition($state, $carp['allowed'] && frp_option($settings, 'notify'), $status['healthy'], time());
		if (!frp_atomic_write(FRP_MONITOR_PATH, json_encode($state), 0600)) { return; }
		if ($event !== null) {
			$message = $event === 'down' ? gettext('FRP Client: service or tunnels have been unavailable for at least two minutes.') : gettext('FRP Client: service and tunnels have recovered.');
			file_notice('frp-connection', $message, 'FRP Client', '/frp_client.php', $event === 'down' ? 1 : 0);
		}
		if ($status['healthy'] === true && !frp_runtime_config_differs($settings)) {
			$good = config_get_path('installedpackages/frp/last_good', []);
			if (frp_revision($good) !== frp_revision($settings)) {
				config_set_path('installedpackages/frp/last_good', $settings);
				write_config(gettext('FRP Client working configuration recorded.'));
			}
		}
	});
}

function frp_restore_candidate() {
	$current = frp_get_config();
	$good = config_get_path('installedpackages/frp/last_good', []);
	if (!empty($good['toml']) && frp_revision($good) !== frp_revision($current)) { return $good; }
	return config_get_path('installedpackages/frp/previous', []);
}
