@php
    $a = $r['activity'];
    $inds = $r['indicators'] ?: [null];
    $n = count($inds);
@endphp
@foreach($inds as $k => $i)
    <tr>
        @if($loop->first)
            <td rowspan="{{ $n }}"><b>{{ $a->code }}</b> {{ $a->description }}</td>
        @endif
        @if($i)
            <td>
                {{ $loop->iteration }}). {{ $i['name'] }}
                @if($i['planned'])<div class="small muted">{{ __('Planned') }}: {{ $i['planned'] }}</div>@endif
                @if($i['in_quarter'] !== null || $i['standing'] > 0)
                    <div class="small muted">{{ __('Achieved in Q:q', ['q' => $quarter]) }}: {{ $i['in_quarter'] !== null ? number_format($i['in_quarter'], 2) : '—' }} {{ $i['unit'] }} · {{ __('so far') }}: {{ number_format($i['standing'], 2) }}</div>
                @endif
            </td>
            <td>{{ $i['baseline'] }}</td>
            <td>{{ $i['target'] }}</td>
        @else
            <td></td><td></td><td></td>
        @endif
        @if($loop->first)
            <td rowspan="{{ $n }}">{{ $r['means'] }}</td>
            <td rowspan="{{ $n }}">{{ $a->directorate?->name }}</td>
            <td rowspan="{{ $n }}">@foreach($r['funding'] as $f){{ $f }}@if(! $loop->last)<br>@endif @endforeach</td>
            <td rowspan="{{ $n }}" class="r">$ {{ number_format($r['approved'], 2) }}</td>
            <td rowspan="{{ $n }}" class="r">$ {{ number_format($r['paid'], 2) }}</td>
            <td rowspan="{{ $n }}" class="r">{{ $r['pct'] === null ? '0.00%' : number_format($r['pct'], 2).'%' }}</td>
            <td rowspan="{{ $n }}">
                {{ $r['results'] }}
                @if($r['rating'])<div class="small muted">{{ __('Rating') }}: {{ __($r['rating']) }}</div>@endif
            </td>
            <td rowspan="{{ $n }}">
                {{ $r['constraints'] }}
                @if($r['reasons_not_met'])<div class="small"><b>{{ __('Why targets were not met') }}:</b> {{ $r['reasons_not_met'] }}</div>@endif
                @if($r['corrective_measures'])<div class="small"><b>{{ __('Corrective measures') }}:</b> {{ $r['corrective_measures'] }}</div>@endif
            </td>
        @endif
    </tr>
@endforeach
