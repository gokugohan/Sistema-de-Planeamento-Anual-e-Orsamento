<?php

use Livewire\Volt\Component;
use App\Models\Directorate;
use App\Models\Program;
use App\Models\EconomicClassification;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public $search = '';
    public $activeTab = 'directorates';

    // Directorate Form State
    public $directorateId = null;
    public $name = '';
    public $code = '';
    public $parent_id = null;
    public $type = 'General Directorate';
    public $description = '';
    public $director_name = '';
    public $director_email = '';
    public $active = true;

    public $modalOpen = false;
    public $isEditing = false;

    // Program Form State
    public $programId = null;
    public $programName = '';
    public $programCode = '';
    public $ogeCode = '';
    public $objective = '';
    public $managerName = '';
    public $kpis = '';
    public $programModalOpen = false;
    public $isProgramEditing = false;

    // Economic Classification Form State
    public $classificationId = null;
    public $classificationCode = '';
    public $classificationName = '';
    public $classificationDescription = '';
    public $classificationType = 'Expense';
    public $classificationModalOpen = false;
    public $isClassificationEditing = false;


    public function with(): array
    {
        $allDirectorates = Directorate::orderBy('name')->get();

        // Filtering logical parents based on classification
        $parentMapping = [
            'National Directorate' => ['General Directorate'],
            'Department' => ['National Directorate', 'General Directorate'],
            'Unit' => ['Department', 'National Directorate'],
            'Regional Office' => ['General Directorate'],
            'General Directorate' => [], // Top-level
            'Cabinet' => [], // Top-level
        ];


        $allowedParentTypes = $parentMapping[$this->type] ?? [];
        $filteredParents = $allDirectorates->filter(function ($d) use ($allowedParentTypes) {
            return in_array($d->type, $allowedParentTypes);
        });

        return [
            'directorates' => Directorate::with('parent')
                ->when($this->search, function ($q) {
                    $q->where('name', 'like', "%{$this->search}%")
                        ->orWhere('code', 'like', "%{$this->search}%");
                })
                ->orderBy('name')
                ->paginate(10),
            'programs' => Program::when($this->search, function ($q) {
                $q->where('name', 'like', "%{$this->search}%")
                    ->orWhere('code', 'like', "%{$this->search}%");
            })
                ->orderBy('name')
                ->paginate(10),
            'economicCategories' => EconomicClassification::when($this->search, function ($q) {
                $q->where('name', 'like', "%{$this->search}%")
                    ->orWhere('code', 'like', "%{$this->search}%");
            })
                ->orderBy('code')
                ->paginate(10),
            'allDirectorates' => $allDirectorates,
            'filteredParents' => $filteredParents
        ];
    }

    public function switchTab($tab)
    {
        $this->activeTab = $tab;
        $this->search = '';
        $this->resetPage();
    }

    public function openModal($id = null)
    {
        $this->resetValidation();
        if ($id) {
            $this->isEditing = true;
            $this->directorateId = $id;
            $d = Directorate::findOrFail($id);
            $this->name = $d->name;
            $this->code = $d->code;
            $this->parent_id = $d->parent_id;
            $this->type = $d->type;
            $this->description = $d->description;
            $this->director_name = $d->director_name;
            $this->director_email = $d->director_email;
            $this->active = $d->active;
        } else {
            $this->isEditing = false;
            $this->directorateId = null;
            $this->reset(['name', 'code', 'parent_id', 'type', 'description', 'director_name', 'director_email', 'active']);
        }
        $this->modalOpen = true;
    }

    public function saveDirectorate()
    {
        $rules = [
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:directorates,code,' . $this->directorateId,
            'type' => 'required|string',
            'parent_id' => 'nullable|exists:directorates,id',
            'director_name' => 'nullable|string|max:255',
            'director_email' => 'nullable|email|max:255',
        ];

        $this->validate($rules);

        $data = [
            'name' => $this->name,
            'code' => $this->code,
            'parent_id' => $this->parent_id ?: null,
            'type' => $this->type,
            'description' => $this->description,
            'director_name' => $this->director_name,
            'director_email' => $this->director_email,
            'active' => $this->active,
        ];

        if ($this->isEditing) {
            // Prevent self-parenting
            if ($this->parent_id == $this->directorateId) {
                $this->addError('parent_id', __('A unit cannot be its own parent.'));
                return;
            }

            Directorate::findOrFail($this->directorateId)->update($data);
            $this->dispatch('toast', message: __('Directorate updated successfully!'), type: 'success');
        } else {
            Directorate::create($data);
            $this->dispatch('toast', message: __('Directorate created successfully!'), type: 'success');
        }

        $this->modalOpen = false;
    }

    public function deleteDirectorate($id)
    {
        $d = Directorate::findOrFail($id);

        // Prevent deletion if it has children
        if ($d->children()->count() > 0) {
            $this->dispatch('toast', message: __('Cannot delete directorate with sub-units. Delete children first.'), type: 'error');
            return;
        }

        // Prevent deletion if it has activities
        if ($d->activities()->count() > 0) {
            $this->dispatch('toast', message: __('Cannot delete directorate with active activities.'), type: 'error');
            return;
        }

        $d->delete();
        $this->dispatch('toast', message: __('Directorate deleted successfully.'), type: 'success');
    }

    public function updatingSearch()
    {
        $this->resetPage();
    }

    // ========== PROGRAM CRUD METHODS ==========

    public function openProgramModal($id = null)
    {
        $this->resetValidation();
        if ($id) {
            $this->isProgramEditing = true;
            $this->programId = $id;
            $p = Program::findOrFail($id);
            $this->programName = $p->name;
            $this->programCode = $p->code;
            $this->ogeCode = $p->oge_code ?? '';
            $this->objective = $p->objective ?? '';
            $this->managerName = $p->manager_name ?? '';
            $this->kpis = $p->kpis ?? '';
        } else {
            $this->isProgramEditing = false;
            $this->programId = null;
            $this->reset(['programName', 'programCode', 'ogeCode', 'objective', 'managerName', 'kpis']);
        }
        $this->programModalOpen = true;
    }

    public function saveProgram()
    {
        $rules = [
            'programName' => 'required|string|max:255',
            'programCode' => 'required|string|max:50|unique:programs,code,' . $this->programId,
            'ogeCode' => 'nullable|string|max:50',
            'objective' => 'nullable|string',
            'managerName' => 'nullable|string|max:255',
            'kpis' => 'nullable|string',
        ];

        $this->validate($rules);

        $data = [
            'name' => $this->programName,
            'code' => $this->programCode,
            'oge_code' => $this->ogeCode,
            'objective' => $this->objective,
            'manager_name' => $this->managerName,
            'kpis' => $this->kpis,
        ];

        if ($this->isProgramEditing) {
            Program::findOrFail($this->programId)->update($data);
            $this->dispatch('toast', message: __('Program updated successfully!'), type: 'success');
        } else {
            Program::create($data);
            $this->dispatch('toast', message: __('Program created successfully!'), type: 'success');
        }

        $this->programModalOpen = false;
    }

    public function deleteProgram($id)
    {
        $p = Program::findOrFail($id);

        // Prevent deletion if it has activities
        if ($p->activities()->count() > 0) {
            $this->dispatch('toast', message: __('Cannot delete program with active activities.'), type: 'error');
            return;
        }

        $p->delete();
        $this->dispatch('toast', message: __('Program deleted successfully.'), type: 'success');
    }

    // ========== ECONOMIC CLASSIFICATION CRUD METHODS ==========

    public function openClassificationModal($id = null)
    {
        $this->resetValidation();
        if ($id) {
            $this->isClassificationEditing = true;
            $this->classificationId = $id;
            $c = EconomicClassification::findOrFail($id);
            $this->classificationCode = $c->code;
            $this->classificationName = $c->name;
            $this->classificationDescription = $c->description ?? '';
            $this->classificationType = $c->category_type ?? 'Expense';
        } else {
            $this->isClassificationEditing = false;
            $this->classificationId = null;
            $this->reset(['classificationCode', 'classificationName', 'classificationDescription']);
            $this->classificationType = 'Expense';
        }
        $this->classificationModalOpen = true;
    }

    public function saveClassification()
    {
        $rules = [
            'classificationCode' => 'required|string|max:50|unique:economic_classifications,code,' . $this->classificationId,
            'classificationName' => 'required|string|max:255',
            'classificationDescription' => 'nullable|string',
            'classificationType' => 'required|string',
        ];

        $this->validate($rules);

        $data = [
            'code' => $this->classificationCode,
            'name' => $this->classificationName,
            'description' => $this->classificationDescription,
            'category_type' => $this->classificationType,
        ];

        if ($this->isClassificationEditing) {
            EconomicClassification::findOrFail($this->classificationId)->update($data);
            $this->dispatch('toast', message: __('Economic classification updated successfully!'), type: 'success');
        } else {
            EconomicClassification::create($data);
            $this->dispatch('toast', message: __('Economic classification created successfully!'), type: 'success');
        }

        $this->classificationModalOpen = false;
    }

    public function deleteClassification($id)
    {
        $c = EconomicClassification::findOrFail($id);

        // Prevent deletion if it has line items
        if ($c->lineItems()->count() > 0) {
            $this->dispatch('toast', message: __('Cannot delete category with active line items.'), type: 'error');
            return;
        }

        $c->delete();
        $this->dispatch('toast', message: __('Economic classification deleted successfully.'), type: 'success');
    }
}; ?>

