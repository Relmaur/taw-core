/**
 * Media Folders — Grid-view sidebar (Alpine.js).
 *
 * Bolts a FileBird-style folder sidebar onto upload.php's default Grid view
 * (a Backbone.js app, wp.media, fetching via the query-attachments AJAX
 * action — a different code path from the classic List view's WP_Query).
 * Two things make that possible without overriding any wp.media Backbone
 * *view*:
 *   1. Reading/setting props on wp.media's existing query collection
 *      (applyFolderToBackbone) — the same narrow technique folder plugins
 *      like FileBird use to trigger a live re-fetch.
 *   2. MediaFolders::filterAjaxQueryAttachmentsArgs() on the PHP side,
 *      reading the taw_media_folder prop those props merge into $query.
 *
 * Folder CRUD here talks to the same wp/v2/taw_media_folder REST routes
 * folders.js already uses, via its exposed window.tawRestFetch — the CRUD
 * *logic* is ported (not shared) since Alpine's reactive model doesn't
 * compose with folders.js's imperative fetch-then-render calls.
 */
(function () {
    'use strict';

    function restFetch(path, options) {
        return window.tawRestFetch(path, options);
    }

    // Tracks whether the drag in progress is one of ours (a folder row or a
    // grid attachment), as opposed to a real OS file drag — see
    // initDragOverlaySuppression() below for why this matters.
    var isInternalDrag = false;

    // The sidebar's markup ships inside an inert <template> (see
    // MediaFolders::renderGridSidebarContainer()) specifically so Alpine
    // never sees x-data="tawMediaSidebar" until *we* insert it into the
    // live DOM — by which point Alpine.data('tawMediaSidebar', ...) below
    // has already registered. Alpine's built-in MutationObserver picks up
    // the freshly-inserted node regardless of whether Alpine.start() has
    // already run, so there's no race to win here.
    function mountSidebar() {
        var grid = document.getElementById('wp-media-grid');
        var template = document.getElementById('taw-media-sidebar-template');

        if (!grid || !template || !grid.parentNode) {
            return;
        }

        var flex = document.createElement('div');
        flex.id = 'taw-media-grid-flex';
        grid.parentNode.insertBefore(flex, grid);
        flex.appendChild(template.content.cloneNode(true));
        flex.appendChild(grid);

        initAttachmentDragSource(grid);
        initDragOverlaySuppression(flex);
    }

    // wp.media's Grid attachments aren't draggable by default (unlike the
    // gallery-edit modal's jQuery-UI-sortable grid) — Backbone re-renders
    // .attachment nodes on every scroll/filter, so a MutationObserver (rather
    // than a one-off querySelectorAll) is needed to keep marking new ones.
    function markAttachmentsDraggable(grid) {
        var items = grid.querySelectorAll('.attachment:not([draggable])');
        for (var i = 0; i < items.length; i++) {
            items[i].setAttribute('draggable', 'true');
        }
    }

    function attachmentIdsForDrag(startId) {
        try {
            var selection = wp.media.frame.state().get('selection');
            if (selection && selection.length > 1 && selection.get(startId)) {
                return selection.map(function (model) { return model.id; });
            }
        } catch (e) {
            // wp.media not ready, or nothing selected — drag just the one item.
        }

        return [startId];
    }

    function initAttachmentDragSource(grid) {
        markAttachmentsDraggable(grid);

        new MutationObserver(function () {
            markAttachmentsDraggable(grid);
        }).observe(grid, { childList: true, subtree: true });

        grid.addEventListener('dragstart', function (event) {
            var item = event.target.closest('.attachment');
            var id = item && parseInt(item.getAttribute('data-id'), 10);

            if (!id) {
                return;
            }

            isInternalDrag = true;
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('application/x-taw-attachments', JSON.stringify(attachmentIdsForDrag(id)));

            // WP core's grid items are float:left with percentage widths —
            // the browser's automatic drag-ghost snapshot can visually bleed
            // into an adjacent floated sibling on that layout. Setting an
            // explicit drag image (just the thumbnail) sidesteps that
            // entirely instead of depending on the browser's own capture.
            var thumb = item.querySelector('.thumbnail img');
            if (thumb) {
                event.dataTransfer.setDragImage(thumb, thumb.offsetWidth / 2, thumb.offsetHeight / 2);
            }
        });
    }

    // WP core's own Grid-view upload dropzone shows a "drop files to upload"
    // overlay on *any* dragenter that reaches it — it doesn't check
    // dataTransfer.types — so dragging a folder row or a grid attachment
    // (neither of which carry Files) triggers it too unless we stop that
    // bubbling ourselves. Stopping it here, on the flex wrapper that's a
    // shared ancestor of both the grid and the sidebar, still lets each
    // event reach our own row-level handlers first (bubble order visits the
    // target before this ancestor) and leaves real OS file drags over the
    // grid completely alone, since we only stop propagation while
    // isInternalDrag is true.
    function initDragOverlaySuppression(flex) {
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(function (type) {
            flex.addEventListener(type, function (event) {
                if (isInternalDrag) {
                    event.stopPropagation();
                }
            });
        });

        flex.addEventListener('dragend', function () {
            isInternalDrag = false;
        });
    }

    function applyFolderToBackbone(value) {
        if (!(window.wp && wp.media && wp.media.frame && wp.media.frame.state)) {
            return;
        }

        var state = wp.media.frame.state();
        var library = state && state.get('library');

        if (!library || !library.props) {
            return;
        }

        library.props.set({ taw_media_folder: value === null ? '' : String(value) });
    }

    function findModeSwitchLink() {
        return document.querySelector('.view-switch .view-list a');
    }

    function addOrReplaceQueryArg(url, key, value) {
        var parsed = new URL(url, window.location.href);

        if (value === null || value === '') {
            parsed.searchParams.delete(key);
        } else {
            parsed.searchParams.set(key, value);
        }

        return parsed.toString();
    }

    // Deliberately NOT wrapped in a document.addEventListener('alpine:init', ...)
    // listener: that only registers a callback for Alpine's *next* init
    // dispatch, but Alpine's own bundle calls Alpine.start() (which
    // dispatches alpine:init) via queueMicrotask() right after its own
    // script finishes executing — a microtask checkpoint that runs before
    // this script (a WP dependent of 'alpinejs', so guaranteed to load and
    // execute after it) even begins. The listener would always register
    // too late to catch that first, only dispatch.
    //
    // Calling Alpine.data() directly here instead is safe: alpine.min.js
    // assigns window.Alpine synchronously, before it queues Alpine.start(),
    // so the global is guaranteed to exist by the time this script — a
    // hard dependency of 'alpinejs' — runs. Alpine.data() just populates a
    // registry; it doesn't require Alpine.start() to not have already run,
    // and the sidebar's actual DOM node (cloned in from its <template> by
    // mountSidebar() below) is picked up by Alpine's built-in
    // MutationObserver whenever it's inserted, before or after this point.
    Alpine.data('tawMediaSidebar', () => ({
        folders: [],
        flatFolders: [],
        selectedFolderId: null, // null | 'unfiled' | number — matches folders.js's convention
        sidebarCollapsed: false,
        menuOpen: false,
        showFolderIds: false,
        collapsedIds: [], // folder ids whose children are hidden in the tree

        get canRename() {
            return typeof this.selectedFolderId === 'number';
        },

        get canDelete() {
            return typeof this.selectedFolderId === 'number';
        },

        init() {
            try {
                this.sidebarCollapsed = window.localStorage.getItem('tawMediaSidebarCollapsed') === '1';
            } catch (e) {
                // localStorage unavailable (private browsing, etc.) — default stays false.
            }

            this.fetchFolders().then(() => this.syncFromUrl());
        },

        toggleSidebar() {
            this.sidebarCollapsed = !this.sidebarCollapsed;

            try {
                window.localStorage.setItem('tawMediaSidebarCollapsed', this.sidebarCollapsed ? '1' : '0');
            } catch (e) {
                // Not persisted this session, but the toggle itself still works.
            }
        },

        renameSelected() {
            if (!this.canRename) {
                return;
            }

            var node = this.folders.find((f) => f.id === this.selectedFolderId);
            this.renameFolder(this.selectedFolderId, node ? node.name : '');
        },

        deleteSelected() {
            if (!this.canDelete) {
                return;
            }

            this.deleteFolder(this.selectedFolderId);
        },

        fetchFolders() {
            return restFetch(tawMediaFolders.taxonomy + '?per_page=100&orderby=name&order=asc&context=edit')
                .then((terms) => {
                    this.folders = terms;
                    this.flattenTree();
                });
        },

        childrenOf(parentId) {
            return this.folders.filter((f) => (f.parent || 0) === parentId);
        },

        isCollapsed(id) {
            return this.collapsedIds.indexOf(id) !== -1;
        },

        toggleExpanded(id) {
            var idx = this.collapsedIds.indexOf(id);
            if (idx === -1) {
                this.collapsedIds.push(id);
            } else {
                this.collapsedIds.splice(idx, 1);
            }

            this.flattenTree();
        },

        expandAll() {
            this.collapsedIds = [];
            this.flattenTree();
        },

        flattenTree() {
            var result = [];
            var walk = (parentId, depth) => {
                this.childrenOf(parentId).forEach((node) => {
                    var hasChildren = this.childrenOf(node.id).length > 0;
                    result.push({ id: node.id, name: node.name, count: node.count, depth: depth, hasChildren: hasChildren });

                    if (hasChildren && !this.isCollapsed(node.id)) {
                        walk(node.id, depth + 1);
                    }
                });
            };

            walk(0, 0);
            this.flatFolders = result;
        },

        slugFor(id) {
            var node = this.folders.find((f) => f.id === id);
            return node ? node.slug : null;
        },

        idForSlug(slug) {
            var node = this.folders.find((f) => f.slug === slug);
            return node ? node.id : null;
        },

        createFolder(parentId) {
            var name = window.prompt('Folder name:');
            if (!name) {
                return;
            }

            restFetch(tawMediaFolders.taxonomy, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name: name, parent: parentId || 0 }),
            }).then(() => this.fetchFolders());
        },

        renameFolder(id, currentName) {
            var name = window.prompt('Rename folder:', currentName);
            if (!name || name === currentName) {
                return;
            }

            restFetch(tawMediaFolders.taxonomy + '/' + id, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name: name }),
            }).then(() => this.fetchFolders());
        },

        deleteFolder(id) {
            if (!window.confirm('Delete this folder? Files inside it will become Unfiled.')) {
                return;
            }

            restFetch(tawMediaFolders.taxonomy + '/' + id, { method: 'DELETE' }).then(() => {
                if (this.selectedFolderId === id) {
                    this.selectFolder(null);
                }
                this.fetchFolders();
            });
        },

        isDescendant(candidateId, ofId) {
            var node = this.folders.find((f) => f.id === candidateId);
            while (node && node.parent) {
                if (node.parent === ofId) {
                    return true;
                }
                node = this.folders.find((f) => f.id === node.parent);
            }
            return false;
        },

        reparentFolder(id, newParentId) {
            if (id === newParentId || this.isDescendant(newParentId, id)) {
                return;
            }

            restFetch(tawMediaFolders.taxonomy + '/' + id, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ parent: newParentId }),
            }).then(() => this.fetchFolders());
        },

        onFolderDragStart(event, id) {
            isInternalDrag = true;
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('application/x-taw-folder', String(id));
        },

        onFolderDragOver(event) {
            event.currentTarget.classList.add('is-dragover');
        },

        onFolderDragLeave(event) {
            event.currentTarget.classList.remove('is-dragover');
        },

        onFolderDrop(event, targetId) {
            event.currentTarget.classList.remove('is-dragover');

            var draggedAttachments = event.dataTransfer.getData('application/x-taw-attachments');
            if (draggedAttachments) {
                this.moveAttachmentsToFolder(JSON.parse(draggedAttachments), targetId);
                return;
            }

            var draggedFolderId = event.dataTransfer.getData('application/x-taw-folder');
            if (draggedFolderId && targetId !== 'unfiled') {
                this.reparentFolder(parseInt(draggedFolderId, 10), targetId);
            }
        },

        moveAttachmentsToFolder(ids, targetId) {
            var term = targetId === 'unfiled' ? [] : [targetId];

            Promise.all(ids.map((id) => restFetch('media/' + id, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ [tawMediaFolders.taxonomy]: term }),
            }))).then(() => {
                this.fetchFolders();
                applyFolderToBackbone(this.selectedFolderId);
            });
        },

        selectFolder(value) {
            this.selectedFolderId = value;
            applyFolderToBackbone(value);
            this.syncModeSwitchLink(value);
        },

        syncModeSwitchLink(value) {
            var urlValue = value === null ? null : (value === 'unfiled' ? 'unfiled' : this.slugFor(value));
            var link = findModeSwitchLink();

            if (link) {
                link.href = addOrReplaceQueryArg(link.href, tawMediaFolders.taxonomy, urlValue);
            }

            window.history.replaceState(null, '', addOrReplaceQueryArg(window.location.href, tawMediaFolders.taxonomy, urlValue));
        },

        syncFromUrl() {
            var raw = new URLSearchParams(window.location.search).get(tawMediaFolders.taxonomy);
            var resolved = null;

            if (raw === 'unfiled') {
                resolved = 'unfiled';
            } else if (raw) {
                resolved = this.idForSlug(raw);
            }

            this.selectedFolderId = resolved;
            applyFolderToBackbone(resolved);
        },
    }));

    jQuery(function () {
        mountSidebar();
    });
})();
