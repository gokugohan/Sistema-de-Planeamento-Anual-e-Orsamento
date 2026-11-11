{{-- What the colours of the organization chart mean. --}}
<div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-zinc-600">
    @foreach(['oc-a-cab' => __('Cabinet'), 'oc-a-dg' => __('General Directorate'), 'oc-a-dn' => __('National Directorate'), 'oc-a-dep' => __('Department'), 'oc-a-unit' => __('Unit')] as $cls => $word)
        <span class="inline-flex items-center gap-1.5"><span class="oc-swatch {{ $cls }}"></span>{{ $word }}</span>
    @endforeach
    @if($bodies->isNotEmpty())
        <span class="inline-flex items-center gap-1.5"><span class="oc-swatch oc-swatch-dashed oc-a-body"></span>{{ __('Autonomous body (supervised)') }}</span>
    @endif
    @if($hint ?? false)
        <span class="text-zinc-400">· {{ __('Drag to move; open a box to see the unit.') }}</span>
    @endif
</div>
