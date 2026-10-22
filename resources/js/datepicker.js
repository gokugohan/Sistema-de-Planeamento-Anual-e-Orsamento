import flatpickr from 'flatpickr';
import { Portuguese } from 'flatpickr/dist/l10n/pt.js';
import 'flatpickr/dist/flatpickr.css';

/**
 * Every <input type="date"> of the application becomes a flatpickr calendar that shows dd/mm/yyyy and keeps the value as
 * yyyy-mm-dd, so that the server and wire:model see exactly what a native date field would give them.
 *
 * Livewire re-renders parts of the page, which can put the plain input back and drop the picker; a MutationObserver puts
 * the picker back (and brings its text up to date with the input) after every change. Nothing in the views needs a
 * special attribute: min and max are read from the input.
 */
const locales = { pt: Portuguese, tet: Portuguese };

function language() {
    return (document.documentElement.lang || 'en').slice(0, 3).replace('-', '').toLowerCase();
}

function localeFor() {
    const l = language();
    const base = locales[l] || locales[l.slice(0, 2)] || null;

    return { ...(base || {}), firstDayOfWeek: 1 };
}

function enhance(input) {
    const ready = input._flatpickr && input._flatpickr.altInput && document.body.contains(input._flatpickr.altInput);
    if (ready) {
        // the value may have been changed by Livewire: show it
        const fp = input._flatpickr;
        const shown = fp.selectedDates[0] ? fp.formatDate(fp.selectedDates[0], 'Y-m-d') : '';
        if ((input.value || '') !== shown) {
            fp.setDate(input.value || null, false);
        }
        fixLabel(input, fp.altInput);

        return;
    }
    if (input._flatpickr) {
        input._flatpickr.destroy();
    }

    const fp = flatpickr(input, {
        altInput: true,
        altFormat: 'd/m/Y',
        dateFormat: 'Y-m-d',
        allowInput: false,
        disableMobile: true,
        locale: localeFor(),
        minDate: input.getAttribute('min') || null,
        maxDate: input.getAttribute('max') || null,
        defaultDate: input.value || null,
        onChange: () => {
            // wire:model listens to "input" on the real field
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        },
    });

    const alt = fp.altInput;
    // Livewire must not morph the picker away when it re-renders the page; the value still follows wire:model
    input.setAttribute('wire:ignore', '');
    alt.setAttribute('wire:ignore', '');
    alt.placeholder = input.getAttribute('placeholder') || 'dd/mm/yyyy';
    ['aria-label', 'aria-describedby', 'aria-invalid'].forEach((a) => input.hasAttribute(a) && alt.setAttribute(a, input.getAttribute(a)));
    if (input.id) {
        alt.id = input.id + '-picker';
    }
    fixLabel(input, alt);
}

/** A label pointed at the hidden field must open the visible one. */
function fixLabel(input, alt) {
    if (!input.id) {
        return;
    }
    document.querySelectorAll(`label[for="${input.id}"]`).forEach((l) => l.setAttribute('for', alt.id));
}

function scan() {
    document.querySelectorAll('input[type="date"], input[data-fp-ready]').forEach((input) => {
        input.dataset.fpReady = '1';
        enhance(input);
    });
}

let queued = false;
function schedule() {
    if (queued) {
        return;
    }
    queued = true;
    requestAnimationFrame(() => {
        queued = false;
        scan();
    });
}

document.addEventListener('DOMContentLoaded', () => {
    scan();
    new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['type', 'value', 'min', 'max'] });
});
document.addEventListener('livewire:navigated', schedule);
// after every answer from the server, show what the field now holds (a form that was reset, a value set by the server)
document.addEventListener('livewire:init', () => {
    window.Livewire.hook('commit', ({ succeed }) => succeed(() => queueMicrotask(scan)));
});
