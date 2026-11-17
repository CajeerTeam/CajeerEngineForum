#!/usr/bin/env python3
"""Issue/verify 1.0.0 production certification from physical evidence, never placeholders.

External prerequisites: completed tagged release CI, real 10k/100k/1m 24h
reports and both disposable interrupted-update reports. Independent operator
signs the exact SHA-256 input inventory only after reviewing the physical
results. Stable publication re-verifies all original reports and signatures.
"""
import argparse
import base64
import hashlib
import importlib.util
import json
import os
from pathlib import Path
from datetime import datetime, timezone
import re
import subprocess
import sys
import tempfile

ROOT=Path(__file__).resolve().parent.parent
VERSION='1.0.0'
RAW=('certification.json','10k.json','100k.json','1m-soak.json',
     'chaos-drained.json','chaos-activating.json',
     'cajeerengine-1.0.0-source.zip','cajeerengine-1.0.0-dist.zip')
OUTPUT='stable-certification.json'


def demand(test, message):
    if not test: raise ValueError(message)


def safe_file(path):
    demand(path.is_file() and not path.is_symlink(), 'Missing or linked evidence: '+path.name)
    demand(path.stat().st_size <= (8*1024*1024 if path.suffix=='.json' else 2000000000), 'Oversized evidence: '+path.name)
    return path


def digest(path):
    with safe_file(path).open('rb') as f: return hashlib.file_digest(f,'sha256').hexdigest()


def read(path):
    demand(path.suffix=='.json', 'Only JSON evidence is supported')
    safe_file(path)
    return json.loads(path.read_text(),parse_constant=lambda x: (_ for _ in ()).throw(ValueError('Nonfinite JSON')))


def module(filename,name):
    spec=importlib.util.spec_from_file_location(name,ROOT/filename)
    mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod)
    return mod


def ed25519_approval(path, signature, public_key):
    """Verify detached Ed25519 over unmodified approval JSON bytes using PHP sodium."""
    for file in (path,signature,public_key):safe_file(file)
    code='''
    $pub=base64_decode(trim(file_get_contents($argv[3])),true);
    $sig=base64_decode(trim(file_get_contents($argv[2])),true);
    if(!is_string($pub)||strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ||
       !is_string($sig)||strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES ||
       !sodium_crypto_sign_verify_detached($sig,file_get_contents($argv[1]),$pub)) exit(2);
    '''
    subprocess.run(['php','-r',code,str(path),str(signature),str(public_key)],check=True,timeout=15)


