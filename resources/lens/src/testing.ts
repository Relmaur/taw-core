/**
 * Test fixture: a page as taw/core's source map prints it (markers + manifest), with every kind of source the
 * matcher must tell apart. The same shapes as the Lens Test page on the dev site. Imported by tests only.
 */
import { MANIFEST_ID } from './manifest';

const NOTE = 'Same words twice.';

export const fixtureManifest = {
    version: 1,
    nodes: [
        {
            id: 0,
            kind: 'page',
            name: 'page.php',
            label: 'Home',
            parent: null,
            template: 'page.php',
            reads: [
                {
                    store: 'post',
                    object: 12,
                    key: 'post_title',
                    type: 'wp',
                    label: 'Title',
                    value: 'Lens Test',
                    admin: '/wp-admin/post.php?post=12&action=edit',
                },
                {
                    store: 'post',
                    object: 12,
                    key: 'lens_note',
                    type: 'text',
                    edit: 'text',
                    label: 'Note',
                    value: NOTE,
                    admin: '/wp-admin/post.php?post=12&action=edit',
                },
                {
                    store: 'post',
                    object: 12,
                    key: 'lens_body',
                    type: 'wysiwyg',
                    edit: 'rich',
                    formats: ['bold', 'italic', 'link', 'lists'],
                    label: 'Body',
                    value: '<p><strong>Rich</strong> text, with a link.</p>\n<p>Second paragraph.</p>',
                    admin: '/wp-admin/post.php?post=12&action=edit',
                },
                {
                    store: 'option',
                    object: 'taw-settings',
                    key: 'company_phone',
                    type: 'text',
                    edit: 'text',
                    label: 'Phone',
                    value: '555-0100',
                    admin: '/wp-admin/admin.php?page=taw-settings',
                    default: true,
                },
                { store: 'post', object: 12, key: 'show_cta', type: 'checkbox', label: 'Show', value: '1', admin: '' },
            ],
        },
        {
            id: 1,
            kind: 'part',
            name: 'header',
            label: 'Header',
            parent: 0,
            template: 'header.php',
            reads: [
                {
                    store: 'wp',
                    object: 'options',
                    key: 'blogname',
                    type: 'wp',
                    label: 'Site Title',
                    value: 'Awesome Company',
                    admin: '/wp-admin/options-general.php',
                },
                {
                    store: 'post',
                    object: 30,
                    key: 'post_title',
                    type: 'wp',
                    label: 'Title',
                    value: 'About',
                    admin: '/wp-admin/post.php?post=30&action=edit',
                },
                {
                    store: 'menu',
                    object: 3,
                    key: 'item-14',
                    type: 'wp',
                    label: 'Menu item',
                    value: 'About',
                    admin: '/wp-admin/nav-menus.php?action=edit&menu=3',
                    target: 30,
                },
                {
                    store: 'menu',
                    object: 3,
                    key: 'item-15',
                    type: 'wp',
                    label: 'Menu item',
                    value: 'Docs',
                    admin: '/wp-admin/nav-menus.php?action=edit&menu=3',
                },
            ],
        },
        {
            id: 2,
            kind: 'block',
            name: 'hero',
            label: 'Hero',
            parent: 0,
            template: 'Blocks/Hero/index.php',
            files: ['Blocks/Hero/Hero.php', 'Blocks/Hero/index.php', 'Blocks/Hero/style.css'],
            class: 'TAW\\Blocks\\Hero\\Hero',
            post: 12,
            reads: [
                {
                    store: 'post',
                    object: 12,
                    key: 'hero_heading',
                    type: 'text',
                    edit: 'text',
                    label: 'Heading',
                    value: 'Point at anything',
                    admin: '/wp-admin/post.php?post=12&action=edit',
                },
                {
                    store: 'post',
                    object: 12,
                    key: 'hero_image',
                    type: 'image',
                    label: 'Image',
                    value: '37',
                    admin: '/wp-admin/post.php?post=12&action=edit',
                    stem: 'hero-photo',
                },
                {
                    store: 'post',
                    object: 12,
                    key: 'hero_cta_text',
                    type: 'text',
                    edit: 'text',
                    label: 'CTA Text',
                    value: 'Get started',
                    admin: '/wp-admin/post.php?post=12&action=edit',
                },
                {
                    store: 'post',
                    object: 12,
                    key: 'hero_cta_url',
                    type: 'url',
                    label: 'CTA URL',
                    value: '/contact/',
                    admin: '/wp-admin/post.php?post=12&action=edit',
                },
            ],
        },
        {
            id: 3,
            kind: 'block',
            name: 'button',
            label: 'Button',
            parent: 2,
            template: 'Blocks/Button/index.php',
            files: ['Blocks/Button/Button.php', 'Blocks/Button/index.php'],
            post: null,
            reads: [],
        },
        {
            id: 4,
            kind: 'block',
            name: 'faq',
            label: 'FAQ',
            parent: 0,
            template: 'Blocks/FAQ/index.php',
            files: ['Blocks/FAQ/FAQ.php', 'Blocks/FAQ/index.php'],
            post: 12,
            reads: [
                {
                    store: 'post',
                    object: 12,
                    key: 'faq_items',
                    type: 'repeater',
                    label: 'Items',
                    value: '[{"question":"What is Lens?","answer":"A picker."}]',
                    admin: '/wp-admin/post.php?post=12&action=edit',
                    subs: {
                        question: { label: 'Question', type: 'text', edit: 'text' },
                        answer: { label: 'Answer', type: 'textarea', edit: 'text' },
                    },
                },
            ],
        },
    ],
    files: {
        'page.php': {
            abs: '/sites/acme/wp-content/themes/acme/page.php',
            texts: [
                { text: 'Why TAW Lens', line: 3, kind: 'i18n' },
                { text: NOTE, line: 4, kind: 'html' },
                { text: 'Call us:', line: 5, kind: 'html' },
                { text: 'Last edited on', line: 6, kind: 'html' },
                { text: 'by the team.', line: 6, kind: 'html' },
            ],
            lines: {
                '2': '<main>',
                '3': "  <h2><?php esc_html_e('Why TAW Lens', 'acme'); ?></h2>",
                '4': `  <p class="note">${NOTE}</p>`,
            },
        },
        'header.php': { abs: '/sites/acme/wp-content/themes/acme/header.php', texts: [], lines: {} },
        'Blocks/Hero/Hero.php': { abs: '/sites/acme/wp-content/themes/acme/Blocks/Hero/Hero.php' },
        'Blocks/Hero/index.php': {
            abs: '/sites/acme/wp-content/themes/acme/Blocks/Hero/index.php',
            texts: [],
            lines: {},
        },
        'Blocks/Hero/style.css': { abs: '/sites/acme/wp-content/themes/acme/Blocks/Hero/style.css' },
        'Blocks/Button/Button.php': { abs: '/sites/acme/wp-content/themes/acme/Blocks/Button/Button.php' },
        'Blocks/Button/index.php': {
            abs: '/sites/acme/wp-content/themes/acme/Blocks/Button/index.php',
            texts: [],
            lines: {},
        },
        'Blocks/FAQ/FAQ.php': { abs: '/sites/acme/wp-content/themes/acme/Blocks/FAQ/FAQ.php' },
        'Blocks/FAQ/index.php': {
            abs: '/sites/acme/wp-content/themes/acme/Blocks/FAQ/index.php',
            texts: [],
            lines: {},
        },
    },
};

