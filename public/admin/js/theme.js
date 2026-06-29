// public/admin/js/theme.js
//
// Theme settings page: active-preset highlight, the color-picker ↔ hex-input
// sync, the per-row "Reset" button, and the live iframe preview.
// Converted from inline handlers/script to delegated listeners for CSP.
//
// PHP values reach JS via data-* attributes:
//   - color picker target ← data-color-target (id of the paired hex input)
//   - reset row           ← data-action="reset-color" + data-role + data-resolved
(function () {
    'use strict';

    // Highlight the active preset card as the user clicks.
    document.querySelectorAll('.theme-preset').forEach(card => {
        card.addEventListener('click', () => {
            document.querySelectorAll('.theme-preset').forEach(c => c.classList.remove('is-active'));
            card.classList.add('is-active');
        });
    });

    // Color picker → hex text input sync (replaces inline oninput).
    document.addEventListener('input', function (e) {
        const picker = e.target.closest('input[type="color"][data-color-target]');
        if (!picker) return;
        const target = document.getElementById(picker.dataset.colorTarget);
        if (target) target.value = picker.value.toUpperCase();
    });

    // Per-row reset button (replaces inline onclick).
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-action="reset-color"]');
        if (!btn) return;
        const role = btn.dataset.role;
        const resolved = btn.dataset.resolved;
        const hexInput = document.getElementById('override_' + role);
        // The color picker is the sibling <input type="color"> in the same row.
        const row = btn.closest('.theme-color-row');
        const picker = row ? row.querySelector('input[type="color"]') : null;
        if (hexInput) hexInput.value = '';
        if (picker) picker.value = resolved;
    });

    // Live preview — collect form values, encode as base64 JSON, push into the
    // iframe URL. Debounced so rapid input doesn't thrash the iframe.
    const form    = document.getElementById('theme-form');
    const frame   = document.getElementById('theme-preview-frame');
    let debounce  = null;
    function rebuildPreview() {
        const preset = form.querySelector('input[name="theme_preset"]:checked');
        const radius = form.querySelector('input[name="theme_radius_scale"]:checked');
        const shadow = form.querySelector('input[name="theme_shadow_scale"]:checked');
        const blob = {
            preset: preset ? preset.value : null,
            overrides: {
                primary:    valOrSkip('override_primary'),
                accent:     valOrSkip('override_accent'),
                background: valOrSkip('override_background'),
                surface:    valOrSkip('override_surface'),
                text:       valOrSkip('override_text'),
                cool:       valOrSkip('override_cool'),
            },
            fonts: {
                display: form.querySelector('[name="theme_font_display"]').value,
                body:    form.querySelector('[name="theme_font_body"]').value,
                eyebrow: form.querySelector('[name="theme_font_eyebrow"]').value,
            },
            radius: radius ? radius.value : undefined,
            shadow: shadow ? shadow.value : undefined,
        };
        const encoded = btoa(JSON.stringify(blob));
        frame.src = '/?_theme_preview=' + encodeURIComponent(encoded);
    }
    function valOrSkip(name) {
        const el = form.querySelector('input[name="' + name + '"]');
        const v  = el ? el.value.trim() : '';
        return v === '' ? undefined : v;
    }
    function schedulePreview() {
        clearTimeout(debounce);
        debounce = setTimeout(rebuildPreview, 250);
    }
    form.addEventListener('input',  schedulePreview);
    form.addEventListener('change', schedulePreview);
    // Initial render so the iframe shows the current edited state, not the persisted one.
    schedulePreview();
})();
