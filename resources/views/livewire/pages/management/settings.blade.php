<?php

use Livewire\Volt\Component;
use App\Models\SystemSetting;

new class extends Component {
    public $activeYear;
    public $organizationLogo;
    public $organizationFavicon;
    public $systemOwner;
    public string $institutionLevel = 'ministry';
    public string $reportRecipient = '';
    public bool $parentValidation = true;
    public bool $budgetConfirmation = false;
    public string $segregation = 'off';
    public $implementedBy;
    public $enableNextYearPlanning;
    public $showDraftInReports;
    public $enableTestMode;
    public array $appTexts = [];

    public function mount()
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);

        $this->activeYear = \App\Support\FiscalYear::current() ?? date('Y');

        $this->organizationLogo    = SystemSetting::get('organization_logo');
        $this->organizationFavicon = SystemSetting::get('organization_favicon');
        $this->systemOwner         = SystemSetting::get('system_owner', '');
        $this->institutionLevel    = \App\Support\Institution::level();
        $this->reportRecipient     = (string) SystemSetting::get('report_recipient', '');
        $rules                     = \App\Support\ApprovalRules::current();
        $this->parentValidation    = $rules->parentValidation;
        $this->budgetConfirmation  = $rules->budgetConfirmation;
        $this->segregation         = $rules->segregation;
        $this->implementedBy       = SystemSetting::get('implemented_by', '');

        $this->enableNextYearPlanning = SystemSetting::get('enable_next_year_planning', false);
        $this->showDraftInReports     = SystemSetting::get('show_draft_planning_in_reports', false);
        $this->enableTestMode         = SystemSetting::get('enable_paa_testing_mode', false);

        $empty = ['en' => '', 'pt' => '', 'tet' => ''];

        foreach (['org_name' => 'org_name', 'org_abbr' => 'org_abbr', 'name' => 'app_name', 'tagline' => 'app_tagline', 'description' => 'app_description'] as $field => $dbKey) {
            $saved = SystemSetting::get($dbKey);
            $this->appTexts[$field] = is_array($saved) ? $saved : $empty;
        }

        $savedItems = SystemSetting::get('app_items');
        foreach (['en', 'pt', 'tet'] as $locale) {
            $raw = (is_array($savedItems) && isset($savedItems[$locale])) ? $savedItems[$locale] : [];
            $this->appTexts['items'][$locale] = array_values(array_slice(array_merge($raw, ['', '', '']), 0, 3));
        }
    }

    /** Choosing a level starts from that level's preset; the two rules below it can then be adjusted. */
    public function updatedInstitutionLevel($level): void
    {
        $preset = \App\Support\ApprovalRules::preset($level);
        $this->parentValidation = $preset->parentValidation;
        $this->budgetConfirmation = $preset->budgetConfirmation;
        $this->segregation = $preset->segregation;
    }

    public function saveSettings()
    {
        abort_unless(auth()->user()->isAdministrator(), 403);
        $this->validate([
            'systemOwner'           => 'nullable|string|max:255',
            'institutionLevel'      => 'required|in:ministry,subordinate',
            'reportRecipient'       => 'nullable|string|max:255',
            'parentValidation'      => 'boolean',
            'budgetConfirmation'    => 'boolean',
            'segregation'           => 'in:'.implode(',', \App\Support\ApprovalRules::SEGREGATION_MODES),
            'implementedBy'         => 'nullable|string|max:255',
            'enableNextYearPlanning' => 'boolean',
            'showDraftInReports'    => 'boolean',
            'enableTestMode'        => 'boolean',
        ]);

        SystemSetting::set('organization_logo',    $this->organizationLogo,    'string');
        SystemSetting::set('organization_favicon', $this->organizationFavicon, 'string');
        SystemSetting::set('system_owner',         $this->systemOwner,         'string', 'Copyright / footer owner name');
        SystemSetting::set('institution_level',    $this->institutionLevel,    'string', 'ministry or subordinate (directorate / gabinete inside a ministry)');
        SystemSetting::set('report_recipient',     trim($this->reportRecipient), 'string', 'Who receives the approved plan when the institution is not a ministry');
        (new \App\Support\ApprovalRules((bool) $this->parentValidation, (bool) $this->budgetConfirmation, $this->segregation))->save($this->institutionLevel);
        SystemSetting::set('implemented_by',       $this->implementedBy,       'string', 'Implementing partner / vendor name');

        SystemSetting::set('enable_next_year_planning',      (bool) $this->enableNextYearPlanning, 'boolean', 'Allow users to create PAA activities for the next fiscal year');
        SystemSetting::set('show_draft_planning_in_reports', (bool) $this->showDraftInReports,     'boolean', 'Control visibility of draft data in reports');
        SystemSetting::set('enable_paa_testing_mode',        (bool) $this->enableTestMode,         'boolean', 'Enable testing mode for PAA planning');

        SystemSetting::set('org_name',        $this->appTexts['org_name'],     'json', 'Organization / institution name (localized)');
        SystemSetting::set('org_abbr',        $this->appTexts['org_abbr'],     'json', 'Organization abbreviation (localized)');
        SystemSetting::set('app_name',        $this->appTexts['name'],         'json', 'Application name (localized)');
        SystemSetting::set('app_tagline',     $this->appTexts['tagline'],      'json', 'Application tagline / subtitle (localized)');
        SystemSetting::set('app_description', $this->appTexts['description'],  'json', 'Application description (localized)');
        SystemSetting::set('app_items',       $this->appTexts['items'],        'json', 'Feature highlight items – array of 3 per locale');

        $this->dispatch('toast', message: __('System settings updated successfully.'), type: 'success');
    }
}; ?>

