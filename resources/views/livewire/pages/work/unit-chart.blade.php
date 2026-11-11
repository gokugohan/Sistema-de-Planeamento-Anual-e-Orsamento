<?php

use App\Services\OrgChart;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * The organization chart page: the units the person may see, drawn top-down under the institution as staff and line
 * (OrgChart), each box with its kind and its head. An extra view of the structure the plan is built on (the Units page
 * is where the work is). It can be zoomed, fitted to the screen, dragged, folded by branch and searched; Print opens the
 * chart on a page of its own, fitted to an A4 landscape sheet (units/chart/print). A box opens the unit's workspace.
 */
new class extends Component {
    /** Show each unit's plan of the year (its light and number of activities) */
    #[Url(as: 'plan')]
    public bool $withPlan = false;

    public function mount(): void
    {
        abort_unless(OrgChart::allowed(auth()->user()), 403);
    }

    public function with(): array
    {
        return app(OrgChart::class)->build(auth()->user(), \App\Support\FiscalYear::current());
    }
}; ?>

<div class="space-y-4" x-data="orgChart()" x-init="init()">
    <x-ui.page-header :title="__('Organization chart')" :subtitle="trans_choice(':n unit of the institution, with its head.|:n units of the institution, each with its head.', $count, ['n' => $count])">
        <x-ui.button variant="secondary" href="{{ route('units.index') }}" wire:navigate>{{ __('Units') }}</x-ui.button>
        @if($count > 0)
            <x-ui.button variant="secondary" href="{{ route('units.chart.print', array_filter(['year' => $year, 'plan' => $withPlan ? 1 : null])) }}" target="_blank" rel="noopener">{{ __('Print') }}</x-ui.button>
        @endif
    </x-ui.page-header>

    @if($count === 0)
        <x-ui.empty-state :title="__('No units yet')" :text="__('The units of the institution are set in Management.')" />
    @else
        {{-- the controls --}}
        <div class="flex flex-wrap items-center gap-2 rounded-lg border border-zinc-200 bg-white px-3 py-2 shadow-card">
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

        @include('livewire.pages.work.partials.org-legend', ['bodies' => $bodies, 'hint' => true])

        {{-- the canvas --}}
        <div x-ref="viewport" class="relative h-[70vh] min-h-[28rem] overflow-auto rounded-xl border border-zinc-200 bg-zinc-50"
            x-on:mousedown="dragStart($event)" x-on:mousemove.window="dragMove($event)" x-on:mouseup.window="dragging = false"
            :class="dragging ? 'cursor-grabbing select-none' : 'cursor-grab'">
            {{-- wider than the screen, the chart starts at its left edge (a plain centre would put its left part out of reach) --}}
            <div class="flex min-w-full p-10" style="width: max-content; justify-content: safe center" :style="{ zoom: scale }">
                @include('livewire.pages.work.partials.org-tree', ['print' => false])
            </div>
        </div>
    @endif

    @include('livewire.pages.work.partials.org-styles')

    @once
        <script>
            window.orgChart = () => ({
                scale: 1, find: '', hits: [], cursor: 0, dragging: false, start: null, fitted: false,
                init() {
                    this.$nextTick(() => this.fit());
                    this.$watch('find', () => this.search());
                },
                zoom(d) { this.scale = Math.min(1.6, Math.max(0.3, Math.round((this.scale + d) * 10) / 10)); },
                // the whole chart in the window (never above 100%; at first never below a readable 60%)
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
                // highlight the boxes whose code, name or head match, open their branches and bring the first into view
                search() {
                    document.querySelectorAll('#org-chart .oc-box').forEach(b => b.classList.remove('oc-hit', 'oc-current'));
                    const q = this.find.trim().toLowerCase();
                    this.hits = q === '' ? [] : [...document.querySelectorAll('#org-chart .oc-box')].filter(b => (b.dataset.find || '').includes(q));
                    this.hits.forEach(b => { let el = b.parentElement?.parentElement; while (el) { if (el.hasAttribute?.('data-oc-branch')) el.dispatchEvent(new CustomEvent('oc-set', { detail: true })); el = el.parentElement; } });
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
