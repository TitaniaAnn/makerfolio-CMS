// public/admin/js/announcements-add.js
//
// Add/edit announcement page: drag-and-drop image upload with preview, and the
// live social-media-post preview that updates as the title / linked entities
// change. Converted from an inline script to an external file for CSP.
//
// PHP value reaches JS via #announcementForm[data-visit-line] (the branding
// line appended to the social preview).
(function () {
    'use strict';

    const form = document.getElementById('announcementForm');
    const SOCIAL_VISIT_LINE = form ? (form.dataset.visitLine || '') : '';

    // Image drag-and-drop
    const uploadArea = document.getElementById('uploadArea');
    const imageInput = document.getElementById('imageInput');

    uploadArea.addEventListener('click', () => imageInput.click());

    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        uploadArea.addEventListener(eventName, preventDefaults, false);
    });

    function preventDefaults(e) {
        e.preventDefault();
        e.stopPropagation();
    }

    uploadArea.addEventListener('drop', handleDrop, false);

    function handleDrop(e) {
        const dt = e.dataTransfer;
        const files = dt.files;
        imageInput.files = files;
        previewImage();
    }

    imageInput.addEventListener('change', previewImage);

    function previewImage() {
        const file = imageInput.files[0];
        if (!file) return;

        const reader = new FileReader();
        reader.onload = (e) => {
            const newPreviewDiv = document.getElementById('newImagePreview');
            newPreviewDiv.innerHTML = '<img src="' + e.target.result + '" alt="New image" class="image-preview" style="margin-top: 1rem;">';
            uploadArea.classList.add('has-image');
        };
        reader.readAsDataURL(file);
    }

    // Update social media preview
    function updateSocialPreview() {
        const title = document.querySelector('input[name="title"]').value || '[Announcement Title]';
        const eventCheckboxes = Array.from(document.querySelectorAll('input[name="event_ids[]"]:checked'));
        const potteryCheckboxes = Array.from(document.querySelectorAll('input[name="piece_ids[]"]:checked'));

        let entities = '';
        eventCheckboxes.forEach(cb => {
            const label = cb.nextElementSibling.textContent.trim();
            entities += '📅 ' + label + '\n';
        });
        potteryCheckboxes.forEach(cb => {
            const label = cb.nextElementSibling.textContent.trim();
            entities += '🏺 ' + label + '\n';
        });

        const preview = title + '\n\n' +
            (entities || '(No linked events or pieces selected)') +
            (SOCIAL_VISIT_LINE ? '\n\n' + SOCIAL_VISIT_LINE : '');

        document.getElementById('socialPreview').textContent = preview;
    }

    // Bind preview updates
    document.querySelector('input[name="title"]').addEventListener('input', updateSocialPreview);
    document.querySelectorAll('input[name="event_ids[]"], input[name="piece_ids[]"]').forEach(cb => {
        cb.addEventListener('change', updateSocialPreview);
    });

    // Initial preview
    updateSocialPreview();
})();
