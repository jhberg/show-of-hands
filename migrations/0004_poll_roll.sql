-- The turnout of a closed poll, taken at the close, so later changes to the member list do not
-- alter it. It names who voted, never what they chose. member_id and delegated_to_member_id
-- carry no foreign key: the snapshot outlives a member who is deleted later.
CREATE TABLE poll_roll (
    poll_id INTEGER NOT NULL REFERENCES polls (id) ON DELETE CASCADE,
    member_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    voice_group_key TEXT NULL,
    status TEXT NOT NULL CHECK (status IN ('voted', 'delegated', 'missing')),
    delegated_to_member_id INTEGER NULL,
    PRIMARY KEY (poll_id, member_id),
    CHECK ((status = 'delegated') = (delegated_to_member_id IS NOT NULL))
) WITHOUT ROWID;
