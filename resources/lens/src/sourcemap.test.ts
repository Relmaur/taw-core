import { beforeEach, describe, expect, it } from 'vitest';
import { parseManifest, readManifest } from './manifest';
import { classifyText, fieldKey, PageMap, plainText, sourceKey, type Attribution, type Source } from './sourcemap';
import { $, fixtureManifest, mountFixture } from './testing';

let map: PageMap;

beforeEach(() => {
    mountFixture();
    map = new PageMap(document, readManifest(document)!);
});

const where = (source: Source) =>
    source.kind === 'field' ? `${source.read.store}:${source.path}` : `${source.file}:${source.line}`;

function summary(a: Attribution): string {
    switch (a.kind) {
        case 'field':
            return `field ${where(a.source)}${a.source.part ? ' (part)' : ''} @${a.node}`;
        case 'template':
            return `template ${where(a.source)} ${a.source.how} @${a.node}`;
        case 'ambiguous':
        case 'mixed':
            return `${a.kind} ${a.sources.map(where).join(' + ')} @${a.node}`;
        default:
            return `${a.kind} @${a.node}`;
    }
}

const at = (selector: string) => summary(map.attribute($(selector)));

describe('the manifest', () => {
    it('reads nodes printed as a list or as an object', () => {
        const asObject = { ...fixtureManifest, nodes: Object.fromEntries(fixtureManifest.nodes.map((n) => [n.id, n])) };
        expect(parseManifest(JSON.stringify(fixtureManifest))?.nodes.size).toBe(5);
        expect(parseManifest(JSON.stringify(asObject))?.nodes.get(2)?.name).toBe('hero');
    });

    it('refuses what it can’t trust', () => {
        expect(parseManifest(null)).toBeNull();
        expect(parseManifest('{not json')).toBeNull();
        expect(parseManifest(JSON.stringify({ ...fixtureManifest, version: 2 }))).toBeNull();
        expect(parseManifest(JSON.stringify({ version: 1, nodes: [], files: {} }))).toBeNull();
    });

    it('is absent on a page without one', () => {
        document.body.innerHTML = '<p>Hi</p>';
        expect(readManifest(document)).toBeNull();
    });
});

describe('what rendered an element', () => {
    it('finds a block’s own field', () => {
        expect(at('h1')).toBe('field post:hero_heading @2');
    });

    it('follows a nested block’s props to the parent’s field', () => {
        expect(at('a.btn')).toBe('field post:hero_cta_text @2');
    });

    it('matches an image to its field in any size or format', () => {
        expect(at('img')).toBe('field post:hero_image @2');
    });

    it('matches a link with no text to its URL field', () => {
        expect(at('a.more')).toBe('field post:hero_cta_url @2');
    });

    it('finds template text, translatable or not, with its line', () => {
        expect(at('h2')).toBe('template page.php:3 i18n @0');
    });

    it('expands repeater rows', () => {
        expect(at('.faq h3')).toBe('field post:faq_items[0].question @4');
        const source = (map.attribute($('.faq p')) as Extract<Attribution, { kind: 'field' }>).source;
        expect(source.label).toBe('Items › Answer (row 1)');
    });

    it('tells WordPress data apart: the site title, and a menu item over the title of the page it links to', () => {
        expect(at('.logo')).toBe('field wp:blogname @1');
        expect(at('nav a[href="/about/"]')).toBe('field menu:item-14 @1');
        expect(at('nav a[href="/docs/"]')).toBe('field menu:item-15 @1');
    });

    it('reports the same text from a field and the template as ambiguous, with both, and never picks one', () => {
        expect(at('.note')).toBe('ambiguous post:lens_note + page.php:4 @0');
    });

    it('finds rich text whole or in parts', () => {
        expect(at('.body')).toBe('field post:lens_body @0');
        expect(at('.body p')).toBe('field post:lens_body (part) @0');
        expect(at('.body strong')).toBe('field post:lens_body (part) @0');
    });

    it('calls template text around values mixed, with the pieces', () => {
        expect(at('.phone')).toBe('mixed option:company_phone + page.php:5 @0');
        expect(at('.date')).toBe('mixed page.php:6 + page.php:6 @0');
    });

    it('calls a block’s outermost element the block', () => {
        expect(at('section.hero')).toBe('block @2');
        expect(at('section.faq')).toBe('block @4');
    });

    it('says when nothing matches, keeping where it sits', () => {
        expect(at('.js')).toBe('element @0');
        expect(at('body')).toBe('block @0');
    });

    it('ignores values that never show as text', () => {
        expect(classifyText(map.manifest, 0, '1').kind).toBe('element');
    });
});

describe('the page index', () => {
    it('knows each node’s outermost elements', () => {
        expect(map.rootsOf(2)).toEqual([$('section.hero')]);
        expect(map.rootsOf(3)).toEqual([$('a.btn')]);
        expect(map.rootsOf(0)).toContain($('main'));
    });

    it('finds the elements each source rendered, and each field’s across rows', () => {
        const heading = map.manifest.nodes.get(2)!.reads[0];
        expect(map.elementsOf(fieldKey(heading))).toEqual([$('h1')]);
        const faq = map.manifest.nodes.get(4)!.reads[0];
        expect(map.elementsOf(fieldKey(faq))).toEqual([$('.faq h3'), $('.faq p')]);
        const both = (map.attribute($('.note')) as Extract<Attribution, { kind: 'ambiguous' }>).sources;
        expect(both.map((s) => map.elementsOf(sourceKey(s)))).toEqual([[$('.note')], [$('.note')]]);
    });

    it('leaves Lens’s own element out', () => {
        const host = document.createElement('taw-lens');
        host.textContent = 'Point at anything';
        document.body.appendChild(host);
        const withHost = new PageMap(document, map.manifest, host);
        expect(withHost.elementsOf(fieldKey(map.manifest.nodes.get(2)!.reads[0]))).toEqual([$('h1')]);
    });

    it('survives a marker that closes out of order', () => {
        document.body.innerHTML =
            '<!--taw-lens:2--><section><!--taw-lens:3--><p>x</p><!--/taw-lens:2--></section><p>y</p>';
        const broken = new PageMap(document, map.manifest);
        expect(broken.nodeOf($('section p'))).toBe(3);
        expect(broken.nodeOf($('body > p'))).toBe(0);
    });
});

it('reads rich text as the page shows it', () => {
    expect(plainText('<p>Rock &amp;\n <b>roll</b></p>')).toBe('Rock & roll');
});
