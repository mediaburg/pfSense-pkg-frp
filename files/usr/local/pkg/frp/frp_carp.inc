<?php

function frp_carp_vips() {
	return array_values(array_filter(config_get_path('virtualip/vip', []),
	    fn($vip) => is_array($vip) && ($vip['mode'] ?? '') === 'carp'));
}

function frp_carp_choices($selected = 'auto') {
	$choices = ['auto' => gettext('Automatic — all CARP VIPs must be MASTER'),
	    'always' => gettext('Ignore CARP — run on every enabled node')];
	foreach (frp_carp_vips() as $vip) {
		if (!empty($vip['uniqid'])) {
			$choices['vip:' . $vip['uniqid']] = ($vip['subnet'] ?? '') . ' — ' . ($vip['descr'] ?? $vip['interface'] ?? 'CARP');
		}
	}
	if (!isset($choices[$selected])) { $choices[$selected] = gettext('Missing CARP VIP — client will stay stopped'); }
	return $choices;
}

function frp_carp_status($settings = null) {
	$mode = ($settings ?? frp_get_config())['carp_mode'] ?? 'auto';
	$result = ['managed' => true, 'allowed' => false, 'state' => 'UNKNOWN'];
	if ($mode === 'always') { return ['managed' => false, 'allowed' => true, 'state' => 'IGNORED']; }
	$vips = frp_carp_vips();
	if ($mode === 'auto' && !$vips) { return ['managed' => false, 'allowed' => true, 'state' => 'STANDALONE']; }
	if (is_string($mode) && str_starts_with($mode, 'vip:')) {
		$vips = array_values(array_filter($vips, fn($vip) => ($vip['uniqid'] ?? '') === substr($mode, 4)));
		if (!$vips) { return array_merge($result, ['state' => 'MISSING VIP']); }
	} elseif ($mode !== 'auto') { return $result; }
	if (config_get_path('virtualip_carp_maintenancemode') !== null) {
		return array_merge($result, ['state' => 'MAINTENANCE']);
	}
	if (!function_exists('get_carp_status') || !function_exists('get_carp_interface_status')) { return $result; }
	try {
		if (!get_carp_status()) { return array_merge($result, ['state' => 'DISABLED']); }
		$states = [];
		foreach ($vips as $vip) {
			$id = $vip['uniqid'] ?? '';
			if (!is_string($id) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $id)) { return $result; }
			$state = get_carp_interface_status('_vip' . $id);
			$states[] = in_array($state, ['MASTER', 'BACKUP', 'INIT'], true) ? $state : 'UNKNOWN';
		}
		$states = array_values(array_unique($states));
		$result['state'] = count($states) === 1 ? $states[0] : 'MIXED';
		$result['allowed'] = $states === ['MASTER'];
	} catch (Throwable $error) { /* Unknown role is never permission to start. */ }
	return $result;
}

function frp_carp_record($status) {
	return frp_atomic_write(FRP_CARP_PATH, json_encode($status), 0600);
}

function frp_carp_reconcile() {
	$previous = json_decode(frp_read_file(FRP_CARP_PATH) ?? '{}', true) ?: [];
	$status = frp_carp_status();
	$status['allowed'] = frp_is_enabled() && $status['allowed'];
	if (!$status['allowed']) {
		if (!frp_stop()) { throw new RuntimeException('Unable to stop FRP on an inactive CARP node.'); }
	} elseif ($status['managed'] && ($previous['allowed'] ?? null) !== true) {
		// A watchdog may already have sent TERM to an old supervisor. Restart
		// across the eligibility edge even if that supervisor has not exited yet.
		if (!frp_restart()) { throw new RuntimeException('Unable to start FRP after CARP promotion.'); }
	}
	$status = frp_carp_status();
	$status['allowed'] = frp_is_enabled() && $status['allowed'];
	// Only a transition back to eligible may auto-start a stopped supervisor.
	// Repeated events/cron must not reset the five-exit crash budget or undo a
	// manual service stop while the node remains MASTER.
	if (!frp_carp_record($status)) { throw new RuntimeException('Unable to record CARP service state.'); }
	return $status;
}

function frp_plugin_carp($event) {
	if (($event['type'] ?? '') !== 'carp' || !in_array($event['event'] ?? '', ['rc.carpmaster', 'rc.carpbackup'], true)) { return; }
	try {
		// Read the current roles, not the possibly stale event name. The rc gate
		// and supervisor independently prevent starts during this lock wait.
		frp_with_lock(fn() => frp_carp_reconcile(), 15);
	} catch (Throwable $error) {
		frp_log('CARP reconciliation deferred: ' . $error->getMessage());
	}
}
