<?php

use Livewire\Volt\Component;
use App\Models\Activity;
use App\Models\LineItem;
use App\Models\AdjustedBudget;
use App\Models\FiscalEnvelope;

new class extends Component {
    public $activeYear;
    public $directorate;
    public $items = [];
    public $stats = [];

    public function mount()
    {
        $currentActiveYear = FiscalEnvelope::getEffectiveYear();
        $this->activeYear = (int)$currentActiveYear + 1;
        $this->directorate = auth()->user()->directorate;
        
        $this->loadData();
    }

    public function updatedActiveYear()
    {
        $this->loadData();
    }

    public function loadData()
    {
        if (!$this->directorate) return;

        $this->items = AdjustedBudget::whereHas('lineItem.activity', function($q) {
            $q->where('directorate_id', $this->directorate->id)
              ->where('fiscal_year', $this->activeYear);
        })->with(['lineItem.activity.program', 'lineItem.economicClassification'])->get();

        $this->calculateStats();
    }

    public function calculateStats()
    {
        $requested = Activity::where('directorate_id', $this->directorate->id ?? 0)
            ->where('fiscal_year', $this->activeYear)
            ->where('status', Activity::STATUS_APPROVED)
            ->get()->flatMap->lineItems->sum('estimated_amount');

        $items = collect($this->items);
        $allocated = $items->sum('approved_amount');
        
        $envelope = FiscalEnvelope::where('fiscal_year', $this->activeYear)->first();

        $this->stats = [
            'requested' => $requested,
            'allocated' => $allocated,
            'variance' => $allocated - $requested,
            'status' => $items->first()->status ?? 'N/A',
            'envelope_status' => $envelope->status ?? 'Draft'
        ];
    }

    public function confirmAllocation()
    {
        foreach ($this->items as $item) {
            if ($item instanceof AdjustedBudget) {
                $item->update(['status' => 'Aprovado']);
            } else {
                AdjustedBudget::find($item['id'])->update(['status' => 'Aprovado']);
            }
        }

        // Notify Planning/Minister
        $admins = \App\Models\User::role(['Admin', 'Planning'])->get();
        foreach ($admins as $admin) {
            $admin->notify(new \App\Notifications\SystemNotification(
                __('Allocation Accepted'),
                __('The Directorate of :dir has accepted their allocation for :year.', ['dir' => $this->directorate->name, 'year' => $this->activeYear]),
                route('planning.fiscal', absolute: false)
            ));
        }

        $this->loadData();
        $this->dispatch('toast', message: __('Budget allocation confirmed by Directorate.'), type: 'success');
    }

    public function requestAdjustment()
    {
        foreach ($this->items as $item) {
            if ($item instanceof AdjustedBudget) {
                $item->update(['status' => 'Parcial']);
            } else {
                AdjustedBudget::find($item['id'])->update(['status' => 'Parcial']);
            }
        }

        // Notify Planning/Minister
        $admins = \App\Models\User::role(['Admin', 'Planning'])->get();
        foreach ($admins as $admin) {
            $admin->notify(new \App\Notifications\SystemNotification(
                __('Adjustment Requested'),
                __('The Directorate of :dir is requesting a budget adjustment for :year.', ['dir' => $this->directorate->name, 'year' => $this->activeYear]),
                route('planning.fiscal', absolute: false)
            ));
        }

        $this->loadData();
        $this->dispatch('toast', message: __('Adjustment request sent to Minister.'), type: 'success');
    }
}; ?>

