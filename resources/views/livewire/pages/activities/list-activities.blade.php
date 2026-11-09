<?php

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\Program;
use App\Models\Subprogram;
use App\Policies\ActivityPolicy;
use App\Services\ActivityExporter;
use App\Services\ActivityRollForward;
use App\Services\ActivityWorkflow;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * The plan for the selected fiscal year: find, filter, group, export and move activities along
 * the approval chain. The year is the one chosen in the top bar.
 */
new class extends Component {
    use WithPagination;

    #[Url(as: 'tab')]
    public string $tab = 'all';       // all | draft | review | approved | mine
    #[Url(as: 'q')]
    public string $search = '';
    #[Url(as: 'unit')]
    public $unit = '';
    #[Url(as: 'programme')]
    public $program = '';
    #[Url(as: 'subprogramme')]
    public $subprogram = '';
    #[Url(as: 'where')]
    public string $location = '';
    #[Url(as: 'origin')]
    public string $origin = '';       // '' | baseline | policy
    #[Url(as: 'group')]
    public string $group = '';        // '' | program | unit

    /** @var array<int, string> ids ticked for a bulk action */
    public array $selected = [];

    private const GROUPS = [
        'draft' => [Activity::STATUS_DRAFT, Activity::STATUS_REJECTED],
        'review' => [Activity::STATUS_SUBMITTED, Activity::STATUS_REVIEWED, Activity::STATUS_VALIDATED, Activity::STATUS_WAITING_APPROVAL],
        'approved' => [Activity::STATUS_APPROVED],
    ];

    public function updated($name): void
    {
        if ($name === 'program') {
            $this->subprogram = ''; // another programme has other subprogrammes
        }
        if (in_array($name, ['tab', 'search', 'unit', 'program', 'subprogram', 'location', 'origin', 'group'], true)) {
            $this->resetPage();
            $this->selected = [];
        }
    }

    /** The × of a filter chip, or "All units". */
    public function clearFilter(string $name): void
    {
        if (in_array($name, ['search', 'unit', 'program', 'subprogram', 'location', 'origin'], true)) {
            $this->{$name} = '';
            $this->updated($name);
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->updated('tab');
    }

    /** Planning was finalized or reopened (by the planning-window card): show the new state and buttons. */
    #[On('planning-changed')]
    public function planningChanged(): void
    {
    }

    private function year(): int
    {
        return \App\Support\FiscalYear::current();
    }

    /** Everything the user may see for the year, before the screen's own filters. */
    private function visible()
    {
        $user = auth()->user();
        $q = Activity::query()->where('fiscal_year', $this->year());
        if (! ($user->can('view_all_paa') || $user->isAdministrator())) {
            $q->whereIn('directorate_id', Directorate::find($user->directorate_id)?->getSelfAndDescendantIds() ?? [0]);
        }

        return $q;
    }

    private function filtered()
    {
        $q = $this->visible();
        if ($this->unit !== '' && $root = Directorate::find((int) $this->unit)) {
            $q->whereIn('directorate_id', $root->getSelfAndDescendantIds());
        }
        if ($this->subprogram !== '') {
            $q->where('subprogram_id', (int) $this->subprogram);
        }
        if ($this->program !== '') {
            $q->where('program_id', (int) $this->program);
        }
        if ($this->location !== '') {
            $q->where('location', $this->location);
        }
        if (isset(Activity::ORIGINS[$this->origin])) {
            $q->where('origin', $this->origin);
        }
        if ($this->search !== '') {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $this->search).'%';
            $q->where(fn ($w) => $w->where('description', 'like', $term)->orWhere('code', 'like', $term));
        }

        return $q;
    }

    public function export()
    {
        abort_unless(auth()->user()->can('view_paa'), 403);
        $filters = $this->filterWords();
        $book = app(ActivityExporter::class)->workbook($this->filtered(), $this->year(), $filters);

        return \App\Support\SpreadsheetKit::download($book, "paa-{$this->year()}-activities.xlsx");
    }

    /** What the list is narrowed to, in words (the chips under the heading, and the unit). @return array<string, string> */
    private function filterWords(): array
    {
        $words = [];
        if ($this->unit !== '' && $u = Directorate::find((int) $this->unit)) {
            $words['unit'] = __('Unit').' '.$u->code;
        }
        if ($this->program !== '' && $p = Program::find((int) $this->program)) {
            $words['program'] = __('Programme').' '.$p->oge_code;
        }
        if ($this->subprogram !== '' && $sp = Subprogram::find((int) $this->subprogram)) {
            $words['subprogram'] = __('Subprogram').' '.$sp->code;
        }
        if ($this->location !== '') {
            $words['location'] = $this->location === 'Nationwide' ? __('Nationwide') : $this->location;
        }
        if (isset(Activity::ORIGINS[$this->origin])) {
            $words['origin'] = Activity::originWord($this->origin);
        }
        if ($this->search !== '') {
            $words['search'] = '“'.$this->search.'”';
        }

        return $words;
    }

    public function rollForward(): void
    {
        abort_unless(auth()->user()->can('create_activities'), 403);
        $user = auth()->user();
        $units = ($user->can('view_all_paa') || $user->isAdministrator()) ? null : (Directorate::find($user->directorate_id)?->getSelfAndDescendantIds() ?? [0]);

        try {
            $r = app(ActivityRollForward::class)->copy($this->year() - 1, $this->year(), $units);
        } catch (\DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }
        $this->dispatch('toast', message: __(':copied activities copied as drafts, :skipped already in the plan.', $r), type: $r['copied'] ? 'success' : 'info');
    }

    public function delete($id): void
    {
        $activity = Activity::findOrFail($id);
        $this->authorize('delete', $activity);
        $activity->delete();
        $this->selected = array_values(array_diff($this->selected, [(string) $id]));
        $this->dispatch('toast', message: __('Activity deleted.'), type: 'success');
    }

    /** Move every ticked activity one step along the chain, as far as the user's turn allows. */
    public function advanceSelected(): void
    {
        $workflow = app(ActivityWorkflow::class);
        $user = auth()->user();
        $done = 0;
        $notReady = 0;

        foreach ($this->visible()->with('directorate')->whereIn('id', $this->selected)->get() as $activity) {
            try {
                $workflow->advance($user, $activity);
                $done++;
            } catch (\DomainException) {
                $notReady++;
            }
        }
        $this->selected = [];

        $this->dispatch('toast',
            message: $done ? trans_choice(':n activity moved on.|:n activities moved on.', $done, ['n' => $done]).($notReady ? ' '.__(':n could not be moved (not your turn or not complete).', ['n' => $notReady]) : '')
                : __('None of the selected activities can be moved by you right now.'),
            type: $done ? 'success' : 'error');
    }

    public function with(): array
    {
        $user = auth()->user();
        $year = $this->year();
        $workflow = app(ActivityWorkflow::class);
        $policy = app(ActivityPolicy::class);

        $base = $this->filtered();
        $counts = (clone $base)->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status');
        $tabCounts = collect(self::GROUPS)->map(fn ($s) => (int) collect($s)->sum(fn ($x) => $counts[$x] ?? 0))->all();
        $tabCounts['all'] = (int) $counts->sum();

        $query = $this->filtered()->with(['directorate:id,code,name,parent_id', 'directorate.parent:id,parent_id,code', 'program:id,oge_code,name', 'subprogram:id,code,name'])
            ->withSum('lineItems as total', 'estimated_amount')->withCount(['indicators']);

        // "Needs my action" is decided by the workflow rules, so it is filtered after the query.
        if ($this->tab === 'mine') {
            $ids = $this->filtered()->get()->filter(fn ($a) => $workflow->next($user, $a) && $a->status !== Activity::STATUS_DRAFT && $a->status !== Activity::STATUS_REJECTED || ($workflow->canApprove($user, $a)))->pluck('id');
            $query->whereIn('id', $ids);
            $tabCounts['mine'] = $ids->count();
        } else {
            if (isset(self::GROUPS[$this->tab])) {
                $query->whereIn('status', self::GROUPS[$this->tab]);
            }
            $tabCounts['mine'] = null;
        }

        $query = match ($this->group) {
            'program' => $query->orderBy('program_id'),
            'unit' => $query->orderBy('directorate_id'),
            default => $query->orderBy('directorate_id'),
        };
        $activities = $query->orderBy('code')->orderBy('id')->paginate(20);

        $rowFlags = $activities->getCollection()->mapWithKeys(fn ($a) => [$a->id => [
            'edit' => $policy->update($user, $a),
            'delete' => $policy->delete($user, $a),
            'next' => $workflow->next($user, $a),
        ]]);

        // the money follows the filters, like the counts; the whole plan's total is shown beside it once a filter is on
        $money = fn ($q) => (float) \App\Models\LineItem::whereIn('activity_id', $q->select('id'))->sum('estimated_amount');
        $filtersActive = $this->unit !== '' || $this->program !== '' || $this->subprogram !== '' || $this->location !== '' || $this->origin !== '' || $this->search !== '';
        $all = $money($this->filtered());
        $previous = Activity::where('fiscal_year', $year - 1)->exists();

        // the list of one unit says whose it is: the unit is the page's heading, with its own figures
        $unitInfo = null;
        if ($this->unit !== '' && $shown = Directorate::find((int) $this->unit)) {
            $ancestors = [];
            for ($p = $shown->parent, $hops = 0; $p && $hops < 20; $p = $p->parent, $hops++) {
                array_unshift($ancestors, $p->code);
            }
            $byClass = \App\Models\LineItem::whereIn('activity_id', $this->filtered()->select('id'))
                ->join('economic_classifications', 'economic_classifications.id', '=', 'line_items.economic_classification_id')
                ->groupBy('economic_classifications.code', 'economic_classifications.name')->orderBy('economic_classifications.code')
                ->selectRaw('economic_classifications.code as code, economic_classifications.name as name, SUM(line_items.estimated_amount) as amount')
                ->get()->filter(fn ($c) => (float) $c->amount != 0)->values();
            $state = match (true) {
                (bool) app(\App\Services\PlanningWindow::class)->unitEndedBy($year, $shown->id) => ['label' => __('Plan finalized'), 'tone' => 'emerald'],
                $tabCounts['all'] === 0 => ['label' => __('Nothing planned yet'), 'tone' => 'zinc'],
                $tabCounts['approved'] === $tabCounts['all'] => ['label' => __('Approved'), 'tone' => 'emerald'],
                $tabCounts['review'] > 0 => ['label' => __('In review'), 'tone' => 'amber'],
                default => ['label' => __('Draft'), 'tone' => 'zinc'],
            };
            $unitInfo = [
                'unit' => $shown,
                'ancestors' => $ancestors,
                'kind' => $shown->parent_id ? null : ($shown->isAutonomous() ? __('Autonomous body') : __('Ministry services')),
                'leader' => $shown->director_name ? $shown->leaderTitle().': '.$shown->director_name : null,
                'byClass' => $byClass,
                'state' => $state,
            ];
        }

        // every other filter shows as a chip, so a shared link says what it shows
        $chips = array_diff_key($this->filterWords(), ['unit' => true]);

        return [
            'year' => $year,
            'activities' => $activities,
            'flags' => $rowFlags,
            'tabCounts' => $tabCounts,
            'totalPlanned' => $all,
            'totalApproved' => $money($this->filtered()->whereIn('status', self::GROUPS['approved'])),
            'totalPlan' => $filtersActive ? $money($this->visible()) : null,
            'units' => Directorate::whereNull('parent_id')->orderBy('code')->get(['id', 'code', 'name', 'administration']),
            'programs' => Program::forYear($this->year())->orderBy('oge_code')->get(['id', 'oge_code', 'name']),
            'subprograms' => Subprogram::query()
                ->whereHas('program', fn ($q) => $q->where('fiscal_year', $this->year()))
                ->when($this->program !== '', fn ($q) => $q->where('program_id', (int) $this->program))
                ->orderBy('code')->get(['id', 'program_id', 'code', 'name']),
            'locations' => config('paa.locations'),
            // the origin filter only shows once the year has an activity that is not an existing service
            'showOrigin' => $this->origin !== '' || $this->visible()->where('origin', '!=', Activity::ORIGIN_BASELINE)->exists(),
            'canCreate' => $user->can('create', Activity::class),
            'canRoll' => $user->can('create_activities') && $previous && $tabCounts['all'] === 0,
            'locked' => \App\Support\FiscalYear::planningEndedBy($year, $user->isAdministrator() ? null : $user->directorate_id),
            'tabs' => ['all' => __('All'), 'draft' => __('Drafts & returned'), 'review' => __('In review'), 'approved' => __('Approved'), 'mine' => __('Needs my action')],
            'filtersActive' => $filtersActive,
            'unitInfo' => $unitInfo,
            'chips' => $chips,
        ];
    }
}; ?>

