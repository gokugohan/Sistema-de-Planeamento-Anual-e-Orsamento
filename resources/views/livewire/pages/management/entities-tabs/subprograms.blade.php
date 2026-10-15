<x-ui.card padding="p-0">
    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
            <div>
                <h2 class="text-sm font-semibold text-zinc-900">{{ __('Subprograms Registry') }}</h2>
                <p class="text-xs text-zinc-500 mt-1">{{ __('Subprograms of the programs registered for :year', ['year' => $fundingYear]) }}</p>
            </div>
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label for="f-subFilter" class="sr-only">{{ __('Program') }}</label>
                    <select id="f-subFilter" wire:model.live="subprogramFilter" class="flux-input !w-60">
                        <option value="">{{ __('All programs') }}</option>
                        @foreach($yearPrograms as $p)
                            <option value="{{ $p->id }}">{{ $p->oge_code }} — {{ $p->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="f-subSearch" class="sr-only">{{ __('Search') }}</label>
                    <input id="f-subSearch" type="search" wire:model.live.debounce.300ms="search" class="flux-input !w-60" placeholder="{{ __('Search by code or name...') }}">
                </div>
            </div>
        </div>
    </x-slot>

    <x-ui.table :headers="[__('Program'), __('Code'), __('Subprogram Name'), __('Objective'), __('Activities'), __('Actions')]">
        @forelse($subprograms as $s)
            <tr class="text-sm hover:bg-zinc-50">
                <td class="px-6 py-3"><span class="font-mono font-semibold text-zinc-900">{{ $s->program->oge_code }}</span> <span class="text-zinc-500">{{ \Illuminate\Support\Str::limit($s->program->name, 40) }}</span></td>
                <td class="px-6 py-3 font-mono font-semibold text-zinc-900">{{ $s->code }}</td>
                <td class="px-6 py-3 text-zinc-900">{{ $s->name }}</td>
                <td class="px-6 py-3 text-xs text-zinc-600">{{ $s->objective ? \Illuminate\Support\Str::limit($s->objective, 60) : '—' }}</td>
                <td class="px-6 py-3 tabular-nums">{{ $s->activities_count }}</td>
                <td class="px-6 py-3 text-right whitespace-nowrap">
                    <button type="button" wire:click="openSubprogramModal({{ $s->id }})" class="p-2 text-zinc-500 hover:text-indigo-700 hover:bg-indigo-50 rounded-md" aria-label="{{ __('Edit') }} {{ $s->code }}">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z" /><path d="m15 5 4 4" /></svg>
                    </button>
                    <button type="button" wire:click="deleteSubprogram({{ $s->id }})" wire:confirm="{{ __('Confirm deletion? This will remove the subprogram permanently.') }}"
                        class="p-2 text-zinc-500 hover:text-rose-700 hover:bg-rose-50 rounded-md" aria-label="{{ __('Delete') }} {{ $s->code }}">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18" /><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6" /><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2" /></svg>
                    </button>
                </td>
            </tr>
        @empty
            <tr><td colspan="6"><x-ui.empty-state :title="__('No subprograms registered for :year.', ['year' => $fundingYear])" :text="__('Add one under a program, or copy the programs of another year to bring their subprograms.')" /></td></tr>
        @endforelse
    </x-ui.table>
</x-ui.card>

<div class="px-2">{{ $subprograms->links() }}</div>

@if($subprogramModalOpen)
    <x-dialog max-width="2xl" close="$set('subprogramModalOpen', false)">
        <x-slot:title>{{ $isSubprogramEditing ? __('Update Subprogram') : __('Create Subprogram') }}</x-slot:title>
        <x-slot:subtitle>{{ __('Registered for fiscal year :year', ['year' => $fundingYear]) }}</x-slot:subtitle>

        <form wire:submit="saveSubprogram" class="p-6 space-y-5">
            <div>
                <label class="field-label" for="f-subProgramId">{{ __('Program') }}</label>
                <select id="f-subProgramId" wire:model="subProgramId" class="flux-input">
                    <option value="">{{ __('Select a program') }}</option>
                    @foreach($yearPrograms as $p)
                        <option value="{{ $p->id }}">{{ $p->oge_code }} — {{ $p->name }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('subProgramId')" />
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="field-label" for="f-subCode">{{ __('Code') }}</label>
                    <input id="f-subCode" type="text" wire:model="subCode" class="flux-input" placeholder="e.g. 51001">
                    <x-input-error :messages="$errors->get('subCode')" />
                </div>
                <div class="md:col-span-2">
                    <label class="field-label" for="f-subName">{{ __('Subprogram Name') }}</label>
                    <input id="f-subName" type="text" wire:model="subName" class="flux-input" placeholder="e.g. Institutional operation">
                    <x-input-error :messages="$errors->get('subName')" />
                </div>
            </div>
            <div>
                <label class="field-label" for="f-subObjective">{{ __('Objective') }}</label>
                <textarea id="f-subObjective" wire:model="subObjective" rows="3" class="flux-input"></textarea>
                <x-input-error :messages="$errors->get('subObjective')" />
            </div>
            <div class="flex items-center gap-4 pt-2">
                <x-ui.button type="button" wire:click="$set('subprogramModalOpen', false)" variant="secondary" class="flex-1">{{ __('Go Back') }}</x-ui.button>
                <x-ui.button type="submit" variant="primary" class="flex-1">{{ $isSubprogramEditing ? __('Update Subprogram') : __('Create Subprogram') }}</x-ui.button>
            </div>
        </form>
    </x-dialog>
@endif
