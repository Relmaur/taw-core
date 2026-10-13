#!/usr/bin/env bash
# Deploy a built TAW theme to its host: the shared theme-deploy.yml runs it, and it runs by hand
# the same way. Host-neutral: one of two methods, configured by variables, no vendor names.
#
#   rsync  SSH + rsync into the theme's folder on the server (WPMUDev, WP Engine's SSH gateway,
#          Kinsta, SiteGround, Cloudways, a VPS…): DEPLOY_HOST, DEPLOY_PORT (22), DEPLOY_USER,
#          DEPLOY_PATH (the theme's folder, ending in /themes/<name>).
#   git    a commit of the built theme pushed to the host's git remote (WP Engine's git push…):
#          DEPLOY_GIT_REMOTE, DEPLOY_GIT_BRANCH (master), DEPLOY_GIT_PATH (wp-content/themes/<dir>).
#
# Usage, from the built theme folder: bash deploy.sh
#   DEPLOY_METHOD   rsync or git
#   DEPLOY_DRY_RUN  1: build the payload and check it, ship nothing
# SSH (the key, the pinned host key) is set up by the caller: the workflow loads the key into
# ssh-agent and writes DEPLOY_KNOWN_HOSTS to ~/.ssh/known_hosts. Nothing secret is an argument.
#
# What ships: the theme minus the defaults below and the patterns in the theme's .deployignore
# (rsync exclude patterns, one per line). Whatever the excludes say, a payload holding a database
# dump, a .sync/ folder, .env files, wp-config.php or a private key is refused: the same rules as
# check-committed-files.sh.

set -euo pipefail

method="${DEPLOY_METHOD:-}"
dry="${DEPLOY_DRY_RUN:-0}"
theme_dir="$(pwd)"
theme="$(basename "$theme_dir")"

fail() { echo "::error::$1" >&2; exit 1; }

case "$method" in
    rsync | git) ;;
    *) fail "DEPLOY_METHOD must be rsync or git (got '${method}')" ;;
esac

stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT

# --- the payload ------------------------------------------------------------------------------

excludes="$stage.excludes"
trap 'rm -rf "$stage" "$excludes"' EXIT
cat > "$excludes" <<'EOF'
.git
.github
.gitignore
.gitattributes
node_modules
tests
.playwright-mcp
.claude
.taw
.sync
.env
.env.*
.deployignore
*.md
phpunit.xml
phpunit.xml.dist
phpstan.neon
phpstan-baseline.neon
*.sql
*.sql.*
*.wpress
*.wpress.*
*.exe
EOF
if [ -f .deployignore ]; then
    grep -vE '^\s*(#|$)' .deployignore >> "$excludes" || true
fi
rsync -a --exclude-from="$excludes" ./ "$stage/"

# --- refuse what must never leave the Mac or the repository -----------------------------------

paths='(^|/)\.sync/|\.(sql|wpress)(\.(gz|zip|bz2|xz))?$|(^|/)remote\.env$|(^|/)\.env(\.[^/]+)?$|(^|/)wp-config\.php$'
allowed='(^|/)\.env(\.[^/]+)?\.(example|sample|dist|template)$'
content='^-- (MySQL|MariaDB) dump|INSERT INTO `?[A-Za-z0-9_]*_(users|usermeta|options)`? |CREATE TABLE (IF NOT EXISTS )?`?[A-Za-z0-9_]*_(users|options)`? |-----BEGIN ([A-Z]+ )?PRIVATE KEY-----'
refused=0
while IFS= read -r f; do
    rel="${f#"$stage"/}"
    if [[ $rel =~ $paths ]] && ! [[ $rel =~ $allowed ]]; then
        echo "::error::$rel: a dump, snapshot or credentials file is never deployed" >&2
        refused=1
    fi
done < <(find "$stage" -type f)
# Content: the theme's own files (vendor/ is Composer's, installed with --no-dev).
while IFS= read -r f; do
    echo "::error::${f#"$stage"/}: looks like a database dump or a private key" >&2
    refused=1
done < <(grep -rIlE --exclude-dir=vendor "$content" "$stage" || true)
[ "$refused" -eq 0 ] || fail "nothing was deployed"

count="$(find "$stage" -type f | wc -l | tr -d ' ')"
echo "Payload: $count files from $theme"
if [ "$dry" = "1" ]; then
    echo "Dry run: nothing shipped."
    exit 0
fi

# --- ship it -----------------------------------------------------------------------------------

if [ "$method" = "rsync" ]; then
    host="${DEPLOY_HOST:-}"
    port="${DEPLOY_PORT:-22}"
    user="${DEPLOY_USER:-}"
    path="${DEPLOY_PATH:-}"
    [ -n "$host" ] && [ -n "$user" ] && [ -n "$path" ] || fail "rsync needs DEPLOY_HOST, DEPLOY_USER and DEPLOY_PATH"
    # --delete removes what the theme no longer has: only ever inside a theme's folder.
    [[ $path =~ /themes/[^/]+/?$ ]] || fail "DEPLOY_PATH must be the theme's folder on the server, ending in /themes/<name> (got '$path')"
    [[ $port =~ ^[0-9]+$ ]] || fail "DEPLOY_PORT must be a number"
    # The host and user go in a throwaway ssh config, not on a command line or in the log.
    sshcfg="$(mktemp)"
    trap 'rm -rf "$stage" "$excludes" "$sshcfg"' EXIT
    printf 'Host taw-deploy\n  HostName %s\n  Port %s\n  User %s\n  BatchMode yes\n  StrictHostKeyChecking yes\n' \
        "$host" "$port" "$user" > "$sshcfg"
    rsync -rlptz --delete --stats -e "ssh -F $sshcfg" "$stage/" "taw-deploy:${path%/}/"
    echo "Deployed $theme to the server (${path%/})"
    exit 0
fi

remote="${DEPLOY_GIT_REMOTE:-}"
branch="${DEPLOY_GIT_BRANCH:-master}"
gitpath="${DEPLOY_GIT_PATH:-wp-content/themes/$theme}"
[ -n "$remote" ] || fail "git needs DEPLOY_GIT_REMOTE"
[[ $gitpath =~ ^[A-Za-z0-9._/-]+$ && $gitpath != /* && $gitpath != *..* ]] || fail "DEPLOY_GIT_PATH must be a relative path such as wp-content/themes/$theme"
repo="$(mktemp -d)"
trap 'rm -rf "$stage" "$excludes" "$repo"' EXIT
mkdir -p "$repo/$gitpath"
rsync -a "$stage/" "$repo/$gitpath/"
(
    cd "$repo"
    git init -q
    git config user.email "deploy@taw.invalid"
    git config user.name "TAW deploy"
    # -f: packages under vendor/ ship their own .gitignore files.
    git add -A -f .
    git commit -q -m "Deploy ${GITHUB_SHA:-$theme}"
    git push -q --force "$remote" "HEAD:refs/heads/$branch"
)
echo "Deployed $theme to the git remote ($branch, $gitpath)"
