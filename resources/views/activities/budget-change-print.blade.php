{{-- The budget change a revision makes (Decreto-Lei n.º 42/2025, art. 35.9): where the money comes from and goes to, by
     category, and what it does to the targets; printed to go with the request to the Ministry of Finance. --}}
@php $money = fn ($v) => '$ '.number_format((float) $v, 2); @endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Budget change') }} — {{ $activity->code }}</title>
    <style>
        @page { size: A4 portrait; margin: 14mm; }
        * { box-sizing: border-box; }
        body { font-family: 'Inter', Arial, sans-serif; font-size: 9.5pt; color: #1C2434; margin: 0; line-height: 1.4; background: #F1F5F9; }
        .sheet { max-width: 210mm; margin: 16px auto; background: #fff; padding: 14mm; box-shadow: 0 1px 3px rgba(0,0,0,.12); overflow-x: auto; }
        .cover { text-align: center; padding-bottom: 10px; border-bottom: 3px solid #1C2434; margin-bottom: 12px; }
        .state { font-size: 8pt; letter-spacing: 2px; text-transform: uppercase; color: #64748B; }
        .org { font-size: 12pt; font-weight: bold; margin-top: 4px; text-transform: uppercase; }
        .title { display: inline-block; margin-top: 8px; padding: 5px 14px; background: #1C2434; color: #fff; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 4px 6px; vertical-align: top; text-align: left; }
        thead th { background: #EFF4FB; border-bottom: 1px solid #94A3B8; font-size: 8pt; text-transform: uppercase; }
        tbody td { border-bottom: 0.5px solid #E2E8F0; }
        .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .muted { color: #64748B; }
        .meta td { border: none; padding: 1px 10px 1px 0; }
        .box { border: 1px solid #CBD5E1; padding: 8px 10px; margin-top: 8px; }
        .warn { border-color: #F59E0B; background: #FFFBEB; }
        .toolbar { position: sticky; top: 0; background: #1C2434; color: #fff; padding: 10px 16px; display: flex; gap: 10px; align-items: center; font-size: 13px; }
        .toolbar button { background: #2152C4; color: #fff; border: 0; border-radius: 4px; padding: 7px 12px; cursor: pointer; font-size: 13px; }
        @media print { body { background: #fff; } .toolbar { display: none; } .sheet { margin: 0; padding: 0; box-shadow: none; max-width: none; } }
    </style>
</head>
<body>
<div class="toolbar"><span>{{ __('Budget change') }} — {{ $activity->code }}</span><span style="margin-left:auto"></span><button type="button" onclick="window.print()">{{ __('Print') }}</button></div>
<div class="sheet">
    <div class="cover">
        @if($logoUri = \App\Support\Branding::logoDataUri())<div><img src="{{ $logoUri }}" alt="" style="height:42px;margin-bottom:4px"></div>@endif
        <div class="state">República Democrática de Timor-Leste</div>
        <div class="org">{{ $organization }}</div>
        <div class="title">{{ __('Budget change') }} — {{ $activity->fiscal_year }}</div>
    </div>

    <table class="meta">
        <tr><td class="muted">{{ __('Activity') }}</td><td><strong>{{ $activity->code }}</strong> {{ $activity->description }}</td></tr>
        <tr><td class="muted">{{ __('Unit') }}</td><td>{{ $activity->directorate?->code }} {{ $activity->directorate?->name }}</td></tr>
        <tr><td class="muted">{{ __('Program') }}</td><td>{{ $check['program'] ? __('from :from to :to', $check['program']) : trim(($activity->program?->oge_code ?? '').' '.($activity->program?->name ?? '')) }}</td></tr>
        <tr><td class="muted">{{ __('Version') }}</td><td>{{ __('Version :n in force; revision to version :m', ['n' => $activity->version, 'm' => $activity->version + 1]) }}</td></tr>
    </table>

    <table style="margin-top:10px">
        <thead><tr><th>{{ __('Category') }}</th><th class="num">{{ __('In force') }}</th><th class="num">{{ __('Revised') }}</th><th class="num">{{ __('Difference') }}</th><th class="num">{{ __('Moved out') }}</th></tr></thead>
        <tbody>
            @foreach($check['moves'] as $m)
                <tr>
                    <td>{{ $m['code'] }} {{ $m['name'] }}</td>
                    <td class="num">{{ $money($m['from']) }}</td>
                    <td class="num">{{ $money($m['to']) }}</td>
                    <td class="num">{{ ($m['to'] - $m['from'] >= 0 ? '+' : '−').$money(abs($m['to'] - $m['from'])) }}</td>
                    <td class="num">{{ $m['moved'] > 0 ? $money($m['moved']) : '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="box"><strong>{{ __('Reason for the revision') }}:</strong> {{ $activity->revision_reason ?: '—' }}</div>
    <div class="box"><strong>{{ __('Effect on the targets') }}:</strong> {{ $activity->revision_effect ?: __('Not stated yet.') }}</div>
    @if($check['warnings'])
        <div class="box warn"><strong>{{ __('To check against the rules on budget changes') }}</strong>
            <ul style="margin:4px 0 0;padding-left:16px">@foreach($check['warnings'] as $w)<li>{{ $w }}</li>@endforeach</ul>
        </div>
    @endif
    <p class="muted" style="margin-top:10px;font-size:8pt">{{ __('Prepared from the annual plan. The binding check of a budget change is made by the Ministry of Finance on the appropriation in its financial management system (Decreto-Lei n.º 42/2025, art. 35.10).') }}</p>
</div>
</body>
</html>
