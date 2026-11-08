import { beforeEach, describe, expect, it } from 'vitest';
import { readManifest } from './manifest';
import { PageMap } from './sourcemap';
import { $, mountFixture } from './testing';
import { ancestorsOf, buildTree, rowIdFor, visibleRows, type TreeNode } from './treeModel';

let map: PageMap;
let root: TreeNode;

beforeEach(() => {
    mountFixture();
    map = new PageMap(document, readManifest(document)!);
    root = buildTree(map);
});

const outline = (node: TreeNode, depth = 0): string[] => [
    `${'  '.repeat(depth)}${node.kind} ${node.name} ${node.detail}`,
    ...node.children.flatMap((child) => outline(child, depth + 1)),
];

it('shows the page as TAW sees it, in page order, with only what is on the page', () => {
    expect(outline(root)).toEqual([
        'page Home page.php',
        '  part Header header.php',
        '    wp Site Title WordPress',
        '    wp Menu item “About” WordPress',
        '    wp Menu item “Docs” WordPress',
        '  block Hero hero',
        '    field hero_heading field',
        '    field hero_image field',
        '    block Button button',
        '    field hero_cta_text field',
        '    field hero_cta_url field',
        '  template Why TAW Lens page.php:3',
        '  field lens_note field',
        '  template Same words twice. page.php:4',
        '  field lens_body field',
        '  block FAQ faq',
        '    field faq_items field',
    ]);
});

it('rings a field’s every element, a block’s outermost ones, and nothing for the page', () => {
    const hero = root.children.find((n) => n.name === 'Hero')!;
    expect(hero.target.elements).toEqual([$('section.hero')]);
    const faq = root.children.find((n) => n.name === 'FAQ')!.children[0];
    expect(faq.target.elements).toEqual([$('.faq h3'), $('.faq p')]);
    expect(root.target.elements).toEqual([]);
});

describe('rows', () => {
    it('show what is open', () => {
        expect(visibleRows(root, new Set([root.id])).map((r) => r.node.name)).toEqual([
            'Home',
            'Header',
            'Hero',
            'Why TAW Lens',
            'lens_note',
            'Same words twice.',
            'lens_body',
            'FAQ',
        ]);
        const rows = visibleRows(root, new Set([root.id, 'n:2']));
        expect(rows.find((r) => r.node.name === 'hero_heading')).toMatchObject({ depth: 2, parent: 'n:2' });
        expect(rows.find((r) => r.node.name === 'Hero')?.expanded).toBe(true);
    });

    it('filter by name or file, keeping the way down to each match open', () => {
        expect(visibleRows(root, new Set(), 'cta').map((r) => r.node.name)).toEqual([
            'Home',
            'Hero',
            'hero_cta_text',
            'hero_cta_url',
        ]);
        expect(visibleRows(root, new Set(), 'page.php:4').map((r) => r.node.name)).toEqual([
            'Home',
            'Same words twice.',
        ]);
        expect(visibleRows(root, new Set(), 'nothing like this')).toEqual([]);
    });
});

it('finds the rows above a row, and the row for a selection', () => {
    const target = { key: 'x', attribution: map.attribute($('.faq h3')), elements: [$('.faq h3')], at: 4 };
    const id = rowIdFor(target)!;
    expect(id).toBe('f:post:12:faq_items');
    expect(ancestorsOf(root, id)).toEqual(['n:0', 'n:4']);
    expect(rowIdFor({ ...target, attribution: map.attribute($('.note')) })).toBeNull();
});
