-- A member may choose light or dark mode, or to follow the device ('system').
-- NULL means they never chose: the choice made before sign-in on that device applies.

ALTER TABLE members ADD COLUMN color_mode TEXT NULL;
