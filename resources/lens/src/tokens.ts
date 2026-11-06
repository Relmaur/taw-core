/**
 * Lens panel design tokens, light and dark (design round 1, component 01; the screen exports are the
 * source of truth where they add values the component spec leaves out: dark diff colours, the
 * approval card's file label and inner rules, button and composer borders).
 *
 * The owner's rules these encode: calm (hairlines, no boxes); the accent is ink, never blue; amber
 * only means "needs your OK"; nothing else is coloured; errors are ink.
 *
 * Tokens become CSS custom properties on the Lens shadow host, so they never reach the site's
 * styles and the site's styles never reach them. The theme follows `prefers-color-scheme` unless the
 * host carries `data-theme="light"` or `data-theme="dark"` (the remembered manual override).
 */

export interface LensTokens {
    /** Panel background. */
    surface: string;
    /** Hairlines: panel edge, separators. */
    line: string;
    /** Body text. */
    text: string;
    /** Secondary text: host, activity line, hints. */
    muted: string;
    /** Secondary text, quieter (placeholders, labels). */
    mutedSoft: string;
    /** The developer's message bubble. */
    bubble: string;
    /** Selection and field chips. */
    chip: string;
    /** The accent: ink. Primary buttons, focus, the selection ring. */
    accent: string;
    /** Text on the accent (primary button label). */
    onAccent: string;
    /** Secondary button border (Skip). */
    buttonLine: string;
    /** Composer border. */
    composerLine: string;
    /** Amber: "needs your OK" only. Card border, card fill, status dot, file label, inner rules. */
    amberLine: string;
    amberFill: string;
    amberDot: string;
    amberText: string;
    amberRule: string;
    /** Diff lines in an approval card. */
    diffRemovedFill: string;
    diffRemovedText: string;
    diffAddedFill: string;
    diffAddedText: string;
}

export const light: LensTokens = {
    surface: '#FFFFFF',
    line: '#ECEDF0',
    text: '#1A1C21',
    muted: '#5F6672',
    mutedSoft: '#6B7280',
    bubble: '#F4F5F7',
    chip: '#F0F1F3',
    accent: '#1A1C21',
    onAccent: '#FFFFFF',
    buttonLine: '#DDE0E5',
    composerLine: '#E3E5E9',
    amberLine: '#F0DDB6',
    amberFill: '#FFFBF3',
    amberDot: '#C98512',
    amberText: '#6B5A35',
    amberRule: '#F3E7CC',
    diffRemovedFill: '#FDEEEF',
    diffRemovedText: '#9F2430',
    diffAddedFill: '#EAF6EF',
    diffAddedText: '#1C6B3E',
};

export const dark: LensTokens = {
    surface: '#17191E',
    line: '#262A31',
    text: '#E7E9ED',
    muted: '#9AA1AD',
    mutedSoft: '#9AA1AD',
    bubble: '#22252C',
    chip: '#262A31',
    accent: '#E7E9ED',
    onAccent: '#17191E',
    buttonLine: '#3A3F4B',
    composerLine: '#30343C',
    amberLine: '#4A3D22',
    amberFill: '#1E1A12',
    amberDot: '#E0A33E',
    amberText: '#C9B68E',
    amberRule: '#3A3122',
    diffRemovedFill: '#3A1F24',
    diffRemovedText: '#F4B9BF',
    diffAddedFill: '#173023',
    diffAddedText: '#A6E0BE',
};

/** Layout and type tokens: the same in both themes. */
export const shape = {
    dockedWidth: '400px',
    sheetWidth: '400px',
    sheetHeight: '600px',
    sheetRadius: '12px',
    sheetShadow: '0 1px 2px rgba(20,24,33,.05), 0 12px 32px rgba(20,24,33,.08)',
    fontUi: 'Inter, system-ui, -apple-system, "Segoe UI", sans-serif',
    fontMono: '"JetBrains Mono", ui-monospace, SFMono-Regular, Menlo, monospace',
} as const;

/** `mutedSoft` → `--lens-muted-soft`. */
export const cssVar = (name: keyof LensTokens | keyof typeof shape): string =>
    `--lens-${name.replace(/[A-Z]/g, (c) => `-${c.toLowerCase()}`)}`;

const declarations = (tokens: LensTokens | typeof shape): string =>
    Object.entries(tokens)
        .map(([name, value]) => `${cssVar(name as keyof LensTokens)}: ${value};`)
        .join(' ');

/**
 * The tokens as a stylesheet for the Lens shadow root: light by default, dark when the system asks
 * for it and the developer hasn't chosen light, and dark whenever the developer chose dark.
 */
export function tokensCss(): string {
    return [
        `:host { ${declarations(light)} ${declarations(shape)} color-scheme: light; }`,
        `@media (prefers-color-scheme: dark) { :host(:not([data-theme="light"])) { ${declarations(dark)} color-scheme: dark; } }`,
        `:host([data-theme="dark"]) { ${declarations(dark)} color-scheme: dark; }`,
    ].join('\n');
}
