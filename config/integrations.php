<?php

declare(strict_types=1);

// All settings below are live server-side policy, never client-supplied metadata.
$names = static function (string $env): array {
    $raw = trim((string) (getenv($env) ?: ''));
    if ($raw === '') return [];
    $values = array_map(static fn (string $v): string => strtolower(trim($v)), explode(',', $raw));
    foreach ($values as $value) {
        if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?)$/D', $value)
            || str_contains($value, '..') || str_contains($value, '*')) {
            throw new \RuntimeException('Invalid ' . $env . ' entry');
        }
    }
    return array_values(array_unique($values));
};
$bounded = static function (string $env, int $default, int $min, int $max): int {
    $value = getenv($env);
    if ($value === false || $value === '') return $default;
    if (!preg_match('/^[0-9]{1,9}$/D', $value) || (int) $value < $min || (int) $value > $max) {
        throw new \RuntimeException('Invalid ' . $env . ' limit');
    }
    return (int) $value;
};

return [
    'enabled' => filter_var(getenv('INTEGRATIONS_ENABLED') === false ? 'true' : getenv('INTEGRATIONS_ENABLED'), FILTER_VALIDATE_BOOLEAN),
    'allowed_hosts' => $names('INTEGRATIONS_ALLOWED_HOSTS'),
    // Only registered providers can run. Empty = no extra restriction.
    'allowed_providers' => $names('INTEGRATIONS_ALLOWED_PROVIDERS'),
    'max_payload_bytes' => $bounded('INTEGRATIONS_MAX_PAYLOAD_BYTES', 65536, 1024, 1048576),
    'max_connections' => $bounded('INTEGRATIONS_MAX_CONNECTIONS', 200, 1, 5000),
];
