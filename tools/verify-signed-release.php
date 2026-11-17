#!/usr/bin/env php
<?php

declare(strict_types=1);

// Verify real ZIP manifests, detached Ed25519 signatures and all release sidecars.
$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'CajeerEngine\\';
    if (!str_starts_with($class, $prefix)) { return; }
    $file = $root . '/core/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) { require_once $file; }
});

try {
    if ($argc !== 3) { throw new RuntimeException('Usage: php tools/verify-signed-release.php DIRECTORY TRUSTED_ED25519_PUBLIC_KEY'); }
    $dir = realpath($argv[1]);
    if ($dir === false || !is_dir($dir)) { throw new RuntimeException('Release directory missing'); }
    $key = realpath($argv[2]);
    if ($key === false || !is_file($key)) { throw new RuntimeException('Trusted key missing'); }
    $meta = json_decode((string) file_get_contents($root . '/release.json'), true, 512, JSON_THROW_ON_ERROR);
    $version = $meta['edition_version'] ?? null;
    if (($meta['edition'] ?? null) !== 'forum' || !is_string($version) || !preg_match('/^(?:\d+\.\d+\.\d+|1\.0\.0-rc\.1)$/D', $version)) {
        throw new RuntimeException('Invalid Forum Edition release identity');
    }
    $indexFile = $dir . '/cajeerengine-' . $version . '-artifacts.json';
    $index = json_decode((string) file_get_contents($indexFile), true, 512, JSON_THROW_ON_ERROR);
    if (($index['format'] ?? null) !== 'cajeerengine.release-artifacts.v1' || ($index['version'] ?? null) !== $version || count($index['artifacts'] ?? []) !== 2) {
        throw new RuntimeException('Invalid release artifact index');
    }
    $indexed = [];
    foreach ($index['artifacts'] as $item) {
        $mode = $item['mode'] ?? null;
        if (!in_array($mode, ['source', 'dist'], true) || isset($indexed[$mode])) { throw new RuntimeException('Duplicate/invalid index mode'); }
        $indexed[$mode] = $item;
    }
    foreach (['source', 'dist'] as $mode) {
        $name = 'cajeerengine-' . $version . '-' . $mode . '.zip';
        $zip = $dir . '/' . $name;
        if (!is_file($zip) || is_link($zip) || !is_file($zip . '.sha256') || is_link($zip . '.sha256') || !is_file($zip . '.sig') || is_link($zip . '.sig')) {
            throw new RuntimeException('Missing/untrusted artifact, signature or checksum: ' . $name);
        }
        $manifest = (new \CajeerEngine\Release\ReleaseArtifactVerifier())->verify($zip, $mode, $version);
        if (($manifest['edition'] ?? null) !== 'forum' || ($manifest['upstream_version'] ?? null) !== trim((string)file_get_contents($root . '/VERSION'))) {
            throw new RuntimeException('Wrong edition/upstream version: ' . $name);
        }
        if (!in_array('composer.lock', $manifest['files'] ?? [], true)) { throw new RuntimeException('Missing lock file: ' . $name); }
        $sha = hash_file('sha256', $zip);
        if ($sha === false || trim((string)file_get_contents($zip . '.sha256')) !== $sha . '  ' . $name) {
            throw new RuntimeException('SHA256 mismatch: ' . $name);
        }
        $sidecar = json_decode((string)file_get_contents($dir . '/cajeerengine-' . $version . '-' . $mode . '.manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        if ($sidecar !== $manifest + ['artifact_sha256' => $sha]) { throw new RuntimeException('Manifest mismatch: ' . $name); }
        $entry = $indexed[$mode] ?? null;
        if (!is_array($entry) || ($entry['name'] ?? null) !== $name || ($entry['sha256'] ?? null) !== $sha || ($entry['size'] ?? null) !== filesize($zip)) {
            throw new RuntimeException('Artifact index mismatch: ' . $name);
        }
        \CajeerEngine\Update\ReleaseSignature::verify($zip, $zip . '.sig', $key, $sha);
    }
    echo "PASS exact Forum Edition $version source/dist manifests, SHA256 and Ed25519 signatures\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Release signature verification failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
