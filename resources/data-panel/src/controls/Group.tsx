import React, { useContext } from 'react';
import { ConditionValues } from '../context';
import { conditionsMet } from '../conditions';
import type { FieldDescriptor } from '../types';
import FieldControl from '../FieldControl';
import FieldGroup from './FieldGroup';

/**
 * group: its sub-fields in one card, each bound to its own meta key
 * ("{prefix}{group}_{sub}"). A read-only group makes every sub-field
 * read-only, as the metabox does.
 */
export function Group({ field }: { field: FieldDescriptor }) {
    const values = useContext(ConditionValues);
    const subs = (field.fields ?? [])
        .filter((sub) => conditionsMet(sub.conditions, values))
        .map((sub) => (field.readonly ? { ...sub, readonly: true } : sub));

    return (
        <FieldGroup field={field}>
            {subs.map((sub) => (
                <FieldControl key={sub.id} field={sub} />
            ))}
        </FieldGroup>
    );
}
