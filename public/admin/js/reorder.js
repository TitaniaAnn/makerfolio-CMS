/**
 * reorder.js — shared admin drag-to-reorder wiring.
 *
 * Usage on any admin list page:
 *
 *   <tbody data-reorder-kind="pottery"> ... rows with data-id="123" ... </tbody>
 *   <script src="/admin/js/sortable.min.js"></script>
 *   <script src="/admin/js/reorder.js"></script>
 *
 * For page_sections (which is reordered per page, not by row ID):
 *
 *   <ul data-reorder-kind="page_sections" data-page="home">
 *       <li data-section-key="hero">...</li>
 *   </ul>
 *
 * Each draggable row should include a drag handle marked with the
 * `.reorder-handle` class so the rest of the row stays clickable.
 *
 * The CSRF token is read from the <meta name="csrf-token"> tag that
 * partials/topbar.php emits on every admin page.
 */
(function () {
    'use strict';

    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

    function statusEl(container) {
        let el = container.parentElement.querySelector('.reorder-status');
        if (!el) {
            el = document.createElement('span');
            el.className = 'reorder-status';
            // Insert near the container so feedback shows close to the drag area.
            container.parentElement.insertBefore(el, container);
        }
        return el;
    }

    function showStatus(container, text, kind) {
        const el = statusEl(container);
        el.textContent = text;
        el.dataset.kind = kind; // 'success' | 'error' | 'saving'
        if (kind === 'success') {
            setTimeout(() => {
                if (el.dataset.kind === 'success') {
                    el.textContent = '';
                    delete el.dataset.kind;
                }
            }, 2000);
        }
    }

    function persistOrder(container) {
        const kind = container.getAttribute('data-reorder-kind');
        const body = new URLSearchParams();
        body.set('kind', kind);

        if (kind === 'page_sections') {
            const page = container.getAttribute('data-page') || '';
            body.set('page', page);
            container.querySelectorAll('[data-section-key]').forEach((el) => {
                body.append('sections[]', el.getAttribute('data-section-key'));
            });
        } else {
            container.querySelectorAll('[data-id]').forEach((el) => {
                body.append('ids[]', el.getAttribute('data-id'));
            });
        }

        showStatus(container, 'Saving order…', 'saving');

        fetch('/admin/reorder.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-CSRF-Token': csrfToken,
                'Content-Type': 'application/x-www-form-urlencoded',
                'Accept': 'application/json',
            },
            body: body.toString(),
        })
            .then((res) => res.json().then((data) => ({ ok: res.ok, data })))
            .then(({ ok, data }) => {
                if (ok && data.ok) {
                    showStatus(container, '✓ Order saved', 'success');
                } else {
                    showStatus(container, '✗ ' + (data.error || 'Reorder failed'), 'error');
                }
            })
            .catch((err) => {
                showStatus(container, '✗ ' + (err.message || 'Network error'), 'error');
            });
    }

    function attach(container) {
        if (typeof Sortable === 'undefined') {
            console.warn('[reorder.js] Sortable is not loaded; drag-to-reorder disabled.');
            return;
        }
        // eslint-disable-next-line no-new
        new Sortable(container, {
            handle: '.reorder-handle',
            animation: 150,
            ghostClass: 'reorder-ghost',
            chosenClass: 'reorder-chosen',
            dragClass: 'reorder-drag',
            onEnd: () => persistOrder(container),
        });
    }

    document.querySelectorAll('[data-reorder-kind]').forEach(attach);
})();
