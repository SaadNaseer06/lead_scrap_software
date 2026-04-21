<?php

use App\Models\LeadSheet;
use Livewire\Component;
use Livewire\Attributes\On;

new class extends Component
{
    public $sheets = [];

    public function mount()
    {
        $this->loadSheets();
    }

    #[On('sheet-created')]
    public function refreshSheets()
    {
        try {
            $this->loadSheets();
            \Illuminate\Support\Facades\Log::info('Sheets refreshed after creation', [
                'user_id' => auth()->id(),
                'sheets_count' => count($this->sheets)
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error refreshing sheets: ' . $e->getMessage());
            $this->loadSheets(); // Try again anyway
        }
    }

    public function loadSheets()
    {
        try {
            if (!auth()->check() || !auth()->user()->canCreateSheets()) {
                $this->sheets = [];
                return;
            }

            $this->sheets = LeadSheet::where('created_by', auth()->id())
                ->orderBy('updated_at', 'desc')
                ->get();
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error loading sheets: ' . $e->getMessage());
            $this->sheets = [];
        }
    }

    public function render()
    {
        return view("components.dashboard.\u{26A1}sheets");
    }
};
?>

<div class="bg-white rounded-xl shadow-sm border-2 border-blue-200 overflow-hidden mt-6" wire:poll.5s="loadSheets">
    <div class="bg-gray-50 px-6 py-4 border-b-2 border-blue-200">
        <h2 class="text-lg font-semibold text-gray-900">Sheets</h2>
    </div>
    <div class="p-6">
        @if(count($sheets) === 0)
            <div class="text-center py-8">
                <p class="text-gray-500 font-medium">No sheets created yet.</p>
            </div>
        @else
            <div class="space-y-3">
                @foreach($sheets as $sheet)
                    <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg border border-gray-200 hover:border-blue-300 hover:bg-blue-50 transition-all">
                        <div class="flex-1">
                            <p class="text-base font-semibold text-gray-900">{{ $sheet->name }}</p>
                            <p class="text-xs text-gray-500 mt-1">Updated {{ $sheet->updated_at->diffForHumans() }}</p>
                        </div>
                        @if(auth()->user()->isScrapper())
                            <div class="flex items-center space-x-2 ml-4">
                                <a 
                                    href="{{ route('leads.index', ['sheetFilter' => $sheet->id, 'viewMode' => 'table']) }}" 
                                    class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold rounded-lg transition-colors shadow-sm hover:shadow-md"
                                >
                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                                    </svg>
                                    Open Sheet
                                </a>
                                <a 
                                    href="{{ route('leads.index', ['sheetFilter' => $sheet->id, 'viewMode' => 'list']) }}" 
                                    class="inline-flex items-center px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white text-sm font-semibold rounded-lg transition-colors shadow-sm hover:shadow-md"
                                >
                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"></path>
                                    </svg>
                                    Open List
                                </a>
                            </div>
                        @else
                            <a href="{{ route('leads.index', ['sheetFilter' => $sheet->id]) }}" class="ml-4">
                                <svg class="w-5 h-5 text-gray-400 hover:text-gray-600 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </a>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
