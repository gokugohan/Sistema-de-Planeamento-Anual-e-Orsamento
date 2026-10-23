<?php

use Livewire\Volt\Component;
use App\Models\Directorate;
use App\Models\Program;
use App\Models\Subprogram;
use App\Models\EconomicClassification;
use App\Models\Activity;
use App\Services\ProgramYears;
use Illuminate\Validation\Rule;
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
    public $administration = 'direct';
    public $legal_form = '';
    public $legal_basis = '';

    public $modalOpen = false;
    public $isEditing = false;

    // Program Form State
    public $programId = null;
    public $programName = '';
    public $ogeCode = '';
    public $objective = '';
    public $managerName = '';
    public $kpis = '';
    public $programModalOpen = false;
    public $isProgramEditing = false;
    /** Suggestions from other years stop showing once one was picked, until the user types again. */
    public bool $suggestionsHidden = false;
    public $copyFromYear = '';

    /** The programme or subprogramme whose objectives and result indicators are open in the dialog. */
    public ?array $detailsFor = null;

    // Subprogram Form State
    public $subprogramId = null;
    public $subProgramId = '';
    public $subCode = '';
    public $subName = '';
    public $subObjective = '';
    public $subprogramFilter = '';
    public $subprogramModalOpen = false;
    public $isSubprogramEditing = false;

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

        // Funding for the working year (approved activities only). Parent units include their sub-units.
        $year = \App\Support\FiscalYear::current();
        $rows = Activity::withoutGlobalScopes()
            ->where('activities.is_test_data', false)->where('activities.fiscal_year', $year)->where('activities.status', Activity::STATUS_APPROVED)
            ->leftJoin('line_items', fn ($j) => $j->on('line_items.activity_id', '=', 'activities.id')->where('line_items.is_test_data', false))
            ->leftJoin('adjusted_budgets', 'adjusted_budgets.line_item_id', '=', 'line_items.id')
            ->groupBy('activities.directorate_id', 'activities.program_id')
            ->selectRaw('activities.directorate_id as d, activities.program_id as p, COUNT(DISTINCT activities.id) as n,
                COALESCE(SUM(CASE WHEN line_items.deleted_at IS NULL THEN line_items.estimated_amount ELSE 0 END), 0) as plan, COALESCE(SUM(adjusted_budgets.approved_amount), 0) as alloc')
            ->get();

        $own = [];
        foreach ($rows as $r) {
            foreach (['n', 'plan', 'alloc'] as $k) {
                $own[$r->d][$k] = ($own[$r->d][$k] ?? 0) + $r->{$k};
            }
        }
        $children = $allDirectorates->groupBy('parent_id');
        $rollup = function ($id) use (&$rollup, $own, $children) {
            $t = $own[$id] ?? ['n' => 0, 'plan' => 0, 'alloc' => 0];
            foreach ($children->get($id, collect()) as $child) {
                $c = $rollup($child->id);
                foreach (['n', 'plan', 'alloc'] as $k) {
                    $t[$k] += $c[$k];
                }
            }

            return $t;
        };
        $dirFunding = $allDirectorates->mapWithKeys(fn ($d) => [$d->id => $rollup($d->id)])->all();
        $progFunding = $rows->groupBy('p')->map(fn ($g) => ['n' => (int) $g->sum('n'), 'plan' => (float) $g->sum('plan'), 'alloc' => (float) $g->sum('alloc')])->all();

        // Who is in each unit and who directs it: two grouped queries, kept until a user, role or unit changes.
        $people = $this->activeTab === 'directorates'
            ? \App\Support\StructureCache::remember('unit-people-counts', 300, fn () => [
                'count' => \App\Models\User::whereNotNull('directorate_id')->groupBy('directorate_id')->selectRaw('directorate_id, COUNT(*) AS n')->pluck('n', 'directorate_id')->map(fn ($n) => (int) $n)->all(),
                'directors' => \Illuminate\Support\Facades\DB::table('users as u')
                    ->join('model_has_roles as mr', fn ($j) => $j->on('mr.model_id', '=', 'u.id')->where('mr.model_type', (new \App\Models\User)->getMorphClass()))
                    ->join('roles as r', 'r.id', '=', 'mr.role_id')->where('r.name', 'Director')->whereNotNull('u.directorate_id')
                    ->orderBy('u.name')->get(['u.directorate_id', 'u.name'])->groupBy('directorate_id')->map(fn ($g) => $g->pluck('name')->all())->all(),
            ])
            : ['count' => [], 'directors' => []];

        // A unit with no Director of its own is directed from the nearest unit above that has one; if there is none, nobody directs it.
        $parentOf = $allDirectorates->pluck('parent_id', 'id');
        $directedFrom = [];
        $nobody = [];
        foreach ($allDirectorates as $d) {
            if (isset($people['directors'][$d->id])) {
                continue;
            }
            $up = $parentOf[$d->id] ?? null;
            for ($n = 0; $up && $n < 20 && ! isset($people['directors'][$up]); $n++) {
                $up = $parentOf[$up] ?? null;
            }
            if ($up) {
                $directedFrom[$d->id] = $allDirectorates->firstWhere('id', $up)?->code;
            } elseif ($d->active) {
                $nobody[] = $d->code;
            }
        }

        return [
            'peopleByUnit' => $people['count'],
            'directorsByUnit' => $people['directors'],
            'directedFrom' => $directedFrom,
            'nobodyDirects' => $this->activeTab === 'directorates' ? $nobody : [],
            'fundingYear' => $year,
            'dirFunding' => $dirFunding,
            'progFunding' => $progFunding,
            'directorates' => Directorate::with('parent')
                ->when($this->search, function ($q) {
                    $q->where('name', 'like', "%{$this->search}%")
                        ->orWhere('code', 'like', "%{$this->search}%");
                })
                ->orderByRaw('COALESCE(parent_id, id), parent_id IS NOT NULL, name')
                ->paginate(15),
            'programs' => Program::forYear($year)->withCount(['alignments', 'resultIndicators'])->when($this->search, function ($q) {
                $q->where(fn ($w) => $w->where('name', 'like', "%{$this->search}%")
                    ->orWhere('oge_code', 'like', "%{$this->search}%"));
            })
                ->orderBy('oge_code')
                ->paginate(10),
            // Auto-complete while registering: programmes of other years that this year does not have yet.
            'programSuggestions' => ($this->programModalOpen && ! $this->isProgramEditing && ! $this->suggestionsHidden)
                ? app(ProgramYears::class)->suggestions($year, trim((string) $this->ogeCode) !== '' ? (string) $this->ogeCode : (string) $this->programName)
                : collect(),
            'subprograms' => Subprogram::query()
                ->with('program:id,oge_code,name,fiscal_year')->withCount(['activities', 'alignments', 'resultIndicators'])
                ->whereHas('program', fn ($q) => $q->where('fiscal_year', $year))
                ->when($this->subprogramFilter !== '', fn ($q) => $q->where('program_id', (int) $this->subprogramFilter))
                ->when($this->search, fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$this->search}%")->orWhere('code', 'like', "%{$this->search}%")))
                ->orderBy(Program::select('oge_code')->whereColumn('programs.id', 'subprograms.program_id'))->orderBy('code')
                ->paginate(10),
            'yearPrograms' => Program::forYear($year)->orderBy('oge_code')->get(['id', 'oge_code', 'name']),
            'copySources' => app(ProgramYears::class)->years()->reject(fn ($y) => $y === $year)->values(),
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
            $this->administration = $d->administration ?: 'direct';
            $this->legal_form = (string) $d->legal_form;
            $this->legal_basis = (string) $d->legal_basis;
        } else {
            $this->isEditing = false;
            $this->directorateId = null;
            $this->reset(['name', 'code', 'parent_id', 'type', 'description', 'director_name', 'director_email', 'active', 'administration', 'legal_form', 'legal_basis']);
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
            'administration' => 'required|in:direct,indirect',
            'legal_form' => 'nullable|string|max:20',
            'legal_basis' => 'nullable|string|max:255',
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
            'administration' => $this->administration,
            'legal_form' => $this->administration === 'indirect' ? ($this->legal_form ?: null) : null,
            'legal_basis' => $this->legal_basis ?: null,
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

    private function guardPrograms(): void
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);
    }

    public function updatedOgeCode(): void
    {
        $this->suggestionsHidden = false;
    }

    public function updatedProgramName(): void
    {
        $this->suggestionsHidden = false;
    }

    /** Fill the form from a programme of another year; saving registers it for the working year. */
    public function pickProgram($id): void
    {
        $this->guardPrograms();
        $p = Program::findOrFail($id);
        $this->ogeCode = (string) $p->oge_code;
        $this->programName = $p->name;
        $this->managerName = $p->manager_name ?? '';
        $this->objective = $p->objective ?? '';
        $this->kpis = $p->kpis ?? '';
        $this->suggestionsHidden = true;
    }

    /** Register in the working year the programmes another year has and this one does not. */
    public function copyPrograms(): void
    {
        $this->guardPrograms();
        $year = \App\Support\FiscalYear::current();
        $from = (int) $this->copyFromYear;
        if (! in_array($from, app(ProgramYears::class)->years()->all(), true) || $from === $year) {
            $this->addError('copyFromYear', __('Choose the year to copy from.'));

            return;
        }

        $r = app(ProgramYears::class)->copy($from, $year);
        $this->copyFromYear = '';
        $this->resetPage();
        $this->dispatch('toast', message: ($r['copied'] || $r['subprograms'])
            ? __(':n programs and :m subprograms copied from :from to :year.', ['n' => $r['copied'], 'm' => $r['subprograms'], 'from' => $from, 'year' => $year])
            : __('Nothing to copy: :year already has the programmes of :from.', ['year' => $year, 'from' => $from]), type: ($r['copied'] || $r['subprograms']) ? 'success' : 'info');
    }

    public function openProgramModal($id = null)
    {
        $this->guardPrograms();
        $this->resetValidation();
        $this->suggestionsHidden = false;
        if ($id) {
            $this->isProgramEditing = true;
            $this->programId = $id;
            $p = Program::findOrFail($id);
            $this->programName = $p->name;
            $this->ogeCode = $p->oge_code ?? '';
            $this->objective = $p->objective ?? '';
            $this->managerName = $p->manager_name ?? '';
            $this->kpis = $p->kpis ?? '';
        } else {
            $this->isProgramEditing = false;
            $this->programId = null;
            $this->reset(['programName', 'ogeCode', 'objective', 'managerName', 'kpis']);
        }
        $this->programModalOpen = true;
    }

    public function saveProgram()
    {
        $this->guardPrograms();
        $year = $this->isProgramEditing ? (int) Program::findOrFail($this->programId)->fiscal_year : \App\Support\FiscalYear::current();

        $rules = [
            'programName' => 'required|string|max:255',
            // one code per year: the same code is fine in another year
            'ogeCode' => ['required', 'string', 'max:50', Rule::unique('programs', 'oge_code')->where('fiscal_year', $year)->ignore($this->programId)],
            'objective' => 'nullable|string',
            'managerName' => 'nullable|string|max:255',
            'kpis' => 'nullable|string',
        ];

        $this->validate($rules);

        $data = [
            'name' => $this->programName,
            'oge_code' => trim($this->ogeCode),
            'objective' => $this->objective,
            'manager_name' => $this->managerName,
            'kpis' => $this->kpis,
        ];

        if ($this->isProgramEditing) {
            Program::findOrFail($this->programId)->update($data);
            $this->dispatch('toast', message: __('Program updated successfully!'), type: 'success');
        } else {
            Program::create($data + ['fiscal_year' => $year]);
            $this->dispatch('toast', message: __('Program created successfully!'), type: 'success');
        }

        $this->programModalOpen = false;
    }

    public function deleteProgram($id)
    {
        $this->guardPrograms();
        $p = Program::findOrFail($id);

        // Prevent deletion if it has activities
        if ($p->activities()->count() > 0) {
            $this->dispatch('toast', message: __('Cannot delete program with active activities.'), type: 'error');
            return;
        }

        $p->delete();
        $this->dispatch('toast', message: __('Program deleted successfully.'), type: 'success');
    }

    public function openDetails(string $type, int $id): void
    {
        $this->guardPrograms();
        $model = $type === 'subprogram' ? Subprogram::findOrFail($id) : Program::findOrFail($id);
        $this->detailsFor = [
            'type' => $type === 'subprogram' ? 'subprogram' : 'program',
            'id' => $model->id,
            'title' => $type === 'subprogram' ? $model->code.' · '.$model->name : $model->oge_code.' · '.$model->name,
        ];
    }

    public function closeDetails(): void
    {
        $this->detailsFor = null;
    }

    // ========== SUBPROGRAM CRUD METHODS ==========

    public function openSubprogramModal($id = null)
    {
        $this->guardPrograms();
        $this->resetValidation();
        if ($id) {
            $s = Subprogram::findOrFail($id);
            $this->isSubprogramEditing = true;
            $this->subprogramId = $s->id;
            $this->subProgramId = (string) $s->program_id;
            $this->subCode = (string) $s->code;
            $this->subName = $s->name;
            $this->subObjective = $s->objective ?? '';
        } else {
            $this->isSubprogramEditing = false;
            $this->subprogramId = null;
            $this->reset(['subCode', 'subName', 'subObjective']);
            $this->subProgramId = (string) $this->subprogramFilter;
        }
        $this->subprogramModalOpen = true;
    }

    public function saveSubprogram()
    {
        $this->guardPrograms();
        $year = \App\Support\FiscalYear::current();

        $this->validate([
            // a subprogramme belongs to a programme of the working year
            'subProgramId' => ['required', Rule::exists('programs', 'id')->where('fiscal_year', $year)],
            // one code per programme
            'subCode' => ['required', 'string', 'max:50', Rule::unique('subprograms', 'code')->where('program_id', $this->subProgramId)->ignore($this->subprogramId)],
            'subName' => ['required', 'string', 'max:255'],
            'subObjective' => ['nullable', 'string'],
        ]);

        $data = ['program_id' => (int) $this->subProgramId, 'code' => trim($this->subCode), 'name' => $this->subName, 'objective' => $this->subObjective ?: null];

        if ($this->isSubprogramEditing) {
            Subprogram::findOrFail($this->subprogramId)->update($data);
            $this->dispatch('toast', message: __('Subprogram updated successfully!'), type: 'success');
        } else {
            Subprogram::create($data);
            $this->dispatch('toast', message: __('Subprogram created successfully!'), type: 'success');
        }

        $this->subprogramModalOpen = false;
    }

    public function deleteSubprogram($id)
    {
        $this->guardPrograms();
        $s = Subprogram::findOrFail($id);

        if ($s->activities()->exists()) {
            $this->dispatch('toast', message: __('Cannot delete a subprogram that has activities.'), type: 'error');

            return;
        }

        $s->delete();
        $this->dispatch('toast', message: __('Subprogram deleted successfully.'), type: 'success');
    }

    public function updatedSubprogramFilter(): void
    {
        $this->resetPage();
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

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="page-title">
                {{ __('Entities') }}
            </h1>
            <p class="page-subtitle">
                {{ __('Units, programmes, subprogrammes and economic categories, with their funding for the selected fiscal year') }}
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
            @elseif($activeTab === 'subprograms')
                <x-ui.button wire:click="openSubprogramModal()" variant="black" shadow="lg" class="!rounded-xl">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="mr-2">
                        <path d="M5 12h14" />
                        <path d="M12 5v14" />
                    </svg>
                    {{ __('Add New Subprogram') }}
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
        <button wire:click="switchTab('subprograms')"
            class="px-6 py-2.5 rounded-xl text-[11px] font-black uppercase tracking-widest transition-all {{ $activeTab === 'subprograms' ? 'bg-white text-indigo-600 shadow-md' : 'text-slate-400 hover:text-slate-600' }}">
            {{ __('Subprograms') }}
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
    @if($activeTab === 'subprograms')
        @include('livewire.pages.management.entities-tabs.subprograms')
    @endif

    @if($activeTab === 'programs')
        @include('livewire.pages.management.entities-tabs.programs')
    @endif

    {{-- Economic Classifications Tab Content --}}
    @if($activeTab === 'classifications')
        @include('livewire.pages.management.entities-tabs.classifications')
    @endif

    @if($detailsFor)
        <x-dialog max-width="3xl" close="closeDetails()">
            <x-slot:title>{{ $detailsFor['title'] }}</x-slot:title>
            <x-slot:subtitle>{{ __('Objectives and result indicators') }}</x-slot:subtitle>
            <livewire:pages.management.structure-details :type="$detailsFor['type']" :id="$detailsFor['id']" :key="'details-'.$detailsFor['type'].'-'.$detailsFor['id']" />
        </x-dialog>
    @endif
</div>
