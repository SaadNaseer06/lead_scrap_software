<?php

use App\Models\Lead;
use App\Models\User;
use App\Services\NotificationService;
use Livewire\Component;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\Schema;

new class extends Component
{
    public $leadId;
    public $lead;
    public $commentMessage = '';

    public function mount($id)
    {
        try {
            $this->leadId = $id;
            $this->loadLead();
            
            if (!$this->lead) {
                if (auth()->check() && $this->leadId) {
                    $update = ['read' => true];
                    if (Schema::hasColumn('notifications', 'read_at')) {
                        $update['read_at'] = now();
                    }

                    \App\Models\Notification::where('user_id', auth()->id())
                        ->where('lead_id', $this->leadId)
                        ->where('read', false)
                        ->update($update);

                    \App\Services\NotificationService::broadcastStateForUser(auth()->id(), null, 'read');
                    $this->dispatch('notification-created');
                }
                session()->flash('message', 'Lead removed.');
                $this->redirect(route('dashboard'));
                return;
            }

            if (auth()->check()) {
                $update = ['read' => true];
                if (Schema::hasColumn('notifications', 'read_at')) {
                    $update['read_at'] = now();
                }

                \App\Models\Notification::where('user_id', auth()->id())
                    ->where('lead_id', $this->lead->id)
                    ->where('read', false)
                    ->update($update);

                \App\Services\NotificationService::broadcastStateForUser(auth()->id(), null, 'read');
                $this->dispatch('notification-created');
            }

            // If lead is not opened and user is sales or admin, mark as opened
            if (auth()->check() && !$this->lead->opened_by && (auth()->user()->isSalesTeam() || auth()->user()->isAdmin())) {
                try {
                    $this->lead->markAsOpened(auth()->user());
                    
                    // Notify the creator that lead was opened
                    if ($this->lead->created_by !== auth()->id()) {
                        $creator = User::find($this->lead->created_by);
                        if ($creator) {
                            NotificationService::createForUsers(
                                [$creator],
                                $this->lead,
                                'lead_opened',
                                "Lead '{$this->lead->name}' has been opened by " . auth()->user()->name,
                            );
                        }
                    }
                    
                    // Dispatch events to refresh leads list and notifications
                    $this->dispatch('lead-opened');
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('Error marking lead as opened: ' . $e->getMessage());
                }
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error mounting lead show: ' . $e->getMessage());
            $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Failed to load lead details.']);
        }
    }

    public function loadLead()
    {
        try {
            $this->lead = Lead::with(['creator', 'opener', 'comments.user', 'leadSheet', 'leadGroup'])->find($this->leadId);
            // When lead was removed from DB while user had it open: session message + redirect (no 404)
            if (!$this->lead) {
                session()->flash('message', 'Lead removed.');
                $this->redirect(route('dashboard'));
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Error loading lead: ' . $e->getMessage());
            $this->lead = null;
            session()->flash('message', 'Lead removed.');
            $this->redirect(route('dashboard'));
        }
    }

    #[On('lead-opened')]
    #[On('lead-updated')]
    public function refreshLead()
    {
        $this->loadLead();
    }

    public function socialLinks(): array
    {
        if (!$this->lead) {
            return [];
        }

        return $this->splitSocialLinks($this->lead->social_links ?? '');
    }

    protected function splitSocialLinks(?string $value): array
    {
        if ($value === null) {
            return [];
        }

        $value = trim($value);
        if ($value === '') {
            return [];
        }

        $parts = preg_split('/[\r\n,;|]+/', $value) ?: [];

        if (count($parts) <= 1) {
            preg_match_all('/(?:https?:\/\/|www\.)[^\s,;|]+|[a-z0-9.-]+\.[a-z]{2,}[^\s,;|]*/i', $value, $matches);
            if (!empty($matches[0])) {
                $parts = $matches[0];
            }
        }

        return array_values(array_unique(array_filter(array_map(static fn ($item) => trim($item), $parts))));
    }

    public function socialLinkHref(string $socialLink): string
    {
        if (preg_match('/^[a-z][a-z0-9+\-.]*:\/\//i', $socialLink)) {
            return $socialLink;
        }

        return 'https://' . ltrim($socialLink, '/');
    }

    public function updateStatus($status)
    {
        try {
            if (!auth()->check()) {
                $this->dispatch('show-toast', ['type' => 'error', 'message' => 'You must be logged in.']);
                return;
            }

            $this->loadLead();
            if (!$this->lead) {
                session()->flash('message', 'Lead removed.');
                return $this->redirect(route('dashboard'));
            }

            if (!auth()->user()->isSalesTeam() && !auth()->user()->isAdmin()) {
                $this->dispatch('show-toast', ['type' => 'error', 'message' => 'You do not have permission to update lead status.']);
                return;
            }

            $validStatuses = ['wrong number', 'follow up', 'hired us', 'hired someone', 'no response'];
            if (!in_array($status, $validStatuses, true)) {
                $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Invalid status.']);
                return;
            }

            $this->lead->update(['status' => $status]);
            
            // Dispatch events to refresh leads list and notifications
            $this->dispatch('lead-updated');
            
            // Set success message in session
            session()->flash('message', 'Lead status updated successfully!');
            
            // Redirect to leads index page with success message
            return $this->redirect(route('leads.index'));
            
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error updating lead status: ' . $e->getMessage());
            $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Failed to update lead status. Please try again.']);
        }
    }

    public function addComment()
    {
        try {
            if (!auth()->check()) {
                $this->dispatch('show-toast', type: 'error', message: 'You must be logged in.');
                return;
            }

            $this->loadLead();
            if (!$this->lead) {
                session()->flash('message', 'Lead removed.');
                return $this->redirect(route('dashboard'));
            }

            if (!auth()->user()->isSalesTeam()) {
                $this->dispatch('show-toast', type: 'error', message: 'You do not have permission to add comments.');
                return;
            }

            $this->validate([
                'commentMessage' => 'required|string|max:5000',
            ]);

            \App\Models\LeadComment::create([
                'lead_id' => $this->lead->id,
                'user_id' => auth()->id(),
                'message' => trim($this->commentMessage),
            ]);

            $this->reset('commentMessage');
            $this->loadLead();
            
            $this->dispatch('show-toast', ['type' => 'success', 'message' => 'Comment added successfully.']);
            request()->session()->flash('message', 'Comment added.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error adding comment: ' . $e->getMessage());
            $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Failed to add comment. Please try again.']);
        }
    }

    public function render()
    {
        if ($this->leadId) {
            $this->loadLead();
            // If lead was removed from DB, loadLead() redirects; otherwise ensure we never render 404
            if (!$this->lead) {
                session()->flash('message', 'Lead removed.');
                $this->redirect(route('dashboard'));
            }
        }
        return view('components.leads.⚡show');
    }
};?>

<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6" wire:poll.3s="loadLead">
    @if(!$lead)
        <!-- Lead removed (e.g. front_sale cleared name = lead deleted): show message and redirect – never 404 -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden" x-data="{ countdown: 2 }" x-init="setInterval(() => { if (countdown > 0) countdown--; else window.location = '{{ route('dashboard') }}'; }, 1000)">
            <div class="p-8 sm:p-12 text-center">
                <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-amber-100 flex items-center justify-center">
                    <svg class="w-8 h-8 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                    </svg>
                </div>
                <h1 class="text-xl font-bold text-gray-900 mb-2">Lead removed</h1>
                <p class="text-gray-600 mb-2 max-w-md mx-auto">This lead has been removed by the person who created it.</p>
                <p class="text-sm text-gray-500 mb-6">Redirecting you to the dashboard in <span x-text="countdown"></span> second(s)...</p>
                <a href="{{ route('dashboard') }}" class="inline-flex items-center px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg shadow-sm transition-colors">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m0 0l-7 7-7-7m7 7V21"></path>
                    </svg>
                    Go to Dashboard
                </a>
            </div>
        </div>
    @else
    <!-- Header -->
    <div class="mb-6 flex items-center justify-between">
        <div class="flex items-center space-x-4">
            <a href="{{ route('leads.index') }}" class="p-2 hover:bg-gray-100 rounded-lg transition-colors">
                <svg class="w-6 h-6 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                </svg>
            </a>
            <div>
                <h1 class="text-3xl font-bold text-gray-900">{{ $lead->name }}</h1>
                <p class="text-gray-600 mt-1">Lead Details & Information</p>
                @if($lead->leadSheet || $lead->leadGroup)
                    <p class="text-sm text-gray-500 mt-1">
                        @if($lead->leadSheet)
                            <span>Sheet: {{ $lead->leadSheet->name }}</span>
                        @endif
                        @if($lead->leadGroup)
                            <span class="{{ $lead->leadSheet ? 'ml-2' : '' }}">Table: <span class="font-medium text-gray-700">{{ $lead->leadGroup->name }}</span></span>
                        @elseif($lead->leadSheet)
                            <span class="ml-2 text-gray-400">— No table</span>
                        @endif
                    </p>
                @endif
            </div>
        </div>
    </div>

    <!-- Status Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-5">
            <p class="text-sm font-medium text-gray-600 mb-2">Status</p>
            @php
                $statusColors = [
                    'wrong number' => 'bg-red-100 text-red-700',
                    'follow up' => 'bg-amber-100 text-amber-700',
                    'hired us' => 'bg-emerald-100 text-emerald-700',
                    'hired someone' => 'bg-purple-100 text-purple-700',
                    'no response' => 'bg-gray-100 text-gray-700',
                ];
            @endphp
            <span class="inline-block px-3 py-1.5 text-sm font-semibold rounded-full {{ $statusColors[$lead->status] ?? 'bg-gray-100 text-gray-700' }}">
                {{ ucwords($lead->status) }}
            </span>
        </div>

        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-5">
            <p class="text-sm font-medium text-gray-600 mb-2">Opened By</p>
            @if($lead->opener)
                <div class="flex items-center space-x-2">
                    <div class="w-8 h-8 bg-emerald-500 rounded-full flex items-center justify-center text-white text-xs font-bold">
                        {{ strtoupper(substr($lead->opener->name, 0, 1)) }}
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-gray-900">{{ $lead->opener->name }}</p>
                        <p class="text-xs text-gray-500">{{ $lead->opened_at?->format('M d, Y H:i') }}</p>
                    </div>
                </div>
            @else
                <span class="text-sm text-gray-400 italic">Not opened yet</span>
            @endif
        </div>

        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-5">
            <p class="text-sm font-medium text-gray-600 mb-2">Created By</p>
            <div class="flex items-center space-x-2">
                <div class="w-8 h-8 bg-blue-600 rounded-full flex items-center justify-center text-white text-xs font-bold">
                    {{ strtoupper(substr($lead->creator->name, 0, 1)) }}
                </div>
                <div>
                    <p class="text-sm font-semibold text-gray-900">{{ $lead->creator->name }}</p>
                    <p class="text-xs text-gray-500">{{ $lead->created_at->format('M d, Y H:i') }}</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content Card -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
        <div class="bg-gray-50 px-6 py-4 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900 flex items-center">
                <svg class="w-5 h-5 mr-2 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                </svg>
                Contact Information
            </h2>
        </div>
        <div class="p-6 space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="bg-gray-50 p-4 rounded-lg">
                    <p class="text-xs font-semibold text-gray-600 uppercase mb-2">Email</p>
                    <p class="text-base font-medium text-gray-900">{{ $lead->email ?? 'N/A' }}</p>
                </div>
                <div class="bg-gray-50 p-4 rounded-lg">
                    <p class="text-xs font-semibold text-gray-600 uppercase mb-2">Phone</p>
                    <p class="text-base font-medium text-gray-900">{{ $lead->phone ?? 'N/A' }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="bg-gray-50 p-4 rounded-lg">
                    <p class="text-xs font-semibold text-gray-600 uppercase mb-2">Lead Date</p>
                    <p class="text-base font-medium text-gray-900">{{ $lead->lead_date?->format('M d, Y') ?? 'N/A' }}</p>
                </div>
                <div class="bg-gray-50 p-4 rounded-lg">
                    <p class="text-xs font-semibold text-gray-600 uppercase mb-2">Service</p>
                    <p class="text-base font-medium text-gray-900">{{ $lead->services ?? 'N/A' }}</p>
                </div>
                <div class="bg-gray-50 p-4 rounded-lg">
                    <p class="text-xs font-semibold text-gray-600 uppercase mb-2">Budget</p>
                    <p class="text-base font-medium text-gray-900">{{ $lead->budget ?? 'N/A' }}</p>
                </div>
                <div class="bg-gray-50 p-4 rounded-lg">
                    <p class="text-xs font-semibold text-gray-600 uppercase mb-2">Credits</p>
                    <p class="text-base font-medium text-gray-900">{{ $lead->credits ?? 'N/A' }}</p>
                </div>
                <div class="bg-gray-50 p-4 rounded-lg">
                    <p class="text-xs font-semibold text-gray-600 uppercase mb-2">Location</p>
                    <p class="text-base font-medium text-gray-900">{{ $lead->location ?? 'N/A' }}</p>
                </div>
                <div class="bg-gray-50 p-4 rounded-lg">
                    <p class="text-xs font-semibold text-gray-600 uppercase mb-2">Position</p>
                    <p class="text-base font-medium text-gray-900">{{ $lead->position ?? 'N/A' }}</p>
                </div>
                <div class="bg-gray-50 p-4 rounded-lg">
                    <p class="text-xs font-semibold text-gray-600 uppercase mb-2">Platform</p>
                    <p class="text-base font-medium text-gray-900">{{ $lead->platform ?? 'N/A' }}</p>
                </div>
                <div class="bg-gray-50 p-4 rounded-lg">
                    <p class="text-xs font-semibold text-gray-600 uppercase mb-2">Social Links</p>
                    @if($this->socialLinks())
                        <div class="space-y-1">
                            @foreach($this->socialLinks() as $socialLink)
                                <a href="{{ $this->socialLinkHref($socialLink) }}" target="_blank" rel="noopener noreferrer" class="block text-blue-600 hover:underline break-all">{{ $socialLink }}</a>
                            @endforeach
                        </div>
                    @else
                        <p class="text-base font-medium text-gray-900">N/A</p>
                    @endif
                </div>
                <div class="bg-gray-50 p-4 rounded-lg">
                    <p class="text-xs font-semibold text-gray-600 uppercase mb-2">Web Link</p>
                    @if($lead->web_link)
                        <a href="{{ $lead->web_link }}" target="_blank" class="text-blue-600 hover:underline">Open link</a>
                    @else
                        <p class="text-base font-medium text-gray-900">N/A</p>
                    @endif
                </div>
                <div class="bg-gray-50 p-4 rounded-lg md:col-span-2">
                    <p class="text-xs font-semibold text-gray-600 uppercase mb-2">Details</p>
                    <p class="text-base font-medium text-gray-900">{{ $lead->detail ?? 'N/A' }}</p>
                </div>
            </div>

            @if(!empty($lead->notes))
                <div class="bg-blue-50 p-5 rounded-lg border border-blue-200">
                    <p class="text-xs font-semibold text-blue-700 uppercase mb-2">Additional Comments</p>
                    <p class="text-sm text-gray-700 whitespace-pre-wrap leading-relaxed">{{ $lead->notes }}</p>
                </div>
            @endif

            <div class="bg-white border border-gray-200 rounded-lg p-5">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-base font-semibold text-gray-900">Comments</h3>
                    <span class="text-xs text-gray-500">{{ $lead->comments->count() }} total</span>
                </div>

                @if($lead->comments && $lead->comments->count() > 0)
                    <div class="grid grid-cols-2 gap-4 text-xs font-semibold text-gray-500 uppercase mb-2 px-1">
                        <span>Agent Name</span>
                        <span>Comments</span>
                    </div>
                    <div class="space-y-4 mb-4">
                        @foreach($lead->comments->sortByDesc('created_at') as $comment)
                            <div class="border border-gray-200 rounded-lg p-4 bg-gray-50 grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <p class="text-sm font-semibold text-gray-900">{{ $comment->user->name ?? 'Unknown' }}</p>
                                    <p class="text-xs text-gray-500">{{ $comment->created_at->format('M d, Y H:i') }}</p>
                                </div>
                                <p class="text-sm text-gray-700 whitespace-pre-wrap">{{ $comment->message ?? '' }}</p>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="text-sm text-gray-500 mb-4">No comments yet.</p>
                @endif

                @if(auth()->user()->isSalesTeam())
                    <form wire:submit="addComment" class="space-y-3">
                        <textarea
                            wire:model="commentMessage"
                            rows="3"
                            class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 resize-none @error('commentMessage') border-red-500 @enderror"
                            placeholder="Add a comment..."
                        ></textarea>
                        @error('commentMessage') <span class="text-red-500 text-sm block">{{ $message }}</span> @enderror
                        <div class="flex justify-end">
                            <button 
                                type="submit" 
                                wire:loading.attr="disabled"
                                wire:target="addComment"
                                class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold shadow-sm hover:shadow-md transition-all disabled:opacity-50 disabled:cursor-not-allowed flex items-center space-x-2"
                            >
                                <span wire:loading.remove wire:target="addComment">Add Comment</span>
                                <span wire:loading wire:target="addComment" class="flex items-center space-x-2">
                                    <svg class="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    <span>Adding...</span>
                                </span>
                            </button>
                        </div>
                    </form>
                @endif
            </div>

            @if(auth()->user()->isSalesTeam() || auth()->user()->isAdmin())
                <div class="bg-amber-50 p-6 rounded-lg border border-amber-200">
                    <h3 class="text-base font-semibold text-gray-900 mb-4">Update Status</h3>
                    <div class="flex flex-wrap gap-3">
                        <button 
                            wire:click="updateStatus('follow up')"
                            wire:loading.attr="disabled"
                            wire:target="updateStatus"
                            class="px-4 py-2 bg-amber-500 hover:bg-amber-600 text-white rounded-lg font-semibold shadow-sm hover:shadow-md transition-all disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            <span wire:loading.remove wire:target="updateStatus">Mark as Follow Up</span>
                            <span wire:loading wire:target="updateStatus">Updating...</span>
                        </button>
                        <button 
                            wire:click="updateStatus('hired us')"
                            wire:loading.attr="disabled"
                            wire:target="updateStatus"
                            class="px-4 py-2 bg-emerald-500 hover:bg-emerald-600 text-white rounded-lg font-semibold shadow-sm hover:shadow-md transition-all disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            <span wire:loading.remove wire:target="updateStatus">Mark as Hired Us</span>
                            <span wire:loading wire:target="updateStatus">Updating...</span>
                        </button>
                        <button 
                            wire:click="updateStatus('hired someone')"
                            wire:loading.attr="disabled"
                            wire:target="updateStatus"
                            class="px-4 py-2 bg-purple-500 hover:bg-purple-600 text-white rounded-lg font-semibold shadow-sm hover:shadow-md transition-all disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            <span wire:loading.remove wire:target="updateStatus">Mark as Hired Someone</span>
                            <span wire:loading wire:target="updateStatus">Updating...</span>
                        </button>
                        <button 
                            wire:click="updateStatus('wrong number')"
                            wire:loading.attr="disabled"
                            wire:target="updateStatus"
                            class="px-4 py-2 bg-red-500 hover:bg-red-600 text-white rounded-lg font-semibold shadow-sm hover:shadow-md transition-all disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            <span wire:loading.remove wire:target="updateStatus">Mark as Wrong Number</span>
                            <span wire:loading wire:target="updateStatus">Updating...</span>
                        </button>
                        <button 
                            wire:click="updateStatus('no response')"
                            wire:loading.attr="disabled"
                            wire:target="updateStatus"
                            class="px-4 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded-lg font-semibold shadow-sm hover:shadow-md transition-all disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            <span wire:loading.remove wire:target="updateStatus">Mark as No Response</span>
                            <span wire:loading wire:target="updateStatus">Updating...</span>
                        </button>
                    </div>
                </div>
            @endif
        </div>
    </div>
    @endif
</div>
