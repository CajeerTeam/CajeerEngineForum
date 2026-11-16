#!/usr/bin/env bash
# Publish only already verified and signed artifacts. Existing releases are never overwritten.
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
[[ $# == 3 ]] || { echo 'Usage: github-publish-release.sh DIR TAG OWNER/REPO' >&2; exit 2; }
dir=$(cd "$1" && pwd)
tag=$2
repo=$3
version=$(php -r '$r=json_decode(file_get_contents($argv[1]."/release.json"),true,512,JSON_THROW_ON_ERROR); if (($r["edition"]??null)!=="forum" || ($r["upstream_version"]??null)!==trim(file_get_contents($argv[1]."/VERSION"))) exit(1); echo $r["edition_version"];' "$root")
[[ "$tag" == "v$version" && "${repo,,}" == cajeerteam/cajeerengineforum ]] || { echo 'Tag or repository mismatch' >&2; exit 1; }
# A CI-generated key is evidence of pipeline execution, not publisher trust.
# The publisher MUST independently provision the authorized Ed25519 public key.
[[ -n ${RELEASE_TRUSTED_PUBLIC_KEY_FILE:-} && -f "$RELEASE_TRUSTED_PUBLIC_KEY_FILE" ]] || { echo 'RELEASE_TRUSTED_PUBLIC_KEY_FILE is required' >&2; exit 1; }
[[ $(git -C "$root" rev-parse HEAD) == $(git -C "$root" rev-list -n1 "refs/tags/$tag") ]] || { echo 'Checkout does not match local release tag' >&2; exit 1; }
python3 "$root/tools/certification-evidence.py" verify "$dir" --trusted-key "$RELEASE_TRUSTED_PUBLIC_KEY_FILE"
python3 - "$dir/certification.json" <<'PY_RELEASE'
import json, sys
report=json.load(open(sys.argv[1]))
if report.get('signing')!='trusted-release-key':
    raise SystemExit('Cannot publish a branch/PR certification signed with an ephemeral CI key')
PY_RELEASE
python3 "$root/tools/verify-certified-run.py" "$dir/ci-jobs.json" "$repo" "$tag" "$(git -C "$root" rev-parse HEAD)"
remote_commit=$(gh api "repos/$repo/commits/$tag" --jq '.sha')
[[ "$remote_commit" == "$(git -C "$root" rev-parse HEAD)" ]] || { echo 'Remote release tag moved or points to the wrong commit' >&2; exit 1; }
files=("$dir/certification.json" "$dir/production-acceptance.json" "$dir/dependency-audit.json" "$dir/ci-jobs.json" "$dir/ci-release.pub")
for mode in source dist; do
  name="cajeerengine-$version-$mode.zip"
  for suffix in '' .sha256 .sig; do
    [[ -s "$dir/$name$suffix" ]] || { echo "Missing $name$suffix" >&2; exit 1; }
    files+=("$dir/$name$suffix")
  done
  (cd "$dir" && sha256sum --strict --check "$name.sha256")
  manifest="$dir/cajeerengine-$version-$mode.manifest.json"
  [[ -s "$manifest" ]]
  files+=("$manifest")
done
index="$dir/cajeerengine-$version-artifacts.json"
[[ -s "$index" ]]
files+=("$index")
# Verify remote tag exists and stage all assets in a draft before exposing the release.
# gh release create refuses an existing release; no --clobber or deletion is performed.
if gh release view "$tag" --repo "$repo" >/dev/null 2>&1; then
  echo "Release $tag already exists (including draft); refusing to overwrite" >&2
  exit 1
fi
flags=()
if [[ "$version" == *-* ]]; then flags+=(--prerelease); fi
gh release create "$tag" --repo "$repo" --verify-tag --draft --title "CajeerEngine Forum $version" --generate-notes "${flags[@]}" "${files[@]}"
# A failed upload leaves a draft, not a partially published release.
# Verify names and byte counts for all assets before publication.
python3 - "$repo" "$tag" "${files[@]}" <<'PY'
import hashlib, json, pathlib, subprocess, sys
repo, tag, *files = sys.argv[1:]
release = json.loads(subprocess.check_output(['gh','release','view',tag,'--repo',repo,'--json','isDraft,databaseId']))
if not release['isDraft']:
    raise SystemExit('Expected draft release')
raw = json.loads(subprocess.check_output(['gh','api',f"repos/{repo}/releases/{release['databaseId']}"]))
assets = {a['name']: a for a in raw['assets']}
for file in files:
    p = pathlib.Path(file)
    a = assets.get(p.name)
    if not a or a['size'] != p.stat().st_size or a.get('state') != 'uploaded':
        raise SystemExit('Missing/incomplete asset: ' + p.name)
    with p.open('rb') as stream:
        expected = 'sha256:' + hashlib.file_digest(stream, 'sha256').hexdigest()
    if a.get('digest') != expected:
        raise SystemExit('Missing/incorrect remote SHA256 digest: ' + p.name)
PY
gh release edit "$tag" --repo "$repo" --draft=false
