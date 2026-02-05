<?php

use App\Models\LeadSheet;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\On;

new class extends Component
{
    use WithPagination;

    public $search = '';

    protected $queryString = [
        'search' => ['except' => ''],
    ];

    public function updatingSearch()
    {
        $this->resetPage();
    }

    #[On('sheet-created')]
    public function refreshSheets()
    {
        $this->resetPage();
    }

    public function viewSheetLeads($sheetId)
    {
        // Redirect to leads page with sheet filter
        return redirect()->route('leads.index', ['sheetFilter' => $sheetId]);
    }

    public function render()
    {
        $query = LeadSheet::with(['creator', 'leads', 'teams']);

        // Apply search filter
        if ($this->search) {
            $query->where('name', 'like', '%' . $this->search . '%');
        }

        // Role-based filtering
        if (auth()->check()) {
            if (auth()->user()->isAdmin()) {
                // Admin sees all sheets
            } elseif (auth()->user()->isScrapper()) {
                // Scrappers only see their own sheets
                $query->where('created_by', auth()->id());
            } else {
                // Sales (front_sale, upsale): see sheets assigned to a team they belong to
                $userTeamIds = auth()->user()->teams()->pluck('teams.id')->toArray();
                if (!empty($userTeamIds)) {
                    $query->whereHas('teams', fn ($q) => $q->whereIn('teams.id', $userTeamIds));
                } else {
                    // User has no teams: show nothing (or no sheets)
                    $query->whereRaw('1 = 0');
                }
            }
        }

        $sheets = $query->orderBy('updated_at', 'desc')->paginate(15);

        return view('components.sheets.⚡index', [
            'sheets' => $sheets,
        ]);
    }
};
?>

<div class="max-w-9xl mx-auto px-4 sm:px-6 lg:px-8">
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <!-- Header -->
        <div class="bg-gradient-to-r from-blue-50 to-indigo-50 px-6 py-5 border-b border-gray-200">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Sheets Management</h1>
                    <p class="text-sm text-gray-600 mt-1">View and manage lead sheets</p>
                </div>
                @if(auth()->user()->canCreateSheets())
                    <button 
                        onclick="document.getElementById('create-sheet-form').scrollIntoView({ behavior: 'smooth', block: 'center' })"
                        class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg shadow-sm hover:shadow-md transition-all"
                    >
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                        </svg>
                        Create Sheet
                    </button>
                @endif
            </div>
        </div>

        <!-- Create Sheet Form -->
        @if(auth()->user()->canCreateSheets())
            <div id="create-sheet-form" class="px-6 py-4 bg-gray-50 border-b border-gray-200">
                <livewire:sheets.create />
            </div>
        @endif

        <!-- Search Bar -->
        <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
            <div class="relative">
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Search sheets by name..."
                    class="w-full px-4 py-2 pl-10 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                >
                <svg class="absolute left-3 top-2.5 h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                </svg>
            </div>
        </div>

        <!-- Sheets List -->
            <div class="overflow-x-auto" wire:poll.5s="$refresh">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Sheet Name</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Created By</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Leads Count</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Last Updated</th>
                            <th class="px-6 py-3 text-right text-xs font-semibold text-gray-700 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @forelse($sheets as $sheet)
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-semibold text-gray-900">{{ $sheet->name }}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900">{{ $sheet->creator->name ?? 'Unknown' }}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-3 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-700">
                                        {{ $sheet->leads->count() }} leads
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    {{ $sheet->updated_at->format('M d, Y') }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    <button 
                                        wire:click="viewSheetLeads({{ $sheet->id }})"
                                        class="text-blue-600 hover:text-blue-900 transition-colors font-semibold"
                                    >
                                        View Leads
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-12 text-center">
                                    <div class="text-gray-500">
                                        <svg class="mx-auto h-12 w-12 text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                        </svg>
                                        <p class="text-lg font-medium">No sheets found</p>
                                        <p class="text-sm mt-1">Get started by creating a new sheet.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

        <!-- Pagination -->
        @if($sheets->hasPages())
            <div class="bg-gray-50 px-6 py-4 border-t border-gray-200">
                {{ $sheets->links() }}
            </div>
        @endif
    </div>
</div>
