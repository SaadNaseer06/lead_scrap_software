<?php

use App\Models\Notification;
use App\Services\NotificationService;
use Livewire\Component;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

new class extends Component
{
    public $unreadCount = 0;
    public $notifications = [];
    public $showDropdown = false;
    public $isMarkingAll = false;

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
        if ($this->isMarkingAll) {
            return;
        }
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

            $query = $this->notificationQueryForUser();

            $this->unreadCount = (clone $query)
                ->where(function ($builder) {
                    $builder->where('read', false)
                        ->orWhereNull('read');
                })
                ->count();

            $collection = $query
                ->with(['lead' => function ($relation) {
                    $relation->select('id', 'lead_sheet_id', 'lead_group_id');
                }])
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();

            // Normalize to array with explicit boolean 'read' so the view always gets true/false
            $this->notifications = $collection->map(function ($n) {
                $arr = $n->toArray();
                $arr['read'] = $n->isRead();
                return $arr;
            })->all();
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

            $notification = $this->notificationQueryForUser()
                ->with(['lead' => function ($relation) {
                    $relation->select('id', 'lead_sheet_id', 'lead_group_id');
                }])
                ->where('id', $notificationId)
                ->first();

            if (!$notification) {
                return;
            }

            $notification->markAsRead();
            NotificationService::broadcastStateForUser($notification->user_id, $notification->id, 'read');
            $this->loadNotifications();
            $this->dispatch('$refresh');
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

            $this->isMarkingAll = true;

            $update = ['read' => true];
            if (Schema::hasColumn('notifications', 'read_at')) {
                $update['read_at'] = now();
            }

            $affected = DB::table('notifications')->where('user_id', auth()->id())
                ->update($update);

            Log::info('markAllAsRead executed', [
                'user_id' => auth()->id(),
                'affected' => $affected,
            ]);

            // Keep UI immediately consistent even before next poll tick.
            $this->unreadCount = 0;
            $this->notifications = collect($this->notifications)
                ->map(function ($notification) {
                    $notification['read'] = true;
                    if (Schema::hasColumn('notifications', 'read_at') && empty($notification['read_at'])) {
                        $notification['read_at'] = now()->toDateTimeString();
                    }
                    return $notification;
                })
                ->all();

            NotificationService::broadcastStateForUser(auth()->id(), null, 'all-read');
            $this->loadNotifications();
            $this->dispatch('show-toast', [
                'type' => 'success',
                'message' => $affected > 0 ? 'All notifications marked as read.' : 'No unread notifications left.',
            ]);
            $this->dispatch('$refresh');
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error marking all notifications as read: ' . $e->getMessage());
            $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Failed to mark all notifications as read.']);
        } finally {
            $this->isMarkingAll = false;
        }
    }

    protected function notificationQueryForUser()
    {
        return Notification::where('user_id', auth()->id());
    }

    /**
     * Leads index URL so “back” from the lead page returns to the same sheet/tab when possible.
     *
     * @param  array<string, mixed>|null  $lead
     */
    public function leadsIndexReturnUrlForLead(?array $lead): string
    {
        if (!is_array($lead) || empty($lead['id'])) {
            return route('leads.index', ['viewMode' => 'table']);
        }

        $params = ['viewMode' => 'table'];

        if (!empty($lead['lead_sheet_id'])) {
            $params['sheetFilter'] = (string) $lead['lead_sheet_id'];
        }

        if (array_key_exists('lead_group_id', $lead) && $lead['lead_group_id'] !== null && $lead['lead_group_id'] !== '') {
            $params['groupFilter'] = (string) $lead['lead_group_id'];
        }

        return route('leads.index', $params);
    }

    // No render method needed for anonymous components
};
?>

