#!/usr/bin/env bash
# Content interchange fidelity suite: a real two-site round trip.
#
#   tests/fidelity/run.sh           the whole round trip (exits non-zero on any difference)
#   tests/fidelity/run.sh seed      only install site A and seed it
#
# Needs: wp (WP-CLI), PHP with mysqli + gd, a MySQL server, and a WordPress
# core copy. Configure with environment variables:
#
#   FID_WORK      scratch directory for the two installs   (default: $TMPDIR/taw-fidelity)
#   FID_DB_HOST   MySQL host for wp-config, e.g. 127.0.0.1 or localhost:/tmp/mysql.sock
#   FID_DB_USER / FID_DB_PASS                               (default: root / empty)
#   FID_WP_CORE   a WordPress directory to copy core from  (default: wp core download)
#   FID_PORT_A    port site A is served on (its media URLs) (default: 8881)

set -euo pipefail

CORE="$(cd "$(dirname "$0")/../.." && pwd)"
HERE="$CORE/tests/fidelity"
WORK="${FID_WORK:-${TMPDIR:-/tmp}/taw-fidelity}"
DB_HOST="${FID_DB_HOST:-127.0.0.1}"
DB_USER="${FID_DB_USER:-root}"
DB_PASS="${FID_DB_PASS:-}"
PORT_A="${FID_PORT_A:-8881}"
PORT_B="${FID_PORT_B:-8882}"

mkdir -p "$WORK"
cd "$WORK" # never inside a site directory the script removes (WP-CLI spawns from the cwd)

say() { printf '\n== %s\n' "$*"; }

# install_site <name> <database> <port>: a fresh WordPress with the fixture theme.
install_site() {
    local name="$1" db="$2" port="$3" dir="$WORK/wp-$1"
    rm -rf "$dir"
    mkdir -p "$dir"
    if [ -n "${FID_WP_CORE:-}" ]; then
        rsync -a --exclude wp-content --exclude wp-config.php --exclude .htaccess "$FID_WP_CORE/" "$dir/"
        mkdir -p "$dir/wp-content/themes" "$dir/wp-content/plugins" "$dir/wp-content/uploads"
    else
        wp core download --path="$dir" --quiet
    fi
    wp --path="$dir" config create --dbname="$db" --dbuser="$DB_USER" --dbpass="$DB_PASS" --dbhost="$DB_HOST" --skip-check --quiet
    wp --path="$dir" config set TAW_FIDELITY_CORE "$CORE" --quiet
    wp --path="$dir" db reset --yes --quiet 2>/dev/null || wp --path="$dir" db create --quiet
    wp --path="$dir" core install --url="http://127.0.0.1:$port" --title="Fidelity $name" --admin_user=admin \
        --admin_password=admin --admin_email="admin@fidelity.test" --skip-email --quiet
    cp -R "$HERE/theme" "$dir/wp-content/themes/taw-fidelity"
    wp --path="$dir" theme activate taw-fidelity --quiet
    wp --path="$dir" rewrite structure '/%postname%/' --quiet
}

# wpa / wpb <args>: WP-CLI on site A / B, as the admin.
wpa() { wp --path="$WORK/wp-a" --user=admin "$@"; }
wpb() { wp --path="$WORK/wp-b" --user=admin "$@"; }

FAILED=0
# check <name> <command…>: run one assertion, keep going on failure.
check() {
    local name="$1"; shift
    if "$@"; then echo "   ok: $name"; else echo "   FAILED: $name"; FAILED=1; fi
}

seed() {
    say "Site A: install and seed"
    install_site a fid_a "$PORT_A"
    wpa eval-file "$HERE/seed.php" # as the admin: no kses on what it writes
}

roundtrip() {
    seed

    say "Site A: served on 127.0.0.1:$PORT_A (site B downloads its media)"
    php -S "127.0.0.1:$PORT_A" -t "$WORK/wp-a" >"$WORK/server-a.log" 2>&1 &
    SERVER=$!
    trap 'kill "$SERVER" 2>/dev/null || true' EXIT
    for _ in $(seq 1 50); do curl -s -o /dev/null "http://127.0.0.1:$PORT_A/wp-includes/images/w-logo-blue.png" && break; sleep 0.2; done

    say "Site B: install, IDs offset so none matches site A's by accident"
    install_site b fid_b "$PORT_B"
    wpb eval 'global $wpdb; foreach ([$wpdb->posts, $wpdb->terms, $wpdb->term_taxonomy, $wpdb->users, $wpdb->comments] as $t) { $wpdb->query("ALTER TABLE {$t} AUTO_INCREMENT = 5000"); }'
    wpb eval-file "$HERE/seed-b.php" # some of A's records, older: the usual pull

    say "Export A, import into B"
    wpa eval-file "$HERE/interchange.php" export "$WORK/a.json"
    wpa eval-file "$HERE/keys.php" "$WORK/keys-a.txt"
    wpb eval-file "$HERE/keys.php" "$WORK/keys-b-before.txt"
    wpb eval-file "$HERE/interchange.php" apply "$WORK/a.json"

    say "Checks"
    check "a second import reports 0 changes" wpb eval-file "$HERE/interchange.php" plan "$WORK/a.json"
    wpb eval-file "$HERE/keys.php" "$WORK/keys-b.txt" >/dev/null
    check "every value in B points at the record it did in A" diff -u "$WORK/keys-a.txt" "$WORK/keys-b.txt"
    wpb eval-file "$HERE/interchange.php" export "$WORK/b.json" >/dev/null
    check "B's export, imported back into A, changes nothing" wpa eval-file "$HERE/interchange.php" plan "$WORK/b.json"

    say "Undo"
    wpb eval-file "$HERE/interchange.php" undo
    wpb eval-file "$HERE/keys.php" "$WORK/keys-b-undone.txt" >/dev/null
    check "undo returns B to where it was" diff -u "$WORK/keys-b-before.txt" "$WORK/keys-b-undone.txt"

    if [ "$FAILED" -ne 0 ]; then
        say "Fidelity: FAILED (files in $WORK)"
        exit 1
    fi
    say "Fidelity: passed"
}

case "${1:-}" in
    seed) seed ;;
    ''|roundtrip) roundtrip ;;
    *) echo "usage: $0 [seed]" >&2; exit 2 ;;
esac
