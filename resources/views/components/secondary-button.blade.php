<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center px-4 py-2 bg-white border border-zinc-200 rounded-lg text-sm font-medium text-zinc-700 shadow-sm
    hover:bg-zinc-50 hover:border-zinc-300 hover:text-zinc-900
    focus:outline-none focus:ring-2 focus:ring-zinc-300/50 focus:ring-offset-1
    disabled:opacity-50 disabled:cursor-not-allowed
    transition duration-150']) }}>
    {{ $slot }}
</button>
