{{-- The programme structure of the year, in the order of the State Budget's annual plans (Anexo K): each programme
     with its outcomes (medium-term objectives), its subprogrammes and their outputs (short-term results), what each
     costs in the approved plan, and what is still missing for the final plan report to show the whole chain.
     Everything is edited in a side panel beside the tree. --}}
@php
    $s = $structure;
    $money = fn ($v) => '$ '.number_format((float) $v);
    $limit = fn ($t, $n = 110) => \Illuminate\Support\Str::limit((string) $t, $n);
@endphp

{{-- Summary and what is missing --}}
<div class="grid gap-4 lg:grid-cols-[1fr_22rem]">
    <div class="grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-zinc-200 bg-zinc-200 shadow-card sm:grid-cols-4">
        @foreach([[__('Programs'), $s['totals']['programs']], [__('Outcomes'), $s['totals']['outcomes']], [__('Subprograms'), $s['totals']['subprograms']], [__('Outputs'), $s['totals']['outputs']]] as [$label, $n])
            <div class="bg-white px-5 py-4">
                <p class="text-xs font-medium text-zinc-500">{{ $label }}</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-zinc-900">{{ $n }}</p>
            </div>
        @endforeach
    </div>
    @if($s['gaps'])
        <div role="status" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <p class="font-semibold">{{ __('To complete for the final plan report') }}</p>
            <ul class="mt-1 space-y-0.5 text-xs">
                @isset($s['gaps']['no_outcome'])<li>{{ trans_choice(':n program has no outcome: :codes|:n programs have no outcome: :codes', count($s['gaps']['no_outcome']), ['n' => count($s['gaps']['no_outcome']), 'codes' => implode(', ', array_slice($s['gaps']['no_outcome'], 0, 6)).(count($s['gaps']['no_outcome']) > 6 ? '…' : '')]) }}</li>@endisset
                @isset($s['gaps']['no_output'])<li>{{ trans_choice(':n subprogram has no output: :codes|:n subprograms have no output: :codes', count($s['gaps']['no_output']), ['n' => count($s['gaps']['no_output']), 'codes' => implode(', ', array_slice($s['gaps']['no_output'], 0, 6)).(count($s['gaps']['no_output']) > 6 ? '…' : '')]) }}</li>@endisset
                @isset($s['gaps']['loose_output'])<li>{{ trans_choice(':n output names no outcome: :codes|:n outputs name no outcome: :codes', count($s['gaps']['loose_output']), ['n' => count($s['gaps']['loose_output']), 'codes' => implode(', ', array_slice($s['gaps']['loose_output'], 0, 6)).(count($s['gaps']['loose_output']) > 6 ? '…' : '')]) }}</li>@endisset
                @isset($s['gaps']['unlinked'])<li>{{ trans_choice(':n activity names no output although its subprogram has one|:n activities name no output although their subprogram has one', $s['gaps']['unlinked'], ['n' => $s['gaps']['unlinked']]) }}</li>@endisset
            </ul>
        </div>
    @else
        <div class="flex items-center gap-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
            <svg class="h-5 w-5 shrink-0 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 6 9 17l-5-5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <span>{{ __('Every program has its outcomes and every subprogram its output.') }}</span>
        </div>
    @endif
</div>

{{-- Toolbar --}}
<div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
    <x-ui.search wire:model.live.debounce.300ms="search" :placeholder="__('Search a program or subprogram by code or name')" />
    @if($copySources->isNotEmpty())
        <div x-data="{ open: false }" class="relative">
            <x-ui.button type="button" variant="secondary" size="sm" shadow="none" x-on:click="open = ! open" x-bind:aria-expanded="open">{{ __('Copy from another year') }}</x-ui.button>
            <div x-show="open" x-cloak x-on:click.outside="open = false" class="absolute right-0 z-20 mt-2 w-80 rounded-lg border border-zinc-200 bg-white p-4 shadow-default">
                <label for="f-copyFromYear" class="field-label">{{ __('Copy programs from another year') }}</label>
                <div class="mt-1 flex gap-2">
                    <select id="f-copyFromYear" wire:model="copyFromYear" class="flux-input">
                        <option value="">{{ __('Choose a year') }}</option>
                        @foreach($copySources as $y)<option value="{{ $y }}">{{ $y }}</option>@endforeach
                    </select>
                    <x-ui.button type="button" size="sm" wire:click="copyPrograms">{{ __('Copy') }}</x-ui.button>
                </div>
                <p class="mt-2 text-xs text-zinc-500">{{ __('Only the programs this year does not have yet are added, with their subprograms.') }}</p>
                <x-input-error :messages="$errors->get('copyFromYear')" />
            </div>
        </div>
    @endif
