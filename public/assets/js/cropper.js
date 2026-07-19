/**
 * Uploader immagini con ritaglio — vanilla JS, nessuna dipendenza. Gestisce avatar (quadrato) e
 * copertina (widescreen): ogni root `.media-uploader` porta la propria config nei data-*.
 * Il ritaglio è lato client (pan + zoom su canvas); il server ri-codifica comunque l'immagine.
 */
(function () {
    'use strict';
    document.querySelectorAll('.media-uploader').forEach(init);

    function init(root) {
        var UPLOAD = root.dataset.uploadUrl;
        var DELETE = root.dataset.deleteUrl;
        var CSRF = root.dataset.csrf;
        var ASPECT = parseFloat(root.dataset.aspect || '1');   // larghezza/altezza
        var OUTW = parseInt(root.dataset.outW || '512', 10);
        var OUTH = parseInt(root.dataset.outH || '512', 10);
        var ROUND = root.dataset.round === '1';
        var T = {};
        try { T = JSON.parse(root.dataset.i18n || '{}'); } catch (e) { T = {}; }

        var fileInput = root.querySelector('.media-file');
        var pickBtn = root.querySelector('.media-pick');
        var removeBtn = root.querySelector('.media-remove');
        var preview = root.querySelector('.media-preview');

        if (pickBtn) pickBtn.addEventListener('click', function () { fileInput.click(); });
        if (fileInput) fileInput.addEventListener('change', onFile);
        if (removeBtn) removeBtn.addEventListener('click', onRemove);

        function onFile() {
            var f = fileInput.files && fileInput.files[0];
            if (!f) return;
            var img = new Image();
            img.onload = function () { openCropper(img); };
            img.onerror = function () { alert(T.error || 'Errore'); };
            img.src = URL.createObjectURL(f);
            fileInput.value = '';
        }

        function onRemove() {
            removeBtn.disabled = true;
            post(DELETE, null).then(renderEmpty).catch(function () { alert(T.error || 'Errore'); })
                .finally(function () { removeBtn.disabled = false; });
        }

        function openCropper(img) {
            var overlay = el('div', 'crop-overlay');
            var modal = el('div', 'crop-modal' + (ROUND ? '' : ' crop-modal-wide'));
            var head = el('div', 'crop-head');
            head.innerHTML = '<h2>' + esc(T.title || 'Ritaglia') + '</h2><p class="muted">' + esc(T.hint || '') + '</p>';

            var stage = el('div', 'crop-stage' + (ROUND ? ' crop-round' : ''));
            var canvas = document.createElement('canvas');
            stage.appendChild(canvas);

            var zoom = document.createElement('input');
            zoom.type = 'range'; zoom.className = 'crop-zoom'; zoom.min = '1'; zoom.max = '3'; zoom.step = '0.01'; zoom.value = '1';

            var actions = el('div', 'crop-actions');
            var cancel = btn('btn btn-ghost', T.cancel || 'Annulla');
            var confirm = btn('btn btn-primary', T.confirm || 'Salva');
            actions.appendChild(cancel); actions.appendChild(confirm);

            modal.appendChild(head); modal.appendChild(stage); modal.appendChild(zoom); modal.appendChild(actions);
            overlay.appendChild(modal); document.body.appendChild(overlay);
            document.body.style.overflow = 'hidden';

            var SW = Math.round(stage.getBoundingClientRect().width) || 320;
            var SH = Math.round(SW / ASPECT);
            stage.style.height = SH + 'px';
            canvas.width = SW; canvas.height = SH;
            var ctx = canvas.getContext('2d');

            var nw = img.naturalWidth, nh = img.naturalHeight;
            var minScale = Math.max(SW / nw, SH / nh);
            var scale = minScale;
            var ox = (SW - nw * scale) / 2, oy = (SH - nh * scale) / 2;

            function clamp() {
                ox = Math.min(0, Math.max(SW - nw * scale, ox));
                oy = Math.min(0, Math.max(SH - nh * scale, oy));
            }
            function draw() { clamp(); ctx.clearRect(0, 0, SW, SH); ctx.drawImage(img, ox, oy, nw * scale, nh * scale); }
            draw();

            zoom.addEventListener('input', function () {
                var ns = minScale * parseFloat(zoom.value);
                var cx = SW / 2, cy = SH / 2;
                ox = cx - (cx - ox) * (ns / scale);
                oy = cy - (cy - oy) * (ns / scale);
                scale = ns; draw();
            });

            var dragging = false, lx = 0, ly = 0;
            canvas.addEventListener('pointerdown', function (e) { dragging = true; lx = e.clientX; ly = e.clientY; canvas.setPointerCapture(e.pointerId); });
            canvas.addEventListener('pointermove', function (e) { if (!dragging) return; ox += e.clientX - lx; oy += e.clientY - ly; lx = e.clientX; ly = e.clientY; draw(); });
            canvas.addEventListener('pointerup', function () { dragging = false; });
            canvas.addEventListener('pointercancel', function () { dragging = false; });

            function close() { document.body.removeChild(overlay); document.body.style.overflow = ''; }
            cancel.addEventListener('click', close);
            overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });

            confirm.addEventListener('click', function () {
                confirm.disabled = true; confirm.classList.add('is-loading');
                var sx = (0 - ox) / scale, sy = (0 - oy) / scale, sW = SW / scale, sH = SH / scale;
                var out = document.createElement('canvas'); out.width = OUTW; out.height = OUTH;
                out.getContext('2d').drawImage(img, sx, sy, sW, sH, 0, 0, OUTW, OUTH);
                out.toBlob(function (blob) {
                    var fd = new FormData();
                    fd.append('image', blob, 'image.webp');
                    post(UPLOAD, fd).then(function (data) {
                        if (data && data.image_url) renderImage(data.image_url);
                        close();
                    }).catch(function (msg) {
                        confirm.disabled = false; confirm.classList.remove('is-loading');
                        alert(msg || T.error || 'Errore');
                    });
                }, 'image/webp', 0.9);
            });
        }

        function post(urlStr, body) {
            var opts = { method: 'POST', headers: { 'Accept': 'application/json' }, credentials: 'same-origin' };
            var fd = body instanceof FormData ? body : new FormData();
            fd.append('_csrf', CSRF); opts.body = fd;
            return fetch(urlStr, opts).then(function (r) {
                if (r.status === 204) return null;
                return r.json().then(function (j) {
                    if (!r.ok) throw (j && j.errors && j.errors[0] && j.errors[0].title);
                    return j.data;
                });
            });
        }

        function renderImage(src) {
            preview.innerHTML = '';
            var im = document.createElement('img');
            im.src = src; im.alt = ''; im.className = ROUND ? 'avatar-img' : 'cover-img';
            preview.appendChild(im);
            preview.classList.add('has-image');
            if (removeBtn) removeBtn.hidden = false;
            if (pickBtn) pickBtn.textContent = T.change || pickBtn.textContent;
        }

        function renderEmpty() {
            preview.classList.remove('has-image');
            if (ROUND) {
                preview.innerHTML = '<span class="avatar-initials">' + esc(preview.getAttribute('data-initials') || '') + '</span>';
            } else {
                preview.innerHTML = '<span class="cover-placeholder"><i class="fa-solid fa-panorama" aria-hidden="true"></i>' + esc(T.empty || '') + '</span>';
            }
            if (removeBtn) removeBtn.hidden = true;
            if (pickBtn) pickBtn.textContent = T.upload || pickBtn.textContent;
        }

        function el(tag, cls) { var e = document.createElement(tag); e.className = cls; return e; }
        function btn(cls, label) { var b = document.createElement('button'); b.type = 'button'; b.className = cls; b.textContent = label; return b; }
        function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
    }
})();
