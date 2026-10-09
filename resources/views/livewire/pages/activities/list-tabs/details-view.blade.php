<x-ui.card padding="p-0">
    <x-ui.table :headers="[__('Ref / Program'), __('Activity Analysis'), __('Priority'), __('Metrics'), __('Budgeting') . ' ($)', __('Status')]">
        <x-slot name="actions"></x-slot>
        @foreach($activities as $activity)
            <tr class="hover:bg-slate-50/50 transition-all duration-300 group">
                <td class="px-6 py-4">
                    <div class="font-black text-slate-900 tracking-tight">{{ $activity->program->oge_code }}</div>
                    <div class="text-[9px] text-slate-400 mt-1 uppercase font-bold tracking-widest leading-none">
                        {{ $activity->program->name }}
                    </div>
                </td>
                <td class="px-6 py-4 w-full min-w-[20rem]">
                    <div class="font-bold text-slate-700 leading-tight group-hover:text-indigo-600 transition-colors">
                        {{ $activity->description }}
                    </div>
                    @if($activity->directorate)
                        <div class="text-[9px] text-slate-400 mt-1 uppercase font-medium italic opacity-60">
                            {{ $activity->directorate->name }}
                        </div>
                    @endif
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    @php
                        $priorityVariant = match ($activity->priority) {
                            'Alta' => 'red',
                            'Média' => 'yellow',
                            default => 'green',
                        };
                        $priorityLabel = match ($activity->priority) {
                            'Alta' => __('High'),
                            'Média' => __('Medium'),
                            default => __('Low'),
                        };
                    @endphp
                    <x-ui.badge :variant="$priorityVariant"
                        class="!rounded-md border-none">{{ $priorityLabel }}</x-ui.badge>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="flex items-center gap-2">
                        <x-ui.badge variant="blue" class="!bg-blue-50/50"
                            title="Indicators">{{ $activity->indicators_count }} I</x-ui.badge>
                        <x-ui.badge variant="indigo" class="!bg-indigo-50/50"
                            title="Budget Items">{{ $activity->line_items_count }} B</x-ui.badge>
                    </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-right">
                    <div class="font-black text-slate-900 tracking-tighter text-sm">
                        $ {{ number_format(($activity->line_items_sum_estimated_amount ?? 0), 2) }}
                    </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    @php
                        $statusVariant = match ($activity->status) {
                            'Approved' => 'green',
                            'Submitted' => 'indigo',
                            'Reviewed' => 'amber',
                            'Rejected' => 'red',
                            'Cut' => 'gray',
                            'Draft' => 'blue',
                            default => 'blue',
                        };

                        $statusLabel = match ($activity->status) {
                            'Approved' => __('Approved'),
                            'Submitted' => __('Submitted'),
                            'Reviewed' => __('Reviewed'),
                            'Rejected' => __('Rejected'),
                            'Cut' => __('Cut'),
                            'Draft' => __('Draft'),
                            default => __('Unknown'),
                        };
                    @endphp
                    <div class="flex flex-col">
                        <x-ui.badge :variant="$statusVariant" :pulse="in_array($activity->status, ['Submitted', 'Reviewed', 'Validated'])">
                            {{ $statusLabel }}
                        </x-ui.badge>

                        @if($activity->status === App\Models\Activity::STATUS_APPROVED && $activity->approved_at)
                            <div class="text-[9px] text-emerald-600 font-bold mt-1 leading-none uppercase tracking-tighter">
                                {{ __('Approved') }}: {{ \Carbon\Carbon::parse($activity->approved_at)->format('d/m/Y') }}
                            </div>
                            @if($activity->approvedBy)
                                <div class="text-[8px] text-slate-400 font-medium italic mt-0.5">
                                    {{ __('by') }} {{ $activity->approvedBy->name }}
                                </div>
                            @endif
                        @elseif($activity->status === App\Models\Activity::STATUS_SUBMITTED)
                            <div
                                class="text-[9px] text-indigo-500 font-black mt-1 leading-none uppercase tracking-tighter flex items-center gap-1">
                                <span class="relative flex h-1.5 w-1.5">
                                    <span
                                        class="animate-ping absolute inline-flex h-full w-full rounded-full bg-indigo-400 opacity-75"></span>
                                    <span class="relative inline-flex rounded-full h-1.5 w-1.5 bg-indigo-500"></span>
                                </span>
                                {{ __('Pending Review') }}
                            </div>
                            <div class="text-[8px] text-slate-400 font-bold uppercase mt-1 opacity-70">
                                {{ __('Submitted to Directorate') }}
                            </div>
                        @elseif($activity->status === App\Models\Activity::STATUS_REVIEWED)
                            <div
                                class="text-[9px] text-amber-600 font-black mt-1 leading-none uppercase tracking-tighter flex items-center gap-1">
                                <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z" />
                                    <path d="m9 12 2 2 4-4" />
                                </svg>
                                {{ __('Technically Reviewed') }}
                            </div>
                            <div class="text-[8px] text-slate-400 font-bold uppercase mt-1 opacity-70">
                                {{ __('Pending General Directorate') }}
                            </div>
                        @elseif($activity->status === App\Models\Activity::STATUS_VALIDATED)
                            <div
                                class="text-[9px] text-indigo-600 font-black mt-1 leading-none uppercase tracking-tighter flex items-center gap-1">
                                <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
                                    <polyline points="22 4 12 14.01 9 11.01" />
                                </svg>
                                {{ __('Validated') }}
                            </div>
                            <div class="text-[8px] text-slate-400 font-bold uppercase mt-1 opacity-70">
                                {{ __('Final Ministry Queue') }}
                            </div>
                        @endif
                    </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-right">
                    <div
                        class="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-all group-hover:translate-x-0 translate-x-4">
                        <x-ui.button variant="ghost" size="icon" href="{{ route('activities.show', $activity->id) }}"
                            wire:navigate class="!rounded-lg hover:bg-white hover:shadow-sm" title="View Portfolio">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0z" />
                                <path
                                    d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                            </svg>
                        </x-ui.button>

                        @if(!$this->isYearLocked($activity->fiscal_year))
                            @if($activity->status === App\Models\Activity::STATUS_DRAFT || $activity->status === App\Models\Activity::STATUS_REJECTED)
                                <x-ui.button variant="ghost" size="icon" href="{{ route('activities.indicators', $activity->id) }}"
                                    wire:navigate class="text-indigo-600 !rounded-lg hover:bg-indigo-50" title="KPIs">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                                        stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="m12 14 4-4 4 4" />
                                        <path d="M3 3v18h18" />
                                        <path d="m3 17 4-4 4 4" />
                                    </svg>
                                </x-ui.button>
                                <x-ui.button variant="ghost" size="icon" href="{{ route('activities.budget', $activity->id) }}"
                                    wire:navigate class="text-emerald-600 !rounded-lg hover:bg-emerald-50" title="Finance">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                                        stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M12 2v20" />
                                        <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
                                    </svg>
                                </x-ui.button>
                                @if(auth()->user()->isPlanner())
                                    <x-ui.button variant="black" size="xs" wire:click="initiateSingleSubmission({{ $activity->id }})"
                                        class="!rounded-lg">
                                        {{ __('Submit') }}
                                    </x-ui.button>
                                @endif
                                <x-ui.button variant="ghost" size="icon" wire:click="delete({{ $activity->id }})"
                                    wire:confirm="{{ __('Are you sure you want to delete this activity?') }}"
                                    class="text-rose-500 !rounded-lg hover:bg-rose-50" title="Purge">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                                        stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M3 6h18" />
                                        <path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6" />
                                        <path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2" />
                                    </svg>
                                </x-ui.button>
                            @else
                                {{-- Show disabled view buttons if already submitted to prevent edits --}}
                                <x-ui.button variant="ghost" size="icon" class="text-slate-300 cursor-not-allowed !rounded-lg"
                                    title="{{ __('Locked for Review') }}">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                                        stroke="currentColor" stroke-width="3" stroke-linecap="round"
                                        stroke-linejoin="round opacity-40">
                                        <rect width="18" height="11" x="3" y="11" rx="2" ry="2" />
                                        <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                                    </svg>
                                </x-ui.button>
                            @endif

                            @if($activity->status === App\Models\Activity::STATUS_SUBMITTED)
                                @if(auth()->user()->isDirector())
                                    <x-ui.button variant="black" size="xs" wire:click="initiateSingleSubmission({{ $activity->id }})"
                                        class="!rounded-lg">
                                        {{ __('Review') }}
                                    </x-ui.button>
                                @endif

                                @if(auth()->user()->isMinister() || auth()->user()->isAdministrator())
                                    <x-ui.button variant="success" size="xs" wire:click="approve({{ $activity->id }})"
                                        class="!rounded-lg !bg-green-600 hover:!bg-green-700 !text-white">
                                        {{ __('Approve') }}
                                    </x-ui.button>
                                    <x-ui.button variant="danger" size="xs" wire:click="reject({{ $activity->id }})"
                                        class="!rounded-lg">
                                        {{ __('Reject') }}
                                    </x-ui.button>
                                @endif
                            @endif

                            @if($activity->status === App\Models\Activity::STATUS_REVIEWED && (auth()->user()->isMinister() || auth()->user()->isAdministrator()))
                                <x-ui.button variant="success" size="xs" wire:click="approve({{ $activity->id }})"
                                    class="!rounded-lg !bg-green-600 hover:!bg-green-700 !text-white">
                                    {{ __('Approve') }}
                                </x-ui.button>
                                <x-ui.button variant="danger" size="xs" wire:click="reject({{ $activity->id }})"
                                    class="!rounded-lg">
                                    {{ __('Reject') }}
                                </x-ui.button>
                            @endif

                            @if($activity->status === App\Models\Activity::STATUS_APPROVED && (auth()->user()->isMinister() || auth()->user()->isAdministrator()))
                                <x-ui.button variant="ghost" size="xs" wire:click="reopen({{ $activity->id }})"
                                    wire:confirm="{{ __('Are you sure you want to reopen this activity? This will return it to draft status and allow editing.') }}"
                                    class="!rounded-lg border border-slate-200 hover:bg-slate-50 text-slate-600">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none"
                                        stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"
                                        class="mr-1">
                                        <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8" />
                                        <path d="M3 3v5h5" />
                                    </svg>
                                    {{ __('Reopen') }}
                                </x-ui.button>
                            @endif
                        @endif
                    </div>
                </td>
            </tr>
        @endforeach
    </x-ui.table>

    {{-- Pagination footer --}}
    @if($activities->hasPages() || $activities->total() > 10)
        <div class="flex items-center justify-between gap-4 px-6 py-4 border-t border-zinc-100">

            {{-- Per-page selector --}}
            <div class="flex items-center gap-2 text-sm text-zinc-500">
                <span class="hidden sm:inline">{{ __('Show') }}</span>
                <select wire:model.live="perPage"
                    class="flux-input h-8 py-0 text-sm w-auto pl-2 pr-7 min-w-0">
                    <option value="10">10</option>
                    <option value="15">15</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
                <span class="hidden sm:inline">{{ __('per page') }}</span>
            </div>

            {{-- Pagination links --}}
            {{ $activities->links() }}
        </div>
    @endif
</x-ui.card>