<?php

use App\Models\FiscalEnvelope;
use App\Models\Program;
use App\Services\ExecutionReport;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Component;

/**
 * Execution of the active fiscal year: what has been paid and committed against the approved budget
 * (read against how far the year has gone), and the delivery of indicator targets, by programme and by quarter.
 * Only verified figures count.
 */
new class extends Component {
    public $activeYear;

    public function mount(): void
    {
        $this->activeYear = FiscalEnvelope::getActiveEnvelope()?->fiscal_year;
    }

    public function with(): array
    {
        if (! $this->activeYear) {
            return ['has' => false];
        }

        $report = app(ExecutionReport::class);
        $data = $report->load((int) $this->activeYear);
        $summary = $report->summary($data);
        $physical = app(\App\Services\DeliveryReport::class)->byProgramme((int) $this->activeYear);
        $programmes = Program::whereIn('id', array_keys($report->grouped($data, 'program_id')))->get(['id', 'name', 'oge_code'])->keyBy('id');

        $rows = [];
        foreach ($report->grouped($data, 'program_id') as $id => $g) {
            $rows[] = [
                'code' => $programmes[$id]->oge_code ?? '', 'name' => $programmes[$id]->name ?? '',
                'approved' => $g['approved'], 'paid' => $g['paid'], 'committed' => $g['committed'],
                'paid_pct' => $g['approved'] > 0 ? $g['paid'] / $g['approved'] * 100 : 0.0,
                'due_pct' => $g['approved'] > 0 ? $g['planned_to_date'] / $g['approved'] * 100 : 0.0,
                'physical' => $physical[$id] ?? null,
            ];
        }
        usort($rows, fn ($a, $b) => strcmp($a['code'], $b['code']));

        $physicalAll = $physical ? array_sum($physical) / count($physical) : null;

        return ['has' => true, 'summary' => $summary, 'rows' => $rows, 'physical' => $physicalAll];
    }
}; ?>

