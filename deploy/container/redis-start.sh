#!/bin/sh
set -eu
umask 077
password=$(cat /run/secrets/redis_password)
case "$password" in *[!A-Za-z0-9_-]*|'') exit 1;; esac
[ "${#password}" -ge 24 ] && [ "${#password}" -le 128 ]
# No secret in docker inspect or redis-server argv. No eviction of rate limits or queued jobs.
printf 'bind 0.0.0.0\nprotected-mode yes\nport 6379\nrequirepass %s\ndir /data\nappendonly yes\nappendfsync everysec\nmaxmemory 256mb\nmaxmemory-policy noeviction\n' "$password" > /tmp/forum-redis.conf
chown redis:redis /tmp/forum-redis.conf
exec docker-entrypoint.sh redis-server /tmp/forum-redis.conf
