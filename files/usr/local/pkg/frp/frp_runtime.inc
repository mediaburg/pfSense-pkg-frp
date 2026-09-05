<?php

function frp_exec($args, $timeout = 5) {
	$pipes = [];
	$process = @proc_open($args, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, '/');
	if (!is_resource($process)) { return [1, gettext('Unable to execute frpc.')]; }
	foreach ($pipes as $pipe) { stream_set_blocking($pipe, false); }
	$output = '';
	$deadline = microtime(true) + $timeout;
	$rc = 1;
	while (true) {
		foreach ($pipes as $pipe) { $output .= stream_get_contents($pipe); }
		$status = proc_get_status($process);
		if (!$status['running']) { $rc = $status['exitcode']; break; }
		if (microtime(true) >= $deadline || strlen($output) > 1048576) {
			proc_terminate($process);
			usleep(100000);
			if (proc_get_status($process)['running']) { proc_terminate($process, 9); }
			$output = gettext('Command timed out or returned too much output.');
			break;
		}
		usleep(20000);
	}
	foreach ($pipes as $pipe) { $output .= stream_get_contents($pipe); fclose($pipe); }
	proc_close($process);
	return [$rc, frp_redact_sensitive(trim(substr($output, 0, 1048576)))];
}

function frp_applied_toml() {
	return frp_read_file(FRP_APPLIED_PATH) ?? frp_get_runtime_toml();
}

function frp_admin_request($path, &$error, $toml = null) {
	$error = '';
	try {
		$data = frp_parse_toml($toml ?? frp_applied_toml());
		$admin = $data['webServer'] ?? [];
		$host = $admin['addr'] ?? '127.0.0.1';
		$port = $admin['port'] ?? 0;
		if (!in_array($host, ['127.0.0.1', '::1'], true) || !is_int($port) || $port < 1 || $port > 65535 || !empty($admin['tls'])) {
			throw new RuntimeException(gettext('Enable local management and apply the configuration to use live status and reload.'));
		}
		if (!in_array($path, ['/api/status', '/api/reload'], true)) { throw new RuntimeException('Invalid API action.'); }
		$auth = base64_encode(($admin['user'] ?? '') . ':' . ($admin['password'] ?? ''));
		$ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 2, 'follow_location' => 0,
		    'header' => "Authorization: Basic {$auth}\r\nConnection: close\r\n", 'ignore_errors' => true]]);
		$url = 'http://' . ($host === '::1' ? '[::1]' : $host) . ':' . $port . $path;
		$response = @file_get_contents($url, false, $ctx, 0, 1048577);
		$headers = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : ($http_response_header ?? []);
		$status = $headers[0] ?? '';
		if ($response === false || strlen($response) > 1048576 || !preg_match('~^HTTP/\S+ 200\b~', $status)) {
			throw new RuntimeException(gettext('The local FRP management API did not respond successfully.'));
		}
		return $response;
	} catch (Throwable $e) {
		$error = $e->getMessage();
		return null;
	}
}

function frp_proxy_rows($json) {
	$data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
	if (!is_array($data)) { throw new RuntimeException('Invalid status response.'); }
	$rows = [];
	foreach ($data as $type => $proxies) {
		if (!is_array($proxies)) { throw new RuntimeException('Invalid proxy list.'); }
		foreach ($proxies as $proxy) {
			if (!is_array($proxy)) { continue; }
			$row = [];
			foreach (['name', 'type', 'status', 'err', 'local_addr', 'remote_addr'] as $field) {
				$value = $proxy[$field] ?? ($field === 'type' ? $type : '');
				$row[$field] = is_scalar($value) ? frp_redact_sensitive(substr((string) $value, 0, 1024)) : '';
			}
			$rows[] = $row;
			if (count($rows) >= 500) { break 2; }
		}
	}
	usort($rows, fn($a, $b) => strcmp($a['name'], $b['name']));
	return $rows;
}

function frp_live_status() {
	$running = frp_is_running();
	$result = ['running' => $running, 'enabled' => frp_is_enabled(), 'healthy' => null, 'proxies' => [],
	    'state' => $running ? gettext('Running') : gettext('Stopped'), 'detail' => '', 'updated' => time()];
	$result['carp'] = frp_carp_status();
	if ($result['enabled'] && !$result['carp']['allowed']) {
		$result['state'] = $running ? gettext('Stopping for CARP') : gettext('CARP standby');
		$result['detail'] = sprintf(gettext('CARP state: %s. The client waits until the selected CARP policy permits MASTER operation.'), $result['carp']['state']);
		$result['healthy'] = $running ? false : null;
		return $result;
	}
	if (!$running) { $result['healthy'] = false; return $result; }
	$response = frp_admin_request('/api/status', $error);
	if ($response === null) { $result['detail'] = $error; return $result; }
	try {
		$result['proxies'] = frp_proxy_rows($response);
		if ($result['proxies']) {
			$result['healthy'] = !array_filter($result['proxies'], fn($p) => $p['status'] !== 'running' || $p['err'] !== '');
			$result['state'] = $result['healthy'] ? gettext('Tunnels running') : gettext('Tunnel problem');
		} else {
			$config = frp_parse_toml(frp_applied_toml());
			$result['healthy'] = empty($config['proxies']) ? null : false;
			$result['state'] = gettext('No active tunnels');
		}
		$result['detail'] = gettext('Reported by the local FRP client. This does not verify external reachability.');
		if ($result['carp']['managed']) { $result['detail'] .= ' ' . gettext('CARP MASTER.'); }
	} catch (Throwable $e) {
		$result['detail'] = gettext('Unable to read the proxy status response.');
	}
	return $result;
}

