<x-ui.card padding="p-0">
    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-center gap-6">
            <div>
                <h2 class="text-sm font-black text-slate-900 uppercase tracking-widest">
                    {{ __('Organisational units') }}
                </h2>
                <p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest mt-1">
                    {{ __('Directorates, offices and units') }}
                </p>
            </div>
            <div class="relative group">
                <span
                    class="absolute inset-y-0 left-0 pl-4 flex items-center text-slate-300 group-focus-within:text-indigo-500 transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8" />
                        <path d="m21 21-4.3-4.3" />
                    </svg>
                </span>
                <input type="text" wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search by code or name...') }}"
                    class="pl-12 h-11 w-72 bg-slate-50 border-slate-100 rounded-2xl text-[11px] font-bold text-slate-600 focus:ring-4 focus:ring-indigo-500/5 focus:border-indigo-500 focus:bg-white transition-all placeholder:text-slate-300">
            </div>
        </div>
    </x-slot>

    <div class="overflow-x-auto">
        <x-ui.table :headers="[__('Unit Code'), __('Identity & Hierarchy'), __('Type'), __('Director'), __('Approved plan') . ' ' . $fundingYear, __('Funded'), __('Status'), __('Actions')]">
            @forelse($directorates as $d)
                <tr class="hover:bg-gray-50/50 transition-colors group text-sm">
                    <td class="px-8 py-5 text-gray-900 font-black tracking-tighter uppercase font-mono">{{ $d->code }}
                    </td>
                    <td class="px-8 py-5 min-w-[18rem]">
                        <div class="font-bold text-gray-900 {{ $d->parent ? 'pl-4 border-l-2 border-zinc-200' : '' }}">{{ $d->name }}</div>
                        @if($d->parent)
                            <div class="flex items-center gap-1.5 mt-1">
                                <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"
                                    class="text-indigo-400 rotate-90">
                                    <path d="M16 13L21 18L16 23" />
                                    <path d="M3 6V15C3 16.6569 4.34315 18 6 18H21" />
                                </svg>
                                <span
                                    class="text-[9px] text-indigo-400 font-black uppercase tracking-widest">{{ $d->parent->code }}</span>
                            </div>
                        @endif
                    </td>
                    <td class="px-8 py-5">
                        <x-ui.badge variant="gray" class="!text-[9px] uppercase">{{ __($d->type) }}</x-ui.badge>
                    </td>
                    <td class="px-8 py-5">
                        <div class="font-bold text-gray-900 leading-none text-xs">
                            {{ $d->director_name ?: '—' }}
                        </div>
                        @if($d->director_email)<div class="text-[9px] text-gray-400 mt-1 font-medium">{{ $d->director_email }}</div>@endif
                    </td>
                    @php $f = $dirFunding[$d->id] ?? ['n' => 0, 'plan' => 0, 'alloc' => 0]; $pct = $f['plan'] > 0 ? min(100, $f['alloc'] / $f['plan'] * 100) : null; @endphp
                    <td class="px-8 py-5 text-sm tabular-nums">
                        @if($f['plan'] > 0)
                            <div class="font-semibold text-zinc-900">$ {{ number_format($f['plan']) }}</div>
                            <div class="text-xs text-zinc-500">{{ trans_choice(':n activity|:n activities', $f['n'], ['n' => $f['n']]) }}</div>
                        @else
                            <span class="text-zinc-400">—</span>
                        @endif
                    </td>
                    <td class="px-8 py-5 w-40">
                        @if($pct !== null)
                            <div class="flex items-center gap-2">
                                <div class="h-1.5 flex-1 rounded-full bg-zinc-100 overflow-hidden"><div class="h-full rounded-full {{ $pct >= 99.5 ? 'bg-emerald-500' : 'bg-amber-500' }}" style="width: {{ $pct }}%"></div></div>
                                <span class="w-10 text-right text-xs tabular-nums text-zinc-600">{{ number_format($pct, 0) }}%</span>
                            </div>
                        @else
                            <span class="text-zinc-400">—</span>
                        @endif
                    </td>
                    <td class="px-8 py-5">
                        @if($d->active)
                            <x-ui.badge variant="emerald" pulse="true">{{ __('Operational') }}</x-ui.badge>
                        @else
                            <x-ui.badge variant="red">{{ __('Inactive') }}</x-ui.badge>
                        @endif
                    </td>
                    <td class="px-8 py-5 text-right">
                        <div class="flex justify-end items-center gap-2">
                            <button wire:click="openModal({{ $d->id }})"
                                class="p-2 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-xl transition-all"
                                title="{{ __('Edit') }}">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                                    stroke-linejoin="round">
                                    <path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z" />
                                    <path d="m15 5 4 4" />
                                </svg>
                            </button>
                            <button wire:click="deleteDirectorate({{ $d->id }})"
                                wire:confirm="{{ __('Confirm deletion? This will remove the node permanently.') }}"
                                class="p-2 text-slate-300 hover:text-rose-600 hover:bg-rose-50 rounded-xl transition-all"
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
                    <td colspan="6" class="p-12 text-center text-sm text-gray-500 italic">
                        {{ __('No directorates found.') }}
                    </td>
                </tr>
            @endforelse
        </x-ui.table>
    </div>
