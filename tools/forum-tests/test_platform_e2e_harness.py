#!/usr/bin/env python3
"""Executable regressions for the real HTTP router and acceptance evidence."""
import hashlib
import json
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import unittest
import urllib.request
from io import StringIO

from run import HERE, ROOT, JUnitResult


class HarnessTest(unittest.TestCase):
    def test_skip_is_recorded(self):
        class Skip(unittest.TestCase):
            def test_skipped(self):
                self.skipTest('fixture')
        result = unittest.TextTestRunner(stream=StringIO(), resultclass=JUnitResult).run(
            unittest.defaultTestLoader.loadTestsFromTestCase(Skip))
        self.assertEqual(result.testsRun, 1)
        self.assertEqual(len(result.skipped), 1)
        self.assertEqual(result.cases[0].find('skipped').get('message'), 'fixture')

    def test_static_router_serves_only_shipped_assets(self):
        with tempfile.TemporaryDirectory(prefix='forum-router-') as tmp:
            public = Path(tmp) / 'public'
            shutil.copytree(ROOT / 'public/admin', public / 'admin')
            (public / 'index.php').write_text('<?php echo "application-router";')
            (public / 'admin/assets/secret.txt').write_text('private-sentinel')
            (public / 'forum-assets').mkdir()
            (public / 'forum-assets/forum.js').write_text('forum-sentinel')
            with socket.socket() as sock:
                sock.bind(('127.0.0.1', 0))
                port = sock.getsockname()[1]
            base = f'http://127.0.0.1:{port}'
            opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
            proc = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', str(public), str(HERE / 'router.php')],
                                    stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            try:
                for _ in range(50):
                    try:
                        with opener.open(base + '/admin/', timeout=1) as res:
                            self.assertEqual(res.read(), (public / 'admin/index.html').read_bytes())
                            self.assertEqual(res.headers['Cache-Control'], 'no-store')
                        break
                    except OSError:
                        if proc.poll() is not None:
                            self.fail('PHP router exited')
                        time.sleep(.1)
                else:
                    self.fail('PHP router not ready')
                with opener.open(base + '/admin/assets/admin-app.js?v=test', timeout=2) as res:
                    self.assertEqual(res.read(), (public / 'admin/assets/admin-app.js').read_bytes())
                    self.assertIn('javascript', res.headers['Content-Type'])
                for route in ('/admin/assets/secret.txt', '/api/v1/auth/me', '/admin/assets/admin-manifest.json'):
                    with opener.open(base + route, timeout=2) as res:
                        self.assertEqual(res.read(), b'application-router')
                with opener.open(base + '/forum-assets/forum.js', timeout=2) as res:
                    self.assertEqual(res.read(), b'forum-sentinel')
            finally:
                proc.terminate()
                proc.wait(timeout=5)

    def test_edition_and_asset_digests(self):
        version = json.loads((ROOT / 'release.json').read_text())['edition_version']
        index = (ROOT / 'public/admin/index.html').read_text()
        app = (ROOT / 'public/admin/assets/admin-app.js').read_text()
        self.assertIn(f"const FORUM_EDITION = '{version}'", app.splitlines())
        for ext in ('js', 'css'):
            self.assertIn(f'/admin/assets/admin-app.{ext}?v={version}"', index)
        manifest = json.loads((ROOT / 'public/admin/assets/admin-manifest.json').read_text())
        for path, record in manifest['files'].items():
            data = (ROOT / 'public/admin' / path).read_bytes()
            self.assertEqual(hashlib.sha256(data).hexdigest(), record['sha256'])
            self.assertEqual(len(data), record['size'])


if __name__ == '__main__':
    unittest.main(verbosity=2)
