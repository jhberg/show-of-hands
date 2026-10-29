-- Voice groups, members with their sign-in addresses, and the settings admins can change.

CREATE TABLE voice_groups (
    key TEXT PRIMARY KEY CHECK (length(key) BETWEEN 1 AND 4 AND key NOT GLOB '*[^A-Z0-9]*'),
    label TEXT NOT NULL,
    position INTEGER NOT NULL
);

CREATE TABLE members (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    voice_group_key TEXT NULL REFERENCES voice_groups (key),
    is_board INTEGER NOT NULL DEFAULT 0 CHECK (is_board IN (0, 1)),
    is_admin INTEGER NOT NULL DEFAULT 0 CHECK (is_admin IN (0, 1)),
    locale TEXT NULL
);

-- Lowercased. Position 0 is the first address, 1 the optional second one.
CREATE TABLE member_emails (
    email TEXT PRIMARY KEY,
    member_id INTEGER NOT NULL REFERENCES members (id) ON DELETE CASCADE,
    position INTEGER NOT NULL CHECK (position IN (0, 1)),
    UNIQUE (member_id, position)
);

CREATE TABLE settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

INSERT INTO settings (key, value) VALUES
    ('delegation_enabled', '1'),
    ('max_received', '2'),
    ('show_open_names', '1'),
    ('retention_days', '14'),
    ('maintenance_last_run', '0');
