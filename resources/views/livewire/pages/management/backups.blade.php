<?php

use App\Models\AuditLog;
use App\Services\DatabaseBackup;
use Livewire\Volt\Component;

/**
 * The database backups: made every night at 02:00 and kept on the server for a set number of days. A backup on the
 * same server as the application is lost with it, so an administrator downloads the newest one regularly and keeps
 * it elsewhere; the page says when that was last done. A restore test loads a backup into a temporary database to
 * prove it can be restored, without touching the live one. Every action is recorded in the audit trail.
 */
new class extends Component {
    public ?array $check = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);
    }

    public function backupNow(DatabaseBackup $backups): void
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);
        abort_unless(config('backup.enabled'), 403);
        try {
            $r = $backups->run();
        } catch (\Throwable $e) {
            report($e);
            $this->dispatch('toast', message: __('The backup failed: :reason', ['reason' => $e->getMessage()]), type: 'error');

            return;
        }
        AuditLog::create(['action' => 'backup.created', 'description' => 'Database backup created: '.basename($r['path']), 'metadata' => ['bytes' => $r['bytes'], 'pruned' => $r['pruned'], 'manual' => true]]);
        $this->dispatch('toast', message: __('Backup made: :name', ['name' => basename($r['path'])]), type: 'success');
    }

    public function restoreCheck(string $name, DatabaseBackup $backups): void
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);
        $file = $backups->file($name);
        try {
            $r = $backups->restoreCheck($file);
        } catch (\Throwable $e) {
            report($e);
            $this->check = ['name' => $name, 'ok' => false, 'error' => $e->getMessage()];
            AuditLog::create(['action' => 'backup.restore_checked', 'description' => 'Restore test failed: '.$name, 'metadata' => ['file' => $name, 'ok' => false, 'error' => \Illuminate\Support\Str::limit($e->getMessage(), 500)]]);

            return;
        }
        $this->check = ['name' => $name, 'ok' => ! $r['missing']] + $r;
        AuditLog::create(['action' => 'backup.restore_checked', 'description' => 'Restore test of '.$name.': '.$r['tables'].' tables, '.$r['rows'].' rows', 'metadata' => ['file' => $name, 'ok' => ! $r['missing']] + $r]);
    }

    public function with(DatabaseBackup $backups): array
    {
        $list = $backups->list();
        $last = fn (string $action) => AuditLog::where('action', $action)->latest('id')->first();
        $downloaded = $last('backup.downloaded');
        $newest = $list->first();
        $every = (int) config('backup.download_every_days', 7);

        return [
            'backups' => $list,
            'newest' => $newest,
            'enabled' => (bool) config('backup.enabled'),
            'keepDays' => (int) config('backup.keep_days'),
            'copyTo' => config('backup.copy_to'),
            'downloaded' => $downloaded,
            // nothing was taken off the server recently: the only copies are on the server itself
            'downloadDue' => $newest && (! $downloaded || $downloaded->created_at->lt(now()->subDays($every))),
            'every' => $every,
            'restored' => $last('backup.restore_checked'),
            'stale' => ! $newest || $newest['time'] < now()->subHours((float) config('backup.max_age_hours'))->getTimestamp(),
        ];
    }
}; ?>

@php
    $when = fn (int $ts) => \Carbon\Carbon::createFromTimestamp($ts)->timezone(config('app.timezone'));
    $size = fn (int $b) => $b >= 1048576 ? number_format($b / 1048576, 1).' MB' : number_format($b / 1024, 0).' KB';
@endphp

