@php
    $pdf = $pdf ?? false;
    $unit = $report['unit'];
    $money = fn ($v) => number_format((float) $v, 2);
    $months = collect(range(1, 12))->map(fn ($m) => \Carbon\Carbon::create($report['year'], $m, 1)->translatedFormat('M'));
    $quarterNames = ['I', 'II', 'III', 'IV'];
    $title = __('Implementation plan of activities by quarter').' — '.$report['year'];
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
        .head { margin-bottom: 6px; }
        .head .unit { font-size: 10pt; font-weight: bold; }
        .head .title { text-align: center; font-size: 9pt; font-weight: bold; margin-top: 4px; }
        table.rep { width: 100%; border-collapse: collapse; border: 0.75pt solid #000; table-layout: fixed; }
        table.rep td { border: 0.3pt solid #000; padding: 2px 3px; font-size: 6pt; vertical-align: top; word-wrap: break-word; }
        table.rep tr.w td { padding: 0; border: 0; height: 0; font-size: 1pt; line-height: 0; }
        .c { text-align: center; } .r { text-align: right; white-space: nowrap; }
        .sec td { background: #eeece1; font-weight: bold; }
        .hd td { background: #bdd6ee; font-weight: bold; }
        .prog td { background: #ffcccc; }
        .sub td { background: #ffff00; }
        .m { background: #b4c6e7; }
        .ml { background: #e3ebf7; }
        .muted { color: #475569; }
        .toolbar { position: sticky; top: 0; background: #1C2434; color: #fff; padding: 10px 16px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; font-size: 13px; z-index: 10; }
        .toolbar a, .toolbar button { background: #2152C4; color: #fff; border: 0; border-radius: 4px; padding: 7px 12px; text-decoration: none; cursor: pointer; font-size: 13px; }
        .toolbar a.secondary { background: transparent; border: 1px solid #475569; }
        .toolbar select, .toolbar label { padding: 6px; border-radius: 4px; }
        .sheet { max-width: 297mm; margin: 12px auto; background: #fff; padding: 8mm; box-shadow: 0 1px 3px rgba(0,0,0,.12); overflow-x: auto; }
        .sheet table.rep { min-width: 1050px; }
        .sign { width: 100%; margin-top: 18px; page-break-inside: avoid; }
        .sign td { text-align: center; width: 50%; font-size: 7pt; padding-top: 4px; }
        .sign .name { margin-top: 28px; font-weight: bold; text-decoration: underline; min-height: 12px; }
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
@php $stateWarning = app(\App\Services\PlanStatus::class)->reportWarning(auth()->user(), $report['year'], $unit?->id); @endphp
@unless($pdf)
<div class="toolbar">
    <a class="secondary" href="{{ route('planning.cycle') }}">← {{ __('Plan cycle') }}</a>
    <form method="GET" action="{{ route('reports.implementation-plan') }}" style="margin:0;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <select name="year" onchange="this.form.submit()" aria-label="{{ __('Fiscal year') }}">
            @foreach(range((int) date('Y') + 2, 2024) as $y)<option value="{{ $y }}" @selected($y === $report['year'])>{{ $y }}</option>@endforeach
        </select>
        <select name="unit" onchange="this.form.submit()" aria-label="{{ __('Unit') }}">
            @foreach($units as $u)<option value="{{ $u->id }}" @selected($unit && $u->id === $unit->id)>{{ $u->code }} — {{ \Illuminate\Support\Str::limit($u->name, 40) }}</option>@endforeach
        </select>
        <label><input type="checkbox" name="status" value="all" onchange="this.form.submit()" @checked(! $report['approved_only'])> {{ __('Include activities not yet approved') }}</label>
    </form>
    <span style="margin-left:auto"></span>
    <button type="button" data-warn="{{ $stateWarning ? $stateWarning.' '.__('Print anyway?') : '' }}" onclick="if (this.dataset.warn && ! confirm(this.dataset.warn)) return; window.print()">{{ __('Print') }}</button>
    <a data-warn="{{ $stateWarning ? $stateWarning.' '.__('Download anyway?') : '' }}" onclick="return ! this.dataset.warn || confirm(this.dataset.warn)" href="{{ route('reports.implementation-plan.pdf', array_filter(['year' => $report['year'], 'unit' => $unit?->id, 'status' => $report['approved_only'] ? null : 'all'])) }}">{{ __('Download PDF') }}</a>
</div>
<div class="sheet">
@endunless

@if($stateWarning)
    <div class="state-warning" style="border:1px solid #B45309;background:#FEF3C7;color:#78350F;padding:5px 8px;margin:0 0 6px;font-size:7.5pt;font-weight:bold">{{ __('Draft state') }}: {{ $stateWarning }}</div>
@endif
<div class="head">
    @if($logoUri = \App\Support\Branding::logoDataUri())<div style="text-align:center"><img src="{{ $logoUri }}" alt="" style="height:46px;margin-bottom:4px"></div>@endif
    <div class="unit">{{ $unit ? $unit->code.' : '.$unit->name : $report['organization'] }}</div>
    <div class="title">{{ $title }}</div>
</div>

<table class="rep">
    <thead>
        <tr class="w">
            <td style="width:13%"></td><td style="width:19%"></td><td style="width:22%"></td>
            @for($m = 0; $m < 12; $m++)<td style="width:2.2%"></td>@endfor
            <td style="width:9%"></td>
        </tr>
        <tr class="sec"><td>6.</td><td colspan="15">{{ __('Budget') }}</td></tr>
        <tr class="hd">
            <td rowspan="3">{{ __('SDG / PED / Programme / Subprogramme / Activity') }}</td>
            <td rowspan="3">{{ __('Performance indicators and target') }}</td>
            <td rowspan="3">{{ __('Implementation of the activities') }}</td>
            <td colspan="12" class="c">{{ __('Implementation schedule') }}</td>
            <td rowspan="3">{{ __('Budget allocated') }}</td>
        </tr>
        <tr class="hd">
            @foreach($quarterNames as $qn)<td colspan="3" class="c">{{ $qn }} {{ __('Quarter') }}</td>@endforeach
        </tr>
        <tr class="hd">
            @foreach($months as $mn)<td class="c" style="font-size:5pt;padding:1px">{{ $mn }}</td>@endforeach
        </tr>
    </thead>
    <tbody>
    @forelse($report['tree'] as $p)
        <tr class="prog">
            <td colspan="15">{{ __('Programme') }} <b>{{ $p['program']->oge_code }}</b>: {{ $p['program']->name }}</td>
            <td class="r">{{ $money($p['total']) }}</td>
        </tr>
        @foreach($p['subprograms'] as $s)
            <tr class="sub">
                <td colspan="15">{{ __('Subprogramme') }} <b>{{ $s['subprogram']->code }}</b>: {{ $s['subprogram']->name }}</td>
                <td class="r">{{ $money($s['total']) }}</td>
            </tr>
            @foreach($s['rows'] as $r)
                @include('reports.partials.implementation-activity', ['r' => $r])
            @endforeach
        @endforeach
        @foreach($p['loose'] as $r)
            @include('reports.partials.implementation-activity', ['r' => $r])
        @endforeach
    @empty
        <tr><td colspan="16" class="c muted">{{ __('No activities to show for this unit and year.') }}</td></tr>
    @endforelse
        <tr class="hd">
            <td colspan="15" class="c">{{ __('TOTAL') }}</td>
            <td class="r">{{ $money($report['total']) }}</td>
        </tr>
    </tbody>
</table>

<p style="margin-top:8px;font-size:7pt">{{ __('Dili') }}, ____ {{ \Carbon\Carbon::now()->translatedFormat('F Y') }}</p>
<table class="sign">
    <tr>
        <td><div>{{ __('Prepared by') }}</div><div class="name">{{ $report['prepared_by'] ?: ' ' }}</div></td>
        <td><div>{{ __('Approved by') }}</div><div class="name">{{ $report['approved_by'] ?: ' ' }}</div></td>
    </tr>
</table>
<p class="fine">{{ $report['reference'] }} · {{ __('Generated') }} {{ $report['generated_at']->format('Y-m-d H:i') }} · {{ $report['generated_by'] }}</p>

@unless($pdf)
</div>
@endunless
</body>
</html>
