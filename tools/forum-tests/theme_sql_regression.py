#!/usr/bin/env python3
"""Test real PHP design transitions and migration/CAS on a real SQLite database."""
import json, sqlite3, subprocess, tempfile, uuid
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
PHP=r'''
spl_autoload_register(static function($c){if(str_starts_with($c,'CajeerEngine\\')){$p='core/'.str_replace('\\','/',substr($c,13)).'.php';if(is_file($p))require $p;}});
use CajeerEngine\Forum\ForumTheme as T;
use CajeerEngine\Forum\Service\ForumThemeService as S;
use CajeerEngine\Forum\ForumException;
function verify($v,$m){if(!$v)throw new RuntimeException($m);}
$p=T::presets();foreach($p as $v){verify(T::validate($v)===$v,'valid preset');verify(str_contains(T::css($v),'@media(prefers-color-scheme:light)'),'CSS system scheme');}
$base=['revision'=>0,'active'=>$p['default'],'draft'=>$p['default'],'library'=>[],'history'=>[]];
$v=$p['ocean'];$v['name']='My Ocean';$v['logo']='OC';$v['width']=1000;$v['sidebar']='left';
$draft=S::transition($base,'draft',['design'=>$v]);verify($draft['active']===$base['active']&&$draft['draft']===$v,'draft isolation');
$active=S::transition($draft,'activate',[]);verify($active['active']===$v&&count($active['history'])===1&&count($active['library'])===1,'activation and library');
$restored=S::transition($active,'restore',['index'=>0]);verify($restored['active']===$base['active']&&count($restored['history'])===2,'restore');
$key=array_key_first($active['library']);$removed=S::transition($active,'delete',['key'=>$key]);verify(!$removed['library']&&$removed['active']===$v,'remove saved copy, preserve active');
foreach([['logo'=>'<x>'],['width'=>799],['radius'=>25],['font'=>'url(x)'],['scheme'=>'x'],['light'=>array_replace($v['light'],['bg'=>'</style>'])],['dark'=>array_replace($v['dark'],['text'=>$v['dark']['bg']])],['extra'=>'x']] as $bad){try{T::validate(array_replace($v,$bad));throw new RuntimeException('invalid accepted');}catch(ForumException $e){verify($e->status===422,'validation');}}
$css=T::css($v);verify(str_contains($css,'--forum-width:1000px')&&str_contains($css,'--bg:#081b23')&&!str_contains($css,'<'),'safe real CSS');
$full=$active;for($i=0;$i<12;$i++){$full['library'][str_repeat(dechex($i),64)]=$v;}try{S::transition($full,'activate',[]);throw new RuntimeException('limit');}catch(ForumException $e){}
for($i=0;$i<15;$i++){$active=S::transition($active,'draft',['design'=>$p[$i%2?'ocean':'sand']]);$active=S::transition($active,'activate',[]);}verify(count($active['history'])===10,'bounded history');
echo json_encode(['base'=>$base,'draft'=>$draft,'active'=>S::transition($draft,'activate',[]),'restored'=>$restored],JSON_THROW_ON_ERROR);
'''
# Execute the exact PHP validator and transition used by the production writer.
state=json.loads(subprocess.check_output(['php','-r',PHP],cwd=ROOT))
with tempfile.TemporaryDirectory() as tmp:
 db=sqlite3.connect(Path(tmp)/'forum.sqlite');db.execute('PRAGMA foreign_keys=ON')
 files=sorted(f for f in (ROOT/'migrations/sqlite').glob('*.sql') if f.name[:4]<='0045');assert files[-1].name=='0045_forum_themes.sql'
 for f in files[:-1]:db.executescript(f.read_text())
 user=str(uuid.uuid4());db.execute('INSERT INTO ce_users(id,email,name,password_hash) VALUES(?,?,?,?)',(user,'theme@example.test','Theme admin','test'));db.commit()
 db.executescript(files[-1].read_text());assert db.execute('SELECT revision,payload,active_payload FROM ce_forum_theme_settings').fetchone()==(0,'{}','{}')
 sql='UPDATE ce_forum_theme_settings SET revision=revision+1,payload=?,active_payload=? WHERE id=1 AND revision=?'
 def update(name,revision):
  v=state[name];return db.execute(sql,(json.dumps(v),json.dumps(v['active']),revision)).rowcount
 assert update('draft',0)==1;db.commit();assert json.loads(db.execute('SELECT active_payload FROM ce_forum_theme_settings').fetchone()[0])==state['base']['active']
 assert update('active',0)==0;db.rollback();assert update('active',1)==1
 db.execute("INSERT INTO ce_audit_log(event,actor_id,context) VALUES('forum.acp.theme.activate',?,?)",(user,json.dumps(state['active'])));db.rollback()
 assert db.execute('SELECT revision FROM ce_forum_theme_settings').fetchone()[0]==1 and db.execute("SELECT COUNT(*) FROM ce_audit_log WHERE event='forum.acp.theme.activate'").fetchone()[0]==0
 assert update('active',1)==1;db.commit();assert json.loads(db.execute('SELECT active_payload FROM ce_forum_theme_settings').fetchone()[0])==state['active']['active']
 assert update('restored',2)==1;db.commit();assert json.loads(db.execute('SELECT active_payload FROM ce_forum_theme_settings').fetchone()[0])==state['base']['active']
 assert not db.execute('PRAGMA foreign_key_check').fetchall()
print('PASS real PHP design validation, presets, safe CSS, draft/activate/restore/delete, bounded history/library; SQLite 0045, CAS, rollback and active snapshot isolation')
