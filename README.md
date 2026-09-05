# pfSense FRP Client package

Manage [FRP](https://github.com/fatedier/frp) client tunnels from **Services > FRP
Client** in the classic pfSense WebGUI. Package version 0.1.1 requires FRP 0.52.0
or newer and PHP 8.3 or newer.

## Features

- TOML editor with locally bundled syntax highlighting, line numbers, search,
  plain-text fallback and an unsaved-changes indicator.
- Validate with the installed frpc, review a masked configuration comparison,
  then save and restart or reload proxy changes without restarting the client.
- Detect concurrent browser and console edits. On apply failure, attempt to
  restore the previous configuration and service state.
- Live per-tunnel status through the authenticated loopback FRP management API,
  also available as a dashboard widget.
- Startup reconnection and bounded crash recovery: ten seconds between retries,
  stopping after five process exits in ten minutes.
- Optional outage/recovery notifications through pfSense's configured
  destinations; disabled by default.
- Load runtime, last known healthy or previously applied settings into the
  editor for review and recovery.
- Bounded DNS, server TCP and local-target connectivity diagnostics.
- Client certificate and trusted CA selection from Certificate Manager.
- pfSense package logs with normal log rotation.
- Configuration replication through pfSense's XMLRPC HA sync, enabled by default
  with an independent send/receive opt-out on each node.
- CARP service ownership: start on MASTER, stop on BACKUP, with a selectable VIP
  and a supervisor check during operation and crash retries.

## Configuration

Enable the client, enter your TOML, select **Review changes**, then choose
**Save and restart**. Disabling it and saving stops the managed service.

**Connection, HA sync, notifications and TLS** contains the additional package options.
On first use, reconnect and local management are offered enabled in the form;
existing installations retain their runtime behavior until you save those
options. Local management replaces the TOML webServer block with an
authenticated API bound to 127.0.0.1. Select a free local port if 7400 is in use.

The editor's source and comments are retained in config.xml. The generated
runtime TOML is written atomically to /usr/local/etc/frpc.toml, mode 0600.
Package settings can override startup retry, management and transport TLS fields
in this runtime file; the corresponding editor source remains intact.

**Save and reload proxies** only accepts changes to proxies, visitors and their
selection on a running client. Changes to common settings, includes or templates
require a restart. FRP's native configuration verifier remains the final check.

Recovery actions only load text/settings into the editor. Review and save to
apply them. A healthy snapshot is recorded by the minute monitor when live proxy
status is healthy. A previously applied snapshot is also retained; it does not
prove that the tunnel ever connected.

The status table reports the local FRP client's view. It does not establish
external reachability or end-to-end application health. Diagnostics test DNS
and TCP reachability, not authentication or TLS handshakes. Plugin, UDP and
hostname-based local targets are skipped; at most ten local targets are checked.

Selected certificates are exported to private, content-addressed files in
/usr/local/etc/frp/certificates. Apply again after certificate renewal. Old
exports are retained for recovery. Configuration backups, retained history,
runtime files and certificate exports contain secrets and should be kept private.

FRP console output is sent through syslog to /var/log/frp.log. Set
log.to = "console" to see FRP's own messages in the package log. A custom file
destination in TOML is handled by FRP itself.

### High Availability sync

Install the same FRP Client package on both HA nodes and configure the usual
XMLRPC synchronization under **System > High Availability Sync** on the primary.
Saving FRP settings requests that native sync; the receiver validates and applies
the shared TOML and package options. In the advanced options, uncheck
**Synchronize FRP settings with the HA peer** and save to stop both sending and
receiving on that node. The switch itself stays local.

CE 2.8.1 needs the [official native XMLRPC correction](docs/patches/README.md)
for live CARP VIP synchronization when package hooks are installed. The package
itself does not patch pfSense. Both nodes in the [HA acceptance test](docs/ha-acceptance-2026-09-05.md)
include this correction.

**CARP service ownership** defaults to automatic: all configured CARP VIPs must
be MASTER before FRP runs. Without CARP VIPs, standalone behavior is preserved.
You can follow one specific VIP instead or explicitly ignore CARP. Standby nodes
keep the shared enable flag and configuration, but leave their client stopped.
The CARP policy is independent of the local configuration-sync switch.
See [HA behavior and testing](docs/ha.md) for settings, certificates, failover
behavior and deployment checks.

## Installation and compatibility

This is a custom package, not an official Netgate-supported package.
Build against a matching pfSense ports tree for the target release and
architecture, then install the resulting package:

    make package
    pkg add /path/to/pfSense-pkg-frp-0.1.1.pkg

For an explicitly intended replacement of an installed custom package, pkg add
-f runs the package installation hooks again. Back up the configuration and old
package first. Do not force a mismatched FreeBSD ABI or enable a generic FreeBSD
package repository.

The package has been exercised on **pfSense CE 2.8.1 / FRP 0.65.0**, with separate
PHP 8.5 regression checks. Current CE 2.9.0 and Plus 26.07 still require their own
target-system acceptance test. See [compatibility review](docs/compatibility.md)
and [testing instructions](docs/testing.md) for exact scope and limitations.

Uninstall stops the managed service and removes its monitor cron entry. Runtime
TOML, private snapshots/certificates and log data are retained for recovery.

## Development

The [release workflow](.github/workflows/release.yml) tests and builds a package
on FreeBSD 15.0. Push a matching version tag such as **v0.1.1** to publish the
package, SHA256 checksum and build information as a GitHub Release. A manual
**Run workflow** builds downloadable artifacts without publishing a release.
See [release instructions](docs/releases.md) for the steps and target limitations.

    php tests/run.php
    php tests/ha.php
    php tests/carp.php
    python3 tests/supervisor.py
    python3 tests/check.py
    node --check files/usr/local/www/frp-assets/frp.js

After changing the installed file inventory:

    python3 tests/check.py --write-plist

Vendored parser/editor versions and licenses are listed in
[THIRD_PARTY.md](THIRD_PARTY.md). No editor assets are loaded from a CDN.
