-- When the app last read the member file (config/members.csv), as a UTC timestamp. Empty until
-- the first import, which runs by itself the first time the file is there.

INSERT INTO settings (key, value) VALUES ('member_import_at', '');
