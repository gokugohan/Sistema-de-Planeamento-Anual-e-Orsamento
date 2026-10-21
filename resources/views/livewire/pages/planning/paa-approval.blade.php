<?php

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\LineItem;
use App\Models\User;
use App\Notifications\SystemNotification;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * Technical review and approval of the activities submitted for the selected fiscal year.
 *
 * Flow: Submitted → (Reviewed →) Validated → Approved. A reviewer can also return an activity
 * to its unit for correction. Queues group the statuses by who has to act next.
 */
new class extends Component {
    use WithPagination;

    /** Planning was finalized or reopened (by the planning-window card): refresh. */
    #[On('planning-changed')]
    public function planningChanged(): void
    {
    }

    /** Who works here: those who decide on activities (a head, a named approver or certifier) and administrators. */
    private function mayApprove($user): bool
    {
        return $user->isAdministrator() || $user->isDirector() || app(\App\Services\ApproverLists::class)->unitIdsOf($user) !== [];
    }

    public function mount(): void
    {
        abort_unless($this->mayApprove(auth()->user()), 403);
    }

    #[\Livewire\Attributes\Url(as: 'queue')]
    public string $queue = 'review';  // review | approval | approved | returned | all
    public $unit = '';                // top-level unit filter ('' = all)

    /** @var array<int, string> activities ticked for a bulk approval */
    public array $selected = [];
    public ?array $bulkSummary = null;   // what the confirmation shows: counted by the server, not the browser
    public ?array $result = null;        // what the last bulk approval did, including what it skipped
    public string $note = '';            // optional note kept with every approval of the batch
    public string $reason = '';          // required reason when returning an activity
    public ?int $returnId = null;

    private const QUEUES = [
        'review' => [Activity::STATUS_SUBMITTED, Activity::STATUS_REVIEWED],
        'approval' => [Activity::STATUS_VALIDATED, Activity::STATUS_WAITING_APPROVAL],
        'approved' => [Activity::STATUS_APPROVED],
        'returned' => [Activity::STATUS_DRAFT, Activity::STATUS_REJECTED],
    ];

    public function setQueue(string $queue): void
    {
        $this->queue = array_key_exists($queue, self::QUEUES) || $queue === 'all' ? $queue : 'review';
        $this->selected = [];
        $this->resetPage();
    }

    public function updatedUnit(): void
    {
        $this->selected = [];
        $this->resetPage();
    }

    private function year(): int
    {
        return \App\Support\FiscalYear::current();
    }

    private function unitIds(): ?array
    {
        if ($this->unit === '') {
            return null;
        }
        $root = Directorate::find((int) $this->unit);

        return $root ? $root->getSelfAndDescendantIds() : [0];
    }

    private function scope()
    {
        $ids = $this->unitIds();
        // only the units this user works in; administrators and those who see every plan have no limit
        $visible = app(\App\Services\PlanCycle::class)->scopeFor(auth()->user());

        return Activity::query()->where('fiscal_year', $this->year())
            ->when($visible !== null, fn ($q) => $q->whereIn('directorate_id', $visible ?: [0]))
            ->when($ids, fn ($q) => $q->whereIn('directorate_id', $ids));
    }

    /** The activities that wait for the final approval, within the unit filter (at most one batch). */
    private function readyIds(?array $unitIds = null)
    {
        return $this->scope()
            ->whereIn('status', self::QUEUES['approval'])
            ->when($unitIds, fn ($q) => $q->whereIn('directorate_id', $unitIds))
            ->orderBy('directorate_id')->orderBy('id')
            ->limit(\App\Services\ActivityWorkflow::BULK_LIMIT)
            ->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    /** Tick everything that is ready in the current filter (up to one batch). */
    public function selectAllReady(): void
    {
        $this->selected = $this->readyIds();
    }

    /** Tick everything that is ready for one top-level unit, to review it before approving. */
    public function selectUnit($rootDirectorateId): void
    {
        $this->selected = $this->readyIds(Directorate::findOrFail($rootDirectorateId)->getSelfAndDescendantIds());
    }

    /** Count and add up what is ticked, so the confirmation shows facts the server worked out. */
    public function prepareBulk(): void
    {
        $workflow = app(\App\Services\ActivityWorkflow::class);
        $user = auth()->user();
        abort_unless($this->mayApprove($user), 403);

        $activities = $this->scope()->with('directorate:id,code')->withSum('lineItems as total', 'estimated_amount')
            ->whereIn('id', array_map('intval', $this->selected))->get();
        $ready = $activities->filter(fn ($a) => $workflow->canApprove($user, $a));

        if ($ready->isEmpty()) {
            $this->bulkSummary = null;
            $this->dispatch('toast', message: __('None of the selected activities can be approved by you right now.'), type: 'error');

            return;
        }

        $this->bulkSummary = [
            'count' => $ready->count(),
            'total' => (float) $ready->sum('total'),
            'units' => $ready->groupBy(fn ($a) => $a->directorate->code)->map->count()->all(),
            'left_out' => $activities->count() - $ready->count(),
        ];
    }

    public function approveSelected(): void
    {
        abort_unless($this->mayApprove(auth()->user()), 403);

        try {
            $this->result = app(\App\Services\ActivityWorkflow::class)->approveMany(auth()->user(), $this->selected, $this->note);
        } catch (\DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        $this->reset('selected', 'bulkSummary', 'note');
        $this->dispatch('toast', message: trans_choice(':n activity approved.|:n activities approved.', count($this->result['approved']), ['n' => count($this->result['approved'])]), type: count($this->result['approved']) ? 'success' : 'error');
    }

    public function cancelBulk(): void
    {
        $this->reset('bulkSummary', 'note');
    }

    public function dismissResult(): void
    {
        $this->result = null;
    }

    /** One approval, written through the same rules (and trail) as everything else. */
    public function approve($id)
    {
        abort_unless($this->mayApprove(auth()->user()), 403);

        app(\App\Services\ActivityWorkflow::class)->approve(auth()->user(), Activity::findOrFail($id));

        $this->dispatch('toast', message: __('Activity approved successfully.'), type: 'success');
    }

    /** Return one activity to its unit; the reason is required and the unit will read it. */
    public function returnActivity(): void
    {
        abort_unless($this->mayApprove(auth()->user()), 403);

        try {
            app(\App\Services\ActivityWorkflow::class)->sendBack(auth()->user(), Activity::findOrFail($this->returnId), $this->reason);
        } catch (\DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        $this->reset('reason', 'returnId');
        $this->dispatch('toast', message: __('Activity returned to department for correction.'), type: 'success');
    }

    public function review($id)
    {
        abort_unless($this->mayApprove(auth()->user()), 403);
        $activity = Activity::findOrFail($id);
        abort_unless(in_array($activity->status, self::QUEUES['review'], true), 422);

        // the same rules as everywhere else: scope, lists, interim, signing in order, budget confirmation, parent validation
        try {
            $newStatus = app(\App\Services\ActivityWorkflow::class)->advance(auth()->user(), $activity);
        } catch (\DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        $this->dispatch('toast', message: __('Activity updated to :status.', ['status' => __($newStatus)]), type: 'success');
    }

    public function with(): array
    {
        $year = $this->year();
        $statuses = $this->queue === 'all' ? null : self::QUEUES[$this->queue];

        $counts = $this->scope()->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');
        $queueCounts = collect(self::QUEUES)->map(fn ($s) => (int) collect($s)->sum(fn ($x) => $counts[$x] ?? 0))->all();
        $queueCounts['all'] = (int) $counts->sum();

        $activities = $this->scope()
            ->when($statuses, fn ($q) => $q->whereIn('status', $statuses))
            ->with(['directorate.parent', 'program:id,oge_code,name', 'lineItems:id,activity_id,estimated_amount'])
            ->withSum('lineItems as total', 'estimated_amount')
            ->orderBy('directorate_id')->orderBy('id')
            ->paginate(20);

        $money = fn (array $s) => (float) LineItem::whereIn('activity_id', $this->scope()->whereIn('status', $s)->select('id'))->sum('estimated_amount');

        return [
            'year' => $year,
            'activities' => $activities,
            'queueCounts' => $queueCounts,
            'pendingReview' => $money(self::QUEUES['review']),
            'awaitingApproval' => $money(self::QUEUES['approval']),
            'approvedPlan' => $money(self::QUEUES['approved']),
            'locked' => false,
            'rootUnits' => Directorate::whereNull('parent_id')->orderBy('code')->get(['id', 'code', 'name']),
            'canApprove' => $this->mayApprove(auth()->user()),
            'queues' => [
                'review' => __('To review'), 'approval' => __('Ready for approval'), 'approved' => __('Approved'),
                'returned' => __('Returned / draft'), 'all' => __('All'),
            ],
        ];
    }
}; ?>

<div class="space-y-6" x-data="{ selected: $wire.$entangle('selected') }">
    <x-calendar-alerts :year="$year" />
    <x-plan-complete-notice :year="$year" />
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="page-title">{{ __('PAA Technical Review') }}</h1>
            <p class="page-subtitle">{{ __('Review and approve the activities submitted for :year.', ['year' => $year]) }}</p>
        </div>
        <div>
            <label for="unit" class="block text-xs font-medium text-zinc-500 mb-1">{{ __('Unit') }}</label>
            <select id="unit" wire:model.live="unit" class="flux-input !w-72 !py-2">
                <option value="">{{ __('All units') }}</option>
                @foreach($rootUnits as $d)
                    <option value="{{ $d->id }}">{{ $d->code }} — {{ $d->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <livewire:pages.planning.planning-window :year="$year" :key="'planning-'.$year" />

    @if($locked)
        <div class="alert alert-success !items-start">
            <div>
                <p class="font-semibold">{{ __('PAA Cycle Finalized') }}</p>
                <p class="text-xs font-normal mt-1">{{ __('The PAA for :year is officially approved and the planning cycle is locked.', ['year' => $year]) }}
                    <a href="{{ route('monitoring.index') }}" class="underline font-medium">{{ __('View Monitoring') }}</a></p>
            </div>
        </div>
    @endif

    @if($result)
        <div class="rounded-lg border border-zinc-200 bg-white p-4 shadow-card" role="status">
            <div class="flex items-start justify-between gap-4">
                <p class="text-sm font-semibold text-zinc-900">
                    {{ trans_choice(':n activity approved.|:n activities approved.', count($result['approved']), ['n' => count($result['approved'])]) }}
                    @if(count($result['skipped']))
                        <span class="font-normal text-amber-800">{{ trans_choice(':n was left out.|:n were left out.', count($result['skipped']), ['n' => count($result['skipped'])]) }}</span>
                    @endif
                </p>
                <button type="button" wire:click="dismissResult" class="text-sm font-medium text-zinc-600 hover:text-zinc-900">{{ __('Dismiss') }}</button>
            </div>
            @if(count($result['skipped']))
                <ul class="mt-3 divide-y divide-zinc-100 text-sm">
                    @foreach($result['skipped'] as $skip)
                        <li class="flex flex-wrap gap-x-3 py-1.5"><span class="font-medium text-zinc-800">{{ str($skip['description'] ?? '#'.$skip['id'])->limit(70) }}</span><span class="text-zinc-600">{{ $skip['reason'] }}</span></li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    <div class="grid gap-4 sm:grid-cols-3">
        <x-ui.kpi :label="__('Pending review')" :value="'$ ' . number_format($pendingReview)" :sub="trans_choice(':n activity|:n activities', $queueCounts['review'], ['n' => $queueCounts['review']])" tone="amber" />
        <x-ui.kpi :label="__('Awaiting approval')" :value="'$ ' . number_format($awaitingApproval)" :sub="trans_choice(':n activity|:n activities', $queueCounts['approval'], ['n' => $queueCounts['approval']])" tone="indigo" />
        <x-ui.kpi :label="__('Approved plan')" :value="'$ ' . number_format($approvedPlan)" :sub="trans_choice(':n activity|:n activities', $queueCounts['approved'], ['n' => $queueCounts['approved']])" tone="emerald" />
    </div>

    {{-- Queues --}}
    <div class="border-b border-zinc-200 flex flex-wrap" role="tablist" aria-label="{{ __('Review queues') }}">
        @foreach($queues as $key => $label)
            <button role="tab" aria-selected="{{ $queue === $key ? 'true' : 'false' }}" wire:click="setQueue('{{ $key }}')"
                class="px-4 py-2.5 text-sm font-medium -mb-px border-b-2 transition-colors flex items-center gap-2 {{ $queue === $key ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-zinc-500 hover:text-zinc-800' }}">
                {{ $label }}
                <span class="text-xs tabular-nums px-1.5 py-0.5 rounded-full {{ $queue === $key ? 'bg-indigo-50 text-indigo-700' : 'bg-zinc-100 text-zinc-600' }}">{{ $queueCounts[$key] }}</span>
            </button>
        @endforeach
    </div>

    @php $showSelect = $canApprove && $queue === 'approval'; @endphp
    @if($showSelect && $activities->total() > 0)
        <div class="flex flex-wrap items-center gap-2" aria-label="{{ __('Select activities') }}">
            <x-ui.button size="sm" variant="secondary" wire:click="selectAllReady">{{ __('Select all ready') }} ({{ min($activities->total(), \App\Services\ActivityWorkflow::BULK_LIMIT) }})</x-ui.button>
            <x-ui.button size="sm" variant="ghost" x-show="selected.length" x-cloak x-on:click="selected = []">{{ __('Clear selection') }}</x-ui.button>
            <span class="mx-1 hidden h-4 w-px bg-zinc-300 sm:block"></span>
            <span class="text-sm text-zinc-600">{{ __('Select all ready of') }}</span>
            @foreach($rootUnits as $d)
                @continue($unit !== '' && (int) $unit !== $d->id)
                <x-ui.button size="sm" variant="secondary" wire:click="selectUnit({{ $d->id }})">{{ $d->code }}</x-ui.button>
            @endforeach
        </div>
    @endif

    <x-ui.card padding="p-0">
        <x-ui.table :headers="array_merge($showSelect ? [''] : [], [__('Activity'), __('Budget'), __('Status'), __('Actions')])">
            @forelse($activities as $a)
                <tr wire:key="act-{{ $a->id }}">
                    @if($showSelect)
                        <td class="pl-5 pr-0 py-4 w-8 align-top">
                            <input type="checkbox" value="{{ $a->id }}" x-model="selected" class="mt-0.5 rounded border-zinc-300 text-indigo-600" aria-label="{{ __('Select') }}">
                        </td>
                    @endif
                    <td class="px-6 py-4 min-w-[22rem]">
                        <a href="{{ route('activities.show', $a->id) }}" wire:navigate class="text-sm font-semibold text-zinc-900 hover:text-indigo-600">{{ $a->description }}</a>
                        <p class="mt-1 text-xs text-zinc-500">
                            {{ $a->directorate->parent?->code ? $a->directorate->parent->code . ' › ' : '' }}{{ $a->directorate->code }}
                            · {{ $a->program->oge_code ?? '—' }} · {{ __('Quarter') }} {{ $a->quarter }}
                        </p>
                    </td>
                    <td class="px-6 py-4 text-sm tabular-nums whitespace-nowrap">
                        <span class="font-semibold text-zinc-900">$ {{ number_format($a->total ?? 0) }}</span>
                        <p class="text-xs text-zinc-500">{{ trans_choice(':n budget line|:n budget lines', $a->lineItems->count(), ['n' => $a->lineItems->count()]) }}</p>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap"><x-ui.status :status="$a->status" /></td>
                    <td class="px-6 py-4 whitespace-nowrap text-right">
                        @if($canApprove)
                            <div class="inline-flex items-center gap-2">
                                @if(in_array($a->status, [Activity::STATUS_SUBMITTED, Activity::STATUS_REVIEWED], true))
                                    <x-ui.button size="sm" variant="primary" wire:click="review({{ $a->id }})">{{ __('Review') }}</x-ui.button>
                                @endif
                                @if(in_array($a->status, [Activity::STATUS_VALIDATED, Activity::STATUS_WAITING_APPROVAL], true))
                                    <x-ui.button size="sm" variant="primary" wire:click="approve({{ $a->id }})">{{ __('Approve') }}</x-ui.button>
                                @endif
                                @if($a->status !== Activity::STATUS_DRAFT)
                                    <x-ui.button size="sm" variant="secondary" x-on:click="$wire.returnId = {{ $a->id }}; $dispatch('open-modal', 'return-pending')">{{ __('Return') }}</x-ui.button>
                                @endif
                            </div>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $showSelect ? 5 : 4 }}"><x-ui.empty-state :title="__('Nothing in this queue.')" :text="__('Activities appear here when a unit submits them. Change the queue or the fiscal year to see others.')" /></td>
                </tr>
            @endforelse
        </x-ui.table>
        @if($activities->hasPages())
            <div class="px-6 py-4 border-t border-zinc-200">{{ $activities->links() }}</div>
        @endif
    </x-ui.card>

    @if($showSelect)
        {{-- Appears as soon as something is ticked; counting and adding up is done by the server, in the confirmation. --}}
        <div x-show="selected.length" x-cloak class="sticky bottom-4 z-30 flex items-center justify-between gap-4 rounded-lg bg-zinc-900 px-5 py-3 text-white shadow-default">
            <p class="text-sm"><span class="font-semibold tabular-nums" x-text="selected.length"></span> {{ __('selected') }}</p>
            <x-ui.button size="sm" variant="primary" wire:loading.attr="disabled" wire:target="prepareBulk"
                x-on:click="$wire.prepareBulk().then(() => { if ($wire.bulkSummary) $dispatch('open-modal', 'bulk-approve') })">{{ __('Review and approve') }}</x-ui.button>
        </div>

        <x-modal name="bulk-approve" max-width="lg">
            <div class="p-6" role="dialog" aria-labelledby="bulk-approve-title">
                <h2 id="bulk-approve-title" class="text-base font-semibold text-zinc-900">{{ __('Approve the selected activities?') }}</h2>
                @if($bulkSummary)
                    <dl class="mt-4 grid grid-cols-2 gap-4 rounded-lg bg-zinc-50 p-4">
                        <div><dt class="text-xs text-zinc-600">{{ __('Activities') }}</dt><dd class="text-2xl font-semibold tabular-nums text-zinc-900">{{ $bulkSummary['count'] }}</dd></div>
                        <div><dt class="text-xs text-zinc-600">{{ __('Planned budget') }}</dt><dd class="text-2xl font-semibold tabular-nums text-zinc-900">$ {{ number_format($bulkSummary['total']) }}</dd></div>
                    </dl>
                    <p class="mt-3 text-sm text-zinc-700">{{ __('Units') }}: {{ collect($bulkSummary['units'])->map(fn ($n, $code) => $code.' ('.$n.')')->implode(', ') }}</p>
                    @if($bulkSummary['left_out'] > 0)
                        <p class="mt-2 text-sm text-amber-800">{{ trans_choice(':n selected activity cannot be approved by you now and will be left out.|:n selected activities cannot be approved by you now and will be left out.', $bulkSummary['left_out'], ['n' => $bulkSummary['left_out']]) }}</p>
                    @endif
                @endif

                <label for="bulk-note" class="field-label mt-4">{{ __('Note for the record') }} <span class="font-normal text-zinc-500">({{ __('optional') }})</span></label>
                <textarea id="bulk-note" wire:model="note" rows="2" maxlength="1000" class="flux-input"></textarea>

                <div class="mt-6 flex justify-end gap-3">
                    <x-ui.button variant="secondary" wire:click="cancelBulk" x-on:click="$dispatch('close-modal', 'bulk-approve')">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button wire:click="approveSelected" x-on:click="$dispatch('close-modal', 'bulk-approve')">{{ __('Approve') }}</x-ui.button>
                </div>
            </div>
        </x-modal>
    @endif

    @if($canApprove)
        <x-note-dialog name="return-pending" property="reason" action="returnActivity" :title="__('Return this activity to the department for corrections?')"
            :message="__('The unit will see your reason, so say what should be corrected.')"
            :label="__('What should be corrected?')" :confirm="__('Return for correction')" :required="true" />
    @endif
</div>
