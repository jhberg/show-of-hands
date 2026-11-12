# Changelog

Every merged pull request is a version, with a section here, newest first. The CI tags the version and publishes its section as the notes of its GitHub release, with the release ZIP. The release also shows the date. Read the sections between your version and the new one before you update: they say what changes for members and admins, and whether an update needs more than unpacking the ZIP.

0.1.0 is the first release. The versions 0.0.1 to 0.0.23 are development states before it, without a release. 0.1.1 to 0.3.1 were tagged afterwards and have no release either. `bin/build-release <tag>` builds the ZIP of any tag.

## 0.5.0

### Changed

- The app runs from the document root itself. Unpack the ZIP straight into the folder the domain serves: hosts that let PHP read only inside that folder can run the app now. The folder `public/` is gone: `index.php` and `assets/` lie in the installation folder, and the instance's logos and fonts move from `public/brand/` to `brand/`.
- The `.htaccess` in the installation folder lets the web reach only the files in `assets/` and `brand/`. Every other address goes to the app, which answers "not found" for files in `config/`, `data/`, `src/`, and the other folders. Without `mod_rewrite`, the site answers nothing rather than hand out files.
- If the app sits in a folder below the document root, it stops with a message that says how to fix it. The document root as the installation folder no longer stops it.
- An update from 0.4.0 needs more than unpacking the ZIP: move `public/brand/` to `brand/`, delete `public/`, and point the document root at the installation folder. The README's section "From 0.4.0 or earlier" has the details.

## 0.4.0

### Added

- Every merged change becomes a version: the CI tags it and publishes a GitHub release with the ZIP and its section of this file.
- After going back to an older release without moving the copy from `data/backup/` over the database, every page stops with a message that names the copy to move. Before, the older release ran on a database newer than itself.
- If the web server serves the installation folder instead of its `public/` folder, the app stops with a message that says how to set the document root in Plesk. The installation folder's own address shows that message instead of an error 403.

### Changed

- The copy an update makes stays in `data/backup/` for 7 days instead of one.
- The README says where the installation folder goes, why Plesk must not serve static files through nginx, and which file modes `config/config.php` and `config/members.csv` need.

## 0.3.1

### Changed

- Nothing changes for the app. The project's shared demo pages now use the app's default colours and icon.

## 0.3.0

### Added

- If PHP cannot write to `data/`, the page says which folders to fix, instead of a PHP warning with a server path.

### Changed

- Files the app creates in `data/` are readable by its own user only. The update also restricts a database that an earlier release created. Hosts that forbid changing file modes keep their modes.
- Each line in the error log names the request's method and path. The log keeps at most about 2 MB: past 1 MB it moves to `app.log.1`.
- Pages render faster: the app caches the translations and its service container per release, and browsers keep the scripts and styles for a year. An edited translation override in `config/translations/` or an edited `config.php` still takes effect at once.
- The README says that maintenance deletes only closed polls. Drafts and polls nobody closes stay until an admin deletes or closes them.

### Fixed

- A poll page is now the same size after a Yes vote as after a No vote, also in the 15-second refresh and the vote message. Before, someone who reads the web server's access logs with response sizes could tell the two choices apart.

## 0.2.0

### Added

- A "Skip to content" link before the header, visible when it gets the keyboard focus.
- A refused delegation without a chosen person shows its message at the field, not only in a short toast.

### Changed

- The keyboard focus stays in place after delegating, taking a delegation back, closing, publishing, and reopening a poll. Before, it fell back to the top of the page.
- Form fields have stronger borders in light and dark mode, with at least 3:1 contrast for every brand. `bin/console brand:check` checks it.
- On touch screens, the back link and the brand link are large enough to tap.
- A long choir name in the header ends with an ellipsis.
- The phone menu closes when the keyboard focus leaves it.
- A closed poll's turnout no longer says that votes are still missing.
- Screen readers read the poll's name, question, and note in the choir's language, also on English pages.

### Fixed

- A long word or web address in a poll note no longer makes the poll page wider than the phone screen.
- Without JavaScript, a toast now goes away after a few seconds.
- Without JavaScript, the poll form shows the question field as soon as an admin picks the exception kind.

## 0.1.1

### Changed

- The database now accepts only the app's own values for a member's language and colour mode. The update clears any other value.
- Updates check every reference between tables before they commit, and refuse a migration that would break one.
- Dependencies are updated to fix two published security advisories. The build now fails on a new advisory.
- The README explains how the vote stays secret: what the database holds, when counting happens, what the board and admins see, and when ballots go.
- Tests: concurrent writes in two processes, the real Google sign-in address, and the vote and delegation journeys in Safari's engine (WebKit).

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

