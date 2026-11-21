-- Contract 2.0.0-l (migration 2026_12_25_000001, account and security from the apps): each line must answer 1 after
-- migrate; "select count(*) = 1 from information_schema.columns where table_schema = database() and table_name = 'users' and column_name = 'email_restore';
select count(*) = 0 from users where email_restore is not null;
-- after-rollback: select count(*) = 0 from information_schema.columns where table_schema = database() and table_name = 'users' and column_name = 'email_restore';
-- after-rollback:" lines must answer 1 after the rollback. Used by deploy/rehearse-migrations.sh --checks.
-- On a production copy: the columns are there and empty (nobody has a profile picture until they add one, and no email
-- change has a restore window yet), avatar_version nullable (null = initials).
select count(*) = 1 from information_schema.columns where table_schema = database() and table_name = 'users' and column_name = 'avatar_version';
select count(*) = 0 from users where avatar_version is not null;
select count(*) = 1 from information_schema.columns where table_schema = database() and table_name = 'users' and column_name = 'avatar_version' and is_nullable = 'YES';
-- after-rollback: select count(*) = 0 from information_schema.columns where table_schema = database() and table_name = 'users' and column_name = 'avatar_version';