function frp_tcp_probe($host, $port, $timeout = 1) {
	if (!is_string($host) || $host === '' || preg_match('/[\x00-\x20\/\\\\]/', $host) ||
	    !is_int($port) || $port < 1 || $port > 65535) { return false; }
	$address = str_contains($host, ':') ? '[' . $host . ']' : $host;
	$socket = @stream_socket_client("tcp://{$address}:{$port}", $errno, $errstr, $timeout);
	if (!is_resource($socket)) { return false; }
	fclose($socket);
	return true;
}

function frp_diagnostics() {
	$data = frp_parse_toml(frp_get_runtime_toml());
	$host = $data['serverAddr'] ?? '';
	$port = $data['serverPort'] ?? 7000;
	$results = [];
	// Bound DNS separately; the system resolver used by FRP may use different settings.
	$resolved = is_string($host) && filter_var($host, FILTER_VALIDATE_IP) ? [$host] : [];
	if (!$resolved && is_string($host) && preg_match('/^[a-zA-Z0-9_.-]{1,253}$/', $host)) {
		[$rc, $answer] = frp_exec(['/usr/bin/host', '-W', '1', '-R', '1', $host], 3);
		if ($rc === 0 && preg_match_all('/has (?:IPv6 )?address ([a-fA-F0-9:.]+)/', $answer, $matches)) {
			$resolved = array_values(array_filter($matches[1], fn($ip) => filter_var($ip, FILTER_VALIDATE_IP)));
		}
	}
	$results[] = ['test' => gettext('Server DNS'), 'ok' => (bool) $resolved,
	    'detail' => $resolved ? implode(', ', $resolved) : gettext('Name could not be resolved by the system resolver.')];
	$protocol = $data['transport']['protocol'] ?? 'tcp';
	if (in_array($protocol, ['tcp', 'websocket', 'wss'], true)) {
		$ok = false;
		foreach (array_slice($resolved, 0, 2) as $ip) { if (frp_tcp_probe($ip, $port)) { $ok = true; break; } }
		$results[] = ['test' => gettext('Server TCP port'), 'ok' => $ok,
		    'detail' => gettext('TCP reachability only; authentication and TLS are checked by FRP.')];
	} else {
		$results[] = ['test' => gettext('Server transport'), 'ok' => null, 'detail' => gettext('A TCP probe does not test this UDP-based transport.')];
	}
	$budget = microtime(true) + 5;
	foreach (array_slice($data['proxies'] ?? [], 0, 10) as $proxy) {
		$name = is_string($proxy['name'] ?? null) ? $proxy['name'] : 'proxy';
		$local = $proxy['localIP'] ?? '127.0.0.1';
		if (!empty($proxy['plugin']) || ($proxy['type'] ?? '') === 'udp' || !filter_var($local, FILTER_VALIDATE_IP) || microtime(true) >= $budget) {
			$results[] = ['test' => $name, 'ok' => null, 'detail' => gettext('Not probed: plugin, UDP, hostname target, or time budget reached.')];
			continue;
		}
		$results[] = ['test' => $name, 'ok' => frp_tcp_probe($local, $proxy['localPort'] ?? 0, 0.5), 'detail' => gettext('Local target TCP port')];
	}
	return $results;
}

function frp_monitor_transition($state, $enabled, $healthy, $now, $delay = 120) {
	if (!$enabled) { return [['down_since' => null, 'alerted' => false], null]; }
	$state += ['down_since' => null, 'alerted' => false];
	if ($healthy === null) { return [$state, null]; }
	if ($healthy) {
		$event = $state['alerted'] ? 'recovered' : null;
		return [['down_since' => null, 'alerted' => false], $event];
	}
	if ($state['down_since'] === null) { $state['down_since'] = $now; }
	if (!$state['alerted'] && $now - $state['down_since'] >= $delay) {
		$state['alerted'] = true;
		return [$state, 'down'];
	}
	return [$state, null];
}
