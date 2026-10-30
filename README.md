# show-of-hands

Anonymous, self-hosted voting for choirs. Members vote on admitting a new singer after an audition, and on exceptions during probation. The result needs a two-thirds majority of all members and stays hidden until the board closes the poll.

Each choir runs its own copy on an ordinary PHP web space, with its own name, logo, colours, and wording, in German and English.

> Work in progress. The first release is not out yet. Sections marked *(to come)* fill in as the features land.

## Requirements

- PHP 8.3 with the extensions `pdo_sqlite`, `intl`, `mbstring`, and `openssl`, plus `curl` or `allow_url_fopen` for the call to Google
- A web space whose document root can point at the `public/` folder (for example a Plesk subdomain)
- A Google Cloud OAuth client (web application) with the redirect URI `https://<your-host>/auth/google/callback`

No database server, no Node, no cron job, and no shell are needed.

## Installation *(to come)*

1. Upload the release ZIP and point the document root at `public/`.
2. Copy `config/config.example.php` to `config/config.php` and fill in the values. Every key is documented in that file.
3. Open the site. The first request creates the database in `data/`, the default voice groups, and the first member from `bootstrap_admin` with board and admin roles. Where a shell exists, `bin/console member:add` adds members as well.

The app checks `config/config.php` on every request. If a value is missing or wrong, the page lists every problem with the key's name.

Members sign in with Google. The app asks Google only for the account's email address and lets in whoever is on the member list with that address. Up to two addresses per member are possible, for example the choir account and a private one.

Housekeeping runs on requests, at most once an hour. It deletes expired session files and, a day after an update succeeded, the database backup the update made. Once the closing day of a poll has ended, it deletes the poll's ballots and keeps the result. Hosts with cron can run `bin/console purge` instead.

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

## Privacy *(to come)*

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
```

Set `APP_PORT` to serve on another port than 8080.

## License

Apache License 2.0, see [LICENSE](LICENSE).
