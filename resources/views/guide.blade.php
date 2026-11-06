@php
    $user = auth()->user();
    $admin = $user->isAdministrator();
    $rules = \App\Support\ApprovalRules::current();
    $options = \App\Support\PlanOptions::current();
    $monitorUrl = $user->can('view_monitoring') ? route('monitoring.report') : route('approvals.index');
    // who each step belongs to: role names of the application ('Admin' marks the administrator's own steps; an
    // administrator may also act at every other step). 'short' puts a step on the path of an activity at the top.
    $groups = [
        $admin ? [__('Set up the year (administrators)'), [
            ['who' => ['Admin'], 'whoText' => __('Administrator'), 'what' => __('Keep the structure and the programs'), 'how' => __('The units as the law defines them, and the programs and subprograms of the year.'),
                'when' => __('Before planning starts.'), 'where' => __('Administration → Structure'), 'url' => route('management.entities')],
            ['who' => ['Admin'], 'whoText' => __('Administrator'), 'what' => __('Give every unit its people'), 'how' => __('A technical officer who prepares and a director who certifies. A unit that nobody directs cannot get its activities approved.'),
                'when' => __('Before planning starts, and whenever people change.'), 'where' => __('Administration → System Users'), 'url' => route('management.users')],
            ['who' => ['Admin'], 'whoText' => __('Administrator'), 'what' => __('Set the calendar'), 'how' => __('The submission dates of the plan (the units submit only between them), and if you wish the dates for approving and for reporting each month and quarter. A unit that is late can be given more time.'),
                'when' => __('Before each step opens.'), 'where' => __('System → Calendar'), 'url' => route('management.reporting-windows')],
            $options->budgetDecisions ? ['who' => ['Admin'], 'whoText' => __('Administrator (the planning office)'), 'what' => __('Record the budget decisions'), 'how' => __('What the budget review committee, the State Budget law or a supplementary budget decided, by category; then open an adjustment round if the plan has to follow.'),
                'when' => __('After each decision.'), 'where' => __('Plan → Budget decisions'), 'url' => route('planning.budget-decisions')] : null,
        ]] : null,
        [__('Plan'), [
            ['who' => ['Planning'], 'whoText' => __('Technical Officer of the unit'), 'what' => __('Prepare the activities'), 'short' => __('Prepare'), 'how' => __('Describe each activity with its indicators and targets, its actions and its budget lines.'),
                'when' => __('For the current year or the next, while planning is open: until your directorate finalizes its plan, and within the dates an administrator may have set.'), 'where' => __('Plan → My plan → New activity'), 'url' => route('activities.index')],
            ['who' => ['Planning'], 'whoText' => __('Technical Officer (or whoever the unit\'s approval list names to prepare)'), 'what' => __('Submit the activity'), 'short' => __('Submit'), 'how' => __('Send the complete activity up for certification. The page says what is missing if it is not ready.'),
                'when' => __('When the activity is complete.'), 'where' => __('The activity page → Submit for certification'), 'url' => route('activities.index')],
        ]],
        [__('Approve'), [
            $rules->budgetConfirmation ? ['who' => ['Finance'], 'whoText' => __('Finance Officer'), 'what' => __('Confirm the budget lines'), 'short' => __('Confirm the budget'), 'how' => __('Check the budget lines of a prepared activity before it is certified.'),
                'when' => __('Before certification.'), 'where' => __('Approvals → Budget review'), 'url' => $user->can('confirm_budget') ? route('approvals.budget') : route('approvals.index')] : null,
            ['who' => ['Director'], 'whoText' => __('The Director of the unit (or the people on its approval list)'), 'what' => __('Certify'), 'short' => __('Certify'), 'how' => __('Read the activity and certify it, or return it with a reason the unit will read.'),
                'when' => __('As soon as an activity is prepared.'), 'where' => __('Approvals → Waiting for you → Activities to decide'), 'url' => route('approvals.index')],
            $rules->parentValidation ? ['who' => ['Director'], 'whoText' => __('The Director of the national directorate above the unit'), 'what' => __('Validate'), 'short' => __('Validate'), 'how' => __('Only where the unit sits under a national directorate: check what its units certified, so that each level checks once. Where the unit sits right under the directorate, this step is part of the approval of the plan.'),
                'when' => __('After the unit certifies.'), 'where' => __('Approvals → Waiting for you → Activities to decide'), 'url' => route('approvals.index')] : null,
            ['who' => ['Director'], 'whoText' => __('The head of the directorate (or the people on its approval list)'), 'what' => __('Approve the plan'), 'short' => __('Approve the plan'), 'how' => __('One step: every certified activity of the directorate is approved and its plan is finalized, so it takes no new activities. Return or approve single activities first if needed. Approved activities change only through a revision; the plan can be reopened with a reason.'),
                'when' => __('When every activity of the directorate is certified.'), 'where' => __('Approvals → Waiting for you → Approve the plan'), 'url' => route('approvals.index').'#plan'],
        ]],
        [__('Monitor'), [
            ['who' => ['Director'], 'whoText' => __('The Director of the unit'), 'what' => __('Report the executed amount'), 'short' => __('Report'), 'how' => __('For each budget line, enter the amount executed in the month. This is where "Executed" comes from.'),
                'when' => __('Every month, once the month has started and inside its reporting window if one is set.'), 'where' => __('Execute → Report execution'), 'url' => $monitorUrl],
            ['who' => ['Director'], 'whoText' => __('The Director of the unit'), 'what' => __('Update the indicators'), 'how' => __('Enter what each indicator has achieved. The quarter follows the month you chose: October to December is Q4.'),
                'when' => __('Every quarter.'), 'where' => __('Execute → Report execution (the Indicators block)'), 'url' => $monitorUrl],
            ['who' => ['Director'], 'whoText' => __('The Director of the unit'), 'what' => __('Write the narrative'), 'how' => __('What was done and what got in the way; for the quarter also why targets were missed and what will be done.'),
                'when' => __('Every month and every quarter.'), 'where' => __('Execute → Report execution (the Narrative block)'), 'url' => $monitorUrl],
            ['who' => ['Finance', 'Director'], 'whoText' => __('Someone other than the person who reported: the Finance Officer or the head'), 'what' => __('Verify'), 'short' => __('Verify'), 'how' => __('Check a reported figure and verify it, or return it. Only verified figures count in the totals.'),
                'when' => __('After a figure is submitted.'), 'where' => __('Approvals → Waiting for you → To verify'), 'url' => route('approvals.index')],
            ['who' => ['Director'], 'whoText' => __('Directors'), 'what' => __('Compile the quarter'), 'how' => __('Turn the units\' reports into the text of the ministry report.'),
                'when' => __('After the quarter ends.'), 'where' => __('Execute → Compile the quarter'), 'url' => $user->hasAnyRole(['Admin', 'Director']) ? route('reports.compile') : route('approvals.index')],
        ]],
        [__('Change'), [
            ['who' => ['Planning', 'Director'], 'whoText' => __('Technical Officer, Director or the head'), 'what' => __('Revise an approved activity'), 'how' => __('Say why, change what is needed and send it through approval again. The approved version stays in force, and the executed amount is reported against it, until the revision is approved.'),
                'when' => __('Whenever an approved activity has to change.'), 'where' => __('The activity page → Revise'), 'url' => route('activities.index')],
            $options->budgetDecisions ? ['who' => ['Planning', 'Director'], 'whoText' => __('Each directorate; its head confirms'), 'what' => __('Adjust to the budget decision'), 'how' => __('In an adjustment round, revise the activities your directorate chooses (and add new ones where the round allows it), then the head confirms the adjustment.'),
                'when' => __('While an adjustment round is open.'), 'where' => __('Plan → Adjustment round'), 'url' => route('planning.adjustment')] : null,
            ['who' => ['Planning', 'Director', 'Finance'], 'whoText' => __('Anyone in a unit that is late'), 'what' => __('Ask for more time'), 'how' => __('When a step of the calendar has closed with work left in your unit, ask the administrator to reopen it for your unit only.'),
                'when' => __('After a step has closed.'), 'where' => __('The message on the dashboard or on the page → Ask the administrator to reopen it'), 'url' => route('dashboard')],
        ]],
    ];
    // leave out the steps and groups this institution does not use
    $groups = array_values(array_filter(array_map(fn ($g) => $g === null ? null : [$g[0], array_values(array_filter($g[1]))], $groups)));
    $mine = fn ($step) => in_array('Admin', $step['who'], true) ? $admin : (! $admin && $user->hasAnyRole($step['who']));
    $path = collect($groups)->flatMap(fn ($g) => $g[1])->filter(fn ($s) => isset($s['short']))->values();
    $words = [
        [__('Draft'), __('Being written by the unit.')],
        [__('Returned'), __('Sent back with a reason; the unit corrects it.')],
        [__('Prepared'), __('Submitted by the unit; waiting to be certified.')],
        [__('Verified'), __('Certified by the unit\'s director; waiting for the national directorate above it.')],
        [__('Certified'), __('Checked by every level below the head; waiting for the approval of the plan.')],
        [__('Approved'), __('Given the final approval. It changes only through a revision.')],
    ];
