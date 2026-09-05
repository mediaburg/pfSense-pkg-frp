# Compatibility review — 2026-09-05

## Result

The integration still uses pfSense's documented XML/PHP package framework.
There is no identified framework removal that requires replacing this package.
The package was built and exercised on CE 2.8.1. For the current releases below,
this remains a source/documentation assessment, **not runtime certification**.

| Edition | Current published release | Release date | Package assessment |
| --- | --- | --- | --- |
| pfSense CE | 2.9.0 | 2026-08-20 | Framework still documented; install/runtime test pending |
| pfSense Plus | 26.07 | 2026-08-13 | Classic WebGUI integration only; install/runtime test pending |

Both releases use a FreeBSD 16-CURRENT base, according to Netgate's
[release/version table](https://docs.netgate.com/pfsense/en/latest/releases/versions.html).
The [CE 2.9.0 release notes](https://docs.netgate.com/pfsense/en/latest/releases/2-9-0.html)
list PHP 8.5.7 in the general component summary. Isolated regression tests pass
on PHP 8.5.10 as well as PHP 8.4.7; target integration tests used PHP 8.3.19.
These results do not establish integration with the exact current pfSense image.

The [Plus 26.07 release notes](https://docs.netgate.com/pfsense/en/latest/releases/26-07.html)
describe Netgate Nexus features. This package provides a classic PHP WebGUI page;
it does not provide a Nexus page or API.

## Sources and dependency limits

- Netgate's [package development documentation](https://docs.netgate.com/pfsense/en/latest/development/develop-packages.html)
  still describes `info.xml`, `packagegui`, PHP includes, service entries, and
  install/resync/deinstall hooks used by this package.
- The public pfSense `master` branch was inspected for `config_get_path`,
  `config_set_path`, `write_config`, `/etc/rc.packages`, package logging, CSRF
  handling, and package service startup. The observed head was
  [`9363ac5b8651a1c7a333180425ce7719070f95f9`](https://github.com/pfsense/pfsense/tree/9363ac5b8651a1c7a333180425ce7719070f95f9).
  The exact `RELENG_2_9_0` source URL returned HTTP 404 and was not in the public
  branch listing. Public `master` must not be treated as the exact CE or Plus
  release source.
- The public pfSense ports `devel` branch contains
  [`net/frp`](https://github.com/pfsense/FreeBSD-ports/blob/a621624266b19a7f48b1f94a60821d2c2fc6ee4c/net/frp/Makefile),
  version 0.65.0 with port revision 8 in the inspected snapshot. This establishes
  source availability, not availability of a compiled package in a particular
  pfSense repository or on a particular architecture.
- FRP's [configuration documentation](https://gofrp.org/en/docs/features/common/configure/)
  dates TOML support to 0.52.0 and documents `frpc verify`. The runtime dependency
  now enforces `frp>=0.52.0`.
- FRP Client is absent from Netgate's published
  [package list](https://docs.netgate.com/pfsense/en/latest/packages/list.html).
  Treat this project as a custom package. Netgate also documents limitations of
  [using software from FreeBSD](https://docs.netgate.com/pfsense/en/latest/recipes/freebsd-pkg-repo.html).
  Build for the target pfSense release and architecture; do not substitute a
  generic FreeBSD repository or force an incompatible package ABI.

## Corrections made

- Check temporary-file writes before invoking the validator; reject malformed
  POST values instead of triggering PHP type errors.
- Replace runtime files atomically and keep TOML permissions at `0600`.
  Report failed persistence, runtime writes, and service actions.
- Preserve the existing runtime configuration on first installation and keep the
  saved enable/disable state when importing console changes.
- Validate the actual runtime configuration before a GUI restart. Every rc.d
  start also verifies its configuration, with the same working directory.
- Stop through `onestop` after disabling, check status through rc.d, retain PID
  ownership in the daemon, and abort restart when stopping fails.
- Match the service name to the package's internal name (`frp`) so package
  lifecycle operations can find it. Mark startup during resync with
  `starts_on_sync` to avoid an additional start by the package framework.
- Use syslog instead of a daemon-held output file, allowing pfSense's package
  log rotation to work without separately signaling the daemon supervisor.
- Use authenticated loopback status for per-proxy health and conservative unknown
  state when unavailable. Keep historical log events separate from live status.
- Add reconnect/crash supervision, guarded reload, recovery history, revision
  checks, Certificate Manager integration, diagnostics, a dashboard widget and
  optional notifications. Bundle TOML parsing and editor assets locally.
- Respect pfSense theme display rules when hiding editor/review elements; add
  consistent padding, action hierarchy and collapsible advanced controls.

## Validation performed

- Regression suite passed 62 checks on PHP 8.3.19 (pfSense VM), 65 checks on
  PHP 8.4.7 (macOS ARM64, two native FRP binaries) and 59 checks on PHP 8.5.10
  (official PHP Linux container, simulated FRP verifier). Warnings are failures. Includes
  native HTTP requests and real OpenSSL certificate/private-key material.
- Generated managed and certificate configurations accepted by official FRP
  0.52.0 and 0.71.0 macOS ARM64 binaries and installed FRP 0.65.0 on pfSense.
- PHP/shell syntax, XML parsing, all 57 installed files, JavaScript syntax and
  whitespace checks. GitHub Actions matrix added for PHP 8.3/8.4/8.5; the workflow
  itself has not been run on GitHub during this review.
- Package built for **CE 2.8.1 / FreeBSD 15 / amd64**, installed over 0.1.0 and
  reinstalled. The VM had FRP 0.65.0_8 and PHP 8.3.19.
- Real loopback HTTP data transfer through FRP; a second proxy added by hot reload
  without changing the client PID; invalid apply preserved the running client.
- Server outage detected, automatic reconnection verified, killed child restarted,
  and stopping during the retry delay prevented a subsequent restart.
- Diagnostics, healthy-history retention, private file permissions and duplicate
  start refusal verified on the VM.
- Safari WebGUI: one visible editor, plain/syntax toggle, advanced controls,
  native validation, masked before/after review and successful proxy reload.
  A subsequent HTTP request reached the changed endpoint.
- Enabled reboot: exactly one client starts and retries the unavailable server.
  Disabled reboot: no client starts. Five forced crashes exhaust the restart
  budget and leave the supervisor stopped.
- Uninstall while running: client stopped and monitor cron removed. Reinstall:
  exactly one service and one cron entry. Installed file checksums passed.
  Original FRP settings and process state restored; all test listeners removed.
- Dashboard widget discovered, added and observed updating in Safari.
- FRP package log force-rotated with newsyslog; both a test logger message and
  actual FRP management requests reached the new active file.
- Two-node HA acceptance on CE 2.8.1: native FRP sync and opt-outs, CARP ownership,
  real tunnel failover/failback, both VM reboots, actual TCP state replication and
  DHCP offers. Native VIP activation required the official pfSense XMLRPC fix
  7a9b526 on both VMs. See the [detailed HA report](ha-acceptance-2026-09-05.md).

The ports tree on this older VM invokes a pkg create option (-T) unsupported by
its installed pkg 1.21.3. Staging and manifest generation succeeded. The package
archive was therefore created with the existing pkg tool from the same staged
files and generated metadata:

    make PORTSDIR=/root/FreeBSD-ports-devel OSVERSION=$(sysctl -n kern.osreldate) clean
    make PORTSDIR=/root/FreeBSD-ports-devel OSVERSION=$(sysctl -n kern.osreldate) package
    pkg create -m work/.metadir.pfSense-pkg-frp -r work/stage -p work/.PLIST.mktmp -o /root/pkg-output

During package removal, the same older pkg tool also reported a SQLite foreign
key failure in its shared-library cleanup, after the package hooks and file
removal completed. The pfSense framework had already removed the rc script,
causing a separate missing-file notice. Reinstallation and targeted package
checksum verification succeeded. This does not certify a clean generic pkg
database operation on this VM; resolving its pkg/database version mismatch
requires separate system maintenance.

This is a test-environment build-tool mismatch. No system packages, repositories
or ABI settings were changed to work around it. All 54 installed files and the downloaded package contents were compared byte
for byte against the final source (with the XML version placeholder expanded).
The resulting FreeBSD:15:amd64 artifact must not be used as a CE 2.9.0 / Plus 26.07 package.

See [test instructions](testing.md) for the reproducible fixture and restoration
procedure. The pre-test configuration and original package were retained on the
VM; test credentials and full configuration files are not included in this repo.

## Target-system acceptance checks

On a test firewall running the intended release:

1. Record `cat /etc/version`, `uname -KU`, `php -v`, `pkg info frp`, and
   `frpc --version`. Confirm that the matching package repository or build
   artifacts provide the `frp` dependency.
2. Build and install the package in a matching pfSense build/test environment.
   Verify the Services menu, FRP privilege, Package Manager entry, and package
   log tab. Confirm one FRP service entry after upgrade from 0.1.0.
3. Save a valid configuration for a test FRP server. Verify login, a proxy
   connection, the runtime file's `0600` mode, and the enabled rc.conf setting.
4. Reject invalid TOML without altering the running service. Make an invalid
   console edit and confirm that GUI restart refuses it. Restore valid TOML and
   confirm import preserves the saved enable state.
5. Disable the client and confirm it stops. Enable it, reboot, and confirm one
   client starts. Reboot while disabled and confirm it stays stopped.
6. Disconnect/reconnect the test server and confirm that new log events replace
   stale status. Rotate the package log and verify new messages reach the active
   file. Use console logging for FRP during this check.
7. Test configuration restore, package reinstall/upgrade, and uninstall. Confirm
   service registration stays consistent and uninstall stops the managed client.
