@php
    $user = auth()->user();
    $year = \App\Models\FiscalEnvelope::getEffectiveYear();
    $items = collect(app(\App\Services\ApprovalInbox::class)->items($user, $year))->groupBy('group');
    $groups = [
        'decide' => [__('Activities to decide'), __('Review, validate or approve what units have submitted.')],
        'verify' => [__('Execution to verify'), __('Figures reported by others, spending and progress. You cannot verify your own.')],
        'plan' => [__('The plan'), __('Ending planning for the year is the final approval of the plan.')],
        'budget' => [__('Budget steps'), __('Steps of the budget cycle waiting for you.')],
        'mine' => [__('Your own work'), __('Drafts to finish and work returned to you.')],
    ];
    $tones = ['amber' => 'bg-amber-50 text-amber-800', 'indigo' => 'bg-indigo-50 text-indigo-800', 'rose' => 'bg-rose-50 text-rose-800', 'emerald' => 'bg-emerald-50 text-emerald-800'];
    $places = array_values(array_filter([
        $user->can('approve_paa') ? ['PAA Tech Review', route('planning.paa-approval'), __('Review and approve submitted activities, in bulk')] : null,
        $user->can('approve_progress_report') ? ['Verify Progress', route('monitoring.approval'), __('Verify quarterly indicator progress')] : null,
        $user->can('view_monitoring') ? ['Report execution', route('monitoring.report'), __('Report and verify what an activity spent and achieved in a quarter')] : null,
        ['Allocation Review', route('planning.director-review'), __('Review how the envelope was allocated')],
    ]));
@endphp
<x-app-layout title="{{ __('Approvals') }}">
    <div class="space-y-6">
        <x-ui.page-header :title="__('Approvals')" :subtitle="__('Everything waiting for you for :year, and the places to deal with it.', ['year' => $year])" />

        @forelse($groups as $key => [$title, $text])
            @continue(! $items->has($key))
            <x-ui.card :title="$title" :description="$text" padding="p-0">
                <ul class="divide-y divide-zinc-200">
                    @foreach($items[$key] as $item)
                        <li>
                            <a href="{{ $item['url'] }}" class="group flex items-center gap-4 px-5 py-3 hover:bg-zinc-50">
                                <span class="flex h-9 min-w-[2.25rem] items-center justify-center rounded-md px-2 text-sm font-semibold {{ $tones[$item['tone']] ?? $tones['indigo'] }}">{{ $item['count'] }}</span>
                                <span class="flex-1 text-sm text-zinc-700">{{ $item['text'] }}</span>
                                <span class="text-xs font-medium text-indigo-700 group-hover:underline">{{ __('Open') }} →</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        @empty
        @endforelse

        @if($items->isEmpty())
            <x-ui.card padding="p-0">
                <x-ui.empty-state :title="__('You are all caught up.')" :text="__('Nothing is waiting for you right now.')" />
            </x-ui.card>
        @endif

        <x-ui.card :title="__('Places')" :description="__('Where approvals and verification happen.')" padding="p-0">
            <ul class="divide-y divide-zinc-200">
                @foreach($places as [$label, $url, $hint])
                    <li>
                        <a href="{{ $url }}" class="group flex items-center justify-between gap-4 px-5 py-3 hover:bg-zinc-50">
                            <span><span class="block text-sm font-medium text-zinc-900">{{ __($label) }}</span><span class="block text-xs text-zinc-500">{{ $hint }}</span></span>
                            <span class="text-xs font-medium text-indigo-700 group-hover:underline">{{ __('Open') }} →</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    </div>
</x-app-layout>
