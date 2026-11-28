<?php

use App\Support\Modules;
use Livewire\Volt\Component;

/**
 * The add-on modules: the plan (planning and approval) is always there; execution reporting, performance and the
 * budget envelope are switched on here. A module that is off is hidden and its pages are not found, but what was
 * recorded in it stays. Performance needs execution. Saving is audited (SystemSetting is Auditable).
 */
new class extends Component {
    public bool $execution = false;
    public bool $performance = false;
    public bool $budget = false;

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);
        $s = Modules::states();
        $this->execution = $s[Modules::EXECUTION];
        $this->performance = $s[Modules::PERFORMANCE];
        $this->budget = $s[Modules::BUDGET];
    }

    public function updatedExecution(bool $on): void
    {
        // performance reads the execution reports: it goes off with them
        if (! $on) {
            $this->performance = false;
        }
    }

    public function save(): void
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);
        Modules::save($this->execution, $this->performance && $this->execution, $this->budget);
        $this->performance = $this->performance && $this->execution;

        $this->dispatch('toast', message: __('Modules saved.'), type: 'success');
        // the menu is drawn by the layout: reload so it shows the modules as they are now
        $this->redirect(route('management.modules'), navigate: true);
    }

    public function with(): array
    {
        return [
            'modules' => [
                ['execution', __('Execution reporting'), __('Units report what each approved activity spent and achieved, by month and quarter; someone else verifies it; the quarter is compiled into the text of the institution\'s report.'),
                    __('Adds Report execution and Compile the quarter to the menu, the figures to verify to Waiting for you, the reporting dates to the Calendar, and the execution of each activity to its page.'), false],
                ['performance', __('Performance insights'), __('Executed and delivered against the plan, by quarter, program and unit, with the monthly and quarterly performance report.'),
                    __('Adds Performance to the menu and its figures to the dashboard. It reads the execution reports, so it needs Execution reporting.'), ! $this->execution],
                ['budget', __('Budget envelope'), __('Record what the budget ceiling, the budget committee and the budget law decided, by category, against the plan, and run an adjustment round so the plan follows the decision.'),
                    __('Adds Budget decisions and Adjustment round to the menu. Whether the decisions are also shared among the units is chosen in Optional rules.'), false],
            ],
        ];
    }
}; ?>

<div class="space-y-4">
    <x-system-tabs />
    <x-ui.page-header :title="__('Modules')" :subtitle="__('Planning and approving the annual plan is always there. Switch on the parts your institution also uses. A module that is switched off is hidden from everyone; what was recorded in it is kept and comes back when it is switched on again.')" />

    <form wire:submit="save" class="space-y-4">
        <x-ui.card padding="p-0">
            @foreach($modules as [$prop, $name, $what, $adds, $disabled])
                <div class="flex items-start justify-between gap-6 border-t border-zinc-100 px-5 py-4 first:border-t-0" x-data="{ more: false }">
                    <div class="min-w-0">
                        <p id="m-{{ $prop }}" class="text-sm font-semibold text-zinc-900">{{ $name }}</p>
                        <p class="mt-0.5 max-w-2xl text-sm text-zinc-600">{{ $what }}</p>
                        <button type="button" @click="more = ! more" :aria-expanded="more.toString()" class="mt-1 text-xs font-medium text-indigo-700 hover:underline">
                            <span x-show="! more">{{ __('What it adds') }}</span><span x-show="more" x-cloak>{{ __('Hide details') }}</span>
                        </button>
                        <p x-show="more" x-cloak class="mt-1 max-w-2xl text-xs leading-relaxed text-zinc-600">{{ $adds }}</p>
                        @if($disabled)
                            <p class="mt-1 text-xs font-medium text-amber-700">{{ __('Switch on Execution reporting first.') }}</p>
                        @endif
                    </div>
                    <label class="relative mt-0.5 inline-flex shrink-0 items-center {{ $disabled ? 'cursor-not-allowed opacity-50' : 'cursor-pointer' }}">
                        <input type="checkbox" wire:model.live="{{ $prop }}" @disabled($disabled) class="peer sr-only" role="switch" aria-labelledby="m-{{ $prop }}">
                        <span class="h-6 w-11 rounded-full bg-zinc-300 transition-colors peer-checked:bg-indigo-600 peer-focus-visible:ring-2 peer-focus-visible:ring-indigo-600 peer-focus-visible:ring-offset-2"></span>
                        <span class="pointer-events-none absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-[#fff] shadow transition-transform peer-checked:translate-x-5"></span>
                    </label>
                </div>
            @endforeach
        </x-ui.card>

        <div class="flex justify-end">
            <x-ui.button type="submit" variant="primary" size="md">{{ __('Save modules') }}</x-ui.button>
        </div>
    </form>
</div>
