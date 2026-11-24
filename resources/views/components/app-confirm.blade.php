{{--
    The app's own confirmation, in place of the browser's confirm() for every wire:confirm in the application. A click (or a
    form submit) on an element with wire:confirm is held back, this dialog asks the same question, and the action goes
    ahead only on confirming. The confirm button carries the label of the button that was clicked ("Approve the plan",
    "Delete"...), in red for an action that deletes, removes or purges. The server still checks every action itself.
--}}
<div x-data="{ message: '', label: '', danger: false, ok: null }"
     x-on:app-confirm.window="message = $event.detail.message; label = $event.detail.label; danger = $event.detail.danger; ok = $event.detail.ok; $dispatch('open-modal', 'app-confirm')">
    <x-modal name="app-confirm" max-width="md" focusable>
        <div class="p-6" role="alertdialog" aria-labelledby="app-confirm-message">
            <p id="app-confirm-message" class="text-sm font-medium leading-relaxed text-zinc-900" x-text="message"></p>
            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button variant="secondary" x-on:click="ok = null; $dispatch('close-modal', 'app-confirm')">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button x-show="! danger" x-on:click="$dispatch('close-modal', 'app-confirm'); const go = ok; ok = null; go && go()"><span x-text="label"></span></x-ui.button>
                <x-ui.button variant="danger" x-show="danger" x-cloak x-on:click="$dispatch('close-modal', 'app-confirm'); const go = ok; ok = null; go && go()"><span x-text="label"></span></x-ui.button>
            </div>
        </div>
    </x-modal>
</div>

<script>
    (() => {
        // once per page load: wire:navigate re-runs this script with each page
        if (window.__appConfirm) return;
        window.__appConfirm = true;
        const fallback = @js(__('Confirm'));

        const ask = (el, go) => {
            let label = (el.innerText || '').trim().replace(/\s+/g, ' ');
            if (! label || label.length > 40) label = fallback;
            const danger = el.hasAttribute('data-confirm-danger') || /delete|destroy|remove|purge|withdraw/i.test(el.getAttribute('wire:click') || '');
            window.dispatchEvent(new CustomEvent('app-confirm', { detail: { message: el.getAttribute('wire:confirm'), label, danger, ok: go } }));
        };
        // the action is sent again, with Livewire's own confirm() answered yes for that one moment
        const through = (fn) => {
            const original = window.confirm;
            window.confirm = () => true;
            try { fn(); } finally { window.confirm = original; }
        };

        document.addEventListener('click', (e) => {
            const el = e.target.closest('[wire\\:confirm]');
            if (! el || el.tagName === 'FORM' || el.__appConfirmed) return;
            e.preventDefault();
            e.stopImmediatePropagation();
            ask(el, () => { el.__appConfirmed = true; through(() => el.click()); el.__appConfirmed = false; });
        }, true);

        document.addEventListener('submit', (e) => {
            const form = e.target.closest('form[wire\\:confirm]');
            if (! form || form.__appConfirmed) return;
            e.preventDefault();
            e.stopImmediatePropagation();
            ask(form, () => { form.__appConfirmed = true; through(() => form.requestSubmit()); form.__appConfirmed = false; });
        }, true);
    })();
</script>
