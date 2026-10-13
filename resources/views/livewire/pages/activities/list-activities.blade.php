<?php

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\FiscalEnvelope;
use App\Models\Program;
use App\Policies\ActivityPolicy;
use App\Services\ActivityExporter;
use App\Services\ActivityRollForward;
use App\Services\ActivityWorkflow;
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
    #[Url(as: 'where')]
    public string $location = '';
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
        if (in_array($name, ['tab', 'search', 'unit', 'program', 'location', 'group'], true)) {
            $this->resetPage();
            $this->selected = [];
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->updated('tab');
    }

    private function year(): int
    {
        return FiscalEnvelope::getEffectiveYear();
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
        if ($this->program !== '') {
            $q->where('program_id', (int) $this->program);
        }
        if ($this->location !== '') {
            $q->where('location', $this->location);
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
        $query = $this->filtered();
        $name = "paa-{$this->year()}.csv";

        return response()->streamDownload(fn () => app(ActivityExporter::class)->write(fopen('php://output', 'w'), $query), $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
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

        $query = $this->filtered()->with(['directorate:id,code,name,parent_id', 'directorate.parent:id,parent_id,code', 'program:id,oge_code,name'])
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

        $money = fn ($statuses) => (float) \App\Models\LineItem::whereIn('activity_id', $this->visible()->whereIn('status', $statuses)->select('id'))->sum('estimated_amount');
        $all = (float) \App\Models\LineItem::whereIn('activity_id', $this->visible()->select('id'))->sum('estimated_amount');
        $previous = Activity::where('fiscal_year', $year - 1)->exists();

        return [
            'year' => $year,
            'activities' => $activities,
            'flags' => $rowFlags,
            'tabCounts' => $tabCounts,
            'totalPlanned' => $all,
            'totalApproved' => $money(self::GROUPS['approved']),
            'units' => Directorate::whereNull('parent_id')->orderBy('code')->get(['id', 'code', 'name', 'administration']),
            'programs' => Program::orderBy('oge_code')->get(['id', 'oge_code', 'name']),
            'locations' => config('paa.locations'),
            'canCreate' => $user->can('create', Activity::class),
            'canRoll' => $user->can('create_activities') && $previous && $tabCounts['all'] === 0,
            'locked' => FiscalEnvelope::where('fiscal_year', $year)->value('status') === FiscalEnvelope::STATUS_CLOSED,
            'tabs' => ['all' => __('All'), 'draft' => __('Drafts & returned'), 'review' => __('In review'), 'approved' => __('Approved'), 'mine' => __('Needs my action')],
            'filtersActive' => $this->unit !== '' || $this->program !== '' || $this->location !== '' || $this->search !== '',
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
    <div class="flex flex-col lg:flex-row lg:items-end justify-between gap-4">
        <div>
            <h1 class="page-title">{{ __('PAA Activities') }}</h1>
            <p class="page-subtitle">{{ __('Annual Activity Plan :year', ['year' => $year]) }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <x-ui.button variant="secondary" wire:click="export">{{ __('Export CSV') }}</x-ui.button>
            @if($canRoll && ! $locked)
                <x-ui.button variant="secondary" wire:click="rollForward"
                    wire:confirm="{{ __('Copy the :from plan into :to as drafts?', ['from' => $year - 1, 'to' => $year]) }}">{{ __('Copy from :year', ['year' => $year - 1]) }}</x-ui.button>
            @endif
            @if($canCreate && ! $locked)
                <x-ui.button href="{{ route('activities.create') }}" wire:navigate>+ {{ __('New activity') }}</x-ui.button>
            @endif
        </div>
    </div>

    @if($locked)
        <div class="alert alert-success">{{ __('The planning cycle for :year is closed; activities can no longer be changed.', ['year' => $year]) }}</div>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.kpi :label="__('Activities')" :value="number_format($tabCounts['all'])" :sub="__(':n in draft', ['n' => $tabCounts['draft']])" tone="zinc" />
        <x-ui.kpi :label="__('In review')" :value="number_format($tabCounts['review'])" :sub="__('Waiting for a decision')" tone="amber" />
        <x-ui.kpi :label="__('Approved')" :value="number_format($tabCounts['approved'])" :sub="__('Part of the plan for the CRO')" tone="emerald" />
        <x-ui.kpi :label="__('Planned budget')" :value="'$ ' . number_format($totalPlanned)" :sub="__('Approved: $ :n', ['n' => number_format($totalApproved)])" tone="indigo" />
    </div>

    {{-- Filters --}}
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-[1fr_12rem_14rem_11rem_9rem]">
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
        <select wire:model.live="location" class="flux-input" aria-label="{{ __('Location') }}">
            <option value="">{{ __('Everywhere') }}</option>
            @foreach($locations as $loc)<option value="{{ $loc }}">{{ $loc === 'Nationwide' ? __('Nationwide') : $loc }}</option>@endforeach
        </select>
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
        <x-ui.table :headers="['', __('Activity'), __('Where / when'), __('Budget'), __('Status'), __('Actions')]">
            @forelse($activities as $a)
                @php $f = $flags[$a->id]; $key = $group === 'program' ? $a->program_id : ($group === 'unit' ? $a->directorate_id : null); @endphp
                @if($group && $key !== $lastGroup)
                    @php $lastGroup = $key; @endphp
                    <tr class="bg-zinc-50">
                        <td colspan="6" class="px-6 py-2 text-xs font-semibold text-zinc-600">
                            {{ $group === 'program' ? ($a->program->oge_code.' — '.$a->program->name) : ($a->directorate->code.' — '.$a->directorate->name) }}
                        </td>
                    </tr>
                @endif
                <tr wire:key="act-{{ $a->id }}" class="hover:bg-zinc-50/60">
                    <td class="pl-6 pr-0 py-4 w-8">
                        @if($f['next'])
                            <input type="checkbox" value="{{ $a->id }}" x-model="selected" class="rounded border-zinc-300 text-indigo-600" aria-label="{{ __('Select') }}">
                        @endif
                    </td>
                    <td class="px-6 py-4 min-w-[22rem]">
                        <a href="{{ route('activities.show', $a) }}" wire:navigate class="text-sm font-semibold text-zinc-900 hover:text-indigo-600">{{ $a->description }}</a>
                        <p class="mt-1 text-xs text-zinc-500">
                            <span class="font-medium text-zinc-600">{{ $a->code ?? '—' }}</span>
                            · {{ $a->directorate->parent?->code ? $a->directorate->parent->code.' › ' : '' }}{{ $a->directorate->code }}
                            · {{ $a->program->oge_code ?? '—' }}
                            · {{ $priority($a->priority) }}
                        </p>
                    </td>
                    <td class="px-6 py-4 text-xs text-zinc-600 whitespace-nowrap">
                        <p>{{ $a->location ?: '—' }}</p>
                        <p class="mt-1 text-zinc-400">{{ collect($a->execution_quarters ?: [$a->quarter])->map(fn ($q) => "Q$q")->implode(' ') }}</p>
                    </td>
                    <td class="px-6 py-4 text-sm tabular-nums whitespace-nowrap">
                        <span class="font-semibold text-zinc-900">$ {{ number_format($a->total ?? 0) }}</span>
                        @if(! $a->total)<p class="text-xs text-amber-600">{{ __('No budget lines') }}</p>@endif
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap"><x-ui.badge :variant="$badge($a->status)">{{ __($a->status) }}</x-ui.badge></td>
                    <td class="px-6 py-4 whitespace-nowrap text-right">
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
                    <td colspan="6" class="px-6 py-14 text-center">
                        @if($filtersActive || $tab !== 'all')
                            <p class="text-sm font-medium text-zinc-900">{{ __('No activities match.') }}</p>
                            <p class="mt-1 text-sm text-zinc-500">{{ __('Clear the filters or choose another tab.') }}</p>
                        @else
                            <p class="text-sm font-medium text-zinc-900">{{ __('No activities planned for :year yet.', ['year' => $year]) }}</p>
                            <p class="mt-1 text-sm text-zinc-500">
                                {{ $canRoll ? __('Start from last year\'s plan with "Copy from :year", or add a new activity.', ['year' => $year - 1]) : __('Add the first activity, or import a CSV file from Administration.') }}
                            </p>
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
