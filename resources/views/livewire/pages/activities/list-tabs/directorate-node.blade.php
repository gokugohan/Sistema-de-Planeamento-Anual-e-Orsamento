@php
    $hasActivities = $node['activities']->isNotEmpty();
    $hasChildren   = $node['children']->isNotEmpty();
    $hasContent    = $hasActivities || $hasChildren;
    $defaultOpen   = $depth === 0;

    $typePill = match($node['type'] ?? '') {
        'General Directorate'  => 'bg-indigo-100 text-indigo-700',
        'National Directorate' => 'bg-violet-100 text-violet-700',
        'Department'           => 'bg-sky-100 text-sky-700',
        'Independent'          => 'bg-amber-100 text-amber-700',
        default                => 'bg-zinc-100 text-zinc-500',
    };

    // Depth-based left-border color for tree-line effect
    $borderColor = match($depth % 4) {
        0       => 'border-indigo-300',
        1       => 'border-violet-300',
        2       => 'border-sky-300',
        default => 'border-zinc-300',
    };
@endphp

<div x-data="{ open: {{ $defaultOpen ? 'true' : 'false' }} }"
    @dir-expand-all.window="open = true"
    @dir-collapse-all.window="open = false"
    class="{{ $depth > 0 ? 'mt-1' : '' }}">

    {{-- ── Node header ──────────────────────────────────────────────── --}}
    <div class="flex items-center gap-0 group/header
                {{ $depth === 0 ? 'bg-white rounded-xl ring-1 ring-zinc-950/5 shadow-sm' : 'bg-zinc-50/80 rounded-lg' }}">

        {{-- Depth tree line (shown for non-root) --}}
        @if($depth > 0)
            <div class="w-4 flex-shrink-0 self-stretch flex items-center justify-center">
                <div class="w-px h-full bg-zinc-200"></div>
            </div>
        @endif

        {{-- Toggle button --}}
        <button
            @if($hasContent) @click="open = !open" @endif
            class="flex-1 flex items-center gap-3 px-4 py-3 text-left transition-colors rounded-xl
                   {{ $hasContent ? 'cursor-pointer hover:bg-zinc-50' : 'cursor-default' }}
                   {{ $depth === 0 ? 'rounded-xl' : 'rounded-lg' }}"
            @if(!$hasContent) disabled @endif>

            {{-- Chevron --}}
            <span class="flex-shrink-0 w-5 h-5 flex items-center justify-center">
                @if($hasContent)
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                        class="text-zinc-400 transition-transform duration-200" :class="open ? 'rotate-90' : ''">
                        <path d="m9 18 6-6-6-6"/>
                    </svg>
                @else
                    <span class="w-1.5 h-1.5 rounded-full bg-zinc-300 block mx-auto"></span>
                @endif
            </span>

            {{-- Code badge --}}
            <span class="flex-shrink-0 text-xs font-semibold px-2 py-0.5 rounded-md {{ $typePill }}">
                {{ $node['code'] ?? '–' }}
            </span>

            {{-- Name --}}
            <span class="flex-1 min-w-0 text-sm font-semibold text-zinc-800 truncate
                         {{ $depth === 0 ? 'text-base' : '' }}">
                {{ $node['name'] }}
            </span>

            {{-- Status micro-breakdown (hidden on small screens) --}}
            <span class="hidden lg:flex items-center gap-2 flex-shrink-0 mr-2">
                @if($node['count_draft'] > 0)
                    <span class="inline-flex items-center gap-1 text-xs font-medium text-blue-600">
                        <span class="w-1.5 h-1.5 rounded-full bg-blue-500"></span>{{ $node['count_draft'] }}
                    </span>
                @endif
                @if($node['count_review'] > 0)
                    <span class="inline-flex items-center gap-1 text-xs font-medium text-indigo-600">
                        <span class="w-1.5 h-1.5 rounded-full bg-indigo-500 animate-pulse"></span>{{ $node['count_review'] }}
                    </span>
                @endif
                @if($node['count_waiting'] > 0)
                    <span class="inline-flex items-center gap-1 text-xs font-medium text-amber-600">
                        <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>{{ $node['count_waiting'] }}
                    </span>
                @endif
                @if($node['count_approved'] > 0)
                    <span class="inline-flex items-center gap-1 text-xs font-medium text-emerald-600">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>{{ $node['count_approved'] }}
                    </span>
                @endif
                @if($node['count_rejected'] > 0)
                    <span class="inline-flex items-center gap-1 text-xs font-medium text-rose-500">
                        <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span>{{ $node['count_rejected'] }}
                    </span>
                @endif
            </span>

            {{-- Summary chips --}}
            <span class="hidden sm:flex items-center gap-3 flex-shrink-0 text-right">
                <span class="text-xs text-zinc-400">
                    <span class="font-semibold text-zinc-700">{{ $node['total_activities'] }}</span>
                    {{ __('act.') }}
                </span>
                <span class="text-xs text-zinc-400 hidden md:inline">
                    $<span class="font-semibold text-zinc-700">{{ number_format($node['total_estimated'] / 1000, 0) }}k</span>
                </span>
            </span>
        </button>
    </div>

    {{-- ── Expanded content ─────────────────────────────────────────── --}}
    @if($hasContent)
        <div x-show="open" x-collapse class="overflow-hidden">
            <div class="pl-5 mt-1 border-l-2 {{ $borderColor }} ml-6 space-y-0.5">

                {{-- Own activities list --}}
                @foreach($node['activities'] as $activity)
                    @php
                        $dot = match($activity->status) {
                            'Approved'         => 'bg-emerald-500',
                            'Waiting Approval' => 'bg-amber-500',
                            'Rejected'         => 'bg-rose-500',
                            'Submitted',
                            'Reviewed',
                            'Validated'        => 'bg-indigo-500',
                            default            => 'bg-zinc-300',
                        };
                        $statusLabel = match($activity->status) {
                            'Approved'         => __('Approved'),
                            'Waiting Approval' => __('Waiting'),
                            'Rejected'         => __('Rejected'),
                            'Submitted'        => __('Prepared'),
                            'Reviewed'         => __('Verified'),
                            'Validated'        => __('Certified'),
                            default            => __('Draft'),
                        };
                        $statusColor = match($activity->status) {
                            'Approved'         => 'text-emerald-700 bg-emerald-50 ring-emerald-200',
                            'Waiting Approval' => 'text-amber-700 bg-amber-50 ring-amber-200',
                            'Rejected'         => 'text-rose-700 bg-rose-50 ring-rose-200',
                            'Submitted',
                            'Reviewed',
                            'Validated'        => 'text-indigo-700 bg-indigo-50 ring-indigo-200',
                            default            => 'text-zinc-600 bg-zinc-100 ring-zinc-200',
                        };
                    @endphp

                    <div class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-white hover:shadow-sm hover:ring-1 hover:ring-zinc-950/5 transition-all group/act">

                        {{-- Status dot --}}
                        <span class="flex-shrink-0 w-2 h-2 rounded-full {{ $dot }}
                                     {{ in_array($activity->status, ['Submitted', 'Reviewed', 'Waiting Approval']) ? 'animate-pulse' : '' }}"></span>

                        {{-- Description --}}
                        <span class="flex-1 min-w-0 text-sm text-zinc-700 group-hover/act:text-zinc-900 truncate"
                              title="{{ $activity->description }}">
                            {{ $activity->description }}
                        </span>

                        {{-- Meta chips --}}
                        <span class="hidden md:flex items-center gap-2 flex-shrink-0">
                            <span class="text-xs font-medium text-zinc-400 bg-zinc-100 px-1.5 py-0.5 rounded">
                                {{ $activity->program->oge_code ?? '–' }}
                            </span>
                            @if($activity->indicators_count > 0)
                                <span class="text-xs font-medium text-zinc-400 flex items-center gap-0.5">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2.5"
                                        stroke-linecap="round" stroke-linejoin="round">
                                        <path d="m12 14 4-4 4 4"/><path d="M3 3v18h18"/>
                                    </svg>
                                    {{ $activity->indicators_count }}
                                </span>
                            @endif
                            <span class="text-xs font-medium text-zinc-500">
                                ${{ number_format(($activity->line_items_sum_estimated_amount ?? 0) / 1000, 0) }}k
                            </span>
                        </span>

                        {{-- Status badge --}}
                        <span class="flex-shrink-0 text-xs font-medium px-2 py-0.5 rounded-full ring-1 {{ $statusColor }}">
                            {{ $statusLabel }}
                        </span>

                        {{-- View link (always visible, not hover-only) --}}
                        <a href="{{ route('activities.show', $activity->id) }}" wire:navigate
                            class="flex-shrink-0 inline-flex items-center justify-center w-6 h-6 rounded-md
                                   text-zinc-400 hover:text-indigo-600 hover:bg-indigo-50 transition-colors"
                            title="{{ __('View') }}">
                            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor" stroke-width="2.5"
                                stroke-linecap="round" stroke-linejoin="round">
                                <path d="m9 18 6-6-6-6"/>
                            </svg>
                        </a>
                    </div>
                @endforeach

                {{-- No direct activities (but has children) --}}
                @if(!$hasActivities && $hasChildren)
                    <p class="px-3 py-1.5 text-xs text-zinc-400 italic">{{ __('No direct activities — see sub-units below.') }}</p>
                @endif

                {{-- Recursive children --}}
                @foreach($node['children'] as $child)
                    @include('livewire.pages.activities.list-tabs.directorate-node', ['node' => $child, 'depth' => $depth + 1])
                @endforeach
            </div>
        </div>
    @endif
</div>
