/**
 * Show/hide toggle for password fields.
 *
 * Mark any password input with data-reveal and wrap it in .ri-pwfield; this
 * adds the eye button and wires it up. Typing a password you cannot see is how
 * people end up locked out of an account whose password was never wrong, and it
 * is worse on a phone keyboard or a temporary password full of punctuation.
 *
 * The field returns to hidden when the form is submitted, so a revealed password
 * is never left on screen after somebody walks away.
 */
(function () {
    'use strict';

    var EYE_OPEN = 'ti-eye';
    var EYE_SHUT = 'ti-na';

    function attach(input) {
        var wrapper = input.closest('.ri-pwfield');
        if (!wrapper || wrapper.querySelector('.ri-pwtoggle')) {
            return;
        }

        var button = document.createElement('button');
        button.type = 'button';                   // never submits the form
        button.className = 'ri-pwtoggle';
        button.setAttribute('aria-label', 'Show password');
        button.setAttribute('aria-pressed', 'false');
        button.innerHTML = '<i class="' + EYE_OPEN + '" aria-hidden="true"></i>';

        button.addEventListener('click', function () {
            var revealed = input.type === 'text';
            input.type = revealed ? 'password' : 'text';
            button.setAttribute('aria-pressed', revealed ? 'false' : 'true');
            button.setAttribute('aria-label', revealed ? 'Show password' : 'Hide password');
            button.querySelector('i').className = revealed ? EYE_OPEN : EYE_SHUT;
            // Keep the caret where it was rather than jumping to the start.
            var position = input.value.length;
            input.focus();
            try { input.setSelectionRange(position, position); } catch (e) { /* not all types allow it */ }
        });

        wrapper.appendChild(button);

        var form = input.form;
        if (form) {
            form.addEventListener('submit', function () {
                input.type = 'password';
            });
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        var fields = document.querySelectorAll('input[type="password"][data-reveal]');
        Array.prototype.forEach.call(fields, attach);
    });
})();
