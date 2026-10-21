@if(count($alerts))
    @php $tones = ['rose' => 'border-rose-200 bg-rose-50 text-rose-900', 'amber' => 'border-amber-200 bg-amber-50 text-amber-900', 'indigo' => 'border-indigo-200 bg-indigo-50 text-indigo-900', 'emerald' => 'border-emerald-200 bg-emerald-50 text-emerald-900']; @endphp
    <section aria-label="{{ __('Dates to keep in mind') }}" class="space-y-2">
        @foreach($alerts as $i => $a)
            <div role="status" class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 rounded-lg border px-4 py-2.5 text-sm {{ $tones[$a['tone']] ?? $tones['indigo'] }}">
                <span><span class="font-semibold">{{ __('Calendar') }}:</span> {{ $a['text'] }}
                    @if(isset($a['ask']))
                        <livewire:pages.calendar.ask-reopen :year="$forYear" :type="$a['ask'][0]" :period="(int) $a['ask'][1]" :key="'cal-ask-'.$i.'-'.$a['ask'][0].'-'.$a['ask'][1]" />
                    @endif
                </span>
                <a href="{{ $a['url'] }}" wire:navigate class="shrink-0 text-xs font-semibold underline">{{ __('Open') }} →</a>
            </div>
        @endforeach
    </section>
@endif
