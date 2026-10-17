<?php

use Livewire\Volt\Component;
use App\Models\Activity;
use App\Models\Program;
use App\Models\Directorate;
use Livewire\Attributes\Validate;

new class extends Component {
    #[Validate('required|exists:programs,id')]
    public $program_id;

    #[Validate('required|string|min:5')]
    public $description;

    #[Validate('required|in:Alta,Média,Baixa')]
    public $priority = 'Média';

    #[Validate('required|date')]
    public $planned_start;

    #[Validate('required|date|after_or_equal:planned_start')]
    public $planned_end;

    #[Validate('required|integer|min:2020|max:2035')]
    public $fiscal_year;

    public function mount(): void
    {
        if (!$this->fiscal_year) {
            $this->fiscal_year = date('Y') + 1;
        }

    }

    public function save(): void
    {
        $this->validate();

        $user = auth()->user();

        Activity::create([
            'program_id' => $this->program_id,
            'directorate_id' => $user->directorate_id ?? Directorate::first()->id,
            'description' => $this->description,
            'priority' => $this->priority,
            'planned_start' => $this->planned_start,
            'planned_end' => $this->planned_end,
            'status' => Activity::STATUS_DRAFT ?? 'Draft',
            'fiscal_year' => $this->fiscal_year,
            'is_test_data' => true,
        ]);

        $this->redirect(route('testing.activities'), navigate: true);
    }
}; ?>

<div>
    <div class="mb-6 flex items-center gap-3">
        <h3 class="text-xl font-semibold text-amber-900 border-b-2 border-amber-500 pb-2">
            {{ __('New Simulation Activity') }}</h3>
        <span
            class="bg-amber-100 text-amber-700 text-xs font-semibold px-2 py-0.5 rounded ">{{ __('Simulator') }}</span>
    </div>

    <form wire:submit="save" class="space-y-6">

        <div class="bg-amber-50 p-6 rounded-xl border border-amber-100">
            <x-input-label for="fiscal_year" :value="__('Target Simulation Year')" class="text-amber-900 font-bold" />
            <select wire:model.live="fiscal_year"
                class="mt-2 block w-full border-amber-200 focus:border-amber-500 focus:ring-amber-500 rounded-xl shadow-sm text-amber-700 font-semibold text-lg">
                @for($y = date('Y'); $y <= date('Y') + 10; $y++)
                    <option value="{{ $y }}">{{ $y }}</option>
                @endfor
            </select>
            <p class="text-xs text-amber-500 font-semibold mt-2 flex items-center gap-1">
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"
                    class="text-amber-400">
                    <circle cx="12" cy="12" r="10" />
                    <line x1="12" x2="12" y1="8" y2="12" />
                    <line x1="12" x2="12.01" y1="16" y2="16" />
                </svg>
                {{ __('Sandbox: Simulation logic is isolated from production.') }}
            </p>
        </div>

        <div class="space-y-4">
            <div>
                <x-input-label for="program_id" :value="__('OGE Program')" />
                <select id="program_id" wire:model="program_id"
                    class="mt-1 block w-full border-gray-300 focus:border-amber-500 focus:ring-amber-500 rounded-xl shadow-sm">
                    <option value="">{{ __('Select a program...') }}</option>
                    @foreach(App\Models\Program::all() as $program)
                        <option value="{{ $program->id }}">{{ $program->oge_code }} - {{ $program->name }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('program_id')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="description" :value="__('Activity Description')" />
                <textarea id="description" wire:model="description"
                    class="mt-1 block w-full border-gray-300 focus:border-amber-500 focus:ring-amber-500 rounded-xl shadow-sm"
                    rows="3" placeholder="Describe the simulated objective..."></textarea>
                <x-input-error :messages="$errors->get('description')" class="mt-2" />
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div>
                    <x-input-label for="priority" :value="__('Priority')" />
                    <select id="priority" wire:model="priority"
                        class="mt-1 block w-full border-gray-300 focus:border-amber-500 focus:ring-amber-500 rounded-xl shadow-sm">
                        <option value="Alta">{{ __('High') }}</option>
                        <option value="Média">{{ __('Medium') }}</option>
                        <option value="Baixa">{{ __('Low') }}</option>
                    </select>
                    <x-input-error :messages="$errors->get('priority')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="planned_start" :value="__('Planned Start')" />
                    <x-text-input id="planned_start" type="date" wire:model="planned_start"
                        class="mt-1 block w-full !rounded-xl" />
                    <x-input-error :messages="$errors->get('planned_start')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="planned_end" :value="__('Planned End')" />
                    <x-text-input id="planned_end" type="date" wire:model="planned_end"
                        class="mt-1 block w-full !rounded-xl" />
                    <x-input-error :messages="$errors->get('planned_end')" class="mt-2" />
                </div>
            </div>
        </div>

        <div class="flex items-center justify-end mt-8 pt-6 border-t border-zinc-100">
            <a href="{{ route('testing.activities') }}" wire:navigate
                class="mr-6 text-xs font-semibold text-zinc-400 hover:text-zinc-600 transition-colors">{{ __('Discard Simulation') }}</a>
            <x-ui.button variant="black"
                class="!bg-amber-600 hover:!bg-amber-700 !border-amber-600 !rounded-xl shadow-sm ">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                    class="mr-2">
                    <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z" />
                    <polyline points="17 21 17 13 7 13 7 21" />
                    <polyline points="7 3 7 8 15 8" />
                </svg>
                {{ __('Save Simulation') }}
            </x-ui.button>
        </div>
    </form>
</div>