<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="page-title">{{ __('General Settings') }}
            </h1>
            <p class="page-subtitle">
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
        <!-- App Branding & Content (merged from Organisation Identity) -->
        <div class="lg:col-span-8 space-y-8">
            <x-ui.card padding="p-10">
                <x-slot name="header">
                    <div>
                        <h3 class="text-sm font-semibold text-zinc-900 ">{{ __('App Branding & Content') }}</h3>
                        <p class="text-xs text-zinc-400 font-medium mt-1">{{ __('Configure text shown on the Login page and Sidebar across Tetum, Portuguese and English') }}</p>
                    </div>
                </x-slot>

                <div x-data="{ tab: 'tet' }">
                    <!-- Language Tabs -->
                    <div class="flex gap-2 mb-8">
                        @foreach(['tet' => 'Tetum', 'pt' => 'Português', 'en' => 'English'] as $code => $label)
                            <button type="button" @click="tab = '{{ $code }}'"
                                :class="tab === '{{ $code }}' ? 'bg-indigo-600 text-white shadow-sm ring-1 ring-indigo-600' : 'bg-zinc-100 text-zinc-500 hover:bg-zinc-200 ring-1 ring-zinc-200'"
                                class="px-5 py-2 rounded-xl text-xs font-bold transition-all">
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>

                    @foreach(['tet' => 'Tetum', 'pt' => 'Português', 'en' => 'English'] as $locale => $localeLabel)
                    <div x-show="tab === '{{ $locale }}'" x-cloak>
                        <div class="space-y-7">

                            <!-- Organisation -->
                            <div>
                                <p class="text-xs font-semibold text-zinc-400 mb-4 flex items-center gap-2">
                                    <span class="w-5 h-px bg-zinc-200 block"></span>{{ __('Organisation') }}
                                </p>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                    <div class="space-y-2">
                                        <x-input-label :value="__('Institution Full Name')" class="field-label" />
                                        <x-text-input wire:model="appTexts.org_name.{{ $locale }}"
                                            class="w-full"
                                            placeholder="Institution Name" />
                                    </div>
                                    <div class="space-y-2">
                                        <x-input-label :value="__('Abbreviation')" class="field-label" />
                                        <x-text-input wire:model="appTexts.org_abbr.{{ $locale }}"
                                            class="w-full"
                                            placeholder="MOF" />
                                    </div>
                                </div>
                            </div>

                            <!-- Application Name -->
                            <div>
                                <p class="text-xs font-semibold text-zinc-400 mb-4 flex items-center gap-2">
                                    <span class="w-5 h-px bg-zinc-200 block"></span>{{ __('Application Name') }}
                                </p>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                    <div class="space-y-2">
                                        <x-input-label :value="__('Title')" class="field-label" />
                                        <x-text-input wire:model="appTexts.name.{{ $locale }}"
                                            class="w-full"
                                            placeholder="Strategic Budget" />
                                    </div>
                                    <div class="space-y-2">
                                        <x-input-label :value="__('Tagline / Subtitle')" class="field-label" />
                                        <x-text-input wire:model="appTexts.tagline.{{ $locale }}"
                                            class="w-full"
                                            placeholder="Planning & Control" />
                                    </div>
                                </div>
                            </div>

                            <!-- Description -->
                            <div>
                                <p class="text-xs font-semibold text-zinc-400 mb-4 flex items-center gap-2">
                                    <span class="w-5 h-px bg-zinc-200 block"></span>{{ __('Description') }}
                                </p>
                                <textarea wire:model="appTexts.description.{{ $locale }}" rows="3"
                                    class="w-full px-4 py-3 rounded-xl bg-zinc-50 border border-zinc-100 text-sm font-bold text-zinc-900 focus:ring-4 focus:ring-indigo-500/5 focus:border-indigo-500 transition-all resize-none outline-none"
                                    placeholder="Unified platform for annual activity planning..."></textarea>
                            </div>

                            <!-- Feature Items -->
                            <div>
                                <p class="text-xs font-semibold text-zinc-400 mb-4 flex items-center gap-2">
                                    <span class="w-5 h-px bg-zinc-200 block"></span>{{ __('Feature Items') }}
                                    <span class="text-zinc-500 font-medium normal-case tracking-normal">{{ __('(login page)') }}</span>
                                </p>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                                    @foreach([0, 1, 2] as $idx)
                                    <div class="space-y-2">
                                        <x-input-label :value="__('Item') . ' ' . ($idx + 1)" class="field-label" />
                                        <x-text-input wire:model="appTexts.items.{{ $locale }}.{{ $idx }}"
                                            class="w-full"
                                            placeholder="Feature {{ $idx + 1 }}" />
                                    </div>
                                    @endforeach
                                </div>
                            </div>

                        </div>
                    </div>
                    @endforeach

                    <!-- Institution level: decides who approves and who receives the plan -->
                    <div class="pt-8 mt-8 border-t border-zinc-100">
                        <p class="text-xs font-semibold text-zinc-400 mb-5 flex items-center gap-2">
                            <span class="w-5 h-px bg-zinc-200 block"></span>{{ __('Institution level') }}
                        </p>
                        <div class="space-y-4">
                            <label class="flex items-start gap-3 text-sm text-zinc-700">
                                <input type="radio" wire:model.live="institutionLevel" value="ministry" class="mt-1">
                                <span>
                                    <span class="font-medium text-zinc-900">{{ __('A ministry') }}</span>
                                    <span class="block text-xs text-zinc-500">{{ __('A whole ministry: several directorates, each finalizing its own plan.') }}</span>
                                </span>
                            </label>
                            <label class="flex items-start gap-3 text-sm text-zinc-700">
                                <input type="radio" wire:model.live="institutionLevel" value="subordinate" class="mt-1">
                                <span>
                                    <span class="font-medium text-zinc-900">{{ __('A directorate or gabinete inside a ministry') }}</span>
                                    <span class="block text-xs text-zinc-500">{{ __('One directorate or gabinete: its plan is sent to the ministry.') }}</span>
                                </span>
                            </label>
                            @if($institutionLevel === 'subordinate')
                                <div class="space-y-2 pl-7">
                                    <x-input-label for="reportRecipient" :value="__('Plan is sent to')" class="field-label" />
                                    <x-text-input id="reportRecipient" wire:model="reportRecipient"
                                        class="w-full"
                                        placeholder="{{ __('The ministry or the team there that prepares the budget') }}" />
                                    <x-input-error :messages="$errors->get('reportRecipient')" />
                                </div>
                            @endif
                        </div>
                    </div>

                    <!-- Approval chain: starts from the preset of the level, adjustable -->
                    <div class="pt-8 mt-8 border-t border-zinc-100">
                        <p class="text-xs font-semibold text-zinc-400 mb-5 flex items-center gap-2">
                            <span class="w-5 h-px bg-zinc-200 block"></span>{{ __('Approval chain') }}
                        </p>
                        <div class="space-y-4">
                            <p class="text-sm text-zinc-600">{{ __('The final approval is given by the head of the directorate (or the people named for it in the approval lists), for the plans of their own directorate.') }}</p>
                            <label class="flex items-center gap-3 text-sm text-zinc-700">
                                <input type="checkbox" wire:model="parentValidation" class="rounded border-zinc-300 text-indigo-600">
                                {{ __('The parent director validates sub-units first') }}
                            </label>
                            <label class="flex items-center gap-3 text-sm text-zinc-700">
                                <input type="checkbox" wire:model="budgetConfirmation" class="rounded border-zinc-300 text-indigo-600">
                                {{ __('A finance officer confirms the budget of an activity before it is certified') }}
                            </label>
                            <div class="space-y-1">
                                <label for="segregation" class="text-sm text-zinc-700">{{ __('May the person who prepared an activity also certify or approve it?') }}</label>
                                <select id="segregation" wire:model="segregation" class="flux-input">
                                    <option value="off">{{ __('Yes, without any mark') }}</option>
                                    <option value="flag">{{ __('Yes, but mark it in the record') }}</option>
                                    <option value="block">{{ __('No (an administrator may, and it is marked)') }}</option>
                                </select>
                            </div>
                            <p class="text-xs text-zinc-500">{{ __('Changing these rules applies from now on; activities already in the chain continue under the new rules.') }}</p>
                        </div>
                    </div>

                    <!-- Footer Credits (not localized) -->
                    <div class="pt-8 mt-8 border-t border-zinc-100">
                        <p class="text-xs font-semibold text-zinc-400 mb-5 flex items-center gap-2">
                            <span class="w-5 h-px bg-zinc-200 block"></span>{{ __('Footer Credits') }}
                            <span class="text-zinc-500 font-medium normal-case tracking-normal">{{ __('(shown in login page copyright)') }}</span>
                        </p>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <div class="space-y-2">
                                <x-input-label for="systemOwner" :value="__('System Owner')" class="field-label" />
                                <x-text-input id="systemOwner" wire:model="systemOwner"
                                    class="w-full"
                                    placeholder="Institution Name" />
                                <x-input-error :messages="$errors->get('systemOwner')" />
                            </div>
                            <div class="space-y-2">
                                <x-input-label for="implementedBy" :value="__('Implemented By')" class="field-label" />
                                <x-text-input id="implementedBy" wire:model="implementedBy"
                                    class="w-full"
                                    placeholder="Tech Partner" />
                                <x-input-error :messages="$errors->get('implementedBy')" />
                            </div>
                        </div>
                    </div>
                </div>
            </x-ui.card>
        </div>

        <!-- Workflow & Reporting -->
        <div class="lg:col-span-4 space-y-8">

            <x-ui.card padding="p-10">
                <x-slot name="header">
                    <h3 class="text-sm font-semibold text-zinc-900 ">
                        {{ __('Workflow & Reporting Constraints') }}
                    </h3>
                </x-slot>

                <div class="space-y-8">
                    <div
                        class="flex items-center justify-between p-6 bg-zinc-50/50 rounded-xl border border-zinc-100">
                        <div class="max-w-md">
                            <h4 class="text-xs font-semibold text-zinc-900 mb-1">
                                {{ __('Enable Next Year Planning') }}
                            </h4>
                            <p class="text-xs text-zinc-500 font-medium leading-relaxed">
                                {{ __('Allow users to create PAA activities for the next fiscal year.') }}
                            </p>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" wire:model.live="enableNextYearPlanning" class="sr-only peer" aria-label="{{ __('Allow planning for the next fiscal year') }}">
                            <div
                                class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-indigo-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600">
                            </div>
                        </label>
                    </div>

                    <div
                        class="flex items-center justify-between p-6 bg-zinc-50/50 rounded-xl border border-zinc-100">
                        <div class="max-w-md">
                            <h4 class="text-xs font-semibold text-zinc-900 mb-1">
                                {{ __('Report Draft Transparency') }}
                            </h4>
                            <p class="text-xs text-zinc-500 font-medium leading-relaxed">
                                {{ __('Control visibility of draft data in consolidated reports and search dropdowns across the system.') }}
                            </p>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" wire:model.live="showDraftInReports" class="sr-only peer" aria-label="{{ __('Show draft plans in reports') }}">
                            <div
                                class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-indigo-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600">
                            </div>
                        </label>
                    </div>

                    <div
                        class="flex items-center justify-between p-6 bg-amber-50 rounded-xl border border-amber-100">
                        <div class="max-w-md">
                            <div class="flex items-center gap-2 mb-1">
                                <span
                                    class="bg-amber-100 text-amber-700 text-xs px-2 py-0.5 rounded-full font-bold">BETA</span>
                                <h4 class="text-xs font-semibold text-amber-900 ">
                                    {{ __('Enable PAA Testing Mode') }}
                                </h4>
                            </div>
                            <p class="text-xs text-amber-800 font-medium leading-relaxed">
                                {{ __('Enable a separate testing environment for PAA planning (adds 🧪 Testing Mode menu). Test data is excluded from all production reports and calculations.') }}
                            </p>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" wire:model.live="enableTestMode" class="sr-only peer" aria-label="{{ __('Testing mode') }}">
                            <div
                                class="w-11 h-6 bg-amber-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-amber-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-amber-600">
                            </div>
                        </label>
                    </div>
                </div>
            </x-ui.card>

        </div>
    </div>

    
</div>