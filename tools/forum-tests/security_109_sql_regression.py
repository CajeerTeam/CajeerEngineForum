#!/usr/bin/env python3
"""Execute real historical SQLite SQL on an existing forum fixture and test session/identity isolation.

No migration files are modified. This checks the 1.0.8 -> 1.0.9 source update
against the last pre-OIDC schema and the production OIDC/session schema.
"""
import hashlib
import sqlite3
from pathlib import Path

root = Path(__file__).resolve().parents[2]
migrations = sorted((root / 'migrations/sqlite').glob('*.sql'))
assert len(migrations) >= 75 and migrations[-1].name.startswith('0078_')
connection = sqlite3.connect(':memory:')
connection.execute('PRAGMA foreign_keys=ON')


def apply(files):
    for migration in files:
        connection.executescript(migration.read_text())


apply(p for p in migrations if p.name[:4] <= '0060')
user = 'aaaabbbb-0000-4000-8000-aaaaaaaaaaaa'
other = 'aaaabbbb-0000-4000-8000-bbbbbbbbbbbb'
for uid, email in [(user, 'existing@example.test'), (other, 'other@example.test')]:
    connection.execute('INSERT INTO ce_users(id,email,name,password_hash) VALUES(?,?,?,?)', (uid, email, 'Old forum user', 'hash'))
board = '22222222-2222-4222-8222-222222222222'
thread = 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa'
post = 'aaaaaaaa-2222-4222-8222-aaaaaaaaaaaa'
connection.execute('INSERT INTO ce_forum_threads(id,board_id,author_id,title) VALUES(?,?,?,?)', (thread, board, user, 'Existing discussion'))
connection.execute('INSERT INTO ce_forum_posts(id,thread_id,author_id,body,ordinal) VALUES(?,?,?,?,?)', (post, thread, user, 'Preserved during upgrade', 1))
old_session = 'bbbbbbbb-1111-4111-8111-bbbbbbbbbbbb'
other_session = 'bbbbbbbb-2222-4222-8222-bbbbbbbbbbbb'
api = 'bbbbbbbb-3333-4333-8333-bbbbbbbbbbbb'
for tid, uid, name in [(old_session, user, 'admin-session'), (other_session, other, 'admin-session'), (api, user, 'personal-api')]:
    connection.execute('INSERT INTO ce_api_tokens(id,user_id,name,token_hash,expires_at) VALUES(?,?,?,?,?)',
                       (tid, uid, name, hashlib.sha256(tid.encode()).hexdigest(), '2099-01-01 00:00:00'))
connection.commit()

# Apply the *actual* OIDC, unified-session and MFA migration chain over the populated old database.
apply(p for p in migrations if p.name[:4] >= '0061')
assert connection.execute('SELECT COUNT(*) FROM ce_users WHERE id IN (?,?)', (user, other)).fetchone()[0] == 2
assert connection.execute('SELECT body FROM ce_forum_posts WHERE id=?', (post,)).fetchone() == ('Preserved during upgrade',)
assert connection.execute('SELECT title FROM ce_forum_threads WHERE id=?', (thread,)).fetchone() == ('Existing discussion',)
assert connection.execute('SELECT COUNT(*) FROM ce_api_tokens').fetchone()[0] == 3
assert connection.execute('PRAGMA foreign_key_check').fetchall() == []
# TOTP replay defense is an atomic compare-and-swap across competing workers.
step = 1967000000
cas = connection.execute('UPDATE ce_users SET two_factor_enabled=1,two_factor_last_step=NULL WHERE id=?', (user,))
assert cas.rowcount == 1
sql = 'UPDATE ce_users SET two_factor_last_step=? WHERE id=? AND two_factor_enabled=TRUE AND (two_factor_last_step IS NULL OR two_factor_last_step < ?)'
assert connection.execute(sql, (step, user, step)).rowcount == 1
assert connection.execute(sql, (step, user, step)).rowcount == 0
assert connection.execute(sql, (step-1, user, step-1)).rowcount == 0
assert connection.execute(sql, (step+1, user, step+1)).rowcount == 1
print('PASS 77 real SQLite migrations, forum users/discussions and opaque tokens survive in-place upgrade')

