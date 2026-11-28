{{-- The organization: every unit under the one above it, who heads it, who works in it, and its approved plan. --}}
@if(count($nobodyDirects))
    <div role="status" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        {{ trans_choice(':n unit has nobody to direct it, so nobody can give the final approval of its activities: :codes|:n units have nobody to direct them, so nobody can give the final approval of their activities: :codes', count($nobodyDirects), ['n' => count($nobodyDirects), 'codes' => implode(', ', $nobodyDirects)]) }}
        <span class="ml-1 text-xs text-amber-800">{{ __('Give a user the Director role in the unit, in System Users.') }}</span>
    </div>
@endif

<x-ui.card padding="p-0">
    <div class="flex flex-col gap-3 border-b border-zinc-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-sm font-semibold text-zinc-900">{{ __('Organizational units') }}</h2>
            <p class="mt-0.5 text-xs text-zinc-500">{{ __('Each unit under the one above it, with its head, its people and its approved plan for :year.', ['year' => $fundingYear]) }}</p>
        </div>
        <x-ui.search wire:model.live.debounce.300ms="search" :placeholder="__('Search a unit by code or name')" />
    </div>

    <div class="overflow-x-auto" tabindex="0">
        <table class="min-w-full text-sm">
            <thead class="bg-zinc-50 text-left text-xs font-medium text-zinc-500">
                <tr>
                    <th scope="col" class="px-5 py-3">{{ __('Unit') }}</th>
                    <th scope="col" class="px-5 py-3">{{ __('Type') }}</th>
                    <th scope="col" class="px-5 py-3">{{ __('Director') }}</th>
                    <th scope="col" class="px-5 py-3 text-right">{{ __('People') }}</th>
                    <th scope="col" class="px-5 py-3 text-right">{{ __('Approved plan') }} {{ $fundingYear }}</th>
                    <th scope="col" class="px-5 py-3">{{ __('Funded') }}</th>
                    <th scope="col" class="px-5 py-3">{{ __('Status') }}</th>
                    <th scope="col" class="px-5 py-3"><span class="sr-only">{{ __('Actions') }}</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @forelse($directorates as $d)
                    @php $f = $dirFunding[$d->id] ?? ['n' => 0, 'plan' => 0, 'alloc' => 0]; $pct = $f['plan'] > 0 ? min(100, $f['alloc'] / $f['plan'] * 100) : null; @endphp
                    <tr class="hover:bg-zinc-50/60" wire:key="unit-{{ $d->id }}">
                        <td class="px-5 py-3">
                            <div class="flex items-start gap-3 {{ $d->parent ? 'pl-6' : '' }}">
                                @if($d->parent)<span class="mt-1 h-3 w-3 shrink-0 rounded-bl border-b-2 border-l-2 border-zinc-300" aria-hidden="true"></span>@endif
                                <div class="min-w-0">
                                    <p class="font-medium text-zinc-900">{{ $d->name }}</p>
                                    <p class="mt-0.5 text-xs text-zinc-500">
                                        <span class="font-mono">{{ $d->code }}</span>
                                        @if($d->budget_code) · {{ __('Div') }} <span class="font-mono">{{ $d->budget_code }}</span>@endif
                                        @if($d->parent) · {{ __('under :unit', ['unit' => $d->parent->code]) }}@endif
                                    </p>
                                </div>
                            </div>
                        </td>
                        <td class="px-5 py-3">
                            <span class="text-zinc-700">{{ __($d->type) }}</span>
                            @if($d->isAutonomous())
                                <x-ui.badge variant="indigo" class="ml-1" title="{{ $d->legal_basis }}">{{ __('Autonomous') }}{{ $d->legal_form ? ' · '.$d->legal_form : '' }}</x-ui.badge>
                            @endif
                        </td>
                        <td class="px-5 py-3">
                            @if(isset($directorsByUnit[$d->id]))
                                <span class="text-zinc-900">{{ implode(', ', $directorsByUnit[$d->id]) }}</span>
                            @elseif(isset($directedFrom[$d->id]))
                                <span class="text-xs text-zinc-500">{{ __('Directed from :unit', ['unit' => $directedFrom[$d->id]]) }}</span>
                            @elseif($d->active)
                                <x-ui.badge variant="amber">{{ __('No Director') }}</x-ui.badge>
                            @else
                                <span class="text-zinc-400">—</span>
                            @endif
                            @if($d->director_name && ! isset($directorsByUnit[$d->id]))<p class="mt-0.5 text-xs text-zinc-500">{{ $d->director_name }}</p>@endif
                        </td>
                        <td class="px-5 py-3 text-right tabular-nums">
                            @if(($peopleByUnit[$d->id] ?? 0) > 0)
                                <a href="{{ route('management.users', ['unit' => $d->id]) }}" wire:navigate class="font-medium text-indigo-700 hover:underline" title="{{ __('See them in System Users') }}">{{ $peopleByUnit[$d->id] }}</a>
                            @else
                                <a href="{{ route('management.users', ['unit' => $d->id, 'new' => 1]) }}" wire:navigate class="text-xs font-medium text-zinc-500 hover:text-indigo-700 hover:underline" title="{{ __('Add a person to this unit') }}">+ {{ __('Add') }}</a>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-right tabular-nums">
                            @if($f['plan'] > 0)
                                <p class="font-medium text-zinc-900">$ {{ number_format($f['plan']) }}</p>
                                <p class="text-xs text-zinc-500">{{ trans_choice(':n activity|:n activities', $f['n'], ['n' => $f['n']]) }}</p>
                            @else
                                <span class="text-zinc-400">—</span>
                            @endif
                        </td>
                        <td class="w-40 px-5 py-3">
                            @if($pct !== null)
                                <div class="flex items-center gap-2">
                                    <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-zinc-100"><div class="h-full rounded-full {{ $pct >= 99.5 ? 'bg-emerald-500' : 'bg-amber-500' }}" style="width: {{ $pct }}%"></div></div>
                                    <span class="w-10 text-right text-xs tabular-nums text-zinc-600">{{ number_format($pct, 0) }}%</span>
                                </div>
                            @else
                                <span class="text-zinc-400">—</span>
                            @endif
                        </td>
                        <td class="px-5 py-3">
                            @if($d->active)
                                <x-ui.badge variant="emerald">{{ __('Active') }}</x-ui.badge>
                            @else
                                <x-ui.badge variant="gray">{{ __('Inactive') }}</x-ui.badge>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-5 py-3 text-right">
                            <x-ui.icon-action icon="edit" :label="__('Edit').' '.$d->code" wire:click="openModal({{ $d->id }})" />
                            <x-ui.icon-action icon="delete" :label="__('Delete').' '.$d->code" wire:click="deleteDirectorate({{ $d->id }})" :confirm="__('Delete this unit? This cannot be undone.')" />
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8"><x-ui.empty-state :title="__('No units found.')" /></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-ui.card>
<div>{{ $directorates->links() }}</div>

