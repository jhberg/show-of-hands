-- Members can be set inactive for a while. An inactive member stays on the member list and can
-- sign in, but is no eligible voter: they cannot vote, delegate, or receive a vote, and the open
-- polls no longer count them. Everyone starts active.

ALTER TABLE members ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1));
