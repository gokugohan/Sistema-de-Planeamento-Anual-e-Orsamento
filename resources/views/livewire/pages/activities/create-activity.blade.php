<?php

use Livewire\Volt\Component;
use App\Models\Activity;
use App\Models\Program;
use App\Models\Directorate;
use Livewire\Attributes\Validate;
use App\Models\FiscalEnvelope;

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
    public $fiscalYear;

    #[Validate('required|exists:directorates,id')]
    public $directorate_id;



    public $activeTab = 'details'; // 'form', 'program', 'details', 'directorate'

    public function mount(): void
    {
        if (!$this->fiscalYear) {
            $this->fiscalYear = date('Y') + 1;
        }

        $user = auth()->user();
        $this->directorate_id = $user->directorate_id;
    }


    public function with(): array
    {
        $user = auth()->user();

        $query = Activity::with(['program', 'directorate', 'indicators', 'lineItems.adjustedBudget'])
            ->where('fiscal_year', $this->fiscalYear);

        // Enforcement of Directorate Isolation
        if (!$user->isAdministrator() && !$user->isMinister() && !$user->can('view_all_paa')) {
            // Force filter to user's directorate
            $query->where('directorate_id', $user->directorate_id);
        } elseif ($this->directorate_id) {
            // Authorized users can filter by directorate_id (preview lists)
            $query->where('directorate_id', $this->directorate_id);
        }

        return [
            'activities' => $query->get(),
        ];
    }

    public function getProgramViewDataProperty()
    {
        $activities = $this->with()['activities'];

        return $activities->groupBy('program_id')->map(function ($programActivities) {
            if ($programActivities->isEmpty())
                return null;
            $program = $programActivities->first()->program;

            $totalEstimated = $programActivities->sum(function ($activity) {
                return $activity->lineItems->sum('estimated_amount');
            });

            $totalAllocated = $programActivities->sum(function ($activity) {
                return $activity->lineItems->sum(function ($item) {
                    return $item->adjustedBudget->approved_amount ?? 0;
                });
            });

            $totalIndicators = $programActivities->sum(function ($activity) {
                return $activity->indicators->count();
            });

            $totalRubricas = $programActivities->sum(function ($activity) {
                return $activity->lineItems->count();
            });

            return [
                'id' => $program->id,
                'name' => $program->name,
                'code' => $program->oge_code,
                'activities' => $programActivities,
                'program_allocation' => $totalAllocated,
                'total_estimated' => $totalEstimated,
                'total_allocated' => $totalAllocated,
                'total_indicators' => $totalIndicators,
                'total_rubricas' => $totalRubricas,
            ];
        })->filter();
    }

    public function getDirectorateViewDataProperty()
    {
        $activities = $this->with()['activities'];
        return $activities->groupBy('directorate_id')->map(function ($dirActivities) {
            if ($dirActivities->isEmpty())
                return null;
            $directorate = $dirActivities->first()->directorate;

            $totalEstimated = $dirActivities->sum(function ($activity) {
                return $activity->lineItems->sum('estimated_amount');
            });

            $totalAllocated = $dirActivities->sum(function ($activity) {
                return $activity->lineItems->sum(function ($item) {
                    return $item->adjustedBudget->approved_amount ?? 0;
                });
            });

            $totalIndicators = $dirActivities->sum(function ($activity) {
                return $activity->indicators->count();
            });

            $totalRubricas = $dirActivities->sum(function ($activity) {
                return $activity->lineItems->count();
            });

            return [
                'id' => $directorate->id ?? 0,
                'name' => $directorate->name ?? __('Unassigned'),
                'code' => $directorate->code ?? '—',
                'activities' => $dirActivities,
                'directorate_allocation' => $totalAllocated,
                'total_estimated' => $totalEstimated,
                'total_allocated' => $totalAllocated,
                'total_indicators' => $totalIndicators,
                'total_rubricas' => $totalRubricas,
            ];
        })->filter();
    }

    public function save(): void
    {
        $this->validate();

        Activity::create([
            'program_id' => $this->program_id,
            'directorate_id' => $this->directorate_id,
            'description' => $this->description,
            'priority' => $this->priority,
            'planned_start' => $this->planned_start,
            'planned_end' => $this->planned_end,
            'status' => Activity::STATUS_DRAFT ?? 'Draft',
            'fiscal_year' => $this->fiscalYear,
            'is_test_data' => false,
        ]);

        $this->redirect(route('activities.index'), navigate: true);
    }
}; ?>

