-- members.locale and members.color_mode accept only the values the app writes, like every other
-- enum-like column. SQLite cannot add a CHECK to an existing column, so the table is rebuilt:
-- create, copy, drop, rename. The migrator turns foreign keys off around every migration, so
-- dropping the old table keeps the addresses, delegations, and turnout that reference it.
-- A new UI language needs a migration like this one that adds it to the locale CHECK.

-- A value the app never writes becomes NULL: the default language, and the device's colour mode.
UPDATE members SET locale = NULL WHERE locale NOT IN ('de', 'en');
UPDATE members SET color_mode = NULL WHERE color_mode NOT IN ('light', 'dark', 'system');

CREATE TABLE members_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    voice_group_key TEXT NULL REFERENCES voice_groups (key),
    is_board INTEGER NOT NULL DEFAULT 0 CHECK (is_board IN (0, 1)),
    is_admin INTEGER NOT NULL DEFAULT 0 CHECK (is_admin IN (0, 1)),
    locale TEXT NULL CHECK (locale IN ('de', 'en')),
    is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    display_name TEXT NULL,
    color_mode TEXT NULL CHECK (color_mode IN ('light', 'dark', 'system'))
);

INSERT INTO members_new (id, name, voice_group_key, is_board, is_admin, locale, is_active, display_name, color_mode)
SELECT id, name, voice_group_key, is_board, is_admin, locale, is_active, display_name, color_mode FROM members;

-- The id counter moves to the new table, so a new member never gets the id of a removed one.
DELETE FROM sqlite_sequence WHERE name = 'members_new';
UPDATE sqlite_sequence SET name = 'members_new' WHERE name = 'members';

DROP TABLE members;
ALTER TABLE members_new RENAME TO members;
