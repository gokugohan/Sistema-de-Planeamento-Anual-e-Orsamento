{{-- The unit a report covers (App\Support\ReportScope): the whole institution for whoever sees the whole plan, else
     one of the units the user may see. Submits its form when changed. --}}
@props(['choices', 'scope', 'class' => 'flux-input !w-auto !py-1.5 !pr-9 max-w-xs'])

@if($choices->isNotEmpty())
    <label for="report-unit" class="sr-only">{{ __('Unit') }}</label>
    <select id="report-unit" name="unit" onchange="this.form.submit()" {{ $attributes->merge(['class' => $class]) }}>
        @unless($scope->fixed)
            <option value="">{{ __('All units') }}</option>
        @endunless
        @foreach($choices as $c)
            <option value="{{ $c['unit']->id }}" @selected($scope->unit?->id === $c['unit']->id)>{{ str_repeat('— ', $c['depth']) }}{{ $c['unit']->code }} — {{ \Illuminate\Support\Str::limit($c['unit']->name, 60) }}</option>
        @endforeach
    </select>
@endif
