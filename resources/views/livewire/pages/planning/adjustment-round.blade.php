<?php

use App\Models\Activity;
use App\Models\AdjustmentRound;
use App\Models\AdjustmentRoundUnit;
use App\Models\Directorate;
use App\Services\AdjustmentRounds;
use App\Services\BudgetDecisions;
use App\Services\PlanningWindow;
use App\Support\FiscalYear;
use App\Support\PlanOptions;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * The adjustment round of the year: after a budget decision, each directorate revises the activities it chooses so
 * that the plan follows the decision. An administrator opens the round (deadline, instructions, a target per
 * directorate) and closes it; a directorate revises its activities here and its head confirms when it is done.
 * Those who see every plan see every directorate; everyone else sees their own.
 */
new class extends Component {
    use WithPagination;

    #[Url]
    public ?int $unit = null;

    public string $search = '';

    // opening a round
    public string $closesOn = '';
    public string $instructions = '';
    public array $targets = [];
    public bool $allowsNewActivities = false;

    // confirming and closing
    public string $remark = '';
    public string $closingNote = '';

    public function mount(): void
    {
        abort_unless(PlanOptions::current()->budgetDecisions, 404);
        abort_unless(auth()->user()->can('view_paa'), 403);
        $this->closesOn = now()->addWeeks(2)->toDateString();
        $this->targets = array_map(fn ($v) => (string) $v, app(AdjustmentRounds::class)->suggestedTargets(FiscalYear::current()));
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedUnit(): void
    {
        $this->resetPage();
        $this->remark = '';
    }

    /** The open round of the year, or else the latest closed one (to read how it ended). */
    private function round(): ?AdjustmentRound
    {
        return AdjustmentRound::with('decision')->where('fiscal_year', FiscalYear::current())
            ->orderByRaw('closed_at IS NULL DESC')->latest('id')->first();
    }

    public function open(AdjustmentRounds $rounds): void
    {
        $this->validate(['closesOn' => 'required|date', 'instructions' => 'nullable|string|max:4000', 'targets.*' => 'nullable|numeric|min:0']);
        try {
            $rounds->open(auth()->user(), FiscalYear::current(), $this->closesOn, $this->instructions, $this->targets, $this->allowsNewActivities);
        } catch (\DomainException $e) {
            $this->addError('targets', $e->getMessage());

            return;
        }
        $this->dispatch('toast', message: __('The adjustment round is open. The directorates have been told.'), type: 'success');
    }

    /** Start the revision of an activity for the round, then open it to change its amounts. */
    public function revise(int $activityId, AdjustmentRounds $rounds)
    {
        $round = $this->round();
        abort_unless($round, 404);
        try {
            $rounds->revise(auth()->user(), $round, Activity::findOrFail($activityId));
        } catch (\DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return null;
        }

        return $this->redirect(route('activities.edit', $activityId), navigate: true);
    }

    public function confirm(AdjustmentRounds $rounds): void
    {
        $round = $this->round();
        $row = $round?->units()->where('directorate_id', $this->unit)->first();
        abort_unless($row, 404);
        try {
            $rounds->confirm(auth()->user(), $row, $this->remark);
        } catch (\DomainException $e) {
            $this->addError('remark', $e->getMessage());

            return;
        }
        $this->remark = '';
        $this->dispatch('toast', message: __('The adjustment of the directorate is confirmed.'), type: 'success');
    }

    public function close(AdjustmentRounds $rounds): void
    {
        $round = $this->round();
        abort_unless($round, 404);
        $rounds->close(auth()->user(), $round, $this->closingNote);
        $this->closingNote = '';
        $this->dispatch('toast', message: __('The adjustment round is closed.'), type: 'success');
    }

    public function with(AdjustmentRounds $rounds): array
    {
        $year = FiscalYear::current();
        $user = auth()->user();
        $round = $this->round();
        $data = [
            'year' => $year, 'round' => $round, 'canOpen' => $rounds->canOpen($user), 'decision' => app(BudgetDecisions::class)->latest($year),
            'directorates' => Directorate::whereIn('id', array_keys($this->targets))->orderBy('code')->get(['id', 'code', 'name']),
        ];

        if (! $round) {
            return $data;
        }

        $visible = $rounds->visibleUnitIds($user, $round);
        $progress = array_intersect_key($rounds->progress($round), array_flip($visible));
        if (! $this->unit || ! isset($progress[$this->unit])) {
            $this->unit = array_key_first($progress);
        }
        $selected = $this->unit ? $progress[$this->unit] : null;

        return $data + [
            'progress' => $progress,
            'wide' => $user->isAdministrator() || $user->can('view_all_paa'),
            'selected' => $selected,
            'canConfirm' => $selected && $rounds->canConfirm($user, $selected['unit']),
            'activities' => $selected ? $this->activities($round, $this->unit) : null,
            'newActivities' => $selected ? $rounds->newActivities($round)->whereIn('a.directorate_id', app(PlanningWindow::class)->subtree($this->unit))->orderBy('a.id')->get() : collect(),
            'canAdd' => $selected && $round->isOpen() && $round->allows_new_activities && ! $selected['unit']->confirmed_at && $user->can('create_activities'),
            'reason' => $rounds->reasonFor($round),
        ];
    }

    /** The approved activities of the directorate, biggest first, with what the round has done with each. */
    private function activities(AdjustmentRound $round, int $root)
    {
        $inForce = DB::table('adjusted_budgets as ab')->join('line_items as li', 'li.id', '=', 'ab.line_item_id')
            ->whereColumn('li.activity_id', 'activities.id')->selectRaw('COALESCE(SUM(ab.approved_amount), 0)');
        $q = trim($this->search);

        $page = Activity::query()
            ->where('fiscal_year', $round->fiscal_year)->where('version', '>', 0)
            ->whereIn('directorate_id', app(PlanningWindow::class)->subtree($root))
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s->where('description', 'like', "%{$q}%")->orWhere('code', 'like', "%{$q}%")))
            ->with('directorate:id,code,name')
            ->select('activities.*')->selectSub($inForce, 'in_force')
            ->orderByDesc('in_force')->orderBy('id')
            ->paginate(20);

        $inRound = $round->activities()->whereIn('activity_id', $page->pluck('id'))->get()->keyBy('activity_id');
        $rounds = app(AdjustmentRounds::class);
        $page->getCollection()->each(function (Activity $a) use ($inRound, $round, $rounds) {
            $a->setAttribute('round_entry', $inRound->get($a->id));
            $a->setAttribute('can_revise', $rounds->canRevise(auth()->user(), $round, $a));
        });

        return $page;
    }
}; ?>

