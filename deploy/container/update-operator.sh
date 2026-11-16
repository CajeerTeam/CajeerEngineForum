#!/bin/sh
# Executed only in a one-shot old-image init container, with the gateway and writers stopped.
set -eu
umask 077
cd /srv/forum
mode=${1:?mode}; target=${2:?target}; digest=${3:?digest}
case "$mode" in verify|apply|recover|open) ;; *) exit 2;; esac
exec 9>/run/ce-keys/bootstrap.lock
flock -x 9
# The host mounts a verified candidate and this operator tool read-only. The
# original publisher key is embedded in the old image, not taken from the candidate.
if [ "$mode" = verify ] || [ "$mode" = apply ]; then
    cmp -s /opt/ce-release.pub /opt/ce-update/release.pub || { echo "Publisher key rotation requires a separate operator procedure" >&2; exit 1; }
    setpriv --reuid=33 --regid=33 --clear-groups php /opt/ce-update/operator.php verify "$target" "$digest"
fi
if [ "$mode" = verify ]; then exit 0; fi
if [ "$mode" = apply ]; then
    if [ -e /run/ce-keys/release.pub ]; then
        cmp -s /opt/ce-release.pub /run/ce-keys/release.pub || { echo 'Installed publisher key differs' >&2; exit 1; }
    else
        install -m 0644 /opt/ce-release.pub /run/ce-keys/release.pub
    fi
fi
# 0.8.2 seeded code as root. Grant the existing runtime UID write access ONLY
# in this stopped, writable operator container. All long-lived mounts remain RO.
# Separate runtime volumes are explicitly excluded; do not traverse or chown them.
for path in /srv/forum/* /srv/forum/.[!.]* /srv/forum/..?*; do
    [ -e "$path" ] || continue
    case "$path" in /srv/forum/storage|/srv/forum/public|/srv/forum/bootstrap) continue;; esac
    find "$path" -xdev -exec chown -h 33:33 {} +
done
for path in /srv/forum/public/* /srv/forum/public/.[!.]* /srv/forum/bootstrap/* /srv/forum/bootstrap/.[!.]*; do
    [ -e "$path" ] || continue
    case "$path" in /srv/forum/public/uploads|/srv/forum/public/extensions|/srv/forum/bootstrap/cache) continue;; esac
    find "$path" -xdev -exec chown -h 33:33 {} +
done
chown 33:33 /srv/forum /srv/forum/public /srv/forum/bootstrap
export BACKUP_KEY_FILE=/run/ce-keys/backup.key
exec setpriv --reuid=33 --regid=33 --clear-groups php /opt/ce-update/operator.php "$mode" "$target" "$digest"
