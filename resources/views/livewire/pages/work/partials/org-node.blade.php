{{-- One box of the organization chart, then the units below it. The first level hangs from the row bar; deeper levels
     hang in a column under their parent. A branch with children can be folded. --}}
@php
    $kids = $children->get($n['id'], collect());
    [$tone, $word] = $light[$n['light']] ?? $light['none'];
@endphp
<div class="flex flex-col items-start" x-data="{ open: {{ ($startOpen ?? true) ? 'true' : 'false' }} }" @if($kids->isNotEmpty()) data-oc-branch x-on:oc-set="open = $event.detail" @endif>
    <a href="{{ route('units.show', $n['id']) }}" wire:navigate
        data-find="{{ mb_strtolower($n['code'].' '.$n['name'].' '.$n['head']) }}"
        class="oc-box {{ $accent($n) }} {{ $n['autonomous'] ? 'oc-dashed' : '' }} block px-3 pb-2.5 pt-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
        <div class="flex items-center justify-between gap-2">
            <span class="truncate text-[10px] font-semibold uppercase tracking-wider text-zinc-500">{{ $typeWord($n) }}</span>
            <span class="shrink-0 rounded bg-zinc-100 px-1.5 py-0.5 font-mono text-[10px] font-semibold text-zinc-700">{{ $n['code'] }}</span>
        </div>
        <div class="mt-1 text-[13px] font-semibold leading-snug text-zinc-900">{{ $n['name'] }}</div>
        <div class="mt-2 flex items-center gap-2 border-t border-zinc-100 pt-2">
            <span class="oc-avatar flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-[11px] font-bold" aria-hidden="true">{{ $n['initials'] !== '' ? mb_strtoupper($n['initials']) : '—' }}</span>
            <span class="min-w-0 leading-tight">
                <span class="block truncate text-[10px] text-zinc-500">{{ $n['title'] }}</span>
                <span class="block truncate text-xs font-medium {{ $n['head'] !== '' ? 'text-zinc-800' : 'italic text-zinc-400' }}">{{ $n['head'] !== '' ? $n['head'] : __('Not assigned') }}</span>
            </span>
        </div>
        @if($withPlan)
            <div class="mt-2 flex items-center justify-between gap-2">
                <span class="rounded-full px-2 py-0.5 text-[10px] font-medium {{ $tone }}">{{ $word }}</span>
                <span class="text-[10px] tabular-nums text-zinc-500">{{ trans_choice(':n activity|:n activities', $n['total'], ['n' => $n['total']]) }}</span>
            </div>
        @endif
    </a>
    @if($kids->isNotEmpty())
        <button type="button" x-on:click="open = ! open" :aria-expanded="open"
            class="oc-toggle ml-[1.1rem] mt-1 inline-flex items-center gap-1 rounded-full border border-zinc-200 bg-white px-2 py-0.5 text-[10px] font-medium text-zinc-600 shadow-sm hover:bg-zinc-50">
            <span x-text="open ? '−' : '+'"></span>{{ trans_choice(':n unit|:n units', $kids->count(), ['n' => $kids->count()]) }}
        </button>
        <ul class="oc-col" x-show="open" x-cloak>
            @foreach($kids as $kid)
                <li>@include('livewire.pages.work.partials.org-node', ['n' => $kid, 'level' => $level + 1])</li>
            @endforeach
        </ul>
    @endif
</div>
