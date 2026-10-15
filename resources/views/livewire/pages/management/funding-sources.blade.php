<?php

use App\Models\FundingSource;
use Livewire\Volt\Component;

/** The short list of funding sources a budget line can be paid from (State Budget, a development partner, ...). */
new class extends Component {
    public string $code = '';
    public string $name = '';
    public string $kind = 'Partner';
    public ?int $editId = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);
    }

    public function save(): void
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);
        $unique = 'unique:funding_sources,code'.($this->editId ? ",{$this->editId}" : '');
        $this->validate([
            'code' => ['required', 'string', 'max:20', 'alpha_dash', $unique],
            'name' => 'required|string|max:100',
            'kind' => 'required|in:'.implode(',', FundingSource::KINDS),
        ]);
        $data = ['code' => strtoupper(trim($this->code)), 'name' => trim($this->name), 'kind' => $this->kind];
        $this->editId ? FundingSource::findOrFail($this->editId)->update($data) : FundingSource::create($data);
        $this->cancel();
        $this->dispatch('toast', message: __('Funding source saved.'), type: 'success');
    }

    public function edit(int $id): void
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);
        $s = FundingSource::findOrFail($id);
        $this->editId = $s->id;
        $this->code = $s->code;
        $this->name = $s->name;
        $this->kind = $s->kind;
    }

    public function cancel(): void
    {
        $this->reset('code', 'name', 'editId');
        $this->kind = 'Partner';
        $this->resetValidation();
    }

    public function makeDefault(int $id): void
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);
        $s = FundingSource::where('active', true)->findOrFail($id);
        FundingSource::where('is_default', true)->update(['is_default' => false]);
        $s->update(['is_default' => true]);
    }

    public function toggle(int $id): void
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);
        $s = FundingSource::findOrFail($id);
        if ($s->is_default && $s->active) {
            $this->dispatch('toast', message: __('The default source cannot be switched off: choose another default first.'), type: 'error');

            return;
        }
        $s->update(['active' => ! $s->active]);
    }

    public function remove(int $id): void
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);
        $s = FundingSource::withCount('lineItems')->findOrFail($id);
        if ($s->is_default || $s->line_items_count > 0) {
            $this->dispatch('toast', message: __('A source that is the default or has budget lines cannot be deleted. Switch it off instead.'), type: 'error');

            return;
        }
        $s->delete();
    }

    public function with(): array
    {
        return ['sources' => FundingSource::withCount('lineItems')->orderByDesc('is_default')->orderBy('name')->get()];
    }
}; ?>

<div class="space-y-6">
    <x-ui.page-header :title="__('Funding sources')" :subtitle="__('Where the money of a budget line comes from. Each budget line picks one; the reports show them. New lines start with the default.')" />

    <x-ui.card :title="$editId ? __('Edit funding source') : __('Add a funding source')" padding="p-5">
        <form wire:submit="save" class="grid gap-4 md:grid-cols-12">
            <div class="md:col-span-2">
                <label for="fs-c" class="field-label">{{ __('Code') }}</label>
                <input id="fs-c" type="text" wire:model="code" class="flux-input" placeholder="WB">
                @error('code')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <div class="md:col-span-5">
                <label for="fs-n" class="field-label">{{ __('Name') }}</label>
                <input id="fs-n" type="text" wire:model="name" class="flux-input" placeholder="{{ __('e.g. World Bank grant') }}">
                @error('name')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <div class="md:col-span-3">
                <label for="fs-k" class="field-label">{{ __('Kind') }}</label>
                <select id="fs-k" wire:model="kind" class="flux-input">
                    <option value="State">{{ __('State') }}</option>
                    <option value="Partner">{{ __('Development partner') }}</option>
                    <option value="Other">{{ __('Other') }}</option>
                </select>
            </div>
            <div class="flex items-end gap-2 md:col-span-2">
                <x-ui.button type="submit">{{ __('Save') }}</x-ui.button>
                @if($editId)<x-ui.button type="button" variant="secondary" shadow="none" wire:click="cancel">{{ __('Cancel') }}</x-ui.button>@endif
            </div>
        </form>
    </x-ui.card>

    <x-ui.card padding="p-0">
        <x-ui.table :headers="[__('Code'), __('Name'), __('Kind'), __('Budget lines'), __('Status'), '']" :right="[3]" class="text-sm">
            @foreach($sources as $s)
                <tr wire:key="fs-{{ $s->id }}">
                    <td class="px-5 py-3 font-medium tabular-nums text-zinc-900">{{ $s->code }}</td>
                    <td class="px-5 py-3">{{ __($s->name) }}@if($s->is_default) <span class="ml-1 rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-800">{{ __('Default') }}</span>@endif</td>
                    <td class="px-5 py-3 text-zinc-600">{{ __($s->kind === 'Partner' ? 'Development partner' : $s->kind) }}</td>
                    <td class="px-5 py-3 num">{{ $s->line_items_count }}</td>
                    <td class="px-5 py-3"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium {{ $s->active ? 'bg-emerald-50 text-emerald-800' : 'bg-zinc-100 text-zinc-700' }}">{{ $s->active ? __('Active') : __('Switched off') }}</span></td>
                    <td class="px-5 py-3 text-right">
                        <div class="flex justify-end gap-3 text-xs font-medium">
                            <button type="button" wire:click="edit({{ $s->id }})" class="text-indigo-700 hover:underline">{{ __('Edit') }}</button>
                            @if(! $s->is_default && $s->active)<button type="button" wire:click="makeDefault({{ $s->id }})" class="text-indigo-700 hover:underline">{{ __('Make default') }}</button>@endif
                            @if(! $s->is_default)<button type="button" wire:click="toggle({{ $s->id }})" class="text-zinc-700 hover:underline">{{ $s->active ? __('Switch off') : __('Switch on') }}</button>@endif
                            @if(! $s->is_default && $s->line_items_count === 0)<button type="button" wire:click="remove({{ $s->id }})" wire:confirm="{{ __('Delete this funding source?') }}" class="text-rose-700 hover:underline">{{ __('Delete') }}</button>@endif
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</div>
