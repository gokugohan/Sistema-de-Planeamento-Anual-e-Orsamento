<?php

use Livewire\Volt\Component;
use App\Models\Activity;
use App\Models\Indicator;
use Livewire\Attributes\Validate;
use App\Models\FiscalEnvelope;

new class extends Component {
    public Activity $activity;
    public $activeYear;

    #[Validate('required|string')]
    public $name;

    #[Validate('required|in:Output,Outcome')]
    public $type = 'Output';

    #[Validate('required|string')]
    public $unit;

    #[Validate('required|numeric|min:0')]
    public $annual_target;

    public function mount(Activity $activity): void
    {
        $this->activity = $activity->load('indicators');
        $this->activeYear = FiscalEnvelope::getEffectiveYear();

        // Check if year is locked
        $status = FiscalEnvelope::where('fiscal_year', $this->activity->fiscal_year)->value('status');
        if (in_array($status, ['Active', 'Closed'])) {
            $this->dispatch('toast', message: 'This fiscal year is finalized and cannot be modified.', type: 'error');
            // $this->redirect(route('activities.index'), navigate: true);

            $this->redirect(route('activities.show', $activity->id), navigate: true);

        }
    }

    public function addIndicator(): void
    {
        $this->validate();

        Indicator::create([
            'activity_id' => $this->activity->id,
            'name' => $this->name,
            'type' => $this->type,
            'unit' => $this->unit,
            'annual_target' => $this->annual_target,
        ]);

        $this->reset(['name', 'unit', 'annual_target']);
        $this->activity->load('indicators');
    }

    public function deleteIndicator(Indicator $indicator): void
    {
        $indicator->delete();
        $this->activity->load('indicators');
    }
}; ?>

