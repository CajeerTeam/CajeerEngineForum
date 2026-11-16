#!/bin/sh
set -eu
umask 077
# The official entrypoint subsequently drops to postgres. Copy bind-mounted
# secrets without weakening their host permissions.
install -m 0600 -o postgres -g postgres /run/secrets/postgres_password /tmp/ce-postgres-password
install -m 0600 -o postgres -g postgres /run/secrets/db_password /tmp/ce-db-password
export POSTGRES_PASSWORD_FILE=/tmp/ce-postgres-password
exec docker-entrypoint.sh postgres
