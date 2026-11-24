-- Contract 2.0.0-r, BE-03 (migration 2026_12_31_000001 Retry all's time): each line must answer 1 after migrate;
-- "-- after-rollback:" lines must answer 1 after the rollback. Used by deploy/rehearse-migrations.sh --checks.
-- After migrate: users.retry_requested_at, a nullable timestamp, null for everyone, and no other new users column than
-- it. After the rollback: it is gone.
select count(*) = 1 from information_schema.columns where table_schema = database() and table_name = 'users' and column_name = 'retry_requested_at' and is_nullable = 'YES' and data_type = 'timestamp';
select count(*) = 0 from users where retry_requested_at is not null;
select count(*) = 1 from information_schema.columns where table_schema = database() and table_name = 'users' and column_name = 'views_version';
-- after-rollback: select count(*) = 0 from information_schema.columns where table_schema = database() and table_name = 'users' and column_name = 'retry_requested_at';
-- after-rollback: select count(*) = 1 from information_schema.columns where table_schema = database() and table_name = 'users' and column_name = 'views_version';