{{-- Unit panel --}}
@if($modalOpen)
    <x-slide-over close="$set('modalOpen', false)">
        <x-slot:eyebrow>{{ __('Organization') }}</x-slot:eyebrow>
        <x-slot:title>{{ $isEditing ? __('Edit unit') : __('New unit') }}</x-slot:title>

        <form id="unit-form" wire:submit="saveDirectorate" class="space-y-6 p-6">
            <fieldset class="space-y-4">
                <legend class="text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ __('Identity') }}</legend>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-[9rem_1fr]">
                    <div>
                        <label class="field-label" for="f-code">{{ __('Code') }}</label>
                        <input id="f-code" type="text" wire:model="code" class="flux-input font-mono" placeholder="{{ __('e.g. DNTT') }}">
                        <x-input-error :messages="$errors->get('code')" />
                    </div>
                    <div>
                        <label class="field-label" for="f-name">{{ __('Name') }}</label>
                        <input id="f-name" type="text" wire:model="name" class="flux-input">
                        <x-input-error :messages="$errors->get('name')" />
                    </div>
                </div>
                <div>
                    <label class="field-label" for="f-budget-code">{{ __('State Budget division code (Div)') }} <span class="font-normal text-zinc-500">({{ __('optional') }})</span></label>
                    <input id="f-budget-code" type="text" wire:model="budget_code" class="flux-input font-mono sm:w-48" placeholder="{{ __('e.g. 0250202') }}" aria-describedby="f-budget-code-hint">
                    <p id="f-budget-code-hint" class="field-hint">{{ __('The code the annual plans of the State Budget give this unit. The final plan report prints it beside the unit.') }}</p>
                    <x-input-error :messages="$errors->get('budget_code')" />
                </div>
            </fieldset>

            <fieldset class="space-y-4">
                <legend class="text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ __('Place in the structure') }}</legend>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="field-label" for="f-type">{{ __('Type') }}</label>
                        <select id="f-type" wire:model.live="type" class="flux-input">
                            <option value="Directorate General">{{ __('Directorate General') }}</option>
                            <option value="National Directorate">{{ __('National Directorate') }}</option>
                            <option value="Department">{{ __('Department') }}</option>
                            <option value="Unit">{{ __('Unit') }}</option>
                            <option value="Cabinet">{{ __('Cabinet') }}</option>
                            <option value="Regional Office">{{ __('Regional Office') }}</option>
                            <option value="Independent">{{ __('Autonomous body') }}</option>
                        </select>
                        <x-input-error :messages="$errors->get('type')" />
                    </div>
                    <div>
                        <label class="field-label" for="f-parent_id">{{ __('Unit above') }}</label>
                        <select id="f-parent_id" wire:model="parent_id" class="flux-input">
                            <option value="">{{ __('None: a top-level unit') }}</option>
                            @foreach($filteredParents as $parent)
                                @if($parent->id != $directorateId)
                                    <option value="{{ $parent->id }}">{{ $parent->code }} — {{ $parent->name }}</option>
                                @endif
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('parent_id')" />
                    </div>
                </div>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div class="{{ $administration === 'indirect' ? '' : 'sm:col-span-3' }}">
                        <label class="field-label" for="f-administration">{{ __('Administration') }}</label>
                        <select id="f-administration" wire:model.live="administration" class="flux-input">
                            <option value="direct">{{ __('Direct (service of the ministry)') }}</option>
                            <option value="indirect">{{ __('Indirect (autonomous public body)') }}</option>
                        </select>
                    </div>
                    @if($administration === 'indirect')
                        <div>
                            <label class="field-label" for="f-legal_form">{{ __('Legal form') }}</label>
                            <input id="f-legal_form" type="text" wire:model="legal_form" placeholder="I.P. / E.P." class="flux-input">
                        </div>
                        <div>
                            <label class="field-label" for="f-legal_basis">{{ __('Legal basis') }}</label>
                            <input id="f-legal_basis" type="text" wire:model="legal_basis" placeholder="{{ __('Decree-law and article') }}" class="flux-input">
                        </div>
                    @endif
                </div>
            </fieldset>

            <fieldset class="space-y-4">
                <legend class="text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ __('Details') }}</legend>
                <div>
                    <label class="field-label" for="f-description">{{ __('What the unit does') }} <span class="font-normal text-zinc-500">({{ __('optional') }})</span></label>
                    <textarea id="f-description" wire:model="description" rows="3" class="flux-input"></textarea>
                </div>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="field-label" for="f-director_name">{{ __('Head of the unit') }} <span class="font-normal text-zinc-500">({{ __('optional') }})</span></label>
                        <input id="f-director_name" type="text" wire:model="director_name" class="flux-input">
                    </div>
                    <div>
                        <label class="field-label" for="f-director_email">{{ __('Head\'s email') }} <span class="font-normal text-zinc-500">({{ __('optional') }})</span></label>
                        <input id="f-director_email" type="email" wire:model="director_email" class="flux-input">
                        <x-input-error :messages="$errors->get('director_email')" />
                    </div>
                </div>
                <p class="text-xs text-zinc-500">{{ __('Who approves is the user with the Director role in the unit, set in System Users; these two fields are for reference.') }}</p>
                <label class="flex items-center justify-between gap-4 rounded-md border border-zinc-200 px-4 py-3">
                    <span>
                        <span class="block text-sm font-medium text-zinc-900">{{ __('Active') }}</span>
                        <span class="block text-xs text-zinc-500">{{ __('An inactive unit takes no new activities; its history is kept.') }}</span>
                    </span>
                    <span class="relative inline-flex shrink-0 items-center">
                        <input type="checkbox" wire:model.live="active" class="peer sr-only" role="switch">
                        <span class="h-6 w-11 rounded-full bg-zinc-300 transition-colors peer-checked:bg-indigo-600 peer-focus-visible:ring-2 peer-focus-visible:ring-indigo-600 peer-focus-visible:ring-offset-2"></span>
                        <span class="pointer-events-none absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-[#fff] shadow transition-transform peer-checked:translate-x-5"></span>
                    </span>
                </label>
            </fieldset>
        </form>

        <x-slot:footer>
            <x-ui.button type="button" variant="secondary" shadow="none" wire:click="$set('modalOpen', false)">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="unit-form">{{ $isEditing ? __('Save unit') : __('Add unit') }}</x-ui.button>
        </x-slot:footer>
    </x-slide-over>
@endif
