/**
 * TAW Visual Editor — Alpine.js Frontend Component
 *
 * Scans the page for [data-taw-field] elements and provides
 * inline editing capabilities based on field type.
 */
document.addEventListener('alpine:init', () => {

    Alpine.data('tawVisualEditor', () => ({

        // ── State ──────────────────────────────────────────────

        /** Currently selected element (DOM node) */
        activeEl: null,

        /** Tracks all pending changes: { fieldId: { blockId, type, value, originalValue } } */
        changes: {},

        /** Whether a save request is in flight */
        saving: false,

        /** Status message for the save bar */
        statusMessage: '',

        // ── Computed ───────────────────────────────────────────

        get hasChanges() {
            return Object.keys(this.changes).length > 0;
        },

        get changeCount() {
            return Object.keys(this.changes).length;
        },

        // ── Lifecycle ──────────────────────────────────────────

        init() {
            // Delegated click handler for editable elements
            document.addEventListener('click', (e) => {
                const editableEl = e.target.closest('[data-taw-field]');

                if (editableEl) {
                    this.select(e);
                } else if (!e.target.closest('.taw-editor-toolbar') && !e.target.closest('.taw-editor-savebar')) {
                    this.deselect();
                }
            });

            // Keyboard shortcuts
            document.addEventListener('keydown', (e) => {
                // Escape: deselect current element
                if (e.key === 'Escape') {
                    this.deselect();
                }
                // Ctrl/Cmd + S: save changes
                if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                    e.preventDefault();
                    if (this.hasChanges) {
                        this.save();
                    }
                }
            });

            console.log('[TAW Editor] Visual editor initialized',
                document.querySelectorAll('[data-taw-field]').length, 'editable fields found');
        },

        // ── Selection ──────────────────────────────────────────

        /**
         * Handle click on an editable element.
         * Called via @click on [data-taw-field] elements.
         */
        select(event) {
            const el = event.target.closest('[data-taw-field]');
            if (!el) return;

            event.preventDefault();
            event.stopPropagation();

            // If clicking the already-active element, do nothing
            // (let the editing interaction handle it)
            if (this.activeEl === el) return;

            // Deselect previous
            this.deselect();

            // Select new
            this.activeEl = el;
            el.classList.add('taw-editor-active');

            // Position and show the toolbar
            this.showToolbar(el);
        },

        /**
         * Deselect the current element.
         */
        deselect() {
            if (this.activeEl) {
                this.activeEl.classList.remove('taw-editor-active', 'taw-editor-editing');
                this.activeEl.removeAttribute('contenteditable');
                this.activeEl = null;
            }
            this.hideToolbar();
        },

        // ── Toolbar ────────────────────────────────────────────

        /**
         * Show the floating toolbar above the given element.
         */
        showToolbar(el) {
            // Remove existing toolbar if any
            this.hideToolbar();

            const fieldId = el.dataset.tawField;
            const fieldType = el.dataset.tawType;
            const label = el.dataset.tawLabel || fieldId;

            // Build the toolbar HTML
            const toolbar = document.createElement('div');
            toolbar.className = 'taw-editor-toolbar';
            toolbar.id = 'taw-editor-toolbar';

            toolbar.innerHTML = `
                <span class="taw-editor-toolbar__label">${this.escHtml(label)}</span>
                <span class="taw-editor-toolbar__type">${this.escHtml(fieldType)}</span>
                ${this.getToolbarActions(fieldType)}
            `;

            document.body.appendChild(toolbar);

            // Bind action buttons
            this.bindToolbarActions(toolbar, el, fieldType);

            // Position above the element
            this.positionToolbar(toolbar, el);
        },

        /**
         * Get the action buttons HTML based on field type.
         */
        getToolbarActions(fieldType) {
            switch (fieldType) {
                case 'text':
                case 'textarea':
                case 'url':
                case 'number':
                    return '<button class="taw-editor-toolbar__btn" data-action="edit">Edit</button>';

                case 'wysiwyg':
                    return '<button class="taw-editor-toolbar__btn" data-action="edit-wysiwyg">Edit Content</button>';

                case 'image':
                    return `
                        <button class="taw-editor-toolbar__btn" data-action="change-image">Change</button>
                    `;

                default:
                    return '<button class="taw-editor-toolbar__btn" data-action="edit">Edit</button>';
            }
        },

        /**
         * Bind click handlers to toolbar action buttons.
         */
        bindToolbarActions(toolbar, el, fieldType) {
            toolbar.querySelectorAll('[data-action]').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const action = btn.dataset.action;

                    switch (action) {
                        case 'edit':
                            this.startInlineEdit(el);
                            break;
                        case 'edit-wysiwyg':
                            this.startWysiwygEdit(el);
                            break;
                        case 'change-image':
                            this.openMediaPicker(el);
                            break;
                    }
                });
            });
        },

        /**
         * Position toolbar above the target element.
         */
        positionToolbar(toolbar, el) {
            const rect = el.getBoundingClientRect();
            const scrollY = window.scrollY;
            const scrollX = window.scrollX;

            // Place above the element with 10px gap
            let top = rect.top + scrollY - toolbar.offsetHeight - 10;
            let left = rect.left + scrollX;

            // If toolbar would go off-screen top, place below instead
            if (top < scrollY + 50) { // 50px buffer for admin bar
                top = rect.bottom + scrollY + 10;
                // Flip the arrow
                toolbar.classList.add('taw-editor-toolbar--below');
            }

            // Keep within horizontal viewport
            const maxLeft = window.innerWidth - toolbar.offsetWidth - 10;
            left = Math.max(10, Math.min(left, maxLeft + scrollX));

            toolbar.style.top = `${top}px`;
            toolbar.style.left = `${left}px`;
        },

        /**
         * Remove the toolbar from the DOM.
         */
        hideToolbar() {
            const existing = document.getElementById('taw-editor-toolbar');
            if (existing) existing.remove();
        },

        // ── Inline Editing (text, textarea, url, number) ──────

        /**
         * Make the element contenteditable for inline text editing.
         */
        startInlineEdit(el) {
            const fieldType = el.dataset.tawType;
            const fieldId = el.dataset.tawField;

            // Store original value before editing starts
            if (!this.changes[fieldId]) {
                this._storeOriginal(el);
            }

            el.classList.add('taw-editor-editing');
            el.setAttribute('contenteditable', 'true');
            el.focus();

            // For single-line fields (text, url, number), prevent Enter from creating new lines
            if (fieldType !== 'textarea') {
                el.addEventListener('keydown', this._singleLineKeyHandler);
            }

            // Track changes on input
            el.addEventListener('input', () => this._trackChange(el), { once: false });

            // When the user clicks away, finalize the edit
            el.addEventListener('blur', () => this._finalizeInlineEdit(el), { once: true });
        },

        /**
         * Prevent Enter key in single-line fields.
         */
        _singleLineKeyHandler(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                e.target.blur(); // Finalize the edit
            }
        },

        /**
         * Finalize inline editing — remove contenteditable, record change.
         */
        _finalizeInlineEdit(el) {
            el.classList.remove('taw-editor-editing');
            el.removeAttribute('contenteditable');
            el.removeEventListener('keydown', this._singleLineKeyHandler);
            this._trackChange(el);
        },

        // ── Image Editing ──────────────────────────────────────

        /**
         * Open the WordPress media picker for an image field.
         */
        openMediaPicker(el) {
            const fieldId = el.dataset.tawField;
            const blockId = el.dataset.tawBlock;

            // Store original before first change
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

                // Update the DOM — handle both <img> and background-image
                if (el.tagName === 'IMG') {
                    el.src = attachment.url;
                    // Also update srcset if present
                    if (el.srcset) {
                        el.removeAttribute('srcset');
                    }
                } else {
                    el.style.backgroundImage = `url(${attachment.url})`;
                }

                // Track the change — for images, we store the attachment ID
                this.changes[fieldId] = {
                    blockId: blockId,
                    fieldId: fieldId,
                    type: 'image',
                    value: attachment.id, // WP attachment ID
                    displayValue: attachment.url,
                    originalValue: this.changes[fieldId]?.originalValue ?? el.src ?? '',
                };
            });

            frame.open();
        },

        // ── WYSIWYG Editing (placeholder for Step 3d+) ────────

        startWysiwygEdit(el) {
            // For MVP, fall back to inline editing
            // In a future iteration, this could open a modal with TinyMCE
            this.startInlineEdit(el);
        },

        // ── Change Tracking ────────────────────────────────────

        /**
         * Store the original value of an element before first edit.
         */
        _storeOriginal(el) {
            const fieldId = el.dataset.tawField;
            const type = el.dataset.tawType;

            let originalValue;
            if (type === 'image') {
                originalValue = el.tagName === 'IMG' ? el.src : el.style.backgroundImage;
            } else {
                originalValue = el.textContent;
            }

            // Initialize the change entry with original value
            this.changes[fieldId] = {
                blockId: el.dataset.tawBlock,
                fieldId: fieldId,
                type: type,
                value: originalValue,
                originalValue: originalValue,
            };
        },

        /**
         * Record a change for the given element.
         */
        _trackChange(el) {
            const fieldId = el.dataset.tawField;
            const type = el.dataset.tawType;
            const newValue = el.textContent;

            if (!this.changes[fieldId]) {
                this._storeOriginal(el);
            }

            // If the value is back to original, remove the change
            if (newValue === this.changes[fieldId].originalValue) {
                delete this.changes[fieldId];
            } else {
                this.changes[fieldId].value = newValue;
            }
        },

        // ── Save & Discard ─────────────────────────────────────

        /**
         * Save all pending changes via the REST API.
         * (REST endpoint will be built in Step 4)
         */
        async save() {
            if (!this.hasChanges || this.saving) return;

            this.saving = true;
            this.statusMessage = 'Saving…';

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

                if (!response.ok) {
                    throw new Error(`Save failed: ${response.status}`);
                }

                const result = await response.json();

                // Clear changes on success
                this.changes = {};
                this.statusMessage = 'Saved!';

                // Brief success feedback, then hide the bar
                setTimeout(() => {
                    this.statusMessage = '';
                }, 1500);

            } catch (error) {
                console.error('[TAW Editor] Save failed:', error);
                this.statusMessage = 'Save failed — please try again';
            } finally {
                this.saving = false;
            }
        },

        /**
         * Discard all pending changes and revert DOM to original values.
         */
        discard() {
            if (!confirm('Discard all unsaved changes?')) return;

            // Revert each changed element to its original value
            for (const [fieldId, change] of Object.entries(this.changes)) {
                const el = document.querySelector(`[data-taw-field="${fieldId}"]`);
                if (!el) continue;

                if (change.type === 'image') {
                    if (el.tagName === 'IMG') {
                        el.src = change.originalValue;
                    } else {
                        el.style.backgroundImage = change.originalValue;
                    }
                } else {
                    el.textContent = change.originalValue;
                }
            }

            this.changes = {};
            this.deselect();
        },

        // ── Utilities ──────────────────────────────────────────

        /**
         * Simple HTML escaping for toolbar content.
         */
        escHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        },

    }));

});