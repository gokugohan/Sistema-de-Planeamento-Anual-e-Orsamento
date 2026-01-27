<?php

use Livewire\Volt\Component;
use App\Models\FiscalEnvelope;
use App\Models\FiscalEnvelopeCategoryAllocation;
use App\Models\EconomicClassification;
use Carbon\Carbon;
use App\Models\Program;

use App\Models\AdjustedBudget;
use App\Models\Indicator;
use App\Models\AuditLog;
use App\Models\LineItem;
use App\Models\Activity;
use Livewire\Attributes\On;


new class extends Component {
    public $viewMode = 'current'; // 'current' or 'list'
    public $envelopes;
    public $activeEnvelope; // The envelope currently being viewed in detail

    // Form Fields
    public $fiscalYear;
    public $totalAmount;
    public $approvedDate;
    public $referenceNumber;
    public $sourceAuthority;
    public $startDate;
    public $dueDate;
    public $status = 'Draft';


    // Economic Classifications
    public $economicClassifications;
    public $categoryAllocations = []; // [classification_id => amount]
    public $plannedAmounts = [];      // [classification_id => planned_amount]

    public $modalOpen = false;
    public $isEditing = false;

    public $canCreateNewEnvelope = false;
    public $detailsTab = 'distribution'; // 'distribution' or 'categories'

    public $redistributionModalOpen = false;
    public $redistributionPreview = [];

    public function mount(): void
    {
        $this->economicClassifications = EconomicClassification::orderBy('code')->get();
        $this->canCreateNewEnvelope = App\Models\SystemSetting::get('enable_next_year_planning', false);
        $this->loadData();
    }

    public function loadData(): void
    {
        $this->envelopes = FiscalEnvelope::production()->orderBy('fiscal_year', 'desc')->get();

        // Default active envelope is the one (currently active by date) or the most recent one
        if (!$this->activeEnvelope) {
            $this->activeEnvelope = FiscalEnvelope::getActiveEnvelope() ?? $this->envelopes->first();

            if ($this->activeEnvelope) {
                $this->activeYear = $this->activeEnvelope->fiscal_year;
            } else {
                $this->activeYear = date('Y');
            }
        }

        if ($this->activeEnvelope) {
            $this->activeEnvelope->load(['categoryAllocations.economicClassification']);
        }
    }


    public function switchTab($tab): void
    {
        $this->viewMode = $tab;
    }

    #[On('switch-budget-tab')]
    public function handleTabSwitch($tab): void
    {
        $this->viewMode = $tab === 'envelope' ? 'current' : 'list';
    }

    public function viewDetails($id): void
    {
        $this->activeEnvelope = FiscalEnvelope::find($id);
        $this->viewMode = 'current';
        $this->dispatch('switch-budget-tab', tab: 'envelope');
    }

    public function openModal($year = null): void
    {
        $this->resetForm();

        if ($year) {
            $this->isEditing = true;
            $envelope = FiscalEnvelope::production()->where('fiscal_year', $year)->first();
            $this->fiscalYear = $envelope->fiscal_year;
            $this->totalAmount = $envelope->total_amount;
            $this->approvedDate = $envelope->approved_date ? Carbon::parse($envelope->approved_date)->format('Y-m-d') : null;
            $this->startDate = $envelope->start_date ? Carbon::parse($envelope->start_date)->format('Y-m-d') : null;
            $this->dueDate = $envelope->due_date ? Carbon::parse($envelope->due_date)->format('Y-m-d') : null;
            $this->referenceNumber = $envelope->reference_number;
            $this->sourceAuthority = $envelope->source_authority;
            $this->status = $envelope->status;

            // Load allocations from relational table
            foreach ($envelope->categoryAllocations as $allocation) {
                $this->categoryAllocations[$allocation->economic_classification_id] = $allocation->allocated_amount;
            }
        } else {
            if (!$this->canCreateNewEnvelope) {
                session()->flash('error', __('New planning cycles are currently disabled by global policy.'));
                return;
            }
            $this->fiscalYear = Carbon::now()->addYear()->year;
            $this->totalAmount = null;
            $this->approvedDate = now()->format('Y-m-d');
            $this->startDate = Carbon::create($this->fiscalYear, 1, 1)->format('Y-m-d');
            $this->dueDate = Carbon::create($this->fiscalYear, 12, 31)->format('Y-m-d');
            $this->status = Activity::STATUS_DRAFT;
        }


        // Load planned amounts from line items

        $this->loadPlannedAmounts();
        $this->modalOpen = true;
    }

    private function loadPlannedAmounts(): void
    {
        if (!$this->fiscalYear)
            return;

        foreach ($this->economicClassifications as $classification) {
            $this->plannedAmounts[$classification->id] =
                FiscalEnvelopeCategoryAllocation::calculatePlannedAmount(
                    $this->fiscalYear,
                    $classification->id
                );
        }
    }

    public function resetForm(): void
    {
        $this->fiscalYear = null;
        $this->totalAmount = null;
        $this->approvedDate = null;
        $this->startDate = null;
        $this->dueDate = null;
        $this->referenceNumber = null;
        $this->sourceAuthority = null;
        $this->status = 'Draft';
        $this->isEditing = false;
        $this->categoryAllocations = [];
        $this->plannedAmounts = [];
    }

    public function save(): void
    {
        $this->validate([
            'fiscalYear' => 'required|integer|min:2020|max:2030',
            'totalAmount' => 'required|numeric|min:0',
            'approvedDate' => 'required|date',
            'startDate' => 'required|date',
            'dueDate' => 'required|date|after_or_equal:startDate',
            'referenceNumber' => 'required|string',
            'status' => 'required|in:Draft,Pending Approval,Approved,Closed',
        ]);

        $envelope = FiscalEnvelope::find($this->activeEnvelope->id);

        if($envelope->status !== App\Models\FiscalEnvelope::STATUS_DRAFT) {
            session()->flash('error', __('You cannot edit an envelope that is not in draft status.'));
            return;
        }

        $envelope = FiscalEnvelope::updateOrCreate(
            ['fiscal_year' => $this->fiscalYear],
            [
                'total_amount' => $this->totalAmount,
                'approved_date' => $this->approvedDate,
                'start_date' => $this->startDate,
                'due_date' => $this->dueDate,
                'reference_number' => $this->referenceNumber,
                'source_authority' => $this->sourceAuthority,
                'status' => $this->status,
            ]
        );


        // Create/update category allocations with variance tracking
        foreach ($this->economicClassifications as $classification) {
            $allocatedAmount = $this->categoryAllocations[$classification->id] ?? 0;
            $plannedAmount = $this->plannedAmounts[$classification->id] ?? 0;

            $allocation = FiscalEnvelopeCategoryAllocation::updateOrCreate(
                [
                    'fiscal_envelope_id' => $envelope->id,
                    'economic_classification_id' => $classification->id
                ],
                [
                    'allocated_amount' => $allocatedAmount,
                    'planned_amount' => $plannedAmount,
                ]
            );

            // Calculate variance
            $allocation->calculateVariance();
        }

        $this->distributeEnvelope($envelope);

        $this->loadData();
        // If we just saved the active envelope (or a new one which becomes active), refresh it
        if ($this->activeEnvelope && $this->activeEnvelope->id === $envelope->id) {
            $this->activeEnvelope = $envelope->fresh();
        } elseif (!$this->activeEnvelope) {
            $this->activeEnvelope = $envelope->fresh();
        }

        $this->modalOpen = false;
    }


    public function submitForApproval($id): void
    {
        $envelope = FiscalEnvelope::production()->findOrFail($id);
        $envelope->update(['status' => FiscalEnvelope::STATUS_PENDING]);

        AuditLog::create([
            'action' => 'fiscal_envelope_submitted',
            'user_id' => auth()->id(),
            'description' => "Fiscal envelope for year {$envelope->fiscal_year} submitted for approval",
        ]);

        // Notify Ministers
        $ministers = App\Models\User::role('Minister')->get();
        foreach ($ministers as $minister) {
            $minister->notify(new App\Notifications\SystemNotification(
                __('Fiscal Envelope Pending Approval'),
                __('A new fiscal envelope for year :year has been submitted and awaits your approval.', ['year' => $envelope->fiscal_year]),
                route('budget.envelope.current', absolute: false)
            ));
        }

        $this->loadData();
        $this->activeEnvelope = $envelope->fresh();
        session()->flash('success', __('Fiscal envelope submitted for approval.'));
    }

    public function approve($id): void
    {
        if (!auth()->user()->isMinister()) {
            session()->flash('error', __('Only the Minister can approve fiscal envelopes.'));
            return;
        }

        $envelope = FiscalEnvelope::production()->findOrFail($id);
        $envelope->update([
            'status' => FiscalEnvelope::STATUS_APPROVED,
            'approved_date' => now(),
        ]);

        AuditLog::create([
            'action' => 'fiscal_envelope_approved',
            'user_id' => auth()->id(),
            'description' => "Fiscal envelope for year {$envelope->fiscal_year} approved by Minister",
        ]);

        $this->loadData();
        $this->activeEnvelope = $envelope->fresh();
        session()->flash('success', __('Fiscal envelope approved successfully.'));
    }

    public function reject($id): void
    {
        if (!auth()->user()->isMinister()) {
            session()->flash('error', __('Only the Minister can reject fiscal envelopes.'));
            return;
        }

        $envelope = FiscalEnvelope::production()->findOrFail($id);
        $envelope->update(['status' => FiscalEnvelope::STATUS_DRAFT]);

        AuditLog::create([
            'action' => 'fiscal_envelope_rejected',
            'user_id' => auth()->id(),
            'description' => "Fiscal envelope for year {$envelope->fiscal_year} rejected by Minister",
        ]);

        $this->loadData();
        $this->activeEnvelope = $envelope->fresh();
        session()->flash('error', __('Fiscal envelope rejected and returned to draft.'));
    }


    protected function distributeEnvelope($envelope): void
    {

        // dd($envelope);
        // 1. Distribute by Economic Category proportionally to LineItems

        foreach ($envelope->categoryAllocations as $allocation) {
            $categoryAmount = (float) $allocation->allocated_amount;
            $classificationId = $allocation->economic_classification_id;

            // Get all proposals for this category in the same fiscal year
            $proposals = LineItem::where('economic_classification_id', $classificationId)
                ->whereHas('activity', function ($query) use ($envelope) {
                    $query->where('fiscal_year', $envelope->fiscal_year);
                })
                ->get();

            $totalRequested = $proposals->sum('estimated_amount');

            if ($totalRequested > 0) {
                // Calculate scaling ratio
                $ratio = min($categoryAmount / $totalRequested, 1000.0); // Safety cap but usually <= 1.0

                foreach ($proposals as $proposal) {
                    AdjustedBudget::updateOrCreate(
                        ['line_item_id' => $proposal->id],
                        [
                            'approved_amount' => (float) $proposal->estimated_amount * $ratio,
                            'status' => Activity::STATUS_APPROVED
                        ]
                    );
                }
            } else {
                // If no requests, maybe log or handle? For now skip.
            }
        }



        // 3. Sync category metrics
        foreach ($envelope->categoryAllocations as $allocation) {
            $allocation->calculateVariance();
        }


        AuditLog::create([
            'action' => 'envelope_distributed_proportional',
            'user_id' => auth()->id(),
            'description' => "Proportional OGE distribution executed for year {$envelope->fiscal_year}",
            'metadata' => [
                'fiscal_year' => $envelope->fiscal_year,
                'total_amount' => $envelope->total_amount,
            ],
        ]);
    }

    public function openRedistributionModal($envelopeId): void
    {
        if (!auth()->user()->isAdministrator() && !auth()->user()->isMinister()) {
            session()->flash('error', __('Only Admin or Minister can redistribute budgets.'));
            return;
        }

        $envelope = FiscalEnvelope::production()->with('categoryAllocations.economicClassification')->findOrFail($envelopeId);

        // Calculate redistribution preview
        $this->redistributionPreview = [];

        foreach ($envelope->categoryAllocations as $allocation) {
            $categoryAmount = (float) $allocation->allocated_amount;
            $classificationId = $allocation->economic_classification_id;

            $proposals = LineItem::where('economic_classification_id', $classificationId)
                ->whereHas('activity', function ($query) use ($envelope) {
                    $query->where('fiscal_year', $envelope->fiscal_year);
                })
                ->get();

            $totalRequested = $proposals->sum('estimated_amount');
            $ratio = $totalRequested > 0 ? min($categoryAmount / $totalRequested, 1000.0) : 0;

            $affectedActivities = [];
            foreach ($proposals as $proposal) {
                $currentApproved = $proposal->adjustedBudget->approved_amount ?? 0;
                $newApproved = (float) $proposal->estimated_amount * $ratio;

                $affectedActivities[] = [
                    'activity_id' => $proposal->activity_id,
                    'activity_description' => $proposal->activity->description ?? 'N/A',
                    'line_item_id' => $proposal->id,
                    'line_item_description' => $proposal->description,
                    'estimated' => $proposal->estimated_amount,
                    'current_approved' => $currentApproved,
                    'new_approved' => $newApproved,
                    'change' => $newApproved - $currentApproved,
                ];
            }

            $this->redistributionPreview[] = [
                'category' => $allocation->economicClassification,
                'allocated_amount' => $categoryAmount,
                'total_requested' => $totalRequested,
                'ratio' => $ratio,
                'affected_count' => count($affectedActivities),
                'activities' => $affectedActivities,
            ];
        }

        $this->redistributionModalOpen = true;
    }

    public function confirmRedistribution($envelopeId): void
    {
        if (!auth()->user()->isAdministrator() && !auth()->user()->isMinister()) {
            session()->flash('error', __('Only Admin or Minister can redistribute budgets.'));
            return;
        }

        $envelope = FiscalEnvelope::production()->with('categoryAllocations')->findOrFail($envelopeId);
        $this->distributeEnvelope($envelope);

        $this->loadData();
        if ($this->activeEnvelope && $this->activeEnvelope->id == $envelopeId) {
            $this->activeEnvelope = $envelope->fresh(['categoryAllocations.economicClassification']);
        }

        $this->redistributionModalOpen = false;
        $this->redistributionPreview = [];

        session()->flash('success', __("Budget for :year redistributed successfully!", ['year' => $envelope->fiscal_year]));
    }

    protected function adjustIndicators($activity, $ratio): void
    {
        $indicators = Indicator::where('activity_id', $activity->id)->get();
        foreach ($indicators as $indicator) {
            $indicator->update(['annual_target' => $indicator->annual_target * $ratio]);
        }
    }

    // Helper to calculate total allocated to categories in form
    public function getTotalCategoryAllocationProperty()
    {
        return collect($this->categoryAllocations)->sum();
    }
}; ?>

