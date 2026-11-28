<?php

use Livewire\Volt\Component;
use App\Models\Directorate;
use App\Models\Program;
use App\Models\Subprogram;
use App\Models\EconomicClassification;
use App\Models\Activity;
use App\Models\ResultIndicator;
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
    public $budget_code = '';
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

    /** The outcome (of a programme) or output (of a subprogramme) open in the side panel. */
    public ?string $resultKind = null;
    public ?int $resultOwnerId = null;
    public ?int $resultId = null;
    public string $rCode = '';
    public string $rStatement = '';
    public $rOutcome = '';
    public string $rName = '';
    public string $rBaseline = '';
    public string $rTarget = '';
    public string $rMeans = '';

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
            // a gabinete and an autonomous body have their own departments and units too
            'Department' => ['National Directorate', 'General Directorate', 'Directorate General', 'Cabinet', 'Independent'],
            'Unit' => ['Department', 'National Directorate', 'Cabinet', 'Independent'],
            'Regional Office' => ['General Directorate', 'Directorate General'],
            'General Directorate' => [], // Top-level
            'Cabinet' => [], // Top-level
            'Independent' => [], // Top-level
        ];
        $parentMapping['National Directorate'][] = 'Directorate General'; // the form's name for a directorate-general


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
            'structure' => in_array($this->activeTab, ['programs', 'subprograms'], true) ? $this->structure($year) : null,
            'resultOutcomes' => $this->resultKind === 'output' ? $this->outcomesFor($this->resultOwnerId) : collect(),
            'resultOwner' => $this->resultKind ? $this->resultOwner() : null,
            // Auto-complete while registering: programmes of other years that this year does not have yet.
            'programSuggestions' => ($this->programModalOpen && ! $this->isProgramEditing && ! $this->suggestionsHidden)
                ? app(ProgramYears::class)->suggestions($year, trim((string) $this->ogeCode) !== '' ? (string) $this->ogeCode : (string) $this->programName)
                : collect(),
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
        // programmes and subprogrammes are one tree now: the old subprogram tab opens it
        $this->activeTab = $tab === 'subprograms' ? 'programs' : $tab;
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
            $this->budget_code = (string) $d->budget_code;
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
            $this->reset(['name', 'code', 'budget_code', 'parent_id', 'type', 'description', 'director_name', 'director_email', 'active', 'administration', 'legal_form', 'legal_basis']);
        }
        $this->modalOpen = true;
    }

    public function saveDirectorate()
    {
        $rules = [
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:directorates,code,' . $this->directorateId,
            // the division code the State Budget gives the unit (Div), e.g. 0250202
            'budget_code' => 'nullable|string|max:20',
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
            'budget_code' => trim((string) $this->budget_code) ?: null,
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

    /** $programId: a new subprogramme added from its programme in the tree starts under it. */
    public function openSubprogramModal($id = null, $programId = null)
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
            $this->subProgramId = (string) ($programId ?: $this->subprogramFilter);
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

    // ========== OUTCOMES AND OUTPUTS (the results chain of the State Budget) ==========

    /** The programme (for an outcome) or subprogramme (for an output) a result belongs to. */
    private function resultOwner(): Program|Subprogram
    {
        return $this->resultKind === 'output' ? Subprogram::findOrFail($this->resultOwnerId) : Program::findOrFail($this->resultOwnerId);
    }

    /** For an output: the outcomes of its programme, one of which it serves. */
    private function outcomesFor(?int $subprogramId)
    {
        $programId = $subprogramId ? Subprogram::whereKey($subprogramId)->value('program_id') : null;

        return $programId ? ResultIndicator::where('indicatable_type', (new Program)->getMorphClass())->where('indicatable_id', $programId)->orderBy('code')->orderBy('id')->get() : collect();
    }

    public function openResult(string $kind, int $ownerId, ?int $id = null): void
    {
        $this->guardPrograms();
        $this->resetValidation();
        $this->resultKind = $kind === 'output' ? 'output' : 'outcome';
        $this->resultOwnerId = $ownerId;
        $owner = $this->resultOwner();
        $r = $id ? $owner->resultIndicators()->findOrFail($id) : null;
        $this->resultId = $r?->id;
        $this->rCode = (string) $r?->code;
        $this->rStatement = (string) $r?->statement;
        $this->rName = (string) $r?->name;
        $this->rBaseline = (string) $r?->baseline;
        $this->rTarget = (string) $r?->target;
        $this->rMeans = (string) $r?->means_of_verification;
        // a new output serves the programme's outcome when there is only one
        $outcomes = $this->resultKind === 'output' ? $this->outcomesFor($ownerId) : collect();
        $this->rOutcome = (string) ($r?->outcome_id ?? ($outcomes->count() === 1 ? $outcomes->first()->id : ''));
    }

    public function saveResult(): void
    {
        $this->guardPrograms();
        $owner = $this->resultOwner();
        $outcomes = $this->resultKind === 'output' ? $this->outcomesFor($owner->id) : collect();
        $this->validate([
            'rCode' => ['nullable', 'string', 'max:40'],
            'rStatement' => ['nullable', 'string', 'max:2000'],
            'rOutcome' => ['nullable', Rule::in($outcomes->pluck('id')->map(fn ($id) => (string) $id)->all())],
            'rName' => ['required', 'string', 'max:500'],
            'rBaseline' => ['nullable', 'string', 'max:255'],
            'rTarget' => ['nullable', 'string', 'max:255'],
            'rMeans' => ['nullable', 'string', 'max:1000'],
        ], [], ['rName' => __('indicator'), 'rOutcome' => __('outcome'), 'rCode' => __('code')]);

        $data = [
            'code' => trim($this->rCode) ?: null, 'statement' => trim($this->rStatement) ?: null,
            'outcome_id' => $this->resultKind === 'output' && $this->rOutcome !== '' ? (int) $this->rOutcome : null,
            'name' => trim($this->rName), 'baseline' => trim($this->rBaseline) ?: null, 'target' => trim($this->rTarget) ?: null,
            'means_of_verification' => trim($this->rMeans) ?: null,
        ];
        $this->resultId ? $owner->resultIndicators()->findOrFail($this->resultId)->update($data) : $owner->resultIndicators()->create($data);

        $this->dispatch('toast', message: $this->resultKind === 'output' ? __('Output saved.') : __('Outcome saved.'), type: 'success');
        $this->closeResult();
    }

    public function deleteResult(string $kind, int $ownerId, int $id): void
    {
        $this->guardPrograms();
        $this->resultKind = $kind === 'output' ? 'output' : 'outcome';
        $this->resultOwnerId = $ownerId;
        // the activities serving an output, and the outputs serving an outcome, keep going: they lose the link only
        $this->resultOwner()->resultIndicators()->findOrFail($id)->delete();
        $this->dispatch('toast', message: $this->resultKind === 'output' ? __('Output removed.') : __('Outcome removed.'), type: 'success');
        $this->closeResult();
    }

    public function closeResult(): void
    {
        $this->reset(['resultKind', 'resultOwnerId', 'resultId', 'rCode', 'rStatement', 'rOutcome', 'rName', 'rBaseline', 'rTarget', 'rMeans']);
        $this->resetValidation();
    }

    /**
     * The programme structure of the year as the State Budget prints it: each programme with its outcomes, its
     * subprogrammes and their outputs, and what is still missing for the final plan report to show the whole chain.
     */
    private function structure(int $year): array
    {
        $programType = (new Program)->getMorphClass();
        $subType = (new Subprogram)->getMorphClass();
        $search = trim((string) $this->search);

        $programs = Program::forYear($year)->withCount('alignments')
            ->with([
                'resultIndicators' => fn ($q) => $q->orderBy('code')->orderBy('id'),
                'subprograms' => fn ($q) => $q->withCount(['activities', 'alignments'])->orderBy('code'),
                'subprograms.resultIndicators' => fn ($q) => $q->orderBy('code')->orderBy('id'),
            ])
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('oge_code', 'like', "%{$search}%")
                ->orWhereHas('subprograms', fn ($s) => $s->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))))
            ->orderBy('oge_code')
            ->paginate(10);

        // the whole year, for the totals and what is missing
        $programIds = Program::forYear($year)->pluck('oge_code', 'id');
        $subs = Subprogram::whereIn('program_id', $programIds->keys())->get(['id', 'program_id', 'code']);
        $outcomes = ResultIndicator::where('indicatable_type', $programType)->whereIn('indicatable_id', $programIds->keys())->get(['id', 'indicatable_id']);
        $outputs = ResultIndicator::where('indicatable_type', $subType)->whereIn('indicatable_id', $subs->pluck('id'))->get(['id', 'indicatable_id', 'outcome_id', 'code']);
        $unlinked = Activity::where('fiscal_year', $year)->whereNotNull('subprogram_id')->whereNull('output_id')
            ->groupBy('subprogram_id')->selectRaw('subprogram_id, COUNT(*) AS n')->pluck('n', 'subprogram_id')->map(fn ($n) => (int) $n);

        $withOutcomes = $outcomes->pluck('indicatable_id')->unique();
        $withOutputs = $outputs->pluck('indicatable_id')->unique();
        $programOf = $subs->pluck('program_id', 'id');
        $subCode = $subs->pluck('code', 'id');

        return [
            'programs' => $programs,
            'unlinked' => $unlinked,
            'totals' => ['programs' => $programIds->count(), 'subprograms' => $subs->count(), 'outcomes' => $outcomes->count(), 'outputs' => $outputs->count()],
            'gaps' => array_filter([
                'no_outcome' => $programIds->except($withOutcomes->all())->values()->all(),
                'no_output' => $subs->whereNotIn('id', $withOutputs->all())->pluck('code')->all(),
                // an output whose programme has outcomes but that names none of them
                'loose_output' => $outputs->filter(fn ($o) => ! $o->outcome_id && $withOutcomes->contains($programOf[$o->indicatable_id] ?? null))
                    ->map(fn ($o) => $o->code ?: $subCode[$o->indicatable_id] ?? '')->values()->all(),
                // activities of a subprogramme that has outputs, but that name none
                'unlinked' => $unlinked->filter(fn ($n, $sub) => $withOutputs->contains($sub))->sum(),
            ]),
        ];
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
    @php
        $tabs = [
            'directorates' => [__('Organization'), __('Units and who heads them')],
            'programs' => [__('Program structure'), __('Programs, outcomes, subprograms and outputs')],
            'classifications' => [__('Economic categories'), __('The categories of a budget line')],
        ];
        $current = $activeTab === 'subprograms' ? 'programs' : $activeTab;
        [$addLabel, $addAction] = match ($current) {
            'programs' => [__('New program'), 'openProgramModal()'],
            'classifications' => [__('New category'), 'openClassificationModal()'],
            default => [__('New unit'), 'openModal()'],
        };
    @endphp

    <x-ui.page-header :title="__('Structure')" :subtitle="__('How the institution is organized and what its plan is built on: its units, its programs as the State Budget lists them, and the economic categories of the budget.')">
        <x-ui.button type="button" wire:click="{{ $addAction }}" class="whitespace-nowrap">
            <svg class="-ml-0.5 mr-1.5 h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
            {{ $addLabel }}
        </x-ui.button>
    </x-ui.page-header>

    <nav aria-label="{{ __('Structure') }}" class="-mx-4 flex gap-1 overflow-x-auto whitespace-nowrap border-b border-zinc-200 px-4 sm:mx-0 sm:px-0">
        @foreach($tabs as $key => [$label, $hint])
            <button type="button" wire:click="switchTab('{{ $key }}')" title="{{ $hint }}" @if($current === $key) aria-current="page" @endif
                class="-mb-px border-b-2 px-4 py-2.5 text-sm font-medium {{ $current === $key ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-zinc-600 hover:border-zinc-300 hover:text-zinc-900' }}">{{ $label }}</button>
        @endforeach
        <a href="{{ route('management.funding-sources') }}" wire:navigate title="{{ __('Where the money of a budget line comes from') }}"
            class="-mb-px border-b-2 border-transparent px-4 py-2.5 text-sm font-medium text-zinc-600 hover:border-zinc-300 hover:text-zinc-900">{{ __('Funding sources') }}</a>
    </nav>

    @if($current === 'directorates')
        @include('livewire.pages.management.entities-tabs.directorates')
    @elseif($current === 'programs')
        @include('livewire.pages.management.entities-tabs.program-structure')
    @elseif($current === 'classifications')
        @include('livewire.pages.management.entities-tabs.classifications')
    @endif
</div>
