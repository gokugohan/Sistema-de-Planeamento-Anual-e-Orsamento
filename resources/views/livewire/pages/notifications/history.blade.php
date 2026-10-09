<?php

use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public function with(): array
    {
        return [
            'notifications' => auth()->user()
                ->notifications()
                ->paginate(15),
        ];
    }

    public function markAsRead($id)
    {
        $notification = auth()->user()->notifications()->findOrFail($id);
        $notification->markAsRead();
    }

    public function markAllAsRead()
    {
        auth()->user()->unreadNotifications->markAsRead();
    }
    
    public function delete($id)
    {
        auth()->user()->notifications()->findOrFail($id)->delete();
    }
}; ?>

<div class="space-y-10 py-6 animate-in fade-in duration-700">
    <div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-6">
        <div>
            <h1 class="page-title">{{ __('Notifications') }}</h1>
            <p class="page-subtitle">
                {{ __('Administrative Alerts & Workflow Updates') }}
            </p>
        </div>
        <div class="flex items-center gap-3">
            <x-ui.button wire:click="markAllAsRead" variant="ghost" size="sm" class="!text-indigo-600 font-semibold text-xs">
                {{ __('Mark all as read') }}
            </x-ui.button>
        </div>
    </div>

    <div class="space-y-4">
        @forelse($notifications as $notification)
            <div class="bg-white p-6 rounded-xl border {{ $notification->read_at ? 'border-zinc-100 opacity-75' : 'border-indigo-100 shadow-sm ' }} flex items-start gap-6 group transition-all duration-300">
                <div class="mt-1 shrink-0">
                    <div class="h-12 w-12 rounded-xl {{ $notification->read_at ? 'bg-zinc-50 text-zinc-300' : 'bg-indigo-50 text-indigo-600' }} flex items-center justify-center shadow-sm">
                        @if(!$notification->read_at)
                            <span class="relative flex h-3 w-3 absolute -top-1 -right-1">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-indigo-400 opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-3 w-3 bg-indigo-500 border-2 border-white"></span>
                            </span>
                        @endif
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>
                        </svg>
                    </div>
                </div>
                
                <div class="flex-1">
                    <div class="flex flex-col md:flex-row md:items-center justify-between gap-2">
                         @if(isset($notification->data['action_url']))
                            <a href="{{ $notification->data['action_url'] }}" wire:click="markAsRead('{{ $notification->id }}')" wire:navigate class="hover:underline decoration-indigo-500 block">
                                <h3 class="text-sm font-semibold text-zinc-900 leading-tight">{{ $notification->data['title'] ?? __('System Alert') }}</h3>
                                <p class="text-xs text-zinc-500 mt-2 leading-relaxed max-w-2xl">{{ $notification->data['message'] }}</p>
                            </a>
                        @else
                            <div>
                                <h3 class="text-sm font-semibold text-zinc-900 leading-tight">{{ $notification->data['title'] ?? __('System Alert') }}</h3>
                                <p class="text-xs text-zinc-500 mt-2 leading-relaxed max-w-2xl">{{ $notification->data['message'] }}</p>
                            </div>
                        @endif
                        <span class="text-xs font-bold text-zinc-400 ">{{ $notification->created_at->diffForHumans() }}</span>
                    </div>
                    
                    <div class="mt-6 flex items-center gap-4">
                        @if(isset($notification->data['action_url']))
                            <x-ui.button variant="black" size="xs" href="{{ $notification->data['action_url'] }}" wire:click="markAsRead('{{ $notification->id }}')" wire:navigate class="!px-6 !rounded-xl">
                                {{ __('Take Action') }} &rarr;
                            </x-ui.button>
                        @endif
                        
                        @if(!$notification->read_at)
                            <button wire:click="markAsRead('{{ $notification->id }}')" class="text-xs font-semibold text-indigo-600 hover:underline">
                                {{ __('Mark read') }}
                            </button>
                        @endif
                        
                        <button wire:click="delete('{{ $notification->id }}')" class="text-xs font-semibold text-zinc-300 hover:text-rose-500 transition-colors opacity-0 group-hover:opacity-100">
                            {{ __('Purge') }}
                        </button>
                    </div>
                </div>
            </div>
        @empty
            <div class="p-20 text-center bg-zinc-50 rounded-xl border border-dashed border-zinc-200">
                <div class="inline-flex items-center justify-center w-20 h-20 bg-white rounded-xl text-zinc-200 mb-6 shadow-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
                </div>
                <h3 class="text-lg font-semibold text-zinc-900 leading-none italic">{{ __('Archive Clear') }}</h3>
                <p class="text-xs font-bold text-zinc-400 mt-3">{{ __('You have no notifications in your history.') }}</p>
            </div>
        @endforelse
    </div>

    @if($notifications->hasPages())
        <div class="p-8 bg-white border border-zinc-100 rounded-xl shadow-sm">
            {{ $notifications->links() }}
        </div>
    @endif
</div>