@php
    $badge = fn ($s) => match ($s) {
        Activity::STATUS_APPROVED => 'emerald',
        Activity::STATUS_VALIDATED, Activity::STATUS_WAITING_APPROVAL => 'indigo',
        Activity::STATUS_SUBMITTED, Activity::STATUS_REVIEWED => 'amber',
        Activity::STATUS_REJECTED => 'red',
        default => 'gray',
    };
    $priority = fn ($p) => match ($p) { 'Alta', 'High' => __('High'), 'Baixa', 'Low' => __('Low'), default => __('Medium') };
    $actionLabel = fn ($to) => match ($to) {
        Activity::STATUS_SUBMITTED => __('Submit'), Activity::STATUS_REVIEWED => __('Review'),
        Activity::STATUS_VALIDATED => __('Validate'), default => __('Send up'),
    };
    $lastGroup = null;
@endphp

{{-- The ticked rows live in the browser (a deferred entangle) and travel with the next action, so ticking a box does not re-render the list. --}}
<div class="space-y-6" x-data="{ selected: $wire.$entangle('selected') }">
    @php
        $org = \App\Models\SystemSetting::getLocalized('org_abbr', '');
        $headTitle = $unitInfo ? $unitInfo['unit']->name : __('PAA Activities');
        $headSub = collect([__('Annual Activity Plan :year', ['year' => $year]), $unitInfo['kind'] ?? null, $unitInfo['leader'] ?? null])->filter()->implode(' · ');
        $eyebrow = $unitInfo ? collect([$org, ...$unitInfo['ancestors'], $unitInfo['unit']->code])->filter()->unique()->implode(' › ') : null;
    @endphp
    <x-ui.page-header :title="$headTitle" :subtitle="$headSub" :eyebrow="$eyebrow">
        @if($unitInfo)
            <x-ui.button variant="secondary" wire:click="clearFilter('unit')">← {{ __('All units') }}</x-ui.button>
        @endif
        <x-ui.button variant="secondary" wire:click="export">{{ __('Export Excel') }}</x-ui.button>
        @if($canRoll && ! $locked)
            <x-ui.button variant="secondary" wire:click="rollForward"
                wire:confirm="{{ __('Copy the :from plan into :to as drafts?', ['from' => $year - 1, 'to' => $year]) }}">{{ __('Copy from :year', ['year' => $year - 1]) }}</x-ui.button>
        @endif
        @if($canCreate && ! $locked)
            <x-ui.button href="{{ route('activities.create') }}" wire:navigate>+ {{ __('New activity') }}</x-ui.button>
        @endif
    </x-ui.page-header>

    <x-calendar-alerts :year="$year" />

    <livewire:pages.planning.planning-window :year="$year" :key="'planning-'.$year" />

    @if(count($chips))
        <div class="-mt-2 flex flex-wrap items-center gap-2" aria-label="{{ __('Filters') }}">
            @foreach($chips as $name => $text)
                <button type="button" wire:click="clearFilter('{{ $name }}')" class="inline-flex items-center gap-1.5 rounded-full bg-indigo-50 px-3 py-1 text-xs font-medium text-indigo-800 hover:bg-indigo-100"
                    title="{{ __('Remove this filter') }}">{{ $text }} <span aria-hidden="true">×</span><span class="sr-only">{{ __('Remove this filter') }}</span></button>
            @endforeach
        </div>
    @endif

    @if($unitInfo)
        {{-- the unit's own figures, for the activities shown --}}
        @php
            $share = $totalPlan ? $totalPlanned / $totalPlan * 100 : null;
            $approvedPct = $totalPlanned > 0 ? min(100, $totalApproved / $totalPlanned * 100) : null;
            $stateTone = ['emerald' => 'text-emerald-700', 'amber' => 'text-amber-700', 'zinc' => 'text-zinc-900'][$unitInfo['state']['tone']];
        @endphp
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.kpi :label="__('Planned budget')" :value="'$ ' . number_format($totalPlanned)" :sub="__(':n activities', ['n' => $tabCounts['all']])" tone="indigo" />
            <x-ui.kpi :label="__('Share of the plan')" :value="$share !== null ? number_format($share, 1) . '%' : '—'"
                :sub="$totalPlan ? __('of $ :t in the whole plan', ['t' => number_format($totalPlan)]) : null" :progress="$share" tone="indigo" />
            <x-ui.kpi :label="__('Approved')" :value="'$ ' . number_format($totalApproved)"
                :sub="$approvedPct !== null ? number_format($approvedPct, 0) . '% ' . __('of the planned budget') : null" :progress="$approvedPct" tone="emerald" />
            <div class="rounded-lg border border-zinc-200 bg-white p-5 shadow-card">
                <p class="text-sm font-medium text-zinc-500">{{ __('Plan status') }}</p>
                <p class="mt-2 text-2xl font-semibold {{ $stateTone }}">{{ $unitInfo['state']['label'] }}</p>
                <p class="mt-1 text-xs text-zinc-500">{{ __(':n in draft', ['n' => $tabCounts['draft']]) }} · {{ $tabCounts['review'] }} {{ __('In review') }} · {{ $tabCounts['approved'] }} {{ __('Approved') }}</p>
            </div>
        </div>
        @if($unitInfo['byClass']->isNotEmpty())
            <div class="flex flex-wrap gap-x-6 gap-y-2 rounded-lg border border-zinc-200 bg-white px-5 py-3 text-sm shadow-card">
                <span class="font-medium text-zinc-500">{{ __('By economic category') }}</span>
                @foreach($unitInfo['byClass'] as $c)
                    <span class="text-zinc-700"><span class="font-semibold text-zinc-900">{{ $c->code }}</span> {{ $c->name }}
                        <span class="tabular-nums font-medium text-zinc-900">$ {{ number_format((float) $c->amount) }}</span></span>
                @endforeach
            </div>
        @endif
    @else
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.kpi :label="__('Activities')" :value="number_format($tabCounts['all'])" :sub="__(':n in draft', ['n' => $tabCounts['draft']])" tone="zinc" />
        <x-ui.kpi :label="__('In review')" :value="number_format($tabCounts['review'])" :sub="__('Waiting for a decision')" tone="amber" />
        <x-ui.kpi :label="__('Approved')" :value="number_format($tabCounts['approved'])" :sub="__('Part of the approved plan')" tone="emerald" />
        <x-ui.kpi :label="__('Planned budget')" :value="'$ ' . number_format($totalPlanned)"
            :sub="$totalPlan !== null ? __('Approved: $ :n · of $ :t in the whole plan', ['n' => number_format($totalApproved), 't' => number_format($totalPlan)]) : __('Approved: $ :n', ['n' => number_format($totalApproved)])" tone="indigo" />
    </div>
    @endif

    {{-- Filters --}}
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-[1fr_12rem_14rem_14rem_11rem_9rem]">
        <input type="search" wire:model.live.debounce.300ms="search" class="flux-input" placeholder="{{ __('Search by code or description') }}" aria-label="{{ __('Search') }}">
        <select wire:model.live="unit" class="flux-input" aria-label="{{ __('Unit') }}">
            <option value="">{{ __('All units') }}</option>
            @foreach($units->groupBy(fn ($u) => $u->isAutonomous() ? 'indirect' : 'direct') as $kind => $group)
                <optgroup label="{{ $kind === 'indirect' ? __('Autonomous bodies') : __('Ministry services') }}">
                    @foreach($group as $u)<option value="{{ $u->id }}">{{ $u->code }}</option>@endforeach
                </optgroup>
            @endforeach
        </select>
        <select wire:model.live="program" class="flux-input" aria-label="{{ __('Programme') }}">
            <option value="">{{ __('All programmes') }}</option>
            @foreach($programs as $p)<option value="{{ $p->id }}">{{ $p->oge_code }} — {{ $p->name }}</option>@endforeach
        </select>
        <select wire:model.live="subprogram" class="flux-input" aria-label="{{ __('Subprogram') }}">
            <option value="">{{ __('All subprograms') }}</option>
            @foreach($subprograms as $s)<option value="{{ $s->id }}">{{ $s->code }} — {{ \Illuminate\Support\Str::limit($s->name, 40) }}</option>@endforeach
        </select>
        <select wire:model.live="location" class="flux-input" aria-label="{{ __('Location') }}">
            <option value="">{{ __('Everywhere') }}</option>
            @foreach($locations as $loc)<option value="{{ $loc }}">{{ $loc === 'Nationwide' ? __('Nationwide') : $loc }}</option>@endforeach
        </select>
        @if($showOrigin)
            <select wire:model.live="origin" class="flux-input" aria-label="{{ __('Existing service or new policy') }}">
                <option value="">{{ __('Existing services and new policies') }}</option>
                @foreach(\App\Models\Activity::ORIGINS as $value => $word)<option value="{{ $value }}">{{ __($word) }}</option>@endforeach
            </select>
        @endif
        <select wire:model.live="group" class="flux-input" aria-label="{{ __('Group by') }}">
            <option value="">{{ __('No grouping') }}</option>
            <option value="program">{{ __('By programme') }}</option>
            <option value="unit">{{ __('By unit') }}</option>
        </select>
    </div>

    {{-- Tabs --}}
    <div class="border-b border-zinc-200 flex flex-wrap items-center" role="tablist" aria-label="{{ __('Activity status') }}">
        @foreach($tabs as $key => $label)
            <button role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="setTab('{{ $key }}')"
                class="px-4 py-2.5 text-sm font-medium -mb-px border-b-2 transition-colors flex items-center gap-2 {{ $tab === $key ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-zinc-500 hover:text-zinc-800' }}">
                {{ $label }}
                @if($tabCounts[$key] !== null)
                    <span class="text-xs tabular-nums px-1.5 py-0.5 rounded-full {{ $tab === $key ? 'bg-indigo-50 text-indigo-700' : 'bg-zinc-100 text-zinc-600' }}">{{ $tabCounts[$key] }}</span>
                @endif
            </button>
        @endforeach
        <div class="ml-auto pb-1.5 flex items-center gap-2" x-show="selected.length" x-cloak>
            <span class="text-xs text-zinc-500" x-text="@js(__(':n selected')).replace(':n', selected.length)"></span>
            <x-ui.button size="sm" wire:click="advanceSelected" wire:confirm="{{ __('Move the selected activities on to the next step?') }}">{{ __('Move on') }}</x-ui.button>
        </div>
    </div>

    <x-ui.card padding="p-0">
        <x-ui.table :headers="['', __('Activity'), __('Budget'), __('Status'), __('Actions')]" :right="[2]">
            @forelse($activities as $a)
                @php $f = $flags[$a->id]; $key = $group === 'program' ? $a->program_id : ($group === 'unit' ? $a->directorate_id : null); @endphp
                @if($group && $key !== $lastGroup)
                    @php $lastGroup = $key; @endphp
                    <tr class="bg-zinc-50">
                        <td colspan="5" class="px-5 py-2 text-xs font-semibold text-zinc-600">
                            {{ $group === 'program' ? ($a->program->oge_code.' — '.$a->program->name) : ($a->directorate->code.' — '.$a->directorate->name) }}
                        </td>
                    </tr>
                @endif
                <tr wire:key="act-{{ $a->id }}" class="hover:bg-zinc-50/60">
                    <td class="pl-5 pr-0 py-3 w-8 align-top">
                        @if($f['next'])
                            <input type="checkbox" value="{{ $a->id }}" x-model="selected" class="mt-0.5 rounded border-zinc-300 text-indigo-600" aria-label="{{ __('Select') }}">
                        @endif
                    </td>
                    <td class="px-5 py-3 min-w-[24rem] max-w-xl">
                        <a href="{{ route('activities.show', $a) }}" wire:navigate class="text-sm font-medium text-zinc-900 hover:text-indigo-700 line-clamp-2">{{ $a->description }}</a>
                        <p class="mt-0.5 text-xs text-zinc-500">
                            <span class="font-medium text-zinc-700">{{ $a->code ?? '—' }}</span>
                            <span aria-hidden="true">·</span> {{ $a->directorate->parent?->code ? $a->directorate->parent->code.' › ' : '' }}{{ $a->directorate->code }}
                            <span aria-hidden="true">·</span> {{ $a->program->oge_code ?? '—' }}@if($a->subprogram)<span class="text-zinc-400">/</span>{{ $a->subprogram->code }}@endif
                            @php $quarters = collect($a->execution_quarters ?: [$a->quarter]); @endphp
                            @if($a->location)<span aria-hidden="true">·</span> {{ $a->location }}@endif
                            @if($a->origin && $a->origin !== \App\Models\Activity::ORIGIN_BASELINE)<span aria-hidden="true">·</span> <span class="font-medium text-indigo-700">{{ $a->policy_ref ?: __('New policy priority') }}</span>@endif
                            <span aria-hidden="true">·</span> {{ $quarters->count() === 4 ? 'Q1–Q4' : $quarters->map(fn ($q) => "Q$q")->implode(' ') }}
                            @if($a->priority && ! in_array($a->priority, ['Média', 'Medium'], true))<span aria-hidden="true">·</span> {{ $priority($a->priority) }}@endif
                        </p>
                    </td>
                    <td class="px-5 py-3 whitespace-nowrap text-sm num align-top">
                        <span class="font-semibold text-zinc-900">$ {{ number_format($a->total ?? 0) }}</span>
                        @if(! $a->total)<p class="text-xs text-amber-700">{{ __('No budget lines') }}</p>@endif
                    </td>
                    <td class="px-5 py-3 whitespace-nowrap align-top"><x-ui.status :status="$a->status" /></td>
                    <td class="px-5 py-3 whitespace-nowrap text-right align-top">
                        <div class="inline-flex items-center gap-3 text-sm">
                            @if($f['next'])
                                <a href="{{ route('activities.show', $a) }}" wire:navigate class="font-medium text-indigo-600 hover:text-indigo-700">{{ $actionLabel($f['next']) }}</a>
                            @endif
                            @if($f['edit'])
                                <a href="{{ route('activities.edit', $a) }}" wire:navigate class="font-medium text-zinc-600 hover:text-zinc-900">{{ __('Edit') }}</a>
                            @endif
                            @if($f['delete'])
                                <button type="button" wire:click="delete({{ $a->id }})" wire:confirm="{{ __('Delete this activity and its budget lines?') }}" class="font-medium text-rose-600 hover:text-rose-700">{{ __('Delete') }}</button>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5">
                        @if($filtersActive || $tab !== 'all')
                            <x-ui.empty-state :title="__('No activities match.')" :text="__('Clear the filters or choose another tab.')" />
                        @else
                            <x-ui.empty-state :title="__('No activities planned for :year yet.', ['year' => $year])"
                                :text="$canRoll ? __('Start from last year\'s plan with "Copy from :year", or add a new activity.', ['year' => $year - 1]) : __('Add the first activity, or import a CSV file from Administration.')" />
                        @endif
                    </td>
                </tr>
            @endforelse
        </x-ui.table>
        @if($activities->hasPages())
            <div class="px-6 py-4 border-t border-zinc-200">{{ $activities->links() }}</div>
        @endif
    </x-ui.card>
</div>
