@php
    $money = fn ($v) => '$ ' . number_format((float) $v, 2);
    $classes = $report['classes'];
    $grand = $report['totals']['total'];
    $pendingTotal = array_sum($report['pending']);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $report['reference'] }} — {{ __('Annual Activity Plan') }} {{ $report['year'] }}</title>
    <style>
        @page { size: A4 portrait; margin: 18mm 14mm 20mm 14mm; }
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', 'Inter', Arial, sans-serif; font-size: 9pt; color: #1C2434; margin: 0; line-height: 1.35; }
        h1 { font-size: 16pt; margin: 0; }
        h2 { font-size: 11pt; margin: 18px 0 6px; padding-bottom: 3px; border-bottom: 1.5px solid #1C2434; page-break-after: avoid; }
        h3 { page-break-after: avoid; }
        h3 { font-size: 9.5pt; margin: 12px 0 4px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 4px 5px; vertical-align: top; }
        thead th { background: #EFF4FB; border-bottom: 1px solid #94A3B8; font-size: 7.5pt; text-transform: uppercase; text-align: left; }
        tbody td { border-bottom: 0.5px solid #E2E8F0; }
        .num { text-align: right; white-space: nowrap; }
        .muted { color: #64748B; }
        .small { font-size: 7.5pt; }
        .total td { font-weight: bold; border-top: 1px solid #1C2434; border-bottom: none; }
        .root td { background: #F7F9FC; font-weight: bold; }
        .cover { text-align: center; padding: 6px 0 14px; border-bottom: 3px solid #1C2434; margin-bottom: 10px; }
        .cover .state { font-size: 8pt; letter-spacing: 2px; text-transform: uppercase; color: #64748B; }
        .cover .org { font-size: 13pt; font-weight: bold; margin-top: 4px; text-transform: uppercase; }
        .cover .title { display: inline-block; margin-top: 10px; padding: 5px 14px; background: #1C2434; color: #fff; font-weight: bold; font-size: 10pt; }
        .meta td { border: none; padding: 1px 0; font-size: 8pt; }
        .note { border: 1px solid #F59E0B; background: #FFFBEB; padding: 6px 8px; margin: 8px 0; font-size: 8pt; }
        .kpi td { border: 1px solid #E2E8F0; text-align: center; padding: 8px 4px; width: 25%; }
        .kpi .v { font-size: 12pt; font-weight: bold; }
        .page-break { page-break-before: always; }
        .avoid-break { page-break-inside: avoid; }
        .signatures td { border: none; text-align: center; padding-top: 40px; width: 33%; }
        .signatures .line { border-top: 1px solid #1C2434; margin: 0 14px; padding-top: 3px; }
        .footer { position: fixed; bottom: -12mm; left: 0; right: 0; font-size: 7pt; color: #64748B; border-top: 0.5px solid #CBD5E1; padding-top: 3px; }
        ul.ind { margin: 0; padding-left: 12px; }
        /* Browser-only toolbar */
        .toolbar { position: sticky; top: 0; background: #1C2434; color: #fff; padding: 10px 16px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; font-family: Inter, Arial, sans-serif; font-size: 13px; z-index: 10; }
        .toolbar a, .toolbar button { background: #3C50E0; color: #fff; border: 0; border-radius: 4px; padding: 7px 12px; text-decoration: none; cursor: pointer; font-size: 13px; }
        .toolbar a.secondary { background: transparent; border: 1px solid #475569; }
        .toolbar select { padding: 6px; border-radius: 4px; }
        .sheet { max-width: 210mm; margin: 16px auto; background: #fff; padding: 14mm; box-shadow: 0 1px 3px rgba(0,0,0,.12); }
        /* dompdf renders with media "screen", so browser-only rules hang off body.screen instead of @media */
        body.screen { background: #F1F5F9; }
        body.screen .footer { position: static; margin-top: 16px; }
        @media print { .toolbar { display: none; } .sheet { margin: 0; padding: 0; box-shadow: none; max-width: none; } body { background: #fff; } }
    </style>
</head>
<body class="{{ $pdf ? 'pdf' : 'screen' }}">
@unless($pdf)
    <div class="toolbar">
        <a class="secondary" href="{{ route('reports.paa') }}">← {{ __('Reporting') }}</a>
        <form method="GET" action="{{ route('reports.approved-plan') }}" style="margin:0">
            <select name="year" onchange="this.form.submit()" aria-label="{{ __('Fiscal year') }}">
                @foreach(range((int) date('Y') + 1, 2024) as $y)
                    <option value="{{ $y }}" @selected($y === $report['year'])>{{ $y }}</option>
                @endforeach
            </select>
        </form>
        <span style="margin-left:auto"></span>
        <button type="button" onclick="window.print()">{{ __('Print') }}</button>
        <a href="{{ route('reports.approved-plan.pdf', ['year' => $report['year']]) }}">{{ __('Download PDF') }}</a>
    </div>
    <div class="sheet">
@endunless

<div class="footer">
    <table><tr>
        <td style="width:44%">{{ $report['reference'] }} · {{ __('Fingerprint') }} {{ $report['fingerprint'] }}</td>
        <td style="width:38%">{{ __('Generated') }} {{ $report['generated_at']->format('Y-m-d H:i') . ' TLT' }}@if($report['generated_by']) · {{ $report['generated_by'] }}@endif</td>
        <td style="width:18%"></td>{{-- page numbers are drawn by the PDF renderer --}}
    </tr></table>
</div>

<div class="cover">
    <div class="state">República Democrática de Timor-Leste</div>
    <div class="org">{{ $report['organization'] }}</div>
    <div class="title">{{ __('Annual Activity Plan and Budget Proposal') }} {{ $report['year'] }}</div>
    <div class="muted" style="margin-top:6px">{{ \App\Support\Institution::coverLine() }}</div>
</div>

<table class="meta">
    <tr><td style="width:28%" class="muted">{{ __('Document reference') }}</td><td>{{ $report['reference'] }}</td></tr>
    <tr><td class="muted">{{ __('Fiscal year') }}</td><td>{{ $report['year'] }}</td></tr>
    <tr><td class="muted">{{ __('Scope') }}</td><td>{{ __('Activities approved internally (status Approved)') }}</td></tr>
</table>

@if($pendingTotal > 0)
    <div class="note">
        <strong>{{ __('Plan not complete:') }}</strong>
        {{ __(':n activities for this year are still in the internal approval workflow and are NOT included:', ['n' => $pendingTotal]) }}
        {{ collect($report['pending'])->map(fn ($n, $status) => __($status) . ' (' . $n . ')')->implode(', ') }}.
    </div>
@endif

<table class="kpi" style="margin-top:10px">
    <tr>
        <td><div class="v">{{ $report['totals']['count'] }}</div><div class="small muted">{{ __('Approved activities') }}</div></td>
        <td><div class="v">{{ $report['by_unit']->sum(fn ($r) => $r['units']->count()) }}</div><div class="small muted">{{ __('Organic units') }}</div></td>
        <td><div class="v">{{ $report['indicator_count'] }}</div><div class="small muted">{{ __('Indicators') }}</div></td>
        <td><div class="v">{{ $money($grand) }}</div><div class="small muted">{{ __('Total budget proposal') }}</div></td>
    </tr>
</table>

@if($report['totals']['count'] === 0)
    <p class="muted" style="margin-top:20px">{{ __('There are no approved activities for this fiscal year yet.') }}</p>
@else

<h2>1. {{ __('Summary by economic category') }}</h2>
<table>
    <thead><tr><th style="width:12%">{{ __('Code') }}</th><th>{{ __('Category') }}</th><th class="num">{{ __('Amount') }}</th><th class="num" style="width:12%">%</th></tr></thead>
    <tbody>
    @foreach($classes as $c)
        @php $v = $report['totals']['by_class'][$c->id] ?? 0; @endphp
        <tr><td>{{ $c->code }}</td><td>{{ $c->name }}</td><td class="num">{{ $money($v) }}</td><td class="num">{{ $grand > 0 ? number_format($v / $grand * 100, 1) : '0.0' }}%</td></tr>
    @endforeach
    <tr class="total"><td></td><td>{{ __('Total') }}</td><td class="num">{{ $money($grand) }}</td><td class="num">100.0%</td></tr>
    </tbody>
</table>

<h2>2. {{ __('Summary by programme and economic category') }}</h2>
<table>
    <thead><tr>
        <th>{{ __('Programme') }}</th>
        @foreach($classes as $c)<th class="num">{{ $c->code }}</th>@endforeach
        <th class="num">{{ __('Total') }}</th>
    </tr></thead>
    <tbody>
    @foreach($report['by_program'] as $p)
        <tr>
            <td>{{ $p['code'] }} — {{ $p['name'] }} <span class="muted small">({{ $p['count'] }})</span></td>
            @foreach($classes as $c)<td class="num">{{ number_format($p['by_class'][$c->id] ?? 0, 0) }}</td>@endforeach
            <td class="num">{{ number_format($p['total'], 2) }}</td>
        </tr>
    @endforeach
    <tr class="total">
        <td>{{ __('Total') }}</td>
        @foreach($classes as $c)<td class="num">{{ number_format($report['totals']['by_class'][$c->id] ?? 0, 0) }}</td>@endforeach
        <td class="num">{{ number_format($grand, 2) }}</td>
    </tr>
    </tbody>
</table>
<p class="small muted">{{ __('Category columns') }}: {{ $classes->map(fn ($c) => $c->code . ' ' . $c->name)->implode(' · ') }}. {{ __('Amounts in USD.') }}</p>

<div class="page-break"></div>
<h2>3. {{ __('Summary by organic unit and economic category') }}</h2>
<table>
    <thead><tr>
        <th>{{ __('Organic unit') }}</th>
        @foreach($classes as $c)<th class="num">{{ $c->code }}</th>@endforeach
        <th class="num">{{ __('Total') }}</th>
    </tr></thead>
    <tbody>
    @php
        $sections = [
            ['label' => __('Ministry services'), 'roots' => $report['by_unit']->where('autonomous', false)],
            ['label' => __('Transfers to autonomous bodies (indirect administration)'), 'roots' => $report['by_unit']->where('autonomous', true)],
        ];
    @endphp
    @foreach($sections as $section)
        @continue($section['roots']->isEmpty())
        @if($report['autonomous']['count'] > 0)
            <tr><td colspan="{{ $classes->count() + 2 }}" class="small muted" style="padding-top:8px"><strong>{{ $section['label'] }}</strong></td></tr>
        @endif
        @foreach($section['roots'] as $root)
            <tr class="root">
                <td>{{ $root['code'] }} — {{ $root['name'] }}@if($root['autonomous'] && $root['legal_form']) <span class="small muted">({{ $root['legal_form'] }})</span>@endif</td>
                @foreach($classes as $c)<td class="num">{{ number_format($root['by_class'][$c->id] ?? 0, 0) }}</td>@endforeach
                <td class="num">{{ number_format($root['total'], 2) }}</td>
            </tr>
            @if($root['units']->count() > 1 || $root['units']->first()['code'] !== $root['code'])
                @foreach($root['units'] as $u)
                    <tr>
                        <td style="padding-left:14px">{{ $u['code'] }} — {{ $u['name'] }}</td>
                        @foreach($classes as $c)<td class="num">{{ number_format($u['by_class'][$c->id] ?? 0, 0) }}</td>@endforeach
                        <td class="num">{{ number_format($u['total'], 2) }}</td>
                    </tr>
                @endforeach
            @endif
        @endforeach
        @if($report['autonomous']['count'] > 0)
            <tr class="total">
                <td>{{ __('Subtotal') }} — {{ $section['label'] }}</td>
                @foreach($classes as $c)<td class="num">{{ number_format($section['roots']->sum(fn ($r) => $r['by_class'][$c->id] ?? 0), 0) }}</td>@endforeach
                <td class="num">{{ number_format($section['roots']->sum('total'), 2) }}</td>
            </tr>
        @endif
    @endforeach
    <tr class="total">
        <td>{{ __('Total') }}</td>
        @foreach($classes as $c)<td class="num">{{ number_format($report['totals']['by_class'][$c->id] ?? 0, 0) }}</td>@endforeach
        <td class="num">{{ number_format($grand, 2) }}</td>
    </tr>
    </tbody>
</table>
@if($report['autonomous']['count'] > 0)
    <p class="small muted" style="margin-top:6px">
        {{ __('The total is one package for the ministry. The autonomous bodies are shown as a separate section because the ministry passes their share on once it has received its fiscal envelope.') }}
    </p>
@endif

<div class="page-break"></div>
<h2>4. {{ __('Approved activities by organic unit') }}</h2>
@foreach($report['by_unit'] as $root)
    @foreach($root['units'] as $u)
        <h3>{{ $u['code'] }} — {{ $u['name'] }} <span class="muted small">({{ $u['count'] }} · {{ $money($u['total']) }})</span></h3>
        <table>
            <thead><tr>
                <th style="width:4%">#</th>
                <th style="width:38%">{{ __('Activity') }}</th>
                <th style="width:10%">{{ __('Period') }}</th>
                <th style="width:28%">{{ __('Indicators / targets') }}</th>
                <th class="num" style="width:20%">{{ __('Budget') }}</th>
            </tr></thead>
            <tbody>
            @foreach($u['rows'] as $i => $r)
                @php $a = $r['activity']; @endphp
                <tr class="avoid-break">
                    <td>{{ $i + 1 }}</td>
                    <td>
                        {{ $a->description }}
                        <div class="small muted">{{ $a->program->oge_code ?? '' }} {{ $a->program->name ?? '' }} · {{ __('Priority') }}: {{ $a->priority }}@if($a->procurement_required) · {{ __('Procurement') }}@endif</div>
                    </td>
                    <td class="small">{{ collect($a->execution_quarters ?: [$a->quarter])->map(fn ($q) => 'T'.$q)->implode(' ') }}<br>{{ \Illuminate\Support\Carbon::parse($a->planned_start)->format('d/m') }}–{{ \Illuminate\Support\Carbon::parse($a->planned_end)->format('d/m') }}</td>
                    <td class="small">
                        @if($a->indicators->isEmpty())
                            <span class="muted">—</span>
                        @else
                            <ul class="ind">
                                @foreach($a->indicators as $ind)
                                    <li>{{ $ind->name }}: <strong>{{ rtrim(rtrim(number_format((float) $ind->annual_target, 2), '0'), '.') }}</strong> {{ $ind->unit }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </td>
                    <td class="num">
                        <strong>{{ $money($r['total']) }}</strong>
                        @foreach($classes as $c)
                            @if(($r['by_class'][$c->id] ?? 0) > 0)
                                <div class="small muted">{{ $c->code }}: {{ number_format($r['by_class'][$c->id], 2) }}</div>
                            @endif
                        @endforeach
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endforeach
@endforeach

@endif

<div class="avoid-break">
    <table class="signatures" style="margin-top:28px">
        <tr>
            <td><div class="line">{{ __('Prepared by') }}</div></td>
            <td><div class="line">{{ __('Verified by') }}</div></td>
            <td><div class="line">{{ __('Approved by (Minister)') }}</div></td>
        </tr>
    </table>
</div>

@unless($pdf)
    </div>
@endunless
</body>
</html>
