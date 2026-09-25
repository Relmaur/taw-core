/**
 * Vitest setup: the real React on window (WordPress's global, which
 * `import React from 'react'` reads through wordpressExternals), then the
 * fake window.wp.
 */
import { createRequire } from 'node:module';
import { afterEach, beforeEach } from 'vitest';
import { cleanup } from '@testing-library/react';
import '@testing-library/jest-dom/vitest';
import { installWpGlobals, resetEditor } from './wp-globals';

const require = createRequire(import.meta.url);
Object.assign(window, { React: require('react'), ReactDOM: require('react-dom') });

installWpGlobals();

beforeEach(() => resetEditor());
afterEach(() => cleanup());
