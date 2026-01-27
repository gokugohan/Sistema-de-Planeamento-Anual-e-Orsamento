<?php

use Livewire\Volt\Component;
use App\Models\SystemSetting;
use App\Models\FiscalEnvelope;

new class extends Component {
    public $activeYear;
    public $orgFullName;
    public $orgShortName;
    public $organizationLogo;
    public $organizationFavicon;
    public $systemOwner;
    public $implementedBy;
    public $enableNextYearPlanning;
    public $showDraftInReports;
    public $enableTestMode;

    public function mount()
    {
        // Active year is now computed from fiscal envelopes
        $this->activeYear = FiscalEnvelope::getActiveFiscalYear() ?? date('Y');

        $this->orgFullName = SystemSetting::get('organization_name_full', 'Ministério do Orçamento');
        $this->orgShortName = SystemSetting::get('organization_name_short', 'MO');
        $this->organizationLogo = SystemSetting::get('organization_logo');
        $this->organizationFavicon = SystemSetting::get('organization_favicon');
        $this->systemOwner = SystemSetting::get('system_owner', '');
        $this->implementedBy = SystemSetting::get('implemented_by', '');

        $this->enableNextYearPlanning = SystemSetting::get('enable_next_year_planning', false);
        $this->showDraftInReports = SystemSetting::get('show_draft_planning_in_reports', false);
        $this->enableTestMode = SystemSetting::get('enable_paa_testing_mode', false);
    }

    public function saveSettings()
    {
        $this->validate([
            'orgFullName' => 'required|string|max:255',
            'orgShortName' => 'required|string|max:50',
            'systemOwner' => 'nullable|string|max:255',
            'implementedBy' => 'nullable|string|max:255',
            'enableNextYearPlanning' => 'boolean',
            'showDraftInReports' => 'boolean',
            'enableTestMode' => 'boolean',
        ]);

        // Note: activeYear is not saved here as it's computed from Fiscal Envelopes

        SystemSetting::set('organization_name_full', $this->orgFullName, 'string', 'Full name of the Ministry/Entity');
        SystemSetting::set('organization_name_short', $this->orgShortName, 'string', 'Short abbreviation of the organization');
        SystemSetting::set('organization_logo', $this->organizationLogo, 'string');
        SystemSetting::set('organization_favicon', $this->organizationFavicon, 'string');
        SystemSetting::set('system_owner', $this->systemOwner, 'string');
        SystemSetting::set('implemented_by', $this->implementedBy, 'string');

        SystemSetting::set('enable_next_year_planning', (bool) $this->enableNextYearPlanning, 'boolean', 'Allow users to create PAA activities for the next fiscal year');
        SystemSetting::set('show_draft_planning_in_reports', (bool) $this->showDraftInReports, 'boolean', 'Control visibility of draft data in reports');
        SystemSetting::set('enable_paa_testing_mode', (bool) $this->enableTestMode, 'boolean', 'Enable testing mode for PAA planning');

        session()->flash('success', __('System settings updated successfully.'));
    }
}; ?>

