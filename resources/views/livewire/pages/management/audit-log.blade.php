<?php

use App\Models\AuditLog;
use App\Models\User;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';
    #[Url(except: '')]
    public string $action = '';
    #[Url(except: '')]
    public string $userId = '';
    #[Url(except: '')]
    public string $from = '';
    #[Url(except: '')]
    public string $to = '';

    public ?int $expanded = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);
    }

    public function updated($property): void
    {
        if (in_array($property, ['search', 'action', 'userId', 'from', 'to'], true)) {
            $this->resetPage();
            $this->expanded = null;
        }
    }

    public function toggle(int $id): void
    {
        $this->expanded = $this->expanded === $id ? null : $id;
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'action', 'userId', 'from', 'to', 'expanded']);
        $this->resetPage();
    }

    public function with(): array
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);

        $logs = AuditLog::query()
            ->with('user:id,name,email')
            ->when($this->action !== '', fn($q) => $q->where('action', $this->action))
            ->when($this->userId !== '', fn($q) => $q->where('user_id', (int) $this->userId))
            ->when($this->from !== '', fn($q) => $q->where('created_at', '>=', $this->from . ' 00:00:00'))
            ->when($this->to !== '', fn($q) => $q->where('created_at', '<=', $this->to . ' 23:59:59'))
            ->when($this->search !== '', function ($q) {
                $term = '%' . addcslashes($this->search, '%_\\') . '%';
                $q->where(fn($w) => $w->where('description', 'like', $term)
                    ->orWhere('ip_address', 'like', $term)
                    ->orWhere('auditable_id', is_numeric($this->search) ? (int) $this->search : -1));
            })
            ->orderByDesc('id')
            ->paginate(25);

        return [
            'logs' => $logs,
            'actions' => AuditLog::query()->select('action')->distinct()->orderBy('action')->pluck('action'),
            'users' => User::query()->whereIn('id', AuditLog::query()->whereNotNull('user_id')->select('user_id')->distinct())
                ->orderBy('name')->get(['id', 'name']),
        ];
    }
}; ?>

<div class="space-y-6">
    <div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-zinc-900 tracking-tight">{{ __('Audit Log') }}</h1>
            <p class="text-sm text-zinc-500 mt-1">{{ __('Who changed what, and when. Records cannot be edited from the application.') }}</p>
        </div>
        <span class="text-sm text-zinc-500">{{ number_format($logs->total()) }} {{ __('records') }}</span>
    </div>

    <x-ui.card padding="p-4">
        <div class="grid gap-3 md:grid-cols-6">
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="{{ __('Search description, ID or IP') }}"
                class="flux-input md:col-span-2">
            <select wire:model.live="action" class="flux-input">
                <option value="">{{ __('All actions') }}</option>
                @foreach($actions as $a)
                    <option value="{{ $a }}">{{ $a }}</option>
                @endforeach
            </select>
            <select wire:model.live="userId" class="flux-input">
                <option value="">{{ __('All users') }}</option>
                @foreach($users as $u)
                    <option value="{{ $u->id }}">{{ $u->name }}</option>
                @endforeach
            </select>
            <input type="date" wire:model.live="from" class="flux-input" aria-label="{{ __('From') }}">
            <input type="date" wire:model.live="to" class="flux-input" aria-label="{{ __('To') }}">
        </div>
        @if($search !== '' || $action !== '' || $userId !== '' || $from !== '' || $to !== '')
            <button wire:click="resetFilters" class="mt-3 text-xs font-medium text-indigo-600 hover:underline">{{ __('Clear filters') }}</button>
        @endif
    </x-ui.card>

    <x-ui.card padding="p-0">
        <x-ui.table :headers="[__('When'), __('User'), __('Action'), __('Record'), __('IP')]">
            @forelse($logs as $log)
                <tr wire:key="log-{{ $log->id }}" wire:click="toggle({{ $log->id }})"
                    class="cursor-pointer hover:bg-zinc-50 transition-colors">
                    <td class="px-6 py-3 text-sm text-zinc-600 whitespace-nowrap">{{ $log->created_at?->format('Y-m-d H:i:s') }}</td>
                    <td class="px-6 py-3 text-sm text-zinc-900">{{ $log->user?->name ?? __('System') }}</td>
                    <td class="px-6 py-3 text-sm">
                        @php
                            $verb = str($log->action)->afterLast('.')->toString();
                            $variant = match ($verb) { 'created' => 'green', 'deleted' => 'red', 'updated' => 'blue', default => 'gray' };
                        @endphp
                        <x-ui.badge :variant="$variant">{{ $log->action }}</x-ui.badge>
                    </td>
                    <td class="px-6 py-3 text-sm text-zinc-600">{{ $log->description }}</td>
                    <td class="px-6 py-3 text-sm text-zinc-500 whitespace-nowrap">{{ $log->ip_address ?? '—' }}</td>
                </tr>
                @if($expanded === $log->id)
                    <tr wire:key="log-{{ $log->id }}-detail" class="bg-zinc-50">
                        <td colspan="5" class="px-6 py-4">
                            @php
                                $old = $log->old_values ?? [];
                                $new = $log->new_values ?? [];
                                $keys = array_values(array_unique(array_merge(array_keys($old), array_keys($new))));
                                $fmt = fn($v) => is_null($v) ? '—' : (is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE));
                            @endphp
                            @if($keys)
                                <table class="w-full text-xs">
                                    <thead>
                                        <tr class="text-left text-zinc-500">
                                            <th class="py-1 pr-4 w-1/5">{{ __('Field') }}</th>
                                            <th class="py-1 pr-4 w-2/5">{{ __('Before') }}</th>
                                            <th class="py-1 w-2/5">{{ __('After') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-zinc-200">
                                        @foreach($keys as $k)
                                            <tr>
                                                <td class="py-1.5 pr-4 font-medium text-zinc-700">{{ $k }}</td>
                                                <td class="py-1.5 pr-4 text-rose-600 break-all">{{ $fmt($old[$k] ?? null) }}</td>
                                                <td class="py-1.5 text-emerald-700 break-all">{{ $fmt($new[$k] ?? null) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            @else
                                <p class="text-xs text-zinc-500">{{ __('No field-level detail recorded.') }}</p>
                            @endif
                            @if($log->user_agent)
                                <p class="mt-3 text-[11px] text-zinc-400 break-all">{{ $log->user_agent }}</p>
                            @endif
                        </td>
                    </tr>
                @endif
            @empty
                <tr>
                    <td colspan="5" class="px-6 py-12 text-center text-sm text-zinc-500">{{ __('No audit records match the current filters.') }}</td>
                </tr>
            @endforelse
        </x-ui.table>
        @if($logs->hasPages())
            <div class="px-6 py-4 border-t border-zinc-200">{{ $logs->links() }}</div>
        @endif
    </x-ui.card>
</div>
