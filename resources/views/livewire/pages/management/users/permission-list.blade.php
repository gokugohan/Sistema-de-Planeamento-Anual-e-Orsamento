<div class="bg-white rounded-[2.5rem] border border-gray-100 shadow-xl overflow-hidden">
    <div class="p-8 bg-gray-50/50 border-b border-gray-100 flex justify-between items-center">
        <div>
            <h3 class="text-xl font-black text-gray-900 tracking-tight">{{ __('Matrix of Permissions') }}</h3>
            <p class="text-xs text-gray-400 mt-1 uppercase font-bold tracking-widest">{{ __('Cross-reference roles with specific system actions') }}</p>
        </div>
        
        <div class="flex items-center gap-6">
            <div class="flex items-center gap-2">
                <div class="w-3 h-3 rounded-full bg-indigo-600 shadow-lg shadow-indigo-600/30"></div>
                <span class="text-[10px] font-black uppercase tracking-tighter text-gray-500">{{ __('Granted') }}</span>
            </div>
            <div class="flex items-center gap-2">
                <div class="w-1.5 h-1.5 rounded-full bg-gray-200"></div>
                <span class="text-[10px] font-black uppercase tracking-tighter text-gray-400">{{ __('Denied') }}</span>
            </div>
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
            <thead>
                <tr>
                    <th class="px-8 py-6 text-[10px] font-black uppercase tracking-widest text-gray-400 border-b border-gray-50 bg-white sticky left-0 z-20">{{ __('Module & Action') }}</th>
                    @foreach($roles as $role)
                        <th class="px-6 py-6 text-center text-[10px] font-black uppercase tracking-widest text-gray-900 border-b border-gray-50 group">
                                {{ __($role->display_name ?: $role->name) }}
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($permissions as $module => $modulePermissions)
                    <tr class="bg-gray-50/30">
                        <td colspan="{{ $roles->count() + 1 }}" class="px-8 py-3 text-[9px] font-black uppercase tracking-[0.3em] text-indigo-500 bg-indigo-50/50 border-y border-indigo-100/30">
                            {{ __($module) }}
                        </td>
                    </tr>
                    @foreach($modulePermissions as $perm)
                        <tr class="hover:bg-gray-50/50 transition-colors">
                            <td class="px-8 py-5 border-r border-gray-50 sticky left-0 bg-white z-10 shadow-[4px_0_10px_-5px_rgba(0,0,0,0.05)]">
                                <div class="font-bold text-gray-900 text-sm tracking-tight">{{ __($perm->display_name ?: $perm->name) }}</div>
                                <div class="text-[10px] text-gray-400 italic mt-0.5">{{ __($perm->name) }}</div>
                            </td>
                            @foreach($roles as $role)
                                <td class="px-6 py-5 border-r border-gray-50/50 text-center">
                                    <div class="flex justify-center">
                                        @if($role->hasPermissionTo($perm->name))
                                            <div class="w-5 h-5 rounded-lg bg-indigo-600 text-white flex items-center justify-center shadow-lg shadow-indigo-600/40 animate-in zoom-in duration-300">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                            </div>
                                        @else
                                            <div class="w-1.5 h-1.5 rounded-full bg-gray-200"></div>
                                        @endif
                                    </div>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    </div>
    
    <div class="p-8 bg-gray-900 text-center">
        <p class="text-xs text-gray-500 font-medium italic">
            {{ __('These permissions are hardcoded in the system logic. Roles defined here determine the user\'s capability within the application modules.') }}
        </p>
    </div>
</div>
