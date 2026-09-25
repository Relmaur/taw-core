import type { Binding, FieldDescriptor, FieldsetDescriptor } from './types';
import { conditionsMet } from './conditions';

export interface Missing {
    key: string;
    label: string;
}

/** The binding's key: a meta key or a `taw_<id>` field. */
export function bindingKey(binding: Binding | undefined): string {
    if (!binding) return '';
    return 'meta' in binding ? binding.meta : binding.field;
}

/** Empty the way TAW\Core\DataPanel\Validation::isEmpty() sees it (a link without a URL is empty). */
export function isEmptyValue(value: unknown): boolean {
    if (typeof value === 'object' && value !== null && !Array.isArray(value) && 'url' in value) {
        return String((value as { url: unknown }).url ?? '').trim() === '';
    }
    return (
        value === '' ||
        value === null ||
        value === undefined ||
        value === false ||
        (Array.isArray(value) && value.length === 0)
    );
}

/** Condition values for a list of fields: top-level ids, and "{group}_{sub}" for group sub-fields. */
export function conditionValues(
    fields: FieldDescriptor[],
    read: (binding: Binding) => unknown,
): Record<string, unknown> {
    const values: Record<string, unknown> = {};
    for (const field of fields) {
        if (field.binding) values[field.id] = read(field.binding);
        if (field.type === 'group') {
            for (const sub of field.fields ?? []) {
                if (sub.binding) values[`${field.id}_${sub.id}`] = read(sub.binding);
            }
        }
    }
    return values;
}

/**
 * Required fields that are empty, as the server would refuse them: shown
 * top-level fields and the sub-fields of shown groups, never read-only ones.
 * Repeater rows aren't checked (the server doesn't check them either).
 */
export function missingRequired(fieldsets: FieldsetDescriptor[], read: (binding: Binding) => unknown): Missing[] {
    const missing: Missing[] = [];
    for (const fieldset of fieldsets) {
        const values = conditionValues(fieldset.fields, read);
        for (const field of fieldset.fields) {
            if (!conditionsMet(field.conditions, values) || field.readonly) continue;
            const targets = field.type === 'group' ? (field.fields ?? []) : [field];
            for (const target of targets) {
                if (!target.required || target.readonly || !target.binding) continue;
                if (isEmptyValue(read(target.binding))) {
                    missing.push({ key: bindingKey(target.binding), label: target.label ?? target.id });
                }
            }
        }
    }
    return missing;
}
