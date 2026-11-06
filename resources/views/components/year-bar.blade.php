{{--
    Which year this page works in, always in sight: blue for the plan being prepared, green for the plan being carried out.
    Another year can be opened for this page only (?year= in its address); a page showing an unusual year says so in amber.
--}}
@php
    use App\Support\FiscalYear;
    $context = FiscalYear::context();
    $year = FiscalYear::current();
    $usual = FiscalYear::usual();
    $planning = FiscalYear::planning();
    $execution = FiscalYear::execution();
    [$label, $chip, $dot] = match ($context) {
        FiscalYear::EXECUTION => [__('Reporting'), 'bg-emerald-50 text-emerald-800 ring-emerald-200', 'bg-emerald-500'],
        FiscalYear::OVERVIEW => [__('Overview'), 'bg-zinc-100 text-zinc-800 ring-zinc-200', 'bg-zinc-500'],
        default => [__('Planning'), 'bg-indigo-50 text-indigo-800 ring-indigo-200', 'bg-indigo-500'],
    };
    $usualWhat = $context === FiscalYear::EXECUTION ? __('the plan being carried out') : __('the plan being prepared');
@endphp
<div class="mb-5 space-y-3" data-year-bar>
    <div class="flex flex-wrap items-center gap-2">
        <span class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-sm font-semibold ring-1 {{ $chip }}">
            <span class="h-2 w-2 rounded-full {{ $dot }}" aria-hidden="true"></span>{{ $label }} {{ $year }}
        </span>
        <label for="page-year" class="sr-only">{{ __('Year of this page') }}</label>
        <select id="page-year" class="flux-input !h-8 !w-auto !py-0 !pl-2 !pr-7 !text-sm" title="{{ __('Look at another year on this page only') }}"
            onchange="window.Livewire ? window.Livewire.navigate(this.value) : (window.location.href = this.value)">
            @foreach(FiscalYear::available() as $y)
                <option value="{{ FiscalYear::urlFor($y) }}" @selected($y === $year)>{{ $y }}</option>
            @endforeach
        </select>
        @if($context === FiscalYear::OVERVIEW)
            {{-- the two plans alive now, one click each --}}
            <a href="{{ route('monitoring.report') }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium text-emerald-800 ring-1 ring-emerald-200 hover:bg-emerald-50">
                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" aria-hidden="true"></span>{{ __('Report :year', ['year' => $execution]) }} &rarr;
            </a>
            @if($planning !== $execution)
                <a href="{{ route('activities.index') }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium text-indigo-800 ring-1 ring-indigo-200 hover:bg-indigo-50">
                    <span class="h-1.5 w-1.5 rounded-full bg-indigo-500" aria-hidden="true"></span>{{ __('Plan :year', ['year' => $planning]) }} &rarr;
                </a>
            @endif
        @endif
    </div>
    @if($year !== $usual && $context !== FiscalYear::OVERVIEW)
        <div class="flex flex-wrap items-center gap-3 rounded-lg bg-amber-50 px-4 py-2.5 text-sm text-amber-900 ring-1 ring-amber-200" role="status">
            <span>{{ __('You are looking at :year. This page normally shows :usual, :what.', ['year' => $year, 'usual' => $usual, 'what' => $usualWhat]) }}</span>
            <a href="{{ FiscalYear::urlFor($usual) }}" wire:navigate class="ml-auto font-semibold underline">{{ __('Back to :year', ['year' => $usual]) }}</a>
        </div>
    @endif
</div>
