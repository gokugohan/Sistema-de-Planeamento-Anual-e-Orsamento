<x-ui.card padding="p-0">
    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-center gap-6">
            <div>
                <h2 class="text-sm font-black text-slate-900 uppercase tracking-widest">
                    {{ __('Programs Registry') }}
                </h2>
                <p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest mt-1">
                    {{ __('Active Programs in System') }}
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
        <x-ui.table :headers="[__('OGE Code'), __('Program Name'), __('Manager'), __('Objective'), __('Approved plan') . ' ' . $fundingYear, __('Funded'), __('Actions')]">
            @forelse($programs as $p)
                <tr class="hover:bg-gray-50/50 transition-colors group text-sm">
                    <td class="px-8 py-5 text-gray-900 font-black tracking-tighter uppercase font-mono">
                        {{ $p->oge_code ?? '—' }}
                    </td>
                    <td class="px-8 py-5">
                        <div class="font-bold text-gray-900">{{ $p->name }}</div>
                    </td>
                    <td class="px-8 py-5">
                        <div class="font-bold text-gray-900 leading-none text-xs">
                            {{ $p->manager_name ?: __('Not Assigned') }}
                        </div>
                    </td>
                    <td class="px-8 py-5">
                        <div class="text-xs text-gray-600 line-clamp-2">
                            {{ $p->objective ? Str::limit($p->objective, 60) : __('No objective defined') }}
                        </div>
                    </td>
                    @php $f = $progFunding[$p->id] ?? ['n' => 0, 'plan' => 0, 'alloc' => 0]; $pct = $f['plan'] > 0 ? min(100, $f['alloc'] / $f['plan'] * 100) : null; @endphp
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
                    <td class="px-8 py-5 text-right">
                        <div class="flex justify-end items-center gap-2">
                            <button wire:click="openProgramModal({{ $p->id }})"
                                class="p-2 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-xl transition-all"
                                title="{{ __('Edit') }}">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                                    stroke-linejoin="round">
                                    <path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z" />
                                    <path d="m15 5 4 4" />
                                </svg>
                            </button>
                            <button wire:click="deleteProgram({{ $p->id }})"
                                wire:confirm="{{ __('Confirm deletion? This will remove the program permanently.') }}"
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
                    <td colspan="7" class="p-12 text-center text-sm text-gray-500 italic">
                        {{ __('No programs found.') }}
                    </td>
                </tr>
            @endforelse
        </x-ui.table>
    </div>
</x-ui.card>

<div class="px-8">
    {{ $programs->links() }}
</div>

{{-- Program Modal --}}
@if($programModalOpen)
    <div
        class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm animate-in fade-in duration-300">
        <div
            class="bg-white w-full max-w-2xl rounded-[2.5rem] shadow-2xl overflow-hidden animate-in zoom-in-95 duration-300">
            <div class="p-8 border-b border-slate-50 flex justify-between items-center bg-slate-50/30">
                <div>
                    <h3 class="text-xl font-black text-slate-900 italic tracking-tight">
                        {{ $isProgramEditing ? __('Update Program') : __('Create Program') }}
                    </h3>
                    <p class="text-[10px] text-slate-400 font-bold uppercase tracking-[0.2em] mt-1">
                        {{ __('Program Registry Management') }}
                    </p>
                </div>
                <button wire:click="$set('programModalOpen', false)"
                    class="p-2 hover:bg-slate-200/50 rounded-full transition-colors text-slate-400 hover:text-slate-900">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                    </svg>
                </button>
            </div>

            <form wire:submit="saveProgram" class="p-8 space-y-8">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <div class="space-y-2">
                        <label
                            class="text-[10px] font-black uppercase text-slate-400 ml-1 tracking-widest">{{ __('Program Code') }}</label>
                        <input type="text" wire:model="programCode"
                            class="w-full h-12 px-5 rounded-2xl bg-slate-50 border-transparent text-sm font-black focus:ring-4 focus:ring-indigo-500/5 focus:border-indigo-500 transition-all uppercase tracking-tighter"
                            placeholder="e.g. PROG01">
                        <x-input-error :messages="$errors->get('programCode')" />
                    </div>
                    <div class="space-y-2">
                        <label
                            class="text-[10px] font-black uppercase text-slate-400 ml-1 tracking-widest">{{ __('Program Name') }}</label>
                        <input type="text" wire:model="programName"
                            class="w-full h-12 px-5 rounded-2xl bg-slate-50 border-transparent text-sm font-bold focus:ring-4 focus:ring-indigo-500/5 focus:border-indigo-500 transition-all"
                            placeholder="e.g. Education Development">
                        <x-input-error :messages="$errors->get('programName')" />
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <div class="space-y-2">
                        <label
                            class="text-[10px] font-black uppercase text-slate-400 ml-1 tracking-widest">{{ __('OGE Code') }}</label>
                        <input type="text" wire:model="ogeCode"
                            class="w-full h-12 px-5 rounded-2xl bg-slate-50 border-transparent text-sm font-bold focus:ring-4 focus:ring-indigo-500/5 focus:border-indigo-500 transition-all"
                            placeholder="Optional">
                        <x-input-error :messages="$errors->get('ogeCode')" />
                    </div>
                    <div class="space-y-2">
                        <label
                            class="text-[10px] font-black uppercase text-slate-400 ml-1 tracking-widest">{{ __('Program Manager') }}</label>
                        <input type="text" wire:model="managerName"
                            class="w-full h-12 px-5 rounded-2xl bg-slate-50 border-transparent text-sm font-bold focus:ring-4 focus:ring-indigo-500/5 focus:border-indigo-500 transition-all"
                            placeholder="Full name">
                        <x-input-error :messages="$errors->get('managerName')" />
                    </div>
                </div>

                <div class="space-y-2">
                    <label
                        class="text-[10px] font-black uppercase text-slate-400 ml-1 tracking-widest">{{ __('Objective') }}</label>
                    <textarea wire:model="objective" rows="3"
                        class="w-full p-5 rounded-[1.5rem] bg-slate-50 border-transparent text-sm font-bold focus:ring-4 focus:ring-indigo-500/5 focus:border-indigo-500 transition-all"
                        placeholder="Program objectives and goals..."></textarea>
                    <x-input-error :messages="$errors->get('objective')" />
                </div>

                <div class="space-y-2">
                    <label
                        class="text-[10px] font-black uppercase text-slate-400 ml-1 tracking-widest">{{ __('Key Performance Indicators') }}</label>
                    <textarea wire:model="kpis" rows="2"
                        class="w-full p-5 rounded-[1.5rem] bg-slate-50 border-transparent text-sm font-bold focus:ring-4 focus:ring-indigo-500/5 focus:border-indigo-500 transition-all"
                        placeholder="KPIs for measuring success..."></textarea>
                    <x-input-error :messages="$errors->get('kpis')" />
                </div>

                <div class="flex items-center gap-4 pt-4">
                    <x-ui.button type="button" wire:click="$set('programModalOpen', false)" variant="secondary"
                        class="flex-1 !py-4 !rounded-2xl border border-slate-200">{{ __('Go Back') }}</x-ui.button>
                    <x-ui.button type="submit" variant="black" shadow="xl"
                        class="flex-1 !py-4 !rounded-2xl">{{ $isProgramEditing ? __('Update Program') : __('Create Program') }}</x-ui.button>
                </div>
            </form>
        </div>
    </div>
@endif