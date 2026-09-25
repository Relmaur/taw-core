import React, { useEffect, useState } from 'react';
import apiFetch from '@wordpress/api-fetch';
import { BaseControl, Button, SearchControl, Spinner } from '@wordpress/components';
import { decodeEntities } from '@wordpress/html-entities';
import { __, sprintf } from '@wordpress/i18n';
import type { ControlProps, FieldDescriptor } from '../types';
import { useDebouncedFetch } from '../useDebouncedFetch';
import { fieldLabel, fieldLabelText } from './label';

export interface PostResult {
    id: number;
    title: string;
    subtype: string;
}

/**
 * post_select values, as the `taw_<id>` REST field decodes them: one post id
 * (or null) for a single field, a list of ids for `multiple`.
 */
export function selectedIds(field: FieldDescriptor, value: unknown): number[] {
    const list = Array.isArray(value) ? value : value === null || value === undefined ? [] : [value];
    return list.map(Number).filter((id) => Number.isInteger(id) && id > 0);
}

/** The value to write back for a list of ids. */
export function toValue(field: FieldDescriptor, ids: number[]): number | number[] | null {
    if (!field.multiple) return ids[0] ?? null;
    const max = field.max && field.max > 0 ? field.max : 0;
    return max > 0 ? ids.slice(0, max) : ids;
}

/** The field's post types for wp/v2/search (it takes a comma-separated list). */
export function subtypes(field: FieldDescriptor): string {
    return (field.post_type ?? 'post')
        .split(',')
        .map((type) => type.trim())
        .filter(Boolean)
        .join(',');
}

interface SearchRow {
    id: number;
    title: string;
    subtype: string;
}

const toResult = (row: SearchRow): PostResult => ({
    id: row.id,
    title:
        decodeEntities(row.title) || sprintf(/* translators: %d: post ID. */ __('(no title) #%d', 'taw-core'), row.id),
    subtype: row.subtype,
});

/** Published posts matching a search (core's wp/v2/search, like the metabox's own search). */
export function searchPosts(field: FieldDescriptor, search: string, exclude: number[]): Promise<PostResult[]> {
    const params = new URLSearchParams({
        search,
        type: 'post',
        subtype: subtypes(field),
        per_page: '10',
        _fields: 'id,title,subtype',
    });
    if (exclude.length > 0) params.set('exclude', exclude.join(','));
    return apiFetch<SearchRow[]>({ path: `/wp/v2/search?${params}` }).then((rows) => rows.map(toResult));
}

