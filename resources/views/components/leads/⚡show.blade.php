<?php

use App\Models\Lead;
use Livewire\Component;
use Livewire\Attributes\On;

new class extends Component
{
    public $leadId;
    public $lead;
    public $commentMessage = '';

    public function mount($id)
    {
        $this->leadId = $id;
        $this->loadLead();
        
        // If lead is not opened and user is sales or admin, mark as opened
        if (!$this->lead->opened_by && (auth()->user()->isSalesTeam() || auth()->user()->isAdmin())) {
            $this->lead->markAsOpened(auth()->user());
            
            // Notify the creator that lead was opened
            if ($this->lead->created_by !== auth()->id()) {
                \App\Models\Notification::create([
                    'user_id' => $this->lead->created_by,
                    'lead_id' => $this->lead->id,
                    'type' => 'lead_opened',
                    'message' => "Lead '{$this->lead->name}' has been opened by " . auth()->user()->name,
                ]);
            }
            
            // Dispatch events to refresh leads list and notifications
            $this->dispatch('lead-opened');
        }
    }

    public function loadLead()
    {
        $this->lead = Lead::with(['creator', 'opener', 'comments.user'])->findOrFail($this->leadId);
    }

    #[On('lead-opened')]
    #[On('lead-updated')]
    public function refreshLead()
    {
        $this->loadLead();
    }

    public function updateStatus($status)
    {
        $this->lead->update(['status' => $status]);
        
        // Reload lead to get fresh data
        $this->loadLead();
        
        // Dispatch events to refresh leads list and notifications
        $this->dispatch('lead-updated');
        
        request()->session()->flash('message', 'Lead status updated successfully!');
    }

    public function addComment()
    {
        if (!auth()->user()->isSalesTeam()) {
            abort(403);
        }

        $this->validate([
            'commentMessage' => 'required|string',
        ]);

        \App\Models\LeadComment::create([
            'lead_id' => $this->lead->id,
            'user_id' => auth()->id(),
            'message' => $this->commentMessage,
        ]);

        $this->reset('commentMessage');
        $this->loadLead();
        request()->session()->flash('message', 'Comment added.');
    }

    public function render()
    {
        // Reload lead to ensure fresh data
        $this->loadLead();
        return view('components.leads.⚡show');
    }
};?>

<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6" wire:poll.3s="loadLead">
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
                    <p class="text-xs font-semibold text-gray-600 uppercase mb-2">LinkedIn</p>
                    @if($lead->linkedin)
                        <a href="{{ $lead->linkedin }}" target="_blank" class="text-blue-600 hover:underline">Open link</a>
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
                    <p class="text-xs font-semibold text-gray-600 uppercase mb-2">Business Description</p>
                    <p class="text-base font-medium text-gray-900">{{ $lead->detail ?? 'N/A' }}</p>
                </div>
            </div>

            @if($lead->notes)
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

                @if($lead->comments->count() > 0)
                    <div class="grid grid-cols-2 gap-4 text-xs font-semibold text-gray-500 uppercase mb-2 px-1">
                        <span>Agent Name</span>
                        <span>Comments</span>
                    </div>
                    <div class="space-y-4 mb-4">
                        @foreach($lead->comments->sortByDesc('created_at') as $comment)
                            <div class="border border-gray-200 rounded-lg p-4 bg-gray-50 grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <p class="text-sm font-semibold text-gray-900">{{ $comment->user->name }}</p>
                                    <p class="text-xs text-gray-500">{{ $comment->created_at->format('M d, Y H:i') }}</p>
                                </div>
                                <p class="text-sm text-gray-700 whitespace-pre-wrap">{{ $comment->message }}</p>
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
                            <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold shadow-sm hover:shadow-md transition-all">
                                Add Comment
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
                            class="px-4 py-2 bg-amber-500 hover:bg-amber-600 text-white rounded-lg font-semibold shadow-sm hover:shadow-md transition-all"
                        >
                            Mark as Follow Up
                        </button>
                        <button 
                            wire:click="updateStatus('hired us')" 
                            class="px-4 py-2 bg-emerald-500 hover:bg-emerald-600 text-white rounded-lg font-semibold shadow-sm hover:shadow-md transition-all"
                        >
                            Mark as Hired Us
                        </button>
                        <button 
                            wire:click="updateStatus('hired someone')" 
                            class="px-4 py-2 bg-purple-500 hover:bg-purple-600 text-white rounded-lg font-semibold shadow-sm hover:shadow-md transition-all"
                        >
                            Mark as Hired Someone
                        </button>
                        <button 
                            wire:click="updateStatus('wrong number')" 
                            class="px-4 py-2 bg-red-500 hover:bg-red-600 text-white rounded-lg font-semibold shadow-sm hover:shadow-md transition-all"
                        >
                            Mark as Wrong Number
                        </button>
                        <button 
                            wire:click="updateStatus('no response')" 
                            class="px-4 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded-lg font-semibold shadow-sm hover:shadow-md transition-all"
                        >
                            Mark as No Response
                        </button>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>



