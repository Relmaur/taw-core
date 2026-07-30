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

    // Grid-view folder cards + breadcrumb: which child folders (of the
    // currently selected folder) should show as cards, and the breadcrumb
    // trail leading to the current selection ([{id, name}, ...], root-first,
    // last entry is the current folder). Module-level (like isInternalDrag)
    // so both plain DOM code and Alpine methods share them.
    var currentGrid = null;
    var currentFolderChildren = [];
    var currentBreadcrumb = [];

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
        });
    }

    function selectFolderFromGrid(id) {
        var sidebarEl = document.getElementById('taw-media-sidebar');
        if (sidebarEl && window.Alpine) {
            window.Alpine.$data(sidebarEl).selectFolder(id);
        }
    }

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

        currentGrid = grid;

        initAttachmentDragSource(grid);
        initDragOverlaySuppression(flex);
        initAutoAssignUploadsToFolder();
    }

    // Uploading a file via WP core's own Grid-view dropzone while a real
    // folder is open should file it there immediately, rather than leaving
    // it Unfiled until manually moved. wp.Uploader.queue is a stable global
    // Backbone collection (not per-view) that every uploaded attachment
    // model passes through — WP core's own upload handler
    // (wp-plupload.js's FileUploaded binding) merges the server response
    // into the model and sets uploading:false once the upload completes,
    // which is exactly the 'change:uploading' event this listens for.
    function initAutoAssignUploadsToFolder() {
        if (!(window.wp && wp.Uploader && wp.Uploader.queue)) {
            return;
        }

        wp.Uploader.queue.on('change:uploading', function (attachment) {
            if (attachment.get('uploading')) {
                return;
            }

            var sidebarEl = document.getElementById('taw-media-sidebar');
            if (!sidebarEl || !window.Alpine) {
                return;
            }

            var data = window.Alpine.$data(sidebarEl);
            var folderId = data.selectedFolderId;

            if (typeof folderId !== 'number') {
                return;
            }

            restFetch('media/' + attachment.get('id'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ [tawMediaFolders.taxonomy]: [folderId] }),
            }).then(function () {
                data.fetchFolders();
                // Not just fetchFolders() — WP core's own dropzone already
                // added the new attachment to the visible collection before
                // it had a folder term, so the current (filtered) view may
                // have since dropped it again. refreshGridQuery() forces the
                // same unconditional re-fetch used after drag-and-drop
                // moves, same reasoning: Backbone's own change-detection
                // won't retrigger this on its own since nothing about the
                // *selected folder* changed, only the file's assignment.
                refreshGridQuery();
            });
        });
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

    // Ensures a <div class="taw-grid-overlay"> (breadcrumb + folder cards)
    // sits directly above ul.attachments, as a sibling inside its parent —
    // created once and left alone otherwise, since wp.media only ever
    // replaces ul.attachments's own children, never touches its parent's
    // other children.
    function ensureGridOverlay(grid) {
        var list = grid.querySelector('ul.attachments');
        if (!list || !list.parentNode) {
            return null;
        }

        var overlay = list.parentNode.querySelector(':scope > .taw-grid-overlay');
        if (overlay) {
            return overlay;
        }

        overlay = document.createElement('div');
        overlay.className = 'taw-grid-overlay';
        overlay.innerHTML =
            '<div class="taw-media-breadcrumb"></div>' +
            '<div class="taw-folder-cards"></div>';

        list.parentNode.insertBefore(overlay, list);

        return overlay;
    }

    function renderBreadcrumb(overlay) {
        var el = overlay.querySelector('.taw-media-breadcrumb');
        var signature = currentBreadcrumb.map(function (crumb) { return crumb.id; }).join(',');

        // Bail out once already correct — rebuilding on every mutation would
        // itself mutate the DOM and re-trigger the observer watching it.
        if (el.dataset.signature === signature) {
            return;
        }
        el.dataset.signature = signature;

        if (!currentBreadcrumb.length) {
            el.innerHTML = '';
            el.style.display = 'none';
            return;
        }

        el.style.display = '';

        var parts = [
            '<a href="#" class="taw-media-breadcrumb__link" data-folder-id="">' +
                tawMediaFolders.homeIcon +
                '<span>All Files</span>' +
            '</a>',
        ];

        currentBreadcrumb.forEach(function (crumb, index) {
            parts.push('<span class="taw-media-breadcrumb__sep">/</span>');

            if (index === currentBreadcrumb.length - 1) {
                parts.push('<span class="taw-media-breadcrumb__current">' + escapeHtml(crumb.name) + '</span>');
            } else {
                parts.push(
                    '<a href="#" class="taw-media-breadcrumb__link" data-folder-id="' + crumb.id + '">' +
                        escapeHtml(crumb.name) +
                    '</a>'
                );
            }
        });

        el.innerHTML = parts.join('');

        el.querySelectorAll('a[data-folder-id]').forEach(function (link) {
            link.addEventListener('click', function (event) {
                event.preventDefault();
                var raw = event.currentTarget.getAttribute('data-folder-id');
                selectFolderFromGrid(raw === '' ? null : parseInt(raw, 10));
            });
        });
    }

    function renderFolderCards(overlay) {
        var el = overlay.querySelector('.taw-folder-cards');

        // Signature includes each folder's count, not just its id — moving
        // a file into/out of a subfolder that's already showing as a card
        // doesn't change *which* folders are shown, only their counts, and
        // an id-only comparison here would treat that as "nothing to
        // update" and leave the stale count on screen.
        var existing = Array.prototype.map.call(el.children, function (card) {
            return card.getAttribute('data-folder-id') + ':' + card.getAttribute('data-folder-count');
        });
        var desired = currentFolderChildren.map(function (folder) {
            return folder.id + ':' + (folder.count || 0);
        });

        var inSync = existing.length === desired.length
            && existing.every(function (entry, i) { return entry === desired[i]; });

        if (inSync) {
            return;
        }

        el.innerHTML = '';
        el.style.display = currentFolderChildren.length ? '' : 'none';

        currentFolderChildren.forEach(function (folder) {
            var card = document.createElement('div');
            card.className = 'taw-folder-card';
            card.tabIndex = 0;
            card.setAttribute('role', 'button');
            card.setAttribute('data-folder-id', String(folder.id));
            card.setAttribute('data-folder-count', String(folder.count || 0));
            card.innerHTML =
                '<span class="taw-folder-card__icon">' + tawMediaFolders.folderIcon + '</span>' +
                '<span class="taw-folder-card__name">' + escapeHtml(folder.name) + '</span>' +
                '<span class="taw-folder-card__count">' + (folder.count || 0) + '</span>';

            card.addEventListener('click', function () {
                selectFolderFromGrid(folder.id);
            });
            card.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    selectFolderFromGrid(folder.id);
                }
            });

            // Lets a real grid attachment be dropped straight onto a
            // subfolder card, same REST move as dropping on the sidebar —
            // isInternalDrag (set on the attachment's dragstart) already
            // keeps WP's own upload-dropzone overlay suppressed for this.
            card.addEventListener('dragover', function (event) {
                event.preventDefault();
                card.classList.add('is-dragover');
            });
            card.addEventListener('dragleave', function () {
                card.classList.remove('is-dragover');
            });
            card.addEventListener('drop', function (event) {
                event.preventDefault();
                card.classList.remove('is-dragover');

                var attachmentsJson = event.dataTransfer.getData('application/x-taw-attachments');
                if (!attachmentsJson) {
                    return;
                }

                var sidebarEl = document.getElementById('taw-media-sidebar');
                if (sidebarEl && window.Alpine) {
                    window.Alpine.$data(sidebarEl).moveAttachmentsToFolder(JSON.parse(attachmentsJson), folder.id);
                }
            });

            el.appendChild(card);
        });
    }

    // Re-run from the same MutationObserver that keeps attachments marked
    // draggable: wp.media fully re-renders ul.attachments's children on
    // every reset (e.g. every folder switch), so the overlay's content has
    // to be kept in sync the same way rather than injected once.
    function syncGridOverlays() {
        if (!currentGrid) {
            return;
        }

        var overlay = ensureGridOverlay(currentGrid);
        if (!overlay) {
            return;
        }

        renderBreadcrumb(overlay);
        renderFolderCards(overlay);
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
            syncGridOverlays();
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

    // Forces the Grid's Backbone query to re-fetch the *same* filter it
    // already has. props.set() (above) only fires a 'change' event — the
    // thing wp.media's Attachments collection actually listens for to
    // re-query — when the new value differs from the current one. Moving a
    // file while still viewing its (unchanged) source or destination folder
    // sets the identical value again, so no 'change' event fires and the
    // grid silently keeps showing stale results until a hard reload.
    // _requery() (wp.media's own collection method, called internally
    // whenever that 'change' event does fire) sidesteps the diffing
    // entirely, so it works regardless of whether the value nominally
    // changed.
    function refreshGridQuery() {
        if (!(window.wp && wp.media && wp.media.frame && wp.media.frame.state)) {
            return;
        }

        var state = wp.media.frame.state();
        var library = state && state.get('library');

        if (library && typeof library._requery === 'function') {
            library._requery();
        }
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
                    this.updateFolderTiles();
                });
        },

        // Keeps the Grid-view's injected folder cards + breadcrumb
        // (syncGridOverlays(), module-level above) matching the currently
        // selected folder's direct children and ancestor chain — called
        // after anything that could change either (selecting a folder, or a
        // CRUD op while one is selected).
        updateFolderTiles() {
            currentFolderChildren = typeof this.selectedFolderId === 'number'
                ? this.childrenOf(this.selectedFolderId)
                : [];
            currentBreadcrumb = typeof this.selectedFolderId === 'number'
                ? this.ancestorsOf(this.selectedFolderId)
                : [];
            syncGridOverlays();
        },

        childrenOf(parentId) {
            return this.folders.filter((f) => (f.parent || 0) === parentId);
        },

        // Root-first ancestor chain for a folder, including the folder
        // itself as the last entry — e.g. Clientes > Lorem Ipsum > Lorem.
        ancestorsOf(folderId) {
            var chain = [];
            var node = this.folders.find((f) => f.id === folderId);

            while (node) {
                chain.unshift({ id: node.id, name: node.name });
                node = node.parent ? this.folders.find((f) => f.id === node.parent) : null;
            }

            return chain;
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
                // Not applyFolderToBackbone(this.selectedFolderId) — the
                // folder being viewed hasn't changed, so that call would be
                // a same-value no-op Backbone silently ignores. This move
                // can still change what belongs in the currently-viewed
                // folder (the file just left it, or just arrived in it), so
                // the grid needs an unconditional re-fetch regardless.
                refreshGridQuery();
            });
        },

        selectFolder(value) {
            this.selectedFolderId = value;
            applyFolderToBackbone(value);
            this.syncModeSwitchLink(value);
            this.updateFolderTiles();
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
            this.updateFolderTiles();
        },
    }));

    jQuery(function () {
        mountSidebar();
    });
})();
