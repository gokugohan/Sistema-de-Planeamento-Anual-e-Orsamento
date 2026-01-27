<div class="flex gap-8">
    <!-- Left Sidebar (Departments) -->
    <div class="w-1/4 border-r border-gray-100 pr-8 space-y-2">
        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-4">Departments</p>
        @foreach($this->directorates as $dir)
            <button wire:click="selectDirectorate({{ $dir->id }})" 
                class="w-full text-left p-4 rounded-2xl transition-all group {{ $selectedDirectorateId == $dir->id ? 'bg-gray-50 border border-gray-100 shadow-sm' : 'hover:bg-gray-50/50' }}">
                <h5 class="text-xs font-black {{ $selectedDirectorateId == $dir->id ? 'text-gray-900' : 'text-gray-500' }}">{{ $dir->name }}</h5>
                <p class="text-[10px] font-bold text-gray-400 uppercase mt-0.5">{{ $dir->code }}</p>
            </button>
        @endforeach
    </div>

    <!-- Right Content Area -->
    <div class="flex-1 space-y-6">
        @if($this->selectedDirectorate)
            <div class="mb-8">
                <h3 class="text-lg font-black text-gray-900">{{ $this->selectedDirectorate->name }} Budget Details</h3>
                <p class="text-xs font-medium text-gray-400 mt-1">Programs and projects under {{ $this->selectedDirectorate->name }} department</p>
            </div>

            <table class="min-w-full text-sm">
                <thead class="text-[10px] font-black text-gray-400 uppercase tracking-widest border-b border-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left">Program/Project</th>
                        <th class="px-4 py-3 text-left">Type</th>
                        <th class="px-4 py-3 text-right">Allocated</th>
                        <th class="px-4 py-3 text-right">Used</th>
                        <th class="px-4 py-3 text-right">Remaining</th>
                        <th class="px-4 py-3 text-right">Usage %</th>
                        <th class="px-4 py-3 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50/50">
                    @foreach($this->selectedDirectorate->grouped_data as $program)
                        <!-- Program Row -->
                        <tr class="bg-gray-50/30 group">
                            <td class="px-4 py-4 font-black text-gray-900">{{ $program->name }}</td>
                            <td class="px-4 py-4">
                                <span class="px-2 py-0.5 bg-gray-100 text-[9px] font-black uppercase tracking-tighter rounded">Program</span>
                            </td>
                            <td class="px-4 py-4 text-right font-bold text-gray-900">$ {{ number_format($program->allocated, 0) }}</td>
                            <td class="px-4 py-4 text-right text-gray-500 font-medium">$ {{ number_format($program->used, 0) }}</td>
                            <td class="px-4 py-4 text-right text-gray-500 font-medium">$ {{ number_format($program->remaining, 0) }}</td>
                            <td class="px-4 py-4 text-right font-black text-gray-900">{{ number_format($program->usage_pc, 1) }}%</td>
                            <td class="px-4 py-4 text-center">
                                <button class="p-1 px-3 border border-gray-200 rounded-lg text-[10px] font-bold hover:bg-white hover:shadow-sm transition-all flex items-center gap-1 mx-auto">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>
                                    Edit
                                </button>
                            </td>
                        </tr>

                        <!-- Project Rows -->
                        @foreach($program->projects as $project)
                            <tr class="hover:bg-gray-50/20 transition-colors">
                                <td class="px-4 py-3 pl-8 text-xs font-bold text-gray-600">{{ $project->description }}</td>
                                <td class="px-4 py-3">
                                    <span class="px-2 py-0.5 bg-white border border-gray-100 text-[9px] font-black uppercase tracking-tighter rounded text-gray-400">Project</span>
                                </td>
                                <td class="px-4 py-3 text-right text-xs font-bold text-gray-600">$ {{ number_format($project->allocated, 0) }}</td>
                                <td class="px-4 py-3 text-right text-xs text-gray-400">$ {{ number_format($project->used, 0) }}</td>
                                <td class="px-4 py-3 text-right text-xs text-gray-400">$ {{ number_format($project->remaining, 0) }}</td>
                                <td class="px-4 py-3 text-right text-xs font-bold text-gray-400">{{ number_format($project->usage_pc, 0) }}%</td>
                                <td class="px-4 py-3 text-center">
                                    <button class="text-gray-300 hover:text-indigo-600 transition-colors">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
                <tfoot class="border-t-2 border-gray-100 bg-gray-50/50">
                    <tr class="font-black text-gray-900">
                        <td colspan="2" class="px-4 py-4 uppercase tracking-widest text-[10px]">Total Department Sum</td>
                        <td class="px-4 py-4 text-right">$ {{ number_format($this->selectedDirectorate->grouped_data->sum('allocated'), 0) }}</td>
                        <td class="px-4 py-4 text-right">$ {{ number_format($this->selectedDirectorate->grouped_data->sum('used'), 0) }}</td>
                        <td class="px-4 py-4 text-right">$ {{ number_format($this->selectedDirectorate->grouped_data->sum('remaining'), 0) }}</td>
                        @php 
                            $totalAlloc = $this->selectedDirectorate->grouped_data->sum('allocated');
                            $totalUsed = $this->selectedDirectorate->grouped_data->sum('used');
                            $totalPC = $totalAlloc > 0 ? ($totalUsed / $totalAlloc) * 100 : 0;
                        @endphp
                        <td class="px-4 py-4 text-right">{{ number_format($totalPC, 1) }}%</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        @else
            <div class="h-64 flex items-center justify-center border-2 border-dashed border-gray-100 rounded-3xl">
                <p class="text-sm font-bold text-gray-300">Select a department to view details</p>
            </div>
        @endif
    </div>
</div>
