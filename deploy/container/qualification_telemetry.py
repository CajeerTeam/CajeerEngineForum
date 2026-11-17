#!/usr/bin/env python3
"""Live cgroup-v2 and PostgreSQL observations for destructive Forum qualification.

Every observation is obtained from running containers, never from a generated fixture.
Counters are cumulative and meaningful as deltas; no user IDs or content are recorded.
"""
import json
import re

SERVICES = ('app', 'worker', 'scheduler', 'postgres', 'redis')
IO_KEYS = ('rbytes', 'wbytes', 'rios', 'wios')

# All statements are read-only. The five subqueries capture real database work,
# lock contention, I/O, WAL and the oldest *ready* durable queue item.
PG_SQL = """
SELECT json_build_object(
  'statistics', (SELECT row_to_json(x) FROM (
    SELECT numbackends, xact_commit, xact_rollback, blks_read, blks_hit,
           tup_returned, tup_fetched, tup_inserted, tup_updated, tup_deleted,
           temp_bytes, deadlocks
      FROM pg_stat_database WHERE datname = current_database()
  ) x),
  'lock_waiters', (SELECT count(*) FROM pg_stat_activity
                   WHERE datname=current_database() AND wait_event_type='Lock'),
  'active_sessions', (SELECT count(*) FROM pg_stat_activity
                      WHERE datname=current_database() AND state='active'),
  'database_bytes', pg_database_size(current_database()),
  'wal_bytes', (SELECT wal_bytes::text FROM pg_stat_wal),
  'durable_queue', (SELECT json_build_object(
       'pending', count(*) FILTER (WHERE status='pending'),
       'reserved', count(*) FILTER (WHERE status='reserved'),
       'oldest_ready_age_seconds', COALESCE(max(EXTRACT(EPOCH FROM (clock_timestamp()-available_at)))
                    FILTER (WHERE status='pending' AND available_at<=clock_timestamp()), 0))
       FROM ce_jobs),
  'notifications', (SELECT json_build_object(
       'pending', count(*) FILTER (WHERE state=0),
       'oldest_age_seconds', COALESCE(max(EXTRACT(EPOCH FROM (clock_timestamp()-created_at)))
                            FILTER (WHERE state=0), 0)) FROM ce_forum_notifications)
)::text
"""


def parse_cpu(raw):
    values = {}
    for line in raw.splitlines():
        fields = line.split()
        if len(fields) == 2 and fields[1].isdigit():
            values[fields[0]] = int(fields[1])
    if 'usage_usec' not in values:
        raise RuntimeError('cgroup-v2 cpu.stat missing usage_usec')
    return {key: values[key] for key in ('usage_usec', 'user_usec', 'system_usec', 'nr_throttled', 'throttled_usec') if key in values}


def parse_io(raw):
    # Sum all block devices and require counters, not absent/zero placeholders.
    totals = {key: 0 for key in IO_KEYS}
    devices = 0
    for line in raw.splitlines():
        fields = line.split()
        if not fields or not re.fullmatch(r'\d+:\d+', fields[0]):
            continue
        found = dict(part.split('=', 1) for part in fields[1:] if part.count('=') == 1)
        if not all(key in found and found[key].isdigit() for key in IO_KEYS):
            continue
        devices += 1
        for key in IO_KEYS:
            totals[key] += int(found[key])
    if devices == 0 and raw.strip():
        raise RuntimeError('cgroup-v2 io.stat has invalid device counters')
    return totals


def sample(compose):
    services = {}
    for service in SERVICES:
        # Three separate reads keep parsing independent from shell output and
        # guarantee missing cgroup-v2 controllers fail the measurement.
        memory = compose.run('exec', '-T', service, 'cat', '/sys/fs/cgroup/memory.current', timeout=15).strip()
        if not memory.isdigit():
            raise RuntimeError('cgroup-v2 memory.current unavailable: ' + service)
        cpu = parse_cpu(compose.run('exec', '-T', service, 'cat', '/sys/fs/cgroup/cpu.stat', timeout=15))
        io = parse_io(compose.run('exec', '-T', service, 'cat', '/sys/fs/cgroup/io.stat', timeout=15))
        services[service] = {'memory_bytes': int(memory), 'cpu': cpu, 'io': io}
    raw = compose.sql("SET statement_timeout = '5000ms'; " + PG_SQL, timeout=20)
    pg = json.loads(raw)
    if not isinstance(pg, dict) or not isinstance(pg.get('statistics'), dict):
        raise RuntimeError('PostgreSQL statistics missing')
    if not isinstance(pg.get('durable_queue'), dict) or not isinstance(pg.get('notifications'), dict):
        raise RuntimeError('PostgreSQL queue telemetry missing')
    return {'services': services, 'postgres': pg}


