import { useDispatch, useSelect } from '@wordpress/data';
import type { Binding, FieldDescriptor } from './types';
import { conditionValues } from './required';

/** The value a binding points at, from the post being edited. */
export function useBinding(binding: Binding | undefined): [unknown, (value: unknown) => void] {
    const { value, meta } = useSelect(
        (select) => {
            const editor = select('core/editor');
            const currentMeta = (editor.getEditedPostAttribute('meta') ?? {}) as Record<string, unknown>;
            if (!binding) return { value: undefined, meta: currentMeta };

            return {
                value: 'meta' in binding ? currentMeta[binding.meta] : editor.getEditedPostAttribute(binding.field),
                meta: currentMeta,
            };
        },
        [binding && ('meta' in binding ? binding.meta : binding.field)],
    );
    const { editPost } = useDispatch('core/editor');

    const setValue = (next: unknown) => {
        if (!binding) return;
        // Changes save with the post (Save, autosave, unsaved-changes warning).
        if ('meta' in binding) {
            editPost({ meta: { ...meta, [binding.meta]: next } });
        } else {
            editPost({ [binding.field]: next });
        }
    };

    return [value, setValue];
}

/** Condition values for a fieldset's fields (see ConditionValues). */
export function useFieldValues(fields: FieldDescriptor[]): Record<string, unknown> {
    return useSelect(
        (select) => {
            const editor = select('core/editor');
            const meta = (editor.getEditedPostAttribute('meta') ?? {}) as Record<string, unknown>;
            return conditionValues(fields, (binding) =>
                'meta' in binding ? meta[binding.meta] : editor.getEditedPostAttribute(binding.field),
            );
        },
        [fields],
    );
}
