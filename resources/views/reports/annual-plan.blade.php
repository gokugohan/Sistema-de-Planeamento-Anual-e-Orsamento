@php
    $pdf = $pdf ?? false;
    $money = fn ($v) => number_format((float) $v, 2);
    $title = __('Annual Activity Plan Report (PAA)').' — '.$report['year'];
    $warn = $report['warning'] ?? null;
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
        .head { text-align: center; margin-bottom: 8px; }
        .head .state { font-size: 7pt; letter-spacing: 1.5px; text-transform: uppercase; }
        .head .org { font-size: 11pt; font-weight: bold; text-transform: uppercase; margin-top: 2px; }
        .head .title { font-size: 9pt; font-weight: bold; margin-top: 4px; }
        h2 { font-size: 8.5pt; margin: 12px 0 4px; }
        table.rep { width: 100%; border-collapse: collapse; border: 0.75pt solid #000; table-layout: fixed; }
        table.rep td, table.rep th { border: 0.3pt solid #000; padding: 3px 4px; font-size: 6.5pt; vertical-align: top; word-wrap: break-word; }
        table.rep th { background: #bdd6ee; text-align: center; font-weight: bold; }
        .r { text-align: right; white-space: nowrap; }
        .root td { background: #eeece1; font-weight: bold; }
        .prog td { background: #ffcccc; font-weight: bold; }
        .subp td { background: #ffffd9; }
        .cls td { background: #ddebf7; font-weight: bold; }
        .act td { font-size: 6pt; }
        .tot td { background: #bdd6ee; font-weight: bold; }
        .muted { color: #475569; }
        .warn { border: 1px solid #B45309; background: #FEF3C7; color: #78350F; padding: 5px 8px; margin: 0 0 8px; font-size: 7.5pt; font-weight: bold; }
        .sign { width: 100%; margin-top: 26px; page-break-inside: avoid; }
        .sign td { text-align: center; width: 50%; padding-top: 34px; font-size: 7pt; }
        .sign .line { border-top: 0.5pt solid #000; margin: 0 40px; padding-top: 2px; }
        .meta { margin-top: 10px; font-size: 6pt; color: #475569; }
        .toolbar { position: sticky; top: 0; background: #1C2434; color: #fff; padding: 10px 16px; display: flex; gap: 10px; align-items: center; font-size: 13px; z-index: 10; }
        .toolbar a, .toolbar button { background: #2152C4; color: #fff; border: 0; border-radius: 4px; padding: 7px 12px; text-decoration: none; cursor: pointer; font-size: 13px; }
        .toolbar a.secondary { background: transparent; border: 1px solid #475569; }
        .sheet { max-width: 297mm; margin: 12px auto; background: #fff; padding: 8mm; box-shadow: 0 1px 3px rgba(0,0,0,.12); }
        @media print { .toolbar { display: none; } body { background: #fff; } .sheet { margin: 0; padding: 0; box-shadow: none; max-width: none; } thead { display: table-header-group; } tr { page-break-inside: avoid; } }
    </style>
</head>
<body>
@unless($pdf)
<div class="toolbar">
    <a class="secondary" href="{{ route('reports.paa', ['year' => $report['year']]) }}">← {{ __('Reporting') }}</a>
    <span style="margin-left:auto"></span>
    <a data-warn="{{ $warn ? $warn.' '.__('Download anyway?') : '' }}" onclick="return ! this.dataset.warn || confirm(this.dataset.warn)" href="{{ route('reports.annual-plan.pdf', ['year' => $report['year']]) }}">{{ __('Download PDF') }}</a>
</div>
<div class="sheet">
@endunless

@if($warn)
    <div class="warn">{{ __('Draft state') }}: {{ $warn }}</div>
@endif

<div class="head">
    @if($logoUri = \App\Support\Branding::logoDataUri())<div style="text-align:center"><img src="{{ $logoUri }}" alt="" style="height:46px;margin-bottom:4px"></div>@endif
    <div class="state">República Democrática de Timor-Leste</div>
    <div class="org">{{ $report['organization'] }}</div>
    <div class="title">{{ $title }}</div>
</div>

<h2>1. {{ __('Planned and approved, by unit') }}</h2>
<table class="rep">
    <colgroup><col style="width:52%"><col style="width:16%"><col style="width:16%"><col style="width:16%"></colgroup>
    <thead><tr><th>{{ __('Unit / Directorate Structure') }}</th><th>{{ __('Planned resources ($)') }}</th><th>{{ __('Approved plan ($)') }}</th><th>{{ __('Utilization (%)') }}</th></tr></thead>
    <tbody>
    @forelse($report['units'] as $u)
        <tr class="{{ $u['depth'] === 0 ? 'root' : '' }}">
            <td style="padding-left: {{ 4 + $u['depth'] * 12 }}px">{{ $u['code'] }} — {{ $u['name'] }}</td>
            <td class="r">$ {{ $money($u['planned']) }}</td><td class="r">$ {{ $money($u['approved']) }}</td><td class="r">{{ number_format($u['usage'], 1) }}%</td>
        </tr>
    @empty
        <tr><td colspan="4" class="muted">{{ __('Nothing to report yet') }}</td></tr>
    @endforelse
        <tr class="tot"><td>{{ __('Consolidated Institutional Totals') }}</td><td class="r">$ {{ $money($report['totals']['planned']) }}</td><td class="r">$ {{ $money($report['totals']['approved']) }}</td><td class="r">{{ number_format($report['totals']['usage'], 1) }}%</td></tr>
    </tbody>
</table>

<h2>2. {{ __('Detailed Programmatic Breakdown') }}</h2>
<table class="rep">
    <colgroup><col style="width:44%"><col style="width:20%"><col style="width:12%"><col style="width:12%"><col style="width:12%"></colgroup>
    <thead><tr><th>{{ __('Hierarchical Breakdown') }}</th><th>{{ __('Status / Indicators') }}</th><th>{{ __('Planned ($)') }}</th><th>{{ __('Approved ($)') }}</th><th>{{ __('Difference ($)') }}</th></tr></thead>
    <tbody>
    @forelse($report['programmes'] as $p)
        @php $program = $p['program']; @endphp
        <tr class="prog"><td>{{ $program->oge_code }} — {{ $program->name }}</td><td>{{ __('Program Total') }}</td><td class="r">$ {{ $money($p['planned']) }}</td><td class="r">$ {{ $money($p['approved']) }}</td><td class="r">$ {{ $money($p['planned'] - $p['approved']) }}</td></tr>
        @foreach($report['subprogrammes'][$program->id] ?? [] as $s)
            <tr class="subp"><td style="padding-left: 14px">{{ $s['code'] }} · {{ $s['name'] }}</td><td>{{ __('Subprogram Total') }}</td><td class="r">$ {{ $money($s['planned']) }}</td><td class="r">$ {{ $money($s['approved']) }}</td><td class="r">$ {{ $money($s['planned'] - $s['approved']) }}</td></tr>
        @endforeach
        @foreach($p['classes'] as $c)
            <tr class="cls"><td style="padding-left: 14px">{{ $c['classification']->code }} {{ $c['classification']->name }}</td><td>{{ __('Category Total') }}</td><td class="r">$ {{ $money($c['planned']) }}</td><td class="r">$ {{ $money($c['approved']) }}</td><td class="r">$ {{ $money($c['planned'] - $c['approved']) }}</td></tr>
            @foreach($c['activities'] as $a)
                <tr class="act">
                    <td style="padding-left: 28px">{{ $a['activity']->description }}<br><span class="muted">{{ $a['activity']->directorate->code ?? '' }} · {{ __('Priority') }}: {{ __($a['activity']->priority) }}</span></td>
                    <td>@foreach($a['indicators'] as $i){{ $i->name }}: {{ number_format($i->annual_target, 0) }} {{ $i->unit }}@if(! $loop->last)<br>@endif @endforeach</td>
                    <td class="r">$ {{ $money($a['planned']) }}</td><td class="r">$ {{ $money($a['approved']) }}</td><td class="r">$ {{ $money($a['planned'] - $a['approved']) }}</td>
                </tr>
            @endforeach
        @endforeach
    @empty
        <tr><td colspan="5" class="muted">{{ __('Nothing to report yet') }}</td></tr>
    @endforelse
    </tbody>
</table>

<table class="sign">
    <tr>
        <td><div class="line">{{ __('Prepared by') }}<br><b>{{ $report['generated_by'] }}</b></div></td>
        <td><div class="line">{{ __('Verified By') }}<br>&nbsp;</div></td>
    </tr>
</table>

<div class="meta">{{ $report['reference'] }} · {{ __('Generated') }} {{ $report['generated_at']->format('Y-m-d H:i') }} TLT · {{ $report['generated_by'] }}</div>

@unless($pdf)
</div>
@endunless
</body>
</html>
