# FRP configuration synchronization

FRP participates in pfSense's native XMLRPC configuration synchronization. It
uses the configured peer, authentication and transport settings from **System >
High Availability Sync**, with no additional credentials or remote PHP commands.
The normal pfSense arrangement is primary-to-secondary configuration replication.

## Setup and opt-out

1. Install the same FRP Client package version on both nodes so both register
   the package's XMLRPC hooks. Updating files alone does not register new hooks;
   install/reinstall the new package through the pfSense package framework.
2. Configure working XMLRPC synchronization on the primary. The receiving node
   needs the normal **System - HA node sync** privilege and network access. At
   least one standard sync category must be selected: the pfSense sender skips
   package hooks when no standard sections are selected.
   On CE 2.8.1, apply the [native XMLRPC fix](patches/README.md) as well: the
   older receiver shadows its VIP sections when package hooks are present,
   preventing live VIP updates. FRP does not modify pfSense core files.
3. Under **Services > FRP Client > Connection, HA sync, notifications and TLS**,
   leave **Synchronize FRP settings with the HA peer** enabled on both nodes.
   It defaults to enabled, including for existing settings without the new key.
4. Review and save FRP changes on the primary. A successful local save requests
   the native HA sync. The UI says *requested*, not *completed*. Inspect
   **Status > System Logs** for the transport outcome and the receiving node's
   FRP package log for application failures. Ordinary native HA syncs include
   FRP too; unsuccessful transfers can be retried with the native HA sync action.

Uncheck the option and save on either node to prevent that node from sending or
accepting FRP settings. A primary cannot re-enable an opted-out receiver. Re-enable
it locally, then initiate a sync from the primary to catch up. Disabling FRP sync
does not change the firewall's other HA synchronization categories.

## What is shared

The shared settings are the full source TOML (including comments and FRP tokens),
the client enable flag, reconnect behavior, live-management enable/port,
notifications, CARP ownership policy and Certificate Manager references. Cleared settings and deleted
tunnels replace the previous shared values on the receiver.

The opt-out switch, generated loopback management credentials, runtime hashes,
recovery history, status and logs remain local. A fresh receiver creates its own
management credentials. The generated runtime files therefore may differ in
local credentials even though the user-facing FRP settings match.

For Certificate Manager selections, enable pfSense certificate synchronization
or provision the same references and certificate/key material on the receiver.
Referenced TOML includes, arbitrary certificate files and other local paths are
not transferred. They must exist on every node that uses them. The receiver
validates the generated configuration with its own installed `frpc`.

Receiving a change uses the same guarded apply/rollback procedure as a local
save. Unchanged configurations do not restart the service. Invalid imports and
concurrent local edits are rejected; a failed service update attempts to restore
the receiver's previous settings, runtime and service state. A remote failure
does not roll back a successful primary save. Receiving does not initiate a new
outgoing sync.

## CARP and tunnel ownership

The **CARP service ownership** selector offers three policies:

- **Automatic** (default): all configured CARP VIPs must be MASTER. A mixed role,
  BACKUP, INIT or unknown status prevents operation. With no CARP VIPs configured,
  the client retains standalone behavior.
- **A specific CARP VIP**: only that VIP determines ownership. A missing VIP keeps
  the client stopped; it never silently falls back to standalone operation.
- **Ignore CARP**: an explicitly enabled client runs regardless of CARP role.
  Identical tunnels may conflict if this is selected on multiple nodes.

Disabled CARP and persistent CARP maintenance prevent operation under either
managed policy. The shared client enable flag remains set on standby; the status
panel and widget report **CARP standby**, and intentional standby does not trigger
outage notifications. CARP policy remains active even if configuration sync is
locally disabled. Selected VIP IDs must match on peers (normal pfSense VIP sync
retains them).

The native CARP event hook checks live roles, rather than trusting potentially
stale event names. It starts on promotion and stops on loss of ownership. The rc
pre-start check prevents boot/manual starts on standby, and the supervisor checks
before each child launch and every two seconds during operation. A missed event
therefore still stops a running client; the existing minute monitor can recover
an unprocessed promotion. A busy save lock can delay the event handler by up to
15 seconds before the monitor fallback. Role checks never change CARP priorities,
VIP configuration or firewall rules.

