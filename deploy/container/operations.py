#!/usr/bin/env python3
"""Read-only production admission check of the *running* Compose forum.

Runs outside containers from a trusted operator checkout. It deliberately neither
creates data nor changes maintenance state. Returns nonzero for every failed gate.
"""
import argparse
import http.client
import json
import os
from pathlib import Path
import re
import socket
import ssl
import subprocess
import sys
import time
from update import HERE, ROOT, project_lock, read_site, durable_json

SERVICES = ('postgres', 'redis', 'app', 'worker', 'scheduler', 'gateway')


class Operations:
    def __init__(self, project, site, ca=None, runner=None, requester=None):
        self.project = project
        self.site = Path(site).resolve()
        self.config = read_site(self.site)
        self.ca = Path(ca).resolve() if ca is not None else None
        if self.ca is not None and not self.ca.is_file():
            raise ValueError('TLS CA file does not exist')
        self.runner = runner or subprocess.run
        self.requester = requester or self.http
        self.env = {k: v for k, v in os.environ.items() if not k.startswith(('CE_', 'COMPOSE_'))}
        self.results = {}

    def execute(self, command, timeout=25):
        try:
            result = self.runner(command, cwd=ROOT, env=self.env, text=True,
                                 capture_output=True, timeout=timeout, check=False)
        except (subprocess.TimeoutExpired, OSError) as exc:
            raise RuntimeError('Operator subprocess unavailable: '+type(exc).__name__) from None
        if result.returncode:
            # Docker/CLI errors may contain private paths, environment or tokens.
            raise RuntimeError('Operator subprocess failed (exit '+str(result.returncode)+')')
        return result.stdout

    def compose(self, *args, timeout=30):
        return self.execute(['docker', 'compose', '--project-name', self.project,
                             '--env-file', str(self.site), '-f', str(HERE/'compose.yaml'), *args], timeout)

    def check(self, name, fn):
        start = time.monotonic()
        try:
            fn()
            self.results[name] = {'ok': True, 'duration_ms': round((time.monotonic()-start)*1000, 1)}
        except Exception as exc:
            # Only constant messages originating in this trusted implementation.
            message = str(exc)
            if not isinstance(exc, (RuntimeError, ValueError)):
                message = type(exc).__name__
            self.results[name] = {'ok': False, 'duration_ms': round((time.monotonic()-start)*1000, 1),
                                  'reason': message[:180]}

    @staticmethod
    def parse_ps(data):
        stripped = data.strip()
        if not stripped:
            raise RuntimeError('Compose has no running containers')
        rows = json.loads(stripped) if stripped.startswith('[') else [json.loads(line) for line in stripped.splitlines()]
        result = {}
        for row in rows:
            service = row.get('Service')
            if not isinstance(service, str) or service in result:
                raise RuntimeError('Invalid or duplicated Compose service')
            result[service] = row
        return result

    def containers(self):
        containers = self.parse_ps(self.compose('ps', '--all', '--format', 'json'))
        release = self.config['CE_RELEASE_VERSION']
        sha = self.config['CE_RELEASE_SHA256']
        for service in SERVICES:
            row = containers.get(service)
            if row is None or row.get('State') != 'running' or row.get('Health') != 'healthy':
                raise RuntimeError('Required service not running and healthy: '+service)
            if service in ('app', 'worker', 'scheduler'):
                if row.get('Image') != 'cajeerengine-forum:'+release+'-'+sha:
                    raise RuntimeError('Running release image mismatch: '+service)
            if service == 'gateway' and row.get('Image') != 'cajeerengine-forum-gateway:'+release:
                raise RuntimeError('Running gateway image mismatch')
        init = containers.get('init')
        if init is None or init.get('State') != 'exited' or int(init.get('ExitCode', -1)) != 0:
            raise RuntimeError('One-shot installer did not complete successfully')

    def runtime(self):
        for service, check in [('app', 'identity'), ('app', 'ready'), ('worker', 'loop-health'),
                               ('scheduler', 'loop-health')]:
            self.compose('exec', '-T', service, 'php', '/opt/ce-container/runtime.php', check, timeout=30)

    def backups(self):
        output = self.compose('exec', '-T', 'app', 'php', 'bin/cajeer',
                              'backup:automation:status', '--json', timeout=30)
        try:
            result = json.loads(output)
        except (ValueError, TypeError):
            raise RuntimeError('Backup status is not JSON') from None
        # CLI consistently returns an object, but never trust a successful exit alone.
        if not isinstance(result, dict) or result.get('ok') is not True:
            raise RuntimeError('No recent verified off-site backup or backup schedule is unhealthy')
        if int(result.get('last_offsite', 0)) <= 0 or int(result.get('pending', 1)) != 0:
            raise RuntimeError('Backups missing or off-site deliveries pending')

    def http(self, path):
        domain = self.config['CE_DOMAIN']
        bind = self.config['CE_BIND_ADDRESS']
        address = '127.0.0.1' if bind == '0.0.0.0' else bind
        port = int(self.config['CE_HTTPS_PORT'])
        context = ssl.create_default_context(cafile=str(self.ca) if self.ca else None)
        with socket.create_connection((address, port), timeout=7) as raw:
            with context.wrap_socket(raw, server_hostname=domain) as tls:
                tls.settimeout(7)
                host = domain + (':'+str(port) if port != 443 else '')
                request = ('GET '+path+' HTTP/1.1\r\nHost: '+host+
                           '\r\nUser-Agent: CajeerForumOperations/0.9\r\nConnection: close\r\n\r\n')
                tls.sendall(request.encode('ascii'))
                response = http.client.HTTPResponse(tls)
                response.begin()
                # Strict bound even if a compromised service streams forever.
                length = response.getheader('Content-Length')
                if length is not None and int(length) > 1048576:
                    raise RuntimeError('HTTP response exceeds operational size limit')
                body = response.read(1048577)
                if len(body) > 1048576:
                    raise RuntimeError('HTTP response exceeds operational size limit')
                return response.status, body

    def public(self):
        ready, _ = self.requester('/health/ready')
        if ready != 200:
            raise RuntimeError('Public TLS readiness is not HTTP 200')
        status, body = self.requester('/api/v1/health')
        if status != 200:
            raise RuntimeError('Public application health is not HTTP 200')
        try: payload = json.loads(body)
        except (TypeError, ValueError): raise RuntimeError('Application health is not JSON') from None
        if payload.get('status') != 'ok' or payload.get('edition_version') != self.config['CE_RELEASE_VERSION']:
            raise RuntimeError('Deployed edition or application readiness mismatch')
        if payload.get('checks', {}).get('database') is not True:
            raise RuntimeError('Application database readiness is not true')
        status, body = self.requester('/forum')
        if status != 200 or b'data-rendered="true"' not in body:
            raise RuntimeError('Forum SSR is unavailable')
        status, body = self.requester('/admin/')
        if status != 200 or b'admin-app.js' not in body:
            raise RuntimeError('Administration frontend is unavailable')
        for path in ('/.env', '/install/', '/upgrade/'):
            status, _ = self.requester(path)
            if status != 404:
                raise RuntimeError('Private path is exposed or returns unexpected status: '+path)

    def run(self, require_backup=False):
        self.results = {}
        self.check('containers', self.containers)
        # Running CLI checks and public TLS are independent for actionable diagnostics.
        self.check('runtime', self.runtime)
        self.check('https', self.public)
        if require_backup:
            self.check('offsite_backup', self.backups)
        return {'ok': all(item['ok'] for item in self.results.values()),
                'edition': 'forum', 'edition_version': self.config['CE_RELEASE_VERSION'],
                'checked_at': int(time.time()), 'checks': self.results}


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--project', required=True)
    parser.add_argument('--env-file', type=Path, required=True)
    parser.add_argument('--ca', type=Path)
    parser.add_argument('--require-backup', action='store_true')
    parser.add_argument('--report', type=Path)
    args = parser.parse_args(argv)
    if not re.fullmatch(r'[a-z0-9][a-z0-9_-]{0,62}', args.project):
        parser.error('Invalid project')
    if args.report is not None:
        if not args.report.parent.is_dir() or args.report.is_symlink():
            parser.error('Report parent must exist; destination must not be a symlink')
        if args.report.exists() and (not args.report.is_file() or args.report.stat().st_mode & 0o077):
            parser.error('Existing report must be private')
    with project_lock(args.project):
        # Never operate on an implicit remote Docker endpoint.
        env = {k:v for k,v in os.environ.items() if not k.startswith(('CE_', 'COMPOSE_'))}
        host = env.get('DOCKER_HOST')
        if not host or env.get('DOCKER_CONTEXT'):
            try:
                host = subprocess.check_output(['docker','context','inspect','--format',
                                                '{{.Endpoints.docker.Host}}'], text=True,
                                               env=env, timeout=15).strip()
            except (OSError, subprocess.SubprocessError):
                raise RuntimeError('Cannot determine local Docker endpoint') from None
        if not host.startswith('unix://'):
            raise RuntimeError('Local Unix Docker endpoint required')
        operations = Operations(args.project, args.env_file, args.ca)
        report = operations.run(args.require_backup)
        if args.report is not None:
            durable_json(args.report, report, mode=0o600)
        print(json.dumps(report, indent=2, sort_keys=True))
        return 0 if report['ok'] else 1


if __name__ == '__main__':
    try: sys.exit(main())
    except (OSError, ValueError, RuntimeError, BlockingIOError) as exc:
        print('Operations verification failed: '+type(exc).__name__, file=sys.stderr)
        sys.exit(2)
