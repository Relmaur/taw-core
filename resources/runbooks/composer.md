## Why it usually fails

Composer couldn't install the new taw/core: another package the theme requires doesn't allow the new
version, the network or GitHub's API limit got in the way, or PHP is older than the new version needs.

## Steps

1. Run it yourself and read the message: `composer update taw/core --with-dependencies`.
2. "Your requirements could not be resolved": `composer why-not taw/core <version>` says which package
   holds it back; update or loosen that one.
3. A GitHub API limit: `composer config -g github-oauth.github.com <a GitHub token>`, then try again.
