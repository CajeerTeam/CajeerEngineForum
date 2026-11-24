#!/usr/bin/env python3
"""Destructive fault injections for the existing disposable Compose qualification.

Imported by qualify.py only after signed-release identity and forum fixtures have
been verified. No Docker socket or privileged fault controls enter application
containers. Every fault is bounded and recovered in a finally block.
"""
import json
import re
import subprocess
import time
import uuid


def checked(condition, message):
    if not condition:
        raise RuntimeError(message)


def docker(*args, timeout=90):
    return subprocess.run(['docker', *args], check=True, text=True,
                          stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                          timeout=timeout).stdout.strip()


def backend_identity(dc, project):
    """Never disconnect arbitrary containers or another project's network."""
    checked(bool(re.fullmatch(r'[a-z0-9_-]*(?:load|qualification|benchmark|chaos)[a-z0-9_-]*', project)),
            'Destructive chaos requires a named disposable project')
    cid = dc.run('ps', '-q', 'app').strip()
    checked(bool(re.fullmatch(r'[a-f0-9]{64}', cid)), 'Unique running app container required')
    info = json.loads(docker('inspect', cid))[0]
    labels = info['Config']['Labels']
    checked(labels.get('com.docker.compose.project') == project and
            labels.get('com.docker.compose.service') == 'app', 'Container does not belong to selected project')
    networks = info['NetworkSettings']['Networks']
    found = []
    for network in networks:
        info_net = json.loads(docker('network', 'inspect', network))[0]
        labels = info_net.get('Labels') or {}
        if labels.get('com.docker.compose.project') == project and labels.get('com.docker.compose.network') == 'backend':
            checked(info_net.get('Internal') is True, 'Backend is not internal')
            found.append(network)
    checked(len(found) == 1, 'Expected exactly one Compose backend network')
    return cid, found[0]


def fail_closed(client, publish_path, credentials, marker, *, expect_unready=True):
    """Outages cannot acknowledge a write; success is never guessed from a timeout."""
    if expect_unready:
        end = time.monotonic() + 45
        while time.monotonic() < end:
            try:
                if client.request('/health/ready')[0] == 503:
                    break
            except (OSError, TimeoutError):
                pass
            time.sleep(.5)
        else:
            raise RuntimeError('Readiness never returned 503 during injected fault')
    try:
        status, _, _ = client.request(publish_path, method='POST', headers=credentials,
                                      data={'body': marker})
        checked(status >= 500, 'Publication was admitted during fault (HTTP '+str(status)+')')
    except (OSError, TimeoutError):
        # Unknown commit result is *not* a success: caller later verifies marker absent.
        pass


def temporary_disk_full(dc, client, path, member, marker, check_ready):
    """Fill only app /tmp's explicitly bounded 512MiB tmpfs, never host/PG disks."""
    fs = dc.run('exec', '-T', 'app', 'stat', '-f', '-c', '%T', '/tmp').strip()
    checked(fs == 'tmpfs', 'Refusing to fill anything except app /tmp tmpfs')
    raw = dc.run('exec', '-T', 'app', 'df', '-Pk', '/tmp').strip().splitlines()[-1].split()
    checked(len(raw) >= 4 and raw[3].isdigit(), 'Cannot measure isolated tmpfs free space')
    available = int(raw[3]); checked(32768 <= available <= 786432, 'Unexpected tmpfs capacity')
    blocks = (available - 2048) // 1024
    checked(blocks > 16, 'Insufficient safe tmpfs budget')
    filename = '/tmp/ce-chaos-' + uuid.uuid4().hex
    try:
        dc.run('exec', '-T', 'app', 'dd', 'if=/dev/zero', 'of='+filename,
               'bs=1048576', 'count='+str(blocks), 'status=none', timeout=120)
        # The remaining 2-3MiB should trip the 16MiB admission threshold.
        fail_closed(client, path, member, marker)
        check_ready('disk_full')
    finally:
        dc.run('exec', '-T', 'app', 'rm', '-f', filename)