<div class="space-y-10 py-6 animate-in fade-in duration-700">
    {{-- Content Area --}}
    <div class="min-h-[500px]">
        @if($viewMode === 'list')
            @include('livewire.pages.budget.envelope-tabs.list')
        @else
            @include('livewire.pages.budget.envelope-tabs.current')
        @endif
    </div>

    <!-- Modal Form -->
    @if($modalOpen)
        <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4 animate-in fade-in duration-200">
            <div
                class="relative w-full max-w-2xl bg-white rounded-lg shadow-lg overflow-hidden animate-in zoom-in-95 duration-200 flex flex-col max-h-[90vh]">
                <!-- Header -->
                <div class="flex flex-col gap-1 p-6 border-b text-center sm:text-left">
                    <h2 class="text-lg font-bold leading-none tracking-tight">
                        {{ $isEditing ? __('Edit Fiscal Envelope :year', ['year' => $fiscalYear]) : __('Create New Fiscal Envelope') }}
                    </h2>
                    <p class="text-sm text-gray-500">
                        {{ $isEditing ? __('Update the details for the fiscal year OGE allocation') : __('Enter the details for the new fiscal year OGE allocation') }}
                    </p>
                    <button wire:click="$set('modalOpen', false)"
                        class="absolute top-4 right-4 text-gray-400 hover:text-black transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="18" x2="6" y1="6" y2="18" />
                            <line x1="6" x2="18" y1="6" y2="18" />
                        </svg>
                    </button>
                </div>

                <!-- Scrollable Content -->
                <div class="p-6 space-y-6 overflow-y-auto">
                    {{-- Basic Information --}}
                    <div class="space-y-4">
                        <h3 class="font-semibold text-gray-900 border-b pb-2">{{ __('Basic Information') }}</h3>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="space-y-2">
                                <label
                                    class="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70">{{ __('Fiscal Year') }}
                                    *</label>
                                <input type="number" wire:model="fiscalYear" {{ $isEditing ? 'disabled' : '' }}
                                    class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
                                    placeholder="2026">
                                @error('fiscalYear') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                            </div>
                            <div class="space-y-2">
                                <label
                                    class="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70">{{ __('Total Envelope Amount') }}
                                    *</label>
                                <input type="number" step="0.01" wire:model.live="totalAmount"
                                    class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
                                    placeholder="50000000">
                                @error('totalAmount') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="space-y-2">
                                <label
                                    class="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70">{{ __('Approval Date') }}
                                    *</label>
                                <input type="date" wire:model="approvedDate"
                                    class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50">
                                @error('approvedDate') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                            </div>
                            <div class="space-y-2">
                                <label
                                    class="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70">{{ __('Approval Reference') }}
                                    *</label>
                                <input type="text" wire:model="referenceNumber"
                                    class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
                                    placeholder="OGE/2026/DNO/MSA-001">
                                @error('referenceNumber') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div class="space-y-2">
                                <label
                                    class="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70">{{ __('Start Date') }}
                                    *</label>
                                <input type="date" wire:model="startDate"
                                    class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50">
                                @error('startDate') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                            </div>
                            <div class="space-y-2">
                                <label
                                    class="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70">{{ __('Due Date') }}
                                    *</label>
                                <input type="date" wire:model="dueDate"
                                    class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50">
                                @error('dueDate') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                            </div>
                        </div>

                        <div class="space-y-2">
                            <label
                                class="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70">{{ __('Status') }}
                                *</label>
                            <select wire:model="status"
                                class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50">
                                <option value="Draft">{{ __('Draft') }}</option>
                                <option value="Pending Approval">{{ __('Pending Approval') }}</option>
                                <option value="Approved">{{ __('Approved') }}</option>
                                <option value="Closed">{{ __('Closed') }}</option>
                            </select>
                            @error('status') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <div class="h-px bg-gray-200"></div>

                    {{-- Economic Allocations --}}
                    <div class="space-y-4">
                        <div>
                            <h3 class="font-semibold text-gray-900">{{ __('Economic Categories Allocation') }}</h3>
                            <p class="text-sm text-gray-500">
                                {{ __('Allocate budget to each economic category (optional, can be done later)') }}
                            </p>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            @foreach($economicClassifications as $classification)
                                <div class="space-y-2">
                                    <label class="text-sm font-medium leading-none flex items-center gap-2">
                                        <span class="bg-gray-100 p-1 rounded">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                                                fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                stroke-linejoin="round">
                                                <rect width="20" height="12" x="2" y="6" rx="2" />
                                                <circle cx="12" cy="12" r="2" />
                                                <path d="M6 12h.01" />
                                                <path d="M18 12h.01" />
                                            </svg>
                                        </span>
                                        {{ $classification->code }} - {{ $classification->name }}
                                    </label>
                                    <input type="number" step="0.01"
                                        wire:model.live="categoryAllocations.{{ $classification->id }}"
                                        class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
                                        placeholder="0">
                                    <p class="text-[10px] text-gray-400">{{ __($classification->description) }}</p>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- Summary Footer --}}
                    <div class="p-4 rounded-lg bg-gray-100 space-y-2">
                        <div class="flex justify-between">
                            <span class="text-sm">{{ __('Total Fiscal Envelope') }}:</span>
                            <span class="font-semibold">$ {{ number_format((float) $totalAmount, 2) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm">{{ __('Total Category Allocation') }}:</span>
                            <span class="font-semibold">$ {{ number_format($this->totalCategoryAllocation, 2) }}</span>
                        </div>
                        <div class="h-px bg-gray-300 w-full"></div>
                        <div class="flex justify-between items-center">
                            <span class="text-sm">{{ __('Unallocated') }}:</span>
                            @php $unallocated = (float) $totalAmount - $this->totalCategoryAllocation; @endphp
                            <span class="font-semibold {{ $unallocated < 0 ? 'text-red-600' : 'text-green-600' }}">
                                $ {{ number_format($unallocated, 2) }}
                            </span>
                        </div>
                    </div>

                </div>

                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end p-6 border-t bg-gray-50">
                    <button wire:click="$set('modalOpen', false)"
                        class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-50 border border-input bg-white text-gray-900 shadow-sm hover:bg-gray-100 h-9 px-4 py-2">
                        {{ __('Cancel') }}
                    </button>
                    <button wire:click="save"
                        class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-50 bg-black text-white shadow hover:bg-gray-800 h-9 px-4 py-2">
                        {{ $isEditing ? __('Save Changes') : __('Create Fiscal Envelope') }}
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- Redistribution Confirmation Modal -->
    @if($redistributionModalOpen)
        <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4 animate-in fade-in duration-200">
            <div
                class="relative w-full max-w-5xl bg-white rounded-2xl shadow-2xl overflow-hidden animate-in zoom-in-95 duration-200 flex flex-col max-h-[90vh]">
                <!-- Header -->
                <div class="flex items-center justify-between p-6 border-b bg-gradient-to-r from-indigo-50 to-white">
                    <div>
                        <h2 class="text-xl font-black text-gray-900 leading-none tracking-tight">
                            {{ __('Confirm OGE Redistribution') }}</h2>
                        <p class="text-sm text-gray-500 mt-2">
                            {{ __('Review the redistribution calculations before applying') }}</p>
                    </div>
                    <button wire:click="$set('redistributionModalOpen', false)"
                        class="p-2 text-gray-400 hover:text-black transition-colors rounded-lg hover:bg-gray-100">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="18" x2="6" y1="6" y2="18" />
                            <line x1="6" x2="18" y1="6" y2="18" />
                        </svg>
                    </button>
                </div>

                <!-- Scrollable Content -->
                <div class="p-6 space-y-6 overflow-y-auto">
                    @foreach($redistributionPreview as $preview)
                        <div class="rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                            <div class="p-4 bg-gradient-to-r from-indigo-50/50 to-white border-b border-gray-100">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-3">
                                        <div
                                            class="h-10 w-10 rounded-lg bg-indigo-100 flex items-center justify-center text-indigo-600 font-black text-sm">
                                            {{ $preview['category']->code }}
                                        </div>
                                        <div>
                                            <h3 class="text-sm font-black text-gray-900">{{ $preview['category']->name }}</h3>
                                            <p class="text-xs text-gray-500">{{ __($preview['category']->description) }}</p>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-xs text-gray-400 uppercase tracking-wider font-bold">
                                            {{ __('Allocation Ratio') }}</p>
                                        <p class="text-lg font-black text-indigo-600">
                                            {{ number_format($preview['ratio'] * 100, 1) }}%</p>
                                    </div>
                                </div>
                                <div class="mt-4 grid grid-cols-2 gap-4">
                                    <div>
                                        <p class="text-[9px] font-black text-gray-400 uppercase tracking-widest">
                                            {{ __('OGE Allocated') }}</p>
                                        <p class="text-sm font-bold text-gray-900">$
                                            {{ number_format($preview['allocated_amount'], 2) }}</p>
                                    </div>
                                    <div>
                                        <p class="text-[9px] font-black text-gray-400 uppercase tracking-widest">
                                            {{ __('Total Requested') }}</p>
                                        <p class="text-sm font-bold text-gray-900">$
                                            {{ number_format($preview['total_requested'], 2) }}</p>
                                    </div>
                                </div>
                            </div>

                            @if(count($preview['activities']) > 0)
                                <div class="p-4">
                                    <p class="text-xs font-bold text-gray-600 uppercase tracking-wider mb-3">
                                        {{ __('Affected Activities') }} ({{ $preview['affected_count'] }})</p>
                                    <div class="space-y-2 max-h-60 overflow-y-auto">
                                        @foreach($preview['activities'] as $activity)
                                            <div class="p-3 rounded-lg border border-gray-100 hover:bg-gray-50 transition-colors">
                                                <div class="flex items-start justify-between gap-4">
                                                    <div class="flex-1 min-w-0">
                                                        <p class="text-xs font-bold text-gray-900 truncate">
                                                            {{ $activity['activity_description'] }}</p>
                                                        <p class="text-[10px] text-gray-500 mt-0.5">
                                                            {{ $activity['line_item_description'] }}</p>
                                                    </div>
                                                    <div class="flex items-center gap-4 shrink-0">
                                                        <div class="text-right">
                                                            <p class="text-[9px] text-gray-400 uppercase tracking-wider font-bold">
                                                                {{ __('Current') }}</p>
                                                            <p class="text-xs font-bold text-gray-600">$
                                                                {{ number_format($activity['current_approved'], 2) }}</p>
                                                        </div>
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14"
                                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                            stroke-linecap="round" stroke-linejoin="round" class="text-gray-300">
                                                            <path d="M5 12h14" />
                                                            <path d="m12 5 7 7-7 7" />
                                                        </svg>
                                                        <div class="text-right">
                                                            <p class="text-[9px] text-gray-400 uppercase tracking-wider font-bold">
                                                                {{ __('New') }}</p>
                                                            <p
                                                                class="text-xs font-black {{ $activity['change'] >= 0 ? 'text-green-600' : 'text-rose-600' }}">
                                                                $ {{ number_format($activity['new_approved'], 2) }}</p>
                                                        </div>
                                                        <div class="text-right min-w-[80px]">
                                                            <p class="text-[9px] text-gray-400 uppercase tracking-wider font-bold">
                                                                {{ __('Change') }}</p>
                                                            <p
                                                                class="text-xs font-black {{ $activity['change'] >= 0 ? 'text-green-600' : 'text-rose-600' }}">
                                                                {{ $activity['change'] >= 0 ? '+' : '' }}$
                                                                {{ number_format($activity['change'], 2) }}
                                                            </p>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @else
                                <div class="p-4 text-center text-sm text-gray-400 italic">
                                    {{ __('No activities found for this category') }}
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>

                <!-- Footer -->
                <div class="flex items-center justify-between gap-4 p-6 border-t bg-gray-50">
                    <div
                        class="flex items-center gap-2 text-amber-700 bg-amber-50 px-4 py-2 rounded-lg border border-amber-200">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z" />
                            <path d="M12 9v4" />
                            <path d="M12 17h.01" />
                        </svg>
                        <span
                            class="text-xs font-bold">{{ __('This action will update all affected activity budgets') }}</span>
                    </div>
                    <div class="flex gap-3">
                        <button wire:click="$set('redistributionModalOpen', false)"
                            class="px-6 py-2.5 rounded-xl text-sm font-bold text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">
                            {{ __('Cancel') }}
                        </button>
                        <button wire:click="confirmRedistribution({{ $activeEnvelope->id }})" wire:loading.attr="disabled"
                            class="px-6 py-2.5 rounded-xl text-sm font-bold text-white bg-indigo-600 hover:bg-indigo-700 transition-colors shadow-lg shadow-indigo-600/20 disabled:opacity-50">
                            <span wire:loading.remove>{{ __('Confirm Redistribution') }}</span>
                            <span wire:loading>{{ __('Processing...') }}</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>