<div class="space-y-6">
    <div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-6">
        <div>
            <h1 class="page-title">{{ __('Directorate Budget Review') }}</h1>
            <div class="flex items-center gap-4 mt-3">
                <p class="page-subtitle">
                    {{ __('Review allocation for') }} <span class="text-indigo-600">{{ $directorate->name ?? 'N/A' }}</span>
                </p>
                <select wire:model.live="activeYear" class="bg-slate-100 border-none rounded-lg text-[10px] font-black uppercase tracking-widest py-1 px-3 focus:ring-2 focus:ring-indigo-500/20 cursor-pointer">
                    @php 
                        $baseYear = (int)App\Models\SystemSetting::get('planning_active_year', date('Y'));
                    @endphp
                    <option value="{{ $baseYear }}">{{ $baseYear }}</option>
                    <option value="{{ $baseYear + 1 }}">{{ $baseYear + 1 }}</option>
                </select>
            </div>
        </div>

        <div class="flex gap-3">
            @if(count($this->items) > 0 && $this->stats['envelope_status'] !== App\Models\FiscalEnvelope::STATUS_CLOSED)
                <button wire:click="requestAdjustment" 
                    class="px-6 py-3 border border-slate-200 text-slate-600 text-[10px] font-black uppercase tracking-widest rounded-xl hover:bg-slate-50 transition-all shadow-sm">
                    {{ __('Request Adjustment') }}
                </button>
                <button wire:click="confirmAllocation" 
                    class="px-6 py-3 bg-emerald-600 text-white text-[10px] font-black uppercase tracking-widest rounded-xl hover:bg-emerald-700 transition-all shadow-lg shadow-emerald-600/20">
                    {{ __('Confirm & Accept') }}
                </button>
            @endif
        </div>
    </div>

    @if(!$directorate)
        <div class="p-8 bg-rose-50 border border-rose-100 rounded-[2rem] text-rose-700">
            <h3 class="text-xl font-black tracking-tight">{{ __('Directorate Not Assigned') }}</h3>
            <p class="text-sm font-bold opacity-80 mt-1">
                {{ __('Your user account is not associated with a Directorate. Please contact the administrator.') }}
            </p>
        </div>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="bg-white p-6 rounded-3xl shadow-sm border border-slate-100 flex items-center justify-between">
                <div>
                    <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __('PAA Requested') }}</div>
                    <div class="text-2xl font-black text-slate-900 leading-none">$ {{ number_format($stats['requested'], 0) }}</div>
                </div>
                <div class="h-10 w-1 pt-1 bg-slate-100 rounded-full"></div>
                <div>
                    <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __('OGE Allocated') }}</div>
                    <div class="text-2xl font-black text-indigo-600 leading-none">$ {{ number_format($stats['allocated'], 0) }}</div>
                </div>
            </div>

            <div class="p-6 rounded-3xl shadow-sm border {{ ($stats['variance'] < 0) ? 'bg-rose-50 border-rose-100' : 'bg-emerald-50 border-emerald-100' }} flex items-center justify-between">
                <div>
                    <div class="text-[10px] font-black uppercase tracking-widest mb-1 {{ ($stats['variance'] < 0) ? 'text-rose-400' : 'text-emerald-400' }}">
                        {{ ($stats['variance'] < 0) ? __('Budget Cut') : __('Additional Funding') }}
                    </div>
                    <div class="text-2xl font-black leading-none {{ ($stats['variance'] < 0) ? 'text-rose-700' : 'text-emerald-700' }}">
                        $ {{ number_format(abs($stats['variance']), 0) }}
                    </div>
                </div>
                <div class="text-right">
                    <div class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __('Status') }}</div>
                    <span class="px-3 py-1 bg-white rounded-lg text-[10px] font-black uppercase tracking-tighter border shadow-sm">
                        {{ $stats['status'] }}
                    </span>
                </div>
            </div>
        </div>

        @if (session()->has('message'))
            <div class="bg-emerald-50 border border-emerald-100 text-emerald-700 px-4 py-3 rounded-xl relative animate-in fade-in" role="alert">
                <span class="block sm:inline font-bold">{{ session('message') }}</span>
            </div>
        @endif

        <div class="bg-white rounded-[2rem] shadow-sm border border-slate-100 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-50/50">
                            <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest">{{ __('Activity') }}</th>
                            <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest">{{ __('Classification') }}</th>
                            <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest text-right">{{ __('Requested') }}</th>
                            <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest text-right">{{ __('Allocated') }}</th>
                            <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest text-center">{{ __('Impact') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @forelse($items as $item)
                            <tr class="hover:bg-slate-50/50 transition-colors">
                                <td class="px-6 py-4">
                                    <div class="text-[10px] font-black text-indigo-500 uppercase mb-1">{{ $item->lineItem->activity->program->oge_code ?? '—' }}</div>
                                    <div class="text-xs font-bold text-slate-900 leading-tight max-w-sm">{{ $item->lineItem->activity->description }}</div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-xs font-bold text-slate-600">{{ $item->lineItem->economicClassification->name }}</div>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <div class="text-xs font-bold text-slate-500">$ {{ number_format($item->lineItem->estimated_amount, 0) }}</div>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <div class="text-xs font-black text-slate-900">$ {{ number_format($item->approved_amount, 0) }}</div>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    @php
                                        $diff = $item->approved_amount - $item->lineItem->estimated_amount;
                                        $percent = $item->lineItem->estimated_amount > 0 ? ($item->approved_amount / $item->lineItem->estimated_amount) * 100 : 0;
                                    @endphp
                                    <div class="flex flex-col items-center">
                                        <span class="text-[10px] font-black {{ $diff < 0 ? 'text-rose-600' : ($diff > 0 ? 'text-emerald-600' : 'text-slate-400') }}">
                                            {{ $diff < 0 ? '-' : ($diff > 0 ? '+' : '') }}{{ number_format($percent, 0) }}%
                                        </span>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="p-12 text-center text-slate-400 italic text-sm">
                                    {{ __('No budget items found for this period. Syncing might be pending.') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
