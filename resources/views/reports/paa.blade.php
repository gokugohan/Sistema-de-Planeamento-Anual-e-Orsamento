@php
    $showDrafts = App\Models\SystemSetting::get('show_draft_planning_in_reports', false);
    $reportYears = App\Models\Activity::distinct()->pluck('fiscal_year')
        ->merge(App\Models\FiscalEnvelope::when(!$showDrafts, fn($q) => $q->where('status', '!=', 'Draft'))->pluck('fiscal_year'))
        ->unique()
        ->sortDesc();

    if ($reportYears->isEmpty()) {
        $reportYears = collect([App\Models\FiscalEnvelope::getEffectiveYear()]);
    }

    $selectedYear = (int) request('year', App\Models\FiscalEnvelope::getEffectiveYear());
@endphp

<x-app-layout>
    <div class="space-y-10 py-6 animate-in fade-in duration-700">
        {{-- Analytic Header --}}
        <div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-6 pb-2 print:hidden">
            <div>
                <h1 class="text-3xl font-black text-slate-900 tracking-tight leading-none">
                    {{ __('Annual Performance Report') }}
                </h1>
                <div class="flex items-center gap-4 mt-4">
                    <p class="text-slate-400 font-bold uppercase tracking-[0.2em] text-[10px]">
                        {{ __('Analytical Audit for') }}
                    </p>
                    <form method="GET" action="{{ route('reports.paa') }}" class="flex items-center gap-2">
                        <select name="year" onchange="this.form.submit()"
                            class="bg-slate-100 border-none rounded-lg text-[10px] font-black uppercase tracking-widest py-1 px-3 focus:ring-2 focus:ring-indigo-500/20 cursor-pointer">
                            @foreach($reportYears as $yr)
                                <option value="{{ $yr }}" {{ (string) $selectedYear === (string) $yr ? 'selected' : '' }}>
                                    {{ $yr }}
                                </option>
                            @endforeach
                        </select>
                    </form>
                </div>
            </div>
            <div class="flex gap-3">
                <x-ui.button variant="secondary" size="md" onclick="exportToCSV()"
                    class="!rounded-xl border border-slate-200">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                        class="mr-2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                        <polyline points="7 10 12 15 17 10" />
                        <line x1="12" x2="12" y1="15" y2="3" />
                    </svg>
                    {{ __('Export Data') }}
                </x-ui.button>
                <x-ui.button variant="black" shadow="lg" onclick="window.print()" class="!rounded-xl px-8">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"
                        class="mr-2">
                        <polyline points="6 9 6 2 18 2 18 9" />
                        <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2" />
                        <rect width="12" height="8" x="6" y="14" />
                    </svg>
                    {{ __('Print Report') }}
                </x-ui.button>
            </div>
        </div>

        {{-- Main Report Body --}}
        <div class="print:py-0">
            <div class="space-y-12 print:px-0 mx-auto">
                <!-- Official Header for Print -->
                <div class="hidden print:block text-center mb-12 border-b-4 border-slate-900 pb-10">
                    <div class="text-[10px] font-black uppercase tracking-[0.4em] text-slate-400 mb-4">República
                        Democrática de Timor-Leste</div>
                    <h1 class="text-3xl font-black uppercase tracking-tighter text-slate-900 leading-none">
                        {{ __('Ministry of State Administration') }}
                    </h1>
                    <div
                        class="mt-8 px-6 py-2 bg-slate-900 text-white inline-block text-xs font-black uppercase tracking-[0.2em] italic">
                        {{ __('Annual Activity Plan Report (PAA)') }} — {{ $selectedYear }}
                    </div>
                </div>

                @php
                    $activeEnvelope = App\Models\FiscalEnvelope::where('fiscal_year', $selectedYear)->first();
                @endphp

                @if($activeEnvelope)
                                <!-- Fiscal Context Summary (Standardized Hero) -->
                                <div
                                    class="premium-card rounded-[2.5rem] bg-white p-10 border border-slate-100 shadow-sm relative overflow-hidden print:border-2 print:border-slate-900 print:shadow-none">
                                    <div
                                        class="absolute top-0 right-0 w-96 h-96 bg-indigo-500/5 rounded-full blur-[100px] -mr-48 -mt-48 print:hidden">
                                    </div>

                                    <div class="relative z-10">
                                        <div
                                            class="flex flex-col md:flex-row justify-between items-start md:items-center gap-8 border-b border-slate-50 pb-10 mb-10 print:border-slate-200">
                                            <div>
                                                <h3 class="text-[10px] font-black text-indigo-500 uppercase tracking-[0.2em] mb-3">
                                                    {{ __('Fiscal Context Authority') }}
                                                </h3>
                                                <div class="flex items-baseline gap-4">
                                                    <span
                                                        class="text-5xl font-black text-slate-900 tracking-tighter leading-none italic">{{ $selectedYear }}</span>
                                                    <span
                                                        class="text-xl font-bold text-slate-400 tracking-tight uppercase tracking-[0.1em]">{{ $activeEnvelope->reference_number }}</span>
                                                </div>
                                            </div>
                                            <div class="flex gap-2 print:hidden">
                                                @if($activeEnvelope->status === 'Approved')
                                                    <span
                                                        class="px-4 py-1.5 bg-emerald-100 text-emerald-700 text-[10px] font-black uppercase tracking-widest rounded-xl">{{ __('Final Approved') }}</span>
                                                @else
                                                    <span
                                                        class="px-4 py-1.5 bg-amber-100 text-amber-700 text-[10px] font-black uppercase tracking-widest rounded-xl">{{ __($activeEnvelope->status) }}</span>
                                                @endif
                                                @if($activeEnvelope->isActiveByDate())
                                                    <span
                                                        class="px-4 py-1.5 bg-indigo-600 text-white text-[10px] font-black uppercase tracking-widest rounded-xl shadow-lg shadow-indigo-600/20">Active
                                                        Authority</span>
                                                @endif
                                            </div>
                                        </div>

                                        <div class="grid grid-cols-1 md:grid-cols-3 gap-12">
                                            <div>
                                                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2">
                                                    {{ __('Global OGE Ceiling') }}
                                                </p>
                                                <p class="text-4xl font-black text-slate-900 leading-none tracking-tighter">$
                                                    {{ number_format($activeEnvelope->total_amount, 2) }}
                                                </p>
                                            </div>

                    @php
                        $allProposals = App\Models\LineItem::whereHas('activity', fn($q) => $q->where('fiscal_year', $selectedYear))
                            ->with(['economicClassification', 'adjustedBudget'])
                            ->get();
                        $totalDistributed = $allProposals->sum(fn($p) => optional($p->adjustedBudget)->approved_amount ?? 0);
                    @endphp

                                            <div
                                                class="md:col-span-2 flex flex-col md:flex-row gap-10 border-l border-slate-100 pl-10 print:border-slate-200">
                                                <div class="flex-1">
                                                    <p class="text-[9px] font-black text-indigo-400 uppercase tracking-widest mb-2">
                                                        {{ __('Distributed to Activities') }}
                                                    </p>
                                                    <p class="text-2xl font-black text-indigo-600 leading-none">$
                                                        {{ number_format($totalDistributed, 2) }}
                                                    </p>
                                                    <div class="mt-4 h-1 bg-slate-50 rounded-full overflow-hidden">
                                                        <div class="h-full bg-indigo-600 transition-all duration-1000"
                                                            style="width: {{ $activeEnvelope->total_amount > 0 ? ($totalDistributed / $activeEnvelope->total_amount) * 100 : 0 }}%">
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="w-px bg-slate-50 md:block hidden print:bg-slate-200"></div>
                                                <div class="flex-1 text-right md:text-left">
                                                    <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2">
                                                        {{ __('Utilization Rate') }}
                                                    </p>
                                                    <p class="text-2xl font-black text-slate-900 leading-none">
                                                        {{ number_format(($activeEnvelope->total_amount > 0 ? ($totalDistributed / $activeEnvelope->total_amount) * 100 : 0), 1) }}%
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                @endif

                {{-- Departmental Distribution Summary (Tree View) --}}
                <div
                    class="bg-white rounded-[2rem] shadow-sm border border-slate-100 overflow-hidden print:border-2 print:border-slate-900">
                    <div class="px-8 py-6 border-b border-slate-50 bg-slate-50/50 flex items-center justify-between">
                        <div>
                            <h4 class="text-xs font-black uppercase tracking-widest text-slate-900">
                                {{ __('Hierarchical Performance Breakdown') }}
                            </h4>
                            <p class="text-[10px] font-bold text-slate-400 uppercase mt-1">
                                {{ __('Organizational structure with value rollups') }}
                            </p>
                        </div>
                        <div
                            class="px-3 py-1 bg-slate-900 text-white text-[8px] font-black rounded uppercase tracking-widest">
                            {{ __('Tree View') }}
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y-2 divide-slate-100 text-sm">
                            <thead>
                                <tr class="bg-white border-b border-slate-100">
                                    <th
                                        class="px-8 py-5 text-left text-[9px] font-black uppercase tracking-widest text-slate-400">
                                        {{ __('Unit / Directorate Structure') }}
                                    </th>
                                    <th
                                        class="px-8 py-5 text-right text-[9px] font-black uppercase tracking-widest text-slate-400">
                                        {{ __('Planned resources ($)') }}
                                    </th>
                                    <th
                                        class="px-8 py-5 text-right text-[9px] font-black uppercase tracking-widest text-slate-400">
                                        {{ __('Approved Budget ($)') }}
                                    </th>
                                    <th
                                        class="px-8 py-5 text-right text-[9px] font-black uppercase tracking-widest text-slate-400">
                                        {{ __('Utilization (%)') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50 bg-white">
                                @php
                                    $rootDirectorates = App\Models\Directorate::whereNull('parent_id')->get();

                                    $renderDirectorate = function ($dir, $depth = 0, $parentId = null) use (&$renderDirectorate, $selectedYear) {
                                        $planned = $dir->getTotalEstimatedRollup($selectedYear);
                                        $approved = $dir->getTotalApprovedRollup($selectedYear);

                                        if ($planned <= 0 && $approved <= 0)
                                            return '';

                                        $usage = $planned > 0 ? ($approved / $planned) * 100 : 0;
                                        $isRoot = $depth === 0;
                                        $hasChildren = $dir->children->isNotEmpty();

                                        $rowId = 'dir-' . $dir->id;
                                        $parentAttr = $parentId ? 'data-parent="' . $parentId . '"' : '';

                                        $html = '<tr id="' . $rowId . '" ' . $parentAttr . ' class="tree-row hover:bg-slate-50/50 transition-colors border-l-4 ' . ($isRoot ? 'border-l-indigo-500 bg-slate-50/20' : 'border-l-transparent') . ' ' . ($depth > 0 ? 'hidden print:table-row' : '') . '">';

                                        // Unit name with indentation and folder icons
                                        $html .= '<td class="px-8 py-4 whitespace-nowrap" style="padding-left: ' . (2 + ($depth * 2.5)) . 'rem;">';
                                        $html .= '<div class="flex items-center gap-3">';

                                        // Toggle Button (Chevron)
                                        if ($hasChildren) {
                                            $html .= '<button onclick="toggleTree(\'' . $rowId . '\')" class="p-1 hover:bg-slate-200 rounded-md transition-all tree-toggle-btn">';
                                            $html .= '<svg class="transition-transform duration-200" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>';
                                            $html .= '</button>';
                                        } else {
                                            $html .= '<div class="w-6"></div>'; // Placeholder for alignment
                                        }

                                        if ($isRoot) {
                                            $html .= '<div class="h-8 w-8 rounded-lg bg-indigo-600 flex items-center justify-center text-white shrink-0">';
                                            $html .= '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 22h16a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v16a2 2 0 0 1-2 2Zm0 0a2 2 0 0 1-2-2v-9c0-1.1.9-2 2-2h2"/><path d="M18 14h-8"/><path d="M15 18h-5"/><path d="M10 6h8v4h-8V6Z"/></svg>';
                                            $html .= '</div>';
                                        } else {
                                            $html .= '<div class="text-slate-300 shrink-0">';
                                            $html .= '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>';
                                            $html .= '</div>';
                                        }

                                        $html .= '<div>';
                                        $html .= '<div class="font-bold text-gray-900 lowercase first-letter:uppercase ' . ($isRoot ? 'text-slate-900 border-b border-indigo-100' : 'text-slate-700') . '">' . e($dir->name) . '</div>';
                                        $html .= '<div class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mt-0.5">' . e($dir->code) . ' (' . __($dir->type) . ')</div>';
                                        $html .= '</div>';
                                        $html .= '</div>';
                                        $html .= '</td>';

                                        // Values
                                        $html .= '<td class="px-8 py-4 text-right text-[11px] font-bold text-slate-600">$ ' . number_format($planned, 2) . '</td>';
                                        $html .= '<td class="px-8 py-4 text-right text-[11px] font-black ' . ($usage > 100 ? 'text-rose-600' : ($isRoot ? 'text-indigo-600' : 'text-slate-900')) . '">$ ' . number_format($approved, 2) . '</td>';

                                        // Utilization
                                        $html .= '<td class="px-8 py-4 text-right">';
                                        $html .= '<div class="flex items-center justify-end gap-3 font-black text-[10px]">';
                                        $html .= '<div class="w-12 h-1 bg-slate-100 rounded-full overflow-hidden hidden md:block">';
                                        $html .= '<div class="h-full bg-indigo-600" style="width: ' . min($usage, 100) . '%"></div>';
                                        $html .= '</div>';
                                        $html .= '<span>' . number_format($usage, 1) . '%</span>';
                                        $html .= '</div>';
                                        $html .= '</td>';

                                        $html .= '</tr>';

                                        // Recursively render children
                                        foreach ($dir->children as $child) {
                                            $html .= $renderDirectorate($child, $depth + 1, $rowId);
                                        }

                                        return $html;
                                    };

                                    $globalPlanned = 0;
                                    $globalApproved = 0;
                                @endphp

                                @foreach($rootDirectorates as $root)
                                    @php
                                        $globalPlanned += $root->getTotalEstimatedRollup($selectedYear);
                                        $globalApproved += $root->getTotalApprovedRollup($selectedYear);
                                    @endphp
                                    {!! $renderDirectorate($root) !!}
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="bg-slate-900 text-white border-t-4 border-slate-700">
                                    <td class="px-8 py-6">
                                        <div class="flex items-center gap-3">
                                            <div
                                                class="h-10 w-10 rounded-xl bg-white/10 flex items-center justify-center text-white shrink-0">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20"
                                                    viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                    stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M12 2v20" />
                                                    <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
                                                </svg>
                                            </div>
                                            <div class="text-[10px] font-black uppercase tracking-[0.2em]">
                                                {{ __('Consolidated Institutional Totals') }}
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-8 py-6 text-right text-[13px] font-black">$
                                        {{ number_format($globalPlanned, 2) }}
                                    </td>
                                    <td class="px-8 py-6 text-right text-[13px] font-black">$
                                        {{ number_format($globalApproved, 2) }}
                                    </td>
                                    <td class="px-8 py-6 text-right text-[13px] font-black">
                                        {{ number_format($globalPlanned > 0 ? ($globalApproved / $globalPlanned) * 100 : 0, 1) }}%
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                {{-- Table Breakdown (ProgramTree Tiered) --}}
                <div
                    class="bg-white rounded-[2rem] shadow-sm border border-slate-100 overflow-hidden print:border-2 print:border-slate-900">
                    <div class="px-8 py-6 border-b border-slate-50 bg-slate-50/50 flex items-center justify-between">
                        <div>
                            <h4 class="text-xs font-black uppercase tracking-widest text-slate-900">
                                {{ __('Detailed Programmatic Breakdown') }}</h4>
                            <p class="text-[10px] font-bold text-slate-400 uppercase mt-1">
                                {{ __('Budget allocation by Program and Economic Category') }}</p>
                        </div>
                        <div
                            class="px-3 py-1 bg-slate-900 text-white text-[8px] font-black rounded uppercase tracking-widest">
                            {{ __('Tree View') }}</div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y-2 divide-slate-100 text-sm">
                            <thead>
                                <tr class="bg-white border-b border-slate-100">
                                    <!-- <th class="px-8 py-5 text-left text-[9px] font-black uppercase tracking-widest text-slate-400">{{ __('Code') }}</th> -->
                                    <th
                                        class="px-8 py-5 text-left text-[9px] font-black uppercase tracking-widest text-slate-400 w-1/3">
                                        {{ __('Hierarchical Breakdown') }}</th>
                                    <th
                                        class="px-8 py-5 text-left text-[9px] font-black uppercase tracking-widest text-slate-400">
                                        {{ __('Status / Indicators') }}</th>
                                    <th
                                        class="px-8 py-5 text-right text-[9px] font-black uppercase tracking-widest text-slate-400">
                                        {{ __('Planned ($)') }}</th>
                                    <th
                                        class="px-8 py-5 text-right text-[9px] font-black uppercase tracking-widest text-slate-400">
                                        {{ __('Approved ($)') }}</th>
                                    <th
                                        class="px-8 py-5 text-right text-[9px] font-black uppercase tracking-widest text-slate-400">
                                        {{ __('Difference ($)') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50 bg-white">
                                @php
                                    $activeYear = $selectedYear;
                                    $programs = App\Models\Program::with([
                                        'activities' => function ($q) use ($activeYear) {
                                            $q->where('fiscal_year', $activeYear)->with(['indicators', 'lineItems.economicClassification', 'lineItems.adjustedBudget']);
                                        }
                                    ])->get();
                                @endphp

                                @foreach($programs as $program)
                                    @if($program->activities->isEmpty()) @continue @endif

                                    @php
                                        $progPlanned = $program->activities->flatMap->lineItems->sum('estimated_amount');
                                        $progApproved = $program->activities->flatMap->lineItems->map->adjustedBudget->sum('approved_amount');
                                        $progDiff = $progPlanned - $progApproved;
                                        $progRowId = 'prog-' . $program->id;

                                        // Group all line items in this program by classification
                                        $itemsByClass = $program->activities->flatMap->lineItems->groupBy('economic_classification_id');
                                    @endphp

                                    {{-- Level 1: Program (Matches Root Unit style) --}}
                                    <tr id="{{ $progRowId }}"
                                        class="tree-row hover:bg-slate-50/50 transition-colors border-l-4 border-l-indigo-500 bg-slate-50/20">
                                        <td class="px-8 py-4 font-black text-slate-900 border-none">
                                            <div class="flex items-center gap-3">
                                                <button onclick="toggleTree('{{ $progRowId }}')"
                                                    class="p-1 hover:bg-slate-200 rounded-md transition-all tree-toggle-btn">
                                                    <svg class="transition-transform duration-200"
                                                        xmlns="http://www.w3.org/2000/svg" width="12" height="12"
                                                        viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                        stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                                        <path d="m9 18 6-6-6-6" />
                                                    </svg>
                                                </button>
                                                <div
                                                    class="h-8 w-8 rounded-lg bg-indigo-600 flex items-center justify-center text-white shrink-0">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                                                        viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                        stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                        <path
                                                            d="M4 22h16a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v16a2 2 0 0 1-2 2Zm0 0a2 2 0 0 1-2-2v-9c0-1.1.9-2 2-2h2" />
                                                        <path d="M18 14h-8" />
                                                        <path d="M15 18h-5" />
                                                        <path d="M10 6h8v4h-8V6Z" />
                                                    </svg>
                                                </div>
                                                <div>
                                                    <div
                                                        class="font-bold text-gray-900 lowercase first-letter:uppercase border-b border-indigo-100 uppercase tracking-tight">
                                                        {{ $program->oge_code }} - {{ $program->name }}</div>
                                                    <div
                                                        class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mt-0.5">
                                                        {{ __('Institutional Program') }}</div>
                                                </div>
                                            </div>
                                        </td>
                                        <td
                                            class="px-8 py-4 border-none text-[9px] font-black text-indigo-400 uppercase tracking-widest">
                                            {{ __('Program Total') }}</td>
                                        <td class="px-8 py-4 text-right font-black border-none text-slate-900">$
                                            {{ number_format($progPlanned, 2) }}</td>
                                        <td class="px-8 py-4 text-right font-black border-none text-indigo-600">$
                                            {{ number_format($progApproved, 2) }}</td>
                                        <td
                                            class="px-8 py-4 text-right font-black border-none {{ $progDiff > 0 ? 'text-rose-600' : 'text-slate-900' }}">
                                            $ {{ number_format($progDiff, 2) }}
                                        </td>
                                    </tr>

                                    @foreach($itemsByClass as $classId => $lineItems)
                                        @php
                                            $classification = $lineItems->first()->economicClassification;
                                            $classPlanned = $lineItems->sum('estimated_amount');
                                            $classApproved = $lineItems->map->adjustedBudget->sum('approved_amount');
                                            $classDiff = $classPlanned - $classApproved;
                                            $classRowId = $progRowId . '-class-' . $classId;

                                            // Activities that have items in this classification
                                            $actIdsInClass = $lineItems->pluck('activity_id')->unique();
                                            $activitiesInClass = $program->activities->whereIn('id', $actIdsInClass);
                                        @endphp

                                        {{-- Level 2: Economic Classification (Matches Level 2 Unit style) --}}
                                        <tr id="{{ $classRowId }}" data-parent="{{ $progRowId }}"
                                            class="tree-row hover:bg-slate-50/50 transition-colors border-l-4 border-l-transparent hidden print:table-row">
                                            <td class="px-8 py-3 font-bold text-slate-700" style="padding-left: 4.5rem;">
                                                <div class="flex items-center gap-3">
                                                    <button onclick="toggleTree('{{ $classRowId }}')"
                                                        class="p-1 hover:bg-slate-200 rounded-md transition-all tree-toggle-btn">
                                                        <svg class="transition-transform duration-200"
                                                            xmlns="http://www.w3.org/2000/svg" width="10" height="10"
                                                            viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                            stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                                            <path d="m9 18 6-6-6-6" />
                                                        </svg>
                                                    </button>
                                                    <div class="text-slate-300 shrink-0">
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12"
                                                            viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                            stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                                            <path
                                                                d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z" />
                                                        </svg>
                                                    </div>
                                                    <div>
                                                        <div class="text-[11px] font-black text-slate-700">
                                                            {{ $classification->code }} {{ $classification->name }}</div>
                                                        <div
                                                            class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mt-0.5">
                                                            {{ __('Economic Category') }}</div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-8 py-3 text-[9px] font-bold text-slate-300 uppercase tracking-widest">
                                                {{ __('Category Total') }}</td>
                                            <td class="px-8 py-3 text-right font-bold text-slate-600 bg-slate-50/30">$
                                                {{ number_format($classPlanned, 2) }}</td>
                                            <td class="px-8 py-3 text-right font-bold text-slate-900 bg-slate-50/30">$
                                                {{ number_format($classApproved, 2) }}</td>
                                            <td class="px-8 py-3 text-right font-bold text-slate-400 bg-slate-50/30">$
                                                {{ number_format($classDiff, 2) }}</td>
                                        </tr>

                                        @foreach($activitiesInClass as $activity)
                                            @php
                                                // Sum only the line items of THIS activity that match THIS classification
                                                $actItemsInClass = $lineItems->where('activity_id', $activity->id);
                                                $actPlanned = $actItemsInClass->sum('estimated_amount');
                                                $actApproved = $actItemsInClass->map->adjustedBudget->sum('approved_amount');
                                                $actDiff = $actPlanned - $actApproved;
                                            @endphp

                                            {{-- Level 3: Activity (Matches Level 3 Unit style) --}}
                                            <tr data-parent="{{ $classRowId }}"
                                                class="tree-row hover:bg-slate-50/30 transition-colors hidden print:table-row border-l-4 border-l-transparent">
                                                <td class="px-8 py-4 border-none" style="padding-left: 7.5rem;">
                                                    <div class="flex items-start gap-3">
                                                        <div class="mt-1 text-slate-200">
                                                            <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"
                                                                viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                                stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                                                <line x1="12" y1="5" x2="12" y2="19" />
                                                                <line x1="5" y1="12" x2="19" y2="12" />
                                                            </svg>
                                                        </div>
                                                        <div>
                                                            <div class="font-bold text-slate-800 leading-tight text-[11px]">
                                                                {{ $activity->description }}</div>
                                                            <div
                                                                class="text-[9px] font-black text-slate-400 uppercase tracking-widest mt-1">
                                                                <span
                                                                    class="text-indigo-500">{{ $activity->directorate->code ?? 'N/A' }}</span>
                                                                • {{ __('Priority') }}: {{ __($activity->priority) }}
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="px-8 py-4 text-[10px] text-slate-500 border-none">
                                                    <ul class="space-y-1.5 list-none">
                                                        @foreach($activity->indicators as $indicator)
                                                            <li class="flex items-start gap-2">
                                                                <div class="w-1 h-1 bg-indigo-300 rounded-full mt-1.5 shrink-0"></div>
                                                                <span>{{ $indicator->name }}: <b
                                                                        class="text-slate-700">{{ number_format($indicator->annual_target, 0) }}
                                                                        {{ $indicator->unit }}</b></span>
                                                            </li>
                                                        @endforeach
                                                    </ul>
                                                </td>
                                                <td class="px-8 py-4 text-right font-medium text-slate-500 border-none">$
                                                    {{ number_format($actPlanned, 2) }}</td>
                                                <td class="px-8 py-4 text-right font-bold text-emerald-600 border-none">$
                                                    {{ number_format($actApproved, 2) }}</td>
                                                <td
                                                    class="px-8 py-4 text-right font-bold border-none {{ $actDiff > 0 ? 'text-rose-600' : 'text-slate-400' }}">
                                                    $ {{ number_format($actDiff, 2) }}</td>
                                            </tr>
                                        @endforeach
                                    @endforeach
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- Signature Sections --}}
                <div class="hidden print:block mt-24 grid grid-cols-2 gap-24 pt-12">
                    <div class="text-center space-y-24">
                        <div class="text-[10px] font-black uppercase tracking-widest text-slate-400">
                            {{ __('Verified By') }}</div>
                        <div class="border-t-2 border-slate-900 pt-4">
                            <div class="text-xs font-black uppercase">{{ __('Director General of Planning') }}</div>
                            <div class="text-[10px] font-bold text-slate-400 mt-1 uppercase">
                                {{ __('Ministry of State Administration') }}</div>
                        </div>
                    </div>
                    <div class="text-center space-y-24">
                        <div class="text-[10px] font-black uppercase tracking-widest text-slate-400">
                            {{ __('Authorized By') }}</div>
                        <div class="border-t-2 border-slate-900 pt-4">
                            <div class="text-xs font-black uppercase">{{ __('Minister\'s Approval') }}</div>
                            <div class="text-[10px] font-bold text-slate-400 mt-1 uppercase">
                                {{ __('Government Context Seal') }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function toggleTree(rowId) {
            const row = document.getElementById(rowId);
            const button = row.querySelector('.tree-toggle-btn svg');
            const isExpanded = button.classList.contains('rotate-90');

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