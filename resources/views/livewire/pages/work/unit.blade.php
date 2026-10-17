<?php

use App\Models\Directorate;
use App\Services\UnitWorkspace;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * The workspace of one unit: where its plan stands, what waits for the person looking at it, how the month's
 * reporting is going, and how the units below it are doing. It is the home of a planner or director ("My work"),
 * of a head of directorate (their whole directorate), and the way an administrator or the Minister looks at the
 * plan of any unit. Authority is checked on the server; the unit selector only offers what the person may open.
 */
new class extends Component {
    public int $unitId;

    /** 'tree': the unit and everything below it; 'unit': only the unit itself. */
    #[Url(as: 'scope')]
    public string $scope = 'tree';

    public function mount(Directorate $directorate): void
    {
        abort_unless(app(UnitWorkspace::class)->canOpen(auth()->user(), $directorate), 403);
        $this->unitId = $directorate->id;
    }

    public function with(): array
    {
        $service = app(UnitWorkspace::class);
        $user = auth()->user();
        $unit = Directorate::findOrFail($this->unitId);
        $year = \App\Support\FiscalYear::current();
        $data = $service->for($user, $unit, $year, $this->scope !== 'unit');

        return $data + [
            'switch' => $service->switchableUnits($user),
            'wide' => $user->isAdministrator() || $user->can('view_all_paa'),
            'canPlan' => $user->can('create_activities') || $user->can('submit_paa'),
            'canDirect' => $user->isAdministrator() || $user->isDirector(),
            'canFinalize' => app(\App\Services\PlanningWindow::class)->canDecide($user, $year, $unit->id),
        ];
    }
}; ?>

@php
    $c = $counts;
    $lightTone = ['none' => 'bg-zinc-100 text-zinc-600', 'ok' => 'bg-emerald-50 text-emerald-800', 'work' => 'bg-amber-50 text-amber-800', 'alert' => 'bg-rose-50 text-rose-800'];
    $lightLabel = ['none' => __('No activity yet'), 'ok' => __('All approved'), 'work' => __('Work in progress'), 'alert' => __('Needs correction')];
    $dot = ['none' => 'bg-zinc-300', 'ok' => 'bg-emerald-500', 'work' => 'bg-amber-500', 'alert' => 'bg-rose-500'];
    $listUrl = fn (array $q = []) => route('activities.index', ['unit' => $unit->id] + $q);
    $cards = [];
    if ($canPlan && $c['returned'] > 0) { $cards[] = ['rose', trans_choice(':n activity returned to correct|:n activities returned to correct', $c['returned'], ['n' => $c['returned']]), $listUrl(['tab' => 'draft'])]; }
    if ($canPlan && $c['draft'] > 0) { $cards[] = ['amber', trans_choice(':n draft to finish|:n drafts to finish', $c['draft'], ['n' => $c['draft']]), $listUrl(['tab' => 'draft'])]; }
    if ($decide['certify'] > 0) { $cards[] = ['indigo', trans_choice(':n activity to certify (your turn)|:n activities to certify (your turn)', $decide['certify'], ['n' => $decide['certify']]), $listUrl(['tab' => 'review'])]; }
    if ($decide['approve'] > 0) { $cards[] = ['indigo', trans_choice(':n activity to approve (your turn)|:n activities to approve (your turn)', $decide['approve'], ['n' => $decide['approve']]), route('planning.paa-approval')]; }
    if ($reporting && $reporting['done'] < $reporting['total'] && auth()->user()->can('view_monitoring')) {
        $cards[] = ['amber', __(':m: :done of :total activities have spending reported', ['m' => \Carbon\Carbon::create($year, $reporting['month'], 1)->translatedFormat('F'), 'done' => $reporting['done'], 'total' => $reporting['total']]), route('monitoring.report')];
    }
    if ($planning['ready'] && $canFinalize) { $cards[] = ['emerald', __('Everything is decided: the plan of this directorate is ready to be finalized.'), $listUrl()]; }
    if ($c['revising'] > 0) { $cards[] = ['zinc', trans_choice(':n revision in progress|:n revisions in progress', $c['revising'], ['n' => $c['revising']]), $listUrl(['tab' => 'draft'])]; }
    $toneBox = ['rose' => 'border-rose-200 bg-rose-50 text-rose-900', 'amber' => 'border-amber-200 bg-amber-50 text-amber-900', 'indigo' => 'border-indigo-200 bg-indigo-50 text-indigo-900', 'emerald' => 'border-emerald-200 bg-emerald-50 text-emerald-900', 'zinc' => 'border-zinc-200 bg-zinc-50 text-zinc-800'];
    $segments = [[__('Draft'), $statuses['Draft'] ?? 0, 'bg-zinc-300'], [__('Returned'), $statuses['Rejected'] ?? 0, 'bg-rose-400'], [__('Prepared'), $statuses['Submitted'] ?? 0, 'bg-sky-400'],
        [__('Verified'), $statuses['Reviewed'] ?? 0, 'bg-indigo-300'], [__('Certified'), ($statuses['Validated'] ?? 0) + ($statuses['Waiting Approval'] ?? 0), 'bg-indigo-500'], [__('Approved'), $statuses['Approved'] ?? 0, 'bg-emerald-500']];
