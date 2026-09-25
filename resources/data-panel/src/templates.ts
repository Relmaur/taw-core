import type { FieldsetDescriptor } from './types';

/** 'page-about.php' and 'page-about' name the same template. */
export function templateSlug(template: string): string {
    return template.replace(/\.php$/, '');
}

/**
 * Whether a fieldset shows for the template chosen in the editor. The
 * server already decided for the saved template; only a change re-checks.
 */
export function isVisible(fieldset: FieldsetDescriptor, editedTemplate: string, savedTemplate: string): boolean {
    if (fieldset.templates.length === 0 || editedTemplate === savedTemplate) {
        return fieldset.active;
    }
    if (fieldset.always) {
        return true;
    }
    const slug = templateSlug(editedTemplate);

    return slug !== '' && fieldset.templates.some((template) => templateSlug(template) === slug);
}
