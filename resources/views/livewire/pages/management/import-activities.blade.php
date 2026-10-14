<?php

use App\Models\Activity;
use App\Services\ActivityCsvImporter;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    #[Validate('required|file|mimes:csv,txt|max:2048')]
    public $file = null;

    #[Validate('required|integer|min:2020|max:2035')]
    public int $fiscalYear;

    /** Display-only preview. Never trusted: import() re-parses the uploaded file. */
    public ?array $preview = null;
    public ?array $result = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);
        $this->fiscalYear = (int) date('Y') + 1;
    }

    public function updatedFile(): void
    {
        $this->result = null;
        $this->preview = null;
    }

    public function updatedFiscalYear(): void
    {
        $this->preview = null;
        $this->result = null;
    }

    private function parseUpload(): array
    {
        $this->validate();

        return app(ActivityCsvImporter::class)->parse($this->file->getRealPath(), $this->fiscalYear);
    }

    public function check(): void
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);
        $this->result = null;

        $parsed = $this->parseUpload();

        $this->preview = [
            'rows' => $parsed['rows'],
            'activities' => count($parsed['activities']),
            'indicators' => array_sum(array_map(fn($a) => count($a['indicators']), $parsed['activities'])),
            'line_items' => array_sum(array_map(fn($a) => count($a['line_items']), $parsed['activities'])),
            'total' => array_sum(array_map(fn($a) => array_sum(array_column($a['line_items'], 'estimated_amount')), $parsed['activities'])),
            'errors' => array_slice($parsed['errors'], 0, 100),
            'error_count' => count($parsed['errors']),
        ];
    }

    public function import(): void
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);

        $importer = app(ActivityCsvImporter::class);
        $parsed = $this->parseUpload();

        if ($parsed['errors'] || !$parsed['activities']) {
            $this->check();
            $this->dispatch('toast', message: __('Fix the errors before importing.'), type: 'error');

            return;
        }

        $this->result = $importer->import($parsed['activities'], $this->fiscalYear);
        $this->preview = null;
        $this->reset('file');
        $this->dispatch('toast', message: __(':n activities imported as Draft.', ['n' => $this->result['activities']]), type: 'success');
    }
}; ?>

<div class="space-y-6 max-w-4xl">
    <div>
        <h1 class="page-title">{{ __('Import Activities') }}</h1>
        <p class="page-subtitle">
            {{ __('Load planned activities, indicators and budget lines from a CSV file. Everything is imported as Draft and nothing is saved until the file passes validation.') }}
        </p>
    </div>

    <x-ui.card>
        <div class="grid gap-4 md:grid-cols-3 items-end">
            <div class="md:col-span-2">
                <label class="block text-sm font-medium text-zinc-700 mb-1" for="csv">{{ __('CSV file') }}</label>
                <input id="csv" type="file" wire:model="file" accept=".csv,.txt" class="flux-input !py-2">
                @error('file') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                <div wire:loading wire:target="file" class="text-xs text-zinc-500 mt-1">{{ __('Uploading…') }}</div>
            </div>
            <div>
                <label class="block text-sm font-medium text-zinc-700 mb-1" for="year">{{ __('Fiscal year') }}</label>
                <input id="year" type="number" wire:model.live="fiscalYear" min="2020" max="2035" class="flux-input">
                @error('fiscalYear') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-3">
            <x-ui.button wire:click="check" variant="primary" :disabled="!$file">{{ __('Validate file') }}</x-ui.button>
            @if($preview && $preview['error_count'] === 0 && $preview['activities'] > 0)
                <x-ui.button wire:click="import" wire:confirm="{{ __('Import :n activities as Draft?', ['n' => $preview['activities']]) }}" variant="black">
                    {{ __('Import :n activities', ['n' => $preview['activities']]) }}
                </x-ui.button>
            @endif
            <a href="{{ route('management.import.template') }}" class="text-sm font-medium text-indigo-600 hover:underline ml-auto">
                {{ __('Download CSV template') }}
            </a>
        </div>
    </x-ui.card>

    @if($preview)
        <x-ui.card>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-center">
                <div><p class="text-2xl font-bold text-zinc-900">{{ $preview['activities'] }}</p><p class="text-xs text-zinc-500">{{ __('Activities') }}</p></div>
                <div><p class="text-2xl font-bold text-zinc-900">{{ $preview['indicators'] }}</p><p class="text-xs text-zinc-500">{{ __('Indicators') }}</p></div>
                <div><p class="text-2xl font-bold text-zinc-900">{{ $preview['line_items'] }}</p><p class="text-xs text-zinc-500">{{ __('Budget lines') }}</p></div>
                <div><p class="text-2xl font-bold text-zinc-900">$ {{ number_format($preview['total'], 2) }}</p><p class="text-xs text-zinc-500">{{ __('Total requested') }}</p></div>
            </div>

            @if($preview['error_count'] > 0)
                <div class="alert alert-error mt-5 !items-start">
                    <div>
                        <p class="font-semibold">{{ trans_choice(':n problem found|:n problems found', $preview['error_count'], ['n' => $preview['error_count']]) }}</p>
                        <ul class="mt-2 space-y-1 text-xs font-normal">
                            @foreach($preview['errors'] as $e)
                                <li>
                                    @if($e['row'] > 0)<span class="font-semibold">{{ __('Row') }} {{ $e['row'] }}:</span>@endif
                                    {{ $e['message'] }}
                                </li>
                            @endforeach
                        </ul>
                        @if($preview['error_count'] > count($preview['errors']))
                            <p class="mt-2 text-xs">{{ __('Only the first :n are shown.', ['n' => count($preview['errors'])]) }}</p>
                        @endif
                    </div>
                </div>
            @else
                <div class="alert alert-success mt-5">{{ __('The file is valid and ready to import.') }}</div>
            @endif
        </x-ui.card>
    @endif

    @if($result)
        <div class="alert alert-success">
            {{ __('Imported :a activities, :i indicators and :l budget lines ($ :t).', ['a' => $result['activities'], 'i' => $result['indicators'], 'l' => $result['line_items'], 't' => number_format($result['total'], 2)]) }}
            <a href="{{ route('activities.index') }}" wire:navigate class="ml-auto font-semibold underline">{{ __('View activities') }}</a>
        </div>
    @endif

    <x-ui.card>
        <h2 class="text-sm font-semibold text-zinc-900 mb-2">{{ __('File layout') }}</h2>
        <p class="text-sm text-zinc-500 mb-3">
            {{ __('One row per activity, indicator or budget line. Rows with the same ref belong to the same activity; fill the activity columns on its first row only. Separator can be ; , or tab. Dates are YYYY-MM-DD and must fall in the fiscal year.') }}
        </p>
        <p class="text-xs font-mono text-zinc-600 break-words">{{ implode(' ; ', \App\Services\ActivityCsvImporter::COLUMNS) }}</p>
        <p class="text-xs text-zinc-500 mt-3">
            {{ __('Directorate and program codes must already exist (Management → Entities); class_code is 10, 20, 30, 40 or 50; indicator_type is Output or Outcome.') }}
        </p>
    </x-ui.card>
</div>
