@php
    $pdf = $pdf ?? false;
    $money = fn ($v) => '$ ' . number_format((float) $v, 2);
    $pct = fn ($v) => $v === null ? '0.00%' : number_format($v, 2) . '%';
    $kind = $report['type'] === 'quarter' ? __('Quarter') : __('Month');
    $periodText = $report['type'] === 'quarter' ? $report['period'] . 'º ' . __('Quarter') . ' ' . $report['year'] : $report['label'] . ' ' . $report['year'];
    $title = ($report['type'] === 'quarter' ? __('Quarterly performance report') : __('Monthly performance report')) . ' — ' . $periodText;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <style>
        @page { size: A4 landscape; margin: 8mm 8mm 12mm 8mm; }
        * { box-sizing: border-box; }
        body { font-family: {!! $pdf ? "'DejaVu Sans'" : "'Inter'" !!}, Arial, sans-serif; font-size: 7pt; color: #000; margin: 0; background: {{ $pdf ? '#fff' : '#F1F5F9' }}; }
        .head { text-align: center; margin-bottom: 6px; }
        .head .state { font-size: 7pt; letter-spacing: 1.5px; text-transform: uppercase; }
        .head .org { font-size: 11pt; font-weight: bold; text-transform: uppercase; margin-top: 2px; }
        .head .title { font-size: 9pt; font-weight: bold; margin-top: 4px; }
        table.rep { width: 100%; border-collapse: collapse; border: 0.75pt solid #000; table-layout: fixed; }
        table.rep td { border: 0.3pt solid #000; padding: 3px 4px; font-size: 6pt; vertical-align: top; word-wrap: break-word; }
        .c { text-align: center; } .r { text-align: right; white-space: nowrap; }
        .hd td, td.hd { background: #bdd6ee; font-weight: bold; }
        .sec td { background: #eeece1; font-weight: bold; }
        .ods td { background: #8eaadb; padding: 1px 4px; }
        .prog td { background: #ffcccc; }
        .prog-i td { background: #bdd6ee; font-style: italic; }
        .sub td { background: #ffff00; }
        .sub-i td { background: #ffffd9; }
        .tot td { background: #ddebf7; font-weight: bold; }
        .muted { color: #475569; }
        .small { font-size: 5.5pt; }
        .sign { width: 100%; margin-top: 22px; page-break-inside: avoid; }
        .sign td { text-align: center; width: 33%; padding-top: 30px; font-size: 7pt; }
        .sign .line { border-top: 0.5pt solid #000; margin: 0 20px; padding-top: 2px; }
        .toolbar { position: sticky; top: 0; background: #1C2434; color: #fff; padding: 10px 16px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; font-size: 13px; z-index: 10; }
        .toolbar a, .toolbar button { background: #2152C4; color: #fff; border: 0; border-radius: 4px; padding: 7px 12px; text-decoration: none; cursor: pointer; font-size: 13px; }
        .toolbar a.secondary { background: transparent; border: 1px solid #475569; }
        .toolbar select { padding: 6px; border-radius: 4px; }
        .sheet { max-width: 297mm; margin: 12px auto; background: #fff; padding: 8mm; box-shadow: 0 1px 3px rgba(0,0,0,.12); overflow-x: auto; }
        .sheet table.rep { min-width: 1000px; }
        .fine { margin-top: 6px; font-size: 6pt; color: #475569; }
        @media print {
            .toolbar { display: none; } body { background: #fff; }
            .sheet { margin: 0; padding: 0; box-shadow: none; max-width: none; overflow: visible; }
            .sheet table.rep { min-width: 0; }
            table.rep td { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            thead { display: table-header-group; } tr { page-break-inside: avoid; }
        }
    </style>
</head>
<body>
@unless($pdf)
<div class="toolbar">
    <a class="secondary" href="{{ route('monitoring.report') }}">← {{ __('Report execution') }}</a>
    <form method="GET" action="{{ route('reports.performance') }}" style="margin:0;display:flex;gap:8px">
        <select name="year" onchange="this.form.submit()" aria-label="{{ __('Fiscal year') }}">
            @foreach(range((int) date('Y') + 1, 2024) as $y)<option value="{{ $y }}" @selected($y === $report['year'])>{{ $y }}</option>@endforeach
        </select>
        <select name="type" onchange="this.form.submit()" aria-label="{{ __('Reports') }}">
            <option value="month" @selected($report['type'] === 'month')>{{ __('Monthly') }}</option>
            <option value="quarter" @selected($report['type'] === 'quarter')>{{ __('Quarterly') }}</option>
        </select>
        <select name="period" onchange="this.form.submit()" aria-label="{{ __('Period') }}">
            @foreach(range(1, $report['type'] === 'quarter' ? 4 : 12) as $p)
                <option value="{{ $p }}" @selected($p === $report['period'])>{{ $report['type'] === 'quarter' ? 'Q'.$p : \Carbon\Carbon::create($report['year'], $p, 1)->translatedFormat('F') }}</option>
            @endforeach
        </select>
    </form>
    <span style="margin-left:auto"></span>
    <button type="button" onclick="window.print()">{{ __('Print') }}</button>
    <a href="{{ route('reports.performance.pdf', ['year' => $report['year'], 'type' => $report['type'], 'period' => $report['period']]) }}">{{ __('Download PDF') }}</a>
</div>
<div class="sheet">
@endunless

<div class="head">
    <div class="state">República Democrática de Timor-Leste</div>
    <div class="org">{{ $report['organization'] }}</div>
    <div class="title">{{ $title }}</div>
</div>

<table class="rep">
    <colgroup>
        <col style="width:12%"><col style="width:13%"><col style="width:8%"><col style="width:8%"><col style="width:7%"><col style="width:7%">
        <col style="width:3%"><col style="width:8%"><col style="width:8%"><col style="width:5%"><col style="width:11%"><col style="width:10%">
    </colgroup>
    <thead>
        <tr class="sec"><td>6.</td><td colspan="11">{{ __('Budget') }}</td></tr>
        <tr class="hd">
            <td rowspan="2">{{ __('SDG / PED / Programme / Subprogramme / Activity') }}</td>
            <td rowspan="2">{{ __('Performance indicators') }}</td>
            <td rowspan="2">{{ __('Baseline') }}</td>
            <td rowspan="2">{{ __('Target') }}</td>
            <td rowspan="2">{{ __('Means of verification') }}</td>
            <td rowspan="2">{{ __('Unit') }}</td>
            <td rowspan="2">{{ __('FF') }}</td>
            <td rowspan="2">{{ __('Total') }}</td>
            <td colspan="2" class="c">{{ __('Execution :p', ['p' => $periodText]) }}</td>
            <td rowspan="2">{{ __('Results achieved') }} — {{ $periodText }}</td>
            <td rowspan="2">{{ __('Implementation constraints') }}</td>
        </tr>
        <tr class="hd"><td class="c">$</td><td class="c">%</td></tr>
    </thead>
    <tbody>
    @forelse($report['tree'] as $p)
        @foreach($p['program']->alignments as $al)
            <tr class="ods"><td colspan="12">{{ $al->reference }} {{ $al->description }}</td></tr>
        @endforeach
        <tr class="prog">
            <td colspan="7">{{ __('Programme') }} <b>{{ $p['program']->oge_code }}</b>: {{ $p['program']->name }}</td>
            <td class="r">{{ $money($p['approved']) }}</td><td class="r">{{ $money($p['paid']) }}</td><td class="r">{{ $pct($p['pct']) }}</td><td></td><td></td>
        </tr>
        @foreach($p['program']->resultIndicators as $ri)
            <tr class="prog-i"><td>{{ $loop->first ? ($p['program']->objective ?? '') : '' }}</td><td>{{ $ri->name }}</td><td>{{ $ri->baseline }}</td><td>{{ $ri->target }}</td><td>{{ $ri->means_of_verification }}</td><td colspan="7"></td></tr>
        @endforeach

        @foreach($p['subprograms'] as $s)
            <tr class="sub">
                <td colspan="7">{{ __('Subprogramme') }} <b>{{ $s['subprogram']->code }}</b>: {{ $s['subprogram']->name }}</td>
                <td class="r">{{ $money($s['approved']) }}</td><td class="r">{{ $money($s['paid']) }}</td><td class="r">{{ $pct($s['pct']) }}</td><td></td><td></td>
            </tr>
            @foreach($s['subprogram']->resultIndicators as $ri)
                <tr class="sub-i"><td>{{ $loop->first ? ($s['subprogram']->objective ?? '') : '' }}</td><td>{{ $ri->name }}</td><td>{{ $ri->baseline }}</td><td>{{ $ri->target }}</td><td>{{ $ri->means_of_verification }}</td><td colspan="7"></td></tr>
            @endforeach
            @foreach($s['rows'] as $r)
                @include('reports.partials.performance-activity', ['r' => $r, 'quarter' => $report['quarter']])
            @endforeach
        @endforeach

        @foreach($p['loose'] as $r)
            @include('reports.partials.performance-activity', ['r' => $r, 'quarter' => $report['quarter']])
        @endforeach
    @empty
        <tr><td colspan="12" class="c muted">{{ __('No approved activities with a distributed budget to report on.') }}</td></tr>
    @endforelse
        <tr class="tot">
            <td colspan="7" class="c">{{ __('TOTAL EXECUTION') }}</td>
            <td class="r">{{ $money($report['totals']['approved']) }}</td><td class="r">{{ $money($report['totals']['paid']) }}</td><td class="r">{{ $pct($report['totals']['pct']) }}</td><td></td><td></td>
        </tr>
    </tbody>
</table>

<p class="fine">{{ $report['reference'] }} · {{ __('Verified figures and reports only') }} · {{ __('Generated') }} {{ $report['generated_at']->format('Y-m-d H:i') }} · {{ $report['generated_by'] }}</p>

<table class="sign">
    <tr>
        <td><div class="line">{{ __('Prepared by') }}</div></td>
        <td><div class="line">{{ __('Verified by') }}</div></td>
        <td><div class="line">{{ __('Approved by') }}</div></td>
    </tr>
</table>

@unless($pdf)
</div>
@endunless
</body>
</html>
