// public/admin/js/schema-health.js
//
// "Copy SQL" buttons on the schema-health page. Each button carries
// data-copy-target = id of the <pre> whose text to copy. Delegated click
// listener so it works regardless of how many rows render. Vanilla JS.
(function () {
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-action="copy-sql"]');
        if (!btn) return;
        const target = document.getElementById(btn.dataset.copyTarget);
        const text = target ? (target.innerText || '') : '';
        if (!text) return;
        navigator.clipboard.writeText(text).then(function () {
            const old = btn.textContent;
            btn.textContent = 'Copied';
            setTimeout(function () { btn.textContent = old; }, 1200);
        });
    });
})();
