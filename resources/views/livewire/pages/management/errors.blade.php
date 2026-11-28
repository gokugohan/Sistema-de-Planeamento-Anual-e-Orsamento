<?php

use App\Models\AuditLog;
use App\Models\ErrorReport;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * The errors the application met, one line per kind (the same error at the same place), newest first. The
 * administrator opens one to see where it happened and its trace, and marks it resolved once it is fixed; if it
 * happens again it comes back, and the administrators are told. Kept on the server only (see ErrorReports).
 */
new class extends Component {
    use WithPagination;

    #[Url(as: 'show')]
    public string $show = 'open'; // open | resolved | all

    #[Url(as: 'open')]
    public ?int $open = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);
    }

    public function updatedShow(): void
    {
        $this->resetPage();
    }

    public function resolve(int $id): void
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);
        $r = ErrorReport::findOrFail($id);
        $r->update(['resolved_at' => now(), 'resolved_by' => auth()->id()]);
        AuditLog::create(['action' => 'error.resolved', 'description' => 'Error marked resolved: '.class_basename($r->class).' at '.$r->file.':'.$r->line, 'metadata' => ['error_report_id' => $r->id]]);
        $this->open = null;
        $this->dispatch('toast', message: __('Marked as resolved. If it happens again, it comes back here.'), type: 'success');
    }

    public function with(): array
    {
        $base = ErrorReport::query();

        return [
            'reports' => (clone $base)->with('user:id,name')
                ->when($this->show === 'open', fn ($q) => $q->whereNull('resolved_at'))
                ->when($this->show === 'resolved', fn ($q) => $q->whereNotNull('resolved_at'))
                ->orderByDesc('last_seen_at')->paginate(20),
            'openCount' => (clone $base)->whereNull('resolved_at')->count(),
            'lastDay' => (clone $base)->where('last_seen_at', '>=', now()->subDay())->count(),
            'detail' => $this->open ? ErrorReport::with(['user:id,name', 'resolver:id,name'])->find($this->open) : null,
        ];
    }
}; ?>

@php $at = fn ($t) => $t?->timezone(config('app.timezone'))->translatedFormat('j M Y, H:i'); @endphp

