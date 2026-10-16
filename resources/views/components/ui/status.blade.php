{{-- The status of an activity as a chip: a dot and a word, never colour alone. --}}
@props(['status'])

@php
    $styles = [
        'Draft'            => ['bg-zinc-100 text-zinc-700', 'bg-zinc-400'],
        'Rejected'         => ['bg-rose-50 text-rose-800', 'bg-rose-500'],
        'Submitted'        => ['bg-sky-50 text-sky-800', 'bg-sky-500'],
        'Reviewed'         => ['bg-indigo-50 text-indigo-800', 'bg-indigo-400'],
        'Validated'        => ['bg-indigo-50 text-indigo-800', 'bg-indigo-600'],
        'Waiting Approval' => ['bg-amber-50 text-amber-800', 'bg-amber-500'],
        'Approved'         => ['bg-emerald-50 text-emerald-800', 'bg-emerald-500'],
    ];
    [$tone, $dot] = $styles[$status] ?? $styles['Draft'];
    // the stages as the Prepare - Verify/Certify - Approve flow names them
    $word = ['Submitted' => 'Prepared', 'Reviewed' => 'Verified', 'Validated' => 'Certified'][$status] ?? $status;
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-medium {$tone}"]) }}>
    <span class="h-1.5 w-1.5 rounded-full {{ $dot }}" aria-hidden="true"></span>
    {{ __($word) }}
</span>
