# Changelog

Each release has a section here. The release workflow publishes a version's section as the notes of its GitHub release. Read it before you update: it says what changes for members and admins, and whether an update needs more than unpacking the ZIP.

## 0.1.0

The first release.

**For members**

- Sign in with Google. Up to two addresses per member, for example the choir account and a private one.
- Vote Yes or No on admitting a new singer after an audition, and on an exception during probation. Change the vote until the board closes the poll.
- Delegate the vote to another member, if the admins allow it, and take the delegation back.
- See who has voted and who delegated to whom, as in a show of hands. Nobody sees a count before the poll closes.
- The poll page updates every 15 seconds while it is open on screen. A tap that fails shows a message.
- German and English, and a light, dark, or system colour mode, per member.

**For the board and admins**

- Create, publish, close, and reopen polls. Reopening works on the closing day.
- The result counts once, at close, with the majority the admins chose: more than half, two thirds, or three quarters, of all eligible voters or of the votes cast.
- Manage members, voice groups, roles, and display names. Set members inactive, so open polls stop counting them. Import members from `config/members.csv` after a preview.
- While a poll is open or can still be reopened, admins cannot add a sign-in address to an existing member.
- Settings for delegation, the visibility of who has not voted yet, and how long closed polls are kept.

**For the web space**

- Runs on a shared PHP 8.3 web space with SQLite. No database server, no cron, no shell.
- Branding by configuration: name, colours, logos, font, and wording. `bin/console brand:check` checks the contrast.
- Housekeeping on requests deletes ballots the day after their poll closed, closed polls after the retention period, old sessions, and the copy an update makes.
- Updates migrate the database on the first request, after a copy to `data/backup/`. A failed update can go back to the previous release, see "Updates" in the README.
- `.htaccess` rules keep `config/` and `data/` private, should a host serve the whole installation folder.
