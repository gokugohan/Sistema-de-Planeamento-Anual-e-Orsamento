@props(['year'])
@php
    // where the institution asks for it: decisions wait until every activity of the directorate has been submitted
    $held = [];
    if (\App\Support\PlanOptions::current()->completeBeforeApproval && ($user = auth()->user())) {
        $scope = app(\App\Services\PlanCycle::class)->scopeFor($user);
        $held = collect(app(\App\Services\PlanStatus::class)->perRoot((int) $year, $scope))->filter(fn ($r) => $r['drafts'] > 0)->values()->all();
    }
@endphp
@if(count($held))
    <div role="status" class="rounded-lg border border-amber-200 bg-amber-50 px-5 py-3 text-sm text-amber-900">
        <p class="font-medium">{{ __('Approval waits for a complete plan') }}</p>
        <ul class="mt-1 list-disc space-y-0.5 pl-5">
            @foreach($held as $r)
                <li><span class="font-medium">{{ $r['name'] }}:</span> {{ app(\App\Services\PlanStatus::class)->incompleteText($r['drafts']) }}</li>
            @endforeach
        </ul>
    </div>
@endif
