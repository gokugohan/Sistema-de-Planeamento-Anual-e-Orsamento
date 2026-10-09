<?php

use Livewire\Volt\Component;
use App\Models\Directorate;
use App\Models\Program;
use App\Models\Activity;
use App\Models\FiscalEnvelope;
use App\Models\EconomicClassification;
use App\Models\LineItem;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public $tab = 'directorates';
    public $search = '';
    public $activeYear;
    public $selectedQuarter = 'all';
    public $selectedDirectorateId = null;
    public $showAdvancedView = false;

    public function mount()
    {
        $this->activeYear = FiscalEnvelope::getEffectiveYear();
    }

    public function openAdvancedView($id)
    {
        $this->selectedDirectorateId = $id;
        $this->showAdvancedView = true;
    }

    public function with(): array
    {
        // Calculate available years dynamically
        $envelopeYears = FiscalEnvelope::pluck('fiscal_year')->unique();

        $latestEnvelopeYear = $envelopeYears->max();

        $hasNextYearPAA = Activity::where('fiscal_year', $latestEnvelopeYear + 1)
            ->exists();

        $availableYears = $envelopeYears->concat($hasNextYearPAA ? [$latestEnvelopeYear + 1] : [])
            ->unique()
            ->sort()
            ->values();

        // Bypass global production scope to allow seeing simulation/test data in registry
        $envelope = FiscalEnvelope::where('fiscal_year', $this->activeYear)->first();

        $selectedDirectorate = null;
        if ($this->selectedDirectorateId) {
            $selectedDirectorate = Directorate::with(['parent', 'children', 'activities' => fn($q) => $q->where('fiscal_year', $this->activeYear), 'activities.lineItems' => fn($q) => $q->where('fiscal_year', $this->activeYear), 'activities.lineItems.adjustedBudget'])
                ->find($this->selectedDirectorateId);
        }

        $totalAllocated = Activity::where('fiscal_year', $this->activeYear)
            ->with(['lineItems' => fn($q) => $q->where('fiscal_year', $this->activeYear), 'lineItems.adjustedBudget'])
            ->get()
            ->flatMap->lineItems
            ->filter(fn($p) => $p->adjustedBudget)
            ->sum(fn($p) => $p->adjustedBudget->approved_amount);

        return [
            'envelope' => $envelope,
            'totalAllocated' => $totalAllocated,
            'remainingBudget' => ($envelope?->total_amount ?? 0) - $totalAllocated,
            'directorates' => $this->getDirectoratesData(),
            'programs' => $this->getProgramsData(),
            'activities' => $this->getActivitiesData(),
            'lineItems' => $this->getLineItemsData(),
            'categorySummaries' => $this->getCategorySummaries(),
            'quarterlyActivitiesSummary' => $this->getQuarterlyActivitiesSummary(),
            'selectedDirectorateDetail' => $selectedDirectorate,
            'availableYears' => $availableYears,
        ];
    }

    private function getDirectoratesData()
    {
        // When searching, we might want a flat list, but user requested tree view UI change.
        // We'll paginate roots to maintain performance.
        $roots = Directorate::with(['children.children', 'parent'])
            ->whereNull('parent_id')
            ->when($this->search, function ($q) {
                // If searching, we show all directorates matching, potentially breaking tree feel,
                // but let's prioritize the "monitoring" tree of roots for now if possible.
                // Actually, if we search, a flat list is often expected. 
                // But for "Tree UI", let's matches roots or just show everything hierarchical for roots that match or have children that match.
                $q->where(fn($sq) => $sq->where('name', 'like', "%{$this->search}%")
                    ->orWhere('code', 'like', "%{$this->search}%"));
            })
            ->paginate(10);

        // Fetch ALL activities for active year to avoid N+1 inside loops
        $activities = Activity::where('fiscal_year', $this->activeYear)
            ->with(['lineItems.adjustedBudget'])
            ->get()
            ->groupBy('directorate_id');

        // Recursively attach stats to the collection
        $roots->getCollection()->transform(function ($d) use ($activities) {
            return $this->attachStatsRecursively($d, $activities);
        });

        return $roots;
    }

    private function attachStatsRecursively($d, $activities)
    {
        $dirActivities = $activities->get($d->id, collect());

        $allocated = $dirActivities->flatMap->lineItems
            ->filter(fn($p) => $p->adjustedBudget)
            ->sum(fn($p) => $p->adjustedBudget->approved_amount);

        // Simulated SPENT for now matching the registry theme
        $spent = $dirActivities->flatMap->lineItems->sum(fn($p) => rand(10000, 50000));

        $d->budget_allocated = $allocated;
        $d->spent = $spent;
        $d->utilization = $allocated > 0 ? min(100, ($spent / $allocated) * 100) : 0;

        if ($d->relationLoaded('children')) {
            foreach ($d->children as $child) {
                $this->attachStatsRecursively($child, $activities);
            }
        }

        return $d;
    }

    private function getProgramsData()
    {
        $programs = Program::with([
            'activities' => function ($q) {
                $q->where('fiscal_year', $this->activeYear)
                    ->with(['lineItems.economicClassification', 'lineItems.adjustedBudget']);
            }
        ])
            ->when($this->search, fn($q) => $q->where('name', 'like', "%{$this->search}%")
                ->orWhere('oge_code', 'like', "%{$this->search}%"))
            ->get();

        foreach ($programs as $p) {
            $p->budget_allocated = $p->activities->flatMap->lineItems
                ->filter(fn($li) => $li->adjustedBudget)
                ->sum(fn($li) => $li->adjustedBudget->approved_amount);

            $p->spent = $p->activities->flatMap->lineItems->sum(fn($li) => rand(10000, 50000));
            $p->utilization = $p->budget_allocated > 0 ? min(100, ($p->spent / $p->budget_allocated) * 100) : 0;

            // Grouping by Economic Category (Classification)
            $p->categories = $p->activities->flatMap->lineItems->groupBy('economic_classification_id')
                ->map(function ($items, $classId) use ($p) {
                    $class = $items->first()->economicClassification;
                    $allocated = $items->filter(fn($li) => $li->adjustedBudget)->sum(fn($li) => $li->adjustedBudget->approved_amount);
                    $spent = $items->sum(fn($li) => rand(5000, 15000));

                    $actIds = $items->pluck('activity_id')->unique();
                    $activities = $p->activities->whereIn('id', $actIds)->map(function ($a) use ($items) {
                        // Stats for this activity WITHIN THIS CATEGORY
                        $aClassItems = $items->where('activity_id', $a->id);
                        $a->cat_allocated = $aClassItems->filter(fn($li) => $li->adjustedBudget)->sum(fn($li) => $li->adjustedBudget->approved_amount);
                        $a->cat_spent = $aClassItems->sum(fn($li) => rand(1000, 5000));
                        $a->cat_utilization = $a->cat_allocated > 0 ? min(100, ($a->cat_spent / $a->cat_allocated) * 100) : 0;
                        return $a;
                    });

                    return (object) [
                        'id' => $classId,
                        'name' => $class?->name ?? 'N/A',
                        'code' => $class?->code ?? 'N/A',
                        'budget_allocated' => $allocated,
                        'spent' => $spent,
                        'utilization' => $allocated > 0 ? min(100, ($spent / $allocated) * 100) : 0,
                        'activities' => $activities
                    ];
                });
        }

        return $programs;
    }

    private function getActivitiesData()
    {
        return Activity::where('fiscal_year', $this->activeYear)
            ->when($this->search, fn($q) => $q->where('description', 'like', "%{$this->search}%")
                ->orWhere('code', 'like', "%{$this->search}%"))
            ->when($this->selectedQuarter !== 'all', fn($q) => $q->where('quarter', $this->selectedQuarter))
            ->with(['program', 'directorate', 'lineItems.adjustedBudget'])
            ->paginate(10);
    }

    private function getQuarterlyActivitiesSummary()
    {
        $summary = [];
        for ($i = 1; $i <= 4; $i++) {
            $acts = Activity::where('fiscal_year', $this->activeYear)->where('quarter', $i)->get();
            $budget = $acts->flatMap->lineItems->filter(fn($li) => $li->adjustedBudget)->sum(fn($li) => $li->adjustedBudget->approved_amount);

            $summary[$i] = [
                'count' => $acts->count(),
                'budget' => $budget,
                'avg_progress' => 45 // Simulated avg 
            ];
        }
        return $summary;
    }

    private function getLineItemsData()
    {
        return LineItem::whereHas('activity', fn($q) => $q->where('fiscal_year', $this->activeYear))
            ->when($this->search, fn($q) => $q->where('account_code', 'like', "%{$this->search}%"))
            ->with(['activity' => fn($q) => $q->where('fiscal_year', $this->activeYear), 'economicClassification', 'adjustedBudget'])
            ->get();
    }

    private function getCategorySummaries()
    {
        $summaries = [];
        $categories = EconomicClassification::orderBy("code")->pluck('code');

        foreach ($categories as $code) {
            $classification = EconomicClassification::where('code', $code)->first();
            $total = LineItem::whereHas('activity', fn($q) => $q->where('fiscal_year', $this->activeYear))
                ->whereHas('economicClassification', fn($q) => $q->where('code', $code))
                ->sum('estimated_amount');

            $summaries[$code] = [
                'name' => $classification->name ?? 'Category',
                'total' => $total,
                'count' => LineItem::whereHas('activity', fn($q) => $q->where('fiscal_year', $this->activeYear))
                    ->whereHas('economicClassification', fn($q) => $q->where('code', $code))
                    ->count(),
                'spent_pc' => rand(30, 60) // Simulated
            ];
        }
        return $summaries;
    }

    public function setTab($tab)
    {
        $this->tab = $tab;
        $this->resetPage();
    }

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function updatingSelectedQuarter()
    {
        $this->resetPage();
    }
}; ?>

