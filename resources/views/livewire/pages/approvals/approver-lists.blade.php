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

    public function remove(int $id): void
    {
        $a = ApproverAssignment::findOrFail($id);
        abort_unless($this->mayEdit($a->directorate_id), 403);
        $a->delete();
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

        return ['units' => $this->units(), 'unit' => $unit, 'lists' => $lists, 'people' => $unit ? User::orderBy('name')->limit(500)->get(['id', 'name']) : collect()];
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
        <p class="text-xs text-zinc-500">{{ __('A list for a unit also applies to the units below it that have no list of their own. Anyone on a list may act; the order is the order they are named in. Administrators are never locked out.') }}</p>
    @endif
</div>
