-- Contract 2.0.0-k (migration 2026_12_24_000001, title from the person): each line must answer 1 after migrate;
-- "-- after-rollback:" lines must answer 1 after the rollback. Used by deploy/rehearse-migrations.sh --checks.
-- On a production copy: the column is there, no recording counts as renamed by a person yet (only renames from now on
-- set it), and no title changed (the person called is worked out when shown; nothing stored is rewritten).
select count(*) = 1 from information_schema.columns where table_schema = database() and table_name = 'capture_sessions' and column_name = 'title_by_person';
select count(*) = 0 from capture_sessions where title_by_person = 1;
select count(*) = 1 from information_schema.columns where table_schema = database() and table_name = 'capture_sessions' and column_name = 'title_by_person' and column_default in ('0', 'false');
-- after-rollback: select count(*) = 0 from information_schema.columns where table_schema = database() and table_name = 'capture_sessions' and column_name = 'title_by_person';
