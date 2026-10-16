<?php

use App\Models\Activity;
use App\Models\BudgetExecution;
use App\Models\FiscalEnvelope;
use App\Models\LineItem;
use App\Policies\ActivityPolicy;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

/**
 * The spending half of "Report execution": what an approved activity committed and paid in a month,
 * line by line. The activity and the month come from the page that holds it. The unit reports it
 * (Draft, then Submitted); someone else verifies it, and only verified figures count in the reports.
 * A verified figure is changed only by reopening it.
 */
new class extends Component {
    public int $year;
    public int $month = 1;
    public ?int $activityId = null;
    /** @var array<int, array{committed: mixed, paid: mixed}> keyed by budget line */
    public array $form = [];

    public function mount(?int $activityId = null, ?int $month = null): void
    {
        $this->year = FiscalEnvelope::getEffectiveYear();
        $this->month = max(1, min($month ?? $this->latestMonth(), $this->latestMonth()));
        $this->activityId = $activityId;
        if ($activityId) {
            $this->fillForm();
        }
    }

    /** The newest month that has started: reporting on a month that has not begun makes no sense. */
    private function latestMonth(): int
    {
        return match (true) {
            now()->year < $this->year => 1,
            now()->year > $this->year => 12,
            default => now()->month,
        };
    }

    private function mayReport(Activity $activity): bool
    {
        $user = auth()->user();

        return ($user->can('update_indicator_progress') || $user->can('approve_progress_report'))
            && app(ActivityPolicy::class)->inScope($user, $activity->directorate_id)
            && ! $activity->isLocked()
            && app(\App\Services\ReportingWindows::class)->allows($user, $this->year, 'month', $this->month);
    }

    private function mayVerify(Activity $activity): bool
    {
        $user = auth()->user();

        return ($user->can('verify_spending') || $user->can('approve_progress_report') || $user->isDirector())
            && app(ActivityPolicy::class)->inScope($user, $activity->directorate_id)
            && ! $activity->isLocked();
    }

    public function getActivityProperty(): ?Activity
    {
        return $this->activityId ? Activity::with('directorate:id,code,name')->find($this->activityId) : null;
    }

    /** The budget lines of the chosen activity with their approved amount and what was reported in the other months. */
    public function getLinesProperty()
    {
        if (! $this->activityId) {
            return collect();
        }

        return LineItem::where('activity_id', $this->activityId)
            ->whereHas('adjustedBudget')
            ->with(['economicClassification:id,code,name', 'adjustedBudget:id,line_item_id,approved_amount', 'executions.verifiedBy:id,name'])
            ->orderBy('id')->get()
            ->map(function ($line) {
                $this_m = $line->executions->firstWhere('month', $this->month);
                $others = $line->executions->where('month', '!=', $this->month);

                return (object) [
                    'id' => $line->id, 'code' => $line->code, 'category' => $line->economicClassification?->code.' '.$line->economicClassification?->name,
                    'approved' => (float) $line->adjustedBudget->approved_amount,
                    'other_committed' => (float) $others->sum('committed'), 'other_paid' => (float) $others->sum('paid'),
                    'entry' => $this_m,
                    'months' => $line->executions->sortBy('month')->map(fn ($e) => ['month' => (int) $e->month, 'committed' => (float) $e->committed, 'paid' => (float) $e->paid, 'status' => $e->status])->values()->all(),
                ];
            });
    }

    public function updatedActivityId(): void
    {
        $this->resetErrorBag();
        $this->fillForm();
    }

    public function updatedMonth(): void
    {
        $this->month = max(1, min((int) $this->month, $this->latestMonth()));
        $this->resetErrorBag();
        $this->fillForm();
    }

    private function fillForm(): void
    {
        $this->form = [];
        foreach ($this->lines as $line) {
            $this->form[$line->id] = [
                'committed' => $line->entry ? (float) $line->entry->committed : '',
                'paid' => $line->entry ? (float) $line->entry->paid : '',
            ];
        }
    }

    public function save(bool $submit = false): void
    {
        $activity = $this->activity;
        abort_unless($activity && $this->mayReport($activity), 403);
        abort_if($this->month > $this->latestMonth(), 422);

        $saved = 0;
        $errors = [];
        foreach ($this->lines as $line) {
            if ($line->entry && ! $line->entry->isEditable()) {
                continue; // submitted or verified: not changed from here
            }
            $in = $this->form[$line->id] ?? [];
            if (($in['committed'] ?? '') === '' && ($in['paid'] ?? '') === '') {
                continue;
            }
            $committed = round((float) ($in['committed'] ?? 0), 2);
            $paid = round((float) ($in['paid'] ?? 0), 2);

            if ($committed < 0 || $paid < 0) {
                $errors["form.{$line->id}.committed"] = __('Amounts cannot be negative.');
            } elseif ($line->other_committed + $committed > $line->approved + 0.005) {
                $errors["form.{$line->id}.committed"] = __('Committed would exceed the approved budget of $ :a.', ['a' => number_format($line->approved, 2)]);
            } elseif ($line->other_paid + $paid > $line->other_committed + $committed + 0.005) {
                $errors["form.{$line->id}.paid"] = __('Paid cannot exceed what has been committed.');
            } else {
                BudgetExecution::updateOrCreate(
                    ['line_item_id' => $line->id, 'month' => $this->month],
                    ['committed' => $committed, 'paid' => $paid, 'status' => $submit ? BudgetExecution::SUBMITTED : BudgetExecution::DRAFT,
                     'submitted_by' => auth()->id(), 'verified_by' => null, 'verified_at' => null]
                );
                $saved++;
            }
        }

        foreach ($errors as $key => $message) {
            $this->addError($key, $message);
        }
        if ($errors) {
            $this->dispatch('toast', message: __('Some lines were not saved: check the amounts.'), type: 'error');
        } elseif ($saved) {
            $this->dispatch('toast', message: $submit ? __('Submitted for verification.') : __('Saved as a draft.'), type: 'success');
            $this->dispatch('execution-changed');
        }
        $this->fillForm();
    }

    public ?int $reopenId = null;
    public string $remark = '';

    /** Reopen a verified line for correction: the final approver or an administrator, in scope, before the year is closed. */
    private function mayCorrect(Activity $activity): bool
    {
        $user = auth()->user();

        return app(\App\Services\PlanningWindow::class)->canDecide($user, null, $activity->directorate_id)
            && app(ActivityPolicy::class)->inScope($user, $activity->directorate_id)
            && ! $activity->isLocked();
    }

    public function reopen(): void
    {
        $activity = $this->activity;
        $id = $this->reopenId;
        $remark = trim($this->remark);
        $this->reopenId = null;
        $this->remark = '';
        abort_unless($activity && $id && $this->mayCorrect($activity), 403);
        if (mb_strlen($remark) < 3) {
            $this->dispatch('toast', message: __('Say why the figure is being reopened.'), type: 'error');

            return;
        }

        $entry = BudgetExecution::where('line_item_id', $id)->where('month', $this->month)->where('status', BudgetExecution::VERIFIED)
            ->whereHas('lineItem', fn ($q) => $q->where('activity_id', $activity->id))->firstOrFail();
        $entry->auditMetadata = ['correction' => 'reopened', 'remark' => $remark, 'by' => auth()->id()];
        $entry->update(['status' => BudgetExecution::DRAFT, 'verified_by' => null, 'verified_at' => null, 'notes' => $remark]);

        $this->dispatch('toast', message: __('Reopened for correction.'), type: 'success');
        $this->dispatch('execution-changed');
        $this->fillForm();
    }

    public function verify(int $lineId, bool $approve = true): void
    {
        $activity = $this->activity;
        abort_unless($activity && $this->mayVerify($activity), 403);

        $entry = BudgetExecution::where('line_item_id', $lineId)->where('month', $this->month)
            ->whereHas('lineItem', fn ($q) => $q->where('activity_id', $activity->id))->firstOrFail();

        if ($entry->status !== BudgetExecution::SUBMITTED) {
            return;
        }
        if ($entry->submitted_by === auth()->id()) {
            $this->dispatch('toast', message: __('Someone other than the person who reported it must verify it.'), type: 'error');

            return;
        }

        $entry->update($approve
            ? ['status' => BudgetExecution::VERIFIED, 'verified_by' => auth()->id(), 'verified_at' => now()]
            : ['status' => BudgetExecution::REJECTED, 'verified_by' => null, 'verified_at' => null]);
        $this->dispatch('toast', message: $approve ? __('Verified.') : __('Returned for correction.'), type: 'success');
        $this->dispatch('execution-changed');
        $this->fillForm();
    }

    /** Verify every spending figure of the month that someone else reported (a verifier's shortcut). */
    public function verifyAll(): void
    {
        $activity = $this->activity;
        abort_unless($activity && $this->mayVerify($activity), 403);

        $n = BudgetExecution::whereIn('line_item_id', $this->lines->pluck('id'))->where('month', $this->month)->where('status', BudgetExecution::SUBMITTED)
            ->where(fn ($q) => $q->whereNull('submitted_by')->orWhere('submitted_by', '!=', auth()->id()))
            ->update(['status' => BudgetExecution::VERIFIED, 'verified_by' => auth()->id(), 'verified_at' => now()]);

        $this->dispatch('toast', message: $n ? trans_choice(':n figure verified.|:n figures verified.', $n, ['n' => $n]) : __('Nothing to verify: your own reports are left for someone else.'), type: $n ? 'success' : 'info');
        if ($n) {
            $this->dispatch('execution-changed');
        }
        $this->fillForm();
    }

    /** The page's "submit everything" button: this panel's drafts go for verification too. */
    #[On('submit-execution')]
    public function submitEverything(): void
    {
        $activity = $this->activity;
        if ($activity && $this->mayReport($activity)) {
            $this->save(true);
        }
    }

    public function with(): array
    {
        $activity = $this->activity;

        return [
            'canReport' => $activity ? $this->mayReport($activity) : false,
            'canVerify' => $activity ? $this->mayVerify($activity) : false,
            'canCorrect' => $activity ? $this->mayCorrect($activity) : false,
        ];
    }
}; ?>

