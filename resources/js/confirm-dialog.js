// Every `wire:confirm` in the app used the browser's own confirm() — a plain
// white box at the top of the screen. Livewire sets el.__livewire_confirm in
// its built-in "confirm" directive (re-registering the directive is ignored),
// so this hooks in right after it, per element, and swaps the native box for
// the app's own dialog. The message is still whatever wire:confirm="…" says.

const DESTRUCTIVE = /למחוק|מחיקה|לבטל|ביטול|להסיר|הסרה|להשבית/;

let open = null;

function confirmDialog(message) {
    open?.cancel();

    return new Promise((resolve) => {
        const destructive = DESTRUCTIVE.test(message);
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay confirm-overlay';
        overlay.innerHTML = `
            <div class="modal-panel confirm-panel" role="alertdialog" aria-modal="true" aria-labelledby="confirm-dialog-message">
                <div class="confirm-icon ${destructive ? 'is-destructive' : ''}" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12.5"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                </div>
                <p class="confirm-message" id="confirm-dialog-message"></p>
                <div class="confirm-actions">
                    <button type="button" class="btn ${destructive ? 'btn-destructive' : 'btn-primary'}" data-confirm>אישור</button>
                    <button type="button" class="btn btn-ghost" data-cancel>ביטול</button>
                </div>
            </div>`;
        overlay.querySelector('.confirm-message').textContent = message;

        const previousFocus = document.activeElement;
        const finish = (result) => {
            document.removeEventListener('keydown', onKey, true);
            overlay.classList.add('is-leaving');
            setTimeout(() => overlay.remove(), 120);
            previousFocus?.focus?.({ preventScroll: true });
            open = null;
            resolve(result);
        };
        const onKey = (e) => {
            if (e.key === 'Escape') { e.preventDefault(); finish(false); }
            if (e.key === 'Tab') {
                // Keep focus inside the dialog.
                const buttons = [...overlay.querySelectorAll('button')];
                const i = buttons.indexOf(document.activeElement);
                e.preventDefault();
                buttons[(i + (e.shiftKey ? -1 : 1) + buttons.length) % buttons.length].focus();
            }
        };

        overlay.addEventListener('click', (e) => { if (e.target === overlay) finish(false); });
        overlay.querySelector('[data-confirm]').addEventListener('click', () => finish(true));
        overlay.querySelector('[data-cancel]').addEventListener('click', () => finish(false));
        document.addEventListener('keydown', onKey, true);

        document.body.appendChild(overlay);
        overlay.querySelector('[data-confirm]').focus();
        open = { cancel: () => finish(false) };
    });
}

window.confirmDialog = confirmDialog;

document.addEventListener('livewire:init', () => {
    window.Livewire.hook('directive.init', ({ el, directive }) => {
        if (directive.value !== 'confirm' || directive.modifiers.includes('prompt')) {
            return;
        }

        const message = directive.expression.replaceAll('\\n', '\n') || 'להמשיך?';

        el.__livewire_confirm = (action, instead) => {
            confirmDialog(message).then((ok) => (ok ? action() : instead()));
        };
    });
});
