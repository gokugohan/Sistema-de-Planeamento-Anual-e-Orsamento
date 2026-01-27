@props(['headers' => []])

<div class="overflow-x-auto">
    <table {{ $attributes->merge(['class' => 'min-w-full divide-y divide-slate-100']) }}>
        <thead class="bg-slate-50/50">
            <tr>
                @foreach($headers as $header)
                    <th
                        class="px-8 py-5 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest whitespace-nowrap">
                        {{ $header }}
                    </th>
                @endforeach
                @if(isset($actions))
                    <th class="px-8 py-5 text-right"></th>
                @endif
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-50 bg-white">
            {{ $slot }}
        </tbody>
    </table>
</div>