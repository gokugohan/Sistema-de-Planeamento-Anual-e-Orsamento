@if($glance)
    <section aria-labelledby="glance-title" class="rounded-lg border border-zinc-200 bg-white px-5 py-4 shadow-card">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
            <h2 id="glance-title" class="text-sm font-semibold text-zinc-900">{{ __('The year :year at a glance', ['year' => $glance['year']]) }}@if($glance['now']) <span class="font-normal text-zinc-500">· {{ __('Now') }}: {{ implode(', ', $glance['now']) }}</span>@endif</h2>
            <span class="flex gap-4 text-xs font-medium">
                <a href="{{ route('guide') }}" wire:navigate class="text-indigo-700 hover:underline">{{ __('How the year works') }}</a>
                <a href="{{ route('planning.cycle') }}" wire:navigate class="text-indigo-700 hover:underline">{{ __('Plan cycle') }} →</a>
            </span>
        </div>
        <ol class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach($glance['phases'] as $p)
                @php $done = $p['state'] === 'done'; $now = $p['state'] === 'now'; @endphp
                <li class="flex flex-col">
                    <div class="flex items-center gap-2">
                        <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[10px] font-semibold {{ $done ? 'bg-emerald-600 text-white' : ($now ? 'bg-indigo-600 text-white ring-4 ring-indigo-100' : 'bg-zinc-100 text-zinc-500') }}" aria-hidden="true">
                            @if($done)<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>@elseif($now)●@else○@endif
                        </span>
                        <span class="text-sm {{ $now ? 'font-semibold text-zinc-900' : ($done ? 'font-medium text-zinc-700' : 'text-zinc-500') }}">{{ $p['label'] }}</span>
                        <span class="sr-only">{{ $done ? __('Done') : ($now ? __('Now') : __('Next')) }}</span>
                    </div>
                    <p class="mt-1.5 text-xs leading-relaxed {{ $now || $done ? 'text-zinc-600' : 'text-zinc-500' }}">{{ $p['text'] }}</p>
                    @if($p['cta'])
                        <a href="{{ $p['cta'][1] }}" wire:navigate class="mt-2 inline-flex w-fit items-center rounded-md bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-800 hover:bg-indigo-100">{{ $p['cta'][0] }} →</a>
                    @endif
                </li>
            @endforeach
        </ol>
    </section>
@endif
