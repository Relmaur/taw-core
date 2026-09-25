import { createContext } from 'react';

/**
 * Values conditions read, by field id: a fieldset's top-level fields and
 * its group sub-fields as "{group}_{sub}" (their meta key without the
 * prefix, as the metabox names them). Repeater rows add their own row.
 */
export const ConditionValues = createContext<Record<string, unknown>>({});

/** The last failed save's messages, by binding key (meta key or `taw_<id>`). */
export const SaveErrors = createContext<Record<string, string>>({});
