<?php

use App\Models\Activity;
use App\Models\AdjustedBudget;
use App\Models\Directorate;
use App\Models\FiscalEnvelope;
use App\Models\LineItem;
use App\Models\User;
use App\Notifications\SystemNotification;
use Livewire\Volt\Component;

/**
 * A unit reviews what it was allocated against what its approved plan asked for, then accepts
 * it or asks for an adjustment. Administrators and ministers can look at any unit.
 */
new class extends Component {
    public $unitId = '';

    public function mount(): void
    {
        $this->unitId = (string) (auth()->user()->directorate_id ?? '');
    }

    private function year(): int
    {
        return FiscalEnvelope::getEffectiveYear();
    }

    private function canAct(): bool
    {
        $user = auth()->user();

        return $user->hasRole('Admin') || ($user->directorate_id && (int) $user->directorate_id === (int) $this->unitId);
    }

    private function unit(): ?Directorate
    {
        return $this->unitId === '' ? null : Directorate::find((int) $this->unitId);
    }

    private function items()
    {
        $unit = $this->unit();
        if (! $unit) {
            return collect();
        }

        return AdjustedBudget::whereHas('lineItem.activity', fn ($q) => $q
            ->whereIn('directorate_id', $unit->getSelfAndDescendantIds())
            ->where('fiscal_year', $this->year()))
            ->with(['lineItem.activity.program', 'lineItem.economicClassification'])
            ->get();
    }

    private function respond(string $status, string $title, string $message, string $toast): void
    {
        abort_unless($this->canAct(), 403);
        $unit = $this->unit();
        abort_unless($unit, 422);
        abort_if(FiscalEnvelope::where('fiscal_year', $this->year())->value('status') === FiscalEnvelope::STATUS_CLOSED, 422);

        $this->items()->each(fn ($item) => $item->update(['status' => $status]));

        User::role(['Admin', 'Planning'])->get()->each(fn ($u) => $u->notify(new SystemNotification(
            $title,
            __($message, ['dir' => $unit->name, 'year' => $this->year()]),
            route('planning.director-review', absolute: false)
        )));

        $this->dispatch('toast', message: $toast, type: 'success');
    }

    public function confirmAllocation(): void
    {
        $this->respond('Approved', __('Allocation Accepted'),
            'The Directorate of :dir has accepted their allocation for :year.', __('Budget allocation confirmed by Directorate.'));
    }

    public function requestAdjustment(): void
    {
        $this->respond('Partial', __('Adjustment Requested'),
            'The Directorate of :dir is requesting a budget adjustment for :year.', __('Adjustment request sent to Minister.'));
    }

    public function with(): array
    {
        $year = $this->year();
        $unit = $this->unit();
        $items = $this->items();

        $requested = $unit
            ? (float) LineItem::whereIn('activity_id', Activity::whereIn('directorate_id', $unit->getSelfAndDescendantIds())
                ->where('fiscal_year', $year)->where('status', Activity::STATUS_APPROVED)->select('id'))->sum('estimated_amount')
            : 0.0;
        $allocated = (float) $items->sum('approved_amount');

        $envelope = FiscalEnvelope::where('fiscal_year', $year)->first();

        return [
            'year' => $year,
            'unit' => $unit,
            'items' => $items,
            'requested' => $requested,
            'allocated' => $allocated,
            'variance' => $allocated - $requested,
            'status' => $items->pluck('status')->unique()->count() === 1 ? $items->first()->status : ($items->isEmpty() ? null : 'Mixed'),
            'closed' => $envelope && $envelope->status === FiscalEnvelope::STATUS_CLOSED,
            'canAct' => $this->canAct(),
            'units' => Directorate::whereNull('parent_id')->orderBy('code')->get(['id', 'code', 'name']),
            'pickUnit' => auth()->user()->hasAnyRole(['Admin', 'Minister']) || ! auth()->user()->directorate_id,
        ];
    }
}; ?>

