/**
 * V1 frontend foundation behaviors.
 *
 * Vanilla, framework-free enhancements for the Blade + Bootstrap UI:
 *
 *  1. `data-confirm` forms — destructive-action confirmation. When a form
 *     carries a `data-confirm="…"` attribute, submission first asks for
 *     confirmation; cancelling aborts the submit.
 *  2. `data-loading` labels — any submit button inside a form with a
 *     `data-loading` attribute (or a submit button carrying
 *     `data-loading`) is disabled once when the form is submitted, and its
 *     label is swapped for "…" / loading text to give immediate feedback.
 *  3. Alert auto-dismiss — `alert-dismissible` alerts fade out after 6s.
 *
 * No dependencies; executes on DOMContentLoaded.
 */

(() => {
    const disableSubmitAndShowLoading = (form) => {
        const label = form.dataset.loading ?? 'Please wait…';
        form.querySelectorAll('button[type="submit"]').forEach((button) => {
            button.dataset.originalHtml = button.innerHTML;
            button.disabled = true;
            button.innerHTML = `<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>${label}`;
        });
    };

    const onFormSubmit = (event) => {
        const form = event.currentTarget;
        const confirmed = form.dataset.confirm;

        if (confirmed && !window.confirm(confirmed)) {
            event.preventDefault();
            event.stopPropagation();
            return;
        }

        if (form.dataset.loading !== undefined) {
            disableSubmitAndShowLoading(form);
        }
    };

    const init = () => {
        document.querySelectorAll('form[data-confirm], form[data-loading]').forEach((form) => {
            form.addEventListener('submit', onFormSubmit);
        });

        // One-time secret copy buttons: copy the text of the referenced
        // element and confirm audibly/visually without altering the DOM text.
        document.querySelectorAll('button[data-copy-target]').forEach((button) => {
            button.addEventListener('click', () => {
                const target = document.querySelector(button.dataset.copyTarget);

                if (target === null) {
                    return;
                }

                const finish = () => {
                    button.dataset.originalLabel = button.dataset.originalLabel ?? button.textContent;
                    button.textContent = 'Copied!';
                    window.setTimeout(() => {
                        button.textContent = button.dataset.originalLabel;
                    }, 2000);
                };

                if (navigator.clipboard !== undefined && window.isSecureContext) {
                    navigator.clipboard.writeText(target.textContent.trim()).then(finish).catch(finish);
                } else {
                    finish();
                }
            });
        });

        document.querySelectorAll('.alert-dismissible').forEach((alert) => {
            window.setTimeout(() => {
                alert.classList.remove('show');
                alert.classList.add('fade');
            }, 6000);
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