@endphp
<x-app-layout title="{{ __('How the year works') }}">
    <div class="space-y-6">
        <x-ui.page-header :title="__('How the year works')" :subtitle="__('Who does what, when and where. The steps that are yours are marked.')" />

        {{-- the path of an activity, from the unit to the approved plan and its reports --}}
        <x-ui.card padding="p-5">
            <h2 class="text-sm font-semibold text-zinc-900">{{ __('The path of an activity') }}</h2>
            <ol class="mt-3 flex flex-wrap items-center gap-2 text-sm">
                @foreach($path as $i => $s)
                    <li class="flex items-center gap-2">
                        <span @class(['rounded-full px-3 py-1 font-medium', 'bg-indigo-600 text-white' => $mine($s), 'bg-zinc-100 text-zinc-800' => ! $mine($s)])>{{ $i + 1 }}. {{ $s['short'] }}</span>
                        @if(! $loop->last)<span class="text-zinc-400" aria-hidden="true">→</span>@endif
                    </li>
                @endforeach
            </ol>
            <p class="mt-3 text-xs text-zinc-500">{{ __('The steps in blue are yours. Where the same person holds two steps in a row, one click takes both. Approving the plan also finalizes it: the directorate takes no new activities afterwards.') }}</p>
        </x-ui.card>

        <x-ui.card padding="p-5">
            <p class="text-sm text-zinc-700"><span class="font-semibold text-zinc-900">{{ __('Where does "Executed" come from?') }}</span>
                {{ __('It is not imported from another system. The unit enters the amount each budget line executed, every month, in Report execution. Someone else then verifies it, and only verified figures count in every total, chart and report.') }}</p>
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