<div class="space-y-6">
    <x-ui.page-header :title="__('Execution Monitoring')"
        :subtitle="__('Paid and committed against the approved budget, and delivery of targets').($activeYear ? ' · '.$activeYear : '')">
        @if($activeYear)
            <x-ui.button variant="secondary" shadow="none" href="{{ route('monitoring.progress') }}" wire:navigate>{{ __('Report progress') }}</x-ui.button>
            <x-ui.button variant="primary" href="{{ route('monitoring.execution') }}" wire:navigate>{{ __('Report spending') }}</x-ui.button>
        @endif
    </x-ui.page-header>

    @if(! $has)
        <x-ui.card padding="p-0">
            <x-ui.empty-state :title="__('Monitoring Suspended: No Active Fiscal Year')"
                :text="__('Execution is monitored once the fiscal envelope for the period has been approved and is within its validity dates.')">
                <x-ui.button variant="primary" href="{{ route('budget.envelope.list') }}">{{ __('Manage Fiscal Envelopes') }}</x-ui.button>
            </x-ui.empty-state>
        </x-ui.card>
    @else
        @php
            $s = $summary;
            $behindSchedule = $s['paid_rate'] !== null && $s['planned_rate'] !== null && $s['paid_rate'] < $s['planned_rate'] - 5;
        @endphp

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.kpi :label="__('Approved budget')" :value="'$ '.number_format($s['approved'])" :sub="__('Distributed to the activities')" tone="zinc" />
            <x-ui.kpi :label="__('Committed')" :value="'$ '.number_format($s['committed'])"
                :sub="number_format($s['committed_rate'] ?? 0, 1).'% '.__('of the approved budget')" :progress="$s['committed_rate']" tone="emerald" />
            <x-ui.kpi :label="__('Paid')" :value="'$ '.number_format($s['paid'])"
                :sub="number_format($s['paid_rate'] ?? 0, 1).'% '.__('paid').' · '.number_format($s['elapsed'], 0).'% '.__('of the year has passed')"
                :progress="$s['paid_rate']" :tone="$behindSchedule ? 'amber' : 'indigo'" />
            <x-ui.kpi :label="__('Delivery against targets')" :value="$physical !== null ? number_format($physical, 1).'%' : '—'"
                :sub="$physical !== null ? __('Verified progress, all programmes') : __('No verified progress yet')" :progress="$physical" tone="amber" />
        </div>

        <div class="grid gap-6 lg:grid-cols-12">
            <x-ui.card :title="__('Execution by quarter')" :description="__('Cumulative at the end of each quarter')" class="min-w-0 lg:col-span-7">
                <div wire:key="q-{{ md5(json_encode($s['quarters'])) }}" class="relative h-72"
                    x-data
                    x-init="onChart(() => { const q = @js($s['quarters']); const usd = v => '$ ' + Math.round(v).toLocaleString();
                        const tick = { color: '#64748B', font: { family: 'Inter', size: 11 } };
                        new Chart($refs.c, { type: 'bar', data: { labels: ['Q1','Q2','Q3','Q4'], datasets: [
                            { type: 'bar', label: @js(__('Paid')), data: q.map(x => x.paid), backgroundColor: '#2152C4', borderRadius: 3, order: 2 },
                            { type: 'line', label: @js(__('Committed')), data: q.map(x => x.committed), borderColor: '#10B981', backgroundColor: '#10B981', tension: .25, order: 1 },
                            { type: 'line', label: @js(__('Planned (cumulative)')), data: q.map(x => x.planned), borderColor: '#94A3B8', borderDash: [5,4], backgroundColor: '#94A3B8', tension: .25, order: 0 } ] },
                            options: { responsive: true, maintainAspectRatio: false, animation: false,
                                plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, boxHeight: 10, font: { family: 'Inter', size: 11 } } }, tooltip: { callbacks: { label: c => ' ' + c.dataset.label + ': ' + usd(c.parsed.y) } } },
                                scales: { y: { beginAtZero: true, grid: { color: '#E2E8F0' }, ticks: { ...tick, callback: v => v >= 1e6 ? '$' + +(v/1e6).toFixed(1) + 'M' : (v >= 1e3 ? '$' + (v/1e3) + 'k' : '$' + v) } }, x: { grid: { display: false }, ticks: tick } } } }); })">
                    <canvas x-ref="c" role="img" aria-label="{{ __('Cumulative planned, committed and paid amounts after each quarter') }}"></canvas>
                </div>
            </x-ui.card>

            <x-ui.card :title="__('Paid and delivered, by programme')" :description="__('Percent of the approved budget paid, and of the targets delivered')" class="min-w-0 lg:col-span-5">
                <div wire:key="p-{{ md5(json_encode($rows)) }}" class="relative h-72"
                    x-data
                    x-init="onChart(() => { const r = @js($rows); const tick = { color: '#64748B', font: { family: 'Inter', size: 11 } };
                        new Chart($refs.c, { type: 'bar', data: { labels: r.map(x => x.code), datasets: [
                            { label: @js(__('Paid')) + ' %', data: r.map(x => x.paid_pct), backgroundColor: '#2152C4', borderRadius: 3 },
                            { label: @js(__('Delivered')) + ' %', data: r.map(x => x.physical), backgroundColor: '#10B981', borderRadius: 3 } ] },
                            options: { responsive: true, maintainAspectRatio: false, animation: false,
                                plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, boxHeight: 10, font: { family: 'Inter', size: 11 } } }, tooltip: { callbacks: { label: c => ' ' + c.dataset.label + ': ' + (c.parsed.y ?? 0).toFixed(1) + '%' } } },
                                scales: { y: { beginAtZero: true, max: 100, grid: { color: '#E2E8F0' }, ticks: { ...tick, callback: v => v + '%' } }, x: { grid: { display: false }, ticks: tick } } } }); })">
                    <canvas x-ref="c" role="img" aria-label="{{ __('Percent paid and percent of targets delivered for each programme') }}"></canvas>
                </div>
            </x-ui.card>
        </div>

        <x-ui.card :title="__('By programme')" :description="__('Read paid against what the schedule calls for by now')" padding="p-0">
            @if(count($rows) === 0)
                <x-ui.empty-state :title="__('Nothing to report yet')" />
            @else
                <x-ui.table :headers="[__('Programme'), __('Approved'), __('Committed'), __('Paid'), __('Paid %'), __('Due by now %'), __('Delivered %')]" :right="[1, 2, 3, 4, 5, 6]" class="text-sm">
                    @foreach($rows as $r)
                        @php $late = $r['paid_pct'] < $r['due_pct'] - 5; @endphp
                        <tr>
                            <td class="px-5 py-3"><div class="font-medium text-zinc-900">{{ $r['code'] }}</div><div class="text-xs text-zinc-500">{{ $r['name'] }}</div></td>
                            <td class="px-5 py-3 num">$ {{ number_format($r['approved']) }}</td>
                            <td class="px-5 py-3 num">$ {{ number_format($r['committed']) }}</td>
                            <td class="px-5 py-3 num">$ {{ number_format($r['paid']) }}</td>
                            <td class="px-5 py-3 num font-medium {{ $late ? 'text-amber-800' : 'text-zinc-900' }}">{{ number_format($r['paid_pct'], 1) }}%@if($late) <span class="sr-only">({{ __('behind schedule') }})</span>@endif</td>
                            <td class="px-5 py-3 num text-zinc-600">{{ number_format($r['due_pct'], 1) }}%</td>
                            <td class="px-5 py-3 num">{{ $r['physical'] !== null ? number_format($r['physical'], 1).'%' : '—' }}</td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        </x-ui.card>
    @endif
</div>
