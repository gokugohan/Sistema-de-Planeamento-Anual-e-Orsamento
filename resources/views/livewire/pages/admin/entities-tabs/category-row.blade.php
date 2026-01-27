@php
    $hasChildren = $category->activities->isNotEmpty();
    $rowId = $parentId . '-cat-' . $category->id;
@endphp

<tr id="{{ $rowId }}" class="tree-row hover:bg-slate-50/50 transition-colors group text-sm hidden" data-level="1"
    data-parent-id="{{ $parentId }}">

    <td class="px-8 py-5" style="padding-left: 3rem;">
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
                    <div class="font-bold text-indigo-600 uppercase tracking-tight">{{ $category->name }}</div>
                    <div class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mt-0.5">
                        {{ $category->code }} - {{ __('Economic Category') }}
                    </div>
                </div>
            @else
                <div class="w-6"></div>
                <div>
                    <div class="font-bold text-indigo-600 uppercase tracking-tight">{{ $category->name }}</div>
                    <div class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mt-0.5">
                        {{ $category->code }} - {{ __('Economic Category') }}
                    </div>
                </div>
            @endif
        </div>
    </td>
    <td class="px-8 py-5 text-gray-400 italic text-[10px]">
        <!-- {{ __('Category Aggregate') }} -->
        ---
    </td>
    <td class="px-8 py-5 font-bold text-gray-400 text-[10px]">
        ---
    </td>
    <td class="px-8 py-5">
        <div class="font-bold text-slate-700 text-xs">$ {{ number_format($category->budget_allocated, 2) }}</div>
        <div class="text-[9px] text-gray-400 mt-0.5 italic">$ {{ number_format($category->spent, 2) }} {{ __('spent') }}
        </div>
    </td>
    <td class="px-8 py-5 whitespace-nowrap">
        <div class="flex items-center gap-2">
            <div class="w-12 h-1 bg-gray-100 rounded-full overflow-hidden">
                <div style="width:{{ $category->utilization }}%"
                    class="h-full bg-indigo-400 rounded-full transition-all duration-700">
                </div>
            </div>
            <span class="text-[10px] font-bold text-slate-500">{{ number_format($category->utilization, 1) }}%</span>
        </div>
    </td>
    <td class="px-8 py-5 max-w-[200px] text-[9px] text-gray-400 leading-tight">
        {{ __('Resources allocated for') }} {{ $category->name }}
    </td>
    <td class="px-8 py-5">
        <x-ui.badge variant="gray" class="!text-[8px] opacity-70">{{ __('Category') }}</x-ui.badge>
    </td>
</tr>

@if($hasChildren)
    @foreach($category->activities as $activity)
        @include('livewire.pages.admin.entities-tabs.activity-row', [
            'p' => $p,
            'category' => $category,
            'a' => $activity,
            'parentId' => $rowId,
            'level' => 2
        ])
    @endforeach
@endif
