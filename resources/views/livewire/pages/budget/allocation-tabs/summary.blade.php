<div class="space-y-6">
    <div class="mb-4">
        <h3 class="text-lg font-black text-gray-900">Budget Allocation Summary</h3>
        <p class="text-xs font-medium text-gray-400 mt-1">Overview of budget allocation across all departments</p>
    </div>

    <table class="min-w-full text-sm">
        <thead class="text-[10px] font-black text-gray-400 uppercase tracking-widest border-b border-gray-50">
            <tr>
                <th class="px-6 py-4 text-left">Department</th>
                <th class="px-6 py-4 text-right">Allocated Amount</th>
                <th class="px-6 py-4 text-right">Used Amount</th>
                <th class="px-6 py-4 text-right">Remaining</th>
                <th class="px-6 py-4 text-right">Usage Rate</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            @foreach($this->summaryData as $row)
                <tr class="hover:bg-gray-50/50 transition-colors group">
                    <td class="px-6 py-5 font-bold text-gray-900">{{ $row['name'] }}</td>
                    <td class="px-6 py-5 text-right font-bold text-gray-900">$ {{ number_format($row['allocated'], 0) }}
                    </td>
                    <td class="px-6 py-5 text-right text-gray-500 font-medium">$ {{ number_format($row['used'], 0) }}</td>
                    <td class="px-6 py-5 text-right text-gray-500 font-medium">$ {{ number_format($row['remaining'], 0) }}
                    </td>
                    <td class="px-6 py-5 text-right font-black text-gray-900">{{ number_format($row['usage_pc'], 1) }}%</td>
                </tr>
            @endforeach
            @if(count($this->summaryData) === 0)
                <tr>
                    <td colspan="5" class="px-6 py-12 text-center text-gray-400 italic font-medium">No department data
                        available for this fiscal year.</td>
                </tr>
            @endif
        </tbody>
        <tfoot class="border-t-2 border-gray-100 bg-gray-50/50">
            <tr class="font-black text-gray-900">
                <td class="px-6 py-6 uppercase tracking-widest text-[10px]">Total Ministry Sum</td>
                <td class="px-6 py-6 text-right">$ {{ number_format($this->summaryData->sum('allocated'), 0) }}</td>
                <td class="px-6 py-6 text-right">$ {{ number_format($this->summaryData->sum('used'), 0) }}</td>
                <td class="px-6 py-6 text-right">$ {{ number_format($this->summaryData->sum('remaining'), 0) }}</td>
                @php 
                                        $smAlloc = $this->summaryData->sum('allocated');
                    $smUsed = $this->summaryData->sum('used');
                    $smPC = $smAlloc > 0 ? ($smUsed / $smAlloc) * 100 : 0;
                @endphp
                <td class="px-6 py-6 text-right">{{ number_format($smPC, 1) }}%</td>
            </tr>
        </tfoot>
    </table>
</div>
