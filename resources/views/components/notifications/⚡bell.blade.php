<?php

use App\Models\Notification;
use Livewire\Component;
use Livewire\Attributes\On;

new class extends Component
{
    public $unreadCount = 0;
    public $notifications = [];
    public $showDropdown = false;

    public function mount()
    {
        $this->loadNotifications();
    }

    #[On('lead-created')]
    #[On('lead-opened')]
    #[On('lead-updated')]
    #[On('notification-created')]
    public function refreshNotifications()
    {
        $this->loadNotifications();
    }

    public function loadNotifications()
    {
        try {
            if (!auth()->check()) {
                $this->unreadCount = 0;
                $this->notifications = [];
                return;
            }

            $query = Notification::where('user_id', auth()->id());

            // Apply team-based filtering for sales users
            if (auth()->user()->isSalesTeam()) {
                $userTeamIds = auth()->user()->teams()->pluck('teams.id')->toArray();
                if (!empty($userTeamIds)) {
                    $query->whereHas('lead.leadSheet', function ($q) use ($userTeamIds) {
                        $q->whereHas('teams', fn ($t) => $t->whereIn('teams.id', $userTeamIds));
                    });
                } else {
                    $query->whereRaw('1 = 0'); // no teams = no notifications
                }
            }
            // Admin and Scrapper: no extra filter (see all their notifications)

            $this->unreadCount = (clone $query)->where('read', false)->count();
            
            $this->notifications = $query
                ->with('lead.leadSheet')
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
                ->toArray();
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error loading notifications: ' . $e->getMessage());
            $this->unreadCount = 0;
            $this->notifications = [];
        }
    }

    public function toggleDropdown()
    {
        $this->showDropdown = !$this->showDropdown;
    }

    public function markAsRead($notificationId)
    {
        try {
            if (!auth()->check()) {
                return;
            }

            $notification = Notification::with('lead.leadSheet')->find($notificationId);
            if ($notification && $notification->user_id === auth()->id()) {
                // For sales users, verify the notification's lead belongs to their team's sheet
                if (auth()->user()->isSalesTeam()) {
                    $userTeamIds = auth()->user()->teams()->pluck('teams.id')->toArray();
                    if (!empty($userTeamIds) && $notification->lead && $notification->lead->leadSheet) {
                        $sheetTeamIds = $notification->lead->leadSheet->teams()->pluck('teams.id')->toArray();
                        if (empty(array_intersect($userTeamIds, $sheetTeamIds))) {
                            return; // Not authorized to mark this notification
                        }
                    } else {
                        return; // No teams or no lead/sheet
                    }
                }
                $notification->markAsRead();
                $this->loadNotifications();
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error marking notification as read: ' . $e->getMessage());
        }
    }

    public function markAllAsRead()
    {
        try {
            if (!auth()->check()) {
                return;
            }

            $query = Notification::where('user_id', auth()->id());

            // Apply team-based filtering for sales users when marking all as read
            if (auth()->user()->isSalesTeam()) {
                $userTeamIds = auth()->user()->teams()->pluck('teams.id')->toArray();
                if (!empty($userTeamIds)) {
                    $query->whereHas('lead.leadSheet', function ($q) use ($userTeamIds) {
                        $q->whereHas('teams', fn ($t) => $t->whereIn('teams.id', $userTeamIds));
                    });
                } else {
                    $query->whereRaw('1 = 0'); // no teams = no notifications
                }
            }

            $query->where('read', false)->update(['read' => true]);
            $this->loadNotifications();
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error marking all notifications as read: ' . $e->getMessage());
        }
    }

    // No render method needed for anonymous components
};
?>

<div class="relative" x-data="{ open: false }" wire:poll.3s="$refresh">
    <button 
        @click="open = !open"
        type="button"
        class="relative p-2.5 text-gray-600 hover:text-gray-900 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500 rounded-xl transition-all duration-200"
    >
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
        </svg>
        @if($unreadCount > 0)
            <span class="absolute top-1 right-1 block h-2.5 w-2.5 rounded-full bg-gradient-to-r from-red-500 to-pink-500 ring-2 ring-white shadow-lg"></span>
            <span class="absolute -top-1 -right-1 flex h-6 w-6 items-center justify-center rounded-full bg-gradient-to-r from-red-500 to-pink-500 text-xs text-white font-bold shadow-lg" wire:key="unread-count-{{ $unreadCount }}-{{ now()->timestamp }}">
                {{ $unreadCount > 9 ? '9+' : $unreadCount }}
            </span>
        @endif
    </button>

    <div 
        x-show="open"
        @click.away="open = false"
        x-cloak
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="transform opacity-0 scale-95"
        x-transition:enter-end="transform opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="transform opacity-100 scale-100"
        x-transition:leave-end="transform opacity-0 scale-95"
        class="absolute right-0 mt-2 w-96 bg-white/95 backdrop-blur-xl rounded-2xl shadow-2xl border border-gray-200/50 z-50 max-h-96 overflow-hidden flex flex-col"
        style="display: none;"
    >
        <div class="bg-gradient-to-r from-blue-600 to-indigo-600 px-5 py-4 flex justify-between items-center">
            <h3 class="text-lg font-bold text-white flex items-center">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                </svg>
                Notifications
            </h3>
            @if($unreadCount > 0)
                <button 
                    wire:click="markAllAsRead"
                    class="text-sm text-white/90 hover:text-white font-semibold px-3 py-1 bg-white/20 rounded-lg hover:bg-white/30 transition-all"
                >
                    Mark all read
                </button>
            @endif
        </div>
        
        <div class="overflow-y-auto flex-1">
            @forelse($notifications as $notification)
                <div 
                    wire:click="markAsRead({{ $notification['id'] }})"
                    class="p-4 border-b border-gray-100 hover:bg-gradient-to-r hover:from-blue-50 hover:to-indigo-50 cursor-pointer transition-all duration-200 {{ !$notification['read'] ? 'bg-gradient-to-r from-blue-50 to-indigo-50 border-l-4 border-blue-500' : '' }}"
                >
                    <div class="flex items-start">
                        <div class="flex-1">
                            <p class="text-sm text-gray-900">{{ $notification['message'] }}</p>
                            <p class="text-xs text-gray-500 mt-1">
                                {{ \Carbon\Carbon::parse($notification['created_at'])->diffForHumans() }}
                            </p>
                            @if(isset($notification['lead']))
                                <a 
                                    href="{{ route('leads.show', $notification['lead']['id']) }}" 
                                    class="text-xs text-blue-600 hover:text-blue-800 mt-1 inline-block"
                                    onclick="event.stopPropagation()"
                                >
                                    View Lead →
                                </a>
                            @endif
                        </div>
                        @if(!$notification['read'])
                            <span class="ml-2 h-2 w-2 bg-blue-500 rounded-full"></span>
                        @endif
                    </div>
                </div>
            @empty
                <div class="p-4 text-center text-gray-500 text-sm">
                    No notifications
                </div>
            @endforelse
        </div>
    </div>
</div>