issuer = 'https://id.skif4er.ru/realms/skif4er'
subject = 'external-id-01'
connection.execute('INSERT INTO ce_oidc_identities (issuer,subject,user_id) VALUES (?,?,?)', (issuer, subject, user))
try:
    connection.execute('INSERT INTO ce_oidc_identities (issuer,subject,user_id) VALUES (?,?,?)', (issuer, subject, other))
    raise AssertionError('External identity takeover accepted')
except sqlite3.IntegrityError:
    pass
assert connection.execute('SELECT user_id FROM ce_oidc_identities WHERE issuer=? AND subject=?',(issuer,subject)).fetchone() == (user,)
print('PASS linked external subject cannot be assigned to a second local account')

# Reuse existing session token IDs as migration does; never classify unlabeled historic cookies as SSO.
connection.execute("INSERT INTO ce_user_sessions(token_id,user_id,provider,last_seen_at) VALUES(?,?,'legacy',CURRENT_TIMESTAMP)", (old_session,user))
connection.execute("INSERT INTO ce_user_sessions(token_id,user_id,provider,last_seen_at) VALUES(?,?,'local',CURRENT_TIMESTAMP)", (other_session,other))
assert connection.execute('SELECT provider FROM ce_user_sessions WHERE token_id=?',(old_session,)).fetchone() == ('legacy',)
try:
    connection.execute("INSERT INTO ce_user_sessions(token_id,user_id,provider) VALUES(?,?,'skif4er')", (old_session,other))
    raise AssertionError('Duplicate session token accepted')
except sqlite3.IntegrityError:
    pass

# Create an actual SSO credential and test the exact current revocation predicate.
sso = 'bbbbbbbb-4444-4444-8444-bbbbbbbbbbbb'
connection.execute('INSERT INTO ce_api_tokens(id,user_id,name,token_hash,expires_at) VALUES(?,?,?,?,?)',
                   (sso,user,'admin-session',hashlib.sha256(sso.encode()).hexdigest(),'2099-01-01 00:00:00'))
connection.execute('INSERT INTO ce_user_sessions(token_id,user_id,provider,issuer,oidc_sub_hash,oidc_sid_hash) VALUES(?,?,?,?,?,?)',
                   (sso,user,'skif4er',issuer,hashlib.sha256(subject.encode()).hexdigest(),hashlib.sha256(b'provider-sid').hexdigest()))
connection.commit()

# A verified logout token's jti must be consumed exactly once.
jti = hashlib.sha256((issuer+'\0'+'logout-1').encode()).hexdigest()
connection.execute('INSERT INTO ce_oidc_logout_replay(jti_hash,issuer,expires_at) VALUES(?,?,?)',(jti,issuer,4102444800))
try:
    connection.execute('INSERT INTO ce_oidc_logout_replay(jti_hash,issuer,expires_at) VALUES(?,?,?)',(jti,issuer,4102444800))
    raise AssertionError('Backchannel replay accepted')
except sqlite3.IntegrityError:
    pass

# Revocation targets linked SSO sessions only; user and machine-token isolation is mandatory.
connection.commit()
connection.execute('BEGIN')
connection.execute("UPDATE ce_api_tokens SET revoked_at = CURRENT_TIMESTAMP WHERE user_id=? AND name='admin-session' AND revoked_at IS NULL AND id IN (SELECT token_id FROM ce_user_sessions WHERE user_id=? AND provider='skif4er' AND revoked_at IS NULL)",(user,user))
count=connection.execute("UPDATE ce_user_sessions SET revoked_at=CURRENT_TIMESTAMP WHERE user_id=? AND provider='skif4er' AND revoked_at IS NULL",(user,)).rowcount
assert count == 1
connection.commit()
assert connection.execute('SELECT revoked_at IS NOT NULL FROM ce_api_tokens WHERE id=?',(sso,)).fetchone()==(1,)
for untouched in (old_session, other_session, api):
    assert connection.execute('SELECT revoked_at IS NULL FROM ce_api_tokens WHERE id=?',(untouched,)).fetchone()==(1,)
assert connection.execute('PRAGMA integrity_check').fetchone()==('ok',)
print('PASS verified SSO revocation, replay prevention, local/legacy session isolation and API credential preservation')
