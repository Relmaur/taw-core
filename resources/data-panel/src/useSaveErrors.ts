import { useSelect } from '@wordpress/data';

interface SaveError {
    code?: string;
    data?: { fields?: Array<{ field?: string; message?: string }> };
}

/**
 * The fields named by the last failed save (`taw_data_invalid`, from
 * TAW\Core\DataPanel\DataPanel::validateRest), by binding key. Empty once
 * a save succeeds or starts again.
 */
export function useSaveErrors(): Record<string, string> {
    const error = useSelect((select) => {
        const editor = select('core/editor');
        return select('core')?.getLastEntitySaveError?.(
            'postType',
            editor.getCurrentPostType(),
            editor.getCurrentPostId(),
        ) as SaveError | undefined;
    }, []);

    const errors: Record<string, string> = {};
    if (error?.code === 'taw_data_invalid') {
        for (const item of error.data?.fields ?? []) {
            if (item.field) errors[item.field] = item.message ?? '';
        }
    }
    return errors;
}
