<?php

use App\Models\Lead;
use App\Models\LeadSheet;
use App\Models\User;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\Log;

new class extends Component
{
    use WithPagination;

    public $search = '';
    public $statusFilter = '';
    public $sheetFilter = '';
    public $viewMode = 'table'; 
    public $leadsData = [];
    public $pendingCreates = [];
    protected $queryString = [
        'sheetFilter' => ['except' => ''],
        'viewMode' => ['except' => 'table'],
    ];

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function updatingStatusFilter()
    {
        $this->resetPage();
    }

    public function updatingSheetFilter()
    {
        $this->resetPage();
    }

    public function updatedSheetFilter()
    {
        $this->loadSheetLeads();
    }

    #[On('lead-created')]
    #[On('lead-updated')]
    #[On('lead-opened')]
    public function refreshLeads()
    {
        // Reset to first page when new leads are added
        $this->resetPage();
    }

    #[On('sheet-created')]
    public function refreshSheets()
    {
        $this->resetPage();
    }

    public function refreshLeadsData()
    {
        // This method is called by polling to refresh the leads list
        // The render method will automatically fetch fresh data
    }

    public function updatedLeadsData($value, $key)
    {
        try {
            // Validate user and role
            if (!auth()->check() || !auth()->user()->isScrapper()) {
                return;
            }

            // Validate sheet filter exists
            if (!$this->sheetFilter) {
                return;
            }

            // Parse key to get index and field
            $parts = explode('.', $key, 2);
            if (count($parts) !== 2) {
                return;
            }

            [$indexStr, $field] = $parts;
            $index = (int) $indexStr;

            // Validate index is numeric and within bounds
            if (!is_numeric($indexStr) || $index < 0 || !isset($this->leadsData[$index])) {
                return;
            }

            // Validate field is allowed
            $allowed = ['name', 'email', 'services', 'phone', 'location', 'position', 'platform', 'linkedin', 'detail', 'web_link'];
            if (!in_array($field, $allowed, true)) {
                return;
            }

            // Get row and validate it exists
            $row = $this->leadsData[$index] ?? null;
            if (!$row || !is_array($row)) {
                return;
            }

            // Normalize value - convert empty strings to null for optional fields
            $value = is_string($value) ? trim($value) : $value;
            if ($value === '' && $field !== 'name') {
                $value = null;
            }

            // Handle name field updates for existing leads
            // Don't auto-delete - just update the name field
            // This prevents accidental deletion and index confusion
            if ($field === 'name' && !empty($row['id'])) {
                try {
                    // Update the name field (even if empty) - don't delete the lead
                    Lead::where('id', $row['id'])
                        ->where('created_by', auth()->id())
                        ->update(['name' => $value ?: '']);
                    
                    // Update local data to reflect the change
                    $this->leadsData[$index]['name'] = $value;
                    $this->dispatch('lead-updated');
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('Error updating lead name: ' . $e->getMessage());
                    $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Failed to update name.']);
                }
                return;
            }

            // Validate email format if provided
            if ($field === 'email' && !empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Invalid email format.']);
                return;
            }

            // Handle new lead creation
            if (empty($row['id'])) {
                // Don't create if name is empty
                $nameValue = trim($row['name'] ?? '');
                if (empty($nameValue)) {
                    return;
                }

                // Prevent duplicate creation attempts
                $pendingKey = "{$index}_{$nameValue}";
                if (!empty($this->pendingCreates[$pendingKey])) {
                    return;
                }

                $this->pendingCreates[$pendingKey] = true;

                try {
                    // Verify sheet still exists and belongs to user
                    $sheet = LeadSheet::where('id', $this->sheetFilter)
                        ->where('created_by', auth()->id())
                        ->first();

                    if (!$sheet) {
                        $this->pendingCreates[$pendingKey] = false;
                        $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Sheet not found or access denied.']);
                        return;
                    }

                    // Check for existing lead to prevent duplicates
                    $existingLead = Lead::where('created_by', auth()->id())
                        ->where('lead_sheet_id', $this->sheetFilter)
                        ->where('name', $nameValue)
                        ->whereDate('lead_date', now()->toDateString())
                        ->first();

                    if ($existingLead) {
                        $this->leadsData[$index]['id'] = $existingLead->id;
                        unset($this->pendingCreates[$pendingKey]);
                        return;
                    }

                    // Create new lead
                    $lead = Lead::create([
                        'created_by' => auth()->id(),
                        'lead_sheet_id' => $this->sheetFilter,
                        'lead_date' => now()->toDateString(),
                        'status' => 'no response',
                        'name' => $nameValue,
                        'email' => !empty($row['email']) ? trim($row['email']) : null,
                        'services' => !empty($row['services']) ? trim($row['services']) : null,
                        'phone' => !empty($row['phone']) ? trim($row['phone']) : null,
                        'location' => !empty($row['location']) ? trim($row['location']) : null,
                        'position' => !empty($row['position']) ? trim($row['position']) : null,
                        'platform' => !empty($row['platform']) ? trim($row['platform']) : null,
                        'linkedin' => !empty($row['linkedin']) ? trim($row['linkedin']) : null,
                        'detail' => !empty($row['detail']) ? trim($row['detail']) : null,
                        'web_link' => !empty($row['web_link']) ? trim($row['web_link']) : null,
                    ]);

                    // Notify sales users efficiently
                    $salesUsers = User::whereIn('role', ['upsale', 'front_sale'])->get();
                    
                    if ($salesUsers->isNotEmpty()) {
                        $notifications = $salesUsers->map(function ($user) use ($lead) {
                            return [
                                'user_id' => $user->id,
                                'lead_id' => $lead->id,
                                'type' => 'new_lead',
                                'message' => "New lead '{$lead->name}' has been added by " . auth()->user()->name,
                                'read' => false,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];
                        })->toArray();

                        \App\Models\Notification::insert($notifications);
                    }

                    // Update local data with the new lead ID
                    $this->leadsData[$index]['id'] = $lead->id;
                    unset($this->pendingCreates[$pendingKey]);
                    
                    // Ensure empty row exists after creating new lead
                    $this->ensureEmptyRow();
                    $this->dispatch('lead-created');
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('Error creating lead in table: ' . $e->getMessage(), [
                        'user_id' => auth()->id(),
                        'sheet_id' => $this->sheetFilter,
                        'row_data' => $row,
                    ]);
                    $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Failed to save lead. Please try again.']);
                    unset($this->pendingCreates[$pendingKey]);
                }
                return;
            }

            // Update existing lead
            try {
                // Verify lead still exists and belongs to user
                $lead = Lead::where('id', $row['id'])
                    ->where('created_by', auth()->id())
                    ->first();

                if (!$lead) {
                    // Lead was deleted, reload data
                    $this->loadSheetLeads();
                    $this->dispatch('show-toast', ['type' => 'warning', 'message' => 'Lead was deleted. Data refreshed.']);
                    return;
                }

                // Update the field
                $lead->update([$field => $value]);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Error updating lead: ' . $e->getMessage(), [
                    'lead_id' => $row['id'] ?? null,
                    'field' => $field,
                    'value' => $value,
                ]);
                $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Failed to update lead.']);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error in updatedLeadsData: ' . $e->getMessage(), [
                'key' => $key,
                'value' => $value,
            ]);
        }
    }

    public function loadSheetLeads()
    {
        try {
            // Validate user and role
            if (!auth()->check() || !auth()->user()->isScrapper()) {
                $this->leadsData = [$this->emptyRow()];
                return;
            }

            // Validate sheet filter
            if (!$this->sheetFilter || !is_numeric($this->sheetFilter)) {
                $this->leadsData = [$this->emptyRow()];
                return;
            }

            // Verify sheet exists and belongs to user
            $sheet = LeadSheet::where('id', $this->sheetFilter)
                ->where('created_by', auth()->id())
                ->first();

            if (!$sheet) {
                $this->leadsData = [$this->emptyRow()];
                $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Selected sheet not found or access denied.']);
                return;
            }

            // Load leads for this sheet
            $leads = Lead::where('created_by', auth()->id())
                ->where('lead_sheet_id', $this->sheetFilter)
                ->get()
                ->map(function ($lead) {
                    return [
                        'id' => $lead->id,
                        'name' => $lead->name ?? '',
                        'email' => $lead->email ?? '',
                        'services' => $lead->services ?? '',
                        'phone' => $lead->phone ?? '',
                        'location' => $lead->location ?? '',
                        'position' => $lead->position ?? '',
                        'platform' => $lead->platform ?? '',
                        'linkedin' => $lead->linkedin ?? '',
                        'detail' => $lead->detail ?? '',
                        'web_link' => $lead->web_link ?? '',
                    ];
                })
                ->values()
                ->all();

            $this->leadsData = $leads;
            $this->pendingCreates = []; // Clear pending creates when reloading
            $this->ensureEmptyRow();
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error loading sheet leads: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
                'sheet_id' => $this->sheetFilter,
            ]);
            $this->leadsData = [$this->emptyRow()];
            $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Failed to load leads. Please try again.']);
        }
    }

    public function deleteLeadFromTable($leadId, $index)
    {
        try {
            if (!auth()->check() || !auth()->user()->isScrapper()) {
                return;
            }

            // Verify lead exists and belongs to user
            $lead = Lead::where('id', $leadId)
                ->where('created_by', auth()->id())
                ->first();

            if (!$lead) {
                $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Lead not found or access denied.']);
                return;
            }

            // Delete the lead
            $lead->delete();

            // Remove from array using the lead ID to find the correct row
            foreach ($this->leadsData as $idx => $row) {
                if (isset($row['id']) && $row['id'] == $leadId) {
                    unset($this->leadsData[$idx]);
                    break;
                }
            }

            // Re-index array
            $this->leadsData = array_values($this->leadsData);
            
            // Ensure empty row exists
            $this->ensureEmptyRow();
            $this->dispatch('lead-updated');
            $this->dispatch('show-toast', ['type' => 'success', 'message' => 'Lead deleted successfully.']);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error deleting lead from table: ' . $e->getMessage());
            $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Failed to delete lead.']);
        }
    }

    public function ensureEmptyRow()
    {
        // Always ensure there's exactly one empty row at the end
        if (empty($this->leadsData)) {
            $this->leadsData[] = $this->emptyRow();
            return;
        }

        // Remove any existing empty rows from the end
        while (!empty($this->leadsData)) {
            $lastIndex = count($this->leadsData) - 1;
            $last = $this->leadsData[$lastIndex] ?? null;
            
            if (!$last) {
                break;
            }
            
            // Check if this is an empty row (no ID and all fields are empty)
            $isEmpty = empty($last['id']);
            if ($isEmpty) {
                foreach ($last as $key => $val) {
                    if ($key !== 'id' && $val !== null && $val !== '') {
                        $isEmpty = false;
                        break;
                    }
                }
            }
            
            // If it's empty, remove it; otherwise stop
            if ($isEmpty) {
                array_pop($this->leadsData);
            } else {
                break;
            }
        }

        // Always add exactly one empty row at the end
        $this->leadsData[] = $this->emptyRow();
    }

    public function emptyRow(): array
    {
        return [
            'id' => null,
            'name' => '',
            'email' => '',
            'services' => '',
            'phone' => '',
            'location' => '',
            'position' => '',
            'platform' => '',
            'linkedin' => '',
            'detail' => '',
            'web_link' => '',
        ];
    }

    public function mount()
    {
        // Set view mode from query parameter
        if (request()->has('viewMode') && in_array(request()->get('viewMode'), ['table', 'list'])) {
            $this->viewMode = request()->get('viewMode');
        }
        
        // Load sheet leads only for table view
        if (auth()->check() && auth()->user()->isScrapper() && $this->viewMode === 'table' && empty($this->leadsData)) {
            $this->loadSheetLeads();
        }
    }

    public function render()
    {
        try {
            // Load sheet leads data only for table view
            if (auth()->check() && auth()->user()->isScrapper() && $this->viewMode === 'table' && empty($this->leadsData)) {
                $this->loadSheetLeads();
            }

            $query = Lead::with(['creator', 'opener'])
                ->orderBy('created_at', 'desc');

            // Apply role-based filtering
            if (auth()->check() && auth()->user()->isScrapper()) {
                $query->where('created_by', auth()->id());
            }

            // Apply search filter
            if ($this->search) {
                $searchTerm = '%' . trim($this->search) . '%';
                $query->where(function($q) use ($searchTerm) {
                    $q->where('name', 'like', $searchTerm)
                      ->orWhere('email', 'like', $searchTerm)
                      ->orWhere('phone', 'like', $searchTerm)
                      ->orWhere('company', 'like', $searchTerm)
                      ->orWhere('services', 'like', $searchTerm)
                      ->orWhere('location', 'like', $searchTerm)
                      ->orWhere('position', 'like', $searchTerm)
                      ->orWhere('platform', 'like', $searchTerm)
                      ->orWhere('linkedin', 'like', $searchTerm)
                      ->orWhere('detail', 'like', $searchTerm)
                      ->orWhere('web_link', 'like', $searchTerm);
                });
            }

            // Apply status filter
            if ($this->statusFilter) {
                $query->where('status', $this->statusFilter);
            }

            // Apply sheet filter
            if ($this->sheetFilter) {
                $query->where('lead_sheet_id', $this->sheetFilter);
            } elseif (auth()->check() && auth()->user()->isScrapper() && $this->viewMode === 'list') {
                // For list view, show all leads if no sheet filter, but for table view, require sheet filter
                // This is already handled in the view logic
            }

            $leads = $query->paginate(10);

            $sheetsQuery = LeadSheet::orderBy('created_at', 'desc');
            if (auth()->check() && auth()->user()->isScrapper()) {
                $sheetsQuery->where('created_by', auth()->id());
            }

            return view('components.leads.⚡index', [
                'leads' => $leads,
                'sheets' => $sheetsQuery->get(),
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error rendering leads index: ' . $e->getMessage());
            return view('components.leads.⚡index', [
                'leads' => \Illuminate\Pagination\LengthAwarePaginator::empty(),
                'sheets' => collect([]),
            ]);
        }
    }
};
?>

<div class="max-w-9xl mx-auto px-4 sm:px-6 lg:px-8 py-6" wire:poll.5s="refreshLeadsData">
    <!-- Header -->
    <div class="mb-6">
        <div class="flex justify-between items-center">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">Leads Management</h1>
                <p class="text-gray-600 mt-1">Manage and track all your leads</p>
            </div>
            @if(auth()->user()->canCreateLeads())
                <button 
                    wire:click="$dispatch('open-create-modal')"
                    class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-lg font-semibold shadow-sm hover:shadow-md transition-all flex items-center space-x-2"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                    </svg>
                    <span>Add New Lead</span>
                </button>
            @endif
        </div>
    </div>

        @if(auth()->user()->canCreateSheets())
            <div class="mb-6">
                <h2 class="text-lg font-semibold text-gray-900 mb-3">Create Sheet</h2>
                <livewire:sheets.create />
            </div>
        @endif

    <!-- Create Lead Modal -->
    @if(auth()->user()->canCreateLeads())
        <livewire:leads.create />
    @endif

    <!-- Search and Filter -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 mb-6">
        <div class="flex flex-col sm:flex-row gap-4">
            <div class="flex-1 relative">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                </div>
                <input 
                    type="text" 
                    wire:model.live.debounce.300ms="search" 
                    placeholder="Search by name, email, phone, or company..." 
                    class="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                >
            </div>
            <select 
                wire:model.live="statusFilter" 
                class="px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white"
            >
                <option value="">All Statuses</option>
                <option value="wrong number">Wrong Number</option>
                <option value="follow up">Follow Up</option>
                <option value="hired us">Hired Us</option>
                <option value="hired someone">Hired Someone</option>
                <option value="no response">No Response</option>
            </select>
            @if(auth()->user()->isScrapper() || auth()->user()->isSalesTeam() || auth()->user()->isAdmin())
                <select 
                    wire:model.live="sheetFilter" 
                    class="px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white"
                >
                    <option value="">All Sheets</option>
                    @foreach($sheets as $sheet)
                        <option value="{{ $sheet->id }}">{{ $sheet->name }}</option>
                    @endforeach
                </select>
            @endif
        </div>
        @error('sheetFilter') <span class="text-red-500 text-sm mt-2 block">{{ $message }}</span> @enderror
    </div>

    <!-- View Mode Toggle for Scrapper -->
    @if(auth()->user()->isScrapper())
        <div class="mb-4 flex justify-end">
            <div class="inline-flex rounded-lg border border-gray-300 bg-white shadow-sm">
                @php
                    $queryParams = request()->query();
                    $queryParams['viewMode'] = 'table';
                    if (!isset($queryParams['sheetFilter']) && $sheetFilter) {
                        $queryParams['sheetFilter'] = $sheetFilter;
                    }
                @endphp
                <a 
                    href="{{ route('leads.index', $queryParams) }}"
                    class="px-4 py-2 text-sm font-semibold rounded-l-lg transition-colors {{ $viewMode === 'table' ? 'bg-blue-600 text-white' : 'text-gray-700 hover:bg-gray-50' }}"
                >
                    <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                    </svg>
                    Table View
                </a>
                @php
                    $queryParams['viewMode'] = 'list';
                @endphp
                <a 
                    href="{{ route('leads.index', $queryParams) }}"
                    class="px-4 py-2 text-sm font-semibold rounded-r-lg transition-colors {{ $viewMode === 'list' ? 'bg-blue-600 text-white' : 'text-gray-700 hover:bg-gray-50' }}"
                >
                    <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"></path>
                    </svg>
                    List View
                </a>
            </div>
        </div>
    @endif

    <!-- Leads Table View (for Scrapper when viewMode is 'table') -->
    @if(auth()->user()->isScrapper() && $viewMode === 'table')
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Name</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Email</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Services</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Phone No</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Location</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Position</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Platform</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">LinkedIn</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Detail</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Web Link</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @forelse($leadsData as $index => $row)
                            <tr wire:key="lead-row-{{ $row['id'] ?? 'new-' . $index }}" class="{{ empty($row['id']) ? 'bg-blue-50' : 'hover:bg-gray-50' }}">
                                <td class="px-4 py-2">
                                    <input type="text" wire:model.live.debounce.500ms="leadsData.{{ $index }}.name" class="w-48 px-2 py-1 border border-gray-300 rounded" placeholder="Name" @if(!$sheetFilter) disabled @endif>
                                </td>
                                <td class="px-4 py-2">
                                    <input type="email" wire:model.live.debounce.500ms="leadsData.{{ $index }}.email" class="w-56 px-2 py-1 border border-gray-300 rounded" placeholder="Email" @if(!$sheetFilter) disabled @endif>
                                </td>
                                <td class="px-4 py-2">
                                    <input type="text" wire:model.live.debounce.500ms="leadsData.{{ $index }}.services" class="w-40 px-2 py-1 border border-gray-300 rounded" placeholder="Services" @if(!$sheetFilter) disabled @endif>
                                </td>
                                <td class="px-4 py-2">
                                    <input type="text" wire:model.live.debounce.500ms="leadsData.{{ $index }}.phone" class="w-40 px-2 py-1 border border-gray-300 rounded" placeholder="Phone" @if(!$sheetFilter) disabled @endif>
                                </td>
                                <td class="px-4 py-2">
                                    <input type="text" wire:model.live.debounce.500ms="leadsData.{{ $index }}.location" class="w-40 px-2 py-1 border border-gray-300 rounded" placeholder="Location" @if(!$sheetFilter) disabled @endif>
                                </td>
                                <td class="px-4 py-2">
                                    <input type="text" wire:model.live.debounce.500ms="leadsData.{{ $index }}.position" class="w-40 px-2 py-1 border border-gray-300 rounded" placeholder="Position" @if(!$sheetFilter) disabled @endif>
                                </td>
                                <td class="px-4 py-2">
                                    <input type="text" wire:model.live.debounce.500ms="leadsData.{{ $index }}.platform" class="w-32 px-2 py-1 border border-gray-300 rounded" placeholder="Platform" @if(!$sheetFilter) disabled @endif>
                                </td>
                                <td class="px-4 py-2">
                                    <input type="text" wire:model.live.debounce.500ms="leadsData.{{ $index }}.linkedin" class="w-48 px-2 py-1 border border-gray-300 rounded" placeholder="LinkedIn" @if(!$sheetFilter) disabled @endif>
                                </td>
                                <td class="px-4 py-2">
                                    <input type="text" wire:model.live.debounce.500ms="leadsData.{{ $index }}.detail" class="w-56 px-2 py-1 border border-gray-300 rounded" placeholder="Detail" @if(!$sheetFilter) disabled @endif>
                                </td>
                                <td class="px-4 py-2">
                                    <input type="text" wire:model.live.debounce.500ms="leadsData.{{ $index }}.web_link" class="w-48 px-2 py-1 border border-gray-300 rounded" placeholder="Web link" @if(!$sheetFilter) disabled @endif>
                                </td>
                                @if(!empty($row['id']))
                                    <td class="px-4 py-2">
                                        <button 
                                            wire:click="deleteLeadFromTable({{ $row['id'] }}, {{ $index }})"
                                            onclick="return confirm('Are you sure you want to delete this lead?')"
                                            class="px-2 py-1 text-red-600 hover:text-red-800 hover:bg-red-50 rounded transition-colors"
                                            title="Delete lead"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                            </svg>
                                        </button>
                                    </td>
                                @else
                                    <td class="px-4 py-2"></td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="11" class="px-6 py-12 text-center text-gray-500">
                                    Select a sheet to view and add leads.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @elseif(auth()->user()->isScrapper() && $viewMode === 'list')
        <!-- List View for Scrapper (same as sales team view) -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Lead</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Contact</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Company</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Opened By</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Created By</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @forelse($leads as $lead)
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="w-10 h-10 bg-blue-600 rounded-lg flex items-center justify-center text-white font-bold text-sm mr-3">
                                            {{ strtoupper(substr($lead->name, 0, 1)) }}
                                        </div>
                                        <a href="{{ route('leads.show', $lead->id) }}" class="text-sm font-semibold text-gray-900 hover:text-blue-600 transition-colors">
                                            {{ $lead->name }}
                                        </a>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">{{ $lead->email ?? 'N/A' }}</div>
                                    <div class="text-xs text-gray-500">{{ $lead->phone ?? 'N/A' }}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">{{ $lead->company ?? 'N/A' }}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @php
                                    $statusColors = [
                                        'wrong number' => 'bg-red-100 text-red-700',
                                        'follow up' => 'bg-amber-100 text-amber-700',
                                        'hired us' => 'bg-emerald-100 text-emerald-700',
                                        'hired someone' => 'bg-purple-100 text-purple-700',
                                        'no response' => 'bg-gray-100 text-gray-700',
                                    ];
                                @endphp
                                <span class="px-3 py-1 text-xs font-semibold rounded-full {{ $statusColors[$lead->status] ?? 'bg-gray-100 text-gray-700' }}">
                                    {{ ucwords($lead->status) }}
                                </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @if($lead->opener)
                                        <div class="flex items-center">
                                            <div class="w-8 h-8 bg-emerald-500 rounded-full flex items-center justify-center text-white text-xs font-bold mr-2">
                                                {{ strtoupper(substr($lead->opener->name, 0, 1)) }}
                                            </div>
                                            <div>
                                                <div class="text-sm font-medium text-gray-900">{{ $lead->opener->name }}</div>
                                                <div class="text-xs text-gray-500">{{ $lead->opened_at?->format('M d, H:i') }}</div>
                                            </div>
                                        </div>
                                    @else
                                        <span class="text-sm text-gray-400 italic">Not opened</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">{{ $lead->creator->name }}</div>
                                    <div class="text-xs text-gray-500">{{ $lead->created_at->format('M d, Y') }}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <a href="{{ route('leads.show', $lead->id) }}" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white text-sm font-semibold rounded-lg hover:bg-blue-700 transition-colors">
                                        View
                                        <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                        </svg>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-12 text-center">
                                    <svg class="w-16 h-16 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                                    </svg>
                                    <p class="text-gray-500 font-medium">No leads found</p>
                                    <p class="text-gray-400 text-sm mt-1">Try adjusting your search or filters</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <!-- Pagination for List View -->
        <div class="mt-6">
            {{ $leads->links() }}
        </div>
    @elseif(!auth()->user()->isScrapper())
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Lead</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Contact</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Company</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Opened By</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Created By</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @forelse($leads as $lead)
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="w-10 h-10 bg-blue-600 rounded-lg flex items-center justify-center text-white font-bold text-sm mr-3">
                                            {{ strtoupper(substr($lead->name, 0, 1)) }}
                                        </div>
                                        <a href="{{ route('leads.show', $lead->id) }}" class="text-sm font-semibold text-gray-900 hover:text-blue-600 transition-colors">
                                            {{ $lead->name }}
                                        </a>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">{{ $lead->email ?? 'N/A' }}</div>
                                    <div class="text-xs text-gray-500">{{ $lead->phone ?? 'N/A' }}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">{{ $lead->company ?? 'N/A' }}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @php
                                    $statusColors = [
                                        'wrong number' => 'bg-red-100 text-red-700',
                                        'follow up' => 'bg-amber-100 text-amber-700',
                                        'hired us' => 'bg-emerald-100 text-emerald-700',
                                        'hired someone' => 'bg-purple-100 text-purple-700',
                                        'no response' => 'bg-gray-100 text-gray-700',
                                    ];
                                @endphp
                                <span class="px-3 py-1 text-xs font-semibold rounded-full {{ $statusColors[$lead->status] ?? 'bg-gray-100 text-gray-700' }}">
                                    {{ ucwords($lead->status) }}
                                </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @if($lead->opener)
                                        <div class="flex items-center">
                                            <div class="w-8 h-8 bg-emerald-500 rounded-full flex items-center justify-center text-white text-xs font-bold mr-2">
                                                {{ strtoupper(substr($lead->opener->name, 0, 1)) }}
                                            </div>
                                            <div>
                                                <div class="text-sm font-medium text-gray-900">{{ $lead->opener->name }}</div>
                                                <div class="text-xs text-gray-500">{{ $lead->opened_at?->format('M d, H:i') }}</div>
                                            </div>
                                        </div>
                                    @else
                                        <span class="text-sm text-gray-400 italic">Not opened</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">{{ $lead->creator->name }}</div>
                                    <div class="text-xs text-gray-500">{{ $lead->created_at->format('M d, Y') }}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <a href="{{ route('leads.show', $lead->id) }}" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white text-sm font-semibold rounded-lg hover:bg-blue-700 transition-colors">
                                        View
                                        <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                        </svg>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-12 text-center">
                                    <svg class="w-16 h-16 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                                    </svg>
                                    <p class="text-gray-500 font-medium">No leads found</p>
                                    <p class="text-gray-400 text-sm mt-1">Try adjusting your search or filters</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <!-- Pagination -->
    @if(!auth()->user()->isScrapper())
        <div class="mt-6">
            {{ $leads->links() }}
        </div>
    @endif
</div>
