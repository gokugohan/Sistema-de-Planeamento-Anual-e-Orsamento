<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center px-4 py-2 bg-rose-600 border border-transparent rounded-lg text-sm font-medium text-white shadow-sm
    hover:bg-rose-700 active:bg-rose-800
    focus:outline-none focus:ring-2 focus:ring-rose-500/30 focus:ring-offset-1
    disabled:opacity-50 disabled:cursor-not-allowed
    transition duration-150']) }}>
    {{ $slot }}
</button>
