<?php

use Livewire\Volt\Component;
use App\Models\Activity;
use App\Models\Program;
use App\Models\Indicator;
use App\Models\LineItem;
use App\Models\AdjustedBudget;
use App\Models\FiscalEnvelope;

new class extends Component {
    public $stats = [];
    public $programExecution = [];
    public $activeYear;

    public function mount(): void
    {
        $activeEnvelope = FiscalEnvelope::getActiveEnvelope();
        $this->activeYear = $activeEnvelope?->fiscal_year;

        if ($this->activeYear) {
            $this->loadStats();
        }
    }

    public function loadStats(): void
    {
        $activities = Activity::where('fiscal_year', $this->activeYear)->get();
        $activityIds = $activities->pluck('id');

        $requested = LineItem::whereIn('activity_id', $activityIds)->sum('estimated_amount');
        $approved = AdjustedBudget::whereHas('lineItem', function ($q) use ($activityIds) {
            $q->whereIn('activity_id', $activityIds);
        })->sum('approved_amount');

        // Physical Progress: Only count data that has been Verified by a Director
        $indicators = Indicator::whereIn('activity_id', $activityIds)
            ->with(['progress' => fn($q) => $q->where('status', 'Verified')])
            ->get();
        $totalProgress = 0;
        $count = $indicators->count();

        foreach ($indicators as $indicator) {
            if ($indicator->annual_target > 0) {
                $achieved = $indicator->progress->sum('achieved_value');
                $totalProgress += min(($achieved / $indicator->annual_target) * 100, 100);
            }
        }

        $avgPhysical = $count > 0 ? $totalProgress / $count : 0;

        $this->stats = [
            'requested' => $requested,
            'approved' => $approved,
            'financial_ratio' => $requested > 0 ? ($approved / $requested) * 100 : 0,
            'physical_progress' => $avgPhysical,
        ];

        // Per Program Stats
        $this->programExecution = Program::all()->map(function ($program) {
            $pActivities = Activity::where('program_id', $program->id)
                ->where('fiscal_year', $this->activeYear)
                ->get();
            $pActivityIds = $pActivities->pluck('id');

            $pRequested = LineItem::whereIn('activity_id', $pActivityIds)->sum('estimated_amount');
            $pApproved = AdjustedBudget::whereHas('lineItem', fn($q) => $q->whereIn('activity_id', $pActivityIds))->sum('approved_amount');

            $pIndicators = Indicator::whereIn('activity_id', $pActivityIds)
                ->with(['progress' => fn($q) => $q->where('status', 'Verified')])
                ->get();
            $pTotalPhys = 0;
            $pCount = $pIndicators->count();
            foreach ($pIndicators as $ind) {
                if ($ind->annual_target > 0)
                    $pTotalPhys += min(($ind->progress->sum('achieved_value') / $ind->annual_target) * 100, 100);
            }
            $pAvgPhys = $pCount > 0 ? $pTotalPhys / $pCount : 0;

            return [
                'name' => $program->name,
                'code' => $program->oge_code,
                'financial' => $pRequested > 0 ? ($pApproved / $pRequested) * 100 : 0,
                'physical' => $pAvgPhys,
            ];
        });

        $this->dispatch('stats-updated', [
            'programs' => $this->programExecution
        ]);
    }
}; ?>

