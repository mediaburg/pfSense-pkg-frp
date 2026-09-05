<?php
require __DIR__ . '/bootstrap.php';
function expect_ha_failure($callback) {
    $failed = false;
    try { $callback(); } catch (RuntimeException $error) { $failed = true; }
    check($failed, 'Invalid HA operation must report failure');
}

$toml = "# shared comment\nserverAddr = \"127.0.0.1\"\nserverPort = 27000\n";
$source = frp_settings_from_post(['toml' => $toml, 'ha_sync' => 'on', 'managed_admin' => 'on', 'reconnect' => 'on'], []);
set_settings($source);
config_set_path('installedpackages/frp/previous', ['toml' => 'private history']);
check(frp_form_values([])['ha_sync'], 'HA sync is offered enabled by default');
$paths = frp_plugin_xmlrpc_send();
check(in_array('installedpackages/frp/config/0/toml', $paths, true), 'Native sender includes TOML');
check(!preg_match('/admin_secret|runtime_hash|ha_sync|previous|last_good/', implode(' ', $paths)), 'Only shared settings leave the node');

// Build the nested payload from the exact paths returned to pfSense.
$wire = [];
foreach ($paths as $path) {
    $target =& $wire;
    foreach (explode('/', $path) as $part) { $target =& $target[$part]; }
    $target = config_get_path($path);
}
unset($target);
$wire['installedpackages']['frp']['config'][0]['admin_secret'] = 'must-not-be-imported';
$wire['installedpackages']['frp']['config'][0]['runtime_hash'] = 'must-not-be-imported';
$disabled = array_merge($source, ['ha_sync' => '']);
set_settings($disabled);
check(frp_plugin_xmlrpc_send() === [], 'Local opt-out stops outgoing settings');
check(frp_plugin_xmlrpc_recv($wire) === [] && frp_get_config() === $disabled, 'Local opt-out stops incoming settings');
$forced = $wire;
$forced['installedpackages']['frp']['config'][0]['ha_sync'] = 'on';
check(frp_plugin_xmlrpc_recv($forced) === [], 'Sender cannot re-enable sync on opted-out receiver');

$receiver = frp_settings_from_post(['toml' => "serverAddr='127.0.0.2'", 'ha_sync' => 'on', 'notify' => 'on'], []);
set_settings($receiver);
frp_apply_settings($receiver);
$receiver = frp_get_config();
$oldRuntime = frp_get_runtime_toml();
$writes = $write_calls;
$result = frp_plugin_xmlrpc_recv($wire);
check($result === [] && frp_ha_pending(), 'Receiver defers completion without adding root config nodes');
check($write_calls === $writes && frp_get_config() === $receiver && frp_get_runtime_toml() === $oldRuntime, 'Receive phase leaves saved and runtime config untouched');
frp_plugin_xmlrpc_recv_done([]); // CE 2.8.1 sends no result argument.
$received = frp_get_config();
check(frp_decode_toml($received) === $toml && !frp_is_enabled(), 'TOML, comments and disabled state applied on peer');
check($received['notify'] === '' && $received['reconnect'] === 'on', 'Cleared and enabled options both propagate');
check($received['admin_secret'] === $receiver['admin_secret'] && $received['admin_secret'] !== $source['admin_secret'], 'Receiver keeps its own management secret');
check(config_get_path('installedpackages/frp/previous') === $receiver, 'Receiver retains its own previous configuration');
check($ha_sync_requests === 0, 'Receiving never initiates a sync loop');
$writes = $write_calls;
check(frp_plugin_xmlrpc_recv($wire) === [], 'Identical repeated sync does not request a restart');
frp_plugin_xmlrpc_recv_done(['frp' => []]);
check($write_calls === $writes, 'Unchanged sync performs no package writes');
$emptyWire = $wire;
foreach (['enable', 'notify', 'certref', 'caref'] as $key) { $emptyWire['installedpackages']['frp']['config'][0][$key] = []; }
check(frp_plugin_xmlrpc_recv($emptyWire) === [] && !frp_ha_pending(), 'Native XMLRPC empty arrays round-trip cleared options and certificate references');
$badOption = $emptyWire;
$badOption['installedpackages']['frp']['config'][0]['notify'] = ['on'];
expect_ha_failure(fn() => frp_plugin_xmlrpc_recv($badOption));
check(!frp_ha_pending(), 'Non-empty option arrays remain invalid');
check(frp_plugin_xmlrpc_recv(['installedpackages' => []]) === [], 'Unrelated HA payload is ignored');

