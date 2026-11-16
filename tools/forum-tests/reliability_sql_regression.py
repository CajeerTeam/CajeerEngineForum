#!/usr/bin/env python3
"""Concurrent real SQLite schema/FTS/cache-generation transactions; no PHP extensions required."""
import argparse
import concurrent.futures
import json
import math
import multiprocessing
import os
from pathlib import Path
import sqlite3
import tempfile
import time
import uuid

ROOT = Path(__file__).resolve().parents[2]
BOARD = '22222222-2222-4222-8222-222222222222'
USER = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'


def connect(path):
    db = sqlite3.connect(path, timeout=15, isolation_level=None)
    db.execute('PRAGMA foreign_keys=ON')
    return db


def thread(i): return str(uuid.UUID(int=10000+i))


def run_worker(path, worker, count, threads):
    db=connect(path); times=[]; writes=[]
    for i in range(count):
        start=time.perf_counter(); tid=thread((worker*count+i)%threads)
        if i%5==0:
            db.execute('BEGIN IMMEDIATE')
            try:
                ordinal=db.execute('SELECT COALESCE(MAX(ordinal),0)+1 FROM ce_forum_posts WHERE thread_id=?',(tid,)).fetchone()[0]
                seq=db.execute('UPDATE ce_forum_threads SET read_sequence=read_sequence+1 WHERE id=? RETURNING read_sequence',(tid,)).fetchone()[0]
                marker=f'reliability worker {worker} write {i}'
                pid=str(uuid.uuid4())
                db.execute('INSERT INTO ce_forum_posts(id,thread_id,author_id,body,ordinal,read_sequence) VALUES(?,?,?,?,?,?)',(pid,tid,USER,marker,ordinal,seq))
                db.execute("INSERT INTO ce_forum_post_revisions(post_id,revision_number,body,body_format,editor_id,edit_reason,created_at) VALUES(?,1,?,'plain',?,'Qualification',?)",(pid,marker,USER,int(time.time())))
                db.execute('UPDATE ce_forum_threads SET updated_at=CURRENT_TIMESTAMP WHERE id=?',(tid,));db.commit();writes.append((pid,marker))
            except BaseException: db.rollback();raise
            kind='write'
        else:
            db.execute('BEGIN')
            # Same snapshot for generation and live content, even during concurrent writes.
            before=db.execute("SELECT generation FROM ce_forum_cache_generation_effective WHERE name='ce_forum_posts'").fetchone()
            rows=db.execute('SELECT id,body FROM ce_forum_live_posts WHERE thread_id=? ORDER BY ordinal LIMIT 25',(tid,)).fetchall()
            assert rows and len({r[0] for r in rows})==len(rows)
            assert db.execute("SELECT generation FROM ce_forum_cache_generation_effective WHERE name='ce_forum_posts'").fetchone()==before
            db.commit();kind='read'
        times.append((kind,(time.perf_counter()-start)*1000))
    db.close();return times,writes


def crash(path):
    db=connect(path);db.execute('BEGIN IMMEDIATE')
    db.execute('UPDATE ce_forum_threads SET title=? WHERE id=?',('UNCOMMITTED',thread(0)))
    os._exit(17)


