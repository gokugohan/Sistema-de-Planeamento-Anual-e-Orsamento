<?php

use App\Models\FiscalEnvelope;
use App\Services\PlanCycle;
use Livewire\Volt\Component;

/**
 * The cycle of the annual action plan: when it may be submitted, how far the approval has got, whether planning
 * has ended, the budget it receives, and the monthly and quarterly reporting with their windows. Read-only;
 * the windows are set by an administrator and the steps are done where they belong.
 */
new class extends Component {
    public int $year;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('view_paa'), 403);
        $this->year = FiscalEnvelope::getEffectiveYear();
    }

    public function updatedYear(): void
    {
        $this->year = max(2020, min(2035, (int) $this->year));
    }

    public function with(): array
    {
        return ['cycle' => app(PlanCycle::class)->build(auth()->user(), $this->year), 'canSetWindows' => auth()->user()->isAdministrator()];
    }
}; ?>

@php
    $stateTone = ['done' => 'bg-emerald-50 text-emerald-800 ring-emerald-200', 'current' => 'bg-indigo-50 text-indigo-800 ring-indigo-300', 'todo' => 'bg-zinc-50 text-zinc-600 ring-zinc-200'];
    $stateLabel = ['done' => __('Done'), 'current' => __('Now'), 'todo' => __('Next')];
    $windowTone = ['none' => 'bg-zinc-100 text-zinc-700', 'upcoming' => 'bg-amber-50 text-amber-800', 'open' => 'bg-emerald-50 text-emerald-800', 'closed' => 'bg-rose-50 text-rose-800'];
    $windowLabel = ['none' => __('Open (no dates)'), 'upcoming' => __('Not yet open'), 'open' => __('Open'), 'closed' => __('Closed')];
    $statusOrder = [
        'Draft' => ['bg-zinc-300', __('Draft')], 'Rejected' => ['bg-rose-400', __('Returned')], 'Submitted' => ['bg-sky-400', __('Submitted')],
        'Reviewed' => ['bg-indigo-300', __('Reviewed')], 'Validated' => ['bg-indigo-500', __('Validated')], 'Waiting Approval' => ['bg-amber-400', __('Waiting approval')], 'Approved' => ['bg-emerald-500', __('Approved')],
    ];
    $a = $cycle['approval'];
@endphp

