<div class="space-y-12">
    <!-- Rule of Thumb / Interactive Permission Hub -->
    <div class="bg-zinc-900 rounded-xl p-10 text-white relative overflow-hidden shadow-sm ">
        <div class="absolute top-0 right-0 w-[500px] h-[500px] bg-indigo-500/10 rounded-full blur-[100px] -mr-64 -mt-64 animate-pulse"></div>
        <div class="absolute bottom-0 left-0 w-64 h-64 bg-emerald-500/5 rounded-full blur-[80px] -ml-32 -mb-32"></div>

        <div class="relative z-10 flex flex-col md:flex-row gap-12 items-center">
            <div class="flex-1 space-y-6">
                <div class="flex items-center gap-4">
                    <span class="px-3 py-1.5 bg-indigo-600 rounded-xl text-xs font-semibold shadow-sm ">{{ __('Security Protocol') }}</span>
                    <div class="h-px w-12 bg-zinc-700"></div>
                </div>
                <h3 class="text-4xl font-semibold italic leading-none">{{ __('Role-Based Authorization Matrix') }}</h3>
                <p class="text-zinc-400 text-sm leading-relaxed max-w-2xl">
                    {{ __('Define the boundaries of user capabilities by toggling permissions across system roles. Changes are applied in real-time to all users assigned to the respective role.') }}
                </p>
            </div>
            <div class="flex gap-4">
                 <div class="px-8 py-6 bg-white/5 backdrop-blur-sm rounded-xl border border-white/10 text-center min-w-[140px]">
                    <div class="text-3xl font-semibold text-indigo-400 leading-none">{{ $roles->count() }}</div>
                    <div class="text-xs font-semibold text-zinc-500 mt-2">{{ __('System Roles') }}</div>
                </div>
                <div class="px-8 py-6 bg-white/5 backdrop-blur-sm rounded-xl border border-white/10 text-center min-w-[140px]">
                    <div class="text-3xl font-semibold text-emerald-400 leading-none">{{ \Spatie\Permission\Models\Permission::count() }}</div>
                    <div class="text-xs font-semibold text-zinc-500 mt-2">{{ __('Global Rules') }}</div>
                </div>
            </div>
        </div>
    </div>

    <!-- The Matrix Card -->
    <div class="bg-white rounded-xl shadow-sm border border-zinc-100 overflow-hidden">
        <div class="px-10 py-8 border-b border-zinc-50 bg-zinc-50/50 flex justify-between items-center">
            <div>
                <h4 class="text-sm font-semibold text-zinc-900">{{ __('Capability Matrix') }}</h4>
                <p class="text-xs font-bold text-zinc-400 mt-1">{{ __('Cross-Functional Access Control') }}</p>
            </div>
            <div class="flex gap-2">
                @foreach($roles as $role)
                    <div class="px-3 py-1 bg-zinc-100 rounded-lg text-xs font-semibold text-zinc-500">{{ $role->name }}</div>
                @endforeach
            </div>
        </div>

        <div class="overflow-x-auto">
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
                                                {{ $role->hasPermissionTo($perm->name) ? 'checked' : '' }}
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