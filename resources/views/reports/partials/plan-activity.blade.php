@php
    $a = $r['activity'];
    $inds = $a->indicators->values()->all() ?: [null];
    $n = count($inds);
    $quarters = $a->execution_quarters ?: [$a->quarter];
    $funding = $a->lineItems->map(fn ($l) => $l->fundingSource?->name)->filter()->unique()->map(fn ($f) => __($f))->all();
    $means = $a->indicators->pluck('means_of_verification')->filter()->unique()->implode('; ');
@endphp
@foreach($inds as $i)
    <tr>
        @if($loop->first)
            <td rowspan="{{ $n }}"><b>{{ $a->code }}</b> {{ $a->description }}</td>
        @endif
        @if($i)
            <td>{{ $loop->iteration }}). {{ $i->name }}@if($i->actions->isNotEmpty())<div style="font-style:italic">{{ $i->implementationText() }}@if($i->hasMonthPlan()) ({{ collect($i->plannedMonths())->map(fn ($m) => \Carbon\Carbon::create(2000, $m, 1)->translatedFormat('M'))->implode(', ') }})@endif</div>@endif</td>
            <td>{{ $i->baseline }}</td>
            <td>{{ $i->target_text ?: rtrim(rtrim(number_format((float) $i->annual_target, 2), '0'), '.').' '.$i->unit }}</td>
        @else
            <td></td><td></td><td></td>
        @endif
        @if($loop->first)
            <td rowspan="{{ $n }}">{{ $means }}</td>
            <td rowspan="{{ $n }}">{{ $a->directorate?->name }}</td>
            <td rowspan="{{ $n }}">@foreach($funding as $f){{ $f }}@if(! $loop->last)<br>@endif @endforeach</td>
            @foreach([1, 2, 3, 4] as $q)<td rowspan="{{ $n }}" @class(['q' => in_array($q, $quarters, true)])></td>@endforeach
            @foreach($classes as $c)<td rowspan="{{ $n }}" class="r">{{ ($r['by_class'][$c->id] ?? 0) > 0 ? $num($r['by_class'][$c->id]) : '' }}</td>@endforeach
            <td rowspan="{{ $n }}" class="r">{{ $num($r['total']) }}</td>
        @endif
    </tr>
@endforeach
