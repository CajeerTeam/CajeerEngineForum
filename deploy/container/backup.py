#!/usr/bin/env python3
"""One-shot operator backup, serialized with Compose upgrades on the local Docker host."""
import argparse
import os
from pathlib import Path
import re
import subprocess
from update import HERE, ROOT, project_lock, read_site


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('action', choices=['build', 'run'])
    p.add_argument('--project', required=True)
    p.add_argument('--env-file', required=True, type=Path)
    p.add_argument('--config-dir', required=True, type=Path)
    p.add_argument('--offsite-dir', required=True, type=Path)
    p.add_argument('--aws-profile', default='default')
    p.add_argument('--force', action='store_true')
    a = p.parse_args()
    if not re.fullmatch(r'[a-z0-9][a-z0-9_-]{0,62}', a.project): p.error('Invalid Compose project')
    if not re.fullmatch(r'[A-Za-z0-9_.-]{1,80}', a.aws_profile): p.error('Invalid AWS profile')
    for path in (a.config_dir, a.offsite_dir):
        if not path.is_absolute() or path.resolve() != path or not path.is_dir(): p.error('Use existing canonical absolute directories')
    if not (a.config_dir/'policy.json').is_file(): p.error('Missing protected policy.json')
    site = read_site(a.env_file)  # validated literal site configuration
    if site['CE_QUEUE_DRIVER'] != 'pgsql':
        raise ValueError('Automated recovery sets require a PostgreSQL queue; Redis queue capture is unsupported')
    env = {k:v for k,v in os.environ.items() if not k.startswith(('CE_', 'COMPOSE_'))}
    host = env.get('DOCKER_HOST')
    if not host or env.get('DOCKER_CONTEXT'):
        host = subprocess.check_output(['docker','context','inspect','--format','{{.Endpoints.docker.Host}}'],text=True,env=env).strip()
    if not host.startswith('unix://'): p.error('Local Docker host required')
    env.update(CE_BACKUP_CONFIG_DIR=str(a.config_dir), CE_BACKUP_OFFSITE_DIR=str(a.offsite_dir), CE_BACKUP_AWS_PROFILE=a.aws_profile)
    cmd=['docker','compose','--project-name',a.project,'--env-file',str(a.env_file.resolve()),'-f',str(HERE/'compose.yaml'),'-f',str(HERE/'backup.compose.yaml'),'--profile','backup']
    cmd += ['build','backup'] if a.action == 'build' else ['run','--rm','--no-deps','backup']+(['--force'] if a.force else [])
    with project_lock(a.project):
        subprocess.run(cmd,cwd=ROOT,env=env,check=True)

if __name__ == '__main__':
    main()
