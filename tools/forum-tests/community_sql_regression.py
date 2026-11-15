#!/usr/bin/env python3
"""Community integrity and recovery on real SQLite; executes production audit SQL and PHP image validator."""
import json
import base64
import hashlib
import time
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
    # The same PHP validators used by the offline integrity gate; no private text in the report.
    drafts=[dict(zip(['scope','target_id','payload','result_json'],r)) for r in db.execute('SELECT scope,target_id,payload,result_json FROM ce_forum_drafts')]
    replicas=[dict(zip(['attachment_id','destination','object_key','sha256'],r)) for r in db.execute('SELECT r.attachment_id,r.destination,r.object_key,a.sha256 FROM ce_forum_media_replicas r LEFT JOIN ce_forum_attachments a ON a.id=r.attachment_id')]
    code=r'''require "core/Forum/ForumException.php";require "core/Forum/Content/ForumContentRenderer.php";require "core/Forum/Content/ContentIntegrity.php";$rows=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);$bad=["invalid_draft_payload"=>0,"invalid_media_replica_key"=>0];foreach($rows[0] as $row)if(!\CajeerEngine\Forum\Content\ContentIntegrity::draft($row))++$bad["invalid_draft_payload"];foreach($rows[1] as $row)if(!\CajeerEngine\Forum\Content\ContentIntegrity::replica($row))++$bad["invalid_media_replica_key"];echo json_encode($bad);'''
    result.update(json.loads(subprocess.check_output(['php','-r',code],input=json.dumps([drafts,replicas]).encode(),cwd=ROOT)))
    return {k:v for k,v in result.items() if v}


def snapshot(db):
    tables=['ce_forum_member_profiles','ce_forum_member_preferences','ce_forum_profile_images','ce_forum_threads','ce_forum_posts','ce_forum_read_state','ce_forum_notifications','ce_forum_watches','ce_forum_mail_delivery','ce_forum_conversations','ce_forum_conversation_participants','ce_forum_conversation_messages','ce_forum_reactions','ce_forum_member_relations','ce_forum_achievement_rules','ce_forum_post_revisions','ce_forum_conversation_revisions','ce_forum_drafts','ce_forum_attachments','ce_forum_media_state','ce_forum_media_variants','ce_forum_media_replicas','ce_forum_polls','ce_forum_poll_options','ce_forum_poll_votes','ce_forum_questions','ce_forum_answer_votes','ce_forum_answer_notifications']
    return {t:sorted(db.execute('SELECT * FROM '+t).fetchall(),key=repr) for t in tables}


