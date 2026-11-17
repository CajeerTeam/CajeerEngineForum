#!/bin/sh
set -eu
cd /srv/forum
case "${1:-}" in
  worker) set -- queue:work --queue=default --limit=10; delay=5;;
  scheduler) set -- scheduler:run; delay=30;;
  *) exit 2;;
esac
# Bound a complete batch, including a blocked external dependency. The per-job
# lease still governs recovery; terminating a batch never acknowledges its jobs.
timeout_seconds=${CE_LOOP_TIMEOUT_SECONDS:-600}
case "$timeout_seconds" in ''|*[!0-9]*) exit 2;; esac
[ "$timeout_seconds" -ge 30 ] && [ "$timeout_seconds" -le 3600 ] || exit 2
child=''
retry_delay=$delay
rm -f /tmp/ce-loop-health
stop() {
    if [ -n "$child" ]; then kill -TERM "$child" 2>/dev/null || :; wait "$child" || :; fi
    exit 0
}
trap stop TERM INT
while :; do
    timeout --signal=TERM --kill-after=15s "${timeout_seconds}s" php bin/cajeer "$@" >/tmp/ce-loop-result.json & child=$!
    if wait "$child"; then
        touch /tmp/ce-loop-health
        retry_delay=$delay
    else
        # Keep the timestamp of the last successful batch. A transient failed
        # attempt must not immediately invalidate a recently healthy worker,
        # while runtime.php still fails once its bounded success age expires.
        # Do not refresh the timestamp on failure: repeated failures cannot
        # keep an unhealthy loop alive indefinitely.
        retry_delay=$((retry_delay * 2))
        [ "$retry_delay" -le 60 ] || retry_delay=60
        # The CLI records detailed errors in application logs. Do not expose payloads on stdout.
        echo 'Background batch failed; retrying after delay' >&2
    fi
    child=''
    sleep "$retry_delay" & child=$!; wait "$child"; child=''
done
