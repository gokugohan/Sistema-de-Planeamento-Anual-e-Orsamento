<a href="{{ route('units.show', $r['id']) }}" wire:navigate class="flex flex-wrap items-center justify-between gap-3 py-3 pr-5 text-sm hover:bg-zinc-50" style="padding-left: {{ $pad }}rem">
    <span class="min-w-0">
        <span class="font-semibold text-zinc-900">{{ $r['code'] }}</span>
        <span class="text-zinc-700">{{ \Illuminate\Support\Str::limit($r['name'], 70) }}</span>
        @if($r['depth'] === 0 && $r['has_children'])<span class="ml-1 text-xs text-zinc-400">{{ __('and the units below') }}</span>@endif
    </span>
    <span class="flex items-center gap-4">
        <span class="hidden text-xs tabular-nums text-zinc-500 sm:inline">{{ $r['counts']['approved'] }} / {{ $r['counts']['total'] }} {{ __('approved') }}@if($r['counts']['returned'] > 0) · {{ $r['counts']['returned'] }} {{ __('returned') }}@endif</span>
        <span class="inline-flex w-40 items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium {{ $lightTone[$r['light']] }}"><span class="h-1.5 w-1.5 rounded-full {{ $dot[$r['light']] }}" aria-hidden="true"></span>{{ $lightLabel[$r['light']] }}</span>
    </span>
</a>
