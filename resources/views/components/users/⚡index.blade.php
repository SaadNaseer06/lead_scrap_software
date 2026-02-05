<?php

use App\Models\User;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\DB;

new class extends Component
{
    use WithPagination;

    public $search = '';
    public $showCreateModal = false;
    public $showEditModal = false;
    public $editingUser = null;

    protected $queryString = [
        'search' => ['except' => ''],
    ];

    public function updatingSearch()
    {
        $this->resetPage();
    }

    #[On('user-created')]
    #[On('user-updated')]
    #[On('user-deleted')]
    public function refreshUsers()
    {
        $this->resetPage();
        $this->showCreateModal = false;
        $this->showEditModal = false;
        $this->editingUser = null;
    }

    #[On('close-create-modal')]
    public function handleCloseCreateModal()
    {
        $this->showCreateModal = false;
    }

    #[On('close-edit-modal')]
    public function handleCloseEditModal()
    {
        $this->showEditModal = false;
        $this->editingUser = null;
    }

    public function openCreateModal()
    {
        $this->showCreateModal = true;
    }

    public function closeCreateModal()
    {
        $this->showCreateModal = false;
    }

    public function openEditModal($userId)
    {
        if (!$userId) {
            return;
        }
        
        $this->editingUser = $userId;
        $this->showEditModal = true;
    }

    public function closeEditModal()
    {
        $this->showEditModal = false;
        $this->editingUser = null;
    }

    public function deleteUser($userId)
    {
        try {
            if (!$userId) {
                return;
            }

            $user = User::findOrFail($userId);
            
            // Prevent deleting yourself
            if ($user->id === auth()->id()) {
                $this->dispatch('show-toast', [
                    'type' => 'error',
                    'message' => 'You cannot delete your own account.'
                ]);
                return;
            }

            $user->delete();
            
            $this->dispatch('user-deleted');
            $this->dispatch('show-toast', [
                'type' => 'success',
                'message' => 'User deleted successfully.'
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error deleting user: ' . $e->getMessage());
            $this->dispatch('show-toast', [
                'type' => 'error',
                'message' => 'Error deleting user: ' . $e->getMessage()
            ]);
        }
    }

    public function render()
    {
        $query = User::query();

        if ($this->search) {
            $query->where(function($q) {
                $q->where('name', 'like', '%' . $this->search . '%')
                  ->orWhere('email', 'like', '%' . $this->search . '%');
            });
        }

        $users = $query->orderBy('created_at', 'desc')->paginate(15);

        // Users with session activity in the last 2 minutes are considered online (reduces “online” from background tabs)
        $onlineUserIds = DB::table('sessions')
            ->whereNotNull('user_id')
            ->where('last_activity', '>', now()->subMinutes(2)->timestamp)
            ->pluck('user_id')
            ->unique()
            ->values()
            ->toArray();

        return view('components.users.⚡index', compact('users', 'onlineUserIds'));
    }
};
?>

<div class="max-w-9xl mx-auto px-4 sm:px-6 lg:px-8">
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <!-- Header -->
        <div class="bg-gradient-to-r from-blue-50 to-indigo-50 px-6 py-5 border-b border-gray-200">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Users Management</h1>
                    <p class="text-sm text-gray-600 mt-1">Manage system users and their roles</p>
                </div>
                <button 
                    wire:click="openCreateModal"
                    class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg shadow-sm hover:shadow-md transition-all"
                >
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                    </svg>
                    Add New User
                </button>
            </div>
        </div>

        <!-- Search Bar -->
        <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
            <div class="flex items-center border border-gray-300 rounded-lg bg-white focus-within:ring-2 focus-within:ring-blue-500 focus-within:border-blue-500">
                <span class="pl-3 flex items-center justify-center shrink-0 text-gray-400" aria-hidden="true">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                </span>
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Search users by name or email..."
                    class="flex-1 min-w-0 py-2 pr-4 pl-1 border-0 bg-transparent focus:ring-0 focus:outline-none"
                >
            </div>
        </div>

        <!-- Users Table -->
        <div class="overflow-x-auto" wire:poll.5s="$refresh">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                            User
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                            Email
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                            Role
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                            Status
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                            Created At
                        </th>
                        <th scope="col" class="px-6 py-3 text-right text-xs font-semibold text-gray-700 uppercase tracking-wider">
                            Actions
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($users as $user)
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="w-10 h-10 bg-blue-600 rounded-full flex items-center justify-center text-white font-semibold text-sm mr-3">
                                        {{ strtoupper(substr($user->name, 0, 1)) }}
                                    </div>
                                    <div>
                                        <div class="text-sm font-semibold text-gray-900">{{ $user->name }}</div>
                                        @if($user->id === auth()->id())
                                            <span class="text-xs text-gray-500">(You)</span>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-900">{{ $user->email }}</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-3 py-1 text-xs font-semibold rounded-full
                                    @if($user->role === 'admin') bg-purple-100 text-purple-700
                                    @elseif($user->role === 'upsale') bg-blue-100 text-blue-700
                                    @elseif($user->role === 'front_sale') bg-amber-100 text-amber-700
                                    @else bg-emerald-100 text-emerald-700
                                    @endif">
                                    {{ ucwords(str_replace('_', ' ', $user->role)) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap align-middle">
                                <div class="flex items-center">
                                    @if(in_array($user->id, $onlineUserIds ?? []))
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-emerald-100 text-emerald-700 min-w-[4.5rem] justify-center">
                                            <span class="w-2 h-2 shrink-0 rounded-full bg-emerald-500 animate-pulse"></span>
                                            <span>Online</span>
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-600 min-w-[4.5rem] justify-center">
                                            <span class="w-2 h-2 shrink-0 rounded-full bg-gray-400"></span>
                                            <span>Offline</span>
                                        </span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                {{ $user->created_at->format('M d, Y') }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex items-center justify-end space-x-2">
                                    <button 
                                        wire:click="openEditModal({{ $user->id }})"
                                        type="button"
                                        class="text-blue-600 hover:text-blue-900 transition-colors"
                                    >
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                        </svg>
                                    </button>
                                    @if($user->id !== auth()->id())
                                        <button 
                                            onclick="if(confirm('Are you sure you want to delete this user?')) { @this.call('deleteUser', {{ $user->id }}) }"
                                            class="text-red-600 hover:text-red-900 transition-colors"
                                        >
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                            </svg>
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center">
                                <div class="text-gray-500">
                                    <svg class="mx-auto h-12 w-12 text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                                    </svg>
                                    <p class="text-lg font-medium">No users found</p>
                                    <p class="text-sm mt-1">Get started by creating a new user.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        @if($users->hasPages())
            <div class="bg-gray-50 px-6 py-4 border-t border-gray-200">
                {{ $users->links() }}
            </div>
        @endif
    </div>

    <!-- Create Modal -->
    @if($showCreateModal)
        <livewire:users.create key="create-user-modal" />
    @endif

    <!-- Edit Modal -->
    @if($showEditModal && $editingUser)
        <livewire:users.edit :userId="$editingUser" key="edit-user-{{ $editingUser }}" />
    @endif
</div>
