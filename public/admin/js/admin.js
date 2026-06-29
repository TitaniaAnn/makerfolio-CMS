// Admin JS

// Mobile sidebar toggle
const adminBurger = document.getElementById('adminBurger');
const adminSidebar = document.querySelector('.admin-sidebar');
if (adminBurger && adminSidebar) {
    adminBurger.addEventListener('click', () => {
        adminSidebar.classList.toggle('open');
    });
}

// Image preview on file input
const fileInputs = document.querySelectorAll('input[type="file"][accept*="image"]');
fileInputs.forEach(input => {
    const preview = input.id ? document.getElementById(input.id + 'Preview') : null;
    if (!preview) return;
    input.addEventListener('change', () => {
        const file = input.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = e => { preview.src = e.target.result; preview.style.display = 'block'; };
            reader.readAsDataURL(file);
        }
    });
});

// Flash auto-dismiss
const flash = document.querySelector('.flash');
if (flash) setTimeout(() => flash.style.opacity = '0', 3500);

// Hide broken thumbnails (CSP-safe replacement for inline onerror). Any
// <img data-hide-on-error> is hidden if its src fails to load. The error
// event doesn't bubble, so bind per-element; some may have already errored
// before this runs, so check .complete + naturalWidth too.
document.querySelectorAll('img[data-hide-on-error]').forEach((img) => {
    const hide = () => { img.style.display = 'none'; };
    img.addEventListener('error', hide);
    if (img.complete && img.naturalWidth === 0) hide();
});

// Generic confirm-before-action (CSP-safe replacement for inline onclick/onsubmit
// "return confirm(...)"). Any element with data-confirm="<message>" gets a
// confirmation gate; cancelling aborts the click/navigation/submit.
//   - clickable element (link/button): cancel prevents the default action
//   - <form data-confirm>: cancel prevents submission
// Delegated so it covers dynamically-rendered rows too.
document.addEventListener('click', (e) => {
    const el = e.target.closest('[data-confirm]');
    if (!el || el.tagName === 'FORM') return;
    if (!window.confirm(el.dataset.confirm)) {
        e.preventDefault();
        e.stopPropagation();
    }
});
document.addEventListener('submit', (e) => {
    const form = e.target.closest('form[data-confirm]');
    if (!form) return;
    if (!window.confirm(form.dataset.confirm)) {
        e.preventDefault();
    }
});

// Width-from-data: apply a percentage width via CSSOM for elements carrying a
// data-width attribute (e.g. the dashboard onboarding progress bar). JS-applied
// styles are not governed by CSP style-src, so this replaces the inline
// style="width:N%" the bar used to emit.
document.querySelectorAll('[data-width]').forEach((el) => {
    el.style.width = el.dataset.width + '%';
});
