{{-- A directorate's share of the budget against its plan, by category (an optional rule). --}}
@if($position && $position['rows'])
    @php $money = fn ($v) => '$ '.number_format((float) $v, 0); $over = $position['over'] >= 1; @endphp
    <section aria-labelledby="budget-share-title" @class(['rounded-xl border bg-white p-4', 'border-rose-200' => $over, 'border-zinc-200' => ! $over])>
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 id="budget-share-title" class="text-sm font-semibold text-zinc-900">{{ __('Share of the budget :year', ['year' => $forYear]) }} · {{ $unitCode }}
                @if($decision = $position['distribution']->decision)
                    <span @class(['ml-1 rounded-full px-2 py-0.5 text-xs font-medium', 'bg-emerald-50 text-emerald-800' => $decision->isFinal(), 'bg-amber-50 text-amber-800' => ! $decision->isFinal()])
                        title="{{ $decision->isFinal() ? '' : __('It shares a provisional amount: the distribution of the State Budget law replaces it.') }}">{{ $decision->isFinal() ? __('Final envelope') : __('Provisional') }} · {{ $decision->stageLabel() }}</span>
                @endif
            </h2>
            <p @class(['text-sm font-medium', 'text-rose-700' => $over, 'text-emerald-700' => ! $over])>
                {{ $over ? __('The plan is $ :amount above the share', ['amount' => number_format($position['over'], 0)]) : __('$ :amount left to plan', ['amount' => number_format(max(0, $position['room']), 0)]) }}
            </p>
        </div>
        <div class="mt-3 overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-xs text-zinc-500">
                    <tr>
                        <th scope="col" class="py-1 pr-3 text-left font-medium">{{ __('Category') }}</th>
                        <th scope="col" class="px-3 py-1 text-right font-medium">{{ __('Share') }}</th>
                        <th scope="col" class="px-3 py-1 text-right font-medium">{{ __('Approved') }}</th>
                        <th scope="col" class="px-3 py-1 text-right font-medium">{{ __('Being prepared') }}</th>
                        <th scope="col" class="py-1 pl-3 text-right font-medium">{{ __('Left') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @foreach($position['rows'] as $r)
                        <tr>
                            <td class="py-1.5 pr-3 text-zinc-800">{{ $r['code'] }} {{ $r['name'] }}</td>
                            <td class="px-3 py-1.5 text-right tabular-nums">{{ $money($r['share']) }}</td>
                            <td class="px-3 py-1.5 text-right tabular-nums">{{ $money($r['in_force']) }}</td>
                            <td class="px-3 py-1.5 text-right tabular-nums text-zinc-600">{{ $r['preparing'] > 0 ? $money($r['preparing']) : '—' }}</td>
                            <td @class(['py-1.5 pl-3 text-right font-medium tabular-nums', 'text-rose-700' => $r['room'] < -0.5, 'text-zinc-900' => $r['room'] >= -0.5])>{{ $r['room'] < -0.5 ? '−'.$money(-$r['room']) : $money($r['room']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if($over)
            <p class="mt-2 text-xs text-zinc-600">
                {{ $mode === \App\Support\PlanOptions::CEILING_BLOCK ? __('The plan cannot be approved while it is above the share: reduce the activities concerned.') : __('Reduce the activities concerned, or the head will need to say why the plan is approved above its share.') }}
            </p>
        @endif
    </section>
@endif
