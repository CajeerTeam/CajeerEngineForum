#!/usr/bin/env python3
"""SQLite source -> FTS regression with real migration triggers, visibility and drift fault injection."""
from pathlib import Path
import sqlite3
import subprocess
import json

ROOT = Path(__file__).resolve().parents[2]
conn = sqlite3.connect(':memory:')
conn.execute('PRAGMA foreign_keys=ON')
for migration in sorted((ROOT / 'migrations/sqlite').glob('*.sql')):
    conn.executescript(migration.read_text())
checks = 0

def check(condition, message):
    global checks
    assert condition, message
    checks += 1

board = conn.execute('SELECT id FROM ce_forum_boards ORDER BY id LIMIT 1').fetchone()[0]
thread = 'a1350000-1111-4111-8111-111111111111'
post = 'a1350000-2222-4222-8222-222222222222'
conn.execute('INSERT INTO ce_forum_threads(id,board_id,title) VALUES(?,?,?)', (thread, board, 'Pulsar observatory'))
conn.execute('INSERT INTO ce_forum_posts(id,thread_id,body,ordinal,read_sequence) VALUES(?,?,?,?,?)', (post, thread, 'Interstellar neutron-star alignment', 1, 1))
conn.execute('INSERT INTO ce_forum_thread_tags(thread_id,tag) VALUES(?,?)', (thread, 'stargazing'))

def hits(index, phrase):
    return conn.execute(f'SELECT COUNT(*) FROM {index} WHERE {index} MATCH ?', (f'"{phrase}"',)).fetchone()[0]

check(hits('ce_forum_fts_threads', 'pulsar') == 1, 'new title indexed')
check(hits('ce_forum_fts_posts', 'interstellar') == 1, 'new post indexed')
check(hits('ce_forum_fts_tags', 'stargazing') == 1, 'new tag indexed')
conn.execute('UPDATE ce_forum_threads SET title=? WHERE id=?', ('Quasar observatory', thread))
conn.execute('UPDATE ce_forum_posts SET body=? WHERE id=?', ('Celestial supercluster', post))
conn.execute('UPDATE ce_forum_thread_tags SET tag=? WHERE thread_id=?', ('cosmology', thread))
check(hits('ce_forum_fts_threads', 'pulsar') == 0 and hits('ce_forum_fts_threads', 'quasar') == 1, 'title edit removes stale terms')
check(hits('ce_forum_fts_posts', 'interstellar') == 0 and hits('ce_forum_fts_posts', 'supercluster') == 1, 'post edit removes stale terms')
check(hits('ce_forum_fts_tags', 'stargazing') == 0 and hits('ce_forum_fts_tags', 'cosmology') == 1, 'tag edit removes stale terms')
conn.execute('UPDATE ce_forum_posts SET deleted_at=1234 WHERE id=?', (post,))
check(conn.execute('SELECT COUNT(*) FROM ce_forum_live_posts WHERE id=?', (post,)).fetchone()[0] == 0, 'soft deleted post excluded from live search')
conn.execute('UPDATE ce_forum_posts SET deleted_at=NULL WHERE id=?', (post,))
check(conn.execute('SELECT COUNT(*) FROM ce_forum_live_posts WHERE id=?', (post,)).fetchone()[0] == 1, 'restored post included')
conn.execute('DROP TRIGGER ce_forum_fts_posts_au')
conn.execute('UPDATE ce_forum_posts SET body=? WHERE id=?', ('Celestial pulsation restored', post))
check(hits('ce_forum_fts_posts', 'supercluster') == 1 and hits('ce_forum_fts_posts', 'pulsation') == 0, 'fault injection produces stale index')
check(conn.execute("SELECT COUNT(*) FROM sqlite_master WHERE type='trigger' AND name='ce_forum_fts_posts_au'").fetchone()[0] == 0, 'lost trigger detectable')
check(conn.execute("SELECT COUNT(*) FROM ce_forum_posts s JOIN ce_forum_fts_keys k ON k.kind='post' AND k.source_id=s.id JOIN ce_forum_fts_posts f ON f.rowid=k.id WHERE f.body IS NOT s.body").fetchone()[0] == 1, 'stale source detectable by consistency SQL')
# Execute the *actual PHP trigger generator* using a recording PDO subclass.
# This checks that the PHP repair code emits SQLite-valid, functional SQL even
# on minimal local machines without the pdo_sqlite extension.
php = r'''
require 'core/Forum/Service/ForumSearchMaintenance.php';
class RecorderPDO extends PDO {
    public array $statements=[];
    public function __construct(){}
    public function exec(string $sql): int|false { $this->statements[]=$sql; return 0; }
}
$p=new RecorderPDO();
$o=(new ReflectionClass(CajeerEngine\Forum\Service\ForumSearchMaintenance::class))->newInstanceWithoutConstructor();
$m=new ReflectionMethod($o,'createSqliteTriggers');
foreach([
    ['thread','ce_forum_threads','ce_forum_fts_threads','id','title,prefix'],
    ['post','ce_forum_posts','ce_forum_fts_posts','id','body'],
    ['tag','ce_forum_thread_tags','ce_forum_fts_tags','thread_id','tag'],
] as $args)$m->invoke($o,$p,...$args);
echo json_encode($p->statements,JSON_THROW_ON_ERROR);
'''
restored_sql = json.loads(subprocess.check_output(['php', '-r', php], cwd=ROOT, text=True))
check(len(restored_sql) == 9, 'PHP emits all nine recovery triggers')
for kind, table, index, id_column, columns in [
    ('thread', 'ce_forum_threads', 'ce_forum_fts_threads', 'id', 'title,prefix'),
    ('post', 'ce_forum_posts', 'ce_forum_fts_posts', 'id', 'body'),
    ('tag', 'ce_forum_thread_tags', 'ce_forum_fts_tags', 'thread_id', 'tag'),
]:
    for suffix in ['ai','ad','au']:
        conn.execute(f'DROP TRIGGER IF EXISTS {index}_{suffix}')
    conn.execute(f'DELETE FROM {index}')
    conn.execute('DELETE FROM ce_forum_fts_keys WHERE kind=?', (kind,))
    tag = 'tag' if kind == 'tag' else "''"
    conn.execute(f"INSERT INTO ce_forum_fts_keys(kind,source_id,tag) SELECT '{kind}',{id_column},{tag} FROM {table}")
    join = f"k.kind='{kind}' AND k.source_id=s.{id_column}" + (' AND k.tag=s.tag' if kind == 'tag' else '')
    values = ','.join('s.' + c for c in columns.split(','))
    conn.execute(f'INSERT INTO {index}(rowid,{columns}) SELECT k.id,{values} FROM {table} s JOIN ce_forum_fts_keys k ON {join}')
