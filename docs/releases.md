# Building and publishing releases

The initial release version is **0.1.1**. `PORTVERSION` in the Makefile is the
single version source; the build expands it into both package XML files.
Earlier local acceptance-test archives labelled 0.2.0 were development builds,
not published releases.

## GitHub Actions

Commit and push the intended source, including `.github/workflows/`, first.
In **Actions > Release > Run workflow**, choose the branch for a trial build.
After success, the run contains a downloadable artifact with:

- `pfSense-pkg-frp-0.1.1-FreeBSD15-noarch.pkg`
- `SHA256SUMS`
- `BUILD-INFO.txt` (source commit, ports snapshot, OS and tool versions)

To publish that source as 0.1.1, tag the intended commit and push the tag:

    git tag -a v0.1.1 -m "FRP Client 0.1.1"
    git push origin v0.1.1

A tag push runs PHP 8.3/8.4/8.5 regression checks, syntax/inventory checks, then
builds on FreeBSD 15.0 and tests against the installed FRP binary. The actual
archive is checked for version, ABI, dependency, hooks, license, file inventory
and byte-for-byte agreement with the source before publishing. A mismatched tag
fails before building. Manual runs never publish, even when started on a tag.

The workflow needs GitHub Actions enabled. It uses the automatically supplied
`GITHUB_TOKEN`, with write permission only in the publish job. No SSH credentials,
private VM access or extra repository secrets are required. Existing releases
are not overwritten; `gh release create` fails if the release already exists.

## Build target

The workflow pins the VM action and pfSense ports snapshot. It produces a
**FreeBSD:15:*** package. The port sets the standard `NO_ARCH` flag because it
contains only interpreted code and assets; the native FRP binary remains an
external dependency. This also avoids a build-kernel minimum version on a
package without native objects. The package verifier rejects ELF payloads and
unexpected native-library dependencies. No ABI override is used.

Package behavior has been tested on **pfSense CE 2.8.1 / amd64**. ARM targets are
not acceptance-tested. FreeBSD 16 based CE 2.9.0 / Plus 26.07 require a separate
matching build and acceptance test; the first release does not claim those
targets. See [compatibility](compatibility.md). The VM action currently provides
a stable FreeBSD 15.0 image; its FreeBSD 16 CURRENT image is shelved, as listed in
the [upstream VM documentation](https://github.com/vmactions/freebsd-vm).

Only the disposable build VM uses the FreeBSD package repository for its tools
and FRP regression binary. Do not enable that repository on a pfSense firewall.
Install FRP >= 0.52.0 from the target firewall's matching pfSense repository
before adding the custom package. The package manifest records the build VM's
installed FRP version; that binary is not included in the download.

## Local build on a matching test/build host

With PHP, pkg, FRP and a pfSense ports tree already available:

    PORTSDIR=/path/to/pfsense-ports sh scripts/build-package.sh

This stages into a fresh temporary directory, generates the standard ports
manifest and uses `pkg create` with options also supported by pkg 1.21. It does
not install the resulting package or invoke pfSense hooks. Output is placed in
`dist/` (gitignored). An existing same-named package causes an error; choose a
fresh output directory as the optional first argument for another build.

For the pinned CI snapshot, see `PORTS_COMMIT` in the workflow. `BUILD-INFO.txt`
marks a local tree as such unless `PORTS_COMMIT` and `GITHUB_SHA` are supplied.
The ports snapshot is fixed; packages installed into the CI VM are resolved
from the repository at run time, so byte-for-byte reproducibility is not claimed.

Verify a download on FreeBSD with:

    sha256 -q pfSense-pkg-frp-0.1.1-FreeBSD15-noarch.pkg

Compare the result with `SHA256SUMS`. On Linux, use `sha256sum --check SHA256SUMS`.
The checksum detects a damaged download; the custom package is unsigned.

## Verification before release

On 2026-09-05, actionlint 1.7.12 accepted both workflows and the version guard
rejected mismatched/invalid tags. The build script produced the 0.1.1 NO_ARCH
archive on CE 2.8.1 using pkg 1.21.3. All 57 installed source files, metadata
and package hooks passed archive verification; package installation and
integrity checks passed on both HA test nodes.

The main suite passed 62 checks on PHP 8.3 / FRP 0.65 and 65 on PHP 8.4 with
FRP 0.52/0.71. Each of PHP 8.3, 8.4 and 8.5 passed 35 HA and 35 CARP checks.
The real shell supervisor, syntax and inventory checks also passed. Native
two-node acceptance covered configuration sync, opt-out, failed-import
rollback, CARP transitions, pfsync and reboot recovery. See the full
[acceptance report](ha-acceptance-2026-09-05.md) for results and limits.

CE 2.8.1 requires the official XMLRPC fix for native VIP synchronization;
[patch instructions](patches/README.md) are provided separately. The FRP
package does not patch pfSense core files.

GitHub's [Actions runs](https://github.com/mediaburg/pfSense-pkg-frp/actions)
record cloud build results. Published assets and their build metadata appear
on the [releases page](https://github.com/mediaburg/pfSense-pkg-frp/releases).
