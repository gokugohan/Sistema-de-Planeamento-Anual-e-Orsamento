<section aria-labelledby="next-actions-title" class="bg-white rounded-lg border border-zinc-200 shadow-card">
    <div class="px-5 py-4 border-b border-zinc-200 flex items-center justify-between">
        <h2 id="next-actions-title" class="text-sm font-semibold text-zinc-900">{{ __('Needs your attention') }}</h2>
        @if(count($items))
            <span class="text-xs text-zinc-500">{{ trans_choice(':n item|:n items', count($items), ['n' => count($items)]) }}</span>
        @endif
    </div>

    @if(count($items))
        <ul class="divide-y divide-zinc-200">
            @foreach($items as $item)
                @php
                    $tone = ['amber' => 'bg-amber-50 text-amber-700', 'indigo' => 'bg-indigo-50 text-indigo-700', 'rose' => 'bg-rose-50 text-rose-700', 'emerald' => 'bg-emerald-50 text-emerald-700'][$item['tone']] ?? 'bg-zinc-100 text-zinc-700';
                @endphp
                <li>
                    <a href="{{ $item['url'] }}" wire:navigate class="flex items-center gap-4 px-5 py-3 hover:bg-zinc-50 transition-colors group">
                        <span class="min-w-[2.25rem] h-9 px-2 rounded-md flex items-center justify-center text-sm font-semibold {{ $tone }}">{{ $item['count'] }}</span>
                        <span class="flex-1 text-sm text-zinc-700">{{ $item['text'] }}</span>
                        <span class="text-xs font-medium text-indigo-600 opacity-0 group-hover:opacity-100 group-focus-visible:opacity-100 transition-opacity">{{ __('Open') }} →</span>
                    </a>
                </li>
            @endforeach
        </ul>
    @else
        <div class="px-5 py-6 flex items-center gap-3 text-sm text-zinc-600">
            <span class="w-9 h-9 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
            </span>
            {{ __('You are all caught up. Nothing is waiting for you right now.') }}
        </div>
    @endif
</section>
