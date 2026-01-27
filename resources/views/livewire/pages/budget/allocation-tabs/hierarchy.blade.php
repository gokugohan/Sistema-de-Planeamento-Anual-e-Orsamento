<div class="space-y-6">
    <div class="flex items-center gap-2 text-gray-900">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="text-gray-400"><path d="m3 21 1.9-1.9"/><path d="m3 21 1.9-1.9"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/><path d="M14 14h7v7h-7z"/><path d="M7 14V7"/><path d="M14 7h-7"/></svg>
        <h3 class="font-black text-lg">Budget Allocation Hierarchy</h3>
    </div>
    <p class="text-xs font-medium text-gray-400 mt-1">Navigate through the complete budget structure from directorates to line items</p>

    <div class="space-y-6 mt-6">
        @foreach($this->directorates as $dir)
            <div x-data="{ expanded: false }" class="border border-gray-100 rounded-2xl overflow-hidden shadow-sm hover:border-gray-200 transition-colors">
                <div @click="expanded = !expanded" class="flex justify-between items-center p-5 bg-white cursor-pointer group">
                    <div class="flex items-center gap-4">
                        <div class="h-10 w-10 rounded-xl bg-gray-50 flex items-center justify-center text-gray-400 group-hover:bg-indigo-50 group-hover:text-indigo-600 transition-colors">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9h18v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V9Z"/><path d="m3 9 2.45-4.9A2 2 0 0 1 7.24 3h9.52a2 2 0 0 1 1.8 1.1L21 9"/><path d="M12 3v6"/></svg>
                        </div>
                        <div>
                            <h4 class="font-black text-gray-900">{{ $dir->name }} ({{ $dir->code }})</h4>
                            <p class="text-[10px] font-bold text-gray-400 leading-none mt-1">$ {{ number_format(0, 0) }} spent of $ {{ number_format($dir->total_allocated, 0) }} allocated</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-8">
                        <div class="text-right">
                            @php $dirPercent = $dir->budget_limit > 0 ? ($dir->total_allocated / $dir->budget_limit) * 100 : 0; @endphp
                            <p class="text-[10px] font-black text-gray-500 uppercase">{{ number_format($dirPercent, 1) }}% used</p>
                            <div class="w-32 h-1.5 bg-gray-100 rounded-full mt-1 overflow-hidden">
                                <div class="h-full bg-black transition-all duration-700" style="width:{{ min($dirPercent, 100) }}%"></div>
                            </div>
                        </div>
                        <svg :class="expanded ? 'rotate-180' : ''" class="text-gray-300 transition-transform duration-300" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                    </div>
                </div>

                <div x-show="expanded" x-collapse>
                    <div class="px-6 pb-6 pt-2 space-y-4">
                        @foreach($dir->activities->groupBy('program_id') as $programId => $activities)
                            @php $prog = $activities->first()->program; @endphp
                            <div x-data="{ progExpanded: false }" class="ml-10 border-l-2 border-gray-50 pl-6 space-y-3">
                                <div @click="progExpanded = !progExpanded" class="flex justify-between items-center py-2 cursor-pointer group">
                                     <div class="flex items-center gap-3">
                                        <div class="h-1.5 w-1.5 rounded-full bg-indigo-500"></div>
                                        <h5 class="text-sm font-bold text-gray-700 group-hover:text-black transition-colors">{{ $prog->oge_code }} - {{ $prog->name }}</h5>
                                     </div>
                                     <div class="flex items-center gap-4">
                                        <span class="text-xs font-black text-gray-900">$ {{ number_format($activities->flatMap->lineItems->sum('estimated_amount'), 0) }}</span>
                                        <svg :class="progExpanded ? 'rotate-180' : ''" class="text-gray-300 transition-transform" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                                     </div>
                                </div>
                                <div x-show="progExpanded" x-collapse class="space-y-4 pt-2">
                                    @foreach($activities as $act)
                                        <div x-data="{ actExpanded: false }" class="ml-6 space-y-2">
                                            <div @click="actExpanded = !actExpanded" class="flex justify-between items-center py-2.5 px-4 bg-gray-50/50 rounded-xl hover:bg-gray-100 transition-all cursor-pointer group/act">
                                                <div class="flex items-center gap-3">
                                                    <div class="h-8 w-8 rounded-lg bg-white shadow-sm flex items-center justify-center text-gray-400 group-hover/act:text-indigo-600 transition-colors">
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 13V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h7"/><path d="M17 13h5"/><path d="M17 17h5"/><path d="M17 21h5"/></svg>
                                                    </div>
                                                    <span class="text-xs font-bold text-gray-700">{{ $act->code }}: {{ $act->description }}</span>
                                                </div>
                                                <div class="flex items-center gap-6">
                                                    @php $actTotal = $act->lineItems->sum('estimated_amount'); @endphp
                                                    <div class="text-right">
                                                        <span class="text-[10px] font-black text-gray-400 tracking-tighter block">$ {{ number_format(0, 0) }} / $ {{ number_format($actTotal, 0) }}</span>
                                                        <div class="w-24 h-1 bg-gray-200 rounded-full overflow-hidden mt-1">
                                                            <div class="h-full bg-indigo-600" style="width: 40%"></div>
                                                        </div>
                                                    </div>
                                                    <svg :class="actExpanded ? 'rotate-180' : ''" class="text-gray-300 transition-transform" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                                                </div>
                                            </div>
                                            <div x-show="actExpanded" x-collapse class="ml-12 space-y-1.5 pt-1">
                                                @foreach($act->lineItems as $item)
                                                    <div class="flex justify-between items-center py-2 px-4 border border-gray-100 rounded-xl bg-white hover:border-indigo-100 transition-colors group/item">
                                                        <div class="flex items-center gap-3">
                                                            <div class="h-6 w-6 rounded flex items-center justify-center text-gray-400 group-hover/item:text-indigo-400">
                                                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                                                            </div>
                                                            <div>
                                                                <div class="text-[11px] font-bold text-gray-900 leading-tight">{{ $item->economicClassification->code }} - {{ $item->economicClassification->name }}</div>
                                                                <div class="text-[9px] text-gray-400 font-medium line-clamp-1">{{ $item->economicClassification->description ?? 'Line item allocation' }}</div>
                                                            </div>
                                                        </div>
                                                        <div class="flex items-center gap-4">
                                                            <span class="text-xs font-black text-gray-900">$ {{ number_format($item->estimated_amount, 2) }}</span>
                                                            <div class="w-16 h-1 bg-gray-50 rounded-full overflow-hidden">
                                                                <div class="h-full bg-indigo-200" style="width: 100%"></div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</div>
