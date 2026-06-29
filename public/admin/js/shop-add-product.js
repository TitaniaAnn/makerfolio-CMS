// public/admin/js/shop-add-product.js
//
// Add/edit shop product page: pot-vs-merch field toggle, existing-image actions
// (rotate / crop / delete / set-cover), and the new-upload preview.
// Converted from inline handlers to delegated listeners for CSP compliance.
//
// PHP values reach JS via data-* attributes and the page-global
// <meta name="csrf-token"> (emitted by the admin topbar):
//   - CSRF token ← meta[name=csrf-token]
//   - image id / product id / direction ← data-img-id / data-parent-id / data-dir
(function () {
    'use strict';

    function csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }

    const primaryImageInput = document.getElementById('primaryImageId');
    const imageInput = document.getElementById('imageInput');
    const previewContainer = document.getElementById('newPreviews');

    // Show/hide pot vs merch fields based on type
    function updateTypeFields() {
        const checked = document.querySelector('input[name="type"]:checked');
        const type = checked ? checked.value : undefined;
        document.querySelectorAll('.pot-only').forEach(el => el.style.display = type === 'pot' ? '' : 'none');
        document.querySelectorAll('.merch-only').forEach(el => el.style.display = type === 'merch' ? '' : 'none');
        document.querySelectorAll('.type-tab').forEach(el => el.classList.remove('active'));
        if (type) {
            const radio = document.querySelector('input[value="' + type + '"]');
            const tab = radio ? radio.closest('.type-tab') : null;
            if (tab) tab.classList.add('active');
        }
    }

    function setPrimary(imageId) {
        primaryImageInput.value = imageId;
        document.querySelectorAll('.img-gallery-item').forEach((item) => {
            item.classList.remove('is-primary');

            const label = item.querySelector('.img-labels');
            if (!label) {
                return;
            }

            if (parseInt(item.dataset.imgId, 10) === imageId) {
                item.classList.add('is-primary');
                label.innerHTML = '<span class="primary-indicator">★ Cover</span>';
            } else {
                label.innerHTML = '<button type="button" class="set-primary-btn" data-action="set-primary" data-img-id="' + item.dataset.imgId + '">Set cover</button>';
            }
        });
    }

    async function deleteImage(imageId, productId) {
        if (!confirm('Delete this image?')) {
            return;
        }

        const response = await fetch('/admin/shop/delete-image?img_id=' + imageId + '&product_id=' + productId + '&csrf=' + encodeURIComponent(csrfToken()));
        const result = await response.json();

        if (!result.success) {
            alert(result.error || 'Unable to delete image.');
            return;
        }

        const imageCard = document.querySelector('.img-gallery-item[data-img-id="' + imageId + '"]');
        if (imageCard) {
            imageCard.remove();
        }

        if (primaryImageInput.value === String(imageId)) {
            primaryImageInput.value = '';
        }
    }

    // ── Rotate existing product image (quarter turn) ──────────
    function rotateImage(imgId, productId, dir) {
        const item = document.querySelector('.img-gallery-item[data-img-id="' + imgId + '"]');
        const btns = item ? item.querySelectorAll('.rotate-img-btn') : [];
        btns.forEach(b => b.disabled = true);
        fetch('/admin/shop/rotate-image?img_id=' + imgId + '&product_id=' + productId + '&dir=' + dir + '&csrf=' + encodeURIComponent(csrfToken()), { method: 'POST' })
            .then(r => r.json())
            .then(data => {
                if (data.success && data.image_url && item) {
                    const img = item.querySelector('img');
                    if (img) img.src = data.image_url + (data.image_url.includes('?') ? '&' : '?') + 't=' + Date.now();
                } else {
                    alert((data && data.error) || 'Rotate failed');
                }
            })
            .catch(() => alert('Rotate failed'))
            .finally(() => btns.forEach(b => b.disabled = false));
    }

    // ── Crop existing product image ───────────────────────────
    function cropImage(imgId, productId) {
        const item = document.querySelector('.img-gallery-item[data-img-id="' + imgId + '"]');
        if (!window.ImageCropper || !item) return;
        const thumb = item.querySelector('img');
        window.ImageCropper.open({
            imageUrl: item.dataset.fullUrl,
            fallbackUrl: thumb ? thumb.src : null,
            saveUrl: '/admin/shop/crop-image',
            csrf: csrfToken(),
            params: { img_id: imgId, product_id: productId },
            onSaved: (data) => {
                if (thumb) thumb.src = data.image_url + (data.image_url.includes('?') ? '&' : '?') + 't=' + Date.now();
                if (data.full_url) item.dataset.fullUrl = data.full_url;
            }
        });
    }

    function updateNewPreviews() {
        previewContainer.innerHTML = '';

        Array.from(imageInput.files || []).forEach((file) => {
            const reader = new FileReader();
            reader.onload = (event) => {
                const card = document.createElement('div');
                card.className = 'new-preview-item';
                card.innerHTML = '<img src="' + event.target.result + '" alt="New image preview"><div class="new-badge">New upload</div>';
                previewContainer.appendChild(card);
            };
            reader.readAsDataURL(file);
        });
    }

    // Delegated handler for existing-image actions.
    document.addEventListener('click', function (e) {
        const el = e.target.closest('[data-action]');
        if (!el) return;
        const action = el.dataset.action;
        const imgId = parseInt(el.dataset.imgId, 10);

        if (action === 'rotate') {
            rotateImage(imgId, parseInt(el.dataset.parentId, 10), el.dataset.dir);
        } else if (action === 'crop') {
            cropImage(imgId, parseInt(el.dataset.parentId, 10));
        } else if (action === 'delete-image') {
            deleteImage(imgId, parseInt(el.dataset.parentId, 10));
        } else if (action === 'set-primary') {
            setPrimary(imgId);
        }
    });

    document.querySelectorAll('input[name="type"]').forEach(r => r.addEventListener('change', updateTypeFields));
    if (imageInput) imageInput.addEventListener('change', updateNewPreviews);
    updateTypeFields();
})();
