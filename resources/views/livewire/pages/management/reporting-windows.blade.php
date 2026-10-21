<?php

use App\Models\ReportingWindow;
use App\Models\WindowExtension;
use App\Services\ReportingWindows;
use Livewire\Volt\Component;

/**
 * The calendar of the plan: when the annual plan is submitted, decided on and finalized, when a revision may be made,
 * and when a month or a quarter may be reported. A period with no dates is open. When a unit is late, the administrator
 * reopens the period for that unit only, until a date.
 */
new class extends Component {
    public int $year;
    public string $periodType = 'plan';
    public int $period = 1;
    public string $opensOn = '';
    public string $closesOn = '';
    public string $note = '';

    /** @var array<int, string> new closing date per late unit (row of the list), and why */
    public array $until = [];
    public array $why = [];

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);
        $this->year = \App\Support\FiscalYear::current();
    }

    public function updatedPeriodType(): void
    {
        $this->period = 1;
    }

    public function save(): void
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);
        $max = match ($this->periodType) { 'month' => 12, 'quarter' => 4, default => 1 };
        $this->validate([
            'periodType' => 'required|in:plan,approval,finalize,revision,month,quarter',
            'period' => "required|integer|min:1|max:$max",
            'opensOn' => 'required|date',
            'closesOn' => 'required|date|after_or_equal:opensOn',
            'note' => 'nullable|string|max:200',
        ]);

        ReportingWindow::updateOrCreate(
            ['fiscal_year' => $this->year, 'period_type' => $this->periodType, 'period' => $this->period],
            ['opens_on' => $this->opensOn, 'closes_on' => $this->closesOn, 'note' => trim($this->note) ?: null, 'created_by' => auth()->id()]
        );
        $this->reset('opensOn', 'closesOn', 'note');
        $this->dispatch('toast', message: __('Calendar saved.'), type: 'success');
    }

    public function remove(int $id): void
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);
        ReportingWindow::where('fiscal_year', $this->year)->whereKey($id)->delete();
        $this->dispatch('toast', message: __('Dates removed.'), type: 'success');
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
        return [
            'windows' => ReportingWindow::where('fiscal_year', $this->year)->orderByRaw("FIELD(period_type, 'plan', 'approval', 'finalize', 'revision', 'month', 'quarter')")->orderBy('period')->get(),
            'late' => app(ReportingWindows::class)->overdue($this->year),
            'reopened' => WindowExtension::where('fiscal_year', $this->year)->whereDate('closes_on', '>=', now()->toDateString())->with('directorate:id,name')->orderBy('closes_on')->get(),
        ];
    }
}; ?>

<div class="space-y-6">
    <x-ui.page-header :title="__('Calendar')" :subtitle="__('The dates for planning, approval, finalizing the plan, revisions and reporting. A step with no dates is open. Administrators can always act; a late unit can be given more time on its own.')">
        <span class="inline-flex items-center rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-800">{{ __('Fiscal year') }} {{ $year }}</span>
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

    <x-ui.card :title="__('Set the dates')" padding="p-5">
        <form wire:submit="save" class="grid gap-4 md:grid-cols-12">
            <div class="md:col-span-3">
                <label for="rw-t" class="field-label">{{ __('Step') }}</label>
                <select id="rw-t" wire:model.live="periodType" class="flux-input">
                    <option value="plan">{{ __('Annual plan') }} · {{ __('submission') }}</option>
                    <option value="approval">{{ __('Annual plan') }} · {{ __('approval') }}</option>
                    <option value="finalize">{{ __('Annual plan') }} · {{ __('finalizing') }}</option>
                    <option value="revision">{{ __('Revisions of approved activities') }}</option>
                    <option value="month">{{ __('Monthly report') }}</option>
                    <option value="quarter">{{ __('Quarterly report') }}</option>
                </select>
            </div>
            <div class="md:col-span-1 @if(! in_array($periodType, ['month', 'quarter'], true)) hidden @endif">
                <label for="rw-p" class="field-label">{{ $periodType === 'month' ? __('Month') : __('Quarter') }}</label>
                <select id="rw-p" wire:model="period" class="flux-input">
                    @foreach(range(1, $periodType === 'month' ? 12 : 4) as $p)
                        <option value="{{ $p }}">{{ $periodType === 'month' ? \Carbon\Carbon::create($year, $p, 1)->translatedFormat('F') : 'Q'.$p }}</option>
                    @endforeach
                </select>
            </div>
            <div class="md:col-span-2">
                <label for="rw-o" class="field-label">{{ __('Opens on') }}</label>
                <input id="rw-o" type="date" wire:model="opensOn" class="flux-input">
                @error('opensOn')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <div class="md:col-span-2">
                <label for="rw-c" class="field-label">{{ __('Closes on') }}</label>
                <input id="rw-c" type="date" wire:model="closesOn" class="flux-input">
                @error('closesOn')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <div class="md:col-span-3">
                <label for="rw-n" class="field-label">{{ __('Note') }}</label>
                <input id="rw-n" type="text" wire:model="note" class="flux-input">
            </div>
            <div class="flex items-end md:col-span-1">
                <x-ui.button type="submit">{{ __('Save') }}</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card :title="__('The year in dates')" padding="p-0">
        @if($windows->isEmpty())
            <x-ui.empty-state :title="__('No dates set')" :text="__('Every step is open: planning, approval, finalizing and reporting.')" />
        @else
            <x-ui.table :headers="[__('Step'), __('Opens on'), __('Closes on'), __('Now'), __('Note'), '']" class="text-sm">
                @foreach($windows as $w)
                    @php
                        $today = now()->startOfDay();
                        [$state, $tone] = match (true) {
                            $today->lt($w->opens_on) => [__('Not open yet'), 'bg-zinc-100 text-zinc-700'],
                            $today->gt($w->closes_on) => [__('Closed'), 'bg-rose-50 text-rose-800'],
                            default => [__('Open'), 'bg-emerald-50 text-emerald-800'],
                        };
                    @endphp
                    <tr wire:key="rw-{{ $w->id }}">
                        <td class="px-5 py-3 font-medium text-zinc-900">{{ app(\App\Services\ReportingWindows::class)->label($w->period_type, $w->period, $year) }}</td>
                        <td class="px-5 py-3 tabular-nums">{{ $w->opens_on->format('d/m/Y') }}</td>
                        <td class="px-5 py-3 tabular-nums">{{ $w->closes_on->format('d/m/Y') }}</td>
                        <td class="px-5 py-3"><span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium {{ $tone }}">{{ $state }}</span></td>
                        <td class="px-5 py-3 text-zinc-600">{{ $w->note }}</td>
                        <td class="px-5 py-3 text-right"><button type="button" wire:click="remove({{ $w->id }})" class="text-xs font-medium text-rose-700 hover:underline">{{ __('Remove') }}</button></td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>
</div>