<div>
    @if(! $activityId)
        <x-ui.card :title="__('Spending')" padding="p-0"><x-ui.empty-state :title="__('Choose an activity')" /></x-ui.card>
    @else
        @php $lines = $this->lines; @endphp
        <x-ui.card :title="__('Spending')" :description="__('What each budget line committed and paid in :month', ['month' => \Carbon\Carbon::create($year, $month, 1)->translatedFormat('F')])" padding="p-0">
            @if($lines->isEmpty())
                <x-ui.empty-state :title="__('This activity has no distributed budget lines.')" />
            @else
                <form wire:submit="save(false)">
                    <x-ui.table :headers="[__('Budget line'), __('Approved'), __('Reported in other months'), __('Committed this month'), __('Paid this month'), __('Status')]" :right="[1, 2]" class="text-sm">
                        @foreach($lines as $line)
                            @php
                                $locked = $line->entry && ! $line->entry->isEditable();
                                $disabled = $locked || ! $canReport;
                            @endphp
                            <tr wire:key="line-{{ $line->id }}-{{ $month }}">
                                <td class="px-5 py-3">
                                    <div class="font-medium text-zinc-900">{{ $line->code }}</div>
                                    <div class="text-xs text-zinc-500">{{ $line->category }}</div>
                                </td>
                                <td class="px-5 py-3 num">$ {{ number_format($line->approved, 2) }}</td>
                                <td class="px-5 py-3 num text-zinc-600">
                                    <div>{{ __('Committed') }} $ {{ number_format($line->other_committed, 2) }}</div>
                                    <div class="text-xs">{{ __('Paid') }} $ {{ number_format($line->other_paid, 2) }}</div>
                                    @if(count($line->months) > 1 || (count($line->months) === 1 && $line->months[0]['month'] !== $month))
                                        <details class="mt-1 text-left text-xs">
                                            <summary class="cursor-pointer text-indigo-700">{{ __('Month by month') }}</summary>
                                            <ul class="mt-1 space-y-0.5 tabular-nums">
                                                @foreach($line->months as $mm)
                                                    <li>{{ \Carbon\Carbon::create($year, $mm['month'], 1)->translatedFormat('M') }}: {{ number_format($mm['committed']) }} / {{ number_format($mm['paid']) }}</li>
                                                @endforeach
                                            </ul>
                                        </details>
                                    @endif
                                </td>
                                @foreach(['committed', 'paid'] as $field)
                                    <td class="px-5 py-3 align-top">
                                        <label for="f-{{ $field }}-{{ $line->id }}" class="sr-only">{{ __(ucfirst($field)) }} — {{ $line->code }}</label>
                                        <input id="f-{{ $field }}-{{ $line->id }}" type="number" step="0.01" min="0" inputmode="decimal" @disabled($disabled)
                                            wire:model="form.{{ $line->id }}.{{ $field }}" class="flux-input !w-36 text-right tabular-nums"
                                            @error("form.{$line->id}.{$field}") aria-invalid="true" aria-describedby="e-{{ $field }}-{{ $line->id }}" @enderror>
                                        @error("form.{$line->id}.{$field}")
                                            <p id="e-{{ $field }}-{{ $line->id }}" class="field-error max-w-[14rem]">{{ $message }}</p>
                                        @enderror
                                    </td>
                                @endforeach
                                <td class="px-5 py-3 align-top">
                                    @if($line->entry)
                                        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium {{ ['Verified' => 'bg-emerald-50 text-emerald-800', 'Submitted' => 'bg-sky-50 text-sky-800', 'Rejected' => 'bg-rose-50 text-rose-800'][$line->entry->status] ?? 'bg-zinc-100 text-zinc-700' }}">{{ __($line->entry->status) }}</span>
                                        @if($line->entry->status === 'Verified' && $line->entry->verified_at)
                                            <div class="mt-1 text-xs text-zinc-500">{{ $line->entry->verifiedBy?->name ?? '—' }} · {{ $line->entry->verified_at->format('d/m/Y') }}</div>
                                            @if($canCorrect)
                                                <button type="button" x-on:click="$wire.reopenId = {{ $line->id }}; $dispatch('open-modal', 'reopen-line')" class="mt-1 text-xs font-medium text-indigo-700 hover:underline">{{ __('Reopen') }}</button>
                                            @endif
                                        @endif
                                        @if($line->entry->status === 'Submitted' && $canVerify)
                                            <div class="mt-2 flex gap-2">
                                                <button type="button" wire:click="verify({{ $line->id }})" class="text-xs font-medium text-indigo-700 hover:underline">{{ __('Verify') }}</button>
                                                <button type="button" wire:click="verify({{ $line->id }}, false)" class="text-xs font-medium text-rose-700 hover:underline">{{ __('Return') }}</button>
                                            </div>
                                        @endif
                                    @else
                                        <span class="text-xs text-zinc-500">{{ __('Not reported') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </x-ui.table>

                    @php $anyEditable = $lines->contains(fn ($l) => ! $l->entry || $l->entry->isEditable()); @endphp
                    @php $anySubmitted = $lines->contains(fn ($l) => $l->entry && $l->entry->status === 'Submitted'); @endphp
                    @if($canVerify && $anySubmitted)
                        <div class="flex justify-end border-t border-zinc-200 bg-zinc-50 px-5 py-3">
                            <x-ui.button type="button" variant="secondary" shadow="none" wire:click="verifyAll">{{ __('Verify all submitted') }}</x-ui.button>
                        </div>
                    @endif
                    @if($canReport && $anyEditable)
                        <div class="flex flex-col-reverse gap-2 border-t border-zinc-200 bg-zinc-50 px-5 py-4 sm:flex-row sm:justify-end">
                            <x-ui.button type="submit" variant="secondary" shadow="none">{{ __('Save draft') }}</x-ui.button>
                            <x-ui.button type="button" wire:click="save(true)">{{ __('Submit for verification') }}</x-ui.button>
                        </div>
                    @endif
                </form>
            @endif
        </x-ui.card>
    @endif

    @if($canCorrect)
        <x-note-dialog name="reopen-line" property="remark" action="reopen" :required="true"
            :title="__('Reopen this verified figure?')"
            :message="__('It goes back to a draft so it can be corrected and verified again. This is recorded with your remark.')"
            :label="__('Why is it being reopened?')" :confirm="__('Reopen')" />
    @endif
</div>
