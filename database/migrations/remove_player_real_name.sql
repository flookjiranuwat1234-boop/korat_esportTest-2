-- Irreversibly removes optional real-name storage after a database backup.
ALTER TABLE players
    DROP COLUMN real_name,
    DROP COLUMN show_real_name;
