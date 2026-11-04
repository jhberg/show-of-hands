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
2. Unpack it into an empty folder on the web space, for example `vote/`. The ZIP holds the folders `public/`, `src/`, `config/`, `data/`, and a few more.
3. Point the document root of your (sub)domain at the `public/` folder inside it, for example `vote/public`. In Plesk, this is the subdomain's "Document root" under "Hosting settings". Choose PHP 8.3, and switch on HTTPS with a certificate.
4. Make sure PHP may write to `data/`. The app keeps its database, sessions, and caches there.
5. Copy `config/config.example.php` to `config/config.php` and fill in the values. See [Configuration](#configuration).
6. Open the site. The first request creates the database, the default voice groups, and the first member from `bootstrap_admin`, with board and admin roles.
7. Sign in as that member, open "Verwaltung" (Admin), and add the members. To add them all at once, put a member file next to the config first (see [Member file](#member-file)). Where a shell exists, `bin/console member:add` adds members as well.

The app checks `config/config.php` on every request. If a value is missing or wrong, the page lists every problem with the key's name.

Only `public/` is reachable from the web. Code, configuration, and data sit next to it, outside the document root. `data/` carries its own `.htaccess` that denies all access, should a host serve the whole folder anyway.

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
| Header logo | `public/brand/header-logo.svg` or `.png` (optional). Without one, the header shows the wordmark. |
| Favicon | `public/brand/favicon.png` |
| Fonts | `public/brand/fonts/*.woff2`, listed in `brand.font.files` |
| Wording | `config/translations/messages.de.yaml` and `messages.en.yaml`. List only the keys you change. |

The app derives its whole colour scheme, light and dark, from the accent and ink colours. `bin/console brand:check` prints the contrast of every text and background pair. It fails when a pair is below its minimum: 4.5:1 for text, 3:1 for the focus ring.

## Updates

1. Download the new release ZIP. Its `VERSION` file names the version, as does the one in your installation.
2. Pick a quiet moment, not a vote during rehearsal: while the files are being replaced, a request can meet old and new code at once.
3. Unpack the ZIP over the installation and let it replace existing files. The ZIP contains no `config/config.php`, no `config/members.csv`, no `public/brand/`, and no database, so your configuration, branding, and data stay.
4. Open the site. The first request copies the database to `data/backup/app-v<N>.sqlite` and brings the schema up to date. Housekeeping deletes that copy a day later.

If the update fails, the page shows an error and the log in `data/logs/app.log` names it. To go back, unpack the previous release and copy the backup over `data/app.sqlite`. While the migration fails, housekeeping keeps the backup. Files a release no longer uses may stay behind after an update. They do no harm.

Read the release notes before you update. A new setting in `config.example.php` is optional unless the notes say otherwise.

## Privacy

The app keeps the secret ballot and stores as little as it can.

**What it stores.** Members with their name, up to two email addresses, voice group, roles, and chosen language. Polls with their name, question, note, and date. For each poll: who has voted, who delegated their vote to whom, and the ballots. Ballots carry no member: each holds only a token and the choice. The token is an HMAC of the poll and the member, keyed with `app.secret`, so the member can change their own vote and nobody can read the choice from the database alone. The member file `config/members.csv` holds names and addresses as well. Delete it once you no longer need to import from it.

**What others see.** Nobody sees a count while a poll is open: not members, not the board, not admins. The app counts once, when the board closes the poll. Members see who has voted and who delegated to whom, as in a show of hands. Admins can hide from members who has not voted yet. The board and admins always see it.

**How long it keeps it.** Housekeeping runs on requests, at most once an hour; hosts with cron can run `bin/console purge` instead.

- Ballots: deleted once the closing day of their poll has ended. Until then, an admin may reopen the poll. Afterwards, only the frozen result counts.
- Closed polls, with their result and turnout: deleted after the retention period the admins choose (7, 14, or 30 days).
- Sessions: deleted after `session.lifetime_days` without a visit.
- The database copy an update makes: deleted a day after the update.

SQLite runs with `secure_delete`, so deleted ballots leave no trace in the file.

**The cost of changeable votes.** Whoever holds both the database and `config/config.php`, such as the web host's administrator, can recompute the tokens of an open or just-closed poll and read who voted what. Deleting ballots the day after closing keeps that window short. Keep both files off shared drives and out of backups you hand around.

**Cookies and outside services.** The app sets a session cookie (`HttpOnly`, `Secure`, `SameSite=Lax`) and, once someone switches the language, a language cookie. It loads no third-party scripts or fonts and sends no analytics. The only outside service is Google, for sign-in. The choice travels only in a form's POST body, so it never appears in a URL or a server's access log. The app's log records errors without request data.

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

The upgrade test builds the release ZIPs of both refs, installs the first with sample data and a vote, unpacks the second over it, and checks the migration, the data, the templates, and the deletion of the backup.

GitHub Actions runs `composer check`, `composer test`, the Playwright journeys, and the upgrade test from the last release tag on pushes to `main` and on pull requests. A tag `v<version>` also builds the release ZIP and publishes it as a GitHub release.

Set `APP_PORT` to serve on another port than 8080.

## License

Apache License 2.0, see [LICENSE](LICENSE).