<div class="space-y-10 py-6 animate-in fade-in duration-700">
    <div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-6">
        <div>
            <h1 class="page-title">
                {{ __('Entities Registry') }}
            </h1>
            <div class="flex items-center gap-4 mt-4">
                <p class="page-subtitle">
                    {{ __('Node Controller for') }}
                </p>
                <select wire:model.live="activeYear"
                    class="bg-slate-100 border-none rounded-lg text-[10px] font-black uppercase tracking-widest py-1 px-3 focus:ring-2 focus:ring-indigo-500/20 cursor-pointer">
                    @foreach($availableYears as $year)
                        <option value="{{ $year }}">
                            {{ $year }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div
        class="flex items-center gap-2 p-1.5 bg-slate-100/80 w-fit rounded-2xl border border-slate-200/50 backdrop-blur-sm shadow-sm">
        <button wire:click="setTab('directorates')"
            class="px-6 py-2.5 rounded-xl text-[11px] font-black uppercase tracking-widest transition-all {{ $tab === 'directorates' ? 'bg-white text-indigo-600 shadow-md ring-1 ring-slate-200/50' : 'text-slate-400 hover:text-slate-600 hover:bg-white/50' }}">
            {{ __('Directorates') }}
        </button>
        <button wire:click="setTab('programs')"
            class="px-6 py-2.5 rounded-xl text-[11px] font-black uppercase tracking-widest transition-all {{ $tab === 'programs' ? 'bg-white text-indigo-600 shadow-md ring-1 ring-slate-200/50' : 'text-slate-400 hover:text-slate-600 hover:bg-white/50' }}">
            {{ __('Programs') }}
        </button>
        <button wire:click="setTab('activities')"
            class="px-6 py-2.5 rounded-xl text-[11px] font-black uppercase tracking-widest transition-all {{ $tab === 'activities' ? 'bg-white text-indigo-600 shadow-md ring-1 ring-slate-200/50' : 'text-slate-400 hover:text-slate-600 hover:bg-white/50' }}">
            {{ __('Activities') }}
        </button>
        <button wire:click="setTab('line_items')"
            class="px-6 py-2.5 rounded-xl text-[11px] font-black uppercase tracking-widest transition-all {{ $tab === 'line_items' ? 'bg-white text-indigo-600 shadow-md ring-1 ring-slate-200/50' : 'text-slate-400 hover:text-slate-600 hover:bg-white/50' }}">
            {{ __('Line Items') }}
        </button>
    </div>

    <!-- Financial Metrics Panel -->
    @if($tab === 'directorates')
        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <div
                class="bg-white p-8 rounded-[2rem] border border-slate-100 shadow-sm shadow-indigo-500/5 group hover:shadow-indigo-500/10 transition-all duration-500">
                <div class="flex justify-between items-start mb-6">
                    <div class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">
                        {{ __('Fiscal Authority') }}
                    </div>
                    <div class="p-2 bg-indigo-50 rounded-xl text-indigo-600"><svg xmlns="http://www.w3.org/2000/svg"
                            width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 2v20" />
                            <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
                        </svg></div>
                </div>
                <div class="text-3xl font-black text-slate-900 leading-none">$
                    {{ number_format($envelope?->total_amount ?? 0, 0) }}
                </div>
                <p class="text-[10px] font-bold text-slate-500 mt-4 italic uppercase tracking-tighter">
                    {{ __('Global Limit for FY') }} {{ $activeYear }}
                </p>
            </div>

            <div
                class="bg-indigo-600 p-8 rounded-[2rem] shadow-xl shadow-indigo-600/20 text-white group hover:scale-[1.02] transition-all duration-500">
                <div class="flex justify-between items-start mb-6">
                    <div class="text-[10px] font-black text-indigo-200 uppercase tracking-[0.2em]">
                        {{ __('Allocated Load') }}
                    </div>
                    <div class="p-2 bg-white/10 rounded-xl text-white"><svg xmlns="http://www.w3.org/2000/svg" width="16"
                            height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path d="M2 13s2 1 10 1 10-1 10-1" />
                            <path d="m2 8 10 1 10-1" />
                            <path d="m2 18 10 1 10-1" />
                            <path d="m2 3 10 1 10-1" />
                        </svg></div>
                </div>
                <div class="text-3xl font-black leading-none">$ {{ number_format($totalAllocated, 0) }}</div>
                <div class="mt-6 flex items-center gap-3">
                    <div class="flex-1 h-1.5 bg-white/20 rounded-full overflow-hidden">
                        <div class="h-full bg-white transition-all duration-1000"
                            style="width: {{ ($envelope?->total_amount ?? 0) > 0 ? ($totalAllocated / $envelope->total_amount) * 100 : 0 }}%">
                        </div>
                    </div>
                    <span
                        class="text-[10px] font-black">{{ number_format((($envelope?->total_amount ?? 0) > 0 ? ($totalAllocated / $envelope->total_amount) * 100 : 0), 1) }}%</span>
                </div>
            </div>

            <div class="bg-slate-900 p-8 rounded-[2rem] shadow-xl shadow-slate-900/20 text-white group">
                <div class="flex justify-between items-start mb-6">
                    <div class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em]">
                        {{ __('Available Space') }}
                    </div>
                    <div class="p-2 bg-slate-800 rounded-xl text-emerald-400"><svg xmlns="http://www.w3.org/2000/svg"
                            width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"
                            stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="20 6 9 17 4 12" />
                        </svg></div>
                </div>
                <div
                    class="text-3xl font-black leading-none {{ $remainingBudget < 0 ? 'text-rose-400' : 'text-emerald-400' }}">
                    $ {{ number_format($remainingBudget, 0) }}</div>
                <p class="text-[10px] font-bold text-slate-500 mt-4 uppercase tracking-widest">
                    {{ __('Unallocated Reserves') }}
                </p>
            </div>
        </div>
    @endif

    <!-- Main Content Table Card -->
    <x-ui.card padding="p-0">
        <x-slot name="header">
            <div class="flex flex-col md:flex-row justify-between items-center gap-6">
                <div>
                    <h2 class="text-sm font-black text-slate-900 uppercase tracking-widest">{{ Str::title($tab) }}
                        {{ __('Monitoring') }}
                    </h2>
                    <p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest mt-1">
                        {{ __('Infrastructure Node Overview') }}
                    </p>
                </div>
                <div class="flex items-center gap-4">
                    @if($tab === 'activities')
                        <div class="flex items-center gap-2 mr-2">
                            <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest">
                                {{ __('Quarter') }}:
                            </div>
                            <select wire:model.live="selectedQuarter"
                                class="bg-slate-50 border-slate-100 rounded-xl text-[10px] font-black uppercase tracking-widest py-1.5 focus:ring-4 focus:ring-indigo-500/5 focus:border-indigo-500 transition-all cursor-pointer">
                                <option value="all">{{ __('Global') }}</option>
                                <option value="1">Q1</option>
                                <option value="2">Q2</option>
                                <option value="3">Q3</option>
                                <option value="4">Q4</option>
                            </select>
                        </div>
                    @endif
                    <div class="relative group">
                        <span
                            class="absolute inset-y-0 left-0 pl-4 flex items-center text-slate-300 group-focus-within:text-indigo-500 transition-colors">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"
                                stroke-linejoin="round">
                                <circle cx="11" cy="11" r="8" />
                                <path d="m21 21-4.3-4.3" />
                            </svg>
                        </span>
                        <input type="text" wire:model.live.debounce.300ms="search"
                            placeholder="{{ __('Filter records...') }}"
                            class="pl-12 h-11 w-72 bg-slate-50 border-slate-100 rounded-2xl text-[11px] font-bold text-slate-600 focus:ring-4 focus:ring-indigo-500/5 focus:border-indigo-500 focus:bg-white transition-all placeholder:text-slate-300">
                    </div>
                </div>
            </div>
        </x-slot>

        <div class="overflow-x-auto">
            @if($tab === 'directorates')
                <x-ui.table :headers="[__('Entity Name'), __('Director'), __('Budget Allocated'), __('Utilization'), __('Status'), '']">
                        <x-slot name="actions"></x-slot>
                        @forelse($directorates as $d)
                            @include('livewire.pages.admin.entities-tabs.directorate-row', ['d' => $d, 'level' => 0])
                        @empty
                            <tr>
                                <td colspan="8" class="p-12 text-center text-sm text-gray-500 italic">
                                    {{ __('No directorates found.') }}</td>
                            </tr>
                        @endforelse
                    </x-ui.table>
            @elseif($tab === 'programs')
                <x-ui.table :headers="[__('Program Name'), __('Directorate'), __('Program Manager'), __('Budget'), __('Utilization') . ' %', 'KPIs', __('Status')]">
                    <x-slot name="actions"></x-slot>
                    @forelse($programs as $p)
                        @include('livewire.pages.admin.entities-tabs.program-row', ['p' => $p])
                    @empty
                        <tr>
                            <td colspan="7" class="p-12 text-center text-sm text-gray-500 italic">
                                {{ __('No programs found.') }}</td>
                        </tr>
                    @endforelse
                </x-ui.table>
            @elseif($tab === 'activities')
                <x-ui.table :headers="[__('Code'), __('Activity Name'), __('Program'), __('Budget'), __('Progress'), __('Quarter'), __('Status')]">
                    <x-slot name="actions"></x-slot>
                    @foreach($activities as $a)
                        <tr class="hover:bg-gray-50/50 transition-colors group">
                            <td class="px-8 py-5 text-gray-900 font-black tracking-tighter uppercase">
                                {{ $a->code ?: 'ACT-' . $a->id }}</td>
                            <td class="px-8 py-5">
                                <div class="font-bold text-gray-900 leading-tight">{{ $a->description }}</div>
                                <div class="text-[10px] text-gray-400 mt-1 uppercase font-bold tracking-tighter italic">
                                    {{ $a->directorate?->code ?? 'N/A' }}</div>
                            </td>
                            <td class="px-8 py-5 italic text-gray-500">{{ $a->program?->name ?? 'N/A' }}</td>
                            <td class="px-8 py-5 font-bold text-gray-900">$
                                {{ number_format($a->lineItems->sum('estimated_amount'), 2) }}</td>
                            <td class="px-8 py-5">
                                <div class="flex items-center gap-2">
                                    <div class="w-12 h-1 bg-gray-100 rounded-full overflow-hidden">
                                        <div style="width:45%" class="h-full bg-black rounded-full"></div>
                                    </div>
                                    <span class="text-[10px] font-black text-gray-900">45%</span>
                                </div>
                            </td>
                            <td class="px-8 py-5 font-black text-gray-400 uppercase tracking-widest">Q{{ $a->quarter }}</td>
                            <td class="px-8 py-5"><x-ui.badge variant="gray">{{ __('Active') }}</x-ui.badge></td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @elseif($tab === 'line_items')
                <x-ui.table :headers="[__('Code'), __('Line Item Name'), __('Category'), __('Activity'), __('Account'), __('Budget'), __('Status')]">
                    <x-slot name="actions"></x-slot>
                    @foreach($lineItems as $bp)
                        <tr class="hover:bg-gray-50/50 transition-colors group text-sm">
                            <td class="px-8 py-5 text-gray-900 font-black tracking-tighter uppercase">
                                {{ $bp->code ?: 'ITEM-' . $bp->id }}</td>
                            <td class="px-8 py-5 font-bold text-gray-900 leading-tight">
                                {{ $bp->economicClassification?->description ?: 'Budget Line Item' }}
                                <div class="text-[10px] text-gray-300 mt-1 italic font-bold">{{ $bp->activity?->directorate?->code ?? 'N/A' }}</div>
                            </td>
                            <td class="px-8 py-5"><x-ui.badge
                                    variant="gray">{{ $bp->economicClassification?->name ?? 'N/A' }}</x-ui.badge></td>
                            <td class="px-8 py-5 italic text-gray-500">{{ $bp->activity?->description }}</td>
                            <td class="px-8 py-5 font-mono font-bold text-xs">{{ $bp->account_code ?: '5100-000' }}</td>
                            <td class="px-8 py-5 font-bold text-gray-900">$ {{ number_format($bp->estimated_amount, 2) }}</td>
                            <td class="px-8 py-5"><x-ui.badge variant="gray">{{ __('Active') }}</x-ui.badge></td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        </div>
    </x-ui.card>

    @php
        $paginator = null;
        if ($tab === 'activities' && $activities instanceof \Illuminate\Pagination\LengthAwarePaginator) {
            $paginator = $activities;
        } elseif ($tab === 'directorates' && $directorates instanceof \Illuminate\Pagination\LengthAwarePaginator) {
            $paginator = $directorates;
        }
    @endphp

    @if($paginator)
        <div class="p-6 bg-white border border-slate-100 rounded-[2rem] shadow-sm mt-8 print:hidden">
            {{ $paginator->links() }}
        </div>
    @endif

    @if($showAdvancedView && $selectedDirectorateDetail)
        <div class="fixed inset-0 z-[100] flex justify-end animate-in fade-in duration-300">
            <div wire:click="$set('showAdvancedView', false)" class="absolute inset-0 bg-slate-900/40 backdrop-blur-sm">
            </div>

            <div
                class="relative w-full max-w-2xl bg-white shadow-2xl h-full overflow-y-auto animate-in slide-in-from-right duration-500">
                <!-- Header -->
                <div
                    class="p-8 border-b border-slate-50 flex justify-between items-start sticky top-0 bg-white/80 backdrop-blur-md z-20">
                    <div>
                        <div class="flex items-center gap-3 mb-2">
                            <span
                                class="px-3 py-1 bg-slate-100 rounded-lg text-[10px] font-black uppercase tracking-widest text-slate-500">
                                {{ $selectedDirectorateDetail->code }}
                            </span>
                            <x-ui.badge variant="black" pulse="true">{{ __('Active System') }}</x-ui.badge>
                        </div>
                        <h3 class="text-3xl font-black text-slate-900 italic tracking-tight">
                            {{ $selectedDirectorateDetail->name }}</h3>
                        <p class="text-[10px] text-slate-400 font-bold uppercase tracking-[0.2em] mt-2">
                            {{ __('Infrastructure Control Center Detail') }}</p>
                    </div>
                    <button wire:click="$set('showAdvancedView', false)"
                        class="p-3 hover:bg-slate-100 rounded-2xl transition-all text-slate-400 hover:text-slate-900">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="18" y1="6" x2="6" y2="18" />
                            <line x1="6" y1="6" x2="18" y2="18" />
                        </svg>
                    </button>
                </div>

                <div class="p-8 space-y-10">
                    <!-- Quick Metrics -->
                    <div class="grid grid-cols-3 gap-4">
                        <div class="p-6 bg-slate-50 rounded-[2rem] border border-slate-100">
                            <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">{{ __('Programs') }}
                            </p>
                            <p class="text-2xl font-black text-slate-900 mt-2">
                                {{ App\Models\Program::whereHas('activities', function($q) use ($selectedDirectorateDetail) {
                                    $q->withoutGlobalScopes()
                                      ->where('directorate_id', $selectedDirectorateDetail->id)
                                      ->where('fiscal_year', $this->activeYear);
                                })->count() }}
                            </p>
                        </div>
                        <div class="p-6 bg-indigo-600 rounded-[2rem] text-white">
                            <p class="text-[9px] font-black text-indigo-200 uppercase tracking-widest">
                                {{ __('Activities') }}</p>
                            <p class="text-2xl font-black mt-2">
                                {{ $selectedDirectorateDetail->activities()->withoutGlobalScopes()->where('fiscal_year', $this->activeYear)->count() }}
                            </p>
                        </div>
                        <div class="p-6 bg-slate-900 rounded-[2rem] text-white">
                            <p class="text-[9px] font-black text-slate-500 uppercase tracking-widest">{{ __('Sub-units') }}
                            </p>
                            <p class="text-2xl font-black mt-2">{{ $selectedDirectorateDetail->children->count() }}</p>
                        </div>
                    </div>

                    <!-- Hierarchy & Context -->
                    <div class="space-y-4">
                        <h4 class="text-xs font-black text-slate-400 uppercase tracking-[0.2em] ml-2">
                            {{ __('Organizational Context') }}</h4>
                        <div class="bg-white border border-slate-100 rounded-[2.5rem] p-8 shadow-sm">
                            <div class="flex items-start gap-6">
                                <div class="flex-1 space-y-6">
                                    <div>
                                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3">
                                            {{ __('Superior Authority') }}</p>
                                        @if($selectedDirectorateDetail->parent)
                                            <div
                                                class="flex items-center gap-4 p-4 bg-slate-50 rounded-2xl border border-slate-100">
                                                <div class="p-2 bg-white rounded-xl text-indigo-600 shadow-sm"><svg
                                                        xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                                                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"
                                                        stroke-linecap="round" stroke-linejoin="round">
                                                        <path d="m12 19-7-7 7-7" />
                                                        <path d="M19 12H5" />
                                                    </svg></div>
                                                <div>
                                                    <p class="text-xs font-black text-slate-900">
                                                        {{ $selectedDirectorateDetail->parent->name }}</p>
                                                    <p class="text-[9px] text-slate-400 font-bold uppercase tracking-widest">
                                                        {{ $selectedDirectorateDetail->parent->code }}</p>
                                                </div>
                                            </div>
                                        @else
                                            <div
                                                class="p-4 bg-slate-50/50 rounded-2xl border border-dashed border-slate-200 text-center">
                                                <p class="text-[10px] text-slate-400 font-bold italic">
                                                    {{ __('Independent Top-level Authority') }}</p>
                                            </div>
                                        @endif
                                    </div>

                                    <div>
                                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3">
                                            {{ __('Unit Classification') }}</p>
                                        <div class="flex items-center gap-3">
                                            <x-ui.badge variant="gray"
                                                class="!text-xs">{{ $selectedDirectorateDetail->type ?: __('National Directorate') }}</x-ui.badge>
                                        </div>
                                    </div>
                                </div>
                                <div class="w-px h-auto self-stretch bg-slate-100"></div>
                                <div class="flex-1">
                                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3">
                                        {{ __('Functional Jurisdiction') }}</p>
                                    <p class="text-sm text-slate-600 font-medium leading-relaxed italic">
                                        {{ $selectedDirectorateDetail->description ?: __('No detailed structural definition provided for this node.') }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Leadership Team -->
                    <div class="space-y-4">
                        <h4 class="text-xs font-black text-slate-400 uppercase tracking-[0.2em] ml-2">
                            {{ __('Command & Control') }}</h4>
                        <div class="grid grid-cols-2 gap-6">
                            <div
                                class="bg-white border border-slate-100 rounded-[2.5rem] p-8 shadow-sm flex items-center gap-4">
                                <div
                                    class="w-12 h-12 bg-slate-100 rounded-2xl flex items-center justify-center text-slate-400">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                                        stroke-linejoin="round">
                                        <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2" />
                                        <circle cx="12" cy="7" r="4" />
                                    </svg>
                                </div>
                                <div>
                                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">
                                        {{ __('Managing Officer') }}</p>
                                    <p class="text-sm font-black text-slate-900 mt-1">
                                        {{ $selectedDirectorateDetail->director_name ?: 'Maria Silva' }}</p>
                                </div>
                            </div>
                            <div
                                class="bg-white border border-slate-100 rounded-[2.5rem] p-8 shadow-sm flex items-center gap-4">
                                <div
                                    class="w-12 h-12 bg-slate-100 rounded-2xl flex items-center justify-center text-slate-400">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                                        stroke-linejoin="round">
                                        <path
                                            d="M22 17a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V9.5C2 7 4 5 6.5 5H18c2.2 0 4 1.8 4 4v8Z" />
                                        <path d="m22 7-10 7L2 7" />
                                    </svg>
                                </div>
                                <div>
                                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">
                                        {{ __('Secure Channel') }}</p>
                                    <p class="text-sm font-black text-slate-900 mt-1">{{
                                        $selectedDirectorateDetail->director_email ?: 'maria.silva@msa.gov.tl' }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Financial Performance Summary -->
                    <div class="space-y-4">
                        <h4 class="text-xs font-black text-slate-400 uppercase tracking-[0.2em] ml-2">
                            {{ __('Fiscal Utilization Roll-up') }}</h4>
                        @php
                            $allocatedDetail = $selectedDirectorateDetail->activities()
                                ->withoutGlobalScopes()
                                ->where('fiscal_year', $activeYear)
                                ->with(['lineItems' => fn($q) => $q->withoutGlobalScopes(), 'lineItems.adjustedBudget'])
                                ->get()
                                ->flatMap->lineItems
                                ->filter(fn($p) => $p->adjustedBudget)
                                ->sum(fn($p) => $p->adjustedBudget->approved_amount);
                            $pcDetail = ($envelope?->total_amount ?? 0) > 0 ? ($allocatedDetail / $envelope->total_amount) * 100 : 0;
                        @endphp
                        <div
                            class="bg-slate-900 rounded-[3rem] p-10 text-white shadow-2xl shadow-slate-900/20 relative overflow-hidden">
                            <div
                                class="absolute top-0 right-0 w-64 h-64 bg-indigo-500/10 rounded-full blur-3xl -mr-32 -mt-32">
                            </div>

                            <div class="relative z-10">
                                <div class="flex justify-between items-end mb-10">
                                    <div>
                                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-[0.3em] mb-4">
                                            {{ __('Consolidated Allocation') }}</p>
                                        <p class="text-5xl font-black italic tracking-tighter">$
                                            {{ number_format($allocatedDetail, 2) }}</p>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-[0.3em] mb-4">
                                            {{ __('Fiscal Load') }}</p>
                                        <p class="text-3xl font-black text-indigo-400">{{ number_format($pcDetail, 1) }}%
                                        </p>
                                    </div>
                                </div>

                                <div class="space-y-4">
                                    <div
                                        class="flex justify-between text-[10px] font-black uppercase tracking-widest text-slate-500">
                                        <span>{{ __('Global Limit Impact') }}</span>
                                        <span>$ {{ number_format($envelope?->total_amount ?? 0, 0) }}</span>
                                    </div>
                                    <div class="h-2.5 bg-slate-800 rounded-full overflow-hidden">
                                        <div class="h-full bg-indigo-500 rounded-full shadow-[0_0_15px_rgba(99,102,241,0.5)] transition-all duration-1000"
                                            style="width: {{ $pcDetail }}%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer Action -->
                <div class="p-8 bg-slate-50 border-t border-slate-100 flex justify-between items-center sticky bottom-0">
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">
                        {{ __('Internal Use Only • Encrypted Node') }}</p>
                    <x-ui.button variant="black" class="!rounded-xl !px-8">
                        {{ __('Open Operations Terminal') }}
                    </x-ui.button>
                </div>
            </div>
        </div>
    @endif

    <script>
        function toggleTree(rowId) {
            const row = document.getElementById(rowId);
            if (!row) return;
            const btn = row.querySelector('.tree-toggle-btn svg');
            if (!btn) return;
            
            const isExpanding = !btn.classList.contains('rotate-90');
            
            if (isExpanding) {
                btn.classList.add('rotate-90');
            } else {
                btn.classList.remove('rotate-90');
            }

            toggleChildrenVisibility(rowId, isExpanding);
        }

        function toggleChildrenVisibility(parentId, visible) {
            const children = document.querySelectorAll(`[data-parent-id="${parentId}"]`);
            children.forEach(child => {
                if (visible) {
                    child.classList.remove('hidden');
                } else {
                    child.classList.add('hidden');
                    const childBtn = child.querySelector('.tree-toggle-btn svg');
                    if (childBtn) {
                        childBtn.classList.remove('rotate-90');
                    }
                    toggleChildrenVisibility(child.id, false);
                }
            });
        }
    </script>
</div>