@endphp

<div class="space-y-6">
    {{-- who and where --}}
    <header class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <nav aria-label="{{ __('Place in the structure') }}" class="flex flex-wrap items-center gap-1 text-xs text-zinc-500">
                @foreach($path as $i => $p)
                    @if($i > 0)<span aria-hidden="true">›</span>@endif
                    @if($p['id'] === $unit->id)<span class="font-medium text-zinc-700">{{ $p['code'] }}</span>
                    @elseif($wide || $switch->contains('id', $p['id']))<a href="{{ route('units.show', $p['id']) }}" wire:navigate class="hover:text-indigo-700 hover:underline">{{ $p['code'] }}</a>
                    @else<span>{{ $p['code'] }}</span>@endif
                @endforeach
            </nav>
            <h1 class="page-title mt-1">{{ $unit->name }}</h1>
            <p class="page-subtitle">{{ __('Annual action plan :year', ['year' => $year]) }}
                <span class="ml-2 inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium {{ $lightTone[$light] }}"><span class="h-1.5 w-1.5 rounded-full {{ $dot[$light] }}" aria-hidden="true"></span>{{ $lightLabel[$light] }}</span>
                @if($planning['closed'])<span class="ml-1 inline-flex rounded-full bg-indigo-50 px-2.5 py-0.5 text-xs font-medium text-indigo-800">{{ __('Plan finalized') }}</span>@endif
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if($unit->children()->exists())
                <div class="inline-flex rounded-md bg-zinc-100 p-0.5 text-xs font-medium" role="group" aria-label="{{ __('What to show') }}">
                    <button type="button" wire:click="$set('scope', 'tree')" class="rounded px-3 py-1.5 {{ $scope !== 'unit' ? 'bg-white text-zinc-900 shadow-sm' : 'text-zinc-600' }}" aria-pressed="{{ $scope !== 'unit' ? 'true' : 'false' }}">{{ __('This unit and below') }}</button>
                    <button type="button" wire:click="$set('scope', 'unit')" class="rounded px-3 py-1.5 {{ $scope === 'unit' ? 'bg-white text-zinc-900 shadow-sm' : 'text-zinc-600' }}" aria-pressed="{{ $scope === 'unit' ? 'true' : 'false' }}">{{ __('Only this unit') }}</button>
                </div>
            @endif
            @if($switch->count() > 1)
                <label for="uw-unit" class="sr-only">{{ __('Unit') }}</label>
                <select id="uw-unit" class="flux-input !w-auto" onchange="window.location.href = this.value">
                    @foreach($switch as $u)<option value="{{ route('units.show', $u->id) }}" @selected($u->id === $unit->id)>{{ $u->code }} — {{ \Illuminate\Support\Str::limit($u->name, 40) }}</option>@endforeach
                </select>
            @endif
            @if($wide || $canDirect)
                <x-ui.button variant="secondary" size="md" shadow="none" href="{{ route('units.index') }}" wire:navigate>{{ __('All units') }}</x-ui.button>
            @endif
        </div>
    </header>

    {{-- next for you --}}
    <section aria-label="{{ __('Next for you') }}">
        <h2 class="section-title mb-3">{{ __('Next for you') }}</h2>
        @if($cards)
            <ul class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                @foreach($cards as [$tone, $text, $url])
                    <li><a href="{{ $url }}" wire:navigate class="flex h-full flex-col justify-between rounded-lg border p-4 text-sm font-medium transition hover:shadow-card {{ $toneBox[$tone] }}"><span>{{ $text }}</span><span class="mt-3 text-xs font-semibold underline">{{ __('Open') }} →</span></a></li>
                @endforeach
            </ul>
        @else
            <x-ui.empty-state :title="__('Nothing is waiting for you')" :text="$c['total'] === 0 ? __('There is no activity for :year yet.', ['year' => $year]) : __('Everything in this plan is up to date.')" />
        @endif
    </section>

    <div class="grid gap-6 xl:grid-cols-3">
        {{-- the plan --}}
        <x-ui.card :title="__('The plan')" :description="trans_choice(':n activity|:n activities', $c['total'], ['n' => $c['total']])" class="xl:col-span-2" padding="p-5">
            @if($c['total'] > 0)
                <div class="flex h-2.5 overflow-hidden rounded-full bg-zinc-100" role="img" aria-label="{{ __(':approved of :total activities approved', ['approved' => $c['approved'], 'total' => $c['total']]) }}">
                    @foreach($segments as [$label, $n, $color])@if($n > 0)<div class="{{ $color }}" style="width: {{ $n / $c['total'] * 100 }}%" title="{{ $label }}: {{ $n }}"></div>@endif @endforeach
                </div>
                <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-3">
                    @foreach($segments as [$label, $n, $color])
                        <div class="flex items-center justify-between gap-2"><dt class="flex items-center gap-2 text-zinc-600"><span class="h-2 w-2 rounded-full {{ $color }}" aria-hidden="true"></span>{{ $label }}</dt><dd class="tabular-nums font-medium text-zinc-900">{{ $n }}</dd></div>
                    @endforeach
                </dl>
            @else
                <p class="text-sm text-zinc-600">{{ __('There is no activity for :year yet.', ['year' => $year]) }}</p>
            @endif
            <div class="mt-5 flex flex-wrap gap-2">
                <x-ui.button variant="secondary" size="md" shadow="none" href="{{ $listUrl() }}" wire:navigate>{{ __('Open the plan of this unit') }}</x-ui.button>
                @if($canPlan)<x-ui.button size="md" shadow="none" href="{{ route('activities.create') }}" wire:navigate>+ {{ __('New activity') }}</x-ui.button>@endif
                @can('view_reports')<x-ui.button variant="secondary" size="md" shadow="none" href="{{ route('reports.implementation-plan', ['year' => $year, 'unit' => $unit->id]) }}">{{ __('Implementation plan') }}</x-ui.button>@endcan
            </div>
        </x-ui.card>

        {{-- the money of the plan --}}
        <x-ui.card :title="__('Budget of the plan')" padding="p-5">
            <dl class="space-y-3 text-sm">
                <div class="flex items-baseline justify-between gap-2"><dt class="text-zinc-600">{{ __('Planned by all activities') }}</dt><dd class="tabular-nums text-base font-semibold text-zinc-900">$ {{ number_format($money['planned']) }}</dd></div>
                <div class="flex items-baseline justify-between gap-2"><dt class="text-zinc-600">{{ __('Approved plan in force') }}</dt><dd class="tabular-nums text-base font-semibold text-zinc-900">$ {{ number_format($money['in_force']) }}</dd></div>
                <div class="flex items-baseline justify-between gap-2"><dt class="text-zinc-600">{{ __('Paid') }} <span class="text-xs text-zinc-400">({{ __('verified') }})</span></dt><dd class="tabular-nums text-base font-semibold text-zinc-900">$ {{ number_format($money['paid']) }}</dd></div>
            </dl>
            @if($money['in_force'] > 0)
                <div class="mt-4 h-1.5 overflow-hidden rounded-full bg-zinc-100" role="img" aria-label="{{ __(':p% of the approved plan paid', ['p' => number_format(min(100, $money['paid'] / $money['in_force'] * 100), 1)]) }}"><div class="h-full bg-emerald-500" style="width: {{ min(100, $money['paid'] / $money['in_force'] * 100) }}%"></div></div>
            @endif
        </x-ui.card>
    </div>

    {{-- the units below --}}
    @if($units)
        @php
            $order = ['alert' => 0, 'work' => 1, 'ok' => 2, 'none' => 3];
            $sorted = collect($units)->sortBy(fn ($u) => $order[$u['light']])->values();
            $quiet = $sorted->where('light', 'none');
            $shown = $sorted->where('light', '!=', 'none');
        @endphp
        <x-ui.card :title="__('Units below')" :description="__('How each unit is doing, the ones that need attention first. Open one to see its own plan.')" padding="p-0">
            <ul class="divide-y divide-zinc-100">
                @foreach($shown as $u)
                    <li>
                        <a href="{{ route('units.show', $u['id']) }}" wire:navigate class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm hover:bg-zinc-50">
                            <span class="min-w-0"><span class="font-medium text-zinc-900">{{ $u['code'] }}</span> <span class="text-zinc-600">{{ \Illuminate\Support\Str::limit($u['name'], 60) }}</span></span>
                            <span class="flex items-center gap-4">
                                <span class="hidden text-xs tabular-nums text-zinc-500 sm:inline">{{ $u['counts']['approved'] }} / {{ $u['counts']['total'] }} {{ __('approved') }}@if($u['counts']['returned'] > 0) · {{ $u['counts']['returned'] }} {{ __('returned') }}@endif</span>
                                <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium {{ $lightTone[$u['light']] }}"><span class="h-1.5 w-1.5 rounded-full {{ $dot[$u['light']] }}" aria-hidden="true"></span>{{ $lightLabel[$u['light']] }}</span>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
            @if($quiet->isNotEmpty())
                <details class="border-t border-zinc-100">
                    <summary class="cursor-pointer px-5 py-3 text-sm text-zinc-600 hover:bg-zinc-50">{{ trans_choice(':n unit has no activity yet|:n units have no activity yet', $quiet->count(), ['n' => $quiet->count()]) }}</summary>
                    <ul class="divide-y divide-zinc-100">
                        @foreach($quiet as $u)
                            <li><a href="{{ route('units.show', $u['id']) }}" wire:navigate class="block px-5 py-2.5 text-sm text-zinc-600 hover:bg-zinc-50"><span class="font-medium text-zinc-800">{{ $u['code'] }}</span> {{ \Illuminate\Support\Str::limit($u['name'], 70) }}</a></li>
                        @endforeach
                    </ul>
                </details>
            @endif
        </x-ui.card>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        <x-ui.card :title="__('Recently')" padding="p-5">
            @forelse($recent as $r)
                <p class="flex items-baseline justify-between gap-3 py-1.5 text-sm @unless($loop->last) border-b border-zinc-100 @endunless">
                    <span class="min-w-0"><a href="{{ route('activities.show', $r['activity_id']) }}" wire:navigate class="font-medium text-indigo-700 hover:underline">{{ $r['code'] }}</a> {{ $r['text'] }}@if($r['by']) <span class="text-zinc-500">· {{ $r['by'] }}</span>@endif</span>
                    <span class="shrink-0 text-xs text-zinc-400">{{ $r['at']->diffForHumans() }}</span>
                </p>
            @empty
                <p class="text-sm text-zinc-600">{{ __('Nothing has happened yet.') }}</p>
            @endforelse
        </x-ui.card>

        <x-ui.card :title="__('Deadlines')" padding="p-5">
            @forelse($windows as $w)
                <p class="flex items-baseline justify-between gap-3 py-1.5 text-sm @unless($loop->last) border-b border-zinc-100 @endunless"><span>{{ $w['label'] }}</span><span class="tabular-nums font-medium text-zinc-900">{{ $w['on']->format('d/m/Y') }}</span></p>
            @empty
                <p class="text-sm text-zinc-600">{{ __('No window closes in the next weeks.') }}</p>
            @endforelse
            @if($reporting && $reporting['closes_on'])
                <p class="mt-3 text-xs text-zinc-500">{{ __('The report for :m closes on :d.', ['m' => \Carbon\Carbon::create($year, $reporting['month'], 1)->translatedFormat('F'), 'd' => $reporting['closes_on']->format('d/m/Y')]) }}</p>
            @endif
        </x-ui.card>
    </div>
</div>
