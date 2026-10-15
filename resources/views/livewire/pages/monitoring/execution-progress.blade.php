<?php

use App\Models\Activity;
use App\Models\Indicator;
use App\Models\IndicatorProgress;
use App\Policies\ActivityPolicy;
use App\Services\PlanningWindow;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

/**
 * The progress half of "Report execution": what an activity achieved against each of its indicators in a
 * quarter. The activity and the quarter come from the page that holds it. The unit reports a figure
 * (Draft, then Submitted); someone else verifies it, and only verified figures count. A verified figure is
 * changed only by reopening it, by the final approver or an administrator, with a remark.
 */
new class extends Component {
    public ?int $activityId = null;
    public int $quarter = 1;
    public ?int $reopenId = null;
    public string $remark = '';

    public function mount(?int $activityId = null, ?int $quarter = null): void
    {
        $this->activityId = $activityId;
        $this->quarter = max(1, min(4, $quarter ?? (int) ceil(now()->month / 3)));
    }

    /** The indicators of the activity, with every quarter's progress (one query each, nothing lazy). */
    public function getIndicatorsProperty()
    {
        return $this->activityId ? Indicator::where('activity_id', $this->activityId)->with('progress')->orderBy('id')->get() : collect();
    }

    /** An indicator the user may report on: permission, and it belongs to their unit (or they see all units). */
    private function mayReport($indicatorId): bool
    {
        $user = auth()->user();
        if (! $user->can('update_indicator_progress') && ! $user->can('approve_progress_report')) {
            return false;
        }
        $activity = Indicator::with('activity:id,directorate_id,fiscal_year')->find($indicatorId)?->activity;

        return $activity && app(ActivityPolicy::class)->inScope($user, $activity->directorate_id)
            && app(\App\Services\ReportingWindows::class)->allows($user, $activity->fiscal_year, 'quarter', $this->quarter);
    }

    /** For the view: can this user reopen verified figures at all? (each reopening is checked again on its own) */
    public function getCanCorrectProperty(): bool
    {
        return app(PlanningWindow::class)->canDecide(auth()->user());
    }

    private function mayCorrect($indicatorId): bool
    {
        $indicator = Indicator::with('activity:id,directorate_id,fiscal_year')->find($indicatorId);
        $user = auth()->user();

        return $indicator?->activity
            && app(PlanningWindow::class)->canDecide($user)
            && app(ActivityPolicy::class)->inScope($user, $indicator->activity->directorate_id)
            && ! $indicator->activity->isLocked();
    }

    public function reopenProgress(): void
    {
        $id = $this->reopenId;
        abort_unless($id && $this->mayCorrect($id), 403);
        $remark = trim($this->remark);
        $this->remark = '';
        $this->reopenId = null;
        if (mb_strlen($remark) < 3) {
            $this->dispatch('toast', message: __('Say why the figure is being reopened.'), type: 'error');

            return;
        }

        $progress = IndicatorProgress::where('indicator_id', $id)->where('quarter', $this->quarter)->where('status', 'Verified')->first();
        if ($progress) {
            $progress->auditMetadata = ['correction' => 'reopened', 'remark' => $remark, 'by' => auth()->id()];
            $progress->update(['status' => 'Draft', 'verified_by' => null, 'verified_at' => null, 'rejection_notes' => $remark]);
            $this->dispatch('toast', message: __('Reopened for correction.'), type: 'success');
            $this->dispatch('execution-changed');
        }
    }

    public function saveProgress($indicatorId, $value): void
    {
        abort_unless($this->mayReport($indicatorId), 403);
        $progress = IndicatorProgress::where('indicator_id', $indicatorId)->where('quarter', $this->quarter)->first();

        // A submitted or verified figure is not overwritten from the entry form
        if ($progress && ! in_array($progress->status, ['Draft', 'Rejected'])) {
            $this->dispatch('toast', message: __('Cannot edit progress that is already :status.', ['status' => __($progress->status)]), type: 'error');

            return;
        }

        IndicatorProgress::updateOrCreate(
            ['indicator_id' => $indicatorId, 'quarter' => $this->quarter],
            ['achieved_value' => $value, 'status' => 'Draft'] // what this form saves always starts as a Draft
        );

        $this->dispatch('toast', message: __('Progress saved as Draft. It will reflect in charts once Verified by a Director.'), type: 'success');
        $this->dispatch('execution-changed');
    }

    public function submitProgress(): void
    {
        $ids = $this->indicators->pluck('id')->filter(fn ($id) => $this->mayReport($id));

        $n = IndicatorProgress::whereIn('indicator_id', $ids)->where('quarter', $this->quarter)->whereIn('status', ['Draft', 'Rejected'])
            ->update(['status' => 'Submitted', 'submitted_by' => auth()->id()]);

        if ($n) {
            $this->dispatch('toast', message: __('Progress submitted for verification.'), type: 'success');
            $this->dispatch('execution-changed');
        }
    }

    /** The page's "submit everything" button: this panel's drafts go for verification too. */
    #[On('submit-execution')]
    public function submitEverything(): void
    {
        $this->submitProgress();
    }

    public function verifyProgress($indicatorId): void
    {
        $this->decide($indicatorId, true);
    }

    public function rejectProgress($indicatorId): void
    {
        $this->decide($indicatorId, false);
    }

    private function decide($indicatorId, bool $approve): void
    {
        abort_unless($this->mayReport($indicatorId), 403);
        if (! auth()->user()->isDirector()) {
            $this->dispatch('toast', message: __('Only Directors can verify progress.'), type: 'error');

            return;
        }

        $progress = IndicatorProgress::where('indicator_id', $indicatorId)->where('quarter', $this->quarter)->first();
        if (! $progress) {
            return;
        }
        if ($progress->submitted_by === auth()->id()) {
            $this->dispatch('toast', message: __('Someone other than the person who reported it must verify it.'), type: 'error');

            return;
        }

        $progress->update($approve
            ? ['status' => 'Verified', 'verified_by' => auth()->id(), 'verified_at' => now()]
            : ['status' => 'Rejected']);
        $this->dispatch('toast', message: $approve ? __('Progress verified successfully.') : __('Progress rejected and returned to draft.'), type: 'success');
        $this->dispatch('execution-changed');
    }

    public function with(): array
    {
        // every indicator of the activity belongs to one unit, so one check answers for all of them
        $first = $this->indicators->first();

        return ['canReport' => $first ? $this->mayReport($first->id) : false];
    }
}; ?>

