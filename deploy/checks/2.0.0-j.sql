-- Contract 2.0.0-j (migration 2026_12_23_000001, audio only): each line must answer 1 after migrate; "-- after-rollback:"
-- lines must answer 1 after the rollback. Used by deploy/rehearse-migrations.sh --checks.
select count(*) = 1 from information_schema.columns where table_schema = database() and table_name = 'capture_sessions' and column_name = 'transcribe';
select count(*) = 0 from capture_sessions where transcribe = 0;
select count(*) = 0 from capture_sessions where status = 'audio_only';
select count(*) = 1 from information_schema.columns where table_schema = database() and table_name = 'clients' and column_name = 'transcribe_calls';
select count(*) = 0 from clients where transcribe_calls <> 'ask';
-- after-rollback: select count(*) = 0 from information_schema.columns where table_schema = database() and table_name = 'capture_sessions' and column_name = 'transcribe';
-- after-rollback: select count(*) = 0 from capture_sessions where status = 'audio_only';
