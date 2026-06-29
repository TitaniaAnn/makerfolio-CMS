// public/admin/js/templates-add.js
//
// Add/edit template page: multi-file upload queue with per-file labels,
// existing-file delete (edit mode), and the preview-image picker.
// Converted from an inline script to an external file for CSP compliance.
//
// PHP values reach JS via the page-global <meta name="csrf-token"> (admin
// topbar) and #templateForm[data-is-edit].
(function () {
    'use strict';

    function csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }

    const form = document.getElementById('templateForm');
    if (!form) return;
    const IS_EDIT = form.dataset.isEdit === 'true';

    const allFiles  = [];
    const picker    = document.getElementById('filePicker');
    const fileDrop  = document.getElementById('fileDrop');
    const fileQueue = document.getElementById('fileQueue');
    const container = document.getElementById('fileInputContainer');

    fileDrop.addEventListener('click', () => picker.click());
    fileDrop.addEventListener('dragover', e => { e.preventDefault(); fileDrop.classList.add('dragover'); });
    fileDrop.addEventListener('dragleave', () => fileDrop.classList.remove('dragover'));
    fileDrop.addEventListener('drop', e => {
        e.preventDefault(); fileDrop.classList.remove('dragover');
        addFiles(Array.from(e.dataTransfer.files));
    });
    picker.addEventListener('change', () => { addFiles(Array.from(picker.files)); picker.value = ''; });

    function addFiles(files)   { files.forEach(f => allFiles.push({ file: f, label: '' })); renderQueue(); syncInput(); }
    function removeFile(idx)   { allFiles.splice(idx, 1); renderQueue(); syncInput(); }

    function renderQueue() {
        fileQueue.innerHTML = '';
        allFiles.forEach((item, idx) => {
            const ext = item.file.name.split('.').pop().toUpperCase();
            const div = document.createElement('div');
            div.className = 'file-queue-item';
            div.innerHTML =
                '<span class="file-queue-item__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></span>' +
                '<span class="file-queue-item__name" title="' + item.file.name + '">' + item.file.name + '</span>' +
                '<span class="file-queue-item__ext">' + ext + '</span>' +
                '<span class="file-queue-item__label"><input type="text" placeholder="Label (optional)" value="' + item.label + '" data-idx="' + idx + '"></span>' +
                '<button type="button" class="file-queue-item__remove" data-idx="' + idx + '">×</button>';
            fileQueue.appendChild(div);
        });
        fileQueue.querySelectorAll('.file-queue-item__label input').forEach(inp => {
            inp.addEventListener('input', () => { allFiles[inp.dataset.idx].label = inp.value; });
        });
        fileQueue.querySelectorAll('.file-queue-item__remove').forEach(btn => {
            btn.addEventListener('click', () => removeFile(parseInt(btn.dataset.idx)));
        });
    }

    function syncInput() {
        container.innerHTML = '';
        if (!allFiles.length) return;
        const dt = new DataTransfer();
        allFiles.forEach(item => dt.items.add(item.file));
        const inp = document.createElement('input');
        inp.type = 'file'; inp.name = 'template_files[]'; inp.multiple = true; inp.style.display = 'none';
        container.appendChild(inp);
        try { inp.files = dt.files; } catch (e) {}
        allFiles.forEach((item) => {
            const h = document.createElement('input');
            h.type = 'hidden'; h.name = 'file_labels[]'; h.value = item.label;
            container.appendChild(h);
        });
    }

    form.addEventListener('submit', e => {
        if (!IS_EDIT && !allFiles.length) {
            e.preventDefault();
            alert('Please add at least one template file.');
            return;
        }
        syncInput();
    });

    // Delete an existing file (edit mode only).
    document.querySelectorAll('.file-list-item__del').forEach(btn => {
        btn.addEventListener('click', () => {
            if (!confirm('Remove this file?')) return;
            const fileId     = btn.dataset.fileId;
            const templateId = btn.dataset.templateId;
            fetch('/admin/templates/delete-file?file_id=' + fileId + '&template_id=' + templateId + '&csrf=' + encodeURIComponent(csrfToken()), { method: 'POST' })
                .then(r => r.json())
                .then(d => {
                    if (d.success) btn.closest('.file-list-item').remove();
                    else alert(d.error || 'Delete failed.');
                });
        });
    });

    // Preview image.
    const previewDrop   = document.getElementById('previewDrop');
    const previewFile   = document.getElementById('previewFile');
    const previewChosen = document.getElementById('previewChosen');
    const previewBox    = document.getElementById('previewBox');
    const previewImg    = document.getElementById('previewImg');

    previewDrop.addEventListener('click', () => previewFile.click());
    previewFile.addEventListener('change', () => {
        if (!previewFile.files[0]) return;
        previewChosen.textContent = previewFile.files[0].name;
        const reader = new FileReader();
        reader.onload = e => { previewImg.src = e.target.result; previewBox.style.display = 'block'; };
        reader.readAsDataURL(previewFile.files[0]);
    });
})();
