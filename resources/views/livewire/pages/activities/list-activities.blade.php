<?php

use Livewire\Volt\Component;
use App\Models\Activity;
use App\Models\Program;
use App\Models\Directorate;
use Illuminate\Support\Collection;
use App\Models\FiscalEnvelope;
use Livewire\Attributes\Url;

new class extends Component {
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

        $query = Activity::with(['program', 'directorate', 'indicators', 'lineItems.adjustedBudget'])
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

        return [
            'activities' => $query->get(),
        ];
    }

    public function getProgramViewDataProperty()
    {
        $activities = $this->with()['activities'];
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

    public function getDirectorateViewDataProperty()
    {
        $fiscalYear = $this->fiscalYear;
        $user = auth()->user();

        // Determine which directorates to show at the root level
        if (!$user->isAdministrator() && !$user->isMinister() && !$user->can('view_all_paa')) {
            // Non-privileged users only see their own directorate as the root
            $rootDirectorates = Directorate::where('id', $user->directorate_id)->get();
        } else {
            // Privileged users see top-level and independent ones
            $topLevelDirectorates = Directorate::whereNull('parent_id')->activeInYear($fiscalYear)->get();
            $independent = Directorate::where('type', 'Independent')->activeInYear($fiscalYear)->get();
            $rootDirectorates = $topLevelDirectorates->merge($independent)->unique('id');
        }

        return $rootDirectorates->map(function ($dir) use ($fiscalYear) {
            return $this->formatDirectorateData($dir, $fiscalYear);
        })->filter();
    }

    protected function formatDirectorateData($directorate, $fiscalYear)
    {
        $activities = Activity::where('directorate_id', $directorate->id)
            ->where('fiscal_year', $fiscalYear)
            ->with(['program', 'indicators', 'lineItems.adjustedBudget'])
            ->get();

        $descendants = $directorate->children()->activeInYear($fiscalYear)->get();
        $childData = $descendants->map(function ($child) use ($fiscalYear) {
            return $this->formatDirectorateData($child, $fiscalYear);
        });

        return [
            'id' => $directorate->id,
            'name' => $directorate->name,
            'code' => $directorate->code,
            'type' => $directorate->type,
            'activities' => $activities,
            'children' => $childData,
            'total_indicators' => $activities->sum(fn($a) => $a->indicators->count()) + $childData->sum('total_indicators'),
            'total_rubricas' => $activities->sum(fn($a) => $a->lineItems->count()) + $childData->sum('total_rubricas'),
            'total_estimated' => $directorate->getTotalEstimatedRollup($fiscalYear),
            'total_allocated' => $directorate->getTotalApprovedRollup($fiscalYear),
        ];
    }


    public function delete(Activity $activity): void
    {
        if ($this->isYearLocked($activity->fiscal_year)) {
            session()->flash('error', 'Cannot delete activities from a locked fiscal year.');
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
            session()->flash('error', __('You do not have permission to submit activities.'));
            return;
        }

        if ($activities->isEmpty()) {
            session()->flash('error', __('No activities found in the correct status to submit.'));
            return;
        }

        $this->processSubmission($activities);
        session()->flash('success', count($activities) . ' ' . __('activities updated.'));
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
            session()->flash('error', __('It is not your turn to submit this activity or you lack permission.'));
            return;
        }

        $this->processSubmission(collect([$activity]));
        session()->flash('success', __('Activity updated.'));
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
            session()->flash('error', __('Only the Minister or authorized administrators can approve activities.'));
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

        session()->flash('success', __('Activity approved successfully.'));
    }

    public function reject(Activity $activity): void
    {
        $activity->update([
            'status' => Activity::STATUS_REJECTED,
        ]);

        session()->flash('error', 'Activity rejected.');
    }

    public function reopen(Activity $activity): void
    {
        $user = auth()->user();
        if (!$user->isMinister() && !$user->hasAnyRole(['Admin', 'Planning'])) {
            session()->flash('error', 'Only the Minister or authorized administrators can reopen activities.');
            return;
        }

        $activity->update([
            'status' => Activity::STATUS_DRAFT,
            'approved_at' => null,
            'approved_by' => null,
            'submitted_at' => null, // Optional: Reset submission as well to allow a fresh flow
        ]);

        session()->flash('success', 'Activity reopened and returned to draft.');
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
            session()->flash('error', "The administration has disabled new planning cycles. Please contact the Planning Unit.");
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

<div class="space-y-10 py-6 animate-in fade-in duration-700">

    <div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-6">
        <div>
            <h1 class="text-3xl md:text-4xl font-black text-slate-900 tracking-tight leading-none">
                {{ __('Annual Activity Planning (PAA)') }} ({{ $fiscalYear }})
            </h1>
            <p class="text-slate-400 font-bold uppercase tracking-[0.2em] text-[10px] mt-3">
                {{ __('Strategic Planning & Budget Coordination') }}
            </p>
        </div>
        <div class="flex flex-col sm:flex-row flex-wrap gap-4 items-stretch sm:items-end w-full md:w-auto">
            @if(auth()->user()->isAdministrator() || auth()->user()->isMinister() || auth()->user()->can('view_all_paa'))
                <div class="flex flex-col gap-1 w-full sm:w-auto">
                    <label
                        class="text-[9px] font-black uppercase text-slate-400 tracking-widest px-2">{{ __('Filter Directorate') }}</label>
                    <select wire:model.live="selectedDirectorate"
                        class="bg-white border-none shadow-sm rounded-2xl text-xs font-black text-slate-900 focus:ring-2 focus:ring-indigo-600/20 py-2.5 pl-4 pr-10 w-full">
                        <option value="">{{ __('All Directorates') }}</option>
                        @foreach(\App\Models\Directorate::activeInYear($this->fiscalYear)->orderByRaw('parent_id IS NOT NULL')->orderBy('parent_id')->orderBy('name')->get() as $dir)
                            <option value="{{ $dir->id }}">{{ $dir->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            <div class="flex flex-col gap-1 w-full sm:w-auto">
                <label
                    class="text-[9px] font-black uppercase text-slate-400 tracking-widest px-2">{{ __('Fiscal Year') }}</label>
                <select wire:model.live="fiscalYear"
                    class="bg-white border-none shadow-sm rounded-2xl text-xs font-black text-slate-900 focus:ring-2 focus:ring-indigo-600/20 py-2.5 pl-4 pr-10 w-full">
                    @php
                        $availableYears = Activity::distinct()->pluck('fiscal_year')
                            ->merge(FiscalEnvelope::when(!$this->showDrafts, fn($q) => $q->where('status', '!=', 'Draft'))->pluck('fiscal_year'))
                            ->unique()
                            ->sortDesc();

                        if ($availableYears->isEmpty()) {
                            $availableYears = collect([$this->activeYear]);
                        }
                    @endphp
                    @foreach($availableYears as $year)
                        <option value="{{ $year }}">{{ $year }} {{ $year == $activeYear ? '(' . __('Active') . ')' : '' }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>
    @php
        $user = auth()->user();
        $hasDrafts = $activities->where('status', Activity::STATUS_DRAFT)
            ->where('directorate_id', $user->directorate_id)
            ->isNotEmpty();
        $hasDraftsAndYearIsNotLocked = $hasDrafts && !$this->isYearLocked($fiscalYear);
    @endphp

    @if($hasDraftsAndYearIsNotLocked || $this->canCreateNewPAA)
        <div class="flex justify-between items-start">

            <div class="flex items-center gap-3">

                @if($hasDraftsAndYearIsNotLocked)
                    <x-ui.button wire:click="initiateBatchSubmission" variant="black" size="md">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                            class="mr-2">
                            <path d="m22 2-7 20-4-9-9-4Z" />
                            <path d="M22 2 11 13" />
                        </svg>
                        {{ __('Submit PAA for Approval') }}
                    </x-ui.button>
                @endif

                @if($this->canCreateNewPAA)
                    <x-ui.button wire:click="startNewPAA" variant="primary" size="md">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                            class="mr-2">
                            <path d="M12 5v14" />
                            <path d="M5 12h14" />
                        </svg>
                        {{ __('New Planning') }} ({{ date('Y') + 1 }})
                    </x-ui.button>
                @endif
            </div>
        </div>
    @endif

    @if($creationAlert)
        <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4 animate-in fade-in duration-200">
            <div class="relative w-full max-w-md bg-white rounded-lg shadow-lg p-6 animate-in zoom-in-95 duration-200">
                <h3 class="text-lg font-bold text-gray-900 mb-2 underline decoration-red-500">{{ __('Existing PAA') }}</h3>
                <p class="text-sm text-gray-600">
                    {{ __('The Annual Activity Plan for the year :year has already been started. Please use the year selection above to manage or update existing activities.', ['year' => date('Y') + 1]) }}
                </p>
                <div class="mt-6 flex justify-end">
                    <button wire:click="$set('creationAlert', false)"
                        class="bg-indigo-600 text-white px-4 py-2 rounded text-xs font-bold uppercase tracking-widest hover:bg-indigo-700">{{ __('Understood') }}</button>
                </div>
            </div>
        </div>
    @endif


    @if($editAlert)
        <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4 animate-in fade-in duration-200">
            <div class="relative w-full max-w-md bg-white rounded-lg shadow-lg p-6 animate-in zoom-in-95 duration-200">
                <h3 class="text-lg font-bold text-gray-900 mb-2 underline decoration-red-500">{{ __('Existing PAA') }}</h3>
                <p class="text-sm text-gray-600">
                    {{ __('The Annual Activity Plan for the year :year has already been started. Would you like to proceed with managing or updating the existing activities?') }}
                </p>
                <div class="mt-6 flex justify-end">
                    <button wire:click="proceedToCreate"
                        class="bg-indigo-600 text-white px-4 py-2 rounded text-xs font-bold uppercase tracking-widest hover:bg-indigo-700">{{ __('Proceed') }}</button>
                </div>
            </div>
        </div>
    @endif

    <div class="flex items-center gap-2 p-1.5 bg-slate-100/50 w-fitsss rounded-2xl border border-slate-200 shadow-sm">
        <x-ui.button wire:click="$set('activeTab', 'program')"
            variant="{{ $activeTab === 'program' ? 'secondary' : 'ghost' }}" size="md" class="!rounded-xl">
            {{ __('Programmatic View') }}
        </x-ui.button>
        <x-ui.button wire:click="$set('activeTab', 'directorate')"
            variant="{{ $activeTab === 'directorate' ? 'secondary' : 'ghost' }}" size="md" class="!rounded-xl">
            {{ __('Directorate View') }}
        </x-ui.button>
        <x-ui.button wire:click="$set('activeTab', 'details')"
            variant="{{ $activeTab === 'details' ? 'secondary' : 'ghost' }}" size="md" class="!rounded-xl">
            {{ __('Tabular Details') }}
        </x-ui.button>
    </div>

    @if($activeTab === 'program')
        @include('livewire.pages.activities.list-tabs.program-view')
    @elseif($activeTab === 'directorate')
        @include('livewire.pages.activities.list-tabs.directorate-view')
    @else
        @if($activities->isEmpty())
            <div class="text-center py-8 text-gray-500 italic">
                {{ __('No activities registered so far.') }}
        @else
                @include('livewire.pages.activities.list-tabs.details-view')
            @endif
    @endif

        @if($confirmingSubmission)
            <div
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4 animate-in fade-in duration-200">
                <div
                    class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl p-8 animate-in zoom-in-95 duration-200">
                    <div class="flex items-center gap-4 mb-6">
                        <div
                            class="h-12 w-12 rounded-full bg-indigo-50 flex items-center justify-center text-indigo-600 shrink-0">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="m22 2-7 20-4-9-9-4Z" />
                                <path d="M22 2 11 13" />
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-lg font-black text-slate-900 leading-tight">
                                {{ $submissionType === 'bulk' ? __('Bulk Submission') : __('Submit Activity') }}
                            </h3>
                            <p class="text-xs text-slate-500 font-bold uppercase tracking-widest mt-1">
                                {{ __('Confirmation Required') }}
                            </p>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <p class="text-sm text-slate-600 leading-relaxed">
                            @if($submissionType === 'bulk')
                                {{ __('Are you sure you want to submit all draft activities for review? This will notify your superior and lock the activities for editing.') }}
                            @else
                                {{ __('Are you sure you want to submit this activity? This will lock it and notify your superior.') }}
                            @endif
                        </p>

                        <div class="flex items-center gap-3 p-4 bg-slate-50 rounded-xl border border-slate-100">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                                class="text-amber-500">
                                <path
                                    d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
                                <line x1="12" y1="9" x2="12" y2="13" />
                                <line x1="12" y1="17" x2="12.01" y2="17" />
                            </svg>
                            <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest">
                                {{ __('Activities will be locked after submission') }}
                            </p>
                        </div>
                    </div>

                    <div class="mt-8 flex flex-col-reverse sm:flex-row justify-end gap-3">
                        <x-ui.button wire:click="cancelSubmission" variant="ghost" class="w-full sm:w-auto">
                            {{ __('Cancel') }}
                        </x-ui.button>
                        <x-ui.button wire:click="confirmSubmission" variant="black" class="w-full sm:w-auto">
                            {{ __('Yes, Proceed') }}
                        </x-ui.button>
                    </div>
                </div>
            </div>
        @endif
    </div>