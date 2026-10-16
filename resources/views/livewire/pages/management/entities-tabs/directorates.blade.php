<x-ui.card padding="p-0">
    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-center gap-6">
            <div>
                <h2 class="text-sm font-semibold text-zinc-900 ">
                    {{ __('Organisational units') }}
                </h2>
                <p class="text-xs text-zinc-400 font-bold mt-1">
                    {{ __('Directorates, offices and units') }}
                </p>
            </div>
            <div class="relative group">
                <span
                    class="absolute inset-y-0 left-0 pl-4 flex items-center text-zinc-300 group-focus-within:text-indigo-500 transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8" />
                        <path d="m21 21-4.3-4.3" />
                    </svg>
                </span>
                <input type="text" wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search by code or name...') }}"
                    class="pl-12 h-11 w-72 bg-zinc-50 border-zinc-100 rounded-xl text-xs font-bold text-zinc-600 focus:ring-4 focus:ring-indigo-500/5 focus:border-indigo-500 focus:bg-white transition-all placeholder:text-zinc-300">
            </div>
        </div>
    </x-slot>

    <div class="overflow-x-auto" tabindex="0">
        <x-ui.table :headers="[__('Unit Code'), __('Identity & Hierarchy'), __('Type'), __('Director'), __('Approved plan') . ' ' . $fundingYear, __('Funded'), __('Status'), __('Actions')]">
            @forelse($directorates as $d)
                <tr class="hover:bg-gray-50/50 transition-colors group text-sm">
                    <td class="px-5 py-5 text-gray-900 font-semibold font-mono">{{ $d->code }}
                    </td>
                    <td class="px-5 py-5 min-w-[18rem]">
                        <div class="font-bold text-gray-900 {{ $d->parent ? 'pl-4 border-l-2 border-zinc-200' : '' }}">{{ $d->name }}</div>
                        @if($d->parent)
                            <div class="flex items-center gap-1.5 mt-1">
                                <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"
                                    class="text-indigo-400 rotate-90">
                                    <path d="M16 13L21 18L16 23" />
                                    <path d="M3 6V15C3 16.6569 4.34315 18 6 18H21" />
                                </svg>
                                <span
                                    class="text-xs text-indigo-400 font-semibold ">{{ $d->parent->code }}</span>
                            </div>
                        @endif
                    </td>
                    <td class="px-5 py-5">
                        <x-ui.badge variant="gray" class="!text-xs ">{{ __($d->type) }}</x-ui.badge>
                        @if($d->isAutonomous())
                            <x-ui.badge variant="indigo" class="!text-xs mt-1" title="{{ $d->legal_basis }}">{{ __('Autonomous') }}{{ $d->legal_form ? ' · '.$d->legal_form : '' }}</x-ui.badge>
                        @endif
                    </td>
                    <td class="px-5 py-5">
                        <div class="font-bold text-gray-900 leading-none text-xs">
                            {{ $d->director_name ?: '—' }}
                        </div>
                        @if($d->director_email)<div class="text-xs text-gray-400 mt-1 font-medium">{{ $d->director_email }}</div>@endif
                    </td>
                    @php $f = $dirFunding[$d->id] ?? ['n' => 0, 'plan' => 0, 'alloc' => 0]; $pct = $f['plan'] > 0 ? min(100, $f['alloc'] / $f['plan'] * 100) : null; @endphp
                    <td class="px-5 py-5 text-sm tabular-nums">
                        @if($f['plan'] > 0)
                            <div class="font-semibold text-zinc-900">$ {{ number_format($f['plan']) }}</div>
                            <div class="text-xs text-zinc-500">{{ trans_choice(':n activity|:n activities', $f['n'], ['n' => $f['n']]) }}</div>
                        @else
                            <span class="text-zinc-400">—</span>
                        @endif
                    </td>
                    <td class="px-5 py-5 w-40">
                        @if($pct !== null)
                            <div class="flex items-center gap-2">
                                <div class="h-1.5 flex-1 rounded-full bg-zinc-100 overflow-hidden"><div class="h-full rounded-full {{ $pct >= 99.5 ? 'bg-emerald-500' : 'bg-amber-500' }}" style="width: {{ $pct }}%"></div></div>
                                <span class="w-10 text-right text-xs tabular-nums text-zinc-600">{{ number_format($pct, 0) }}%</span>
                            </div>
                        @else
                            <span class="text-zinc-400">—</span>
                        @endif
                    </td>
                    <td class="px-5 py-5">
                        @if($d->active)
                            <x-ui.badge variant="emerald" pulse="true">{{ __('Operational') }}</x-ui.badge>
                        @else
                            <x-ui.badge variant="red">{{ __('Inactive') }}</x-ui.badge>
                        @endif
                    </td>
                    <td class="px-5 py-5 text-right">
                        <div class="flex justify-end items-center gap-2">
                            <button wire:click="openModal({{ $d->id }})"
                                class="p-2 text-zinc-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-xl transition-all"
                                title="{{ __('Edit') }}">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                                    stroke-linejoin="round">
                                    <path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z" />
                                    <path d="m15 5 4 4" />
                                </svg>
                            </button>
                            <button wire:click="deleteDirectorate({{ $d->id }})"
                                wire:confirm="{{ __('Confirm deletion? This will remove the node permanently.') }}"
                                class="p-2 text-zinc-300 hover:text-rose-600 hover:bg-rose-50 rounded-xl transition-all"
                                title="{{ __('Delete') }}">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                                    stroke-linejoin="round">
                                    <path d="M3 6h18" />
                                    <path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6" />
                                    <path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2" />
                                    <line x1="10" x2="10" y1="11" y2="17" />
                                    <line x1="14" x2="14" y1="11" y2="17" />
                                </svg>
                            </button>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6"><x-ui.empty-state :title="__('No directorates found.')" /></td>
                </tr>
            @endforelse
        </x-ui.table>
    </div>
