#!/usr/bin/env python3
"""Physical 0048 -> 0049 upgrade with live-sized fixture, access and query-plan checks.

This deliberately uses sqlite3 without PHP/vendor; PostgreSQL has its own CI
physical test in tools/forum-performance-pg-regression.php.
"""
import hashlib
import sqlite3
import uuid
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
DB = sqlite3.connect(':memory:')
DB.execute('PRAGMA foreign_keys=ON')
DB.execute('PRAGMA temp_store=FILE')
MIGRATIONS = sorted((ROOT / 'migrations/sqlite').glob('*.sql'))
BASELINE = [p for p in MIGRATIONS if p.name <= '0048_forum_observability_indexes.sql']
assert BASELINE[-1].name == '0048_forum_observability_indexes.sql'
for migration in BASELINE:
    DB.executescript(migration.read_text())

# Known real schema and foreign keys: 8k topics, 24k posts, 4.5k notices,
# deleted content, two ACL scopes and multiple recipients.
public = '22222222-2222-4222-8222-222222222222'
hidden = str(uuid.UUID(int=900001))
category = '11111111-1111-4111-8111-111111111111'
reader, actor, unrelated = [str(uuid.UUID(int=i)) for i in range(1001, 1004)]
DB.execute('INSERT INTO ce_forum_boards(id,category_id,name,description) VALUES(?,?,?,?)',
           (hidden, category, 'Hidden', ''))
DB.executemany('INSERT INTO ce_users(id,email,name,password_hash) VALUES(?,?,?,?)',
               [(u, f'user-{i}@example.test', f'User {i}', 'unused')
                for i, u in enumerate((reader, actor, unrelated))])

thread_rows, post_rows, notification_rows, accepted_rows = [], [], [], []
for n in range(1, 8001):
    thread = str(uuid.UUID(int=200000+n))
    board = hidden if n % 17 == 0 else public
    when = f'2026-11-01 00:{n//160:02d}:{n%60:02d}'
    thread_rows.append((thread, board, actor, f'FTS topic {n}', when, 3, 1 if n % 109 == 0 else None))
    for ordinal in range(1, 4):
        post = str(uuid.UUID(int=500000+n*4+ordinal))
        post_rows.append((post, thread, actor, f'Post {ordinal} for topic {n}', ordinal, ordinal,
                          1 if n % 109 == 0 or (ordinal == 2 and n % 31 == 0) else None))
        if ordinal == 1 and n <= 1800:
            notification_rows.append((str(uuid.UUID(int=900000+n)),
                                     reader if n % 5 else unrelated, actor, post, 'reply', 1))
        if ordinal == 3 and n <= 2500:
            accepted_rows.append((str(uuid.UUID(int=950000+n)),
                                  reader if n % 5 else unrelated, actor, post, 1))
DB.executemany('INSERT INTO ce_forum_threads(id,board_id,author_id,title,updated_at,read_sequence,deleted_at) VALUES(?,?,?,?,?,?,?)',thread_rows)
DB.executemany('INSERT INTO ce_forum_posts(id,thread_id,author_id,body,ordinal,read_sequence,deleted_at) VALUES(?,?,?,?,?,?,?)',post_rows)
DB.executemany('INSERT INTO ce_forum_notifications(event_id,recipient_id,actor_id,post_id,kind,state) VALUES(?,?,?,?,?,?)',notification_rows)
DB.executemany('INSERT INTO ce_forum_answer_notifications(event_id,recipient_id,actor_id,post_id,state) VALUES(?,?,?,?,?)',accepted_rows)
DB.executemany('INSERT INTO ce_forum_read_state(user_id,thread_id,read_sequence) VALUES(?,?,?)',
               [(reader, row[0], 1) for row in thread_rows[::17]])
DB.commit()


def fingerprint():
    """Stable, streamed fingerprints of real rows, not a schema-only comparison."""
    result = {}
    for table in ('ce_forum_threads', 'ce_forum_posts', 'ce_forum_notifications',
                  'ce_forum_answer_notifications', 'ce_forum_read_state'):
        digest = hashlib.sha256()
        count = 0
        for row in DB.execute(f'SELECT * FROM {table} ORDER BY rowid'):
            digest.update(repr(row).encode('utf-8'))
            digest.update(b'\0')
            count += 1
        result[table] = (count, digest.hexdigest())
    return result


before = fingerprint()
assert before['ce_forum_posts'][0] == 24000
assert before['ce_forum_threads'][0] == 8000
migration = ROOT / 'migrations/sqlite/0049_forum_database_optimization.sql'
DB.executescript(migration.read_text())
DB.execute('ANALYZE')
after = fingerprint()
assert before == after, (before, after)
assert not DB.execute('PRAGMA foreign_key_check').fetchall()
indexes = {row[0] for row in DB.execute("SELECT name FROM sqlite_master WHERE type='index'")}
for index in ('ce_forum_answer_delivery_idx', 'ce_forum_unread_board_idx'):
    assert index in indexes, index


def plan(sql, params=()):
    return ' '.join(str(part) for row in DB.execute('EXPLAIN QUERY PLAN ' + sql, params) for part in row)


