/**
 * TAW Media — admin UI.
 *
 * Two independent pieces gated by which DOM anchors are present, since this
 * one file is enqueued both on the dedicated "Media -> TAW Media" screen and
 * on the classic Media Library list screen (see MediaFolders::enqueueAssets()):
 *
 *   1. #taw-folders-app  — the TAW Media screen: an Alpine.js app (folder
 *      tree, breadcrumb, folder cards, direct file upload, multi-select +
 *      bulk move/delete). Talks directly to WordPress's own REST routes
 *      (wp/v2/taw_media_folder, wp/v2/media) — no custom REST endpoint
 *      exists for this feature.
 *   2. #taw-folder-filter — the classic List view: shows/hides the "move to
 *      folder" target dropdown when the "Move to folder…" bulk action is chosen.
 */
(function () {
    'use strict';

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
        });
    }

    function restFetch(path, options) {
        options = options || {};
        options.headers = Object.assign({ 'X-WP-Nonce': tawMediaFolders.nonce }, options.headers || {});
        return fetch(tawMediaFolders.restUrl + path, options).then(function (response) {
            if (!response.ok) {
                throw new Error('Request failed: ' + response.status);
            }
            return response.status === 204 ? null : response.json();
        });
    }

    // ── Piece 2: classic List view bulk-move toggle ──────────────────────

    function initListViewBulkMoveToggle() {
        var targetSelect = document.getElementById('taw-bulk-move-target');
        if (!targetSelect) {
            return;
        }

        var actionSelects = document.querySelectorAll('select[name="action"], select[name="action2"]');

        function syncVisibility() {
            var anyMoveSelected = Array.prototype.some.call(actionSelects, function (select) {
                return select.value === 'taw_move_to_folder';
            });
            targetSelect.style.display = anyMoveSelected ? '' : 'none';
        }

        actionSelects.forEach(function (select) {
            select.addEventListener('change', syncVisibility);
        });

        syncVisibility();
    }

    document.addEventListener('DOMContentLoaded', function () {
        initListViewBulkMoveToggle();
    });

    // ── Piece 1: the TAW Media screen (Alpine.js) ────────────────────────
    //
    // A self-contained Alpine component — deliberately not sharing code
    // with sidebar.js's tawMediaSidebar (folder CRUD/tree logic is *ported*,
    // not shared, matching this project's existing precedent for the same
    // reason: two Alpine.data() registrations don't compose well through a
    // shared mixin for a two-consumer case, and the two screens' grid
    // concerns are genuinely different — this one owns its own REST-driven
    // attachment grid instead of bridging to wp.media's Backbone collection).

    Alpine.data('tawFoldersApp', () => ({
        folders: [],
        flatFolders: [],
        selectedFolderId: null, // null | 'unfiled' | number
        sidebarCollapsed: false,
        menuOpen: false,
        showFolderIds: false,
        collapsedIds: [],

        gridItems: [],
        gridPage: 1,
        hasMore: false,
        gridLoading: false,
        selectedAttachments: [],
        isDraggingFiles: false,
        uploading: false,
        uploadProgress: null, // { done, total } while uploading, else null

        get canRename() {
            return typeof this.selectedFolderId === 'number';
        },

        get canDelete() {
            return typeof this.selectedFolderId === 'number';
        },

        get hasSelection() {
            return this.selectedAttachments.length > 0;
        },

        // Root-first ancestor chain for the current folder (last entry is
        // the folder itself) — only meaningful for a real numeric folder,
        // not 'All Files'/'Unfiled'.
        get breadcrumb() {
            return typeof this.selectedFolderId === 'number' ? this.ancestorsOf(this.selectedFolderId) : [];
        },

        // Direct children of the current folder, shown as cards in the
        // grid pane for one-click navigation — same idea as sidebar.js's
        // Grid-view folder cards.
        get folderCards() {
            return typeof this.selectedFolderId === 'number' ? this.childrenOf(this.selectedFolderId) : [];
        },

        init() {
            this.fetchFolders().then(() => this.loadGrid(null, 1, false));
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

        selectFolder(value) {
            this.selectedFolderId = value;
            this.selectedAttachments = [];
            this.loadGrid(value, 1, false);
        },

        // ---- Attachment grid ----

        thumbUrl(media) {
            if (media.media_type === 'image' && media.media_details && media.media_details.sizes && media.media_details.sizes.thumbnail) {
                return media.media_details.sizes.thumbnail.source_url;
            }
            return '';
        },

        loadGrid(folderId, page, append) {
            this.gridLoading = true;
            this.gridPage = page;

            var query = folderId === 'unfiled'
                ? 'taw_unfiled=1'
                : (folderId === null ? '' : tawMediaFolders.taxonomy + '=' + folderId);

            restFetch('media?' + (query ? query + '&' : '') + 'per_page=60&page=' + page).then((items) => {
                var mapped = items.map((media) => ({
                    id: media.id,
                    title: media.title.rendered,
                    thumb: this.thumbUrl(media),
                }));

                this.gridItems = append ? this.gridItems.concat(mapped) : mapped;
                this.hasMore = items.length === 60;
                this.gridLoading = false;
            }).catch(() => {
                this.gridLoading = false;
                this.hasMore = false;
            });
        },

        loadMoreGrid() {
            this.loadGrid(this.selectedFolderId, this.gridPage + 1, true);
        },

        // ---- multi-select + bulk actions ----

        toggleAttachmentSelected(id) {
            var idx = this.selectedAttachments.indexOf(id);
            if (idx === -1) {
                this.selectedAttachments.push(id);
            } else {
                this.selectedAttachments.splice(idx, 1);
            }
        },

        isAttachmentSelected(id) {
            return this.selectedAttachments.indexOf(id) !== -1;
        },

        clearSelection() {
            this.selectedAttachments = [];
        },

        bulkDelete() {
            if (!this.selectedAttachments.length) {
                return;
            }

            if (!window.confirm('Delete ' + this.selectedAttachments.length + ' selected file(s)? This cannot be undone.')) {
                return;
            }

            Promise.all(this.selectedAttachments.map((id) => restFetch('media/' + id, {
                method: 'DELETE',
            }))).then(() => {
                this.selectedAttachments = [];
                this.fetchFolders();
                this.loadGrid(this.selectedFolderId, 1, false);
            });
        },

        moveAttachmentsToFolder(ids, targetId) {
            var term = targetId === 'unfiled' ? [] : [targetId];

            Promise.all(ids.map((id) => restFetch('media/' + id, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ [tawMediaFolders.taxonomy]: term }),
            }))).then(() => {
                this.selectedAttachments = [];
                this.fetchFolders();
                this.loadGrid(this.selectedFolderId, 1, false);
            });
        },

        onAttachmentDragStart(event, id) {
            var ids = this.isAttachmentSelected(id) && this.selectedAttachments.length > 1
                ? this.selectedAttachments
                : [id];

            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('application/x-taw-attachments', JSON.stringify(ids));
        },

        // ---- direct OS file upload ----

        onDropzoneDragOver(event) {
            // Only react to a real file drag (not our own attachment/folder
            // drags, which also fire dragover as they pass over this pane).
            if (event.dataTransfer.types.indexOf('Files') !== -1) {
                this.isDraggingFiles = true;
            }
        },

        onDropzoneDragLeave() {
            this.isDraggingFiles = false;
        },

        onDropzoneDrop(event) {
            this.isDraggingFiles = false;

            if (event.dataTransfer.files && event.dataTransfer.files.length) {
                this.uploadFiles(event.dataTransfer.files);
            }
        },

        onFileInputChange(event) {
            if (event.target.files && event.target.files.length) {
                this.uploadFiles(event.target.files);
                event.target.value = '';
            }
        },

        uploadFiles(fileList) {
            var files = Array.prototype.slice.call(fileList);
            var folderId = this.selectedFolderId;

            this.uploading = true;
            this.uploadProgress = { done: 0, total: files.length };

            var uploadOne = (file) => {
                var formData = new FormData();
                formData.append('file', file, file.name);

                if (typeof folderId === 'number') {
                    formData.append(tawMediaFolders.taxonomy + '[]', String(folderId));
                }

                return fetch(tawMediaFolders.restUrl + 'media', {
                    method: 'POST',
                    headers: { 'X-WP-Nonce': tawMediaFolders.nonce },
                    body: formData,
                }).then((response) => {
                    if (!response.ok) {
                        throw new Error('Upload failed: ' + response.status);
                    }
                    return response.json();
                }).then(() => {
                    this.uploadProgress.done++;
                });
            };

            // Sequential rather than Promise.all — keeps progress reporting
            // simple/accurate and avoids hitting the server with every file
            // in a batch at once.
            var finish = () => {
                this.uploading = false;
                this.uploadProgress = null;
                this.fetchFolders();
                this.loadGrid(this.selectedFolderId, 1, false);
            };

            files.reduce((promise, file) => promise.then(() => uploadOne(file)), Promise.resolve())
                .then(finish)
                .catch(() => {
                    window.alert('One or more files failed to upload.');
                    finish();
                });
        },
    }));

    // Exposed so sidebar.js (the Grid-view sidebar) can reuse the same
    // nonce/URL/error handling instead of a second fetch wrapper.
    window.tawRestFetch = restFetch;
})();
