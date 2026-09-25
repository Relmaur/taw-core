/**
 * Build for the TAW Data panel (ADR-0007). The output is COMMITTED to
 * ../../assets/data-panel/ so Composer installs need no Node; CI fails if it
 * differs from a fresh build.
 *
 * PHP loads it through TAW\Core\Assets\Vite with the package root as the
 * project dir and "assets/data-panel" as outDir. While `npm run dev` runs,
 * the hot file there points WordPress at this dev server instead.
 */
import path from 'node:path';
import { defineConfig } from 'vite';
import { hotFile, wordpressExternals } from '../vite/taw-vite.mjs';

const outDir = path.resolve(import.meta.dirname, '../../assets/data-panel');

export default defineConfig(({ command }) => ({
    base: command === 'build' ? './' : '/',

    plugins: [hotFile({ path: path.join(outDir, 'hot') }), wordpressExternals()],

    // Classic JSX runtime: React is WordPress's global (`import React from 'react'`).
    oxc: {
        jsx: { runtime: 'classic', pragma: 'React.createElement', pragmaFrag: 'React.Fragment' },
    },

    build: {
        outDir,
        emptyOutDir: true,
        manifest: true,
        rolldownOptions: {
            input: { 'data-panel': path.resolve(import.meta.dirname, 'src/index.tsx') },
            output: {
                format: 'es',
                entryFileNames: '[name]-[hash].js',
                chunkFileNames: '[name]-[hash].js',
                assetFileNames: '[name]-[hash][extname]',
            },
        },
    },

    server: {
        // taw-theme usually has 5173 and taw-gutenberg 5174; hotFile() records
        // the port Vite actually got.
        port: 5175,
        cors: { origin: /^https?:\/\/(localhost|127\.0\.0\.1|[a-z0-9-]+\.local)(:\d+)?$/ },
    },

    test: {
        environment: 'jsdom',
        setupFiles: ['./tests/setup.ts'],
        include: ['src/**/*.test.{ts,tsx}'],
        clearMocks: true,
    },
}));
