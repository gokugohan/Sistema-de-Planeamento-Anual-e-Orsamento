<?php

use App\Models\ApprovalDelegation;
use App\Models\Directorate;
use App\Models\User;
use App\Notifications\SystemNotification;
use Livewire\Volt\Component;

/**
 * Interim approvers: for a set time, someone stands in for the director of a unit, with that seat's
 * authority over the unit and the units below it. A director appoints one for their own unit; an
 * administrator for any unit. It ends by date or when revoked.
 */
new class extends Component {
    public string $unitId = '';
    public string $userId = '';
    public string $startsOn = '';
    public string $endsOn = '';
    public string $reason = '';

    public function mount(): void
    {
        abort_unless($this->allowed(), 403);
        $this->startsOn = now()->toDateString();
        $units = $this->units();
        if ($units->count() === 1) {
            $this->unitId = (string) $units->first()->id;
        }
    }

    private function allowed(): bool
    {
        $user = auth()->user();

        return $user && ($user->isAdministrator() || ($user->hasRole('Director') && $user->directorate_id));
    }

    /** The units this person may appoint an interim for: any (administrator) or their own seat (director). */
    private function units()
    {
        $user = auth()->user();

        return $user->isAdministrator() ? Directorate::orderBy('code')->get(['id', 'code', 'name']) : Directorate::where('id', $user->directorate_id)->get(['id', 'code', 'name']);
    }

    public function appoint(): void
    {
        abort_unless($this->allowed(), 403);
        $this->validate([
            'unitId' => 'required|integer|exists:directorates,id',
            'userId' => 'required|integer|exists:users,id',
            'startsOn' => 'required|date',
            'endsOn' => 'required|date|after_or_equal:startsOn',
            'reason' => 'nullable|string|max:200',
        ]);
        abort_unless($this->units()->contains('id', (int) $this->unitId), 403);
        if ((int) $this->userId === auth()->id()) {
            $this->addError('userId', __('You cannot appoint yourself.'));

            return;
        }

        $delegation = ApprovalDelegation::create([
            'user_id' => (int) $this->userId, 'directorate_id' => (int) $this->unitId, 'starts_on' => $this->startsOn,
            'ends_on' => $this->endsOn, 'reason' => trim($this->reason) ?: null, 'created_by' => auth()->id(),
        ]);
        $unit = Directorate::find($this->unitId);
        User::find($this->userId)?->notify(new SystemNotification(
            __('Interim appointment'),
            __('You stand in for the director of :unit from :from to :to.', ['unit' => $unit->name, 'from' => $delegation->starts_on->format('d/m/Y'), 'to' => $delegation->ends_on->format('d/m/Y')]),
            route('approvals.index', [], false)
        ));

        $this->reset('userId', 'endsOn', 'reason');
        $this->dispatch('toast', message: __('Interim approver appointed.'), type: 'success');
    }

    public function revoke(int $id): void
    {
        abort_unless($this->allowed(), 403);
        $d = ApprovalDelegation::whereNull('revoked_at')->findOrFail($id);
        abort_unless($this->units()->contains('id', $d->directorate_id), 403);
        $d->update(['revoked_at' => now()]);
        $this->dispatch('toast', message: __('Appointment revoked.'), type: 'success');
    }

    public function with(): array
    {
        $unitIds = $this->units()->pluck('id');

        return [
            'units' => $this->units(),
            'people' => User::where('id', '!=', auth()->id())->orderBy('name')->get(['id', 'name', 'email']),
            'delegations' => ApprovalDelegation::with(['user:id,name', 'directorate:id,code,name'])->whereIn('directorate_id', $unitIds)->orderByDesc('starts_on')->limit(100)->get(),
        ];
    }
}; ?>

<div class="space-y-6">
    <x-ui.page-header :title="__('Interim approvers')" :subtitle="__('When a director is away, someone stands in for them for a set time, with their authority over the unit and the units below it. It ends on its own when the time is over.')" />

    <x-ui.card :title="__('Appoint an interim approver')" padding="p-5">
        <form wire:submit="appoint" class="grid gap-4 md:grid-cols-12">
            <div class="md:col-span-3">
                <label for="dl-u" class="field-label">{{ __('Unit') }}</label>
                <select id="dl-u" wire:model="unitId" class="flux-input">
                    <option value="">{{ __('Select...') }}</option>
                    @foreach($units as $u)<option value="{{ $u->id }}">{{ $u->code }} — {{ $u->name }}</option>@endforeach
                </select>
                @error('unitId')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <div class="md:col-span-3">
                <label for="dl-p" class="field-label">{{ __('Stands in') }}</label>
                <select id="dl-p" wire:model="userId" class="flux-input">
                    <option value="">{{ __('Select...') }}</option>
                    @foreach($people as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                </select>
                @error('userId')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <div class="md:col-span-2">
                <label for="dl-s" class="field-label">{{ __('From') }}</label>
                <input id="dl-s" type="date" wire:model="startsOn" class="flux-input">
                @error('startsOn')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <div class="md:col-span-2">
                <label for="dl-e" class="field-label">{{ __('Until') }}</label>
                <input id="dl-e" type="date" wire:model="endsOn" class="flux-input">
                @error('endsOn')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <div class="md:col-span-8">
                <label for="dl-r" class="field-label">{{ __('Reason') }}</label>
                <input id="dl-r" type="text" wire:model="reason" class="flux-input" placeholder="{{ __('e.g. annual leave') }}">
            </div>
            <div class="flex items-end md:col-span-4">
                <x-ui.button type="submit">{{ __('Appoint') }}</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card padding="p-0">
        @if($delegations->isEmpty())
            <x-ui.empty-state :title="__('No interim approvers')" :text="__('Appointments you make appear here.')" />
        @else
            <x-ui.table :headers="[__('Unit'), __('Stands in'), __('From'), __('Until'), __('Reason'), __('Status'), '']" class="text-sm">
                @foreach($delegations as $d)
                    @php $active = $d->isActive(); @endphp
                    <tr wire:key="dl-{{ $d->id }}">
                        <td class="px-5 py-3">{{ $d->directorate?->code }} — {{ $d->directorate?->name }}</td>
                        <td class="px-5 py-3 font-medium text-zinc-900">{{ $d->user?->name }}</td>
                        <td class="px-5 py-3 tabular-nums">{{ $d->starts_on->format('d/m/Y') }}</td>
                        <td class="px-5 py-3 tabular-nums">{{ $d->ends_on->format('d/m/Y') }}</td>
                        <td class="px-5 py-3 text-zinc-600">{{ $d->reason }}</td>
                        <td class="px-5 py-3">
                            <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium {{ $active ? 'bg-emerald-50 text-emerald-800' : ($d->revoked_at ? 'bg-rose-50 text-rose-800' : 'bg-zinc-100 text-zinc-700') }}">
                                {{ $d->revoked_at ? __('Revoked') : ($active ? __('In force') : ($d->starts_on->isFuture() ? __('Starts later') : __('Ended'))) }}
                            </span>
                        </td>
                        <td class="px-5 py-3 text-right">
                            @unless($d->revoked_at || $d->ends_on->isPast())
                                <button type="button" wire:click="revoke({{ $d->id }})" wire:confirm="{{ __('Revoke this appointment?') }}" class="text-xs font-medium text-rose-700 hover:underline">{{ __('Revoke') }}</button>
                            @endunless
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>
</div>
