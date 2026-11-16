#!/bin/sh
set -eu
umask 077
cd /srv/forum
# Only this one-shot service can write the release volume. All web/worker mounts are read-only.
exec 9>/run/ce-keys/bootstrap.lock
flock -x 9
if [ ! -f release-manifest.json ]; then
    if [ -e .env ] || [ -e storage/app/installed.lock ]; then
        echo 'Refusing to seed code over existing installation' >&2; exit 1
    fi
    cp -a /opt/ce-image/. /srv/forum/
    chown -R 33:33 storage bootstrap/cache public/uploads public/extensions
fi
# Keep the bootstrap lock for the whole process, including the installer.
php /opt/ce-container/runtime.php identity
chown 33:33 /srv/forum
chmod 0755 /srv/forum
for d in storage bootstrap/cache public/uploads public/extensions modules plugins themes; do
    mkdir -p "$d"
    chown 33:33 "$d"
done
# Named volumes are initially empty; only create the required directories, never recursively rewrite existing data.
for d in storage/app storage/cache storage/database storage/logs storage/tmp; do
    mkdir -p "$d"; chown 33:33 "$d"
done
install -m 0600 -o 33 -g 33 /run/secrets/backup_key /tmp/ce-backup.key
if [ -e /run/ce-keys/backup.key ]; then
    cmp -s /tmp/ce-backup.key /run/ce-keys/backup.key || { echo 'Backup key differs; explicit key rotation required' >&2; exit 1; }
else
    install -m 0600 -o 33 -g 33 /tmp/ce-backup.key /run/ce-keys/backup.key
fi
rm /tmp/ce-backup.key
for secret in db_password redis_password admin_password; do
    install -m 0600 -o 33 -g 33 "/run/secrets/$secret" "/tmp/ce-$secret"
done
trap 'rm -f /tmp/ce-db_password /tmp/ce-redis_password /tmp/ce-admin_password' EXIT
setpriv --reuid=33 --regid=33 --clear-groups php /opt/ce-container/bootstrap.php
