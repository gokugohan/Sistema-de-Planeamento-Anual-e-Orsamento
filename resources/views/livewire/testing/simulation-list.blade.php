<?php

use Livewire\Volt\Component;
use App\Models\Activity;
use App\Models\Program;
use App\Models\Directorate;
use Illuminate\Support\Collection;
use App\Models\FiscalEnvelope;

new class extends Component {
    public $fiscalYear;
    public $activeYear;
    public $creationAlert = false;
    public $submissionModal = false;
    public $approvalModal = false;
    public $rejectionModal = false;
    public $activeTab = 'program'; // 'program', 'details'
    public $canCreateNewPAA = true; // Always true in simulation
    public $showDrafts = false;

    public function mount(): void
    {
        $this->activeYear = FiscalEnvelope::getEffectiveYear();

        $latestTestYear = Activity::testData()->max('fiscal_year');
        $this->fiscalYear = $latestTestYear ?? (date('Y') + 1);

        $this->showDrafts = App\Models\SystemSetting::get('show_draft_planning_in_reports', false);
    }


    public function with(): array
    {
        $user = auth()->user();

        $query = Activity::testData();

        $query->with(['program', 'directorate', 'indicators', 'lineItems.adjustedBudget'])
            ->where('fiscal_year', $this->fiscalYear);

        if (!$user->hasAnyRole(['Admin', 'Planning'])) {
            $query->where('directorate_id', $user->directorate_id);
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


    public function delete($id): void
    {
        $activity = Activity::testData()->findOrFail($id);
        if ($this->isYearLocked($activity->fiscal_year)) {
            $this->dispatch('toast', message: 'Cannot delete activities from a locked fiscal year.', type: 'error');
            return;
        }
        $activity->delete();
    }

    public function submitBatch(): void
    {
        $user = auth()->user();

        $query = Activity::testData();

        $query->where('fiscal_year', $this->fiscalYear)
            ->where('status', Activity::STATUS_DRAFT);

        if (!$user->hasAnyRole(['Admin', 'Planning'])) {
            $query->where('directorate_id', $user->directorate_id);
        }

        $activities = $query->get();

        if ($activities->isEmpty()) {
            $this->dispatch('toast', message: 'No draft simulations found to submit.', type: 'error');
            return;
        }

        foreach ($activities as $activity) {
            $activity->update([
                'status' => Activity::STATUS_SUBMITTED,
                'submitted_at' => now(),
            ]);
        }

        $this->submissionModal = false;
        $this->dispatch('toast', message: count($activities) . ' simulation activities submitted for approval.', type: 'success');
    }

    public function approveBatch(): void
    {
        if (!auth()->user()->isMinister() && !auth()->user()->hasAnyRole(['Admin', 'Planning'])) {
            $this->dispatch('toast', message: 'Only the Minister or Admins can approve simulation activities.', type: 'error');
            return;
        }

        $query = Activity::testData();
        $activities = $query->where('fiscal_year', $this->fiscalYear)
            ->where('status', Activity::STATUS_SUBMITTED)
            ->get();

        if ($activities->isEmpty()) {
            $this->dispatch('toast', message: 'No submitted simulations found to approve.', type: 'error');
            return;
        }

        foreach ($activities as $activity) {
            $activity->update([
                'status' => Activity::STATUS_APPROVED,
                'approved_at' => now(),
                'approved_by' => auth()->id(),
            ]);
        }

        $this->approvalModal = false;
        $this->dispatch('toast', message: count($activities) . ' simulation activities approved.', type: 'success');
    }

    public function rejectBatch(): void
    {
        if (!auth()->user()->isMinister() && !auth()->user()->hasAnyRole(['Admin', 'Planning'])) {
            $this->dispatch('toast', message: 'Only the Minister or Admins can reject simulation activities.', type: 'error');
            return;
        }

        $query = Activity::testData();
        $activities = $query->where('fiscal_year', $this->fiscalYear)
            ->where('status', Activity::STATUS_SUBMITTED)
            ->get();

        if ($activities->isEmpty()) {
            $this->dispatch('toast', message: 'No submitted simulations found to reject.', type: 'error');
            return;
        }

        foreach ($activities as $activity) {
            $activity->update([
                'status' => Activity::STATUS_REJECTED,
            ]);
        }

        $this->rejectionModal = false;
        $this->dispatch('toast', message: count($activities) . ' simulation activities rejected.', type: 'error');
    }

    public function approve($id): void
    {
        if (!auth()->user()->isMinister() && !auth()->user()->hasAnyRole(['Admin', 'Planning'])) {
            $this->dispatch('toast', message: 'Only the Minister or Admins can approve simulation activities.', type: 'error');
            return;
        }

        $activity = Activity::testData()->findOrFail($id);
        $activity->update([
            'status' => Activity::STATUS_APPROVED,
            'approved_at' => now(),
            'approved_by' => auth()->id(),
        ]);

        $this->dispatch('toast', message: 'Simulation approved successfully.', type: 'success');
    }

    public function reject($id): void
    {
        $activity = Activity::testData()->findOrFail($id);
        $activity->update([
            'status' => Activity::STATUS_REJECTED,
        ]);

        $this->dispatch('toast', message: 'Simulation rejected.', type: 'error');
    }

    public function isYearLocked($year): bool
    {
        $query = FiscalEnvelope::testData();

        $status = $query->where('fiscal_year', $year)->value('status');
        return in_array($status, ['Active', 'Closed']);
    }

    public function startNewPAA(): void
    {
        $targetYear = date('Y') + 1;

        $query = Activity::testData();

        if ($query->where('fiscal_year', $targetYear)->exists()) {
            $this->creationAlert = true;
            return;
        }

        $this->redirect(route('testing.activities.create'), navigate: true);
    }
}; ?>

<div class="space-y-10">
    @php
        $simStatus = 'draft';
        if ($activities->isNotEmpty()) {
            if ($activities->every(fn($a) => $a->status === Activity::STATUS_APPROVED)) {
                $simStatus = 'approved';
            } elseif ($activities->contains(fn($a) => in_array($a->status, [Activity::STATUS_SUBMITTED, Activity::STATUS_REVIEWED]))) {
                $simStatus = 'review';
            }
        }
    @endphp

    <div class="bg-amber-50 rounded-xl p-4 border border-amber-200 mb-6 shadow-sm">
        <p class="text-xs text-amber-900 font-medium">
            @if($simStatus === 'approved')
                <span class="font-semibold">🧪 {{ __('SIMULATION ARCHIVE') }}</span>:
                {{ __('This simulation has been approved. These records are locked for historical reference within the sandbox.') }}
            @elseif($simStatus === 'review')
                <span class="font-semibold">🧪 {{ __('SIMULATION REVIEW') }}</span>:
                {{ __('This simulation package has been submitted for approval. You are viewing the proposed simulation outcome.') }}
            @else
                <span class="font-semibold">🧪 {{ __('SIMULATION ENVIRONMENT') }}</span>:
                {{ __('You are viewing/editing simulation data. These records are isolated from production and will not affect official reports.') }}
            @endif
        </p>
    </div>

    <div class="flex justify-between items-start">
        <div>
            <h2 class="text-3xl font-semibold text-amber-900 tracking-tight">{{ __('PAA Simulation Environment') }} 🧪</h2>
            <p class="text-amber-600 text-xs font-semibold mt-1">
                {{ __('Isolated Sandbox Data') }}
            </p>
        </div>
        <div class="flex items-center gap-4">
            <span
                class="text-xs font-semibold text-amber-400 ">{{ __('Simulation Period:') }}</span>
            <select wire:model.live="fiscalYear"
                class="rounded-xl border-amber-200 bg-amber-50 text-sm font-bold text-amber-900 focus:ring-amber-500 focus:border-amber-500 transition-all h-9 px-3 cursor-pointer shadow-sm">
                @php
                    $activityScope = Activity::testData();
                    $envelopeScope = FiscalEnvelope::testData();

                    $availableYears = $activityScope->distinct()->pluck('fiscal_year')
                        ->merge($envelopeScope->when(!$this->showDrafts, fn($q) => $q->where('status', '!=', 'Draft'))->pluck('fiscal_year'))
                        ->unique()
                        ->sortDesc();

                    if ($availableYears->isEmpty()) {
                        $availableYears = collect([$this->fiscalYear]);
                    }
                @endphp
                @foreach($availableYears as $year)
                    <option value="{{ $year }}">{{ $year }}</option>
                @endforeach
            </select>
        </div>
    </div>

    @php
        $user = auth()->user();
        $isYearLocked = $this->isYearLocked($fiscalYear);

        if ($user->hasAnyRole(['Admin', 'Planning'])) {
            $hasDrafts = $activities->where('status', Activity::STATUS_DRAFT)->isNotEmpty();
            $hasSubmitted = $activities->where('status', Activity::STATUS_SUBMITTED)->isNotEmpty();
        } else {
            $hasDrafts = $activities->where('status', Activity::STATUS_DRAFT)
                ->where('directorate_id', $user->directorate_id)
                ->isNotEmpty();
            $hasSubmitted = $activities->where('status', Activity::STATUS_SUBMITTED)
                ->where('directorate_id', $user->directorate_id)
                ->isNotEmpty();
        }

        $canSubmit = $hasDrafts && !$isYearLocked;
        $canApprove = $hasSubmitted && !$isYearLocked && ($user->isMinister() || $user->hasAnyRole(['Admin', 'Planning']));
    @endphp

    <div class="flex justify-between items-start">
        <div class="flex items-center gap-3">
            @if($canSubmit)
                <x-ui.button wire:click="$set('submissionModal', true)" variant="black" size="md">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                        class="mr-2">
                        <path d="m22 2-7 20-4-9-9-4Z" />
                        <path d="M22 2 11 13" />
                    </svg>
                    {{ __('Submit Simulation for Approval') }}
                </x-ui.button>
            @endif

            @if($canApprove)
                <x-ui.button wire:click="$set('approvalModal', true)"
                    class="!bg-emerald-600 hover:!bg-emerald-700 !text-white !border-emerald-700" size="md">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                        class="mr-2">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
                        <polyline points="22 4 12 14.01 9 11.01" />
                    </svg>
                    {{ __('Approval Simulation') }}
                </x-ui.button>

                <x-ui.button wire:click="$set('rejectionModal', true)"
                    class="!bg-rose-600 hover:!bg-rose-700 !text-white !border-rose-700" size="md">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                        class="mr-2">
                        <path d="M18 6 6 18" />
                        <path d="m6 6 12 12" />
                    </svg>
                    {{ __('Reject Simulation') }}
                </x-ui.button>
            @endif

            <x-ui.button wire:click="startNewPAA" variant="primary" size="md"
                class="!bg-amber-600 hover:!bg-amber-700 !border-amber-700">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                    class="mr-2">
                    <path d="M12 5v14" />
                    <path d="M5 12h14" />
                </svg>
                {{ __('New Simulation Planning') }}
            </x-ui.button>
        </div>
    </div>

    @if($submissionModal)
        <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4 animate-in fade-in duration-200">
            <div
                class="relative w-full max-w-md bg-white rounded-lg shadow-sm p-6 animate-in zoom-in-95 duration-200 border-t-4 border-amber-600">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 bg-amber-100 rounded-full flex items-center justify-center">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"
                            class="text-amber-600">
                            <path d="m22 2-7 20-4-9-9-4Z" />
                            <path d="M22 2 11 13" />
                        </svg>
                    </div>
                    <h3 class="text-lg font-bold text-amber-900">{{ __('Submit Simulation Package') }}</h3>
                </div>

                <p class="text-sm text-gray-600">
                    {{ __('You are about to submit high-fidelity simulation drafts for the :year fiscal period.', ['year' => $this->fiscalYear]) }}
                </p>
                <p class="text-xs text-amber-700 mt-4 bg-amber-50 p-3 rounded-lg border border-amber-100 italic">
                    {{ __('Note: This action moves the selected activities to the review stage within the simulator sandbox.') }}
                </p>

                <div class="mt-8 flex justify-end gap-3">
                    <button wire:click="$set('submissionModal', false)"
                        class="px-4 py-2 text-xs font-bold text-zinc-500 hover:text-zinc-700 transition-colors">{{ __('Cancel') }}</button>
                    <button wire:click="submitBatch"
                        class="bg-amber-600 text-white px-4 py-2 rounded-lg text-xs font-bold hover:bg-amber-700 shadow-sm active:scale-95 transition-all">{{ __('Verify & Submit') }}</button>
                </div>
            </div>
        </div>
    @endif

    @if($approvalModal)
        <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4 animate-in fade-in duration-200">
            <div
                class="relative w-full max-w-md bg-white rounded-lg shadow-sm p-6 animate-in zoom-in-95 duration-200 border-t-4 border-emerald-600">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 bg-emerald-100 rounded-full flex items-center justify-center">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"
                            class="text-emerald-600">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
                            <polyline points="22 4 12 14.01 9 11.01" />
                        </svg>
                    </div>
                    <h3 class="text-lg font-bold text-emerald-900">{{ __('Approval Simulation') }}</h3>
                </div>

                <p class="text-sm text-gray-600">
                    {{ __('You are performing a mass approval for all :year simulation activities currently in the "Submitted" stage.', ['year' => $this->fiscalYear]) }}
                </p>
                <div class="mt-4 p-4 bg-emerald-50 rounded-xl border border-emerald-100">
                    <div
                        class="flex items-center justify-between text-xs font-semibold text-emerald-700">
                        <span>{{ __('Activities found') }}</span>
                        <span
                            class="bg-emerald-200 px-2 py-1 rounded-md">{{ $activities->where('status', Activity::STATUS_SUBMITTED)->count() }}</span>
                    </div>
                </div>

                <div class="mt-8 flex justify-end gap-3">
                    <button wire:click="$set('approvalModal', false)"
                        class="px-4 py-2 text-xs font-bold text-zinc-500 hover:text-zinc-700 transition-colors">{{ __('Cancel') }}</button>
                    <button wire:click="approveBatch"
                        class="bg-emerald-600 text-white px-4 py-2 rounded-lg text-xs font-bold hover:bg-emerald-700 shadow-sm active:scale-95 transition-all">{{ __('Verify & Approve All') }}</button>
                </div>
            </div>
        </div>
    @endif

    @if($rejectionModal)
        <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4 animate-in fade-in duration-200">
            <div
                class="relative w-full max-w-md bg-white rounded-lg shadow-sm p-6 animate-in zoom-in-95 duration-200 border-t-4 border-rose-600">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 bg-rose-100 rounded-full flex items-center justify-center">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"
                            class="text-rose-600">
                            <path d="M18 6 6 18" />
                            <path d="m6 6 12 12" />
                        </svg>
                    </div>
                    <h3 class="text-lg font-bold text-rose-900">{{ __('Reject Simulation') }}</h3>
                </div>

                <p class="text-sm text-gray-600">
                    {{ __('You are about to REJECT all submitted simulation activities for :year. This will return them to the draft/rejected state.', ['year' => $this->fiscalYear]) }}
                </p>
                <div class="mt-4 p-4 bg-rose-50 rounded-xl border border-rose-100">
                    <div
                        class="flex items-center justify-between text-xs font-semibold text-rose-700">
                        <span>{{ __('Activities found') }}</span>
                        <span
                            class="bg-rose-200 px-2 py-1 rounded-md">{{ $activities->where('status', Activity::STATUS_SUBMITTED)->count() }}</span>
                    </div>
                </div>

                <div class="mt-8 flex justify-end gap-3">
                    <button wire:click="$set('rejectionModal', false)"
                        class="px-4 py-2 text-xs font-bold text-zinc-500 hover:text-zinc-700 transition-colors">{{ __('Cancel') }}</button>
                    <button wire:click="rejectBatch"
                        class="bg-rose-600 text-white px-4 py-2 rounded-lg text-xs font-bold hover:bg-rose-700 shadow-sm active:scale-95 transition-all">{{ __('Confirm Rejection') }}</button>
                </div>
            </div>
        </div>
    @endif

    @if($creationAlert)
        <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4 animate-in fade-in duration-200">
            <div
                class="relative w-full max-w-md bg-white rounded-lg shadow-sm p-6 animate-in zoom-in-95 duration-200 border-t-4 border-amber-500">
                <h3 class="text-lg font-bold text-amber-900 mb-2 underline decoration-amber-500">
                    {{ __('Existing Simulation') }}
                </h3>
                <p class="text-sm text-gray-600">
                    {{ __('The Simulation for the year :year has already been started.', ['year' => date('Y') + 1]) }}
                </p>
                <div class="mt-6 flex justify-end">
                    <button wire:click="$set('creationAlert', false)"
                        class="bg-amber-600 text-white px-4 py-2 rounded text-xs font-bold hover:bg-amber-700">{{ __('Understood') }}</button>
                </div>
            </div>
        </div>
    @endif

    <div class="flex items-center gap-2 p-1.5 bg-amber-50/50 w-fit rounded-xl border border-amber-200 shadow-sm">
        <x-ui.button wire:click="$set('activeTab', 'program')"
            variant="{{ $activeTab === 'program' ? 'secondary' : 'ghost' }}" size="md" class="!rounded-xl">
            {{ __('Programmatic View') }}
        </x-ui.button>
        <x-ui.button wire:click="$set('activeTab', 'details')"
            variant="{{ $activeTab === 'details' ? 'secondary' : 'ghost' }}" size="md" class="!rounded-xl">
            {{ __('Tabular Details') }}
        </x-ui.button>
    </div>

    @if($activeTab === 'program')
        @include('livewire.testing.list-tabs.program-view')
    @else
        @if($activities->isEmpty())
            <div class="text-center py-8 text-amber-500 italic">
                {{ __('No simulation activities registered so far.') }}
            </div>
        @else
            @include('livewire.testing.list-tabs.details-view')
        @endif
    @endif
</div>