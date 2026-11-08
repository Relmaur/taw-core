import { act } from 'preact/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { HOST_TAG, mount, pageEnv } from './index';

afterEach(() => {
    document.querySelector(HOST_TAG)?.remove();
    document.getElementById('taw-lens-push')?.remove();
    document.getElementById('wpadminbar')?.remove();
    localStorage.clear();
});

describe('mount', () => {
    it('renders inside a shadow root on one host at the end of <html>, once', () => {
        const host = mount();
        expect(host.parentElement).toBe(document.documentElement);
        expect(host.shadowRoot?.querySelector('aside[aria-label="TAW Lens"]')).not.toBeNull();
        expect(host.shadowRoot?.querySelector('style')?.textContent).toContain('--lens-surface');
        expect(mount()).toBe(host);
        expect(document.querySelectorAll(HOST_TAG)).toHaveLength(1);
    });

    it('adds nothing to the page but the docked push style', () => {
        act(() => void mount());
        expect(document.body.querySelector('[class*="lens"]')).toBeNull();
        expect(document.getElementById('taw-lens-push')?.textContent).toBe('html { margin-right: 400px !important; }');
    });
});

describe('pageEnv', () => {
    it('removes the push style when the panel undocks', () => {
        const host = document.createElement(HOST_TAG);
        const env = pageEnv(document, host);
        env.push('left');
        expect(document.getElementById('taw-lens-push')?.textContent).toContain('margin-left');
        env.push(null);
        expect(document.getElementById('taw-lens-push')).toBeNull();
    });

    it('sets data-theme on the host only for a manual choice', () => {
        const host = document.createElement(HOST_TAG);
        const env = pageEnv(document, host);
        env.theme('dark');
        expect(host.dataset.theme).toBe('dark');
        env.theme('system');
        expect(host.hasAttribute('data-theme')).toBe(false);
    });

    it('starts under a fixed admin bar', () => {
        const bar = document.createElement('div');
        bar.id = 'wpadminbar';
        bar.style.position = 'fixed';
        bar.getBoundingClientRect = () => ({ bottom: 32 }) as DOMRect;
        document.body.appendChild(bar);
        expect(pageEnv(document, document.createElement('div')).viewport().top).toBe(32);
    });
});
