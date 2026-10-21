<?php

use App\Models\ActivityReport;
use App\Models\Directorate;
use App\Models\IndicatorProgress;
use App\Policies\ActivityPolicy;
use Livewire\Volt\Component;

/**
 * Verify the indicator progress the units have reported for a quarter. Each pending line opens into what a reviewer
 * needs to decide: who reported it and when, what the indicator is and how it is verified, what was verified in the
 * other quarters and where that leaves the indicator against its target, and the words the unit wrote for the quarter.
 * A figure is verified by someone other than the person who reported it, or returned with a reason the unit will read.
 */
new class extends Component {
    public $activeYear;
    public $selectedDirectorate = null;
    public $quarter = 1;
    public ?int $returnId = null;
    public string $reason = '';

    public function mount()
    {
        $this->activeYear = \App\Support\FiscalYear::current();
        $this->quarter = (int) ceil(date('n') / 3);

        if (! auth()->user()->can('view_all_paa')) {
            $this->selectedDirectorate = auth()->user()->directorate_id;
        }
    }

    /** The submitted figures of the quarter, with everything the details show loaded at once. */
    private function pending()
    {
        return IndicatorProgress::where('status', 'Submitted')
            ->where('quarter', $this->quarter)
            ->whereHas('indicator.activity', function ($q) {
                $q->where('fiscal_year', $this->activeYear);
                if ($this->selectedDirectorate) {
                    $q->where('directorate_id', $this->selectedDirectorate);
                }
            })
            ->with(['indicator.activity.directorate', 'indicator.actions', 'indicator.progress', 'submittedBy:id,name'])
            ->orderBy('id')->get();
    }

    /** The quarter's narrative of each activity, by activity id. */
    private function narratives($pending)
    {
        return ActivityReport::whereIn('activity_id', $pending->pluck('indicator.activity_id')->unique())
            ->where('period_type', ActivityReport::QUARTER)->where('period', $this->quarter)->get()->keyBy('activity_id');
    }

    /** In the user's scope, and not something they reported themselves. @return string|null why it cannot be decided, null if it can */
    private function whyNot(IndicatorProgress $progress): ?string
    {
        $progress->loadMissing('indicator.activity');
        if (! app(ActivityPolicy::class)->inScope(auth()->user(), $progress->indicator?->activity?->directorate_id)) {
            return 'scope';
        }

        return $progress->submitted_by === auth()->id() ? 'own' : null;
    }

    public function approve($id)
    {
        abort_unless(auth()->user()->can('approve_progress_report'), 403);
        $progress = IndicatorProgress::findOrFail($id);
        $why = $this->whyNot($progress);
        abort_if($why === 'scope', 403);
        if ($why === 'own') {
            $this->dispatch('toast', message: __('Someone other than the person who reported it must verify it.'), type: 'error');

            return;
        }
        $progress->update(['status' => 'Verified', 'verified_by' => auth()->id(), 'verified_at' => now()]);
        $this->dispatch('toast', message: __('Progress verified successfully.'), type: 'success');
    }

    /** Return one figure to its unit, saying why. The reason is required: the unit reads it and corrects. */
    public function returnProgress()
    {
        abort_unless(auth()->user()->can('approve_progress_report'), 403);
        $progress = IndicatorProgress::findOrFail($this->returnId);
        $why = $this->whyNot($progress);
        abort_if($why === 'scope', 403);
        if ($why === 'own') {
            $this->dispatch('toast', message: __('Someone other than the person who reported it must verify it.'), type: 'error');

            return;
        }
        $reason = trim($this->reason);
        if (mb_strlen($reason) < 3) {
            $this->dispatch('toast', message: __('Say why the figure is returned, so the unit knows what to correct.'), type: 'error');

            return;
        }
        $progress->auditMetadata = ['by' => auth()->id(), 'returned' => true, 'reason' => $reason];
        $progress->update(['status' => 'Rejected', 'rejection_notes' => mb_substr($reason, 0, 1000)]);
        $this->reset('returnId', 'reason');
        $this->dispatch('toast', message: __('Returned to the unit with your reason.'), type: 'success');
    }

    public function approveAll()
    {
        abort_unless(auth()->user()->can('approve_progress_report'), 403);
        $done = $left = 0;
        foreach ($this->pending() as $progress) {
            if ($this->whyNot($progress) !== null) {
                $left++; // their own reports, or outside their scope, wait for someone else

                continue;
            }
            $progress->update(['status' => 'Verified', 'verified_by' => auth()->id(), 'verified_at' => now()]);
            $done++;
        }
        $this->dispatch('toast', message: $left
            ? __(':done verified; :left left for someone else (your own reports cannot be verified by you).', ['done' => $done, 'left' => $left])
            : __('All pending progress verified.'), type: $left ? 'info' : 'success');
    }

    public function with(): array
    {
        $pending = $this->pending();

        return [
            'pending' => $pending,
            'narratives' => $this->narratives($pending),
            'me' => auth()->id(),
            'directorates' => auth()->user()->can('view_all_paa') ? Directorate::orderBy('name')->get(['id', 'name']) : collect(),
        ];
    }
}; ?>

