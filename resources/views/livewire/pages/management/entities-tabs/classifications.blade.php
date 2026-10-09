<x-ui.card padding="p-0">
    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-center gap-6">
            <div>
                <h2 class="text-sm font-semibold text-zinc-900 ">
                    {{ __('Economic Classifications Registry') }}
                </h2>
                <p class="text-xs text-zinc-400 font-bold mt-1">
                    {{ __('Active Economic Classifications') }}
                </p>
            </div>
            <div class="relative group">
                <span
                    class="absolute inset-y-0 left-0 pl-4 flex items-center text-zinc-300 group-focus-within:text-indigo-500 transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8" />
                        <path d="m21 21-4.3-4.3" />
                    </svg>
                </span>
                <input type="text" wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search by code or name...') }}"
                    class="pl-12 h-11 w-72 bg-zinc-50 border-zinc-100 rounded-xl text-xs font-bold text-zinc-600 focus:ring-4 focus:ring-indigo-500/5 focus:border-indigo-500 focus:bg-white transition-all placeholder:text-zinc-300">
            </div>
        </div>
    </x-slot>

    <div class="overflow-x-auto">
        <x-ui.table :headers="[__('Code'), __('Category Name'), __('Description'), __('Type'), __('Actions')]">
            @forelse($economicCategories as $c)
                <tr class="hover:bg-gray-50/50 transition-colors group text-sm">
                    <td class="px-8 py-5 text-gray-900 font-semibold font-mono">{{ $c->code }}
                    </td>
                    <td class="px-8 py-5">
                        <div class="font-bold text-gray-900">{{ $c->name }}</div>
                    </td>
                    <td class="px-8 py-5">
                        <div class="text-xs text-gray-600 line-clamp-2">
                            {{ $c->description ? Str::limit($c->description, 80) : __('No description') }}
                        </div>
                    </td>
                    <td class="px-8 py-5">
                        <x-ui.badge variant="{{ $c->category_type === 'Expense' ? 'rose' : 'emerald' }}"
                            class="!text-xs ">
                            {{ __($c->category_type) }}
                        </x-ui.badge>
                    </td>
                    <td class="px-8 py-5 text-right">
                        <div class="flex justify-end items-center gap-2">
                            <button wire:click="openClassificationModal({{ $c->id }})"
                                class="p-2 text-zinc-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-xl transition-all"
                                title="{{ __('Edit') }}">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                                    stroke-linejoin="round">
                                    <path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z" />
                                    <path d="m15 5 4 4" />
                                </svg>
                            </button>
                            <button wire:click="deleteClassification({{ $c->id }})"
                                wire:confirm="{{ __('Confirm deletion? This will remove the classification permanently.') }}"
                                class="p-2 text-zinc-300 hover:text-rose-600 hover:bg-rose-50 rounded-xl transition-all"
                                title="{{ __('Delete') }}">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                                    stroke-linejoin="round">
                                    <path d="M3 6h18" />
                                    <path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6" />
                                    <path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2" />
                                    <line x1="10" x2="10" y1="11" y2="17" />
                                    <line x1="14" x2="14" y1="11" y2="17" />
                                </svg>
                            </button>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="p-12 text-center text-sm text-gray-500 italic">
                        {{ __('No economic categories found.') }}
                    </td>
                </tr>
            @endforelse
        </x-ui.table>
    </div>
</x-ui.card>

<div class="px-8">
    {{ $economicCategories->links() }}
</div>

{{-- Classification Modal --}}
@if($classificationModalOpen)
    <div
        class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-zinc-900/60 backdrop-blur-sm animate-in fade-in duration-300">
        <div
            class="bg-white w-full max-w-2xl rounded-xl shadow-sm overflow-hidden animate-in zoom-in-95 duration-300">
            <div class="p-8 border-b border-zinc-50 flex justify-between items-center bg-zinc-50/30">
                <div>
                    <h3 class="text-xl font-semibold text-zinc-900 italic tracking-tight">
                        {{ $isClassificationEditing ? __('Update Economic Classification') : __('Create Economic Classification') }}
                    </h3>
                    <p class="text-xs text-zinc-400 font-bold mt-1">
                        {{ __('Economic Classification Management') }}
                    </p>
                </div>
                <button wire:click="$set('classificationModalOpen', false)"
                    class="p-2 hover:bg-zinc-200/50 rounded-full transition-colors text-zinc-400 hover:text-zinc-900">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                    </svg>
                </button>
            </div>

            <form wire:submit="saveClassification" class="p-8 space-y-8">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <div class="space-y-2">
                        <label
                            class="text-xs font-semibold text-zinc-400 ml-1 ">{{ __('Classification Code') }}</label>
                        <input type="text" wire:model="classificationCode"
                            class="w-full h-12 px-5 rounded-xl bg-zinc-50 border-transparent text-sm font-semibold focus:ring-4 focus:ring-indigo-500/5 focus:border-indigo-500 transition-all "
                            placeholder="e.g. 2.1.1">
                        <x-input-error :messages="$errors->get('classificationCode')" />
                    </div>
                    <div class="space-y-2">
                        <label
                            class="text-xs font-semibold text-zinc-400 ml-1 ">{{ __('Classification Name') }}</label>
                        <input type="text" wire:model="classificationName"
                            class="w-full h-12 px-5 rounded-xl bg-zinc-50 border-transparent text-sm font-bold focus:ring-4 focus:ring-indigo-500/5 focus:border-indigo-500 transition-all"
                            placeholder="e.g. Personnel Expenses">
                        <x-input-error :messages="$errors->get('classificationName')" />
                    </div>
                </div>

                <div class="space-y-2">
                    <label
                        class="text-xs font-semibold text-zinc-400 ml-1 ">{{ __('Classification Type') }}</label>
                    <select wire:model="classificationType"
                        class="w-full h-12 px-5 rounded-xl bg-zinc-50 border-transparent text-sm font-bold focus:ring-4 focus:ring-indigo-500/5 focus:border-indigo-500 transition-all">
                        <option value="Expense">{{ __('Expense') }}</option>
                        <option value="Revenue">{{ __('Revenue') }}</option>
                    </select>
                    <x-input-error :messages="$errors->get('classificationType')" />
                </div>

                <div class="space-y-2">
                    <label
                        class="text-xs font-semibold text-zinc-400 ml-1 ">{{ __('Description') }}</label>
                    <textarea wire:model="classificationDescription" rows="4"
                        class="w-full p-5 rounded-xl bg-zinc-50 border-transparent text-sm font-bold focus:ring-4 focus:ring-indigo-500/5 focus:border-indigo-500 transition-all"
                        placeholder="Classification description and usage guidelines..."></textarea>
                    <x-input-error :messages="$errors->get('classificationDescription')" />
                </div>

                <div class="flex items-center gap-4 pt-4">
                    <x-ui.button type="button" wire:click="$set('classificationModalOpen', false)" variant="secondary"
                        class="flex-1 !py-4 !rounded-xl border border-zinc-200">{{ __('Go Back') }}</x-ui.button>
                    <x-ui.button type="submit" variant="black" shadow="xl"
                        class="flex-1 !py-4 !rounded-xl">{{ $isClassificationEditing ? __('Update Classification') : __('Create Classification') }}</x-ui.button>
                </div>
            </form>
        </div>
    </div>
@endif