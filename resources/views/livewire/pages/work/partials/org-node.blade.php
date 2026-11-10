{{-- One box of the organization chart, then the units below it (recursively). --}}
@php $kids = $children->get($n['id'], collect()); @endphp
<a href="{{ route('units.show', $n['id']) }}" wire:navigate
    class="block rounded-lg border bg-white px-3 py-2 shadow-card transition hover:border-indigo-400 hover:shadow-md {{ $n['autonomous'] ? 'border-dashed border-zinc-400' : 'border-zinc-200' }} {{ $root ? 'border-t-4 !border-t-indigo-600' : '' }}">
    <div class="flex items-start justify-between gap-2">
        <div class="min-w-0">
            <div class="text-[11px] font-medium uppercase tracking-wide text-zinc-500">{{ $n['code'] }}@if($t = $typeWord($n)) · {{ $t }}@endif</div>
            <div class="{{ $root ? 'text-sm font-semibold' : 'text-[13px] font-medium' }} leading-snug text-zinc-900">{{ $n['name'] }}</div>
        </div>
        @if($withPlan)
            <span class="mt-1 h-2.5 w-2.5 shrink-0 rounded-full {{ $dot[$n['light']] ?? 'bg-zinc-300' }}" title="{{ $lightLabel[$n['light']] ?? '' }}"></span>
        @endif
    </div>
    <div class="mt-1 text-xs text-zinc-600">
        <span class="text-zinc-500">{{ $n['title'] }}:</span> {{ $n['head'] !== '' ? $n['head'] : '—' }}
    </div>
    @if($withPlan)
        <div class="mt-1 text-xs text-zinc-500">{{ trans_choice(':n activity|:n activities', $n['total'], ['n' => $n['total']]) }} · {{ $lightLabel[$n['light']] ?? '' }}</div>
    @endif
</a>
@if($kids->isNotEmpty())
    <ul class="org-kids">
        @foreach($kids as $kid)
            <li>@include('livewire.pages.work.partials.org-node', ['n' => $kid, 'root' => false])</li>
        @endforeach
    </ul>
@endif
