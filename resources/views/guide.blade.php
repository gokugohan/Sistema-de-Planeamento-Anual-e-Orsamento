@php
    $user = auth()->user();
    $admin = $user->isAdministrator();
    // who each step belongs to: role names of the application; an administrator may do any of them
    $groups = [
        [__('Plan'), [
            ['who' => ['Planning', 'Director'], 'whoText' => __('Technical Officer of the unit (the Director too)'), 'what' => __('Prepare the activities'), 'how' => __('Describe each activity with its indicators and targets, its actions and its budget lines.'),
                'when' => __('While planning is open: until your directorate finalizes its plan, and within the dates an administrator may have set.'), 'where' => __('Plan → My plan → New activity'), 'url' => route('activities.index')],
            ['who' => ['Planning', 'Director'], 'whoText' => __('Technical Officer or Director'), 'what' => __('Submit the activity'), 'how' => __('Send the complete activity up for certification. The page says what is missing if it is not ready.'),
                'when' => __('When the activity is complete.'), 'where' => __('The activity page → Submit for Review'), 'url' => route('activities.index')],
        ]],
        [__('Approve'), [
            ['who' => ['Director'], 'whoText' => __('The Director of the unit (or the people on its approval list)'), 'what' => __('Certify'), 'how' => __('Read the activity and certify it, or return it with a reason the unit will read.'),
                'when' => __('As soon as an activity is prepared.'), 'where' => __('Approvals → Waiting for you → Activities to decide'), 'url' => route('approvals.index')],
            ['who' => ['Director'], 'whoText' => __('The head of the directorate (or the people on its approval list)'), 'what' => __('Give the final approval'), 'how' => __('The approved activity is locked. From then on it changes only through a revision.'),
                'when' => __('After it is certified.'), 'where' => __('Approvals → Waiting for you → Activities to decide'), 'url' => route('approvals.index')],
            ['who' => ['Finance'], 'whoText' => __('Finance Officer (only where your institution asks for it)'), 'what' => __('Confirm the budget lines'), 'how' => __('Check the budget lines of a prepared activity before it is certified.'),
                'when' => __('Before certification.'), 'where' => __('Approvals → Budget review'), 'url' => $user->can('confirm_budget') ? route('approvals.budget') : route('approvals.index')],
            ['who' => ['Director'], 'whoText' => __('The head of the directorate (or an administrator)'), 'what' => __('Finalize the plan'), 'how' => __('Once everything is decided, finalizing ends planning for the directorate: no new activities after it. It can be reopened with a reason.'),
                'when' => __('When every activity of the directorate is decided.'), 'where' => __('The card at the top of the activities list'), 'url' => route('activities.index')],
        ]],
        [__('Monitor'), [
            ['who' => ['Director'], 'whoText' => __('The Director of the unit'), 'what' => __('Report the spending'), 'how' => __('For each budget line, enter what was committed and what was paid in the month. This is where "Paid" comes from.'),
                'when' => __('Every month, once the month has started and inside its reporting window if one is set.'), 'where' => __('Execute → Report execution'), 'url' => $user->can('view_monitoring') ? route('monitoring.report') : route('approvals.index')],
            ['who' => ['Director'], 'whoText' => __('The Director of the unit'), 'what' => __('Update the indicators'), 'how' => __('Enter what each indicator has achieved. The quarter follows the month you chose: October to December is Q4.'),
                'when' => __('Every quarter.'), 'where' => __('Execute → Report execution (the Indicators block)'), 'url' => $user->can('view_monitoring') ? route('monitoring.report') : route('approvals.index')],
            ['who' => ['Director'], 'whoText' => __('The Director of the unit'), 'what' => __('Write the narrative'), 'how' => __('What was done and what got in the way; for the quarter also why targets were missed and what will be done.'),
                'when' => __('Every month and every quarter.'), 'where' => __('Execute → Report execution (the Narrative block)'), 'url' => $user->can('view_monitoring') ? route('monitoring.report') : route('approvals.index')],
            ['who' => ['Finance', 'Director'], 'whoText' => __('Someone other than the person who reported: the Finance Officer or the head'), 'what' => __('Verify'), 'how' => __('Check a reported figure and verify it, or return it. Only verified figures count in the totals.'),
                'when' => __('After a figure is submitted.'), 'where' => __('Approvals → Waiting for you → To verify'), 'url' => route('approvals.index')],
            ['who' => ['Director'], 'whoText' => __('Directors'), 'what' => __('Compile the quarter'), 'how' => __('Turn the units\' reports into the text of the ministry report.'),
                'when' => __('After the quarter ends.'), 'where' => __('Execute → Compile the quarter'), 'url' => $user->hasAnyRole(['Admin', 'Director']) ? route('reports.compile') : route('approvals.index')],
        ]],
        [__('Change'), [
            ['who' => ['Planning', 'Director'], 'whoText' => __('Technical Officer, Director or the head'), 'what' => __('Revise an approved activity'), 'how' => __('Say why, change what is needed and send it through approval again. The approved version stays in force, and spending is reported against it, until the revision is approved.'),
                'when' => __('Whenever an approved activity has to change.'), 'where' => __('The activity page → Revise'), 'url' => route('activities.index')],
        ]],
    ];
    $mine = fn ($step) => ! $admin && $user->hasAnyRole($step['who']);
    $words = [
        [__('Draft'), __('Being written by the unit.')],
        [__('Returned'), __('Sent back with a reason; the unit corrects it.')],
        [__('Prepared'), __('Submitted by the unit; waiting to be certified.')],
        [__('Certified'), __('The unit\'s director agreed; waiting for the final approval.')],
        [__('Approved'), __('Given the final approval. It changes only through a revision.')],
    ];
