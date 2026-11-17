#!/usr/bin/env python3
"""Release-chain admission negative tests; physical SQL/HTTP runs in CI."""
import json
import os
import subprocess
from pathlib import Path
import sys
import tempfile
import unittest

sys.path.insert(0,str(Path(__file__).resolve().parent))
from release_acceptance import verify_release_sequence, chain_receipt, enable_legacy_pgsql_fts_compat

class UpgradeChainRegression(unittest.TestCase):
    def test_supported_chronological_versions(self):
        verify_release_sequence(['0.8.7','0.9.2','0.9.8','0.9.9','1.0.0'])

    def test_out_of_order_or_equal_versions_rejected(self):
        for versions in (['0.9.7','0.9.7'],['0.9.9','0.9.8'],
                         ['0.8.7','0.9.9','0.9.2'],['0.8.7','1.0.0-rc.2'],
                         ['01.9.8','0.9.9'],['0.9.8'],['0.9.0']*11):
            with self.subTest(versions=versions),self.assertRaises(ValueError):
                verify_release_sequence(versions)

    def test_only_final_approved_rc_is_accepted(self):
        verify_release_sequence(['0.8.7','0.9.2','0.9.7','0.9.8','1.0.0-rc.1'])
        for bad in (['0.9.8','1.0.0-rc.2'], ['1.0.0-rc.1','1.0.0'], ['0.9.8','1.0.0-rc.1','1.0.0']):
            with self.subTest(bad=bad), self.assertRaises(ValueError):
                verify_release_sequence(bad)

    def test_authentic_legacy_php_uses_temporary_fts_preload(self):
        with tempfile.TemporaryDirectory() as temp:
            work=Path(temp)
            original=work/'historical-signed.zip'
            original.write_bytes(b'never-edit-authentic-release')
            env=os.environ.copy()
            enable_legacy_pgsql_fts_compat(work,env)
            ini=work/'legacy-php-ini'/'zz-ce-forum-fts.ini'
            self.assertTrue(ini.is_file())
            self.assertIn('auto_prepend_file=',ini.read_text())
            self.assertEqual(env['PHP_INI_SCAN_DIR'],':'+str(ini.parent))
            script=work/'probe.php'
            script.write_text('<?php echo class_exists("CajeerEngine\\Forum\\Service\\ForumSearchMaintenance",false)?"preloaded":"missing";')
            result=subprocess.run(['php',str(script)],env=env,text=True,capture_output=True,check=True)
            self.assertEqual(result.stdout,'preloaded')
            self.assertEqual(original.read_bytes(),b'never-edit-authentic-release')

    def test_receipt_uses_real_artifact_hashes_only_after_recovery(self):
        with tempfile.TemporaryDirectory() as temp:
            old=Path(temp)/'old.zip';new=Path(temp)/'new.zip'
            old.write_bytes(b'authentic source-dist');new.write_bytes(b'approved target-dist')
            for rollback,failed in ((False,True),(True,False)):
                with self.assertRaises(RuntimeError):
                    chain_receipt(['0.9.8','0.9.9'],[old,new],'b'*40,rollback,failed)
            receipt=chain_receipt(['0.9.8','0.9.9'],[old,new],'b'*40,True,True)
            self.assertEqual(receipt['rollback_to'],'0.9.8')
            self.assertEqual(receipt['steps'][0]['source_sha256'],__import__('hashlib').sha256(old.read_bytes()).hexdigest())
            self.assertNotEqual(receipt['steps'][0]['source_sha256'],receipt['steps'][0]['target_sha256'])
            self.assertEqual(receipt['git_commit'],'b'*40)
            with self.assertRaises(ValueError):
                chain_receipt(['0.9.8','0.9.9'],[old],'b'*40,True,True)
            with self.assertRaisesRegex(ValueError,'Git commit'):
                chain_receipt(['0.9.8','0.9.9'],[old,new],'',True,True)

if __name__=='__main__':unittest.main(verbosity=2)
