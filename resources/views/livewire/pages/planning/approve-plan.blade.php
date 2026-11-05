<?php

use App\Models\Directorate;
use App\Services\PlanningWindow;
use Livewire\Volt\Component;

/**
 * "Approve the plan": the head of a directorate approves every certified activity of it and finalizes its plan in one
 * act. Shown for each directorate the person decides for that has not finalized yet, with where its plan stands.
 */
new class extends Component {
    public int $year;

    /** @var array<int, string> directorate id => remark */
    public array $remark = [];

    public function approve(int $rootId): void
    {
        try {
            $result = app(PlanningWindow::class)->approvePlan(auth()->user(), $this->year, $rootId, $this->remark[$rootId] ?? null);
        } catch (\DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }
        unset($this->remark[$rootId]);
        if (! $result['finalized']) {
            $first = $result['skipped'][0];
            $this->dispatch('toast', type: 'error', message: trans_choice(':n activity approved; the plan is not finalized yet because :m could not be approved now (:reason).|:n activities approved; the plan is not finalized yet because :m could not be approved now (:reason).', $result['approved'], ['n' => $result['approved'], 'm' => count($result['skipped']), 'reason' => $first['reason']]));

            return;
        }
        $this->dispatch('toast', type: 'success', message: trans_choice('Plan approved: :n activity approved and the plan finalized.|Plan approved: :n activities approved and the plan finalized.', $result['approved'], ['n' => $result['approved']]));
    }

    public function with(): array
    {
        $window = app(PlanningWindow::class);
        $ids = $window->decidesFor(auth()->user(), $this->year)->all();
        $names = Directorate::whereIn('id', $ids)->get(['id', 'code', 'name'])->keyBy('id');
        $readiness = $window->planReadinessOf($this->year, $ids);
        $plans = collect($ids)
            ->map(fn ($id) => ['unit' => $names[$id] ?? null] + $readiness[(int) $id] + ['id' => (int) $id])
            ->filter(fn ($p) => $p['unit'] && ! $p['finalized'] && ($p['certified'] + $p['approved'] + $p['with_units'] + $p['in_certification']) > 0)
            ->sortBy([fn ($a, $b) => $b['ready'] <=> $a['ready'], fn ($a, $b) => strcmp($a['unit']->code, $b['unit']->code)])
            ->values();

        return ['plans' => $plans];
    }
}; ?>

<div id="plan">
    @if($plans->isNotEmpty())
        <x-ui.card :title="__('Approve the plan')" :description="__('Approving the plan gives the final approval to every certified activity of the directorate and finalizes its plan, in one step. Return or approve single activities first if you need to.')" padding="p-0">
            <ul class="divide-y divide-zinc-200">
                @foreach($plans as $p)
                    <li wire:key="plan-{{ $p['id'] }}" class="flex flex-col gap-3 px-5 py-4 lg:flex-row lg:items-center">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-zinc-900">{{ $p['unit']->code }} <span class="font-normal text-zinc-600">{{ $p['unit']->name }}</span></p>
                            <p class="mt-0.5 text-sm text-zinc-600">
                                @if($p['ready'])
                                    {{ $p['certified'] > 0
                                        ? trans_choice(':n certified activity is ready for your approval.|:n certified activities are ready for your approval.', $p['certified'], ['n' => $p['certified']])
                                        : __('Every activity is approved: the plan only needs to be finalized.') }}
                                    @if($p['approved'] > 0 && $p['certified'] > 0) {{ trans_choice(':n is already approved.|:n are already approved.', $p['approved'], ['n' => $p['approved']]) }} @endif
                                @else
                                    {{ __('Not ready yet:') }}
                                    @if($p['in_certification'] > 0) {{ trans_choice(':n activity is still being certified.|:n activities are still being certified.', $p['in_certification'], ['n' => $p['in_certification']]) }} @endif
                                    @if($p['with_units'] > 0) {{ trans_choice(':n activity is still with the units (draft or returned).|:n activities are still with the units (drafts or returned).', $p['with_units'], ['n' => $p['with_units']]) }} @endif
                                @endif
                            </p>
                        </div>
                        @if($p['ready'])
                            <div class="flex w-full flex-col gap-2 sm:flex-row lg:w-auto">
                                <input type="text" wire:model="remark.{{ $p['id'] }}" class="flux-input sm:w-72" placeholder="{{ __('Remark for the record (optional)') }}" aria-label="{{ __('Remark for the record (optional)') }}">
                                <x-ui.button size="md" wire:click="approve({{ $p['id'] }})" wire:loading.attr="disabled"
                                    wire:confirm="{{ $p['certified'] > 0
                                        ? trans_choice('Approve the plan of :unit? :n certified activity is approved and the plan is finalized: it takes no new activities afterwards. Approved activities can still be revised.|Approve the plan of :unit? :n certified activities are approved and the plan is finalized: it takes no new activities afterwards. Approved activities can still be revised.', $p['certified'], ['unit' => $p['unit']->code, 'n' => $p['certified']])
                                        : __('Finalize the plan of :unit? It takes no new activities afterwards. Approved activities can still be revised.', ['unit' => $p['unit']->code]) }}">
                                    {{ $p['certified'] > 0 ? __('Approve the plan') : __('Finalize the plan') }}
                                </x-ui.button>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif
</div>
