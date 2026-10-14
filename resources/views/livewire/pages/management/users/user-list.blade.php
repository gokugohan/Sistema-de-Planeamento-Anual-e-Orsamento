<div class="space-y-4">
    <!-- Filters & Search -->
    <div
        class="flex flex-col md:flex-row justify-between items-center gap-4 bg-white p-4 rounded-xl border border-gray-100 shadow-sm">
        <div class="relative w-full md:w-96 group">
            <span class="absolute inset-y-0 left-0 pl-4 flex items-center text-gray-400">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8" />
                    <path d="m21 21-4.3-4.3" />
                </svg>
            </span>
            <input type="text" wire:model.live.debounce.300ms="search"
                placeholder="{{ __('Search users by name or email...') }}"
                class="block w-full pl-11 pr-4 h-11 bg-gray-50/50 border-transparent rounded-xl text-sm focus:ring-1 focus:ring-black focus:border-black focus:bg-white transition-all">
        </div>

        <div class="flex items-center gap-2">
            <span class="text-xs font-semibold text-gray-400 ">{{ $users->total() }}
                {{ __('Registered Users') }}</span>
        </div>
    </div>

    <!-- Users Table -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
        <table class="w-full text-left border-collapse">
            <thead>
                <tr class="bg-gray-50/50">
                    <th
                        class="px-8 py-5 text-xs font-semibold text-gray-400 border-b border-gray-50">
                        {{ __('Name & Identity') }}
                    </th>
                    <th
                        class="px-8 py-5 text-xs font-semibold text-gray-400 border-b border-gray-50">
                        {{ __('Security Role') }}
                    </th>
                    <th
                        class="px-8 py-5 text-xs font-semibold text-gray-400 border-b border-gray-50">
                        {{ __('Directorate-General') }}
                    </th>
                    <th
                        class="px-8 py-5 text-xs font-semibold text-gray-400 border-b border-gray-50">
                        {{ __('Status') }}
                    </th>
                    <th class="px-8 py-5 text-right border-b border-gray-50"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @forelse($users as $user)
                    <tr class="group hover:bg-gray-50/30 transition-colors">
                        <td class="px-8 py-6">
                            <div class="flex items-center gap-4">
                                <div
                                    class="w-10 h-10 rounded-xl bg-indigo-600 text-white flex items-center justify-center font-semibold text-xs shadow-sm shadow-black/10">
                                    {{ substr($user->name, 0, 2) }}
                                </div>
                                <div>
                                    <h4 class="text-sm font-semibold text-gray-900 leading-tight">{{ $user->name }}</h4>
                                    <p class="text-xs text-gray-400 mt-0.5">{{ $user->email }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-8 py-6">
                            @php $role = $user->roles->first(); @endphp
                            <span
                                class="inline-flex items-center gap-1.5 px-3 py-1 transparent text-xs font-semibold border border-gray-200 rounded-lg text-gray-600">
                                <span class="w-1 h-1 bg-indigo-500 rounded-full"></span>
                                {{ $role?->display_name ? __($role->display_name) : ($role?->name ? __($role->name) : __('No Role')) }}
                            </span>
                        </td>
                        <td class="px-8 py-6 text-sm">
                            <span
                                class="font-bold text-gray-700 italic tracking-tight">{{ $user->directorate->code ?? __('National Authority') }}</span>
                        </td>
                        <td class="px-8 py-6">
                            <span
                                class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-green-50 text-green-700 text-xs font-semibold rounded-lg border border-green-100">
                                <span class="w-1.5 h-1.5 bg-green-500 rounded-full animate-pulse"></span>
                                {{ __('Active') }}
                            </span>
                        </td>
                        <td class="px-8 py-6 text-right">
                            <div class="flex justify-end items-center gap-2">
                                <button wire:click="openModal({{ $user->id }})" title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}"
                                    class="p-2 text-gray-400 hover:text-black hover:bg-gray-100 rounded-xl transition-all">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                                        stroke-linejoin="round">
                                        <path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z" />
                                        <path d="m15 5 4 4" />
                                    </svg>
                                </button>
                                @if($user->id !== auth()->id() && auth()->user()->isAdministrator())
                                    @if($user->two_factor_confirmed_at)
                                        <button wire:click="resetTwoFactor({{ $user->id }})"
                                            wire:confirm="{{ __('Reset two-factor authentication for this user? Use this when they lost their phone.') }}"
                                            class="p-2 text-gray-400 hover:text-amber-600 hover:bg-amber-50 rounded-xl transition-all"
                                            title="{{ __('Reset two-factor') }}" aria-label="{{ __('Reset two-factor') }}">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/><path d="M9.5 9.5 14.5 14.5"/><path d="m14.5 9.5-5 5"/></svg>
                                        </button>
                                    @endif
                                    <button wire:click="resetPassword({{ $user->id }})"
                                        wire:confirm="{{ __('Issue a new temporary password for this user? Their current password stops working.') }}"
                                        class="p-2 text-gray-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-xl transition-all"
                                        title="{{ __('Reset password') }}" aria-label="{{ __('Reset password') }}">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/></svg>
                                    </button>
                                    <button wire:click="deleteUser({{ $user->id }})" title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}"
                                        wire:confirm="{{ __('Are you sure you want to delete this user?') }}"
                                        class="p-2 text-gray-300 hover:text-red-600 hover:bg-red-50 rounded-xl transition-all">
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
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-8 py-12 text-center text-gray-400 italic text-sm">
                            {{ __('No users found matching your criteria.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <!-- Pagination -->
        <div class="px-8 py-4 bg-gray-50/30 border-t border-gray-50">
            {{ $users->links() }}
        </div>
    </div>
</div>