@php
    $totalNodes = $this->directorateViewData->count();
    $totalActs  = $this->directorateViewData->sum('total_activities');
@endphp

<div x-data="{}">

    {{-- Toolbar --}}
    @if($totalNodes > 0)
        <div class="flex items-center justify-between mb-3">
            <p class="text-sm text-zinc-500">
                <span class="font-medium text-zinc-700">{{ $totalNodes }}</span>
                {{ __('top-level directorates') }} &mdash;
                <span class="font-medium text-zinc-700">{{ $totalActs }}</span>
                {{ __('total activities') }}
            </p>
            <div class="flex items-center gap-1">
                <button @click="$dispatch('dir-expand-all')"
                    class="px-3 py-1.5 text-xs font-medium text-zinc-600 hover:text-zinc-900 bg-white rounded-lg ring-1 ring-zinc-950/5 shadow-sm hover:bg-zinc-50 transition-colors">
                    {{ __('Expand all') }}
                </button>
                <button @click="$dispatch('dir-collapse-all')"
                    class="px-3 py-1.5 text-xs font-medium text-zinc-600 hover:text-zinc-900 bg-white rounded-lg ring-1 ring-zinc-950/5 shadow-sm hover:bg-zinc-50 transition-colors">
                    {{ __('Collapse all') }}
                </button>
            </div>
        </div>
    @endif

    {{-- Tree --}}
    <div class="space-y-2">
        @forelse($this->directorateViewData as $dirData)
            @include('livewire.pages.activities.list-tabs.directorate-node', ['node' => $dirData, 'depth' => 0])
        @empty
            <div class="text-center py-16 bg-white rounded-xl ring-1 ring-zinc-950/5 shadow-sm">
                <div class="w-14 h-14 bg-zinc-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                        class="text-zinc-300">
                        <path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/>
                        <path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/>
                        <path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/>
                    </svg>
                </div>
                <p class="text-sm text-zinc-400 font-medium">{{ __('No directorates found for this fiscal year.') }}</p>
            </div>
        @endforelse
    </div>
</div>
