#!/usr/bin/env python3
"""0.7.0 cross-surface SQLite acceptance: real FTS, ACL evaluator, sitemap SQL and cache epochs."""
import json
import re
import sqlite3
import subprocess
import tempfile
import uuid
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]

def uid(): return str(uuid.uuid4())

# Invoke the actual PHP query builder and the real inherited guest permission evaluator.
PHP = r'''
require 'core/Forum/Security/ForumAccessPolicy.php';
require 'core/Forum/Security/PermissionResolver.php';
require 'core/Forum/Repository/ForumSearchQuery.php';
$a=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR);
$actor=new \CajeerEngine\Forum\Security\ForumAccessPolicy('',[]);
$allowed=[];
foreach($a['chains'] as $id=>$chain){
    $p=\CajeerEngine\Forum\Security\PermissionResolver::evaluate($actor,[],$chain,$a['rules']);
    if($p['node:view']&&$p['thread:read'])$allowed[]=$id;
}
$acl=$allowed?'t.board_id IN ('.implode(',',array_map(fn($id)=>"'".$id."'",$allowed)).')':'1=0';
[$sql,$params]=\CajeerEngine\Forum\Repository\ForumSearchQuery::build('sqlite',$a['term'],0,$acl,$a['tag'],'threads',$a['options']);
echo json_encode([$sql,$params,$allowed],JSON_THROW_ON_ERROR);
'''

