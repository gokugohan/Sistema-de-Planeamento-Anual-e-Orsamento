@php
    $user = auth()->user();
    $year = \App\Support\FiscalYear::current();
    $inbox = app(\App\Services\ApprovalInbox::class);
    // the units this user may open, to link them (null: every unit)
    $openable = app(\App\Services\UnitWorkspace::class)->visibleUnitIds($user);
    $items = collect($inbox->items($user, \App\Support\FiscalYear::isUnusual() ? $year : null))->groupBy('group');
    // only decisions and verifications belong here; drafts and reporting due are in My work
    $groups = [
        'decide' => [__('To decide'), __('Activities waiting for you: first certified by the unit, then given the final approval.'), __('Decide')],
        'verify' => [__('To verify'), __('Figures reported by others. You cannot verify your own.'), __('Verify')],
        'late' => [__('Late on the calendar'), __('Periods that have closed with work left in them. Give a unit more time without reopening the period for everybody.'), __('Open the calendar')],
    ];
    $tones = ['amber' => 'bg-amber-50 text-amber-800', 'indigo' => 'bg-indigo-50 text-indigo-800', 'rose' => 'bg-rose-50 text-rose-800', 'emerald' => 'bg-emerald-50 text-emerald-800'];
    // the plans ready for their head's approval are in the card "Approve the plan" (below the notices), not in a list
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

        <livewire:pages.planning.approve-plan :year="$year" :key="'approve-plan-'.$year" />

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
                        @php $details = $item['key'] ? $inbox->details($user, $item['key'], \App\Support\FiscalYear::isUnusual() ? $year : null) : []; @endphp
                        <li>
                            <a href="{{ $item['url'] }}" class="group flex items-center gap-4 px-5 py-3 hover:bg-zinc-50">
                                <span class="flex h-9 min-w-[2.25rem] items-center justify-center rounded-md px-2 text-sm font-semibold {{ $tones[$item['tone']] ?? $tones['indigo'] }}">{{ $item['count'] }}</span>
                                <span class="flex-1 text-sm font-medium text-zinc-800">{{ $item['text'] }}</span>
                                <span class="text-xs font-medium text-indigo-700 group-hover:underline">{{ $verb }} →</span>
                            </a>
                            {{-- what is waiting, unit by unit, without opening it --}}
                            @if($details)
                                <ul class="space-y-2 px-5 pb-4 pl-[4.25rem]">
                                    @foreach(array_slice($details, 0, 6) as $d)
                                        <li class="text-sm">
                                            <p class="text-zinc-800">
                                                @if($d['parent'])<span class="text-zinc-500">{{ $d['parent']->code }} ›</span>@endif
                                                @if($d['unit'] && ($openable === null || in_array($d['unit']->id, $openable, true)))
                                                    <a href="{{ route('units.show', $d['unit']) }}" wire:navigate class="hover:text-indigo-700 hover:underline"><span class="font-medium">{{ $d['unit']->code }}</span>
                                                        <span class="text-zinc-500">{{ \Illuminate\Support\Str::limit($d['unit']->name, 60) }}</span></a>
                                                @else
                                                    <span class="font-medium">{{ $d['unit']?->code }}</span>
                                                    <span class="text-zinc-500">{{ \Illuminate\Support\Str::limit($d['unit']?->name, 60) }}</span>
                                                @endif
                                                <span class="ml-1 rounded-full bg-zinc-100 px-1.5 py-0.5 text-xs tabular-nums text-zinc-700">{{ $d['count'] }}</span>
                                            </p>
                                            <ul class="mt-0.5 space-y-0.5">
                                                @foreach($d['activities'] as $a)
                                                    <li class="truncate text-xs"><a href="{{ route('activities.show', $a) }}" wire:navigate class="text-zinc-600 hover:text-indigo-700 hover:underline"><span class="font-medium text-zinc-700">{{ $a->code }}</span> {{ $a->description }}</a></li>
                                                @endforeach
                                                @if($d['count'] > count($d['activities']))
                                                    <li class="text-xs text-zinc-500">{{ trans_choice('and :n more|and :n more', $d['count'] - count($d['activities']), ['n' => $d['count'] - count($d['activities'])]) }}</li>
                                                @endif
                                            </ul>
                                        </li>
                                    @endforeach
                                    @if(count($details) > 6)
                                        <li class="text-xs text-zinc-500">{{ trans_choice('and :n more unit|and :n more units', count($details) - 6, ['n' => count($details) - 6]) }} — <a href="{{ $item['url'] }}" class="font-medium text-indigo-700 hover:underline">{{ $verb }} →</a></li>
                                    @endif
                                </ul>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        @endforeach

        @if($shown->isEmpty() && ! $items->has('plan'))
            <x-ui.card padding="p-0">
                <x-ui.empty-state :title="__('You are all caught up.')" :text="__('Nothing is waiting for a decision or a check from you right now.')" />
            </x-ui.card>
        @endif
    </div>
</x-app-layout>
