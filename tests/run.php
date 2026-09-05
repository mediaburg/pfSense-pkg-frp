<?php
require __DIR__ . '/bootstrap.php';
$legacy_errors = [];
frp_validate_input(['toml' => "serverAddr='127.0.0.1'"], $legacy_errors);
check(count($legacy_errors) === 1, 'Generic package editor cannot bypass guarded save workflow');
$valid = "serverAddr = \"127.0.0.1\"\nserverPort = 27000\n";
set_settings(settings_for($valid));
check(frp_validate_toml($valid, $output), 'Valid configuration passes');
$verification = json_decode(file_get_contents($sandbox . '/verification.json'), true);
check($verification['mode'] === 0600 && $verification['cwd'] === '/', 'Private verifier file and consistent working directory');
check(!file_exists($verification['path']), 'Verifier file cleaned up');
foreach (['', null, [], str_repeat('x', FRP_MAX_CONFIG_BYTES)] as $bad) check(!frp_validate_toml($bad, $output), 'Reject invalid input type/size');
check(!frp_validate_toml('INVALID', $output) && !str_contains($output, 'test-secret'), 'Failure output redacted');
foreach (['auth.token = "hidden"', "password='hidden'", 'secretKey="a\\"hidden"', '"clientSecret":"hidden"', "token='''multi\nhidden'''", 'token="unfinished hidden'] as $secret) check(!str_contains(frp_redact_sensitive($secret), 'hidden'), 'Secret redaction');
check(frp_decode_toml(['toml' => []]) === '', 'Malformed stored data is handled');
check(frp_parse_toml("[transport.tls]\nenable = true")['transport']['tls']['enable'], 'Full TOML nested parser');
try { frp_parse_toml("x=1\nx=2"); check(false, 'Duplicate key accepted'); } catch (RuntimeException $e) { check(true, 'Duplicate keys rejected'); }
$new = frp_settings_from_post(submission('save', $valid, ['enable' => 'on', 'managed_admin' => 'on', 'reconnect' => 'on']), frp_get_config());
$prepared = frp_parse_toml(frp_prepare_runtime($new, $certs));
check($prepared['loginFailExit'] === false, 'Startup retries enabled');
check($prepared['webServer']['addr'] === '127.0.0.1' && strlen($prepared['webServer']['password']) === 64, 'Managed API is loopback and authenticated');
check(frp_decode_toml($new) === $valid, 'Original TOML retained');
check(frp_reload_compatible($valid, $valid . "[[proxies]]\nname='test'\ntype='tcp'\nlocalPort=8080\nremotePort=28000"), 'Proxy-only changes reload');
check(!frp_reload_compatible($valid, str_replace('27000', '27001', $valid)), 'Server changes require restart');
check(!frp_reload_compatible($valid . 'includes=["/tmp/*.toml"]', $valid), 'Includes require restart');
$diff = frp_config_comparison("auth.token='old'\nserverPort=1", "auth.token='new'\nserverPort=2");
check($diff[0]['before'] === '<redacted>' && $diff[0]['after'] === '<redacted>', 'Diff masks both old and new secrets');
file_put_contents($sandbox . '/target', 'untouched'); symlink($sandbox . '/target', $sandbox . '/link');
check(!frp_atomic_write($sandbox . '/link', 'overwrite', 0600) && file_get_contents($sandbox . '/target') === 'untouched', 'Symlinks refused');
check(!frp_atomic_write($sandbox . '/absent/config', 'data', 0600), 'No tempnam directory fallback');
check(frp_write_config([]), 'First install writes disabled rc config');
file_put_contents(FRP_CONFIG_PATH, $valid . '# console'); $console = file_get_contents(FRP_CONFIG_PATH);
check(frp_write_config([]) && file_get_contents(FRP_CONFIG_PATH) === $console, 'First install preserves console configuration');
foreach ([false, -1] as $failure) { $write_result = $failure; $old = frp_get_config(); check(!frp_store_settings([], 'test') && frp_get_config() === $old, 'Failed persistence restored in memory'); }
$write_result = null;
$result = frp_handle_post(submission('save', $valid, ['enable' => 'on']));
check(!$result['errors'] && frp_is_running(), 'Save applies and starts service: ' . implode(' ', $result['errors']));
check((fileperms(FRP_CONFIG_PATH) & 0777) === 0600 && !frp_runtime_config_differs(), 'Applied runtime private and consistent');
$stale = submission('save', $valid);
set_settings(array_merge(frp_get_config(), ['notify' => 'on']));
$result = frp_handle_post($stale);
check($result['errors'] && frp_is_running(), 'Stale browser save refused');
$result = frp_handle_post(submission('review', $valid . '# edited'));
check(!$result['errors'] && $result['reviewed'], 'Review renders without applying');
$result = frp_handle_post(submission('save', ['unexpected'])); check((bool) $result['errors'], 'Array POST rejected');
$result = frp_handle_post(submission(['save'], $valid)); check((bool) $result['errors'], 'Array action rejected');
$before = frp_get_config();
$result = frp_handle_post(submission('save', 'INVALID')); check($result['errors'] && frp_get_config() === $before, 'Invalid save preserves settings');
$write_result = -1;
$result = frp_handle_post(submission('save', $valid)); check($result['errors'] && frp_get_config() === $before, 'Disk write failure stops apply');
$write_result = null;
$oldruntime = frp_get_runtime_toml();
$stop_result = 1;
$result = frp_handle_post(submission('save', str_replace('27000', '27001', $valid), ['enable' => 'on']));
check($result['errors'] && frp_get_runtime_toml() === $oldruntime && frp_get_config() === $before, 'Failed service update restores files and settings');
$stop_result = 0;
$result = frp_handle_post(submission('save', $valid)); check(!$result['errors'] && !frp_is_running(), 'Disable stops service');
file_put_contents(FRP_CONFIG_PATH, str_replace('27000', '27002', $valid));
$result = frp_handle_post(submission('reload_runtime', 'unsaved', ['enable' => 'on']));
check(!$result['errors'] && str_contains($result['values']['toml'], '27002') && !frp_is_enabled(), 'Runtime import only changes editor');
$result = frp_handle_post(submission('restore', $valid)); check(!$result['errors'] && !frp_is_enabled(), 'Restore loads history without applying');
$rows = frp_proxy_rows('{"tcp":[{"name":"<script>","status":"running","err":"","local_addr":"127.0.0.1:80"}]}');
check($rows[0]['name'] === '<script>' && $rows[0]['type'] === 'tcp', 'API values remain data for safe frontend rendering');
$state = [];
[$state, $event] = frp_monitor_transition($state, true, false, 100); check($event === null, 'Brief outage is quiet');
[$state, $event] = frp_monitor_transition($state, true, false, 221); check($event === 'down', 'Sustained outage notifies');
[$state, $event] = frp_monitor_transition($state, true, false, 300); check($event === null, 'No repeated outage notification');
[$state, $event] = frp_monitor_transition($state, true, null, 310); check($event === null && $state['alerted'], 'Unknown state does not claim recovery');
[$state, $event] = frp_monitor_transition($state, true, true, 320); check($event === 'recovered', 'Recovery notifies once');
[$state, $event] = frp_monitor_transition($state, false, false, 400); check($event === null && !$state['alerted'], 'Disabled monitoring is quiet');
// Use real OpenSSL material: export, matching key, renewal identity and missing ref.
$key = openssl_pkey_new(['private_key_bits' => 2048]);
$csr = openssl_csr_new(['commonName' => 'frp-test.invalid'], $key);
$cert = openssl_csr_sign($csr, null, $key, 1);
openssl_x509_export($cert, $pem); openssl_pkey_export($key, $private);
config_set_path('cert', [['refid' => 'test-cert', 'descr' => 'Test client', 'crt' => base64_encode($pem), 'prv' => base64_encode($private)]]);
config_set_path('ca', [['refid' => 'test-ca', 'descr' => 'Test CA', 'crt' => base64_encode($pem)]]);
$certsettings = array_merge($new, ['certref' => 'test-cert', 'caref' => 'test-ca']);
$certRuntime = frp_prepare_runtime($certsettings, $certfiles);
$certdata = frp_parse_toml($certRuntime);
frp_write_certificates($certfiles);
check($certdata['transport']['tls']['enable'] && count($certfiles) === 2, 'Certificate Manager selection builds TLS config');
check(file_get_contents($certdata['transport']['tls']['keyFile']) === $private &&
    (fileperms($certdata['transport']['tls']['keyFile']) & 0777) === 0600, 'Matching private key exported with mode 0600');
