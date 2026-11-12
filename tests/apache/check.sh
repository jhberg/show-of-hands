#!/usr/bin/env bash
# Asks Apache, which serves the checkout as its document root with the .htaccess files in force, for
# the app's pages, its assets, and the files that must stay private.
#
#   docker compose --profile apache up --exit-code-from apache-check apache-check
set -euo pipefail

url="http://localhost"
failed=0
fail() {
    echo "FAIL: $*" >&2
    failed=1
}

# Prints the status of a path, and keeps the body in /tmp/body.
status() { curl -s --path-as-is -o /tmp/body -w '%{http_code}' "$url$1"; }

for path in /login /assets/css/app.css /assets/js/app.js /assets/vendor/htmx-2.0.11.min.js /assets/brand-default/favicon.png /brand/check.svg; do
    code="$(status "$path")"
    [ "$code" = 200 ] || fail "$path answered $code, expected 200"
done
status /login >/dev/null
grep -q 'Example Choir' /tmp/body || fail "/login does not render the app"
# Apache, not the app, serves the assets: they carry the cache header of assets/.htaccess.
curl -s -D - -o /dev/null "$url/assets/css/app.css" | grep -qi '^cache-control: public, max-age=31536000, immutable' \
    || fail "/assets/css/app.css lacks the cache header of assets/.htaccess"

# Everything else goes to the app, which answers "not found". Apache itself refuses .htaccess files.
private=(
    /config/config.php /config/config.example.php /config/members.example.csv /config/
    /data/ /data/app.sqlite /data/logs/app.log
    /src/Paths.php /vendor/autoload.php /templates/layout.html.twig /translations/messages.de.yaml
    /migrations/ /bin/console /tests/apache/config.php /composer.json /composer.lock /README.md
    /.git/config /.htaccess /config/.htaccess /assets/missing.css /brand/missing.svg
    /assets/../config/config.php /assets/%2e%2e/config/config.php /brand/../data/app.sqlite
)
for path in "${private[@]}"; do
    code="$(status "$path")"
    case "$code" in
        403 | 404) ;;
        *) fail "$path answered $code, expected 403 or 404" ;;
    esac
    if grep -qE '<\?php|SQLite format|a5a5a5a5|Require all denied' /tmp/body; then
        fail "$path gave away file contents"
    fi
done

[ "$failed" = 0 ] || exit 1
echo "Apache serves the app and assets/, and keeps every other file private."
