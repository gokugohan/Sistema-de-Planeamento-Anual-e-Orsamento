<?php

use App\Models\Activity;
use App\Policies\ActivityPolicy;
use App\Services\ActivityWorkflow;
use App\Support\ApprovalRules;
use Livewire\Volt\Component;

/**
 * Budget review (the resource plan): where an institution asks for it, a finance officer confirms the budget
 * lines of each submitted activity, with a short justification, before the activity can be certified. Or sends
 * it back to the unit with the reason. Changing the lines afterwards asks for a new confirmation.
 */
new class extends Component {
    public int $year;
    public array $justification = [];
    public array $reason = [];

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdministrator() || auth()->user()?->can('confirm_budget'), 403);
        $this->year = \App\Support\FiscalYear::current();
    }

    public function getActivitiesProperty()
    {
        $policy = app(ActivityPolicy::class);
        $user = auth()->user();

        return Activity::where('fiscal_year', $this->year)->where('status', Activity::STATUS_SUBMITTED)
            ->with(['directorate:id,code,name', 'lineItems.economicClassification:id,code,name', 'lineItems.fundingSource:id,name'])
            ->orderBy('directorate_id')->orderBy('id')->limit(200)->get()
            ->filter(fn (Activity $a) => $policy->inScope($user, $a->directorate_id))->values();
    }

    private function activity(int $id): Activity
    {
        return Activity::findOrFail($id);
    }

    public function confirm(int $id): void
    {
        $workflow = app(ActivityWorkflow::class);
        $activity = $this->activity($id);
        try {
            $workflow->confirmBudget(auth()->user(), $activity, $this->justification[$id] ?? null);
        } catch (\DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }
        unset($this->justification[$id]);
        $this->dispatch('toast', message: __('Budget confirmed.'), type: 'success');
    }

    public function sendBack(int $id): void
    {
        $workflow = app(ActivityWorkflow::class);
        $activity = $this->activity($id);
        abort_unless($workflow->canConfirmBudget(auth()->user(), $activity), 403);
        try {
            $workflow->sendBack(auth()->user(), $activity, (string) ($this->reason[$id] ?? ''));
        } catch (\DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }
        unset($this->reason[$id]);
        $this->dispatch('toast', message: __('Returned to the unit.'), type: 'success');
    }

    public function with(): array
    {
        $on = ApprovalRules::current()->budgetConfirmation;

        return ['on' => $on, 'rows' => $on ? $this->activities : collect()];
    }
}; ?>

<div class="space-y-6">
    <x-ui.page-header :title="__('Budget review')" :subtitle="__('Confirm the budget lines of each submitted activity before it is certified, or send it back to the unit with the reason. Changing the lines asks for a new confirmation.')">
        <span class="inline-flex items-center rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-800">{{ __('Fiscal year') }} {{ $year }}</span>
    </x-ui.page-header>

    @if(! $on)
        <x-ui.card padding="p-0"><x-ui.empty-state :title="__('Budget confirmation is not switched on')" :text="__('An administrator can ask for it in Settings: a finance officer then confirms the budget of an activity before it is certified.')" /></x-ui.card>
    @else
        @forelse($rows as $a)
            @php $total = $a->lineItems->sum('estimated_amount'); @endphp
            <x-ui.card padding="p-5" wire:key="br-{{ $a->id }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-xs font-medium text-zinc-500">{{ $a->directorate?->code }} · {{ $a->code }}</p>
                        <h2 class="text-sm font-semibold text-zinc-900">{{ $a->description }}</h2>
                    </div>
                    <p class="text-lg font-semibold tabular-nums text-zinc-900">$ {{ number_format($total, 2) }}</p>
                </div>

                <div class="mt-3 overflow-x-auto" tabindex="0">
                    <table class="w-full text-sm">
                        <caption class="sr-only">{{ __('Budget lines') }}</caption>
                        <thead><tr class="border-b border-zinc-100 text-xs font-semibold text-zinc-400">
                            <th scope="col" class="py-2 pr-4 text-left">{{ __('Category') }}</th>
                            <th scope="col" class="py-2 px-4 text-left">{{ __('Funding source') }}</th>
                            <th scope="col" class="py-2 pl-4 text-right">{{ __('Amount') }}</th>
                        </tr></thead>
                        <tbody class="divide-y divide-zinc-50">
                            @foreach($a->lineItems as $l)
                                <tr>
                                    <td class="py-2 pr-4 text-zinc-700">{{ $l->economicClassification?->code }} · {{ $l->economicClassification?->name }}</td>
                                    <td class="py-2 px-4 text-zinc-600">{{ $l->fundingSource ? __($l->fundingSource->name) : '—' }}</td>
                                    <td class="py-2 pl-4 text-right tabular-nums">{{ number_format($l->estimated_amount, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <div>
                        <label for="br-j-{{ $a->id }}" class="field-label">{{ __('Justification of the resource plan') }}</label>
                        <textarea id="br-j-{{ $a->id }}" rows="2" wire:model="justification.{{ $a->id }}" class="flux-input"></textarea>
                        <x-ui.button type="button" class="mt-2" wire:click="confirm({{ $a->id }})">{{ __('Confirm the budget') }}</x-ui.button>
                    </div>
                    <div>
                        <label for="br-r-{{ $a->id }}" class="field-label">{{ __('Why it goes back to the unit') }}</label>
                        <textarea id="br-r-{{ $a->id }}" rows="2" wire:model="reason.{{ $a->id }}" class="flux-input"></textarea>
                        <x-ui.button type="button" variant="secondary" shadow="none" class="mt-2" wire:click="sendBack({{ $a->id }})">{{ __('Return to the unit') }}</x-ui.button>
                    </div>
                </div>
            </x-ui.card>
        @empty
            <x-ui.card padding="p-0"><x-ui.empty-state :title="__('Nothing to review')" :text="__('Submitted activities waiting for the budget to be confirmed appear here.')" /></x-ui.card>
        @endforelse
    @endif
</div>
