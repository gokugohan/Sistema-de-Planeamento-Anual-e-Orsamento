<?php

use Livewire\Volt\Component;
use App\Models\Activity;
use App\Models\LineItem;
use App\Models\EconomicClassification;
use Livewire\Attributes\Validate;
use App\Models\FiscalEnvelope;

new class extends Component {
    public Activity $activity;
    public $activeYear;

    #[Validate('required|exists:economic_classifications,id')]
    public $economic_classification_id;

    #[Validate('required|numeric|min:0')]
    public $estimated_amount;

    public function mount(Activity $activity): void
    {
        $this->activity = $activity->load('lineItems.economicClassification');
        $this->activeYear = FiscalEnvelope::getEffectiveYear();

        // Check if year is locked
        $status = FiscalEnvelope::where('fiscal_year', $this->activity->fiscal_year)->value('status');
        if (in_array($status, ['Active', 'Closed'])) {
            $this->dispatch('toast', message: 'This fiscal year is finalized and cannot be modified.', type: 'error');
            // $this->redirect(route('activities.index'), navigate: true);
            $this->redirect(route('activities.show', $activity->id), navigate: true);
        }
    }

    public function addLineItem(): void
    {
        $this->validate();

        // Check if already exists for this activity and classification
        $existing = LineItem::where('activity_id', $this->activity->id)
            ->where('economic_classification_id', $this->economic_classification_id)
            ->first();

        if ($existing) {
            $existing->increment('estimated_amount', $this->estimated_amount);
        } else {
            LineItem::create([
                'activity_id' => $this->activity->id,
                'economic_classification_id' => $this->economic_classification_id,
                'estimated_amount' => $this->estimated_amount,
            ]);
        }

        $this->reset(['economic_classification_id', 'estimated_amount']);
        $this->activity->load('lineItems.economicClassification');
    }

    public function deleteLineItem(LineItem $item): void
    {
        $item->delete();
        $this->activity->load('lineItems.economicClassification');
    }

    public function getTotalProperty()
    {
        return $this->activity->lineItems->sum('estimated_amount');
    }
}; ?>

<div class="space-y-8">
    <div class="flex justify-between items-center border-b pb-4">
        <div>
            <h3 class="text-xl font-bold text-gray-900">{{ __('Activity Budgeting (Line Items)') }}</h3>
            <p class="text-sm text-gray-500 mt-1">{{ __('Activity') }}: {{ $activity->description }}</p>
        </div>
        @if($activity->fiscal_year > $activeYear)
            <a href="{{ route('activities.create') }}" wire:navigate
                class="text-indigo-600 hover:text-indigo-900 text-sm font-medium">← {{ __('Back to Planning') }}</a>
        @else
            <a href="{{ route('activities.show', $activity->id) }}" wire:navigate
                class="text-indigo-600 hover:text-indigo-900 text-sm font-medium">← {{ __('Back to Details') }}</a>
        @endif
    </div>

    <!-- Form for new line item -->
    <div class="bg-indigo-50 p-6 rounded-lg border border-indigo-100">
        <h4 class="text-md font-semibold mb-4 text-indigo-900">{{ __('Add Budget Item') }}</h4>
        <form wire:submit="addLineItem" class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
            <div class="md:col-span-1">
                <x-input-label for="economic_classification_id" :value="__('Economic Classification')" />
                <select id="economic_classification_id" wire:model="economic_classification_id"
                    class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                    <option value="">{{ __('Select...') }}</option>
                    @foreach(App\Models\EconomicClassification::all() as $ec)
                        <option value="{{ $ec->id }}">{{ $ec->code }} - {{ $ec->name }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('economic_classification_id')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="estimated_amount" :value="__('Estimated Value ($)')" />
                <x-text-input id="estimated_amount" type="number" step="0.01" wire:model="estimated_amount"
                    class="mt-1 block w-full" placeholder="0.00" />
                <x-input-error :messages="$errors->get('estimated_amount')" class="mt-2" />
            </div>
            <div class="flex justify-end">
                <x-primary-button class="bg-indigo-700 hover:bg-indigo-800">
                    {{ __('Add Item') }}
                </x-primary-button>
            </div>
        </form>
    </div>

    <!-- Budget Table -->
    <div class="bg-white shadow-sm rounded-lg border overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Item') }}</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">
                        {{ __('Partial Value') }}
                    </th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">{{ __('Actions') }}
                    </th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($activity->lineItems as $item)
                    <tr>
                        <td class="px-6 py-4 text-sm text-gray-900">
                            <span class="font-bold border-r pr-2 mr-2">{{ $item->economicClassification->code }}</span>
                            {{ $item->economicClassification->name }}
                        </td>
                        <td class="px-6 py-4 text-sm text-right font-mono text-gray-900">
                            $ {{ number_format($item->estimated_amount, 2) }}
                        </td>
                        <td class="px-6 py-4 text-right text-sm font-medium">
                            <button wire:click="deleteLineItem({{ $item->id }})"
                                wire:confirm="{{ __('Delete this item?') }}"
                                class="text-red-600 hover:text-red-900">{{ __('Remove') }}</button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="px-6 py-8 text-center text-sm text-gray-500 italic">
                            {{ __('No budget item (Line Item) defined.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
            <tfoot class="bg-indigo-50">
                <tr>
                    <td class="px-6 py-4 text-sm font-bold text-indigo-900 uppercase">{{ __('Total Estimated') }}</td>
                    <td class="px-6 py-4 text-sm text-right font-extrabold text-indigo-900 font-mono">
                        $ {{ number_format($this->total, 2) }}
                    </td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>