</x-ui.card>

<div class="px-8">
    {{ $directorates->links() }}
</div>

<!-- CRUD Modal -->
@if($modalOpen)
    <x-dialog max-width="2xl" close="$set('modalOpen', false)">
<x-slot:title>{{ $isEditing ? __('Update Structural Unit') : __('Create Structural Unit') }}</x-slot:title>
<x-slot:subtitle>{{ __('Core Infrastructure Node Definition') }}</x-slot:subtitle>

            <form wire:submit="saveDirectorate" class="p-6 space-y-5">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="field-label" for="f-code">{{ __('Unit Code') }}</label>
<input id="f-code" type="text" wire:model="code"
                            class="flux-input"
                            placeholder="e.g. DGPL">
                        <x-input-error :messages="$errors->get('code')" />
                    </div>
                    <div>
                        <label class="field-label" for="f-name">{{ __('Unit Name') }}</label>
<input id="f-name" type="text" wire:model="name"
                            class="flux-input"
                            placeholder="e.g. Planning Directorate">
                        <x-input-error :messages="$errors->get('name')" />
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="field-label" for="f-type">{{ __('Classification') }}</label>
<select id="f-type" wire:model.live="type"
                            class="flux-input">
                            <option value="Directorate General">{{ __('Directorate General') }}</option>
                            <option value="National Directorate">{{ __('National Directorate') }}</option>
                            <option value="Department">{{ __('Department') }}</option>
                            <option value="Unit">{{ __('Unit') }}</option>
                            <option value="Cabinet">{{ __('Cabinet') }}</option>
                            <option value="Regional Office">{{ __('Regional Office') }}</option>
                        </select>
                        <x-input-error :messages="$errors->get('type')" />
                    </div>
                    <div>
                        <label class="field-label" for="f-parent_id">{{ __('Parent Authority') }}
                            @if($type === 'National Directorate') (DG Only)
                            @elseif($type === 'Department') (ND/DG Only)
                            @endif</label>
<select id="f-parent_id" wire:model="parent_id"
                            class="flux-input">
                            <option value="">{{ __('Top Level (Independent)') }}</option>
                            @foreach($filteredParents as $parent)
                                @if($parent->id != $directorateId)
                                    <option value="{{ $parent->id }}">{{ $parent->code }} - {{ $parent->name }}
                                        ({{ __($parent->type) }})</option>
                                @endif
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('parent_id')" />
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
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

                <div>
                    <label class="field-label" for="f-description">{{ __('Function Description') }}</label>
<textarea id="f-description" wire:model="description" rows="3"
                        class="flux-input"
                        placeholder="Primary role and responsibilities of this node..."></textarea>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="field-label" for="f-director_name">{{ __('Manager / Director') }}</label>
<input id="f-director_name" type="text" wire:model="director_name"
                            class="flux-input"
                            placeholder="Full name">
                    </div>
                    <div>
                        <label class="field-label" for="f-director_email">{{ __('Officer Email') }}</label>
<input id="f-director_email" type="email" wire:model="director_email"
                            class="flux-input"
                            placeholder="e.g. officer@msa.gov.tl">
                    </div>
                </div>

                <div class="flex items-center justify-between">
                    <label class="text-xs font-semibold text-zinc-400 ">
                        {{ __('Operational Status') }}
                    </label>
                    <button type="button" wire:click="$toggle('active')"
                        class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none {{ $active ? 'bg-emerald-500' : 'bg-zinc-200' }}">
                        <span
                            class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform {{ $active ? 'translate-x-6' : 'translate-x-1' }}"></span>
                    </button>
                </div>

                <div class="flex items-center gap-4 pt-4">
                    <x-ui.button type="button" wire:click="$set('modalOpen', false)" variant="secondary"
                        class="flex-1">{{ __('Go Back') }}</x-ui.button>
                    <x-ui.button type="submit" variant="primary"
                        class="flex-1">{{ $isEditing ? __('Sync Changes') : __('Deploy Unit') }}</x-ui.button>
                </div>
            </form>
</x-dialog>
@endif