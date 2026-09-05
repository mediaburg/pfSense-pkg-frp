<?php

function frp_ha_enabled($settings = null) {
	return frp_option($settings ?? frp_get_config(), 'ha_sync', true);
}

function frp_ha_shared_keys() {
	// The opt-out, management secret, runtime hash and recovery history are local.
	return ['toml', 'enable', 'managed_admin', 'reconnect', 'notify', 'admin_port', 'certref', 'caref', 'carp_mode'];
}

function frp_plugin_xmlrpc_send($unused = []) {
	$settings = frp_get_config();
	if (!frp_ha_enabled($settings) || trim(frp_decode_toml($settings)) === '') { return []; }
	$paths = [];
	foreach (frp_ha_shared_keys() as $key) {
		if (array_key_exists($key, $settings)) { $paths[] = 'installedpackages/frp/config/0/' . $key; }
	}
	return $paths;
}

function frp_ha_pending($value = null) {
	// Request-local handoff; pfSense 2.8.1 calls recv_done with an empty argument,
	// while current versions pass plugin results. Neither needs a temporary file.
	static $pending = [];
	if ($value !== null) { $pending = $value; }
	return $pending;
}

function frp_plugin_xmlrpc_recv($sections) {
	frp_ha_pending([]);
	try {
		return frp_ha_prepare_import($sections);
	} catch (Throwable $error) {
		frp_log('HA configuration rejected: ' . $error->getMessage());
		throw new RuntimeException('FRP HA configuration was rejected. Check the receiving node package log.');
	}
}

function frp_ha_prepare_import($sections) {
	$old = frp_get_config();
	$incoming = $sections['installedpackages']['frp']['config'][0] ?? null;
	if (!frp_ha_enabled($old) || $incoming === null) { return []; }
	if (!is_array($incoming) || !is_string($incoming['toml'] ?? null)) {
		throw new RuntimeException('FRP HA sync: invalid configuration payload.');
	}
	$post = ['toml' => frp_decode_toml($incoming)];
	foreach (['enable', 'managed_admin', 'reconnect', 'notify'] as $key) {
		$value = $incoming[$key] ?? '';
		// Native sync represents empty config paths as empty arrays.
		if ($value === []) { $value = ''; }
		if (!in_array($value, ['', 'on'], true)) { throw new RuntimeException('FRP HA sync: invalid option ' . $key . ' (' . get_debug_type($value) . ').'); }
		if ($value === 'on') { $post[$key] = 'on'; }
	}
	foreach (['admin_port' => '7400', 'certref' => '', 'caref' => '', 'carp_mode' => 'auto'] as $key => $default) {
		$post[$key] = $incoming[$key] ?? $default;
		if (in_array($key, ['certref', 'caref'], true) && $post[$key] === []) { $post[$key] = ''; }
	}
	// The receiver's opt-out is never imported from the primary.
	$post['ha_sync'] = 'on';
	$candidate = frp_settings_from_post($post, $old);
	if (!array_key_exists('ha_sync', $old)) { unset($candidate['ha_sync']); }
	$runtime = frp_prepare_runtime($candidate, $certificates);
	$candidate['runtime_hash'] = frp_toml_hash($runtime);
	if ($candidate === $old) { return []; }
	frp_ha_pending(['settings' => $candidate, 'revision' => frp_revision($old),
	    'runtime_revision' => frp_runtime_revision()]);
	// Defer this package's write to recv_done. The normal FRP apply transaction
	// can then restore the receiver's previous settings if validation/start fails.
	// Both supported dispatchers call recv_done unconditionally. Returning a
	// marker would create an unwanted root config node on pfSense CE 2.8.1.
	return [];
}

function frp_plugin_xmlrpc_recv_done($results = []) {
	$pending = frp_ha_pending();
	frp_ha_pending([]);
	if (!$pending) { return; }
	try {
		frp_with_lock(function () use ($pending) {
			if (!frp_ha_enabled()) { return; }
			frp_assert_revision($pending);
			frp_apply_settings($pending['settings'], 'restart');
			frp_log('HA configuration received and applied.');
		});
	} catch (Throwable $error) {
		frp_log('HA configuration could not be applied: ' . $error->getMessage());
		throw new RuntimeException('FRP HA configuration could not be applied. Check the receiving node package log.');
	}
	// Never request another outbound sync when applying a received configuration.
}

function frp_ha_sync_after_save() {
	if (!frp_ha_enabled() || empty(config_get_path('hasync/synchronizetoip'))) { return ''; }
	try {
		carp_sync_client();
		return ' ' . gettext('HA configuration sync requested. Check System Logs for the result.');
	} catch (Throwable $error) {
		frp_log('Unable to request HA sync: ' . $error->getMessage());
		return ' ' . gettext('Local settings were saved, but HA sync could not be requested. Check package logs.');
	}
}
