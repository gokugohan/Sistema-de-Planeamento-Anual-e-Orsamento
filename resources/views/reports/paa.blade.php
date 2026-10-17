@php
    $showDrafts = App\Models\SystemSetting::get('show_draft_planning_in_reports', false);
    $reportYears = App\Models\Activity::distinct()->pluck('fiscal_year')
        ->unique()
        ->sortDesc();

    if ($reportYears->isEmpty()) {
        $reportYears = collect([\App\Support\FiscalYear::current()]);
    }

    $selectedYear = (int) request('year', \App\Support\FiscalYear::current());

    // Every figure on this page is added up by the database (see PlanBreakdown), never in loops over records.
    $breakdown = app(App\Services\PlanBreakdown::class);
    $unitTotals = $breakdown->unitTotals($selectedYear);
    $full = request()->boolean('full');
    $programmes = $breakdown->programmes($selectedYear, withActivities: $full);
    $subprogrammes = $breakdown->subprogrammes($selectedYear);
@endphp

<x-app-layout title="{{ __('Reporting') }}">
    <div class="space-y-6">
        <x-ui.page-header :title="__('Annual Performance Report')" :subtitle="__('Analytical Audit for').' '.$selectedYear" class="print:hidden">
            <form method="GET" action="{{ route('reports.paa') }}" class="flex items-center gap-2">
                <label for="report-year" class="sr-only">{{ __('Fiscal year') }}</label>
                <select id="report-year" name="year" onchange="this.form.submit()" class="flux-input !w-auto !py-1.5 !pr-9">
                    @foreach($reportYears as $yr)
                        <option value="{{ $yr }}" @selected((string) $selectedYear === (string) $yr)>{{ $yr }}</option>
                    @endforeach
                </select>
            </form>
            <x-ui.button variant="secondary" size="md" shadow="none" onclick="withFullReport('export')">{{ __('Export Data') }}</x-ui.button>
            <x-ui.button variant="secondary" size="md" shadow="none" href="{{ route('reports.approved-plan', ['year' => $selectedYear]) }}">
                {{ __('Final plan report') }}
            </x-ui.button>
            <x-ui.button variant="primary" size="md" onclick="withFullReport('print')">{{ __('Print Report') }}</x-ui.button>
        </x-ui.page-header>

        {{-- Official header: printed pages only --}}
        <div class="hidden print:block text-center mb-8 border-b-2 border-zinc-900 pb-6">
            <div class="text-xs font-semibold text-zinc-500 mb-2">República Democrática de Timor-Leste</div>
            <h1 class="page-title">{{ \App\Models\SystemSetting::getLocalized('org_name', config('app.name')) }}</h1>
            <div class="mt-3 text-sm font-medium text-zinc-700">{{ __('Annual Activity Plan Report (PAA)') }} — {{ $selectedYear }}</div>
        </div>

        @php

            // One query for the whole tree; each unit's children are attached in memory.
            $byParent = App\Models\Directorate::all()->groupBy(fn ($d) => $d->parent_id ?? 0);
            $byParent->flatten()->each(fn ($d) => $d->setRelation('children', $byParent->get($d->id, collect())));
            $rootDirectorates = $byParent->get(0, collect());
            $emptyTotal = ['planned' => 0.0, 'approved' => 0.0];

            $chevron = '<svg class="transition-transform duration-200" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>';

            $renderDirectorate = function ($dir, $depth = 0, $parentId = null) use (&$renderDirectorate, $unitTotals, $emptyTotal, $chevron) {
                ['planned' => $planned, 'approved' => $approved] = $unitTotals[$dir->id] ?? $emptyTotal;

                if ($planned <= 0 && $approved <= 0) {
                    return '';
                }

                $usage = $planned > 0 ? ($approved / $planned) * 100 : 0;
                $isRoot = $depth === 0;
                $hasChildren = $dir->children->isNotEmpty();
                $rowId = 'dir-' . $dir->id;
                $parentAttr = $parentId ? 'data-parent="' . $parentId . '"' : '';

                $html = '<tr id="' . $rowId . '" ' . $parentAttr . ' class="tree-row hover:bg-zinc-50 ' . ($isRoot ? 'bg-zinc-50/60' : '') . ' ' . ($depth > 0 ? 'hidden print:table-row' : '') . '">';
                $html .= '<td class="px-5 py-3 whitespace-nowrap" style="padding-left: ' . (1.25 + $depth * 1.75) . 'rem;">';
                $html .= '<div class="flex items-center gap-2">';
                $html .= $hasChildren
                    ? '<button type="button" aria-label="' . e(__('Expand or collapse')) . '" onclick="toggleTree(\'' . $rowId . '\')" class="tree-toggle-btn -ml-1 rounded p-1 text-zinc-500 hover:bg-zinc-200 hover:text-zinc-900">' . $chevron . '</button>'
                    : '<span class="w-5" aria-hidden="true"></span>';
                $html .= '<div><div class="text-sm ' . ($isRoot ? 'font-semibold text-zinc-900' : 'font-medium text-zinc-700') . '">' . e($dir->name) . '</div>';
                $html .= '<div class="text-xs text-zinc-500">' . e($dir->code) . ' · ' . e(__($dir->type)) . '</div></div>';
                $html .= '</div></td>';
                $html .= '<td class="px-5 py-3 num text-sm text-zinc-700">$ ' . number_format($planned, 2) . '</td>';
                $html .= '<td class="px-5 py-3 num text-sm font-medium ' . ($usage > 100 ? 'text-rose-700' : 'text-zinc-900') . '">$ ' . number_format($approved, 2) . '</td>';
                $html .= '<td class="px-5 py-3"><div class="flex items-center justify-end gap-3 text-sm tabular-nums text-zinc-700">';
                $html .= '<div class="hidden h-1.5 w-16 overflow-hidden rounded-full bg-zinc-100 md:block" aria-hidden="true"><div class="h-full bg-indigo-600" style="width: ' . min($usage, 100) . '%"></div></div>';
                $html .= '<span class="w-14 text-right">' . number_format($usage, 1) . '%</span></div></td>';
                $html .= '</tr>';

                foreach ($dir->children as $child) {
                    $html .= $renderDirectorate($child, $depth + 1, $rowId);
                }

                return $html;
            };

            $globalPlanned = 0;
            $globalApproved = 0;
            $unitRows = '';
            foreach ($rootDirectorates as $root) {
                $globalPlanned += ($unitTotals[$root->id] ?? $emptyTotal)['planned'];
                $globalApproved += ($unitTotals[$root->id] ?? $emptyTotal)['approved'];
                $unitRows .= $renderDirectorate($root);
            }
        @endphp

        <x-ui.card :title="__('Hierarchical Performance Breakdown')" :description="__('Organizational structure with value rollups')" padding="p-0">
            @if($unitRows === '')
                <x-ui.empty-state :title="__('Nothing to report yet')" :text="__('Figures appear here once activities are planned for this year.')" />
            @else
                <x-ui.table :headers="[__('Unit / Directorate Structure'), __('Planned resources ($)'), __('Approved Budget ($)'), __('Utilization (%)')]" :right="[1, 2, 3]" class="text-sm">
                    {!! $unitRows !!}
                    <tr class="border-t-2 border-zinc-300 bg-zinc-50 font-semibold text-zinc-900">
                        <td class="px-5 py-3 text-sm">{{ __('Consolidated Institutional Totals') }}</td>
                        <td class="px-5 py-3 num text-sm">$ {{ number_format($globalPlanned, 2) }}</td>
                        <td class="px-5 py-3 num text-sm">$ {{ number_format($globalApproved, 2) }}</td>
                        <td class="px-5 py-3 num text-sm">{{ number_format($globalPlanned > 0 ? ($globalApproved / $globalPlanned) * 100 : 0, 1) }}%</td>
                    </tr>
                </x-ui.table>
            @endif
        </x-ui.card>

        <x-ui.card :title="__('Detailed Programmatic Breakdown')" :description="__('Budget allocation by Program and Economic Category')" padding="p-0">
            @if(count($programmes) === 0)
                <x-ui.empty-state :title="__('Nothing to report yet')" :text="__('Figures appear here once activities are planned for this year.')" />
            @else
                <x-ui.table :headers="[__('Hierarchical Breakdown'), __('Status / Indicators'), __('Planned ($)'), __('Approved ($)'), __('Difference ($)')]" :right="[2, 3, 4]" class="text-sm">
                    @foreach($programmes as $programRow)
                        @php
                            $program = $programRow['program'];
                            $progDiff = $programRow['planned'] - $programRow['approved'];
                            $progRowId = 'prog-' . $program->id;
                        @endphp

                        <tr id="{{ $progRowId }}" class="tree-row bg-zinc-50/60 hover:bg-zinc-50">
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-2">
                                    <button type="button" aria-label="{{ __('Expand or collapse') }}" onclick="toggleTree('{{ $progRowId }}')" class="tree-toggle-btn -ml-1 rounded p-1 text-zinc-500 hover:bg-zinc-200 hover:text-zinc-900">{!! $chevron !!}</button>
                                    <div>
                                        <div class="text-sm font-semibold text-zinc-900">{{ $program->oge_code }} - {{ $program->name }}</div>
                                        <div class="text-xs text-zinc-500">{{ __('Institutional Program') }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-3 text-xs font-medium text-zinc-500">{{ __('Program Total') }}</td>
                            <td class="px-5 py-3 num text-sm font-semibold text-zinc-900">$ {{ number_format($programRow['planned'], 2) }}</td>
                            <td class="px-5 py-3 num text-sm font-semibold text-zinc-900">$ {{ number_format($programRow['approved'], 2) }}</td>
                            <td class="px-5 py-3 num text-sm font-semibold {{ $progDiff > 0 ? 'text-rose-700' : 'text-zinc-900' }}">$ {{ number_format($progDiff, 2) }}</td>
                        </tr>

                        {{-- the programme's subprogrammes: a summary of how its total is divided --}}
                        @foreach($subprogrammes[$program->id] ?? [] as $sub)
                            <tr data-parent="{{ $progRowId }}" class="tree-row hidden bg-zinc-50/30 hover:bg-zinc-50 print:table-row">
                                <td class="py-2 pr-5" style="padding-left: 3rem;">
                                    <div class="text-sm text-zinc-800"><span class="font-mono font-semibold">{{ $sub['code'] }}</span> · {{ $sub['name'] }}</div>
                                    <div class="text-xs text-zinc-500">{{ __('Subprogram') }}</div>
                                </td>
                                <td class="px-5 py-2 text-xs text-zinc-500">{{ __('Subprogram Total') }}</td>
                                <td class="px-5 py-2 num text-sm text-zinc-700">$ {{ number_format($sub['planned'], 2) }}</td>
                                <td class="px-5 py-2 num text-sm text-zinc-900">$ {{ number_format($sub['approved'], 2) }}</td>
                                <td class="px-5 py-2 num text-sm {{ $sub['planned'] - $sub['approved'] > 0 ? 'text-rose-700' : 'text-zinc-700' }}">$ {{ number_format($sub['planned'] - $sub['approved'], 2) }}</td>
                            </tr>
                        @endforeach

                        @foreach($programRow['classes'] as $classId => $classRow)
                            @php
                                $classification = $classRow['classification'];
                                $classDiff = $classRow['planned'] - $classRow['approved'];
                                $classRowId = $progRowId . '-class-' . $classId;
                            @endphp

                            <tr id="{{ $classRowId }}" data-parent="{{ $progRowId }}"
                                @unless($full) data-activities-url="{{ route('reports.paa.activities', ['year' => $selectedYear, 'program' => $program->id, 'class' => $classId, 'parent' => $classRowId]) }}" @endunless
                                class="tree-row hidden hover:bg-zinc-50 print:table-row">
                                <td class="py-3 pr-5" style="padding-left: 3rem;">
                                    <div class="flex items-center gap-2">
                                        <button type="button" aria-label="{{ __('Expand or collapse') }}" onclick="toggleTree('{{ $classRowId }}')" class="tree-toggle-btn -ml-1 rounded p-1 text-zinc-500 hover:bg-zinc-200 hover:text-zinc-900">{!! $chevron !!}</button>
                                        <div>
                                            <div class="text-sm font-medium text-zinc-800">{{ $classification->code }} {{ $classification->name }}</div>
                                            <div class="text-xs text-zinc-500">{{ __('Economic Category') }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-3 text-xs text-zinc-500">{{ __('Category Total') }}</td>
                                <td class="px-5 py-3 num text-sm text-zinc-700">$ {{ number_format($classRow['planned'], 2) }}</td>
                                <td class="px-5 py-3 num text-sm text-zinc-900">$ {{ number_format($classRow['approved'], 2) }}</td>
                                <td class="px-5 py-3 num text-sm {{ $classDiff > 0 ? 'text-rose-700' : 'text-zinc-700' }}">$ {{ number_format($classDiff, 2) }}</td>
                            </tr>

                            @if($full)
                                @include('reports.partials.paa-activity-rows', ['rows' => $classRow['activities'], 'classRowId' => $classRowId])
                            @endif
                        @endforeach
                    @endforeach
                </x-ui.table>
            @endif
        </x-ui.card>

        {{-- Signatures: printed pages only --}}
        <div class="hidden print:grid mt-16 grid-cols-2 gap-16 pt-8">
            <div class="text-center space-y-16">
                <div class="text-xs font-semibold text-zinc-500">{{ __('Verified By') }}</div>
                <div class="border-t border-zinc-900 pt-3">
                    <div class="text-sm font-semibold">{{ __('Director General of Planning') }}</div>
                    <div class="text-xs text-zinc-500 mt-1">{{ \App\Models\SystemSetting::getLocalized('org_name', config('app.name')) }}</div>
                </div>
            </div>
            <div class="text-center space-y-16">
                <div class="text-xs font-semibold text-zinc-500">{{ __('Authorized By') }}</div>
                <div class="border-t border-zinc-900 pt-3">
                    <div class="text-sm font-semibold">{{ __('Minister\'s Approval') }}</div>
                    <div class="text-xs text-zinc-500 mt-1">{{ __('Government Context Seal') }}</div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const fullReport = @json($full);

        // Export and print need every activity on the page: reopen the report in full mode and do it there.
        function withFullReport(action) {
            if (fullReport) return action === 'print' ? window.print() : exportToCSV();
            const url = new URL(window.location.href);
            url.searchParams.set('full', '1');
            url.searchParams.set('do', action);
            window.location.href = url.toString();
        }

        document.addEventListener('DOMContentLoaded', () => {
            const action = new URLSearchParams(window.location.search).get('do');
            if (fullReport && action) setTimeout(() => action === 'print' ? window.print() : exportToCSV(), 300);
        });

        // A category's activities are fetched the first time it is expanded.
        async function loadActivities(row) {
            if (!row.dataset.activitiesUrl || row.dataset.loaded) return;
            row.dataset.loaded = 'loading';
            try {
                const response = await fetch(row.dataset.activitiesUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                if (!response.ok) throw new Error(response.status);
                row.insertAdjacentHTML('afterend', await response.text());
                row.dataset.loaded = '1';
            } catch (e) {
                delete row.dataset.loaded;
            }
        }

        async function toggleTree(rowId) {
            const row = document.getElementById(rowId);
            const button = row.querySelector('.tree-toggle-btn svg');
            const isExpanded = button.classList.contains('rotate-90');
            if (!isExpanded) await loadActivities(row);

            // Toggle chevron rotation
            button.classList.toggle('rotate-90');

            // Find all children (immediate and deep)
            const toggleChildren = (parentId, show) => {
                const children = document.querySelectorAll(`tr[data-parent="${parentId}"]`);
                children.forEach(child => {
                    if (show) {
                        child.classList.remove('hidden');
                    } else {
                        child.classList.add('hidden');
                        // Also hide its children and reset their buttons if collapsing
                        const childButton = child.querySelector('.tree-toggle-btn svg');
                        if (childButton) childButton.classList.remove('rotate-90');
                        toggleChildren(child.id, false);
                    }
                });
            };

            toggleChildren(rowId, !isExpanded);
        }

        function exportToCSV() {
            let csv = [];
            const rows = document.querySelectorAll("table tr");

            for (const row of rows) {
                const cols = row.querySelectorAll("td, th");
                const rowData = [];
                for (const col of cols) {
                    let text = col.innerText.replace(/"/g, '""').replace(/\s+/g, ' ').trim();
                    rowData.push('"' + text + '"');
                }
                csv.push(rowData.join(","));
            }

            const csvFile = new Blob([csv.join("\n")], { type: "text/csv" });
            const downloadLink = document.createElement("a");
            downloadLink.download = "relatorio_paa_" + new Date().toISOString().slice(0, 10) + ".csv";
            downloadLink.href = window.URL.createObjectURL(csvFile);
            downloadLink.style.display = "none";
            document.body.appendChild(downloadLink);
            downloadLink.click();
        }
    </script>
</x-app-layout>