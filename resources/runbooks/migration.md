## Why it usually fails

A migration couldn't finish on its own, usually because it met something the site changed that it
shouldn't decide about. Its "For you" steps say exactly what to do.

## Steps

1. See what's left: `vendor/bin/taw upgrade`.
2. For each one, read what it does, why, and how to do it by hand: `vendor/bin/taw upgrade --explain <id>`.
3. Do those steps, then run `vendor/bin/taw upgrade` again: when nothing is listed, it's done.
