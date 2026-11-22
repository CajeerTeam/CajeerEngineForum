#!/usr/bin/env python3
"""Negative and cryptographic-admission plumbing regressions for final RC audit."""
import importlib.util
import json
from pathlib import Path
from unittest.mock import patch
import tempfile
import unittest
import zipfile

ROOT=Path(__file__).resolve().parent.parent

def load(name, filename):
    spec=importlib.util.spec_from_file_location(name,ROOT/'tools'/filename)
    module=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module

audit=load('final_audit','final-audit.py')
staging=load('staging_gate','staging-gate.py')
regression=load('fixture_ci','certification-regression.py')
e=regression.e


def synthetic_complete(directory):
    # Fabricate test-only runner data but exercise actual hashing, ZIP identity,
    # CI topology and evidence provenance. Signature entrypoint is mocked only
    # here; release production validation never accepts mocked signatures.
    regression.fixture(directory)
    for mode in ('source','dist'):
        file=directory/f'cajeerengine-{regression.VERSION}-{mode}.zip'
        with zipfile.ZipFile(file,'w') as z:
            z.writestr('cajeerengine/release.json',json.dumps({'edition':'forum','edition_version':regression.VERSION,'upstream_version':'2.1.0'}))
    dist=audit.sha(directory/f'cajeerengine-{regression.VERSION}-dist.zip')
    host=regression.a.read_report(directory/'production-acceptance.json') if hasattr(regression.a,'read_report') else json.loads((directory/'production-acceptance.json').read_text())
    host['artifact_sha256']=dist
    regression.a.write_report(directory/'production-acceptance.json',host)
    for label in ('sqlite','pg17','pg18','s3'):
        file=directory/('dr-'+label+'.json')
        doc=json.loads(file.read_text());doc['artifact_sha256']=dist
        regression.a.write_report(file,doc)
    file=directory/'staging-acceptance.json'
    doc=json.loads(file.read_text());doc['artifact_sha256']=dist
    doc['host_report_sha256']=audit.sha(directory/'production-acceptance.json')
    regression.a.write_report(file,doc)
    (directory/f'cajeerengine-{regression.VERSION}-artifacts.json').write_text(json.dumps({
        'version':regression.VERSION,
        'artifacts':[{'mode':mode} for mode in ('source','dist')]}))
    for mode in ('source','dist'):
        filename=f'cajeerengine-{regression.VERSION}-{mode}.zip'
        (directory/(filename+'.sha256')).write_text(audit.sha(directory/filename)+'  '+filename+'\n')
    # Real Ed25519 material is checked by production CLI. This fixture only
    # exercises independently computed digests and evidence consistency.
    key=directory/'external.pub';key.write_bytes((directory/'ci-release.pub').read_bytes())
    with patch.object(e,'verify_signatures'):
        e.seal(directory,regression.VERSION,regression.COMMIT,regression.REPO,regression.RUN,regression.ATTEMPT)
    return key


class FinalAuditRegression(unittest.TestCase):
    def test_complete_and_reject_tampered_evidence(self):
        with tempfile.TemporaryDirectory() as tmp:
            d=Path(tmp);key=synthetic_complete(d)
            with patch.object(audit.subprocess,'run') as verify:
                result=audit.audit(d,key,regression.VERSION,regression.COMMIT)
                self.assertTrue(result['ok'])
                self.assertEqual(result['matrix_jobs'],sum(audit.EXPECTED.values()))
                verify.assert_called_once()
            for name in ('staging-acceptance.json','ci-jobs.json','dr-pg18.json',
                         'dependency-audit.json','cajeerengine-'+regression.VERSION+'-dist.zip.sig'):
                file=d/name;original=file.read_bytes();file.write_bytes(original+b'!')
                with self.subTest(name=name),self.assertRaises(ValueError):
                    audit.audit(d,key,regression.VERSION,regression.COMMIT)
                file.write_bytes(original)
            with self.assertRaises(ValueError):audit.audit(d,key,regression.VERSION,'f'*40)
            key.write_text('different-external-trust-root')
            with self.assertRaises(ValueError):audit.audit(d,key,regression.VERSION,regression.COMMIT)

    def test_reject_resealed_bad_staging_and_ci(self):
        for case in ('stage_missing_check','stage_wrong_host','ci_missing_job','composer_advisory'):
            with self.subTest(case=case),tempfile.TemporaryDirectory() as tmp:
                d=Path(tmp);key=synthetic_complete(d)
                files={'stage_missing_check':'staging-acceptance.json','stage_wrong_host':'staging-acceptance.json',
                       'ci_missing_job':'ci-jobs.json','composer_advisory':'dependency-audit.json'}
                file=d/files[case];doc=json.loads(file.read_text())
                if case=='stage_missing_check':doc['checks'].pop('http_auth_acl_search_media_and_moderation')
                if case=='stage_wrong_host':doc['host_report_sha256']='0'*64
                if case=='ci_missing_job':doc['jobs'].pop()
                if case=='composer_advisory':doc['advisories']={'unsafe/a':[{'id':'CVE'}]}
                file.write_text(json.dumps(doc))
                # Resigning report evidence alone can never bypass semantic verification.
                cert=d/'certification.json';sealed=json.loads(cert.read_text());sealed['sha256'][file.name]=audit.sha(file)
                if case=='ci_missing_job':sealed['ci_jobs']=doc['jobs']
                cert.write_text(json.dumps(sealed))
                with patch.object(audit.subprocess,'run'),self.assertRaises(ValueError):
                    audit.audit(d,key,regression.VERSION,regression.COMMIT)

    def test_staging_real_logs_digest_guard(self):
        with tempfile.TemporaryDirectory() as tmp:
            d=Path(tmp);candidate=d/'candidate';candidate.mkdir()
            zipfile_path=candidate/f'cajeerengine-{regression.VERSION}-dist.zip'
            with zipfile.ZipFile(zipfile_path,'w') as z:
                z.writestr('cajeerengine/release.json',json.dumps({'edition_version':regression.VERSION,'edition':'forum','upstream_version':'2.1.0'}))
            key=d/'key.pub';key.write_text('dummy-key')
            host=d/'host.json';host.write_text(json.dumps({
                'ok':True,'version':regression.VERSION,'profile':'nginx-tls-fpm-pgsql',
                'artifact_sha256':staging.digest(zipfile_path),'checks':dict.fromkeys([str(i) for i in range(8)],True),
                'load':{'errors':0,'requests':400}}))
            stdout=d/'stdout.log';stdout.write_text('PASS physical dist acceptance\n')
            logs=d/'logs';logs.mkdir()
            for name in ('initial-install.log','initial-migrate.log','initial-postcheck.log','initial-install-check.log'):
                (logs/name).write_text('actual fixture command\n')
            with patch.object(staging.subprocess,'run'):
                good=staging.check(candidate,key,host,stdout,logs,regression.VERSION,regression.COMMIT)
                self.assertEqual(good['artifact_sha256'],staging.digest(zipfile_path))
                stdout.write_text('FAIL physical dist acceptance\n')
                with self.assertRaises(ValueError):staging.check(candidate,key,host,stdout,logs,regression.VERSION,regression.COMMIT)
                stdout.write_text('PASS physical dist acceptance\n')
                (logs/'initial-migrate.log').unlink()
                with self.assertRaises(ValueError):staging.check(candidate,key,host,stdout,logs,regression.VERSION,regression.COMMIT)

if __name__=='__main__':unittest.main(verbosity=2)
