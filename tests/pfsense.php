<?php
/*
 * Integration fixture for a dedicated pfSense test VM. See docs/testing.md.
 * Run setup, exercise, reboot checks, then restore.
 * All FRP listeners and the HTTP target bind only to loopback.
 */
if (PHP_SAPI !== 'cli' || !is_file('/etc/version') || ($argv[1] ?? '') !== '--test-vm') {
    fwrite(STDERR, "Usage on a dedicated pfSense VM: php tests/pfsense.php --test-vm setup|exercise|enable|disable|check-enabled|check-disabled|restore\n");
    exit(1);
}
require_once('/usr/local/pkg/frp/frp.inc');
const TEST_DIR = '/root/frp-acceptance-20260905';
function test_assert($ok, $name) {
    if (!$ok) { throw new RuntimeException($name); }
    echo "PASS: {$name}\n"; flush();
}
function await_test($callback, $seconds = 20) {
    $deadline = microtime(true) + $seconds;
    do { if ($callback()) return true; usleep(250000); } while (microtime(true) < $deadline);
    return false;
}
function test_toml($second = false) {
    $s = "serverAddr='127.0.0.1'\nserverPort=27000\ntransport.heartbeatInterval=1\ntransport.heartbeatTimeout=3\nlog.to='console'\nlog.level='info'\n[[proxies]]\nname='acceptance-http'\ntype='tcp'\nlocalIP='127.0.0.1'\nlocalPort=28080\nremotePort=28000\n";
    if ($second) $s .= "[[proxies]]\nname='acceptance-second'\ntype='tcp'\nlocalIP='127.0.0.1'\nlocalPort=28080\nremotePort=28001\n";
    return $s;
}
function test_settings($enabled = true, $second = false) {
    return frp_settings_from_post(['toml' => test_toml($second), 'enable' => $enabled ? 'on' : null,
        'reconnect' => 'on', 'managed_admin' => 'on', 'admin_port' => '27400'], frp_get_config());
}
function test_apply($enabled = true, $second = false, $method = 'restart') {
    return frp_with_lock(fn() => frp_apply_settings(test_settings($enabled, $second), $method));
}
function start_fixture($name, $args) {
    $cmd = array_merge(['/usr/sbin/daemon', '-f', '-p', TEST_DIR . "/{$name}.pid",
        '-o', TEST_DIR . "/{$name}.log"], $args);
    [$rc, $out] = frp_exec($cmd);
    test_assert($rc === 0, "Start loopback {$name}");
}
function stop_fixture($name) {
    $file = TEST_DIR . "/{$name}.pid";
    if (is_file($file)) {
        $pid = trim(file_get_contents($file));
        if (ctype_digit($pid) && (int)$pid > 1) {
            [$rc, $comm] = frp_exec(['/bin/ps', '-p', $pid, '-o', 'comm=']);
            if ($rc === 0 && in_array(basename(trim($comm)), ['frps', 'php'], true)) {
                posix_kill((int)$pid, SIGTERM);
                await_test(fn() => !posix_kill((int)$pid, 0), 5);
            }
        }
    }
}
function fixture_server() { start_fixture('frps', ['/usr/local/bin/frps', '-c', TEST_DIR . '/frps.toml']); }
function endpoint($port) {
    return @file_get_contents("http://127.0.0.1:{$port}/", false,
        stream_context_create(['http' => ['timeout' => 1]])) === "FRP acceptance OK\n";
}
$action = $argv[2] ?? '';
try {
    if ($action === 'setup') {
        test_assert(!file_exists(TEST_DIR), 'Backup directory is new');
        mkdir(TEST_DIR, 0700);
        $backup = ['config' => config_get_path('installedpackages/frp', []), 'running' => frp_is_running(), 'files' => []];
        foreach ([FRP_CONFIG_PATH, FRP_RCCONF_PATH, FRP_APPLIED_PATH, FRP_MONITOR_PATH] as $path) {
            $backup['files'][$path] = frp_read_file($path);
        }
        test_assert(frp_atomic_write(TEST_DIR . '/backup.json', json_encode($backup), 0600), 'Private FRP backup saved');
        foreach ([27000, 27400, 28000, 28001, 28080] as $port) {
            test_assert(!frp_tcp_probe('127.0.0.1', $port, .2), "Test port {$port} unused");
        }
        file_put_contents(TEST_DIR . '/frps.toml', "bindAddr='127.0.0.1'\nbindPort=27000\nproxyBindAddr='127.0.0.1'\n");
        mkdir(TEST_DIR . '/www', 0700);
        file_put_contents(TEST_DIR . '/www/index.php', "<?php echo \"FRP acceptance OK\\n\";");
        fixture_server();
        start_fixture('http', [PHP_BINARY, '-S', '127.0.0.1:28080', '-t', TEST_DIR . '/www']);
        test_apply();
        test_assert(await_test(fn() => frp_live_status()['healthy'] === true), 'Live tunnel status healthy');
        test_assert(endpoint(28000), 'HTTP payload traverses real FRP tunnel');
    } elseif ($action === 'exercise') {
        test_assert(is_file(TEST_DIR . '/backup.json'), 'Backup exists');
        $pid = trim(file_get_contents(FRP_PID_PATH));
        $before = frp_get_config();
        $rejected = false;
        try {
            frp_with_lock(fn() => frp_apply_settings(array_merge($before, ['toml' => base64_encode("serverPort='invalid'")])));
        } catch (RuntimeException $e) { $rejected = true; }
        test_assert($rejected && frp_get_config() === $before && trim(file_get_contents(FRP_PID_PATH)) === $pid, 'Invalid apply preserves configuration and process');
        test_apply(true, true, 'reload');
        test_assert(await_test(fn() => count(frp_live_status()['proxies']) === 2 && endpoint(28001)), 'Hot reload adds a working second proxy');
        test_assert(trim(file_get_contents(FRP_PID_PATH)) === $pid, 'Hot reload preserves client PID');
        test_assert(count(array_filter(frp_diagnostics(), fn($p) => $p['ok'] === true)) === 4, 'Server and both local targets pass diagnostics');
        stop_fixture('frps');
        test_assert(await_test(fn() => frp_live_status()['healthy'] === false), 'Server outage detected');
        fixture_server();
        test_assert(await_test(fn() => frp_live_status()['healthy'] === true && endpoint(28000), 40), 'Client reconnects after server outage');
        posix_kill((int)$pid, SIGKILL);
        test_assert(await_test(fn() => !frp_is_running(), 3), 'Killed client is reported stopped');
        test_assert(await_test(fn() => frp_is_running() && trim(file_get_contents(FRP_PID_PATH)) !== $pid && endpoint(28000), 25), 'Supervisor restarts a crashed client');
        $pid = trim(file_get_contents(FRP_PID_PATH));
        posix_kill((int)$pid, SIGKILL);
        test_assert(await_test(fn() => !frp_is_running(), 3), 'Second crash enters retry interval');
        test_assert(frp_stop(), 'Stop succeeds during retry interval');
        usleep(11000000);
        test_assert(!frp_is_running(), 'Stopped supervisor does not restart client');
        test_apply();
        test_assert(await_test(fn() => endpoint(28000)), 'Service starts again');
        frp_monitor_run();
        test_assert(!empty(config_get_path('installedpackages/frp/last_good/toml')), 'Healthy configuration retained for recovery');
        test_assert((fileperms(FRP_CONFIG_PATH) & 0777) === 0600, 'Runtime TOML mode 0600');
        test_assert((fileperms(FRP_APPLIED_PATH) & 0777) === 0600, 'Applied snapshot mode 0600');
        $pid = trim(file_get_contents(FRP_PID_PATH));
        [$rc, $out] = frp_exec(['/usr/local/etc/rc.d/frpc-pfsense', 'start']);
        test_assert(trim(file_get_contents(FRP_PID_PATH)) === $pid && str_contains($out, 'already'), 'Duplicate start refused');
    } elseif ($action === 'enable' || $action === 'disable') {
        test_apply($action === 'enable');
        echo "Prepared {$action}d state for reboot.\n";
    } elseif ($action === 'check-enabled' || $action === 'check-disabled') {
        test_assert(frp_is_running() === ($action === 'check-enabled'), "Boot state: {$action}");
        if ($action === 'check-enabled') {
            [$rc, $out] = frp_exec(['/usr/bin/pgrep', '-x', 'frpc']);
            test_assert($rc === 0 && count(preg_split('/\s+/', trim($out))) === 1, 'Exactly one frpc after reboot');
        }
    } elseif ($action === 'restore') {
        $backup = json_decode(file_get_contents(TEST_DIR . '/backup.json'), true, 512, JSON_THROW_ON_ERROR);
        test_assert(frp_stop(), 'Stop test client');
        stop_fixture('frps'); stop_fixture('http');
        config_set_path('installedpackages/frp', $backup['config']);
        write_config('FRP acceptance tests: restore pre-test FRP settings.');
        foreach ($backup['files'] as $path => $content) {
            test_assert(frp_restore_file($path, $content, $path === FRP_RCCONF_PATH ? 0644 : 0600), 'Restore ' . basename($path));
        }
        if ($backup['running']) test_assert(frp_start(), 'Restart originally running client');
        echo "Original FRP settings and process state restored; private backup retained in " . TEST_DIR . ".\n";
    } else { throw new RuntimeException('Unknown action'); }
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\nRun restore to recover the original FRP configuration.\n");
    exit(1);
}
