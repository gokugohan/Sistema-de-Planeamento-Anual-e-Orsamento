{{-- A data table: a header that stays in view while the page scrolls inside it, tight rows, and
     numbers that line up (give a header ending in "num" to a column to right-align it). --}}
@props(['headers' => [], 'right' => []])

<div class="overflow-x-auto">
    <table {{ $attributes->merge(['class' => 'min-w-full']) }}>
        <thead class="bg-zinc-50 border-b border-zinc-200">
            <tr>
                @foreach($headers as $i => $header)
                    <th scope="col"
                        class="px-5 py-3 {{ in_array($i, $right, true) ? 'text-right' : 'text-left' }} whitespace-nowrap">
                        {{ $header }}
                    </th>
                @endforeach
                @if(isset($actions))
                    <th scope="col" class="px-5 py-3 text-right"><span class="sr-only">{{ __('Actions') }}</span></th>
                @endif
            </tr>
        </thead>
        <tbody class="divide-y divide-zinc-100 bg-white">
            {{ $slot }}
        </tbody>
    </table>
</div>
