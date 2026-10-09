@props(['disabled' => false])

<input
    @disabled($disabled)
    {{ $attributes->merge(['class' => 'block w-full rounded-md border border-zinc-200 bg-white px-3.5 py-2.5 text-sm text-zinc-900
        placeholder:text-zinc-400
        focus:outline-none focus:ring-1 focus:ring-indigo-600 focus:border-indigo-600
        disabled:bg-zinc-50 disabled:text-zinc-500 disabled:cursor-not-allowed
        transition duration-150']) }}
>
