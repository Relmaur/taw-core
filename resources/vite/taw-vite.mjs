/**
 * Shared Vite config for TAW projects (taw/core ADR-0006).
 *
 * Import it from a theme's vite.config.js:
 *
 *   import { wordpressExternals, hotFile, phpReload } from './vendor/taw/core/resources/vite/taw-vite.mjs';
 *
 *   export default defineConfig({
 *     plugins: [hotFile(), wordpressExternals()],
 *     build: { outDir: 'dist', manifest: true, rolldownOptions: { input: { ... } } },
 *   });
 *
 * The PHP side is TAW\Core\Assets\Vite, which reads `{outDir}/hot` and
 * `{outDir}/.vite/manifest.json`.
 *
 * Plain ESM with no dependencies, so it works from vendor/ without its own
 * node_modules. The .mjs extension keeps it ESM whatever package.json sits
 * above it.
 */

import { mkdirSync, unlinkSync, writeFileSync } from 'node:fs';
import { basename, dirname } from 'node:path';

/**
 * Imports that WordPress already ships as browser globals. Bundling our own
 * copies would load React twice and break hooks, so these resolve to the
 * globals instead. Each script that imports one must also depend on the
 * matching WordPress handle (e.g. 'wp-blocks') so the global exists first.
 */
export const WP_GLOBALS = {
    '@wordpress/api-fetch': 'wp.apiFetch',
    '@wordpress/autop': 'wp.autop',
    '@wordpress/block-editor': 'wp.blockEditor',
    '@wordpress/blocks': 'wp.blocks',
    '@wordpress/components': 'wp.components',
    '@wordpress/compose': 'wp.compose',
    '@wordpress/core-data': 'wp.coreData',
    '@wordpress/data': 'wp.data',
    '@wordpress/date': 'wp.date',
    '@wordpress/dom-ready': 'wp.domReady',
    '@wordpress/editor': 'wp.editor',
    '@wordpress/element': 'wp.element',
    '@wordpress/hooks': 'wp.hooks',
    '@wordpress/html-entities': 'wp.htmlEntities',
    '@wordpress/i18n': 'wp.i18n',
    '@wordpress/media-utils': 'wp.mediaUtils',
    '@wordpress/notices': 'wp.notices',
    '@wordpress/plugins': 'wp.plugins',
    '@wordpress/primitives': 'wp.primitives',
    '@wordpress/rich-text': 'wp.richText',
    '@wordpress/url': 'wp.url',
    react: 'React',
    'react-dom': 'ReactDOM',
    // WordPress 6.6+ ships the automatic JSX runtime as the
    // 'react-jsx-runtime' script.
    'react/jsx-runtime': 'ReactJSXRuntime',
};

/**
 * Named exports the virtual modules expose.
 *
 * ES modules need export names at build time, but the globals only exist at
 * runtime, so they're listed here. Importing a name that isn't listed fails
 * the build ("is not exported"). A listed name the global doesn't have is
 * `undefined` at runtime. Add missing names here (or per project with
 * `wordpressExternals({ extraExports: [...] })`).
 */
