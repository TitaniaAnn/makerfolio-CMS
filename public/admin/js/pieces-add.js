// public/admin/js/pieces-add.js
//
// Add/edit pottery piece page: existing-image actions (rotate / crop / delete /
// set-cover), the "add more" picker tile, and the new-upload preview/queue.
// Converted from inline handlers to delegated listeners for CSP compliance.
//
// PHP values reach JS via data-* attributes and the page-global
// <meta name="csrf-token"> (emitted by the admin topbar):
//   - CSRF token   ← meta[name=csrf-token]
//   - IS_EDIT      ← #pieceForm[data-is-edit]
//   - image id / piece id / direction ← data-img-id / data-parent-id / data-dir
(function () {
    'use strict';

    function csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }

    const form = document.getElementById('pieceForm');
    if (!form) return;
    const IS_EDIT = form.dataset.isEdit === 'true';

    // ── Rotate existing image (quarter turn) ──────────────────
    function rotateImage(imgId, pieceId, dir) {
        const item = document.querySelector('.img-gallery-item[data-img-id="' + imgId + '"]');
        const btns = item ? item.querySelectorAll('.rotate-img-btn') : [];
        btns.forEach(b => b.disabled = true);
        fetch('/admin/pieces/rotate-image?img_id=' + imgId + '&piece_id=' + pieceId + '&dir=' + dir + '&csrf=' + encodeURIComponent(csrfToken()), { method: 'POST' })
            .then(r => r.json())
            .then(data => {
                if (data.success && data.image_url && item) {
                    // New filename = new URL, so swapping src is enough to bust the
                    // browser cache; add a timestamp too for belt-and-braces.
                    const img = item.querySelector('img');
                    if (img) img.src = data.image_url + (data.image_url.includes('?') ? '&' : '?') + 't=' + Date.now();
                } else {
                    alert((data && data.error) || 'Rotate failed');
                }
            })
            .catch(() => alert('Rotate failed'))
            .finally(() => btns.forEach(b => b.disabled = false));
    }

    // ── Crop existing image ───────────────────────────────────
    function cropImage(imgId, pieceId) {
        const item = document.querySelector('.img-gallery-item[data-img-id="' + imgId + '"]');
        if (!window.ImageCropper || !item) return;
        const thumb = item.querySelector('img');
        window.ImageCropper.open({
            imageUrl: item.dataset.fullUrl,
            fallbackUrl: thumb ? thumb.src : null,
            saveUrl: '/admin/pieces/crop-image',
            csrf: csrfToken(),
            params: { img_id: imgId, piece_id: pieceId },
            onSaved: (data) => {
                if (thumb) thumb.src = data.image_url + (data.image_url.includes('?') ? '&' : '?') + 't=' + Date.now();
                if (data.full_url) item.dataset.fullUrl = data.full_url;
            }
        });
    }

    // ── Set primary (existing image) ──────────────────────────
    function setPrimary(imgId) {
        document.getElementById('primaryImageId').value = imgId;
        document.querySelectorAll('.img-gallery-item').forEach(el => {
            el.classList.remove('is-primary');
            const lbl = el.querySelector('.img-labels');
            if (!lbl) return;
            if (parseInt(el.dataset.imgId) === imgId) {
                lbl.innerHTML = '<span class="primary-indicator">★ Cover</span>';
                el.classList.add('is-primary');
            } else {
                const existingId = parseInt(el.dataset.imgId);
                lbl.innerHTML = '<button type="button" class="set-primary-btn" data-action="set-primary" data-img-id="' + existingId + '">Set cover</button>';
            }
        });
    }

    // ── Delete existing image ─────────────────────────────────
    function deleteImage(imgId, pieceId) {
        if (!confirm('Delete this image?')) return;
        fetch('/admin/pieces/delete-image?img_id=' + imgId + '&piece_id=' + pieceId + '&csrf=' + encodeURIComponent(csrfToken()), { method: 'POST' })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const el = document.querySelector('.img-gallery-item[data-img-id="' + imgId + '"]');
                    if (el) el.remove();
                } else {
                    alert(data.error || 'Delete failed');
                }
            });
    }

    // Delegated handler for existing-image actions + the picker tile.
    document.addEventListener('click', function (e) {
        const actionEl = e.target.closest('[data-action]');
        if (!actionEl) return;
        const action = actionEl.dataset.action;
        const imgId = parseInt(actionEl.dataset.imgId, 10);

        if (action === 'rotate') {
            rotateImage(imgId, parseInt(actionEl.dataset.parentId, 10), actionEl.dataset.dir);
        } else if (action === 'crop') {
            cropImage(imgId, parseInt(actionEl.dataset.parentId, 10));
        } else if (action === 'delete-image') {
            deleteImage(imgId, parseInt(actionEl.dataset.parentId, 10));
        } else if (action === 'set-primary') {
            setPrimary(imgId);
        } else if (action === 'open-picker') {
            document.getElementById('imgPicker').click();
        }
    });

    // ── New image uploads preview ─────────────────────────────
    const MAX_NEW   = 10;
    const newFiles  = [];
    const picker    = document.getElementById('imgPicker');
    const previews  = document.getElementById('newPreviews');
    const container = document.getElementById('fileInputContainer');

    picker.addEventListener('change', () => {
        Array.from(picker.files).forEach(f => {
            if (!IS_EDIT && newFiles.length >= MAX_NEW) return;
            newFiles.push(f);
        });
        picker.value = '';
        renderPreviews();
        syncFiles();
    });

    function renderPreviews() {
        previews.innerHTML = '';
        newFiles.forEach((f, i) => {
            const reader = new FileReader();
            reader.onload = e => {
                const div = document.createElement('div');
                div.className = 'new-preview-item';
                const showCover = !IS_EDIT && i === 0;
                div.innerHTML = '<img src="' + e.target.result + '">' +
                    '<button type="button" class="remove-new-btn" data-action="remove-new" data-idx="' + i + '">×</button>' +
                    '<div class="new-badge">' + (showCover ? 'Cover' : 'New') + '</div>';
                previews.appendChild(div);
            };
            reader.readAsDataURL(f);
        });
    }

    function removeNew(idx) {
        newFiles.splice(idx, 1);
        renderPreviews();
        syncFiles();
    }

    // Delegated handler for the dynamically-added remove-new buttons.
    previews.addEventListener('click', function (e) {
        const el = e.target.closest('[data-action="remove-new"]');
        if (!el) return;
        removeNew(parseInt(el.dataset.idx, 10));
    });

    function syncFiles() {
        container.innerHTML = '';
        if (newFiles.length === 0) return;
        const dt = new DataTransfer();
        newFiles.forEach(f => dt.items.add(f));
        const inp = document.createElement('input');
        inp.type = 'file'; inp.name = 'images[]'; inp.multiple = true; inp.style.display = 'none';
        container.appendChild(inp);
        try { inp.files = dt.files; } catch (e) {}
    }

    form.addEventListener('submit', e => {
        if (!IS_EDIT && newFiles.length === 0) {
            e.preventDefault();
            alert('Please add at least one photo.');
            return;
        }
        syncFiles();
    });
})();
