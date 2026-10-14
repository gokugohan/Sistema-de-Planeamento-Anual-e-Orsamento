{{-- The top of a page: title, one line of context, and the page's main actions on the right. --}}
@props(['title', 'subtitle' => null])

<div {{ $attributes->merge(['class' => 'flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between']) }}>
    <div class="min-w-0">
        <h1 class="page-title">{{ $title }}</h1>
        @if($subtitle)
            <p class="page-subtitle">{{ $subtitle }}</p>
        @endif
    </div>
    @if(trim((string) $slot) !== '')
        <div class="flex flex-wrap items-center gap-2 sm:justify-end">{{ $slot }}</div>
    @endif
</div>
