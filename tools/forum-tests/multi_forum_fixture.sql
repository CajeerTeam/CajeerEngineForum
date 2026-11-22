-- Synthetic snapshots with the native column layouts needed by each adapter.
CREATE TABLE xf_user_group(user_group_id INTEGER PRIMARY KEY,title TEXT);
INSERT INTO xf_user_group VALUES(2,'Members'),(3,'Administrators');
CREATE TABLE xf_user(user_id INTEGER PRIMARY KEY,username TEXT,email TEXT,register_date INT,user_group_id INT,secondary_group_ids TEXT,password TEXT,avatar_date INT NOT NULL DEFAULT 0);
INSERT INTO xf_user VALUES(1,'XF Alice','xf-alice@example.test',1600000000,2,'3','do-not-copy',0),(2,'XF Bob','xf-bob@example.test',1600000000,2,'','secret',0),(3,'Conflict','existing@example.test',1600000000,2,'','secret',0);
CREATE TABLE xf_user_follow(user_id INTEGER NOT NULL, follow_user_id INTEGER NOT NULL, PRIMARY KEY(user_id,follow_user_id));
INSERT INTO xf_user_follow VALUES(1,2),(1,3),(2,1),(3,1);
CREATE TABLE xf_node(node_id INTEGER PRIMARY KEY,parent_node_id INT,display_order INT,node_type_id TEXT,title TEXT,description TEXT);
INSERT INTO xf_node VALUES(15,10,1,'Forum','XF Forum','Board'),(10,0,2,'Category','XF Category',''),(18,10,2,'LinkForum','External','');
CREATE TABLE xf_thread(thread_id INTEGER PRIMARY KEY,node_id INT,user_id INT,title TEXT,post_date INT,last_post_date INT,discussion_type TEXT,discussion_open INT,sticky INT,discussion_state TEXT);
INSERT INTO xf_thread VALUES(20,15,1,'XF needle',1600000000,1600000001,'discussion',0,1,'visible'),(21,15,1,'Deleted',1600000000,1600000000,'discussion',1,0,'deleted'),(22,15,1,'Empty',1600000000,1600000000,'discussion',1,0,'visible');
CREATE TABLE xf_post(post_id INTEGER PRIMARY KEY,thread_id INT,user_id INT,message TEXT,post_date INT,last_edit_date INT,message_state TEXT);
INSERT INTO xf_post VALUES(30,20,1,'XF needle [b]literal[/b]',1600000000,0,'visible'),(31,20,2,'Reply',1600000001,0,'visible'),(32,20,1,'Unapproved',1600000002,0,'moderated'),(33,21,1,'Deleted',1600000000,0,'visible');
CREATE TABLE xf_attachment(attachment_id INTEGER PRIMARY KEY,content_id INT,content_type TEXT,data_id INT,unassociated INT,attach_date INT);
CREATE TABLE xf_attachment_data(data_id INTEGER PRIMARY KEY,user_id INT,file_hash TEXT,file_key TEXT,filename TEXT,file_size INT);
INSERT INTO xf_attachment_data VALUES(1001,1,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb','sample.txt',12);
INSERT INTO xf_attachment VALUES(40,30,'post',1001,0,1600000000),(41,30,'conversation_message',1001,0,1600000000),(42,30,'post',1001,1,1600000000);
CREATE TABLE xf_thread_watch(thread_id INT,user_id INT);INSERT INTO xf_thread_watch VALUES(20,1),(20,2);
CREATE TABLE xf_forum_watch(node_id INT,user_id INT);INSERT INTO xf_forum_watch VALUES(15,1);
CREATE TABLE xf_poll(poll_id INTEGER PRIMARY KEY,content_id INT,content_type TEXT,question TEXT,close_date INT,max_votes INT);
INSERT INTO xf_poll VALUES(50,20,'thread','Pick',0,1);
CREATE TABLE xf_poll_response(poll_response_id INTEGER PRIMARY KEY,poll_id INT,response TEXT);INSERT INTO xf_poll_response VALUES(60,50,'Yes'),(61,50,'No');
CREATE TABLE xf_poll_vote(poll_response_id INT,user_id INT);INSERT INTO xf_poll_vote VALUES(60,1),(61,2),(61,0);
CREATE TABLE xf_conversation_message(id INT);INSERT INTO xf_conversation_message VALUES(1);
CREATE TABLE xf_user_profile(user_id INTEGER PRIMARY KEY,about TEXT,location TEXT,website TEXT,signature TEXT);
INSERT INTO xf_user_profile VALUES
 (1,'Imported biography','Stockholm','https://example.test/member','signature plain'),
 (2,'','', '', 'A real signature'),
 (3,'Collision','', '', '');
CREATE TABLE xf_reaction_content(reaction_content_id INTEGER PRIMARY KEY,content_type TEXT,content_id INT,reaction_user_id INT,reaction_id INT);
INSERT INTO xf_reaction_content VALUES
 (100,'post',30,2,1),
 (101,'post',31,1,2),
 (102,'post',30,1,1),
 (103,'post',30,3,1),
 (104,'post',31,2,5),
 (105,'post',32,2,1),
 (106,'thread',20,2,1);

CREATE TABLE mybb_usergroups(gid INTEGER PRIMARY KEY,title TEXT,description TEXT);
INSERT INTO mybb_usergroups VALUES(2,'Members',''),(4,'Administrators','');
CREATE TABLE mybb_users(uid INTEGER PRIMARY KEY,username TEXT,email TEXT,regdate INT,usergroup INT,additionalgroups TEXT,password TEXT);
INSERT INTO mybb_users VALUES(1,'My Alice','my-alice@example.test',1600000000,2,'4','secret'),(2,'My Bob','my-bob@example.test',1600000000,2,'','secret'),(3,'Conflict','existing@example.test',1600000000,2,'','secret');
CREATE TABLE mybb_forums(fid INTEGER PRIMARY KEY,pid INT,disporder INT,linkto TEXT,type TEXT,name TEXT,description TEXT);
INSERT INTO mybb_forums VALUES(15,10,1,'','f','My Forum','Board'),(10,0,2,'','c','My Category',''),(18,10,2,'https://example.test','f','External','');
CREATE TABLE mybb_threads(tid INTEGER PRIMARY KEY,fid INT,uid INT,subject TEXT,dateline INT,lastpost INT,closed TEXT,sticky INT,visible INT);
INSERT INTO mybb_threads VALUES(20,15,1,'My needle',1600000000,1600000001,'1',1,1),(21,15,1,'Deleted',1600000000,1600000000,'',0,-1),(22,15,1,'Empty',1600000000,1600000000,'',0,1);
CREATE TABLE mybb_posts(pid INTEGER PRIMARY KEY,tid INT,fid INT,uid INT,message TEXT,dateline INT,edittime INT,visible INT);
INSERT INTO mybb_posts VALUES(30,20,15,1,'My needle [b]literal[/b]',1600000000,0,1),(31,20,15,2,'Reply',1600000001,0,1),(32,20,15,1,'Unapproved',1600000002,0,0),(33,21,15,1,'Deleted',1600000000,0,1);
CREATE TABLE mybb_attachments(aid INTEGER PRIMARY KEY,pid INT,uid INT,visible INT,attachname TEXT,filename TEXT,filesize INT,dateuploaded INT);
INSERT INTO mybb_attachments VALUES(40,30,1,1,'202009/post_1_safe.attach','sample.txt',12,1600000000),(41,0,1,1,'202009/post_1_safe.attach','sample.txt',12,1600000000),(42,30,1,1,'../outside.txt','sample.txt',12,1600000000);
CREATE TABLE mybb_threadsubscriptions(tid INT,uid INT);INSERT INTO mybb_threadsubscriptions VALUES(20,1),(20,2);
CREATE TABLE mybb_forumsubscriptions(fid INT,uid INT);INSERT INTO mybb_forumsubscriptions VALUES(15,1);
CREATE TABLE mybb_polls(pid INTEGER PRIMARY KEY,tid INT,question TEXT,dateline INT,options TEXT,numoptions INT,timeout INT,multiple INT,maxoptions INT);
INSERT INTO mybb_polls VALUES(50,20,'Pick',1600000000,'Yes||~|~||No',2,0,0,1);
CREATE TABLE mybb_pollvotes(pid INT,uid INT,voteoption INT);INSERT INTO mybb_pollvotes VALUES(50,1,1),(50,2,2),(50,0,2);
CREATE TABLE mybb_privatemessages(id INT);INSERT INTO mybb_privatemessages VALUES(1);
CREATE TABLE mybb_reputation(id INT);

CREATE TABLE smf_settings(variable TEXT,value TEXT);INSERT INTO smf_settings VALUES('smfVersion','SMF 2.1.6'),('recycle_enable','0');
CREATE TABLE smf_membergroups(id_group INTEGER PRIMARY KEY,group_name TEXT);INSERT INTO smf_membergroups VALUES(0,'Regular'),(2,'Members'),(3,'Administrators');
CREATE TABLE smf_members(id_member INTEGER PRIMARY KEY,real_name TEXT,email_address TEXT,date_registered INT,id_group INT,additional_groups TEXT,passwd TEXT);
INSERT INTO smf_members VALUES(1,'SMF Alice','smf-alice@example.test',1600000000,2,'3','secret'),(2,'SMF Bob','smf-bob@example.test',1600000000,2,'','secret'),(3,'Conflict','existing@example.test',1600000000,2,'','secret');
CREATE TABLE smf_categories(id_cat INTEGER PRIMARY KEY,cat_order INT,name TEXT);INSERT INTO smf_categories VALUES(10,2,'SMF Category');
CREATE TABLE smf_boards(id_board INTEGER PRIMARY KEY,id_cat INT,id_parent INT,board_order INT,name TEXT,description TEXT);INSERT INTO smf_boards VALUES(15,10,0,1,'SMF Forum','Board'),(16,10,15,2,'Nested','');
CREATE TABLE smf_topics(id_topic INTEGER PRIMARY KEY,id_board INT,id_member_started INT,id_first_msg INT,id_last_msg INT,id_redirect_topic INT,locked INT,is_sticky INT,approved INT,id_poll INT);
INSERT INTO smf_topics VALUES(20,15,1,30,31,0,1,1,1,50),(21,15,1,33,33,0,0,0,0,0),(22,15,1,34,34,0,0,0,1,0);
CREATE TABLE smf_messages(id_msg INTEGER PRIMARY KEY,id_topic INT,id_board INT,id_member INT,subject TEXT,body TEXT,poster_time INT,modified_time INT,approved INT);
INSERT INTO smf_messages VALUES(30,20,15,1,'SMF needle','SMF needle [b]literal[/b]',1600000000,0,1),(31,20,15,2,'Reply','Reply',1600000001,0,1),(32,20,15,1,'Unapproved','Unapproved',1600000002,0,0),(33,21,15,1,'Deleted','Deleted',1600000000,0,1),(34,22,15,1,'Empty','Unapproved',1600000000,0,0);
CREATE TABLE smf_attachments(id_attach INTEGER PRIMARY KEY,id_msg INT,attachment_type INT,approved INT,file_hash TEXT,id_folder INT,filename TEXT,size INT);
INSERT INTO smf_attachments VALUES(40,30,0,1,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',1,'sample.txt',12),(41,30,1,1,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',1,'sample.txt',12),(42,30,0,0,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',1,'sample.txt',12);
CREATE TABLE smf_log_notify(id_topic INT,id_board INT,id_member INT);INSERT INTO smf_log_notify VALUES(20,0,1),(20,0,2),(0,15,1);
CREATE TABLE smf_polls(id_poll INTEGER PRIMARY KEY,question TEXT,expire_time INT,max_votes INT);INSERT INTO smf_polls VALUES(50,'Pick',0,1);
CREATE TABLE smf_poll_choices(id_poll INT,id_choice INT,label TEXT);INSERT INTO smf_poll_choices VALUES(50,0,'Yes'),(50,1,'No');
CREATE TABLE smf_log_polls(id_poll INT,id_member INT,id_choice INT);INSERT INTO smf_log_polls VALUES(50,1,0),(50,2,1),(50,0,1);
CREATE TABLE smf_personal_messages(id INT);INSERT INTO smf_personal_messages VALUES(1);
CREATE TABLE smf_user_likes(id INT);
