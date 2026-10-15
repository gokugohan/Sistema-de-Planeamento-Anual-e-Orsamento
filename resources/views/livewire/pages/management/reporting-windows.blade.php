<?php

use App\Models\FiscalEnvelope;
use App\Models\ReportingWindow;
use Livewire\Volt\Component;

/** When each month and quarter may be reported. A period with no window is open. */
new class extends Component {
    public int $year;
    public string $periodType = 'month';
    public int $period = 1;
    public string $opensOn = '';
    public string $closesOn = '';
    public string $note = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);
        $this->year = FiscalEnvelope::getEffectiveYear();
    }

    public function save(): void
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);
        $max = $this->periodType === 'month' ? 12 : 4;
        $this->validate([
            'periodType' => 'required|in:month,quarter',
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
        $this->dispatch('toast', message: __('Reporting window saved.'), type: 'success');
    }

    public function remove(int $id): void
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);
        ReportingWindow::where('fiscal_year', $this->year)->whereKey($id)->delete();
        $this->dispatch('toast', message: __('Reporting window removed.'), type: 'success');
    }

    public function with(): array
    {
        return ['windows' => ReportingWindow::where('fiscal_year', $this->year)->orderBy('period_type', 'desc')->orderBy('period')->get()];
    }
}; ?>

<div class="space-y-6">
    <x-ui.page-header :title="__('Reporting windows')" :subtitle="__('Set the dates between which a month or a quarter may be reported. A period with no window is open. Administrators can always report.')">
        <span class="inline-flex items-center rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-800">{{ __('Fiscal year') }} {{ $year }}</span>
    </x-ui.page-header>

    <x-ui.card :title="__('Open a period')" padding="p-5">
        <form wire:submit="save" class="grid gap-4 md:grid-cols-12">
            <div class="md:col-span-2">
                <label for="rw-t" class="field-label">{{ __('Reports') }}</label>
                <select id="rw-t" wire:model.live="periodType" class="flux-input">
                    <option value="month">{{ __('Monthly') }}</option>
                    <option value="quarter">{{ __('Quarterly') }}</option>
                </select>
            </div>
            <div class="md:col-span-2">
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

    <x-ui.card padding="p-0">
        @if($windows->isEmpty())
            <x-ui.empty-state :title="__('No reporting windows')" :text="__('Every month and quarter is open for reporting.')" />
        @else
            <x-ui.table :headers="[__('Period'), __('Opens on'), __('Closes on'), __('Note'), '']" class="text-sm">
                @foreach($windows as $w)
                    <tr wire:key="rw-{{ $w->id }}">
                        <td class="px-5 py-3 font-medium text-zinc-900">{{ $w->period_type === 'month' ? \Carbon\Carbon::create($year, $w->period, 1)->translatedFormat('F') : 'Q'.$w->period }}</td>
                        <td class="px-5 py-3 tabular-nums">{{ $w->opens_on->format('d/m/Y') }}</td>
                        <td class="px-5 py-3 tabular-nums">{{ $w->closes_on->format('d/m/Y') }}</td>
                        <td class="px-5 py-3 text-zinc-600">{{ $w->note }}</td>
                        <td class="px-5 py-3 text-right"><button type="button" wire:click="remove({{ $w->id }})" class="text-xs font-medium text-rose-700 hover:underline">{{ __('Remove') }}</button></td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>
</div>