// Even an authenticated peer may have a different or broken configuration.
$invalid = $wire;
$invalid['installedpackages']['frp']['config'][0]['admin_port'] = ['7400'];
expect_ha_failure(fn() => frp_plugin_xmlrpc_recv($invalid));
check(!frp_ha_pending(), 'Malformed fields rejected before writes');
$invalid = $wire;
$invalid['installedpackages']['frp']['config'][0]['toml'] = base64_encode("serverAddr='INVALID'");
$before = frp_get_config();
$beforeRuntime = frp_get_runtime_toml();
frp_plugin_xmlrpc_recv($invalid);
expect_ha_failure(fn() => frp_plugin_xmlrpc_recv_done([]));
check(frp_get_config() === $before && frp_get_runtime_toml() === $beforeRuntime, 'Failed native verification preserves receiver config and runtime');

$changed = $wire;
$changed['installedpackages']['frp']['config'][0]['enable'] = 'on';
frp_plugin_xmlrpc_recv($changed);
$start_result = 1;
expect_ha_failure(fn() => frp_plugin_xmlrpc_recv_done(['frp' => ['xmlrpc_recv_result' => true]]));
check(frp_get_config() === $before && frp_get_runtime_toml() === $beforeRuntime && !frp_is_running(), 'Failed peer start rolls back to receiver settings and service state');
$start_result = 0;

frp_plugin_xmlrpc_recv($changed);
set_settings(array_merge($before, ['notify' => 'on']));
$concurrent = frp_get_config();
expect_ha_failure(fn() => frp_plugin_xmlrpc_recv_done([]));
check(frp_get_config() === $concurrent, 'Concurrent local edit is preserved');
frp_plugin_xmlrpc_recv($changed);
set_settings(array_merge($concurrent, ['ha_sync' => '']));
$optedOut = frp_get_config();
frp_plugin_xmlrpc_recv_done([]);
check(frp_get_config() === $optedOut, 'Disabling sync before completion cancels pending import');

set_settings([]);
frp_plugin_xmlrpc_recv($wire);
frp_plugin_xmlrpc_recv_done(['frp' => ['xmlrpc_recv_result' => true]]);
check(strlen(frp_get_config()['admin_secret']) === 64 && frp_get_config()['admin_secret'] !== $source['admin_secret'], 'Fresh peer creates its own management secret');
check(frp_ha_enabled(), 'Default-enabled receiver stays enabled after import');

// A successful user save queues the native HA event only on the primary.
config_set_path('hasync/synchronizetoip', '192.0.2.2');
$post = submission('review', $toml, ['ha_sync' => 'on']);
check(frp_handle_post($post)['reviewed'] && $ha_sync_requests === 0, 'Review does not send HA data');
$saved = frp_handle_post(submission('save', $toml, ['ha_sync' => 'on']));
check(!$saved['errors'] && $ha_sync_requests === 1 && str_contains($saved['message'], 'requested'), 'Successful primary save requests native sync');
$failed = frp_handle_post(submission('save', "serverAddr='INVALID'", ['ha_sync' => 'on']));
check($failed['errors'] && $ha_sync_requests === 1, 'Failed save does not request sync');
$saved = frp_handle_post(submission('save', $toml));
check(!$saved['errors'] && !frp_ha_enabled() && $ha_sync_requests === 1, 'User can save opt-out without sending another sync');
set_settings(array_merge(frp_get_config(), ['ha_sync' => 'on']));
config_set_path('hasync/synchronizetoip', '');
check(frp_ha_sync_after_save() === '' && $ha_sync_requests === 1, 'Secondary and standalone nodes do not request outgoing sync');
printf("PASS: %d HA checks (PHP %s)\n", $checks, PHP_VERSION);
