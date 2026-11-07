/**
 * Translations through WordPress's wp.i18n (PHP loads `wp-i18n` and the taw-core locale data before
 * Lens). Call sites pass a literal string and the 'taw-core' domain, so tools/i18n.php extracts them.
 */
interface WpI18n {
    __: (text: string, domain: string) => string;
}

const wpI18n = (): WpI18n | undefined => (window as unknown as { wp?: { i18n?: WpI18n } }).wp?.i18n;

export const __ = (text: string, domain: 'taw-core'): string => wpI18n()?.__(text, domain) ?? text;
