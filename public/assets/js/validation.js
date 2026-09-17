/* ============================================================
   iMplement ERP — Client-side validation (validation.js)
   ------------------------------------------------------------
   Progressive enhancement over server-side validation (which
   remains authoritative). Attach to a form with `data-validate`.

   Supported per-field hooks:
     required             native attribute
     type="email"         format check
     minlength            native attribute
     data-match="otherId" must equal another field's value
   ============================================================ */
(function () {
    'use strict';

    function fieldError(field, message) {
        const group = field.closest('.form-group') || field.parentElement;
        group.classList.add('is-error');
        let err = group.querySelector('.field-err.js-err');
        if (!err) {
            err = document.createElement('span');
            err.className = 'field-err js-err';
            group.appendChild(err);
        }
        err.textContent = message;
    }

    function clearError(field) {
        const group = field.closest('.form-group') || field.parentElement;
        group.classList.remove('is-error');
        group.querySelector('.field-err.js-err')?.remove();
    }

    function validateField(field) {
        clearError(field);
        const value = (field.value || '').trim();

        if (field.hasAttribute('required') && value === '') {
            fieldError(field, 'This field is required.');
            return false;
        }
        if (value === '') return true; // optional & empty → ok

        if (field.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
            fieldError(field, 'Enter a valid email address.');
            return false;
        }
        const min = parseInt(field.getAttribute('minlength'), 10);
        if (min && value.length < min) {
            fieldError(field, 'Must be at least ' + min + ' characters.');
            return false;
        }
        const matchId = field.getAttribute('data-match');
        if (matchId) {
            const other = document.getElementById(matchId);
            if (other && value !== other.value) {
                fieldError(field, 'Values do not match.');
                return false;
            }
        }
        return true;
    }

    function initForm(form) {
        const fields = form.querySelectorAll('input, select, textarea');

        fields.forEach((field) => {
            field.addEventListener('blur', () => validateField(field));
            field.addEventListener('input', () => {
                if ((field.closest('.form-group') || field).classList?.contains('is-error')) {
                    validateField(field);
                }
            });
        });

        form.addEventListener('submit', (e) => {
            let ok = true;
            fields.forEach((field) => { if (!validateField(field)) ok = false; });
            if (!ok) {
                e.preventDefault();
                const firstErr = form.querySelector('.form-group.is-error input, .form-group.is-error select, .form-group.is-error textarea');
                firstErr?.focus();
                if (window.App) App.notify('Please fix the highlighted fields.', 'error');
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('form[data-validate]').forEach(initForm);
    });
})();
