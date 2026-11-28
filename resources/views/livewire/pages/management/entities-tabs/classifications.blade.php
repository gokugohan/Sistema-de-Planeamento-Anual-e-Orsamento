{{-- The economic categories of the State Budget (salaries, goods and services, transfers, minor capital, development
     capital): each budget line of an activity is in one of them. --}}
<x-ui.card padding="p-0">
    <div class="flex flex-col gap-3 border-b border-zinc-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-sm font-semibold text-zinc-900">{{ __('Economic categories') }}</h2>
            <p class="mt-0.5 text-xs text-zinc-500">{{ __('Every budget line of an activity is in one of them; the reports total the plan by category.') }}</p>
        </div>
        <x-ui.search wire:model.live.debounce.300ms="search" :placeholder="__('Search a category by code or name')" />
    </div>

    <div class="overflow-x-auto" tabindex="0">
        <table class="min-w-full text-sm">
            <thead class="bg-zinc-50 text-left text-xs font-medium text-zinc-500">
                <tr>
                    <th scope="col" class="w-24 px-5 py-3">{{ __('Code') }}</th>
                    <th scope="col" class="px-5 py-3">{{ __('Category') }}</th>
                    <th scope="col" class="px-5 py-3">{{ __('Description') }}</th>
                    <th scope="col" class="px-5 py-3">{{ __('Type') }}</th>
                    <th scope="col" class="px-5 py-3"><span class="sr-only">{{ __('Actions') }}</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @forelse($economicCategories as $c)
                    <tr class="hover:bg-zinc-50/60" wire:key="cat-{{ $c->id }}">
                        <td class="px-5 py-3 font-mono font-medium text-zinc-900">{{ $c->code }}</td>
                        <td class="px-5 py-3 font-medium text-zinc-900">{{ $c->name }}</td>
                        <td class="px-5 py-3 text-xs text-zinc-600">{{ $c->description ? \Illuminate\Support\Str::limit($c->description, 90) : '—' }}</td>
                        <td class="px-5 py-3"><x-ui.badge :variant="$c->category_type === 'Revenue' ? 'emerald' : 'gray'">{{ __($c->category_type ?: 'Expense') }}</x-ui.badge></td>
                        <td class="whitespace-nowrap px-5 py-3 text-right">
                            <x-ui.icon-action icon="edit" :label="__('Edit').' '.$c->code" wire:click="openClassificationModal({{ $c->id }})" />
                            <x-ui.icon-action icon="delete" :label="__('Delete').' '.$c->code" wire:click="deleteClassification({{ $c->id }})" :confirm="__('Delete this category? This cannot be undone.')" />
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5"><x-ui.empty-state :title="__('No economic categories found.')" /></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-ui.card>
<div>{{ $economicCategories->links() }}</div>

@if($classificationModalOpen)
    <x-slide-over close="$set('classificationModalOpen', false)" width="lg">
        <x-slot:eyebrow>{{ __('Economic categories') }}</x-slot:eyebrow>
        <x-slot:title>{{ $isClassificationEditing ? __('Edit category') : __('New category') }}</x-slot:title>

        <form id="category-form" wire:submit="saveClassification" class="space-y-5 p-6">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-[8rem_1fr]">
                <div>
                    <label class="field-label" for="f-classificationCode">{{ __('Code') }}</label>
                    <input id="f-classificationCode" type="text" wire:model="classificationCode" class="flux-input font-mono" placeholder="{{ __('e.g. 20') }}">
                    <x-input-error :messages="$errors->get('classificationCode')" />
                </div>
                <div>
                    <label class="field-label" for="f-classificationName">{{ __('Name') }}</label>
                    <input id="f-classificationName" type="text" wire:model="classificationName" class="flux-input">
                    <x-input-error :messages="$errors->get('classificationName')" />
                </div>
            </div>
            <div>
                <label class="field-label" for="f-classificationType">{{ __('Type') }}</label>
                <select id="f-classificationType" wire:model="classificationType" class="flux-input">
                    <option value="Expense">{{ __('Expense') }}</option>
                    <option value="Revenue">{{ __('Revenue') }}</option>
                </select>
                <x-input-error :messages="$errors->get('classificationType')" />
            </div>
            <div>
                <label class="field-label" for="f-classificationDescription">{{ __('Description') }} <span class="font-normal text-zinc-500">({{ __('optional') }})</span></label>
                <textarea id="f-classificationDescription" wire:model="classificationDescription" rows="3" class="flux-input"></textarea>
                <x-input-error :messages="$errors->get('classificationDescription')" />
            </div>
        </form>

        <x-slot:footer>
            <x-ui.button type="button" variant="secondary" shadow="none" wire:click="$set('classificationModalOpen', false)">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="category-form">{{ $isClassificationEditing ? __('Save category') : __('Add category') }}</x-ui.button>
        </x-slot:footer>
    </x-slide-over>
@endif
