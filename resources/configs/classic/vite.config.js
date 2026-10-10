/**
 * Vite config (taw:config 1).
 *
 * The base (entries, output, dev server, the hot file TAW reads) comes from
 * taw/core's classicTheme() and updates with `composer update taw/core`; this
 * file is the site's own and updates never change it. Put this site's own
 * settings in the object below (Vite's mergeConfig adds them to the base).
 * Run `composer install` before `npm run dev` / `npm run build`.
 */
import { existsSync } from 'node:fs';
import path from 'node:path';
import tailwindcss from '@tailwindcss/vite';
import { defineConfig, mergeConfig } from 'vite';

const tawVite = path.resolve(import.meta.dirname, 'vendor/taw/core/resources/vite/taw-vite.mjs');
if (!existsSync(tawVite)) {
    throw new Error('taw/core is not installed: run `composer install` in the theme folder first.');
}
const { classicTheme } = await import(tawVite);

export default defineConfig((env) =>
    mergeConfig(classicTheme(env, { plugins: [tailwindcss()] }), {
        // This site's own settings.
    }),
);
