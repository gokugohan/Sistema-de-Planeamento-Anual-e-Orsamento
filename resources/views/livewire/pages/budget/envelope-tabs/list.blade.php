<div class="space-y-6 animate-in fade-in zoom-in-95 duration-300">
    <div class="flex justify-between items-start">
        <div>
            <h3 class="text-3xl font-black text-slate-900 italic tracking-tight leading-none">
                {{ __('Fiscal Envelopes (OGE)') }}
            </h3>
            <p class="text-slate-400 font-bold uppercase tracking-[0.2em] text-[10px] mt-3">
                {{ __('Ministry budget allocations approved by Parliament') }}
            </p>
        </div>
        @if($this->canCreateNewEnvelope)
            <x-ui.button wire:click="openModal" variant="black" shadow="lg" size="md">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="mr-2">
                    <path d="M12 5v14" />
                    <path d="M5 12h14" />
                </svg>
                {{ __('Create new fiscal envelopes') }}
            </x-ui.button>
        @else
            <x-ui.badge variant="gray" class="!bg-slate-100 !text-slate-400 !border-slate-200">
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="mr-2">
                    <rect width="18" height="11" x="3" y="11" rx="2" ry="2" />
                    <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                </svg>
                {{ __('Logic Restricted') }}
            </x-ui.badge>
        @endif
    </div>

    <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-[2rem] border border-slate-100 bg-white p-8 shadow-sm hover:shadow-md transition-shadow">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">{{ __('Total Envelopes') }}</p>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="text-3xl font-black text-slate-900">{{ $envelopes->count() }}</span>
            </div>
            <p class="text-[9px] text-slate-400 mt-2 font-bold uppercase tracking-widest">{{ __('Across all years') }}
            </p>
        </div>

        <div
            class="rounded-[2rem] border border-indigo-100 bg-gradient-to-br from-indigo-50/50 to-white p-8 shadow-sm hover:shadow-md transition-shadow">
            <p class="text-[10px] font-black text-indigo-700 uppercase tracking-[0.2em]">{{ __('Active Envelope') }}</p>
            <div class="mt-3 flex items-baseline gap-2">
                <span
                    class="text-3xl font-black text-indigo-950">{{ $envelopes->filter(fn($e) => $e->isActiveByDate())->count() }}</span>
            </div>
            <p class="text-[9px] text-indigo-400 mt-2 font-bold uppercase tracking-widest">{{ __('Currently active') }}
            </p>
        </div>

        <div class="rounded-[2rem] border border-slate-100 bg-white p-8 shadow-sm hover:shadow-md transition-shadow">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">{{ __('Approved') }}</p>
            <div class="mt-3 flex items-baseline gap-2">
                <span
                    class="text-3xl font-black text-slate-900">{{ $envelopes->where('status', 'Approved')->count() }}</span>
            </div>
            <p class="text-[9px] text-slate-400 mt-2 font-bold uppercase tracking-widest italic">
                {{ __('Pending activation') }}</p>
        </div>

        <div class="rounded-[2rem] border border-slate-100 bg-white p-8 shadow-sm hover:shadow-md transition-shadow">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">{{ __('Current Year') }}</p>
            <div class="mt-3">
                @php $currentYearEnv = $envelopes->where('fiscal_year', date('Y'))->first(); @endphp
                <span class="text-xl font-black text-slate-900">$
                    {{ number_format($currentYearEnv->total_amount ?? 0, 2) }}</span>
            </div>
            <p class="text-[9px] text-slate-400 mt-2 font-bold uppercase tracking-widest">{{ __('Active envelope') }}
            </p>
        </div>
    </div>

    <!-- Table Container -->
    <div class="rounded-[2rem] border border-slate-100 bg-white shadow-sm overflow-hidden">
        <div class="p-8 border-b border-slate-50 bg-gradient-to-r from-slate-50/50 to-white">
            <div class="flex items-center gap-3">
                <div class="p-2.5 bg-slate-100 rounded-xl text-slate-600">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path
                            d="M4 21h16a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v16a2 2 0 0 1-2 2Zm0 0a2 2 0 0 1-2-2v-9c0-1.1.9-2 2-2h2" />
                        <path d="M18 14h-8" />
                        <path d="M15 18h-5" />
                        <path d="M10 6h8v4h-8V6Z" />
                    </svg>
                </div>
                <div>
                    <h4 class="text-xs font-black uppercase tracking-[0.2em] text-slate-900">
                        {{ __('All Fiscal Envelopes') }}
                    </h4>
                    <p class="text-[10px] text-slate-400 mt-0.5 font-bold uppercase tracking-widest">
                        {{ __('View and manage fiscal envelopes across different years') }}
                    </p>
                </div>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs border-collapse">
                <thead
                    class="bg-slate-50/50 text-slate-400 font-black uppercase tracking-[0.2em] border-b border-slate-100">
                    <tr>
                        <th class="px-8 py-5">{{ __('Fiscal Year') }}</th>
                        <th class="px-8 py-5">{{ __('Total Envelope') }}</th>
                        <th class="px-8 py-5">{{ __('Allocated') }}</th>
                        <th class="px-8 py-5">{{ __('Spent') }}</th>
                        <th class="px-8 py-5">{{ __('Execution Rate') }}</th>
                        <th class="px-8 py-5">{{ __('Validity') }}</th>
                        <th class="px-8 py-5">{{ __('Status') }}</th>
                        <th class="px-8 py-5 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($envelopes as $envelope)
                        <tr class="hover:bg-slate-50/50 transition-colors group">
                            <td class="px-8 py-5">
                                <div class="flex items-center gap-2">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                                        stroke-linejoin="round" class="text-slate-300">
                                        <rect width="18" height="18" x="3" y="4" rx="2" ry="2" />
                                        <line x1="16" x2="16" y1="2" y2="6" />
                                        <line x1="8" x2="8" y1="2" y2="6" />
                                        <line x1="3" x2="21" y1="10" y2="10" />
                                    </svg>
                                    <span class="font-bold text-slate-900">{{ $envelope->fiscal_year }}</span>
                                </div>
                            </td>
                            <td class="px-8 py-5 font-black text-slate-900">$ {{ number_format($envelope->total_amount, 2) }}
                            </td>
                            <td class="px-8 py-5">
                                <div class="font-bold text-slate-700">$ {{ number_format($envelope->total_amount, 2) }}
                                </div>
                                <div class="text-[10px] text-slate-400 uppercase tracking-widest font-bold">{{ __('100.0% allocated') }}</div>
                            </td>
                            <td class="px-8 py-5 text-slate-600">
                                <div class="font-bold">$ 0.00</div>
                                <div class="text-[10px] text-slate-400 uppercase tracking-widest font-bold">{{ __('0.0% of allocated') }}</div>
                            </td>
                            <td class="px-8 py-5">
                                <div class="flex items-center gap-2">
                                    <span class="font-black text-slate-900">0.0%</span>
                                    <div class="h-1.5 w-20 bg-slate-100 rounded-full overflow-hidden shadow-inner">
                                        <div class="h-full bg-slate-300" style="width: 0%"></div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-8 py-5">
                                <div class="text-xs font-bold text-slate-900">
                                    {{ $envelope->start_date ? \Carbon\Carbon::parse($envelope->start_date)->format('Y-m-d') : '-' }}
                                    {{ __('to') }}
                                    {{ $envelope->due_date ? \Carbon\Carbon::parse($envelope->due_date)->format('Y-m-d') : '-' }}
                                </div>
                            </td>

                            <td class="px-8 py-5">
                                <div class="flex flex-wrap gap-2">
                                    @if($envelope->isActiveByDate())
                                        <span
                                            class="inline-flex items-center rounded-xl bg-emerald-600 px-2.5 py-1 text-[9px] font-black text-white leading-tight uppercase tracking-[0.15em] shadow-sm">{{ __('Active Now') }}</span>
                                    @endif

                                    @if($envelope->status === 'Approved')
                                        <span
                                            class="inline-flex items-center rounded-xl bg-blue-50 px-2.5 py-1 text-[9px] font-black text-blue-700 leading-tight uppercase tracking-[0.15em] border border-blue-100">{{ __('Approved') }}</span>
                                    @elseif($envelope->status === 'Pending Approval')
                                        <span
                                            class="inline-flex items-center rounded-xl bg-amber-50 px-2.5 py-1 text-[9px] font-black text-amber-700 leading-tight uppercase tracking-[0.15em] border border-amber-100">{{ __('Pending') }}</span>
                                    @elseif($envelope->status === 'Closed')
                                        <span
                                            class="inline-flex items-center rounded-xl bg-rose-50 px-2.5 py-1 text-[9px] font-black text-rose-700 leading-tight uppercase tracking-[0.15em] border border-rose-100">{{ __('Closed') }}</span>
                                    @else
                                        <span
                                            class="inline-flex items-center rounded-xl bg-slate-100 px-2.5 py-1 text-[9px] font-black text-slate-500 leading-tight uppercase tracking-[0.15em] border border-slate-200">{{ __('Draft') }}</span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-8 py-5 text-right flex justify-end gap-2">
                                <button wire:click="viewDetails({{ $envelope->id }})"
                                    class="group inline-flex items-center gap-1.5 px-3 py-1.5 text-slate-500 hover:text-slate-900 transition-all rounded-xl hover:bg-slate-100">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                                        stroke-linejoin="round">
                                        <circle cx="12" cy="12" r="3" />
                                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                                    </svg>
                                    <span class="font-black uppercase text-[10px] tracking-[0.15em]">{{ __('View') }}</span>
                                </button>
                                @if($envelope->status === App\Models\FiscalEnvelope::STATUS_DRAFT)
                                    <button wire:click="openModal({{ $envelope->fiscal_year }})"
                                        class="group inline-flex items-center gap-1.5 px-3 py-1.5 text-slate-400 hover:text-slate-900 transition-all rounded-xl hover:bg-slate-100">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
                                            fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                                            stroke-linejoin="round">
                                            <path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z" />
                                            <path d="m15 5 4 4" />
                                        </svg>
                                        <span class="font-black uppercase text-[10px] tracking-[0.15em]">{{ __('Edit') }}</span>
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-6 py-24 text-center">
                                <div class="flex flex-col items-center">
                                    <div class="bg-gray-100 p-3 rounded-full mb-3 text-gray-400">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                            fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                            stroke-linejoin="round">
                                            <path
                                                d="M4 22h16a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v16a2 2 0 0 1-2 2Zm0 0a2 2 0 0 1-2-2v-9c0-1.1.9-2 2-2h2" />
                                            <path d="M18 14h-8" />
                                            <path d="M15 18h-5" />
                                            <path d="M10 6h8v4h-8V6Z" />
                                        </svg>
                                    </div>
                                    <p class="text-sm font-bold text-gray-900">{{ __('No Envelopes Found') }}</p>
                                    <p class="text-xs text-gray-500 mt-1">
                                        {{ __('Start by creating a new fiscal year envelope.') }}
                                    </p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>