export const WP_EXPORT_NAMES = [
    // @wordpress/blocks
    'registerBlockType', 'unregisterBlockType', 'registerBlockVariation', 'registerBlockStyle',
    'unregisterBlockStyle', 'createBlock', 'cloneBlock', 'getBlockType', 'getBlockTypes',
    'getBlockContent', 'getSaveContent', 'serialize', 'parse', 'rawHandler', 'pasteHandler',
    'registerBlockBindingsSource', 'unregisterBlockBindingsSource', 'getBlockBindingsSource',
    // @wordpress/block-editor
    'useBlockProps', 'useInnerBlocksProps', 'RichText', 'RichTextToolbarButton', 'InnerBlocks',
    'InspectorControls', 'BlockControls', 'MediaUpload', 'MediaUploadCheck', 'MediaPlaceholder',
    'MediaReplaceFlow', 'PanelColorSettings', 'AlignmentControl', 'BlockAlignmentToolbar', 'URLInput',
    'URLInputButton', 'BlockEditorProvider', 'BlockList', 'BlockTools', 'BlockInspector', 'BlockIcon',
    'WritingFlow', 'ObserveTyping', 'BlockCanvas', 'BlockEditorKeyboardShortcuts', 'Inserter',
    'useSetting', 'useSettings', 'useBlockEditingMode', 'withColors',
    // @wordpress/components
    'BaseControl', 'Button', 'Card', 'CardBody', 'CardHeader', 'CheckboxControl', 'ColorIndicator', 'ColorPalette',
    'ColorPicker', 'ComboboxControl', 'DatePicker', 'DateTimePicker', 'Dashicon', 'Dropdown',
    'DropdownMenu', 'ExternalLink', 'Fill', 'Flex', 'FlexBlock', 'FlexItem', 'FormTokenField', 'Icon',
    'MenuGroup', 'MenuItem', 'Modal', 'Notice', 'PanelBody', 'PanelRow', 'Placeholder', 'Popover',
    'RadioControl', 'RangeControl', 'SearchControl', 'SelectControl', 'Slot', 'SlotFillProvider',
    'Spinner', 'TabPanel', 'TextControl', 'TextareaControl', 'ToggleControl', 'Toolbar',
    'ToolbarButton', 'ToolbarGroup', 'Tooltip', 'VisuallyHidden',
    // @wordpress/element, react, react-dom, react/jsx-runtime
    'Children', 'Fragment', 'RawHTML', 'StrictMode', 'cloneElement', 'createContext', 'createElement',
    'createPortal', 'createRoot', 'forwardRef', 'isValidElement', 'memo', 'render', 'useCallback',
    'useContext', 'useEffect', 'useId', 'useLayoutEffect', 'useMemo', 'useReducer', 'useRef',
    'useState', 'jsx', 'jsxs',
    // @wordpress/i18n
    '__', '_x', '_n', '_nx', 'sprintf', 'isRTL', 'setLocaleData',
    // @wordpress/data
    'useSelect', 'useDispatch', 'useRegistry', 'select', 'dispatch', 'subscribe', 'register',
    'createReduxStore', 'createRegistry', 'combineReducers', 'RegistryProvider', 'withSelect',
    'withDispatch', 'store',
    // @wordpress/compose
    'compose', 'createHigherOrderComponent', 'ifCondition', 'useDebounce', 'useInstanceId',
    'useMergeRefs', 'useRefEffect', 'useViewportMatch', 'withState',
    // @wordpress/core-data
    'useEntityProp', 'useEntityRecord', 'useEntityRecords', 'useEntityBlockEditor',
    // @wordpress/editor, @wordpress/plugins
    'PluginSidebar', 'PluginSidebarMoreMenuItem', 'PluginDocumentSettingPanel', 'PluginMoreMenuItem',
    'PluginPostStatusInfo', 'PluginPrePublishPanel', 'PluginPostPublishPanel',
    'PluginBlockSettingsMenuItem', 'registerPlugin', 'unregisterPlugin', 'getPlugin', 'getPlugins',
    'PluginArea', 'usePluginContext',
    // @wordpress/hooks
    'addAction', 'addFilter', 'applyFilters', 'doAction', 'hasFilter', 'removeAction', 'removeFilter',
    'createHooks',
    // @wordpress/rich-text
    'registerFormatType', 'unregisterFormatType', 'applyFormat', 'removeFormat', 'toggleFormat',
    'create', 'insert', 'toHTMLString', 'useAnchor',
    // @wordpress/autop, url, html-entities, date, media-utils, primitives
    'autop', 'removep', 'addQueryArgs', 'getQueryArg', 'removeQueryArgs', 'isURL', 'cleanForSlug',
    'safeDecodeURI', 'decodeEntities', 'dateI18n', 'format', 'getSettings', 'uploadMedia', 'SVG',
    'Path', 'G', 'Circle', 'Rect',
];

const VIRTUAL_PREFIX = '\0taw-wp-global:';

