@php
    $act = 'inline-flex h-8 w-8 items-center justify-center rounded-md text-zinc-500 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600';
@endphp
<div class="space-y-4">
    @if($noUnit > 0 && $unitFilter !== 'none')
        <div role="status" class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <span>{{ trans_choice(':n director or officer has no unit and cannot see any activity.|:n directors or officers have no unit and cannot see any activity.', $noUnit, ['n' => $noUnit]) }}</span>
            <button type="button" wire:click="$set('unitFilter', 'none')" class="font-medium underline">{{ __('Show them') }}</button>
        </div>
    @endif

    <x-ui.card padding="p-0">
        {{-- search and filters --}}
        <div class="flex flex-col gap-3 border-b border-zinc-200 px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
            <x-ui.search wire:model.live.debounce.300ms="search" :placeholder="__('Search users by name or email...')" />
            <div class="flex flex-wrap items-center gap-2">
                <label for="uf-unit" class="sr-only">{{ __('Unit') }}</label>
                <select id="uf-unit" wire:model.live="unitFilter" class="flux-input !w-auto min-w-[12rem]">
                    <option value="">{{ __('All units') }}</option>
                    <option value="none">{{ __('No unit') }}</option>
                    @foreach($unitTree as $u)
                        <option value="{{ $u['id'] }}">{{ str_repeat('— ', $u['depth']) }}{{ $u['label'] }}</option>
                    @endforeach
                </select>
                <label for="uf-role" class="sr-only">{{ __('Security Role') }}</label>
                <select id="uf-role" wire:model.live="roleFilter" class="flux-input !w-auto">
                    <option value="">{{ __('All roles') }}</option>
                    @foreach($roleOptions as $r)
                        <option value="{{ $r->name }}">{{ __($r->display_name ?: $r->name) }}</option>
                    @endforeach
                </select>
                <label for="uf-status" class="sr-only">{{ __('Status') }}</label>
                <select id="uf-status" wire:model.live="statusFilter" class="flux-input !w-auto">
                    <option value="">{{ __('Every status') }}</option>
                    <option value="active">{{ __('Active') }}</option>
                    <option value="deactivated">{{ __('Deactivated') }}</option>
                </select>
                <span class="whitespace-nowrap text-xs text-zinc-500">{{ $users->total() }} {{ __('Registered Users') }}</span>
                @if($search !== '' || $unitFilter !== '' || $roleFilter !== '' || $statusFilter !== '')
                    <button type="button" wire:click="clearFilters" class="whitespace-nowrap text-xs font-medium text-indigo-700 hover:underline">{{ __('Clear filters') }}</button>
                @endif
            </div>
        </div>

        <div class="overflow-x-auto" tabindex="0">
            <table class="min-w-full text-sm">
                <thead class="bg-zinc-50 text-left text-xs font-medium text-zinc-500">
                    <tr>
                        <th scope="col" class="px-5 py-3">{{ __('Name & Identity') }}</th>
                        <th scope="col" class="px-5 py-3">{{ __('Security Role') }}</th>
                        <th scope="col" class="px-5 py-3">{{ __('Unit') }}</th>
                        <th scope="col" class="px-5 py-3">{{ __('Status') }}</th>
                        <th scope="col" class="px-5 py-3"><span class="sr-only">{{ __('Actions') }}</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @forelse($users as $user)
                        @php $role = $user->roles->first(); @endphp
                        <tr class="hover:bg-zinc-50/60" wire:key="user-{{ $user->id }}">
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-3">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-indigo-50 text-xs font-semibold uppercase text-indigo-700" aria-hidden="true">{{ mb_substr($user->name, 0, 2) }}</span>
                                    <div class="min-w-0">
                                        <p class="font-medium text-zinc-900">{{ $user->name }}</p>
                                        <p class="text-xs text-zinc-500">{{ $user->email }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-3">
                                {{-- a head shows the title of the level they lead; the role underneath --}}
                                <span class="text-zinc-900">{{ $role ? $user->roleTitle() : __('No Role') }}</span>
                                @if($role?->name === 'Director' && $user->directorate)
                                    <span class="block text-xs text-zinc-500">{{ __('Head') }}</span>
                                @endif
                            </td>
                            <td class="px-5 py-3">
                                @if($user->directorate)
                                    <a href="{{ route('units.show', $user->directorate->id) }}" wire:navigate title="{{ __('Open the unit') }}" class="font-medium text-indigo-700 hover:underline">{{ $user->directorate->code }}</a>
                                    <span class="block max-w-[16rem] truncate text-xs text-zinc-500">{{ $user->directorate->name }}</span>
                                @else
                                    <span class="text-xs text-zinc-500">{{ __('No unit') }}</span>
                                @endif
                            </td>
                            <td class="px-5 py-3">
                                @if($user->isActive())
                                    <x-ui.badge variant="emerald">{{ __('Active') }}</x-ui.badge>
                                @else
                                    <x-ui.badge variant="gray">{{ __('Deactivated') }}</x-ui.badge>
                                    <span class="mt-1 block text-xs text-zinc-500">{{ __('since :date', ['date' => $user->deactivated_at->format('d/m/Y')]) }}</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-5 py-3 text-right">
                                <x-ui.icon-action icon="edit" :label="__('Edit').' '.$user->name" wire:click="openModal({{ $user->id }})" />
                                @if(auth()->user()->isAdministrator())
                                    <a href="{{ route('management.audit', ['userId' => $user->id]) }}" wire:navigate title="{{ __('Activity of this person') }}" aria-label="{{ __('Activity of this person') }}" class="{{ $act }} hover:bg-indigo-50 hover:text-indigo-700">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 8v4l3 3"/><circle cx="12" cy="12" r="9"/></svg>
                                    </a>
                                @endif
                                @if($user->id !== auth()->id() && auth()->user()->isAdministrator())
                                    @if($user->two_factor_confirmed_at)
                                        <button type="button" wire:click="resetTwoFactor({{ $user->id }})" wire:confirm="{{ __('Reset two-factor authentication for this user? Use this when they lost their phone.') }}"
                                            class="{{ $act }} hover:bg-amber-50 hover:text-amber-700" title="{{ __('Reset two-factor') }}" aria-label="{{ __('Reset two-factor') }}">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/><path d="M9.5 9.5 14.5 14.5"/><path d="m14.5 9.5-5 5"/></svg>
                                        </button>
                                    @endif
                                    @if($user->isActive())
                                        <button type="button" wire:click="toggleActive({{ $user->id }})" wire:confirm="{{ __('Deactivate this account? The person can no longer sign in; their history stays. You can reactivate it at any time.') }}"
                                            class="{{ $act }} hover:bg-amber-50 hover:text-amber-700" title="{{ __('Deactivate') }}" aria-label="{{ __('Deactivate') }}">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="m4.9 4.9 14.2 14.2"/></svg>
                                        </button>
                                    @else
                                        <button type="button" wire:click="toggleActive({{ $user->id }})" class="{{ $act }} hover:bg-emerald-50 hover:text-emerald-700" title="{{ __('Reactivate') }}" aria-label="{{ __('Reactivate') }}">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>
                                        </button>
                                    @endif
                                    <button type="button" wire:click="resetPassword({{ $user->id }})" wire:confirm="{{ __('Issue a new temporary password for this user? Their current password stops working.') }}"
                                        class="{{ $act }} hover:bg-indigo-50 hover:text-indigo-700" title="{{ __('Reset password') }}" aria-label="{{ __('Reset password') }}">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/></svg>
                                    </button>
                                    <x-ui.icon-action icon="delete" :label="__('Delete').' '.$user->name" wire:click="deleteUser({{ $user->id }})" :confirm="__('Delete this account for good? To keep their history, deactivate it instead.')" />
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><x-ui.empty-state :title="__('No users found matching your criteria.')" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-zinc-200 px-5 py-3">{{ $users->links() }}</div>
    </x-ui.card>
</div>
