#!/usr/bin/env python3
"""Apply real target SQLite migrations and exercise the formerly overlong XenForo v5 kind.
SQLite does not enforce VARCHAR(N); PostgreSQL and MySQL upgrades are in migration 0079.
"""
from pathlib import Path
import sqlite3
import tempfile
import uuid
root=Path(__file__).resolve().parents[2]
for driver,fragment in [('pgsql','ALTER COLUMN kind TYPE VARCHAR(32)'),('mysql','MODIFY COLUMN kind VARCHAR(32) NOT NULL')]:
    sql=(root/'migrations'/driver/'0079_forum_xenforo_media_kind_width.sql').read_text()
    assert fragment in sql,(driver,'missing real schema upgrade')
with tempfile.TemporaryDirectory() as folder:
    conn=sqlite3.connect(Path(folder)/'target.sqlite')
    conn.execute('PRAGMA foreign_keys=ON')
    migrations=sorted((root/'migrations/sqlite').glob('*.sql'))
    for sql in migrations:
        conn.executescript(sql.read_text())
    assert len(migrations)>=78
    job='a'*64;category=str(uuid.uuid4())
    conn.execute("INSERT INTO ce_forum_categories(id,name,description,position,visibility) VALUES(?,?,?,0,'hidden')",(category,'import','test'))
    conn.execute("INSERT INTO ce_forum_import_jobs(id,source_version,category_id,backup_id,plan_json,created_at,updated_at) VALUES(?,?,?,?,?,?,?)",(job,'2.3.0',category,'snapshot','{}',1,1))
    kinds=['conversations','conversation_messages','conversation_attachments','conversation_content']
    for i,kind in enumerate(kinds,1):
        conn.execute("INSERT INTO ce_forum_import_map(job_id,kind,source_id,target_id,outcome,reason) VALUES(?,?,?,NULL,'skipped','no_source')",(job,kind,i))
    assert conn.execute("SELECT COUNT(*) FROM ce_forum_import_map WHERE job_id=?",(job,)).fetchone()[0]==4
    conn.commit()
    conn.execute('SAVEPOINT broken_batch')
    conn.execute("INSERT INTO ce_forum_import_map(job_id,kind,source_id,target_id,outcome,reason) VALUES(?,?,?,NULL,'skipped','broken')",(job,'conversation_attachments',42))
    conn.execute('UPDATE ce_forum_import_jobs SET cursor_id=42 WHERE id=?',(job,))
    conn.execute('ROLLBACK TO SAVEPOINT broken_batch');conn.execute('RELEASE SAVEPOINT broken_batch')
    assert conn.execute("SELECT cursor_id FROM ce_forum_import_jobs WHERE id=?",(job,)).fetchone()[0]==0
    assert conn.execute("SELECT COUNT(*) FROM ce_forum_import_map WHERE source_id=42").fetchone()[0]==0
    try:
        conn.execute("INSERT INTO ce_forum_import_map(job_id,kind,source_id,target_id,outcome,reason) VALUES(?,?,?,NULL,'skipped','test')",(job,'conversation_messages',2))
        raise AssertionError('duplicate source row accepted')
    except sqlite3.IntegrityError:pass
    assert conn.execute('PRAGMA foreign_key_check').fetchall()==[]
print(f'PASS {len(migrations)} migrations, XenForo long phase identities, PK/FK integrity, transactional checkpoint rollback')
