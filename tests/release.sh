#!/bin/sh
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
version=$(sh "$root/scripts/release-version.sh")
[ "$(sh "$root/scripts/release-version.sh" "v$version")" = "$version" ]
for tag in v999.999.999 "$version" "v$version-rc1" '' 'v1.2.3;false'; do
    if sh "$root/scripts/release-version.sh" "$tag" >/dev/null 2>&1; then
        echo "Unexpectedly accepted tag: $tag" >&2
        exit 1
    fi
done
for script in "$root"/scripts/*.sh; do sh -n "$script"; done
php -l "$root/scripts/verify-package.php"
echo 'PASS: release version guard and build script syntax'
