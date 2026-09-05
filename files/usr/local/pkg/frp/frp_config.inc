<?php

spl_autoload_register(function ($class) {
	$prefix = 'Internal\\Toml\\';
	if (str_starts_with($class, $prefix)) {
		$file = __DIR__ . '/vendor/toml/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
		if (is_file($file)) {
			require_once($file);
		}
	}
});

function frp_parse_toml($toml) {
	if (!is_string($toml) || trim($toml) === '' || strlen($toml) > FRP_MAX_CONFIG_BYTES) {
		throw new RuntimeException(gettext('Provide a non-empty TOML configuration of at most 256 KiB.'));
	}
	try {
		return Internal\Toml\Toml::parseToArray($toml);
	} catch (Throwable $e) {
		// Parser diagnostics may quote entire lines containing credentials.
		throw new RuntimeException(gettext('Unable to parse TOML. Check quoting, table names and duplicate keys.'));
	}
}

function frp_option($settings, $key, $default = false) {
	return array_key_exists($key, $settings) ? $settings[$key] === 'on' : $default;
}

function frp_settings_from_post($post, $previous) {
	foreach (['toml', 'admin_port', 'certref', 'caref', 'carp_mode', 'revision', 'runtime_revision'] as $key) {
		if (isset($post[$key]) && !is_string($post[$key])) {
			throw new RuntimeException(gettext('Invalid form field type. Reload the page and try again.'));
		}
	}
	$port = filter_var($post['admin_port'] ?? '7400', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1024, 'max_range' => 65535]]);
	if ($port === false) {
		throw new RuntimeException(gettext('The local management port must be between 1024 and 65535.'));
	}
	$toml = frp_normalize_toml($post['toml'] ?? '');
	frp_parse_toml($toml);
	$settings = $previous;
	foreach (['enable', 'managed_admin', 'reconnect', 'notify', 'ha_sync'] as $key) {
		$settings[$key] = isset($post[$key]) ? 'on' : '';
	}
	$settings['toml'] = base64_encode($toml);
	$settings['toml_hash'] = frp_toml_hash($toml);
	$settings['admin_port'] = (string) $port;
	$settings['admin_secret'] = $previous['admin_secret'] ?? bin2hex(random_bytes(32));
	$settings['certref'] = $post['certref'] ?? '';
	$settings['caref'] = $post['caref'] ?? '';
	$settings['carp_mode'] = $post['carp_mode'] ?? 'auto';
	if (!preg_match('/^(?:auto|always|vip:[a-zA-Z0-9_-]{1,64})$/D', $settings['carp_mode'])) {
		throw new RuntimeException(gettext('Invalid CARP mode.'));
	}
	return $settings;
}

function frp_revision($settings = null) {
	return hash('sha256', serialize($settings ?? frp_get_config()));
}

function frp_runtime_revision() {
	$toml = frp_get_runtime_toml();
	return $toml === null ? 'missing' : hash('sha256', $toml);
}

function frp_assert_revision($post) {
	if (!is_string($post['revision'] ?? null) || !hash_equals(frp_revision(), $post['revision']) ||
	    !is_string($post['runtime_revision'] ?? null) || !hash_equals(frp_runtime_revision(), $post['runtime_revision'])) {
		throw new RuntimeException(gettext('Configuration changed in another window or on the console. Reload this page before applying changes. Your submitted text is still shown below.'));
	}
}

function frp_certificate_choices($type) {
	$result = ['' => gettext('Use paths from TOML')];
	foreach (config_get_path($type, []) as $entry) {
		if (is_array($entry) && !empty($entry['refid']) && !empty($entry['crt']) && ($type !== 'cert' || !empty($entry['prv']))) {
			$result[$entry['refid']] = $entry['descr'] ?? $entry['refid'];
		}
	}
	return $result;
}

function frp_selected_certificate($type, $ref) {
	foreach (config_get_path($type, []) as $entry) {
		if (is_array($entry) && ($entry['refid'] ?? '') === $ref) {
			return $entry;
		}
	}
	throw new RuntimeException(gettext('A selected certificate or CA no longer exists in Certificate Manager.'));
}

