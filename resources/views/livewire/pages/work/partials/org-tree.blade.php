{{-- The organization chart itself (OrgChart), for the page and for its print. $print: every branch open, nothing interactive. --}}
@php $shared = ['children' => $children, 'withPlan' => $withPlan, 'print' => $print] + \App\Services\OrgChart::helpers(); @endphp
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
                    <div class="oc-staff-grid" style="--oc-cols: {{ min(5, max(2, (int) ceil(sqrt($staff->count() * 1.6)))) }}">
                        @foreach($staff as $root)
                            <div class="oc-staff-item">@include('livewire.pages.work.partials.org-node', $shared + ['n' => $root, 'level' => 1, 'startOpen' => $print])</div>
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
