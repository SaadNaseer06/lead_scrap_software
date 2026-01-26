<?php

use App\Models\Lead;
use Livewire\Component;
use Livewire\Attributes\On;

new class extends Component
{
    public $recentLeads = [];

    public function mount()
    {
        $this->loadLeads();
    }

    #[On('lead-created')]
    #[On('lead-updated')]
    #[On('lead-opened')]
    public function refreshLeads()
    {
        $this->loadLeads();
    }

    public function loadLeads()
    {
        $this->recentLeads = Lead::with(['creator', 'opener'])
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();
    }

    public function render()
    {
        return view("components.dashboard.\u{26A1}recent-leads");
    }
};
?>

<div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mt-6" wire:poll.3s="loadLeads">
    <div class="bg-gray-50 px-6 py-4 border-b border-gray-200">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center">
            <svg class="w-5 h-5 mr-2 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            Recent Leads
        </h2>
    </div>
    <div class="p-6">
        @if(count($recentLeads) > 0)
            <div class="space-y-3">
                @foreach($recentLeads as $lead)
                    <a href="{{ route('leads.show', $lead->id) }}" class="block group">
                        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg border border-gray-200 hover:border-blue-300 hover:bg-blue-50 transition-all">
                            <div class="flex items-center space-x-4 flex-1">
                                <div class="w-10 h-10 bg-blue-600 rounded-lg flex items-center justify-center text-white font-bold text-sm">
                                    {{ strtoupper(substr($lead->name, 0, 1)) }}
                                </div>
                                <div class="flex-1">
                                    <h3 class="text-base font-semibold text-gray-900 group-hover:text-blue-600 transition-colors">{{ $lead->name }}</h3>
                                    <div class="flex items-center space-x-3 mt-1">
                                        <p class="text-xs text-gray-500">
                                            <span class="font-medium">{{ $lead->creator->name }}</span> - {{ $lead->created_at->diffForHumans() }}
                                        </p>
                                        @if($lead->opener)
                                            <span class="text-xs text-blue-600 font-medium flex items-center">
                                                <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                                </svg>
                                                Opened by {{ $lead->opener->name }}
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center space-x-4">
                                <span class="px-3 py-1 text-xs font-semibold rounded-full
                                    @if($lead->status === 'no response') bg-gray-100 text-gray-700
                                    @elseif($lead->status === 'follow up') bg-amber-100 text-amber-700
                                    @elseif($lead->status === 'hired us') bg-emerald-100 text-emerald-700
                                    @elseif($lead->status === 'hired someone') bg-purple-100 text-purple-700
                                    @else bg-red-100 text-red-700
                                    @endif">
                                    {{ ucwords($lead->status) }}
                                </span>
                                <svg class="w-5 h-5 text-gray-400 group-hover:text-blue-600 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        @else
            <div class="text-center py-12">
                <svg class="w-16 h-16 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                </svg>
                <p class="text-gray-500 font-medium">No leads yet</p>
                <p class="text-gray-400 text-sm mt-1">Start by creating your first lead</p>
            </div>
        @endif
    </div>
</div>
