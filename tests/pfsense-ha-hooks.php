<?php
/* Read-only pfSense integration check: configuration changes stay in this PHP
 * process. No write_config(), service action or outgoing sync is invoked. */
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--inspect') {
    fwrite(STDERR, "Run on pfSense: php tests/pfsense-ha-hooks.php --inspect\n");
    exit(1);
}
require_once('config.inc');
require_once('functions.inc');
$root = dirname(__DIR__);
function ha_check($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
}
try {
    $xml = parse_xml_config_pkg("$root/files/usr/local/pkg/frp.xml", 'packagegui');
    $registration = ['configurationfile' => 'frp.xml', 'include_file' => "$root/files/usr/local/pkg/frp/frp.inc", 'plugins' => $xml['plugins']];
    config_set_path('installedpackages/package', [$registration]);
    config_set_path('installedpackages/frp/config/0', ['toml' => base64_encode("serverAddr='127.0.0.1'"), 'ha_sync' => 'on', 'enable' => '']);
    $sent = pkg_call_plugins('plugin_xmlrpc_send', []);
    ha_check(in_array('installedpackages/frp/config/0/toml', $sent['frp'] ?? [], true), 'Send hook was not discovered');
    $wire = [];
    // Mirror the native HA sender, including its empty-path array fallback.
    foreach ($sent['frp'] as $path) { array_set_path($wire, $path, array_get_path(config_get_path(''), $path, [])); }
    config_set_path('installedpackages/frp/config/0', ['ha_sync' => '']);
    ha_check((pkg_call_plugins('plugin_xmlrpc_recv', $wire)['frp'] ?? null) === [], 'Receiver opt-out ignored');
    config_set_path('installedpackages/frp/config/0', []);
    $received = pkg_call_plugins('plugin_xmlrpc_recv', $wire);
    ha_check(($received['frp'] ?? null) === [] && frp_ha_pending(), 'Receive hook was not discovered');
    ha_check(config_get_path('installedpackages/frp/config/0') === [], 'Receive hook changed configuration before completion');
    // Discard the pending import before inspecting the completion registration.
    frp_ha_pending([]);
    $done = pkg_call_plugins('plugin_xmlrpc_recv_done', []);
    ha_check(array_key_exists('frp', $done), 'Completion hook was not discovered');
    $carp = pkg_call_plugins('plugin_carp', ['type' => 'inspection']);
    ha_check(array_key_exists('frp', $carp), 'CARP hook was not discovered');
    ha_check(function_exists('get_carp_status') && function_exists('get_carp_interface_status'), 'Native CARP status API missing');
    ha_check(!frp_carp_status(['carp_mode' => 'vip:missing-test-vip'])['allowed'], 'Missing native VIP did not fail closed');
    $receiver = file_get_contents('/usr/local/www/xmlrpc.php');
    ha_check(!str_contains($receiver, 'foreach ($pkg_merged_paths as $pkg => $sections)'),
        'Native XMLRPC shadows VIP sections when package hooks are present. Apply official pfSense fix 7a9b5263229799719643b64e304e2afd5675f86f before using VIP sync.');
    echo "PASS: native pfSense XML registration, HA/CARP hook dispatchers and CARP status API; no settings written\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
