/**
 * Build for the TAW Lens page script (umbrella ADR-0006, taw/core ADR-0020). The output is
 * COMMITTED to ../../assets/lens/ so Composer installs need no Node; CI fails if it differs from
 * a fresh build.
 *
 * PHP loads it through TAW\Core\Assets\Vite, only under the Lens gate (dev server running, the
 * theme's "Enable Lens" toggle, a user who can edit the theme). While `npm run dev` runs, the hot
 * file there points WordPress at this dev server instead.
 *
 * Unlike the data panel, Lens runs on the site's frontend, where WordPress's React isn't loaded:
 * it bundles its own Preact and mounts inside a shadow root, so it shares nothing with the site.
 */
import path from 'node:path';
import { defineConfig } from 'vite';
import { hotFile } from '../vite/taw-vite.mjs';

const outDir = path.resolve(import.meta.dirname, '../../assets/lens');

export default defineConfig(({ command }) => ({
    base: command === 'build' ? './' : '/',

    plugins: [hotFile({ path: path.join(outDir, 'hot') })],

    oxc: {
        jsx: { runtime: 'automatic', importSource: 'preact' },
    },

    build: {
        outDir,
        emptyOutDir: true,
        manifest: true,
        rolldownOptions: {
            input: {
                lens: path.resolve(import.meta.dirname, 'src/index.tsx'),
            },
            output: {
                format: 'es',
                entryFileNames: '[name]-[hash].js',
                chunkFileNames: '[name]-[hash].js',
                assetFileNames: '[name]-[hash][extname]',
            },
        },
    },

    server: {
        // taw-theme usually has 5173, taw-gutenberg 5174, the data panel 5175; hotFile() records
        // the port Vite actually got.
        port: 5176,
        cors: { origin: /^https?:\/\/(localhost|127\.0\.0\.1|[a-z0-9-]+\.local)(:\d+)?$/ },
    },

    test: {
        environment: 'jsdom',
        setupFiles: ['./tests/setup.ts'],
        include: ['src/**/*.test.{ts,tsx}'],
        clearMocks: true,
    },
}));
