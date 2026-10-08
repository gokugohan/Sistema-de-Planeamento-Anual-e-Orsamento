@props(['headers' => []])

<div class="overflow-x-auto">
    <table {{ $attributes->merge(['class' => 'min-w-full divide-y divide-zinc-200']) }}>
        <thead class="bg-zinc-100">
            <tr>
                @foreach($headers as $header)
                    <th
                        class="px-6 py-4 text-left text-xs font-medium text-zinc-600 uppercase tracking-wide whitespace-nowrap">
                        {{ $header }}
                    </th>
                @endforeach
                @if(isset($actions))
                    <th class="px-6 py-4 text-right"></th>
                @endif
            </tr>
        </thead>
        <tbody class="divide-y divide-zinc-200 bg-white">
            {{ $slot }}
        </tbody>
    </table>
</div>