# show-of-hands

Anonymous, self-hosted voting for choirs. Members vote on admitting a new singer after an audition, and on exceptions during probation. The result needs the majority the admins configure (by default two thirds of all members) and stays hidden until the board closes the poll.

Each choir runs its own copy on an ordinary PHP web space, with its own name, logo, colours, and wording, in German and English.

## Requirements

- PHP 8.3 with the extensions `pdo_sqlite`, `intl`, `mbstring`, and `openssl`, plus `curl` or `allow_url_fopen` for the call to Google
- A web space whose document root can point at the `public/` folder (for example a Plesk subdomain), served over HTTPS
- A Google Cloud OAuth client (see [Google sign-in](#google-sign-in))

No database server, no Node, no cron job, and no shell are needed.

## Installation

1. Download `show-of-hands-<version>.zip` from the repository's Releases page.
2. Unpack it into an empty folder on the web space, next to the web folder of your main site, not inside it: in Plesk, for example `vote.example.org/` beside `httpdocs/`, not `httpdocs/vote/`. Inside another site's folder, that site would serve the code and the configuration, and only the `.htaccess` rules would hold them back. The ZIP holds the folders `public/`, `src/`, `config/`, `data/`, and a few more.
3. Point the document root of your (sub)domain at the `public/` folder inside it, for example `vote.example.org/public`. In Plesk, this is the subdomain's "Document root" under "Hosting settings". Choose PHP 8.3, and switch on HTTPS with a certificate. If the document root is the installation folder or a folder above it, the app stops and says how to fix it.
4. Make sure PHP may write to `data/`. The app keeps its database, sessions, log, and caches there. What it creates there, only the user PHP runs as may read: folders get mode 700, the database 600, where the host allows it. Where PHP runs as your own account, as on Plesk, your FTP access still reaches them for [Backups](#backups); where PHP runs as another user, ask your host how to download the database. If PHP cannot write to `data/`, the page names the folders and says what to change.
5. Copy `config/config.example.php` to `config/config.php` and fill in the values. See [Configuration](#configuration). Give it mode `600`, or `640` where PHP runs as another user in your group, and the member file `config/members.csv` as well: they hold the app's secret, and names with addresses.
6. Open the site. The first request creates the database, the default voice groups, and the first member from `bootstrap_admin`, with board and admin roles.
7. Sign in as that member, open "Verwaltung" (Admin), and add the members. To add them all at once, put a member file next to the config first (see [Member file](#member-file)). Where a shell exists, `bin/console member:add` adds members as well.

The app checks `config/config.php` on every request. If a value is missing or wrong, the page lists every problem with the key's name.

Only `public/` is reachable from the web. Code, configuration, and data sit next to it, outside the document root. Should a host serve the whole folder anyway, the installation folder's `.htaccess` lets only `public/` through. `config/` and `data/` also carry their own `.htaccess` that denies all access, for hosts without `mod_rewrite`.

In Plesk, leave "Serve static files directly by nginx" switched off under "Apache & nginx Settings". With it on, nginx answers requests for files such as `.css` or `.png` before Apache sees them, and nginx reads no `.htaccess` file. Whether it then also serves `.csv`, `.sqlite`, or `.php` files as plain files depends on the list of extensions there; it has not been tested.

## Google sign-in

Members sign in with Google. The app asks Google only for the account's email address and lets in whoever is on the member list with that address. Up to two addresses per member are possible, for example the choir account and a private one.

In the [Google Cloud Console](https://console.cloud.google.com/):

1. Create a project, for example "Choir voting".
2. Under "Google Auth Platform", set up the branding (the consent screen): the app name members see, a support email, and your domain.
3. Under "Audience", choose the user type:
   - **External** lets any Google account sign in, private addresses included. Publish the app ("In production"). The app asks only for `openid` and `email`, which need no verification by Google. While it stays in "Testing", only the test users you list can sign in.
   - **Internal** exists only for a Google Workspace. It admits accounts of your Workspace and nobody else, so members' private addresses cannot sign in.
4. Under "Clients", create an OAuth client of type "Web application". Add the authorized redirect URI `https://<your-host>/auth/google/callback`, with your host exactly as in `app.url`.
5. Copy the client ID and the client secret into `google.client_id` and `google.client_secret`.

Sign-in uses the authorization code flow with PKCE. No Google script runs on the app's pages. The app calls Google only to exchange the code and to read the address.

## Configuration

`config/config.php` returns one array. `config/config.example.php` documents every key next to its value.

| Key | Meaning |
|---|---|
| `app.url` | The address of the site, such as `https://vote.example.org`. No path, no trailing slash. Links and the Google redirect use it. An `https` address also switches on HSTS and secure cookies. |
| `app.secret` | At least 32 random bytes as hex, for example from `openssl rand -hex 32`. It keys the ballot tokens: never change it while polls exist. |
| `app.timezone` | The choir's time zone, for dates on screen and for "the same day" rules. |
| `app.env` | `prod`. `dev` turns on the development login and works only with a `localhost` address. |
| `locale.default` | `de` or `en`, for members who have not picked a language. |
| `google.client_id`, `google.client_secret` | The OAuth client from [Google sign-in](#google-sign-in). |
| `google.workspace_domain` | Optional. Shown as the placeholder for email addresses. |
| `brand.*` | Name, wordmark, colours, and font. See [Branding](#branding). |
| `bootstrap_admin` | Name, email, and voice group key of the first member. Used only while the member list is empty. |
| `session.lifetime_days` | Members stay signed in this many days after their last visit. |

Admins change everything else in the app, under "Verwaltung" → "Einstellungen": the majority, whether members may delegate their vote and how many votes one person may receive, whether members see who has not voted yet, how long closed polls are kept, and the voice groups.

## Member file

The app can read the member list from `config/members.csv`, a CSV file next to `config/config.php`. `config/members.example.csv` is the template:

```csv
name;display_name;email;email2;group;board;admin;inactive
Alex Example;;alex.example@example.org;;S1;x;x;
Samantha Sample-Smith;Sam S.;sam.sample@example.org;sam.private@example.org;A2;x;;
Kim Beispiel;Kim B.;kim.beispiel@example.org;;B1;;;x
```

| Column | Meaning |
|---|---|
| `name` | Full name. Required. |
| `display_name` | Optional shorter name, such as `Sam S.`. Name lists show it instead of the full name. Empty for the full name. |
| `email` | The address the member signs in with. Required. |
| `email2` | Optional second address, for example a private Google account. |
| `group` | Voice group, by key (`S1`) or label (`Sopran 1`). Empty for none. |
| `board`, `admin` | `x` or `ja`/`yes` for the role, empty for none. |
| `inactive` | `x` or `ja`/`yes` to add the member as inactive, empty to add them active. |

The first line names the columns, in any order. Other columns are ignored. Mark at least one member as `admin`, or keep `bootstrap_admin` in the config, so someone can sign in to the admin area. Spreadsheets may save the file with commas or semicolons, in UTF-8, Windows-1252, or Mac Roman (Excel for Mac's "CSV (Macintosh)").

A new installation reads the file on its first request, after it creates the first member from `bootstrap_admin`. A file added later, or changed later, waits for an admin: under "Verwaltung" → "Mitglieder", "Datei erneut einlesen" first lists the members the file would add, the display names it fills in for members already on the list, and the lines it skips, with the reason, and imports only after the admin confirms. An import adds members and changes no one, with one exception: a member who has no display name yet gets the one from their line. A line whose address a member already uses leaves that member as it is otherwise, including a display name they already have and whether they are active. A member removed since the last import comes back if the file still lists them, so check the list before you confirm.

## Branding

The brand lives in files that updates never touch:

| What | Where |
|---|---|
| Name, wordmark, accent and ink colour, font | `brand` in `config/config.php` |
| Login logo | `public/brand/logo.svg` or `logo.png`. Without one, the login page shows the wordmark. |
| Login logo for dark mode | `public/brand/logo-dark.svg` or `.png` (optional). Devices in dark mode, and members who chose dark mode, see it in place of the login logo. Without one, they see the login logo. |
| Header logo | `public/brand/header-logo.svg` or `.png` (optional). Without one, the header shows the wordmark. |
| Header logo for dark mode | `public/brand/header-logo-dark.svg` or `.png` (optional). Devices in dark mode, and members who chose dark mode, see it in place of the header logo. |
| Favicon | `public/brand/favicon.png` |
| Fonts | `public/brand/fonts/*.woff2`, listed in `brand.font.files` |
| Wording | `config/translations/messages.de.yaml` and `messages.en.yaml`. List only the keys you change. |

The app derives its whole colour scheme, light and dark, from the accent and ink colours. `bin/console brand:check` prints the contrast of every text and background pair. It fails when a pair is below its minimum: 4.5:1 for text, 3:1 for the focus ring.

## Updates

1. Download the new release ZIP. Its `VERSION` file names the version, as does the one in your installation. Read its section in `CHANGELOG.md`. A new setting in `config.example.php` is optional unless the notes say otherwise.
2. Pick a quiet moment, not a vote during rehearsal: while the files are being replaced, a request can meet old and new code at once.
3. Download a copy of `data/app.sqlite`, as [Backups](#backups) describes.
4. Unpack the ZIP over the installation and let it replace existing files. The ZIP contains no `config/config.php`, no `config/members.csv`, no `config/translations/`, no `public/brand/`, and no database, so your configuration, branding, and data stay.
5. Open the site. If the release brings database changes, the first request copies the database to `data/backup/app-v<N>.sqlite` and then brings the schema up to date. `<N>` is the schema version before the update. Housekeeping deletes that copy 7 days later.

If the update fails, the page shows an error and the log in `data/logs/app.log` names it. Housekeeping keeps the copy in `data/backup/` while the database changes fail. It also keeps it after you unpacked the previous release, as long as the database is newer than that release. To go back:

1. Unpack the previous release ZIP over the installation.
2. Move `data/backup/app-v<N>.sqlite` to `data/app.sqlite`, replacing the file there. `<N>` is the number at the start of the last line in the previous release's `MIGRATIONS.txt` file, without leading zeros: `0009_…` means `app-v9.sqlite`. If no such copy exists, the release brought no database changes, or it made them more than 7 days ago and housekeeping deleted the copy. In the first case, skip this step. In the second, upload the copy you downloaded before the update as `data/app.sqlite`: everything since then is lost.
3. Open the site. It works as before the update, with the data it had then.

If you skip step 2, the site stops, from release 0.4.0 on: every page answers with an error that names the copy to move, until you move it or unpack the newer release again. The older release would otherwise run on a database it does not know.

Move the copy rather than copy it: the next update must make a fresh one. The newer release's migration files stay in `migrations/`. They do no harm: each release runs only the migrations its `MIGRATIONS.txt` file lists. Other files a release no longer uses may stay behind after an update as well.

## Backups

The app makes no backups of its own. The copy an update makes is gone 7 days later. Download your own copy of `data/app.sqlite`:

- before every update,
- and regularly, for example once a month and after you changed many members.

Download it at a quiet moment, when nobody votes, so the copy does not catch a write halfway. By FTP or SFTP, open the installation folder, then `data/`, and download `app.sqlite`. In Plesk, open "Files", go to `data/` inside the installation folder, and choose "Download" in the file's menu. If a file `app.sqlite-journal` lies next to it, a write is under way: wait a moment and download again.

The copy holds everything the database holds: the members with their addresses, the polls, who voted, and the ballots of open polls and of polls closed today. With `config/config.php`, anyone can read from it who voted what in those polls (see [How your vote stays secret](#how-your-vote-stays-secret)). Keep it private: on your own computer or an encrypted drive, not on a shared drive and not in an email.

To restore a copy, upload it as `data/app.sqlite`. Everything since the copy is lost. A copy from an older release works: the next request brings its schema up to date.

## How your vote stays secret

Within the choir, everyone sees who has voted. Nobody sees what they chose.

**What the database holds.** A vote goes into two tables that nothing links. `participation` records who has voted in which poll, for the turnout. `ballots` records the choices: each row holds only the poll, a token, and Yes or No. It has no member id, no timestamp, and no row number that would give away the order of the votes. The token is an HMAC of the poll and the member, keyed with `app.secret` from `config/config.php`. With it, the app finds a member's own ballot, so they can change their vote. Without the secret, a token does not tell whose ballot it is. A delegation records who handed their vote to whom, never a choice.

**When counting happens.** Only when the board closes the poll. While a poll is open, no page, admin view, or log line shows how many voted Yes or No, not even to admins. The only ballot the app reads then is the member's own, to show them their choice. The close counts all ballots once and freezes the result in the poll. Every vote, delegation, and close takes the database's write lock before it checks the poll, so no ballot slips in after the count.

**What the board and admins see.** The same as every member: the turnout, the delegations, and the result after the close. Where admins hide from members who has not voted yet, the board and admins still see it. Board members close polls; admins set up polls, members, and settings. Neither role sees a choice, or how many voted Yes or No before the close. An admin also cannot take a member's place: while a poll is open, or closed today and so still reopenable, admins cannot add or replace a sign-in address of an existing member, and cannot set a member inactive who took part.

**When ballots are deleted.** Once the closing day of their poll has ended. Until then, an admin can reopen a poll closed by mistake, which needs the ballots. Afterwards only the frozen result remains, until the retention period deletes the poll. A poll nobody closes keeps its ballots, and a draft its name, until the board closes it or an admin deletes it. SQLite runs with `secure_delete`, so a changed or deleted ballot leaves no trace in the file.

**Where the choice travels.** Only in the POST body of the form, so it never appears in a URL or a server's access log. Access logs record the size of each response, so a page is exactly as long after a Yes as after a No. Where the server compresses pages, the compressed sizes can still differ by a few bytes. Pages for signed-in members carry `Cache-Control: no-store`, and htmx keeps no copies of earlier pages. The app's log in `data/logs/app.log` records errors with the request's method and path, never its query, body, or a choice. Past 1 MB it moves to `app.log.1`, replacing the older one.

**The limit.** Whoever holds both the database and `config/config.php`, such as the web host's administrator, can recompute the tokens of an open poll or one closed today and read who voted what. That is the price of votes a member can change. Deleting ballots after the closing day keeps that window short. Keep both files off shared drives, and keep database copies private (see [Backups](#backups)).

## Privacy

The app stores as little as it can.

**What it stores.** Members with their name, up to two email addresses, voice group, roles, and chosen language and colour mode. Polls with their name, question, note, and date. For each poll: who has voted, who delegated their vote to whom, and the ballots, which carry no member (see [How your vote stays secret](#how-your-vote-stays-secret)). The member file `config/members.csv` holds names and addresses as well. Delete it once you no longer need to import from it.

**What others see.** Members see who has voted and who delegated to whom, as in a show of hands. Admins can hide from members who has not voted yet. Nobody sees how many voted Yes or No before the board closes the poll.

**How long it keeps it.** Housekeeping runs on requests, at most once an hour; hosts with cron can run `bin/console purge` instead.

- Ballots: deleted once the closing day of their poll has ended.
- Closed polls, with their result and turnout: deleted after the retention period the admins choose (7, 14, or 30 days).
- Sessions: deleted after `session.lifetime_days` without a visit.
- The database copy an update makes: deleted 7 days after the update. It holds the ballots of the polls that were open or closed that day when you updated, so those ballots stay in the copy up to 7 days longer than in the database. Copies you download yourself are yours to delete (see [Backups](#backups)).

**Cookies and outside services.** The app sets a session cookie (`HttpOnly`, `Secure`, `SameSite=Lax`) and, once someone switches the language or the colour mode, a cookie for that choice. It loads no third-party scripts or fonts and sends no analytics. The only outside service is Google, for sign-in.

## Development

The dev environment runs in Docker, so the machine needs no local PHP.

```bash
cp config/config.example.php config/config.php   # set app.url to http://localhost:8080, app.env to 'dev', and a secret
docker compose run --rm app composer install
docker compose run --rm app bin/console seed:demo # sample choir: 25 invented members in nine voice groups
docker compose up                                 # serves http://localhost:8080
```

With `app.env = 'dev'`, `/dev-login` signs you in as any member, or with any address to see the unknown-address error. Without a Google client in the config, the Google button leads there. The dev login answers only requests to `localhost` from loopback or from the ranges in `SHOW_OF_HANDS_DEV_PEERS`, which `compose.yaml` sets for Docker's port forwarding.

Commands run inside the service:

```bash
docker compose run --rm app composer check        # PHPStan, coding standard, Twig lint, translation check
docker compose run --rm app composer test         # unit and HTTP tests
docker compose run --rm app composer fix          # apply the coding standard
docker compose run --rm app bin/console brand:check
docker compose --profile e2e up --exit-code-from e2e e2e   # Playwright journeys against a seeded copy on port 8081
docker compose run --rm app bin/build-release [ref]        # release ZIP of a git ref into build/
docker compose run --rm app tests/upgrade/run.sh <from-ref> [to-ref]   # install one release, update to the next
```

The upgrade test builds the release ZIPs of both refs, installs the first with sample data and a vote, unpacks the second over it, and checks the migration, the data, the templates, the `.htaccess` files, and the deletion of the backup. Then it unpacks a broken update with a failing migration and goes back as [Updates](#updates) describes.

GitHub Actions runs `composer audit`, `composer check`, `composer test`, the Playwright journeys (in Chromium, and the voting journeys also in WebKit on an iPhone profile), and the upgrade test from the last tag on pushes to `main` and on pull requests.

Every merge to `main` publishes a release. Each pull request adds a section `## <version>` at the top of `CHANGELOG.md` with what it changes. A new or changed feature raises the middle number, a fix, docs, or tests only the last one. The job "Changelog entry" fails a pull request that leaves `CHANGELOG.md` alone, or whose version does not follow the latest one. When two pull requests take the same version, the second one meets a conflict in `CHANGELOG.md`: rebase it and raise its version. After the merge, once the checks pass, the job "Tag and release" tags the commit `v<version>`, builds the ZIP, and publishes it as a GitHub release with the section as its notes. Nobody tags or releases by hand. If the job fails by chance, run it again. If its cause needs a fix, the next pull request brings it with a version of its own, and the failed version stays without a tag. A version that already has a tag stops the job, as after a push straight to `main`. `bin/changelog` holds these rules.

Set `APP_PORT` to serve on another port than 8080.

## License

Apache License 2.0, see [LICENSE](LICENSE).