## 0.0.23

### Fixed

- While a poll is open, or was closed today and can still be reopened, admins cannot add a sign-in address to an existing member. Before, an admin could add an address of their own and then read or change that member's vote. New members can be added at any time.

## 0.0.22

### Fixed

- A tap that fails shows "Not saved, please tap again." Before, nothing happened.
- An expired session reloads the page and leads to the sign-in, which returns to the poll.
- A busy database answers with a page that asks to try again, instead of an internal error.

## 0.0.21

### Added

- Members choose Light, Dark, or Match system next to the language flag, in the header and on the login page. The app saves the choice for signed-in members.

## 0.0.20

### Added

- Optional dark-mode logos, `public/brand/logo-dark` and `header-logo-dark`, shown on devices in dark mode. `bin/console brand:check` lists them.

## 0.0.19

### Changed

- The member list shows edit, set inactive, and remove as icons that line up from row to row.
- The pills read inactive, board, admin, voice group, so the voice group always sits next to the buttons.

## 0.0.18

### Changed

- On the desktop, every page has the header's width.
- The settings show one setting per row. The majority splits into "Required majority" and "Counted from".

## 0.0.17

### Changed

- On phones, the header fits into one row down to 320 px.
- A flag shows the language in use and switches to the other one. Sign-out is an icon.
- Only admins see a navigation. On phones, admins open the admin sections from a menu.

## 0.0.16

### Changed

- Tests: the live refresh journey runs after the vote journeys, so it no longer fails now and then.

## 0.0.15

### Added

- Members can have a display name, a shorter name for the name lists. The admin area keeps the full name.
- The member file reads an optional `display_name` column. A re-import fills in a missing display name and never overwrites one.

## 0.0.14

### Added

- The member file reads an optional `inactive` column.

### Fixed

- The import reads CSV files that Excel for Mac saves, so umlauts and accents come out right.

## 0.0.13

### Fixed

- Admins cannot remove a member who voted, delegated, or received a vote in an open poll. Before, removing a member took their vote out of a running poll.

## 0.0.12

### Added

- Admins set members inactive and active again. Open polls count only active members.
- A member who took part in an open poll stays active until it closes, so nobody can take a cast vote out of the count.

## 0.0.11

### Added

- Import members from `config/members.csv`. A new installation reads it on the first request. Later imports show a preview first and only add members.
- The import reads commas or semicolons, and UTF-8 or Windows-1252, so a spreadsheet export works as is.

## 0.0.10

### Added

- A release ZIP that unpacks over an existing installation without touching its instance files.
- The README covers installation on a web space, Google sign-in, configuration, updates, and privacy.

### Changed

- The cancel link of a confirmation returns the keyboard focus to the control that opened it.
- Tests: an upgrade test, security header checks on every page, accessibility checks in light and dark mode, and CI.

### Fixed

- After an update, pages no longer show the previous release's templates.

## 0.0.9

### Added

- Admins create, edit, publish, and delete polls in the admin area. An open poll only lets them change its note and date.
- Admins reopen a poll on the day it closed. The poll page and the result say that it was reopened.
- Closed polls are deleted after the retention period.

## 0.0.8

### Added

- The poll page refreshes every 15 seconds while it is visible, so new votes and the result appear without a reload.

## 0.0.7

### Fixed

- A field filled right after a refused save no longer loses its text to another field.

## 0.0.6

### Added

- Admin settings: majority, vote delegation and its limit, who sees who has not voted yet, and the retention period.
- The majority is more than half, two thirds, or three quarters, of all eligible voters or of the votes cast. Each poll keeps the rule it was created with.
- Admins rename, sort, add, and remove voice groups.

## 0.0.5

### Added

- Admins add, edit, and remove members in the admin area. The member list is also the list of who can sign in.
- Admins cannot remove themselves or the address they are signed in with.
- A member who took part in a poll closed today can only be removed from the next day.

## 0.0.4

### Added

- Board members close a poll. The result then replaces the ballot for everyone.
- The home page lists closed polls with their verdict.
- The app deletes a closed poll's ballots the day after it closed. The result stays.

## 0.0.3

### Added

- Members delegate their vote to another member on the poll page, and take it back.
- Everyone sees who delegated to whom. A delegated vote counts only once its recipient has voted.

## 0.0.2

### Added

- Members vote Yes or No on the poll page and change their vote until the poll closes.
- The poll page shows the turnout and who has not voted yet.
- The home page lists the open polls with the member's own status.

## 0.0.1

### Added

- Members sign in with Google. A poll link opened while signed out leads back to the poll after sign-in.
- On first start, the app creates the admin named in `config.php`.
- German and English, and a home page that greets the member.
