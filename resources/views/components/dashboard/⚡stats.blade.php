<?php

use App\Models\Lead;
use Livewire\Component;
use Livewire\Attributes\On;

new class extends Component
{
    public $total = 0;
    public $followUp = 0;
    public $noResponse = 0;

    public function mount()
    {
        $this->loadStats();
    }

    #[On('lead-created')]
    #[On('lead-updated')]
    #[On('lead-opened')]
    public function refreshStats()
    {
        $this->loadStats();
    }

    public function loadStats()
    {
        try {
            if (!auth()->check()) {
                $this->total = 0;
                $this->followUp = 0;
                $this->noResponse = 0;
                return;
            }

            $baseQuery = Lead::query();
            
            if (auth()->user()->isScrapper()) {
                $baseQuery->where('created_by', auth()->id());
            }

            // Optimize: Use single query with conditional aggregation
            $stats = $baseQuery->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as follow_up,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as no_response
            ', ['follow up', 'no response'])->first();

            $this->total = (int) ($stats->total ?? 0);
            $this->followUp = (int) ($stats->follow_up ?? 0);
            $this->noResponse = (int) ($stats->no_response ?? 0);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error loading stats: ' . $e->getMessage());
            $this->total = 0;
            $this->followUp = 0;
            $this->noResponse = 0;
        }
    }

    // No render method needed for anonymous components
};
?>

<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6" wire:poll.5s="loadStats">
    <!-- Total Leads Card -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-md transition-shadow">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-600 mb-1">Total Leads</p>
                <p class="text-3xl font-bold text-gray-900">{{ $total }}</p>
            </div>
            <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                </svg>
            </div>
        </div>
    </div>

    <!-- Follow Up Leads Card -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-md transition-shadow">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-600 mb-1">Follow Up</p>
                <p class="text-3xl font-bold text-gray-900">{{ $followUp }}</p>
            </div>
            <div class="w-12 h-12 bg-amber-100 rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
        </div>
    </div>

    <!-- No Response Leads Card -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-md transition-shadow">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-600 mb-1">No Response</p>
                <p class="text-3xl font-bold text-gray-900">{{ $noResponse }}</p>
            </div>
            <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
        </div>
    </div>
</div>
