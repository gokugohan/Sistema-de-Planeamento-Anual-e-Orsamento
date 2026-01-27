@php
    $level = $level ?? 0;
    $hasChildren = $d->children->isNotEmpty();
    $rowId = 'dir-' . $d->id;
    $parentRowId = $parentId ?? null;
@endphp

<tr id="{{ $rowId }}"
    class="tree-row hover:bg-slate-50/50 transition-colors group text-sm {{ $level > 0 ? 'hidden' : '' }}"
    data-level="{{ $level }}" @if($parentRowId) data-parent-id="{{ $parentRowId }}" @endif>

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
                    <div class="font-bold text-gray-900 lowercase first-letter:uppercase">{{ $d->name }}</div>
                    <div class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mt-0.5">
                        {{ $d->code }} ({{ $d->type }})
                    </div>
                </div>
            @else
                <div>
                    <div class="font-bold text-gray-900 lowercase first-letter:uppercase">{{ $d->name }}</div>
                    <div class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mt-0.5">
                        {{ $d->code }} ({{ $d->type }})
                    </div>
                </div>
            @endif

        </div>

    </td>
    <td class="px-8 py-5 text-gray-900">
        <div class="font-bold text-gray-900 leading-none text-xs">
            {{ $d->director_name ?: 'Maria Silva' }}
        </div>
        <div class="text-[9px] text-gray-400 mt-1 italic">{{ $d->director_email ?: 'maria.silva@msa.gov.tl' }}</div>
    </td>
    <td class="px-8 py-5">
        <div class="font-bold text-gray-900">{{ number_format($d->budget_allocated, 2) }}</div>
        <div class="text-[10px] text-gray-400 mt-1 italic">$ {{ number_format($d->spent, 2) }}
            {{ __('spent') }}
        </div>
    </td>
    <td class="px-8 py-5 whitespace-nowrap">
        <div class="flex items-center gap-3">
            <div class="w-16 h-1.5 bg-gray-100 rounded-full overflow-hidden shadow-inner">
                <div style="width:{{ $d->utilization }}%"
                    class="h-full bg-{{ $d->utilization > 90 ? 'rose-500 shadow-[0_0_8px_rgba(244,63,94,0.4)]' : ($d->utilization > 70 ? 'amber-500' : 'indigo-600') }} rounded-full transition-all duration-700">
                </div>
            </div>
            <span class="text-[10px] font-black text-gray-900">{{ number_format($d->utilization, 1) }}%</span>
        </div>
    </td>
    <td class="px-8 py-5">
        <x-ui.badge variant="black" pulse="true" class="!text-[9px]">{{ __('Active') }}</x-ui.badge>
    </td>
    <td class="px-8 py-5 text-right">
        <button wire:click="openAdvancedView({{ $d->id }})"
            class="p-2 text-slate-400 hover:text-indigo-600 hover:bg-slate-100 rounded-xl transition-all"
            title="{{ __('View Advanced Details') }}">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z" />
                <circle cx="12" cy="12" r="3" />
            </svg>
        </button>
    </td>
</tr>

@if($hasChildren)
    @foreach($d->children as $child)
        @include('livewire.pages.admin.entities-tabs.directorate-row', ['d' => $child, 'level' => $level + 1, 'parentId' => $rowId])
    @endforeach
@endif