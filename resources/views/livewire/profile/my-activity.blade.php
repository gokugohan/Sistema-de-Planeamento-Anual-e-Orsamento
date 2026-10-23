<?php

use App\Models\AuditLog;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * What the signed-in person did in the system (and when they signed in), from the audit log. Only their own entries are
 * ever read; the figures that changed are not shown here, only the plain description. Ten at a time, newest first, from
 * one indexed query.
 */
new class extends Component {
    use WithPagination;

    public function with(): array
    {
        return [
            'logs' => AuditLog::where('user_id', auth()->id())
                ->select(['id', 'action', 'description', 'ip_address', 'created_at'])
                ->orderByDesc('id')->simplePaginate(10, pageName: 'activity'),
        ];
    }
}; ?>

<div>
    @forelse($logs as $log)
        <div wire:key="act-{{ $log->id }}" class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-2.5 @unless($loop->last) border-b border-zinc-100 @endunless">
            <p class="min-w-0 text-sm text-zinc-800">{{ $log->description ?: $log->action }}</p>
            <p class="shrink-0 text-xs tabular-nums text-zinc-500">{{ $log->created_at?->format('d/m/Y H:i') }}@if($log->ip_address) · {{ $log->ip_address }}@endif</p>
        </div>
    @empty
        <p class="py-4 text-sm text-zinc-600">{{ __('Nothing recorded for you yet.') }}</p>
    @endforelse

    @if($logs->hasPages())
        <div class="mt-3 flex justify-between text-sm">
            @if($logs->onFirstPage())<span></span>@else<button type="button" wire:click="previousPage('activity')" class="font-medium text-indigo-700 hover:underline">← {{ __('Newer') }}</button>@endif
            @if($logs->hasMorePages())<button type="button" wire:click="nextPage('activity')" class="font-medium text-indigo-700 hover:underline">{{ __('Older') }} →</button>@endif
        </div>
    @endif
</div>
