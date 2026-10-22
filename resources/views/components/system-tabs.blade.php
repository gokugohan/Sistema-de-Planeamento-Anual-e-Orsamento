@php
    // The administrator's setup area: three pages that read as one. The menu has a single entry for them.
    $tabs = [
        ['management.settings', __('Settings'), __('Identity, logo, approval rules, e-mail and testing')],
        ['management.optional-rules', __('Optional rules'), __('Rules of the planning cycle to switch on')],
        ['management.reporting-windows', __('Calendar'), __('Dates for each step, and more time for a late unit')],
    ];
@endphp
<nav aria-label="{{ __('System') }}" class="flex flex-wrap gap-1 border-b border-zinc-200">
    @foreach($tabs as [$route, $label, $hint])
        @php $active = request()->routeIs($route); @endphp
        <a href="{{ route($route) }}" wire:navigate title="{{ $hint }}" @if($active) aria-current="page" @endif
            class="-mb-px border-b-2 px-4 py-2.5 text-sm font-medium {{ $active ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-zinc-600 hover:border-zinc-300 hover:text-zinc-900' }}">{{ $label }}</a>
    @endforeach
</nav>
