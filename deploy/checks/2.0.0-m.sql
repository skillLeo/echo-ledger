-- Contract 2.0.0-m (migration 2026_12_25_000001, lists): each line must answer 1 after migrate; "-- after-rollback:"
-- lines must answer 1 after the rollback. Used by deploy/rehearse-migrations.sh --checks.
-- On a production copy: the table is there with its keys, every coloured label kept its colour on its list, positions
-- count from 0 without repeats for each person, colours are lower case #rrggbb, and no list has a fixed chip's name.
-- label_colors is left as it was.
select count(*) = 1 from information_schema.tables where table_schema = database() and table_name = 'label_lists';
select collation_name = 'utf8mb4_bin' and character_maximum_length = 160 from information_schema.columns where table_schema = database() and table_name = 'label_lists' and column_name = 'label_key';
select count(*) = 1 from information_schema.statistics where table_schema = database() and table_name = 'label_lists' and index_name = 'label_lists_workspace_id_user_id_label_key_unique' and seq_in_index = 1;
select count(*) = 0 from label_colors lc join label_lists ll on ll.workspace_id = lc.workspace_id and ll.user_id = lc.user_id and ll.label_key = lc.label_key where not (ll.color <=> lower(lc.color));
select count(*) = 0 from (select workspace_id, user_id, position from label_lists group by workspace_id, user_id, position having count(*) > 1) repeated;
select count(*) = 0 from (select workspace_id, user_id from label_lists group by workspace_id, user_id having min(position) <> 0 or max(position) <> count(*) - 1) gaps;
select count(*) = 0 from label_lists where color is not null and (cast(color as binary) <> cast(lower(color) as binary) or color not regexp '^#[0-9a-f]{6}$');
select count(*) = 0 from label_lists where label_key in ('all', 'unread', 'important', 'سب', 'نہ پڑھی گئی', 'اہم');
select count(*) = 1 from information_schema.tables where table_schema = database() and table_name = 'label_colors';
-- after-rollback: select count(*) = 0 from information_schema.tables where table_schema = database() and table_name = 'label_lists';
-- after-rollback: select count(*) = 1 from information_schema.tables where table_schema = database() and table_name = 'label_colors';