export const fixtureBody = `
<!--taw-lens:1--><header><a class="logo" href="/">Awesome Company</a><nav><a href="/about/">About</a><a href="/docs/">Docs</a></nav></header><!--/taw-lens:1-->
<!--taw-lens:2--><section class="hero"><h1>Point at anything</h1><img src="/wp-content/uploads/2026/11/hero-photo-800x600.avif" alt=""><!--taw-lens:3--><a class="btn" href="/contact/">Get started</a><!--/taw-lens:3--><a class="more" href="/contact/"><span></span></a></section><!--/taw-lens:2-->
<main>
  <h2>Why TAW Lens</h2>
  <p class="note">${NOTE}</p>
  <p class="phone">Call us: 555-0100</p>
  <div class="body"><p><strong>Rich</strong> text, with a link.</p>
<p>Second paragraph.</p></div>
  <p class="date">Last edited on 2026-11-06 by the team.</p>
  <span class="js">Added later</span>
  <!--taw-lens:4--><section class="faq"><h3>What is Lens?</h3><p>A picker.</p></section><!--/taw-lens:4-->
</main>
<script type="application/json" id="${MANIFEST_ID}">${JSON.stringify(fixtureManifest)}</script>
`;

/** Puts the fixture page in the test document. */
export function mountFixture(doc: Document = document): Document {
    doc.body.innerHTML = fixtureBody;

    return doc;
}

export const $ = (selector: string, doc: Document = document): Element => {
    const el = doc.querySelector(selector);
    if (!el) {
        throw new Error(`No ${selector} in the fixture`);
    }
    return el;
};
