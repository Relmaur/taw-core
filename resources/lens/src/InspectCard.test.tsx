import { fireEvent, render, screen } from '@testing-library/preact';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Target } from './describe';
import { InspectCard } from './InspectCard';
import { readManifest } from './manifest';
import { PageMap } from './sourcemap';
import { $, mountFixture } from './testing';

const EDITOR = 'vscode://file/{path}:{line}';
let map: PageMap;

beforeEach(() => {
    mountFixture();
    map = new PageMap(document, readManifest(document)!);
});

function card(selector: string, overrides: Partial<Target> = {}) {
    const el = $(selector);
    const target: Target = {
        key: selector,
        attribution: map.attribute(el),
        elements: [el],
        at: map.nodeOf(el),
        ...overrides,
    };
    const onClose = vi.fn();
    const onShowInTree = vi.fn();
    const { container } = render(
        <InspectCard
            manifest={map.manifest}
            target={target}
            editor={EDITOR}
            onClose={onClose}
            onShowInTree={onShowInTree}
        />,
        { container: document.body.appendChild(document.createElement('div')) },
    );
    return { container, onClose, onShowInTree, target };
}

describe('a field (screen 06, column 1)', () => {
    it('shows its name, type, location, value and where it rendered, with the way to change it by hand', () => {
        const { container } = card('h1');
        expect(container.querySelector('.card-title')).toHaveTextContent('hero_heading');
        expect(screen.getByText('Field · text')).toBeInTheDocument();
        expect(screen.getByText('Home › Hero › Heading')).toBeInTheDocument();
        expect(container.querySelector('.card-value')).toHaveTextContent('Point at anything');
        expect(screen.getByText(/Rendered by/)).toHaveTextContent('Rendered by Blocks/Hero/index.php');
        expect(screen.getByRole('link', { name: 'Open in wp-admin' })).toHaveAttribute(
            'href',
            '/wp-admin/post.php?post=12&action=edit',
        );
        // No editing without the page's edit support (Edit.test.tsx covers it with the panel).
        expect(screen.queryByRole('button', { name: 'Edit here' })).toBeNull();
    });

    it('names a repeater row and shows only its value', () => {
        const { container } = card('.faq p');
        expect(container.querySelector('.card-title')).toHaveTextContent('faq_items[0].answer');
        expect(screen.getByText('Field · long text')).toBeInTheDocument();
        expect(container.querySelector('.card-value')).toHaveTextContent(/^A picker\.$/);
    });

    it('says when it shows the field’s default, and when it is part of rich text', () => {
        card('.body p');
        expect(screen.getByText('Part of this rich-text field.')).toBeInTheDocument();
        expect(screen.getByText('Field · rich text')).toBeInTheDocument();
    });

    it('treats WordPress data as WordPress’s, with no in-place edit', () => {
        card('.logo');
        expect(screen.getByText('WordPress')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Edit here' })).toBeNull();
        expect(screen.getByRole('link', { name: 'Open in wp-admin' })).toHaveAttribute(
            'href',
            '/wp-admin/options-general.php',
        );
    });

    it('clears the selection', () => {
        const { onClose } = card('h1');
        fireEvent.click(screen.getByRole('button', { name: 'Clear selection' }));
        expect(onClose).toHaveBeenCalled();
    });
});

it('shows template text with its lines, the current one marked, and opens it at that line', () => {
    const { container } = card('h2');
    expect(screen.getByText('Template text · translatable')).toBeInTheDocument();
    const lines = [...container.querySelectorAll('.card-code-line')];
    expect(lines.map((l) => l.querySelector('.card-code-number')?.textContent)).toEqual(['2', '3', '4']);
    expect(lines[1]).toHaveAttribute('data-current');
    expect(screen.getByText('page.php:3')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Open in editor' })).toHaveAttribute(
        'href',
        'vscode://file//sites/acme/wp-content/themes/acme/page.php:3',
    );
});

it('shows a block with its files and fields, and finds it in the tree', () => {
    const { container, onShowInTree, target } = card('section.hero');
    expect(screen.getByText('Block · hero')).toBeInTheDocument();
    expect([...container.querySelectorAll('.card-files li')].map((li) => li.textContent)).toEqual([
        'Blocks/Hero/Hero.php',
        'Blocks/Hero/index.php',
        'Blocks/Hero/style.css',
    ]);
    expect(screen.getByText(/Fields:/)).toHaveTextContent(
        'Fields: hero_heading, hero_image, hero_cta_text, hero_cta_url',
    );
    expect(screen.getByRole('link', { name: 'Open in editor' })).toHaveAttribute(
        'href',
        'vscode://file//sites/acme/wp-content/themes/acme/Blocks/Hero/index.php:1',
    );
    fireEvent.click(screen.getByRole('button', { name: 'Show in tree' }));
    expect(onShowInTree).toHaveBeenCalledWith(target);
});

it('lists every candidate when the text is ambiguous, each with its way to change it', () => {
    const { container } = card('.note');
    expect(screen.getByText('Ambiguous')).toBeInTheDocument();
    const items = [...container.querySelectorAll('.card-sources li')];
    expect(items).toHaveLength(2);
    expect(items[0]).toHaveTextContent('lens_note');
    expect(items[0].querySelector('a')).toHaveAttribute('href', '/wp-admin/post.php?post=12&action=edit');
    expect(items[1]).toHaveTextContent('page.php:4');
    expect(items[1].querySelector('a')).toHaveAttribute(
        'href',
        'vscode://file//sites/acme/wp-content/themes/acme/page.php:4',
    );
});

it('lists the pieces of mixed text', () => {
    const { container } = card('.phone');
    expect(screen.getByText('Mixed')).toBeInTheDocument();
    expect([...container.querySelectorAll('.card-source-name')].map((n) => n.textContent)).toEqual([
        'company_phone',
        '“Call us:”',
    ]);
});

it('says plainly when the text is neither a field nor template text', () => {
    card('.js');
    expect(screen.getByText('Element')).toBeInTheDocument();
    expect(screen.getByText(/may be computed in the template, or added by JavaScript/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Open in editor' })).toHaveAttribute(
        'href',
        expect.stringContaining('page.php:1'),
    );
});
