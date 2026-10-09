@props(['title', 'subtitle' => null])

<div class="mb-8">
    <h1 class="text-2xl font-bold tracking-tight text-zinc-900">{{ $title }}</h1>
    @if($subtitle)
        <p class="mt-2 text-sm text-zinc-500">{{ $subtitle }}</p>
    @endif
</div>
