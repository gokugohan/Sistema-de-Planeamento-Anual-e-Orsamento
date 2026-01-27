@php
    $hasChildren = $p->categories->isNotEmpty();
    $rowId = 'prog-' . $p->id;
@endphp

<tr id="{{ $rowId }}" class="tree-row hover:bg-slate-50/50 transition-colors group text-sm" data-level="0">

    <td class="px-8 py-5">
        <div class="flex items-center gap-3">
            @if($hasChildren)
                <button onclick="toggleTree('{{ $rowId }}')"
                    class="p-1 hover:bg-slate-200 rounded-md transition-all tree-toggle-btn">
                    <svg class="transition-transform duration-200" xmlns="http://www.w3.org/2000/svg" width="12" height="12"
                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path d="m9 18 6-6-6-6" />
                    </svg>
                </button>
                <div onclick="toggleTree('{{ $rowId }}')" class="cursor-pointer">
                    <div class="font-bold text-gray-900 lowercase first-letter:uppercase">{{ $p->name }}</div>
                    <div class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mt-0.5">
                        {{ $p->oge_code }} - {{ __('Institutional Program') }}
                    </div>
                </div>
            @else
                <div class="w-6"></div>
                <div>
                    <div class="font-bold text-gray-900 lowercase first-letter:uppercase">{{ $p->name }}</div>
                    <div class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mt-0.5">
                        {{ $p->oge_code }} - {{ __('Institutional Program') }}
                    </div>
                </div>
            @endif
        </div>
    </td>
    <td class="px-8 py-5 text-gray-500 italic">
        {{ __('Multi-Directorate') }}
    </td>
    <td class="px-8 py-5 font-bold text-gray-900">
        {{ $p->manager_name ?: 'Teresa Soares' }}
    </td>
    <td class="px-8 py-5">
        <div class="font-bold text-gray-900">$ {{ number_format($p->budget_allocated, 2) }}</div>
        <div class="text-[10px] text-gray-400 mt-1 italic">$ {{ number_format($p->spent, 2) }} {{ __('spent') }}</div>
    </td>
    <td class="px-8 py-5 whitespace-nowrap">
        <div class="flex items-center gap-3">
            <div class="w-16 h-1.5 bg-gray-100 rounded-full overflow-hidden shadow-inner">
                <div style="width:{{ $p->utilization }}%"
                    class="h-full bg-{{ $p->utilization > 90 ? 'rose-500 shadow-[0_0_8px_rgba(244,63,94,0.4)]' : ($p->utilization > 70 ? 'amber-500' : 'black') }} rounded-full transition-all duration-700">
                </div>
            </div>
            <span class="text-[10px] font-black text-gray-900">{{ number_format($p->utilization, 1) }}%</span>
        </div>
    </td>
    <td class="px-8 py-5 max-w-[200px] text-[10px] text-gray-500 leading-relaxed">
        {{ $p->kpis ?: __('No KPIs listed') }}
    </td>
    <td class="px-8 py-5">
        <x-ui.badge variant="gray">{{ __('Active') }}</x-ui.badge>
    </td>
</tr>

@if($hasChildren)
    @foreach($p->categories as $category)
        @include('livewire.pages.admin.entities-tabs.category-row', [
            'p' => $p,
            'category' => $category,
            'parentId' => $rowId,
            'level' => 1
        ])
    @endforeach
@endif
