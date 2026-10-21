<?php

use App\Services\ReportingWindows;
use Livewire\Volt\Component;

/** "Ask the administrator to reopen it": shown where a closed period of the calendar stops a unit's work. */
new class extends Component {
    public int $year;
    public string $type;
    public int $period = 1;
    public bool $sent = false;

    public function ask(): void
    {
        $user = auth()->user();
        abort_unless($user && ! $user->isAdministrator(), 403);

        $this->sent = true;
        $new = app(ReportingWindows::class)->requestReopening($user, $this->year, $this->type, $this->period);
        $this->dispatch('toast', message: $new ? __('The administrators were asked to reopen it for your unit.') : __('You already asked today. The administrators have been told.'), type: 'success');
    }
}; ?>

<span>
    @if(auth()->user()?->isAdministrator())
        <a href="{{ route('management.reporting-windows') }}" wire:navigate class="font-medium text-amber-900 underline">{{ __('Open the calendar') }}</a>
    @elseif($sent)
        <span class="font-medium text-amber-900">{{ __('Asked. The administrators have been told.') }}</span>
    @else
        <button type="button" wire:click="ask" class="font-medium text-amber-900 underline">{{ __('Ask the administrator to reopen it for my unit') }}</button>
    @endif
</span>