<div class="space-y-6">
    <x-ui.page-header :title="__('Backups')" :subtitle="__('A copy of the whole database is made every night at 02:00 and kept on the server for :days days. Keep a copy off the server too: download the newest backup regularly and store it somewhere safe.', ['days' => $keepDays])">
        @if($enabled)
            <x-ui.button type="button" variant="secondary" wire:click="backupNow" wire:loading.attr="disabled" wire:target="backupNow" class="whitespace-nowrap">
                <span wire:loading.remove wire:target="backupNow">{{ __('Back up now') }}</span>
                <span wire:loading wire:target="backupNow">{{ __('Backing up…') }}</span>
            </x-ui.button>
        @endif
    </x-ui.page-header>

    {{-- Where things stand --}}
    <div class="grid gap-4 md:grid-cols-3">
        <x-ui.card>
            <p class="text-xs font-medium text-zinc-500">{{ __('Newest backup') }}</p>
            @if($newest)
                <p class="mt-1 text-lg font-semibold text-zinc-900">{{ $when($newest['time'])->translatedFormat('j M Y, H:i') }}</p>
                <p class="text-xs text-zinc-500">{{ $when($newest['time'])->diffForHumans() }} · {{ $size($newest['bytes']) }}</p>
            @else
                <p class="mt-1 text-lg font-semibold text-zinc-900">{{ __('None yet') }}</p>
            @endif
            @if(! $enabled)
                <p class="mt-2 text-xs font-medium text-rose-700">{{ __('Nightly backups are switched off (BACKUP_ENABLED=false).') }}</p>
            @elseif($stale)
                <p class="mt-2 text-xs font-medium text-amber-700">{{ __('No backup in the last :hours hours: check that the scheduler runs.', ['hours' => (int) config('backup.max_age_hours')]) }}</p>
            @endif
        </x-ui.card>
        <x-ui.card>
            <p class="text-xs font-medium text-zinc-500">{{ __('Last copy taken off the server') }}</p>
            @if($downloaded)
                <p class="mt-1 text-lg font-semibold text-zinc-900">{{ $downloaded->created_at->timezone(config('app.timezone'))->translatedFormat('j M Y, H:i') }}</p>
                <p class="text-xs text-zinc-500">{{ $downloaded->user?->name }} · {{ $downloaded->metadata['file'] ?? '' }}</p>
            @else
                <p class="mt-1 text-lg font-semibold text-zinc-900">{{ __('Never') }}</p>
            @endif
            @if($copyTo)<p class="mt-2 text-xs text-zinc-500">{{ __('Each backup is also copied to :path.', ['path' => $copyTo]) }}</p>@endif
        </x-ui.card>
        <x-ui.card>
            <p class="text-xs font-medium text-zinc-500">{{ __('Last restore test') }}</p>
            @if($restored)
                <p class="mt-1 text-lg font-semibold {{ ($restored->metadata['ok'] ?? false) ? 'text-emerald-700' : 'text-rose-700' }}">{{ ($restored->metadata['ok'] ?? false) ? __('Restored') : __('Failed') }}</p>
                <p class="text-xs text-zinc-500">{{ $restored->created_at->timezone(config('app.timezone'))->translatedFormat('j M Y, H:i') }} · {{ $restored->metadata['file'] ?? '' }}</p>
            @else
                <p class="mt-1 text-lg font-semibold text-zinc-900">{{ __('Never') }}</p>
                <p class="text-xs text-zinc-500">{{ __('A backup is only proven once it has been restored.') }}</p>
            @endif
        </x-ui.card>
    </div>

    @if($downloadDue)
        <div role="status" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <p class="font-semibold">{{ __('Keep a copy off the server') }}</p>
            <p class="mt-0.5">{{ $downloaded ? __('The last copy was taken away more than :days days ago.', ['days' => $every]) : __('No backup has been downloaded yet.') }} {{ __('If this server is lost, the backups on it are lost with it. Download the newest backup and store it on another computer, an external drive or the institution\'s network share.') }}</p>
        </div>
    @endif

    @if($check)
        <div role="status" class="rounded-lg border px-4 py-3 text-sm {{ $check['ok'] ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-rose-200 bg-rose-50 text-rose-900' }}">
            @if(isset($check['error']))
                <p class="font-semibold">{{ __('The restore test of :name failed', ['name' => $check['name']]) }}</p>
                <p class="mt-0.5 font-mono text-xs">{{ $check['error'] }}</p>
            @else
                <p class="font-semibold">{{ $check['ok'] ? __(':name restores: :tables tables, :rows rows, in :seconds s.', ['name' => $check['name'], 'tables' => $check['tables'], 'rows' => number_format($check['rows']), 'seconds' => $check['seconds']]) : __(':name restored, but some tables of the live database are not in it.', ['name' => $check['name']]) }}</p>
                <p class="mt-0.5 text-xs">{{ __('The live database has :rows rows now; the difference is what changed since the backup was made.', ['rows' => number_format($check['live_rows'])]) }}</p>
                @if($check['missing'])<p class="mt-0.5 text-xs">{{ __('Not in the backup: :tables', ['tables' => implode(', ', $check['missing'])]) }}</p>@endif
            @endif
        </div>
    @endif

    {{-- The backups on the server --}}
    <x-ui.card padding="p-0">
        <div class="border-b border-zinc-200 px-5 py-4">
            <h2 class="text-sm font-semibold text-zinc-900">{{ __('Backups on the server') }}</h2>
            <p class="mt-0.5 text-xs text-zinc-500">{{ __('Each is a compressed dump of the whole database, checked when it is made. Older ones are deleted after :days days.', ['days' => $keepDays]) }}</p>
        </div>
        <div class="overflow-x-auto" tabindex="0">
            <table class="min-w-full text-sm">
                <thead class="bg-zinc-50 text-left text-xs font-medium text-zinc-500">
                    <tr>
                        <th scope="col" class="px-5 py-3">{{ __('Backup') }}</th>
                        <th scope="col" class="px-5 py-3">{{ __('Made') }}</th>
                        <th scope="col" class="px-5 py-3 text-right">{{ __('Size') }}</th>
                        <th scope="col" class="px-5 py-3"><span class="sr-only">{{ __('Actions') }}</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @forelse($backups as $b)
                        <tr class="hover:bg-zinc-50/60" wire:key="bk-{{ $b['name'] }}">
                            <td class="px-5 py-3 font-mono text-xs text-zinc-900">{{ $b['name'] }}</td>
                            <td class="px-5 py-3 text-zinc-700">{{ $when($b['time'])->translatedFormat('j M Y, H:i') }}</td>
                            <td class="px-5 py-3 text-right tabular-nums text-zinc-700">{{ $size($b['bytes']) }}</td>
                            <td class="whitespace-nowrap px-5 py-3 text-right">
                                <x-ui.button type="button" variant="ghost" size="xs" wire:click="restoreCheck('{{ $b['name'] }}')" wire:loading.attr="disabled" wire:target="restoreCheck"
                                    wire:confirm="{{ __('Test that this backup restores? It is loaded into a temporary database beside the live one, which is not touched. It may take a minute.') }}">{{ __('Test restore') }}</x-ui.button>
                                <x-ui.button :href="route('management.backups.download', $b['name'])" variant="secondary" size="xs" shadow="none">{{ __('Download') }}</x-ui.button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4"><x-ui.empty-state :title="__('No backup yet.')" :text="__('The first is made tonight at 02:00, or now with Back up now.')" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>
</div>
