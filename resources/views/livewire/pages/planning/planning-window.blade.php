<?php

use App\Models\Activity;
use App\Services\PlanningWindow;
use Livewire\Volt\Component;

/**
 * Where planning stands for a year, directorate by directorate: open, or finalized by its head. The head (or an
 * administrator) finalizes the plan of the directorate and can reopen it with a remark. Finalizing stops new
 * activities only; an approved activity can still be revised. Everything is re-checked
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

    public function declareNil(): void
    {
        $this->act(fn () => app(PlanningWindow::class)->finalize(auth()->user(), $this->year, $this->remark, $this->unit, true), __('Recorded: this directorate has nothing to plan for :year.', ['year' => $this->year]));
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
        // a user sees the directorates they work in; administrators and those who see every plan see them all
        $policy = app(\App\Policies\ActivityPolicy::class);
        $state['units'] = array_values(array_filter($state['units'], fn ($u) => $policy->inScope($user, $u['id'])));

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

    @if(count($state['units']) > 0)
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
                                @if($u['closed'] && ($u['nil_return'] ?? false))
                                    {{ __('Nothing to plan, declared by :by on :date', ['by' => $u['by'] ?? '—', 'date' => $u['at']?->format('d/m/Y') ?? '—']) }}
                                    @if($u['remark'])<span class="italic">“{{ $u['remark'] }}”</span>@endif
                                @elseif($u['closed'])
                                    {{ __('Finalized by :by on :date', ['by' => $u['by'] ?? '—', 'date' => $u['at']?->format('d/m/Y') ?? '—']) }}
                                    @if($u['remark'])<span class="italic">“{{ $u['remark'] }}”</span>@endif
                                @elseif($u['nothing_yet'] ?? false)
                                    {{ __('Expected to submit: no activity yet.') }}
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
                            <span class="flex flex-wrap gap-2">
                                @if($u['may_declare_nil'] ?? false)
                                    <x-ui.button variant="secondary" size="sm" shadow="none" wire:click="$set('unit', {{ $u['id'] }})" x-on:click="$dispatch('open-modal', 'declare-nil')">{{ __('Nothing to plan') }}</x-ui.button>
                                @endif
                                <x-ui.button variant="secondary" size="sm" shadow="none" :disabled="$u['pending'] > 0 || $u['approved'] === 0" wire:click="$set('unit', {{ $u['id'] }})" x-on:click="$dispatch('open-modal', 'finalize-plan')">{{ __('Finalize plan') }}</x-ui.button>
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </details>

        @if($deciding->isNotEmpty())
            <x-note-dialog name="reopen-planning" property="remark" action="reopen" :required="true"
                :title="__('Reopen planning for :year?', ['year' => $year])"
                :message="__('New activities can be added again. This is recorded with your remark.')"
                :label="__('Why is planning reopened?')" :confirm="__('Reopen planning')" />
            <x-note-dialog name="declare-nil" property="remark" action="declareNil" :required="true"
                :title="__('Declare that this directorate has nothing to plan for :year?', ['year' => $year])"
                :message="__('Its year closes as a nil return. Only the head of the directorate or an administrator can reopen it, with a remark.')"
                :label="__('Why is there nothing to plan?')" :confirm="__('Nothing to plan')" />
            <x-note-dialog name="finalize-plan" property="remark" action="finalize"
                :title="__('Finalize the plan for :year?', ['year' => $year])"
                :message="__('This is the final approval of the plan. No new activities can be added afterwards; only the head of the directorate or an administrator can reopen planning, with a remark.')"
                :label="__('Remark')" :confirm="__('Finalize plan')" />
        @endif
    @endif
</div>
