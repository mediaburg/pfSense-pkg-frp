<?php
error_reporting(E_ALL);
if (!function_exists('gettext')) { function gettext($message) { return $message; } }
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) { throw new ErrorException($message, 0, $severity, $file, $line); }
    return false;
});
$root = dirname(__DIR__);
$sandbox = sys_get_temp_dir() . '/frp-tests-' . bin2hex(random_bytes(8));
mkdir($sandbox, 0700);
$config_data = [];
$write_result = null;
$write_calls = 0;
$commands = [];
$service = null;
$stop_result = 0;
$stop_race = false;
$start_result = 0;
$notices = [];
$ha_sync_requests = 0;
$carp_states = [];
$carp_enabled = true;
function get_carp_status() { return $GLOBALS['carp_enabled']; }
function get_carp_interface_status($vip) { return $GLOBALS['carp_states'][$vip] ?? ''; }
function carp_sync_client() { $GLOBALS['ha_sync_requests']++; }
function config_get_path($path, $default = null) {
    $value = $GLOBALS['config_data'];
    foreach (explode('/', trim($path, '/')) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) return $default;
        $value = $value[$part];
    }
    return $value;
}
function config_set_path($path, $value) {
    $target =& $GLOBALS['config_data'];
    $parts = explode('/', trim($path, '/'));
    foreach ($parts as $part) { $target =& $target[$part]; }
    $target = $value;
}
function write_config($description) { $GLOBALS['write_calls']++; return $GLOBALS['write_result']; }
function unixnewlines($text) { return str_replace(["\r\n", "\r"], "\n", $text); }
function file_notice($id, $message, ...$args) { $GLOBALS['notices'][] = $message; }
function install_cron_job(...$args) {}
function isAllowedPage($page) { return true; }
function print_input_errors($errors) { echo htmlspecialchars(implode("\n", $errors)); }
function print_info_box($message, $class = '') { echo htmlspecialchars($message); }
function parse_xml_config_pkg($path, $root) {
    return ['fields' => ['field' => [['fieldname' => 'toml', 'default_value' => base64_encode('serverAddr = "127.0.0.1"')]]]];
}
function stop_fake_service() {
    if (is_resource($GLOBALS['service'])) {
        proc_terminate($GLOBALS['service']); proc_close($GLOBALS['service']); $GLOBALS['service'] = null;
    }
    if (defined('FRP_PID_PATH') && is_file(FRP_PID_PATH)) unlink(FRP_PID_PATH);
}
function mwexec($command) {
    $GLOBALS['commands'][] = $command;
    if (str_contains($command, ' onestatus')) return is_resource($GLOBALS['service']) ? 0 : 1;
    if (str_ends_with($command, ' onestop')) {
        if ($GLOBALS['stop_result'] === 0 || $GLOBALS['stop_race']) stop_fake_service();
        return $GLOBALS['stop_result'];
    }
    if (str_ends_with($command, ' start')) {
        if ($GLOBALS['start_result'] === 0) {
            $GLOBALS['service'] = proc_open([$GLOBALS['sandbox'] . '/sleep/frpc', '120'],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            file_put_contents(FRP_PID_PATH, (string) proc_get_status($GLOBALS['service'])['pid']);
            usleep(50000);
        }
        return $GLOBALS['start_result'];
    }
    return 0;
}
register_shutdown_function(function () use ($sandbox) {
    stop_fake_service();
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sandbox, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname());
    }
    rmdir($sandbox);
});
mkdir($sandbox . '/sleep'); copy('/bin/sleep', $sandbox . '/sleep/frpc'); chmod($sandbox . '/sleep/frpc', 0700);
foreach (['config.inc', 'functions.inc', 'notices.inc', 'services.inc', 'guiconfig.inc', 'head.inc', 'foot.inc'] as $stub) file_put_contents($sandbox . '/' . $stub, '<?php');
set_include_path($sandbox . PATH_SEPARATOR . get_include_path());
$paths = [
 '/usr/local/etc/frpc.toml' => $sandbox . '/frpc.toml',
 '/usr/local/bin/frpc' => $sandbox . '/frpc',
 '/usr/local/etc/rc.conf.d' => $sandbox . '/rc.conf.d',
 '/usr/local/etc/rc.d/frpc-pfsense' => $sandbox . '/frpc-pfsense',
 '/var/run/frpc-pfsense.pid' => $sandbox . '/frpc.pid',
 '/var/log/frp.log' => $sandbox . '/frp.log',
 '/usr/local/etc/frp' => $sandbox . '/state',
 '/var/run/frp-monitor.json' => $sandbox . '/monitor.json',
 '/var/run/frp-carp.json' => $sandbox . '/carp.json',
 '/var/run/frp-config.lock' => $sandbox . '/config.lock',
];
foreach (glob($root . '/files/usr/local/pkg/frp/*.inc') as $file) file_put_contents($sandbox . '/' . basename($file), strtr(file_get_contents($file), $paths));
symlink($root . '/files/usr/local/pkg/frp/vendor', $sandbox . '/vendor');
file_put_contents($sandbox . '/frpc-pfsense', "#!/bin/sh\nexit 0\n"); chmod($sandbox . '/frpc-pfsense', 0700);
$fake_binary = '#!' . PHP_BINARY . "\n" . <<<'SCRIPT'
<?php
if (($argv[1] ?? '') === '--version') { echo 'test-frpc'; exit(0); }
$file = $argv[3] ?? '';
$content = file_get_contents($file);
file_put_contents(__DIR__ . '/verification.json', json_encode(['path' => $file, 'mode' => fileperms($file) & 0777, 'cwd' => getcwd(), 'content' => $content]));
if (str_contains($content, 'INVALID')) { echo 'auth.token = "test-secret"'; exit(1); }
echo 'syntax is ok';
SCRIPT;
file_put_contents($sandbox . '/frpc', $fake_binary); chmod($sandbox . '/frpc', 0700);
require $sandbox . '/frp.inc';
require $sandbox . '/web.inc';
$checks = 0;
function check($condition, $description) { if (!$condition) throw new RuntimeException($description); $GLOBALS['checks']++; }
function settings_for($toml, $enabled = true) { return ['enable' => $enabled ? 'on' : '', 'toml' => base64_encode($toml), 'toml_hash' => frp_toml_hash($toml)]; }
function set_settings($settings) { config_set_path('installedpackages/frp/config/0', $settings); }
function submission($action, $toml, $extra = []) {
    return array_merge(['action' => $action, 'toml' => $toml, 'admin_port' => '7400', 'revision' => frp_revision(), 'runtime_revision' => frp_runtime_revision()], $extra);
}
