#!/usr/bin/env bash
# Upgrade test: installs the release ZIP of one ref, adds data, unpacks the release ZIP of a later
# ref over it, and checks that the data survived and the pages render the new templates. When the
# later ref brings migrations, it also checks that they ran and that lazy maintenance deletes the
# pre-migration backup once it is a day old. Then it unpacks a broken update with a failing
# migration over the later release and goes back as the README says: the later release unpacked
# again, the backup moved over the database. The site must work again, and a fixed update that
# replaces the broken migration under its number must go through.
#
#   docker compose run --rm app tests/upgrade/run.sh <from-ref> [to-ref]
#
# to-ref defaults to HEAD.
set -euo pipefail

root="$(cd "$(dirname "$0")/../.." && pwd)"
from="${1:?usage: tests/upgrade/run.sh <from-ref> [to-ref]}"
to="${2:-HEAD}"
# The end-to-end tests' dev config fits: localhost on this port, a fixed secret.
port=8081
url="http://localhost:$port"

work="$(mktemp -d)"
server=""
cleanup() {
    [ -n "$server" ] && kill "$server" 2>/dev/null || true
    rm -rf "$work"
}
trap cleanup EXIT

fail() {
    echo "FAIL: $*" >&2
    tail -n 40 "$work/site/data/logs/app.log" >&2 2>/dev/null || true
    exit 1
}
step() { echo "--- $*"; }

start_server() {
    (cd "$work/site" && exec php -S "127.0.0.1:$port" -t public public/index.php >"$work/server.log" 2>&1) &
    server=$!
    for _ in $(seq 50); do
        curl -fsS -o /dev/null "$url/login" 2>/dev/null && return
        sleep 0.1
    done
    cat "$work/server.log" >&2
    fail "the server did not start"
}

stop_server() {
    kill "$server"
    wait "$server" 2>/dev/null || true
    server=""
}

sql() { php -r '$pdo = new PDO("sqlite:" . $argv[1]); echo $pdo->query($argv[2])->fetchColumn();' "$work/site/data/app.sqlite" "$1"; }

# A request as the signed-in member; fails on any status but 2xx and 3xx.
curl_() { curl -fsS -b "$work/cookies" -c "$work/cookies" "$@"; }

# GET a page and print its body.
page() { curl_ "$url$1"; }

# Whether the page at a path shows a text. grep reads to the end, so curl never writes into a closed pipe.
shows() { page "$1" | grep -F "$2" >/dev/null; }

csrf() { grep -o 'name="_csrf" value="[^"]*"' | tail -1 | cut -d'"' -f4; }

# Submits a page's form: the page to take the CSRF token from, the form's action, its fields.
submit() {
    local token field
    local fields=()
    token="$(page "$1" | csrf)"
    for field in "${@:3}"; do fields+=(--data-urlencode "$field"); done
    curl_ -o /dev/null --data-urlencode "_csrf=$token" "${fields[@]}" "$url$2"
}

sign_in() { submit /dev-login /dev-login return=/ "email=$1"; }

vote() { submit "/polls/$1" "/polls/$1/ballot" "choice=$2"; }

# Lazy maintenance runs on the next request once its last run is an hour old.
run_maintenance() {
    sql "UPDATE settings SET value = '0' WHERE key = 'maintenance_last_run'"
    page /login >/dev/null
}

step "Build both releases"
old_zip="$("$root/bin/build-release" "$from")"
new_zip="$("$root/bin/build-release" "$to")"

step "Install $(basename "$old_zip")"
mkdir "$work/site"
unzip -q "$old_zip" -d "$work/site"
cp "$root/tests/e2e/config.php" "$work/site/config/config.php"
(cd "$work/site" && php bin/console seed:demo >/dev/null)
# The instance's member file, which the update must leave as it is. Invented, like all test data.
printf 'name;email;group\nRobin Example;robin.example@example.org;S1\n' >"$work/site/config/members.csv"
old_version="$(sql 'PRAGMA user_version')"
members="$(sql 'SELECT COUNT(*) FROM members')"
addresses="$(sql 'SELECT COUNT(*) FROM member_emails')"
polls="$(sql 'SELECT COUNT(*) FROM polls')"

step "Add data: a member votes Yes on poll 2"
start_server
sign_in theo.reinhardt@example.org
vote 2 yes
shows /polls/2 'Deine Stimme ist registriert: Ja.' || fail "the vote did not register in the old release"
stop_server

step "Unpack $(basename "$new_zip") over the installation"
unzip -q -o "$new_zip" -d "$work/site"
cmp -s "$root/tests/e2e/config.php" "$work/site/config/config.php" || fail "the update changed config/config.php"
grep -q '^Robin Example;' "$work/site/config/members.csv" || fail "the update changed config/members.csv"
start_server

