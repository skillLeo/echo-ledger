-- Contract 2.0.0-r, BE-06 (migration 2027_01_01_000001 avatars' colours): each line must answer 1 after migrate;
-- "-- after-rollback:" lines must answer 1 after the rollback. Used by deploy/rehearse-migrations.sh --checks.
-- After migrate: users.avatar_color, unsigned tinyint, not null, default 0; every account already there has the colour
-- of its id (id % 8), so all are 0 to 7. After the rollback: it is gone.
select count(*) = 1 from information_schema.columns where table_schema = database() and table_name = 'users' and column_name = 'avatar_color' and is_nullable = 'NO' and column_default = '0' and column_type like 'tinyint%unsigned';
select count(*) = 0 from users where avatar_color <> id % 8;
select count(*) = 0 from users where avatar_color > 7;
-- after-rollback: select count(*) = 0 from information_schema.columns where table_schema = database() and table_name = 'users' and column_name = 'avatar_color';
