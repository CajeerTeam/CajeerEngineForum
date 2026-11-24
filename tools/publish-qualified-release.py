#!/usr/bin/env python3
"""Stage and verify an immutable qualified release draft. Never publish a draft here.

The protected workflow performs certification and publishes only after this tool
has verified the tag, main ref and every remote asset. Re-running after an upload
interruption may add missing assets; an existing differing asset is never replaced.
"""
import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess


def require(ok, message):
    if not ok:
        raise ValueError(message)


def gh(*args, missing=False):
    result = subprocess.run(['gh',*args],text=True,capture_output=True,timeout=900)
    if result.returncode:
        if missing and ('HTTP 404' in result.stderr or result.stderr.strip().lower()=='release not found'):
            return None
        raise RuntimeError('GitHub operation failed: '+args[0]+' '+args[1]+': '+result.stderr.strip()[:500])
    return result.stdout


def api(repo,path,missing=False):
    value=gh('api',f'repos/{repo}/{path}',missing=missing)
    return None if value is None else json.loads(value)


def asset_inventory(files):
    inventory={}
    for path in files:
        require(path.is_file() and not path.is_symlink() and path.stat().st_size>0,'Invalid asset: '+path.name)
        require(path.name not in inventory,'Duplicate asset: '+path.name)
        with path.open('rb') as stream:
            inventory[path.name]={'path':path,'size':path.stat().st_size,'digest':'sha256:'+hashlib.file_digest(stream,'sha256').hexdigest()}
    require(inventory,'No assets')
    return inventory


def missing_assets(remote, expected, *, complete=False):
    require(isinstance(remote,list),'Invalid remote asset list')
    seen=set()
    for row in remote:
        name=row.get('name')
        require(name in expected and name not in seen,'Unexpected/duplicate remote asset: '+str(name))
        seen.add(name)
        local=expected[name]
        require(row.get('state')=='uploaded' and row.get('size')==local['size'] and row.get('digest')==local['digest'], 'Remote asset differs or upload incomplete: '+name)
    absent=set(expected)-seen
    require(not complete or not absent,'Draft incomplete: '+', '.join(sorted(absent)))
    return [expected[name]['path'] for name in sorted(absent)]


def require_current(repo,tag,commit):
    require(api(repo,'git/ref/heads/main')['object']['sha']==commit,'main moved; release not qualified for current main')
    require(api(repo,'commits/'+tag)['sha']==commit,'Release tag points to different commit')


def get_draft(repo,tag):
    raw=gh('release','view',tag,'--repo',repo,'--json','isDraft,databaseId,tagName',missing=True)
    if raw is None:
        return None
    row=json.loads(raw)
    require(row.get('isDraft') is True and row.get('tagName')==tag and type(row.get('databaseId')) is int,'Existing release is not the expected draft')
    full=api(repo,'releases/'+str(row['databaseId']))
    require(full.get('draft') is True and full.get('tag_name')==tag and full.get('prerelease') is False,'Unexpected release identity')
    return full


def stage(repo,tag,commit,notes,files):
    require(repo.lower()=='cajeerteam/cajeerengineforum','Unexpected release repository')
    require(re.fullmatch(r'v[0-9]+\.[0-9]+\.[0-9]+',tag) is not None and re.fullmatch(r'[0-9a-f]{40}',commit) is not None,'Invalid release identity')
    require(notes.is_file(),'Release notes missing')
    expected=asset_inventory(files)
    # Inspect any existing draft before mutating the tag or uploading anything.
    draft=get_draft(repo,tag)
    if draft is not None:
        missing_assets(draft['assets'],expected)
    require(api(repo,'git/ref/heads/main')['object']['sha']==commit,'main moved; release not qualified for current main')
    if api(repo,'git/ref/tags/'+tag,missing=True) is None:
        gh('api','--method','POST',f'repos/{repo}/git/refs','-f','ref=refs/tags/'+tag,'-f','sha='+commit)
    require_current(repo,tag,commit)
    if draft is None:
        gh('release','create',tag,'--repo',repo,'--verify-tag','--target',commit,'--title','CajeerEngineForum '+tag[1:],'--notes-file',str(notes),'--draft')
        draft=get_draft(repo,tag)
    require(draft is not None,'Draft unavailable')
    for path in missing_assets(draft['assets'],expected):
        gh('release','upload',tag,str(path),'--repo',repo)
    # Re-fetch and compare the exact asset set (including remote SHA256 digests).
    draft=get_draft(repo,tag)
    require(draft is not None,'Draft unavailable after upload')
    missing_assets(draft['assets'],expected,complete=True)
    require_current(repo,tag,commit)
    print('PASS: complete draft, remote asset digests and immutable tag verified; publication remains protected')


def verify_published(repo, tag, commit, files):
    """Revalidate remote bytes before Latest promotion; never replace assets."""
    require(repo.lower()=='cajeerteam/cajeerengineforum' and tag=='v1.6.0'
            and re.fullmatch(r'[0-9a-f]{40}',commit) is not None,'Invalid stable promotion identity')
    require_current(repo,tag,commit)
    release=api(repo,'releases/tags/'+tag)
    require(release.get('draft') is False and release.get('prerelease') is False
            and release.get('tag_name')==tag and bool(release.get('published_at')), 'Not the published candidate')
    missing_assets(release['assets'],asset_inventory(files),complete=True)
    require_current(repo,tag,commit)
    print('PASS: exact public release assets and tag revalidated')


def main():
    p=argparse.ArgumentParser(description=__doc__)
    for name in ('repo','tag','commit'):p.add_argument('--'+name,required=True)
    p.add_argument('--notes',type=Path);p.add_argument('--verify-published',action='store_true');p.add_argument('files',type=Path,nargs='+')
    a=p.parse_args()
    if a.verify_published: verify_published(a.repo,a.tag,a.commit,a.files)
    else:
        require(a.notes is not None,'Release notes required for draft')
        stage(a.repo,a.tag,a.commit,a.notes,a.files)


if __name__=='__main__':main()