</div>

{{-- The tree --}}
<div class="space-y-4">
    @forelse($s['programs'] as $p)
        @php
            $f = $progFunding[$p->id] ?? ['n' => 0, 'plan' => 0, 'alloc' => 0];
            $pct = $f['plan'] > 0 ? min(100, $f['alloc'] / $f['plan'] * 100) : null;
            $outcomeIds = $p->resultIndicators->pluck('id');
            // each subprogramme sits under the outcome its output serves; the others are listed after the outcomes
            $byOutcome = $p->subprograms->groupBy(fn ($sp) => (int) ($sp->resultIndicators->first(fn ($o) => $outcomeIds->contains($o->outcome_id))?->outcome_id));
        @endphp
        <section class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-card" x-data="{ open: true }" wire:key="prog-{{ $p->id }}" aria-labelledby="prog-{{ $p->id }}-title">
            {{-- Programme --}}
            <header class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-zinc-200 bg-zinc-50 px-4 py-3">
                <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open" class="rounded p-1 text-zinc-500 hover:bg-zinc-200 hover:text-zinc-900" aria-label="{{ __('Show or hide') }} {{ $p->oge_code }}">
                    <svg class="h-4 w-4 transition-transform" x-bind:class="open ? 'rotate-90' : ''" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.17 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/></svg>
                </button>
                <div class="min-w-0 flex-1">
                    <p class="text-xs font-medium text-zinc-500">{{ __('Program') }} <span class="font-mono text-zinc-700">{{ $p->oge_code }}</span>@if($p->manager_name) · {{ $p->manager_name }}@endif</p>
                    <h3 id="prog-{{ $p->id }}-title" class="truncate text-sm font-semibold text-zinc-900">{{ $p->name }}</h3>
                </div>
                <div class="text-right text-sm tabular-nums">
                    @if($f['plan'] > 0)
                        <p class="font-semibold text-zinc-900">{{ $money($f['plan']) }}</p>
                        <p class="text-xs text-zinc-500">{{ trans_choice(':n activity|:n activities', $f['n'], ['n' => $f['n']]) }}@if($pct !== null) · {{ __(':pct% funded', ['pct' => number_format($pct, 0)]) }}@endif</p>
                    @else
                        <p class="text-xs text-zinc-400">{{ __('Nothing approved yet') }}</p>
                    @endif
                </div>
                <div class="flex items-center gap-1">
                    <x-ui.icon-action icon="edit" :label="__('Edit').' '.$p->oge_code" wire:click="openProgramModal({{ $p->id }})" />
                    <x-ui.icon-action icon="delete" :label="__('Delete').' '.$p->oge_code" wire:click="deleteProgram({{ $p->id }})" :confirm="__('Confirm deletion? This will remove the program permanently.')" />
                </div>
            </header>

            <div x-show="open" class="divide-y divide-zinc-100">
                @foreach($p->resultIndicators->concat([null]) as $outcome)
                    @php $subs = $byOutcome->get((int) $outcome?->id, collect()); @endphp
                    @continue(! $outcome && $subs->isEmpty())
                    <div class="px-4 py-3">
                        {{-- Outcome --}}
                        @if($outcome)
                            <div class="group flex items-start gap-3">
                                <span class="mt-0.5 inline-flex shrink-0 rounded bg-indigo-50 px-1.5 py-0.5 text-[11px] font-semibold text-indigo-800">{{ __('Outcome') }}</span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm text-zinc-900">@if($outcome->code)<span class="font-mono font-medium">{{ $outcome->code }}</span> · @endif{{ $outcome->statement ?: $outcome->name }}</p>
                                    @if($outcome->statement)<p class="mt-0.5 text-xs text-zinc-500">{{ $outcome->name }}@if($outcome->baseline || $outcome->target) · {{ $outcome->baseline ?: '—' }} → {{ $outcome->target ?: '—' }}@endif</p>@endif
                                </div>
                                <div class="flex shrink-0 items-center gap-1">
                                    <x-ui.icon-action icon="edit" :label="__('Edit').' '.($outcome->code ?: __('outcome'))" wire:click="openResult('outcome', {{ $p->id }}, {{ $outcome->id }})" />
                                    <x-ui.icon-action icon="delete" :label="__('Remove').' '.($outcome->code ?: __('outcome'))" wire:click="deleteResult('outcome', {{ $p->id }}, {{ $outcome->id }})" :confirm="__('Remove this outcome? The outputs that serve it keep going without it.')" />
                                </div>
                            </div>
                        @elseif($p->resultIndicators->isNotEmpty())
                            <p class="text-xs font-medium text-zinc-500">{{ __('Subprograms whose output names no outcome') }}</p>
                        @endif

                        {{-- Subprogrammes under it, each with its output --}}
                        @if($subs->isNotEmpty())
                            <ul class="mt-2 space-y-2 {{ $outcome ? 'ml-4 border-l-2 border-zinc-100 pl-4' : '' }}">
                                @foreach($subs as $sp)
                                    @php $unlinked = $sp->resultIndicators->isNotEmpty() ? ($s['unlinked'][$sp->id] ?? 0) : 0; @endphp
                                    <li class="rounded-md border border-zinc-200" wire:key="sub-{{ $sp->id }}">
                                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2">
                                            <span class="font-mono text-xs font-semibold text-zinc-700">{{ $sp->code }}</span>
                                            <span class="min-w-0 flex-1 text-sm font-medium text-zinc-900">{{ $sp->name }}</span>
                                            <span class="text-xs tabular-nums text-zinc-500">{{ trans_choice(':n activity|:n activities', $sp->activities_count, ['n' => $sp->activities_count]) }}</span>
                                            @if($unlinked)<x-ui.badge variant="amber" class="!text-[11px]">{{ trans_choice(':n without output|:n without output', $unlinked, ['n' => $unlinked]) }}</x-ui.badge>@endif
                                            <span class="flex items-center gap-1">
                                                <x-ui.icon-action icon="edit" :label="__('Edit').' '.$sp->code" wire:click="openSubprogramModal({{ $sp->id }})" />
                                                <x-ui.icon-action icon="delete" :label="__('Delete').' '.$sp->code" wire:click="deleteSubprogram({{ $sp->id }})" :confirm="__('Confirm deletion? This will remove the subprogram permanently.')" />
                                            </span>
                                        </div>
                                        <div class="border-t border-zinc-100 bg-zinc-50/60 px-3 py-2">
                                            @forelse($sp->resultIndicators as $out)
                                                <div class="flex items-start gap-3 {{ $loop->first ? '' : 'mt-2' }}">
                                                    <span class="mt-0.5 inline-flex shrink-0 rounded bg-amber-50 px-1.5 py-0.5 text-[11px] font-semibold text-amber-800">{{ __('Output') }}</span>
                                                    <div class="min-w-0 flex-1">
                                                        <p class="text-xs text-zinc-800">@if($out->code)<span class="font-mono font-medium">{{ $out->code }}</span> · @endif{{ $limit($out->statement ?: $out->name, 160) }}</p>
                                                        @if($out->statement)<p class="text-xs text-zinc-500">{{ $limit($out->name) }}@if($out->baseline || $out->target) · {{ $out->baseline ?: '—' }} → {{ $out->target ?: '—' }}@endif</p>@endif
                                                    </div>
                                                    <span class="flex shrink-0 items-center gap-1">
                                                        <x-ui.icon-action icon="edit" :label="__('Edit').' '.($out->code ?: __('output'))" wire:click="openResult('output', {{ $sp->id }}, {{ $out->id }})" />
                                                        <x-ui.icon-action icon="delete" :label="__('Remove').' '.($out->code ?: __('output'))" wire:click="deleteResult('output', {{ $sp->id }}, {{ $out->id }})" :confirm="__('Remove this output? Its activities keep going without it.')" />
                                                    </span>
                                                </div>
                                            @empty
                                                <button type="button" wire:click="openResult('output', {{ $sp->id }})" class="text-xs font-medium text-amber-800 hover:underline">+ {{ __('Add the output of this subprogram') }}</button>
                                            @endforelse
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endforeach

                {{-- Adding to the programme --}}
                <div class="flex flex-wrap gap-2 bg-white px-4 py-3">
                    <x-ui.button type="button" variant="secondary" size="xs" shadow="none" wire:click="openResult('outcome', {{ $p->id }})">+ {{ __('Outcome') }}</x-ui.button>
                    <x-ui.button type="button" variant="secondary" size="xs" shadow="none" wire:click="openSubprogramModal(null, {{ $p->id }})">+ {{ __('Subprogram') }}</x-ui.button>
                    @if($p->resultIndicators->isEmpty() && $p->subprograms->isEmpty())
                        <span class="self-center text-xs text-zinc-500">{{ __('Add the outcome the program pursues, then its subprograms and their outputs, as the State Budget lists them.') }}</span>
                    @endif
                </div>
            </div>
        </section>
    @empty
        <x-ui.card>
            <x-ui.empty-state :title="__('No programs registered for :year.', ['year' => $fundingYear])" :text="__('Register one, or copy them from another year.')" />
        </x-ui.card>
    @endforelse
