@props(['disabled' => false])

<input
    @disabled($disabled)
    {{ $attributes->merge(['class' => 'block w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900
        shadow-sm placeholder:text-zinc-400
        focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-400
        disabled:bg-zinc-50 disabled:text-zinc-500 disabled:cursor-not-allowed
        transition duration-150']) }}
>
