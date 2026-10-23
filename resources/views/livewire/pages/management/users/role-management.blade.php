<div class="space-y-12">
    @php $granted = $roles->mapWithKeys(fn ($r) => [$r->id => $r->permissions->pluck('name')->flip()]); @endphp

    <!-- The Matrix Card -->
    <div class="bg-white rounded-xl shadow-sm border border-zinc-100 overflow-hidden">
        <div class="px-10 py-8 border-b border-zinc-50 bg-zinc-50/50 flex justify-between items-center">
            <div>
                <h4 class="text-sm font-semibold text-zinc-900">{{ __('Roles and permissions') }}</h4>
                <p class="mt-1 text-xs text-zinc-500">{{ __('Switch a permission on or off for a role: it applies at once to everyone who has that role.') }}</p>
            </div>
            <div class="flex items-center gap-5 text-xs font-semibold text-zinc-500">
                <span>{{ $roles->count() }} {{ __('System Roles') }}</span>
                <span>{{ $permissions->sum(fn ($g) => $g->count()) }} {{ __('Permissions') }}</span>
            </div>
        </div>

        <div class="overflow-x-auto" tabindex="0">
            <table class="min-w-full divide-y divide-zinc-100">
                <thead>
                    <tr class="bg-white">
                        <th class="px-10 py-6 text-left text-xs font-semibold text-zinc-400 border-r border-zinc-50 bg-zinc-50/10">{{ __('System Module / Permission') }}</th>
                        @foreach($roles as $role)
                            <th class="px-6 py-6 text-center text-xs font-semibold text-zinc-900 min-w-[120px]">
                                {{ $role->display_name ?: $role->name }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-50 bg-white">
                    @foreach($permissions as $module => $modulePermissions)
                        <tr class="bg-zinc-50/30">
                            <td colspan="{{ $roles->count() + 1 }}" class="px-10 py-3 text-xs font-semibold text-indigo-600 bg-indigo-50/30">
                                {{ __($module) }}
                            </td>
                        </tr>
                        @foreach($modulePermissions as $perm)
                            <tr class="hover:bg-zinc-50/50 transition-colors group">
                                <td class="px-10 py-5 border-r border-zinc-50">
                                    <div class="text-xs font-semibold text-zinc-800">{{ __($perm->display_name ?: $perm->name) }}</div>
                                    <div class="text-xs text-zinc-400 font-bold mt-1 max-w-xs">{{ __($perm->description) }}</div>
                                </td>
                                @foreach($roles as $role)
                                    <td class="px-6 py-5 text-center">
                                        <label class="relative inline-flex items-center cursor-pointer">
                                            <input type="checkbox" 
                                                wire:click="togglePermission({{ $role->id }}, '{{ $perm->name }}')"
                                                {{ isset($granted[$role->id][$perm->name]) ? 'checked' : '' }}
                                                class="sr-only peer">
                                            <div class="w-10 h-5 bg-zinc-100 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-indigo-600 shadow-inner"></div>
                                        </label>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>