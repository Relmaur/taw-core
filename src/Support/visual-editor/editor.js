/**
 * TAW Visual Editor — Alpine.js Frontend Component
 *
 * Provides inline editing and a right-side panel for
 * content editing on the frontend.
 */
document.addEventListener('alpine:init', () => {

    Alpine.data('tawVisualEditor', () => ({

        // ── State ──────────────────────────────────────────────

        /** Currently selected DOM element */
        activeEl: null,

        /** Current panel mode: 'idle' | 'field' | 'section' */
        panelMode: 'idle',

        /** The block ID of the currently selected section */
        activeBlockId: null,

        /** The field ID of the currently selected field */
        activeFieldId: null,

        /** Tracks all pending changes */
        changes: {},

        /** Whether a save is in flight */
        saving: false,

        /** Toast notifications */
        toasts: [],
        _toastId: 0,

        /** Map of blockId → array of field info (built on init) */
        blockFields: {},

        // ── Computed ───────────────────────────────────────────

        get hasChanges() {
            return Object.keys(this.changes).length > 0;
        },

        get changeCount() {
            return Object.keys(this.changes).length;
        },

        /** Get the fields to display in the panel for the active block */
        get activeSectionFields() {
            if (!this.activeBlockId) return [];
            return this.blockFields[this.activeBlockId] || [];
        },

        /** Get the single active field's info for field mode */
        get activeFieldInfo() {
            if (!this.activeFieldId || !this.activeBlockId) return null;
            const fields = this.blockFields[this.activeBlockId] || [];
            return fields.find(f => f.fieldId === this.activeFieldId) || null;
        },

        /** List of blocks found on the page (for idle panel state) */
        get availableBlocks() {
            return Object.entries(this.blockFields).map(([blockId, fields]) => ({
                blockId,
                fieldCount: fields.length,
            }));
        },

        // ── Lifecycle ──────────────────────────────────────────

        init() {
            // Build the block → fields map by scanning the DOM
            this.scanEditableFields();

            // Delegated click handler
            document.addEventListener('click', (e) => {
                const fieldEl = e.target.closest('[data-taw-field]');
                const sectionEl = e.target.closest('[data-taw-block-section]');
                const panelEl = e.target.closest('.taw-editor-panel');
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

            // Keyboard shortcuts
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') this.deselect();
                if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                    e.preventDefault();
                    if (this.hasChanges) this.save();
                }
            });

            // Unsaved changes protection
            window.addEventListener('beforeunload', (e) => {
                if (this.hasChanges) {
                    e.preventDefault();
                    e.returnValue = '';
                }
            });

            console.log('[TAW Editor] Initialized.',
                Object.keys(this.blockFields).length, 'blocks,',
                document.querySelectorAll('[data-taw-field]').length, 'fields');
        },

        /**
         * Scan the DOM for all [data-taw-field] elements and
         * build a map of blockId → fields.
         */
        scanEditableFields() {
            this.blockFields = {};

            document.querySelectorAll('[data-taw-field]').forEach(el => {
                const blockId = el.dataset.tawBlock;
                const fieldId = el.dataset.tawField;
                const type = el.dataset.tawType;
                const label = el.dataset.tawLabel || fieldId;
                const editor = el.dataset.tawEditor
                    ? JSON.parse(el.dataset.tawEditor) : {};

                if (!this.blockFields[blockId]) {
                    this.blockFields[blockId] = [];
                }

                // Avoid duplicates (same field rendered twice)
                if (!this.blockFields[blockId].find(f => f.fieldId === fieldId)) {
                    this.blockFields[blockId].push({
                        fieldId,
                        type,
                        label,
                        editor,
                        el, // Reference to the DOM element
                    });
                }
            });
        },

        // ── Selection ──────────────────────────────────────────

        /**
         * Select a single field — opens field mode in the panel.
         */
        selectField(el) {
            if (this.activeEl === el) {
                // Already selected → start inline editing (if text-based)
                if (el.dataset.tawType !== 'image') {
                    this.startInlineEdit(el);
                }
                return;
            }
            this.clearActiveState();

            this.activeEl = el;
            this.activeBlockId = el.dataset.tawBlock;
            this.activeFieldId = el.dataset.tawField;
            this.panelMode = 'field';

            el.classList.add('taw-editor-active');
            // this.showToolbar(el);
        },

        /**
         * Select a block section — opens section mode in the panel
         * showing all editable fields for that block.
         */
        selectSection(blockId) {
            this.clearActiveState();

            this.activeBlockId = blockId;
            this.activeFieldId = null;
            this.panelMode = 'section';

            // Highlight all fields in this section
            document.querySelectorAll(`[data-taw-block="${blockId}"]`).forEach(el => {
                el.classList.add('taw-editor-active');
            });
        },

        /**
         * Switch from field mode to section mode for the same block.
         */
        expandToSection() {
            if (this.activeBlockId) {
                this.selectSection(this.activeBlockId);
            }
        },

        /**
         * From the panel, focus a specific field (scrolls to it and selects it).
         */
        focusField(fieldId) {
            const el = document.querySelector(`[data-taw-field="${fieldId}"]`);
            if (!el) return;

            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            this.selectField(el);
        },

        deselect() {
            this.clearActiveState();
            this.panelMode = 'idle';
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

        // ── Toolbar (stays as a lightweight indicator) ─────────

        // showToolbar(el) {
        //     this.hideToolbar();

        //     const label = el.dataset.tawLabel || el.dataset.tawField;
        //     const fieldType = el.dataset.tawType;

        //     const toolbar = document.createElement('div');
        //     toolbar.className = 'taw-editor-toolbar';
        //     toolbar.id = 'taw-editor-toolbar';
        //     toolbar.innerHTML = `
        //         <span class="taw-editor-toolbar__label">${this.escHtml(label)}</span>
        //         <span class="taw-editor-toolbar__type">${this.escHtml(fieldType)}</span>
        //     `;

        //     document.body.appendChild(toolbar);
        //     this.positionToolbar(toolbar, el);
        // },

        positionToolbar(toolbar, el) {
            const rect = el.getBoundingClientRect();
            const scrollY = window.scrollY;
            const scrollX = window.scrollX;
            // Account for the panel width (320px) on the right
            const panelWidth = 320;

            let top = rect.top + scrollY - toolbar.offsetHeight - 10;
            let left = rect.left + scrollX;

            if (top < scrollY + 50) {
                top = rect.bottom + scrollY + 10;
            }

            const maxLeft = window.innerWidth - panelWidth - toolbar.offsetWidth - 10;
            left = Math.max(10, Math.min(left, maxLeft + scrollX));

            toolbar.style.top = `${top}px`;
            toolbar.style.left = `${left}px`;
        },

        hideToolbar() {
            const existing = document.getElementById('taw-editor-toolbar');
            if (existing) existing.remove();
        },

        // ── Panel Field Editing ────────────────────────────────
        // These are called from the panel's input elements.

        /**
         * Handle panel input change for a field.
         * Updates both the DOM preview and the changes tracker.
         */
        panelFieldUpdate(fieldId, newValue) {
            const fieldInfo = this.findFieldInfo(fieldId);
            if (!fieldInfo) return;

            const el = fieldInfo.el;

            // Store original if first edit
            if (!this.changes[fieldId]) {
                this._storeOriginal(el);
            }

            // Update the live DOM preview
            if (fieldInfo.type === 'image') {
                // For image, newValue from panel is an attachment ID
                // We'll handle this via the media picker
                return;
            }

            // Update DOM element
            el.textContent = newValue;

            // Track the change
            if (newValue === this.changes[fieldId]?.originalValue) {
                delete this.changes[fieldId];
            } else {
                this.changes[fieldId].value = newValue;
            }
        },

        /**
         * Open the media picker from the panel for an image field.
         */
        panelImagePicker(fieldId) {
            const fieldInfo = this.findFieldInfo(fieldId);
            if (!fieldInfo) return;
            this.openMediaPicker(fieldInfo.el);
        },

        /**
         * Get the current display value for a field (for panel inputs).
         */
        getFieldValue(fieldId) {
            const el = document.querySelector(`[data-taw-field="${fieldId}"]`);
            if (!el) return '';

            if (el.dataset.tawType === 'image') {
                return el.tagName === 'IMG' ? el.src : '';
            }
            return el.textContent;
        },

        /**
         * Find a field's info object from any block.
         */
        findFieldInfo(fieldId) {
            for (const fields of Object.values(this.blockFields)) {
                const found = fields.find(f => f.fieldId === fieldId);
                if (found) return found;
            }
            return null;
        },

        // ── Inline Editing ─────────────────────────────────────

        startInlineEdit(el) {
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
                title: `Select Image — ${el.dataset.tawLabel || fieldId}`,
                button: { text: 'Use this image' },
                multiple: false,
                library: { type: 'image' },
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
                    type: 'image',
                    value: attachment.id,
                    displayValue: attachment.url,
                    originalValue: this.changes[fieldId]?.originalValue ?? el.src ?? '',
                };
            });

            frame.open();
        },

        startWysiwygEdit(el) {
            this.startInlineEdit(el);
        },

        // ── Change Tracking ────────────────────────────────────

        _storeOriginal(el) {
            const fieldId = el.dataset.tawField;
            const type = el.dataset.tawType;

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
                value: originalValue,
                originalValue,
            };
        },

        _trackChange(el) {
            const fieldId = el.dataset.tawField;
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

        // ── Save & Discard ─────────────────────────────────────

        async save() {
            if (!this.hasChanges || this.saving) return;
            this.saving = true;

            try {
                const response = await fetch(`${tawEditor.restUrl}save`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': tawEditor.nonce,
                    },
                    body: JSON.stringify({
                        post_id: tawEditor.postId,
                        fields: this.changes,
                    }),
                });

                if (!response.ok) throw new Error(`${response.status}`);
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
                const el = document.querySelector(`[data-taw-field="${fieldId}"]`);
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

        toasts: [],
        _toastId: 0,

        toast(message, type = 'info', duration = 3000) {
            const id = ++this._toastId;
            this.toasts.push({ id, message, type, visible: false });

            // $nextTick lets Alpine wire up the x-for reactive effects for the
            // new item first; the inner rAF then gives the browser a chance to
            // compute the initial opacity:0 style so the CSS transition fires.
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