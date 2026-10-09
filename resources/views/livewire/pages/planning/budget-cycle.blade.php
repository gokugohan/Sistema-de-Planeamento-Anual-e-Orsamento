<?php

use App\Services\BudgetCycleService;
use Livewire\Volt\Component;

new class extends Component {
    public int $year;

    public $ceilingAmount = null;
    public string $ceilingReference = '';
    public string $ceilingDate = '';

    public $croTotal = null;
    public string $croDate = '';
    public string $croReference = '';
    public string $croNotes = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('view_budget'), 403);
        $this->year = (int) date('Y') + 1;
        $this->fillForms();
    }

    public function updatedYear(): void
    {
        $this->year = max(2020, min(2035, (int) $this->year));
        $this->resetValidation();
        $this->fillForms();
    }

    private function fillForms(): void
    {
        $c = app(BudgetCycleService::class)->summary($this->year)['cycle'];
        $this->ceilingAmount = $c->indicative_ceiling;
        $this->ceilingReference = (string) $c->ceiling_reference;
        $this->ceilingDate = $c->ceiling_date?->format('Y-m-d') ?? '';
        $this->croTotal = $c->cro_total;
        $this->croDate = $c->cro_decision_date?->format('Y-m-d') ?? '';
        $this->croReference = (string) $c->cro_reference;
        $this->croNotes = (string) $c->cro_notes;
    }

    private function canManage(): bool
    {
        return auth()->user()->hasAnyRole(['Admin', 'Minister']);
    }

    private function attempt(callable $fn, string $success): void
    {
        abort_unless($this->canManage(), 403);
        try {
            $fn(app(BudgetCycleService::class));
            $this->dispatch('toast', message: $success, type: 'success');
        } catch (\DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');
        }
        $this->fillForms();
    }

    public function saveCeiling(): void
    {
        $this->validate([
            'ceilingAmount' => 'required|numeric|min:0',
            'ceilingReference' => 'nullable|string|max:255',
            'ceilingDate' => 'nullable|date',
        ]);
        $this->attempt(fn($s) => $s->recordCeiling($this->year, (float) $this->ceilingAmount, $this->ceilingReference, $this->ceilingDate), __('Indicative ceiling saved.'));
    }

    public function recordSubmission(): void
    {
        $this->attempt(fn($s) => $s->recordSubmission($this->year), __('Submission to the CRO recorded and frozen.'));
    }

    public function saveCroDecision(): void
    {
        $this->validate([
            'croTotal' => 'required|numeric|min:0',
            'croDate' => 'required|date',
            'croReference' => 'nullable|string|max:255',
            'croNotes' => 'nullable|string|max:2000',
        ]);
        $this->attempt(fn($s) => $s->recordCroDecision($this->year, (float) $this->croTotal, $this->croDate, $this->croReference, $this->croNotes), __('CRO decision recorded.'));
    }

    public function with(): array
    {
        return [
            'summary' => app(BudgetCycleService::class)->summary($this->year),
            'canManage' => $this->canManage(),
        ];
    }
}; ?>

@php
    $c = $summary['cycle'];
    $s = $summary['stages'];
    $env = $summary['envelope'];
    $money = fn ($v) => $v === null ? '—' : '$ ' . number_format((float) $v, 2);
    $delta = function ($v, $base) use ($money) {
        if ($v === null) return '';
        $pct = $base ? ' (' . ($v >= 0 ? '+' : '') . number_format($v / $base * 100, 1) . '%)' : '';
        return ($v >= 0 ? '+' : '−') . ltrim($money(abs($v)), ' ') . $pct;
    };
    $steps = [
        ['label' => __('Indicative ceiling'), 'done' => $s['ceiling'] !== null],
        ['label' => __('Submitted to CRO'), 'done' => $c->isSubmitted()],
        ['label' => __('CRO decision'), 'done' => $c->hasCroDecision()],
        ['label' => __('Budget law promulgated'), 'done' => (bool) $env?->hasLegalBasis()],
        ['label' => __('Envelope approved'), 'done' => in_array($env?->status, [\App\Models\FiscalEnvelope::STATUS_APPROVED, \App\Models\FiscalEnvelope::STATUS_CLOSED], true)],
    ];
@endphp

