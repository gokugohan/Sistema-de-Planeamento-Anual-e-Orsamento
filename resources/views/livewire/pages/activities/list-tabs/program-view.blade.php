<div class="space-y-6">
    @forelse($this->programViewData as $data)
        <div x-data="{ expanded: false }"
            class="premium-card rounded-[2rem] overflow-hidden group/card shadow-indigo-500/5 hover:shadow-indigo-500/10 hover:border-indigo-100 transition-all duration-500">
            <div @click="expanded = !expanded"
                class="p-8 cursor-pointer bg-white group-hover/card:bg-slate-50/30 flex justify-between items-center transition-colors duration-500">
                <div class="space-y-3">
                    <div class="flex items-center gap-4">
                        <span
                            class="px-3 py-1.5 bg-slate-900 text-white text-[9px] font-black uppercase tracking-[0.2em] rounded-lg shadow-xl shadow-black/10 transition-transform group-hover/card:scale-105 duration-500">{{ $data['code'] }}</span>
                        <h4
                            class="text-2xlss font-black text-slate-900 group-hover/card:text-indigo-600 transition-colors duration-500">
                            {{ $data['name'] }}
                        </h4>
                    </div>
                    <div class="flex gap-10 text-[9px] font-black uppercase tracking-widest text-slate-400">
                        <div class="flex items-center gap-2">
                            <div class="w-1.5 h-1.5 rounded-full bg-blue-500 shadow-sm shadow-blue-500/50"></div>
                            <span>{{ $data['total_indicators'] }} {{ __('Goals') }}</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="w-1.5 h-1.5 rounded-full bg-indigo-500 shadow-sm shadow-indigo-500/50"></div>
                            <span>{{ $data['total_rubricas'] }} {{ __('Finance Items') }}</span>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-12">
                    <div class="text-right hidden sm:block">
                        <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest leading-none mb-2">
                            {{ __('Programmatic Ceiling') }}
                        </p>
                        <p class="text-xlss font-black text-slate-900 leading-none tracking-tight">
                            $ {{ number_format($data['program_allocation'], 2) }}
                        </p>
                    </div>
                    <div class="h-3 w-px bg-slate-100 hidden sm:block opacity-50"></div>
                    <div class="text-right border-r-4 border-indigo-600 pr-4">
                        <p class="text-[9px] font-black text-indigo-400 uppercase tracking-widest leading-none mb-2">
                            {{ __('Distributed') }}
                        </p>
                        <p class="text-2xlsss font-black text-indigo-600 leading-none tracking-tighter">
                            $ {{ number_format($data['total_allocated'], 2) }}
                        </p>
                    </div>
                    <div class="ml-4">
                        <x-ui.button variant="secondary" size="icon"
                            x-bind:class="expanded ? 'rotate-180 bg-indigo-50 border-indigo-100 text-indigo-600' : ''"
                            class="!rounded-2xl transition-all duration-500">
                            <svg class="w-3 h-3 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M19 9l-7 7-7-7" />
                            </svg>
                        </x-ui.button>
                    </div>
                </div>
            </div>

            <div x-show="expanded" x-collapse>
                <div class="px-8 pb-10 pt-4 border-t border-slate-50 bg-slate-50/10">
                    <div class="mb-4 flex items-center justify-between px-2">
                        <span
                            class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">{{ __('Consolidated Activities Breakdown') }}</span>
                        <span class="text-[10px] font-bold text-slate-400 italic">{{ count($data['activities']) }}
                            {{ __('entries found') }}</span>
                    </div>
                    <div class="rounded-3xl border border-slate-100 bg-white overflow-hidden shadow-sm shadow-indigo-500/5">
                        <x-ui.table :headers="[__('Activity / Directorate'), __('Goals'), __('Requested'), __('Approved'), __('Status')]">
                            <x-slot name="actions"></x-slot>
                            @foreach($data['activities'] as $activity)
                                <tr class="hover:bg-slate-50/50 transition-colors group/row">
                                    <td class="px-8 py-6">
                                        <p
                                            class="font-black text-slate-700 leading-tight group-hover/row:text-indigo-600 transition-colors">
                                            {{ $activity->description }}
                                        </p>
                                        <p class="text-[9px] font-bold text-slate-400 uppercase mt-1.5 tracking-widest">
                                            {{ $activity->directorate->name }}
                                        </p>
                                    </td>
                                    <td class="px-8 py-6">
                                        <x-ui.badge variant="blue"
                                            class="!bg-blue-50/50">{{ $activity->indicators->count() }}</x-ui.badge>
                                    </td>
                                    <td class="px-8 py-6">
                                        <span class="text-xs font-bold text-slate-500 tracking-tight">$
                                            {{ number_format($activity->lineItems->sum('estimated_amount'), 2) }}</span>
                                    </td>
                                    <td class="px-8 py-6">
                                        <span class="text-sm font-black text-indigo-600 tracking-tighter">$
                                            {{ number_format($activity->lineItems->sum(fn($item) => $item->adjustedBudget->approved_amount ?? 0), 2) }}</span>
                                    </td>
                                    <td class="px-8 py-6">
                                        @php
                                            $statusVariant = match ($activity->status) {
                                                'Aprovada' => 'green',
                                                'Cortada' => 'red',
                                                'Planeada' => 'indigo',
                                                default => 'amber',
                                            };
                                            $statusLabel = match ($activity->status) {
                                                'Aprovada' => __('Approved'),
                                                'Cortada' => __('Cut'),
                                                'Planeada' => __('Planned'),
                                                default => __('Pending'),
                                            };
                                        @endphp
                                        <x-ui.badge :variant="$statusVariant"
                                            class="!rounded-md">{{ $statusLabel }}</x-ui.badge>
                                    </td>
                                    <td class="px-8 py-6 text-right">
                                        <div
                                            class="flex justify-end gap-3 opacity-0 group-hover/row:opacity-100 transition-all group-hover/row:translate-x-0 translate-x-2">
                                            <x-ui.button variant="ghost" size="icon"
                                                href="{{ route('activities.show', $activity->id) }}" wire:navigate
                                                title="View Detail Analysis" class="!rounded-xl hover:bg-white hover:shadow-sm">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                                        d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                                        d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                                </svg>
                                            </x-ui.button>
                                            @if((auth()->user()->isMinister() || auth()->user()->hasAnyRole(['Admin', 'Planning'])) && $activity->status === 'Aprovada')
                                                <x-ui.button variant="ghost" size="icon" wire:click="reopen({{ $activity->id }})"
                                                    wire:confirm="{{ __('Are you sure you want to reopen this activity? This will return it to draft status and allow editing.') }}"
                                                    class="!rounded-xl border border-slate-100 hover:bg-white hover:shadow-sm text-slate-500 hover:text-indigo-600"
                                                    title="Reopen Activity">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                                                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"
                                                        stroke-linecap="round" stroke-linejoin="round">
                                                        <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8" />
                                                        <path d="M3 3v5h5" />
                                                    </svg>
                                                </x-ui.button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </x-ui.table>
                    </div>
                </div>
            </div>
        </div>
    @empty
        <div class="text-center py-20 bg-gray-50 rounded-3xl border-2 border-dashed border-gray-200">
            <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg class="w-8 h-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                </svg>
            </div>
            <p class="text-gray-400 italic font-medium">{{ __('No activities grouped by program found.') }}</p>
        </div>
    @endforelse
</div>