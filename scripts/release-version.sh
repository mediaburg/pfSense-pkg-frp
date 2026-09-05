#!/bin/sh
# Optional argument: the exact release tag to validate against the Makefile.
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
version=$(awk '$1 == "PORTVERSION=" { value=$2; count++ } END { if (count != 1) exit 1; print value }' "$root/Makefile")
if ! printf '%s\n' "$version" | LC_ALL=C grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$'; then
    echo 'Makefile must contain one numeric major.minor.patch PORTVERSION.' >&2
    exit 1
fi
if [ "$#" -gt 1 ] || { [ "$#" -eq 1 ] && [ "$1" != "v$version" ]; }; then
    echo "Release tag must be v$version (from Makefile)." >&2
    exit 1
fi
printf '%s\n' "$version"
