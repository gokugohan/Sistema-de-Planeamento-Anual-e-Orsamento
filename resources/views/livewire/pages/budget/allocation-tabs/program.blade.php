<div class="space-y-6">
    <div class="mb-4">
        <h3 class="text-lg font-black text-gray-900">Budget Allocation by Program</h3>
        <p class="text-xs font-medium text-gray-400 mt-1">Analysis of budget utilization across all organizational
            programs</p>
    </div>

    <table class="min-w-full text-sm">
        <thead class="text-[10px] font-black text-gray-400 uppercase tracking-widest border-b border-gray-50">
            <tr>
                <th class="px-6 py-4 text-left">Program</th>
                <th class="px-6 py-4 text-right">Allocated</th>
                <th class="px-6 py-4 text-right">Used</th>
                <th class="px-6 py-4 text-right">Remaining</th>
                <th class="px-6 py-4 text-right">Usage Rate</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            @foreach($this->programData as $row)
                <tr class="hover:bg-gray-50/50 transition-colors group">
                    <td class="px-6 py-5 font-bold text-gray-900">
                        <div class="flex items-baseline gap-2">
                            <span
                                class="text-[10px] bg-black text-white px-1.5 py-0.5 rounded leading-none">{{ $row['code'] }}</span>
                            <span>{{ $row['name'] }}</span>
                        </div>
                    </td>
                    <td class="px-6 py-5 text-right font-bold text-gray-900">$ {{ number_format($row['allocated'], 0) }}
                    </td>
                    <td class="px-6 py-5 text-right text-gray-500 font-medium">$ {{ number_format($row['used'], 0) }}</td>
                    <td class="px-6 py-5 text-right text-gray-500 font-medium">$ {{ number_format($row['remaining'], 0) }}
                    </td>
                    <td class="px-6 py-5 text-right font-black text-gray-900">{{ number_format($row['usage_pc'], 1) }}%</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot class="border-t-2 border-gray-100 bg-gray-50/50">
            <tr class="font-black text-gray-900">
                <td class="px-6 py-6 uppercase tracking-widest text-[10px]">Total Program Sum</td>
                <td class="px-6 py-6 text-right">$ {{ number_format($this->programData->sum('allocated'), 0) }}</td>
                <td class="px-6 py-6 text-right">$ {{ number_format($this->programData->sum('used'), 0) }}</td>
                <td class="px-6 py-6 text-right">$ {{ number_format($this->programData->sum('remaining'), 0) }}</td>
                @php 
                                        $prAlloc = $this->programData->sum('allocated');
                    $prUsed = $this->programData->sum('used');
                    $prPC = $prAlloc > 0 ? ($prUsed / $prAlloc) * 100 : 0;
                @endphp
                <td class="px-6 py-6 text-right">{{ number_format($prPC, 1) }}%</td>
            </tr>
        </tfoot>
    </table>
</div>
