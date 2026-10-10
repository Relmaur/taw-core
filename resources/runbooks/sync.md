## Why it usually fails

Applying the scaffold's framework files couldn't reach GitHub (it clones the taw-theme scaffold), or a
framework file couldn't be written.

## Steps

1. Run it yourself: `vendor/bin/taw sync` (a report, nothing written), then `vendor/bin/taw sync --apply`.
2. If it can't clone: check the network and `git ls-remote https://github.com/Relmaur/taw-theme.git`.
3. Framework-owned files (`functions.php`, `bin/`, workflows, framework skills) are safe to replace; never
   put the site's own code in them.
