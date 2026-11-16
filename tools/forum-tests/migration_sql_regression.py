#!/usr/bin/env python3
"""Exercise the exact target SQL, schema, FTS and rollback on SQLite without PHP PDO."""
import re, sqlite3, tempfile, uuid, hashlib, base64, json
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
code=(ROOT/'core/Forum/Migration/ForumMigrationEngine.php').read_text()
queries=[q for _,q in re.findall(r'''\$this->query\((['"])(.*?)\1''',code,re.S)]
def sql(prefix): return next(q for q in queries if q.startswith(prefix))
def uid(): return str(uuid.uuid4())
with tempfile.TemporaryDirectory() as tmp:
    db=sqlite3.connect(Path(tmp)/'target.sqlite');db.execute('PRAGMA foreign_keys=ON')
    files=sorted((ROOT/'migrations/sqlite').glob('*.sql'))
    assert files[-1].name=='0047_forum_migration.sql'
    for f in files: db.executescript(f.read_text())
    src=sqlite3.connect(':memory:');src.executescript((ROOT/'tools/forum-tests/phpbb_fixture.sql').read_text())
    assert src.execute('SELECT COUNT(*) FROM phpbb_posts').fetchone()[0]==4
    job='a'*64;cat=uid();role=uid();user=uid();board=uid();thread=uid();post=uid();attachment=uid();date='2020-09-13 12:26:40'
    db.execute(sql('INSERT INTO ce_forum_categories'),(cat,'Imported','Archive'))
    db.execute(sql('INSERT INTO ce_forum_import_jobs'),(job,'3.3.15',cat,'backup','{}',1,1));db.commit()
    db.execute(sql('INSERT INTO ce_roles'),(role,'forum-group-import-example','Imported','[]'))
    db.execute(sql('INSERT INTO ce_forum_groups'),(role,''))
    db.execute(sql('INSERT INTO ce_forum_import_map'),(job,'groups',4,role,'imported',''))
    db.execute(sql('UPDATE ce_forum_import_jobs SET phase=?,cursor_id='),(0,4,0,2,job));db.rollback()
    assert db.execute('SELECT COUNT(*) FROM ce_forum_import_map').fetchone()[0]==0
    assert db.execute('SELECT cursor_id FROM ce_forum_import_jobs').fetchone()[0]==0
    db.execute(sql('INSERT INTO ce_roles'),(role,'forum-group-import-example','Imported','[]'))
    db.execute(sql('INSERT INTO ce_forum_groups'),(role,''))
    db.execute(sql('INSERT INTO ce_users'),(user,'alice@example.test','Alice','random-hash',date,date))
    member=db.execute("SELECT id FROM ce_roles WHERE handle='forum-member'").fetchone()[0]
    db.execute(sql('INSERT INTO ce_user_roles'),(user,member));db.execute(sql('INSERT INTO ce_forum_primary_groups'),(user,member));db.execute(sql('INSERT INTO ce_user_roles'),(user,role))
    db.execute(sql('INSERT INTO ce_forum_member_preferences'),(user,))
    db.execute(sql('INSERT INTO ce_forum_boards'),(board,cat,None,'Hidden board','',1,'forum'))
    db.execute(sql('INSERT INTO ce_forum_threads'),(thread,board,user,'Migration needle',1,1,date,date))
    db.execute(sql('INSERT INTO ce_forum_posts'),(post,thread,user,'Migration needle',1,1,date,None))
    db.execute(sql('INSERT INTO ce_forum_post_revisions'),(post,'Migration needle',user,1))
    db.execute(sql('UPDATE ce_forum_threads SET read_sequence='),(1,thread))
    data=b'hello world!';db.execute(sql('INSERT INTO ce_forum_attachments'),(attachment,user,post,'sample.txt','text/plain',len(data),hashlib.sha256(data).hexdigest(),base64.b64encode(data).decode(),1))
    db.execute(sql('INSERT INTO ce_forum_polls'),(thread,'Choose',1,0,2))
    db.execute(sql('INSERT INTO ce_forum_poll_options'),(thread,1,'Yes'));db.execute(sql('INSERT INTO ce_forum_poll_options'),(thread,2,'No'))
    db.execute(sql('INSERT INTO ce_forum_poll_votes'),(thread,user,1))
    db.execute(sql('INSERT INTO ce_forum_watches'),(thread,user));db.execute(sql('INSERT INTO ce_forum_board_watches'),(board,user))
    for kind,source,target in [('groups',4,role),('users',2,user),('forums',11,board),('topics',20,thread),('posts',30,post),('attachments',40,attachment),('polls',20,thread)]:
        db.execute(sql('INSERT INTO ce_forum_import_map'),(job,kind,source,target,'imported',''))
    db.commit()
    assert db.execute('SELECT visibility FROM ce_forum_categories WHERE id=?',(cat,)).fetchone()==('hidden',)
    assert db.execute('SELECT visibility,state,seo_index FROM ce_forum_boards WHERE id=?',(board,)).fetchone()==('hidden','readonly',0)
    assert db.execute('SELECT status FROM ce_users WHERE id=?',(user,)).fetchone()==('disabled',)
    assert db.execute('SELECT COUNT(*) FROM ce_forum_fts_posts WHERE ce_forum_fts_posts MATCH ?',('needle',)).fetchone()[0]==1
    assert db.execute('SELECT COUNT(*) FROM ce_forum_post_revisions WHERE post_id=?',(post,)).fetchone()[0]==1
    assert db.execute('SELECT COUNT(*) FROM ce_forum_extension_events').fetchone()[0]==0
    assert db.execute('SELECT COUNT(*) FROM ce_forum_mail_delivery').fetchone()[0]==0
    assert db.execute('PRAGMA foreign_key_check').fetchall()==[]
    try:
        db.execute(sql('INSERT INTO ce_forum_import_map'),(job,'posts',30,post,'imported',''))
        raise AssertionError('duplicate mapping accepted')
    except sqlite3.IntegrityError: db.rollback()
    print('PASS 0047 migration, exact import writes, FTS, revision, attachments, polls, watches, quarantine and transactional checkpoint')
