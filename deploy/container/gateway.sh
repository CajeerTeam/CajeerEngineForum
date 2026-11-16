#!/bin/sh
set -eu
case "${CE_DOMAIN:-}" in ''|*[!a-z0-9.-]*) echo 'Invalid CE_DOMAIN' >&2; exit 1;; esac
case "${CE_HTTPS_PORT:-443}" in ''|*[!0-9]*) exit 1;; esac
CE_HTTPS_PORT=${CE_HTTPS_PORT:-443}; export CE_HTTPS_PORT
openssl x509 -in /run/secrets/tls_certificate -noout -checkend 86400 >/dev/null
openssl x509 -in /run/secrets/tls_certificate -noout -checkhost "$CE_DOMAIN" | grep -q 'does match certificate'
envsubst '$CE_DOMAIN $CE_HTTPS_PORT' < /opt/forum/nginx.conf.template > /tmp/forum-nginx.conf
exec nginx -c /tmp/forum-nginx.conf -g 'daemon off;'
