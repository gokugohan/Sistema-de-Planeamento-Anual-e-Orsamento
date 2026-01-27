<?php

use Livewire\Volt\Component;

new class extends Component {
    public function getNotificationsProperty()
    {
        return auth()->user()->unreadNotifications;
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
}; ?>

<div class="relative" x-data="{ open: false }">
    <button @click="open = !open"
        class="relative p-2 text-slate-400 hover:text-indigo-600 transition-colors bg-white rounded-xl shadow-sm border border-slate-100 group">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
            class="group-hover:scale-110 transition-transform">
            <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9" />
            <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" />
        </svg>
        @if($this->notifications->count() > 0)
            <span class="absolute top-1.5 right-1.5 flex h-2.5 w-2.5">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-rose-400 opacity-75"></span>
                <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-rose-500 border-2 border-white"></span>
            </span>
        @endif
    </button>

    <div x-show="open" @click.away="open = false" x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 scale-95 translate-y-2"
        x-transition:enter-end="opacity-100 scale-100 translate-y-0"
        class="absolute right-0 mt-4 w-80 bg-white rounded-2xl shadow-2xl border border-slate-100 overflow-hidden z-[60]">

        <div class="p-4 border-b border-slate-50 flex items-center justify-between bg-slate-50/50">
            <h3 class="text-xs font-black uppercase tracking-widest text-slate-500">{{ __('Notifications') }}</h3>
            @if($this->notifications->count() > 0)
                <button wire:click="markAllAsRead"
                    class="text-[10px] font-black text-indigo-600 uppercase tracking-tighter hover:underline">
                    {{ __('Mark all read') }}
                </button>
            @endif
        </div>

        <div class="max-h-96 overflow-y-auto">
            @forelse($this->notifications as $notification)
                <div class="p-4 border-b border-slate-50 hover:bg-slate-50/50 transition-colors group relative">
                    <div class="flex gap-3">
                        <div class="mt-1 shrink-0">
                            <div class="h-2 w-2 rounded-full bg-indigo-500"></div>
                        </div>
                        <div class="flex-1">
                            @if(isset($notification->data['action_url']))
                                <a href="{{ $notification->data['action_url'] }}"
                                    wire:click="markAsRead('{{ $notification->id }}')" wire:navigate
                                    class="block hover:opacity-75 transition-opacity">
                                    <p class="text-xs font-black text-slate-900 leading-tight">
                                        {{ $notification->data['title'] }}</p>
                                    <p class="text-[11px] text-slate-500 mt-1 leading-relaxed">
                                        {{ $notification->data['message'] }}</p>
                                </a>
                            @else
                                <div>
                                    <p class="text-xs font-black text-slate-900 leading-tight">
                                        {{ $notification->data['title'] }}</p>
                                    <p class="text-[11px] text-slate-500 mt-1 leading-relaxed">
                                        {{ $notification->data['message'] }}</p>
                                </div>
                            @endif
                            <div class="flex items-center justify-between mt-3">
                                <span
                                    class="text-[9px] font-bold text-slate-400 uppercase tracking-tighter">{{ $notification->created_at->diffForHumans() }}</span>
                                @if(isset($notification->data['action_url']))
                                    <a href="{{ $notification->data['action_url'] }}"
                                        wire:click="markAsRead('{{ $notification->id }}')"
                                        class="text-[9px] font-black text-indigo-600 uppercase tracking-widest hover:underline">
                                        {{ __('View Details') }} &rarr;
                                    </a>
                                @else
                                    <button wire:click="markAsRead('{{ $notification->id }}')"
                                        class="text-[9px] font-black text-slate-400 uppercase tracking-widest hover:text-indigo-600 transition-colors">
                                        {{ __('Dismiss') }}
                                    </button>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            @empty
                <div class="p-8 text-center">
                    <div
                        class="inline-flex items-center justify-center w-12 h-12 bg-slate-50 rounded-2xl text-slate-300 mb-3">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9" />
                            <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" />
                        </svg>
                    </div>
                    <p class="text-xs font-bold text-slate-400 italic">{{ __('Zero pending alerts') }}</p>
                </div>
            @endforelse
        </div>

        @if(auth()->user()->notifications->count() > 0)
            <div class="p-3 bg-slate-50/50 text-center border-t border-slate-50">
                <a href="{{ route('notifications.index', absolute: false) }}" wire:navigate
                    class="text-[10px] font-black text-slate-400 uppercase tracking-widest hover:text-slate-600 transition-colors inline-block w-full">
                    {{ __('View Notification History') }}
                </a>
            </div>
        @endif
    </div>
</div>