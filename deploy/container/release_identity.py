"""Verify the actual running code volume against an independently signed distribution."""
import hashlib
import json
from pathlib import Path
import re
import subprocess
import zipfile

ROOT = Path(__file__).resolve().parents[2]


def require(ok, message):
    if not ok:
        raise RuntimeError(message)


def verify_running(dc, site, archive, key):
    archive, key = Path(archive), Path(key)
    require(archive.is_file() and key.is_file(), 'Signed deployment archive and trusted key required')
    subprocess.run(['php', str(ROOT/'deploy/verify.php'),str(archive),str(key),site['CE_RELEASE_SHA256'],site['CE_RELEASE_VERSION']],check=True)
    with zipfile.ZipFile(archive) as package:
        manifest = json.loads(package.read('cajeerengine/release-manifest.json'))
    expected_tag = f"cajeerengine-forum:{site['CE_RELEASE_VERSION']}-{site['CE_RELEASE_SHA256']}"
    ids = set()
    for service in ('app','worker','scheduler'):
        cid=dc.run('ps','-q',service).strip()
        require(re.fullmatch(r'[a-f0-9]{64}',cid) is not None, 'Missing unique running '+service)
        actual=json.loads(subprocess.check_output(['docker','inspect',cid],text=True,timeout=30,env=dc.env))[0]
        require(actual['State']['Running'] is True and actual['Config']['Image']==expected_tag, 'Incorrect deployment '+service)
        require(re.fullmatch(r'sha256:[a-f0-9]{64}',actual['Image']) is not None, 'Missing image ID '+service)
        ids.add(actual['Image'])
        live=json.loads(dc.run('exec','-T',service,'cat','/srv/forum/release-manifest.json',timeout=30))
        require(live==manifest, 'Installed manifest differs from signed package: '+service)
        dc.run('exec','-T',service,'php','/opt/ce-container/runtime.php','identity',timeout=180)
    require(len(ids)==1, 'App and background workers use different images')
    return {'image':ids.pop(),'manifest_sha256':hashlib.sha256(json.dumps(manifest,sort_keys=True,separators=(',',':')).encode()).hexdigest()}
