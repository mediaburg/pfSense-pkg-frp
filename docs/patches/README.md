# Native pfSense XMLRPC prerequisite on CE 2.8.1

The unpatched CE 2.8.1 XMLRPC receiver reuses `$sections` when processing package
callbacks. This overwrites the variable used to activate virtual IP changes.
In the two-node test, changing primary WAN CARP `advskew` from 0 to 1 wrote 101
to the backup configuration, while its live interface remained at 100.

[Upstream commit 7a9b5263229799719643b64e304e2afd5675f86f](https://github.com/pfsense/pfsense/commit/7a9b5263229799719643b64e304e2afd5675f86f)
fixes the variable collision and improves package callback handling. The adjacent
patch contains exactly that commit's changes to `src/usr/local/www/xmlrpc.php`.
It does not include the commit's unrelated file changes. The source is licensed
under Apache 2.0 by the pfSense project/Netgate.

FRP does **not** automatically patch the operating system. On an authorized
CE 2.8.1 test node, retain a private backup, dry-run the patch, apply it only if
it matches, and check PHP syntax:

```sh
umask 077
mkdir /root/xmlrpc-backup
cp -p /usr/local/www/xmlrpc.php /root/xmlrpc-backup/xmlrpc.php
patch --dry-run -d /usr/local/www < /path/to/pfsense-ce-2.8.1-xmlrpc-7a9b526.patch
patch -d /usr/local/www < /path/to/pfsense-ce-2.8.1-xmlrpc-7a9b526.patch
php -l /usr/local/www/xmlrpc.php
```

Keep backups outside the document root. Do not force the patch onto a different
version or a file which already contains the fix. Check for the correction again
after a pfSense update; current public source already includes it.

Both acceptance VMs received this exact fix. Subsequent native XMLRPC transfers
activated priority changes on WAN and LAN, and restored the configured 0/100
primary/secondary priorities. `tests/pfsense-ha-hooks.php --inspect` detects the
legacy variable collision without changing configuration or sending traffic.
