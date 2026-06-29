// public/admin/js/announcements-index.js
//
// Delete-announcement buttons on the announcements list. Each delete control
// carries data-action="delete-announcement" + data-id. CSRF token comes from
// the page-global <meta name="csrf-token"> (emitted by the admin topbar).
// Delegated click listener, vanilla JS.
(function () {
    function csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }

    document.addEventListener('click', function (e) {
        const el = e.target.closest('[data-action="delete-announcement"]');
        if (!el) return;
        e.preventDefault();
        const id = el.dataset.id;
        if (confirm('Are you sure you want to delete this announcement?')) {
            window.location.href = '/admin/announcements/delete?id=' + id + '&csrf=' + encodeURIComponent(csrfToken());
        }
    });
})();
