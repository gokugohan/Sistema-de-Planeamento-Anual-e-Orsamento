<?php

use App\Models\ReportingWindow;
use App\Models\WindowExtension;
use App\Services\ReportingWindows;
use App\Support\PlanOptions;
use Carbon\Carbon;
use Livewire\Volt\Component;

/**
 * The calendar of the year, in the order the year runs: 1 Planning (submission, which needs its dates), 2 Approval and
 * revisions (open unless dated), 3 Reporting (each month and quarter, filled in at once from a rule: due so many days after
 * the period ends). Each row is saved on its own; emptying both dates removes them. When a unit is late, the administrator
 * reopens the period for that unit only, until a date.
 */
new class extends Component {
    public int $year;

    /** @var array<string, array{opens: string, closes: string}> the dates of each step, keyed type_period (plan_1, month_3) */
    public array $dates = [];

    /** The rule for the reporting dates: due this many days after the month or quarter ends. */
    public int $monthDays = 10;
    public int $quarterDays = 15;

    public string $guidance = '';

    /** @var array<int, string> new closing date per late unit (row of the list), and why */
    public array $until = [];
    public array $why = [];

    /** Approval also covers finalizing: approving the plan of a directorate is what finalizes it. */
    private const STEPS = ['plan_1', 'approval_1', 'revision_1'];

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);
        $this->year = \App\Support\FiscalYear::current();
        $this->load();
    }

    public function updatedYear(): void
    {
        $this->validate(['year' => 'required|integer|min:2000|max:2100']);
        $this->load();
    }

    private function load(): void
    {
        $this->dates = [];
        foreach ($this->keys() as $key) {
            $this->dates[$key] = ['opens' => '', 'closes' => ''];
        }
        foreach (ReportingWindow::where('fiscal_year', $this->year)->get() as $w) {
            $key = $w->period_type.'_'.$w->period;
            if (isset($this->dates[$key])) {
                $this->dates[$key] = ['opens' => $w->opens_on->toDateString(), 'closes' => $w->closes_on->toDateString()];
            }
        }
        $this->guidance = (string) PlanOptions::guidance($this->year);
        $this->resetValidation();
    }

    /** @return array<int, string> */
    private function keys(): array
    {
        return [...self::STEPS, ...array_map(fn ($m) => "month_$m", range(1, 12)), ...array_map(fn ($q) => "quarter_$q", range(1, 4))];
    }

    /** Save the dates of one step, or remove them when both are empty. */
    public function saveStep(string $key): void
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);
        abort_unless(in_array($key, $this->keys(), true), 404);
        [$type, $period] = explode('_', $key);
        $d = $this->dates[$key];

        if (trim($d['opens']) === '' && trim($d['closes']) === '') {
            $this->write($type, (int) $period, null, null);
            $this->dispatch('toast', message: __('Dates removed.'), type: 'success');

            return;
        }
        $this->validate([
            "dates.$key.opens" => 'required|date',
            "dates.$key.closes" => "required|date|after_or_equal:dates.$key.opens",
        ], [], ["dates.$key.opens" => __('Opens on'), "dates.$key.closes" => __('Closes on')]);

        $this->write($type, (int) $period, $d['opens'], $d['closes']);
        $this->dispatch('toast', message: __('Calendar saved.'), type: 'success');
    }

    private function write(string $type, int $period, ?string $opens, ?string $closes): void
    {
        // approving the plan finalizes it, so the approval dates are the finalizing dates too
        foreach ($type === 'approval' ? ['approval', 'finalize'] : [$type] as $t) {
            $where = ['fiscal_year' => $this->year, 'period_type' => $t, 'period' => $period];
            if ($opens === null) {
                ReportingWindow::where($where)->get()->each->delete();
            } else {
                ReportingWindow::updateOrCreate($where, ['opens_on' => $opens, 'closes_on' => $closes, 'created_by' => auth()->id()]);
            }
        }
    }

    /** Every month and quarter of the year: open from its first day, due so many days after it ends. */
    public function fillReports(): void
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);
        $this->validate(['monthDays' => 'required|integer|min:0|max:60', 'quarterDays' => 'required|integer|min:0|max:90']);

        foreach (range(1, 12) as $m) {
            $start = Carbon::create($this->year, $m, 1);
            $this->write('month', $m, $start->toDateString(), $start->copy()->endOfMonth()->addDays($this->monthDays)->toDateString());
        }
        foreach (range(1, 4) as $q) {
            $start = Carbon::create($this->year, ($q - 1) * 3 + 1, 1);
            $this->write('quarter', $q, $start->toDateString(), $start->copy()->addMonthsNoOverflow(2)->endOfMonth()->addDays($this->quarterDays)->toDateString());
        }
        $this->load();
        $this->dispatch('toast', message: __('The reporting dates of :year are filled in.', ['year' => $this->year]), type: 'success');
    }

    public function clearReports(): void
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);
        ReportingWindow::where('fiscal_year', $this->year)->whereIn('period_type', ['month', 'quarter'])->get()->each->delete();
        $this->load();
        $this->dispatch('toast', message: __('Dates removed.'), type: 'success');
    }

    public function saveGuidance(): void
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);
        $this->validate(['guidance' => 'nullable|string|max:4000']);
        PlanOptions::setGuidance($this->year, $this->guidance);
        $this->dispatch('toast', message: __('Guidance saved.'), type: 'success');
    }

    /** Reopen a closed period for one late unit (and the units below it) until a date. */
    public function reopen(int $row): void
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);
        $late = app(ReportingWindows::class)->overdue($this->year)[$row] ?? null;
        abort_unless($late, 404);

        $until = $this->until[$row] ?? now()->addDays(7)->toDateString();
        $this->validate([
            "until.$row" => 'nullable|date|after_or_equal:today',
            "why.$row" => 'nullable|string|max:200',
        ]);
        app(ReportingWindows::class)->reopen(auth()->user(), $this->year, $late['type'], $late['period'], $late['unit_id'], $until, $this->why[$row] ?? null);
        unset($this->until[$row], $this->why[$row]);
        $this->dispatch('toast', message: __(':what is open again for :unit.', ['what' => $late['label'], 'unit' => $late['unit']]), type: 'success');
    }

    public function withdraw(int $id): void
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);
        app(ReportingWindows::class)->withdraw($id);
        $this->dispatch('toast', message: __('Reopening withdrawn.'), type: 'success');
    }

    public function with(): array
    {
        $saved = ReportingWindow::where('fiscal_year', $this->year)->get()->keyBy(fn ($w) => $w->period_type.'_'.$w->period);
        $today = now()->startOfDay();
        // where each step stands today, from the saved dates (not from what is being typed)
        $state = function (string $key) use ($saved, $today) {
            $w = $saved[$key] ?? null;

            return match (true) {
                $w === null => null,
                $today->lt($w->opens_on) => [__('Not open yet'), 'bg-zinc-100 text-zinc-700'],
                $today->gt($w->closes_on) => [__('Closed'), 'bg-rose-50 text-rose-800'],
                default => [__('Open'), 'bg-emerald-50 text-emerald-800'],
            };
        };

        return [
            'state' => $state,
            'hasReports' => $saved->keys()->contains(fn ($k) => str_starts_with($k, 'month_') || str_starts_with($k, 'quarter_')),
            'late' => app(ReportingWindows::class)->overdue($this->year),
            'reopened' => WindowExtension::where('fiscal_year', $this->year)->whereDate('closes_on', '>=', now()->toDateString())->with('directorate:id,name')->orderBy('closes_on')->get(),
        ];
    }
}; ?>

