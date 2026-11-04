-- A member may have a shorter display name, which name lists show instead of the full name.
-- NULL shows the full name.

ALTER TABLE members ADD COLUMN display_name TEXT NULL;
