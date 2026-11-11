<?php

use App\Models\Directorate;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\UnitWorkspace;
use App\Support\Branding;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * The organization chart: the units the person may see, drawn top-down under the institution, each box with its kind
 * and its head. An extra view of the structure the plan is built on (the Units page is where the work is).
 *
 * The top-level units stand in one row joined to the institution; the units below each of them hang in a column under
 * it, so the chart stays legible however many units an institution has. The autonomous bodies (indirect
 * administration) hang from a dashed line: supervised, not part of the hierarchy. The view can be zoomed, fitted to the
 * screen, dragged, folded by branch and searched; printing fits the whole chart on one landscape page. Optionally each
 * box shows how the unit's plan of the year is doing. A box opens the unit's workspace.
 */
new class extends Component {
    /** The kinds of unit that carry the line of command; the others at the top are staff (advice and support). */
    private const LINE = ['General Directorate', 'Directorate General', 'National Directorate', 'Regional Office'];

    /** Show each unit's plan of the year (its light and number of activities) */
    #[Url(as: 'plan')]
    public bool $withPlan = false;

    public function mount(): void
    {
        $u = auth()->user();
        abort_unless($u->isAdministrator() || $u->can('view_all_paa') || $u->isDirector(), 403);
    }

    public function with(): array
    {
        $year = \App\Support\FiscalYear::current();
        $rows = collect(app(UnitWorkspace::class)->tree(auth()->user(), $year));
        $units = Directorate::whereIn('id', $rows->pluck('id'))->get(['id', 'type', 'administration', 'legal_form', 'director_name'])->keyBy('id');

        // who heads each unit: the people with the Director role placed in it, else the name on the unit's record
        $heads = User::role('Director')->whereIn('directorate_id', $rows->pluck('id'))->whereNull('deactivated_at')
            ->orderBy('name')->get(['id', 'name', 'directorate_id'])->groupBy('directorate_id');

        $nodes = $rows->map(function ($r) use ($units, $heads) {
            $u = $units[$r['id']] ?? null;
            $head = ($heads[$r['id']] ?? collect())->pluck('name')->implode(', ') ?: (string) $u?->director_name;

            return $r + [
                'type' => $u?->type,
                'autonomous' => $u?->isAutonomous() ?? false,
                'legal_form' => $u?->legal_form,
                'title' => $u ? $u->leaderTitle() : __('Head'),
                'head' => $head,
                'initials' => $head !== '' ? collect(preg_split('/\s+/', trim(explode(',', $head)[0])))->filter()->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->implode('') : '',
                'total' => (int) ($r['counts']['total'] ?? 0),
            ];
        });

        // the top of what the person sees (a head sees their own unit as the top), then each unit's children
        $ids = $nodes->pluck('id')->all();
        $roots = $nodes->filter(fn ($n) => ! $n['parent_id'] || ! in_array($n['parent_id'], $ids, true))->values();
        $children = $nodes->filter(fn ($n) => $n['parent_id'] && in_array($n['parent_id'], $ids, true))->groupBy('parent_id');

        return [
            'year' => $year,
            'organization' => SystemSetting::getLocalized('org_name', config('app.name')),
            'abbr' => SystemSetting::getLocalized('org_abbr', ''),
            'logo' => Branding::logoDataUri(),
            // staff and line: the cabinets, support units and offices of the top stand in a block on the trunk; the
            // directorates carry the hierarchy in the row below (all of them in the row when there are no directorates)
            'staff' => ($line = $roots->where('autonomous', false)->filter(fn ($n) => in_array($n['type'], self::LINE, true)))->isEmpty()
                ? collect() : $roots->where('autonomous', false)->reject(fn ($n) => in_array($n['type'], self::LINE, true))->values(),
            'own' => $line->isEmpty() ? $roots->where('autonomous', false)->values() : $line->values(),
            'bodies' => $roots->where('autonomous', true)->values(),
            'children' => $children,
            'count' => $nodes->count(),
        ];
    }
}; ?>

