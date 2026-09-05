#!/bin/sh
# Run on FreeBSD with pkg, PHP, an installed frp and a pfSense ports tree.
# Only stage/package: never install this package or run its pfSense hooks here.
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
version=$(sh "$root/scripts/release-version.sh")
if [ "$(uname -s)" != FreeBSD ]; then
    echo 'Build this package on FreeBSD; do not override its ABI.' >&2
    exit 1
fi
: "${PORTSDIR:=/usr/ports}"
[ -f "$PORTSDIR/Mk/bsd.port.mk" ] || { echo 'Set PORTSDIR to a pfSense ports tree.' >&2; exit 1; }
for command in make pkg php; do command -v "$command" >/dev/null; done
pkg query '%v' frp >/dev/null
output=${1:-"$root/dist"}
mkdir -p "$output"
output=$(CDPATH= cd -- "$output" && pwd)
build=$(mktemp -d /tmp/frp-package.XXXXXXXX)
trap 'rm -rf "$build"' EXIT HUP INT TERM
osversion=$(sysctl -n kern.osreldate)
abi=$(pkg config ABI | cut -d: -f1,2):\*
target=$(printf '%s' "$abi" | tr -d ':' | sed 's/\*/-noarch/')
asset="pfSense-pkg-frp-$version-$target.pkg"
[ ! -e "$output/$asset" ] || { echo "Output already exists: $output/$asset" >&2; exit 1; }

make -C "$root" PORTSDIR="$PORTSDIR" OSVERSION="$osversion" \
    WRKDIR="$build/work" PKGORIGIN=net/pfSense-pkg-frp \
    BATCH=yes stage create-manifest
# Compatible with pkg 1.21 as well as current pkg (no newer -T option).
pkg create -f txz -m "$build/work/.metadir.pfSense-pkg-frp" \
    -r "$build/work/stage" -p "$build/work/.PLIST.mktmp" -o "$build"
package="$build/pfSense-pkg-frp-$version.pkg"
php "$root/scripts/verify-package.php" "$package" "$version" "$abi"
cp "$package" "$output/$asset"
{
    printf 'version=%s\nabi=%s\n' "$version" "$abi"
    printf 'source_commit=%s\n' "${GITHUB_SHA:-local-worktree}"
    printf 'ports_commit=%s\n' "${PORTS_COMMIT:-local-tree}"
    printf 'build_system=%s\nkernel_osversion=%s\n' "$(uname -sr)" "$osversion"
    printf 'pkg_version=%s\nfrp_version=%s\n' "$(pkg -v)" "$(pkg query '%v' frp)"
} > "$output/BUILD-INFO.txt"
(cd "$output" && sha256 -q "$asset" | awk -v name="$asset" '{print $0 "  " name}') > "$output/SHA256SUMS"
printf 'Package verified: %s/%s\n' "$output" "$asset"