for statement in restored_sql:
    conn.execute(statement)
check(hits('ce_forum_fts_posts', 'supercluster') == 0 and hits('ce_forum_fts_posts', 'pulsation') == 1, 'real PHP-trigger repair backfills current text')
conn.execute('UPDATE ce_forum_posts SET body=? WHERE id=?', ('Celestial pulsar repaired', post))
check(hits('ce_forum_fts_posts', 'pulsation') == 0 and hits('ce_forum_fts_posts', 'pulsar') == 1, 'generated post update trigger syncs edits')
conn.execute('UPDATE ce_forum_threads SET title=? WHERE id=?', ('Nebula observatory', thread))
check(hits('ce_forum_fts_threads', 'quasar') == 0 and hits('ce_forum_fts_threads', 'nebula') == 1, 'generated thread update trigger syncs edits')
conn.execute('UPDATE ce_forum_thread_tags SET tag=? WHERE thread_id=?', ('astrobiology', thread))
check(hits('ce_forum_fts_tags', 'cosmology') == 0 and hits('ce_forum_fts_tags', 'astrobiology') == 1, 'generated tag update trigger syncs edits')
reply = 'a1350000-3333-4333-8333-333333333333'
conn.execute('INSERT INTO ce_forum_posts(id,thread_id,body,ordinal,read_sequence) VALUES(?,?,?,?,?)', (reply,thread,'Multiverse nebular evidence',2,2))
check(hits('ce_forum_fts_posts','multiverse') == 1, 'generated insert trigger handles new reply')
conn.execute('DELETE FROM ce_forum_posts WHERE id=?', (reply,))
check(hits('ce_forum_fts_posts','multiverse') == 0, 'generated delete trigger removes reply')
conn.execute('DELETE FROM ce_forum_threads WHERE id=?', (thread,))
check(hits('ce_forum_fts_threads', 'nebula') == 0, 'purge removes thread index')
check(hits('ce_forum_fts_tags', 'astrobiology') == 0, 'purge removes tag index')
check(conn.execute('PRAGMA foreign_key_check').fetchall() == [], 'foreign key integrity preserved')
print(f'Forum FTS sync SQLite: {checks} PASS, {len(list((ROOT / "migrations/sqlite").glob("*.sql")))} migrations')
