#!/usr/bin/env bash
# Run on a release host with PHP 8.4+, Composer 2, Node and ext-zip.
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
cd "$root"
[[ -n ${RELEASE_SIGNING_KEY_FILE:-} && -f "$RELEASE_SIGNING_KEY_FILE" ]] || { echo 'RELEASE_SIGNING_KEY_FILE required for release build' >&2; exit 1; }
[[ -n ${RELEASE_TRUSTED_PUBLIC_KEY_FILE:-} && -f "$RELEASE_TRUSTED_PUBLIC_KEY_FILE" ]] || { echo 'RELEASE_TRUSTED_PUBLIC_KEY_FILE required to verify Ed25519 signatures' >&2; exit 1; }
if [[ ! -f composer.lock ]]; then
  echo 'Missing composer.lock. Maintainer: composer update --no-install; review and commit composer.lock.' >&2
  exit 1
fi
if [[ -n $(git status --porcelain --untracked-files=no) ]]; then
  echo 'Commit tracked changes before building a release.' >&2
  exit 1
fi
git ls-files --error-unmatch composer.lock >/dev/null
out=${1:-$root/storage/releases}
mkdir -p "$out"
out=$(cd "$out" && pwd)
stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT
# Do not use a running installation as a build context.
git archive HEAD | tar -xf - -C "$stage"
cd "$stage"
export SOURCE_DATE_EPOCH=${SOURCE_DATE_EPOCH:-$(git -C "$root" show -s --format=%ct HEAD)}
export TZ=UTC
composer validate --strict
composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
composer check-platform-reqs --no-dev
composer audit --locked --no-dev --format=json --abandoned=fail > "$out/dependency-audit.json"
node admin/scripts/publish-admin-assets.mjs
node admin/scripts/publish-admin-assets.mjs --check
php bin/cajeer admin:assets --check
php bin/cajeer release:verify --dist --strict
php tools/build-release.php --all --target-dir="$out" --json > "$out/build-report.json"
version=$(php -r '$r=json_decode(file_get_contents("release.json"),true,512,JSON_THROW_ON_ERROR); if (($r["edition"]??null)!=="forum" || ($r["upstream_version"]??null)!==trim(file_get_contents("VERSION"))) exit(1); echo $r["edition_version"];')
for mode in source dist; do
  php bin/cajeer release:verify --"$mode" --strict --artifact="$out/cajeerengine-$version-$mode.zip" --json
  php bin/cajeer release:sign --package="$out/cajeerengine-$version-$mode.zip" --secret-key="$RELEASE_SIGNING_KEY_FILE"
done
php tools/verify-signed-release.php "$out" "$RELEASE_TRUSTED_PUBLIC_KEY_FILE"
# This output is not certified for publication without CI matrix evidence and
# a real physical host report bound to these same bytes.
echo "Signed release candidate in $out (not certified for publication)"