<div class="space-y-6">
    <x-system-tabs />
    <x-ui.page-header :title="__('Calendar')" :subtitle="__('The dates of the year, in the order it runs. Activities are submitted only between the planning dates, by everybody, administrators included; until then they stay drafts. The other steps are open unless you give them dates, and a late unit can be given more time on its own.')">
        <div class="flex items-center gap-2">
            <label for="cal-year" class="whitespace-nowrap text-sm text-zinc-600">{{ __('Fiscal year') }}</label>
            <input id="cal-year" type="number" wire:model.live.debounce.500ms="year" class="flux-input !w-28">
        </div>
    </x-ui.page-header>

    @if(count($late))
        <x-ui.card :title="__('Late')" :description="__('These periods have closed and the unit still has work left in them. Give a unit more time without reopening the period for everybody.')" padding="p-0">
            <ul class="divide-y divide-zinc-200">
                @foreach($late as $i => $row)
                    <li wire:key="late-{{ $i }}-{{ $row['type'] }}-{{ $row['period'] }}-{{ $row['unit_id'] }}" class="grid gap-3 px-5 py-4 md:grid-cols-12 md:items-end">
                        <div class="md:col-span-4">
                            <p class="text-sm font-medium text-zinc-900">{{ $row['unit'] }}</p>
                            <p class="text-xs text-zinc-500">{{ $row['label'] }} · {{ __('closed on :d', ['d' => $row['closed_on']->format('d/m/Y')]) }}</p>
                            <p class="mt-0.5 text-xs text-amber-800">{{ $row['count'] }} {{ $row['what'] }}</p>
                        </div>
                        <div class="md:col-span-2">
                            <label for="until-{{ $i }}" class="field-label">{{ __('Open until') }}</label>
                            <input id="until-{{ $i }}" type="date" wire:model="until.{{ $i }}" min="{{ now()->toDateString() }}" placeholder="{{ now()->addDays(7)->toDateString() }}" class="flux-input">
                            @error("until.$i")<p class="field-error">{{ $message }}</p>@enderror
                        </div>
                        <div class="md:col-span-4">
                            <label for="why-{{ $i }}" class="field-label">{{ __('Reason') }}</label>
                            <input id="why-{{ $i }}" type="text" wire:model="why.{{ $i }}" class="flux-input">
                        </div>
                        <div class="md:col-span-2 md:text-right">
                            <x-ui.button type="button" variant="secondary" shadow="none" wire:click="reopen({{ $i }})">{{ __('Reopen for this unit') }}</x-ui.button>
                        </div>
                    </li>
                @endforeach
            </ul>
            <p class="border-t border-zinc-200 bg-zinc-50 px-5 py-3 text-xs text-zinc-600">{{ __('If you leave the date empty, the unit gets seven days from today.') }}</p>
        </x-ui.card>
    @endif

    @if($reopened->isNotEmpty())
        <x-ui.card :title="__('Reopened for a unit')" padding="p-0">
            <x-ui.table :headers="[__('Unit'), __('Period'), __('Open until'), __('Reason'), '']" class="text-sm">
                @foreach($reopened as $e)
                    <tr wire:key="ext-{{ $e->id }}">
                        <td class="px-5 py-3 font-medium text-zinc-900">{{ $e->directorate?->name }}</td>
                        <td class="px-5 py-3">{{ app(\App\Services\ReportingWindows::class)->label($e->period_type, $e->period, $year) }}</td>
                        <td class="px-5 py-3 tabular-nums">{{ $e->closes_on->format('d/m/Y') }}</td>
                        <td class="px-5 py-3 text-zinc-600">{{ $e->reason }}</td>
                        <td class="px-5 py-3 text-right"><button type="button" wire:click="withdraw({{ $e->id }})" class="text-xs font-medium text-rose-700 hover:underline">{{ __('Withdraw') }}</button></td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif

    {{-- 1 Planning --}}
    <x-ui.card padding="p-0">
        <div class="flex items-center gap-3 border-b border-zinc-200 px-5 py-4">
            <span class="flex h-6 w-6 items-center justify-center rounded-full bg-indigo-50 text-xs font-bold text-indigo-600" aria-hidden="true">1</span>
            <div>
                <h2 class="section-title">{{ __('Planning') }}</h2>
                <p class="text-xs text-zinc-500">{{ __('The units can prepare their activities at any time; they submit them only between these dates.') }}</p>
            </div>
        </div>
        @include('livewire.pages.management.partials.calendar-row', ['key' => 'plan_1', 'label' => __('Submission of the plan'), 'hint' => __('Required: without dates nobody can submit.'), 'required' => true])
        <div class="border-t border-zinc-200 px-5 py-4">
            <label for="guidance" class="field-label">{{ __('Guidance for preparing the plan') }}</label>
            <textarea id="guidance" rows="3" wire:model="guidance" class="flux-input" placeholder="{{ __('Shown to those who prepare activities for this year, for example the deadlines and instructions of the planning circular.') }}"></textarea>
            @error('guidance')<p class="field-error">{{ $message }}</p>@enderror
            <div class="mt-2 flex justify-end"><x-ui.button type="button" variant="secondary" shadow="none" wire:click="saveGuidance">{{ __('Save guidance') }}</x-ui.button></div>
        </div>
    </x-ui.card>

    {{-- 2 Approval and revisions --}}
    <x-ui.card padding="p-0">
        <div class="flex items-center gap-3 border-b border-zinc-200 px-5 py-4">
            <span class="flex h-6 w-6 items-center justify-center rounded-full bg-indigo-50 text-xs font-bold text-indigo-600" aria-hidden="true">2</span>
            <div>
                <h2 class="section-title">{{ __('Approval') }}</h2>
                <p class="text-xs text-zinc-500">{{ __('Optional. Leave the dates empty and these steps are always open.') }}</p>
            </div>
        </div>
        @include('livewire.pages.management.partials.calendar-row', ['key' => 'approval_1', 'label' => __('Certifying, validating and approving the plan'), 'hint' => __('Approving the plan of a directorate finalizes it.'), 'required' => false])
        @include('livewire.pages.management.partials.calendar-row', ['key' => 'revision_1', 'label' => __('Revisions of approved activities'), 'hint' => __('When a unit may start a revision of an approved activity.'), 'required' => false])
    </x-ui.card>

    {{-- 3 Reporting --}}
    <x-ui.card padding="p-0">
        <div class="flex items-center gap-3 border-b border-zinc-200 px-5 py-4">
            <span class="flex h-6 w-6 items-center justify-center rounded-full bg-indigo-50 text-xs font-bold text-indigo-600" aria-hidden="true">3</span>
            <div>
                <h2 class="section-title">{{ __('Reporting') }}</h2>
                <p class="text-xs text-zinc-500">{{ __('Optional. Each month and quarter can be reported from its first day until its due date; without dates it is always open.') }}</p>
            </div>
        </div>
        <form wire:submit="fillReports" class="flex flex-wrap items-end gap-4 border-b border-zinc-200 bg-zinc-50 px-5 py-4">
            <div>
                <label for="monthDays" class="field-label">{{ __('Monthly report due, days after the month ends') }}</label>
                <input id="monthDays" type="number" min="0" max="60" wire:model="monthDays" class="flux-input !w-28">
                @error('monthDays')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="quarterDays" class="field-label">{{ __('Quarterly report due, days after the quarter ends') }}</label>
                <input id="quarterDays" type="number" min="0" max="90" wire:model="quarterDays" class="flux-input !w-28">
                @error('quarterDays')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            @if($hasReports)
                <x-ui.button type="submit" wire:confirm="{{ __('Replace the reporting dates of :year?', ['year' => $year]) }}">{{ __('Fill in the dates for :year', ['year' => $year]) }}</x-ui.button>
                <x-ui.button type="button" variant="secondary" shadow="none" wire:click="clearReports" wire:confirm="{{ __('Remove every reporting date of :year? Reporting is then always open.', ['year' => $year]) }}">{{ __('Remove them') }}</x-ui.button>
            @else
                <x-ui.button type="submit">{{ __('Fill in the dates for :year', ['year' => $year]) }}</x-ui.button>
            @endif
        </form>
        <details class="group" @if($hasReports) open @endif>
            <summary class="cursor-pointer px-5 py-3 text-sm font-medium text-indigo-700 hover:underline">{{ __('Each month and quarter') }}</summary>
            @foreach(range(1, 12) as $m)
                @include('livewire.pages.management.partials.calendar-row', ['key' => "month_$m", 'label' => \Carbon\Carbon::create($year, $m, 1)->translatedFormat('F'), 'hint' => null, 'required' => false])
            @endforeach
            @foreach(range(1, 4) as $q)
                @include('livewire.pages.management.partials.calendar-row', ['key' => "quarter_$q", 'label' => __('Quarter :q', ['q' => $q]), 'hint' => null, 'required' => false])
            @endforeach
        </details>
    </x-ui.card>
</div>
