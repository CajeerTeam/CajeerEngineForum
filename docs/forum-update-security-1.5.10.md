# Forum Edition 1.5.10 — Update Security & Supply Chain

## Trust policy and key rotation

The default remains the out-of-band `UPDATE_TRUSTED_PUBLIC_KEY_FILE`. Never store a publisher private key on the forum host. For root-controlled trust on Linux, provision `/etc/cajeerengine/release-trust.json` and set `UPDATE_TRUST_POLICY_FILE=/etc/cajeerengine/release-trust.json`. This policy takes precedence over the legacy key; invalid policy never falls back. It must be a canonical regular file, root-owned, with no group/other write permission in any path component. The deployment service account and the web administrator must not own the policy or its ancestors.

Policy format: JSON object with integer `schema: 1` and `keys`, an array of 1–32 entries. Each entry has `key_id` (lower-case SHA-256 of the decoded 32-byte Ed25519 public key), `public_key` (base64), `not_before` and `not_after` (UTC `YYYY-MM-DDTHH:MM:SSZ`), and Boolean `revoked`. Validity is `[not_before, not_after)`; the signer must be active at verification time and at authorization issuance. A revoked key never verifies, regardless of prior downloads. Invalid/duplicate entries invalidate the entire policy.

Generate the new publisher key on a separate signing machine with `php bin/cajeer release:keygen --secret-key=/secure/new.secret --public-key=/secure/new.pub`. Distribute the public key through an authenticated out-of-band channel. Generate an entry from a verified public key (adjust dates for actual rotation):

```sh
php -r '$p=trim(file_get_contents($argv[1])); $k=base64_decode($p,true); if ($k===false||strlen($k)!==32) exit(1); echo json_encode(["key_id"=>hash("sha256",$k),"public_key"=>$p,"not_before"=>$argv[2],"not_after"=>$argv[3],"revoked"=>false],JSON_PRETTY_PRINT),PHP_EOL;' /secure/new.pub 2026-11-24T00:00:00Z 2027-11-24T00:00:00Z
```

Place old and new entries in `{"schema":1,"keys":[...]}` during the overlap. Install the policy with `sudo install -o root -g root -m 0644 /secure/release-trust.json /etc/cajeerengine/release-trust.json` while the update operator is stopped. Verify new signer in staging before switching the CI signing secret and configured public key together. Revoke the old key by setting `revoked` to `true`. In an emergency revoke immediately and stop auto-updates. Do not remove the active recovery journal; rollback is independent of publisher authorization. The manual Compose operator retains its separately provisioned immutable single publisher key; it now also checks the signed authorization using a locally mounted verifier.

## Signed release freshness and replay defense

`release:sign` retains the legacy v1 detached ZIP SHA-256 signature for upgrading from 1.5.9, and for Forum packages from 1.5.10 adds a separately domain-separated Ed25519 authorization over repository, key ID, ZIP digest, version, mode, issued-at and expiry (90 days). New clients require authorization for 1.5.10 and later, including prereleases. Legacy versions retain digest signature compatibility. Old clients do not enforce the added authorization. UTC clock synchronization is required; authorization issued more than five minutes in the future or expired is rejected. Trust is checked again after backup and before mutation.

A historical package past expiry must be re-authorized by the publisher over the **same verified bytes** with a currently trusted key; re-sign the original archive, never rebuild a published version. Distribute the refreshed sidecar through an authenticated publisher procedure. Automatic GitHub sidecar renewal is not implemented. An expired intermediate release blocks sequential upgrades until refreshed.

First successfully verified dist SHA-256 is pinned per version under `storage/app/updates/trust-pins/`, with exclusive locking and durable atomic writes. Re-signing identical bytes is allowed; a different ZIP for the same version is rejected even with a valid signature. Pins survive normal update rollback (updates directory excluded from backup restoration). Retain the directory across server migration. Do not remove a pin to bypass a rejection: investigate, revoke compromised keys, and publish a new version. This is local first-observation pinning, not transparency logging or protection against an attacker controlling the host or publisher private key.

## Supply chain and installed verification

The ZIP manifest contains `dependency_sbom`, a deterministic CycloneDX 1.5 runtime Composer component inventory including locked versions and source/dist references. The verifier derives it independently from the bundled lock; dist still requires the installed vendor inventory to match. Existing `composer audit --locked --no-dev --format=json --abandoned=fail` and independent qualification gates remain mandatory. Export the BOM from the signed manifest for external tools. This is not a complete inventory of OS packages or non-Composer dependencies.

Compare installed managed files against a separately obtained signed distribution of the installed version:

```sh
php bin/cajeer update:integrity --package=/secure/cajeerengine-1.5.10-dist.zip --sha256=APPROVED_SHA256
```

The command holds a shared operation lease, authenticates the archive, compares SHA-256 of managed files and detects extra core/vendor files. Exit status is nonzero on mismatch. User content, external extension trees, runtime state and configuration are outside this attestation. The first 100 mismatches and total count are reported; no repair is performed. The update journal records verified signer ID and authorization expiry.

## Release acceptance

Run `php tools/update-supply-chain-regression.php` and full CI. Signed dist install, real GitHub, key rotation on live hosts and physical acceptance remain required. No migrations or new Composer dependencies.
