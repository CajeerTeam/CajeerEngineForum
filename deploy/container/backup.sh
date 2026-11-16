#!/bin/sh
set -eu
umask 077
cd /srv/forum
php /opt/ce-container/runtime.php identity
exec php bin/cajeer backup:automation:run --policy=/run/ce-backup/policy.json "$@"
