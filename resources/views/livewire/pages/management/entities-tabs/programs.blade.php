<x-ui.card padding="p-0">
    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-center gap-6">
            <div>
                <h2 class="text-sm font-semibold text-zinc-900 ">
                    {{ __('Programs Registry') }}
                </h2>
                <p class="text-xs text-zinc-400 font-bold mt-1">
                    {{ __('Active Programs in System') }}
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

    <div class="overflow-x-auto" tabindex="0">
        <x-ui.table :headers="[__('OGE Code'), __('Program Name'), __('Manager'), __('Objective'), __('Approved plan') . ' ' . $fundingYear, __('Funded'), __('Actions')]">
            @forelse($programs as $p)
                <tr class="hover:bg-gray-50/50 transition-colors group text-sm">
                    <td class="px-8 py-5 text-gray-900 font-semibold font-mono">
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
                                class="p-2 text-zinc-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-xl transition-all"
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
                    <td colspan="7"><x-ui.empty-state :title="__('No programs found.')" /></td>
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
    <x-dialog max-width="2xl" close="$set('programModalOpen', false)">
<x-slot:title>{{ $isProgramEditing ? __('Update Program') : __('Create Program') }}</x-slot:title>
<x-slot:subtitle>{{ __('Program Registry Management') }}</x-slot:subtitle>

            <form wire:submit="saveProgram" class="p-6 space-y-5">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="field-label" for="f-programCode">{{ __('Program Code') }}</label>
<input id="f-programCode" type="text" wire:model="programCode"
                            class="flux-input"
                            placeholder="e.g. PROG01">
                        <x-input-error :messages="$errors->get('programCode')" />
                    </div>
                    <div>
                        <label class="field-label" for="f-programName">{{ __('Program Name') }}</label>
<input id="f-programName" type="text" wire:model="programName"
                            class="flux-input"
                            placeholder="e.g. Education Development">
                        <x-input-error :messages="$errors->get('programName')" />
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="field-label" for="f-ogeCode">{{ __('OGE Code') }}</label>
<input id="f-ogeCode" type="text" wire:model="ogeCode"
                            class="flux-input"
                            placeholder="Optional">
                        <x-input-error :messages="$errors->get('ogeCode')" />
                    </div>
                    <div>
                        <label class="field-label" for="f-managerName">{{ __('Program Manager') }}</label>
<input id="f-managerName" type="text" wire:model="managerName"
                            class="flux-input"
                            placeholder="Full name">
                        <x-input-error :messages="$errors->get('managerName')" />
                    </div>
                </div>

                <div>
                    <label class="field-label" for="f-objective">{{ __('Objective') }}</label>
<textarea id="f-objective" wire:model="objective" rows="3"
                        class="flux-input"
                        placeholder="Program objectives and goals..."></textarea>
                    <x-input-error :messages="$errors->get('objective')" />
                </div>

                <div>
                    <label class="field-label" for="f-kpis">{{ __('Key Performance Indicators') }}</label>
<textarea id="f-kpis" wire:model="kpis" rows="2"
                        class="flux-input"
                        placeholder="KPIs for measuring success..."></textarea>
                    <x-input-error :messages="$errors->get('kpis')" />
                </div>

                <div class="flex items-center gap-4 pt-4">
                    <x-ui.button type="button" wire:click="$set('programModalOpen', false)" variant="secondary"
                        class="flex-1">{{ __('Go Back') }}</x-ui.button>
                    <x-ui.button type="submit" variant="primary"
                        class="flex-1">{{ $isProgramEditing ? __('Update Program') : __('Create Program') }}</x-ui.button>
                </div>
            </form>
</x-dialog>
@endif