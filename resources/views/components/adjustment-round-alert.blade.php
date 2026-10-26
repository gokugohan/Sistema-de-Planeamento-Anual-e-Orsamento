@if($text)
    <div role="status" class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 rounded-lg border px-4 py-2.5 text-sm {{ $urgent ? 'border-rose-200 bg-rose-50 text-rose-900' : 'border-amber-200 bg-amber-50 text-amber-900' }}">
        <span><span class="font-semibold">{{ __('Budget') }}:</span> {{ $text }}</span>
        <a href="{{ route('planning.adjustment') }}" wire:navigate class="shrink-0 text-xs font-semibold underline">{{ __('Open') }} →</a>
    </div>
@endif