<div class="space-y-10 py-6 animate-in fade-in duration-700">
    <!-- Header -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-6">
        <div>
            <h1 class="text-4xl font-black text-slate-900 tracking-tight leading-none italic">
                {{ __('Resource Management') }}
            </h1>
            <p class="text-slate-400 font-bold uppercase tracking-[0.2em] text-[10px] mt-4">
                {{ __('Organizational Hierarchy & System Governance') }}
            </p>
        </div>
        <div class="flex gap-3">
            @if($activeTab === 'directorates')
                <x-ui.button wire:click="openModal()" variant="black" shadow="lg" class="!rounded-xl">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="mr-2">
                        <path d="M5 12h14" />
                        <path d="M12 5v14" />
                    </svg>
                    {{ __('Add New Unit') }}
                </x-ui.button>
            @elseif($activeTab === 'programs')
                <x-ui.button wire:click="openProgramModal()" variant="black" shadow="lg" class="!rounded-xl">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="mr-2">
                        <path d="M5 12h14" />
                        <path d="M12 5v14" />
                    </svg>
                    {{ __('Add New Program') }}
                </x-ui.button>
            @elseif($activeTab === 'classifications')
                <x-ui.button wire:click="openClassificationModal()" variant="black" shadow="lg" class="!rounded-xl">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="mr-2">
                        <path d="M5 12h14" />
                        <path d="M12 5v14" />
                    </svg>
                    {{ __('Add New Classification') }}
                </x-ui.button>
            @endif
        </div>
    </div>

    <!-- Tab Navigation -->
    <div class="flex items-center gap-2 p-1.5 bg-slate-100/80 w-fit rounded-2xl border border-slate-200/50">
        <button wire:click="switchTab('directorates')"
            class="px-6 py-2.5 rounded-xl text-[11px] font-black uppercase tracking-widest transition-all {{ $activeTab === 'directorates' ? 'bg-white text-indigo-600 shadow-md' : 'text-slate-400 hover:text-slate-600' }}">
            {{ __('Directorates') }}
        </button>
        <button wire:click="switchTab('programs')"
            class="px-6 py-2.5 rounded-xl text-[11px] font-black uppercase tracking-widest transition-all {{ $activeTab === 'programs' ? 'bg-white text-indigo-600 shadow-md' : 'text-slate-400 hover:text-slate-600' }}">
            {{ __('Programs') }}
        </button>
        <button wire:click="switchTab('classifications')"
            class="px-6 py-2.5 rounded-xl text-[11px] font-black uppercase tracking-widest transition-all {{ $activeTab === 'classifications' ? 'bg-white text-indigo-600 shadow-md' : 'text-slate-400 hover:text-slate-600' }}">
            {{ __('Economic Classifications') }}
        </button>
    </div>

    <!-- Directorates Tab Content -->
    @if($activeTab === 'directorates')
        @include('livewire.pages.management.entities-tabs.directorates')
    @endif

    {{-- Programs Tab Content --}}
    @if($activeTab === 'programs')
        @include('livewire.pages.management.entities-tabs.programs')
    @endif

    {{-- Economic Classifications Tab Content --}}
    @if($activeTab === 'classifications')
        @include('livewire.pages.management.entities-tabs.classifications')
    @endif

</div>