</x-ui.card>

<div class="px-8">
    {{ $directorates->links() }}
</div>

<!-- CRUD Modal -->
@if($modalOpen)
    <div
        class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm animate-in fade-in duration-300">
        <div
            class="bg-white w-full max-w-2xl rounded-[2.5rem] shadow-2xl overflow-hidden animate-in zoom-in-95 duration-300">
            <div class="p-8 border-b border-slate-50 flex justify-between items-center bg-slate-50/30">
                <div>
                    <h3 class="text-xl font-black text-slate-900 italic tracking-tight">
                        {{ $isEditing ? __('Update Structural Unit') : __('Create Structural Unit') }}
                    </h3>
                    <p class="text-[10px] text-slate-400 font-bold uppercase tracking-[0.2em] mt-1">
                        {{ __('Core Infrastructure Node Definition') }}
                    </p>
                </div>
                <button wire:click="$set('modalOpen', false)"
                    class="p-2 hover:bg-slate-200/50 rounded-full transition-colors text-slate-400 hover:text-slate-900">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                    </svg>
                </button>
            </div>

            <form wire:submit="saveDirectorate" class="p-8 space-y-8">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <div class="space-y-2">
                        <label
                            class="text-[10px] font-black uppercase text-slate-400 ml-1 tracking-widest">{{ __('Unit Code') }}</label>
                        <input type="text" wire:model="code"
                            class="w-full h-12 px-5 rounded-2xl bg-slate-50 border-transparent text-sm font-black focus:ring-4 focus:ring-indigo-500/5 focus:border-indigo-500 transition-all uppercase tracking-tighter"
                            placeholder="e.g. DGPL">
                        <x-input-error :messages="$errors->get('code')" />
                    </div>
                    <div class="space-y-2">
                        <label
                            class="text-[10px] font-black uppercase text-slate-400 ml-1 tracking-widest">{{ __('Unit Name') }}</label>
                        <input type="text" wire:model="name"
                            class="w-full h-12 px-5 rounded-2xl bg-slate-50 border-transparent text-sm font-bold focus:ring-4 focus:ring-indigo-500/5 focus:border-indigo-500 transition-all"
                            placeholder="e.g. Planning Directorate">
                        <x-input-error :messages="$errors->get('name')" />
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <div class="space-y-2">
                        <label
                            class="text-[10px] font-black uppercase text-slate-400 ml-1 tracking-widest">{{ __('Classification') }}</label>
                        <select wire:model.live="type"
                            class="w-full h-12 px-5 rounded-2xl bg-slate-50 border-transparent text-sm font-bold focus:ring-4 focus:ring-indigo-500/5 focus:border-indigo-500 transition-all">
                            <option value="Directorate General">{{ __('Directorate General') }}</option>
                            <option value="National Directorate">{{ __('National Directorate') }}</option>
                            <option value="Department">{{ __('Department') }}</option>
                            <option value="Unit">{{ __('Unit') }}</option>
                            <option value="Cabinet">{{ __('Cabinet') }}</option>
                            <option value="Regional Office">{{ __('Regional Office') }}</option>
                        </select>
                        <x-input-error :messages="$errors->get('type')" />
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black uppercase text-slate-400 ml-1 tracking-widest">
                            {{ __('Parent Authority') }}
                            @if($type === 'National Directorate') (DG Only)
                            @elseif($type === 'Department') (ND/DG Only)
                            @endif
                        </label>
                        <select wire:model="parent_id"
                            class="w-full h-12 px-5 rounded-2xl bg-slate-50 border-transparent text-sm font-bold focus:ring-4 focus:ring-indigo-500/5 focus:border-indigo-500 transition-all">
                            <option value="">{{ __('Top Level (Independent)') }}</option>
                            @foreach($filteredParents as $parent)
                                @if($parent->id != $directorateId)
                                    <option value="{{ $parent->id }}">{{ $parent->code }} - {{ $parent->name }}
                                        ({{ __($parent->type) }})</option>
                                @endif
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('parent_id')" />
                    </div>
                </div>

                <div class="space-y-2">
                    <label
                        class="text-[10px] font-black uppercase text-slate-400 ml-1 tracking-widest">{{ __('Function Description') }}</label>
                    <textarea wire:model="description" rows="3"
                        class="w-full p-5 rounded-[1.5rem] bg-slate-50 border-transparent text-sm font-bold focus:ring-4 focus:ring-indigo-500/5 focus:border-indigo-500 transition-all"
                        placeholder="Primary role and responsibilities of this node..."></textarea>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <div class="space-y-2">
                        <label
                            class="text-[10px] font-black uppercase text-slate-400 ml-1 tracking-widest">{{ __('Manager / Director') }}</label>
                        <input type="text" wire:model="director_name"
                            class="w-full h-12 px-5 rounded-2xl bg-slate-50 border-transparent text-sm font-bold focus:ring-4 focus:ring-indigo-500/5 focus:border-indigo-500 transition-all"
                            placeholder="Full name">
                    </div>
                    <div class="space-y-2">
                        <label
                            class="text-[10px] font-black uppercase text-slate-400 ml-1 tracking-widest">{{ __('Officer Email') }}</label>
                        <input type="email" wire:model="director_email"
                            class="w-full h-12 px-5 rounded-2xl bg-slate-50 border-transparent text-sm font-bold focus:ring-4 focus:ring-indigo-500/5 focus:border-indigo-500 transition-all"
                            placeholder="e.g. officer@msa.gov.tl">
                    </div>
                </div>

                <div class="flex items-center justify-between">
                    <label class="text-[10px] font-black uppercase text-slate-400 tracking-widest">
                        {{ __('Operational Status') }}
                    </label>
                    <button type="button" wire:click="$toggle('active')"
                        class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none {{ $active ? 'bg-emerald-500' : 'bg-slate-200' }}">
                        <span
                            class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform {{ $active ? 'translate-x-6' : 'translate-x-1' }}"></span>
                    </button>
                </div>

                <div class="flex items-center gap-4 pt-4">
                    <x-ui.button type="button" wire:click="$set('modalOpen', false)" variant="secondary"
                        class="flex-1 !py-4 !rounded-2xl border border-slate-200">{{ __('Go Back') }}</x-ui.button>
                    <x-ui.button type="submit" variant="black" shadow="xl"
                        class="flex-1 !py-4 !rounded-2xl">{{ $isEditing ? __('Sync Changes') : __('Deploy Unit') }}</x-ui.button>
                </div>
            </form>
        </div>
    </div>
@endif