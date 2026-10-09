<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md text-sm font-medium text-white
    hover:bg-indigo-700 active:bg-indigo-800
    focus:outline-none focus:ring-2 focus:ring-indigo-600/40 focus:ring-offset-2
    disabled:opacity-50 disabled:cursor-not-allowed
    transition duration-150']) }}>
    {{ $slot }}
</button>
