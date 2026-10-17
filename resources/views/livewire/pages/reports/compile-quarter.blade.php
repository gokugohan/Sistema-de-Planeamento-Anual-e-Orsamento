<?php

use App\Models\Activity;
use App\Services\QuarterCompilation;
use Livewire\Volt\Component;

/**
 * Compile the quarter: the director of a unit turns what the unit reported (three months and the quarter)
 * into the text that goes in the ministry's report. A draft is built from the reports and edited here; the
 * unit's own text is never changed.
 */
new class extends Component {
    public int $year;
    public int $quarter = 1;
    public array $form = [];

    public function mount(): void
    {
        abort_unless($this->allowed(), 403);
        $this->year = \App\Support\FiscalYear::current();
        $this->quarter = $this->latestQuarter();
        $this->fillForm();
    }

    private function allowed(): bool
    {
        $u = auth()->user();

        return $u && ($u->isAdministrator() || $u->isDirector());
    }

    /** The newest quarter that has started. */
    private function latestQuarter(): int
    {
        return match (true) {
            now()->year < $this->year => 1,
            now()->year > $this->year => 4,
            default => (int) ceil(now()->month / 3),
        };
    }

    public function updatedQuarter(): void
    {
        $this->quarter = max(1, min((int) $this->quarter, $this->latestQuarter()));
        $this->fillForm();
    }

    private function service(): QuarterCompilation
    {
        return app(QuarterCompilation::class);
    }

    public function getActivitiesProperty()
    {
        return $this->service()->activities(auth()->user(), $this->year)
            ->with('directorate:id,code,name')->orderBy('directorate_id')->orderBy('id')->limit(200)->get(['id', 'code', 'description', 'directorate_id', 'fiscal_year', 'status']);
    }

    private function fillForm(): void
    {
        $this->form = [];
        foreach ($this->activities as $a) {
            $c = $this->service()->find($a, $this->quarter);
            $this->form[$a->id] = ['results' => $c?->compiled_results ?? '', 'constraints' => $c?->compiled_constraints ?? ''];
        }
    }

    private function activity(int $id): Activity
    {
        $a = Activity::findOrFail($id);
        abort_unless($this->service()->canCompile(auth()->user(), $a), 403);

        return $a;
    }

    /** Fill the boxes with a first draft built from the reports: nothing is saved until the director saves. */
    public function draft(int $id): void
    {
        $d = $this->service()->draft($this->activity($id), $this->quarter);
        $this->form[$id] = $d;
    }

    public function save(int $id): void
    {
        $a = $this->activity($id);
        $this->validate(["form.$id.results" => 'nullable|string|max:5000', "form.$id.constraints" => 'nullable|string|max:5000']);
        $this->service()->save(auth()->user(), $a, $this->quarter, $this->form[$id]['results'] ?? '', $this->form[$id]['constraints'] ?? '');
        $this->dispatch('toast', message: __('Compiled text saved.'), type: 'success');
    }

    public function with(): array
    {
        $svc = $this->service();
        $rows = $this->activities->map(fn ($a) => ['activity' => $a, 'sources' => $svc->sources($a, $this->quarter), 'compiled' => $svc->find($a, $this->quarter)]);

        return ['rows' => $rows, 'maxQuarter' => $this->latestQuarter()];
    }
}; ?>

<div class="space-y-6">
    <x-ui.page-header :title="__('Compile the quarter')" :subtitle="__('Turn what your units reported in the three months and in the quarter into the text that goes in the ministry\'s report. Start from a draft, edit it, save. What the units wrote is kept as it was.')">
        <span class="inline-flex items-center rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-800">{{ __('Fiscal year') }} {{ $year }}</span>
    </x-ui.page-header>

    <x-ui.card padding="p-5">
        <div class="max-w-xs">
            <label for="cq-q" class="field-label">{{ __('Quarter') }}</label>
            <select id="cq-q" wire:model.live="quarter" class="flux-input">
                @foreach(range(1, $maxQuarter) as $q)<option value="{{ $q }}">Q{{ $q }}</option>@endforeach
            </select>
        </div>
    </x-ui.card>

    @forelse($rows as $r)
        @php $a = $r['activity']; $id = $a->id; $src = $r['sources']; @endphp
        <x-ui.card padding="p-5" wire:key="cq-{{ $id }}-{{ $quarter }}">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-xs font-medium text-zinc-500">{{ $a->directorate?->code }} · {{ $a->code }}</p>
                    <h2 class="text-sm font-semibold text-zinc-900">{{ $a->description }}</h2>
                </div>
                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium {{ $r['compiled']?->compiled_at ? 'bg-emerald-50 text-emerald-800' : 'bg-zinc-100 text-zinc-700' }}">
                    {{ $r['compiled']?->compiled_at ? __('Compiled') : __('Not compiled') }}
                </span>
            </div>

            <details class="mt-3 rounded-md border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm">
                <summary class="cursor-pointer font-medium text-indigo-700">{{ __('What the unit reported') }} ({{ $src['months']->count() + ($src['quarter'] ? 1 : 0) }})</summary>
                <ul class="mt-2 space-y-2 text-zinc-700">
                    @forelse($src['months'] as $m)
                        <li><span class="font-medium">{{ \Carbon\Carbon::create($year, $m->period, 1)->translatedFormat('F') }}:</span> {{ $m->results }}@if($m->constraints) <span class="text-zinc-500">({{ __('Constraints') }}: {{ $m->constraints }})</span>@endif</li>
                    @empty
                    @endforelse
                    @if($src['quarter'])<li><span class="font-medium">Q{{ $quarter }}:</span> {{ $src['quarter']->results }}@if($src['quarter']->constraints) <span class="text-zinc-500">({{ __('Constraints') }}: {{ $src['quarter']->constraints }})</span>@endif</li>@endif
                    @if($src['months']->isEmpty() && ! $src['quarter'])<li class="text-zinc-500">{{ __('Nothing verified yet for this quarter.') }}</li>@endif
                </ul>
            </details>

            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <div>
                    <label for="cq-r-{{ $id }}" class="field-label">{{ __('Results achieved') }}</label>
                    <textarea id="cq-r-{{ $id }}" rows="5" wire:model="form.{{ $id }}.results" class="flux-input"></textarea>
                </div>
                <div>
                    <label for="cq-c-{{ $id }}" class="field-label">{{ __('Implementation constraints') }}</label>
                    <textarea id="cq-c-{{ $id }}" rows="5" wire:model="form.{{ $id }}.constraints" class="flux-input"></textarea>
                </div>
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-3">
                <x-ui.button type="button" variant="secondary" shadow="none" wire:click="draft({{ $id }})">{{ __('Draft from the reports') }}</x-ui.button>
                <x-ui.button type="button" wire:click="save({{ $id }})">{{ __('Save') }}</x-ui.button>
            </div>
        </x-ui.card>
    @empty
        <x-ui.card padding="p-0"><x-ui.empty-state :title="__('No approved activities to compile')" :text="__('Approved activities of the units you direct appear here.')" /></x-ui.card>
    @endforelse
</div>
