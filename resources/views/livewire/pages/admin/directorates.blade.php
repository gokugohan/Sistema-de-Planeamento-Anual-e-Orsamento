<?php

use Livewire\Volt\Component;
use App\Models\Directorate;
use Livewire\Attributes\Validate;

new class extends Component {
    public $directorates;

    #[Validate('required|string|unique:directorates,name')]
    public $name;

    #[Validate('required|string|unique:directorates,code|max:10')]
    public $code;

    public function mount(): void
    {
        $this->directorates = Directorate::all();
    }

    public function save(): void
    {
        $this->validate();

        Directorate::create([
            'name' => $this->name,
            'code' => $this->code,
        ]);

        $this->reset(['name', 'code']);
        $this->directorates = Directorate::all();
        session()->flash('message', __('Directorate-General created successfully.'));
    }

    public function toggle($id): void
    {
        $directorate = Directorate::find($id);
        $directorate->update(['active' => !$directorate->active]);
        $this->directorates = Directorate::all();
    }
}; ?>

<div class="space-y-6">
    <div class="bg-white p-6 rounded-lg shadow-sm border">
        <h3 class="text-lg font-bold mb-4">{{ __('New Directorate-General / Unit') }}</h3>
        <form wire:submit="save" class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
            <div>
                <x-input-label for="name" :value="__('Directorate Name')" />
                <x-text-input id="name" wire:model="name" class="mt-1 block w-full"
                    placeholder="Ex: Direção Geral de Finanças" />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="code" :value="__('Acronym/Code')" />
                <x-text-input id="code" wire:model="code" class="mt-1 block w-full" placeholder="Ex: DGAF" />
                <x-input-error :messages="$errors->get('code')" class="mt-2" />
            </div>
            <div>
                <x-primary-button>{{ __('Add Unit') }}</x-primary-button>
            </div>
        </form>
    </div>

    @if (session()->has('message'))
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative">
            {{ session('message') }}
        </div>
    @endif

    <div class="bg-white shadow rounded-lg overflow-hidden border">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">{{ __('Acronym') }}
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">
                        {{ __('Full Name') }}
                    </th>
                    <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 uppercase">{{ __('Status') }}
                    </th>
                    <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 uppercase">{{ __('Actions') }}
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @foreach($directorates as $dir)
                    <tr>
                        <td class="px-6 py-4 font-bold text-indigo-600">{{ $dir->code }}</td>
                        <td class="px-6 py-4 text-sm text-gray-900">{{ $dir->name }}</td>
                        <td class="px-6 py-4 text-center">
                            <span
                                class="px-2 py-1 text-xs rounded-full {{ $dir->active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                {{ $dir->active ? __('Active') : __('Inactive') }}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-right">
                            <button wire:click="toggle({{ $dir->id }})" class="text-sm text-gray-500 hover:text-indigo-600">
                                {{ $dir->active ? __('Deactivate') : __('Activate') }}
                            </button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>