# The schema version is the number of the newest migration the release ships.
latest="$(find "$work/site/migrations" -name '[0-9][0-9][0-9][0-9]_*.sql' -exec basename {} \; | sort | tail -1)"
latest=$((10#${latest%%_*}))
new_version="$(sql 'PRAGMA user_version')"
[ "$new_version" = "$latest" ] || fail "schema version is $new_version, expected $latest"
diff <(find "$work/site/migrations" -name '[0-9][0-9][0-9][0-9]_*.sql' -exec basename {} \; | sort) "$work/site/MIGRATIONS.txt" >/dev/null \
    || fail "MIGRATIONS.txt does not list the release's migrations"
echo "Schema version $old_version -> $new_version"
[ "$(sql 'SELECT COUNT(*) FROM members')" = "$members" ] || fail "members changed"
[ "$(sql 'SELECT COUNT(*) FROM member_emails')" = "$addresses" ] || fail "sign-in addresses changed"
[ "$(sql 'SELECT COUNT(*) FROM polls')" = "$polls" ] || fail "polls changed"
backup="$work/site/data/backup/app-v$old_version.sqlite"
if [ "$new_version" -gt "$old_version" ]; then
    [ -f "$backup" ] || fail "no pre-migration backup at data/backup/$(basename "$backup")"
else
    [ ! -e "$backup" ] || fail "a backup without a migration"
fi

step "The data still works in the new release"
shows /polls/2 'Deine Stimme ist registriert: Ja.' || fail "the vote from the old release is gone"
vote 2 no
shows /polls/2 'Deine Stimme ist registriert: Nein.' || fail "changing the vote failed"
shows /polls/5 'Aufgenommen' || fail "the closed poll lost its result"

step "Pages render the new templates, not the old release's compiled ones"
# A ZIP restores its files' build times, which can be older than the old release's template cache.
strip_csrf() { sed 's/name="_csrf" value="[^"]*"//g'; }
page /polls/2 | strip_csrf >"$work/after-update.html"
rm -rf "$work/site/data/cache/twig"
page /polls/2 | strip_csrf >"$work/fresh-cache.html"
diff -q "$work/after-update.html" "$work/fresh-cache.html" >/dev/null || fail "the poll page differs from a fresh template cache"

if [ "$new_version" -gt "$old_version" ]; then
    step "Maintenance keeps the backup for a day, then deletes it"
    run_maintenance
    [ -f "$backup" ] || fail "maintenance deleted the backup before a day had passed"
    touch -d '25 hours ago' "$backup"
    run_maintenance
    [ ! -f "$backup" ] || fail "maintenance kept the backup after a day"
fi

step "The release keeps everything but public/ private"
listing="$(unzip -Z1 "$new_zip")"
for rule in .htaccess config/.htaccess data/.htaccess; do
    grep -qx "$rule" <<<"$listing" || fail "the release has no $rule"
done
grep -q '^Require all denied' "$work/site/config/.htaccess" || fail "config/.htaccess denies nothing"
grep -q '^Require all denied' "$work/site/data/.htaccess" || fail "data/.htaccess denies nothing"
grep -q '^ *RewriteRule !^public/ - \[F\]' "$work/site/.htaccess" || fail "the root .htaccess lets more than public/ through"

step "A broken update: one migration works, the next fails"
release_version="$(cat "$work/site/VERSION")"
probe_version=$((new_version + 1))
broken_version=$((new_version + 2))
probe="$(printf '%04d' "$probe_version")_rollback_probe.sql"
broken="$(printf '%04d' "$broken_version")_rollback_broken.sql"
fixed="$(printf '%04d' "$broken_version")_rollback_fixed.sql"
# A release that adds two migrations, written over the installation as unpacking its ZIP would.
printf 'CREATE TABLE rollback_probe (n INTEGER);\n' >"$work/site/migrations/$probe"
printf 'INSERT INTO missing_table VALUES (1);\n' >"$work/site/migrations/$broken"
printf '%s\n' "$probe" "$broken" >>"$work/site/MIGRATIONS.txt"
echo "$release_version-broken" >"$work/site/VERSION"
status="$(curl -s -o /dev/null -w '%{http_code}' -b "$work/cookies" "$url/polls/2")"
[ "$status" = 500 ] || fail "the broken update answered $status, expected 500"
grep -q "$broken failed" "$work/site/data/logs/app.log" || fail "the log does not name the failed migration"
[ "$(sql 'PRAGMA user_version')" = "$probe_version" ] || fail "the working migration of the broken update did not run"
backup="$work/site/data/backup/app-v$new_version.sqlite"
[ -f "$backup" ] || fail "no backup before the broken update"

step "Go back: unpack the release again, move the backup over the database"
unzip -q -o "$new_zip" -d "$work/site"
touch -d '25 hours ago' "$backup"
run_maintenance
[ -f "$backup" ] || fail "maintenance deleted the backup the way back needs"
mv "$backup" "$work/site/data/app.sqlite"
shows /polls/2 'Deine Stimme ist registriert: Nein.' || fail "the site does not work after going back"
shows /polls/5 'Aufgenommen' || fail "the closed poll lost its result after going back"
[ "$(sql 'PRAGMA user_version')" = "$new_version" ] || fail "schema version is $(sql 'PRAGMA user_version') after going back, expected $new_version"
[ "$(sql "SELECT COUNT(*) FROM sqlite_master WHERE name = 'rollback_probe'")" = 0 ] || fail "the broken update's migration ran again"
vote 2 yes
shows /polls/2 'Deine Stimme ist registriert: Ja.' || fail "voting failed after going back"

step "A fixed update replaces the broken migration under its number"
# The broken file stays behind from the failed update; the fixed release no longer lists it.
printf 'CREATE TABLE rollback_fixed (n INTEGER);\n' >"$work/site/migrations/$fixed"
printf '%s\n' "$probe" "$fixed" >>"$work/site/MIGRATIONS.txt"
echo "$release_version-fixed" >"$work/site/VERSION"
shows /polls/2 'Deine Stimme ist registriert: Ja.' || fail "the fixed update failed"
[ "$(sql 'PRAGMA user_version')" = "$broken_version" ] || fail "the fixed update did not reach schema version $broken_version"
[ "$(sql "SELECT COUNT(*) FROM sqlite_master WHERE name = 'rollback_fixed'")" = 1 ] || fail "the fixed migration did not run"
[ -f "$backup" ] || fail "no fresh backup before the fixed update"

echo "Upgrade from $from to $to passed."