<div class="space-y-10 py-6 animate-in fade-in duration-1000" x-data="{
    chart: null,
    initCharts(data) {
        if (this.chart) this.chart.destroy();
        const programs = data ? data.programs : @js($programExecution);
        
        this.chart = new Chart(document.getElementById('programExecutionChart'), {
            type: 'bar',
            data: {
                labels: programs.map(p => p.code),
                datasets: [
                    {
                        label: '{{ __('Financial Execution %') }}',
                        data: programs.map(p => p.financial),
                        backgroundColor: '#2152C4',
                        borderRadius: 6
                    },
                    {
                        label: '{{ __('Physical Progress %') }}',
                        data: programs.map(p => p.physical),
                        backgroundColor: '#10b981',
                        borderRadius: 6
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 12, font: { weight: 'bold', size: 10 } } },
                    tooltip: { callbacks: { label: (c) => c.dataset.label + ': ' + c.parsed.y.toFixed(1) + '%' } }
                },
                scales: {
                    y: { beginAtZero: true, max: 100, grid: { color: '#f1f5f9' }, ticks: { callback: (v) => v + '%' } },
                    x: { grid: { display: false } }
                }
            }
        });
    }
}" x-init="onChart(() => {
    initCharts();
    window.addEventListener('stats-updated', (e) => initCharts(e.detail[0]));
})">
    <div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-6">
        <div>
            <h1 class="page-title">{{ __('Execution Monitoring') }}
            </h1>
            <p class="page-subtitle">
                {{ __('Real-time physical and financial performance tracking') }} @if($activeYear) {{ __('for') }}
                {{ $activeYear }} @endif
            </p>
        </div>
        @if($activeYear)
            <div class="flex gap-3">
                <x-ui.badge variant="indigo" pulse="true" class="px-4 py-2 text-xs font-semibold ">
                    {{ __('Active Fiscal Year') }}: {{ $activeYear }}
                </x-ui.badge>
            </div>
        @endif
    </div>

    @if(!$activeYear)
        <div class="bg-amber-50 border-2 border-dashed border-amber-200 rounded-xl p-12 text-center">
            <div class="h-20 w-20 bg-amber-100 rounded-full flex items-center justify-center mx-auto mb-6">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-10 w-10 text-amber-600" fill="none" viewBox="0 0 24 24"
                    stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
            </div>
            <h2 class="text-md font-semibold text-zinc-900 tracking-tight mb-4">
                {{ __('Monitoring Suspended: No Active Fiscal Year') }}
            </h2>
            <p class="text-zinc-500 text-sm font-medium max-w-lg mx-auto leading-relaxed">
                {{ __('Real-time monitoring is only available when a Fiscal Envelope for the current period has been') }}
                <b class="text-zinc-900">{{ __('Approved') }}</b> {{ __('and is within its') }} <b
                    class="text-zinc-900">{{ __('Active Validity Dates') }}</b>.
                {{ __('As of today, no active budget authority has been found.') }}
            </p>
            <div class="mt-8 flex justify-center gap-4">
                <a href="{{ route('budget.envelope.list') }}"
                    class="px-6 py-3 bg-indigo-600 text-white text-xs font-semibold rounded-xl hover:bg-indigo-700 transition-all">
                    {{ __('Manage Fiscal Envelopes') }}
                </a>
            </div>
        </div>
    @else

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
            <x-ui.stats-card label="{{ __('Budget Requested') }}" value="$ {{ number_format($stats['requested'], 0) }}"
                description="{{ __('Total initial estimates') }}" icon="banknotes" />

            <x-ui.stats-card label="{{ __('Budget Approved') }}" value="$ {{ number_format($stats['approved'], 0) }}"
                description="{{ __('Total adjusted allocations') }}" icon="check-badge" />

            <x-ui.stats-card label="{{ __('Financial Rate') }}" value="{{ number_format($stats['financial_ratio'], 1) }}%"
                description="{{ __('Approved vs Requested ratio') }}"
                trend="{{ $stats['financial_ratio'] > 80 ? 'up' : 'down' }}" />

            <x-ui.stats-card label="{{ __('Physical Progress') }}"
                value="{{ number_format($stats['physical_progress'], 1) }}%"
                description="{{ __('PAA target achievement') }}"
                trend="{{ $stats['physical_progress'] > 50 ? 'up' : 'down' }}" />
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
            <div class="lg:col-span-8">
                <x-ui.card padding="p-0" class="overflow-hidden">
                    <x-slot name="header">
                        <div class="flex items-center justify-between px-6 py-4">
                            <h3 class="text-sm font-semibold text-zinc-900 ">
                                {{ __('Programmatic Performance Comparison') }}
                            </h3>
                        </div>
                    </x-slot>
                    <div class="p-8">
                        <div class="h-[400px] w-full">
                            <canvas id="programExecutionChart"></canvas>
                        </div>
                    </div>
                </x-ui.card>
            </div>

            <div class="lg:col-span-4">
                <x-ui.card class="h-full">
                    <x-slot name="header">
                        <h3 class="text-xs font-semibold text-zinc-900 ">
                            {{ __('Achievement Details') }}
                        </h3>
                    </x-slot>
                    <div class="space-y-6 mt-4">
                        @foreach($programExecution as $prog)
                            <div class="group">
                                <div class="flex justify-between items-end mb-2">
                                    <div class="flex flex-col">
                                        <span
                                            class="text-xs font-semibold text-zinc-400 ">{{ $prog['code'] }}</span>
                                        <span class="text-xs font-bold text-zinc-700 leading-tight">{{ $prog['name'] }}</span>
                                    </div>
                                    <span
                                        class="text-xs font-semibold text-indigo-600">{{ number_format($prog['physical'], 0) }}%</span>
                                </div>
                                <div class="w-full bg-zinc-100 rounded-full h-1.5 overflow-hidden">
                                    <div class="bg-indigo-500 h-full transition-all duration-1000 group-hover:bg-indigo-600"
                                        style="width: {{ $prog['physical'] }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-ui.card>
            </div>
        </div>
    @endif
</div>