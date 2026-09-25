import { defineConfig, globalIgnores } from 'eslint/config';
import js from '@eslint/js';
import tseslint from 'typescript-eslint';
import react from 'eslint-plugin-react';
import reactHooks from 'eslint-plugin-react-hooks';
import prettier from 'eslint-config-prettier';
import globals from 'globals';

export default defineConfig([
    globalIgnores(['node_modules/', 'coverage/']),
    js.configs.recommended,
    tseslint.configs.recommended,
    {
        files: ['src/**/*.{ts,tsx}', 'tests/**/*.{ts,tsx}'],
        extends: [react.configs.flat.recommended, reactHooks.configs.flat.recommended],
        languageOptions: { globals: globals.browser },
        settings: { react: { version: '18.3' } },
        rules: {
            'react/prop-types': 'off',
            'react/react-in-jsx-scope': 'error',
        },
    },
    { files: ['*.config.js'], languageOptions: { globals: globals.node } },
    prettier,
]);
