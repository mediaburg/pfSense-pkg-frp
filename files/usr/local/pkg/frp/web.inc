<?php

function frp_form_values($settings) {
	return ['toml' => frp_decode_toml($settings), 'enable' => frp_is_enabled($settings),
	    'managed_admin' => frp_option($settings, 'managed_admin', true), 'reconnect' => frp_option($settings, 'reconnect', true),
	    'notify' => frp_option($settings, 'notify'), 'admin_port' => $settings['admin_port'] ?? '7400',
	    'ha_sync' => frp_ha_enabled($settings),
	    'carp_mode' => $settings['carp_mode'] ?? 'auto',
	    'certref' => $settings['certref'] ?? '', 'caref' => $settings['caref'] ?? ''];
}

function frp_handle_post($post) {
	$settings = frp_get_config();
	$result = ['errors' => [], 'message' => '', 'comparison' => [], 'diagnostics' => [], 'reviewed' => false,
	    'values' => frp_form_values($settings), 'revision' => $post['revision'] ?? frp_revision(),
	    'runtime_revision' => $post['runtime_revision'] ?? frp_runtime_revision()];
	try {
		$action = $post['action'] ?? '';
		if (!is_string($action) || !in_array($action, ['review', 'save', 'reload', 'validate', 'restart', 'reload_runtime', 'restore', 'diagnose'], true)) {
			throw new RuntimeException(gettext('Invalid action requested.'));
		}
		foreach (['toml', 'admin_port', 'caref', 'certref', 'carp_mode', 'revision', 'runtime_revision'] as $field) {
			if (isset($post[$field]) && !is_string($post[$field])) { throw new RuntimeException(gettext('Invalid form field type.')); }
		}
		foreach ($result['values'] as $key => $value) {
			$result['values'][$key] = is_bool($value) ? isset($post[$key]) : ($post[$key] ?? $value);
		}
		if ($action === 'diagnose') {
			$result['diagnostics'] = frp_diagnostics();
		} elseif ($action === 'reload_runtime') {
			$runtime = frp_get_runtime_toml();
			if ($runtime === null) { throw new RuntimeException(gettext('No readable runtime configuration is available.')); }
			frp_parse_toml($runtime);
			$result['values']['toml'] = $runtime;
			$result['message'] = gettext('Runtime configuration loaded into the editor. Review and save to keep it in pfSense.');
		} elseif ($action === 'restore') {
			$candidate = frp_restore_candidate();
			if (empty($candidate['toml'])) { throw new RuntimeException(gettext('No previous configuration is available yet.')); }
			$result['values'] = frp_form_values($candidate);
			$result['message'] = gettext('Previous working/applied settings loaded into the editor. Review before applying.');
		} elseif ($action === 'restart') {
			frp_with_lock(function () use ($post) {
				frp_assert_revision($post);
				if (!frp_is_enabled() || !frp_restart()) { throw new RuntimeException(gettext('Unable to restart. Enable the client and check its runtime configuration.')); }
			});
			$result['message'] = gettext('Service restart requested. Unsaved editor changes were not applied.');
		} else {
			$new = frp_settings_from_post($post, $settings);
			$runtime = frp_prepare_runtime($new, $certificates);
			frp_write_certificates($certificates);
			if (!frp_validate_toml($runtime, $output)) { throw new RuntimeException(gettext('Configuration validation failed: ') . $output); }
			if ($action === 'validate') {
				$result['message'] = gettext('Configuration validation succeeded.');
			} elseif ($action === 'review') {
				frp_assert_revision($post);
				$result['comparison'] = frp_config_comparison(frp_decode_toml($settings), $result['values']['toml']);
				foreach (['enable', 'managed_admin', 'reconnect', 'notify', 'ha_sync', 'admin_port', 'certref', 'caref', 'carp_mode'] as $key) {
					if (($settings[$key] ?? '') !== ($new[$key] ?? '')) {
						$result['comparison'][] = ['key' => 'Package: ' . $key, 'before' => (string) ($settings[$key] ?? ''), 'after' => (string) ($new[$key] ?? '')];
					}
				}
				$result['reviewed'] = true;
			} else {
				$result['message'] = frp_with_lock(function () use ($post, $action) {
					frp_assert_revision($post);
					return frp_apply_settings(frp_settings_from_post($post, frp_get_config()), $action === 'reload' ? 'reload' : 'restart');
				});
				$result['revision'] = frp_revision();
				$result['runtime_revision'] = frp_runtime_revision();
				$result['values'] = frp_form_values(frp_get_config());
				$result['message'] .= frp_ha_sync_after_save();
			}
		}
	} catch (Throwable $e) {
		$result['errors'][] = $e->getMessage();
	}
	return $result;
}