<div class="space-y-6">
    <x-ui.page-header :title="__('Verify progress')" :subtitle="__('Open a line to see what was reported, how it compares with the target and what the unit wrote. Then verify it, or return it with a reason. You cannot verify what you reported yourself.')">
        <label for="va-q" class="sr-only">{{ __('Select Trimestre') }}</label>
        <select id="va-q" wire:model.live="quarter" class="flux-input !w-auto min-w-[11rem]">
            <option value="1">{{ __('Q1 (Jan-Mar)') }}</option>
            <option value="2">{{ __('Q2 (Apr-Jun)') }}</option>
            <option value="3">{{ __('Q3 (Jul-Sep)') }}</option>
            <option value="4">{{ __('Q4 (Oct-Dec)') }}</option>
        </select>
        @if($directorates->isNotEmpty())
            <label for="va-d" class="sr-only">{{ __('Filter by Directorate') }}</label>
            <select id="va-d" wire:model.live="selectedDirectorate" class="flux-input !w-auto min-w-[14rem]">
                <option value="">{{ __('All Directorates') }}</option>
                @foreach($directorates as $dir)<option value="{{ $dir->id }}">{{ $dir->name }}</option>@endforeach
            </select>
        @endif
    </x-ui.page-header>

    <x-ui.card padding="p-0">
        <x-slot name="header">
            <div class="flex items-center justify-between gap-3 px-5 py-4">
                <h2 class="text-sm font-semibold text-zinc-900">{{ __('Waiting to be verified') }} ({{ $pending->count() }})</h2>
                @if($pending->count() > 0)
                    <x-ui.button type="button" size="sm" variant="secondary" wire:click="approveAll"
                        wire:confirm="{{ __('Verify every line you are allowed to verify? Open the lines first if you have not read them.') }}">{{ __('Verify all') }}</x-ui.button>
                @endif
            </div>
        </x-slot>

        @forelse($pending as $p)
            @php
                $ind = $p->indicator;
                $act = $ind->activity;
                $verified = $ind->progress->where('status', 'Verified')->where('quarter', '!=', $p->quarter)->pluck('achieved_value', 'quarter');
                $after = $verified->replace([$p->quarter => $p->achieved_value]); // a new collection: $verified stays the other quarters
                $standing = $ind->standing($after->map(fn ($v) => (float) $v));
                $target = (float) $ind->annual_target;
                $pct = $target > 0 ? $standing / $target * 100 : null;
                $mine = $p->submitted_by === $me;
                $note = $narratives->get($act->id);
                $months = range(($p->quarter - 1) * 3 + 1, $p->quarter * 3);
                $plan = $ind->actions->filter(fn ($a) => array_intersect($a->monthList(), $months) || $a->monthList() === []);
            @endphp
            <div class="border-t border-zinc-200 first:border-t-0" x-data="{ open: {{ $pending->count() <= 2 ? 'true' : 'false' }} }" wire:key="vp-{{ $p->id }}">
                <div class="flex flex-wrap items-center gap-4 px-5 py-4">
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-medium text-indigo-700">{{ $act->directorate?->code }} · {{ $act->code }}</p>
                        <p class="text-sm font-semibold leading-snug text-zinc-900">{{ $ind->name }}</p>
                        <p class="mt-0.5 line-clamp-1 text-xs text-zinc-500">{{ $act->description }}</p>
                    </div>
                    <div class="text-right text-sm tabular-nums">
                        <p class="text-xs text-zinc-500">{{ __('Reported for Q:q', ['q' => $p->quarter]) }}</p>
                        <p class="text-lg font-semibold text-zinc-900">{{ number_format((float) $p->achieved_value, 2) }} <span class="text-xs font-normal text-zinc-500">{{ $ind->unit }}</span></p>
                        <p class="text-xs text-zinc-500">{{ __('Target :t', ['t' => number_format($target, 2)]) }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" x-on:click="open = ! open" class="rounded-md border border-zinc-200 px-3 py-1.5 text-xs font-medium text-zinc-700 hover:bg-zinc-50" x-bind:aria-expanded="open.toString()">
                            <span x-show="! open">{{ __('Details') }}</span><span x-show="open" x-cloak>{{ __('Hide details') }}</span>
                        </button>
                        @if($mine)
                            <span class="text-xs text-zinc-500">{{ __('You reported this: someone else verifies it.') }}</span>
                        @else
                            <x-ui.button type="button" size="sm" wire:click="approve({{ $p->id }})">{{ __('Verify') }}</x-ui.button>
                            <button type="button" x-on:click="$wire.returnId = {{ $p->id }}; $dispatch('open-modal', 'return-progress')" class="rounded-md px-3 py-1.5 text-xs font-medium text-rose-700 hover:bg-rose-50">{{ __('Return with a reason') }}</button>
                        @endif
                    </div>
                </div>

                <div x-show="open" x-cloak class="grid gap-5 border-t border-zinc-100 bg-zinc-50/60 px-5 py-4 text-sm md:grid-cols-3">
                    {{-- what was reported and where it leaves the indicator --}}
                    <div class="space-y-2">
                        <h3 class="text-xs font-semibold text-zinc-500">{{ __('What was reported') }}</h3>
                        <p class="text-zinc-700">{{ __('Reported by :name on :date.', ['name' => $p->submittedBy?->name ?? '—', 'date' => $p->updated_at?->timezone('Asia/Dili')->format('d/m/Y H:i')]) }}</p>
                        <dl class="space-y-1 text-zinc-700">
                            <div class="flex justify-between gap-3"><dt>{{ __('Verified in other quarters') }}</dt><dd class="tabular-nums">
                                @forelse($verified->sortKeys() as $q => $v)Q{{ $q }}: {{ number_format((float) $v, 2) }}@if(! $loop->last) · @endif @empty — @endforelse
                            </dd></div>
                            <div class="flex justify-between gap-3"><dt>{{ $ind->isLevel() ? __('Level after verifying this') : __('Total after verifying this') }}</dt><dd class="font-semibold tabular-nums">{{ number_format($standing, 2) }} {{ $ind->unit }}@if($pct !== null) ({{ number_format($pct, 0) }}%)@endif</dd></div>
                        </dl>
                        @if($pct !== null && $pct > 100)<p class="rounded bg-amber-50 px-2 py-1 text-xs text-amber-900">{{ __('This is above the annual target: check the figure.') }}</p>@endif
                    </div>

                    {{-- what the indicator is and how it is checked --}}
                    <div class="space-y-2">
                        <h3 class="text-xs font-semibold text-zinc-500">{{ __('The indicator') }}</h3>
                        <dl class="space-y-1 text-zinc-700">
                            <div class="flex justify-between gap-3"><dt>{{ __('Kind') }}</dt><dd>{{ $ind->isLevel() ? __('Level: the figure is where it stands at the end of the quarter') : __('Cumulative: each quarter adds to the total') }}</dd></div>
                            <div class="flex justify-between gap-3"><dt>{{ __('Annual target') }}</dt><dd class="tabular-nums">{{ number_format($target, 2) }} {{ $ind->unit }}</dd></div>
                            @if($ind->baseline)<div class="flex justify-between gap-3"><dt>{{ __('Baseline') }}</dt><dd>{{ $ind->baseline }}</dd></div>@endif
                            @if($ind->means_of_verification)<div><dt class="text-zinc-500">{{ __('Means of verification') }}</dt><dd class="mt-0.5">{{ $ind->means_of_verification }}</dd></div>@endif
                        </dl>
                        @if($plan->isNotEmpty())
                            <div><p class="text-zinc-500">{{ __('Planned for this quarter') }}</p><ul class="mt-0.5 list-disc space-y-0.5 pl-4 text-zinc-700">@foreach($plan as $a)<li>{{ $a->text }}</li>@endforeach</ul></div>
                        @endif
                    </div>

                    {{-- what the unit wrote --}}
                    <div class="space-y-2">
                        <h3 class="text-xs font-semibold text-zinc-500">{{ __('What the unit wrote for Q:q', ['q' => $p->quarter]) }}</h3>
                        @if($note && ($note->results || $note->constraints || $note->reasons_not_met || $note->corrective_measures))
                            <dl class="space-y-1.5 text-zinc-700">
                                @foreach(['results' => __('What was done'), 'constraints' => __('Constraints'), 'reasons_not_met' => __('Why targets were not met'), 'corrective_measures' => __('Corrective measures')] as $field => $label)
                                    @if($note->{$field})<div><dt class="text-xs text-zinc-500">{{ $label }}</dt><dd class="whitespace-pre-line">{{ $note->{$field} }}</dd></div>@endif
                                @endforeach
                                @if($note->rating)<div class="text-xs text-zinc-500">{{ __('Rating') }}: <span class="font-medium text-zinc-800">{{ __($note->rating) }}</span> · {{ __($note->status) }}</div>@endif
                            </dl>
                        @else
                            <p class="text-zinc-500">{{ __('Nothing written yet for this quarter.') }}</p>
                        @endif
                        <p><a href="{{ route('activities.show', $act) }}" wire:navigate class="text-xs font-medium text-indigo-700 hover:underline">{{ __('Open the activity') }} →</a></p>
                    </div>
                </div>
            </div>
        @empty
            <x-ui.empty-state :title="__('Nothing is waiting to be verified')" :text="__('No progress report is waiting for the quarter you chose.')" />
        @endforelse
    </x-ui.card>

    <x-note-dialog name="return-progress" property="reason" action="returnProgress" :required="true"
        :title="__('Return this figure to the unit?')"
        :message="__('The unit sees your reason and corrects the figure.')"
        :label="__('What should be corrected?')" :confirm="__('Return to the unit')" />
</div>