def network_partition(dc, project, client, path, member, marker, wait_ready, check_ready):
    cid, network = backend_identity(dc, project)
    detached = False
    try:
        docker('network', 'disconnect', '--force', network, cid)
        detached = True
        fail_closed(client, path, member, marker)
        check_ready('network_partition')
    finally:
        if detached:
            # Reattach before recreating: cleanup is attempted even after assertion
            # failures, and Compose restores its service aliases on recreation.
            try:
                docker('network', 'connect', '--alias', 'app', network, cid)
            finally:
                dc.run('up', '-d', '--no-deps', '--force-recreate', 'app', timeout=180)
                wait_ready()


def interrupted_migration_transaction(dc, wait_ready):
    """Kill a PostgreSQL migration *client* inside transactional DDL, confirm rollback.

    Creates an otherwise unused, unique test table in the disposable database,
    never changes live schema/migration bookkeeping. This tests PG rollback on a
    lost session, not the full application's migration runner.
    """
    suffix = uuid.uuid4().hex[:20]
    name = 'ce_chaos_abort_' + suffix
    app = 'ce_chaos_migration_' + suffix
    sql = f'BEGIN; CREATE TABLE public.{name}(n bigint NOT NULL); INSERT INTO public.{name} VALUES (42); SELECT pg_sleep(60); COMMIT;\n'
    cmd = dc.prefix + ['exec', '-T', 'postgres', 'sh', '-c',
                       'PGAPPNAME='+app+' PGPASSWORD="$(cat /tmp/ce-db-password)" exec psql -X -qAt -v ON_ERROR_STOP=1 -h 127.0.0.1 -U cajeerengine -d cajeerengine']
    process = subprocess.Popen(cmd, stdin=subprocess.PIPE, stdout=subprocess.DEVNULL,
                               stderr=subprocess.DEVNULL, text=True)
    try:
        checked(process.stdin is not None, 'Missing migration input')
        process.stdin.write(sql); process.stdin.close()
        end = time.monotonic() + 20
        while time.monotonic() < end:
            count = int(dc.sql("SELECT count(*) FROM pg_stat_activity WHERE application_name='"+app+"' AND query LIKE '%pg_sleep(60)%' AND state='active'"))
            if count == 1:
                break
            checked(process.poll() is None, 'Migration client exited before injection')
            time.sleep(.2)
        else:
            raise RuntimeError('Transactional migration was never observed running')
        process.kill(); process.wait(timeout=10)
        # Closing the psql connection aborts the transaction; terminate the
        # server session too if it is still sleeping, without touching others.
        dc.sql("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE application_name='"+app+"' AND pid<>pg_backend_pid()")
        end = time.monotonic() + 20
        while time.monotonic() < end:
            if dc.sql("SELECT to_regclass('public."+name+"') IS NULL").strip() == 't':
                wait_ready()
                return
            time.sleep(.2)
        raise RuntimeError('Interrupted transaction left schema changes behind')
    finally:
        if process.poll() is None:
            process.kill(); process.wait(timeout=10)
        # Never drop a table as a way to hide a failed rollback test.


def run_chaos(dc, client, *, project, path, member, marker_prefix, wait_ready, verify_posts, report, save):
    """Real fault sequence; preserve *all* acknowledged writes across every fault."""
    def pass_check(name, begin):
        verify_posts()
        checked(client.request('/health/ready')[0] == 200, 'Readiness not restored: '+name)
        report.setdefault('fault_recovery_seconds', {})[name] = round(time.monotonic()-begin, 3)
        report['checks'][name] = True
        save()

    # All operations are stateful and destructive. The signed fixture guard in
    # qualify.py plus a project-name guard is required before the first fault.
    backend_identity(dc, project)
    report['checks']['chaos_identity'] = True; save()
    for service in ('app',):
        begin=time.monotonic()
        mark = marker_prefix+'fpm'
        dc.run('stop', service)
        try:
            fail_closed(client, path, member, mark)
        finally:
            dc.run('start', service)
            wait_ready()
        pass_check('php_fpm_restart', begin)
    begin=time.monotonic()
    mark = marker_prefix+'network'
    network_partition(dc, project, client, path, member, mark, wait_ready,
                      lambda _: None)
    pass_check('network_partition', begin)
    begin=time.monotonic()
    mark = marker_prefix+'disk'
    temporary_disk_full(dc, client, path, member, mark, lambda _: None)
    wait_ready(); pass_check('temp_disk_full', begin)
    begin=time.monotonic()
    interrupted_migration_transaction(dc, wait_ready)
    pass_check('interrupted_transaction_rollback', begin)
