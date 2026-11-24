<?php

declare(strict_types=1);

use CajeerEngine\Update\GitHubEndpoints;

return [
    'core' => [
        'channel' => getenv('UPDATE_CHANNEL') ?: 'stable',
        'sequential' => (filter_var(getenv('UPDATE_SEQUENTIAL') !== false ? getenv('UPDATE_SEQUENTIAL') : 'true', FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true),
        'releases_url' => GitHubEndpoints::RELEASES_URL,
        'timeout' => (int) (getenv('UPDATE_HTTP_TIMEOUT') ?: 5),
        'auto_check_enabled' => filter_var(getenv('UPDATE_AUTO_CHECK_ENABLED') !== false ? getenv('UPDATE_AUTO_CHECK_ENABLED') : 'true', FILTER_VALIDATE_BOOLEAN),
        'auto_check_interval_seconds' => (int) (getenv('UPDATE_AUTO_CHECK_INTERVAL_SECONDS') ?: 21600),
        'auto_stage_enabled' => filter_var(getenv('UPDATE_AUTO_STAGE_ENABLED') ?: false, FILTER_VALIDATE_BOOLEAN),
        'auto_stage_interval_seconds' => (int) (getenv('UPDATE_AUTO_STAGE_INTERVAL_SECONDS') ?: 3600),
        'download_timeout_seconds' => (int) (getenv('UPDATE_DOWNLOAD_TIMEOUT_SECONDS') ?: 300),
        'download_max_bytes' => (int) (getenv('UPDATE_DOWNLOAD_MAX_BYTES') ?: 2147483648),
    ],
    'policy' => [
        'mode' => getenv('UPDATE_POLICY_MODE') === false ? 'legacy' : getenv('UPDATE_POLICY_MODE'),
        'timezone' => getenv('UPDATE_MAINTENANCE_TIMEZONE') === false ? 'UTC' : getenv('UPDATE_MAINTENANCE_TIMEZONE'),
        'days' => getenv('UPDATE_MAINTENANCE_DAYS') === false ? '1,2,3,4,5,6,7' : getenv('UPDATE_MAINTENANCE_DAYS'),
        'window_start' => getenv('UPDATE_MAINTENANCE_START') === false ? '' : getenv('UPDATE_MAINTENANCE_START'),
        'window_end' => getenv('UPDATE_MAINTENANCE_END') === false ? '' : getenv('UPDATE_MAINTENANCE_END'),
        'defer_until' => getenv('UPDATE_DEFER_UNTIL') === false ? '' : getenv('UPDATE_DEFER_UNTIL'),
        'blocked_versions' => getenv('UPDATE_BLOCKED_VERSIONS') === false ? '' : getenv('UPDATE_BLOCKED_VERSIONS'),
    ],
    'operator' => [
        'enabled' => filter_var(getenv('UPDATE_OPERATOR_ENABLED') ?: false, FILTER_VALIDATE_BOOLEAN),
        'max_heartbeat_age_seconds' => (int) (getenv('UPDATE_OPERATOR_MAX_HEARTBEAT_AGE_SECONDS') ?: 180),
    ],
    'registry' => [
        'url' => GitHubEndpoints::REGISTRY_URL,
        'signature_required' => true,
    ],
    'web_upgrade' => [
        'enabled' => filter_var(getenv('UPDATE_WEB_UPGRADE_ENABLED') ?: false, FILTER_VALIDATE_BOOLEAN),
        'operator_required' => true,
    ],
];