<div
    class="relative"
    wire:poll.2s="loadNotifications"
    x-data="{
        open: false,
        channel: null,
        echoInitAttempts: 0,
        echoInitTimer: null,
        echoBoundHandler: null,
        echoRefreshTimer: null,
        bindEchoHandler() {
            if (!this.echoBoundHandler) {
                this.echoBoundHandler = (payload) => {
                    if (this.echoRefreshTimer) {
                        clearTimeout(this.echoRefreshTimer);
                    }
                    this.echoRefreshTimer = setTimeout(() => {
                        $wire.call('loadNotifications');
                        this.echoRefreshTimer = null;
                    }, 80);

                    const path = window.location.pathname || '';
                    const onDashboard = path === '/dashboard' || path.endsWith('/dashboard');
                    if (
                        onDashboard
                        && document.visibilityState === 'visible'
                        && payload?.action === 'created'
                        && (payload?.push_body ?? payload?.pushBody)
                    ) {
                        const msg = payload.push_body ?? payload.pushBody;
                        if (window.Livewire?.dispatch) {
                            window.Livewire.dispatch('show-toast', { type: 'info', message: msg });
                        } else {
                            window.dispatchEvent(
                                new CustomEvent('show-toast', { detail: { type: 'info', message: msg } }),
                            );
                        }
                    }
                };
            }
            return this.echoBoundHandler;
        },
        attachEchoListener() {
            if (!window.Echo?.private) {
                return false;
            }

            const handler = this.bindEchoHandler();
            const channelName = 'notifications.{{ auth()->id() }}';

            if (this.channel) {
                try {
                    this.channel.stopListening('.notification.state-changed', handler);
                } catch (e) { /* noop */ }
            }

            this.channel = window.Echo.private(channelName);
            this.channel.listen('.notification.state-changed', handler);

            return true;
        },
        init() {
            if (this.attachEchoListener()) {
                return;
            }

            this.echoInitTimer = setInterval(() => {
                this.echoInitAttempts++;

                if (this.attachEchoListener() || this.echoInitAttempts >= 45) {
                    clearInterval(this.echoInitTimer);
                    this.echoInitTimer = null;
                }
            }, 500);
        },
        destroy() {
            if (this.echoInitTimer) {
                clearInterval(this.echoInitTimer);
                this.echoInitTimer = null;
            }
            if (this.echoRefreshTimer) {
                clearTimeout(this.echoRefreshTimer);
                this.echoRefreshTimer = null;
            }
            if (this.channel && this.echoBoundHandler) {
                try {
                    this.channel.stopListening('.notification.state-changed', this.echoBoundHandler);
                } catch (e) { /* noop */ }
            }
            this.channel = null;
        }
    }"
>
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
                <form method="POST" action="{{ route('notifications.mark-all-read') }}" @click.stop>
                    @csrf
                    <button 
                        type="submit"
                        class="text-sm text-white/90 hover:text-white font-semibold px-3 py-1 bg-white/20 rounded-lg hover:bg-white/30 transition-all"
                    >
                        Mark all read
                    </button>
                </form>
            @endif
        </div>
        
        <div class="overflow-y-auto flex-1" wire:key="notification-list-{{ $unreadCount }}">
            @forelse($notifications as $notification)
                @php $isUnread = !($notification['read'] ?? false); @endphp
                <div 
                    wire:key="notification-{{ $notification['id'] }}-{{ $isUnread ? 'unread' : 'read' }}"
                    wire:click="markAsRead({{ $notification['id'] }})"
                    class="p-4 border-b border-gray-100 hover:bg-gradient-to-r hover:from-blue-50 hover:to-indigo-50 cursor-pointer transition-all duration-200 {{ $isUnread ? 'bg-gradient-to-r from-blue-50 to-indigo-50 border-l-4 border-blue-500' : '' }}"
                >
                    <div class="flex items-start">
                        <div class="flex-1">
                            <p class="text-sm text-gray-900">{{ $notification['message'] }}</p>
                            <p class="text-xs text-gray-500 mt-1">
                                {{ \Carbon\Carbon::parse($notification['created_at'])->diffForHumans() }}
                            </p>
                            @if(!empty($notification['read_at']))
                                <p class="text-[11px] text-emerald-600 mt-1">
                                    Read {{ \Carbon\Carbon::parse($notification['read_at'])->diffForHumans() }}
                                </p>
                            @endif
                            @if(isset($notification['lead']))
                                <a
                                    href="{{ route('leads.show', ['id' => $notification['lead']['id'], 'return_to' => $this->leadsIndexReturnUrlForLead($notification['lead'])]) }}"
                                    class="text-xs text-blue-600 hover:text-blue-800 mt-1 inline-block"
                                    onclick="event.stopPropagation()"
                                >
                                    View Lead →
                                </a>
                            @endif
                        </div>
                        @if($isUnread)
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
