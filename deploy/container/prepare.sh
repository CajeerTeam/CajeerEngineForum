#!/bin/sh
# Prepare exactly one approved signed dist for the Docker build context.
set -eu
[ "$#" = 4 ] || { echo 'Usage: prepare.sh DIST.zip RELEASE.pub SHA256 VERSION' >&2; exit 2; }
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
case "$3" in *[!0-9a-f]*|'') exit 2;; esac
[ "${#3}" = 64 ] || exit 2
# The artifact and public key are supplied out of band, never private signing material.
php "$root/deploy/verify.php" "$1" "$2" "$3" "$4"
mkdir -p "$root/deploy/container/artifacts"
stage=$(mktemp -d "$root/deploy/container/artifacts/.stage.XXXXXX")
trap 'rm -rf "$stage"' EXIT
install -m 0644 "$1" "$stage/release.zip"
install -m 0644 "$1.sig" "$stage/release.zip.sig"
install -m 0644 "$2" "$stage/release.pub"
php "$root/deploy/verify.php" "$stage/release.zip" "$stage/release.pub" "$3" "$4"
for name in release.zip release.zip.sig release.pub; do mv -f "$stage/$name" "$root/deploy/container/artifacts/$name"; done