<div class="space-y-10 py-6 animate-in fade-in duration-700">
    <div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-6">
        <div>
            <h1 class="text-4xl font-black text-slate-900 tracking-tight leading-none">{{ __('General Setings') }}
            </h1>
            <p class="text-slate-400 font-bold uppercase tracking-[0.2em] text-[10px] mt-3">
                {{ __('Control Center for Planning Cycles & Entity Configurations') }}
            </p>
        </div>
        <div class="flex gap-3">
            <x-ui.button wire:click="saveSettings" variant="black" shadow="lg" className="text-white">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="mr-2">
                    <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z" />
                    <polyline points="17 21 17 13 7 13 7 21" />
                    <polyline points="7 3 7 8 15 8" />
                </svg>
                {{ __('Save Settings') }}
            </x-ui.button>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-10">
        <!-- Main Configuration Form -->
        <div class="lg:col-span-8 space-y-8">
            <x-ui.card padding="p-10">
                <x-slot name="header">
                    <h3 class="text-sm font-black text-slate-900 uppercase tracking-widest">
                        {{ __('Organization Identity') }}
                    </h3>
                </x-slot>

                <div class="space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                        <div class="space-y-2">
                            <x-input-label for="orgFullName" :value="__('Official Ministry Name (Legal)')"
                                class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1" />
                            <x-text-input id="orgFullName" wire:model="orgFullName"
                                class="w-full h-12 px-4 !rounded-2xl !bg-slate-50 !border-slate-100 !text-sm font-bold focus:!ring-4 focus:!ring-indigo-500/5 focus:!border-indigo-500 transition-all" />
                            <x-input-error :messages="$errors->get('orgFullName')" />
                        </div>

                        <div class="space-y-2">
                            <x-input-label for="orgShortName" :value="__('System Abbreviation (Identifier)')"
                                class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1" />
                            <x-text-input id="orgShortName" wire:model="orgShortName"
                                class="w-full h-12 px-4 !rounded-2xl !bg-slate-50 !border-slate-100 !text-sm font-bold focus:!ring-4 focus:!ring-indigo-500/5 focus:!border-indigo-500 transition-all" />
                            <x-input-error :messages="$errors->get('orgShortName')" />
                        </div>

                        <div class="space-y-2">
                            <x-input-label for="systemOwner" :value="__('System Owner')"
                                class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1" />
                            <x-text-input id="systemOwner" wire:model="systemOwner"
                                class="w-full h-12 px-4 !rounded-2xl !bg-slate-50 !border-slate-100 !text-sm font-bold focus:!ring-4 focus:!ring-indigo-500/5 focus:!border-indigo-500 transition-all" />
                            <x-input-error :messages="$errors->get('systemOwner')" />
                        </div>

                        <div class="space-y-2">
                            <x-input-label for="implementedBy" :value="__('Implemented By')"
                                class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1" />
                            <x-text-input id="implementedBy" wire:model="implementedBy"
                                class="w-full h-12 px-4 !rounded-2xl !bg-slate-50 !border-slate-100 !text-sm font-bold focus:!ring-4 focus:!ring-indigo-500/5 focus:!border-indigo-500 transition-all" />
                            <x-input-error :messages="$errors->get('implementedBy')" />
                        </div>
                    </div>

                    <!-- File Uploads (Placeholder for future implementation) -->
                    <div
                        class="p-6 bg-slate-50 rounded-[1.5rem] border border-slate-100 flex flex-col md:flex-row gap-6 items-center justify-between opacity-50">
                        <div>
                            <h4 class="text-xs font-black text-slate-900 uppercase tracking-widest mb-1">
                                {{ __('Branding Assets') }}
                            </h4>
                            <p class="text-[10px] text-slate-500 font-medium">
                                {{ __('Logo and Favicon upload functionality coming soon.') }}
                            </p>
                        </div>
                        <div class="flex gap-4">
                            <div
                                class="w-12 h-12 bg-white rounded-xl shadow-sm border border-slate-200 flex items-center justify-center">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                    stroke-linejoin="round" class="text-slate-300">
                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                                    <polyline points="17 8 12 3 7 8" />
                                    <line x1="12" x2="12" y1="3" y2="15" />
                                </svg>
                            </div>
                        </div>
                    </div>
                </div>
            </x-ui.card>

        </div>

        <!-- Sidebar Info -->
        <div class="lg:col-span-4 space-y-8">

            <x-ui.card padding="p-10">
                <x-slot name="header">
                    <h3 class="text-sm font-black text-slate-900 uppercase tracking-widest">
                        {{ __('Workflow & Reporting Constraints') }}
                    </h3>
                </x-slot>

                <div class="space-y-8">
                    <div
                        class="flex items-center justify-between p-6 bg-slate-50/50 rounded-[1.5rem] border border-slate-100">
                        <div class="max-w-md">
                            <h4 class="text-xs font-black text-slate-900 uppercase tracking-widest mb-1">
                                {{ __('Enable Next Year Planning') }}
                            </h4>
                            <p class="text-[10px] text-slate-500 font-medium leading-relaxed">
                                {{ __('Allow users to create PAA activities for the next fiscal year.') }}
                            </p>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" wire:model.live="enableNextYearPlanning" class="sr-only peer">
                            <div
                                class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-indigo-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600">
                            </div>
                        </label>
                    </div>

                    <div
                        class="flex items-center justify-between p-6 bg-slate-50/50 rounded-[1.5rem] border border-slate-100">
                        <div class="max-w-md">
                            <h4 class="text-xs font-black text-slate-900 uppercase tracking-widest mb-1">
                                {{ __('Report Draft Transparency') }}
                            </h4>
                            <p class="text-[10px] text-slate-500 font-medium leading-relaxed">
                                {{ __('Control visibility of draft data in consolidated reports and search dropdowns across the system.') }}
                            </p>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" wire:model.live="showDraftInReports" class="sr-only peer">
                            <div
                                class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-indigo-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600">
                            </div>
                        </label>
                    </div>

                    <div
                        class="flex items-center justify-between p-6 bg-amber-50 rounded-[1.5rem] border border-amber-100">
                        <div class="max-w-md">
                            <div class="flex items-center gap-2 mb-1">
                                <span
                                    class="bg-amber-100 text-amber-700 text-[10px] px-2 py-0.5 rounded-full font-bold">BETA</span>
                                <h4 class="text-xs font-black text-amber-900 uppercase tracking-widest">
                                    {{ __('Enable PAA Testing Mode') }}
                                </h4>
                            </div>
                            <p class="text-[10px] text-amber-700/80 font-medium leading-relaxed">
                                {{ __('Enable a separate testing environment for PAA planning (adds 🧪 Testing Mode menu). Test data is excluded from all production reports and calculations.') }}
                            </p>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" wire:model.live="enableTestMode" class="sr-only peer">
                            <div
                                class="w-11 h-6 bg-amber-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-amber-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-amber-600">
                            </div>
                        </label>
                    </div>
                </div>
            </x-ui.card>

        </div>
    </div>

    <x-ui.card padding="p-8">
        <h4 class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400 mb-6">
            {{ __('Infrastructure Nodes') }}
        </h4>
        <div class="space-y-4">
            <div class="flex items-center gap-4 p-4 bg-slate-50 rounded-2xl border border-slate-100">
                <div class="w-1.5 h-1.5 rounded-full bg-emerald-500"></div>
                <span class="text-[10px] font-black text-slate-900 uppercase tracking-widest">Main Production
                    Unit</span>
            </div>
            <div class="flex items-center gap-4 p-4 bg-slate-50 rounded-2xl border border-slate-100">
                <div class="w-1.5 h-1.5 rounded-full bg-emerald-500"></div>
                <span class="text-[10px] font-black text-slate-900 uppercase tracking-widest">Failover Mirror
                    Sec-2</span>
            </div>
        </div>
    </x-ui.card>

</div>