<div class="space-y-6">
    <div class="flex flex-col lg:flex-row lg:items-end justify-between gap-4">
        <div>
            <h1 class="page-title">{{ __('Allocation Review') }}</h1>
            <p class="page-subtitle">{{ __('Compare what was allocated with what the approved plan asked for in :year.', ['year' => $year]) }}</p>
        </div>

        <div class="flex flex-wrap items-end gap-3">
            @if($pickUnit)
                <div>
                    <label for="unitId" class="block text-xs font-medium text-zinc-500 mb-1">{{ __('Unit') }}</label>
                    <select id="unitId" wire:model.live="unitId" class="flux-input !w-72 !py-2">
                        <option value="">{{ __('Select a unit') }}</option>
                        @foreach($units as $d)
                            <option value="{{ $d->id }}">{{ $d->code }} — {{ $d->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            @if($canAct && $items->isNotEmpty() && ! $closed)
                <x-ui.button variant="secondary" wire:click="requestAdjustment"
                    wire:confirm="{{ __('Ask for an adjustment of this allocation?') }}">{{ __('Request Adjustment') }}</x-ui.button>
                <x-ui.button variant="primary" wire:click="confirmAllocation"
                    wire:confirm="{{ __('Accept this allocation?') }}">{{ __('Confirm & Accept') }}</x-ui.button>
            @endif
        </div>
    </div>

    @if(! $unit)
        <x-ui.card>
            <p class="text-sm font-medium text-zinc-900">{{ __('No unit selected') }}</p>
            <p class="mt-1 text-sm text-zinc-500">
                {{ $pickUnit ? __('Choose a unit above to review its allocation.') : __('Your user account is not associated with a unit. Please contact the administrator.') }}
            </p>
        </x-ui.card>
    @else
        @if($closed)
            <div class="alert alert-success">{{ __('The planning cycle for :year is closed; allocations can no longer be changed.', ['year' => $year]) }}</div>
        @endif

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.kpi :label="__('Approved plan')" :value="'$ ' . number_format($requested)" :sub="$unit->code" tone="indigo" />
            <x-ui.kpi :label="__('Allocated')" :value="'$ ' . number_format($allocated)" :sub="__('From the fiscal envelope')" tone="emerald" />
            <x-ui.kpi :label="$variance < 0 ? __('Shortfall') : __('Additional funding')" :value="'$ ' . number_format(abs($variance))" :sub="$variance < 0 ? __('Allocated below the plan') : __('Allocated at or above the plan')" :tone="$variance < 0 ? 'rose' : 'zinc'" />
            <x-ui.kpi :label="__('Response')" :value="$status ? __($status) : '—'" :sub="__('Unit decision')" tone="amber" />
        </div>

        <x-ui.card padding="p-0">
            <x-ui.table :headers="[__('Activity'), __('Classification'), __('Requested'), __('Allocated'), __('Change')]">
                @forelse($items as $item)
                    @php
                        $asked = (float) $item->lineItem->estimated_amount;
                        $diff = (float) $item->approved_amount - $asked;
                        $pct = $asked > 0 ? ($item->approved_amount / $asked) * 100 : 0;
                    @endphp
                    <tr wire:key="item-{{ $item->id }}">
                        <td class="px-6 py-4 min-w-[22rem]">
                            <p class="text-sm font-medium text-zinc-900">{{ $item->lineItem->activity->description }}</p>
                            <p class="mt-1 text-xs text-zinc-500">{{ $item->lineItem->activity->program->oge_code ?? '—' }}</p>
                        </td>
                        <td class="px-6 py-4 text-sm text-zinc-600">{{ $item->lineItem->economicClassification->name ?? '—' }}</td>
                        <td class="px-6 py-4 text-sm tabular-nums text-right text-zinc-600">$ {{ number_format($asked) }}</td>
                        <td class="px-6 py-4 text-sm tabular-nums text-right font-semibold text-zinc-900">$ {{ number_format($item->approved_amount) }}</td>
                        <td class="px-6 py-4 text-right">
                            <x-ui.badge :variant="$diff < 0 ? 'red' : ($diff > 0 ? 'emerald' : 'gray')">{{ $diff > 0 ? '+' : '' }}{{ number_format($pct - 100, 0) }}%</x-ui.badge>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-6 py-14 text-center">
                            <p class="text-sm font-medium text-zinc-900">{{ __('Nothing allocated yet') }}</p>
                            <p class="mt-1 text-sm text-zinc-500">{{ __('Allocations appear here once the fiscal envelope has been distributed to this unit.') }}</p>
                        </td>
                    </tr>
                @endforelse
            </x-ui.table>
        </x-ui.card>
    @endif
</div>