/** Titles for the chosen posts, in the stored order. */
function usePosts(ids: number[]) {
    const key = ids.join(',');
    const [posts, setPosts] = useState<Record<number, PostResult | null>>({});

    useEffect(() => {
        const missing = ids.filter((id) => !(id in posts));
        if (missing.length === 0) return undefined;
        let live = true;
        const params = new URLSearchParams({
            include: missing.join(','),
            type: 'post',
            subtype: 'any',
            per_page: String(Math.min(missing.length, 100)),
            _fields: 'id,title,subtype',
        });
        apiFetch<SearchRow[]>({ path: `/wp/v2/search?${params}` })
            .then((rows) => {
                if (!live) return;
                const found: Record<number, PostResult | null> = {};
                for (const id of missing) found[id] = null; // not published, or deleted
                for (const row of rows) found[row.id] = toResult(row);
                setPosts((current) => ({ ...current, ...found }));
            })
            .catch(() => undefined);
        return () => {
            live = false;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps -- `posts` is a cache; re-run on the ids only.
    }, [key]);

    return posts;
}

function Results({
    field,
    query,
    exclude,
    onPick,
}: {
    field: FieldDescriptor;
    query: string;
    exclude: number[];
    onPick: (post: PostResult) => void;
}) {
    const excludeKey = exclude.join(',');
    const { data, loading, error } = useDebouncedFetch(`${subtypes(field)}|${query}|${excludeKey}`, () =>
        searchPosts(field, query, exclude),
    );
    const results = data ?? [];

    if (loading && results.length === 0) return <Spinner />;
    if (error) return <p className="taw-data-panel__post-note">{__('Search failed.', 'taw-core')}</p>;
    if (results.length === 0) {
        return <p className="taw-data-panel__post-note">{__('Nothing found.', 'taw-core')}</p>;
    }

    return (
        <ul className="taw-data-panel__post-results" role="listbox">
            {results.map((post) => (
                <li key={post.id}>
                    <Button className="taw-data-panel__post-result" role="option" onClick={() => onPick(post)}>
                        <span className="taw-data-panel__post-title">{post.title}</span>
                        <span className="taw-data-panel__post-type">{post.subtype}</span>
                    </Button>
                </li>
            ))}
        </ul>
    );
}

/** post_select: search published posts of the field's types; one, or several in order. */
export function PostSelect({ field, value, onChange }: ControlProps) {
    const ids = selectedIds(field, value);
    const posts = usePosts(ids);
    const [query, setQuery] = useState('');
    const [searching, setSearching] = useState(false);
    const max = field.multiple ? (field.max && field.max > 0 ? field.max : 0) : 1;
    const full = field.multiple ? max > 0 && ids.length >= max : false;
    const id = `taw-data-${field.id}`;

    const write = (next: number[]) => onChange(toValue(field, next));
    const pick = (post: PostResult) => {
        write(field.multiple ? [...ids, post.id] : [post.id]);
        setQuery('');
        setSearching(false);
    };
    const move = (from: number, to: number) => {
        const next = [...ids];
        next.splice(to, 0, ...next.splice(from, 1));
        write(next);
    };

    return (
        <BaseControl __nextHasNoMarginBottom id={id} label={fieldLabel(field)} help={field.description}>
            {ids.length > 0 ? (
                <ul className="taw-data-panel__posts">
                    {ids.map((postId, index) => {
                        const post = posts[postId];
                        const title =
                            post?.title ??
                            (post === null
                                ? sprintf(/* translators: %d: post ID. */ __('#%d (not published)', 'taw-core'), postId)
                                : '…');
                        return (
                            <li key={postId} className="taw-data-panel__post">
                                <span className="taw-data-panel__post-title">{title}</span>
                                {post ? <span className="taw-data-panel__post-type">{post.subtype}</span> : null}
                                {field.readonly ? null : (
                                    <span className="taw-data-panel__file-actions">
                                        {field.multiple ? (
                                            <>
                                                <Button
                                                    size="small"
                                                    icon="arrow-up-alt2"
                                                    label={__('Move up', 'taw-core')}
                                                    disabled={index === 0}
                                                    onClick={() => move(index, index - 1)}
                                                />
                                                <Button
                                                    size="small"
                                                    icon="arrow-down-alt2"
                                                    label={__('Move down', 'taw-core')}
                                                    disabled={index === ids.length - 1}
                                                    onClick={() => move(index, index + 1)}
                                                />
                                            </>
                                        ) : null}
                                        <Button
                                            size="small"
                                            icon="no-alt"
                                            className="taw-data-panel__remove"
                                            label={sprintf(
                                                /* translators: %s: post title. */ __('Remove %s', 'taw-core'),
                                                title,
                                            )}
                                            onClick={() => write(ids.filter((other) => other !== postId))}
                                        />
                                    </span>
                                )}
                            </li>
                        );
                    })}
                </ul>
            ) : null}

            {field.readonly || full || (!field.multiple && ids.length > 0 && !searching) ? (
                !field.readonly && !field.multiple && ids.length > 0 ? (
                    <Button variant="secondary" size="compact" onClick={() => setSearching(true)}>
                        {__('Change', 'taw-core')}
                    </Button>
                ) : null
            ) : (
                <div className="taw-data-panel__post-search">
                    <SearchControl
                        __nextHasNoMarginBottom
                        id={id}
                        label={fieldLabelText(field)}
                        hideLabelFromVision
                        placeholder={
                            field.multiple ? __('Search to add…', 'taw-core') : __('Search for a post…', 'taw-core')
                        }
                        value={query}
                        onChange={setQuery}
                        onFocus={() => setSearching(true)}
                    />
                    {searching ? <Results field={field} query={query} exclude={ids} onPick={pick} /> : null}
                </div>
            )}
        </BaseControl>
    );
}