Repeated MASTER events and monitor ticks do not restart an already eligible
client or reset the crash retry limit. A manual stop or exhausted five-exit budget
on a stable MASTER requires a manual start/save; a later real loss and regain of
ownership permits a fresh automatic start. Saving a standby configuration uses
**Save and restart**; proxy hot reload remains limited to a running client.

FRP connections are re-established after failover; established tunnel sessions
are not migrated. CARP determines ownership locally: a network partition that
leaves both peers reporting MASTER is not solved by this package. The two-node
acceptance test must check CARP communication and actual tunnel recovery.

## Implementation and verification

The package registers `plugin_carp`, `plugin_xmlrpc_send`, `plugin_xmlrpc_recv` and
`plugin_xmlrpc_recv_done` in its package XML. It defers the FRP write to completion
so the existing apply transaction can preserve the receiver's original settings
for rollback. A request-local handoff supports both CE 2.8.1's empty completion
argument and the result argument passed by current pfSense source. Both invoke
completion unconditionally, so the receive hook returns no paths or marker nodes.
The native sender represents empty paths as empty arrays; these are accepted for
cleared boolean options and certificate references. Non-empty arrays are rejected.

The source contract was checked against the official
[plugin dispatcher](https://github.com/pfsense/pfsense/blob/master/src/etc/inc/pfsense-utils.inc),
[HA sender](https://github.com/pfsense/pfsense/blob/master/src/etc/rc.filter_synchronize),
[XMLRPC receiver](https://github.com/pfsense/pfsense/blob/master/src/usr/local/www/xmlrpc.php)
and the [Netgate HA documentation](https://docs.netgate.com/pfsense/en/latest/highavailability/xmlrpc-sync.html).
The current public source is not certification of every CE/Plus release.

On 2026-09-05, 35 isolated HA checks passed on PHP 8.3.19 (pfSense VM), 8.4.7
(macOS) and 8.5.10 (container). They cover both callback variants, local opt-out,
selective transfer, unchanged imports, validation and start failures, rollback,
concurrent edits, local secrets and loop prevention. The native pfSense dispatcher
also discovered the hooks from the package XML on CE 2.8.1. These isolated checks
do not change live settings. The subsequent [two-node acceptance run](ha-acceptance-2026-09-05.md)
verified real XMLRPC transport, CARP failover, pfsync, DHCP failover and reboots.

The 35 CARP checks passed with PHP 8.3, 8.4 and 8.5 as well. The shell
supervisor tests verify blocked BACKUP startup, child termination, cancellation
of a pending crash retry and retention of the five-exit limit. The native
pfSense inspector also recognizes the CARP hook and status APIs. The package
with both HA sync and CARP contains 57 source files and was built, installed and
tested on both CE 2.8.1 VMs. Native live VIP replication was verified after
applying the official pfSense XMLRPC fix described above.
The event contract was checked against pfSense's
[MASTER handler](https://github.com/pfsense/pfsense/blob/master/src/etc/rc.carpmaster)
and [BACKUP handler](https://github.com/pfsense/pfsense/blob/master/src/etc/rc.carpbackup).

Run the isolated tests with `php tests/ha.php`. On a pfSense host, the following
checks real registration/dispatch using configuration held only in that PHP
process; it does not write settings, apply an import or send network traffic:

    php tests/pfsense-ha-hooks.php --inspect

The inspector also rejects the known native receiver with the VIP variable
shadowing bug. Resolve that prerequisite before treating VIP sync as ready.

For a two-node acceptance test, use a dedicated HA pair with the package installed
on both. Start with the FRP client disabled, change a comment/proxy and an option
on the primary, then save. Check identical shared settings on the receiver and
different local management credentials. Remove a proxy and clear an option to
verify replacement. Disable sync on the receiver, repeat the primary change and
verify that nothing changes there; re-enable and trigger native sync to catch up.
Also test primary opt-out, an unavailable peer, missing receiver certificate/file
references and receiver apply failure. Check logs and successful retry after
repair. Then select automatic CARP ownership (or the same dedicated VIP on both nodes),
enable the client and verify exactly one active FRP client/tunnel. Enter persistent
CARP maintenance on the active node: its FRP child must exit, the new MASTER must
start its client and a new end-to-end tunnel request must succeed. Leave
maintenance, check failback and repeat with a reboot. Confirm no outage alert on
the standby. Also test a lost role during crash backoff, mixed VIP states and a
missing selected VIP. Restore the original HA settings after acceptance.
