@php
    $a = $r['activity'];
    // one row per action; an indicator without actions still takes one row; budget not tied to an indicator is a row of its own
    $blocks = collect($r['indicators'])->map(fn ($x) => ['rows' => max(1, $x['indicator']->actions->count())] + $x);
    $total = $blocks->sum('rows') + ($r['whole'] > 0 ? 1 : 0);
    $total = max($total, 1);
    $first = true;
    $cells = function (array $on, array $light) {
        $out = '';
        for ($m = 1; $m <= 12; $m++) {
            $out .= '<td class="'.(in_array($m, $on, true) ? 'm' : (in_array($m, $light, true) ? 'ml' : '')).'"></td>';
        }

        return $out;
    };
@endphp
@foreach($blocks->values() as $bi => $b)
    @php $ind = $b['indicator']; $acts = $ind->actions; $light = $ind->plannedMonths(); @endphp
    @for($k = 0; $k < $b['rows']; $k++)
        @php $act = $acts[$k] ?? null; @endphp
        <tr>
            @if($first)
                @php $first = false; @endphp
                <td rowspan="{{ $total }}"><b>{{ $a->code }}</b> {{ $a->description }}</td>
            @endif
            @if($k === 0)
                <td rowspan="{{ $b['rows'] }}">{{ $loop->parent->iteration }}) {{ $ind->name }}@if($ind->target_text || $ind->annual_target > 0) <span class="muted">— {{ $ind->target_text ?: rtrim(rtrim(number_format((float) $ind->annual_target, 2), '0'), '.').' '.$ind->unit }}</span>@endif</td>
            @endif
            <td>@if($act){{ $k + 1 }}. {{ $act->text }}@endif</td>
            {!! $cells($act ? $act->monthList() : [], $act && $act->monthList() ? [] : ($act ? $light : [])) !!}
            @if($k === 0)
                <td rowspan="{{ $b['rows'] }}" class="r">{{ $b['budget'] > 0 ? number_format($b['budget'], 2) : '' }}</td>
            @endif
        </tr>
    @endfor
@endforeach
@if($r['whole'] > 0 || $blocks->isEmpty())
    <tr>
        @if($first)
            <td rowspan="{{ $total }}"><b>{{ $a->code }}</b> {{ $a->description }}</td>
        @endif
        <td class="muted"><i>{{ __('Whole activity') }}</i></td>
        <td></td>
        {!! $cells([], []) !!}
        <td class="r">{{ $r['whole'] > 0 ? number_format($r['whole'], 2) : '' }}</td>
    </tr>
@endif
