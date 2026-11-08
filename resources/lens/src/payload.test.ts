import { beforeEach, describe, expect, it } from 'vitest';
import type { Target } from './describe';
import { readManifest } from './manifest';
import { messageChips, selectionsPayload, selectorOf } from './payload';
import { PageMap } from './sourcemap';
import { $, mountFixture } from './testing';

let map: PageMap;
const target = (selector: string): Target => {
    const el = $(selector);
    return { key: selector, attribution: map.attribute(el), elements: [el], at: map.nodeOf(el) };
};
const page = { url: 'https://acme.local/lens-test/', title: 'Lens Test' };

beforeEach(() => {
    mountFixture();
    map = new PageMap(document, readManifest(document)!);
});

describe('what Claude receives about the selections', () => {
    it('is nothing without a selection', () => {
        expect(selectionsPayload(map.manifest, [], page)).toBe('');
        expect(selectionsPayload(null, [target('h1')], page)).toBe('');
    });

    it('names a field, where it is stored, its value, how to change it by hand, and the element', () => {
        const text = selectionsPayload(map.manifest, [target('h1')], page);
        expect(text).toBe(
            [
                '<lens-selections page="Lens Test" url="https://acme.local/lens-test/">',
                'What the developer selected on the page, from TAW’s source map:',
                '1. Field hero_heading (Heading, text)',
                '   Stored on: post 12',
                '   Value: "Point at anything"',
                '   Rendered in: Home › Hero (block hero, Blocks/Hero/index.php)',
                '   By hand: /wp-admin/post.php?post=12&action=edit',
                '   Element: <h1> at `section.hero > h1`',
                '</lens-selections>',
            ].join('\n'),
        );
    });

    it('names template text with its file and line, and says when it is translatable', () => {
        const text = selectionsPayload(map.manifest, [target('h2')], page);
        expect(text).toContain('1. Template text "Why TAW Lens"');
        expect(text).toContain('   File: page.php line 3, translatable (inside a gettext call such as esc_html_e)');
    });

    it('lists every candidate of an ambiguous selection and asks Claude not to pick', () => {
        const text = selectionsPayload(map.manifest, [target('.note')], page);
        expect(text).toContain(
            'Ambiguous: the text "Same words twice." comes from 2 places. Don\'t pick one: ask which.',
        );
        expect(text).toContain('Candidate 1:');
        expect(text).toContain('     Field lens_note (Note, text)');
        expect(text).toContain('     Template text "Same words twice."');
    });

    it('describes repeater rows, defaults, options, WordPress data, blocks and unknown elements', () => {
        const text = selectionsPayload(
            map.manifest,
            [
                target('.faq h3'),
                target('.phone'),
                target('nav a[href="/about/"]'),
                target('section.hero'),
                target('.js'),
            ],
            page,
        );
        expect(text).toContain('1. Field faq_items, row 1, sub-field question (Items › Question (row 1); repeater)');
        expect(text).toContain('Piece 1:');
        expect(text).toContain('Stored on: options page taw-settings (taw fields:set options …)');
        expect(text).toContain('Nothing is stored yet: the page shows the field’s default.');
        expect(text).toContain('3. WordPress data: Menu item');
        expect(text).toContain('Menu item item-14 in menu 3, linking to post 30');
        expect(text).toContain('4. Block hero (Hero)');
        expect(text).toContain('Files: Blocks/Hero/Hero.php, Blocks/Hero/index.php, Blocks/Hero/style.css');
        expect(text).toContain(
            'Its fields: hero_heading (Heading), hero_image (Image), hero_cta_text (CTA Text), hero_cta_url (CTA URL)',
        );
        expect(text).toContain(
            '5. An element whose text TAW didn\'t render from a field or template text: "Added later"',
        );
    });

    it('labels the chips on the sent message', () => {
        expect(
            messageChips(map.manifest, [
                target('h1'),
                target('h2'),
                target('section.hero'),
                target('.note'),
                target('.logo'),
            ]),
        ).toEqual([
            'hero_heading · field',
            '“Why TAW Lens” · template text',
            'Hero · block',
            '“Same words twice.” · ambiguous',
            'Site Title · WordPress',
        ]);
    });
});

it('writes short selectors: an id ends the climb, siblings get their position', () => {
    document.body.innerHTML = '<main id="content"><ul class="list x y"><li>a</li><li>b</li></ul></main>';
    expect(selectorOf(document.querySelectorAll('li')[1])).toBe('#content > ul.list.x > li:nth-of-type(2)');
});