</div>
<div>{{ $s['programs']->links() }}</div>

{{-- Programme panel --}}
@if($programModalOpen)
    <x-slide-over close="$set('programModalOpen', false)">
        <x-slot:eyebrow>{{ __('Program structure :year', ['year' => $fundingYear]) }}</x-slot:eyebrow>
        <x-slot:title>{{ $isProgramEditing ? __('Edit program') : __('New program') }}</x-slot:title>

        <form id="program-form" wire:submit="saveProgram" class="space-y-5 p-6">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-[8rem_1fr]">
                <div>
                    <label class="field-label" for="f-ogeCode">{{ __('Code') }}</label>
                    <input id="f-ogeCode" type="text" wire:model.live.debounce.300ms="ogeCode" class="flux-input font-mono" placeholder="{{ __('e.g. 510') }}" autocomplete="off">
                    <x-input-error :messages="$errors->get('ogeCode')" />
                </div>
                <div>
                    <label class="field-label" for="f-programName">{{ __('Name') }}</label>
                    <input id="f-programName" type="text" wire:model.live.debounce.300ms="programName" class="flux-input" autocomplete="off">
                    <x-input-error :messages="$errors->get('programName')" />
                </div>
            </div>

            @if($programSuggestions->isNotEmpty())
                <div role="region" aria-label="{{ __('Programs from other years') }}" class="rounded-md border border-indigo-200 bg-indigo-50/60">
                    <p class="px-4 pt-3 text-xs font-medium text-zinc-700">{{ __('Already registered in another year: pick one to fill in the form.') }}</p>
                    <ul class="p-1">
                        @foreach($programSuggestions as $sug)
                            <li>
                                <button type="button" wire:click="pickProgram({{ $sug->id }})" wire:key="sug-{{ $sug->id }}" class="flex w-full items-start justify-between gap-3 rounded px-3 py-2 text-left hover:bg-white">
                                    <span class="min-w-0 text-sm text-zinc-900"><span class="font-mono font-semibold">{{ $sug->oge_code }}</span> · {{ $sug->name }}</span>
                                    <span class="shrink-0 text-xs text-zinc-500">{{ $sug->fiscal_year }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div>
                <label class="field-label" for="f-managerName">{{ __('Program manager') }} <span class="font-normal text-zinc-500">({{ __('optional') }})</span></label>
                <input id="f-managerName" type="text" wire:model="managerName" class="flux-input">
                <x-input-error :messages="$errors->get('managerName')" />
            </div>

            {{-- the outcomes say what the programme pursues; the old free texts stay editable only where they were filled --}}
            @if(trim((string) $objective) !== '' || trim((string) $kpis) !== '')
                <div class="rounded-md border border-zinc-200 bg-zinc-50 p-4">
                    <p class="text-xs font-medium text-zinc-600">{{ __('Written before outcomes were recorded. The outcomes now say what the program pursues; these texts are kept.') }}</p>
                    <label class="field-label mt-3" for="f-objective">{{ __('Objective') }}</label>
                    <textarea id="f-objective" wire:model="objective" rows="2" class="flux-input"></textarea>
                    <label class="field-label mt-3" for="f-kpis">{{ __('Key Performance Indicators') }}</label>
                    <textarea id="f-kpis" wire:model="kpis" rows="2" class="flux-input"></textarea>
                </div>
            @endif
        </form>

        @if($isProgramEditing && $programId)
            <div class="border-t border-zinc-200">
                <livewire:pages.management.structure-details type="program" :id="$programId" :results="false" :key="'al-program-'.$programId" />
            </div>
        @endif

        <x-slot:footer>
            <x-ui.button type="button" variant="secondary" shadow="none" wire:click="$set('programModalOpen', false)">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="program-form">{{ $isProgramEditing ? __('Save program') : __('Add program') }}</x-ui.button>
        </x-slot:footer>
    </x-slide-over>
@endif

{{-- Subprogramme panel --}}
@if($subprogramModalOpen)
    <x-slide-over close="$set('subprogramModalOpen', false)">
        <x-slot:eyebrow>{{ __('Program structure :year', ['year' => $fundingYear]) }}</x-slot:eyebrow>
        <x-slot:title>{{ $isSubprogramEditing ? __('Edit subprogram') : __('New subprogram') }}</x-slot:title>

        <form id="subprogram-form" wire:submit="saveSubprogram" class="space-y-5 p-6">
            <div>
                <label class="field-label" for="f-subProgramId">{{ __('Program') }}</label>
                <select id="f-subProgramId" wire:model="subProgramId" class="flux-input">
                    <option value="">{{ __('Select a program') }}</option>
                    @foreach($yearPrograms as $yp)<option value="{{ $yp->id }}">{{ $yp->oge_code }} — {{ $yp->name }}</option>@endforeach
                </select>
                <x-input-error :messages="$errors->get('subProgramId')" />
            </div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-[8rem_1fr]">
                <div>
                    <label class="field-label" for="f-subCode">{{ __('Code') }}</label>
                    <input id="f-subCode" type="text" wire:model="subCode" class="flux-input font-mono" placeholder="{{ __('e.g. 51001') }}">
                    <x-input-error :messages="$errors->get('subCode')" />
                </div>
                <div>
                    <label class="field-label" for="f-subName">{{ __('Name') }}</label>
                    <input id="f-subName" type="text" wire:model="subName" class="flux-input">
                    <x-input-error :messages="$errors->get('subName')" />
                </div>
            </div>
            @if(trim((string) $subObjective) !== '')
                <div class="rounded-md border border-zinc-200 bg-zinc-50 p-4">
                    <p class="text-xs font-medium text-zinc-600">{{ __('Written before outputs were recorded. The output now says what the subprogram delivers; this text is kept.') }}</p>
                    <label class="field-label mt-3" for="f-subObjective">{{ __('Objective') }}</label>
                    <textarea id="f-subObjective" wire:model="subObjective" rows="2" class="flux-input"></textarea>
                </div>
            @endif
            @unless($isSubprogramEditing)
                <p class="text-xs text-zinc-500">{{ __('Then add its output: the short-term result it delivers, which its activities serve.') }}</p>
            @endunless
        </form>

        @if($isSubprogramEditing && $subprogramId)
            <div class="border-t border-zinc-200">
                <livewire:pages.management.structure-details type="subprogram" :id="$subprogramId" :results="false" :key="'al-sub-'.$subprogramId" />
            </div>
        @endif

        <x-slot:footer>
            <x-ui.button type="button" variant="secondary" shadow="none" wire:click="$set('subprogramModalOpen', false)">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="subprogram-form">{{ $isSubprogramEditing ? __('Save subprogram') : __('Add subprogram') }}</x-ui.button>
        </x-slot:footer>
    </x-slide-over>
@endif

{{-- Outcome or output panel --}}
@if($resultKind && $resultOwner)
    @php $isOutput = $resultKind === 'output'; @endphp
    <x-slide-over close="closeResult()">
        <x-slot:eyebrow>{{ $isOutput ? __('Subprogram').' '.$resultOwner->code.' · '.$limit($resultOwner->name, 60) : __('Program').' '.$resultOwner->oge_code.' · '.$limit($resultOwner->name, 60) }}</x-slot:eyebrow>
        <x-slot:title>{{ $isOutput ? ($resultId ? __('Edit output') : __('New output')) : ($resultId ? __('Edit outcome') : __('New outcome')) }}</x-slot:title>
        <x-slot:subtitle>{{ $isOutput ? __('The short-term result the subprogram delivers (Resultado de Curto Prazo).') : __('The medium-term objective the program pursues (Objetivo de Médio Prazo).') }}</x-slot:subtitle>

        <form id="result-form" wire:submit="saveResult" class="space-y-5 p-6">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-[10rem_1fr]">
                <div>
                    <label class="field-label" for="f-rCode">{{ __('Code') }} <span class="font-normal text-zinc-500">({{ __('optional') }})</span></label>
                    <input id="f-rCode" type="text" wire:model="rCode" class="flux-input font-mono" placeholder="{{ $isOutput ? __('e.g. 50204.0.0') : __('e.g. 502.0.1') }}">
                    <x-input-error :messages="$errors->get('rCode')" />
                </div>
                @if($isOutput)
                    <div>
                        <label class="field-label" for="f-rOutcome">{{ __('Outcome it serves') }}</label>
                        <select id="f-rOutcome" wire:model="rOutcome" class="flux-input">
                            <option value="">{{ $resultOutcomes->isEmpty() ? __('The program has no outcomes yet') : __('Select an outcome') }}</option>
                            @foreach($resultOutcomes as $oc)<option value="{{ $oc->id }}">{{ $limit($oc->label(), 90) }}</option>@endforeach
                        </select>
                        <x-input-error :messages="$errors->get('rOutcome')" />
                    </div>
                @endif
            </div>
            <div>
                <label class="field-label" for="f-rStatement">{{ $isOutput ? __('Output (the short-term result)') : __('Outcome (the medium-term objective)') }}</label>
                <textarea id="f-rStatement" wire:model="rStatement" rows="2" class="flux-input" placeholder="{{ __('As the State Budget states it, e.g. Maritime transport facilities working well') }}"></textarea>
                <x-input-error :messages="$errors->get('rStatement')" />
            </div>
            <fieldset class="rounded-md border border-zinc-200 p-4">
                <legend class="px-1 text-xs font-semibold text-zinc-700">{{ __('How it is measured') }}</legend>
                <label class="field-label" for="f-rName">{{ __('Indicator') }}</label>
                <input id="f-rName" type="text" wire:model="rName" class="flux-input" placeholder="{{ __('e.g. % of public institutions with secure connectivity') }}">
                <x-input-error :messages="$errors->get('rName')" />
                <div class="mt-3 grid grid-cols-2 gap-3">
                    <div>
                        <label class="field-label" for="f-rBaseline">{{ __('Starting point') }}</label>
                        <input id="f-rBaseline" type="text" wire:model="rBaseline" class="flux-input">
                    </div>
                    <div>
                        <label class="field-label" for="f-rTarget">{{ __('Target') }}</label>
                        <input id="f-rTarget" type="text" wire:model="rTarget" class="flux-input">
                    </div>
                </div>
                <label class="field-label mt-3" for="f-rMeans">{{ __('Means of verification') }} <span class="font-normal text-zinc-500">({{ __('optional') }})</span></label>
                <textarea id="f-rMeans" wire:model="rMeans" rows="2" class="flux-input"></textarea>
            </fieldset>
        </form>

        <x-slot:footer>
            <x-ui.button type="button" variant="secondary" shadow="none" wire:click="closeResult">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="result-form">{{ $isOutput ? __('Save output') : __('Save outcome') }}</x-ui.button>
        </x-slot:footer>
    </x-slide-over>
@endif
