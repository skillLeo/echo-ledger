-- Contract 2.0.0-i (migration 2026_12_21_000001): each line must answer 1 after migrate; "-- after-rollback:" lines
-- must answer 1 after the rollback. Used by deploy/rehearse-migrations.sh --checks.
select count(*) = 1 from information_schema.columns where table_schema = database() and table_name = 'capture_sessions' and column_name = 'important';
select count(*) = 0 from capture_sessions where important = 1;
select count(*) = 1 from information_schema.statistics where table_schema = database() and table_name = 'capture_sessions' and index_name = 'capture_sessions_user_id_important_index' and seq_in_index = 1;
select count(*) = 1 from information_schema.tables where table_schema = database() and table_name = 'label_colors';
select collation_name = 'utf8mb4_bin' and character_maximum_length = 160 from information_schema.columns where table_schema = database() and table_name = 'label_colors' and column_name = 'label_key';
select count(*) = 0 from label_colors;
-- after-rollback: select count(*) = 0 from information_schema.columns where table_schema = database() and table_name = 'capture_sessions' and column_name = 'important';
-- after-rollback: select count(*) = 0 from information_schema.tables where table_schema = database() and table_name = 'label_colors';
