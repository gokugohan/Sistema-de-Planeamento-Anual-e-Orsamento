<?php

use App\Models\Alignment;
use App\Models\Program;
use App\Models\ResultIndicator;
use App\Models\Subprogram;
use Livewire\Volt\Component;

/**
 * What a programme or subprogramme is for: the national objectives it contributes to (SDG, strategic plan)
 * and its own result indicators (the change it should bring, described with a baseline and a target, not
 * reported quarter by quarter). Shown inside the dialog of the Entities page; administrators only.
 */
new class extends Component {
    public string $type = 'program';   // program | subprogram
    public int $targetId;

    public ?int $indicatorId = null;
    public string $indName = '';
    public string $indBaseline = '';
    public string $indTarget = '';
    public string $indMeans = '';

    public string $framework = 'SDG';
    public string $reference = '';
    public string $description = '';

    public function mount(string $type, int $id): void
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);
        $this->type = $type === 'subprogram' ? 'subprogram' : 'program';
        $this->targetId = $id;
        $this->target(); // 404 if it does not exist
    }

    private function target(): Program|Subprogram
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);

        return $this->type === 'subprogram' ? Subprogram::findOrFail($this->targetId) : Program::findOrFail($this->targetId);
    }

    // ── result indicators

    public function editIndicator(int $id): void
    {
        $i = $this->target()->resultIndicators()->findOrFail($id);
        $this->indicatorId = $i->id;
        $this->indName = $i->name;
        $this->indBaseline = (string) $i->baseline;
        $this->indTarget = (string) $i->target;
        $this->indMeans = (string) $i->means_of_verification;
    }

    public function saveIndicator(): void
    {
        $target = $this->target();
        $this->validate([
            'indName' => ['required', 'string', 'max:500'],
            'indBaseline' => ['nullable', 'string', 'max:255'],
            'indTarget' => ['nullable', 'string', 'max:255'],
            'indMeans' => ['nullable', 'string', 'max:1000'],
        ], [], ['indName' => __('indicator'), 'indBaseline' => __('baseline'), 'indTarget' => __('target'), 'indMeans' => __('means of verification')]);

        $data = ['name' => trim($this->indName), 'baseline' => trim($this->indBaseline) ?: null, 'target' => trim($this->indTarget) ?: null, 'means_of_verification' => trim($this->indMeans) ?: null];
        $this->indicatorId ? $target->resultIndicators()->findOrFail($this->indicatorId)->update($data) : $target->resultIndicators()->create($data);

        $this->resetIndicatorForm();
    }

    public function deleteIndicator(int $id): void
    {
        $this->target()->resultIndicators()->findOrFail($id)->delete();
        $this->resetIndicatorForm();
    }

    public function resetIndicatorForm(): void
    {
        $this->reset(['indicatorId', 'indName', 'indBaseline', 'indTarget', 'indMeans']);
        $this->resetValidation();
    }

    // ── alignments

    public function saveAlignment(): void
    {
        $target = $this->target();
        $this->validate([
            'framework' => ['required', 'in:'.implode(',', Alignment::FRAMEWORKS)],
            'reference' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
        ], [], ['reference' => __('reference'), 'description' => __('description')]);

        $target->alignments()->create(['framework' => $this->framework, 'reference' => trim($this->reference), 'description' => trim($this->description) ?: null]);
        $this->reset(['reference', 'description']);
        $this->resetValidation();
    }

    public function deleteAlignment(int $id): void
    {
        $this->target()->alignments()->findOrFail($id)->delete();
    }

    public function with(): array
    {
        $target = $this->target();

        return [
            'indicators' => $target->resultIndicators()->orderBy('id')->get(),
            'alignments' => $target->alignments()->orderBy('framework')->orderBy('reference')->get(),
        ];
    }
}; ?>