def inspect(directory, trusted_release, trusted_operator):
    require=(directory/'ci-release.pub')
    safe_file(require)
    demand(trusted_release.resolve()!=trusted_operator.resolve(), 'Separate publisher and operator trust anchors required')
    demand(digest(trusted_release)!=digest(trusted_operator), 'Publisher and operator trust anchor must differ')
    # Local release provenance and physical job matrix are rechecked, not read from an ok flag.
    cert=read(directory/'certification.json')
    commit=cert.get('commit')
    demand(re.fullmatch('[a-f0-9]{40}',str(commit)) is not None, 'Missing certified Git SHA')
    demand(cert.get('version')==VERSION and cert.get('signing')=='trusted-release-key', 'Not a stable tagged CI certificate')
    subprocess.run(['python3',str(ROOT/'tools/certification-evidence.py'),'verify',str(directory),
                    '--trusted-key',str(trusted_release)],check=True,timeout=180,stdout=subprocess.DEVNULL)
    subprocess.run(['python3',str(ROOT/'tools/final-audit.py'),'--directory',str(directory),
                    '--trusted-public-key',str(trusted_release),'--version',VERSION,'--commit',commit],
                   check=True,timeout=180,stdout=subprocess.DEVNULL)
    distsha=digest(directory/'cajeerengine-1.0.0-dist.zip')
    last_finished=0
    load=module('deploy/container/certify_load.py','stable_load_certifier')
    summaries={}
    hardware=set(); images=set()
    for profile in ('10k','100k','1m-soak'):
        report=load.validate_report(directory/(profile+'.json'),profile,commit,distsha,VERSION)
        hardware.add(report['hardware']['host_sha256']);images.add(report['app_image'])
        last_finished=max(last_finished,report['finished_at'])
        summaries[profile]={'sha256':digest(directory/(profile+'.json')),
                            'elapsed_seconds':report['load']['elapsed_seconds'],
                            'rps':report['load']['requests_per_second'],
                            'queue_p95_upper_ms':report['queue_delivery']['p95_upper_ms']}
    demand(len(hardware)==1 and len(images)==1, 'Physical performance reports must use same host and image')
    chaos={}
    for checkpoint,expected in (('drained','rolled_back'),('activating','committed')):
        file=directory/('chaos-'+checkpoint+'.json');doc=read(file)
        demand(doc.get('schema')==1 and doc.get('ok') is True and doc.get('checkpoint')==checkpoint
               and doc.get('source_version') in ('0.9.9','1.0.0-rc.1')
               and doc.get('target_version')==VERSION
               and doc.get('target_sha256')==distsha
               and doc.get('recovery_state')==expected
               and doc.get('actual_operator_sigkill') is True
               and re.fullmatch(r'[a-f0-9]{64}',str(doc.get('source_sha256',''))) is not None,
               'Incomplete physical interrupted-update recovery: '+checkpoint)
        chaos[checkpoint]={'sha256':digest(file),'result':expected}
    approval=read(directory/'operator-approval.json')
    expected_hashes={name:digest(directory/name) for name in RAW}
    demand(approval.get('schema')==1 and approval.get('decision')=='GO'
           and approval.get('version')==VERSION and approval.get('commit')==commit
           and approval.get('dist_sha256')==distsha and approval.get('input_sha256')==expected_hashes
           and approval.get('checks') == {'load_and_24h_soak_reviewed':True,'chaos_recovery_reviewed':True,
                                          'restore_dr_reviewed':True,'oncall_and_rollback_ready':True},
           'Operator has not signed GO for exact completed physical evidence')
    demand(approval.get('reviewed_at_utc') and re.fullmatch(r'20\d\d-\d\d-\d\dT\d\d:\d\d:\d\dZ',approval['reviewed_at_utc']) is not None,
           'Operator approval lacks UTC review timestamp')
    reviewed=datetime.strptime(approval['reviewed_at_utc'],'%Y-%m-%dT%H:%M:%SZ').replace(tzinfo=timezone.utc).timestamp()
    demand(reviewed >= last_finished, 'Operator GO predates the completed 24-hour physical test')
    ed25519_approval(directory/'operator-approval.json',directory/'operator-approval.json.sig',trusted_operator)
    return {'schema':1,'ok':True,'version':VERSION,'commit':commit,'dist_sha256':distsha,
            'ci_run_id':cert['run_id'],'ci_attempt':cert['attempt'],
            'load':summaries,'chaos':chaos,'reviewed_at_utc':approval['reviewed_at_utc'],
            'evidence_sha256':{**expected_hashes,
                'operator-approval.json':digest(directory/'operator-approval.json'),
                'operator-approval.json.sig':digest(directory/'operator-approval.json.sig')},
            'release_key_sha256':digest(trusted_release),'operator_key_sha256':digest(trusted_operator)}


def run(args):
    directory=args.directory.resolve(strict=True)
    public=args.trusted_public_key
    operator=args.operator_public_key
    expected=inspect(directory,public,operator)
    target=directory/OUTPUT
    if args.action=='verify':
        demand(read(target)==expected, 'Stable certification is missing, stale or tampered')
    else:
        demand(not target.exists() and not target.is_symlink(), 'Refusing to overwrite stable certificate')
        umask=os.umask(0o077)
        try:
            with tempfile.NamedTemporaryFile('w',dir=directory,prefix='.stable-cert-',delete=False) as stream:
                temporary=Path(stream.name)
                json.dump(expected,stream,indent=2,sort_keys=True);stream.write('\n')
                stream.flush();os.fsync(stream.fileno())
            os.replace(temporary,target)
        finally:os.umask(umask)
    print('PASS independently verified stable',args.action,'commit',expected['commit'],'dist',expected['dist_sha256'])


if __name__=='__main__':
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('action',choices=('issue','verify'))
    p.add_argument('--directory',required=True,type=Path)
    p.add_argument('--trusted-public-key',required=True,type=Path)
    p.add_argument('--operator-public-key',required=True,type=Path)
    args=p.parse_args()
    try:run(args)
    except (ValueError, KeyError, TypeError, OSError, subprocess.SubprocessError) as error:
        print('FAIL stable certification:',error,file=sys.stderr);sys.exit(1)
