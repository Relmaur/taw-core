/**
 * Lucide icon picker — admin field UI.
 *
 * Plain event-delegated vanilla JS (same technique as the image/files field
 * scripts in Metabox.php), not an Alpine component: delegation means it
 * works unmodified for icon fields added later by the repeater "add row"
 * clone, with no explicit re-init step required.
 */
(function () {
    'use strict';

    var modal = null;
    var activeField = null;
    var debounceTimer = null;

    function escapeAttr(value) {
        return String(value).replace(/[&<>"']/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
        });
    }

    function ensureModal() {
        if (modal) {
            return modal;
        }

        modal = document.createElement('div');
        modal.className = 'taw-icon-modal';
        modal.innerHTML =
            '<div class="taw-icon-modal__dialog">' +
                '<div class="taw-icon-modal__header">' +
                    '<input type="text" class="taw-icon-modal__search" placeholder="Search icons…" autocomplete="off">' +
                    '<button type="button" class="taw-icon-modal__close" aria-label="Close">&times;</button>' +
                '</div>' +
                '<div class="taw-icon-modal__grid" role="listbox"></div>' +
            '</div>';
        document.body.appendChild(modal);

        modal.querySelector('.taw-icon-modal__close').addEventListener('click', closeModal);

        modal.addEventListener('click', function (e) {
            if (e.target === modal) {
                closeModal();
            }
        });

        modal.querySelector('.taw-icon-modal__search').addEventListener('input', function (e) {
            var value = e.target.value;
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(function () {
                searchIcons(value);
            }, 200);
        });

        modal.querySelector('.taw-icon-modal__grid').addEventListener('click', function (e) {
            var item = e.target.closest('.taw-icon-modal__item');
            if (item) {
                selectIcon(item.getAttribute('data-name'), item.innerHTML);
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.classList.contains('is-open')) {
                closeModal();
            }
        });

        return modal;
    }

    function openModal(field) {
        activeField = field;
        var m = ensureModal();
        m.classList.add('is-open');

        var search = m.querySelector('.taw-icon-modal__search');
        search.value = '';
        search.focus();

        searchIcons('');
    }

    function closeModal() {
        if (modal) {
            modal.classList.remove('is-open');
        }
        activeField = null;
    }

    function searchIcons(query) {
        var grid = modal.querySelector('.taw-icon-modal__grid');
        grid.setAttribute('aria-busy', 'true');

        var url = tawLucidePicker.restUrl + '?search=' + encodeURIComponent(query);

        fetch(url, { headers: { 'X-WP-Nonce': tawLucidePicker.nonce } })
            .then(function (response) {
                return response.json();
            })
            .then(function (icons) {
                grid.removeAttribute('aria-busy');

                if (!icons.length) {
                    grid.innerHTML = '<p class="taw-icon-modal__empty">No icons found.</p>';
                    return;
                }

                grid.innerHTML = icons.map(function (icon) {
                    var name = escapeAttr(icon.name);
                    return '<button type="button" class="taw-icon-modal__item" data-name="' + name + '" title="' + name + '">' + icon.svg + '</button>';
                }).join('');
            })
            .catch(function () {
                grid.removeAttribute('aria-busy');
                grid.innerHTML = '<p class="taw-icon-modal__empty">Could not load icons.</p>';
            });
    }

    function selectIcon(name, svgMarkup) {
        if (!activeField) {
            return;
        }

        var input = activeField.querySelector('.taw-icon-input');
        var preview = activeField.querySelector('.taw-icon-preview');
        var removeBtn = activeField.querySelector('.taw-icon-remove');

        input.value = name;
        input.dispatchEvent(new Event('change', { bubbles: true }));

        var wrapper = document.createElement('div');
        wrapper.innerHTML = svgMarkup.trim();
        var svgEl = wrapper.firstElementChild;
        if (svgEl) {
            svgEl.classList.add('taw-icon-preview__svg');
        }

        preview.innerHTML = '';
        if (svgEl) {
            preview.appendChild(svgEl);
        }

        var nameEl = document.createElement('span');
        nameEl.className = 'taw-icon-preview__name';
        nameEl.textContent = name;
        preview.appendChild(nameEl);

        if (removeBtn) {
            removeBtn.style.display = '';
        }

        closeModal();
    }

    document.addEventListener('click', function (e) {
        var chooseBtn = e.target.closest('.taw-icon-choose');
        if (chooseBtn) {
            e.preventDefault();
            openModal(chooseBtn.closest('.taw-icon-field'));
            return;
        }

        var removeBtn = e.target.closest('.taw-icon-remove');
        if (removeBtn) {
            e.preventDefault();
            var field = removeBtn.closest('.taw-icon-field');
            var input = field.querySelector('.taw-icon-input');
            var preview = field.querySelector('.taw-icon-preview');

            input.value = '';
            input.dispatchEvent(new Event('change', { bubbles: true }));
            preview.innerHTML = '<span class="taw-icon-preview__empty">No icon selected</span>';
            removeBtn.style.display = 'none';
        }
    });
})();
