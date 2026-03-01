<?php

use Livewire\Volt\Component;
use App\Models\Program;
use Livewire\Attributes\Validate;

new class extends Component {
    public $programs;

    #[Validate('required|string|max:255')]
    public $name;

    #[Validate('required|string|unique:programs,oge_code')]
    public $oge_code;

    #[Validate('required|string')]
    public $objective;

    public function mount(): void
    {
        $this->programs = Program::all();
    }

    public function save(): void
    {
        $this->validate();

        Program::create([
            'name' => $this->name,
            'oge_code' => $this->oge_code,
            'objective' => $this->objective,
        ]);

        $this->reset(['name', 'oge_code', 'objective']);
        $this->programs = Program::all();
        $this->dispatch('toast', message: __('Budget program created successfully.'), type: 'success');
    }
}; ?>

<div class="space-y-6">
    <div class="bg-white p-6 rounded-lg shadow-sm border">
        <h3 class="text-lg font-bold mb-4">{{ __('New OGE Program') }}</h3>
        <form wire:submit="save" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div class="md:col-span-1">
                    <x-input-label for="oge_code" :value="__('OGE Code')" />
                    <x-text-input id="oge_code" wire:model="oge_code" class="mt-1 block w-full" placeholder="Ex: 001" />
                </div>
                <div class="md:col-span-3">
                    <x-input-label for="name" :value="__('Program Name')" />
                    <x-text-input id="name" wire:model="name" class="mt-1 block w-full" />
                </div>
            </div>
            <div>
                <x-input-label for="objective" :value="__('Strategic Objective')" />
                <textarea id="objective" wire:model="objective"
                    class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"></textarea>
            </div>
            <div class="flex justify-end">
                <x-primary-button>{{ __('Register Program') }}</x-primary-button>
            </div>
        </form>
    </div>

    <div class="bg-white shadow rounded-lg overflow-hidden border">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">{{ __('Code') }}
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">{{ __('Program') }}
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">{{ __('Objective') }}
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @foreach($programs as $prog)
                    <tr>
                        <td class="px-6 py-4 font-bold text-gray-900">{{ $prog->oge_code }}</td>
                        <td class="px-6 py-4 text-sm font-medium text-indigo-700">{{ $prog->name }}</td>
                        <td class="px-6 py-4 text-xs text-gray-500 max-w-xs">{{ $prog->objective }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>