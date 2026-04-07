<?php

use App\Models\Lead;
use App\Models\LeadGroup;
use App\Models\LeadSheet;
use App\Models\User;
use App\Services\NotificationService;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

new class extends Component
{
    use WithPagination;

    public $search = '';
    public $statusFilter = '';
    public $sheetFilter = '';
    public $groupFilter = '';
    public $newGroupName = '';
    public $viewMode = 'table'; 
    public $leadsData = [];
    public $pendingCreates = [];
    public $editingGroupId = null;
    public $editingGroupName = '';
    public $addGroupFormKey = 1;
    private $rowUniqueIds = [];
    protected $queryString = [
        'sheetFilter' => ['except' => ''],
        'groupFilter' => ['except' => ''],
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
        if (!$this->sheetFilter) {
            $this->groupFilter = '';
            $this->editingGroupId = null;
            $this->editingGroupName = '';
            $this->pendingCreates = [];
            $this->leadsData = [];
            $this->loadSheetLeads();
            return;
        }

        $firstGroupId = LeadGroup::where('lead_sheet_id', $this->sheetFilter)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->value('id');

        $this->groupFilter = $firstGroupId ? (string) $firstGroupId : null;
        $this->editingGroupId = null;
        $this->editingGroupName = '';
        $this->pendingCreates = [];
        $this->leadsData = [];
        $this->resetPage();
        $this->loadSheetLeads();
    }

    public function updatedGroupFilter()
    {
        $this->pendingCreates = [];
        $this->leadsData = [];
        $this->editingGroupId = null;
        $this->editingGroupName = '';
        $this->resetPage();
        $this->loadSheetLeads();
    }

    public function selectGroup($groupId)
    {
        if (!$this->sheetFilter) {
            return;
        }

        $group = LeadGroup::where('id', $groupId)
            ->where('lead_sheet_id', $this->sheetFilter)
            ->first();

        if (!$group) {
            $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Tab not found for this sheet.']);
            return;
        }

        $this->groupFilter = (string) $groupId;
        $this->pendingCreates = [];
        $this->leadsData = [];
        $this->resetPage();
        $this->loadSheetLeads();
    }

    public function addGroup()
    {
        $this->validate(['newGroupName' => 'required|string|max:255'], ['newGroupName.required' => 'Table name is required.']);
        $sheetId = (int) $this->sheetFilter;
        $sheet = LeadSheet::where('id', $sheetId)->where('created_by', auth()->id())->first();
        if (!$sheet) {
            $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Sheet not found or access denied.']);
            return;
        }
        $maxOrder = LeadGroup::where('lead_sheet_id', $sheetId)->max('sort_order') ?? 0;
        $group = LeadGroup::create([
            'lead_sheet_id' => $sheetId,
            'name' => trim($this->newGroupName),
            'sort_order' => $maxOrder + 1,
        ]);
        $this->newGroupName = '';
        $this->groupFilter = (string) $group->id;
        $this->resetValidation();
        $this->addGroupFormKey++;
        $this->loadSheetLeads();
        $this->dispatch('show-toast', ['type' => 'success', 'message' => 'Tab added.']);
    }

    public function removeGroup($groupId)
    {
        $group = LeadGroup::find($groupId);
        if (!$group) return;
        $sheet = $group->leadSheet;
        if (!$sheet || $sheet->created_by !== auth()->id()) {
            $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Access denied.']);
            return;
        }
        $group->leads()->delete();
        $group->delete();
        if ($this->groupFilter == $groupId) $this->groupFilter = '';
        $this->loadSheetLeads();
        $this->dispatch('show-toast', ['type' => 'success', 'message' => 'Tab removed. Leads in it were soft-deleted.']);
    }


    public function startEditingGroup($groupId)
    {
        $group = LeadGroup::find($groupId);
        if (!$group) {
            return;
        }

        $sheet = $group->leadSheet;
        if (!$sheet || $sheet->created_by !== auth()->id()) {
            $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Access denied.']);
            return;
        }

        $this->groupFilter = (string) $groupId;
        $this->editingGroupId = $groupId;
        $this->editingGroupName = $group->name;
    }

    public function cancelEditingGroup()
    {
        $this->editingGroupId = null;
        $this->editingGroupName = '';
    }

    public function updateGroup()
    {
        if (!$this->editingGroupName || trim($this->editingGroupName) === '') {
            $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Tab name cannot be empty.']);
            return;
        }

        $this->validate([
            'editingGroupName' => 'required|string|max:255',
        ], [
            'editingGroupName.required' => 'Tab name is required.'
        ]);

        $group = LeadGroup::find($this->editingGroupId);
        if (!$group) {
            $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Tab not found.']);
            return;
        }

        $sheet = $group->leadSheet;
        if (!$sheet || $sheet->created_by !== auth()->id()) {
            $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Access denied.']);
            return;
        }

        $group->update(['name' => trim($this->editingGroupName)]);

        $this->editingGroupId = null;
        $this->editingGroupName = '';
        $this->loadSheetLeads();
        $this->resetValidation();
        $this->dispatch('show-toast', ['type' => 'success', 'message' => 'Tab renamed successfully.']);
    }

    public function deleteLeadRow($leadId)
    {
        if (!auth()->check() || !$this->canEditAcrossAllSheets()) {
            $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Access denied.']);
            return;
        }

        $lead = Lead::where('id', $leadId)
            ->where('created_by', auth()->id())
            ->first();

        if (!$lead) {
            $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Lead not found.']);
            return;
        }

        $lead->delete();
        $this->leadsData = array_values(array_filter(
            $this->leadsData,
            fn ($row) => ($row['id'] ?? null) !== $leadId
        ));
        $this->ensureEmptyRow();
        $this->dispatch('show-toast', ['type' => 'success', 'message' => 'Lead deleted successfully.']);
        $this->dispatch('lead-updated');
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
    public function refreshSheets($sheetId = null)
    {
        $this->resetPage();

        if (!auth()->check() || !$sheetId) {
            return;
        }

        $sheet = LeadSheet::where('id', $sheetId)
            ->where('created_by', auth()->id())
            ->first();

        if (!$sheet) {
            return;
        }

        $this->sheetFilter = (string) $sheetId;
        $firstGroupId = LeadGroup::where('lead_sheet_id', $sheetId)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->value('id');
        $this->groupFilter = $firstGroupId ? (string) $firstGroupId : null;
        $this->pendingCreates = [];
        $this->leadsData = [];
        $this->editingGroupId = null;
        $this->editingGroupName = '';
        $this->loadSheetLeads();
    }

    public function refreshLeadsData()
    {
        // This method is called by polling to refresh the leads list
        // The render method will automatically fetch fresh data
    }

    protected function canEditAcrossAllSheets(): bool
    {
        return auth()->check() && (auth()->user()->isScrapper() || auth()->user()->isFrontSale());
    }

    public function updatedLeadsData($value, $key)
    {
        try {
            if (!auth()->check()) {
                return;
            }
            if (!$this->canEditAcrossAllSheets()) {
                return;
            }

            [$index, $field] = explode('.', $key) + [null, null];
            if ($index === null || $field === null) {
                return;
            }

            $allowed = ['name', 'email', 'services', 'phone', 'location', 'position', 'platform', 'linkedin', 'detail', 'web_link'];
            if (!in_array($field, $allowed, true)) {
                return;
            }

            $row = $this->leadsData[$index] ?? null;
            if (!$row) {
                return;
            }

            // Trim value
            $value = is_string($value) ? trim($value) : $value;

            if ($field === 'name' && $row['id'] && $value === '') {
                try {
                    Lead::where('id', $row['id'])
                        ->where('created_by', auth()->id())
                        ->delete();
                    array_splice($this->leadsData, (int) $index, 1);
                    $this->ensureEmptyRow();
                    $this->dispatch('lead-updated');
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('Error deleting lead: ' . $e->getMessage());
                    $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Failed to delete lead.']);
                }
                return;
            }

            if ($field === 'email' && $value && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Invalid email format.']);
                return;
            }

            if (!$this->sheetFilter && empty($row['id'])) {
                return;
            }

            if ($this->sheetFilter && ($this->groupFilter === '' || $this->groupFilter === null) && empty($row['id'])) {
                return;
            }

            if (empty($row['id'])) {
                if (empty($row['name'])) {
                    return;
                }

                if (!empty($this->pendingCreates[$index])) {
                    return;
                }

                $this->pendingCreates[$index] = true;

                try {
                    $existingLead = Lead::where('created_by', auth()->id())
                        ->where('lead_sheet_id', $this->sheetFilter)
                        ->where('name', $row['name'])
                        ->whereDate('lead_date', now()->toDateString());

                    if ($this->groupFilter !== '' && $this->groupFilter !== null) {
                        $existingLead->where('lead_group_id', $this->groupFilter);
                    } else {
                        $existingLead->whereNull('lead_group_id');
                    }

                    $existingLead = $existingLead
                        ->orderBy('created_at', 'desc')
                        ->first();

                    if ($existingLead) {
                        $this->leadsData[$index]['id'] = $existingLead->id;
                        $this->pendingCreates[$index] = false;
                        return;
                    }

                    $lead = Lead::create([
                        'created_by' => auth()->id(),
                        'lead_sheet_id' => $this->sheetFilter,
                        'lead_group_id' => $this->groupFilter ?: null,
                        'lead_date' => now()->toDateString(),
                        'status' => 'no response',
                        'name' => trim($row['name']),
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

                    // Notify sales users and broadcast the update to their active clients.
                    $salesUsers = User::whereIn('role', ['sales', 'upsale', 'front_sale'])->get();
                    
                    if ($salesUsers->isNotEmpty()) {
                        NotificationService::createForUsers(
                            $salesUsers,
                            $lead,
                            'new_lead',
                            "New lead '{$lead->name}' has been added by " . auth()->user()->name
                        );
                    }

                    $this->leadsData[$index]['id'] = $lead->id;
                    $this->dispatch('lead-created');
                    $this->ensureEmptyRow();
                    $this->pendingCreates[$index] = false;
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('Error creating lead in table: ' . $e->getMessage());
                    $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Failed to save lead. Please try again.']);
                    $this->pendingCreates[$index] = false;
                }
                return;
            }

            // Update existing lead
            try {
                Lead::where('id', $row['id'])
                    ->where('created_by', auth()->id())
                    ->update([$field => $value]);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Error updating lead: ' . $e->getMessage());
                $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Failed to update lead.']);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error in updatedLeadsData: ' . $e->getMessage());
        }
    }

    public function loadSheetLeads()
    {
        try {
            if (!auth()->check()) {
                $this->leadsData = [$this->emptyRow()];
                return;
            }

            if (!$this->sheetFilter) {
                if (!$this->canEditAcrossAllSheets()) {
                    $this->leadsData = [];
                    return;
                }

                $this->leadsData = Lead::with(['leadSheet', 'leadGroup'])
                    ->where('created_by', auth()->id())
                    ->orderBy('created_at', 'desc')
                    ->get()
                    ->map(function ($lead) {
                        return [
                            '_row_key' => 'lead-'.$lead->id,
                            'id' => $lead->id,
                            'sheet_name' => $lead->leadSheet?->name ?? '',
                            'group_name' => $lead->leadGroup?->name ?? 'Ungrouped',
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

                return;
            }

            $sheet = LeadSheet::find($this->sheetFilter);
            if (!$sheet || $sheet->created_by !== auth()->id()) {
                $this->leadsData = [$this->emptyRow()];
                $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Selected sheet not found or access denied.']);
                return;
            }
            // Scrapper or front_sale can load leads only for their own sheets
            if (!auth()->user()->isScrapper() && !auth()->user()->isFrontSale()) {
                $this->leadsData = [$this->emptyRow()];
                return;
            }

            if ($this->groupFilter === '' || $this->groupFilter === null) {
                $this->leadsData = [];
                return;
            }

            $leadsQuery = Lead::where('created_by', auth()->id())
                ->where('lead_sheet_id', $this->sheetFilter);
            $this->applySelectedGroupScope($leadsQuery);
            $this->leadsData = $leadsQuery->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($lead) {
                    return [
                        '_row_key' => 'lead-'.$lead->id,
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

            $this->ensureEmptyRow();
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error loading sheet leads: ' . $e->getMessage());
            $this->leadsData = [$this->emptyRow()];
            $this->dispatch('show-toast', type: 'error', message: 'Failed to load leads. Please try again.');
        }
    }

    public function ensureEmptyRow()
    {
        // Don't add a new row if there's no data
        if (empty($this->leadsData)) {
            $this->leadsData[] = $this->emptyRow();
            return;
        }

        $last = end($this->leadsData);
        
        // If last row is empty (no id and no name), don't add another empty row
        if (!$last || (empty($last['id']) && empty($last['name']))) {
            return;
        }

        // If last row has an ID (saved lead), always ensure there's an empty row after it
        if (!empty($last['id'])) {
            $this->leadsData[] = $this->emptyRow();
            return;
        }

        // If last row has a name but no ID yet (being created), wait for creation to complete
        // Don't add empty row until the lead is fully created and has an ID
    }

    public function emptyRow(): array
    {
        return [
            '_row_key' => Str::uuid()->toString(),
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

    public function displayValue($value, string $fallback = '—'): string
    {
        if ($value === null) {
            return $fallback;
        }

        if (is_string($value) && trim($value) === '') {
            return $fallback;
        }

        return (string) $value;
    }

    protected function applySelectedGroupScope($query): void
    {
        if ($this->groupFilter !== '' && $this->groupFilter !== null) {
            $query->where('lead_group_id', $this->groupFilter);
            return;
        }

        $query->whereRaw('1 = 0');
    }

    public function mount()
    {
        // Set view mode from query parameter
        if (request()->has('viewMode') && in_array(request()->get('viewMode'), ['table', 'list'])) {
            $this->viewMode = request()->get('viewMode');
        }
        
        if (auth()->check() && $this->viewMode === 'table' && empty($this->leadsData)) {
            if (!$this->sheetFilter && $this->canEditAcrossAllSheets()) {
                $this->loadSheetLeads();
                return;
            }

            if ($this->sheetFilter) {
                $sheet = LeadSheet::find($this->sheetFilter);
                if ($sheet && $sheet->created_by === auth()->id() && $this->canEditAcrossAllSheets()) {
                    $this->loadSheetLeads();
                }
            }
        }
    }

    public function render()
    {
        try {
            if (auth()->check() && $this->viewMode === 'table' && empty($this->leadsData)) {
                if (!$this->sheetFilter && $this->canEditAcrossAllSheets()) {
                    $this->loadSheetLeads();
                } elseif ($this->sheetFilter) {
                    $currentSheet = LeadSheet::find($this->sheetFilter);
                    if ($currentSheet && $currentSheet->created_by === auth()->id() && $this->canEditAcrossAllSheets()) {
                        $this->loadSheetLeads();
                    }
                }
            }

            $query = Lead::with(['creator', 'opener', 'leadSheet', 'leadGroup'])
                ->orderBy('created_at', 'desc');

            // Apply role-based filtering
            if (auth()->check()) {
                if (auth()->user()->isScrapper()) {
                    $query->where('created_by', auth()->id());
                } elseif (auth()->user()->isSalesTeam()) {
                    // Sales: leads from sheets they created OR sheets assigned to their teams
                    $userTeamIds = auth()->user()->teams()->pluck('teams.id')->toArray();
                    $query->whereHas('leadSheet', function ($q) use ($userTeamIds) {
                        $q->where('created_by', auth()->id())
                            ->orWhereHas('teams', fn ($t) => $t->whereIn('teams.id', $userTeamIds));
                    });
                }
                // Admin: no extra filter (sees all)
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
            }
            // Apply group (tab) filter when viewing one sheet
            if ($this->sheetFilter) {
                $this->applySelectedGroupScope($query);
            }
            if (auth()->check() && auth()->user()->isScrapper() && $this->viewMode === 'list' && !$this->sheetFilter) {
                // For list view, show all leads if no sheet filter, but for table view, require sheet filter
                // This is already handled in the view logic
            }

            $leads = $query->paginate(10);

            $sheetsQuery = LeadSheet::with('teams')->orderBy('created_at', 'desc');
            if (auth()->check()) {
                if (auth()->user()->isScrapper()) {
                    $sheetsQuery->where('created_by', auth()->id());
                } elseif (auth()->user()->isSalesTeam()) {
                    // Sales: sheets they created OR sheets assigned to their teams
                    $userTeamIds = auth()->user()->teams()->pluck('teams.id')->toArray();
                    $sheetsQuery->where(function ($q) use ($userTeamIds) {
                        $q->where('created_by', auth()->id())
                            ->orWhereHas('teams', fn ($t) => $t->whereIn('teams.id', $userTeamIds));
                    });
                }
                // Admin: no extra filter (sees all sheets)
            }

            $groups = collect([]);
            if ($this->sheetFilter) {
                $groups = LeadGroup::where('lead_sheet_id', $this->sheetFilter)->orderBy('sort_order')->orderBy('name')->get();
            }

            return view('components.leads.⚡index', [
                'leads' => $leads,
                'sheets' => $sheetsQuery->get(),
                'groups' => $groups,
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error rendering leads index: ' . $e->getMessage());
            return view('components.leads.⚡index', [
                'leads' => \Illuminate\Pagination\LengthAwarePaginator::empty(),
                'sheets' => collect([]),
                'groups' => collect([]),
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
                    wire:key="sheet-filter-{{ $sheetFilter ?: 'all' }}-{{ $sheets->count() }}"
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

    <!-- Tabs (groups) when a sheet is selected -->
    @if($sheetFilter && (auth()->user()->isScrapper() || auth()->user()->isSalesTeam() || auth()->user()->isAdmin()))
        @php
            $currentSheet = $sheets->firstWhere('id', (int)$sheetFilter);
            $canEditTabs = $currentSheet && $currentSheet->created_by === auth()->id() && (auth()->user()->isScrapper() || auth()->user()->isFrontSale());
        @endphp
        <div class="bg-white rounded-t-xl border border-gray-200 border-b-0 overflow-hidden mb-0" wire:key="tabs-{{ $sheetFilter ?: 'none' }}-{{ $groups->count() }}">
            <div class="flex items-end border-b border-gray-200 bg-gray-50/80 overflow-x-auto">
                @foreach($groups as $group)
                    @if($editingGroupId === $group->id)
                        <form wire:submit.prevent="updateGroup" class="flex items-center px-4 py-2.5 border-b-2 -mb-px border-blue-600">
                            <input 
                                type="text" 
                                wire:model="editingGroupName" 
                                placeholder="Enter tab name..." 
                                maxlength="255"
                                wire:keydown.escape="cancelEditingGroup"
                                wire:keydown.enter="updateGroup"
                                autofocus
                                class="px-2 py-1 text-sm border border-gray-300 rounded focus:ring-2 focus:ring-blue-500 focus:border-transparent w-40"
                            >
                            <button type="submit" class="ml-2 px-3 py-1 text-sm text-white bg-blue-600 hover:bg-blue-700 rounded font-medium">Save</button>
                            <button type="button" wire:click="cancelEditingGroup" class="ml-1 px-3 py-1 text-sm text-gray-600 hover:bg-gray-200 rounded font-medium">Cancel</button>
                        </form>
                    @else
                        <button type="button"
                            wire:click="selectGroup({{ $group->id }})"
                            @if($canEditTabs) wire:dblclick="startEditingGroup({{ $group->id }})" @endif
                            class="group px-4 py-2.5 text-sm font-medium whitespace-nowrap border-b-2 -mb-px transition-colors flex items-center gap-1.5 {{ (string)$groupFilter === (string)$group->id ? 'bg-white text-blue-600 border-blue-600' : 'text-gray-600 border-transparent hover:bg-gray-100' }}"
                            {!! $canEditTabs ? 'title="Double-click to rename"' : '' !!}
                        >
                            <span>{{ $group->name }}</span>
                            @if($canEditTabs)
                                <span class="opacity-0 group-hover:opacity-100 hover:opacity-100 text-gray-400 hover:text-red-600 cursor-pointer text-base leading-none select-none" onclick="event.stopPropagation(); if(confirm('Remove this tab? Leads in it will be deleted for users.')) { @this.call('removeGroup', {{ $group->id }}) }">×</span>
                            @endif
                        </button>
                    @endif
                @endforeach
                @if($canEditTabs)
                    <form wire:submit.prevent="addGroup" class="flex items-center gap-1 ml-1 pb-1.5 border-b-2 border-transparent -mb-px">
                        <input 
                            type="text" 
                            wire:model.defer="newGroupName" 
                            placeholder="+ New tab" 
                            maxlength="255"
                            wire:key="add-group-input-{{ $addGroupFormKey }}"
                            class="w-24 px-2 py-1.5 text-sm border border-gray-300 rounded focus:ring-2 focus:ring-blue-500"
                        >
                        <button type="submit" class="px-2 py-1.5 text-sm text-blue-600 hover:bg-blue-50 rounded font-medium">Add</button>
                    </form>
                    @error('newGroupName') <span class="text-red-500 text-xs ml-2">{{ $message }}</span> @enderror
                @endif
            </div>
        </div>
    @endif

    <!-- View Mode Toggle for Scrapper and Front Sale (for their own sheets) -->
    @php
        $currentSheetForView = $sheetFilter ? $sheets->firstWhere('id', (int)$sheetFilter) : null;
        $canUseTableView = $currentSheetForView && $currentSheetForView->created_by === auth()->id() && (auth()->user()->isScrapper() || auth()->user()->isFrontSale());
        $canViewAllLeads = !$sheetFilter && (auth()->user()->isScrapper() || auth()->user()->isSalesTeam() || auth()->user()->isAdmin());
        $canEditAllSheetsTable = !$sheetFilter && (auth()->user()->isScrapper() || auth()->user()->isFrontSale());
    @endphp
    @if($canUseTableView || $canViewAllLeads)
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

    <!-- Leads Table View -->
    @if(($canUseTableView || $canEditAllSheetsTable) && $viewMode === 'table')
        @if($sheetFilter && ($groupFilter === '' || $groupFilter === null))
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 text-center text-gray-600">
                Select or create a tab/group to start adding leads in this sheet.
            </div>
        @else
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
                <div class="overflow-x-auto" wire:key="sheet-table-{{ $sheetFilter ?: 'none' }}-{{ $groupFilter !== '' ? $groupFilter : 'none' }}">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                @if(!$sheetFilter)
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Sheet</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Group</th>
                                @endif
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
                                <tr
                                    wire:key="lead-row-{{ $row['_row_key'] ?? Str::uuid() }}"
                                    class="{{ empty($row['id']) ? 'bg-blue-50' : 'hover:bg-gray-50' }}"
                                >
                                    @if(!$sheetFilter)
                                        <td class="px-4 py-2 text-sm text-gray-700 whitespace-nowrap">
                                            {{ $this->displayValue($row['sheet_name'] ?? null) }}
                                        </td>
                                        <td class="px-4 py-2 text-sm text-gray-600 whitespace-nowrap">
                                            {{ $this->displayValue($row['group_name'] ?? null, 'Ungrouped') }}
                                        </td>
                                    @endif
                                    <td class="px-4 py-2">
                                        <input type="text" wire:model.live.debounce.500ms="leadsData.{{ $index }}.name" class="w-48 px-2 py-1 border border-gray-300 rounded" placeholder="Name">
                                    </td>
                                    <td class="px-4 py-2">
                                        <input type="email" wire:model.live.debounce.500ms="leadsData.{{ $index }}.email" class="w-56 px-2 py-1 border border-gray-300 rounded" placeholder="Email">
                                    </td>
                                    <td class="px-4 py-2">
                                        <input type="text" wire:model.live.debounce.500ms="leadsData.{{ $index }}.services" class="w-40 px-2 py-1 border border-gray-300 rounded" placeholder="Services">
                                    </td>
                                    <td class="px-4 py-2">
                                        <input type="text" wire:model.live.debounce.500ms="leadsData.{{ $index }}.phone" class="w-40 px-2 py-1 border border-gray-300 rounded" placeholder="Phone">
                                    </td>
                                    <td class="px-4 py-2">
                                        <input type="text" wire:model.live.debounce.500ms="leadsData.{{ $index }}.location" class="w-40 px-2 py-1 border border-gray-300 rounded" placeholder="Location">
                                    </td>
                                    <td class="px-4 py-2">
                                        <input type="text" wire:model.live.debounce.500ms="leadsData.{{ $index }}.position" class="w-40 px-2 py-1 border border-gray-300 rounded" placeholder="Position">
                                    </td>
                                    <td class="px-4 py-2">
                                        <input type="text" wire:model.live.debounce.500ms="leadsData.{{ $index }}.platform" class="w-32 px-2 py-1 border border-gray-300 rounded" placeholder="Platform">
                                    </td>
                                    <td class="px-4 py-2">
                                        <input type="text" wire:model.live.debounce.500ms="leadsData.{{ $index }}.linkedin" class="w-48 px-2 py-1 border border-gray-300 rounded" placeholder="LinkedIn">
                                    </td>
                                    <td class="px-4 py-2">
                                        <input type="text" wire:model.live.debounce.500ms="leadsData.{{ $index }}.detail" class="w-56 px-2 py-1 border border-gray-300 rounded" placeholder="Detail">
                                    </td>
                                    <td class="px-4 py-2">
                                        <input type="text" wire:model.live.debounce.500ms="leadsData.{{ $index }}.web_link" class="w-48 px-2 py-1 border border-gray-300 rounded" placeholder="Web link">
                                    </td>
                                    <td class="px-4 py-2">
                                        @if(!empty($row['id']))
                                            <button
                                                type="button"
                                                class="px-3 py-1 text-xs font-semibold text-red-700 bg-red-50 hover:bg-red-100 rounded"
                                                onclick="event.stopPropagation(); if(confirm('Delete this lead?')) { @this.call('deleteLeadRow', {{ $row['id'] }}) }"
                                            >
                                                Delete
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ $sheetFilter ? '11' : '13' }}" class="px-6 py-12 text-center text-gray-500">
                                        No leads found.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    @elseif($canViewAllLeads && $viewMode === 'table')
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Lead</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Contact</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Sheet</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Group</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Services</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Status</th>
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
                                        <div>
                                            <a href="{{ route('leads.show', $lead->id) }}" class="text-sm font-semibold text-gray-900 hover:text-blue-600 transition-colors">
                                                {{ $lead->name }}
                                            </a>
                                            <div class="text-xs text-gray-500">{{ $lead->lead_date?->format('M d, Y') ?? '—' }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">{{ $this->displayValue($lead->email) }}</div>
                                    <div class="text-xs text-gray-500">{{ $this->displayValue($lead->phone) }}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    {{ $this->displayValue($lead->leadSheet?->name) }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    {{ $lead->leadGroup->name ?? 'Ungrouped' }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                                    {{ $this->displayValue($lead->services) }}
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
                                <td colspan="8" class="px-6 py-12 text-center">
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
        <div class="mt-6">
            {{ $leads->links() }}
        </div>
    @elseif(($canUseTableView || $canViewAllLeads) && $viewMode === 'list')
        <!-- List View for Scrapper (same as sales team view) -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Lead</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Contact</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Sheet</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Group</th>
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
                                    <div class="text-sm font-medium text-gray-900">{{ $this->displayValue($lead->email) }}</div>
                                    <div class="text-xs text-gray-500">{{ $this->displayValue($lead->phone) }}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">{{ $this->displayValue($lead->leadSheet?->name) }}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    {{ $lead->leadGroup->name ?? 'Ungrouped' }}
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
                                <td colspan="8" class="px-6 py-12 text-center">
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
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Group</th>
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
                                    <div class="text-sm font-medium text-gray-900">{{ $this->displayValue($lead->email) }}</div>
                                    <div class="text-xs text-gray-500">{{ $this->displayValue($lead->phone) }}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">{{ $this->displayValue($lead->company) }}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    {{ $lead->leadGroup->name ?? 'Ungrouped' }}
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
                                <td colspan="8" class="px-6 py-12 text-center">
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
        <div class="mt-6">
            {{ $leads->links() }}
        </div>
    @endif
</div>
