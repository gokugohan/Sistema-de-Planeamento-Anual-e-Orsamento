<div class="space-y-6">
    @forelse($this->programViewData as $data)
        <div x-data="{ expanded: false }"
            class="premium-card rounded-[2rem] overflow-hidden group/card shadow-amber-500/5 hover:shadow-amber-500/10 border-amber-100/50 hover:border-amber-200 transition-all duration-500 border bg-white">
            <div @click="expanded = !expanded"
                class="p-8 cursor-pointer bg-white group-hover/card:bg-amber-50/10 flex justify-between items-center transition-colors duration-500">
                <div class="space-y-3">
                    <div class="flex items-center gap-4">
                        <span
                            class="px-3 py-1.5 bg-amber-600 text-white text-[9px] font-black uppercase tracking-[0.2em] rounded-lg shadow-xl shadow-amber-600/10 transition-transform group-hover/card:scale-105 duration-500">{{ $data['code'] }}</span>
                        <h4
                            class="text-2xl font-black text-amber-900 group-hover/card:text-amber-700 transition-colors duration-500">
                            {{ $data['name'] }}
                        </h4>
                    </div>
                    <div class="flex gap-10 text-[9px] font-black uppercase tracking-widest text-amber-400">
                        <div class="flex items-center gap-2">
                            <div class="w-1.5 h-1.5 rounded-full bg-amber-500 shadow-sm shadow-amber-500/50"></div>
                            <span>{{ $data['total_indicators'] }} {{ __('Goal Simulations') }}</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="w-1.5 h-1.5 rounded-full bg-amber-600 shadow-sm shadow-amber-600/50"></div>
                            <span>{{ $data['total_rubricas'] }} {{ __('Finance Simulations') }}</span>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-12">
                    <div class="text-right hidden sm:block">
                        <p class="text-[9px] font-black text-amber-400 uppercase tracking-widest leading-none mb-2">
                            {{ __('Simulated Ceiling') }}
                        </p>
                        <p class="text-xl font-black text-amber-900 leading-none tracking-tight">
                            $ {{ number_format($data['program_allocation'], 2) }}
                        </p>
                    </div>
                    <div class="h-10 w-px bg-amber-100 hidden sm:block opacity-50"></div>
                    <div class="text-right border-r-4 border-amber-600 pr-4">
                        <p class="text-[9px] font-black text-amber-400 uppercase tracking-widest leading-none mb-2">
                            {{ __('Distributed') }}
                        </p>
                        <p class="text-2xl font-black text-amber-600 leading-none tracking-tighter">
                            $ {{ number_format($data['total_allocated'], 2) }}
                        </p>
                    </div>
                    <div class="ml-4">
                        <x-ui.button variant="secondary" size="icon"
                            x-bind:class="expanded ? 'rotate-180 bg-amber-50 border-amber-100 text-amber-600' : ''"
                            class="!rounded-2xl transition-all duration-500 !bg-amber-50/50 !text-amber-600">
                            <svg class="w-5 h-5 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M19 9l-7 7-7-7" />
                            </svg>
                        </x-ui.button>
                    </div>
                </div>
            </div>

            <div x-show="expanded" x-collapse>
                <div class="px-8 pb-10 pt-4 border-t border-amber-50 bg-amber-50/10">
                    <div class="mb-4 flex items-center justify-between px-2">
                        <span
                            class="text-[10px] font-black text-amber-400 uppercase tracking-[0.2em]">{{ __('Simulated Activities Breakdown') }}</span>
                        <span class="text-[10px] font-bold text-amber-400 italic">{{ count($data['activities']) }}
                            {{ __('simulations found') }}</span>
                    </div>
                    <div class="rounded-3xl border border-amber-100 bg-white overflow-hidden shadow-sm shadow-amber-500/5">
                        <x-ui.table :headers="[__('Activity / Directorate'), __('Goals'), __('Requested'), __('Approved'), __('Status')]">
                            <x-slot name="actions"></x-slot>
                            @foreach($data['activities'] as $activity)
                                <tr class="hover:bg-amber-50/50 transition-colors group/row">
                                    <td class="px-8 py-6">
                                        <p
                                            class="font-black text-amber-900 leading-tight group-hover/row:text-amber-700 transition-colors">
                                            {{ $activity->description }}
                                        </p>
                                        <p class="text-[9px] font-bold text-amber-400 uppercase mt-1.5 tracking-widest">
                                            {{ $activity->directorate->name }}
                                        </p>
                                    </td>
                                    <td class="px-8 py-6">
                                        <x-ui.badge variant="amber"
                                            class="!bg-amber-50/50 !text-amber-600">{{ $activity->indicators->count() }}</x-ui.badge>
                                    </td>
                                    <td class="px-8 py-6">
                                        <span class="text-xs font-bold text-amber-700 tracking-tight">$
                                            {{ number_format($activity->lineItems->sum('estimated_amount'), 2) }}</span>
                                    </td>
                                    <td class="px-8 py-6">
                                        <span class="text-sm font-black text-amber-600 tracking-tighter">$
                                            {{ number_format($activity->lineItems->sum(fn($item) => $item->adjustedBudget->approved_amount ?? 0), 2) }}</span>
                                    </td>
                                    <td class="px-8 py-6">
                                        @php
                                            $statusVariant = match ($activity->status) {
                                                'Aprovada' => 'green',
                                                'Cortada' => 'red',
                                                'Planeada' => 'amber',
                                                default => 'amber',
                                            };
                                            $statusLabel = match ($activity->status) {
                                                'Aprovada' => __('Sim. Approved'),
                                                'Submitted' => __('Sim. Submitted'),
                                                'Rejected' => __('Sim. Rejected'),
                                                'Cortada' => __('Sim. Cut'),
                                                'Planeada' => __('Sim. Planned'),
                                                default => $activity->status,
                                            };
                                        @endphp
                                        <x-ui.badge :variant="$statusVariant"
                                            class="!rounded-md">{{ $statusLabel }}</x-ui.badge>
                                    </td>
                                    <td class="px-8 py-6 text-right">
                                        <div
                                            class="flex justify-end gap-3 opacity-0 group-hover/row:opacity-100 transition-all group-hover/row:translate-x-0 translate-x-2">
                                            <x-ui.button variant="ghost" size="icon"
                                                href="{{ route('testing.activities.show', $activity->id) }}" wire:navigate
                                                title="View Simulation Detail"
                                                class="!rounded-xl hover:bg-white hover:shadow-sm">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                                        d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                                        d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                                </svg>
                                            </x-ui.button>
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
        <div class="text-center py-20 bg-amber-50/30 rounded-3xl border-2 border-dashed border-amber-200">
            <div class="w-16 h-16 bg-amber-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg class="w-8 h-8 text-amber-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                </svg>
            </div>
            <p class="text-amber-400 italic font-medium">{{ __('No simulation activities found.') }}</p>
        </div>
    @endforelse
</div>