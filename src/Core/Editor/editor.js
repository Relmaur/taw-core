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

        activeEl:      null,
        panelMode:     'idle',
        activeBlockId: null,
        activeFieldId: null,
        loading:       true,

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

            window.addEventListener('beforeunload', (e) => {
                if (this.hasChanges) {
                    e.preventDefault();
                    e.returnValue = '';
                }
            });

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
                            existing.options = apiField.options || null;
                            existing.value   = apiField.value;
                        } else {
                            // Panel-only field — no DOM element
                            this.blockFields[groupId].fields.push({
                                fieldId: apiField.fieldId,
                                type:    apiField.type,
                                label:   apiField.label,
                                options: apiField.options || null,
                                value:   apiField.value,
                                el:      null,
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

            document.querySelectorAll(`[data-taw-block="${CSS.escape(blockId)}"]`).forEach(el => {
                el.classList.add('taw-editor-active');
            });
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
            this.panelMode     = 'idle';
            this.activeBlockId = null;
            this.activeFieldId = null;
            this.hideToolbar();
        },

        clearActiveState() {
            document.querySelectorAll('.taw-editor-active, .taw-editor-editing').forEach(el => {
                el.classList.remove('taw-editor-active', 'taw-editor-editing');
                el.removeAttribute('contenteditable');
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
                    blockId:       this.activeBlockId || fieldInfo.metaboxId,
                    fieldId,
                    type:          fieldInfo.type,
                    value:         originalValue,
                    originalValue,
                };
            }

            // Update the live DOM element if it exists
            if (el && fieldInfo.type !== 'image') {
                el.textContent = newValue;
            }

            // Track or un-track the change
            if (newValue === this.changes[fieldId].originalValue) {
                delete this.changes[fieldId];
            } else {
                this.changes[fieldId].value = newValue;
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
                const el = document.querySelector(`[data-taw-field="${CSS.escape(fieldId)}"]`);
                if (!el) continue;

                if (change.type === 'image') {
                    if (el.tagName === 'IMG') el.src = change.originalValue;
                    else el.style.backgroundImage = change.originalValue;
                } else {
                    el.textContent = change.originalValue;
                }
            }

            this.changes = {};
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

        // ── Utilities ──────────────────────────────────────────

        escHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        },

    }));

});
