<?php

use App\Models\ApproverAssignment;
use App\Models\Directorate;
use App\Models\User;
use Livewire\Volt\Component;

/**
 * Approval lists: for each unit, the named people who prepare, certify and approve its activities. A step
 * with no list keeps the default rule (the director acts). A director keeps the lists of the units they
 * direct; an administrator, of any unit.
 */
new class extends Component {
    public string $unitId = '';
    public array $pick = ['prepare' => '', 'certify' => '', 'approve' => ''];

    public function mount(): void
    {
        abort_unless($this->allowed(), 403);
        $units = $this->units();
        if ($units->isNotEmpty()) {
            $this->unitId = (string) $units->first()->id;
        }
    }

    private function allowed(): bool
    {
        $u = auth()->user();

        return $u && ($u->isAdministrator() || $u->isDirector());
    }

    private function units()
    {
        $u = auth()->user();

        return $u->isAdministrator()
            ? Directorate::orderBy('code')->get(['id', 'code', 'name'])
            : Directorate::whereIn('id', $u->directedUnitIds())->orderBy('code')->get(['id', 'code', 'name']);
    }

    private function mayEdit(int $unitId): bool
    {
        return $this->allowed() && $this->units()->contains('id', $unitId);
    }

    public function add(string $step): void
    {
        abort_unless(in_array($step, ApproverAssignment::STEPS, true) && $this->mayEdit((int) $this->unitId), 403);
        $userId = (int) ($this->pick[$step] ?? 0);
        abort_unless($userId && User::whereKey($userId)->exists(), 422);

        $exists = ApproverAssignment::where(['directorate_id' => $this->unitId, 'user_id' => $userId, 'step' => $step])->exists();
        if (! $exists) {
            ApproverAssignment::create([
                'directorate_id' => (int) $this->unitId, 'user_id' => $userId, 'step' => $step, 'created_by' => auth()->id(),
                'position' => (int) ApproverAssignment::where(['directorate_id' => $this->unitId, 'step' => $step])->max('position') + 1,
            ]);
        }
        $this->pick[$step] = '';
    }

    /** Certify and approve lists can ask everyone named to sign, one after the other in the listed order. */
    public function toggleSequential(string $step): void
    {
        abort_unless(in_array($step, [ApproverAssignment::CERTIFY, ApproverAssignment::APPROVE], true) && $this->mayEdit((int) $this->unitId), 403);
        $rows = ApproverAssignment::where(['directorate_id' => (int) $this->unitId, 'step' => $step]);
        $on = ! $rows->clone()->where('sequential', true)->exists();
        foreach ($rows->get() as $row) {
            $row->update(['sequential' => $on]);
        }
        $this->dispatch('toast', message: $on ? __('Everyone on this list now signs, in the order listed.') : __('Anyone on this list may act again.'), type: 'info');
    }

    public function remove(int $id): void
    {
        $a = ApproverAssignment::findOrFail($id);
        abort_unless($this->mayEdit($a->directorate_id), 403);
        $key = ['directorate_id' => $a->directorate_id, 'step' => $a->step];
        $a->delete();
        if (! ApproverAssignment::where($key)->exists()) {
            $this->dispatch('toast', message: __('That list is now empty: the default rule applies again.'), type: 'info');
        }
    }

    public string $replaceOld = '';
    public string $replaceNew = '';

    /** A reshuffle: one person takes over from another in every list of the units this user keeps. */
    public function replaceEverywhere(): void
    {
        abort_unless($this->allowed(), 403);
        $this->validate(['replaceOld' => 'required|integer|exists:users,id', 'replaceNew' => 'required|integer|exists:users,id|different:replaceOld']);
        $ids = $this->units()->pluck('id');
        $n = 0;
        foreach (ApproverAssignment::whereIn('directorate_id', $ids)->where('user_id', (int) $this->replaceOld)->get() as $row) {
            if (ApproverAssignment::where(['directorate_id' => $row->directorate_id, 'step' => $row->step, 'user_id' => (int) $this->replaceNew])->exists()) {
                $row->delete(); // already named there: the old one simply leaves
            } else {
                $row->update(['user_id' => (int) $this->replaceNew]);
            }
            $n++;
        }
        $this->reset('replaceOld', 'replaceNew');
        $this->dispatch('toast', message: $n ? trans_choice('Replaced in :n list.|Replaced in :n lists.', $n, ['n' => $n]) : __('That person is not named in any of your lists.'), type: $n ? 'success' : 'info');
    }

    /** Move a person up or down within the list: the order is the order they are named. */
    public function move(int $id, string $direction): void
    {
        $a = ApproverAssignment::findOrFail($id);
        abort_unless($this->mayEdit($a->directorate_id), 403);
        $list = ApproverAssignment::where(['directorate_id' => $a->directorate_id, 'step' => $a->step])->orderBy('position')->orderBy('id')->get()->values();
        $i = $list->search(fn ($x) => $x->id === $a->id);
        $j = $direction === 'up' ? $i - 1 : $i + 1;
        if ($j < 0 || $j >= $list->count()) {
            return;
        }
        $order = $list->all();
        [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
        foreach ($order as $n => $row) {
            $row->update(['position' => $n + 1]);
        }
    }

    public function with(): array
    {
        $unit = $this->unitId ? $this->units()->firstWhere('id', (int) $this->unitId) : null;
        $lists = $unit ? ApproverAssignment::with('user:id,name')->where('directorate_id', $unit->id)->orderBy('position')->orderBy('id')->get()->groupBy('step') : collect();

        $named = ApproverAssignment::whereIn('directorate_id', $this->units()->pluck('id'))->distinct()->pluck('user_id');

        return [
            'units' => $this->units(), 'unit' => $unit, 'lists' => $lists, 'people' => User::orderBy('name')->limit(500)->get(['id', 'name']),
            'named' => User::whereIn('id', $named)->orderBy('name')->get(['id', 'name']),
        ];
    }
}; ?>

<div class="space-y-6">
    <x-ui.page-header :title="__('Approval lists')" :subtitle="__('Name the people who prepare, certify and approve the activities of a unit. A step with no list keeps the default rule: the director of the unit acts.')" />

    <x-ui.card padding="p-5">
        <div class="max-w-md">
            <label for="al-u" class="field-label">{{ __('Unit') }}</label>
            <select id="al-u" wire:model.live="unitId" class="flux-input">
                @foreach($units as $u)<option value="{{ $u->id }}">{{ $u->code }} — {{ $u->name }}</option>@endforeach
            </select>
        </div>
    </x-ui.card>

    @if($unit)
        @php
            $steps = [
                'prepare' => [__('Prepare'), __('Prepare the activity and submit it. Without a list, anyone who may submit does.')],
                'certify' => [__('Certify'), __('Review it and validate it. Without a list, the director of the unit, then of the unit above, does.')],
                'approve' => [__('Approve'), __('Give the final approval. Without a list, the Minister or the head of the office does, as the institution\'s rules say.')],
            ];
        @endphp
        <div class="grid gap-6 lg:grid-cols-3">
            @foreach($steps as $key => [$title, $help])
                @php $members = $lists[$key] ?? collect(); @endphp
                <x-ui.card :title="$title" :description="$help" padding="p-5" wire:key="al-{{ $unit->id }}-{{ $key }}">
                    @if($members->isEmpty())
                        <p class="rounded-md bg-zinc-50 px-3 py-2 text-sm text-zinc-600">{{ __('No list: the default rule applies.') }}</p>
                    @else
                        <ol class="space-y-2">
                            @foreach($members as $m)
                                <li class="flex items-center justify-between gap-2 rounded-md border border-zinc-200 px-3 py-2 text-sm" wire:key="alm-{{ $m->id }}">
                                    <span class="min-w-0 truncate"><span class="mr-2 tabular-nums text-zinc-400">{{ $loop->iteration }}.</span>{{ $m->user?->name }}</span>
                                    <span class="flex flex-shrink-0 items-center gap-2 text-xs font-medium">
                                        <button type="button" wire:click="move({{ $m->id }}, 'up')" class="text-zinc-600 hover:text-zinc-900" aria-label="{{ __('Move up') }}" @disabled($loop->first)>↑</button>
                                        <button type="button" wire:click="move({{ $m->id }}, 'down')" class="text-zinc-600 hover:text-zinc-900" aria-label="{{ __('Move down') }}" @disabled($loop->last)>↓</button>
                                        <button type="button" wire:click="remove({{ $m->id }})" wire:confirm="{{ __('Remove this person from the list?') }}" class="text-rose-700 hover:underline">{{ __('Remove') }}</button>
                                    </span>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                    @if($key !== 'prepare' && $members->count() > 1)
                        @php $inOrder = $members->contains('sequential', true); @endphp
                        <label class="mt-4 flex items-start gap-2 text-sm text-zinc-700">
                            <input type="checkbox" class="mt-0.5 rounded border-zinc-300" @checked($inOrder) wire:click="toggleSequential('{{ $key }}')">
                            <span>{{ __('Everyone signs, in the order listed') }}<span class="block text-xs text-zinc-500">{{ __('It moves on only after the last one has signed. Without this, any one of them may act.') }}</span></span>
                        </label>
                    @endif
                    <div class="mt-4 flex gap-2">
                        <select wire:model="pick.{{ $key }}" class="flux-input" aria-label="{{ __('Add a person') }}">
                            <option value="">{{ __('Add a person') }}</option>
                            @foreach($people as $p)@unless($members->contains('user_id', $p->id))<option value="{{ $p->id }}">{{ $p->name }}</option>@endunless @endforeach
                        </select>
                        <x-ui.button type="button" variant="secondary" shadow="none" wire:click="add('{{ $key }}')">{{ __('Add') }}</x-ui.button>
                    </div>
                </x-ui.card>
            @endforeach
        </div>
        @if($named->isNotEmpty())
            <x-ui.card :title="__('Replace someone in every list')" :description="__('After a reshuffle: one person takes over from another in all the lists of the units you keep.')" padding="p-5">
                <div class="grid gap-4 md:grid-cols-12">
                    <div class="md:col-span-5">
                        <label for="al-ro" class="field-label">{{ __('Leaves') }}</label>
                        <select id="al-ro" wire:model="replaceOld" class="flux-input"><option value="">{{ __('Select...') }}</option>@foreach($named as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select>
                        @error('replaceOld')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="md:col-span-5">
                        <label for="al-rn" class="field-label">{{ __('Takes over') }}</label>
                        <select id="al-rn" wire:model="replaceNew" class="flux-input"><option value="">{{ __('Select...') }}</option>@foreach($people as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select>
                        @error('replaceNew')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="flex items-end md:col-span-2">
                        <x-ui.button type="button" wire:click="replaceEverywhere" wire:confirm="{{ __('Replace this person in every list of your units?') }}">{{ __('Replace') }}</x-ui.button>
                    </div>
                </div>
            </x-ui.card>
        @endif
        <p class="text-xs text-zinc-500">{{ __('A list for a unit also applies to the units below it that have no list of their own. Anyone on a list may act, unless it is set so that everyone signs in the order listed. Administrators are never locked out.') }}</p>
    @endif
</div>
