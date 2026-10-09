/**
 * Admin panel behaviour.
 *
 * This file exists because the admin pages send a strict Content-Security-
 * Policy with script-src 'self'. That deliberately forbids inline handlers
 * such as onchange="..." and onsubmit="return confirm(...)" — the browser
 * drops them silently, with no visible error. Anything interactive therefore
 * has to be wired up from a served file like this one.
 *
 * Every behaviour here degrades safely if this file fails to load: the agent
 * switcher falls back to its own visible button, and a form that cannot ask
 * for confirmation is simply a form that submits.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        // --- Agent switcher -------------------------------------------
        // Submitting on change is a convenience. The form keeps a real
        // submit button for when this script is unavailable, so the button
        // is only hidden once we know we can do the job ourselves.
        var pickers = document.querySelectorAll('select[data-autosubmit]');
        Array.prototype.forEach.call(pickers, function (select) {
            var form = select.form;
            if (!form) {
                return;
            }

            var fallback = form.querySelector('[data-autosubmit-fallback]');
            if (fallback) {
                fallback.hidden = true;
            }

            select.addEventListener('change', function () {
                form.submit();
            });
        });

        // --- Confirmation before something destructive ----------------
        var guarded = document.querySelectorAll('form[data-confirm]');
        Array.prototype.forEach.call(guarded, function (form) {
            form.addEventListener('submit', function (event) {
                var question = form.getAttribute('data-confirm') || 'Are you sure?';
                if (!window.confirm(question)) {
                    event.preventDefault();
                }
            });
        });
    });
})();
