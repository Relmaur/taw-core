## Why it usually fails

A PHP file in the theme has a syntax error, often from a merge or a half-finished edit, or uses syntax
newer than the PHP version the site runs.

## Steps

1. Run the check yourself from the theme folder:
   `find . -name "*.php" -not -path "./vendor/*" -not -path "./node_modules/*" -print0 | xargs -0 -n1 php -l`
2. Open each file it names at the line it gives and fix the syntax.
3. If the error is in a file the update changed (see "What changed" above), compare it with the previous
   version: `git diff HEAD~1 -- <file>`.
