#!/usr/bin/env python3
"""Community integrity and recovery on real SQLite; executes production audit SQL and PHP image validator."""
import json
from pathlib import Path
import re
import sqlite3
import subprocess
import tempfile
import uuid

ROOT=Path(__file__).resolve().parents[2]
uid=lambda: str(uuid.uuid4())
# A 2x2 JPEG generated from a plain RGB image; no GD or Pillow needed by the test.
JPEG='/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAUDBAQEAwUEBAQFBQUGBwwIBwcHBw8LCwkMEQ8SEhEPERETFhwXExQaFRERGCEYGh0dHx8fExciJCIeJBweHx7/2wBDAQUFBQcGBw4ICA4eFBEUHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh7/wAARCAACAAIDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwD5iooor2Dyj//Z'


def connection(path):
    db=sqlite3.connect(path);db.execute('PRAGMA foreign_keys=ON');return db


def audit(db):
    source=(ROOT/'core/Forum/ForumIntegrityCheck.php').read_text()
    queries=dict((name,sql) for name,_,sql in re.findall(r"^\s*'([a-z_]+)'=>\s*(['\"])(.*?)\2,\s*$",source,re.M))
    assert 'orphan_reaction_notification' in queries and 'invalid_conversation_limits' in queries
    result={key:db.execute(sql).fetchone()[0] for key,sql in queries.items()}
    images=db.execute('SELECT kind,image_data FROM ce_forum_profile_images').fetchall()
    code=r'''require "core/Forum/Security/ProfileImage.php"; $rows=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$bad=0;foreach($rows as [$kind,$data])if(CajeerEngine\Forum\Security\ProfileImage::decodeStored($kind,$data)===null)$bad++;echo $bad;'''
    result['invalid_profile_images']=int(subprocess.check_output(['php','-r',code],input=json.dumps(images).encode(),cwd=ROOT))
    return {k:v for k,v in result.items() if v}


def snapshot(db):
    tables=['ce_forum_member_profiles','ce_forum_member_preferences','ce_forum_profile_images','ce_forum_threads','ce_forum_posts','ce_forum_read_state','ce_forum_notifications','ce_forum_watches','ce_forum_mail_delivery','ce_forum_conversations','ce_forum_conversation_participants','ce_forum_conversation_messages','ce_forum_reactions','ce_forum_member_relations','ce_forum_achievement_rules']
    return {t:sorted(db.execute('SELECT * FROM '+t).fetchall(),key=repr) for t in tables}


with tempfile.TemporaryDirectory(prefix='forum-community-sql-') as directory:
    root=Path(directory);db=connection(root/'live.sqlite')
    files=sorted((ROOT/'migrations/sqlite').glob('*.sql'))
    for f in files:db.executescript(f.read_text())
    a,b,thread,post,reply,event,conversation,message,delivery=[uid() for _ in range(9)]
    board='22222222-2222-4222-8222-222222222222'
    for user in (a,b):db.execute('INSERT INTO ce_users(id,email,name,password_hash,email_verified_at) VALUES(?,?,?,?,CURRENT_TIMESTAMP)',(user,user+'@example.test','Member','test'))
    db.execute('INSERT INTO ce_forum_member_profiles(user_id,bio) VALUES(?,?)',(a,'Community bio'))
    db.execute("INSERT INTO ce_forum_member_preferences(user_id,theme) VALUES(?,'dark')",(a,))
    db.execute("INSERT INTO ce_forum_profile_images(user_id,kind,image_data) VALUES(?,'avatar',?)",(a,JPEG))
    db.execute('INSERT INTO ce_forum_threads(id,board_id,author_id,title,read_sequence) VALUES(?,?,?,?,2)',(thread,board,a,'Community topic'))
    db.execute('INSERT INTO ce_forum_posts(id,thread_id,author_id,body,ordinal,read_sequence) VALUES(?,?,?,?,1,1)',(post,thread,a,'First'))
    db.execute('INSERT INTO ce_forum_posts(id,thread_id,author_id,body,ordinal,read_sequence) VALUES(?,?,?,?,2,2)',(reply,thread,b,'Second'))
    db.execute('INSERT INTO ce_forum_read_state(user_id,thread_id,read_sequence) VALUES(?,?,1)',(a,thread))
    db.execute("INSERT INTO ce_forum_reactions(post_id,user_id,reaction_type) VALUES(?,?,'helpful')",(post,b))
    db.execute("INSERT INTO ce_forum_notifications(event_id,recipient_id,actor_id,post_id,kind,state) VALUES(?,?,?,?,'reaction',1)",(event,a,b,post))
    db.execute("INSERT INTO ce_forum_watches(thread_id,user_id,mode) VALUES(?,?,'weekly')",(thread,a))
    db.execute("INSERT INTO ce_forum_mail_delivery(id,user_id,post_id,board_id,source_kind,mode,due_at) VALUES(?,?,?,?,'thread','weekly',9999999999)",(delivery,a,reply,board))
    db.execute("INSERT INTO ce_forum_conversations(id,owner_id,title,kind,last_sequence,created_at,updated_at) VALUES(?,?,?,'direct',1,1,1)",(conversation,a,'Private'))
    for user,read,muted in [(a,1,0),(b,0,1)]:db.execute('INSERT INTO ce_forum_conversation_participants(conversation_id,user_id,joined_sequence,read_sequence,muted) VALUES(?,?,1,?,?)',(conversation,user,read,muted))
    db.execute('INSERT INTO ce_forum_conversation_messages(id,conversation_id,author_id,message_sequence,body,created_at) VALUES(?,?,?,1,?,1)',(message,conversation,a,'Private message'))
    db.execute("INSERT INTO ce_forum_member_relations(user_id,target_id,kind) VALUES(?,?,'follow')",(b,a));db.commit()
    assert audit(db)=={},audit(db)
    before=snapshot(db)
    # Exercise SQLite's actual backup/restore, including pending queue data and private history.
    backup=connection(root/'backup.sqlite');db.backup(backup)
    db.execute('DELETE FROM ce_forum_reactions WHERE post_id=?',(post,))
    assert audit(db)=={'orphan_reaction_notification':1},audit(db)
    db.rollback()
    db.execute("UPDATE ce_forum_notifications SET actor_id=recipient_id WHERE event_id=?",(event,))
    assert audit(db)=={'invalid_notification_recipient':1,'orphan_reaction_notification':1},audit(db)
    db.rollback()
    db.execute("UPDATE ce_forum_profile_images SET image_data='not-an-image' WHERE user_id=?",(a,))
    db.execute("INSERT INTO ce_forum_conversation_limits(user_id,action,window_start,hits) VALUES(?,'bogus',1,1)",(a,))
    assert audit(db)=={'invalid_conversation_limits':1,'invalid_profile_images':1},audit(db)
    db.commit();backup.backup(db);backup.close()
    assert snapshot(db)==before and audit(db)=={}
    assert db.execute('PRAGMA foreign_key_check').fetchall()==[] and db.execute('PRAGMA integrity_check').fetchone()==('ok',)
    db.close()
    print(f'PASS {len(files)} migrations; community audit, restored JPEG, corrupt notifications/limits, SQLite backup/restore and data equality')
