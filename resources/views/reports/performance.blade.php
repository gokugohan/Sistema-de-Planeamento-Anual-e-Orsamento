@php
    $money = fn ($v) => '$ ' . number_format((float) $v, 2);
    $pct = fn ($v) => $v === null ? '—' : number_format($v, 1) . '%';
    $t = $report['totals'];
    $title = ($report['type'] === 'quarter' ? __('Quarterly performance report') : __('Monthly performance report')) . ' — ' . $report['label'] . ' ' . $report['year'];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <style>
        @page { size: A4 landscape; margin: 14mm; }
        * { box-sizing: border-box; }
        body { font-family: 'Inter', Arial, sans-serif; font-size: 9pt; color: #1C2434; margin: 0; line-height: 1.35; background: #F1F5F9; }
        h1 { font-size: 15pt; margin: 0; }
        h2 { font-size: 11pt; margin: 18px 0 6px; padding-bottom: 3px; border-bottom: 1.5px solid #1C2434; page-break-after: avoid; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 4px 6px; vertical-align: top; }
        thead th { background: #EFF4FB; border-bottom: 1px solid #94A3B8; font-size: 7.5pt; text-transform: uppercase; text-align: left; }
        tbody td { border-bottom: 0.5px solid #E2E8F0; }
        .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .muted { color: #64748B; }
        .small { font-size: 7.5pt; }
        .total td { font-weight: bold; border-top: 1px solid #1C2434; }
        .cover { text-align: center; padding: 6px 0 12px; border-bottom: 3px solid #1C2434; margin-bottom: 10px; }
        .cover .state { font-size: 8pt; letter-spacing: 2px; text-transform: uppercase; color: #64748B; }
        .cover .org { font-size: 13pt; font-weight: bold; margin-top: 4px; text-transform: uppercase; }
        .cover .title { display: inline-block; margin-top: 10px; padding: 5px 14px; background: #1C2434; color: #fff; font-weight: bold; font-size: 10pt; }
        .kpi td { border: 1px solid #E2E8F0; text-align: center; padding: 8px 4px; width: 20%; }
        .kpi .v { font-size: 12pt; font-weight: bold; }
        .note { margin-top: 3px; font-size: 8pt; color: #334155; }
        .avoid-break { page-break-inside: avoid; }
        .signatures td { border: none; text-align: center; padding-top: 40px; width: 33%; }
        .signatures .line { border-top: 1px solid #1C2434; margin: 0 14px; padding-top: 3px; }
        .toolbar { position: sticky; top: 0; background: #1C2434; color: #fff; padding: 10px 16px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; font-size: 13px; z-index: 10; }
        .toolbar a, .toolbar button { background: #2152C4; color: #fff; border: 0; border-radius: 4px; padding: 7px 12px; text-decoration: none; cursor: pointer; font-size: 13px; }
        .toolbar a.secondary { background: transparent; border: 1px solid #475569; }
        .toolbar select { padding: 6px; border-radius: 4px; }
        .sheet { max-width: 297mm; margin: 16px auto; background: #fff; padding: 12mm; box-shadow: 0 1px 3px rgba(0,0,0,.12); }
        @media screen and (max-width: 700px) { .sheet { overflow-x: auto; padding: 8mm; } }
        @media print { .toolbar { display: none; } .sheet { margin: 0; padding: 0; box-shadow: none; max-width: none; } body { background: #fff; } }
    </style>
</head>
<body>
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
</div>
<div class="sheet">
    <div class="cover">
        <div class="state">República Democrática de Timor-Leste</div>
        <div class="org">{{ $report['organization'] }}</div>
        <div class="title">{{ $title }}</div>
        <div class="muted small" style="margin-top:6px">{{ __('Verified figures and reports only') }} · {{ __('Generated') }} {{ $report['generated_at']->format('Y-m-d H:i') }} · {{ $report['generated_by'] }}</div>
    </div>

    <table class="kpi">
        <tr>
            <td><div class="v">{{ $t['activities'] }}</div><div class="small muted">{{ __('Approved activities') }}</div></td>
            <td><div class="v">{{ $money($t['approved']) }}</div><div class="small muted">{{ __('Approved budget') }}</div></td>
            <td><div class="v">{{ $money($t['committed']) }}</div><div class="small muted">{{ __('Committed in :p', ['p' => $report['label']]) }}</div></td>
            <td><div class="v">{{ $money($t['paid']) }}</div><div class="small muted">{{ __('Paid in :p', ['p' => $report['label']]) }}</div></td>
            <td><div class="v">{{ $pct($t['rate']) }}</div><div class="small muted">{{ __('Paid so far / approved') }}</div></td>
        </tr>
    </table>

    @forelse($report['units'] as $u)
        <h2>{{ $u['unit']?->code }} — {{ $u['unit']?->name }}</h2>
        <table>
            <thead>
                <tr>
                    <th style="width:34%">{{ __('Activity') }}</th>
                    <th class="num">{{ __('Approved') }}</th>
                    <th class="num">{{ __('Committed in :p', ['p' => $report['label']]) }}</th>
                    <th class="num">{{ __('Paid in :p', ['p' => $report['label']]) }}</th>
                    <th class="num">{{ __('Paid so far') }}</th>
                    <th class="num">{{ __('Rate') }}</th>
                    <th style="width:30%">{{ __('Indicators (Q:q)', ['q' => $report['quarter']]) }}</th>
                </tr>
            </thead>
            <tbody>
            @foreach($u['rows'] as $r)
                <tr class="avoid-break">
                    <td>
                        <strong>{{ $r['activity']->code }}</strong> {{ $r['activity']->description }}
                        <div class="small muted">{{ $r['activity']->program?->oge_code }} · {{ $r['activity']->program?->name }}</div>
                        @if($r['month_note'])
                            <div class="note"><strong>{{ __('Monthly report') }}:</strong> {{ $r['month_note']->results }}@if($r['month_note']->constraints) — <em>{{ __('Constraints') }}:</em> {{ $r['month_note']->constraints }}@endif</div>
                        @endif
                        @if($r['quarter_note'])
                            <div class="note"><strong>{{ __('Quarterly report') }}@if($r['quarter_note']->rating) ({{ __($r['quarter_note']->rating) }})@endif:</strong> {{ $r['quarter_note']->results }}
                                @if($r['quarter_note']->reasons_not_met) — <em>{{ __('Why targets were not met') }}:</em> {{ $r['quarter_note']->reasons_not_met }}@endif
                                @if($r['quarter_note']->corrective_measures) — <em>{{ __('Corrective measures') }}:</em> {{ $r['quarter_note']->corrective_measures }}@endif</div>
                        @endif
                    </td>
                    <td class="num">{{ number_format($r['approved'], 2) }}</td>
                    <td class="num">{{ number_format($r['committed'], 2) }}</td>
                    <td class="num">{{ number_format($r['paid'], 2) }}</td>
                    <td class="num">{{ number_format($r['paid_so_far'], 2) }}</td>
                    <td class="num">{{ $pct($r['rate']) }}</td>
                    <td>
                        @foreach($r['indicators'] as $i)
                            <div class="small">{{ $i['name'] }}: <strong>{{ number_format($i['standing'], 2) }}</strong> / {{ number_format($i['target'], 2) }} {{ $i['unit'] }}@if($i['pct'] !== null) ({{ number_format($i['pct'], 0) }}%)@endif</div>
                        @endforeach
                    </td>
                </tr>
            @endforeach
                <tr class="total">
                    <td>{{ __('Total') }}</td>
                    <td class="num">{{ number_format($u['approved'], 2) }}</td>
                    <td class="num">{{ number_format($u['committed'], 2) }}</td>
                    <td class="num">{{ number_format($u['paid'], 2) }}</td>
                    <td class="num">{{ number_format($u['paid_so_far'], 2) }}</td>
                    <td class="num">{{ $pct($u['approved'] > 0 ? $u['paid_so_far'] / $u['approved'] * 100 : null) }}</td>
                    <td></td>
                </tr>
            </tbody>
        </table>
    @empty
        <p class="muted" style="margin-top:20px">{{ __('No approved activities with a distributed budget to report on.') }}</p>
    @endforelse

    <div class="avoid-break">
        <table class="signatures" style="margin-top:28px">
            <tr>
                <td><div class="line">{{ __('Prepared by') }}</div></td>
                <td><div class="line">{{ __('Verified by') }}</div></td>
                <td><div class="line">{{ __('Approved by') }}</div></td>
            </tr>
        </table>
    </div>
</div>
</body>
</html>
