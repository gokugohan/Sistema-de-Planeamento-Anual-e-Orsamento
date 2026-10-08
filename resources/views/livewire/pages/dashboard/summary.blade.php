<?php

use Livewire\Volt\Component;
use App\Models\Activity;
use App\Models\LineItem;
use App\Models\FiscalEnvelope;

new class extends Component {
    public $requestedTotal;
    public $approvedTotal;
    public $activityCounts = [];
    public $activeYear;
    public $directorateData = [];
    public $classificationData = [];
    public $statusData = [];
    public $programData = [];
    public $performanceData = [];
    public $indicatorData = [];
    public $paaExecutionRate = 0;
    public $quarterlyExecution = [0, 0, 0, 0];

    public $selectedDirectorate = null;
    public $selectedCategory = null;

    public function mount()
    {
        $this->activeYear = App\Models\FiscalEnvelope::getEffectiveYear();
        $this->loadData();
    }

    public function updatedSelectedDirectorate()
    {
        $this->loadData();
    }
    public function updatedSelectedCategory()
    {
        $this->loadData();
    }

    public function loadData()
    {
        $this->directorateData = [];
        $this->classificationData = [];
        $this->statusData = [];
        $this->programData = [];
        $this->performanceData = [];

        $activeEnvelope = App\Models\FiscalEnvelope::where('fiscal_year', $this->activeYear)->activeByDate()->first();
        $this->approvedTotal = $activeEnvelope ? $activeEnvelope->total_amount : (FiscalEnvelope::where('fiscal_year', $this->activeYear)->value('total_amount') ?? 0);

        $filtered = function () {
            // Scope is qualified by hand: the global scope's bare is_test_data is ambiguous once line_items is joined
            $q = Activity::withoutGlobalScopes()->where('activities.is_test_data', false)->where('activities.fiscal_year', $this->activeYear);
            if ($this->selectedDirectorate) {
                $q->where('activities.directorate_id', $this->selectedDirectorate);
            }
            if ($this->selectedCategory) {
                $q->whereExists(fn($sub) => $sub->selectRaw('1')->from('line_items')
                    ->whereColumn('line_items.activity_id', 'activities.id')
                    ->where('line_items.is_test_data', false)
                    ->where('line_items.economic_classification_id', $this->selectedCategory));
            }
            return $q;
        };

        // Status counts (single grouped query)
        $byStatus = $filtered()->selectRaw('activities.status, COUNT(*) as c')->groupBy('activities.status')->pluck('c', 'status');
        foreach ([Activity::STATUS_DRAFT, Activity::STATUS_SUBMITTED, Activity::STATUS_REVIEWED, Activity::STATUS_APPROVED] as $status) {
            $count = (int) ($byStatus[$status] ?? 0);
            $this->activityCounts[$status] = $count;
            if ($count > 0) {
                $this->statusData[] = ['label' => __($status), 'value' => $count];
            }
        }

        // Money, grouped in the database by directorate / program / classification
        $rows = $filtered()
            ->leftJoin('line_items', fn($j) => $j->on('line_items.activity_id', '=', 'activities.id')->where('line_items.is_test_data', false))
            ->leftJoin('adjusted_budgets', 'adjusted_budgets.line_item_id', '=', 'line_items.id')
            ->leftJoin('directorates', 'directorates.id', '=', 'activities.directorate_id')
            ->leftJoin('programs', 'programs.id', '=', 'activities.program_id')
            ->leftJoin('economic_classifications', 'economic_classifications.id', '=', 'line_items.economic_classification_id')
            ->groupBy('activities.directorate_id', 'activities.program_id', 'line_items.economic_classification_id',
                'directorates.name', 'programs.name', 'economic_classifications.name')
            ->selectRaw('directorates.name as dir_name, programs.name as prog_name, economic_classifications.name as class_name,
                line_items.economic_classification_id as class_id,
                COALESCE(SUM(line_items.estimated_amount), 0) as proposed,
                COALESCE(SUM(adjusted_budgets.approved_amount), 0) as allocated')
            ->get();

        $dirTotals = $progTotals = $classTotals = $perfData = [];
        $totalAllocated = 0;
        $this->requestedTotal = 0;
        foreach ($rows as $r) {
            $dir = $r->dir_name ?? __('Unknown');
            $prog = $r->prog_name ?? __('No Program');
            $dirTotals[$dir] = ($dirTotals[$dir] ?? 0) + $r->proposed;
            $progTotals[$prog] = ($progTotals[$prog] ?? 0) + $r->proposed;
            $perfData[$dir]['proposed'] = ($perfData[$dir]['proposed'] ?? 0) + $r->proposed;
            $perfData[$dir]['allocated'] = ($perfData[$dir]['allocated'] ?? 0) + $r->allocated;
            $this->requestedTotal += $r->proposed;
            $totalAllocated += $r->allocated;
            if ($r->class_id !== null && (!$this->selectedCategory || $r->class_id == $this->selectedCategory)) {
                $cls = $r->class_name ?? __('Uncategorized');
                $classTotals[$cls] = ($classTotals[$cls] ?? 0) + $r->proposed;
            }
        }
        foreach ($dirTotals as $label => $value) {
            $this->directorateData[] = ['label' => $label, 'value' => $value];
        }
        foreach ($classTotals as $label => $value) {
            $this->classificationData[] = ['label' => $label, 'value' => $value];
        }
        foreach ($progTotals as $label => $value) {
            $this->programData[] = ['label' => $label, 'value' => $value];
        }
        foreach ($perfData as $dir => $v) {
            $this->performanceData[] = ['directorate' => $dir, 'proposed' => $v['proposed'], 'allocated' => $v['allocated']];
        }

        // Indicator & quarterly performance: verified sums computed by the database
        $verified = fn($q) => $q->where('status', 'Verified');
        $indicators = App\Models\Indicator::whereIn('activity_id', $filtered()->select('activities.id'))
            ->where('annual_target', '>', 0)
            ->withSum(['progress as achieved' => $verified], 'achieved_value')
            ->withSum(['progress as q1' => fn($q) => $verified($q)->where('quarter', 1)], 'achieved_value')
            ->withSum(['progress as q2' => fn($q) => $verified($q)->where('quarter', 2)], 'achieved_value')
            ->withSum(['progress as q3' => fn($q) => $verified($q)->where('quarter', 3)], 'achieved_value')
            ->withSum(['progress as q4' => fn($q) => $verified($q)->where('quarter', 4)], 'achieved_value')
            ->get(['id', 'annual_target']);

        $achTotal = 0;
        $count = 0;
        $qSums = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
        $totalTargets = 0;
        foreach ($indicators as $indicator) {
            $totalTargets += $indicator->annual_target;
            $achTotal += min(100, ($indicator->achieved / $indicator->annual_target) * 100);
            $count++;
            foreach ([1, 2, 3, 4] as $q) {
                $qSums[$q] += $indicator->{"q$q"};
            }
        }
        $this->indicatorData = $count > 0 ? round($achTotal / $count, 1) : 0;

        // Cumulative Quarterly Progress
        $this->quarterlyExecution = [0, 0, 0, 0];
        if ($totalTargets > 0) {
            $cumulative = 0;
            foreach ([1, 2, 3, 4] as $q) {
                $cumulative += $qSums[$q];
                $this->quarterlyExecution[$q - 1] = round(($cumulative / $totalTargets) * 100, 1);
            }
        }

        // PAA Execution (Average of Financial and Physical)
        $budgetExecution = $this->approvedTotal > 0 ? ($totalAllocated / $this->approvedTotal) * 100 : 0;
        $this->paaExecutionRate = round(($budgetExecution + $this->indicatorData) / 2, 1);

        $this->dispatch('data-updated', [
            'performance' => $this->performanceData,
            'program' => $this->programData,
            'directorate' => $this->directorateData,
            'classification' => $this->classificationData,
            'status' => $this->statusData,
            'quarterly' => $this->quarterlyExecution,
            'paaRate' => $this->paaExecutionRate,
        ]);
    }
}; ?>

<div class="space-y-8 animate-in fade-in duration-500" x-data="{
    chartInstances: {},
    primaryColor: '#6366f1',
    colors: ['#6366f1', '#10b981', '#f59e0b', '#ef4444', '#3b82f6', '#8b5cf6', '#ec4899', '#06b6d4'],

    init() {
        this.renderAll();
        window.addEventListener('data-updated', (e) => {
            this.renderAll(e.detail[0]);
        });
    },

    destroyAll() {
        Object.values(this.chartInstances).forEach(chart => chart.destroy());
        this.chartInstances = {};
    },

    renderAll(data = null) {
        this.destroyAll();
        
        const perf = data ? data.performance : @js($performanceData);
        const prog = data ? data.program : @js($programData);
        const dirs = data ? data.directorate : @js($directorateData);
        const classes = data ? data.classification : @js($classificationData);
        const stats = data ? data.status : @js($statusData);
        const quart = data ? data.quarterly : @js($quarterlyExecution);

        // 1. Performance Comparison
        this.chartInstances.perf = new Chart(document.getElementById('performanceChart'), {
            type: 'bar',
            data: {
                labels: perf.map(d => d.directorate),
                datasets: [
                    { label: '{{ __('Proposed ($)') }}', data: perf.map(d => d.proposed), backgroundColor: '#94a3b8', borderRadius: 6 },
                    { label: '{{ __('Allocated ($)') }}', data: perf.map(d => d.allocated), backgroundColor: this.primaryColor, borderRadius: 6 }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 10, weight: 'bold' } } },
                    tooltip: { callbacks: { label: (c) => c.dataset.label + ': $' + new Intl.NumberFormat().format(c.parsed.y) } }
                },
                scales: {
                    y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { font: { size: 10 }, callback: (v) => '$' + new Intl.NumberFormat().format(v) } },
                    x: { grid: { display: false }, ticks: { font: { size: 10, weight: 'bold' } } }
                }
            }
        });

        // 2. Program Distribution
        this.chartInstances.program = new Chart(document.getElementById('programChart'), {
            type: 'polarArea',
            data: {
                labels: prog.map(d => d.label),
                datasets: [{ data: prog.map(d => d.value), backgroundColor: this.colors.map(c => c + 'aa'), borderWidth: 0 }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: { r: { ticks: { display: false }, grid: { color: '#f1f5f9' } } },
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 9, weight: 'bold' }, padding: 15 } } }
            }
        });

        // 3. Directorate Breakdown
        this.chartInstances.dir = new Chart(document.getElementById('directorateChart'), {
            type: 'bar',
            data: {
                labels: dirs.map(d => d.label),
                datasets: [{ label: '{{ __('Budget ($)') }}', data: dirs.map(d => d.value), backgroundColor: this.primaryColor, borderRadius: 8 }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: (c) => '$' + new Intl.NumberFormat().format(c.parsed.y) } }
                },
                scales: {
                    y: { beginAtZero: true, grid: { display: false }, ticks: { callback: (v) => '$' + new Intl.NumberFormat().format(v) } },
                    x: { grid: { display: false } }
                }
            }
        });

        // 4. Classification
        this.chartInstances.class = new Chart(document.getElementById('classificationChart'), {
            type: 'doughnut',
            data: {
                labels: classes.map(d => d.label),
                datasets: [{ data: classes.map(d => d.value), backgroundColor: this.colors, borderWidth: 0, hoverOffset: 10 }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, padding: 15, font: { size: 10, weight: 'bold' } } } },
                cutout: '70%'
            }
        });

        // 5. Status
        this.chartInstances.status = new Chart(document.getElementById('statusChart'), {
            type: 'pie',
            data: {
                labels: stats.map(d => d.label),
                datasets: [{ data: stats.map(d => d.value), backgroundColor: this.colors.slice().reverse(), borderWidth: 0 }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, padding: 15, font: { size: 10, weight: 'bold' } } } }
            }
        });

        // 6. Quarterly Progress
        this.chartInstances.quart = new Chart(document.getElementById('quarterlyProgressChart'), {
            type: 'line',
            data: {
                labels: ['Q1', 'Q2', 'Q3', 'Q4'],
                datasets: [{
                    label: '{{ __('Target %') }}',
                    data: quart,
                    borderColor: '#fff',
                    backgroundColor: 'rgba(255,255,255,0.1)',
                    fill: true, tension: 0.4, pointRadius: 4, pointBackgroundColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { display: false, min: 0, max: 100 },
                    x: { ticks: { color: 'rgba(255,255,255,0.5)', font: { size: 9, weight: 'bold' } }, grid: { display: false } }
                }
            }
        });
    }
}" x-init="
    const script = document.createElement('script');
    script.src = 'https://cdn.jsdelivr.net/npm/chart.js';
    script.onload = () => init();
    document.head.appendChild(script);
