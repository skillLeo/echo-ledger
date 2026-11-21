-- Contract 2.0.0-l (migration 2026_12_25_000001, account and security from the apps): each line must answer 1 after
-- migrate; "select count(*) = 1 from information_schema.columns where table_schema = database() and table_name = 'users' and column_name = 'email_restore';
select count(*) = 0 from users where email_restore is not null;
select count(*) = 1 from information_schema.columns where table_schema = database() and table_name = 'users' and column_name = 'email_restore_address';
select count(*) = 1 from information_schema.statistics where table_schema = database() and table_name = 'users' and column_name = 'email_restore_address';
-- The reserved address compares as addresses do: the same collation as users.email.
select count(*) = 1 from information_schema.columns a join information_schema.columns e on e.table_schema = a.table_schema and e.table_name = a.table_name and e.column_name = 'email' where a.table_schema = database() and a.table_name = 'users' and a.column_name = 'email_restore_address' and a.collation_name = e.collation_name;
select count(*) = 1 from information_schema.columns where table_schema = database() and table_name = 'users' and column_name = 'email_restore_until';
-- after-rollback: select count(*) = 0 from information_schema.columns where table_schema = database() and table_name = 'users' and column_name = 'email_restore_address';
select count(*) = 1 from information_schema.columns where table_schema = database() and table_name = 'users' and column_name = 'recovery_codes_generated_at';
-- after-rollback: select count(*) = 0 from information_schema.columns where table_schema = database() and table_name = 'users' and column_name = 'email_restore_until';
-- after-rollback: select count(*) = 0 from information_schema.columns where table_schema = database() and table_name = 'users' and column_name = 'recovery_codes_generated_at';
-- after-rollback: select count(*) = 0 from information_schema.columns where table_schema = database() and table_name = 'users' and column_name = 'email_restore';
-- after-rollback:" lines must answer 1 after the rollback. Used by deploy/rehearse-migrations.sh --checks.
-- On a production copy: the columns are there and empty (nobody has a profile picture until they add one, and no email
-- change has a restore window yet), avatar_version nullable (null = initials).
-- Before 2.0.0-l goes live: set ECHOLEDGER_ANDROID_FINGERPRINTS (the phone app's release signing key, SHA-256) in .env.
-- It is empty by default, and until it is set Android App Links and phone passkeys stay off (passkeys_available false).
select count(*) = 1 from information_schema.columns where table_schema = database() and table_name = 'users' and column_name = 'avatar_version';
select count(*) = 0 from users where avatar_version is not null;
select count(*) = 1 from information_schema.columns where table_schema = database() and table_name = 'users' and column_name = 'avatar_version' and is_nullable = 'YES';
-- after-rollback: select count(*) = 0 from information_schema.columns where table_schema = database() and table_name = 'users' and column_name = 'avatar_version';