<div class="space-y-6">
    <div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
        <div>
            <h1 class="page-title">{{ __('Budget Cycle') }}</h1>
            <p class="text-sm text-zinc-500 mt-1">{{ __('Indicative ceiling → submission to the CRO → CRO decision → State Budget law → fiscal envelope.') }}</p>
        </div>
        <div class="flex items-center gap-2">
            <label for="year" class="text-sm text-zinc-500">{{ __('Fiscal year') }}</label>
            <input id="year" type="number" min="2020" max="2035" wire:model.live.debounce.500ms="year" class="flux-input !w-28">
        </div>
    </div>

    {{-- Stepper --}}
    <x-ui.card padding="p-4">
        <ol class="grid grid-cols-1 sm:grid-cols-5 gap-3">
            @foreach($steps as $i => $step)
                <li class="flex items-center gap-3">
                    <span class="w-8 h-8 shrink-0 rounded-full flex items-center justify-center text-sm font-semibold {{ $step['done'] ? 'bg-emerald-600 text-white' : 'bg-zinc-100 text-zinc-500' }}">
                        @if($step['done'])
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                        @else
                            {{ $i + 1 }}
                        @endif
                    </span>
                    <span class="text-sm {{ $step['done'] ? 'text-zinc-900 font-medium' : 'text-zinc-500' }}">{{ $step['label'] }}</span>
                </li>
            @endforeach
        </ol>
    </x-ui.card>

    {{-- Fit check: the plan must be within the CRO amount before it goes to Parliament --}}
    @if($summary['fit_gap'] !== null)
        @if($summary['fit_gap'] > 0)
            <div class="alert alert-error !items-start">
                <div>
                    <p class="font-semibold">{{ __('The approved plan exceeds the CRO decision by :a.', ['a' => $money($summary['fit_gap'])]) }}</p>
                    <p class="text-xs font-normal mt-1">{{ __('Revise the activities (reduce or defer) so the plan fits the CRO amount before the budget goes to Parliament. Otherwise the envelope will be distributed pro rata and every activity is cut.') }}</p>
                </div>
            </div>
        @else
            <div class="alert alert-success">
                {{ $summary['fit_gap'] == 0 ? __('The approved plan matches the CRO decision exactly.') : __('The approved plan is within the CRO decision (:a below it).', ['a' => $money(abs($summary['fit_gap']))]) }}
            </div>
        @endif
    @endif

    {{-- Comparison --}}
    <x-ui.card padding="p-0">
        <x-ui.table :headers="[__('Stage'), __('Amount'), __('Difference')]">
            <tr><td class="px-6 py-3 text-sm">{{ __('Indicative ceiling') }}</td><td class="px-6 py-3 text-sm font-medium">{{ $money($s['ceiling']) }}</td><td class="px-6 py-3 text-sm text-zinc-400">—</td></tr>
            <tr><td class="px-6 py-3 text-sm">{{ __('Submitted to CRO') }}</td><td class="px-6 py-3 text-sm font-medium">{{ $money($s['submitted']) }}</td>
                <td class="px-6 py-3 text-sm {{ ($s['submitted_vs_ceiling'] ?? 0) > 0 ? 'text-amber-600' : 'text-zinc-500' }}">
                    @if($s['submitted_vs_ceiling'] !== null){{ $delta($s['submitted_vs_ceiling'], $s['ceiling']) }} {{ __('vs ceiling') }}@else — @endif
                </td></tr>
            <tr><td class="px-6 py-3 text-sm">{{ __('CRO decision') }}</td><td class="px-6 py-3 text-sm font-medium">{{ $money($s['cro']) }}</td>
                <td class="px-6 py-3 text-sm {{ ($s['cro_vs_submitted'] ?? 0) < 0 ? 'text-rose-600' : 'text-zinc-500' }}">
                    @if($s['cro_vs_submitted'] !== null){{ $delta($s['cro_vs_submitted'], $s['submitted']) }} {{ __('vs submitted') }}@else — @endif
                </td></tr>
            <tr><td class="px-6 py-3 text-sm">{{ __('Final envelope (OGE law)') }}</td><td class="px-6 py-3 text-sm font-medium">{{ $money($s['final']) }}</td>
                <td class="px-6 py-3 text-sm {{ ($s['final_vs_cro'] ?? 0) < 0 ? 'text-rose-600' : 'text-zinc-500' }}">
                    @if($s['final_vs_cro'] !== null){{ $delta($s['final_vs_cro'], $s['cro']) }} {{ __('vs CRO') }}@else — @endif
                </td></tr>
        </x-ui.table>
    </x-ui.card>

    <div class="grid gap-6 lg:grid-cols-2">
        {{-- 1. Ceiling --}}
        <x-ui.card>
            <h2 class="text-sm font-semibold text-zinc-900">1. {{ __('Indicative ceiling') }}</h2>
            <p class="text-xs text-zinc-500 mt-1 mb-4">{{ __('The maximum the Council of Ministers set for the ministry (teto indicativo / limite máximo de despesa).') }}</p>
            @if($canManage)
                <form wire:submit="saveCeiling" class="grid gap-3 sm:grid-cols-3">
                    <div><input type="number" step="0.01" wire:model="ceilingAmount" placeholder="{{ __('Amount') }}" class="flux-input">@error('ceilingAmount')<p class="text-xs text-rose-600 mt-1">{{ $message }}</p>@enderror</div>
                    <div><input type="text" wire:model="ceilingReference" placeholder="{{ __('Reference (e.g. Council of Ministers)') }}" class="flux-input"></div>
                    <div><input type="date" wire:model="ceilingDate" class="flux-input" aria-label="{{ __('Date') }}"></div>
                    <div class="sm:col-span-3"><x-ui.button type="submit" variant="secondary">{{ __('Save ceiling') }}</x-ui.button></div>
                </form>
            @else
                <p class="text-sm">{{ $money($s['ceiling']) }} @if($c->ceiling_reference)<span class="text-zinc-500">· {{ $c->ceiling_reference }}</span>@endif</p>
            @endif
        </x-ui.card>

        {{-- 2. Submission --}}
        <x-ui.card>
            <h2 class="text-sm font-semibold text-zinc-900">2. {{ __('Submission to the CRO') }}</h2>
            <p class="text-xs text-zinc-500 mt-1 mb-4">{{ __('Freezes the approved plan exactly as sent. Later edits to activities do not change it.') }}</p>

            @if($c->isSubmitted())
                <dl class="grid grid-cols-2 gap-2 text-sm">
                    <dt class="text-zinc-500">{{ __('Reference') }}</dt><dd class="font-mono text-xs">{{ $c->submission_reference }}</dd>
                    <dt class="text-zinc-500">{{ __('Total') }}</dt><dd>{{ $money($c->submitted_total) }} · {{ $c->submitted_activities }} {{ __('activities') }}</dd>
                    <dt class="text-zinc-500">{{ __('Recorded') }}</dt><dd>{{ $c->submitted_at?->timezone('Asia/Dili')->format('Y-m-d H:i') }} TLT · {{ $c->submitter?->name ?? '—' }}</dd>
                </dl>
                @if($summary['plan_changed_since_submission'])
                    <div class="alert alert-error mt-4 !items-start text-xs">
                        {{ __('The approved plan has changed since it was submitted (now :t). The CRO has the earlier version.', ['t' => $money($summary['approved_now'])]) }}
                    </div>
                @endif
            @else
                <p class="text-sm text-zinc-600">{{ __('Approved plan now') }}: <strong>{{ $money($summary['approved_now']) }}</strong></p>
            @endif

            <div class="mt-4 flex flex-wrap gap-3 items-center">
                @if($canManage && !$c->hasCroDecision())
                    <x-ui.button wire:click="recordSubmission" variant="primary"
                        wire:confirm="{{ $c->isSubmitted() ? __('Replace the recorded submission with the current approved plan?') : __('Record the current approved plan as submitted to the CRO?') }}">
                        {{ $c->isSubmitted() ? __('Replace submission') : __('Record submission') }}
                    </x-ui.button>
                @endif
                <a href="{{ route('reports.approved-plan', ['year' => $year]) }}" class="text-sm font-medium text-indigo-600 hover:underline">{{ __('Open approved plan document') }}</a>
            </div>
        </x-ui.card>

        {{-- 3. CRO decision --}}
        <x-ui.card>
            <h2 class="text-sm font-semibold text-zinc-900">3. {{ __('CRO decision') }}</h2>
            <p class="text-xs text-zinc-500 mt-1 mb-4">{{ __('The ministry total agreed by the Comité de Revisão.') }}</p>
            @if($canManage && $c->isSubmitted())
                <form wire:submit="saveCroDecision" class="grid gap-3 sm:grid-cols-2">
                    <div><input type="number" step="0.01" wire:model="croTotal" placeholder="{{ __('Ministry total') }}" class="flux-input">@error('croTotal')<p class="text-xs text-rose-600 mt-1">{{ $message }}</p>@enderror</div>
                    <div><input type="date" wire:model="croDate" class="flux-input" aria-label="{{ __('Decision date') }}">@error('croDate')<p class="text-xs text-rose-600 mt-1">{{ $message }}</p>@enderror</div>
                    <div class="sm:col-span-2"><input type="text" wire:model="croReference" placeholder="{{ __('Reference (minute / letter)') }}" class="flux-input"></div>
                    <div class="sm:col-span-2"><textarea wire:model="croNotes" rows="2" placeholder="{{ __('Notes') }}" class="flux-input"></textarea></div>
                    <div class="sm:col-span-2"><x-ui.button type="submit" variant="secondary">{{ __('Save CRO decision') }}</x-ui.button></div>
                </form>
            @elseif(!$c->isSubmitted())
                <p class="text-sm text-zinc-500">{{ __('Available after the submission is recorded.') }}</p>
            @else
                <p class="text-sm">{{ $money($c->cro_total) }} · {{ $c->cro_decision_date?->format('Y-m-d') }} @if($c->cro_reference)· {{ $c->cro_reference }}@endif</p>
                @if($c->cro_notes)<p class="text-xs text-zinc-500 mt-2">{{ $c->cro_notes }}</p>@endif
            @endif
        </x-ui.card>

        {{-- 4. Final --}}
        <x-ui.card>
            <h2 class="text-sm font-semibold text-zinc-900">4. {{ __('State Budget law and envelope') }}</h2>
            <p class="text-xs text-zinc-500 mt-1 mb-4">{{ __('After Parliament approves and the President promulgates, register the envelope and distribute it.') }}</p>
            @if($env)
                <dl class="grid grid-cols-2 gap-2 text-sm">
                    <dt class="text-zinc-500">{{ __('Envelope') }}</dt><dd>{{ $money($env->total_amount) }} · {{ __($env->status) }}</dd>
                    <dt class="text-zinc-500">{{ __('State Budget law') }}</dt><dd>{{ $env->budget_law_reference ?? '—' }}</dd>
                    <dt class="text-zinc-500">{{ __('Promulgation date') }}</dt><dd>{{ $env->promulgation_date?->format('Y-m-d') ?? '—' }}</dd>
                </dl>
            @else
                <p class="text-sm text-zinc-500">{{ __('No envelope registered for :year yet.', ['year' => $year]) }}</p>
            @endif
            <a href="{{ route('budget.envelope.current') }}" wire:navigate class="inline-block mt-4 text-sm font-medium text-indigo-600 hover:underline">{{ __('Go to fiscal envelope') }}</a>
        </x-ui.card>
    </div>

    {{-- By category: submitted vs final --}}
    @if($summary['submitted_by_class'] || $summary['final_by_class'])
        @php $codes = collect(array_keys($summary['submitted_by_class']))->merge(array_keys($summary['final_by_class']))->unique()->sort()->values(); @endphp
        <x-ui.card padding="p-0">
            <div class="px-6 pt-5 pb-2"><h2 class="text-sm font-semibold text-zinc-900">{{ __('By economic category: submitted vs final envelope') }}</h2></div>
            <x-ui.table :headers="[__('Category'), __('Submitted to CRO'), __('Final envelope'), __('Difference')]">
                @foreach($codes as $code)
                    @php $sub = $summary['submitted_by_class'][$code] ?? 0; $fin = $summary['final_by_class'][$code] ?? null; @endphp
                    <tr>
                        <td class="px-6 py-3 text-sm">{{ $code }}</td>
                        <td class="px-6 py-3 text-sm">{{ $money($sub) }}</td>
                        <td class="px-6 py-3 text-sm">{{ $money($fin) }}</td>
                        <td class="px-6 py-3 text-sm {{ $fin !== null && $fin < $sub ? 'text-rose-600' : 'text-zinc-500' }}">{{ $fin === null ? '—' : $delta($fin - $sub, $sub) }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif
</div>