function frp_certificate_files($settings, &$data) {
	$files = [];
	foreach (['caref' => 'ca', 'certref' => 'cert'] as $key => $type) {
		if (empty($settings[$key])) { continue; }
		$entry = frp_selected_certificate($type, $settings[$key]);
		$crt = base64_decode($entry['crt'] ?? '', true);
		if ($crt === false || !@openssl_x509_read($crt)) {
			throw new RuntimeException(gettext('The selected certificate is invalid.'));
		}
		$materials = ['crt' => $crt];
		if ($type === 'cert') {
			$prv = base64_decode($entry['prv'] ?? '', true);
			if ($prv === false || !@openssl_x509_check_private_key($crt, $prv)) {
				throw new RuntimeException(gettext('The selected client certificate has no matching private key.'));
			}
			$materials['key'] = $prv;
		}
		foreach ($materials as $suffix => $pem) {
			$path = FRP_CERT_DIR . '/' . hash('sha256', $pem) . '.' . $suffix;
			$files[$path] = $pem;
			$field = $type === 'ca' ? 'trustedCaFile' : ($suffix === 'key' ? 'keyFile' : 'certFile');
			$data['transport']['tls'][$field] = $path;
		}
		$data['transport']['tls']['enable'] = true;
	}
	return $files;
}

function frp_prepare_runtime($settings, &$certificate_files = []) {
	$data = frp_parse_toml(frp_decode_toml($settings));
	if (frp_option($settings, 'reconnect')) {
		$data['loginFailExit'] = false;
	}
	if (frp_option($settings, 'managed_admin')) {
		if (empty($settings['admin_secret'])) {
			throw new RuntimeException(gettext('Save the configuration to initialize local management credentials.'));
		}
		// Fully own this block: never expose the management API beyond loopback.
		$data['webServer'] = ['addr' => '127.0.0.1', 'port' => (int) ($settings['admin_port'] ?? 7400),
		    'user' => 'pfsense-frp', 'password' => $settings['admin_secret']];
	}
	$certificate_files = frp_certificate_files($settings, $data);
	// Source TOML including comments stays intact in config.xml / the editor.
	return "# Generated by pfSense FRP Client; edit through Services > FRP Client.\n" .
	    (string) Internal\Toml\Toml::encode($data) . "\n";
}

function frp_write_certificates($files) {
	if (!$files) { return; }
	if (is_link(FRP_CERT_DIR) || (!is_dir(FRP_CERT_DIR) && !@mkdir(FRP_CERT_DIR, 0700, true)) || !@chmod(FRP_CERT_DIR, 0700)) {
		throw new RuntimeException(gettext('Unable to prepare the FRP certificate directory.'));
	}
	foreach ($files as $path => $pem) {
		if (!frp_atomic_write($path, $pem, 0600)) {
			throw new RuntimeException(gettext('Unable to export the selected certificate.'));
		}
	}
}

function frp_reload_compatible($old, $new) {
	try {
		$a = frp_parse_toml($old);
		$b = frp_parse_toml($new);
		// Referenced files, runtime stores and templates can change invisibly.
		if (!empty($a['includes']) || !empty($b['includes']) || !empty($a['store']) || !empty($b['store']) ||
		    str_contains($old . $new, '{{')) { return false; }
		unset($a['proxies'], $a['visitors'], $a['start'], $b['proxies'], $b['visitors'], $b['start']);
		return $a == $b;
	} catch (Throwable $e) { return false; }
}

function frp_config_comparison($old, $new) {
	// A bounded semantic diff hides secret values, including secrets removed in this change.
	$flatten = function ($data, $prefix = '') use (&$flatten) {
		$out = [];
		foreach ($data as $key => $value) {
			$path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
			if (is_array($value) && $value !== []) {
				$out += $flatten($value, $path);
			} else {
				$out[$path] = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			}
		}
		return $out;
	};
	$a = trim($old) === '' ? [] : $flatten(frp_parse_toml($old));
	$b = $flatten(frp_parse_toml($new));
	$result = [];
	foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $key) {
		if (($a[$key] ?? null) === ($b[$key] ?? null)) { continue; }
		$secret = preg_match('/token|password|secret|passwd|httppwd|groupkey|(^|\.)sk($|\.)|proxyurl|authorization/i', $key);
		$result[] = ['key' => $key, 'before' => isset($a[$key]) ? ($secret ? '<redacted>' : $a[$key]) : '—',
		    'after' => isset($b[$key]) ? ($secret ? '<redacted>' : $b[$key]) : '—'];
		if (count($result) >= 300) { break; }
	}
	return $result;
}