<div class="space-y-10 py-6 animate-in fade-in duration-700">
    <div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-6">
        <div>
            <h1 class="page-title">
                {{ __('Performance Indicators') }}
            </h1>
            <p class="page-subtitle">
                {{ __('Activity') }}: {{ Str::limit($activity->description, 60) }}
            </p>
        </div>
        <div>
            @if($activity->fiscal_year > $activeYear)
                <x-ui.button variant="secondary" size="md" href="{{ route('activities.create') }}" wire:navigate>
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                        class="mr-2">
                        <path d="m12 19-7-7 7-7" />
                        <path d="M19 12H5" />
                    </svg>
                    {{ __('Back to Planning') }}
                </x-ui.button>
            @else
                <x-ui.button variant="secondary" size="md" href="{{ route('activities.show', $activity->id) }}"
                    wire:navigate>
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                        class="mr-2">
                        <path d="m12 19-7-7 7-7" />
                        <path d="M19 12H5" />
                    </svg>
                    {{ __('Back to Details') }}
                </x-ui.button>
            @endif
        </div>
    </div>

    <!-- Form to add new indicator -->
    <div class="bg-zinc-50/50 backdrop-blur-sm p-8 rounded-xl border border-zinc-100 shadow-sm">
        <h4 class="text-xs font-semibold text-zinc-900 mb-6">{{ __('New Indicator') }}</h4>
        <form wire:submit="addIndicator" class="grid grid-cols-1 md:grid-cols-4 gap-6 items-end">
            <div class="md:col-span-2">
                <label
                    class="text-xs font-semibold text-zinc-400 px-1 mb-2 block">{{ __('Indicator Name') }}</label>
                <input type="text" id="name" wire:model="name"
                    class="w-full bg-white border-transparent rounded-xl focus:ring-4 focus:ring-indigo-500/10 focus:bg-white focus:border-indigo-500 font-bold text-zinc-900 px-4 py-3 transition-all shadow-sm"
                    placeholder="Ex: Km de estrada reabilitada" />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>
            <div>
                <label
                    class="text-xs font-semibold text-zinc-400 px-1 mb-2 block">{{ __('Type') }}</label>
                <select id="type" wire:model="type"
                    class="w-full bg-white border-transparent rounded-xl focus:ring-4 focus:ring-indigo-500/10 focus:bg-white focus:border-indigo-500 font-bold text-zinc-900 px-4 py-3 transition-all shadow-sm">
                    <option value="Output">Output</option>
                    <option value="Outcome">Outcome</option>
                </select>
                <x-input-error :messages="$errors->get('type')" class="mt-2" />
            </div>
            <div>
                <label
                    class="text-xs font-semibold text-zinc-400 px-1 mb-2 block">{{ __('Unit') }}</label>
                <input type="text" id="unit" wire:model="unit"
                    class="w-full bg-white border-transparent rounded-xl focus:ring-4 focus:ring-indigo-500/10 focus:bg-white focus:border-indigo-500 font-bold text-zinc-900 px-4 py-3 transition-all shadow-sm"
                    placeholder="Ex: Km, Unid., %" />
                <x-input-error :messages="$errors->get('unit')" class="mt-2" />
            </div>
            <div>
                <label
                    class="text-xs font-semibold text-zinc-400 px-1 mb-2 block">{{ __('Annual Target') }}</label>
                <input type="number" id="annual_target" step="0.01" wire:model="annual_target"
                    class="w-full bg-white border-transparent rounded-xl focus:ring-4 focus:ring-indigo-500/10 focus:bg-white focus:border-indigo-500 font-bold text-zinc-900 px-4 py-3 transition-all shadow-sm" />
                <x-input-error :messages="$errors->get('annual_target')" class="mt-2" />
            </div>
            <div class="md:col-span-4 flex justify-end">
                <x-ui.button variant="black" size="md">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                        class="mr-2">
                        <path d="M5 12h14" />
                        <path d="M12 5v14" />
                    </svg>
                    {{ __('Add Indicator') }}
                </x-ui.button>
            </div>
        </form>
    </div>

    <!-- List of existing indicators -->
    <div class="bg-white rounded-xl shadow-sm border border-zinc-100 overflow-hidden">
        <div class="px-10 py-6 border-b border-zinc-50 bg-zinc-50/30">
            <h3 class="text-xs font-semibold text-zinc-900 ">{{ __('Registered Indicators') }}
            </h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-zinc-100">
                <thead class="bg-white">
                    <tr>
                        <th class="px-10 py-6 text-left text-xs font-semibold text-zinc-400">
                            {{ __('Indicator') }}
                        </th>
                        <th class="px-10 py-6 text-left text-xs font-semibold text-zinc-400">
                            {{ __('Type') }}
                        </th>
                        <th class="px-10 py-6 text-left text-xs font-semibold text-zinc-400">
                            {{ __('Unit') }}
                        </th>
                        <th
                            class="px-10 py-6 text-right text-xs font-semibold text-zinc-400">
                            {{ __('Annual Target') }}
                        </th>
                        <th
                            class="px-10 py-6 text-right text-xs font-semibold text-zinc-400">
                            {{ __('Actions') }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-50 bg-white">
                    @forelse($activity->indicators as $indicator)
                        <tr class="hover:bg-zinc-50/50 transition-all duration-300 group">
                            <td class="px-10 py-6 text-xs font-bold text-zinc-900">{{ $indicator->name }}</td>
                            <td class="px-10 py-6 text-xs text-zinc-500">{{ $indicator->type }}</td>
                            <td class="px-10 py-6 text-xs text-zinc-500">{{ $indicator->unit }}</td>
                            <td class="px-10 py-6 text-xs text-zinc-900 text-right font-semibold">
                                {{ number_format($indicator->annual_target, 2) }}
                            </td>
                            <td class="px-10 py-6 text-right text-xs font-medium">
                                <button wire:click="deleteIndicator({{ $indicator->id }})"
                                    wire:confirm="{{ __('Delete this indicator?') }}"
                                    class="text-rose-600 hover:text-rose-900 font-bold transition-colors">{{ __('Remove') }}</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-10 py-12 text-center text-sm text-zinc-400 italic">
                                {{ __('This activity does not have indicators defined yet.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>