<div class="space-y-6">
    <x-ui.page-header :title="__('Plan cycle')" :subtitle="__('Where the annual action plan stands: when it may be submitted, how far the approval has got, the budget it receives and the reporting that follows.')">
        <label for="pc-y" class="sr-only">{{ __('Fiscal year') }}</label>
        <select id="pc-y" wire:model.live="year" class="flux-input !w-auto">
            @foreach(range((int) date('Y') + 2, 2024) as $y)<option value="{{ $y }}">{{ $y }}</option>@endforeach
        </select>
    </x-ui.page-header>

    {{-- the steps --}}
    <ol class="grid gap-3 md:grid-cols-3 xl:grid-cols-6" aria-label="{{ __('Steps of the plan') }}">
        @foreach($cycle['steps'] as $i => $s)
            <li class="rounded-lg p-4 ring-1 {{ $stateTone[$s['state']] }}">
                <div class="flex items-center justify-between gap-2">
                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-white text-xs font-semibold ring-1 ring-current">{{ $i + 1 }}</span>
                    <span class="text-xs font-semibold">{{ $stateLabel[$s['state']] }}</span>
                </div>
                <p class="mt-2 text-sm font-semibold">{{ $s['label'] }}</p>
                <p class="mt-1 text-xs opacity-90">{{ $s['detail'] }}</p>
            </li>
        @endforeach
    </ol>

    <div class="grid gap-6 xl:grid-cols-2">
        {{-- submission window and the end of planning --}}
        <x-ui.card :title="__('Plan submission')" padding="p-5">
            @php $w = $cycle['plan_window']; @endphp
            <div class="flex flex-wrap items-center gap-3">
                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium {{ $windowTone[$w['state']] }}">{{ $windowLabel[$w['state']] }}</span>
                @if($w['opens_on'])<span class="text-sm tabular-nums text-zinc-700">{{ $w['opens_on']->format('d/m/Y') }} → {{ $w['closes_on']->format('d/m/Y') }}</span>@endif
            </div>
            @if($w['note'])<p class="mt-2 text-sm text-zinc-600">{{ $w['note'] }}</p>@endif
            <p class="mt-3 text-sm text-zinc-600">{{ __('Units create, edit and submit activities while the window is open. An administrator can always act.') }}</p>

            @php $p = $cycle['planning']; @endphp
            <div class="mt-4 border-t border-zinc-100 pt-4 text-sm">
                @if($p['ended'])
                    <p class="font-medium text-zinc-900">{{ __('Planning for :year has ended', ['year' => $year]) }}</p>
                    @if($p['at'])<p class="mt-1 text-xs text-zinc-500">{{ $p['by'] }} · {{ $p['at']->timezone('Asia/Dili')->format('d/m/Y H:i') }}@if($p['remark']) — {{ $p['remark'] }}@endif</p>@endif
                @else
                    <p class="text-zinc-700">{{ __('Planning is open. It ends when the final approver finalizes the plan.') }}</p>
                @endif
            </div>
            @if($canSetWindows)
                <x-ui.button variant="secondary" size="md" shadow="none" class="mt-4" href="{{ route('management.reporting-windows') }}" wire:navigate>{{ __('Set the windows') }}</x-ui.button>
            @endif
        </x-ui.card>

        {{-- approval --}}
        <x-ui.card :title="__('Approval')" :description="__(':approved of :total activities approved', ['approved' => $a['approved'], 'total' => $a['total']])" padding="p-5">
            @if($a['total'] === 0)
                <p class="text-sm text-zinc-500">{{ __('No activity yet') }}</p>
            @else
                <div class="flex h-3 w-full overflow-hidden rounded-full bg-zinc-100" role="img" aria-label="{{ __('Activities by status') }}">
                    @foreach($statusOrder as $key => [$color, $label])
                        @php $n = $a['by_status'][$key] ?? 0; @endphp
                        @if($n > 0)<div class="{{ $color }}" style="width: {{ $n / $a['total'] * 100 }}%" title="{{ $label }}: {{ $n }}"></div>@endif
                    @endforeach
                </div>
                <ul class="mt-4 grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
                    @foreach($statusOrder as $key => [$color, $label])
                        @php $n = $a['by_status'][$key] ?? 0; @endphp
                        <li class="flex items-center justify-between gap-2 {{ $n === 0 ? 'text-zinc-400' : 'text-zinc-700' }}">
                            <span class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-sm {{ $color }}" aria-hidden="true"></span>{{ $label }}</span>
                            <span class="tabular-nums font-medium">{{ $n }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
            <x-ui.button variant="secondary" size="md" shadow="none" class="mt-4" href="{{ route('approvals.index') }}" wire:navigate>{{ __('Open approvals') }}</x-ui.button>
        </x-ui.card>
    </div>

    {{-- the budget --}}
    @php $b = $cycle['budget']; @endphp
    <x-ui.card :title="__('Budget')" padding="p-5">
        <dl class="grid gap-4 sm:grid-cols-3">
            <div><dt class="text-xs font-medium text-zinc-500">{{ __('Planned by approved activities') }}</dt><dd class="mt-0.5 text-lg font-semibold tabular-nums text-zinc-900">$ {{ number_format($b['planned']) }}</dd></div>
            <div><dt class="text-xs font-medium text-zinc-500">{{ __('Fiscal envelope') }}</dt><dd class="mt-0.5 text-lg font-semibold tabular-nums text-zinc-900">{{ $b['envelope'] !== null ? '$ '.number_format($b['envelope']) : '—' }}</dd></div>
            <div><dt class="text-xs font-medium text-zinc-500">{{ __('Distributed over the plan') }}</dt><dd class="mt-0.5 text-lg font-semibold tabular-nums text-zinc-900">$ {{ number_format($b['approved_plan']) }}</dd></div>
        </dl>
        @can('view_all_paa')
            <x-ui.button variant="secondary" size="md" shadow="none" class="mt-4" href="{{ route('budget.envelope.current') }}" wire:navigate>{{ __('Open the fiscal envelope') }}</x-ui.button>
        @endcan
    </x-ui.card>

    {{-- reporting --}}
    <x-ui.card :title="__('Reporting')" :description="__('Spending is reported every month and indicators every quarter, inside the windows set for them.')" padding="p-0">
        @php $r = $cycle['reporting']; @endphp
        <x-ui.table :headers="[__('Month'), __('Window'), __('Activities with verified spending'), __('Waiting for verification')]" :right="[2, 3]" class="text-sm">
            @foreach($r['months'] as $m => $row)
                <tr wire:key="pc-m-{{ $m }}">
                    <td class="px-5 py-2.5 font-medium text-zinc-900">{{ \Carbon\Carbon::create($year, $m, 1)->translatedFormat('F') }}</td>
                    <td class="px-5 py-2.5">
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $windowTone[$row['window']['state']] }}">{{ $windowLabel[$row['window']['state']] }}</span>
                        @if($row['window']['opens_on'])<span class="ml-2 text-xs tabular-nums text-zinc-500">{{ $row['window']['opens_on']->format('d/m') }}–{{ $row['window']['closes_on']->format('d/m') }}</span>@endif
                    </td>
                    <td class="px-5 py-2.5 num">{{ $row['verified'] }} / {{ $row['due'] }}</td>
                    <td class="px-5 py-2.5 num">{{ $row['waiting'] ?: '—' }}</td>
                </tr>
            @endforeach
        </x-ui.table>
        <div class="grid gap-3 border-t border-zinc-100 px-5 py-4 sm:grid-cols-4">
            @foreach($r['quarters'] as $q => $row)
                <div class="rounded-md border border-zinc-200 px-3 py-2 text-sm">
                    <p class="font-medium text-zinc-900">{{ __('Indicators') }} · Q{{ $q }}</p>
                    <span class="mt-1 inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $windowTone[$row['window']['state']] }}">{{ $windowLabel[$row['window']['state']] }}</span>
                    @if($row['window']['opens_on'])<span class="ml-1 text-xs tabular-nums text-zinc-500">{{ $row['window']['opens_on']->format('d/m') }}–{{ $row['window']['closes_on']->format('d/m') }}</span>@endif
                </div>
            @endforeach
        </div>
    </x-ui.card>

    {{-- where each step is done --}}
    <x-ui.card :title="__('Where things are done')" padding="p-5">
        <div class="flex flex-wrap gap-3">
            <x-ui.button variant="secondary" size="md" shadow="none" href="{{ route('monitoring.report') }}" wire:navigate>{{ __('Report execution') }}</x-ui.button>
            @can('view_reports')
                <x-ui.button variant="secondary" size="md" shadow="none" href="{{ route('reports.compile') }}" wire:navigate>{{ __('Compile the quarter') }}</x-ui.button>
                <x-ui.button variant="secondary" size="md" shadow="none" href="{{ route('reports.performance', ['year' => $year]) }}">{{ __('Performance report') }}</x-ui.button>
                @can('view_all_paa')
                    <x-ui.button variant="secondary" size="md" shadow="none" href="{{ route('reports.approved-plan', ['year' => $year]) }}">{{ __('Final plan report') }}</x-ui.button>
                @endcan
            @endcan
        </div>
    </x-ui.card>
</div>
