-- Contract 2.0.0-o (B12, Ask everywhere and each line's language): no migration, so there is nothing to rehearse or roll
-- back; each line must answer 1 on the production copy before the deploy and on production after it (the columns the
-- release relies on are as it expects).
-- On a production copy: an Ask about a recording or across recordings is a `client_ask` row of the usage ledger with no
-- project (client_id null) and, across recordings, no recording either, so both columns must stay nullable and
-- use_case must hold the name; a line's `language` lives in the transcripts' segments JSON, so nothing there changes.
select count(*) = 1 from information_schema.columns where table_schema = database() and table_name = 'usage_ledger' and column_name = 'client_id' and is_nullable = 'YES';
select count(*) = 1 from information_schema.columns where table_schema = database() and table_name = 'usage_ledger' and column_name = 'capture_session_id' and is_nullable = 'YES';
select count(*) = 1 from information_schema.columns where table_schema = database() and table_name = 'usage_ledger' and column_name = 'use_case' and character_maximum_length >= char_length('client_ask');
select count(*) = 1 from information_schema.columns where table_schema = database() and table_name = 'transcripts' and column_name = 'segments';
