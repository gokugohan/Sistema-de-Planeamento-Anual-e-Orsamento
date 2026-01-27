<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-amber-800 leading-tight">
            {{ __('Add New Simulation Activity') }} 🧪
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-amber-50 rounded-xl p-4 border border-amber-200 mb-6 shadow-sm">
                <p class="text-xs text-amber-900 font-medium">
                    <span class="font-black">🧪 {{ __('SIMULATION MODE') }}</span>:
                    {{ __('Creating a TEST activity for simulation purposes. This entry stays strictly inside the sandbox.') }}
                </p>
            </div>

            <div class="bg-white overflow-hidden shadow-2xl sm:rounded-3xl border border-slate-100">
                <div class="p-8 text-gray-900">
                    <livewire:testing.simulation-create />
                </div>
            </div>
        </div>
    </div>
</x-app-layout>