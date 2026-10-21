<x-ui.card padding="p-0" class="border-amber-100">
    <x-ui.table :headers="[__('Ref / Program'), __('Simulation Analysis'), __('Priority'), __('Metrics'), __('Simulated') . ' ($)', __('Simulation Status')]">
        <x-slot name="actions"></x-slot>
        @foreach($activities as $activity)
            <tr class="hover:bg-amber-50/30 transition-all duration-300 group">
                <td class="px-8 py-6">
                    <div class="font-semibold text-amber-900 tracking-tight">{{ $activity->program->oge_code }}</div>
                    <div class="text-xs text-amber-500 mt-1 font-bold leading-none">
                        {{ $activity->program->name }}
                    </div>
                </td>
                <td class="px-8 py-6">
                    <div class="font-bold text-amber-800 leading-tight group-hover:text-amber-700 transition-colors">
                        {{ $activity->description }}
                    </div>
                    @if($activity->directorate)
                        <div class="text-xs text-amber-400 mt-1 font-medium italic opacity-60">
                            {{ $activity->directorate->name }}
                        </div>
                    @endif
                </td>
                <td class="px-8 py-6 whitespace-nowrap">
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
                <td class="px-8 py-6 whitespace-nowrap">
                    <div class="flex items-center gap-2">
                        <x-ui.badge variant="amber" class="!bg-amber-50/50 !text-amber-600"
                            title="Indicators">{{ $activity->indicators->count() }} I</x-ui.badge>
                        <x-ui.badge variant="amber" class="!bg-amber-100/50 !text-amber-700"
                            title="Budget Items">{{ $activity->lineItems->count() }} B</x-ui.badge>
                    </div>
                </td>
                <td class="px-8 py-6 whitespace-nowrap text-right">
                    <div class="font-semibold text-amber-900 text-sm">
                        $ {{ number_format($activity->lineItems->sum('estimated_amount'), 2) }}
                    </div>
                </td>
                <td class="px-8 py-6 whitespace-nowrap">
                    @php
                        $statusVariant = match ($activity->status) {
                            'Aprovada' => 'green',
                            'Submitted' => 'amber',
                            'Reviewed' => 'amber',
                            'Rejected' => 'red',
                            'Cortada' => 'gray',
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
                    <div class="flex flex-col">
                        <x-ui.badge :variant="$statusVariant" :pulse="$activity->status === 'Submitted'">
                            {{ $statusLabel }}
                        </x-ui.badge>

                        @if($activity->status === App\Models\Activity::STATUS_APPROVED && $activity->approved_at)
                            <div class="text-xs text-emerald-600 font-bold mt-1 leading-none ">
                                {{ __('Approved') }}: {{ \Carbon\Carbon::parse($activity->approved_at)->format('d/m/Y') }}
                            </div>
                            @if($activity->approvedBy)
                                <div class="text-xs text-amber-600/60 font-medium italic mt-0.5">
                                    {{ __('by') }} {{ $activity->approvedBy->name }}
                                </div>
                            @endif
                        @elseif($activity->status === App\Models\Activity::STATUS_SUBMITTED && $activity->submitted_at)
                            <div class="text-xs text-amber-600 font-bold mt-1 leading-none ">
                                {{ __('Submitted') }}: {{ \Carbon\Carbon::parse($activity->submitted_at)->format('d/m/Y') }}
                            </div>
                        @endif
                    </div>
                </td>
                <td class="px-8 py-6 whitespace-nowrap text-right">
                    <div
                        class="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-all group-hover:translate-x-0 translate-x-4">
                        <x-ui.button variant="ghost" size="icon"
                            href="{{ route('testing.activities.show', $activity->id) }}" wire:navigate
                            class="!rounded-lg hover:bg-white hover:shadow-sm" title="View Portfolio Simulation">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0z" />
                                <path
                                    d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                            </svg>
                        </x-ui.button>

                        @if(!$this->isYearLocked($activity->fiscal_year))
                            @if($activity->status === 'Planeada' || $activity->status === 'Rejected')
                                <x-ui.button variant="ghost" size="icon"
                                    href="{{ route('testing.activities.indicators', $activity->id) }}" wire:navigate
                                    class="text-amber-600 !rounded-lg hover:bg-amber-50" title="Sim. KPIs">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                                        stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="m12 14 4-4 4 4" />
                                        <path d="M3 3v18h18" />
                                        <path d="m3 17 4-4 4 4" />
                                    </svg>
                                </x-ui.button>
                                <x-ui.button variant="ghost" size="icon"
                                    href="{{ route('testing.activities.budget', $activity->id) }}" wire:navigate
                                    class="text-amber-500 !rounded-lg hover:bg-amber-50" title="Sim. Finance">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                                        stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M12 2v20" />
                                        <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
                                    </svg>
                                </x-ui.button>
                                <x-ui.button variant="ghost" size="icon" wire:click="delete({{ $activity->id }})"
                                    wire:confirm="{{ __('Are you sure you want to delete this simulation activity?') }}"
                                    class="text-rose-500 !rounded-lg hover:bg-rose-50" title="Purge Simulation">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                                        stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"
                                        class="text-rose-400">
                                        <path d="M3 6h18" />
                                        <path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6" />
                                        <path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2" />
                                    </svg>
                                </x-ui.button>
                            @endif

                            @if(auth()->user()->hasAnyRole(['Admin', 'Director']) && $activity->status === 'Submitted')
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
                    </div>
                </td>
            </tr>
        @endforeach
    </x-ui.table>
</x-ui.card>