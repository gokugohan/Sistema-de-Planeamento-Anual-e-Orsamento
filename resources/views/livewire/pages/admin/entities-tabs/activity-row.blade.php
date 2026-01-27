@php
    $rowId = $parentId . '-act-' . $a->id;
@endphp

<tr id="{{ $rowId }}" class="tree-row hover:bg-slate-50/50 transition-colors group text-sm hidden" data-level="2"
    data-parent-id="{{ $parentId }}">

    <td class="px-8 py-4" style="padding-left: 5rem;">
        <div class="flex items-center gap-2">
            <div
                class="p-1.5 bg-slate-100 rounded-lg text-slate-400 group-hover:text-indigo-600 group-hover:bg-indigo-50 transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z" />
                    <polyline points="14 2 14 8 20 8" />
                </svg>
            </div>
            <div>
                <div class="font-medium text-slate-700 leading-tight">{{ $a->description }}</div>
                <div class="text-[9px] font-black text-indigo-500 uppercase tracking-tighter mt-0.5">
                    {{ $a->code ?: 'ACT-' . $a->id }}
                </div>
            </div>
        </div>
    </td>
    <td class="px-8 py-4">
        <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">
            {{ $a->directorate?->code ?? 'N/A' }}
        </div>
    </td>
    <td class="px-8 py-4 text-[10px] text-slate-500">
        {{ $a->manager_name ?: 'Manager N/A' }}
    </td>
    <td class="px-8 py-4">
        <div class="font-bold text-slate-900 text-[11px]">$ {{ number_format($a->cat_allocated, 2) }}</div>
        <div class="text-[9px] text-gray-400 mt-0.5">$ {{ number_format($a->cat_spent, 2) }}</div>
    </td>
    <td class="px-8 py-4 whitespace-nowrap">
        <span class="text-[10px] font-black {{ $a->cat_utilization > 90 ? 'text-rose-600' : 'text-slate-900' }}">
            {{ number_format($a->cat_utilization, 1) }}%
        </span>
    </td>
    <td class="px-8 py-4">
        <div class="flex items-center gap-1">
            @for($i = 1; $i <= 4; $i++)
                <div class="w-2.5 h-1 rounded-full {{ $a->quarter >= $i ? 'bg-indigo-500' : 'bg-slate-100' }}"></div>
            @endfor
            <span class="text-[9px] font-bold text-slate-400 ml-1">Q{{ $a->quarter }}</span>
        </div>
    </td>
    <td class="px-8 py-4">
        <x-ui.badge variant="black" class="!text-[8px] !px-1.5 !py-0.5">{{ $a->status }}</x-ui.badge>
    </td>
</tr>