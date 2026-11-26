-- W-INC-1124 (migration 2026_12_29_000001 assembly bound, fix/W-required-extensions): each line must answer 1 after
-- migrate; "-- after-rollback:" lines must answer 1 after the rollback. Used by deploy/rehearse-migrations.sh --checks.
-- After migrate: recordings.assembling_since nullable timestamp, empty; recordings.recovery_rounds unsigned tinyint,
-- not null, default 0, 0 everywhere; recordings has exactly its columns from production plus these two. After the
-- rollback: both gone, the rest as before.
select count(*) = 1 from information_schema.columns where table_schema = database() and table_name = 'recordings' and column_name = 'assembling_since' and is_nullable = 'YES' and data_type = 'timestamp';
select count(*) = 0 from recordings where assembling_since is not null;
select count(*) = 1 from information_schema.columns where table_schema = database() and table_name = 'recordings' and column_name = 'recovery_rounds' and is_nullable = 'NO' and column_default = '0' and column_type like 'tinyint%unsigned';
select count(*) = 0 from recordings where recovery_rounds <> 0;
select group_concat(column_name order by cast(column_name as binary) separator ',') = 'assembled_at,assembling_since,capture_session_id,channels,chunk_size_bytes,codec,created_at,declared_size_bytes,duration_ms,error,id,key_id,mime,received_chunks,recovery_rounds,role,sample_rate,sha256,stored_path,stored_size_bytes,total_chunks,updated_at,upload_started_at,upload_status' from information_schema.columns where table_schema = database() and table_name = 'recordings';
-- after-rollback: select group_concat(column_name order by cast(column_name as binary) separator ',') = 'assembled_at,capture_session_id,channels,chunk_size_bytes,codec,created_at,declared_size_bytes,duration_ms,error,id,key_id,mime,received_chunks,role,sample_rate,sha256,stored_path,stored_size_bytes,total_chunks,updated_at,upload_started_at,upload_status' from information_schema.columns where table_schema = database() and table_name = 'recordings';
