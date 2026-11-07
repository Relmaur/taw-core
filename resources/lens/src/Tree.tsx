/**
 * The structure view (component 01, screen 04): TAW's view of the page. Hover a row (or move to it with the
 * keyboard) to ring its element on the page; click or Enter to select; shift-click or Shift+Enter to add it to
 * the selection. Arrow keys move, Right opens, Left closes or goes to the parent, Home and End jump.
 */
import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'preact/hooks';
import type { Target } from './describe';
import { __ } from './i18n';
import { BlockIcon, ChevronDownIcon, ChevronRightIcon, FieldIcon, PageIcon, PartIcon, TextIcon } from './icons';
import { ancestorsOf, visibleRows, type TreeKind, type TreeNode } from './treeModel';

interface Props {
    root: TreeNode;
    selected: ReadonlySet<string>;
    /** A row to reveal and focus (from "Show in tree" or a selection made on the page). */
    reveal: string | null;
    onHover(target: Target | null): void;
    onSelect(target: Target, add: boolean): void;
    onBack(): void;
}

const KindIcon = ({ kind }: { kind: TreeKind }) =>
    kind === 'page' ? (
        <PageIcon />
    ) : kind === 'part' ? (
        <PartIcon />
    ) : kind === 'block' ? (
        <BlockIcon />
    ) : kind === 'template' ? (
        <TextIcon />
    ) : (
        <FieldIcon />
    );

export function Tree({ root, selected, reveal, onHover, onSelect, onBack }: Props) {
    const [filter, setFilter] = useState('');
    const [expanded, setExpanded] = useState<Set<string>>(() => new Set([root.id]));
    const [focused, setFocusedState] = useState<string>(root.id);
    // Keys can arrive faster than renders (a held arrow key): the handler reads the latest row from here.
    const focusedRef = useRef(focused);
    const setFocused = (id: string) => {
        focusedRef.current = id;
        setFocusedState(id);
    };
    const [hovered, setHovered] = useState<string | null>(null);
    const rowRefs = useRef(new Map<string, HTMLDivElement>());
    const moved = useRef(false);

    // Selected rows and a revealed one are visible: their ancestors open.
    useEffect(() => {
        const open = [...selected, ...(reveal ? [reveal] : [])].flatMap((id) => ancestorsOf(root, id));
        if (reveal) {
            open.push(reveal);
        }
        if (open.length > 0) {
            setExpanded((current) => new Set([...current, ...open]));
        }
        if (reveal) {
            setFocused(reveal);
            moved.current = true;
        }
    }, [root, reveal, selected]);

    const rows = useMemo(() => visibleRows(root, expanded, filter), [root, expanded, filter]);
    const index = Math.max(
        0,
        rows.findIndex((row) => row.node.id === focused),
    );
    const current = rows[index];

    useLayoutEffect(() => {
        if (moved.current && current) {
            rowRefs.current.get(current.node.id)?.focus();
            rowRefs.current.get(current.node.id)?.scrollIntoView?.({ block: 'nearest' });
            moved.current = false;
        }
    }, [current]);

    const toggle = (id: string, open?: boolean) =>
        setExpanded((now) => {
            const next = new Set(now);
            if (open ?? !next.has(id)) {
                next.add(id);
            } else {
                next.delete(id);
            }
            return next;
        });

    const move = (to: number) => {
        const row = rows[Math.max(0, Math.min(rows.length - 1, to))];
        if (row) {
            moved.current = true;
            setFocused(row.node.id);
            onHover(row.node.target);
        }
    };

    const onKeyDown = (event: KeyboardEvent) => {
        const at = rows.findIndex((row) => row.node.id === focusedRef.current);
        const index = Math.max(0, at);
        const current = rows[index];
        if (!current) {
            return;
        }
        const { node, expanded: open, parent } = current;
        switch (event.key) {
            case 'ArrowDown':
                move(index + 1);
                break;
            case 'ArrowUp':
                move(index - 1);
                break;
            case 'Home':
                move(0);
                break;
            case 'End':
                move(rows.length - 1);
                break;
            case 'ArrowRight':
                if (node.children.length > 0 && !open) {
                    toggle(node.id, true);
                } else if (open) {
                    move(index + 1);
                }
                break;
            case 'ArrowLeft':
                if (open) {
                    toggle(node.id, false);
                } else if (parent) {
                    move(rows.findIndex((row) => row.node.id === parent));
                }
                break;
            case 'Enter':
            case ' ':
                onSelect(node.target, event.shiftKey);
                break;
            default:
                return;
        }
        event.preventDefault();
    };

    return (
        <div class="structure">
            <div class="structure-head">
                <span class="structure-title" id="lens-structure-title">
                    {__('Structure', 'taw-core')}
                </span>
                <button type="button" class="text-button" onClick={onBack}>
                    {__('Back to chat', 'taw-core')}
                </button>
            </div>
            <label class="sr-only" for="lens-tree-filter">
                {__('Filter', 'taw-core')}
            </label>
            <input
                id="lens-tree-filter"
                class="filter"
                type="search"
                autocomplete="off"
                spellcheck={false}
                placeholder={__('Filter blocks, fields, files…', 'taw-core')}
                value={filter}
                onInput={(event) => setFilter((event.currentTarget as HTMLInputElement).value)}
            />
            <div
                class="tree"
                role="tree"
                aria-labelledby="lens-structure-title"
                onKeyDown={onKeyDown}
                onMouseLeave={() => {
                    setHovered(null);
                    onHover(null);
                }}
            >
                {rows.map(({ node, depth, expanded: open }) => {
                    const isSelected = selected.has(node.id);
                    const onPage = node.target.elements.length > 0;
                    return (
                        <div
                            key={node.id}
                            ref={(el) => {
                                if (el) {
                                    rowRefs.current.set(node.id, el);
                                } else {
                                    rowRefs.current.delete(node.id);
                                }
                            }}
                            class="tree-row"
                            role="treeitem"
                            aria-level={depth + 1}
                            aria-selected={isSelected}
                            aria-expanded={node.children.length > 0 ? open : undefined}
                            tabIndex={node.id === current?.node.id ? 0 : -1}
                            data-selected={isSelected ? '' : undefined}
                            style={{ paddingLeft: `${8 + depth * 16}px` }}
                            onMouseEnter={() => {
                                setHovered(node.id);
                                onHover(node.target);
                            }}
                            onFocus={() => setFocused(node.id)}
                            onClick={(event) => {
                                setFocused(node.id);
                                onSelect(node.target, event.shiftKey);
                            }}
                        >
                            {node.children.length > 0 ? (
                                <span
                                    class="tree-twisty"
                                    onClick={(event) => {
                                        event.stopPropagation();
                                        toggle(node.id);
                                    }}
                                >
                                    {open ? <ChevronDownIcon /> : <ChevronRightIcon />}
                                </span>
                            ) : (
                                <span class="tree-twisty" />
                            )}
                            <span class="tree-icon">
                                <KindIcon kind={node.kind} />
                            </span>
                            <span class="tree-name">{node.name}</span>
                            <span class="tree-detail mono">{node.detail}</span>
                            {hovered === node.id && onPage && (
                                <span class="tree-hint">{__('on page', 'taw-core')}</span>
                            )}
                        </div>
                    );
                })}
                {rows.length === 0 && <div class="tree-none">{__('Nothing matches.', 'taw-core')}</div>}
            </div>
            <div class="structure-help">
                {__(
                    'Hover a row to ring it on the page. Click to select; shift-click to add it to the chat.',
                    'taw-core',
                )}
            </div>
        </div>
    );
}