def summarize(first, last, peak):
    """Derive measured deltas and peaks from actual cgroup/PG counters."""
    result = {'services': {}, 'postgres': {}}
    for name in SERVICES:
        a, b = first['services'][name], last['services'][name]
        max_mem = max(row['services'][name]['memory_bytes'] for row in peak)
        result['services'][name] = {
            'memory_peak_bytes': max_mem,
            'memory_growth_bytes': max_mem - a['memory_bytes'],
            'cpu_usage_seconds': round((b['cpu']['usage_usec']-a['cpu']['usage_usec']) / 1e6, 3),
            'io': {key+'_delta': b['io'][key] - a['io'][key] for key in IO_KEYS},
        }
        if result['services'][name]['cpu_usage_seconds'] < 0 or any(n < 0 for n in result['services'][name]['io'].values()):
            raise RuntimeError('cgroup counters reset during qualification: ' + name)
    a, b = first['postgres'], last['postgres']
    for key in ('xact_commit', 'xact_rollback', 'blks_read', 'blks_hit', 'temp_bytes', 'deadlocks'):
        change = int(b['statistics'][key]) - int(a['statistics'][key])
        if change < 0:
            raise RuntimeError('PostgreSQL statistics reset during qualification: ' + key)
        result['postgres'][key+'_delta'] = change
    result['postgres']['database_bytes_end'] = int(b['database_bytes'])
    result['postgres']['wal_bytes_delta'] = int(b['wal_bytes'])-int(a['wal_bytes'])
    if result['postgres']['wal_bytes_delta'] < 0:
        raise RuntimeError('PostgreSQL WAL statistics reset during qualification')
    result['postgres']['peak_lock_waiters'] = max(int(row['postgres']['lock_waiters']) for row in peak)
    result['postgres']['max_durable_queue_age_seconds'] = max(float(row['postgres']['durable_queue']['oldest_ready_age_seconds']) for row in peak)
    result['postgres']['max_notification_age_seconds'] = max(float(row['postgres']['notifications']['oldest_age_seconds']) for row in peak)
    return result

class ResourceMonitor:
    """Asynchronous sampler: expensive Docker/SQL probes cannot distort HTTP pacing."""
    def __init__(self, compose, interval_seconds=30):
        import threading
        self.compose = compose
        self.interval = interval_seconds
        self.started = __import__('time').monotonic()
        self.samples = []
        self._stop = threading.Event()
        self._thread = None
        self.error = None

    def _capture(self):
        import time
        row = sample(self.compose)
        row['elapsed_seconds'] = round(time.monotonic()-self.started, 3)
        self.samples.append(row)

    def start(self):
        import threading
        self._capture()  # fail before beginning expensive traffic
        def worker():
            import time
            next_sample = time.monotonic() + self.interval
            while not self._stop.wait(max(0, next_sample - time.monotonic())):
                try:
                    self._capture()
                except Exception as exc:
                    self.error = type(exc).__name__ + ': ' + str(exc)
                    return
                next_sample += self.interval
                # Do not let slow Docker commands accumulate an arbitrary
                # delayed schedule. A large gap still fails certification.
                if next_sample < time.monotonic():
                    next_sample = time.monotonic() + self.interval
        self._thread = threading.Thread(target=worker, daemon=True, name='qualification-resources')
        self._thread.start()
        return self

    def finish(self):
        self._stop.set()
        if self._thread:
            self._thread.join(timeout=30)
            if self._thread.is_alive():
                raise RuntimeError('resource sampler did not stop')
        if self.error:
            raise RuntimeError('resource sampling failed: '+self.error)
        self._capture()
        if len(self.samples) < 2:
            raise RuntimeError('resource samples missing')
        for first, second in zip(self.samples, self.samples[1:]):
            if second['elapsed_seconds']-first['elapsed_seconds'] > self.interval*3 + 15:
                raise RuntimeError('resource telemetry has unobserved gap')
        return summarize(self.samples[0], self.samples[-1], self.samples)


class QueueLatencyMonitor:
    """Measure acknowledgement-to-delivery latency on real watched publications.

    Each poll requests the latest 25 notification items (bounded) and matches
    only synthetic post IDs. Polling uses a separate thread and HTTPS client.
    """
    def __init__(self, client, headers, histogram, interval_seconds=1):
        import threading
        self.client = client
        self.headers = headers
        self.histogram = histogram
        self.interval = interval_seconds
        self.pending = {}
        self.errors = 0
        self._mutex = threading.Lock()
        self._stop = threading.Event()
        self._thread = None
        self._failure = None

    def track(self, post_id):
        import time
        with self._mutex:
            if post_id in self.pending:
                raise RuntimeError('duplicate queue observation')
            self.pending[post_id] = time.monotonic()

    def poll(self):
        import time
        status, _, payload = self.client.request('/api/v1/forum/notifications', headers=self.headers)
        if status != 200:
            raise RuntimeError('notification HTTP '+str(status))
        rows = json.loads(payload)['data']['items']
        if not isinstance(rows, list):
            raise RuntimeError('invalid notification data')
        ids = {row.get('post_id') for row in rows}
        now = time.monotonic()
        with self._mutex:
            for post_id, started in tuple(self.pending.items()):
                if post_id in ids:
                    self.histogram.add((now-started)*1000)
                    del self.pending[post_id]
            if self.pending and now-min(self.pending.values())>120:
                raise RuntimeError('notification delivery exceeded 120 seconds')

    def start(self):
        import threading
        def worker():
            while not self._stop.wait(self.interval):
                try:
                    self.poll()
                    self.errors = 0
                except Exception as exc:
                    self.errors += 1
                    if self.errors >= 5:
                        self._failure = type(exc).__name__+': '+str(exc)
                        return
        self._thread = threading.Thread(target=worker, daemon=True, name='qualification-notifications')
        self._thread.start()
        return self

    def finish(self, max_wait=45):
        import time
        end = time.monotonic()+max_wait
        while time.monotonic()<end:
            if self._failure:
                break
            with self._mutex:
                if not self.pending:
                    break
            time.sleep(min(1, self.interval))
        self._stop.set()
        if self._thread:
            self._thread.join(timeout=15)
            if self._thread.is_alive():
                raise RuntimeError('queue latency sampler did not stop')
        if self._failure:
            raise RuntimeError('queue latency probe failed: '+self._failure)
        if self.pending:
            raise RuntimeError('acknowledged notification not delivered within queue latency budget')
        if not self.histogram.total:
            raise RuntimeError('no queue delivery latency observations')
        return self.histogram.report()
