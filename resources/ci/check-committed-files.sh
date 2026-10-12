#!/usr/bin/env bash
# CI check: no database dump, content snapshot or credentials file is committed.
#
# A theme repo can be public. In 2026 a full database dump (users with password
# hashes, options, content) reached one through a broken .gitignore line, and
# every TAW theme now ignores /.sync/. This check catches what an ignore rule
# can't: `git add -f`, a dump written somewhere else, a repo without the line.
#
# It fails when a tracked file (or, given a range, a file any commit in it
# added, even one a later commit removed) is:
#   - under .sync/ (snapshots, dumps, remote.env)
#   - *.sql or *.wpress, compressed or not
#   - remote.env, .env or .env.* (not *.example, *.sample, *.dist, *.template)
#   - wp-config.php
# or when a tracked file (or a line a commit in the range adds) looks like a
# MySQL dump or a private key.
#
# A commit a pull request adds stays in the history after a merge commit, so
# removing the file in a later commit isn't enough: rewrite the branch.
#
# Usage, from the repository: bash vendor/taw/core/resources/ci/check-committed-files.sh [<base> [<head>]]
#   --staged: check what is about to be committed (the index), for the pre-commit
#   hook (resources/git-hooks/pre-commit); plain messages instead of CI annotations.
# Needs git and bash only.

set -euo pipefail

staged=0
if [ "${1:-}" = "--staged" ]; then
    staged=1
    shift
fi
base="${1:-}"
head="${2:-HEAD}"

paths='(^|/)\.sync/|\.(sql|wpress)(\.(gz|zip|bz2|xz))?$|(^|/)remote\.env$|(^|/)\.env(\.[^/]+)?$|(^|/)wp-config\.php$'
allowed='(^|/)\.env(\.[^/]+)?\.(example|sample|dist|template)$'
content='^-- (MySQL|MariaDB) dump|INSERT INTO `?[A-Za-z0-9_]*_(users|usermeta|options)`? |CREATE TABLE (IF NOT EXISTS )?`?[A-Za-z0-9_]*_(users|options)`? |-----BEGIN ([A-Z]+ )?PRIVATE KEY-----'

found=0
report() {
    if [ "$staged" -eq 1 ]; then echo "  $1" >&2; else echo "::error::$1"; fi
    found=1
}

files=$(git ls-files)
if [ -n "$base" ]; then
    files=$(printf '%s\n%s\n' "$files" "$(git log --format= --name-only --diff-filter=A "$base..$head")")
fi
while IFS= read -r file; do
    [ -n "$file" ] || continue
    if [[ $file =~ $paths ]] && ! [[ $file =~ $allowed ]]; then
        report "$file: never commit dumps, snapshots or credentials"
    fi
done < <(printf '%s\n' "$files" | sort -u)

while IFS= read -r file; do
    report "$file: looks like a database dump or a private key"
done < <(if [ "$staged" -eq 1 ]; then git grep --cached -IlE "$content" -- . || true; else git grep -IlE "$content" -- . || true; fi)

if [ -n "$base" ] && git log -p --format= "$base..$head" | grep -E '^\+' | grep -vE '^\+\+\+ ' | grep -qE "${content//^/^\\+}"; then
    report "a commit in $base..$head adds what looks like a database dump or a private key (a later commit removing it isn't enough: rewrite the branch)"
fi

if [ "$found" -eq 0 ] && [ "$staged" -eq 0 ]; then
    echo "No dumps, snapshots or credentials committed."
fi
exit "$found"
