#!/bin/sh
# Executed by the official postgres entrypoint only for a new cluster.
set -eu
password=$(cat /tmp/ce-db-password)
case "$password" in *[!A-Za-z0-9_-]*|'') echo 'Invalid database password' >&2; exit 1;; esac
[ "${#password}" -ge 24 ] && [ "${#password}" -le 128 ]
# Do not pass password through argv or echo SQL to logs.
printf "CREATE ROLE cajeerengine LOGIN CREATEDB NOSUPERUSER NOCREATEROLE PASSWORD '%s';\nCREATE DATABASE cajeerengine OWNER cajeerengine;\n" "$password" | psql -X -q -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres
