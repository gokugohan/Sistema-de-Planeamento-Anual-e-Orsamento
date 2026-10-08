<?php

use Livewire\Volt\Component;
use Livewire\WithPagination;
use App\Models\Activity;
use App\Models\Program;
use App\Models\Directorate;
use Illuminate\Support\Collection;
use App\Models\FiscalEnvelope;
use Livewire\Attributes\Url;

new class extends Component {
    use WithPagination;

    #[Url(as: 'year')]
    public $fiscalYear;
    public $activeYear;
    public $creationAlert = false;
    public $editAlert = false;
    public $activeTab = 'details'; // 'program', 'details', 'directorate'
    public $canCreateNewPAA = false;
    public $selectedDirectorate = null;
    public $showDrafts = false;
    public $confirmingSubmission = false;
    public $submissionType = 'single'; // 'single' or 'bulk'
    public $activityToSubmit = null;

    #[Url(as: 'status')]
    public string $statusFilter = '';

    #[Url(as: 'q')]
    public string $search = '';

    public int $perPage = 15;

    public function paginationView(): string
    {
        return 'livewire.pagination';
    }

    public function updatingSearch(): void            { $this->resetPage(); }
    public function updatingStatusFilter(): void      { $this->resetPage(); }
    public function updatingFiscalYear(): void        { $this->resetPage(); }
    public function updatingSelectedDirectorate(): void { $this->resetPage(); }
    public function updatingPerPage(): void           { $this->resetPage(); }

    public function mount(): void
    {
        $this->activeYear = FiscalEnvelope::getEffectiveYear();
        $this->fiscalYear = $this->activeYear;
        $this->canCreateNewPAA = (bool) \App\Models\SystemSetting::get('enable_next_year_planning', false);
        $this->showDrafts = \App\Models\SystemSetting::get('show_draft_planning_in_reports', false);

        $user = auth()->user();
        // Only Admin, Minister, or users with specific permission can view all
        if (!$user->isAdministrator() && !$user->isMinister() && !$user->can('view_all_paa')) {
            $this->selectedDirectorate = $user->directorate_id;
        }
    }


    public function with(): array
    {
        $user = auth()->user();

        $query = Activity::with(['program', 'directorate'])->withCount(['indicators', 'lineItems'])->withSum('lineItems as line_items_sum_estimated_amount', 'estimated_amount')
            ->where('fiscal_year', $this->fiscalYear);

        // Enforcement of Directorate Isolation & Hierarchy Support
        if (!$user->isAdministrator() && !$user->isMinister() && !$user->can('view_all_paa')) {
            // Fetch activities for user's directorate AND all its sub-units
            $directorate = Directorate::find($user->directorate_id);
            $ids = $directorate ? $directorate->getSelfAndDescendantIds() : [$user->directorate_id];
            $query->whereIn('directorate_id', $ids);
        } elseif ($this->selectedDirectorate) {
            // Authorized users can filter by selected directorate AND its descendants
            $directorate = Directorate::find($this->selectedDirectorate);
            $ids = $directorate ? $directorate->getSelfAndDescendantIds() : [$this->selectedDirectorate];
            $query->whereIn('directorate_id', $ids);
        }

        if ($this->statusFilter !== '') {
            $query->where('status', $this->statusFilter);
        }

        if ($this->search !== '') {
            $query->where('description', 'like', '%' . $this->search . '%');
        }

        return [
            'activities' => $query->paginate($this->perPage),
        ];
    }

    public function getProgramViewDataProperty()
    {
        $user = auth()->user();
        $query = Activity::with(['program', 'directorate', 'indicators', 'lineItems.adjustedBudget'])
            ->where('fiscal_year', $this->fiscalYear);

        if (!$user->isAdministrator() && !$user->isMinister() && !$user->can('view_all_paa')) {
            $directorate = Directorate::find($user->directorate_id);
            $ids = $directorate ? $directorate->getSelfAndDescendantIds() : [$user->directorate_id];
            $query->whereIn('directorate_id', $ids);
        } elseif ($this->selectedDirectorate) {
            $directorate = Directorate::find($this->selectedDirectorate);
            $ids = $directorate ? $directorate->getSelfAndDescendantIds() : [$this->selectedDirectorate];
            $query->whereIn('directorate_id', $ids);
        }

        $activities = $query->get();
        return $activities->groupBy('program_id')->map(function ($programActivities) {
            if ($programActivities->isEmpty())
                return null;
            $program = $programActivities->first()->program;

            $totalEstimated = $programActivities->sum(function ($activity) {
                return $activity->lineItems->sum('estimated_amount');
            });

            $totalAllocated = $programActivities->sum(function ($activity) {
                return $activity->lineItems->sum(function ($item) {
                    return $item->adjustedBudget->approved_amount ?? 0;
                });
            });

            $totalIndicators = $programActivities->sum(function ($activity) {
                return $activity->indicators->count();
            });

            $totalRubricas = $programActivities->sum(function ($activity) {
                return $activity->lineItems->count();
            });

            return [
                'id' => $program->id,
                'name' => $program->name,
                'code' => $program->oge_code,
                'activities' => $programActivities,
                'program_allocation' => $totalAllocated,
                'total_estimated' => $totalEstimated,
                'total_allocated' => $totalAllocated,
                'total_indicators' => $totalIndicators,
                'total_rubricas' => $totalRubricas,
            ];
        })->filter();
    }

    public function getDirectorateViewDataProperty(): \Illuminate\Support\Collection
    {
        $fiscalYear = $this->fiscalYear;
        $user       = auth()->user();

        // ── Query 1: all active directorates for this year ────────────────
        $allDirectorates = Directorate::activeInYear($fiscalYear)->get()->keyBy('id');

        // ── Query 2: all activities with needed relations ─────────────────
        $actQuery = Activity::with(['program'])->withCount(['indicators', 'lineItems'])->withSum('lineItems as line_items_sum_estimated_amount', 'estimated_amount')
            ->where('fiscal_year', $fiscalYear);

        if (!$user->isAdministrator() && !$user->isMinister() && !$user->can('view_all_paa')) {
            $dir = $allDirectorates->get($user->directorate_id);
            $ids = $dir ? $dir->getSelfAndDescendantIds() : [$user->directorate_id];
            $actQuery->whereIn('directorate_id', $ids);
        } elseif ($this->selectedDirectorate) {
            $dir = $allDirectorates->get($this->selectedDirectorate);
            $ids = $dir ? $dir->getSelfAndDescendantIds() : [$this->selectedDirectorate];
            $actQuery->whereIn('directorate_id', $ids);
        }

        $activitiesByDir = $actQuery->get()->groupBy('directorate_id');

        // ── Determine root IDs ────────────────────────────────────────────
        if (!$user->isAdministrator() && !$user->isMinister() && !$user->can('view_all_paa')) {
            $rootIds = [$user->directorate_id];
        } else {
            $rootIds = $allDirectorates
                ->filter(fn($d) => $d->parent_id === null || $d->type === 'Independent')
                ->pluck('id')->unique()->values()->all();
        }

        // ── Build tree in memory (zero more DB queries) ───────────────────
        $buildNode = function (int $dirId) use (&$buildNode, $allDirectorates, $activitiesByDir): ?array {
            $dir = $allDirectorates->get($dirId);
            if (!$dir) return null;

            $activities = $activitiesByDir->get($dirId, collect());
            $children   = $allDirectorates
                ->filter(fn($d) => (int) $d->parent_id === $dirId)
                ->map(fn($child) => $buildNode($child->id))
                ->filter()->values();

            $selfEst  = $activities->sum('line_items_sum_estimated_amount');
            $childEst = $children->sum('total_estimated');

            return [
                'id'               => $dir->id,
                'name'             => $dir->name,
                'code'             => $dir->code,
                'type'             => $dir->type,
                'activities'       => $activities,
                'children'         => $children,
                'total_activities' => $activities->count() + $children->sum('total_activities'),
                'total_indicators' => $activities->sum('indicators_count') + $children->sum('total_indicators'),
                'total_estimated'  => $selfEst + $childEst,
                'count_draft'      => $activities->where('status', Activity::STATUS_DRAFT)->count()            + $children->sum('count_draft'),
                'count_review'     => $activities->whereIn('status', [Activity::STATUS_SUBMITTED, Activity::STATUS_REVIEWED, Activity::STATUS_VALIDATED])->count() + $children->sum('count_review'),
                'count_waiting'    => $activities->where('status', Activity::STATUS_WAITING_APPROVAL)->count() + $children->sum('count_waiting'),
                'count_approved'   => $activities->where('status', Activity::STATUS_APPROVED)->count()         + $children->sum('count_approved'),
                'count_rejected'   => $activities->where('status', Activity::STATUS_REJECTED)->count()         + $children->sum('count_rejected'),
            ];
        };

        return collect($rootIds)->map(fn($id) => $buildNode((int) $id))->filter()->values();
    }


    public function delete(Activity $activity): void
    {
        if ($this->isYearLocked($activity->fiscal_year)) {
            $this->dispatch('toast', message: 'Cannot delete activities from a locked fiscal year.', type: 'error');
            return;
        }
        $activity->delete();
    }

    public function confirmSubmission(): void
    {
        if ($this->submissionType === 'bulk') {
            $this->submitBatch();
        } else {
            $activity = Activity::find($this->activityToSubmit);
            if ($activity) {
                $this->submitOne($activity);
            }
        }
        $this->confirmingSubmission = false;
        $this->activityToSubmit = null;
    }

    public function cancelSubmission(): void
    {
        $this->confirmingSubmission = false;
        $this->activityToSubmit = null;
    }

    public function initiateBatchSubmission(): void
    {
        $this->submissionType = 'bulk';
        $this->confirmingSubmission = true;
    }

    public function initiateSingleSubmission($id): void
    {
        $this->activityToSubmit = $id;
        $this->submissionType = 'single';
        $this->confirmingSubmission = true;
    }

    public function submitBatch(): void
    {
        $user = auth()->user();
        $query = Activity::where('fiscal_year', $this->fiscalYear)
            ->where('directorate_id', $user->directorate_id);

        if ($user->isPlanner()) {
            $activities = $query->where('status', Activity::STATUS_DRAFT)->get();
        } elseif ($user->isDirector()) {
            // Directors can bulk-submit items that are in their "Turn"
            // For now, let's fetch items that are SUBMITTED (to them) 
            // OR if it's their own unit's items that they need to review.
            // Actually, Directors usually review and submit their Unit's items.
            $activities = Activity::where('fiscal_year', $this->fiscalYear)
                ->whereIn('directorate_id', $user->directorate->getSelfAndDescendantIds())
                ->get()
                ->filter(fn($a) => $this->isUserTurnToSubmit($user, $a));
        } else {
            $this->dispatch('toast', message: __('You do not have permission to submit activities.'), type: 'error');
            return;
        }

        if ($activities->isEmpty()) {
            $this->dispatch('toast', message: __('No activities found in the correct status to submit.'), type: 'error');
            return;
        }

        $this->processSubmission($activities);
        $this->dispatch('toast', message: count($activities) . ' ' . __('activities updated.'), type: 'success');
    }

    protected function isUserTurnToSubmit($user, $activity): bool
    {
        if ($user->isPlanner()) {
            return $activity->status === Activity::STATUS_DRAFT && $activity->directorate_id === $user->directorate_id;
        }

        if ($user->isDirector()) {
            // If it belongs to my directorate and is Submitted
            if ($activity->directorate_id === $user->directorate_id && $activity->status === Activity::STATUS_SUBMITTED) {
                return true;
            }

            // If it belongs to my children and matches the next stage
            $childIds = $user->directorate->children()->pluck('id')->toArray();
            if (in_array($activity->directorate_id, $childIds)) {
                if ($activity->status === Activity::STATUS_SUBMITTED && $user->directorate->type !== 'Department') {
                    // This case handles Chief (Dept) -> Director (DN) if we skip a step, 
                    // but usually it goes status by status.
                    return true;
                }
                if ($activity->status === Activity::STATUS_REVIEWED) {
                    return true;
                }
            }

            // DG Director can review "Validated" items from DNs
            if ($user->directorate->type === 'General Directorate') {
                $dnIds = $user->directorate->children()->pluck('id')->toArray();
                if (in_array($activity->directorate_id, $dnIds) && $activity->status === Activity::STATUS_REVIEWED) {
                    return true;
                }

                // Allow DG to Submit "Validated" items to Minister (Waiting Approval)
                $allDescendants = $user->directorate->getAllDescendantIds();
                if (in_array($activity->directorate_id, $allDescendants) && $activity->status === Activity::STATUS_VALIDATED) {
                    return true;
                }
            }
        }

        return false;
    }

    public function submitOne(Activity $activity): void
    {
        $user = auth()->user();

        if (!$this->isUserTurnToSubmit($user, $activity) && !$user->isAdministrator()) {
            $this->dispatch('toast', message: __('It is not your turn to submit this activity or you lack permission.'), type: 'error');
            return;
        }

        $this->processSubmission(collect([$activity]));
        $this->dispatch('toast', message: __('Activity updated.'), type: 'success');
    }

    protected function processSubmission($activities): void
    {
        if ($activities->isEmpty())
            return;

        $user = auth()->user();

        foreach ($activities as $activity) {
            $newStatus = null;
            $notificationTitle = null;
            $notificationMessage = null;
            $targetRoles = [];
            $targetDirectorateId = null;

            // Get the activity's owning directorate to check hierarchy
            $ownerDirectorate = $activity->directorate;
            $hasParent = $ownerDirectorate->parent_id !== null;

            // 1. PLANNER: Draft -> Submitted
            if ($user->isPlanner() && $activity->status === Activity::STATUS_DRAFT) {
                // Planners always submit to their Director
                $newStatus = Activity::STATUS_SUBMITTED;
                $notificationTitle = __('PAA Submitted for Review');
                $notificationMessage = __('Planner :name has submitted activities for directorate :dir for review.', ['name' => $user->name, 'dir' => $activity->directorate->name]);
                $targetRoles = ['Director'];
                $targetDirectorateId = $activity->directorate_id;
            }

            // 2. DIRECTOR: Review & Upgrade Status
            elseif ($user->isDirector()) {

                // My Directorate's Parent (The boss of the current user)
                $myDirectorate = $user->directorate;
                $myParent = $myDirectorate->parent;

                // Case A: Handling 'Submitted' items (From Planner or Subordinate)
                if ($activity->status === Activity::STATUS_SUBMITTED) {
                    if ($myParent) {
                        // Intermediate Director (DN) -> Sends to Superior (DG)
                        $newStatus = Activity::STATUS_REVIEWED;
                        $notificationTitle = __('PAA Pending Review');
                        $notificationMessage = __('Director :name has reviewed PAA from :dir. Pending your validation.', ['name' => $user->name, 'dir' => $activity->directorate->name]);
                        $targetRoles = ['Director']; // The DG
                        $targetDirectorateId = $myParent->id;
                    } else {
                        // Top Level Director (DG/Independent) -> Validates directly
                        $newStatus = Activity::STATUS_VALIDATED;
                        $notificationTitle = __('PAA Validated');
                        $notificationMessage = __('Director General :name has validated the PAA for :dir.', ['name' => $user->name, 'dir' => $activity->directorate->name]);
                        // Notification goes back to owner/planner? Or stays silent until submission?
                        // Let's notify the owner that it's validated
                        $targetRoles = ['Planning'];
                        $targetDirectorateId = $activity->directorate_id;
                    }

                    $activity->update([
                        'reviewed_at' => now(),
                        'reviewed_by' => $user->id,
                    ]);
                }

                // Case B: Handling 'Reviewed' items (From Subordinate DN)
                elseif ($activity->status === Activity::STATUS_REVIEWED) {
                    // I am the Supervisor (DG) reviewing a DN's work
                    $newStatus = Activity::STATUS_VALIDATED;
                    $notificationTitle = __('PAA Validated');
                    $notificationMessage = __('Director General :name has validated the PAA from :dir.', ['name' => $user->name, 'dir' => $activity->directorate->name]);
                    $targetRoles = ['Director', 'Planning'];
                    $targetDirectorateId = $activity->directorate_id; // Notify the DN back
                }

                // Case C: Handling 'Validated' items -> Submit to Minister (Waiting Approval)
                elseif ($activity->status === Activity::STATUS_VALIDATED) {
                    // Only Top Level Directors can do this
                    if (!$myParent) {
                        $newStatus = Activity::STATUS_WAITING_APPROVAL;
                        $notificationTitle = __('PAA Pending Final Approval');
                        $notificationMessage = __('General Director :name has submitted the PAA Plan for :dir to the Minister.', ['name' => $user->name, 'dir' => $myDirectorate->name]);
                        $targetRoles = ['Minister'];
                        $targetDirectorateId = null;

                        $activity->update(['submitted_at' => now()]);
                    }
                }
            }

            if ($newStatus) {
                $activity->update(['status' => $newStatus]);

                // Send Notifications
                $notification = new App\Notifications\SystemNotification(
                    $notificationTitle,
                    $notificationMessage,
                    route('activities.show', ['activity' => $activity->id], false)
                );

                if ($targetDirectorateId) {
                    $targets = App\Models\User::role($targetRoles)->where('directorate_id', $targetDirectorateId)->get();
                } else {
                    $targets = App\Models\User::role($targetRoles)->get();
                }

                foreach ($targets as $target) {
                    $target->notify($notification);
                }
            }
        }
    }

    public function approve(Activity $activity): void
    {
        $user = auth()->user();
        if (!$user->isMinister() && !$user->isAdministrator()) {
            $this->dispatch('toast', message: __('Only the Minister or authorized administrators can approve activities.'), type: 'error');
            return;
        }

        $activity->update([
            'status' => Activity::STATUS_APPROVED,
            'approved_at' => now(),
            'approved_by' => $user->id,
        ]);

        // Notify Director and Planner
        $notification = new App\Notifications\SystemNotification(
            __('PAA Activity Approved'),
            __('Activity ":desc" has been approved by the Minister.', ['desc' => $activity->description]),
            route('activities.show', ['activity' => $activity->id], false)
        );

        $notifiables = App\Models\User::role(['Director', 'Planning'])
            ->where('directorate_id', $activity->directorate_id)
            ->get();

        foreach ($notifiables as $notifiable) {
            $notifiable->notify($notification);
        }

        $this->dispatch('toast', message: __('Activity approved successfully.'), type: 'success');
    }

    public function reject(Activity $activity): void
    {
        $activity->update([
            'status' => Activity::STATUS_REJECTED,
        ]);

        $this->dispatch('toast', message: 'Activity rejected.', type: 'error');
    }

    public function reopen(Activity $activity): void
    {
        $user = auth()->user();
        if (!$user->isMinister() && !$user->hasAnyRole(['Admin', 'Planning'])) {
            $this->dispatch('toast', message: 'Only the Minister or authorized administrators can reopen activities.', type: 'error');
            return;
        }

        $activity->update([
            'status' => Activity::STATUS_DRAFT,
            'approved_at' => null,
            'approved_by' => null,
            'submitted_at' => null, // Optional: Reset submission as well to allow a fresh flow
        ]);

        $this->dispatch('toast', message: 'Activity reopened and returned to draft.', type: 'success');
    }

    public function isYearLocked($year): bool
    {
        $status = FiscalEnvelope::where('fiscal_year', $year)->value('status');
        return in_array($status, ['Active', 'Closed']);
    }

    public function startNewPAA(): void
    {
        $targetYear = date('Y') + 1;

        if (!$this->canCreateNewPAA) {
            $this->dispatch('toast', message: "The administration has disabled new planning cycles. Please contact the Planning Unit.", type: 'error');
            return;
        }

        // dd(Activity::where('fiscal_year', $targetYear)->exists());
        $query = Activity::where('fiscal_year', $targetYear);
        $isNewPAAActivityExist = $query->exists();
        $isNewPAAActivityStatusDraftExist = $query->where('status', Activity::STATUS_DRAFT)->exists();

        if ($isNewPAAActivityExist && !$isNewPAAActivityStatusDraftExist) {
            $this->creationAlert = true;
            return;
        } else if ($isNewPAAActivityExist && $isNewPAAActivityStatusDraftExist) {
            $this->editAlert = true;
            return;
        }

        $this->redirect(route('activities.create'), navigate: true);

    }


    public function proceedToCreate(): void
    {
        $this->redirect(route('activities.create'), navigate: true);
    }
}; ?>

<div class="space-y-6 py-2 animate-in fade-in duration-700">

    @php
        $user = auth()->user();

        $allActivities = \App\Models\Activity::select(['activities.id', 'activities.status', 'activities.directorate_id'])
            ->withSum('lineItems as budget', 'estimated_amount')
            ->where('fiscal_year', $fiscalYear)
            ->when(
                !$user->isAdministrator() && !$user->isMinister() && !$user->can('view_all_paa'),
                function ($q) use ($user) {
                    $dir = \App\Models\Directorate::find($user->directorate_id);
                    $ids = $dir ? $dir->getSelfAndDescendantIds() : [$user->directorate_id];
                    $q->whereIn('directorate_id', $ids);
                }
            )
            ->when($selectedDirectorate, function ($q) use ($selectedDirectorate) {
                $dir = \App\Models\Directorate::find($selectedDirectorate);
                $ids = $dir ? $dir->getSelfAndDescendantIds() : [$selectedDirectorate];
                $q->whereIn('directorate_id', $ids);
            })
            ->get();

        $statDraft     = $allActivities->where('status', \App\Models\Activity::STATUS_DRAFT)->count();
        $statSubmitted = $allActivities->whereIn('status', [\App\Models\Activity::STATUS_SUBMITTED, \App\Models\Activity::STATUS_REVIEWED, \App\Models\Activity::STATUS_VALIDATED])->count();
        $statWaiting   = $allActivities->where('status', \App\Models\Activity::STATUS_WAITING_APPROVAL)->count();
        $statApproved  = $allActivities->where('status', \App\Models\Activity::STATUS_APPROVED)->count();
        $totalBudget   = $allActivities->sum('budget');

        $hasDrafts = $allActivities->where('status', \App\Models\Activity::STATUS_DRAFT)
            ->where('directorate_id', $user->directorate_id)
            ->isNotEmpty();
        $hasDraftsAndYearIsNotLocked = $hasDrafts && !$this->isYearLocked($fiscalYear);

        $availableYears = \App\Models\Activity::distinct()->pluck('fiscal_year')
            ->merge(\App\Models\FiscalEnvelope::when(!$this->showDrafts, fn($q) => $q->where('status', '!=', 'Draft'))->pluck('fiscal_year'))
            ->unique()->sortDesc();
        if ($availableYears->isEmpty()) {
            $availableYears = collect([$this->activeYear]);
        }
    @endphp

    {{-- ─── Page Header ──────────────────────────────────────── --}}
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-zinc-900 tracking-tight">
                {{ __('Annual Activity Planning (PAA)') }}
            </h1>
            <p class="text-sm text-zinc-500 mt-0.5">
                {{ __('Fiscal Year') }} <span class="font-semibold text-indigo-600">{{ $fiscalYear }}</span>
                @if($fiscalYear == $activeYear)
                    <span class="ml-1.5 text-xs text-emerald-600 font-medium">&bull; {{ __('Active') }}</span>
                @endif
            </p>
        </div>

        <div class="flex items-center gap-2 flex-wrap">
            @if($hasDraftsAndYearIsNotLocked)
                <x-ui.button wire:click="initiateBatchSubmission" variant="black" size="sm">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="mr-1.5">
                        <path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>
                    </svg>
                    {{ __('Submit PAA for Approval') }}
                </x-ui.button>
            @endif

            @if($this->canCreateNewPAA)
                <x-ui.button wire:click="startNewPAA" variant="primary" size="sm">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="mr-1.5">
                        <path d="M12 5v14"/><path d="M5 12h14"/>
                    </svg>
                    {{ __('New Planning') }} ({{ date('Y') + 1 }})
                </x-ui.button>
            @endif
        </div>
    </div>

    {{-- ─── Status Stats Bar ─────────────────────────────────── --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-5 gap-3">
        <button wire:click="$set('statusFilter', 'Draft')"
            class="flex flex-col items-start px-4 py-3 rounded-xl ring-1 transition-all text-left
                   {{ $statusFilter === 'Draft' ? 'bg-blue-600 ring-blue-600 text-white' : 'bg-white ring-zinc-950/5 shadow-sm hover:ring-zinc-950/10' }}">
            <span class="text-[10px] font-medium uppercase tracking-wider {{ $statusFilter === 'Draft' ? 'text-blue-200' : 'text-zinc-500' }}">{{ __('Draft') }}</span>
            <span class="text-2xl font-semibold mt-0.5 {{ $statusFilter === 'Draft' ? 'text-white' : 'text-zinc-900' }}">{{ $statDraft }}</span>
        </button>
        <button wire:click="$set('statusFilter', 'Submitted')"
            class="flex flex-col items-start px-4 py-3 rounded-xl ring-1 transition-all text-left
                   {{ $statusFilter === 'Submitted' ? 'bg-indigo-600 ring-indigo-600 text-white' : 'bg-white ring-zinc-950/5 shadow-sm hover:ring-zinc-950/10' }}">
            <span class="text-[10px] font-medium uppercase tracking-wider {{ $statusFilter === 'Submitted' ? 'text-indigo-200' : 'text-zinc-500' }}">{{ __('In Review') }}</span>
            <span class="text-2xl font-semibold mt-0.5 {{ $statusFilter === 'Submitted' ? 'text-white' : 'text-zinc-900' }}">{{ $statSubmitted }}</span>
        </button>
        <button wire:click="$set('statusFilter', 'Waiting Approval')"
            class="flex flex-col items-start px-4 py-3 rounded-xl ring-1 transition-all text-left
                   {{ $statusFilter === 'Waiting Approval' ? 'bg-amber-500 ring-amber-500 text-white' : 'bg-white ring-zinc-950/5 shadow-sm hover:ring-zinc-950/10' }}">
            <span class="text-[10px] font-medium uppercase tracking-wider {{ $statusFilter === 'Waiting Approval' ? 'text-amber-100' : 'text-zinc-500' }}">{{ __('Awaiting Approval') }}</span>
            <span class="text-2xl font-semibold mt-0.5 {{ $statusFilter === 'Waiting Approval' ? 'text-white' : 'text-zinc-900' }}">{{ $statWaiting }}</span>
        </button>
        <button wire:click="$set('statusFilter', 'Approved')"
            class="flex flex-col items-start px-4 py-3 rounded-xl ring-1 transition-all text-left
                   {{ $statusFilter === 'Approved' ? 'bg-emerald-600 ring-emerald-600 text-white' : 'bg-white ring-zinc-950/5 shadow-sm hover:ring-zinc-950/10' }}">
            <span class="text-[10px] font-medium uppercase tracking-wider {{ $statusFilter === 'Approved' ? 'text-emerald-100' : 'text-zinc-500' }}">{{ __('Approved') }}</span>
            <span class="text-2xl font-semibold mt-0.5 {{ $statusFilter === 'Approved' ? 'text-white' : 'text-zinc-900' }}">{{ $statApproved }}</span>
        </button>
        <div class="hidden lg:flex flex-col items-start px-4 py-3 rounded-xl ring-1 ring-zinc-950/5 bg-zinc-50 shadow-sm sm:col-span-2 lg:col-span-1">
            <span class="text-[10px] font-medium uppercase tracking-wider text-zinc-500">{{ __('Total Budget') }}</span>
            <span class="text-lg font-semibold mt-0.5 text-zinc-900 tracking-tight">${{ number_format($totalBudget / 1000, 0) }}k</span>
        </div>
    </div>

    {{-- ─── Filters Row ──────────────────────────────────────── --}}
    <div class="flex flex-col sm:flex-row items-start sm:items-center gap-3 flex-wrap">

        {{-- Search --}}
        <div class="flex items-center gap-2 flex-1 min-w-[180px] max-w-xs h-9 px-3 bg-white border border-zinc-200/60 rounded-lg
            focus-within:border-indigo-400 focus-within:ring-2 focus-within:ring-indigo-500/15 transition-all shadow-sm">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                class="text-zinc-400 flex-shrink-0">
                <circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>
            </svg>
            <input type="text" wire:model.live.debounce.300ms="search"
                placeholder="{{ __('Search activities...') }}"
                class="flex-1 bg-transparent text-sm text-zinc-700 placeholder:text-zinc-400 focus:outline-none min-w-0">
            @if($search)
                <button wire:click="$set('search', '')" class="text-zinc-400 hover:text-zinc-600 transition-colors flex-shrink-0">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 6 6 18"/><path d="m6 6 12 12"/>
                    </svg>
                </button>
            @endif
        </div>

        {{-- Fiscal Year --}}
        <select wire:model.live="fiscalYear"
            class="flux-input h-9 py-0 text-sm w-auto min-w-[130px]">
            @foreach($availableYears as $year)
                <option value="{{ $year }}">{{ $year }} {{ $year == $activeYear ? '(' . __('Active') . ')' : '' }}</option>
            @endforeach
        </select>

        {{-- Directorate (privileged) --}}
        @if($user->isAdministrator() || $user->isMinister() || $user->can('view_all_paa'))
            <select wire:model.live="selectedDirectorate"
                class="flux-input h-9 py-0 text-sm w-auto min-w-[160px]">
                <option value="">{{ __('All Directorates') }}</option>
                @foreach(\App\Models\Directorate::activeInYear($this->fiscalYear)->orderByRaw('parent_id IS NOT NULL')->orderBy('parent_id')->orderBy('name')->get() as $dir)
                    <option value="{{ $dir->id }}">{{ $dir->name }}</option>
                @endforeach
            </select>
        @endif

        {{-- Clear status filter pill --}}
        @if($statusFilter)
            <button wire:click="$set('statusFilter', '')"
                class="flex items-center gap-1.5 px-3 h-9 text-sm font-medium text-indigo-600 bg-indigo-50 rounded-lg ring-1 ring-indigo-200 hover:bg-indigo-100 transition-colors flex-shrink-0">
                <span>{{ $statusFilter }}</span>
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 6 6 18"/><path d="m6 6 12 12"/>
                </svg>
            </button>
        @endif
    </div>

    {{-- ─── Tabs ─────────────────────────────────────────────── --}}
    <div class="flex items-center gap-1 p-1 bg-zinc-100/70 w-fit rounded-xl border border-zinc-200/60 shadow-sm">
        <button wire:click="$set('activeTab', 'details')"
            class="px-4 py-1.5 text-sm font-medium rounded-lg transition-all
                   {{ $activeTab === 'details' ? 'bg-white text-zinc-900 shadow-sm ring-1 ring-zinc-950/5' : 'text-zinc-500 hover:text-zinc-700' }}">
            {{ __('Tabular') }}
        </button>
        <button wire:click="$set('activeTab', 'program')"
            class="px-4 py-1.5 text-sm font-medium rounded-lg transition-all
                   {{ $activeTab === 'program' ? 'bg-white text-zinc-900 shadow-sm ring-1 ring-zinc-950/5' : 'text-zinc-500 hover:text-zinc-700' }}">
            {{ __('By Program') }}
        </button>
        <button wire:click="$set('activeTab', 'directorate')"
            class="px-4 py-1.5 text-sm font-medium rounded-lg transition-all
                   {{ $activeTab === 'directorate' ? 'bg-white text-zinc-900 shadow-sm ring-1 ring-zinc-950/5' : 'text-zinc-500 hover:text-zinc-700' }}">
            {{ __('By Directorate') }}
        </button>
    </div>

    {{-- ─── Tab Content ──────────────────────────────────────── --}}
    @if($activeTab === 'program')
        @include('livewire.pages.activities.list-tabs.program-view')
    @elseif($activeTab === 'directorate')
        @include('livewire.pages.activities.list-tabs.directorate-view')
    @else
        @if($activities->isEmpty())
            <div class="text-center py-16 bg-white rounded-xl ring-1 ring-zinc-950/5 shadow-sm">
                <div class="w-14 h-14 bg-zinc-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                        class="text-zinc-300">
                        <rect width="8" height="4" x="8" y="2" rx="1" ry="1"/>
                        <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>
                        <path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/>
                    </svg>
                </div>
                @if($search || $statusFilter)
                    <p class="text-sm font-medium text-zinc-600">{{ __('No activities match your filters.') }}</p>
                    <p class="text-xs text-zinc-400 mt-1">{{ __('Try clearing the search or status filter.') }}</p>
                    <button wire:click="$set('search', ''); $set('statusFilter', '')"
                        class="mt-4 text-sm text-indigo-600 hover:text-indigo-700 font-medium transition-colors">
                        {{ __('Clear filters') }}
                    </button>
                @elseif($this->canCreateNewPAA)
                    <p class="text-sm font-medium text-zinc-600">{{ __('No activities registered so far.') }}</p>
                    <p class="text-xs text-zinc-400 mt-1">{{ __('Start planning by creating the first activity.') }}</p>
                    <x-ui.button wire:click="startNewPAA" variant="primary" size="sm" class="mt-4">
                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="mr-1.5">
                            <path d="M12 5v14"/><path d="M5 12h14"/>
                        </svg>
                        {{ __('Create First Activity') }}
                    </x-ui.button>
                @else
                    <p class="text-sm font-medium text-zinc-600">{{ __('No activities registered so far.') }}</p>
                    <p class="text-xs text-zinc-400 mt-1">{{ __('Activities will appear here once planning begins.') }}</p>
                @endif
            </div>
        @else
            @include('livewire.pages.activities.list-tabs.details-view')
        @endif
    @endif

    {{-- ─── Modals ───────────────────────────────────────────── --}}

    @if($creationAlert)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4 animate-in fade-in duration-200">
            <div class="relative w-full max-w-md bg-white rounded-xl shadow-xl p-6 animate-in zoom-in-95 duration-200">
                <h3 class="text-base font-semibold text-zinc-900 mb-2">{{ __('Existing PAA') }}</h3>
                <p class="text-sm text-zinc-600 leading-relaxed">
                    {{ __('The Annual Activity Plan for the year :year has already been started. Please use the year selection above to manage or update existing activities.', ['year' => date('Y') + 1]) }}
                </p>
                <div class="mt-6 flex justify-end">
                    <x-ui.button wire:click="$set('creationAlert', false)" variant="primary" size="sm">{{ __('Understood') }}</x-ui.button>
                </div>
            </div>
        </div>
    @endif

    @if($editAlert)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4 animate-in fade-in duration-200">
            <div class="relative w-full max-w-md bg-white rounded-xl shadow-xl p-6 animate-in zoom-in-95 duration-200">
                <h3 class="text-base font-semibold text-zinc-900 mb-2">{{ __('Existing PAA') }}</h3>
                <p class="text-sm text-zinc-600 leading-relaxed">
                    {{ __('The Annual Activity Plan for the year :year has already been started. Would you like to proceed with managing or updating the existing activities?') }}
                </p>
                <div class="mt-6 flex justify-end gap-3">
                    <x-ui.button wire:click="$set('editAlert', false)" variant="ghost" size="sm">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button wire:click="proceedToCreate" variant="primary" size="sm">{{ __('Proceed') }}</x-ui.button>
                </div>
            </div>
        </div>
    @endif

    @if($confirmingSubmission)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4 animate-in fade-in duration-200">
            <div class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl p-8 animate-in zoom-in-95 duration-200">
                <div class="flex items-center gap-4 mb-6">
                    <div class="h-11 w-11 rounded-full bg-indigo-50 flex items-center justify-center text-indigo-600 shrink-0">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-base font-semibold text-zinc-900">
                            {{ $submissionType === 'bulk' ? __('Bulk Submission') : __('Submit Activity') }}
                        </h3>
                        <p class="text-xs text-zinc-500 mt-0.5">{{ __('Confirmation Required') }}</p>
                    </div>
                </div>

                <p class="text-sm text-zinc-600 leading-relaxed">
                    @if($submissionType === 'bulk')
                        {{ __('Are you sure you want to submit all draft activities for review? This will notify your superior and lock the activities for editing.') }}
                    @else
                        {{ __('Are you sure you want to submit this activity? This will lock it and notify your superior.') }}
                    @endif
                </p>

                <div class="flex items-center gap-3 mt-4 p-3.5 bg-amber-50 rounded-lg ring-1 ring-amber-200/60">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                        class="text-amber-500 flex-shrink-0">
                        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                        <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                    </svg>
                    <p class="text-xs text-amber-700">{{ __('Activities will be locked after submission') }}</p>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <x-ui.button wire:click="cancelSubmission" variant="ghost" size="sm">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button wire:click="confirmSubmission" variant="black" size="sm">{{ __('Yes, Proceed') }}</x-ui.button>
                </div>
            </div>
        </div>
    @endif

</div>