<div>
    @php
        $indicators = $this->indicators;
        $anyDrafts = $indicators->contains(function ($i) {
            $p = $i->progress->firstWhere('quarter', (int) $this->quarter);

            return $p && in_array($p->status, ['Draft', 'Rejected'], true);
        });
        $verifierNames = \App\Models\User::whereIn('id', $indicators->flatMap(fn ($i) => $i->progress->pluck('verified_by'))->filter()->unique())->pluck('name', 'id');
    @endphp

    <x-ui.card :title="__('Indicators')" :description="__('What the activity achieved in Q:q', ['q' => $quarter])" padding="p-0">
        @if(! $activityId)
            <x-ui.empty-state :title="__('Choose an activity')" />
        @elseif($indicators->isEmpty())
            <x-ui.empty-state :title="__('This activity has no indicators.')" />
        @else
            <x-ui.table :headers="[__('Indicator'), __('Unit'), __('Annual target'), __('Achieved Value').' (Q'.$quarter.')', __('Status')]" :right="[2]" class="text-sm">
                @foreach($indicators as $indicator)
                    @php
                        $progress = $indicator->progress->firstWhere('quarter', (int) $quarter);
                        $status = $progress?->status ?? 'Draft';
                        $standing = $indicator->standing($indicator->progress->where('status', 'Verified')->pluck('achieved_value', 'quarter'));
                        $standingPct = $indicator->annual_target > 0 ? min(100, $standing / $indicator->annual_target * 100) : null;
                        $editable = in_array($status, ['Draft', 'Rejected'], true) && $canReport;
                    @endphp
                    <tr wire:key="ind-{{ $indicator->id }}-{{ $quarter }}">
                        <td class="px-5 py-3">
                            <div class="font-medium text-zinc-900">{{ $indicator->name }}</div>
                            @unless($indicator->isDueIn((int) $quarter))
                                <span class="mt-0.5 inline-flex rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-700">{{ __('Not due this quarter') }}</span>
                            @endunless
                            <div class="mt-0.5 text-xs text-zinc-500">{{ __($indicator->type) }} · {{ $indicator->isLevel() ? __('Level: enter where it stands at the end of the quarter') : __('Adds up: enter what was achieved in this quarter') }}</div>
                            <div class="text-xs text-zinc-500">{{ __('Verified so far') }}: <span class="font-semibold tabular-nums text-zinc-700">{{ number_format($standing, 2) }}</span> {{ $indicator->unit }}@if($standingPct !== null) ({{ number_format($standingPct, 0) }}%)@endif</div>
                        </td>
                        <td class="px-5 py-3 text-xs text-zinc-600">{{ $indicator->unit }}</td>
                        <td class="px-5 py-3 num font-medium text-zinc-900">{{ number_format($indicator->annual_target, 2) }}</td>
                        <td class="px-5 py-3 align-top">
                            <input type="number" step="0.01" value="{{ $progress?->achieved_value ?? 0 }}" @disabled(! $editable)
                                aria-label="{{ __('Achieved Value') }} (Q{{ $quarter }}): {{ $indicator->name }}"
                                x-on:blur="$wire.saveProgress({{ $indicator->id }}, $event.target.value)"
                                class="flux-input !w-32 text-right tabular-nums">
                        </td>
                        <td class="px-5 py-3 align-top">
                            <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium {{ ['Verified' => 'bg-emerald-50 text-emerald-800', 'Submitted' => 'bg-sky-50 text-sky-800', 'Rejected' => 'bg-rose-50 text-rose-800'][$status] ?? 'bg-zinc-100 text-zinc-700' }}">{{ __($status) }}</span>
                            @if($status === 'Verified' && $progress?->verified_at)
                                <div class="mt-1 text-xs text-zinc-500">{{ $verifierNames[$progress->verified_by] ?? '—' }} · {{ $progress->verified_at->format('d/m/Y') }}</div>
                                @if($this->canCorrect)
                                    <button type="button" x-on:click="$wire.reopenId = {{ $indicator->id }}; $dispatch('open-modal', 'reopen-progress')" class="mt-1 text-xs font-medium text-indigo-700 hover:underline">{{ __('Reopen') }}</button>
                                @endif
                            @endif
                            @if($status === 'Submitted' && auth()->user()->isDirector())
                                <div class="mt-2 flex gap-3">
                                    <button type="button" wire:click="verifyProgress({{ $indicator->id }})" class="text-xs font-medium text-indigo-700 hover:underline">{{ __('Verify') }}</button>
                                    <button type="button" wire:click="rejectProgress({{ $indicator->id }})" class="text-xs font-medium text-rose-700 hover:underline">{{ __('Return') }}</button>
                                </div>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>

            @if($anyDrafts && $canReport)
                <div class="flex justify-end border-t border-zinc-200 bg-zinc-50 px-5 py-4">
                    <x-ui.button type="button" wire:click="submitProgress">{{ __('Submit for Verification') }}</x-ui.button>
                </div>
            @endif
        @endif
    </x-ui.card>

    @if($this->canCorrect)
        <x-note-dialog name="reopen-progress" property="remark" action="reopenProgress" :required="true"
            :title="__('Reopen this verified figure?')"
            :message="__('It goes back to a draft so it can be corrected and verified again. This is recorded with your remark.')"
            :label="__('Why is it being reopened?')" :confirm="__('Reopen')" />
    @endif
</div>
