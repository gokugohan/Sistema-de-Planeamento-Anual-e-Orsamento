{{-- A distribution of the budget among the units, on one A4 sheet: the annex of the act (despacho) that approves it. --}}
@php
    $money = fn ($v) => '$ '.number_format((float) $v, 2);
    $d = $distribution;
    $columns = $classes->filter(fn ($c) => collect($matrix)->contains(fn ($byClass) => abs($byClass[$c->id] ?? 0) >= 0.005))->values();
    // each share as a percentage of the category (the column total, which is what the decision has in it)
    $colTotal = $columns->mapWithKeys(fn ($c) => [$c->id => collect($matrix)->sum(fn ($byClass) => $byClass[$c->id] ?? 0)])->all();
    $grand = collect($matrix)->sum(fn ($byClass) => array_sum($byClass));
    $pct = fn ($part, $whole) => $whole > 0 ? number_format(100 * $part / $whole, 1).'%' : '';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Budget distribution') }} {{ $d->fiscal_year }} — {{ $organization }}</title>
    <style>
        @page { size: A4 landscape; margin: 12mm; }
        * { box-sizing: border-box; }
        body { font-family: 'Inter', Arial, sans-serif; font-size: 9pt; color: #1C2434; margin: 0; line-height: 1.35; background: #F1F5F9; }
        .sheet { max-width: 297mm; margin: 16px auto; background: #fff; padding: 12mm; box-shadow: 0 1px 3px rgba(0,0,0,.12); overflow-x: auto; }
        .cover { text-align: center; padding-bottom: 10px; border-bottom: 3px solid #1C2434; margin-bottom: 12px; }
        .state { font-size: 8pt; letter-spacing: 2px; text-transform: uppercase; color: #64748B; }
        .org { font-size: 13pt; font-weight: bold; margin-top: 4px; text-transform: uppercase; }
        .title { display: inline-block; margin-top: 8px; padding: 5px 14px; background: #1C2434; color: #fff; font-weight: bold; font-size: 10pt; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 4px 6px; vertical-align: top; }
        thead th { background: #EFF4FB; border-bottom: 1px solid #94A3B8; font-size: 7.5pt; text-transform: uppercase; text-align: left; }
        tbody td { border-bottom: 0.5px solid #E2E8F0; }
        .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .muted { color: #64748B; }
        .pct { font-size: 7pt; color: #64748B; }
        .total td { font-weight: bold; border-top: 1px solid #1C2434; border-bottom: none; }
        .meta td { border: none; padding: 1px 8px 1px 0; }
        .draft { border: 1px solid #F59E0B; background: #FFFBEB; padding: 6px 8px; margin: 8px 0; }
        .signatures td { border: none; text-align: center; padding-top: 44px; width: 50%; }
        .signatures .line { border-top: 1px solid #1C2434; margin: 0 30px; padding-top: 3px; }
        .toolbar { position: sticky; top: 0; background: #1C2434; color: #fff; padding: 10px 16px; display: flex; gap: 10px; align-items: center; font-size: 13px; }
        .toolbar button { background: #2152C4; color: #fff; border: 0; border-radius: 4px; padding: 7px 12px; cursor: pointer; font-size: 13px; }
        @media print {
            body { background: #fff; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .toolbar { display: none; }
            .sheet { margin: 0; padding: 0; box-shadow: none; max-width: none; overflow: visible; }
        }
    </style>
</head>
<body>
<div class="toolbar"><span>{{ __('Budget distribution') }} {{ $d->fiscal_year }}</span><span style="margin-left:auto"></span><button type="button" onclick="window.print()">{{ __('Print') }}</button></div>
<div class="sheet">
    <div class="cover">
        @if($logoUri = \App\Support\Branding::logoDataUri())<div><img src="{{ $logoUri }}" alt="" style="height:46px;margin-bottom:4px"></div>@endif
        <div class="state">República Democrática de Timor-Leste</div>
        <div class="org">{{ $organization }}</div>
        <div class="title">{{ __('Distribution of the budget among the units') }} — {{ $d->fiscal_year }}</div>
    </div>

    @if($d->status !== \App\Models\BudgetDistribution::APPROVED)
        <div class="draft">{{ __('Status') }}: <strong>{{ $d->statusWord() }}</strong>. {{ $d->status === \App\Models\BudgetDistribution::SUPERSEDED ? __('A later distribution replaced this one.') : __('Not approved: not in force.') }}</div>
    @endif

    <table class="meta" style="width:auto;margin-bottom:10px">
        <tr><td class="muted">{{ __('Decision shared') }}</td><td>{{ $d->decision?->stageLabel() }}@if($d->decision?->reference) — {{ $d->decision->reference }}@endif, {{ $d->decision?->decided_on?->format('d/m/Y') }}</td></tr>
        <tr><td class="muted">{{ __('Amount decided') }}</td><td>{{ $money($d->decision?->total) }}</td></tr>
        <tr><td class="muted">{{ __('Version') }}</td><td>{{ $d->version }}</td></tr>
        @if($d->act_reference)<tr><td class="muted">{{ __('Approved by the act') }}</td><td>{{ $d->act_reference }}@if($d->act_date), {{ $d->act_date->format('d/m/Y') }}@endif</td></tr>@endif
    </table>

    <table>
        <thead>
            <tr>
                <th>{{ __('Unit') }}</th>
                @foreach($columns as $c)<th class="num">{{ $c->code }} {{ $c->name }}</th>@endforeach
                <th class="num">{{ __('Total') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($units as $u)
                <tr>
                    <td><strong>{{ $u->code }}</strong> {{ $u->name }}</td>
                    @foreach($columns as $c)
                        @php $v = (float) ($matrix[$u->id][$c->id] ?? 0); @endphp
                        <td class="num">{{ abs($v) >= 0.005 ? $money($v) : '—' }}@if(abs($v) >= 0.005)<div class="pct">{{ $pct($v, $colTotal[$c->id]) }}</div>@endif</td>
                    @endforeach
                    <td class="num">{{ $money(array_sum($matrix[$u->id] ?? [])) }}<div class="pct">{{ $pct(array_sum($matrix[$u->id] ?? []), $grand) }}</div></td>
                </tr>
            @endforeach
            <tr>
                <td><strong>{{ __('Kept by the institution') }}</strong></td>
                @foreach($columns as $c)<td class="num">{{ $money($matrix[0][$c->id] ?? 0) }}<div class="pct">{{ $pct($matrix[0][$c->id] ?? 0, $colTotal[$c->id]) }}</div></td>@endforeach
                <td class="num">{{ $money(array_sum($matrix[0] ?? [])) }}<div class="pct">{{ $pct(array_sum($matrix[0] ?? []), $grand) }}</div></td>
            </tr>
            <tr class="total">
                <td>{{ __('Total') }}</td>
                @foreach($columns as $c)<td class="num">{{ $money(collect($matrix)->sum(fn ($byClass) => $byClass[$c->id] ?? 0)) }}</td>@endforeach
                <td class="num">{{ $money(collect($matrix)->sum(fn ($byClass) => array_sum($byClass))) }}</td>
            </tr>
        </tbody>
    </table>
    @if($d->note)<p style="margin-top:10px"><span class="muted">{{ __('Note') }}:</span> {{ $d->note }}</p>@endif

    <table class="signatures">
        <tr>
            <td><div class="line">{{ __('Prepared by') }}: {{ $d->submitter?->name ?? $d->preparer?->name ?? '' }}</div></td>
            <td><div class="line">{{ __('Approved by') }}: {{ $d->approver?->name ?? '' }}@if($d->on_behalf) ({{ __('on behalf of the approver') }})@endif</div></td>
        </tr>
    </table>
</div>
</body>
</html>