try { frp_prepare_runtime(array_merge($certsettings, ['certref' => 'deleted']), $certfiles); check(false, 'Deleted certificate accepted'); }
catch (RuntimeException $e) { check(true, 'Deleted certificate rejected'); }
config_set_path('cert/0/prv', base64_encode('invalid key'));
try { frp_prepare_runtime($certsettings, $certfiles); check(false, 'Invalid private key accepted'); }
catch (RuntimeException $e) { check(true, 'Invalid private key rejected'); }
// Exercise actual HTTP streams (including PHP 8.5 response header handling).
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
$port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
fclose($socket);
$router = $sandbox . '/api.php';
file_put_contents($router, '<?php if (($_SERVER["PHP_AUTH_USER"] ?? "") !== "test") { http_response_code(401); exit; } echo "{\\"tcp\\":[]}";');
$api = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
try {
    $ready = false;
    for ($attempt = 0; $attempt < 40 && !$ready; $attempt++) { usleep(50000); $ready = frp_tcp_probe('127.0.0.1', $port, .1); }
    check($ready, 'Local API fixture started');
    $apiToml = "webServer.addr='127.0.0.1'\nwebServer.port={$port}\nwebServer.user='test'\nwebServer.password='test-only'\n";
    check(frp_admin_request('/api/status', $error, $apiToml) === '{"tcp":[]}', 'Authenticated API response accepted');
    check(frp_admin_request('/api/status', $error, str_replace("user='test'", "user='bad'", $apiToml)) === null, 'HTTP 401 rejected');
    check(frp_admin_request('/api/status', $error, str_replace('127.0.0.1', '192.0.2.1', $apiToml)) === null, 'Non-loopback management refused before request');
} finally { proc_terminate($api); proc_close($api); }
config_set_path('installedpackages/service', [['name' => 'frpc', 'rcfile' => 'frpc-pfsense'], ['name' => 'other', 'rcfile' => 'other']]);
frp_custom_php_install_command();
check(config_get_path('installedpackages/service') === [['name' => 'other', 'rcfile' => 'other']], 'Upgrade removes only the obsolete FRP service');
foreach (array_slice($argv, 1) as $binary) {
    copy($binary, FRP_BINARY_PATH); chmod(FRP_BINARY_PATH, 0700);
    check(frp_validate_toml(frp_prepare_runtime($new, $certs), $output), 'Real FRP accepts managed configuration: ' . $output);
    check(frp_validate_toml($certRuntime, $output), 'Real FRP accepts exported TLS material');
    check(!frp_validate_toml('serverPort="invalid"', $output), 'Real FRP rejects invalid port');
    echo 'Verified with frpc ' . frp_get_version() . "\n";
}
echo "PASS: {$checks} checks (PHP " . PHP_VERSION . ")\n";