<div class="space-y-6">
    <x-ui.page-header :title="__('Errors')" :subtitle="__('What went wrong in the application, one line per kind of error. The administrators are told of a new one; mark it resolved once it is fixed, and it comes back here if it happens again. Nothing is sent outside the server.')" />

    <div class="grid gap-4 sm:grid-cols-3">
        <x-ui.card><p class="text-xs font-medium text-zinc-500">{{ __('To follow up') }}</p><p class="mt-1 text-2xl font-semibold tabular-nums {{ $openCount ? 'text-rose-700' : 'text-zinc-900' }}">{{ $openCount }}</p></x-ui.card>
        <x-ui.card><p class="text-xs font-medium text-zinc-500">{{ __('Seen in the last 24 hours') }}</p><p class="mt-1 text-2xl font-semibold tabular-nums text-zinc-900">{{ $lastDay }}</p></x-ui.card>
        <x-ui.card>
            <p class="text-xs font-medium text-zinc-500">{{ __('Is the application up?') }}</p>
            <p class="mt-1 text-sm text-zinc-700">{{ __('An outside check should open :url every few minutes and alert you when it does not answer "ok".', ['url' => url('/health')]) }}</p>
        </x-ui.card>
    </div>

    <x-ui.card padding="p-0">
        <div class="flex flex-col gap-3 border-b border-zinc-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
            <h2 class="text-sm font-semibold text-zinc-900">{{ __('Errors') }}</h2>
            <div role="radiogroup" aria-label="{{ __('Show') }}" class="inline-flex rounded-lg border border-zinc-200 p-0.5 text-sm">
                @foreach(['open' => __('To follow up'), 'resolved' => __('Resolved'), 'all' => __('All')] as $key => $label)
                    <button type="button" role="radio" aria-checked="{{ $show === $key ? 'true' : 'false' }}" wire:click="$set('show', '{{ $key }}')"
                        class="rounded-md px-3 py-1 font-medium {{ $show === $key ? 'bg-indigo-600 text-white' : 'text-zinc-600 hover:text-zinc-900' }}">{{ $label }}</button>
                @endforeach
            </div>
        </div>
        <div class="overflow-x-auto" tabindex="0">
            <table class="min-w-full text-sm">
                <thead class="bg-zinc-50 text-left text-xs font-medium text-zinc-500">
                    <tr>
                        <th scope="col" class="px-5 py-3">{{ __('Error') }}</th>
                        <th scope="col" class="px-5 py-3">{{ __('Where') }}</th>
                        <th scope="col" class="px-5 py-3 text-right">{{ __('Times') }}</th>
                        <th scope="col" class="px-5 py-3">{{ __('Last seen') }}</th>
                        <th scope="col" class="px-5 py-3"><span class="sr-only">{{ __('Actions') }}</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @forelse($reports as $r)
                        <tr class="hover:bg-zinc-50/60" wire:key="err-{{ $r->id }}">
                            <td class="max-w-md px-5 py-3">
                                <p class="font-medium text-zinc-900">{{ class_basename($r->class) }}</p>
                                <p class="mt-0.5 line-clamp-2 text-xs text-zinc-600">{{ $r->message }}</p>
                            </td>
                            <td class="px-5 py-3 font-mono text-xs text-zinc-600">{{ $r->file }}:{{ $r->line }}</td>
                            <td class="px-5 py-3 text-right tabular-nums text-zinc-900">{{ number_format($r->occurrences) }}</td>
                            <td class="px-5 py-3 text-zinc-700">{{ $at($r->last_seen_at) }}@if($r->resolved_at)<p class="text-xs text-emerald-700">{{ __('Resolved') }}</p>@endif</td>
                            <td class="whitespace-nowrap px-5 py-3 text-right">
                                <x-ui.button type="button" variant="ghost" size="xs" wire:click="$set('open', {{ $r->id }})">{{ __('Details') }}</x-ui.button>
                                @unless($r->resolved_at)<x-ui.button type="button" variant="secondary" size="xs" shadow="none" wire:click="resolve({{ $r->id }})">{{ __('Mark resolved') }}</x-ui.button>@endunless
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><x-ui.empty-state :title="$show === 'open' ? __('No error to follow up.') : __('No errors.')" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>
    <div>{{ $reports->links() }}</div>

    @if($detail)
        <x-slide-over close="$set('open', null)" width="2xl">
            <x-slot:eyebrow>{{ __('Error') }}</x-slot:eyebrow>
            <x-slot:title>{{ class_basename($detail->class) }}</x-slot:title>
            <x-slot:subtitle>{{ $detail->message }}</x-slot:subtitle>
            <dl class="grid grid-cols-1 gap-x-6 gap-y-3 p-6 text-sm sm:grid-cols-2">
                <div><dt class="text-xs font-medium text-zinc-500">{{ __('Where') }}</dt><dd class="font-mono text-xs text-zinc-900">{{ $detail->file }}:{{ $detail->line }}</dd></div>
                <div><dt class="text-xs font-medium text-zinc-500">{{ __('Kind') }}</dt><dd class="break-all font-mono text-xs text-zinc-900">{{ $detail->class }}</dd></div>
                <div><dt class="text-xs font-medium text-zinc-500">{{ __('Last page') }}</dt><dd class="break-all text-zinc-900">{{ $detail->method }} {{ $detail->url }}</dd></div>
                <div><dt class="text-xs font-medium text-zinc-500">{{ __('Last user') }}</dt><dd class="text-zinc-900">{{ $detail->user?->name ?? '—' }}</dd></div>
                <div><dt class="text-xs font-medium text-zinc-500">{{ __('Times') }}</dt><dd class="text-zinc-900">{{ number_format($detail->occurrences) }} · {{ __('first :first, last :last', ['first' => $at($detail->first_seen_at), 'last' => $at($detail->last_seen_at)]) }}</dd></div>
                <div><dt class="text-xs font-medium text-zinc-500">{{ __('Version') }}</dt><dd class="text-zinc-900">{{ $detail->app_version ?? '—' }}</dd></div>
                @if($detail->resolved_at)
                    <div class="sm:col-span-2"><dt class="text-xs font-medium text-zinc-500">{{ __('Resolved') }}</dt><dd class="text-zinc-900">{{ $at($detail->resolved_at) }} · {{ $detail->resolver?->name }}</dd></div>
                @endif
                <div class="sm:col-span-2">
                    <dt class="text-xs font-medium text-zinc-500">{{ __('Trace') }}</dt>
                    <dd><pre class="mt-1 max-h-96 overflow-auto rounded-md bg-zinc-50 p-3 text-[11px] leading-relaxed text-zinc-800">{{ $detail->trace }}</pre></dd>
                </div>
            </dl>
            <x-slot:footer>
                <x-ui.button type="button" variant="secondary" shadow="none" wire:click="$set('open', null)">{{ __('Close') }}</x-ui.button>
                @unless($detail->resolved_at)<x-ui.button type="button" wire:click="resolve({{ $detail->id }})">{{ __('Mark resolved') }}</x-ui.button>@endunless
            </x-slot:footer>
        </x-slide-over>
    @endif
</div>
