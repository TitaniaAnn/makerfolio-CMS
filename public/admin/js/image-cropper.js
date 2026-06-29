// public/admin/js/image-cropper.js
//
// Tiny dependency-free interactive image cropper used by the pottery + product
// galleries. window.ImageCropper.open({...}) builds a modal showing the full
// original image with a draggable / resizable crop box (optional aspect lock),
// then POSTs the crop rect (converted to NATURAL source pixels) to the given
// endpoint. No build step, no vendored library — vanilla JS, mouse + touch.
//
// open({
//   imageUrl,    // URL of the ORIGINAL (full-res) image to crop
//   fallbackUrl, // optional thumb URL to fall back to if the original won't load
//   saveUrl,     // endpoint that runs ImageCropHandler::crop
//   csrf,        // CSRF token
//   params,      // extra POST fields, e.g. { img_id, piece_id }
//   onSaved      // fn(data) called after a successful crop; data has image_url, full_url
// })
(function () {
    'use strict';

    function open(opts) {
        var overlay = document.createElement('div');
        overlay.className = 'cropper-overlay';
        overlay.innerHTML =
            '<div class="cropper-modal" role="dialog" aria-modal="true">' +
                '<div class="cropper-stage">' +
                    '<img class="cropper-img" alt="Image to crop">' +
                    '<div class="cropper-box" hidden>' +
                        '<i class="cropper-h cropper-h--nw" data-h="nw"></i>' +
                        '<i class="cropper-h cropper-h--ne" data-h="ne"></i>' +
                        '<i class="cropper-h cropper-h--sw" data-h="sw"></i>' +
                        '<i class="cropper-h cropper-h--se" data-h="se"></i>' +
                    '</div>' +
                '</div>' +
                '<div class="cropper-bar">' +
                    '<label class="cropper-aspect-label">Aspect ' +
                        '<select class="cropper-aspect">' +
                            '<option value="0">Free</option>' +
                            '<option value="1">Square</option>' +
                            '<option value="1.33333">4:3</option>' +
                            '<option value="0.75">3:4</option>' +
                            '<option value="1.77778">16:9</option>' +
                        '</select>' +
                    '</label>' +
                    '<span class="cropper-spacer"></span>' +
                    '<button type="button" class="admin-btn cropper-cancel">Cancel</button>' +
                    '<button type="button" class="admin-btn admin-btn--primary cropper-save">Save crop</button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(overlay);

        var img = overlay.querySelector('.cropper-img');
        var stage = overlay.querySelector('.cropper-stage');
        var box = overlay.querySelector('.cropper-box');
        var aspectSel = overlay.querySelector('.cropper-aspect');
        var aspect = 0;                 // 0 = free
        var b = { x: 0, y: 0, w: 0, h: 0 };  // displayed px, relative to stage
        var MIN = 24;

        function imgRect() {
            var ir = img.getBoundingClientRect(), sr = stage.getBoundingClientRect();
            return { left: ir.left - sr.left, top: ir.top - sr.top, w: ir.width, h: ir.height };
        }
        function render() {
            box.style.left = b.x + 'px'; box.style.top = b.y + 'px';
            box.style.width = b.w + 'px'; box.style.height = b.h + 'px';
        }
        function clampWithin() {
            var r = imgRect();
            b.w = Math.min(b.w, r.w); b.h = Math.min(b.h, r.h);
            b.x = Math.max(r.left, Math.min(b.x, r.left + r.w - b.w));
            b.y = Math.max(r.top, Math.min(b.y, r.top + r.h - b.h));
        }
        function initBox() {
            var r = imgRect();
            b.w = r.w * 0.8;
            b.h = aspect > 0 ? b.w / aspect : r.h * 0.8;
            if (b.h > r.h * 0.95) { b.h = r.h * 0.8; if (aspect > 0) b.w = b.h * aspect; }
            b.x = r.left + (r.w - b.w) / 2;
            b.y = r.top + (r.h - b.h) / 2;
            clampWithin(); render(); box.hidden = false;
        }

        // If the full-res original won't load, fall back to the thumbnail that's
        // already showing in the gallery — coordinates are mapped onto whatever
        // image is displayed via its naturalWidth/Height, so cropping stays
        // correct whichever resolution is shown.
        var triedFallback = false;
        img.addEventListener('load', initBox);
        img.addEventListener('error', function () {
            if (!triedFallback && opts.fallbackUrl && opts.fallbackUrl !== opts.imageUrl) {
                triedFallback = true;
                img.src = opts.fallbackUrl;
            }
        });
        img.src = opts.imageUrl;

        aspectSel.addEventListener('change', function () {
            aspect = parseFloat(aspectSel.value) || 0;
            if (aspect > 0) { b.h = b.w / aspect; clampWithin(); render(); }
        });

        // --- drag / resize ---
        var drag = null;
        function pt(e) { return e.touches && e.touches[0] ? e.touches[0] : e; }
        function onDown(e) {
            var handle = e.target.closest ? e.target.closest('.cropper-h') : null;
            var onBox = e.target === box;
            if (!handle && !onBox) return;
            e.preventDefault();
            var p = pt(e);
            drag = { mode: handle ? handle.getAttribute('data-h') : 'move', sx: p.clientX, sy: p.clientY, start: { x: b.x, y: b.y, w: b.w, h: b.h } };
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
            document.addEventListener('touchmove', onMove, { passive: false });
            document.addEventListener('touchend', onUp);
        }
        function onMove(e) {
            if (!drag) return;
            e.preventDefault();
            var p = pt(e), dx = p.clientX - drag.sx, dy = p.clientY - drag.sy, s = drag.start, r = imgRect();
            if (drag.mode === 'move') {
                b.x = s.x + dx; b.y = s.y + dy; clampWithin(); render(); return;
            }
            var east = drag.mode.indexOf('e') >= 0, south = drag.mode.indexOf('s') >= 0;
            var nx = s.x, ny = s.y, nw = s.w, nh = s.h;
            if (east) { nw = s.w + dx; } else { nw = s.w - dx; nx = s.x + dx; }
            if (south) { nh = s.h + dy; } else { nh = s.h - dy; ny = s.y + dy; }
            if (aspect > 0) {
                nh = nw / aspect;
                if (!south) { ny = s.y + (s.h - nh); }
            }
            if (nw < MIN || nh < MIN) return;
            if (nx < r.left - 0.5 || ny < r.top - 0.5) return;
            if (nx + nw > r.left + r.w + 0.5 || ny + nh > r.top + r.h + 0.5) return;
            b.x = nx; b.y = ny; b.w = nw; b.h = nh; render();
        }
        function onUp() {
            drag = null;
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
            document.removeEventListener('touchmove', onMove);
            document.removeEventListener('touchend', onUp);
        }
        box.addEventListener('mousedown', onDown);
        box.addEventListener('touchstart', onDown, { passive: false });

        // --- close / save ---
        function close() {
            overlay.remove();
            document.removeEventListener('keydown', onEsc);
        }
        function onEsc(e) { if (e.key === 'Escape') close(); }
        document.addEventListener('keydown', onEsc);
        overlay.querySelector('.cropper-cancel').addEventListener('click', close);
        overlay.addEventListener('mousedown', function (e) { if (e.target === overlay) close(); });

        overlay.querySelector('.cropper-save').addEventListener('click', function () {
            var r = imgRect();
            if (b.w < 2 || b.h < 2 || !r.w || !r.h) return;
            // Map the displayed crop box onto the loaded image's NATURAL pixels.
            // The server crops the original at these pixel coords (and clamps).
            var natW = img.naturalWidth  || r.w;
            var natH = img.naturalHeight || r.h;
            var sx = (natW / r.w), sy = (natH / r.h);
            var px = Math.round((b.x - r.left) * sx);
            var py = Math.round((b.y - r.top) * sy);
            var pw = Math.round(b.w * sx);
            var ph = Math.round(b.h * sy);

            var body = new URLSearchParams();
            var params = opts.params || {};
            Object.keys(params).forEach(function (k) { body.append(k, params[k]); });
            body.append('x', px);
            body.append('y', py);
            body.append('w', pw);
            body.append('h', ph);
            body.append('csrf', opts.csrf);

            var saveBtn = this; saveBtn.disabled = true;
            fetch(opts.saveUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data && data.success && data.image_url) {
                        if (opts.onSaved) opts.onSaved(data);
                        close();
                    } else {
                        alert((data && data.error) || 'Crop failed');
                        saveBtn.disabled = false;
                    }
                })
                .catch(function () { alert('Crop failed'); saveBtn.disabled = false; });
        });
    }

    window.ImageCropper = { open: open };
})();
