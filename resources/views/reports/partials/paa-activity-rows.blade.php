{{-- Level 3 of the annual report: the activities behind one programme and economic category.
     Used inline (full report) and as the fragment fetched when a category row is expanded. --}}
@foreach($rows as $activityRow)
    @php
        $activity = $activityRow['activity'];
        $actDiff = $activityRow['planned'] - $activityRow['approved'];
    @endphp
    <tr data-parent="{{ $classRowId }}" class="tree-row hidden hover:bg-zinc-50 print:table-row">
        <td class="py-3 pr-5" style="padding-left: 5.25rem;">
            <div class="text-sm font-medium text-zinc-800">{{ $activity->description }}</div>
            <div class="mt-0.5 text-xs text-zinc-500">
                <span class="font-medium text-indigo-700">{{ $activity->directorate->code ?? 'N/A' }}</span>
                · {{ __('Priority') }}: {{ __($activity->priority) }}
            </div>
        </td>
        <td class="px-5 py-3 text-xs text-zinc-600">
            <ul class="space-y-1 list-none">
                @foreach($activityRow['indicators'] as $indicator)
                    <li>{{ $indicator->name }}: <span class="font-medium text-zinc-800">{{ number_format($indicator->annual_target, 0) }} {{ $indicator->unit }}</span></li>
                @endforeach
            </ul>
        </td>
        <td class="px-5 py-3 num text-sm text-zinc-600">$ {{ number_format($activityRow['planned'], 2) }}</td>
        <td class="px-5 py-3 num text-sm font-medium text-emerald-700">$ {{ number_format($activityRow['approved'], 2) }}</td>
        <td class="px-5 py-3 num text-sm {{ $actDiff > 0 ? 'text-rose-700' : 'text-zinc-500' }}">$ {{ number_format($actDiff, 2) }}</td>
    </tr>
@endforeach