for name, sql, args, expected in [
    ('notification', 'SELECT event_id FROM ce_forum_notifications WHERE recipient_id=? AND state=1 ORDER BY created_at DESC,event_id DESC LIMIT 25', (reader,), 'ce_forum_notify_page'),
    ('accepted', 'SELECT event_id FROM ce_forum_answer_notifications WHERE recipient_id=? AND state=1 ORDER BY created_at DESC,event_id DESC LIMIT 25', (reader,), 'ce_forum_answer_delivery_idx'),
    ('unread', 'SELECT id FROM ce_forum_live_threads WHERE board_id=? ORDER BY updated_at DESC,id LIMIT 25', (public,), 'ce_forum_unread_board_idx'),
]:
    actual = plan(sql, args)
    print(name, actual)
    assert expected in actual, (name, actual)

# This is the actual SQL shape of the PHP inbox query: TWO independently bound
# recipient predicates (not one full-history UNION followed by a late filter).
feed = """(SELECT event_id,recipient_id,actor_id,post_id,kind,moderation_action,subscription_kind,state,is_read,created_at
FROM ce_forum_notifications WHERE recipient_id=? AND state=1
UNION ALL SELECT event_id,recipient_id,actor_id,post_id,'accepted' AS kind,NULL AS moderation_action,NULL AS subscription_kind,state,is_read,created_at
FROM ce_forum_answer_notifications WHERE recipient_id=? AND state=1)"""
visible = f""" FROM {feed} n JOIN ce_forum_live_posts p ON p.id=n.post_id
JOIN ce_forum_live_threads t ON t.id=p.thread_id
WHERE (n.kind='moderation' OR NOT EXISTS (SELECT 1 FROM ce_forum_user_blocks b
WHERE (b.user_id=n.recipient_id AND b.blocked_id=n.actor_id)
OR (b.blocked_id=n.recipient_id AND b.user_id=n.actor_id))) AND t.board_id=?"""
inbox_sql = 'SELECT n.event_id,n.recipient_id,n.kind,n.post_id' + visible + ' ORDER BY n.created_at DESC,n.event_id DESC LIMIT 25'
notices = DB.execute(inbox_sql, (reader, reader, public)).fetchall()
assert notices and len(notices) == 25
assert all(row[1] == reader for row in notices)
assert {'reply', 'accepted'} == {row[2] for row in DB.execute(
    'SELECT n.event_id,n.recipient_id,n.kind,n.post_id' + visible, (reader, reader, public))}
assert not DB.execute(inbox_sql, (reader, reader, str(uuid.uuid4()))).fetchall()
assert 'ce_forum_notify_page' in plan(
    'SELECT event_id FROM ce_forum_notifications WHERE recipient_id=? AND state=1 ORDER BY created_at DESC,event_id DESC LIMIT 25', (reader,))

# Count the actual unread rows, not a thread counter that includes deleted holes.
thread = thread_rows[1][0]
DB.execute('INSERT INTO ce_forum_read_state(user_id,thread_id,read_sequence) VALUES(?,?,?)', (reader, thread, 1))
query = """SELECT COUNT(*) FROM ce_forum_live_posts p
WHERE p.thread_id=? AND p.read_sequence>(SELECT read_sequence FROM ce_forum_read_state WHERE user_id=? AND thread_id=?)"""
assert DB.execute(query, (thread, reader, thread)).fetchone()[0] == 2

# A blocked actor cannot leak through the inbox, including accepted-answer arm.
DB.execute('INSERT INTO ce_forum_user_blocks(user_id,blocked_id) VALUES(?,?)', (reader, actor))
assert not DB.execute(inbox_sql, (reader, reader, public)).fetchall()
assert DB.execute(inbox_sql, (unrelated, unrelated, public)).fetchall()

# Mass marking uses original snapshot IDs and fresh authorization. The exact
# SQLite upsert shape must not regress past read_sequence or mark hidden topics.
source = DB.execute('SELECT id FROM ce_forum_live_threads WHERE board_id=? ORDER BY id', (public,)).fetchall()
assert len(source) > 7000
for start in range(0, len(source), 500):
    ids = [row[0] for row in source[start:start+500]]
    marks = ','.join('?' for _ in ids)
    statement = f'''INSERT INTO ce_forum_read_state(user_id,thread_id,read_sequence)
    SELECT ?,t.id,t.read_sequence FROM ce_forum_live_threads t WHERE t.id IN ({marks})
    ON CONFLICT (user_id,thread_id) DO UPDATE SET read_sequence=CASE
    WHEN excluded.read_sequence>ce_forum_read_state.read_sequence
    THEN excluded.read_sequence ELSE ce_forum_read_state.read_sequence END'''
    DB.execute(statement, (reader, *ids))
assert DB.execute('SELECT COUNT(*) FROM ce_forum_read_state WHERE user_id=?', (reader,)).fetchone()[0] >= 7000
assert not DB.execute('SELECT 1 FROM ce_forum_read_state r JOIN ce_forum_threads t ON t.id=r.thread_id WHERE r.user_id=? AND t.board_id=?', (reader, hidden)).fetchone()
assert DB.execute(query, (thread, reader, thread)).fetchone()[0] == 0
assert not DB.execute('PRAGMA foreign_key_check').fetchall()
print('PASS: populated 0048 -> 0049 upgrade, unchanged source data, 3 index plans, union recipient ACL, unread, 7k+ batched marks')
