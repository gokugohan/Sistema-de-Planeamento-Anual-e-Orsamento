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
    /** A new corrective action (an optional rule) and the note that closes one. */
    public array $action = ['description' => '', 'owner_name' => '', 'due_on' => ''];
    public ?int $closingId = null;
    public string $closingNote = '';

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
            // one model at a time, so that every submitted report leaves its own row in the audit trail
            $n += ActivityReport::where(['activity_id' => $this->activityId, 'period_type' => $type, 'period' => $this->period($type)])
                ->whereIn('status', ['Draft', 'Rejected'])->get()
                ->each(fn (ActivityReport $r) => $r->update(['status' => 'Submitted', 'submitted_by' => auth()->id()]))
                ->count();
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

    // ── corrective actions, where the institution tracks them (PlanOptions::correctiveActions)

    private function tracksActions(): bool
    {
        return \App\Support\PlanOptions::current()->correctiveActions && $this->activity !== null;
    }

    public function addAction(): void
    {
        abort_unless($this->tracksActions() && $this->mayReport(), 403);
        $data = $this->validate([
            'action.description' => 'required|string|min:3|max:2000',
            'action.owner_name' => 'required|string|max:150',
            'action.due_on' => 'nullable|date',
        ])['action'];

        \App\Models\CorrectiveAction::create([
            'activity_id' => $this->activityId, 'quarter' => $this->quarter, 'description' => trim($data['description']),
            'owner_name' => trim($data['owner_name']), 'due_on' => $data['due_on'] ?: null, 'status' => \App\Models\CorrectiveAction::OPEN, 'created_by' => auth()->id(),
        ]);
        $this->action = ['description' => '', 'owner_name' => '', 'due_on' => ''];
        $this->dispatch('toast', message: __('Corrective action recorded.'), type: 'success');
    }

    public function closeAction(): void
    {
        abort_unless($this->tracksActions() && ($this->mayReport() || $this->mayVerify()), 403);
        $item = \App\Models\CorrectiveAction::where('activity_id', $this->activityId)->whereKey($this->closingId)->where('status', \App\Models\CorrectiveAction::OPEN)->firstOrFail();
        if (mb_strlen(trim($this->closingNote)) < 3) {
            $this->dispatch('toast', message: __('Say what was done.'), type: 'error');

            return;
        }
        $item->update(['status' => \App\Models\CorrectiveAction::DONE, 'closing_note' => trim($this->closingNote), 'closed_at' => now(), 'closed_by' => auth()->id()]);
        $this->reset('closingId', 'closingNote');
        $this->dispatch('toast', message: __('Corrective action closed.'), type: 'success');
    }

    public function with(): array
    {
        return ['actions' => $this->tracksActions() ? \App\Models\CorrectiveAction::where('activity_id', $this->activityId)->orderByRaw("status = 'Done'")->orderBy('due_on')->orderBy('id')->get() : null,
            'reports' => $this->reports(), 'canReport' => $this->mayReport(), 'mayType' => ['month' => $this->mayReport('month'), 'quarter' => $this->mayReport('quarter')], 'canVerify' => $this->mayVerify(), 'year' => $this->activity?->fiscal_year ?? now()->year];
    }
}; ?>

<div>
    <x-ui.card :title="__('Narrative')" :description="__('The words that go with the numbers')" padding="p-5">
        <div x-data="{ open: {{ ($reports['month'] || $reports['quarter']) ? 'true' : 'false' }} }">
        <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" class="mb-4 flex w-full items-center justify-between rounded-lg border border-zinc-200 px-4 py-3 text-left text-sm hover:bg-zinc-50">
            <span class="font-medium text-zinc-900"><span x-show="! open">{{ __('Write the narrative') }}</span><span x-show="open" x-cloak>{{ __('Hide the narrative') }}</span></span>
            <span class="text-xs text-zinc-500">{{ __('Monthly report') }}: {{ $reports['month'] ? __($reports['month']->status) : __('Not written') }} · {{ __('Quarterly report') }}: {{ $reports['quarter'] ? __($reports['quarter']->status) : __('Not written') }}</span>
        </button>
        <div x-show="open" x-cloak class="space-y-8">
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
            @if($actions !== null)
                <section aria-labelledby="corr-actions" class="border-t border-zinc-200 pt-6">
                    <h3 id="corr-actions" class="text-sm font-semibold text-zinc-900">{{ __('Corrective actions') }}</h3>
                    <p class="mt-1 text-sm text-zinc-600">{{ __('For an activity that is off track or partly on track: what will be done, by whom and by when. Open actions stay here until they are closed.') }}</p>
                    @if($actions->isNotEmpty())
                        <ul class="mt-3 divide-y divide-zinc-100 rounded-lg border border-zinc-200 text-sm">
                            @foreach($actions as $ca)
                                <li class="flex flex-wrap items-start justify-between gap-3 px-4 py-3" wire:key="ca-{{ $ca->id }}">
                                    <div class="min-w-0">
                                        <p class="text-zinc-900">{{ $ca->description }}</p>
                                        <p class="text-xs text-zinc-600">Q{{ $ca->quarter }} · {{ $ca->owner_name }}@if($ca->due_on) · {{ __('due :date', ['date' => $ca->due_on->format('d/m/Y')]) }}@endif
                                            @if($ca->status === \App\Models\CorrectiveAction::DONE) · {{ __('Done') }}: {{ $ca->closing_note }}@elseif($ca->isOverdue()) · <span class="font-medium text-rose-700">{{ __('Overdue') }}</span>@endif</p>
                                    </div>
                                    @if($ca->status === \App\Models\CorrectiveAction::OPEN && ($canReport || $canVerify))
                                        @if($closingId === $ca->id)
                                            <div class="flex w-full gap-2 sm:w-auto">
                                                <input type="text" wire:model="closingNote" class="flux-input text-sm" placeholder="{{ __('What was done') }}" aria-label="{{ __('What was done') }}">
                                                <x-ui.button type="button" size="sm" variant="secondary" wire:click="closeAction">{{ __('Close') }}</x-ui.button>
                                            </div>
                                        @else
                                            <button type="button" wire:click="$set('closingId', {{ $ca->id }})" class="text-xs font-medium text-indigo-700 hover:underline">{{ __('Close') }}</button>
                                        @endif
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @if($canReport)
                        <div class="mt-3 grid gap-3 md:grid-cols-[1fr_12rem_10rem_auto] md:items-end">
                            <div>
                                <label for="ca-d" class="field-label">{{ __('Action') }}</label>
                                <input id="ca-d" type="text" wire:model="action.description" class="flux-input">
                                @error('action.description')<p class="field-error">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="ca-o" class="field-label">{{ __('Person responsible') }}</label>
                                <input id="ca-o" type="text" wire:model="action.owner_name" class="flux-input">
                                @error('action.owner_name')<p class="field-error">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="ca-u" class="field-label">{{ __('Due date') }}</label>
                                <input id="ca-u" type="date" wire:model="action.due_on" class="flux-input">
                            </div>
                            <x-ui.button type="button" variant="secondary" wire:click="addAction">{{ __('Add') }}</x-ui.button>
                        </div>
                    @endif
                </section>
            @endif
            @if($canReport)
                <div class="flex justify-end border-t border-zinc-200 pt-4">
                    <x-ui.button type="button" variant="secondary" wire:click="submit">{{ __('Submit the narrative') }}</x-ui.button>
                </div>
            @endif
        </div>
        </div>
    </x-ui.card>
</div>
