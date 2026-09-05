# Testing FRP Client

## Local regression checks

PHP 8.3 or newer, OpenSSL, a POSIX shell, ps, Python 3 and Node.js:

    php tests/run.php
    php tests/ha.php
    php tests/carp.php
    python3 tests/supervisor.py
    python3 tests/check.py
    node --check files/usr/local/www/frp-assets/frp.js
    git diff --check

The suite substitutes pfSense APIs and runtime paths in a temporary private
directory. It covers the POST controller, invalid input, concurrent edits,
transaction rollback, permissions, full TOML parsing, masked diffs, Certificate
Manager exports, authenticated loopback HTTP, service state and notification
transitions. It never sends real notifications. The temporary HTTP server and
test processes are stopped when the suite exits.

Pass official native FRP binaries to also test generated configurations:

    php tests/run.php /path/to/frp-0.52.0/frpc /path/to/frp-0.71.0/frpc

The GitHub Actions workflow runs the isolated checks on PHP 8.3, 8.4 and 8.5.
It does not emulate pfSense's package framework or FreeBSD service management.

The HA suite covers native send/receive callback semantics, both completion
argument variants, opt-out, local credentials, repeated imports, rollback and
loop prevention. See [HA checks](ha.md) for the read-only native dispatcher
check and a real two-node acceptance procedure.

The CARP suite covers live-role decisions, stale/repeated events, startup gates,
standby saves and HA imports, promotion/demotion, maintenance, notification
suppression and preservation of a manual stop/crash exhaustion on a stable
MASTER. The supervisor test runs the actual shell wrapper with private paths and
a simulated role gate; it verifies child termination and cancellation of crash
retries when eligibility is lost.

## Dedicated pfSense VM

The script tests/pfsense.php changes the FRP settings and starts/stops the managed
service. Use it only on an explicitly authorized test VM with a configuration
backup and a separately retained copy of the previously installed package.

The installed FRP package must match this source, with frpc and frps installed.
The fixture reserves loopback ports 27000, 27400, 28000, 28001 and 28080 and refuses
to proceed if they are in use. It saves only the FRP subtree and relevant runtime
files in /root/frp-acceptance-20260905/backup.json, mode 0600.

    php tests/pfsense.php --test-vm setup
    php tests/pfsense.php --test-vm exercise

Setup starts a local HTTP target and frps, and sends an HTTP payload through a
real FRP tunnel. Exercise tests proxy reload with unchanged PID, invalid apply,
outage detection, reconnection, crash restart, stopping during the retry delay,
diagnostics, healthy-history retention and permissions.

For boot acceptance:

    php tests/pfsense.php --test-vm enable
    shutdown -r now
    # reconnect after the reboot
    php tests/pfsense.php --test-vm check-enabled
    php tests/pfsense.php --test-vm disable
    shutdown -r now
    # reconnect after the reboot
    php tests/pfsense.php --test-vm check-disabled

The test server does not start at boot. An enabled client should remain running
and retry the unavailable loopback server. This deliberately tests startup
without a reachable server.

After success or failure, restore the original FRP settings and process state:

    php tests/pfsense.php --test-vm restore

The private backup is retained for inspection. Restore does not revert unrelated
pfSense configuration or remove the installed package. Do not edit FRP settings
concurrently during this acceptance run.

Additional manual checks:

- Use the WebGUI to validate, review and apply a proxy-only change. Verify the
  changed endpoint and preserved client PID. Check syntax/plain editor switching,
  advanced options, unsaved indicator, stale-tab rejection and dashboard widget.
- Force-rotate only the FRP newsyslog configuration, then verify both a logger
  message and actual FRP messages reach the new active file.
- Kill five supervised clients in one ten-minute window; after the final retry
  delay the supervisor must stop. A manual start must recover it.
- Upgrade from 0.1.0, repeat installation, uninstall while running, then reinstall.
  Verify one service registration, one monitor cron entry, stopped processes and
  removed cron on uninstall. Retain the FRP backup outside the package directory.
- Run these checks on the actual intended pfSense release/architecture; a CE
  2.8.1 result does not certify CE 2.9.0 or Plus 26.07.
