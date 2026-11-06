import { defineConfig, globalIgnores } from 'eslint/config';
import js from '@eslint/js';
import tseslint from 'typescript-eslint';
import prettier from 'eslint-config-prettier';
import globals from 'globals';

export default defineConfig([
    globalIgnores(['node_modules/', 'coverage/']),
    js.configs.recommended,
    tseslint.configs.recommended,
    {
        files: ['src/**/*.{ts,tsx}', 'tests/**/*.{ts,tsx}'],
        languageOptions: { globals: globals.browser },
    },
    { files: ['*.config.js'], languageOptions: { globals: globals.node } },
    prettier,
]);
