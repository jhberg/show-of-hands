-- Vote delegations: a member hands their vote in one poll to another member. Delegations are
-- visible to every member, so unlike the ballot this table may name both sides.
CREATE TABLE delegations (
    poll_id INTEGER NOT NULL REFERENCES polls (id) ON DELETE CASCADE,
    from_member_id INTEGER NOT NULL REFERENCES members (id) ON DELETE CASCADE,
    to_member_id INTEGER NOT NULL REFERENCES members (id) ON DELETE CASCADE,
    PRIMARY KEY (poll_id, from_member_id),
    CHECK (from_member_id <> to_member_id)
) WITHOUT ROWID;

CREATE INDEX delegations_to ON delegations (poll_id, to_member_id);
