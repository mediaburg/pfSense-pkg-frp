<?php
require __DIR__ . '/bootstrap.php';
function carp_failure($callback) {
    $failed = false;
    try { $callback(); } catch (RuntimeException $error) { $failed = true; }
    check($failed, 'Invalid CARP input must fail');
}
$masterEvent = ['type' => 'carp', 'event' => 'rc.carpmaster', 'interface' => '1@em0'];
$backupEvent = ['type' => 'carp', 'event' => 'rc.carpbackup', 'interface' => '1@em0'];
$toml = "# CARP test\nserverAddr='127.0.0.1'\n";
check(frp_carp_status()['allowed'] && !frp_carp_status()['managed'], 'Existing standalone configuration keeps running without VIPs');
check(frp_form_values([])['carp_mode'] === 'auto', 'Automatic CARP ownership is the default');
$vips = [
    ['mode' => 'carp', 'uniqid' => 'wanvip', 'subnet' => '192.0.2.10', 'interface' => 'wan'],
    ['mode' => 'carp', 'uniqid' => 'lanvip', 'subnet' => '198.51.100.10', 'interface' => 'lan'],
];
config_set_path('virtualip/vip', $vips);
$carp_states = ['_vipwanvip' => 'MASTER', '_viplanvip' => 'MASTER'];
check(frp_carp_status()['allowed'] && frp_carp_status()['state'] === 'MASTER', 'All MASTER VIPs allow automatic mode');
$carp_states['_viplanvip'] = 'BACKUP';
check(!frp_carp_status()['allowed'] && frp_carp_status()['state'] === 'MIXED', 'Mixed VIP roles fail closed');
check(frp_carp_status(['carp_mode' => 'vip:wanvip'])['allowed'], 'Selected VIP can own FRP independently of another VIP');
check(!frp_carp_status(['carp_mode' => 'vip:missing'])['allowed'], 'Missing selected VIP fails closed');
check(frp_carp_status(['carp_mode' => 'always'])['allowed'], 'Explicit ignore mode bypasses CARP');
foreach (['BACKUP', 'INIT', ''] as $role) {
    $carp_states = ['_vipwanvip' => $role, '_viplanvip' => $role];
    check(!frp_carp_status()['allowed'], 'Non-MASTER state blocks startup: ' . $role);
}
$carp_states = ['_vipwanvip' => 'MASTER', '_viplanvip' => 'MASTER'];
$carp_enabled = false;
check(!frp_carp_status()['allowed'], 'Disabled CARP blocks startup');
$carp_enabled = true;
config_set_path('virtualip_carp_maintenancemode', '');
check(frp_carp_status()['state'] === 'MAINTENANCE' && !frp_carp_status()['allowed'], 'Persistent maintenance blocks even a reported MASTER');
unset($config_data['virtualip_carp_maintenancemode']);
carp_failure(fn() => frp_settings_from_post(['toml' => $toml, 'carp_mode' => ['auto']], []));
carp_failure(fn() => frp_settings_from_post(['toml' => $toml, 'carp_mode' => 'vip:a;id'], []));

$settings = frp_settings_from_post(['toml' => $toml, 'enable' => 'on', 'notify' => 'on', 'ha_sync' => 'on'], []);
$carp_states = ['_vipwanvip' => 'BACKUP', '_viplanvip' => 'BACKUP'];
frp_apply_settings($settings);
check(frp_is_enabled() && !frp_is_running(), 'Saving enabled configuration on BACKUP leaves FRP stopped');
check(str_contains(frp_read_file(FRP_RCCONF_PATH), '"YES"'), 'Enable intent remains saved for later promotion');
check(frp_live_status()['state'] === 'CARP standby' && frp_live_status()['healthy'] === null, 'Standby is visible and is not an outage');
frp_monitor_run();
check(!$notices, 'Intentional standby does not send outage notifications');
check(frp_start() && !frp_is_running(), 'Manual start cannot bypass CARP');
frp_plugin_carp($masterEvent);
check(!frp_is_running(), 'Stale MASTER event cannot start a live BACKUP');

$wire = ['installedpackages' => ['frp' => ['config' => [['toml' => base64_encode($toml . "# synchronized\n"), 'enable' => 'on', 'carp_mode' => 'auto']]]]];
frp_plugin_xmlrpc_recv($wire);
frp_plugin_xmlrpc_recv_done([]);
check(str_contains(frp_decode_toml(frp_get_config()), '# synchronized') && !frp_is_running(), 'HA import on BACKUP saves configuration without starting tunnels');
check(in_array('installedpackages/frp/config/0/carp_mode', frp_plugin_xmlrpc_send(), true), 'CARP ownership policy is shared through HA sync');

$carp_states = ['_vipwanvip' => 'MASTER', '_viplanvip' => 'MASTER'];
frp_plugin_carp($backupEvent);
check(frp_is_running(), 'Current MASTER starts despite a stale BACKUP event');
$pid = frp_read_file(FRP_PID_PATH);
frp_plugin_carp($masterEvent);
frp_monitor_run();
check(frp_read_file(FRP_PID_PATH) === $pid, 'Repeated MASTER event and monitor retain the existing process');
frp_carp_record(['allowed' => false]);
frp_plugin_carp($masterEvent);
check(frp_is_running() && frp_read_file(FRP_PID_PATH) !== $pid, 'Rapid re-promotion replaces a supervisor that may already have received TERM');
$pid = frp_read_file(FRP_PID_PATH);
$stop_result = 1;
$stop_race = true;
check(frp_stop() && !frp_is_running(), 'Concurrent watchdog stop is treated as an already stopped service');
$stop_result = 0;
$stop_race = false;
frp_monitor_run();
frp_plugin_carp($masterEvent);
check(!frp_is_running(), 'Stable MASTER does not reset crash exhaustion or undo a manual stop');
$carp_states['_vipwanvip'] = 'BACKUP';
frp_plugin_carp($backupEvent);
$carp_states['_vipwanvip'] = 'MASTER';
frp_plugin_carp($masterEvent);
check(frp_is_running() && frp_read_file(FRP_PID_PATH) !== $pid, 'A new promotion starts a fresh client');
$carp_states['_viplanvip'] = 'INIT';
frp_plugin_carp($backupEvent);
check(!frp_is_running(), 'Transition away from eligible MASTER stops the client');

// The minute monitor repairs a missed event without restarting a stable MASTER.
$carp_states['_viplanvip'] = 'MASTER';
frp_monitor_run();
check(frp_is_running(), 'Monitor recovers a missed promotion event');
config_set_path('virtualip_carp_maintenancemode', '');
frp_monitor_run();
check(!frp_is_running(), 'Monitor enforces maintenance mode without an event');
unset($config_data['virtualip_carp_maintenancemode']);
frp_monitor_run();
check(frp_is_running(), 'Leaving maintenance permits a new start');

$carp_states['_viplanvip'] = 'BACKUP';
$changed = array_merge(frp_get_config(), ['carp_mode' => 'vip:lanvip']);
frp_apply_settings($changed, 'reload');
check(!frp_is_running(), 'Changing ownership to a BACKUP VIP stops instead of hot-reloading');
set_settings(array_merge(frp_get_config(), ['enable' => '']));
$carp_states['_viplanvip'] = 'MASTER';
frp_plugin_carp($masterEvent);
check(!frp_is_running(), 'Disabled client stays disabled on promotion');
check($ha_sync_requests === 0, 'CARP events never request configuration synchronization');
printf("PASS: %d CARP checks (PHP %s)\n", $checks, PHP_VERSION);