@endphp
<x-app-layout title="{{ __('How the year works') }}">
    <div class="space-y-6">
        <x-ui.page-header :title="__('How the year works')" :subtitle="__('Who does what, when and where. The steps that are yours are marked.')" />

        <x-ui.card padding="p-5">
            <p class="text-sm text-zinc-700"><span class="font-semibold text-zinc-900">{{ __('Where does "Paid" come from?') }}</span>
                {{ __('It is not imported from another system. The unit enters what each budget line committed and paid, every month, in Report execution. Someone else then verifies it, and only verified figures count in every total, chart and report.') }}</p>
        </x-ui.card>

        @foreach($groups as [$title, $steps])
            <x-ui.card :title="$title" padding="p-0">
                <ul class="divide-y divide-zinc-200">
                    @foreach($steps as $s)
                        <li class="grid gap-3 px-5 py-4 md:grid-cols-12 {{ $mine($s) ? 'bg-indigo-50/40' : '' }}">
                            <div class="md:col-span-3">
                                <p class="text-sm font-semibold text-zinc-900">{{ $s['what'] }}</p>
                                @if($mine($s))<span class="mt-1 inline-flex rounded-full bg-indigo-100 px-2 py-0.5 text-[11px] font-semibold text-indigo-800">{{ __('Yours') }}</span>@endif
                            </div>
                            <div class="md:col-span-5 text-sm text-zinc-700">
                                <p>{{ $s['how'] }}</p>
                                <p class="mt-1 text-xs text-zinc-500"><span class="font-medium">{{ __('When') }}:</span> {{ $s['when'] }}</p>
                            </div>
                            <div class="md:col-span-4 text-sm">
                                <p class="text-zinc-700"><span class="text-xs font-medium text-zinc-500">{{ __('Who') }}:</span> {{ $s['whoText'] }}</p>
                                <a href="{{ $s['url'] }}" wire:navigate class="mt-1 inline-block text-xs font-medium text-indigo-700 hover:underline">{{ $s['where'] }} →</a>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        @endforeach

        <x-ui.card :title="__('The words used for an activity')" padding="p-5">
            <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                @foreach($words as [$word, $meaning])
                    <div class="flex gap-2"><dt class="w-24 shrink-0 font-semibold text-zinc-900">{{ $word }}</dt><dd class="text-zinc-700">{{ $meaning }}</dd></div>
                @endforeach
            </dl>
            @if($admin)
                <p class="mt-4 text-sm text-zinc-600">{{ __('As an administrator you may act at every step; the people named above do them day to day.') }}</p>
            @endif
            @if($user->hasAnyRole(['Minister', 'Viewer']))
                <p class="mt-4 text-sm text-zinc-600">{{ __('You read plans and reports: use Programmes, Units and Performance. You do not prepare, approve or report.') }}</p>
            @endif
        </x-ui.card>
    </div>
</x-app-layout>
