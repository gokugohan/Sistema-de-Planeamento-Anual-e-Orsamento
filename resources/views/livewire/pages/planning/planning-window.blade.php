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

    public ?int $unit = null;

    public function finalize(): void
    {
        $this->act(fn () => app(PlanningWindow::class)->finalize(auth()->user(), $this->year, $this->remark, $this->unit), __('The plan for :year is final: no new activities can be added.', ['year' => $this->year]));
    }

    public function reopen(): void
    {
        $this->act(fn () => app(PlanningWindow::class)->reopen(auth()->user(), $this->year, $this->remark, $this->unit), __('Planning for :year is open again.', ['year' => $this->year]));
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
        $this->unit = null;
    }

    public function with(): array
    {
        $window = app(PlanningWindow::class);
        $user = auth()->user();

        $state = $window->state($this->year);

        return [
            'state' => $state,
            'deciding' => collect($state['units'])->pluck('id')->filter(fn ($id) => $window->canDecide($user, $this->year, $id))->values(),
            'canCreate' => $user->can('create', Activity::class),
            'nextYear' => $state['ended'] ? $window->nextOpenYear() : null,
        ];
    }
}; ?>

<div>
    @php $ended = $state['ended']; @endphp

    @if($ended === 'Closed' || $ended === 'Approved')
        <div class="alert bg-indigo-50 text-indigo-900 ring-1 ring-indigo-200 !items-start justify-between gap-4">
            <div class="min-w-0">
                <p class="font-semibold">{{ __('Planning for :year has ended', ['year' => $year]) }}</p>
                <p class="mt-1 text-sm font-normal">
                    @if($ended === 'Closed')
                        {{ __('The planning cycle for :year is closed; activities can no longer be changed.', ['year' => $year]) }}
                    @else
                        {{ __('The budget for :year has been received, so its plan is final: only the distribution of the envelope can still change.', ['year' => $year]) }}
                    @endif
                </p>
            </div>
            @if($canCreate)
                <a href="{{ route('fiscal-year.switch', $nextYear) }}" class="shrink-0 text-sm font-semibold underline">{{ __('Plan for :year', ['year' => $nextYear]) }}</a>
            @endif
        </div>
    @elseif(count($state['units']) > 0)
        @php $doneN = count(array_filter($state['units'], fn ($u) => $u['closed'])); $totalN = count($state['units']); @endphp
        <details class="group rounded-lg border border-zinc-200 bg-white shadow-card" @if($totalN <= 3 || $deciding->isNotEmpty() && $deciding->count() <= 3) open @endif>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-5 py-3 text-sm">
                <span class="min-w-0">
                    <span class="block font-semibold text-zinc-900">{{ $ended ? __('Planning for :year has ended', ['year' => $year]) : __('Planning for :year is open', ['year' => $year]) }} · {{ __(':n of :total directorates finalized', ['n' => $doneN, 'total' => $totalN]) }}</span>
                    <span class="block text-zinc-600">{{ __('Each directorate finalizes its own plan once everything in it has been decided; no new activities can be added to it afterwards.') }}</span>
                </span>
                <span class="shrink-0 text-xs font-medium text-indigo-700 group-open:hidden">{{ __('Show directorates') }}</span>
                <span class="hidden shrink-0 text-xs font-medium text-indigo-700 group-open:inline">{{ __('Hide') }}</span>
            </summary>
            <ul class="divide-y divide-zinc-100">
                @foreach($state['units'] as $u)
                    @php $mine = $deciding->contains($u['id']); @endphp
                    <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm">
                        <div class="min-w-0">
                            <p class="font-medium text-zinc-900">{{ $u['code'] }} · {{ $u['name'] }}</p>
                            <p class="text-zinc-600">
                                @if($u['closed'])
                                    {{ __('Finalized by :by on :date', ['by' => $u['by'] ?? '—', 'date' => $u['at']?->format('d/m/Y') ?? '—']) }}
                                    @if($u['remark'])<span class="italic">“{{ $u['remark'] }}”</span>@endif
                                @elseif($u['pending'] > 0)
                                    {{ trans_choice(':n activity is still waiting for a decision. Decide on it before finalizing the plan.|:n activities are still waiting for a decision. Decide on them before finalizing the plan.', $u['pending'], ['n' => $u['pending']]) }}
                                @else
                                    {{ __('When every activity has been decided, finalize the plan: no new activities can be added afterwards.') }}
                                @endif
                            </p>
                        </div>
                        @if($mine && $u['closed'])
                            <x-ui.button variant="secondary" size="sm" shadow="none" wire:click="$set('unit', {{ $u['id'] }})" x-on:click="$dispatch('open-modal', 'reopen-planning')">{{ __('Reopen planning') }}</x-ui.button>
                        @elseif($mine)
                            <x-ui.button variant="secondary" size="sm" shadow="none" :disabled="$u['pending'] > 0 || $u['approved'] === 0" wire:click="$set('unit', {{ $u['id'] }})" x-on:click="$dispatch('open-modal', 'finalize-plan')">{{ __('Finalize plan') }}</x-ui.button>
                        @endif
                    </li>
                @endforeach
            </ul>
        </details>

        @if($deciding->isNotEmpty())
            <x-note-dialog name="reopen-planning" property="remark" action="reopen" :required="true"
                :title="__('Reopen planning for :year?', ['year' => $year])"
                :message="__('New activities can be added again. This is recorded with your remark; once the budget envelope is received it can no longer be reopened.')"
                :label="__('Why is planning reopened?')" :confirm="__('Reopen planning')" />
            <x-note-dialog name="finalize-plan" property="remark" action="finalize"
                :title="__('Finalize the plan for :year?', ['year' => $year])"
                :message="__('This is the final approval of the plan. No new activities can be added afterwards; only the head of the directorate or an administrator can reopen planning, with a remark.')"
                :label="__('Remark')" :confirm="__('Finalize plan')" />
        @endif
    @endif
</div>
