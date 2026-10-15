<?php

use App\Models\Activity;
use App\Models\ActivityReport;
use App\Policies\ActivityPolicy;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

/**
 * The narrative half of "Report execution": the words that go with the numbers. For the month: what was done
 * and what got in the way. For the quarter: the same, and where targets were not met, why and what will be
 * done, with a rating. The unit writes it (Draft, then Submitted); someone else verifies it.
 */
new class extends Component {
    public ?int $activityId = null;
    public int $month = 1;
    public array $form = ['month' => [], 'quarter' => []];

    public function mount(?int $activityId = null, ?int $month = null): void
    {
        $this->activityId = $activityId;
        $this->month = max(1, min(12, $month ?? now()->month));
        $this->fillForm();
    }

    public function getQuarterProperty(): int
    {
        return (int) ceil($this->month / 3);
    }

    public function getActivityProperty(): ?Activity
    {
        return $this->activityId ? Activity::find($this->activityId) : null;
    }

    /** @return array<string, ActivityReport|null> */
    private function reports(): array
    {
        $rows = ActivityReport::where('activity_id', $this->activityId)
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('period_type', 'month')->where('period', $this->month))
                ->orWhere(fn ($w) => $w->where('period_type', 'quarter')->where('period', $this->quarter)))->get();

        return ['month' => $rows->firstWhere('period_type', 'month'), 'quarter' => $rows->firstWhere('period_type', 'quarter')];
    }

    private function fillForm(): void
    {
        foreach ($this->reports() as $type => $r) {
            $this->form[$type] = [
                'results' => $r?->results ?? '', 'constraints' => $r?->constraints ?? '',
                'reasons_not_met' => $r?->reasons_not_met ?? '', 'corrective_measures' => $r?->corrective_measures ?? '', 'rating' => $r?->rating ?? '',
            ];
        }
    }

    private function mayReport(?string $type = null): bool
    {
        $user = auth()->user();
        $a = $this->activity;
        if (! $a || ! ($user->can('update_indicator_progress') || $user->can('approve_progress_report'))
            || ! app(ActivityPolicy::class)->inScope($user, $a->directorate_id) || $a->isLocked()) {
            return false;
        }
        $windows = app(\App\Services\ReportingWindows::class);

        return $type
            ? $windows->allows($user, $a->fiscal_year, $type, $this->period($type))
            : ($windows->allows($user, $a->fiscal_year, 'month', $this->month) || $windows->allows($user, $a->fiscal_year, 'quarter', $this->quarter));
    }

    private function mayVerify(): bool
    {
        $user = auth()->user();
        $a = $this->activity;

        return $a && ($user->can('approve_progress_report') || $user->isDirector())
            && app(ActivityPolicy::class)->inScope($user, $a->directorate_id) && ! $a->isLocked();
    }

    private function period(string $type): int
    {
        return $type === 'month' ? $this->month : $this->quarter;
    }

    public function save(string $type): void
    {
        abort_unless(in_array($type, ['month', 'quarter'], true) && $this->mayReport($type), 403);
        $data = $this->validate([
            "form.$type.results" => 'nullable|string|max:3000',
            "form.$type.constraints" => 'nullable|string|max:3000',
            "form.$type.reasons_not_met" => 'nullable|string|max:3000',
            "form.$type.corrective_measures" => 'nullable|string|max:3000',
            "form.$type.rating" => ['nullable', 'in:'.implode(',', ActivityReport::RATINGS)],
        ])['form'][$type];

        $key = ['activity_id' => $this->activityId, 'period_type' => $type, 'period' => $this->period($type)];
        $existing = ActivityReport::where($key)->first();
        if ($existing && ! $existing->isEditable()) {
            $this->dispatch('toast', message: __('Cannot edit progress that is already :status.', ['status' => __($existing->status)]), type: 'error');

            return;
        }
        $data = array_map(fn ($v) => ($v = trim((string) $v)) === '' ? null : $v, $data);
        ActivityReport::updateOrCreate($key, $data + ['status' => 'Draft']);
        $this->dispatch('toast', message: __('Saved as Draft.'), type: 'success');
        $this->dispatch('execution-changed');
    }

    public function submit(): void
    {
        abort_unless($this->mayReport(), 403);
        $n = 0;
        foreach (['month', 'quarter'] as $type) {
            if (! $this->mayReport($type)) {
                continue;
            }
            $n += ActivityReport::where(['activity_id' => $this->activityId, 'period_type' => $type, 'period' => $this->period($type)])
                ->whereIn('status', ['Draft', 'Rejected'])->update(['status' => 'Submitted', 'submitted_by' => auth()->id()]);
        }
        if ($n) {
            $this->dispatch('toast', message: __('Submitted for verification.'), type: 'success');
            $this->dispatch('execution-changed');
        }
    }

    /** The page's "submit everything" button. */
    #[On('submit-execution')]
    public function submitEverything(): void
    {
        $this->submit();
    }

    public function decide(string $type, bool $approve): void
    {
        abort_unless(in_array($type, ['month', 'quarter'], true) && $this->mayVerify(), 403);
        $report = ActivityReport::where(['activity_id' => $this->activityId, 'period_type' => $type, 'period' => $this->period($type), 'status' => 'Submitted'])->first();
        if (! $report) {
            return;
        }
        if ($report->submitted_by === auth()->id()) {
            $this->dispatch('toast', message: __('Someone other than the person who reported it must verify it.'), type: 'error');

            return;
        }
        $report->update($approve ? ['status' => 'Verified', 'verified_by' => auth()->id(), 'verified_at' => now()] : ['status' => 'Rejected']);
        $this->dispatch('toast', message: $approve ? __('Report verified.') : __('Report returned for correction.'), type: 'success');
        $this->dispatch('execution-changed');
    }

    public function with(): array
    {
        return ['reports' => $this->reports(), 'canReport' => $this->mayReport(), 'mayType' => ['month' => $this->mayReport('month'), 'quarter' => $this->mayReport('quarter')], 'canVerify' => $this->mayVerify(), 'year' => $this->activity?->fiscal_year ?? now()->year];
    }
}; ?>

