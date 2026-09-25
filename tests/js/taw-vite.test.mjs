// Tests for resources/vite/taw-vite.mjs. Run: node --test tests/js/
import { EventEmitter } from 'node:events';
import { existsSync, mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, it } from 'node:test';
import assert from 'node:assert/strict';

import {
    WP_EXPORT_NAMES,
    WP_GLOBALS,
    globalExpression,
    hotFile,
    phpReload,
    wordpressExternals,
} from '../../resources/vite/taw-vite.mjs';

/** Evaluate a virtual module's code as a real ES module. */
async function evaluate(code) {
    return import('data:text/javascript,' + encodeURIComponent(code));
}

describe('wordpressExternals', () => {
    beforeEach(() => {
        globalThis.window = {
            wp: { blocks: { registerBlockType: () => 'registered' }, apiFetch: () => 'fetched' },
            React: { createElement: () => 'element', Fragment: 'F' },
        };
    });
    afterEach(() => {
        delete globalThis.window;
    });

    it('resolves every WordPress global to a virtual module, and nothing else', () => {
        const plugin = wordpressExternals();
        for (const id of Object.keys(WP_GLOBALS)) {
            assert.match(plugin.resolveId(id), /^\0taw-wp-global:/, id);
        }
        assert.equal(plugin.resolveId('lodash'), null);
        assert.equal(plugin.resolveId('./local.ts'), null);
        assert.equal(plugin.load('/src/main.ts'), null);
    });

    it('reads named exports from the global at runtime', async () => {
        const plugin = wordpressExternals();
        const mod = await evaluate(plugin.load(plugin.resolveId('@wordpress/blocks')));

        assert.equal(mod.registerBlockType(), 'registered');
        assert.equal(mod.default, window.wp.blocks);
        assert.equal(mod.createBlock, undefined, 'a listed name the global lacks is undefined');
    });

    it('exposes a function global (api-fetch) as the default export', async () => {
        const plugin = wordpressExternals();
        const mod = await evaluate(plugin.load(plugin.resolveId('@wordpress/api-fetch')));

        assert.equal(mod.default(), 'fetched');
    });

    it('does not throw when the global is missing entirely', async () => {
        const plugin = wordpressExternals();
        const mod = await evaluate(plugin.load(plugin.resolveId('@wordpress/editor')));

        assert.deepEqual(mod.default, {});
        assert.equal(mod.PluginSidebar, undefined);
    });

    it('accepts extra export names and globals', async () => {
        const plugin = wordpressExternals({ extraExports: ['myHelper'], globals: { 'acme-lib': 'acme.lib' } });
        window.acme = { lib: { myHelper: () => 'ok' } };
        const mod = await evaluate(plugin.load(plugin.resolveId('acme-lib')));

        assert.equal(mod.myHelper(), 'ok');
    });

    it('rejects export names that are not identifiers', () => {
        assert.throws(() => wordpressExternals({ extraExports: ['not-valid'] }), /valid export name/);
    });

    it('has no duplicate export names', () => {
        assert.equal(new Set(WP_EXPORT_NAMES).size, WP_EXPORT_NAMES.length);
    });

    it('builds safe global expressions', () => {
        assert.equal(globalExpression('wp.blockEditor'), 'window.wp?.blockEditor');
        assert.equal(globalExpression('React'), 'window.React');
    });
});

describe('hotFile', () => {
    let dir;

    beforeEach(() => {
        dir = mkdtempSync(join(tmpdir(), 'taw-vite-hot-'));
    });
    afterEach(() => {
        rmSync(dir, { recursive: true, force: true });
    });

    function fakeServer(port) {
        const httpServer = new EventEmitter();
        httpServer.address = () => ({ port });
        return { httpServer, config: { server: {} } };
    }

    it('writes the origin when listening and removes it on close', () => {
        const path = join(dir, 'dist', 'hot');
        const server = fakeServer(5199);
        hotFile({ path }).configureServer(server);

        server.httpServer.emit('listening');
        assert.equal(readFileSync(path, 'utf8'), 'http://localhost:5199');
        assert.equal(server.config.server.origin, 'http://localhost:5199');

        server.httpServer.emit('close');
        assert.equal(existsSync(path), false);
    });

    it('only runs for the dev server', () => {
        assert.equal(hotFile().apply, 'serve');
        assert.equal(phpReload().apply, 'serve');
    });
});

describe('phpReload', () => {
    it('reloads the page when a watched file changes', () => {
        const sent = [];
        const watcher = new EventEmitter();
        watcher.add = () => {};
        phpReload().configureServer({
            watcher,
            ws: { send: (message) => sent.push(message) },
            config: { logger: { info: () => {} } },
        });

        watcher.emit('change', '/theme/templates/index.html');
        watcher.emit('change', '/theme/src/main.ts');

        assert.deepEqual(sent, [{ type: 'full-reload' }]);
    });
});
