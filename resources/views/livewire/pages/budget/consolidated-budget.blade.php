<?php

use Livewire\Volt\Component;
use App\Models\Activity;
use App\Models\Program;
use App\Models\FiscalEnvelope;

use Livewire\Attributes\On;

new class extends Component {
    public $activeTab = 'envelope'; // 'envelope' or 'list'
    public $fiscalYear;
    public $requestedTotal;
    public $approvedTotal;
    public $programs;
    public $envelope;

    public function mount(): void
    {
        $activeYear = \App\Models\FiscalEnvelope::getEffectiveYear();
        $this->fiscalYear = request()->query('year', $activeYear);
        $this->loadData();
    }

    public function loadData(): void
    {
        $this->requestedTotal = App\Models\LineItem::whereHas('activity', fn($q) => $q->where('fiscal_year', $this->fiscalYear))->sum('estimated_amount');

        $this->envelope = FiscalEnvelope::where('fiscal_year', $this->fiscalYear)->first();
        $this->approvedTotal = $this->envelope ? $this->envelope->total_amount : 0;

        $this->programs = Program::with([
            'activities' => function ($q) {
                $q->where('fiscal_year', $this->fiscalYear)->with('lineItems.adjustedBudget');
            }
        ])->get()->map(function ($program) {
            $program->requested = $program->activities->flatMap->lineItems->sum('estimated_amount');

            $program->approved = $program->activities->flatMap->lineItems->sum(function ($item) {
                return $item->adjustedBudget->approved_amount ?? 0;
            });
            return $program;
        });
    }

    #[On('switch-budget-tab')]
    public function switchTab($tab): void
    {
        $this->activeTab = $tab;
    }
}; ?>

<div class="space-y-10 py-6 animate-in fade-in duration-700">
    <!-- Tabs Navigation -->
    <div class="flex items-center gap-2 p-1.5 bg-slate-100/50 w-fit rounded-2xl border border-slate-200 shadow-sm">
        <button wire:click="switchTab('envelope')"
            class="inline-flex items-center justify-center whitespace-nowrap rounded-xl px-4 py-2.5 text-xs font-black transition-all {{ $activeTab === 'envelope' ? 'bg-white shadow-sm text-slate-900' : 'text-slate-500 hover:text-slate-900' }}">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-2">
                <rect width="7" height="9" x="3" y="3" rx="1" />
                <rect width="7" height="5" x="14" y="3" rx="1" />
                <rect width="7" height="9" x="14" y="12" rx="1" />
                <rect width="7" height="5" x="3" y="16" rx="1" />
            </svg>
            {{ __('Current Envelope') }}
        </button>
        <button wire:click="switchTab('list')"
            class="inline-flex items-center justify-center whitespace-nowrap rounded-xl px-4 py-2.5 text-xs font-black transition-all {{ $activeTab === 'list' ? 'bg-white shadow-sm text-slate-900' : 'text-slate-500 hover:text-slate-900' }}">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-2">
                <line x1="8" x2="21" y1="6" y2="6" />
                <line x1="8" x2="21" y1="12" y2="12" />
                <line x1="8" x2="21" y1="18" y2="18" />
                <line x1="3" x2="3.01" y1="6" y2="6" />
                <line x1="3" x2="3.01" y1="12" y2="12" />
                <line x1="3" x2="3.01" y1="18" y2="18" />
            </svg>
            {{ __('Envelope List') }}
        </button>
    </div>

    <!-- Content Area -->
    <livewire:pages.budget.manage-envelopes :viewMode="$activeTab === 'envelope' ? 'current' : 'list'"
        wire:key="manage-envelopes-{{ $activeTab }}" />


</div>