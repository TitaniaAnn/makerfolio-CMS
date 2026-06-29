// public/admin/js/email-templates.js
//
// Email-templates settings page: live {var} preview substitution, the per-row
// "Send test" trigger, the send-test confirm dialog, and click-to-copy on the
// variable names. Converted from inline handlers/script to delegated listeners
// for CSP compliance.
//
// PHP values reach JS via data-* attributes:
//   - PREVIEW_SAMPLES ← #email-form[data-samples] (JSON)
//   - per-template defaults ← .et-template[data-default-subject|data-default-body]
//   - send-test target form ← data-action="send-test" + data-template-key
//   - copy text ← data-action="copy-var" + data-var
(function () {
    'use strict';

    const form = document.getElementById('email-form');
    let SAMPLES = {};
    if (form && form.dataset.samples) {
        try { SAMPLES = JSON.parse(form.dataset.samples); } catch (e) { SAMPLES = {}; }
    }

    function substitute(template) {
        if (!template) return '';
        return template.replace(/\{(\w+)\}/g, (m, key) => key in SAMPLES ? SAMPLES[key] : m);
    }

    document.querySelectorAll('.et-template').forEach(section => {
        const subjectInput = section.querySelector('[data-field="subject"]');
        const bodyInput    = section.querySelector('[data-field="body"]');
        const subPreview   = section.querySelector('[data-preview="subject"]');
        const bodyPreview  = section.querySelector('[data-preview="body"]');
        const defaults = {
            subject: section.dataset.defaultSubject,
            body:    section.dataset.defaultBody,
        };

        function refresh() {
            const subText = subjectInput.value.trim() === '' ? defaults.subject : subjectInput.value;
            const bodText = bodyInput.value.trim() === '' ? defaults.body : bodyInput.value;
            subPreview.textContent  = substitute(subText);
            bodyPreview.textContent = substitute(bodText);
        }

        subjectInput.addEventListener('input', refresh);
        bodyInput.addEventListener('input', refresh);
        refresh();
    });

    // "Send test" button → submit the matching hidden form (replaces inline onclick).
    document.addEventListener('click', function (e) {
        const sendBtn = e.target.closest('[data-action="send-test"]');
        if (sendBtn) {
            const f = document.getElementById('send-test-' + sendBtn.dataset.templateKey);
            if (f) f.submit();
            return;
        }
        // Click-to-copy a variable name (replaces inline onclick).
        const copyEl = e.target.closest('[data-action="copy-var"]');
        if (copyEl && navigator.clipboard) {
            navigator.clipboard.writeText('{' + copyEl.dataset.var + '}');
        }
    });

    // Confirm before a send-test form submits (replaces inline onsubmit).
    document.querySelectorAll('form[data-confirm]').forEach(f => {
        f.addEventListener('submit', function (e) {
            if (!confirm(f.dataset.confirm)) {
                e.preventDefault();
            }
        });
    });
})();
