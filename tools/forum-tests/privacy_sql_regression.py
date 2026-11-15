#!/usr/bin/env python3
"""Execute the production export queries against every SQLite migration."""
import re
import sqlite3
import uuid
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
source = (ROOT / 'core/Forum/Repository/PersonalDataRepository.php').read_text()
db = sqlite3.connect(':memory:')
db.execute('PRAGMA foreign_keys=ON')
for migration in sorted((ROOT / 'migrations/sqlite').glob('*.sql')):
    db.executescript(migration.read_text())
uid = lambda: str(uuid.uuid4())
a, b = uid(), uid()
for user in (a, b):
    db.execute('INSERT INTO ce_users(id,email,name,password_hash) VALUES(?,?,?,?)', (user, user+'@example.test', 'Member', 'secret-hash'))
board = db.execute('SELECT id FROM ce_forum_boards LIMIT 1').fetchone()[0]
thread = uid()
db.execute('INSERT INTO ce_forum_threads(id,board_id,author_id,title) VALUES(?,?,?,?)', (thread, board, a, 'Shared thread'))
for n, user in enumerate((a,b),1):
    db.execute('INSERT INTO ce_forum_posts(id,thread_id,author_id,body,ordinal,read_sequence) VALUES(?,?,?,?,?,?)', (uid(),thread,user,'own' if user==a else 'foreign',n,n))
member = re.search(r"\$member = '([^']+)';",source).group(1)
queries = re.findall(r"'([a-z_]+)'=>\[(\"[^\"]*\"|'[^']*'),'([^']+)'\]",source)
assert len(queries)==32, len(queries)
for section, query, order in queries:
    query = query[1:-1].replace('$threadBoard','1=1').replace('$board','1=1').replace('$member',member)
    cursor = db.execute(query+' ORDER BY '+order+' LIMIT 26 OFFSET 0',(a,))
    rows = cursor.fetchall()
    if section=='account':
        assert len(rows)==1 and rows[0][0]==a
        assert 'password_hash' not in [x[0] for x in cursor.description]
    if section=='posts':
        assert len(rows)==1 and rows[0][2]=='own', rows
        assert not db.execute(query.replace('1=1','1=0'),(a,)).fetchall()
    if section=='threads': assert len(rows)==1 and rows[0][0]==thread
    print('PASS export',section)
# Exercise real cleanup statements and tombstone against the migrated schema.
for tables in re.findall(r"foreach\(\[([^\]]+)\] as \$table\)\s*\$this->query\(\"DELETE FROM \$table WHERE user_id=\?\"",source):
    for table in re.findall(r"'([^']+)'",tables):
        db.execute('DELETE FROM '+table+' WHERE user_id=?',(a,))
for tables in re.findall(r"foreach\(\[([^\]]+)\] as \$table\)\s*\$this->query\(\"DELETE FROM \$table WHERE recipient_id=\?\"",source):
    for table in re.findall(r"'([^']+)'",tables):
        db.execute('DELETE FROM '+table+' WHERE recipient_id=?',(a,))
for sql in re.findall(r"\$this->query\('([^']+)'",source):
    if sql.startswith('DELETE FROM'):
        db.execute(sql,(a,)*sql.count('?'))
update = re.search(r'\$this->query\("(UPDATE ce_users SET .*?)",',source).group(1).replace('$recovery',"'[]'")
db.execute(update,('closed@example.invalid','Removed','random-hash',a))
db.execute('INSERT INTO ce_forum_erased_accounts(user_id,erased_at) VALUES (?,?)',(a,1))
assert db.execute('SELECT status FROM ce_users WHERE id=?',(a,)).fetchone()==('disabled',)
assert db.execute('SELECT count(*) FROM ce_forum_posts WHERE author_id=?',(a,)).fetchone()==(1,)
assert db.execute('PRAGMA foreign_key_check').fetchall()==[]
print('PASS account tombstone and retained publications')