@php
    $light = ['none' => ['bg-zinc-100 text-zinc-600', __('No activity yet')], 'ok' => ['bg-emerald-50 text-emerald-800', __('All approved')], 'work' => ['bg-amber-50 text-amber-800', __('Work in progress')], 'alert' => ['bg-rose-50 text-rose-800', __('Needs correction')]];
    // the colour of a box's edge by the kind of unit (the legend says which)
    $accent = fn ($n) => $n['autonomous'] ? 'oc-a-body' : match ($n['type']) {
        'Cabinet' => 'oc-a-cab', 'General Directorate', 'Directorate General' => 'oc-a-dg', 'National Directorate' => 'oc-a-dn',
        'Department' => 'oc-a-dep', 'Regional Office' => 'oc-a-reg', default => 'oc-a-unit',
    };
    $typeWord = fn ($n) => $n['autonomous'] ? __('Autonomous body').($n['legal_form'] ? ' · '.$n['legal_form'] : '') : ($n['type'] ? __($n['type']) : '');
    $shared = ['children' => $children ?? collect(), 'withPlan' => $withPlan, 'light' => $light, 'accent' => $accent, 'typeWord' => $typeWord];
@endphp

<div class="space-y-4" x-data="orgChart()" x-init="init()">
    <x-ui.page-header :title="__('Organization chart')" :subtitle="trans_choice(':n unit of the institution, with its head.|:n units of the institution, each with its head.', $count, ['n' => $count])">
        <x-ui.button variant="secondary" href="{{ route('units.index') }}" wire:navigate>{{ __('Units') }}</x-ui.button>
        <x-ui.button variant="secondary" x-on:click="print()">{{ __('Print') }}</x-ui.button>
    </x-ui.page-header>

    @if($count === 0)
        <x-ui.empty-state :title="__('No units yet')" :text="__('The units of the institution are set in Management.')" />
    @else
        {{-- the controls --}}
        <div class="oc-tools flex flex-wrap items-center gap-2 rounded-lg border border-zinc-200 bg-white px-3 py-2 shadow-card">
            <label for="oc-find" class="sr-only">{{ __('Find a unit') }}</label>
            <input id="oc-find" type="search" x-model.debounce.200ms="find" x-on:keydown.enter.prevent="next()" class="flux-input !w-56 !py-1.5" placeholder="{{ __('Find a unit') }}">
            <span class="text-xs text-zinc-500" x-show="find.trim() !== ''" x-text="hits.length ? (cursor + 1) + ' / ' + hits.length : @js(__('No unit found'))"></span>
            <span class="mx-1 h-6 w-px bg-zinc-200" aria-hidden="true"></span>
            <div class="inline-flex overflow-hidden rounded-md border border-zinc-200" role="group" aria-label="{{ __('Zoom') }}">
                <button type="button" x-on:click="zoom(-0.1)" class="px-2.5 py-1.5 text-sm text-zinc-700 hover:bg-zinc-50" aria-label="{{ __('Zoom out') }}">−</button>
                <span class="min-w-[3.5rem] border-x border-zinc-200 px-2 py-1.5 text-center text-xs tabular-nums text-zinc-600" x-text="Math.round(scale * 100) + '%'"></span>
                <button type="button" x-on:click="zoom(0.1)" class="px-2.5 py-1.5 text-sm text-zinc-700 hover:bg-zinc-50" aria-label="{{ __('Zoom in') }}">+</button>
            </div>
            <button type="button" x-on:click="fit()" class="rounded-md border border-zinc-200 px-2.5 py-1.5 text-xs font-medium text-zinc-700 hover:bg-zinc-50">{{ __('Fit to screen') }}</button>
            <button type="button" x-on:click="setAll(true)" class="rounded-md border border-zinc-200 px-2.5 py-1.5 text-xs font-medium text-zinc-700 hover:bg-zinc-50">{{ __('Expand all') }}</button>
            <button type="button" x-on:click="setAll(false)" class="rounded-md border border-zinc-200 px-2.5 py-1.5 text-xs font-medium text-zinc-700 hover:bg-zinc-50">{{ __('Collapse all') }}</button>
            <label class="ml-auto inline-flex items-center gap-2 text-sm text-zinc-700">
                <input type="checkbox" wire:model.live="withPlan" class="rounded border-zinc-300 text-indigo-600">
                {{ __('Show the plan of :year', ['year' => $year]) }}
            </label>
        </div>

        {{-- the legend --}}
        <div class="oc-legend flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-zinc-600">
            @foreach(['oc-a-cab' => __('Cabinet'), 'oc-a-dg' => __('General Directorate'), 'oc-a-dn' => __('National Directorate'), 'oc-a-dep' => __('Department'), 'oc-a-unit' => __('Unit')] as $cls => $word)
                <span class="inline-flex items-center gap-1.5"><span class="oc-swatch {{ $cls }}"></span>{{ $word }}</span>
            @endforeach
            @if($bodies->isNotEmpty())
                <span class="inline-flex items-center gap-1.5"><span class="oc-swatch oc-swatch-dashed oc-a-body"></span>{{ __('Autonomous body (supervised)') }}</span>
            @endif
            <span class="text-zinc-400">· {{ __('Drag to move; open a box to see the unit.') }}</span>
        </div>

        {{-- the canvas --}}
        <div x-ref="viewport" class="oc-viewport relative h-[70vh] min-h-[28rem] overflow-auto rounded-xl border border-zinc-200 bg-zinc-50"
            x-on:mousedown="dragStart($event)" x-on:mousemove.window="dragMove($event)" x-on:mouseup.window="dragging = false"
            :class="dragging ? 'cursor-grabbing select-none' : 'cursor-grab'">
            <div x-ref="stage" class="oc-stage p-10" :style="`zoom: ${scale}`">
                <div id="org-chart" class="oc">
                    <ul>
                        <li class="oc-root">
                            <div class="oc-top">
                                @if($logo)<img src="{{ $logo }}" alt="" class="mx-auto mb-2 h-10 rounded bg-white p-1">@endif
                                <div class="text-[10px] font-medium uppercase tracking-[0.18em] text-indigo-200">República Democrática de Timor-Leste</div>
                                <div class="mt-1 text-base font-semibold leading-snug text-white">{{ $organization }}</div>
                                @if($abbr)<div class="mt-0.5 text-xs font-medium text-indigo-200">{{ $abbr }}</div>@endif
                            </div>
                            @if($staff->isNotEmpty())
                                <div class="oc-staff">
                                    <div class="oc-staff-label">{{ __('Advisory and support units') }}</div>
                                    <div class="oc-staff-grid">
                                        @foreach($staff as $root)
                                            <div class="oc-staff-item">@include('livewire.pages.work.partials.org-node', $shared + ['n' => $root, 'level' => 1, 'startOpen' => false])</div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                            <ul>
                                @foreach($own as $root)
                                    <li class="oc-l1">@include('livewire.pages.work.partials.org-node', $shared + ['n' => $root, 'level' => 1])</li>
                                @endforeach
                                @foreach($bodies as $root)
                                    <li class="oc-l1 oc-supervised">@include('livewire.pages.work.partials.org-node', $shared + ['n' => $root, 'level' => 1])</li>
                                @endforeach
                            </ul>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    @endif

    <style>
        /* edges by kind of unit */
        .oc-a-cab { --oc-edge: #7A5AF8; } .oc-a-dg { --oc-edge: #465FFF; } .oc-a-dn { --oc-edge: #0BA5EC; }
        .oc-a-dep { --oc-edge: #12B76A; } .oc-a-reg { --oc-edge: #F79009; } .oc-a-unit { --oc-edge: #98A2B3; } .oc-a-body { --oc-edge: #F79009; }
        .oc-swatch { display: inline-block; width: .9rem; height: .55rem; border-radius: 2px; background: var(--oc-edge); }
        .oc-swatch-dashed { background: transparent; border: 1.5px dashed var(--oc-edge); }

        .oc { --oc-line: #98A2B3; width: max-content; }
        /* wider than the screen: start at the left edge (a plain centre would push the left part out of reach) */
        .oc-stage { display: flex; justify-content: safe center; min-width: 100%; width: max-content; }
        /* the staff block on the trunk: the line runs through it from the institution to the row of directorates */
        .oc-staff { position: relative; margin-top: 28px; padding: 26px 18px 14px; border: 1px dashed var(--c-neutral-300, #D0D5DD); border-radius: .9rem; background: color-mix(in srgb, var(--surface, #fff) 70%, transparent); }
        .oc-staff::before { content: ""; position: absolute; left: 50%; top: -28px; height: 28px; border-left: 2px solid var(--oc-line); }
        .oc-staff::after { content: ""; position: absolute; left: 50%; bottom: -28px; height: 28px; border-left: 2px solid var(--oc-line); }
        .oc-staff-label { position: absolute; top: 6px; left: 0; right: 0; text-align: center; font-size: 10px; font-weight: 600; letter-spacing: .12em; text-transform: uppercase; color: var(--t-zinc-500, #667085); }
        .oc-staff-grid { display: grid; grid-template-columns: repeat(var(--oc-cols, 4), 15.5rem); gap: 12px 16px; justify-content: center; }
        .oc-staff-item > div > ul.oc-col { margin-left: 22px; }
        .oc ul { position: relative; display: flex; justify-content: center; padding-top: 28px; margin: 0; }
        .oc li { position: relative; list-style: none; padding: 28px 10px 0; display: flex; flex-direction: column; align-items: center; }
        /* the bar across the siblings and the drop to each of them */
        .oc li::before, .oc li::after { content: ""; position: absolute; top: 0; right: 50%; width: 50%; height: 28px; border-top: 2px solid var(--oc-line); }
        .oc li::after { right: auto; left: 50%; border-left: 2px solid var(--oc-line); }
        .oc li:only-child::before, .oc li:only-child::after { display: none; }
        .oc li:only-child { padding-top: 0; }
        .oc li:first-child::before, .oc li:last-child::after { border: 0 none; }
        .oc li:last-child::before { border-right: 2px solid var(--oc-line); border-top-right-radius: 6px; }
        .oc li:first-child::after { border-top-left-radius: 6px; }
        /* the drop from a parent to the bar of its children */
        .oc ul ul::before { content: ""; position: absolute; top: 0; left: 50%; height: 28px; border-left: 2px solid var(--oc-line); }
        .oc > ul { padding-top: 0; }
        .oc > ul > li { padding-top: 0; }
        .oc > ul > li::before, .oc > ul > li::after { display: none; }
        /* supervised bodies hang from a dashed line */
        .oc li.oc-supervised::after { border-left-style: dashed; }

        /* below the first level the units hang in a column on the left of their parent */
        .oc ul.oc-col { display: block; align-self: stretch; margin: 10px 0 0 22px; padding: 0; }
        .oc ul.oc-col::before { display: none; }
        .oc ul.oc-col > li { display: block; padding: 8px 0 0 18px; }
        .oc ul.oc-col > li::before { top: 0; left: 0; right: auto; width: 18px; height: 34px; border: 0; border-left: 2px solid var(--oc-line); border-bottom: 2px solid var(--oc-line); border-radius: 0 0 0 6px; display: block; }
        .oc ul.oc-col > li::after { top: 34px; bottom: 0; left: 0; width: 0; height: auto; border: 0; border-left: 2px solid var(--oc-line); border-radius: 0; display: block; }
        .oc ul.oc-col > li:last-child::after { display: none; }
        .oc ul.oc-col > li:only-child { padding-top: 8px; }
        .oc ul.oc-col ul.oc-col { margin-left: 18px; }

        .oc-top { min-width: 18rem; max-width: 24rem; padding: 1rem 1.5rem; border-radius: .75rem; background: #161950; text-align: center; box-shadow: 0 10px 25px -10px rgba(22, 25, 80, .55); }
        .oc-box { width: 15.5rem; text-align: left; border-radius: .6rem; border: 1px solid var(--c-neutral-200, #E4E7EC); border-top: 4px solid var(--oc-edge); background: var(--surface, #fff); box-shadow: 0 1px 2px rgba(16, 24, 40, .06); transition: box-shadow .15s, transform .15s; }
        .oc-box:hover { box-shadow: 0 8px 20px -8px rgba(16, 24, 40, .25); transform: translateY(-1px); }
        .oc-box.oc-dashed { border-style: dashed; border-top-style: solid; }
        .oc-box.oc-hit { outline: 3px solid #FDB022; outline-offset: 2px; }
        .oc-box.oc-current { outline-color: #F04438; }
        .oc-avatar { background: color-mix(in srgb, var(--oc-edge) 14%, transparent); color: var(--oc-edge); }

        @media print {
            @page { size: A4 landscape; margin: 8mm; }
            body * { visibility: hidden; }
            #org-chart, #org-chart * { visibility: visible; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .oc-viewport { overflow: visible !important; height: auto !important; border: 0 !important; background: #fff !important; }
            .oc-stage { padding: 0 !important; }
            #org-chart { position: absolute; left: 0; top: 0; }
            .oc-box:hover { transform: none; box-shadow: none; }
            .oc-toggle { display: none !important; }
        }
    </style>

    @once
        <script>
            window.orgChart = () => ({
                scale: 1, find: '', hits: [], cursor: 0, dragging: false, start: null, before: null, fitted: false,
                init() {
                    // the staff block: as many columns as keep it about as wide as the row of directorates
                    const staff = document.querySelector('#org-chart .oc-staff-grid');
                    if (staff) staff.style.setProperty('--oc-cols', Math.min(5, Math.max(2, Math.ceil(Math.sqrt(staff.children.length * 1.6)))));
                    this.$nextTick(() => this.fit());
                    this.$watch('find', () => this.search());
                    window.addEventListener('beforeprint', () => { this.before = this.scale; this.fitPage(); });
                    window.addEventListener('afterprint', () => { if (this.before) this.scale = this.before; });
                },
                zoom(d) { this.scale = Math.min(1.6, Math.max(0.3, Math.round((this.scale + d) * 10) / 10)); },
                // the whole chart in the window (never above 100%)
                fit() {
                    const vp = this.$refs.viewport, chart = document.getElementById('org-chart');
                    if (!vp || !chart) return;
                    this.scale = 1;
                    this.$nextTick(() => {
                        const s = Math.min(1, (vp.clientWidth - 40) / (chart.scrollWidth + 80));
                        this.scale = Math.max(this.fitted ? 0.3 : 0.6, Math.floor(s * 100) / 100);
                        this.fitted = true;
                        this.$nextTick(() => { vp.scrollLeft = (vp.scrollWidth - vp.clientWidth) / 2; });
                    });
                },
                // printing: the whole chart on the width of a landscape page
                fitPage() {
                    const chart = document.getElementById('org-chart');
                    if (!chart) return;
                    this.scale = 1;
                    this.scale = Math.floor(Math.min(1, 1050 / (chart.scrollWidth + 20)) * 100) / 100;
                },
                print() { window.print(); },
                setAll(open) { document.querySelectorAll('#org-chart [data-oc-branch]').forEach(el => el.dispatchEvent(new CustomEvent('oc-set', { detail: open }))); },
                dragStart(e) {
                    if (e.button !== 0 || e.target.closest('a, button, input')) return;
                    this.dragging = true;
                    this.start = { x: e.clientX, y: e.clientY, l: this.$refs.viewport.scrollLeft, t: this.$refs.viewport.scrollTop };
                },
                dragMove(e) {
                    if (!this.dragging) return;
                    this.$refs.viewport.scrollLeft = this.start.l - (e.clientX - this.start.x);
                    this.$refs.viewport.scrollTop = this.start.t - (e.clientY - this.start.y);
                },
                // highlight the boxes whose code, name or head match, and bring the first into view
                search() {
                    document.querySelectorAll('#org-chart .oc-box').forEach(b => b.classList.remove('oc-hit', 'oc-current'));
                    const q = this.find.trim().toLowerCase();
                    this.hits = q === '' ? [] : [...document.querySelectorAll('#org-chart .oc-box')].filter(b => (b.dataset.find || '').includes(q));
                    this.hits.forEach(b => { let el = b.parentElement; while (el) { if (el.hasAttribute && el.hasAttribute('data-oc-branch') && el !== b.parentElement) el.dispatchEvent(new CustomEvent('oc-set', { detail: true })); el = el.parentElement; } });
                    this.cursor = 0;
                    this.$nextTick(() => this.show());
                },
                next() { if (this.hits.length) { this.cursor = (this.cursor + 1) % this.hits.length; this.show(); } },
                show() {
                    this.hits.forEach((b, i) => b.classList.toggle('oc-current', i === this.cursor));
                    this.hits[this.cursor]?.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'center' });
                },
            });
        </script>
    @endonce
</div>
