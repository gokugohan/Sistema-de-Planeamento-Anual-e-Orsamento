{{-- Level 3 of the annual report: the activities behind one programme and economic category.
     Used inline (full report) and as the fragment fetched when a category row is expanded. --}}
@foreach($rows as $activityRow)
    @php
        $activity = $activityRow['activity'];
        $actPlanned = $activityRow['planned'];
        $actApproved = $activityRow['approved'];
        $actDiff = $actPlanned - $actApproved;
    @endphp
    <tr data-parent="{{ $classRowId }}"
        class="tree-row hover:bg-zinc-50/30 transition-colors hidden print:table-row border-l-4 border-l-transparent">
        <td class="px-8 py-4 border-none" style="padding-left: 7.5rem;">
            <div class="font-bold text-zinc-800 leading-tight text-xs">{{ $activity->description }}</div>
            <div class="text-xs font-semibold text-zinc-400 mt-1">
                <span class="text-indigo-500">{{ $activity->directorate->code ?? 'N/A' }}</span>
                • {{ __('Priority') }}: {{ __($activity->priority) }}
            </div>
        </td>
        <td class="px-8 py-4 text-xs text-zinc-500 border-none">
            <ul class="space-y-1.5 list-none">
                @foreach($activityRow['indicators'] as $indicator)
                    <li>{{ $indicator->name }}: <b class="text-zinc-700">{{ number_format($indicator->annual_target, 0) }} {{ $indicator->unit }}</b></li>
                @endforeach
            </ul>
        </td>
        <td class="px-8 py-4 text-right font-medium text-zinc-500 border-none">$ {{ number_format($actPlanned, 2) }}</td>
        <td class="px-8 py-4 text-right font-bold text-emerald-600 border-none">$ {{ number_format($actApproved, 2) }}</td>
        <td class="px-8 py-4 text-right font-bold border-none {{ $actDiff > 0 ? 'text-rose-600' : 'text-zinc-400' }}">$ {{ number_format($actDiff, 2) }}</td>
    </tr>
@endforeach