<div class="space-y-10 py-6 animate-in fade-in duration-700">
    <div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-6">
        <div>
            <h1 class="page-title">
                {{ __('New Planned Activity') }}
            </h1>
            <p class="page-subtitle">
                {{ __('Strategic Activity Registration') }}
            </p>
        </div>
    </div>

    <div class="bg-indigo-50/50 backdrop-blur-sm p-8 rounded-xl border border-indigo-100 shadow-sm">
        <div class="flex items-center gap-4 mb-3">
            <div
                class="w-10 h-10 rounded-xl bg-indigo-600 flex items-center justify-center text-white font-semibold text-sm">
                FY</div>
            <div>
                <p class="text-xs font-semibold text-indigo-400 ">
                    {{ __('Fiscal Year of Planning') }}
                </p>
                <div class="text-3xl font-semibold text-indigo-700 leading-none mt-1">
                    {{ $this->fiscalYear }}
                </div>
            </div>
        </div>
        <p class="text-xs text-indigo-600 font-bold tracking-wide mt-4">
            {{ __('This PAA is being planned for the next fiscal cycle.') }}
        </p>
    </div>

    <div class="flex items-center gap-2 p-1.5 bg-zinc-100/50 w-fit rounded-xl border border-zinc-200 shadow-sm">
        <x-ui.button wire:click="$set('activeTab', 'form')"
            variant="{{ $activeTab === 'form' ? 'secondary' : 'ghost' }}" size="md"
            class="!rounded-xl !text-xs !font-semibold">
            {{ __('Form') }}
        </x-ui.button>
        <x-ui.button wire:click="$set('activeTab', 'program')"
            variant="{{ $activeTab === 'program' ? 'secondary' : 'ghost' }}" size="md"
            class="!rounded-xl !text-xs !font-semibold">
            {{ __('Programmatic View') }}
        </x-ui.button>
        <x-ui.button wire:click="$set('activeTab', 'directorate')"
            variant="{{ $activeTab === 'directorate' ? 'secondary' : 'ghost' }}" size="md"
            class="!rounded-xl !text-xs !font-semibold">
            {{ __('Directorate View') }}
        </x-ui.button>
        <x-ui.button wire:click="$set('activeTab', 'details')"
            variant="{{ $activeTab === 'details' ? 'secondary' : 'ghost' }}" size="md"
            class="!rounded-xl !text-xs !font-semibold">
            {{ __('Tabular Details') }}
        </x-ui.button>
    </div>

    @if($activeTab === 'form')
        <form wire:submit="save" class="space-y-6">
            <!-- ... form contents ... -->
            <input type="hidden" wire:model="fiscalYear">

            <div>
                <x-input-label for="directorate_id" :value="__('Executing Directorate')" />
                <select id="directorate_id" wire:model.live="directorate_id"
                    class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm @if(!auth()->user()->isAdministrator() && !auth()->user()->isMinister() && !auth()->user()->can('view_all_paa')) bg-gray-50 cursor-not-allowed @endif"
                    @if(!auth()->user()->isAdministrator() && !auth()->user()->isMinister() && !auth()->user()->can('view_all_paa')) disabled @endif>
                    <option value="">{{ __('Select a directorate...') }}</option>
                    @foreach(App\Models\Directorate::activeInYear($this->fiscalYear)->orderByRaw('parent_id IS NOT NULL')->orderBy('parent_id')->orderBy('name')->get() as $dir)
                        <option value="{{ $dir->id }}">{{ $dir->name }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('directorate_id')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="program_id" :value="__('OGE Program')" />
                <select id="program_id" wire:model="program_id"
                    class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
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
                    class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                    rows="3"></textarea>
                <x-input-error :messages="$errors->get('description')" class="mt-2" />
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <x-input-label for="priority" :value="__('Priority')" />
                    <select id="priority" wire:model="priority"
                        class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                        <option value="Alta">{{ __('High') }}</option>
                        <option value="Média">{{ __('Medium') }}</option>
                        <option value="Baixa">{{ __('Low') }}</option>
                    </select>
                    <x-input-error :messages="$errors->get('priority')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="planned_start" :value="__('Planned Start')" />
                    <x-text-input id="planned_start" type="date" wire:model="planned_start" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('planned_start')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="planned_end" :value="__('Planned End')" />
                    <x-text-input id="planned_end" type="date" wire:model="planned_end" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('planned_end')" class="mt-2" />
                </div>
            </div>

            <div class="flex items-center justify-end mt-4 pt-4 border-t">
                <a href="{{ route('activities.index') }}" wire:navigate
                    class="mr-4 text-sm text-gray-600 hover:text-gray-900">{{ __('Cancel') }}</a>
                <x-primary-button>
                    {{ __('Save Activity') }}
                </x-primary-button>
            </div>
        </form>
    @elseif($activeTab === 'program')
        @include('livewire.pages.activities.list-tabs.program-view')
    @elseif($activeTab === 'directorate')
        @include('livewire.pages.activities.list-tabs.directorate-view')
    @else
        @if($activities->isEmpty())
            <div class="text-center py-8 text-gray-500 italic">
                {{ __('No activities registered so far.') }}
            </div>
        @else
            @include('livewire.pages.activities.list-tabs.details-create-view')
        @endif
    @endif
</div>

</div>