-- Contract 2.0.0-v (migration 2027_01_03_000001, call rules): each line must answer 1 after migrate; "-- after-rollback:"
-- lines must answer 1 after the rollback. Used by deploy/rehearse-migrations.sh --checks.
-- On a production copy both start empty: the call_rules flag is off for everyone, so nobody has rules or settings yet.
select count(*) = 1 from information_schema.tables where table_schema = database() and table_name = 'call_rules';
select count(*) = 0 from call_rules;
select count(*) = 1 from information_schema.statistics where table_schema = database() and table_name = 'call_rules' and index_name = 'call_rules_user_id_kind_match_key_unique' and column_name = 'match_key';
select count(*) = 1 from information_schema.columns where table_schema = database() and table_name = 'users' and column_name = 'call_settings';
select count(*) = 0 from users where call_settings is not null;
-- after-rollback: select count(*) = 0 from information_schema.tables where table_schema = database() and table_name = 'call_rules';
-- after-rollback: select count(*) = 0 from information_schema.columns where table_schema = database() and table_name = 'users' and column_name = 'call_settings';
