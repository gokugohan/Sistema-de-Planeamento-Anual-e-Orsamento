<?php

use Livewire\Volt\Component;
use App\Models\EconomicClassification;
use Livewire\Attributes\Validate;

new class extends Component {
    public $classifications;

    #[Validate('required|string|unique:economic_classifications,code')]
    public $code;

    #[Validate('required|string')]
    public $name;

    public function mount(): void
    {
        $this->loadData();
    }

    public function loadData(): void
    {
        $this->classifications = EconomicClassification::orderBy('code')->get();
    }

    public function save(): void
    {
        $this->validate();

        EconomicClassification::create([
            'code' => $this->code,
            'name' => $this->name,
        ]);

        $this->reset(['code', 'name']);
        $this->loadData();
        session()->flash('message_ec', __('Economic Classification added successfully.'));
    }

    public function delete($id): void
    {
        EconomicClassification::find($id)->delete();
        $this->loadData();
    }
}; ?>

<div class="space-y-6">
    <div class="bg-white p-6 rounded-lg shadow-sm border">
        <h3 class="text-lg font-bold mb-4">{{ __('New Item / Economic Classification') }}</h3>
        <form wire:submit="save" class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
            <div>
                <x-input-label for="code_ec" :value="__('Code (Item)')" />
                <x-text-input id="code_ec" wire:model="code" class="mt-1 block w-full" placeholder="Ex: 210101" />
                <x-input-error :messages="$errors->get('code')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="name_ec" :value="__('Designation')" />
                <x-text-input id="name_ec" wire:model="name" class="mt-1 block w-full"
                    placeholder="Ex: Salários e Vencimentos" />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>
            <div>
                <x-primary-button>{{ __('Add') }}</x-primary-button>
            </div>
        </form>
    </div>

    @if (session()->has('message_ec'))
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative">
            {{ session('message_ec') }}
        </div>
    @endif

    <div class="bg-white shadow rounded-lg overflow-hidden border">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 ">{{ __('Code') }}
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 ">
                        {{ __('Designation') }}
                    </th>
                    <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 ">{{ __('Actions') }}
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @foreach($classifications as $item)
                    <tr>
                        <td class="px-6 py-4 font-mono text-sm text-indigo-700 font-bold">{{ $item->code }}</td>
                        <td class="px-6 py-4 text-sm text-gray-900">{{ $item->name }}</td>
                        <td class="px-6 py-4 text-right">
                            <button wire:click="delete({{ $item->id }})" wire:confirm="{{ __('Are you sure?') }}"
                                class="text-red-600 hover:text-red-900 text-sm">{{ __('Delete') }}</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>