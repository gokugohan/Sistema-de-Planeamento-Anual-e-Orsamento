<?php

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\FiscalEnvelope;
use App\Models\LineItem;
use App\Models\User;
use App\Notifications\SystemNotification;
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

    public string $queue = 'review';  // review | approval | approved | returned | all
    public $unit = '';                // top-level unit filter ('' = all)

    private const QUEUES = [
        'review' => [Activity::STATUS_SUBMITTED, Activity::STATUS_REVIEWED],
        'approval' => [Activity::STATUS_VALIDATED, Activity::STATUS_WAITING_APPROVAL],
        'approved' => [Activity::STATUS_APPROVED],
        'returned' => [Activity::STATUS_DRAFT, Activity::STATUS_REJECTED],
    ];

    public function setQueue(string $queue): void
    {
        $this->queue = array_key_exists($queue, self::QUEUES) || $queue === 'all' ? $queue : 'review';
        $this->resetPage();
    }

    public function updatedUnit(): void
    {
        $this->resetPage();
    }

    private function year(): int
    {
        return FiscalEnvelope::getEffectiveYear();
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

        return Activity::query()->where('fiscal_year', $this->year())->when($ids, fn ($q) => $q->whereIn('directorate_id', $ids));
    }

    private function notifyUnit(Activity $activity, string $title, string $message, string $url): void
    {
        User::where('directorate_id', $activity->directorate_id)->get()->each(
            fn ($u) => $u->notify(new SystemNotification($title, $message, $url))
        );
    }

    public function approve($id)
    {
        abort_unless(auth()->user()->can('approve_paa'), 403);
        $activity = Activity::findOrFail($id);
        abort_unless(in_array($activity->status, self::QUEUES['approval'], true), 422);

        $activity->update(['status' => Activity::STATUS_APPROVED, 'approved_at' => now(), 'approved_by' => auth()->id()]);
        $this->notifyUnit($activity, __('Activity Approved'),
            __('Activity ":desc" has been technically approved for :year.', ['desc' => str($activity->description)->limit(30), 'year' => $this->year()]),
            route('activities.show', ['activity' => $activity->id], false));

        $this->dispatch('toast', message: __('Activity approved successfully.'), type: 'success');
    }

    /** Approve every validated activity of one top-level unit in one step. */
    public function approveDirectoratePlan($rootDirectorateId)
    {
        abort_unless(auth()->user()->can('approve_paa'), 403);
        $ids = Directorate::findOrFail($rootDirectorateId)->getSelfAndDescendantIds();

        $count = 0;
        Activity::where('fiscal_year', $this->year())->whereIn('directorate_id', $ids)->whereIn('status', self::QUEUES['approval'])->get()
            ->each(function ($activity) use (&$count) {
                $activity->update(['status' => Activity::STATUS_APPROVED, 'approved_at' => now(), 'approved_by' => auth()->id()]);
                $this->notifyUnit($activity, __('Activity Approved'),
                    __('Activity ":desc" has been technically approved for :year.', ['desc' => str($activity->description)->limit(30), 'year' => $this->year()]),
                    route('activities.show', ['activity' => $activity->id], false));
                $count++;
            });

        $this->dispatch('toast', message: trans_choice(':n activity approved.|:n activities approved.', $count, ['n' => $count]), type: 'success');
    }

    public function review($id)
    {
        abort_unless(auth()->user()->can('approve_paa'), 403);
        $activity = Activity::findOrFail($id);
        abort_unless(in_array($activity->status, self::QUEUES['review'], true), 422);

        $newStatus = Activity::STATUS_VALIDATED; // final validation by default
        if ($activity->status === Activity::STATUS_SUBMITTED) {
            $firstParent = $activity->directorate->parent;
            if ($firstParent && $firstParent->parent) {
                $newStatus = Activity::STATUS_REVIEWED; // an intermediate review is required first
            }
        }

        $activity->update(['status' => $newStatus, 'reviewed_at' => now(), 'reviewed_by' => auth()->id()]);
        $this->dispatch('toast', message: __('Activity updated to :status.', ['status' => __($newStatus)]), type: 'success');
    }

    public function reject($id)
    {
        abort_unless(auth()->user()->can('approve_paa'), 403);
        $activity = Activity::findOrFail($id);
        $activity->update(['status' => Activity::STATUS_DRAFT]); // back to the unit for correction

        $this->notifyUnit($activity, __('Activity Returned for Correction'),
            __('Activity ":desc" requires corrections for :year.', ['desc' => str($activity->description)->limit(30), 'year' => $this->year()]),
            route('activities.index', absolute: false));

        $this->dispatch('toast', message: __('Activity returned to department for correction.'), type: 'success');
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

        $env = FiscalEnvelope::where('fiscal_year', $year)->first();

        return [
            'year' => $year,
            'activities' => $activities,
            'queueCounts' => $queueCounts,
            'pendingReview' => $money(self::QUEUES['review']),
            'awaitingApproval' => $money(self::QUEUES['approval']),
            'approvedPlan' => $money(self::QUEUES['approved']),
            'locked' => $env && $env->status === FiscalEnvelope::STATUS_CLOSED,
            'rootUnits' => Directorate::whereNull('parent_id')->orderBy('code')->get(['id', 'code', 'name']),
            'canApprove' => auth()->user()->can('approve_paa'),
            'queues' => [
                'review' => __('To review'), 'approval' => __('Ready for approval'), 'approved' => __('Approved'),
                'returned' => __('Returned / draft'), 'all' => __('All'),
            ],
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
@endphp

<div class="space-y-6">
    <div class="flex flex-col lg:flex-row lg:items-end justify-between gap-4">
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

    @if($locked)
        <div class="alert alert-success !items-start">
            <div>
                <p class="font-semibold">{{ __('PAA Cycle Finalized') }}</p>
                <p class="text-xs font-normal mt-1">{{ __('The PAA for :year is officially approved and the planning cycle is locked.', ['year' => $year]) }}
                    <a href="{{ route('monitoring.index') }}" class="underline font-medium">{{ __('View Monitoring') }}</a></p>
            </div>
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

    <x-ui.card padding="p-0">
        <x-ui.table :headers="[__('Activity'), __('Budget'), __('Status'), __('Actions')]">
            @forelse($activities as $a)
                <tr wire:key="act-{{ $a->id }}">
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
                    <td class="px-6 py-4 whitespace-nowrap"><x-ui.badge :variant="$badge($a->status)">{{ __($a->status) }}</x-ui.badge></td>
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
                                    <x-ui.button size="sm" variant="secondary" wire:click="reject({{ $a->id }})"
                                        wire:confirm="{{ __('Return this activity to the department for corrections?') }}">{{ __('Return') }}</x-ui.button>
                                @endif
                            </div>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="px-6 py-14 text-center">
                        <p class="text-sm font-medium text-zinc-900">{{ __('Nothing in this queue.') }}</p>
                        <p class="mt-1 text-sm text-zinc-500">{{ __('Activities appear here when a unit submits them. Change the queue or the fiscal year to see others.') }}</p>
                    </td>
                </tr>
            @endforelse
        </x-ui.table>
        @if($activities->hasPages())
            <div class="px-6 py-4 border-t border-zinc-200">{{ $activities->links() }}</div>
        @endif
    </x-ui.card>

    @if($canApprove && $queue === 'approval' && $activities->total() > 0)
        <div class="flex flex-wrap items-center gap-3">
            <span class="text-sm text-zinc-500">{{ __('Approve everything that is ready for a whole unit:') }}</span>
            @foreach($rootUnits as $d)
                @continue($unit !== '' && (int) $unit !== $d->id)
                <x-ui.button size="sm" variant="secondary" wire:click="approveDirectoratePlan({{ $d->id }})"
                    wire:confirm="{{ __('Approve all ready activities of :unit?', ['unit' => $d->code]) }}">{{ $d->code }}</x-ui.button>
            @endforeach
        </div>
    @endif
</div>