with tempfile.TemporaryDirectory(prefix='forum-community-sql-') as directory:
    root=Path(directory);db=connection(root/'live.sqlite')
    files=sorted(p for p in (ROOT/'migrations/sqlite').glob('*.sql') if p.name[:4]<='0029')
    assert files[-2].name=='0028_forum_rich_content.sql' and files[-1].name=='0029_forum_attachments.sql'
    for f in files[:-2]:db.executescript(f.read_text())
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
    db.executescript(files[-2].read_text());db.executescript(files[-1].read_text());db.executescript((ROOT/'migrations/sqlite/0030_forum_media_storage.sql').read_text());db.executescript((ROOT/'migrations/sqlite/0031_forum_polls_questions.sql').read_text())
    assert db.execute('SELECT body,body_format FROM ce_forum_posts WHERE id=?',(post,)).fetchone()==('First','plain')
    assert db.execute('SELECT body,body_format FROM ce_forum_conversation_messages WHERE id=?',(message,)).fetchone()==('Private message','plain')
    # Exercise the actual edit SQL, including the compatibility path omitting a format.
    for table,repo,key,body,format in [('ce_forum_posts','PostRepository.php',post,'**First**','markdown'),('ce_forum_conversation_messages','ConversationRepository.php',message,'[b]Private[/b]','bbcode')]:
        src=(ROOT/'core/Forum/Repository'/repo).read_text()
        sql=re.search(r"'((?:UPDATE )"+table+r" SET body=.*?)'",src).group(1)
        params=(body,format,key) if table=='ce_forum_posts' else (body,format,2,key)
        db.execute(sql,params)
        params=(body,None,key) if table=='ce_forum_posts' else (body,None,3,key)
        db.execute(sql,params)
        assert db.execute('SELECT body,body_format FROM '+table+' WHERE id=?',(key,)).fetchone()==(body,format)
        try: db.execute('UPDATE '+table+' SET body_format=? WHERE id=?',('html',key))
        except sqlite3.IntegrityError: pass
        else: raise AssertionError('invalid format accepted')
    db.commit()
    db.executescript((ROOT/'migrations/sqlite/0032_forum_drafts_revisions.sql').read_text());db.executescript((ROOT/'migrations/sqlite/0033_forum_soft_delete.sql').read_text());db.executescript((ROOT/'migrations/sqlite/0034_forum_moderation_center.sql').read_text());db.executescript((ROOT/'migrations/sqlite/0035_forum_sanctions.sql').read_text());db.executescript((ROOT/'migrations/sqlite/0036_forum_antispam.sql').read_text());db.executescript((ROOT/'migrations/sqlite/0037_forum_personal_data.sql').read_text());db.executescript((ROOT/'migrations/sqlite/0038_forum_trust_safety.sql').read_text())
    # Build a full content graph on the current schema and verify its bytes/relationships
    # survive a single real SQLite backup/restore, not separate disconnected fixtures.
    now=int(time.time());question,qfirst,answer,image,pending,draft,receipt,private_file=[uid() for _ in range(8)]
    db.execute("INSERT INTO ce_forum_polls(thread_id,question,max_choices,allow_change,visibility) VALUES(?,?,1,1,'always')",(thread,'Choice'))
    for n,label in [(1,'One'),(2,'Two')]:db.execute('INSERT INTO ce_forum_poll_options VALUES(?,?,?)',(thread,n,label))
    db.execute('INSERT INTO ce_forum_poll_votes(thread_id,option_no,user_id) VALUES(?,?,?)',(thread,2,b))
    db.execute('INSERT INTO ce_forum_threads(id,board_id,author_id,title,read_sequence) VALUES(?,?,?,?,2)',(question,board,a,'Question'))
    for pid,author,body,seq in [(qfirst,a,'Question',1),(answer,b,'Answer',2)]:
        db.execute('INSERT INTO ce_forum_posts(id,thread_id,author_id,body,ordinal,read_sequence) VALUES(?,?,?,?,?,?)',(pid,question,author,body,seq,seq))
        db.execute("INSERT INTO ce_forum_post_revisions(post_id,revision_number,body,body_format,editor_id,edit_reason,created_at) VALUES(?,1,?,'plain',?,'',?)",(pid,body,author,now))
    db.execute('INSERT INTO ce_forum_questions VALUES(?,?)',(question,answer));db.execute('INSERT INTO ce_forum_answer_votes VALUES(?,?)',(answer,a))
    db.execute('INSERT INTO ce_forum_answer_notifications(event_id,recipient_id,actor_id,post_id,state) VALUES(?,?,?,?,1)',(uid(),b,a,answer))
    jpeg=base64.b64decode(JPEG);sha=hashlib.sha256(jpeg).hexdigest()
    db.execute('INSERT INTO ce_forum_attachments(id,owner_id,post_id,filename,mime,size_bytes,sha256,file_data,created_at,expires_at) VALUES(?,?,?,?,?,?,?,?,?,0)',(image,a,post,'stable.jpg','image/jpeg',len(jpeg),sha,JPEG,now))
    db.execute("INSERT INTO ce_forum_media_state(attachment_id,state) VALUES(?,'ready')",(image,))
    for name in ('thumbnail','medium','large'):db.execute('INSERT INTO ce_forum_media_variants VALUES(?,?,?,?,?,?,?,?)',(image,name,'image/jpeg',2,2,len(jpeg),sha,JPEG))
    db.execute("INSERT INTO ce_forum_media_replicas(attachment_id,destination,object_key,state,due_at) VALUES(?,?,?,'ready',?)",(image,'a'*64,'forum-media/stable/'+image+'/'+sha,now+86400))
    raw=b'Content fixture';encoded=base64.b64encode(raw).decode();digest=hashlib.sha256(raw).hexdigest()
    db.execute('INSERT INTO ce_forum_attachments(id,owner_id,filename,mime,size_bytes,sha256,file_data,created_at,expires_at) VALUES(?,?,?,?,?,?,?,?,?)',(pending,a,'pending.txt','text/plain',len(raw),digest,encoded,now,now+2592000))
    db.execute('INSERT INTO ce_forum_attachments(id,owner_id,message_id,filename,mime,size_bytes,sha256,file_data,created_at,expires_at) VALUES(?,?,?,?,?,?,?,?,?,0)',(private_file,a,message,'private.txt','text/plain',len(raw),digest,encoded,now))
    db.execute('INSERT INTO ce_forum_drafts(id,user_id,scope,target_id,revision_number,payload,updated_at,expires_at) VALUES(?,?,?,?,1,?,?,?)',(draft,a,'reply',question,json.dumps({'body':'Pending','body_format':'markdown','attachment_ids':[pending]}),now,now+2592000))
    db.execute('INSERT INTO ce_forum_drafts(id,user_id,scope,target_id,revision_number,payload,result_json,updated_at,expires_at) VALUES(?,?,?,?,1,?,?,?,?)',(receipt,a,'thread',board,'{}',json.dumps({'id':thread}),now,now+2592000))
    db.commit()
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
    db.rollback()
    db.execute("UPDATE ce_forum_drafts SET payload='{' WHERE id=?",(draft,))
    db.execute("UPDATE ce_forum_media_replicas SET object_key='bad' WHERE attachment_id=?",(image,))
    db.execute("UPDATE ce_forum_media_variants SET width=241 WHERE attachment_id=? AND variant='thumbnail'",(image,))
    assert audit(db)=={'invalid_draft_payload':1,'invalid_media_replica_key':1,'invalid_media_variant_dimensions':1},audit(db)
    db.commit();backup.backup(db);backup.close()
    assert snapshot(db)==before and audit(db)=={}
    assert db.execute('PRAGMA foreign_key_check').fetchall()==[] and db.execute('PRAGMA integrity_check').fetchone()==('ok',)
    db.close()
    print('PASS 35 migrations; community + content graph, draft/replica/variant corruption, SQLite backup/restore and complete data equality')
