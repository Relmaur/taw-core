/**
 * TAW Visual Editor — Alpine.js Frontend Component
 *
 * Provides inline editing and a right-side panel for
 * content editing on the frontend.
 *
 * Field discovery is two-layered:
 *   1. DOM scan — finds [data-taw-field] elements for inline click-to-edit.
 *   2. API fetch — loads ALL registered metabox fields with current values,
 *      so the panel is fully populated even without template annotations.
 */
document.addEventListener('alpine:init', () => {

    Alpine.data('tawVisualEditor', () => ({

        // ── State ──────────────────────────────────────────────

        activeEl:          null,
        panelMode:         'idle',
        activeBlockId:     null,
        activeFieldId:     null,
        activeRepeaterId:  null,
        openRepeaterRows:  [],
        loading:           true,

        /** Tracks all pending changes: { fieldId → { blockId, fieldId, type, value, originalValue } } */
        changes: {},

        saving: false,

        toasts:   [],
        _toastId: 0,

        /**
         * Map of groupId → { title, fields[] }
         * Populated by both DOM scan and API fetch.
         */
        blockFields: {},

        // ── Computed ───────────────────────────────────────────

        get hasChanges() {
            return Object.keys(this.changes).length > 0;
        },

        get changeCount() {
            return Object.keys(this.changes).length;
        },

        get activeSectionFields() {
            if (!this.activeBlockId) return [];
            return this.blockFields[this.activeBlockId]?.fields || [];
        },

        get activeBlockTitle() {
            if (!this.activeBlockId) return null;
            return this.blockFields[this.activeBlockId]?.title || null;
        },

        get activeFieldInfo() {
            if (!this.activeFieldId || !this.activeBlockId) return null;
            const fields = this.blockFields[this.activeBlockId]?.fields || [];
            return fields.find(f => f.fieldId === this.activeFieldId) || null;
        },

        get availableBlocks() {
            return Object.entries(this.blockFields).map(([blockId, group]) => ({
                blockId,
                title:      group.title || blockId,
                fieldCount: group.fields.length,
            }));
        },

        get activeRepeaterField() {
            if (!this.activeRepeaterId) return null;
            return this.findFieldInfo(this.activeRepeaterId);
        },

        get activeRepeaterRows() {
            if (!this.activeRepeaterId) return [];
            if (this.changes[this.activeRepeaterId]) {
                return this.changes[this.activeRepeaterId].value;
            }
            return this.findFieldInfo(this.activeRepeaterId)?.rows ?? [];
        },

        get activeRepeaterSubFields() {
            return this.findFieldInfo(this.activeRepeaterId)?.subFields ?? [];
        },

        // ── Lifecycle ──────────────────────────────────────────

        async init() {
            this.scanEditableFields();
            await this.fetchFieldsFromApi();
            this.loading = false;

            // Force full-page navigation for the admin bar exit link so Swup
            // (or any other SPA router) cannot intercept and do a partial swap.
            const adminBarExitLink = document.querySelector('#wp-admin-bar-taw-visual-editor a');
            if (adminBarExitLink) {
                adminBarExitLink.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    window.location.assign(adminBarExitLink.href);
                });
            }

            // Intercept ALL internal link clicks before any SPA router (Swup, Barba, etc.)
            // can handle them. Forces a full page reload with ?taw_visual_edit=1 so PHP
            // renders section wrappers and localizes fresh editor data for the new page.
            // The existing beforeunload handler covers the "unsaved changes" warning.
            document.addEventListener('click', this._interceptNavigation.bind(this), { capture: true });

            document.addEventListener('click', (e) => {
                const fieldEl   = e.target.closest('[data-taw-field]');
                const sectionEl = e.target.closest('[data-taw-block-section]');
                const panelEl   = e.target.closest('.taw-editor-panel');
                const toolbarEl = e.target.closest('.taw-editor-toolbar');

                if (fieldEl) {
                    e.preventDefault();
                    e.stopPropagation();
                    this.selectField(fieldEl);
                } else if (sectionEl && !panelEl) {
                    e.preventDefault();
                    this.selectSection(sectionEl.dataset.tawBlockSection);
                } else if (!panelEl && !toolbarEl) {
                    this.deselect();
                }
            });

            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') this.deselect();
                if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                    e.preventDefault();
                    if (this.hasChanges) this.save();
                }
            });

            // No beforeunload warning — unsaved-change count in the panel is sufficient.

            console.log(
                '[TAW Editor] Initialized.',
                Object.keys(this.blockFields).length, 'groups,',
                document.querySelectorAll('[data-taw-field]').length, 'DOM-annotated fields'
            );
        },

        // ── Field Discovery ────────────────────────────────────

        /**
         * DOM scan: find [data-taw-field] elements and build initial blockFields.
         * Inline click-to-edit only works for DOM-annotated fields.
         */
        scanEditableFields() {
            document.querySelectorAll('[data-taw-field]').forEach(el => {
                const blockId  = el.dataset.tawBlock;
                const fieldId  = el.dataset.tawField;
                const type     = el.dataset.tawType;
                const label    = el.dataset.tawLabel || fieldId;
                const editorCfg = el.dataset.tawEditor
                    ? JSON.parse(el.dataset.tawEditor) : {};

                if (!this.blockFields[blockId]) {
                    this.blockFields[blockId] = { title: blockId, fields: [] };
                }

                if (!this.blockFields[blockId].fields.find(f => f.fieldId === fieldId)) {
                    this.blockFields[blockId].fields.push({
                        fieldId,
                        type,
                        label,
                        editor:  editorCfg,
                        options: null,
                        value:   null,
                        el,
                    });
                }
            });
        },

        /**
         * API fetch: load ALL registered metabox fields with their current DB values.
         * Merges into blockFields — enriches DOM-found entries and adds panel-only ones.
         */
        async fetchFieldsFromApi() {
            try {
                const blockParam = tawEditor.queuedBlocks?.length
                    ? `&blocks=${encodeURIComponent(tawEditor.queuedBlocks.join(','))}`
                    : '';

                const res = await fetch(
                    `${tawEditor.restUrl}fields?post_id=${tawEditor.postId}${blockParam}`,
                    { headers: { 'X-WP-Nonce': tawEditor.nonce } }
                );

                if (!res.ok) return;

                const data = await res.json();
                if (!data.success) return;

                for (const [groupId, group] of Object.entries(data.groups)) {
                    if (!this.blockFields[groupId]) {
                        this.blockFields[groupId] = { title: group.title, fields: [] };
                    } else {
                        // Update title if we have one from the API
                        if (group.title && this.blockFields[groupId].title === groupId) {
                            this.blockFields[groupId].title = group.title;
                        }
                    }

                    for (const apiField of group.fields) {
                        const existing = this.blockFields[groupId].fields
                            .find(f => f.fieldId === apiField.fieldId);

                        if (existing) {
                            // Enrich the DOM-found entry with API data
                            existing.options   = apiField.options   || null;
                            existing.value     = apiField.value;
                            existing.subFields = apiField.subFields || null;
                            existing.rows      = apiField.rows      || [];
                        } else {
                            // Panel-only field — no DOM element
                            this.blockFields[groupId].fields.push({
                                fieldId:   apiField.fieldId,
                                type:      apiField.type,
                                label:     apiField.label,
                                options:   apiField.options   || null,
                                value:     apiField.value,
                                subFields: apiField.subFields || null,
                                rows:      apiField.rows      || [],
                                el:        null,
                            });
                        }
                    }
                }
            } catch (e) {
                console.warn('[TAW Editor] Could not load fields from API:', e);
            }
        },

        // ── Selection ──────────────────────────────────────────

        selectField(el) {
            if (this.activeEl === el) {
                if (el.dataset.tawType !== 'image') {
                    this.startInlineEdit(el);
                }
                return;
            }
            this.clearActiveState();

            this.activeEl      = el;
            this.activeBlockId = el.dataset.tawBlock;
            this.activeFieldId = el.dataset.tawField;
            this.panelMode     = 'field';

            el.classList.add('taw-editor-active');
        },

        selectSection(blockId) {
            this.clearActiveState();

            this.activeBlockId = blockId;
            this.activeFieldId = null;
            this.panelMode     = 'section';

            // Highlight data-taw-field elements inside the block
            document.querySelectorAll(`[data-taw-block="${CSS.escape(blockId)}"]`).forEach(el => {
                el.classList.add('taw-editor-active');
            });

            // Highlight the block's auto-wrapped section container
            const sectionEl = document.querySelector(`[data-taw-block-section="${CSS.escape(blockId)}"]`);
            if (sectionEl) sectionEl.classList.add('taw-section-active');
        },

        expandToSection() {
            if (this.activeBlockId) {
                this.selectSection(this.activeBlockId);
            }
        },

        focusField(fieldId) {
            const el = document.querySelector(`[data-taw-field="${CSS.escape(fieldId)}"]`);

            if (el) {
                el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                this.selectField(el);
                return;
            }

            // No DOM element — open in panel field mode without DOM selection
            const fieldInfo = this.findFieldInfo(fieldId);
            if (!fieldInfo) return;

            this.clearActiveState();
            this.activeFieldId = fieldId;
            this.panelMode     = 'field';
        },

        deselect() {
            this.clearActiveState();
            this.panelMode        = 'idle';
            this.activeBlockId    = null;
            this.activeFieldId    = null;
            this.activeRepeaterId = null;
            this.openRepeaterRows = [];
            this.hideToolbar();
        },

        clearActiveState() {
            document.querySelectorAll('.taw-editor-active, .taw-editor-editing').forEach(el => {
                el.classList.remove('taw-editor-active', 'taw-editor-editing');
                el.removeAttribute('contenteditable');
            });
            document.querySelectorAll('.taw-section-active').forEach(el => {
                el.classList.remove('taw-section-active');
            });
            this.hideToolbar();
        },

        // ── Panel Field Editing ────────────────────────────────

        panelFieldUpdate(fieldId, newValue) {
            const fieldInfo = this.findFieldInfo(fieldId);
            if (!fieldInfo) return;

            const el = fieldInfo.el;

            // Capture the original value before the first edit
            if (!this.changes[fieldId]) {
                let originalValue;
                if (el) {
                    originalValue = fieldInfo.type === 'image'
                        ? (el.tagName === 'IMG' ? el.src : '')
                        : el.textContent;
                } else {
                    originalValue = String(fieldInfo.value ?? '');
                }
                this.changes[fieldId] = {
                    blockId:       this.activeBlockId,
                    fieldId,
                    type:          fieldInfo.type,
                    value:         originalValue,
                    originalValue,
                };
            }

            // Update the live DOM element if annotated with data-taw-field
            if (el && fieldInfo.type !== 'image') {
                el.textContent = newValue;
            }

            // Live preview for panel-only text fields via content matching
            if (!el && ['text', 'textarea', 'wysiwyg', 'url', 'number'].includes(fieldInfo.type)) {
                const prevValue = fieldInfo._lastPreviewValue
                    ?? this.changes[fieldId].originalValue;
                this.livePreviewTextContent(this.activeBlockId, prevValue, newValue);
                fieldInfo._lastPreviewValue = newValue;
            }

            // Track or un-track the change
            if (newValue === this.changes[fieldId].originalValue) {
                delete this.changes[fieldId];
                fieldInfo._lastPreviewValue = null;
            } else {
                this.changes[fieldId].value = newValue;
            }
        },

        /**
         * Find the text node in a block section whose trimmed content matches
         * oldValue and replace it with newValue. Used for live preview of
         * panel-only fields that have no data-taw-field DOM annotation.
         */
        livePreviewTextContent(blockId, oldValue, newValue) {
            if (!blockId || !String(oldValue ?? '').trim()) return;

            const sectionEl = document.querySelector(
                `[data-taw-block-section="${CSS.escape(blockId)}"]`
            );
            if (!sectionEl) return;

            const search  = String(oldValue).trim();
            const replace = String(newValue ?? '');

            const walker = document.createTreeWalker(sectionEl, NodeFilter.SHOW_TEXT);
            let node;
            while ((node = walker.nextNode())) {
                const text    = node.textContent;
                const trimmed = text.trim();
                if (trimmed === search) {
                    // Preserve surrounding whitespace (leading/trailing)
                    node.textContent = text.replace(search, replace);
                    return;
                }
            }
        },

        panelImagePicker(fieldId) {
            const fieldInfo = this.findFieldInfo(fieldId);
            if (!fieldInfo) return;

            if (fieldInfo.el) {
                this.openMediaPicker(fieldInfo.el);
                return;
            }

            // No DOM element — open picker and track change without DOM update
            const frame = wp.media({
                title:    `Select Image — ${fieldInfo.label || fieldId}`,
                button:   { text: 'Use this image' },
                multiple: false,
                library:  { type: 'image' },
            });

            frame.on('select', () => {
                const attachment = frame.state().get('selection').first().toJSON();
                this.changes[fieldId] = {
                    blockId:      this.activeBlockId,
                    fieldId,
                    type:         'image',
                    value:        attachment.id,
                    displayValue: attachment.url,
                    originalValue: this.changes[fieldId]?.originalValue ?? String(fieldInfo.value ?? ''),
                };
                // Update the in-memory value so the preview refreshes
                fieldInfo.value = attachment.id;
                fieldInfo._displayUrl = attachment.url;
            });

            frame.open();
        },

        /**
         * Get the current display value for a field (for panel inputs).
         * Prefers live DOM text, falls back to tracked change, then API value.
         */
        getFieldValue(fieldId) {
            const el = document.querySelector(`[data-taw-field="${CSS.escape(fieldId)}"]`);

            if (el) {
                if (el.dataset.tawType === 'image') {
                    return el.tagName === 'IMG' ? el.src : '';
                }
                return el.textContent;
            }

            // Panel-only field — return tracked change value or API value
            if (this.changes[fieldId]) return String(this.changes[fieldId].value ?? '');

            const info = this.findFieldInfo(fieldId);
            return String(info?.value ?? '');
        },

        /**
         * Get the display URL for an image field (URL, not attachment ID).
         */
        getFieldDisplayUrl(fieldId) {
            const el = document.querySelector(`[data-taw-field="${CSS.escape(fieldId)}"]`);
            if (el && el.tagName === 'IMG') return el.src;

            const info = this.findFieldInfo(fieldId);
            if (info?._displayUrl) return info._displayUrl;

            // If value is a URL string (from meta), return it directly
            const val = this.getFieldValue(fieldId);
            return val && String(val).startsWith('http') ? val : '';
        },

        findFieldInfo(fieldId) {
            for (const group of Object.values(this.blockFields)) {
                const found = group.fields.find(f => f.fieldId === fieldId);
                if (found) return found;
            }
            return null;
        },

        // ── Repeater Editing ───────────────────────────────────

        selectRepeater(fieldId) {
            this.activeRepeaterId = fieldId;
            this.openRepeaterRows = [];
            this.panelMode = 'repeater';
        },

        /** Ensure a change-tracking entry exists for the active repeater. */
        _ensureRepeaterChange() {
            if (this.changes[this.activeRepeaterId]) return;
            const fi = this.findFieldInfo(this.activeRepeaterId);
            const originalRows = fi?.rows ?? [];
            this.changes[this.activeRepeaterId] = {
                blockId:       this.activeBlockId,
                fieldId:       this.activeRepeaterId,
                type:          'repeater',
                value:         JSON.parse(JSON.stringify(originalRows)),
                originalValue: JSON.stringify(originalRows),
            };
        },

        toggleRepeaterRow(index) {
            const i = this.openRepeaterRows.indexOf(index);
            if (i === -1) this.openRepeaterRows.push(index);
            else          this.openRepeaterRows.splice(i, 1);
        },

        addRepeaterRow() {
            this._ensureRepeaterChange();
            const emptyRow = {};
            for (const sf of this.activeRepeaterSubFields) emptyRow[sf.id] = '';
            const rows = this.changes[this.activeRepeaterId].value;
            rows.push(emptyRow);
            this.openRepeaterRows.push(rows.length - 1);
        },

        removeRepeaterRow(index) {
            this._ensureRepeaterChange();
            const rows = this.changes[this.activeRepeaterId].value;
            rows.splice(index, 1);
            this.openRepeaterRows = this.openRepeaterRows
                .filter(i => i !== index)
                .map(i => i > index ? i - 1 : i);
            this._pruneRepeaterIfUnchanged();
        },

        repeaterRowFieldUpdate(rowIndex, subFieldId, newValue) {
            this._ensureRepeaterChange();
            const rows = this.changes[this.activeRepeaterId].value;
            if (!rows[rowIndex]) return;
            rows[rowIndex][subFieldId] = newValue;
            this._pruneRepeaterIfUnchanged();
        },

        repeaterRowImagePicker(rowIndex, subFieldId) {
            this._ensureRepeaterChange();
            const frame = wp.media({
                title:    'Select Image',
                button:   { text: 'Use this image' },
                multiple: false,
                library:  { type: 'image' },
            });
            frame.on('select', () => {
                const att = frame.state().get('selection').first().toJSON();
                const rows = this.changes[this.activeRepeaterId].value;
                if (rows[rowIndex]) {
                    rows[rowIndex][subFieldId]              = att.id;
                    rows[rowIndex]['_' + subFieldId + '_url'] = att.url;
                }
            });
            frame.open();
        },

        getRepeaterRowImageUrl(row, subFieldId) {
            // Prefer the display URL resolved by PHP or set by the picker
            if (row['_' + subFieldId + '_url']) return row['_' + subFieldId + '_url'];
            // If the stored value is already a URL string, use it
            const val = String(row[subFieldId] ?? '');
            return val.startsWith('http') ? val : '';
        },

        _pruneRepeaterIfUnchanged() {
            const ch = this.changes[this.activeRepeaterId];
            if (ch && JSON.stringify(ch.value) === ch.originalValue) {
                delete this.changes[this.activeRepeaterId];
            }
        },

        // ── Inline Editing ─────────────────────────────────────

        startInlineEdit(el) {
            if (!el) return;
            const fieldId = el.dataset.tawField;

            if (!this.changes[fieldId]) {
                this._storeOriginal(el);
            }

            el.classList.add('taw-editor-editing');
            el.setAttribute('contenteditable', 'true');
            el.focus();

            if (el.dataset.tawType !== 'textarea') {
                el.addEventListener('keydown', this._singleLineKeyHandler);
            }

            el.addEventListener('input', () => this._trackChange(el));
            el.addEventListener('blur', () => this._finalizeInlineEdit(el), { once: true });
        },

        _singleLineKeyHandler(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                e.target.blur();
            }
        },

        _finalizeInlineEdit(el) {
            el.classList.remove('taw-editor-editing');
            el.removeAttribute('contenteditable');
            el.removeEventListener('keydown', this._singleLineKeyHandler);
            this._trackChange(el);
        },

        // ── Image Editing ──────────────────────────────────────

        openMediaPicker(el) {
            const fieldId = el.dataset.tawField;
            const blockId = el.dataset.tawBlock;

            if (!this.changes[fieldId]) {
                this._storeOriginal(el);
            }

            const frame = wp.media({
                title:    `Select Image — ${el.dataset.tawLabel || fieldId}`,
                button:   { text: 'Use this image' },
                multiple: false,
                library:  { type: 'image' },
            });

            frame.on('select', () => {
                const attachment = frame.state().get('selection').first().toJSON();

                if (el.tagName === 'IMG') {
                    el.src = attachment.url;
                    if (el.srcset) el.removeAttribute('srcset');
                } else {
                    el.style.backgroundImage = `url(${attachment.url})`;
                }

                this.changes[fieldId] = {
                    blockId,
                    fieldId,
                    type:          'image',
                    value:         attachment.id,
                    displayValue:  attachment.url,
                    originalValue: this.changes[fieldId]?.originalValue ?? el.src ?? '',
                };
            });

            frame.open();
        },

        // ── Change Tracking ────────────────────────────────────

        _storeOriginal(el) {
            const fieldId = el.dataset.tawField;
            const type    = el.dataset.tawType;

            let originalValue;
            if (type === 'image') {
                originalValue = el.tagName === 'IMG' ? el.src : el.style.backgroundImage;
            } else {
                originalValue = el.textContent;
            }

            this.changes[fieldId] = {
                blockId: el.dataset.tawBlock,
                fieldId,
                type,
                value:         originalValue,
                originalValue,
            };
        },

        _trackChange(el) {
            const fieldId  = el.dataset.tawField;
            const newValue = el.textContent;

            if (!this.changes[fieldId]) {
                this._storeOriginal(el);
            }

            if (newValue === this.changes[fieldId].originalValue) {
                delete this.changes[fieldId];
            } else {
                this.changes[fieldId].value = newValue;
            }
        },

        // ── Toolbar ────────────────────────────────────────────

        positionToolbar(toolbar, el) {
            const rect      = el.getBoundingClientRect();
            const scrollY   = window.scrollY;
            const scrollX   = window.scrollX;
            const panelWidth = 320;

            let top  = rect.top + scrollY - toolbar.offsetHeight - 10;
            let left = rect.left + scrollX;

            if (top < scrollY + 50) top = rect.bottom + scrollY + 10;

            const maxLeft = window.innerWidth - panelWidth - toolbar.offsetWidth - 10;
            left = Math.max(10, Math.min(left, maxLeft + scrollX));

            toolbar.style.top  = `${top}px`;
            toolbar.style.left = `${left}px`;
        },

        hideToolbar() {
            const existing = document.getElementById('taw-editor-toolbar');
            if (existing) existing.remove();
        },

        // ── Save & Discard ─────────────────────────────────────

        async save() {
            if (!this.hasChanges || this.saving) return;
            this.saving = true;

            try {
                const response = await fetch(`${tawEditor.restUrl}save`, {
                    method:  'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce':   tawEditor.nonce,
                    },
                    body: JSON.stringify({
                        post_id: tawEditor.postId,
                        fields:  this.changes,
                    }),
                });

                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                const result = await response.json();

                if (result.success) {
                    this.changes = {};
                    this.toast(result.message, 'success');
                } else {
                    this.toast(result.message, 'error', 5000);
                    if (result.saved) {
                        result.saved.forEach(id => delete this.changes[id]);
                    }
                }
            } catch (error) {
                console.error('[TAW Editor] Save failed:', error);
                this.toast('Save failed — please try again', 'error', 5000);
            } finally {
                this.saving = false;
            }
        },

        discard() {
            if (!confirm('Discard all unsaved changes?')) return;

            for (const [fieldId, change] of Object.entries(this.changes)) {
                if (change.type === 'repeater') {
                    // Restore original rows on the fieldInfo object so the panel re-renders
                    const fi = this.findFieldInfo(fieldId);
                    if (fi) fi.rows = JSON.parse(change.originalValue);
                    continue;
                }

                const el = document.querySelector(`[data-taw-field="${CSS.escape(fieldId)}"]`);
                if (el) {
                    if (change.type === 'image') {
                        if (el.tagName === 'IMG') el.src = change.originalValue;
                        else el.style.backgroundImage = change.originalValue;
                    } else {
                        el.textContent = change.originalValue;
                    }
                } else {
                    const fieldInfo = this.findFieldInfo(fieldId);
                    if (fieldInfo?._lastPreviewValue != null) {
                        this.livePreviewTextContent(change.blockId, fieldInfo._lastPreviewValue, change.originalValue);
                        fieldInfo._lastPreviewValue = null;
                    }
                }
            }

            this.changes = {};
            this.openRepeaterRows = [];
            this.deselect();
            this.toast('Changes discarded', 'info');
        },

        // ── Toast Notifications ────────────────────────────────

        toast(message, type = 'info', duration = 3000) {
            const id = ++this._toastId;
            this.toasts.push({ id, message, type, visible: false });

            this.$nextTick(() => {
                requestAnimationFrame(() => {
                    const t = this.toasts.find(t => t.id === id);
                    if (t) t.visible = true;
                });
            });

            if (duration > 0) {
                setTimeout(() => this.dismissToast(id), duration);
            }
        },

        dismissToast(id) {
            const t = this.toasts.find(t => t.id === id);
            if (!t) return;
            t.visible = false;
            setTimeout(() => {
                this.toasts = this.toasts.filter(t => t.id !== id);
            }, 300);
        },

        // ── Navigation Intercept ───────────────────────────────

        /**
         * Run in capture phase so it fires before Swup / Barba / any SPA router.
         * Rewrites the destination URL to include ?taw_visual_edit=1 and forces a
         * full page load so PHP renders section wrappers on the next page.
         * Same-page hash/anchor links and external links are left alone.
         */
        _interceptNavigation(e) {
            const link = e.target.closest('a[href]');
            if (!link) return;

            // Let the WP admin bar and the editor panel handle their own links
            if (link.closest('#wpadminbar') || link.closest('.taw-editor-panel')) return;

            try {
                const url = new URL(link.href, window.location.origin);

                // External links — don't touch
                if (url.hostname !== window.location.hostname) return;

                // Same pathname + same query string = hash-only anchor navigation — skip
                if (url.pathname === window.location.pathname && url.search === window.location.search) return;

                e.preventDefault();
                e.stopImmediatePropagation();

                url.searchParams.set('taw_visual_edit', '1');
                window.location.assign(url.toString());
            } catch (_) { /* skip non-parseable hrefs e.g. javascript: */ }
        },

        // ── Utilities ──────────────────────────────────────────

        escHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        },

    }));

});
