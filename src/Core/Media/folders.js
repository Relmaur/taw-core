/**
 * Media Folders — admin UI.
 *
 * Two independent pieces gated by which DOM anchors are present, since this
 * one file is enqueued both on the dedicated "Media -> Folders" screen and
 * on the classic Media Library list screen (see MediaFolders::enqueueAssets()):
 *
 *   1. #taw-folders-app  — the Folders screen: a folder tree (create/
 *      rename/delete/drag-to-reparent) and a drag-and-drop attachment grid.
 *      Talks directly to WordPress's own REST routes (wp/v2/taw_media_folder,
 *      wp/v2/media) — no custom REST endpoint exists for this feature.
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

    // ── Piece 1: the Folders screen ───────────────────────────────────────

    function initFoldersApp() {
        var app = document.getElementById('taw-folders-app');
        if (!app) {
            return;
        }

        var treeEl = document.getElementById('taw-folders-tree');
        var gridEl = document.getElementById('taw-folders-grid');
        var gridTitleEl = document.getElementById('taw-folders-grid-title');
        var loadMoreBtn = document.getElementById('taw-folders-load-more');
        var newRootBtn = document.getElementById('taw-folders-new-root');

        var folders = []; // flat list from the REST API
        var selectedFolderId = null; // number, or 'unfiled'
        var gridPage = 1;

        // ---- Folder tree ----

        function fetchFolders() {
            return restFetch(tawMediaFolders.taxonomy + '?per_page=100&orderby=name&order=asc&context=edit')
                .then(function (terms) {
                    folders = terms;
                    renderTree();
                });
        }

        function childrenOf(parentId) {
            return folders.filter(function (f) { return (f.parent || 0) === parentId; });
        }

        function renderTreeNodes(parentId) {
            return childrenOf(parentId).map(function (node) {
                var kids = childrenOf(node.id);
                var childrenHtml = kids.length ? '<ul>' + renderTreeNodes(node.id) + '</ul>' : '';
                var selected = selectedFolderId === node.id ? ' is-selected' : '';

                return (
                    '<li class="taw-folders-tree__node' + selected + '" data-id="' + node.id + '" draggable="true">' +
                        '<div class="taw-folders-tree__row">' +
                            '<span class="taw-folders-tree__name">' + escapeHtml(node.name) + '</span>' +
                            '<span class="taw-folders-tree__actions">' +
                                '<button type="button" class="taw-folders-tree__add" title="Add subfolder">+</button>' +
                                '<button type="button" class="taw-folders-tree__rename" title="Rename">&#9998;</button>' +
                                '<button type="button" class="taw-folders-tree__delete" title="Delete">&times;</button>' +
                            '</span>' +
                        '</div>' +
                        childrenHtml +
                    '</li>'
                );
            }).join('');
        }

        function renderTree() {
            var unfiledSelected = selectedFolderId === 'unfiled' ? ' is-selected' : '';

            treeEl.removeAttribute('aria-busy');
            treeEl.innerHTML =
                '<li class="taw-folders-tree__node taw-folders-tree__node--unfiled' + unfiledSelected + '" data-id="unfiled">' +
                    '<div class="taw-folders-tree__row">' +
                        '<span class="taw-folders-tree__name">Unfiled</span>' +
                    '</div>' +
                '</li>' +
                renderTreeNodes(0);
        }

        function createFolder(parentId) {
            var name = window.prompt('Folder name:');
            if (!name) {
                return;
            }

            restFetch(tawMediaFolders.taxonomy, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name: name, parent: parentId || 0 }),
            }).then(fetchFolders);
        }

        function renameFolder(id, currentName) {
            var name = window.prompt('Rename folder:', currentName);
            if (!name || name === currentName) {
                return;
            }

            restFetch(tawMediaFolders.taxonomy + '/' + id, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name: name }),
            }).then(fetchFolders);
        }

        function deleteFolder(id) {
            if (!window.confirm('Delete this folder? Files inside it will become Unfiled.')) {
                return;
            }

            restFetch(tawMediaFolders.taxonomy + '/' + id, { method: 'DELETE' }).then(function () {
                if (selectedFolderId === id) {
                    selectedFolderId = null;
                    gridEl.innerHTML = '';
                    gridTitleEl.textContent = 'Select a folder';
                }
                fetchFolders();
            });
        }

        function isDescendant(candidateId, ofId) {
            var node = folders.find(function (f) { return f.id === candidateId; });
            while (node && node.parent) {
                if (node.parent === ofId) {
                    return true;
                }
                node = folders.find(function (f) { return f.id === node.parent; });
            }
            return false;
        }

        function reparentFolder(id, newParentId) {
            if (id === newParentId || isDescendant(newParentId, id)) {
                return;
            }

            restFetch(tawMediaFolders.taxonomy + '/' + id, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ parent: newParentId }),
            }).then(fetchFolders);
        }

        treeEl.addEventListener('click', function (e) {
            var node = e.target.closest('.taw-folders-tree__node');
            if (!node) {
                return;
            }

            var id = node.getAttribute('data-id');

            if (e.target.closest('.taw-folders-tree__add')) {
                createFolder(parseInt(id, 10));
                return;
            }

            if (e.target.closest('.taw-folders-tree__rename')) {
                var nameEl = node.querySelector(':scope > .taw-folders-tree__row .taw-folders-tree__name');
                renameFolder(parseInt(id, 10), nameEl ? nameEl.textContent : '');
                return;
            }

            if (e.target.closest('.taw-folders-tree__delete')) {
                deleteFolder(parseInt(id, 10));
                return;
            }

            if (e.target.closest('.taw-folders-tree__row')) {
                selectedFolderId = id === 'unfiled' ? 'unfiled' : parseInt(id, 10);
                renderTree();
                loadGrid(selectedFolderId, 1, false);
            }
        });

        treeEl.addEventListener('dragstart', function (e) {
            var node = e.target.closest('.taw-folders-tree__node');
            if (node && node.getAttribute('data-id') !== 'unfiled') {
                e.dataTransfer.setData('application/x-taw-folder', node.getAttribute('data-id'));
            }
        });

        treeEl.addEventListener('dragover', function (e) {
            var node = e.target.closest('.taw-folders-tree__node');
            if (node) {
                e.preventDefault();
                node.classList.add('is-dragover');
            }
        });

        treeEl.addEventListener('dragleave', function (e) {
            var node = e.target.closest('.taw-folders-tree__node');
            if (node) {
                node.classList.remove('is-dragover');
            }
        });

        treeEl.addEventListener('drop', function (e) {
            var node = e.target.closest('.taw-folders-tree__node');
            if (!node) {
                return;
            }

            e.preventDefault();
            node.classList.remove('is-dragover');

            var targetId = node.getAttribute('data-id');

            var draggedAttachmentId = e.dataTransfer.getData('application/x-taw-attachment');
            if (draggedAttachmentId) {
                moveAttachmentToFolder(parseInt(draggedAttachmentId, 10), targetId);
                return;
            }

            var draggedFolderId = e.dataTransfer.getData('application/x-taw-folder');
            if (draggedFolderId && targetId !== 'unfiled') {
                reparentFolder(parseInt(draggedFolderId, 10), parseInt(targetId, 10));
            }
        });

        newRootBtn.addEventListener('click', function () {
            createFolder(0);
        });

        // ---- Attachment grid ----

        function thumbUrl(media) {
            if (media.media_type === 'image' && media.media_details && media.media_details.sizes && media.media_details.sizes.thumbnail) {
                return media.media_details.sizes.thumbnail.source_url;
            }
            return '';
        }

        function renderGridItems(items) {
            return items.map(function (media) {
                var thumb = thumbUrl(media);
                var visual = thumb
                    ? '<img src="' + thumb + '" alt="">'
                    : '<span class="taw-folders-grid__icon dashicons dashicons-media-default"></span>';

                return (
                    '<div class="taw-folders-grid__item" draggable="true" data-id="' + media.id + '" title="' + escapeHtml(media.title.rendered) + '">' +
                        visual +
                        '<span class="taw-folders-grid__name">' + escapeHtml(media.title.rendered) + '</span>' +
                    '</div>'
                );
            }).join('');
        }

        function loadGrid(folderId, page, append) {
            gridTitleEl.textContent = folderId === 'unfiled' ? 'Unfiled' : (folders.find(function (f) { return f.id === folderId; }) || {}).name || '';
            gridPage = page;

            var query = folderId === 'unfiled'
                ? 'taw_unfiled=1'
                : tawMediaFolders.taxonomy + '=' + folderId;

            restFetch('media?' + query + '&per_page=60&page=' + page).then(function (items) {
                if (!append) {
                    gridEl.innerHTML = '';
                }

                if (!items.length && !append) {
                    gridEl.innerHTML = '<p class="taw-folders-grid__empty">No files in this folder.</p>';
                    loadMoreBtn.style.display = 'none';
                    return;
                }

                gridEl.insertAdjacentHTML('beforeend', renderGridItems(items));
                loadMoreBtn.style.display = items.length === 60 ? '' : 'none';
            }).catch(function () {
                loadMoreBtn.style.display = 'none';
            });
        }

        function moveAttachmentToFolder(attachmentId, folderId) {
            var payload = folderId === 'unfiled'
                ? { [tawMediaFolders.taxonomy]: [] }
                : { [tawMediaFolders.taxonomy]: [parseInt(folderId, 10)] };

            restFetch('media/' + attachmentId, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            }).then(function () {
                if (selectedFolderId !== null) {
                    loadGrid(selectedFolderId, 1, false);
                }
            });
        }

        gridEl.addEventListener('dragstart', function (e) {
            var item = e.target.closest('.taw-folders-grid__item');
            if (item) {
                e.dataTransfer.setData('application/x-taw-attachment', item.getAttribute('data-id'));
            }
        });

        loadMoreBtn.addEventListener('click', function () {
            if (selectedFolderId !== null) {
                loadGrid(selectedFolderId, gridPage + 1, true);
            }
        });

        fetchFolders();
    }

    document.addEventListener('DOMContentLoaded', function () {
        initListViewBulkMoveToggle();
        initFoldersApp();
    });

    // Exposed so sidebar.js (the Grid-view sidebar) can reuse the same
    // nonce/URL/error handling instead of a second fetch wrapper.
    window.tawRestFetch = restFetch;
})();
