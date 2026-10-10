## Why it usually fails

The front-end build (Vite) couldn't compile the theme's CSS or JavaScript: a dependency changed, a file
it imports moved, or `node_modules` is out of date.

## Steps

1. Reinstall the front-end dependencies: `npm ci`.
2. Run the build yourself: `npm run build` (or `npm run check` when the theme has it). It names the file and
   the problem.
3. If it says taw/core isn't installed, run `composer install` first: the Vite config loads taw/core's base
   from `vendor/`.