/**
 * A JS expression that reads a dotted global without throwing when a
 * parent is missing: 'wp.blocks' → window.wp?.blocks.
 */
export function globalExpression(path) {
    const [head, ...rest] = path.split('.');
    return ['window.' + head, ...rest].join('?.');
}

/**
 * Resolve WordPress and React imports to virtual modules that read the
 * browser globals. One plugin for dev, build and Vitest, so all three resolve
 * these imports the same way.
 *
 * @param {{ extraExports?: string[], globals?: Record<string, string> }} [options]
 */
export function wordpressExternals(options = {}) {
    const globals = { ...WP_GLOBALS, ...(options.globals ?? {}) };
    const names = [...new Set([...WP_EXPORT_NAMES, ...(options.extraExports ?? [])])];

    for (const name of names) {
        if (!/^[A-Za-z_$][\w$]*$/.test(name)) {
            throw new Error(`wordpressExternals: "${name}" isn't a valid export name.`);
        }
    }

    return {
        name: 'taw-wordpress-externals',
        enforce: 'pre',
        resolveId(id) {
            return Object.hasOwn(globals, id) ? VIRTUAL_PREFIX + id : null;
        },
        load(id) {
            if (!id.startsWith(VIRTUAL_PREFIX)) {
                return null;
            }
            const expression = globalExpression(globals[id.slice(VIRTUAL_PREFIX.length)]);

            // Read the global when the module runs, not at build time. One
            // export per name, each marked pure, so the build drops every name
            // nothing imports (a single destructuring can't be tree-shaken:
            // every bundle would carry all of WP_EXPORT_NAMES).
            return [
                `const mod = ${expression} || {};`,
                'const get = (name) => mod[name];',
                'export default mod;',
                ...names.map((name) => `export const ${name} = /*#__PURE__*/ get(${JSON.stringify(name)});`),
                '',
            ].join('\n');
        },
    };
}

/**
 * Write the dev server's origin to `path` while it runs, and remove it when
 * it stops. TAW\Core\Assets\Vite (PHP) only uses the dev server named in
 * this file, so another project's Vite server on the same port is never
 * mistaken for this one's. The origin is also set as `server.origin`, so
 * URLs inside CSS point at the dev server.
 *
 * @param {{ path?: string }} [options] Relative to the Vite root; must be `{outDir}/hot`.
 */
export function hotFile(options = {}) {
    const path = options.path ?? 'dist/hot';
    const cleanup = () => {
        try {
            unlinkSync(path);
        } catch {
            // Already gone.
        }
    };

    return {
        name: 'taw-hot-file',
        apply: 'serve',
        configureServer(server) {
            const httpServer = server.httpServer;
            if (!httpServer) {
                return; // Middleware mode: there's no origin to publish.
            }

            httpServer.once('listening', () => {
                const address = httpServer.address();
                const port = typeof address === 'object' && address ? address.port : 5173;
                const origin = `http://localhost:${port}`;
                mkdirSync(dirname(path), { recursive: true });
                writeFileSync(path, origin);
                server.config.server.origin = origin;
            });
            httpServer.once('close', cleanup);
            process.once('exit', cleanup);
            for (const signal of ['SIGINT', 'SIGTERM', 'SIGHUP']) {
                process.once(signal, () => {
                    cleanup();
                    process.exit();
                });
            }
        },
    };
}

/**
 * Full page reload when a PHP (or other server-rendered) file changes.
 * Opt-in: add it to `plugins` if you want it.
 *
 * @param {{ extensions?: string[] }} [options]
 */
export function phpReload(options = {}) {
    const extensions = options.extensions ?? ['.php', '.html'];

    return {
        name: 'taw-php-reload',
        apply: 'serve',
        configureServer(server) {
            server.watcher.add(extensions.map((extension) => `**/*${extension}`));
            server.watcher.on('change', (file) => {
                if (extensions.some((extension) => file.endsWith(extension))) {
                    server.config.logger.info(`page reload: ${basename(file)}`);
                    server.ws.send({ type: 'full-reload' });
                }
            });
        },
    };
}
