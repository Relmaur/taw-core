import { beforeEach, expect, it } from 'vitest';
import { chipLabel, editorUrl, fieldName, location, shortName, truncate, type Target } from './describe';
import { readManifest } from './manifest';
import { PageMap } from './sourcemap';
import { $, mountFixture } from './testing';

let map: PageMap;
const target = (selector: string): Target => {
    const el = $(selector);
    return { key: selector, attribution: map.attribute(el), elements: [el], at: map.nodeOf(el) };
};

beforeEach(() => {
    mountFixture();
    map = new PageMap(document, readManifest(document)!);
});

it('labels a ring with its block and what it is (screen 05: taw/hero › hero_title)', () => {
    expect(chipLabel(map.manifest, target('h1'))).toBe('hero › hero_heading');
    expect(chipLabel(map.manifest, target('a.btn'))).toBe('button › hero_cta_text');
    expect(chipLabel(map.manifest, target('section.hero'))).toBe('hero');
    expect(chipLabel(map.manifest, target('h2'))).toBe('page.php › “Why TAW Lens”');
    expect(chipLabel(map.manifest, target('.note'))).toBe('page.php › ambiguous');
    expect(chipLabel(map.manifest, target('.js'))).toBe('page.php › span');
});

it('says where a thing is, from the page down', () => {
    expect(location(map.manifest, 2, 'Heading')).toBe('Home › Hero › Heading');
    expect(location(map.manifest, 3)).toBe('Home › Hero › Button');
});

it('names WordPress data the way wp-admin does', () => {
    const header = map.manifest.nodes.get(1)!;
    expect(fieldName(header.reads[0])).toBe('Site Title');
    expect(fieldName(header.reads[1])).toBe('Post title');
    expect(fieldName(header.reads[2])).toBe('Menu item “About”');
    expect(shortName(map.manifest, target('section.faq'))).toBe('FAQ');
    expect(shortName(map.manifest, target('.js'))).toBe('<span>');
});

it('builds the editor link with the file’s path and line', () => {
    expect(editorUrl('vscode://file/{path}:{line}', '/Users/me/Local Sites/acme/page.php', 12)).toBe(
        'vscode://file//Users/me/Local%20Sites/acme/page.php:12',
    );
    expect(editorUrl('phpstorm://open?file={path}&line={line}', '/a.php')).toBe('phpstorm://open?file=/a.php&line=1');
});

it('truncates long text with an ellipsis', () => {
    expect(truncate('abcdef', 4)).toBe('abc…');
    expect(truncate('abc', 4)).toBe('abc');
});