def main():
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('--threads',type=int,default=1000);p.add_argument('--posts-per-thread',type=int,default=10);p.add_argument('--workers',type=int,default=8);p.add_argument('--operations',type=int,default=80);p.add_argument('--report',type=Path)
    a=p.parse_args();assert 1<=a.threads<=100000 and 1<=a.posts_per_thread<=100 and a.threads*a.posts_per_thread<=1000000 and 2<=a.workers<=32 and 5<=a.operations<=1000
    with tempfile.TemporaryDirectory(prefix='forum-reliability-') as tmp:
        path=str(Path(tmp)/'forum.sqlite');db=connect(path)
        db.execute('PRAGMA journal_mode=WAL')
        for f in sorted((ROOT/'migrations/sqlite').glob('*.sql')):db.executescript(f.read_text())
        db.execute('BEGIN IMMEDIATE')
        db.execute('INSERT INTO ce_users(id,email,name,password_hash) VALUES(?,?,?,?)',(USER,'load@example.test','Load','unused'))
        db.commit();seed_start=time.perf_counter()
        for begin in range(0,a.threads,100):
            db.execute('BEGIN IMMEDIATE');end=min(a.threads,begin+100)
            db.executemany('INSERT INTO ce_forum_threads(id,board_id,author_id,title,read_sequence) VALUES(?,?,?,?,?)',((thread(i),BOARD,USER,f'Reliability {i}',a.posts_per_thread) for i in range(begin,end)))
            rows=[(str(uuid.uuid4()),thread(i),USER,f'loadmarker seed {i} {j}',j,j) for i in range(begin,end) for j in range(1,a.posts_per_thread+1)]
            db.executemany('INSERT INTO ce_forum_posts(id,thread_id,author_id,body,ordinal,read_sequence) VALUES(?,?,?,?,?,?)',rows)
            db.executemany("INSERT INTO ce_forum_post_revisions(post_id,revision_number,body,body_format,editor_id,edit_reason,created_at) VALUES(?,1,?,'plain',?,'Qualification seed',?)",((r[0],r[3],USER,int(time.time())) for r in rows));db.commit()
        seed_elapsed=time.perf_counter()-seed_start;db.close()
        start=time.perf_counter()
        with concurrent.futures.ProcessPoolExecutor(max_workers=a.workers) as pool:
            results=[f.result() for f in [pool.submit(run_worker,path,i,a.operations,a.threads) for i in range(a.workers)]]
        elapsed=time.perf_counter()-start;times=[t for r,_ in results for t in r];writes=[w for _,r in results for w in r]
        db=connect(path)
        expected_writes=a.workers*((a.operations+4)//5);assert len(writes)==expected_writes and len({i for i,_ in writes})==expected_writes
        assert db.execute('SELECT COUNT(*) FROM ce_forum_live_posts').fetchone()[0]==a.threads*a.posts_per_thread+expected_writes
        for pid,marker in writes:assert db.execute('SELECT body FROM ce_forum_live_posts WHERE id=?',(pid,)).fetchone()==(marker,)
        assert not db.execute('SELECT thread_id,ordinal FROM ce_forum_posts GROUP BY thread_id,ordinal HAVING COUNT(*)>1').fetchall()
        assert not db.execute('PRAGMA foreign_key_check').fetchall()
        assert db.execute('SELECT COUNT(*) FROM ce_forum_post_revisions').fetchone()[0]==a.threads*a.posts_per_thread+expected_writes
        assert db.execute("SELECT COUNT(*) FROM ce_forum_fts_posts WHERE ce_forum_fts_posts MATCH 'loadmarker'").fetchone()[0]==a.threads*a.posts_per_thread
        db.execute("INSERT INTO ce_forum_fts_posts(ce_forum_fts_posts) VALUES('integrity-check')")
        generation=db.execute("SELECT generation FROM ce_forum_cache_generation_effective WHERE name='ce_forum_threads'").fetchone()
        process=multiprocessing.Process(target=crash,args=(path,));process.start();process.join(15)
        assert process.exitcode==17
        assert db.execute('SELECT title FROM ce_forum_threads WHERE id=?',(thread(0),)).fetchone()==('Reliability 0',)
        assert db.execute("SELECT generation FROM ce_forum_cache_generation_effective WHERE name='ce_forum_threads'").fetchone()==generation
        db.execute('UPDATE ce_forum_threads SET title=? WHERE id=?',('Recovered',thread(0)))
        assert db.execute('SELECT title FROM ce_forum_threads WHERE id=?',(thread(0),)).fetchone()==('Recovered',)
        db.close()
        def stats(kind):
            v=sorted(ms for k,ms in times if k==kind)
            return {'count':len(v),'p95_ms':round(v[math.ceil(.95*len(v))-1],3),'p99_ms':round(v[math.ceil(.99*len(v))-1],3)}
        result={'seed_threads':a.threads,'seed_posts':a.threads*a.posts_per_thread,'seed_seconds':round(seed_elapsed,3),'processes':a.workers,'operations':len(times),'elapsed_seconds':round(elapsed,3),'rps':round(len(times)/elapsed,2),'reads':stats('read'),'writes':stats('write'),'confirmed_writes':len(writes),'crash_rollback':True,'fts_integrity':True,'revision_parity':True};print(json.dumps(result,indent=2))
        if a.report: a.report.write_text(json.dumps(result,indent=2)+'\n')


if __name__=='__main__':main()
