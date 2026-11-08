import { describe, expect, it } from 'vitest';
import { panelCss } from './styles';

// jsdom doesn't run the cascade, so these read the stylesheet itself (issue #225).
describe('panel stylesheet', () => {
    const css = panelCss();

    it('keeps the button reset at zero specificity, so a button class sets its own colour', () => {
        // `.lens button { color: inherit }` (0,1,1) beat `.send { color: on-accent }` (0,1,0): the Send arrow
        // took the panel's text colour, the same as its fill, in both themes.
        expect(css).toContain(':where(.lens button, .lens input)');
        expect(css).not.toMatch(/(^|[}\s])\.lens button,\s*\.lens input\s*\{/);
        expect(css).toMatch(/\.send \{[^}]*color: var\(--lens-on-accent\)/);
    });

    it('draws no focus ring on the message field (owner, 2026-11-08)', () => {
        expect(css).toMatch(/\.composer-input:focus-visible \{\s*outline: none;/);
    });
});
