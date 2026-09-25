/**
 * The descriptor taw/core sends (TAW\Core\DataPanel\Descriptor, ADR-0007 § 6).
 * Keep in sync with DescriptorTest.
 */

/** Where a value lives on the REST post object. */
export type Binding = { meta: string } | { field: string };

export interface Condition {
    field: string;
    operator: string;
    value: unknown;
}

export interface FieldDescriptor {
    id: string;
    type: string;
    label?: string;
    description?: string;
    placeholder?: string;
    default?: unknown;
    required?: boolean;
    readonly?: boolean;
    options?: Record<string, string> | string[];
    min?: number;
    max?: number;
    step?: number;
    unit?: string;
    rows?: number;
    date_format?: string;
    min_date?: string;
    max_date?: string;
    multiple?: boolean;
    /** post_select: one post type, or several comma-separated. */
    post_type?: string;
    /** files: most attachments allowed (0 = no limit). */
    limit?: number;
    /** files: "image" to offer images only; empty for any file. */
    file_types?: string;
    button_label?: string;
    /** wysiwyg */
    teeny?: boolean;
    media_buttons?: boolean;
    /** wysiwyg: the blocks the editor offers (overrides the default set). */
    blocks?: string[];
    validated?: boolean;
    conditions?: Condition[];
    binding?: Binding;
    fields?: FieldDescriptor[];
    [key: string]: unknown;
}

export interface TabDescriptor {
    label: string;
    icon: string;
    fields: string[];
}

export interface FieldsetDescriptor {
    id: string;
    title: string;
    icon: string;
    templates: string[];
    tabs: TabDescriptor[];
    fields: FieldDescriptor[];
    /** Applies to the post as saved. */
    active: boolean;
    /** Applies whatever template the post uses. */
    always: boolean;
}

export interface PanelDescriptor {
    version: number;
    postType: string;
    fieldsets: FieldsetDescriptor[];
    warnings: string[];
    /** Whether the Lucide icon picker is on (TAW\\Core\\Icons\\Lucide::enable()). */
    icons?: boolean;
}

export interface ControlProps<T = unknown> {
    field: FieldDescriptor;
    value: T;
    onChange: (value: unknown) => void;
}
