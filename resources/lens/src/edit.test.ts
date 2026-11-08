import { beforeEach, describe, expect, it } from 'vitest';
import type { Target } from './describe';
import { fieldRequest, planEdit, shownValue, templateMessage, type EditPlan } from './edit';
import { readManifest } from './manifest';
import { PageMap, type FieldSource } from './sourcemap';
import { $, mountFixture } from './testing';

let map: PageMap;
const target = (selector: string): Target => {
    const el = $(selector);
    return { key: selector, attribution: map.attribute(el), elements: [el], at: map.nodeOf(el) };
};
const plan = (selector: string): EditPlan => {
    const verdict = planEdit(map, target(selector));
    if (!verdict || !('plan' in verdict)) {
        throw new Error(`${selector}: ${verdict ? verdict.reason : 'not editable'}`);
    }
    return verdict.plan;
};
const reason = (selector: string): string => {
    const verdict = planEdit(map, target(selector));
    if (!verdict || !('reason' in verdict)) {
        throw new Error(`${selector} has no reason`);
    }
    return verdict.reason;
};
const rescan = () => {
    map = new PageMap(document, readManifest(document)!);
};

beforeEach(() => {
    mountFixture();
    rescan();
});

describe('what Lens edits on the page (component 05)', () => {
    it('edits a text field as plain text, starting from its stored value', () => {
        const p = plan('h1');
        expect(p).toMatchObject({ kind: 'field', mode: 'text', original: 'Point at anything', el: $('h1') });
    });

    it('edits a repeater row’s sub-field', () => {
        const p = plan('.faq h3');
        expect(p.kind === 'field' && p.source.path).toBe('faq_items[0].question');
        expect(p.original).toBe('What is Lens?');
    });

    it('edits a rich-text field from the element that shows all of it, even when a paragraph was picked', () => {
        const p = plan('.body p');
        expect(p).toMatchObject({ kind: 'field', mode: 'rich', el: $('.body') });
        expect(p.kind === 'field' && p.mode === 'rich' && p.formats).toEqual(['bold', 'italic', 'link', 'lists']);
        expect(p.kind === 'field' && p.mode === 'rich' && p.blocks).toEqual([
            { type: 'p', html: '<strong>Rich</strong> text, with a link.' },
            { type: 'p', html: 'Second paragraph.' },
        ]);
    });

    it('edits template text, which Claude saves', () => {
        expect(plan('h2')).toMatchObject({ kind: 'template', original: 'Why TAW Lens' });
    });

    it('leaves ambiguous, mixed, blocks, WordPress data and images alone', () => {
        for (const selector of ['.note', '.phone', 'section.hero', '.logo', 'nav a[href="/about/"]', 'img', '.js']) {
            expect(planEdit(map, target(selector)), selector).toBeNull();
        }
    });

    it('says why when the page doesn’t show the stored value as it is', () => {
        $('h1').innerHTML = 'Point at <em>anything</em>';
        rescan();
        expect(reason('h1')).toMatch(/shows this field differently/);
    });

    it('won’t flatten a long text’s line breaks', () => {
        const manifest = JSON.parse(document.getElementById('taw-lens-map')!.textContent!);
        manifest.nodes[4].reads[0].value = '[{"question":"What is Lens?","answer":"Line one\\nLine two"}]';
        document.getElementById('taw-lens-map')!.textContent = JSON.stringify(manifest);
        $('.faq p').textContent = 'Line one Line two';
        rescan();
        expect(reason('.faq p')).toMatch(/line breaks/);
    });

    it('won’t edit rich text with formatting the field’s formats don’t cover', () => {
        const manifest = JSON.parse(document.getElementById('taw-lens-map')!.textContent!);
        manifest.nodes[0].reads[2].value = '<p><img src="/a.jpg"> Rich text, with a link.</p>';
        document.getElementById('taw-lens-map')!.textContent = JSON.stringify(manifest);
        $('.body').innerHTML = '<p><img src="/a.jpg"> Rich text, with a link.</p>';
        rescan();
        expect(reason('.body')).toMatch(/formatting Lens can’t edit/);
    });

    it('won’t edit a field the source map doesn’t mark editable', () => {
        const manifest = JSON.parse(document.getElementById('taw-lens-map')!.textContent!);
        delete manifest.nodes[2].reads[0].edit;
        document.getElementById('taw-lens-map')!.textContent = JSON.stringify(manifest);
        rescan();
        expect(reason('h1')).toMatch(/can’t be edited on the page/);
    });
});

describe('what an edit becomes', () => {
    it('a field save: the field as the source map read it, the new value and the one the page showed', () => {
        const p = plan('h1') as Extract<EditPlan, { kind: 'field' }>;
        expect(fieldRequest(p.source, 'New', p.original)).toEqual({
            store: 'post',
            object: 12,
            key: 'hero_heading',
            value: 'New',
            expect: 'Point at anything',
        });
        const row = plan('.faq h3') as Extract<EditPlan, { kind: 'field' }>;
        expect(fieldRequest(row.source, 'Why?', row.original)).toMatchObject({
            key: 'faq_items',
            row: 0,
            sub: 'question',
        });
    });

    it('a row value is read out of its rows', () => {
        const source = (plan('.faq p') as { source: FieldSource }).source;
        expect(shownValue(source.read, 'faq_items[0].answer')).toBe('A picker.');
        expect(shownValue(source.read, 'faq_items[3].answer')).toBeNull();
    });

    it('template text: a message to Claude with the file, line, before and after, and the selection', () => {
        const t = target('h2');
        const p = plan('h2') as Extract<EditPlan, { kind: 'template' }>;
        const message = templateMessage(map.manifest, t, p.source, 'Why Lens', {
            url: 'https://acme.local/',
            title: 'Home',
        });
        expect(message.text).toBe('Change "Why TAW Lens" to "Why Lens".');
        expect(message.from).toBe('edit');
        expect(message.chips).toEqual(['“Why TAW Lens” · template text']);
        expect(message.context).toContain(
            '<lens-edit file="page.php" line="3">\nBefore: Why TAW Lens\nAfter: Why Lens\n</lens-edit>',
        );
        expect(message.context).toContain('<lens-selections page="Home" url="https://acme.local/">');
    });
});
