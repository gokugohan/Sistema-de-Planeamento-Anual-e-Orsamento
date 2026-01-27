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

        $query = Activity::where('fiscal_year', $this->activeYear)
            ->with(['directorate', 'lineItems.economicClassification', 'lineItems.adjustedBudget', 'program']);

        if ($this->selectedDirectorate) {
            $query->where('directorate_id', $this->selectedDirectorate);
        }

        if ($this->selectedCategory) {
            $query->whereHas('lineItems', fn($q) => $q->where('economic_classification_id', $this->selectedCategory));
        }

        $activities = $query->get();
        $this->requestedTotal = $activities->flatMap->lineItems->sum('estimated_amount');

        $statuses = [Activity::STATUS_DRAFT, Activity::STATUS_SUBMITTED, Activity::STATUS_REVIEWED, Activity::STATUS_APPROVED];
        foreach ($statuses as $status) {
            $count = $activities->where('status', $status)->count();
            $this->activityCounts[$status] = $count;
            if ($count > 0) {
                $this->statusData[] = ['label' => __($status), 'value' => $count];
            }
        }

        // Fetch Directorate Data
        $dirTotals = [];
        foreach ($activities as $activity) {
            $name = $activity->directorate->name ?? __('Unknown');
            $amount = $activity->lineItems->sum('estimated_amount');
            $dirTotals[$name] = ($dirTotals[$name] ?? 0) + $amount;
        }
        foreach ($dirTotals as $label => $value) {
            $this->directorateData[] = ['label' => $label, 'value' => $value];
        }

        // Fetch Classification Data
        $classTotals = [];
        foreach ($activities as $activity) {
            foreach ($activity->lineItems as $item) {
                if ($this->selectedCategory && $item->economic_classification_id != $this->selectedCategory)
                    continue;
                $name = $item->economicClassification->name ?? __('Uncategorized');
                $classTotals[$name] = ($classTotals[$name] ?? 0) + $item->estimated_amount;
            }
        }
        foreach ($classTotals as $label => $value) {
            $this->classificationData[] = ['label' => $label, 'value' => $value];
        }

        // Program Totals
        $progTotals = [];
        foreach ($activities as $activity) {
            $name = $activity->program->name ?? __('No Program');
            $amount = $activity->lineItems->sum('estimated_amount');
            $progTotals[$name] = ($progTotals[$name] ?? 0) + $amount;
        }
        foreach ($progTotals as $label => $value) {
            $this->programData[] = ['label' => $label, 'value' => $value];
        }

        // Performance Data (Proposed vs Allocated)
        $perfData = [];
        foreach ($activities as $activity) {
            $dirName = $activity->directorate->name ?? __('Unknown');
            if (!isset($perfData[$dirName]))
                $perfData[$dirName] = ['proposed' => 0, 'allocated' => 0];
            $perfData[$dirName]['proposed'] += $activity->lineItems->sum('estimated_amount');
            $perfData[$dirName]['allocated'] += $activity->lineItems->sum(fn($i) => $i->adjustedBudget->approved_amount ?? 0);
        }
        foreach ($perfData as $dir => $v) {
            $this->performanceData[] = ['directorate' => $dir, 'proposed' => $v['proposed'], 'allocated' => $v['allocated']];
        }

        // Indicator & Quarterly Performance
        $indicators = App\Models\Indicator::whereHas('activity', function ($q) use ($activities) {
            $q->whereIn('id', $activities->pluck('id'));
        })->with(['progress' => fn($q) => $q->where('status', 'Verified')])->get();

        $achTotal = 0;
        $count = 0;
        $qSums = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
        $totalTargets = 0;

        foreach ($indicators as $indicator) {
            if ($indicator->annual_target > 0) {
                $totalTargets += $indicator->annual_target;
                $achiedSubTotal = $indicator->progress->sum('achieved_value');
                $achTotal += min(100, ($achiedSubTotal / $indicator->annual_target) * 100);
                $count++;
                foreach ([1, 2, 3, 4] as $q) {
                    $qSums[$q] += $indicator->progress->where('quarter', $q)->sum('achieved_value');
                }
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
        $totalAllocated = $activities->flatMap->lineItems->sum(fn($i) => $i->adjustedBudget->approved_amount ?? 0);
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

<div class="space-y-10 py-6 animate-in fade-in duration-1000" x-data="{
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
    <div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-6">
        <div>
            <h1 class="text-3xl md:text-4xl font-black text-slate-900 tracking-tight leading-none">{{ __('Dashboard') }}
            </h1>
            <p class="text-slate-400 font-bold uppercase tracking-[0.2em] text-[10px] mt-3">
                {{ __('Budget Planning & Performance Overview') }}
            </p>
        </div>
        <div class="flex flex-col sm:flex-row flex-wrap gap-4 items-stretch sm:items-end w-full md:w-auto">
            <div class="flex flex-col gap-1 w-full sm:w-auto">
                <label
                    class="text-[9px] font-black uppercase text-slate-400 tracking-widest px-2">{{ __('Filter Directorate') }}</label>
                <select wire:model.live="selectedDirectorate"
                    class="bg-white border-none shadow-sm rounded-2xl text-xs font-black text-slate-900 focus:ring-2 focus:ring-indigo-600/20 py-2.5 pl-4 pr-10 w-full">
                    <option value="">{{ __('All Directorates') }}</option>
                    @foreach(\App\Models\Directorate::orderBy('name')->get() as $dir)
                        <option value="{{ $dir->id }}">{{ $dir->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex flex-col gap-1 w-full sm:w-auto">
                <label
                    class="text-[9px] font-black uppercase text-slate-400 tracking-widest px-2">{{ __('Filter Category') }}</label>
                <select wire:model.live="selectedCategory"
                    class="bg-white border-none shadow-sm rounded-2xl text-xs font-black text-slate-900 focus:ring-2 focus:ring-indigo-600/20 py-2.5 pl-4 pr-10 w-full">
                    <option value="">{{ __('All Categories') }}</option>
                    @foreach(\App\Models\EconomicClassification::orderBy('name')->get() as $cat)
                        <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex gap-3 w-full sm:w-auto mt-2 sm:mt-0">
                <x-ui.button variant="secondary" size="md" href="{{ route('reports.paa') }}" wire:navigate
                    class="flex-1 sm:flex-none">
                    {{ __('Export') }}
                </x-ui.button>
                <x-ui.button variant="black" shadow="lg" href="{{ route('activities.index') }}" wire:navigate
                    class="flex-1 sm:flex-none">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"
                        class="mr-2">
                        <path d="M5 12h14" />
                        <path d="M12 5v14" />
                    </svg>
                    {{ __('Planning') }}
                </x-ui.button>
            </div>
        </div>
    </div>

    <!-- Stats Grid -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-6">
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

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
        <!-- Main Performance Card -->
        <div class="lg:col-span-8 space-y-8">
            <x-ui.card padding="p-0" class="overflow-hidden">
                <x-slot name="header">
                    <div class="flex items-center justify-between px-6 py-4 border-b border-slate-50">
                        <h3 class="text-[10px] md:text-sm font-black text-slate-900 uppercase tracking-widest">
                            {{ __('Proposed vs Allocated') }}
                        </h3>
                        <x-ui.badge variant="emerald" pulse="true"
                            class="text-[9px]">{{ __('Performance') }}</x-ui.badge>
                    </div>
                </x-slot>

                <div class="p-4 md:p-8">
                    <div class="h-[250px] md:h-[400px] w-full">
                        <canvas id="performanceChart"></canvas>
                    </div>
                </div>
            </x-ui.card>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <x-ui.card padding="p-6">
                    <x-slot name="header">
                        <h3 class="text-[10px] font-black text-slate-900 uppercase tracking-widest">
                            {{ __('Budget by Program') }}
                        </h3>
                    </x-slot>
                    <div class="h-[250px] md:h-[300px] mt-4">
                        <canvas id="programChart"></canvas>
                    </div>
                </x-ui.card>

                <x-ui.card padding="p-6">
                    <x-slot name="header">
                        <h3 class="text-[10px] font-black text-slate-900 uppercase tracking-widest">
                            {{ __('Economic Classification') }}
                        </h3>
                    </x-slot>
                    <div class="h-[250px] md:h-[300px] mt-4">
                        <canvas id="classificationChart"></canvas>
                    </div>
                </x-ui.card>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <x-ui.card padding="p-6">
                    <x-slot name="header">
                        <h3 class="text-[10px] font-black text-slate-900 uppercase tracking-widest">
                            {{ __('Budget Distribution') }}
                        </h3>
                    </x-slot>
                    <div class="h-[200px] md:h-[250px] mt-4">
                        <canvas id="directorateChart"></canvas>
                    </div>
                </x-ui.card>

                <x-ui.card padding="p-6">
                    <x-slot name="header">
                        <h3 class="text-[10px] font-black text-slate-900 uppercase tracking-widest">
                            {{ __('Activity Status') }}
                        </h3>
                    </x-slot>
                    <div class="h-[200px] md:h-[250px] mt-4">
                        <canvas id="statusChart"></canvas>
                    </div>
                </x-ui.card>
            </div>
        </div>

        <!-- Sidebar Widgets -->
        <div class="lg:col-span-4 space-y-8">
            <x-ui.card padding="p-8">
                <x-slot name="header">
                    <h3 class="text-[10px] font-black text-slate-900 uppercase tracking-[0.2em]">{{ __('Operations') }}
                    </h3>
                </x-slot>
                <div class="space-y-4">
                    <x-ui.button variant="secondary" size="lg" class="w-full !justify-start !rounded-2xl !py-4"
                        href="{{ route('activities.create') }}" wire:navigate>
                        <div
                            class="w-8 h-8 rounded-lg bg-indigo-50 flex items-center justify-center text-indigo-600 mr-4">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"
                                stroke-linejoin="round">
                                <path d="M5 12h14" />
                                <path d="M12 5v14" />
                            </svg>
                        </div>
                        <span class="text-xs font-black">{{ __('New Activity') }}</span>
                    </x-ui.button>
                    <x-ui.button variant="secondary" size="lg" class="w-full !justify-start !rounded-2xl !py-4"
                        href="{{ route('reports.paa') }}" wire:navigate>
                        <div
                            class="w-8 h-8 rounded-lg bg-slate-50 flex items-center justify-center text-slate-600 mr-4">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"
                                stroke-linejoin="round">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                                <polyline points="14 2 14 8 20 8" />
                                <line x1="16" x2="8" y1="13" y2="13" />
                                <line x1="16" x2="8" y1="17" y2="17" />
                                <line x1="10" x2="8" y1="9" y2="9" />
                            </svg>
                        </div>
                        <span class="text-xs font-black">{{ __('Report') }}</span>
                    </x-ui.button>
                </div>
            </x-ui.card>

            <div class="bg-slate-900 rounded-[2.5rem] p-6 md:p-10 text-white relative overflow-hidden">
                <h4 class="text-[9px] font-black uppercase tracking-[0.25em] text-slate-500 mb-8">
                    {{ __('PAA Execution') }}
                </h4>
                <div class="flex items-center gap-6 md:gap-8">
                    <div class="relative shrink-0">
                        <svg class="h-20 w-20 md:h-24 md:w-24 transform -rotate-90">
                            <circle cx="48" cy="48" r="42" stroke="currentColor" stroke-width="6" fill="transparent"
                                class="text-slate-800" />
                            <circle cx="48" cy="48" r="42" stroke="currentColor" stroke-width="8" fill="transparent"
                                stroke-dasharray="263.89"
                                stroke-dashoffset="{{ 263.89 - (263.89 * ($paaExecutionRate / 100)) }}"
                                class="text-indigo-500" stroke-linecap="round" />
                        </svg>
                        <div
                            class="absolute inset-0 flex items-center justify-center font-black text-lg md:text-xl tracking-tighter">
                            {{ $paaExecutionRate }}%
                        </div>
                    </div>
                    <div>
                        <p class="text-2xl md:text-3xl font-black leading-none">
                            {{ $paaExecutionRate > 90 ? __('Optimal') : ($paaExecutionRate > 50 ? __('Moderate') : __('Initial')) }}
                        </p>
                        <p class="text-[9px] font-bold text-slate-500 uppercase tracking-widest mt-2">
                            {{ __('Envelope Utilization') }}
                        </p>
                    </div>
                </div>
                <div class="mt-8 pt-8 border-t border-slate-800/50 flex justify-between items-center">
                    <div class="flex gap-1.5">
                        <div
                            class="w-1.5 h-1.5 rounded-full {{ $paaExecutionRate > 30 ? 'bg-emerald-500' : 'bg-slate-700' }}">
                        </div>
                        <div
                            class="w-1.5 h-1.5 rounded-full {{ $paaExecutionRate > 60 ? 'bg-emerald-500' : 'bg-slate-700' }}">
                        </div>
                        <div
                            class="w-1.5 h-1.5 rounded-full {{ $paaExecutionRate > 90 ? 'bg-emerald-500' : 'bg-slate-700' }}">
                        </div>
                    </div>
                    <span
                        class="text-[9px] font-black text-slate-500 tracking-widest uppercase">{{ __('Financial Adherence') }}</span>
                </div>

                <div class="mt-6 md:mt-8">
                    <p class="text-[9px] font-black text-slate-500 uppercase tracking-widest mb-4">
                        {{ __('Quarterly Performance') }}
                    </p>
                    <div class="h-20 md:h-24 w-full">
                        <canvas id="quarterlyProgressChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>