<div class="space-y-8 p-6">
    {{-- National objectives --}}
    <section aria-labelledby="al-title">
        <h3 id="al-title" class="text-sm font-semibold text-zinc-900">{{ __('Contributes to') }}</h3>
        <p class="mt-0.5 text-xs text-zinc-500">{{ __('The national objectives this contributes to: a Sustainable Development Goal, the strategic plan, or another.') }}</p>

        <ul class="mt-3 divide-y divide-zinc-100 rounded-md border border-zinc-200">
            @forelse($alignments as $al)
                <li class="flex items-start justify-between gap-3 px-4 py-2.5 text-sm">
                    <div class="min-w-0">
                        <span class="mr-2 inline-flex rounded bg-indigo-50 px-1.5 py-0.5 text-xs font-semibold text-indigo-800">{{ $al->framework }}</span>
                        <span class="font-medium text-zinc-900">{{ $al->reference }}</span>
                        @if($al->description)<p class="mt-0.5 text-xs text-zinc-600">{{ $al->description }}</p>@endif
                    </div>
                    <button type="button" wire:click="deleteAlignment({{ $al->id }})" wire:confirm="{{ __('Remove this objective?') }}" class="shrink-0 text-xs font-medium text-rose-700 hover:underline" aria-label="{{ __('Remove') }} {{ $al->reference }}">{{ __('Remove') }}</button>
                </li>
            @empty
                <li class="px-4 py-3 text-sm text-zinc-500">{{ __('None yet.') }}</li>
            @endforelse
        </ul>

        <form wire:submit="saveAlignment" class="mt-3 grid gap-3 sm:grid-cols-[8rem_1fr_auto] sm:items-end">
            <div>
                <label for="al-framework" class="field-label">{{ __('Framework') }}</label>
                <select id="al-framework" wire:model="framework" class="flux-input">
                    <option value="SDG">{{ __('SDG (ODS)') }}</option>
                    <option value="PED">{{ __('Strategic plan (PED)') }}</option>
                    <option value="Other">{{ __('Other') }}</option>
                </select>
            </div>
            <div>
                <label for="al-reference" class="field-label">{{ __('Reference') }}</label>
                <input id="al-reference" type="text" wire:model="reference" class="flux-input" placeholder="{{ __('e.g. SDG 9, or a goal of the strategic plan') }}">
                @error('reference')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <x-ui.button type="submit" variant="secondary" shadow="none">{{ __('Add objective') }}</x-ui.button>
            <div class="sm:col-span-3">
                <label for="al-description" class="field-label">{{ __('Description') }} <span class="font-normal text-zinc-500">({{ __('optional') }})</span></label>
                <input id="al-description" type="text" wire:model="description" class="flux-input">
            </div>
        </form>
    </section>

    {{-- Result indicators --}}
    <section aria-labelledby="ri-title">
        <h3 id="ri-title" class="text-sm font-semibold text-zinc-900">{{ __('Result indicators') }}</h3>
        <p class="mt-0.5 text-xs text-zinc-500">{{ __('The change this is meant to bring, with where it stands and where it should get to. Described here; the activities report their own indicators every quarter.') }}</p>

        <ul class="mt-3 divide-y divide-zinc-100 rounded-md border border-zinc-200">
            @forelse($indicators as $ri)
                <li class="flex items-start justify-between gap-3 px-4 py-2.5 text-sm">
                    <div class="min-w-0">
                        <p class="font-medium text-zinc-900">{{ $ri->name }}</p>
                        <p class="mt-0.5 text-xs text-zinc-600">
                            @if($ri->baseline){{ __('Baseline') }}: {{ $ri->baseline }}@endif
                            @if($ri->baseline && $ri->target) · @endif
                            @if($ri->target){{ __('Target') }}: {{ $ri->target }}@endif
                        </p>
                        @if($ri->means_of_verification)<p class="mt-0.5 text-xs text-zinc-500">{{ __('Means of verification') }}: {{ $ri->means_of_verification }}</p>@endif
                    </div>
                    <div class="flex shrink-0 gap-3 text-xs font-medium">
                        <button type="button" wire:click="editIndicator({{ $ri->id }})" class="text-indigo-700 hover:underline" aria-label="{{ __('Edit') }}: {{ $ri->name }}">{{ __('Edit') }}</button>
                        <button type="button" wire:click="deleteIndicator({{ $ri->id }})" wire:confirm="{{ __('Remove this indicator?') }}" class="text-rose-700 hover:underline" aria-label="{{ __('Remove') }}: {{ $ri->name }}">{{ __('Remove') }}</button>
                    </div>
                </li>
            @empty
                <li class="px-4 py-3 text-sm text-zinc-500">{{ __('None yet.') }}</li>
            @endforelse
        </ul>

        <form wire:submit="saveIndicator" class="mt-3 grid gap-3 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label for="ri-name" class="field-label">{{ $indicatorId ? __('Edit indicator') : __('New indicator') }}</label>
                <input id="ri-name" type="text" wire:model="indName" class="flux-input" placeholder="{{ __('e.g. % of public institutions with secure connectivity') }}">
                @error('indName')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="ri-baseline" class="field-label">{{ __('Baseline') }}</label>
                <input id="ri-baseline" type="text" wire:model="indBaseline" class="flux-input">
            </div>
            <div>
                <label for="ri-target" class="field-label">{{ __('Target') }}</label>
                <input id="ri-target" type="text" wire:model="indTarget" class="flux-input">
            </div>
            <div class="sm:col-span-2">
                <label for="ri-means" class="field-label">{{ __('Means of verification') }}</label>
                <textarea id="ri-means" rows="2" wire:model="indMeans" class="flux-input"></textarea>
            </div>
            <div class="flex gap-2 sm:col-span-2">
                <x-ui.button type="submit" variant="primary">{{ $indicatorId ? __('Save indicator') : __('Add indicator') }}</x-ui.button>
                @if($indicatorId)<x-ui.button type="button" variant="secondary" shadow="none" wire:click="resetIndicatorForm">{{ __('Cancel') }}</x-ui.button>@endif
            </div>
        </form>
    </section>
</div>
