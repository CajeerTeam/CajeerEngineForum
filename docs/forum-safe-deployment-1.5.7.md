# Forum 1.5.7 — Safe Deployment & Service Lifecycle

Autonomous host updates now require a systemd service policy. Installation fails closed if the policy is missing or invalid; manual CLI and the external Compose updater retain their existing paths. Configure one dedicated single-node installation per FPM service, not a shared multi-site FPM instance. The update operator must remain outside the units it controls. Do not add the operator to `sudo` or the Docker group.

1. Provision `/etc/cajeerengine/update-services.json` as root (0644, root-owned non-writable ancestors). Use `examples/systemd/update-service-policy.example.json`, adjust the PHP-FPM unit and list **every** worker unit for this installation. The scheduler service/timer names are fixed. Run `systemctl list-units` to confirm. All named units and the timer must already be running, except the scheduler oneshot which may be inactive.
2. Review and install `examples/systemd/49-cajeerengine-updater.rules` in `/etc/polkit-1/rules.d/` as root (0644). Its exact unit allowlist must match the policy. Authorize only start/stop for the dedicated updater user. Unit definitions, drop-ins, and any privileged ExecStart/ExecStop commands must be root-owned, not editable by the updater. If using generated worker units, regenerate/install them as root with `Environment=CE_UPDATE_WAIT_FOR_DRAIN=1`, reload systemd and restart them. This makes newly activated workers wait for the shared activity lease rather than repeatedly failing while deployment holds it.
3. Configure a loopback-only HTTP listener on the selected port, routed to **the same PHP-FPM pool and application** as public ingress, including `/health/live` and `/health/ready`. It must return the application JSON, without redirects, cached responses or a static health substitute. Set `probe_host` to the listener's virtual host. Bind only to 127.0.0.1. In the **public** virtual host install `if (-f /var/www/cajeerengine/storage/app/maintenance.json) { return 503; }` with `open_file_cache off;` (adjust path). Nginx must have search permission on the parent directories; never expose the storage directory. This gate is essential because PHP-FPM is stopped during deployment. It also closes ACP during manual maintenance, so use the CLI to reopen. The generated host configuration uses its dedicated `cajeerengine-php.service` and loopback port 18085; adjust the policy and Polkit rule accordingly. The root-owned host deployer remains an alternative manual path, not an automatically provisioned updater account. Example Nginx server (adjust document root and socket):

```nginx
server {
    listen 127.0.0.1:8081;
    server_name localhost;
    root /var/www/cajeerengine/public;
    location ~ ^/health/(live|ready)$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }
    location / { return 404; }
}
```

4. Set `UPDATE_SERVICE_POLICY_FILE=/etc/cajeerengine/update-services.json` in `.env`; keep the existing signed-update, backup and policy settings. Enable the existing non-root operator timer. Check the policy under the operator's service account before enabling automatic apply. Do not enable the host systemd path inside containers.

## Transaction behavior

The exclusive operations lease first proves no application request, scheduler invocation or worker batch is active. Busy autonomous operations are deferred. The old release passes read-only checks and the protected HTTP probe. The operator writes the service identity and maintenance journal before stopping the scheduler timer, scheduler, all configured workers, and PHP-FPM in order. The normal backup, rehearsal, signature and migration gates remain mandatory. After mutation, a fresh CLI verifies the schema and application; PHP-FPM is started and actual loopback liveness/edition and protected readiness are checked. Public readiness must remain 503 during the exclusive operation. Worker and timer startup follow; maintenance is removed only after successful service activation and the durable transaction commit. The ordinary post-release readiness check is `curl --fail http://127.0.0.1:8081/health/ready` (add the configured Host header when needed).

On failure, services are stopped again before any restore. The old backup is restored only after the recorded mutation boundary, and successful restoration is recorded before restarting old services. A failed service activation/recovery leaves the active marker and maintenance in place. Keep the original service policy unchanged during recovery. Run `php bin/cajeer update:rollback` as the operator; if installed files are damaged, follow the existing authenticated `recover.php` instructions. Do not manually remove active or maintenance markers. For a crash before backup creation there may be no recovery launcher; the old CLI still exists. Rollback from 1.5.7 to an older release uses the original older health endpoint (which lacks edition metadata) plus fresh FPM and CLI checks.

Compose: the existing external signed deployment tool stops gateway, then background writers, then the app; it starts the app, workers and scheduler before opening ingress and performs its existing deep verification. The PHP systemd operator is not a Docker API controller.

No database migration. Local regression covers unit ordering and failure injection using a simulated systemd adapter, the real liveness endpoint and cross-process flock. Real systemd/Polkit/FPM, SQL, GitHub and Compose deployment require independent staging qualification before enabling autonomous updates.
