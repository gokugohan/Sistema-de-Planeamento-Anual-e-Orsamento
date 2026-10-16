{{-- The annual plan in the ministry's template: programme and subprogramme bands, each activity with its
     indicators, the quarters it runs in and the budget by economic category. --}}
@php
    $nCat = $classes->count();
    $cols = 12 + $nCat; // activity, indicator, baseline, target, means, unit, funding, 4 quarters, categories, total
    $num = fn ($v) => number_format((float) $v, 2);
    $css = '
        table.plan { width: 100%; border-collapse: collapse; border: 0.75pt solid #000; table-layout: fixed; }
        table.plan td { border: 0.3pt solid #000; padding: 2px 3px; font-size: 6pt; vertical-align: top; word-wrap: break-word; }
    ';
@endphp
<style>
    {!! $css !!}
    table.plan .hd td { background: #bdd6ee; font-weight: bold; }
    table.plan .sec td { background: #eeece1; font-weight: bold; }
    table.plan .ods td { background: #8eaadb; padding: 1px 3px; }
    table.plan .prog td { background: #ffcccc; }
    table.plan .prog-i td { background: #bdd6ee; font-style: italic; }
    table.plan .sub td { background: #ffff00; }
    table.plan .sub-i td { background: #ffffd9; }
    table.plan .tot td { background: #ddebf7; font-weight: bold; }
    table.plan td.q { background: #439aff; }
    table.plan td.r { text-align: right; white-space: nowrap; }
    table.plan td.c { text-align: center; }
    table.plan tr { page-break-inside: avoid; }
    table.plan tr.w td { padding: 0; border: 0; height: 0; font-size: 1pt; line-height: 0; }
    table.plan thead { display: table-header-group; }
    @media print { table.plan td { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
</style>
<table class="plan">
    <thead>
        <tr class="w">
            @foreach([12, 12, 6, 6, 6, 6, 5] as $w)<td style="width:{{ $w }}%"></td>@endforeach
            @foreach([2.4, 2.4, 2.4, 2.4] as $w)<td style="width:{{ $w }}%"></td>@endforeach
            @foreach($classes as $c)<td style="width:{{ round(26 / max($nCat, 1), 2) }}%"></td>@endforeach
            <td style="width:8%"></td>
        </tr>
        <tr class="sec"><td>6.</td><td colspan="{{ $cols - 1 }}">{{ __('Budget') }}</td></tr>
        <tr class="hd">
            <td rowspan="2">{{ __('SDG / PED / Programme / Subprogramme / Activity') }}</td>
            <td rowspan="2">{{ __('Performance indicators') }}</td>
            <td rowspan="2">{{ __('Baseline') }}</td>
            <td rowspan="2">{{ __('Target') }}</td>
            <td rowspan="2">{{ __('Means of verification') }}</td>
            <td rowspan="2">{{ __('Unit') }}</td>
            <td rowspan="2">{{ __('FF') }}</td>
            <td colspan="4" class="c">{{ __('Implementation schedule') }}</td>
            <td colspan="{{ $nCat }}" class="c">{{ __('Budget category') }}</td>
            <td rowspan="2">{{ __('Total') }}</td>
        </tr>
        <tr class="hd">
            @foreach([1, 2, 3, 4] as $q)<td class="c">{{ $q }}T</td>@endforeach
            @foreach($classes as $c)<td class="c">{{ $c->code }}</td>@endforeach
        </tr>
    </thead>
    <tbody>
    @foreach($tree as $p)
        @foreach($p['program']->alignments as $al)
            <tr class="ods"><td colspan="{{ $cols }}">{{ $al->reference }} {{ $al->description }}</td></tr>
        @endforeach
        <tr class="prog">
            <td colspan="{{ 7 + 4 }}">{{ __('Programme') }} <b>{{ $p['program']->oge_code }}</b>: {{ $p['program']->name }}</td>
            @foreach($classes as $c)<td class="r">{{ $num($p['by_class'][$c->id] ?? 0) }}</td>@endforeach
            <td class="r">{{ $num($p['total']) }}</td>
        </tr>
        @foreach($p['program']->resultIndicators as $ri)
            <tr class="prog-i"><td>{{ $loop->first ? ($p['program']->objective ?? '') : '' }}</td><td>{{ $ri->name }}</td><td>{{ $ri->baseline }}</td><td>{{ $ri->target }}</td><td>{{ $ri->means_of_verification }}</td><td colspan="{{ $cols - 5 }}"></td></tr>
        @endforeach

        @foreach($p['subprograms'] as $s)
            <tr class="sub">
                <td colspan="{{ 7 + 4 }}">{{ __('Subprogramme') }} <b>{{ $s['subprogram']->code }}</b>: {{ $s['subprogram']->name }}</td>
                @foreach($classes as $c)<td class="r">{{ $num($s['by_class'][$c->id] ?? 0) }}</td>@endforeach
                <td class="r">{{ $num($s['total']) }}</td>
            </tr>
            @foreach($s['subprogram']->resultIndicators as $ri)
                <tr class="sub-i"><td>{{ $loop->first ? ($s['subprogram']->objective ?? '') : '' }}</td><td>{{ $ri->name }}</td><td>{{ $ri->baseline }}</td><td>{{ $ri->target }}</td><td>{{ $ri->means_of_verification }}</td><td colspan="{{ $cols - 5 }}"></td></tr>
            @endforeach
            @foreach($s['rows'] as $r)
                @include('reports.partials.plan-activity', ['r' => $r, 'classes' => $classes, 'num' => $num])
            @endforeach
        @endforeach

        @foreach($p['loose'] as $r)
            @include('reports.partials.plan-activity', ['r' => $r, 'classes' => $classes, 'num' => $num])
        @endforeach
    @endforeach
        <tr class="tot">
            <td colspan="{{ 7 + 4 }}" class="c">{{ __('TOTAL') }}</td>
            @foreach($classes as $c)<td class="r">{{ $num($totals['by_class'][$c->id] ?? 0) }}</td>@endforeach
            <td class="r">{{ $num($totals['total']) }}</td>
        </tr>
    </tbody>
</table>
