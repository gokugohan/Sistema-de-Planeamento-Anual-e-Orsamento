<div class="space-y-6">
    <div class="mb-4">
        <h3 class="text-lg font-black text-gray-900">Economic Classifications Breakdown</h3>
        <p class="text-xs font-medium text-gray-400 mt-1">Budget distribution by major economic categories (Salaries,
            Goods & Services, Capital)</p>
    </div>

    <table class="min-w-full text-sm">
        <thead class="text-[10px] font-black text-gray-400 uppercase tracking-widest border-b border-gray-50">
            <tr>
                <th class="px-6 py-4 text-left">Classification</th>
                <th class="px-6 py-4 text-right">Allocated</th>
                <th class="px-6 py-4 text-right">Used</th>
                <th class="px-6 py-4 text-right">Remaining</th>
                <th class="px-6 py-4 text-right">Usage Rate</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            @foreach($this->economicData as $row)
                <tr class="hover:bg-gray-50/50 transition-colors group">
                    <td class="px-6 py-5 font-bold text-gray-900">
                        <div class="flex items-baseline gap-2">
                            <span
                                class="text-[10px] bg-gray-100 px-1.5 py-0.5 rounded text-gray-500">{{ $row['code'] }}</span>
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
                <td class="px-6 py-6 uppercase tracking-widest text-[10px]">Total Economic Sum</td>
                <td class="px-6 py-6 text-right">$ {{ number_format($this->economicData->sum('allocated'), 0) }}</td>
                <td class="px-6 py-6 text-right">$ {{ number_format($this->economicData->sum('used'), 0) }}</td>
                <td class="px-6 py-6 text-right">$ {{ number_format($this->summaryData->sum('remaining'), 0) }}</td>
                @php 
                                        $ecAlloc = $this->economicData->sum('allocated');
                    $ecUsed = $this->economicData->sum('used');
                    $ecPC = $ecAlloc > 0 ? ($ecUsed / $ecAlloc) * 100 : 0;
                @endphp
                <td class="px-6 py-6 text-right">{{ number_format($ecPC, 1) }}%</td>
            </tr>
        </tfoot>
    </table>
</div>
