# Two-node HA acceptance — 2026-09-05

## Test environment

The acceptance test used two pfSense CE 2.8.1-RELEASE / amd64 VMs with FRPC
0.65.0. The IPv4 test setup included CARP, native XMLRPC synchronization,
pfsync and ISC DHCP failover. FRP configuration synchronization and automatic
CARP ownership control were enabled on both nodes.

The final state had two healthy tunnels on the MASTER and an enabled but
intentionally stopped client on the BACKUP. Shared FRP settings matched;
local management credentials differed. Network addresses, host identifiers,
credentials and private recovery paths are omitted from this public report.

## Defects found and corrected

1. The native sender converts empty configuration paths to empty arrays. The FRP
   receiver previously rejected cleared options and certificate references.
   It now accepts only those empty arrays and still rejects non-empty arrays.
   Rejected imports now write a sanitized reason to the package log.
2. The old receive-completion marker would be written as a root configuration
   node by unpatched CE 2.8.1. FRP now uses its request-local pending state and
   returns no configuration paths. Both native completion signatures work.
3. CE 2.8.1's native XMLRPC package loop overwrites the variable needed to activate
   VIP changes. Both VMs received the exact `xmlrpc.php` changes from official
   [pfSense commit 7a9b526](https://github.com/pfsense/pfsense/commit/7a9b5263229799719643b64e304e2afd5675f86f).
   WAN and LAN priority changes then appeared in both saved configuration and
   live `ifconfig` output. Original priorities were restored. The core patch is
   documented [separately](patches/README.md) and is not installed by FRP.

## Observed tests

| Test | Result |
| --- | --- |
| Native XMLRPC FRP transfer | Shared TOML/options match on both nodes; different local management credentials |
| Empty options/certificate references | Real transport accepted after the compatibility fix |
| Repeated unchanged sync | Primary PID and standby runtime hash unchanged |
| Add/remove proxy | Two real tunnels worked; removal closed the second remote listener |
| Receiver opt-out | Update blocked; sender could not re-enable the receiver; catch-up worked after local re-enable |
| Sender opt-out | No FRP update on receiver; re-enable and sync caught up |
| Receiver apply failure | Deliberately blocked snapshot path rejected the update; prior settings/runtime survived; retry after repair worked |
| Manual forced start on BACKUP | Rejected; client remained stopped |
| Standby monitoring | Unknown/intentional standby health, no outage timer or alert |
| Missing selected VIP | Client remained stopped |
| Explicit ignore-CARP policy | Backup could run when explicitly selected; returning to automatic stopped it |
| Mixed WAN/LAN roles | Both clients stopped; consistent ownership restored operation |
| Maintenance failover | Test tunnel reached Firewall 2 after 3.48 s; exactly one running client |
| Maintenance failback | Test tunnel reached Firewall 1 after 2.98 s; exactly one running client |
| Firewall 1 reboot | Backup tunnel responded at 5.59 s after reboot command; Firewall 1 resumed at 88.30 s |
| Reboot interruption samples | First missing response at 0.63 s, backup response at 5.59 s; during return, missing at 84.62 s and primary response at 88.30 s |
| Firewall 2 reboot | No failed tunnel request during the 55 s observation; final boot verification confirmed BACKUP and FRP stopped |
| Actual pfsync state | Open TCP connection had identical state and creator IDs on both nodes |
| DHCP failover | Both servers returned to normal; DISCOVER probes received offers from both peer addresses with shared gateway/DNS |
| Native VIP sync | Changes to both WAN and LAN advertisement priorities were activated after the upstream fix |
| Existing external FRP server | Both configured tunnels returned the node-specific probe; takeover to Firewall 2 took 4.74 s |
| Final external tunnel check | Both existing tunnels returned Firewall 1; primary reported two healthy proxies, backup stopped |
| Installed package integrity | `pkg check -s pfSense-pkg-frp` passed on both nodes |

The final automated external-server failback assertion overlapped a manual WebGUI
CARP maintenance action and was not counted as an isolated timing result. The
manual maintenance cycle subsequently ended and the stable final checks passed.
A concurrent FRP edit was retained and synchronized; its final TOML hash matched
on both nodes.

The independent test server ran FRPS 0.71.0 on the Mac, using a random test token
and only two allowed proxy ports. The firewalls ran FRPC 0.65.0. The payload was
an HTTPS request to a temporary node-identifying file through the real tunnel.
Durations are observations from this LAN/test server, not a failover SLA. Existing
FRP TCP sessions reconnect; they are not migrated.

## Package and regression evidence

Both nodes have `pfSense-pkg-frp-0.1.1-FreeBSD15-noarch.pkg`, built natively on
CE 2.8.1 and verified against all 57 installed source files. SHA256:

```text
184b3b2f13fc9a1c0b550e80df985c248691277a472b5be67426d80d73693744
```

- 35 HA and 35 CARP checks passed on PHP 8.3.19, 8.4.7 and 8.5.10.
- Main suite: 62 checks with native FRPC 0.65.0 on pfSense; 65 with FRPC 0.52.0
  and 0.71.0 on macOS; 59 with the simulated verifier on PHP 8.5.10.
- Actual shell supervisor checks passed for BACKUP startup rejection, child
  termination, canceled crash retry and the retained five-exit limit.
- PHP/XML/shell and JavaScript syntax and installed-file inventory passed.
  The PHP container needs `procps`; macOS simulation needs access to `ps`.
- This is not a runtime certification of CE 2.9.0 / Plus 26.07, an IPv6 HA test,
  a network-partition/fencing test, or a sustained LAN-client NAT session test.

## Cleanup and recovery

Temporary probe rules, node-identifying web files and the independent FRPS
listener were removed after acceptance. The final service uses the pre-existing
FRP configuration. Private configuration and recovery backups were retained
outside the repository and release assets.
