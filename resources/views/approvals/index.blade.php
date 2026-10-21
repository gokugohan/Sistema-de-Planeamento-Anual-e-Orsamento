@php
    $user = auth()->user();
    $year = \App\Support\FiscalYear::current();
    $items = collect(app(\App\Services\ApprovalInbox::class)->items($user, $year))->groupBy('group');
    // only decisions and verifications belong here; drafts and reporting due are in My work
    $groups = [
        'decide' => [__('To decide'), __('Activities waiting for you: first certified by the unit, then given the final approval.'), __('Decide')],
        'verify' => [__('To verify'), __('Figures reported by others. You cannot verify your own.'), __('Verify')],
        'late' => [__('Late on the calendar'), __('Periods that have closed with work left in them. Give a unit more time without reopening the period for everybody.'), __('Open the calendar')],
        'plan' => [__('Ready to finalize'), __('Everything is decided. Finalizing ends planning for that directorate: no new activities after it.'), __('Finalize')],
    ];
    $tones = ['amber' => 'bg-amber-50 text-amber-800', 'indigo' => 'bg-indigo-50 text-indigo-800', 'rose' => 'bg-rose-50 text-rose-800', 'emerald' => 'bg-emerald-50 text-emerald-800'];
    $shown = collect($groups)->keys()->filter(fn ($k) => $items->has($k));
    $mine = auth()->user()->can('view_paa') ? route('work.home') : null;
@endphp
<x-app-layout title="{{ __('Waiting for you') }}">
    <div class="space-y-6">
        <x-ui.page-header :title="__('Waiting for you')" :subtitle="__('What needs a decision or a check from you for :year. Your own drafts and reporting due are in My work.', ['year' => $year])">
            @if($mine)
                <x-ui.button variant="secondary" size="md" shadow="none" href="{{ $mine }}" wire:navigate>{{ __('My work') }}</x-ui.button>
            @endif
        </x-ui.page-header>

        <x-calendar-alerts :year="$year" />
        <x-plan-complete-notice :year="$year" />

        @php
            $calendar = app(\App\Services\ReportingWindows::class);
            $closedNow = $user->isAdministrator() ? [] : array_filter([
                ['type' => 'approval', 'reason' => $calendar->closedReason($user, $year, 'approval', 1)],
                ['type' => 'finalize', 'reason' => $calendar->closedReason($user, $year, 'finalize', 1)],
            ], fn ($c) => $c['reason']);
        @endphp
        @foreach($closedNow as $c)
            <div role="status" class="rounded-lg border border-amber-200 bg-amber-50 px-5 py-3 text-sm text-amber-900">{{ $c['reason'] }}
                <livewire:pages.calendar.ask-reopen :year="$year" :type="$c['type']" :period="1" :key="'ask-'.$c['type']" />
            </div>
        @endforeach

        @foreach($shown as $key)
            @php [$title, $text, $verb] = $groups[$key]; @endphp
            <x-ui.card :title="$title" :description="$text" padding="p-0">
                <ul class="divide-y divide-zinc-200">
                    @foreach($items[$key] as $item)
                        <li>
                            <a href="{{ $item['url'] }}" class="group flex items-center gap-4 px-5 py-3 hover:bg-zinc-50">
                                <span class="flex h-9 min-w-[2.25rem] items-center justify-center rounded-md px-2 text-sm font-semibold {{ $tones[$item['tone']] ?? $tones['indigo'] }}">{{ $item['count'] }}</span>
                                <span class="flex-1 text-sm text-zinc-700">{{ $item['text'] }}</span>
                                <span class="text-xs font-medium text-indigo-700 group-hover:underline">{{ $verb }} →</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        @endforeach

        @if($shown->isEmpty())
            <x-ui.card padding="p-0">
                <x-ui.empty-state :title="__('You are all caught up.')" :text="__('Nothing is waiting for a decision or a check from you right now.')" />
            </x-ui.card>
        @endif
    </div>
</x-app-layout>
