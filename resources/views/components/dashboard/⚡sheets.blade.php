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
        $this->loadSheets();
    }

    public function loadSheets()
    {
        if (!auth()->user()->canCreateSheets()) {
            $this->sheets = [];
            return;
        }

        $this->sheets = LeadSheet::where('created_by', auth()->id())
            ->orderBy('updated_at', 'desc')
            ->get();
    }

    public function render()
    {
        return view("components.dashboard.\u{26A1}sheets");
    }
};
?>

<div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mt-6" wire:poll.3s="loadSheets">
    <div class="bg-gray-50 px-6 py-4 border-b border-gray-200">
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
                    <a href="{{ route('leads.index', ['sheetFilter' => $sheet->id]) }}" class="block">
                        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg border border-gray-200 hover:border-blue-300 hover:bg-blue-50 transition-all">
                            <div>
                                <p class="text-base font-semibold text-gray-900">{{ $sheet->name }}</p>
                                <p class="text-xs text-gray-500 mt-1">Updated {{ $sheet->updated_at->diffForHumans() }}</p>
                            </div>
                            <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                            </svg>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</div>
