#!/bin/sh
set -eu
cd /srv/forum
case "${1:-}" in
  worker) set -- queue:work --queue=default --limit=10; delay=5;;
  scheduler) set -- scheduler:run; delay=30;;
  *) exit 2;;
esac
child=''
stop() {
    if [ -n "$child" ]; then kill -TERM "$child" 2>/dev/null || :; wait "$child" || :; fi
    exit 0
}
trap stop TERM INT
while :; do
    php bin/cajeer "$@" >/tmp/ce-loop-result.json & child=$!
    if wait "$child"; then
        touch /tmp/ce-loop-health
    else
        # The CLI records detailed errors in application logs. Do not expose payloads on stdout.
        echo 'Background batch failed; retrying after delay' >&2
    fi
    child=''
    sleep "$delay" & child=$!; wait "$child"; child=''
done