">
    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-5">
        <div>
            <h1 class="text-2xl font-semibold text-zinc-900 tracking-tight">{{ __('Dashboard') }}</h1>
            <p class="text-sm text-zinc-400 mt-1">{{ __('Budget Planning & Performance Overview') }}</p>
        </div>

        <div class="flex flex-wrap items-end gap-3">
            <!-- Directorate filter -->
            <div class="flex flex-col gap-1">
                <label class="text-xs font-medium text-zinc-500">{{ __('Directorate') }}</label>
                <select wire:model.live="selectedDirectorate"
                    class="flux-input py-2 pr-8 text-sm min-w-[160px]">
                    <option value="">{{ __('All') }}</option>
                    @foreach(\App\Models\Directorate::orderBy('name')->get() as $dir)
                        <option value="{{ $dir->id }}">{{ $dir->name }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Category filter -->
            <div class="flex flex-col gap-1">
                <label class="text-xs font-medium text-zinc-500">{{ __('Category') }}</label>
                <select wire:model.live="selectedCategory"
                    class="flux-input py-2 pr-8 text-sm min-w-[160px]">
                    <option value="">{{ __('All') }}</option>
                    @foreach(\App\Models\EconomicClassification::orderBy('name')->get() as $cat)
                        <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Actions -->
            <div class="flex items-center gap-2">
                <x-ui.button variant="secondary" size="md" href="{{ route('reports.paa') }}" wire:navigate>
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1.5">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                    </svg>
                    {{ __('Export') }}
                </x-ui.button>
                <x-ui.button variant="primary" size="md" href="{{ route('activities.index') }}" wire:navigate>
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="mr-1.5">
                        <path d="M5 12h14"/><path d="M12 5v14"/>
                    </svg>
                    {{ __('Planning') }}
                </x-ui.button>
            </div>
        </div>
    </div>

    <!-- Stats Grid -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
        <x-ui.stats-card label="{{ __('Fiscal Envelope') }} {{ $activeYear }}"
            value="$ {{ number_format($approvedTotal, 0) }}" description="{{ __('Authorized ceiling') }}"
            icon="envelope" />
        <x-ui.stats-card label="{{ __('Budget Requested') }}" value="$ {{ number_format($requestedTotal, 0) }}"
            description="{{ number_format(($approvedTotal > 0 ? ($requestedTotal / $approvedTotal) * 100 : 0), 1) }}% {{ __('utilization') }}"
            trend="{{ $requestedTotal > $approvedTotal ? 'up' : 'down' }}"
            trendValue="{{ number_format(($approvedTotal > 0 ? ($requestedTotal / $approvedTotal) * 100 : 0) - 100, 1) }}%"
            class="{{ $requestedTotal > $approvedTotal ? 'ring-2 ring-rose-500/20' : '' }}" />
        <x-ui.stats-card label="{{ __('Validated') }}" value="{{ $activityCounts[Activity::STATUS_APPROVED] ?? 0 }}"
            description="{{ __('Ready to implement') }}" />
        <x-ui.stats-card label="{{ __('Achievements') }}" value="{{ $indicatorData }}%"
            description="{{ __('Targets met') }}" trend="{{ $indicatorData > 70 ? 'up' : 'down' }}" />
        <x-ui.stats-card label="{{ __('Pending') }}"
            value="{{ ($activityCounts[Activity::STATUS_SUBMITTED] ?? 0) + ($activityCounts[Activity::STATUS_REVIEWED] ?? 0) }}"
            description="{{ __('Awaiting approval') }}" />
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        <!-- Main Charts Column -->
        <div class="lg:col-span-8 space-y-6">

            <!-- Performance Chart -->
            <x-ui.card padding="p-0">
                <x-slot name="header">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-zinc-900">{{ __('Proposed vs Allocated') }}</h3>
                        <x-ui.badge variant="emerald" pulse="true">{{ __('Performance') }}</x-ui.badge>
                    </div>
                </x-slot>
                <div class="p-5">
                    <div class="h-[260px] md:h-[360px] w-full">
                        <canvas id="performanceChart"></canvas>
                    </div>
                </div>
            </x-ui.card>

            <!-- Program & Classification -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <x-ui.card padding="p-5">
                    <x-slot name="header">
                        <h3 class="text-sm font-semibold text-zinc-900">{{ __('Budget by Program') }}</h3>
                    </x-slot>
                    <div class="h-[240px] md:h-[280px] mt-3">
                        <canvas id="programChart"></canvas>
                    </div>
                </x-ui.card>

                <x-ui.card padding="p-5">
                    <x-slot name="header">
                        <h3 class="text-sm font-semibold text-zinc-900">{{ __('Economic Classification') }}</h3>
                    </x-slot>
                    <div class="h-[240px] md:h-[280px] mt-3">
                        <canvas id="classificationChart"></canvas>
                    </div>
                </x-ui.card>
            </div>

            <!-- Distribution & Status -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <x-ui.card padding="p-5">
                    <x-slot name="header">
                        <h3 class="text-sm font-semibold text-zinc-900">{{ __('Budget Distribution') }}</h3>
                    </x-slot>
                    <div class="h-[200px] md:h-[240px] mt-3">
                        <canvas id="directorateChart"></canvas>
                    </div>
                </x-ui.card>

                <x-ui.card padding="p-5">
                    <x-slot name="header">
                        <h3 class="text-sm font-semibold text-zinc-900">{{ __('Activity Status') }}</h3>
                    </x-slot>
                    <div class="h-[200px] md:h-[240px] mt-3">
                        <canvas id="statusChart"></canvas>
                    </div>
                </x-ui.card>
            </div>
        </div>

        <!-- Right Sidebar Widgets -->
        <div class="lg:col-span-4 space-y-6">

            <!-- Quick Actions -->
            <x-ui.card padding="p-5">
                <x-slot name="header">
                    <h3 class="text-sm font-semibold text-zinc-900">{{ __('Quick Actions') }}</h3>
                </x-slot>
                <div class="space-y-2 mt-1">
                    <a href="{{ route('activities.create') }}" wire:navigate
                        class="flex items-center gap-3 px-3 py-3 rounded-lg border border-zinc-200/60 hover:border-indigo-200 hover:bg-indigo-50/50 transition-all group">
                        <div class="w-8 h-8 rounded-lg bg-indigo-50 flex items-center justify-center text-indigo-600 flex-shrink-0 group-hover:bg-indigo-100 transition-colors">
                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M5 12h14"/><path d="M12 5v14"/>
                            </svg>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-zinc-800">{{ __('New Activity') }}</p>
                            <p class="text-xs text-zinc-400">{{ __('Create PAA activity') }}</p>
                        </div>
                    </a>
                    <a href="{{ route('reports.paa') }}" wire:navigate
                        class="flex items-center gap-3 px-3 py-3 rounded-lg border border-zinc-200/60 hover:border-zinc-300 hover:bg-zinc-50 transition-all group">
                        <div class="w-8 h-8 rounded-lg bg-zinc-100 flex items-center justify-center text-zinc-600 flex-shrink-0 group-hover:bg-zinc-200 transition-colors">
                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                <polyline points="14 2 14 8 20 8"/>
                                <line x1="16" x2="8" y1="13" y2="13"/>
                                <line x1="16" x2="8" y1="17" y2="17"/>
                            </svg>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-zinc-800">{{ __('Export Report') }}</p>
                            <p class="text-xs text-zinc-400">{{ __('Download PAA report') }}</p>
                        </div>
                    </a>
                </div>
            </x-ui.card>

            <!-- PAA Execution Card -->
            <div class="bg-zinc-900 rounded-2xl p-6 text-white ring-1 ring-white/5 overflow-hidden relative">

                <!-- Subtle glow -->
                <div class="absolute top-0 right-0 w-48 h-48 bg-indigo-600/10 rounded-full blur-3xl translate-x-1/2 -translate-y-1/2 pointer-events-none"></div>

                <div class="relative">
                    <p class="text-xs font-medium text-zinc-500 uppercase tracking-wider mb-5">
                        {{ __('PAA Execution') }}
                    </p>

                    <!-- Gauge + label -->
                    <div class="flex items-center gap-5 mb-6">
                        <div class="relative flex-shrink-0">
                            <svg class="w-20 h-20 -rotate-90">
                                <circle cx="40" cy="40" r="34" stroke="currentColor" stroke-width="5"
                                    fill="transparent" class="text-white/10" />
                                <circle cx="40" cy="40" r="34" stroke="currentColor" stroke-width="6"
                                    fill="transparent"
                                    stroke-dasharray="213.6"
                                    stroke-dashoffset="{{ 213.6 - (213.6 * ($paaExecutionRate / 100)) }}"
                                    class="text-indigo-500" stroke-linecap="round" />
                            </svg>
                            <div class="absolute inset-0 flex items-center justify-center text-base font-semibold text-white">
                                {{ $paaExecutionRate }}%
                            </div>
                        </div>
                        <div>
                            <p class="text-xl font-semibold text-white leading-tight">
                                {{ $paaExecutionRate > 90 ? __('Optimal') : ($paaExecutionRate > 50 ? __('Moderate') : __('Early Stage')) }}
                            </p>
                            <p class="text-xs text-zinc-500 mt-1">{{ __('Envelope Utilization') }}</p>
                        </div>
                    </div>

                    <!-- Progress milestones -->
                    <div class="flex items-center gap-2 mb-5">
                        @foreach([30, 60, 90] as $threshold)
                            <div class="flex items-center gap-1.5">
                                <div class="w-2 h-2 rounded-full {{ $paaExecutionRate >= $threshold ? 'bg-emerald-500' : 'bg-white/15' }}"></div>
                                <span class="text-[10px] text-zinc-600">{{ $threshold }}%</span>
                            </div>
                        @endforeach
                        <span class="text-[10px] text-zinc-600 ml-auto">{{ __('Financial Adherence') }}</span>
                    </div>

                    <!-- Quarterly chart -->
                    <div class="pt-4 border-t border-white/10">
                        <p class="text-[10px] font-medium text-zinc-600 uppercase tracking-wider mb-3">
                            {{ __('Quarterly Performance') }}
                        </p>
                        <div class="h-[72px] w-full">
                            <canvas id="quarterlyProgressChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>