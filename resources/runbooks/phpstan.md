## Why it usually fails

Static analysis found code that calls something wrongly, often because a taw/core API changed in this
update (read the UPGRADING.md sections between the two versions: `vendor/taw/core/UPGRADING.md`), or
because of code the site added since the last analysis.

## Steps

1. Run it yourself: `composer run phpstan`. Each error names a file, a line and what's wrong.
2. Fix the code it names. If an error is about a taw/core class or method, look it up in
   `vendor/taw/core/README.md` for the new way to call it.
3. Only if an error is a false alarm (PHPStan can't see something WordPress provides at runtime), add a
   narrow `ignoreErrors` entry to the theme's `phpstan.neon` under `parameters`, with a comment saying why.
