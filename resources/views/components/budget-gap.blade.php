@if($gap)
    @php $over = $gap['difference'] > 0; @endphp
    <div role="status" class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 rounded-lg border px-4 py-2.5 text-sm {{ $over ? 'border-rose-200 bg-rose-50 text-rose-900' : 'border-indigo-200 bg-indigo-50 text-indigo-900' }}">
        <span><span class="font-semibold">{{ __('Budget') }}:</span>
            {{ $over
                ? __('the approved plan is $ :amount above the latest decision (:stage, $ :decided). The activities concerned need a revision.', ['amount' => number_format($gap['difference'], 0), 'stage' => $gap['stage'], 'decided' => number_format($gap['decided'], 0)])
                : __('the approved plan is $ :amount below the latest decision (:stage, $ :decided).', ['amount' => number_format(abs($gap['difference']), 0), 'stage' => $gap['stage'], 'decided' => number_format($gap['decided'], 0)]) }}
        </span>
        <a href="{{ route('planning.budget-decisions') }}" wire:navigate class="shrink-0 text-xs font-semibold underline">{{ __('Open') }} →</a>
    </div>
@endif
