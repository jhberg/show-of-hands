-- Polls, the secret ballots, and who has voted.

-- AUTOINCREMENT keeps ids from being reused after retention deletes a poll, so an old link
-- never points at a new poll. eligible … delegation_count hold the tally frozen at close.
CREATE TABLE polls (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    kind TEXT NOT NULL CHECK (kind IN ('admission', 'exception')),
    subject_name TEXT NOT NULL,
    question TEXT NULL,
    voice_group_key TEXT NOT NULL REFERENCES voice_groups (key),
    date TEXT NULL,
    note TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL CHECK (status IN ('draft', 'open', 'closed')),
    created_at TEXT NOT NULL,
    opened_at TEXT NULL,
    closed_at TEXT NULL,
    reopened_at TEXT NULL,
    reopen_count INTEGER NOT NULL DEFAULT 0,
    eligible INTEGER NULL,
    yes_weight INTEGER NULL,
    no_weight INTEGER NULL,
    cast_weight INTEGER NULL,
    delegation_count INTEGER NULL
);

-- The secret ballot: no member id, no timestamp, no row id. The token is an HMAC of
-- (poll id, member id) under the app secret, so only the member's own request finds the row.
CREATE TABLE ballots (
    poll_id INTEGER NOT NULL REFERENCES polls (id) ON DELETE CASCADE,
    token BLOB NOT NULL,
    choice TEXT NOT NULL CHECK (choice IN ('yes', 'no')),
    PRIMARY KEY (poll_id, token)
) WITHOUT ROWID;

-- Turnout: who has voted, never what. Written without a timestamp and kept apart from
-- ballots, so the order of rows links no member to a choice.
CREATE TABLE participation (
    poll_id INTEGER NOT NULL REFERENCES polls (id) ON DELETE CASCADE,
    member_id INTEGER NOT NULL REFERENCES members (id) ON DELETE CASCADE,
    PRIMARY KEY (poll_id, member_id)
) WITHOUT ROWID;