<div>
    <x-ui.card :title="__('Narrative')" :description="__('The words that go with the numbers')" padding="p-5">
        <div class="space-y-8">
            @foreach(['month' => \Carbon\Carbon::create($year, $month, 1)->translatedFormat('F'), 'quarter' => 'Q'.$this->quarter] as $type => $label)
                @php
                    $r = $reports[$type];
                    $status = $r?->status ?? 'Draft';
                    $editable = $mayType[$type] && (! $r || $r->isEditable());
                @endphp
                <section wire:key="narr-{{ $type }}-{{ $type === 'month' ? $month : $this->quarter }}" aria-labelledby="narr-{{ $type }}">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <h3 id="narr-{{ $type }}" class="text-sm font-semibold text-zinc-900">{{ $type === 'month' ? __('Monthly report') : __('Quarterly report') }} · {{ $label }}</h3>
                        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium {{ ['Verified' => 'bg-emerald-50 text-emerald-800', 'Submitted' => 'bg-sky-50 text-sky-800', 'Rejected' => 'bg-rose-50 text-rose-800'][$status] ?? 'bg-zinc-100 text-zinc-700' }}">{{ $r ? __($status) : __('Not written') }}</span>
                    </div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label for="n-{{ $type }}-r" class="field-label">{{ __('What was done') }}</label>
                            <textarea id="n-{{ $type }}-r" rows="3" wire:model="form.{{ $type }}.results" @disabled(! $editable) class="flux-input"></textarea>
                        </div>
                        <div>
                            <label for="n-{{ $type }}-c" class="field-label">{{ __('Constraints') }}</label>
                            <textarea id="n-{{ $type }}-c" rows="3" wire:model="form.{{ $type }}.constraints" @disabled(! $editable) class="flux-input"></textarea>
                        </div>
                        @if($type === 'quarter')
                            <div>
                                <label for="n-q-n" class="field-label">{{ __('Why targets were not met') }}</label>
                                <textarea id="n-q-n" rows="3" wire:model="form.quarter.reasons_not_met" @disabled(! $editable) class="flux-input"></textarea>
                            </div>
                            <div>
                                <label for="n-q-m" class="field-label">{{ __('Corrective measures') }}</label>
                                <textarea id="n-q-m" rows="3" wire:model="form.quarter.corrective_measures" @disabled(! $editable) class="flux-input"></textarea>
                            </div>
                            <div class="md:col-span-2 md:max-w-xs">
                                <label for="n-q-g" class="field-label">{{ __('Rating') }}</label>
                                <select id="n-q-g" wire:model="form.quarter.rating" @disabled(! $editable) class="flux-input">
                                    <option value="">—</option>
                                    @foreach(\App\Models\ActivityReport::RATINGS as $rt)<option value="{{ $rt }}">{{ __($rt) }}</option>@endforeach
                                </select>
                            </div>
                        @endif
                    </div>
                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        @if($editable)
                            <x-ui.button type="button" variant="secondary" shadow="none" wire:click="save('{{ $type }}')">{{ __('Save draft') }}</x-ui.button>
                        @endif
                        @if($r && $r->status === 'Submitted' && $canVerify)
                            <button type="button" wire:click="decide('{{ $type }}', true)" class="text-sm font-medium text-indigo-700 hover:underline">{{ __('Verify') }}</button>
                            <button type="button" wire:click="decide('{{ $type }}', false)" class="text-sm font-medium text-rose-700 hover:underline">{{ __('Return') }}</button>
                        @endif
                    </div>
                </section>
            @endforeach
            @if($canReport)
                <div class="flex justify-end border-t border-zinc-200 pt-4">
                    <x-ui.button type="button" wire:click="submit">{{ __('Submit for Verification') }}</x-ui.button>
                </div>
            @endif
        </div>
    </x-ui.card>
</div>