@php
    $money = fn ($v) => '$ '.number_format((float) $v, 0);
    $states = [
        'not_started' => [__('Not started'), 'bg-zinc-100 text-zinc-700'],
        'revising' => [__('Revisions in the approval chain'), 'bg-amber-50 text-amber-800'],
        'ready' => [__('Ready to confirm'), 'bg-indigo-50 text-indigo-800'],
        'confirmed' => [__('Confirmed'), 'bg-emerald-50 text-emerald-800'],
    ];
@endphp

<div class="space-y-6">
    <x-ui.page-header :title="__('Adjustment round')" :subtitle="__('After a budget decision, each directorate revises the activities it chooses so that the plan of :year follows the decision. Every revision goes through the usual approval.', ['year' => $year])">
        <x-ui.button variant="secondary" size="md" shadow="none" href="{{ route('planning.budget-decisions') }}" wire:navigate>{{ __('Budget decisions') }}</x-ui.button>
    </x-ui.page-header>

    {{-- ── no round yet: an administrator opens one ── --}}
    @if(! $round || (! $round->isOpen() && $canOpen))
        @if(! $canOpen)
            @if(! $round)
                <x-ui.card padding="p-0"><x-ui.empty-state :title="__('No adjustment round for :year', ['year' => $year])" :description="__('When the budget decided for the institution changes, the planning office opens a round here and each directorate is told what to adjust.')" /></x-ui.card>
            @endif
        @elseif(! $decision)
            <x-ui.card padding="p-0"><x-ui.empty-state :title="__('Record the budget decision first')" :description="__('A round adjusts the plan to a budget decision. Record the decision, then come back to open the round.')" /></x-ui.card>
        @else
            <x-ui.card :title="__('Open an adjustment round')" :description="__('Adjusts the plan to the latest decision: :decision of :date.', ['decision' => $decision->stageLabel().($decision->reference ? ' ('.$decision->reference.')' : ''), 'date' => $decision->decided_on->format('d/m/Y')])" padding="p-5">
                <form wire:submit="open" class="space-y-4">
                    <div class="grid gap-3 sm:grid-cols-[12rem_1fr]">
                        <div>
                            <label for="ar-date" class="field-label">{{ __('Deadline') }}</label>
                            <input id="ar-date" type="date" wire:model="closesOn" class="flux-input">
                            @error('closesOn')<p class="field-error">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="ar-instr" class="field-label">{{ __('Instructions to the directorates') }}</label>
                            <textarea id="ar-instr" rows="2" wire:model="instructions" class="flux-input" placeholder="{{ __('For example: protect salaries; postpone new equipment to next year.') }}"></textarea>
                        </div>
                    </div>
                    <div>
                        <p class="field-label">{{ __('Target reduction per directorate') }}</p>
                        <p class="mb-2 text-xs text-zinc-500">{{ __('Suggested: the difference between the plan and the decision, shared in proportion to each directorate\'s approved plan. Change any amount; zero asks for no reduction.') }}</p>
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach($directorates as $d)
                                <div wire:key="ar-t-{{ $d->id }}">
                                    <label for="ar-t-{{ $d->id }}" class="text-xs text-zinc-600">{{ $d->code }} {{ $d->name }}</label>
                                    <input id="ar-t-{{ $d->id }}" type="number" min="0" step="1" wire:model="targets.{{ $d->id }}" class="flux-input tabular-nums">
                                </div>
                            @endforeach
                        </div>
                        @error('targets')<p class="field-error">{{ $message }}</p>@enderror
                        @error('targets.*')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <label class="flex items-start gap-3 text-sm text-zinc-700">
                        <input type="checkbox" wire:model="allowsNewActivities" class="mt-0.5 rounded border-zinc-300 text-indigo-600">
                        <span><span class="font-medium text-zinc-900">{{ __('Directorates may add new activities in this round') }}</span><br>
                            <span class="text-zinc-600">{{ __('For a supplementary budget with new money: until the round closes (or the directorate confirms), the directorates can create new activities although their plan is finalized, and submit and approve them outside the calendar.') }}</span></span>
                    </label>
                    <div class="flex justify-end"><x-ui.button type="submit">{{ __('Open the round') }}</x-ui.button></div>
                </form>
            </x-ui.card>
        @endif
    @endif

    {{-- ── the round ── --}}
    @if($round)
        <x-ui.card padding="p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0 space-y-1 text-sm">
                    <p class="font-semibold text-zinc-900">{{ $reason }}</p>
                    <p class="text-zinc-600">
                        @if($round->isOpen())
                            {{ __('Open until :date', ['date' => $round->closes_on->format('d/m/Y')]) }}
                            @if($round->closes_on->isPast())<span class="ml-1 font-medium text-rose-700">· {{ __('the deadline has passed') }}</span>@endif
                        @else
                            {{ __('Closed on :date', ['date' => $round->closed_at->format('d/m/Y')]) }}@if($round->closing_note) · {{ $round->closing_note }}@endif
                        @endif
                    </p>
                    @if($round->allows_new_activities)<p class="text-zinc-700">{{ __('New activities may be added until the round closes.') }}</p>@endif
                    @if($round->instructions)<p class="whitespace-pre-line text-zinc-700">{{ $round->instructions }}</p>@endif
                </div>
                @if($round->isOpen() && $canOpen)
                    <form wire:submit="close" class="flex flex-wrap items-end gap-2">
                        <div>
                            <label for="ar-close" class="field-label">{{ __('Closing note') }}</label>
                            <input id="ar-close" type="text" wire:model="closingNote" class="flux-input !w-64">
                        </div>
                        <x-ui.button variant="secondary" type="submit" wire:confirm="{{ __('Close the round? Directorates can still revise activities, but no longer for this round.') }}">{{ __('Close the round') }}</x-ui.button>
                    </form>
                @endif
            </div>
        </x-ui.card>

        @if(count($progress) === 0)
            <x-ui.card padding="p-0"><x-ui.empty-state :title="__('Your directorate is not in this round')" :description="__('Only directorates with an approved plan when the round opened take part.')" /></x-ui.card>
        @else
            <x-ui.card :title="__('Directorates')" :description="__('Reduced: what revisions took off the activities the directorate already had. Added: the new activities of the round once approved. Proposed: what revisions still in the approval chain would take off.')" padding="p-0">
                <x-ui.table :headers="[__('Directorate'), __('Target'), __('Reduced'), __('Added'), __('Proposed'), __('Still to reduce'), __('State')]" :right="[1, 2, 3, 4, 5]" class="text-sm">
                    @foreach($progress as $id => $p)
                        <tr wire:key="ar-p-{{ $id }}" @class(['bg-indigo-50/50' => $id === $unit])>
                            <td class="min-w-[16rem] px-5 py-2.5">
                                <button type="button" wire:click="$set('unit', {{ $id }})" class="text-left font-medium text-indigo-700 hover:underline">{{ $p['unit']->directorate?->code }}</button>
                                <span class="text-zinc-700">{{ $p['unit']->directorate?->name }}</span>
                            </td>
                            <td class="px-5 py-2.5 num whitespace-nowrap">{{ $p['target'] > 0 ? $money($p['target']) : '—' }}</td>
                            <td class="px-5 py-2.5 num whitespace-nowrap">{{ $money($p['reduced']) }}</td>
                            <td class="px-5 py-2.5 num whitespace-nowrap">{{ $p['added'] >= 0.5 ? $money($p['added']) : '—' }}@if($p['adding'] >= 0.5)<span class="block text-xs text-zinc-500">+ {{ $money($p['adding']) }} {{ __('on the way') }}</span>@endif</td>
                            <td class="px-5 py-2.5 num whitespace-nowrap text-zinc-600">{{ abs($p['proposed']) >= 0.5 ? $money($p['proposed']) : '—' }}</td>
                            <td class="px-5 py-2.5 num whitespace-nowrap font-medium {{ $p['remaining'] > 0.5 ? 'text-rose-700' : 'text-emerald-700' }}">{{ $p['remaining'] > 0.5 ? $money($p['remaining']) : __('Reached') }}</td>
                            <td class="px-5 py-2.5"><span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium {{ $states[$p['state']][1] }}">{{ $states[$p['state']][0] }}</span></td>
                        </tr>
                    @endforeach
                    @if($wide && count($progress) > 1)
                        @php $sum = fn ($k) => array_sum(array_column($progress, $k)); @endphp
                        <tr class="bg-zinc-50 font-semibold">
                            <td class="px-5 py-2.5">{{ __('Total') }}</td>
                            <td class="px-5 py-2.5 num whitespace-nowrap">{{ $money($sum('target')) }}</td>
                            <td class="px-5 py-2.5 num whitespace-nowrap">{{ $money($sum('reduced')) }}</td>
                            <td class="px-5 py-2.5 num whitespace-nowrap">{{ $money($sum('added')) }}</td>
                            <td class="px-5 py-2.5 num whitespace-nowrap">{{ $money($sum('proposed')) }}</td>
                            <td class="px-5 py-2.5 num whitespace-nowrap">{{ $money($sum('remaining')) }}</td>
                            <td class="px-5 py-2.5 text-xs font-normal text-zinc-600">{{ __(':n of :m confirmed', ['n' => count(array_filter($progress, fn ($p) => $p['state'] === 'confirmed')), 'm' => count($progress)]) }}</td>
                        </tr>
                    @endif
                </x-ui.table>
            </x-ui.card>

            {{-- ── one directorate: its activities and its confirmation ── --}}
            @if($selected)
                @php $u = $selected['unit']; @endphp
                <x-ui.card :title="$u->directorate?->code.' · '.$u->directorate?->name" :description="__('Choose the activities to revise. Each one goes back to draft with the round as the reason; change its amounts and send it through the approval chain.')" padding="p-0">
                    <div class="flex flex-wrap items-end justify-between gap-3 border-b border-zinc-100 px-5 py-3">
                        <div>
                            <label for="ar-q" class="sr-only">{{ __('Search') }}</label>
                            <input id="ar-q" type="search" wire:model.live.debounce.300ms="search" class="flux-input sm:w-72" placeholder="{{ __('Search an activity') }}">
                        </div>
                        <p class="text-sm text-zinc-600">{{ __('Target') }}: <span class="font-semibold tabular-nums text-zinc-900">{{ $selected['target'] > 0 ? $money($selected['target']) : '—' }}</span> · {{ __('Reduced') }}: <span class="font-semibold tabular-nums text-zinc-900">{{ $money($selected['reduced']) }}</span></p>
                    </div>
                    @if($activities->isEmpty())
                        <x-ui.empty-state :title="__('No approved activity')" :description="$search !== '' ? __('Nothing matches the search.') : __('This directorate has no approved activity in :year.', ['year' => $year])" />
                    @else
                        <x-ui.table :headers="[__('Activity'), __('Unit'), __('Approved plan amount'), __('In this round')]" :right="[2]" class="text-sm">
                            @foreach($activities as $a)
                                <tr wire:key="ar-a-{{ $a->id }}">
                                    <td class="px-5 py-2.5"><a href="{{ route('activities.show', $a) }}" wire:navigate class="text-indigo-700 hover:underline">{{ $a->code }}</a> <span class="text-zinc-800">{{ str($a->description)->limit(80) }}</span></td>
                                    <td class="px-5 py-2.5 text-zinc-600">{{ $a->directorate?->code }}</td>
                                    <td class="px-5 py-2.5 num whitespace-nowrap">{{ $money($a->in_force) }}</td>
                                    <td class="px-5 py-2.5">
                                        @if($a->status !== Activity::STATUS_APPROVED)
                                            <span class="text-xs font-medium text-amber-800">{{ $a->round_entry ? __('Being revised for the round') : __('Being revised') }}</span>
                                            <a href="{{ route('activities.edit', $a) }}" wire:navigate class="ml-2 text-xs font-semibold text-indigo-700 hover:underline">{{ __('Edit') }}</a>
                                        @elseif($a->can_revise)
                                            <button type="button" wire:click="revise({{ $a->id }})" wire:confirm="{{ __('Revise this activity for the round? It goes back to draft and through the whole approval chain; the approved amounts stay in force until the revision is approved.') }}" class="whitespace-nowrap text-xs font-semibold text-indigo-700 hover:underline">{{ __('Revise for this round') }}</button>
                                            @if($a->round_entry)<span class="ml-2 text-xs text-emerald-700">{{ $a->round_entry->is_new ? __('New in this round') : __('Revised: $ :from → $ :to', ['from' => number_format((float) $a->round_entry->total_before, 0), 'to' => number_format((float) $a->in_force, 0)]) }}</span>@endif
                                        @elseif($a->round_entry)
                                            <span class="text-xs text-emerald-700">{{ $a->round_entry->is_new ? __('New in this round') : __('Revised: $ :from → $ :to', ['from' => number_format((float) $a->round_entry->total_before, 0), 'to' => number_format((float) $a->in_force, 0)]) }}</span>
                                        @else
                                            <span class="text-xs text-zinc-400">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </x-ui.table>
                        @if($activities->hasPages())<div class="border-t border-zinc-200 px-5 py-3">{{ $activities->links() }}</div>@endif
                    @endif

                    @if($canAdd || $newActivities->isNotEmpty())
                        <div class="border-t border-zinc-200 px-5 py-4">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <h3 class="text-sm font-semibold text-zinc-900">{{ __('New activities of the round') }}</h3>
                                @if($canAdd)<x-ui.button size="sm" shadow="none" href="{{ route('activities.create') }}" wire:navigate>+ {{ __('New activity') }}</x-ui.button>@endif
                            </div>
                            @if($newActivities->isEmpty())
                                <p class="mt-1 text-sm text-zinc-500">{{ __('None yet. A new activity goes through the usual approval; once approved, its amount counts as added.') }}</p>
                            @else
                                <ul class="mt-2 divide-y divide-zinc-100 text-sm">
                                    @foreach($newActivities as $n)
                                        <li class="flex flex-wrap items-center justify-between gap-2 py-1.5" wire:key="ar-n-{{ $n->id }}">
                                            <span><a href="{{ route('activities.show', $n->id) }}" wire:navigate class="text-indigo-700 hover:underline">{{ $n->code }}</a> {{ str($n->description)->limit(80) }}</span>
                                            <span class="tabular-nums text-zinc-700">{{ $money($n->status === Activity::STATUS_APPROVED ? $n->in_force : $n->draft) }} · <x-ui.status :status="$n->status" /></span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endif

                    <div class="border-t border-zinc-200 bg-zinc-50 px-5 py-4 text-sm">
                        @if($u->confirmed_at)
                            <p class="text-emerald-800"><span class="font-semibold">{{ __('Confirmed') }}</span> {{ __('by :name on :date', ['name' => $u->confirmer?->name ?? '—', 'date' => $u->confirmed_at->format('d/m/Y')]) }}@if($u->remark) · {{ $u->remark }}@endif</p>
                        @elseif($canConfirm)
                            <form wire:submit="confirm" class="flex flex-wrap items-end gap-3">
                                <div class="min-w-0 flex-1">
                                    <label for="ar-remark" class="field-label">{{ __('Remark') }} @if($selected['remaining'] > 0.5)<span class="font-normal text-zinc-500">({{ __('required: the target is not reached') }})</span>@endif</label>
                                    <input id="ar-remark" type="text" wire:model="remark" class="flux-input">
                                    @error('remark')<p class="field-error">{{ $message }}</p>@enderror
                                </div>
                                <x-ui.button type="submit">{{ __('Confirm the adjustment') }}</x-ui.button>
                            </form>
                        @elseif($round->isOpen())
                            <p class="text-zinc-600">{{ __('The head of the directorate confirms here when its adjustment is complete.') }}</p>
                        @else
                            <p class="text-zinc-600">{{ __('Not confirmed before the round closed.') }}</p>
                        @endif
                    </div>
                </x-ui.card>
            @endif
        @endif
    @endif
</div>
