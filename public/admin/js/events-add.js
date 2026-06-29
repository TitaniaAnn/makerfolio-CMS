// public/admin/js/events-add.js
//
// Add/edit event page: show/hide the type-specific field groups (sales / class)
// based on the selected event type, and toggle the per-piece check badge.
// Converted from an inline script to an external file for CSP compliance.
//
// PHP value reaches JS via #eventForm[data-is-edit].
(function () {
    'use strict';

    const form = document.getElementById('eventForm');
    if (!form) return;
    const IS_EDIT = form.dataset.isEdit === 'true';

    const salesFields = document.getElementById('salesFields');
    const classFields = document.getElementById('classFields');

    function applyEventType(type) {
        salesFields.classList.toggle('active', ['pottery_sale', 'storefront_sale'].includes(type));
        classFields.classList.toggle('active', type === 'class');
    }

    if (!IS_EDIT) {
        const eventTypeSelect = document.getElementById('eventType');
        eventTypeSelect.addEventListener('change', () => applyEventType(eventTypeSelect.value));
        applyEventType(eventTypeSelect.value);
    }

    document.querySelectorAll('.piece-item input[type="checkbox"]').forEach(checkbox => {
        const check = checkbox.parentElement.querySelector('.piece-item__check');
        if (checkbox.checked) check.style.display = 'flex';
        checkbox.addEventListener('change', function () {
            check.style.display = this.checked ? 'flex' : 'none';
        });
    });
})();