with tempfile.TemporaryDirectory(prefix='forum-stable-') as tmp:
    path=Path(tmp)/'forum.sqlite'
    db=sqlite3.connect(path);db.row_factory=sqlite3.Row
    db.execute('PRAGMA foreign_keys=ON');db.execute('PRAGMA journal_mode=WAL')
    for migration in sorted((ROOT/'migrations/sqlite').glob('*.sql')):
        db.executescript(migration.read_text())
    cat=db.execute('SELECT id FROM ce_forum_categories LIMIT 1').fetchone()[0]
    parent=db.execute('SELECT id FROM ce_forum_boards LIMIT 1').fetchone()[0]
    child=uid();db.execute('INSERT INTO ce_forum_boards(id,category_id,parent_id,name,description) VALUES(?,?,?,?,?)',(child,cat,parent,'Stable child',''))
    live,empty,post=uid(),uid(),uid()
    for t in (live,empty):
        db.execute('INSERT INTO ce_forum_threads(id,board_id,title) VALUES(?,?,?)',(t,child,'Stablemarker topic'))
        db.execute('INSERT INTO ce_forum_thread_tags(thread_id,tag) VALUES(?,?)',(t,'stablemarker'))
    db.execute('INSERT INTO ce_forum_posts(id,thread_id,body,ordinal,read_sequence) VALUES(?,?,?,?,?)',(post,live,'Stablemarker original',1,1));db.commit()
    second=sqlite3.connect(path);second.row_factory=sqlite3.Row;second.execute('PRAGMA foreign_keys=ON')
    source=(ROOT/'core/Forum/Repository/ForumSitemapRepository.php').read_text()
    chunks=re.findall(r"'(SELECT [^']+ WHERE )'\.\$this->boardFilter\('t.board_id',\$boards\)\.'([^']+)'",source)
    assert len(chunks)==3
    def read(conn,term='stablemarker',tag=None,options=None):
        category=dict(conn.execute('SELECT id,name,visibility FROM ce_forum_categories WHERE id=?',(cat,)).fetchone());category['key']='category:'+cat
        parents={r['id']:dict(r) for r in conn.execute('SELECT id,name,visibility,parent_id,seo_index FROM ce_forum_boards WHERE category_id=?',(cat,))}
        chains={}
        for bid in (parent,child):
            chain=[];cursor=parents[bid]
            while cursor:
                chain.insert(0,cursor|{'key':'board:'+cursor['id']});cursor=parents.get(cursor['parent_id'])
            chains[bid]=[category,*chain]
        rules={}
        for kind in ('category','board'):
            for r in conn.execute('SELECT * FROM ce_forum_'+kind+'_permissions'):
                rules.setdefault(kind+':'+r['node_id'],[]).append(dict(r))
        sql,args,allowed=json.loads(subprocess.check_output(['php','-r',PHP,json.dumps({'chains':chains,'rules':rules,'term':term,'tag':tag,'options':options or {}})],cwd=ROOT))
        hits=[dict(r) for r in conn.execute(sql,args)]
        # Sitemap's additional SEO policy is separate from read ACL; exclude descendants of noindex nodes.
        seo=[bid for bid in allowed if all(n.get('seo_index',1)==1 for n in chains[bid])]
        where='t.board_id IN ('+','.join('?' for _ in seo)+')' if seo else '1=0'
        sitemap=[r[0] for r in conn.execute(chunks[2][0]+where+chunks[2][1]+'1000 OFFSET 0',seo)]
        generation=tuple(tuple(r) for r in conn.execute("SELECT name,generation FROM ce_forum_cache_generation_effective WHERE scope='public' ORDER BY name"))
        assert len(generation)==21
        return hits,sitemap,generation
    def ids(rows): return [r['id'] for r in rows]
    hits,sitemap,old=read(second);assert ids(hits)==[live] and sitemap==[live]
    assert ids(read(second,'',tag='stablemarker')[0])==[live]
    assert empty not in ids(read(second,'stable',options={'discover':'suggest'})[0])
    # A reader already in flight sees one coherent old snapshot, then a fresh request sees revocation.
    second.execute('BEGIN');assert read(second)[2]==old
    db.execute("UPDATE ce_forum_boards SET visibility='private' WHERE id=?",(parent,));db.commit()
    assert ids(read(second)[0])==[live] and read(second)[2]==old
    second.commit();hits,sitemap,private=read(second);assert not hits and not sitemap and private!=old
    db.execute("UPDATE ce_forum_boards SET visibility='public' WHERE id=?",(parent,));db.commit()
    hits,sitemap,restored=read(second);assert ids(hits)==[live] and sitemap==[live] and restored!=private
    # A guest-specific inherited deny also invalidates cached public data.
    db.execute("INSERT INTO ce_forum_board_permissions(node_id,subject_type,subject_id,permission,effect) VALUES(?,'guest','','thread:read','deny')",(parent,));db.commit()
    assert not read(second)[0] and not read(second)[1] and read(second)[2]!=restored
    db.execute('DELETE FROM ce_forum_board_permissions WHERE node_id=?',(parent,));db.commit()
    # Noindex does not revoke read/search access.
    db.execute('UPDATE ce_forum_boards SET seo_index=0 WHERE id=?',(parent,));db.commit()
    assert ids(read(second)[0])==[live] and not read(second)[1]
    db.execute('UPDATE ce_forum_boards SET seo_index=1 WHERE id=?',(parent,));db.commit()
    # Removing the last published post removes title, tag, discovery and sitemap hits.
    db.execute('UPDATE ce_forum_posts SET deleted_at=1 WHERE id=?',(post,));db.commit()
    assert not read(second)[0] and not read(second)[1] and not read(second,'',tag='stablemarker')[0]
    db.execute('UPDATE ce_forum_posts SET deleted_at=NULL WHERE id=?',(post,));db.commit()
    assert ids(read(second)[0])==[live] and read(second)[1]==[live]
    before=read(second)[2];db.execute('UPDATE ce_forum_posts SET body=? WHERE id=?',('uncommitted marker',post));db.rollback()
    assert read(second)[2]==before and not read(second,'uncommitted')[0]
    db.execute('UPDATE ce_forum_posts SET body=? WHERE id=?',('replacementmarker',post));db.commit()
    assert ids(read(second,'replacementmarker')[0])==[live] and read(second)[2]!=before
    db.execute('DELETE FROM ce_forum_threads WHERE id=?',(live,));db.commit()
    assert not read(second)[0] and not read(second)[1] and not db.execute('PRAGMA foreign_key_check').fetchall()
    # Exercise the same integrity and source parity SQL as the maintenance command.
    for kind,table,index,key,cols in [('thread','ce_forum_threads','ce_forum_fts_threads','id',['title','prefix']),('post','ce_forum_posts','ce_forum_fts_posts','id',['body']),('tag','ce_forum_thread_tags','ce_forum_fts_tags','thread_id',['tag'])]:
        db.execute(f"INSERT INTO {index}({index}) VALUES('integrity-check')")
        join=f"k.kind='{kind}' AND k.source_id=s.{key}"+(' AND k.tag=s.tag' if kind=='tag' else '')
        differences=' OR '.join(f'f.{c} IS NOT s.{c}' for c in cols)
        assert db.execute(f'SELECT COUNT(*) FROM {table} s LEFT JOIN ce_forum_fts_keys k ON {join} LEFT JOIN {index} f ON f.rowid=k.id WHERE f.rowid IS NULL OR {differences}').fetchone()[0]==0
        assert db.execute(f'SELECT COUNT(*) FROM {index} f LEFT JOIN ce_forum_fts_keys k ON k.id=f.rowid LEFT JOIN {table} s ON {join} WHERE s.{key} IS NULL').fetchone()[0]==0
    db.commit();second.close();db.close()
print('PASS 0.7.0 cross-surface search/sitemap/cache: inherited ACL, snapshot, noindex, orphan, delete/restore, rollback and purge')
