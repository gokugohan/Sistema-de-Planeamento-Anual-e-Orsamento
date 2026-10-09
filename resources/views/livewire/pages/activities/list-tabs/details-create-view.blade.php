<x-ui.card padding="p-0">
    <x-ui.table :headers="[__('Ref / Program'), __('Activity Analysis'), __('Priority'), __('Metrics'), __('Budgeting') . ' ($)', __('Legal Status')]">
        <x-slot name="actions"></x-slot>
        @foreach($activities as $activity)
            <tr class="hover:bg-zinc-50/50 transition-all duration-300 group">
                <td class="px-8 py-6">
                    <div class="font-semibold text-zinc-900 tracking-tight">{{ $activity->program->oge_code }}</div>
                    <div class="text-xs text-zinc-400 mt-1 font-bold leading-none">
                        {{ $activity->program->name }}
                    </div>
                </td>
                <td class="px-8 py-6">
                    <div class="font-bold text-zinc-700 leading-tight group-hover:text-indigo-600 transition-colors">
                        {{ $activity->description }}
                    </div>
                    @if($activity->directorate)
                        <div class="text-xs text-zinc-400 mt-1 font-medium italic opacity-60">
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
                        <x-ui.badge variant="blue" class="!bg-blue-50/50"
                            title="Indicators">{{ $activity->indicators->count() }} I</x-ui.badge>
                        <x-ui.badge variant="indigo" class="!bg-indigo-50/50"
                            title="Budget Items">{{ $activity->lineItems->count() }} B</x-ui.badge>
                    </div>
                </td>
                <td class="px-8 py-6 whitespace-nowrap text-right">
                    <div class="font-semibold text-zinc-900 text-sm">
                        $ {{ number_format($activity->lineItems->sum('estimated_amount'), 2) }}
                    </div>
                </td>
                <td class="px-8 py-6 whitespace-nowrap">
                    @php
                        $statusVariant = match ($activity->status) {
                            'Aprovada' => 'green',
                            'Submitted' => 'indigo',
                            'Reviewed' => 'amber',
                            'Rejected' => 'red',
                            'Cortada' => 'gray',
                            default => 'blue',
                        };
                        $statusLabel = match ($activity->status) {
                            'Aprovada' => __('Approved'),
                            'Submitted' => __('Submitted'),
                            'Reviewed' => __('Reviewed'),
                            'Rejected' => __('Rejected'),
                            'Cortada' => __('Cut'),
                            'Planeada' => __('Planned'),
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
                                <div class="text-xs text-zinc-400 font-medium italic mt-0.5">
                                    {{ __('by') }} {{ $activity->approvedBy->name }}
                                </div>
                            @endif
                        @elseif($activity->status === App\Models\Activity::STATUS_SUBMITTED && $activity->submitted_at)
                            <div class="text-xs text-indigo-600 font-bold mt-1 leading-none ">
                                {{ __('Submitted') }}: {{ \Carbon\Carbon::parse($activity->submitted_at)->format('d/m/Y') }}
                            </div>
                        @endif
                    </div>
                </td>
                <td class="px-8 py-6 whitespace-nowrap text-right">
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

                        @if($activity->status === 'Planeada' || $activity->status === 'Rejected')
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
                        @endif
                    </div>
                </td>
            </tr>
        @endforeach
    </x-ui.table>
</x-ui.card>