<?php

use App\Models\Activity;
use App\Services\PlanningWindow;
use Livewire\Volt\Component;

/**
 * Where planning stands for a year: open, finalized by the final approver, or final because the budget
 * envelope has been received. The final approver (or an administrator) can finalize the plan and reopen
 * it with a remark; once the envelope is received the plan cannot be reopened. Everything is re-checked
 * on the server in PlanningWindow, so hiding a button is only a convenience.
 */
new class extends Component {
    public int $year;
    public string $remark = '';

    public function mount(int $year): void
    {
        $this->year = $year;
    }

    public function finalize(): void
    {
        $this->act(fn () => app(PlanningWindow::class)->finalize(auth()->user(), $this->year, $this->remark), __('The plan for :year is final: no new activities can be added.', ['year' => $this->year]));
    }

    public function reopen(): void
    {
        $this->act(fn () => app(PlanningWindow::class)->reopen(auth()->user(), $this->year, $this->remark), __('Planning for :year is open again.', ['year' => $this->year]));
    }

    private function act(callable $do, string $done): void
    {
        try {
            $do();
            $this->dispatch('toast', message: $done, type: 'success');
            $this->dispatch('planning-changed');
        } catch (\DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');
        }
        $this->remark = '';
    }

    public function with(): array
    {
        $window = app(PlanningWindow::class);
        $user = auth()->user();

        $state = $window->state($this->year);

        return [
            'state' => $state,
            'canDecide' => $window->canDecide($user),
            'canCreate' => $user->can('create', Activity::class),
            'nextYear' => $state['ended'] ? $window->nextOpenYear() : null,
        ];
    }
}; ?>

<div>
    @php $ended = $state['ended']; @endphp

    @if($ended)
        <div class="alert bg-indigo-50 text-indigo-900 ring-1 ring-indigo-200 !items-start justify-between gap-4">
            <div class="min-w-0">
                <p class="font-semibold">{{ __('Planning for :year has ended', ['year' => $year]) }}</p>
                <p class="mt-1 text-sm font-normal">
                    @if($ended === 'Closed')
                        {{ __('The planning cycle for :year is closed; activities can no longer be changed.', ['year' => $year]) }}
                    @elseif($ended === 'Approved')
                        {{ __('The budget for :year has been received, so its plan is final: only the distribution of the envelope can still change.', ['year' => $year]) }}
                    @else
                        {{ __('The plan for :year was finalized by :by on :date, so new activities cannot be added.', ['year' => $year, 'by' => $state['by'] ?? '—', 'date' => $state['at']?->format('d/m/Y') ?? '—']) }}
                        @if($state['remark'])<span class="block italic">“{{ $state['remark'] }}”</span>@endif
                        @unless($canDecide){{ __('Only the final approver or an administrator can reopen planning.') }}@endunless
                    @endif
                </p>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                @if($ended === 'Finalized' && $canDecide)
                    <x-ui.button variant="secondary" size="sm" shadow="none" x-on:click="$dispatch('open-modal', 'reopen-planning')">{{ __('Reopen planning') }}</x-ui.button>
                @endif
                @if($canCreate)
                    <a href="{{ route('fiscal-year.switch', $nextYear) }}" class="text-sm font-semibold underline">{{ __('Plan for :year', ['year' => $nextYear]) }}</a>
                @endif
            </div>
        </div>

        @if($ended === 'Finalized' && $canDecide)
            <x-note-dialog name="reopen-planning" property="remark" action="reopen" :required="true"
                :title="__('Reopen planning for :year?', ['year' => $year])"
                :message="__('New activities can be added again. This is recorded with your remark; once the budget envelope is received it can no longer be reopened.')"
                :label="__('Why is planning reopened?')" :confirm="__('Reopen planning')" />
        @endif
    @elseif($canDecide)
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-zinc-200 bg-white px-5 py-3 shadow-card">
            <div class="min-w-0 text-sm">
                <p class="font-semibold text-zinc-900">{{ __('Planning for :year is open', ['year' => $year]) }}</p>
                <p class="text-zinc-600">
                    @if($state['pending'] > 0)
                        {{ trans_choice(':n activity is still waiting for a decision. Decide on it before finalizing the plan.|:n activities are still waiting for a decision. Decide on them before finalizing the plan.', $state['pending'], ['n' => $state['pending']]) }}
                    @else
                        {{ __('When every activity has been decided, finalize the plan: no new activities can be added afterwards.') }}
                    @endif
                </p>
            </div>
            <x-ui.button variant="secondary" size="md" shadow="none" :disabled="$state['pending'] > 0" x-on:click="$dispatch('open-modal', 'finalize-plan')">{{ __('Finalize plan') }}</x-ui.button>
        </div>

        <x-note-dialog name="finalize-plan" property="remark" action="finalize"
            :title="__('Finalize the plan for :year?', ['year' => $year])"
            :message="__('This is the final approval of the plan. No new activities can be added afterwards; only the final approver or an administrator can reopen planning, with a remark.')"
            :label="__('Remark')" :confirm="__('Finalize plan')" />
    @endif
</div>
