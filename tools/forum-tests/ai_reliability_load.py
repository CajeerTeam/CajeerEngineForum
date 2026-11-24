#!/usr/bin/env python3
"""Exercise the admission SQL under concurrent independent SQLite connections.
This is a database contention test, not an OpenAI/PHP HTTP throughput benchmark.
"""
import concurrent.futures
import sqlite3
import tempfile
import threading
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
ACTIVE, WAITING = 4, 16

with tempfile.TemporaryDirectory() as temp:
    path = str(Path(temp) / 'admission.sqlite')
    def connect():
        db = sqlite3.connect(path, timeout=15, isolation_level=None)
        db.execute('PRAGMA foreign_keys=ON')
        return db
    with connect() as db:
        db.execute('PRAGMA journal_mode=WAL')
        db.execute('CREATE TABLE ce_users (id VARCHAR(36) PRIMARY KEY)')
        for name in ('0084_forum_ai_chat.sql','0085_forum_thread_ai.sql','0086_forum_ai_search.sql','0090_forum_ai_privacy_abuse.sql','0091_forum_ai_reliability.sql'):
            db.executescript((ROOT / 'migrations/sqlite' / name).read_text())
        db.executemany('INSERT INTO ce_users VALUES (?)', [(str(i),) for i in range(64)])
    barrier = threading.Barrier(64)
    maximum = [0, 0]
    order = []
    def request(i):
        ticket = f'{i:032x}'
        db = connect()
        barrier.wait(timeout=15)
        # Keep admitted work active until enough requests have queued/rejected.
        deadline = time.monotonic() + 8
        try:
            while time.monotonic() < deadline:
                db.execute('BEGIN IMMEDIATE')
                db.execute('UPDATE ce_forum_ai_admission_state SET cooldown_until=cooldown_until WHERE id=1')
                now = int(time.time())
                db.execute('DELETE FROM ce_forum_ai_admission WHERE expires_at<=?', (now,))
                exists = db.execute('SELECT id FROM ce_forum_ai_admission WHERE user_id=?', (str(i),)).fetchone()
                if not exists:
                    if db.execute('SELECT COUNT(*) FROM ce_forum_ai_admission WHERE active=0').fetchone()[0] >= WAITING:
                        db.commit()
                        return 'full'
                    db.execute('UPDATE ce_forum_ai_admission_state SET sequence_no=sequence_no+1 WHERE id=1')
                    seq = db.execute('SELECT sequence_no FROM ce_forum_ai_admission_state WHERE id=1').fetchone()[0]
                    db.execute('INSERT INTO ce_forum_ai_admission VALUES (?,?,?,0,?)',(ticket,str(i),seq,now+60))
                active = db.execute('SELECT COUNT(*) FROM ce_forum_ai_admission WHERE active=1').fetchone()[0]
                waiting = db.execute('SELECT COUNT(*) FROM ce_forum_ai_admission WHERE active=0').fetchone()[0]
                maximum[1] = max(maximum[1], waiting)
                head = db.execute('SELECT id,sequence_no FROM ce_forum_ai_admission WHERE active=0 ORDER BY sequence_no LIMIT 1').fetchone()
                if active < ACTIVE and head and head[0] == ticket:
                    db.execute('UPDATE ce_forum_ai_admission SET active=1,expires_at=? WHERE id=?',(now+240,ticket))
                    maximum[0] = max(maximum[0], active+1)
                    order.append(head[1])
                    db.commit()
                    time.sleep(.18)
                    return 'ok'
                db.commit()
                time.sleep(.02)
            return 'timeout'
        finally:
            if db.in_transaction: db.rollback()
            db.execute('BEGIN IMMEDIATE')
            db.execute('DELETE FROM ce_forum_ai_admission WHERE id=?',(ticket,))
            db.commit()
            db.close()
    with concurrent.futures.ThreadPoolExecutor(max_workers=64) as pool:
        result = list(pool.map(request, range(64)))
    assert maximum[0] <= ACTIVE and maximum[0] > 1, maximum
    assert maximum[1] <= WAITING, maximum
    assert order == sorted(order), order
    assert result.count('ok') >= ACTIVE, result
    with connect() as db:
        assert db.execute('SELECT COUNT(*) FROM ce_forum_ai_admission').fetchone()[0] == 0
        db.execute('INSERT INTO ce_forum_ai_admission VALUES (?,?,?,?,?)',('crash','0',99999,1,int(time.time())-1))
        assert db.execute('DELETE FROM ce_forum_ai_admission WHERE expires_at<=?',(int(time.time()),)).rowcount == 1
        db.execute('INSERT INTO ce_forum_ai_admission VALUES (?,?,?,?,?)',('owner','1',100000,1,int(time.time())+240))
        db.execute('DELETE FROM ce_users WHERE id=?',('1',))
        assert db.execute('SELECT COUNT(*) FROM ce_forum_ai_admission').fetchone()[0] == 0
    print(f'64 concurrent clients: {result.count("ok")} admitted, {result.count("full")} queue-full, {result.count("timeout")} timed out; peak active {maximum[0]}, peak waiting {maximum[1]}; FIFO, cleanup, FK cascade PASS')
