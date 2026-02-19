<x-app-layout title="{{ __('Testing Zone') }}">
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-amber-800 leading-tight">
            {{ __('PAA Testing Environment') }} 🚧
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-amber-50 overflow-hidden shadow-sm sm:rounded-lg border border-amber-200">
                <div class="p-6 text-amber-900">
                    <h3 class="text-lg font-bold mb-4">{{ __('Welcome to the Testing Mode') }}</h3>
                    <p class="mb-4">
                        {{ __('This is a safe environment to test PAA planning features. Data created here is isolated from the production environment (marked as is_test_data=true).') }}
                    </p>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-6">
                        <div class="bg-white p-6 rounded-xl shadow-sm border border-amber-100">
                            <h4 class="font-bold text-gray-800 mb-2">{{ __('Test Activities') }}</h4>
                            <p class="text-sm text-gray-600 mb-4">
                                {{ __('Create and manage activities without affecting production reports.') }}
                            </p>
                            <!-- Future Link to Testing Activities -->
                            <!-- Simulation Creation Link -->
                            <div class="flex flex-col gap-2 mt-2">
                                <a href="{{ route('testing.activities.create') }}"
                                    class="inline-flex items-center justify-center px-4 py-2 bg-amber-600 text-white rounded-lg text-sm font-bold shadow-sm hover:bg-amber-700 transition-colors gap-2">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                                        stroke-linejoin="round">
                                        <path d="M12 5v14" />
                                        <path d="M5 12h14" />
                                    </svg>
                                    {{ __('Initiate New Simulation') }}
                                </a>
                                <a href="{{ route('testing.activities') }}"
                                    class="inline-flex items-center text-xs font-bold text-amber-700 hover:text-amber-900 justify-center">
                                    {{ __('View Existing Simulations →') }}
                                </a>
                            </div>
                        </div>

                        <div class="bg-white p-6 rounded-xl shadow-sm border border-amber-100">
                            <h4 class="font-bold text-gray-800 mb-2">{{ __('Test Budget') }}</h4>
                            <p class="text-sm text-gray-600 mb-4">
                                {{ __('Simulate budget allocations and expenditure tracking.') }}
                            </p>
                            <!-- Future Link to Testing Budget -->
                            <span
                                class="text-xs text-amber-600 font-bold bg-amber-50 px-2 py-1 rounded">{{ __('Coming Soon') }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>