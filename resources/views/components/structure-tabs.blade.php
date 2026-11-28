{{-- The Structure area: the organization, the program structure, the economic categories (tabs of one page) and the
     funding sources (a page of its own), shown as one row of tabs. --}}
@props(['current'])

@php
    $tabs = [
        'directorates' => [route('management.entities', ['tab' => 'directorates']), __('Organization'), __('Units and who heads them')],
        'programs' => [route('management.entities', ['tab' => 'programs']), __('Program structure'), __('Programs, outcomes, subprograms and outputs')],
        'classifications' => [route('management.entities', ['tab' => 'classifications']), __('Economic categories'), __('The categories of a budget line')],
        'funding' => [route('management.funding-sources'), __('Funding sources'), __('Where the money of a budget line comes from')],
    ];
@endphp
<nav aria-label="{{ __('Structure') }}" class="-mx-4 flex gap-1 overflow-x-auto whitespace-nowrap border-b border-zinc-200 px-4 sm:mx-0 sm:px-0">
    @foreach($tabs as $key => [$url, $label, $hint])
        <a href="{{ $url }}" wire:navigate title="{{ $hint }}" @if($current === $key) aria-current="page" @endif
            class="-mb-px border-b-2 px-4 py-2.5 text-sm font-medium {{ $current === $key ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-zinc-600 hover:border-zinc-300 hover:text-zinc-900' }}">{{ $label }}</a>
    @endforeach
</nav>
