-- The majority becomes a setting. Each poll keeps the rule in force when it was created, so a
-- change never alters a running or closed poll. Existing polls keep two thirds of all eligible voters.

ALTER TABLE polls ADD COLUMN majority TEXT NOT NULL DEFAULT 'two_thirds'
    CHECK (majority IN ('half', 'two_thirds', 'three_quarters'));
ALTER TABLE polls ADD COLUMN majority_base TEXT NOT NULL DEFAULT 'eligible'
    CHECK (majority_base IN ('eligible', 'cast'));

INSERT INTO settings (key, value) VALUES
    ('majority', 'two_thirds'),
    ('majority_base', 'eligible');
