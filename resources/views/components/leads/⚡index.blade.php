<?php

use App\Models\Lead;
use App\Models\LeadSheet;
use App\Models\User;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\On;

new class extends Component
{
    use WithPagination;

    public $search = '';
    public $statusFilter = '';
    public $sheetFilter = '';
    public $leadsData = [];
    public $pendingCreates = [];
    protected $queryString = [
        'sheetFilter' => ['except' => ''],
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
        if (!auth()->user()->isScrapper()) {
            return;
        }

        if (!$this->sheetFilter) {
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

        if ($field === 'name' && $row['id'] && $value === '') {
            Lead::where('id', $row['id'])
                ->where('created_by', auth()->id())
                ->delete();
            array_splice($this->leadsData, (int) $index, 1);
            $this->ensureEmptyRow();
            $this->dispatch('lead-updated');
            return;
        }

        if ($field === 'email' && $value && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
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

            $existingLead = Lead::where('created_by', auth()->id())
                ->where('lead_sheet_id', $this->sheetFilter)
                ->where('name', $row['name'])
                ->whereDate('lead_date', now()->toDateString())
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
                'lead_date' => now()->toDateString(),
                'status' => 'no response',
                'name' => $row['name'],
                'email' => $row['email'] ?? null,
                'services' => $row['services'] ?? null,
                'phone' => $row['phone'] ?? null,
                'location' => $row['location'] ?? null,
                'position' => $row['position'] ?? null,
                'platform' => $row['platform'] ?? null,
                'linkedin' => $row['linkedin'] ?? null,
                'detail' => $row['detail'] ?? null,
                'web_link' => $row['web_link'] ?? null,
            ]);

            $salesUsers = User::whereIn('role', ['sales', 'upsale', 'front_sale'])->get();
            foreach ($salesUsers as $user) {
                \App\Models\Notification::create([
                    'user_id' => $user->id,
                    'lead_id' => $lead->id,
                    'type' => 'new_lead',
                    'message' => "New lead '{$lead->name}' has been added by " . auth()->user()->name,
                ]);
            }

            $this->leadsData[$index]['id'] = $lead->id;
            $this->dispatch('lead-created');
            $this->ensureEmptyRow();
            $this->pendingCreates[$index] = false;
            return;
        }

        Lead::where('id', $row['id'])
            ->where('created_by', auth()->id())
            ->update([$field => $value]);
    }

    public function loadSheetLeads()
    {
        if (!auth()->user()->isScrapper() || !$this->sheetFilter) {
            $this->leadsData = [$this->emptyRow()];
            return;
        }

        $this->leadsData = Lead::where('created_by', auth()->id())
            ->where('lead_sheet_id', $this->sheetFilter)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($lead) {
                return [
                    'id' => $lead->id,
                    'name' => $lead->name,
                    'email' => $lead->email,
                    'services' => $lead->services,
                    'phone' => $lead->phone,
                    'location' => $lead->location,
                    'position' => $lead->position,
                    'platform' => $lead->platform,
                    'linkedin' => $lead->linkedin,
                    'detail' => $lead->detail,
                    'web_link' => $lead->web_link,
                ];
            })
            ->values()
            ->all();

        $this->ensureEmptyRow();
    }

    public function ensureEmptyRow()
    {
        $last = end($this->leadsData);
        if (!$last || !empty($last['id']) || array_filter($last)) {
            $this->leadsData[] = $this->emptyRow();
        }
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

    public function render()
    {
        if (auth()->user()->isScrapper() && empty($this->leadsData)) {
            $this->loadSheetLeads();
        }

        $query = Lead::with(['creator', 'opener'])
            ->orderBy('created_at', 'desc');

        // Apply role-based filtering
        if (auth()->user()->isScrapper()) {
            $query->where('created_by', auth()->id());
        }

        // Apply search filter
        if ($this->search) {
            $query->where(function($q) {
                $q->where('name', 'like', '%' . $this->search . '%')
                  ->orWhere('email', 'like', '%' . $this->search . '%')
                  ->orWhere('phone', 'like', '%' . $this->search . '%')
                  ->orWhere('company', 'like', '%' . $this->search . '%')
                  ->orWhere('services', 'like', '%' . $this->search . '%')
                  ->orWhere('location', 'like', '%' . $this->search . '%')
                  ->orWhere('position', 'like', '%' . $this->search . '%')
                  ->orWhere('platform', 'like', '%' . $this->search . '%')
                  ->orWhere('linkedin', 'like', '%' . $this->search . '%')
                  ->orWhere('detail', 'like', '%' . $this->search . '%')
                  ->orWhere('web_link', 'like', '%' . $this->search . '%');
            });
        }

        // Apply status filter
        if ($this->statusFilter) {
            $query->where('status', $this->statusFilter);
        }

        // Apply sheet filter
        if ($this->sheetFilter) {
            $query->where('lead_sheet_id', $this->sheetFilter);
        } elseif (auth()->user()->isScrapper()) {
            $query->whereRaw('1 = 0');
        }

        $leads = $query->paginate(10);

        $sheetsQuery = LeadSheet::orderBy('created_at', 'desc');
        if (auth()->user()->isScrapper()) {
            $sheetsQuery->where('created_by', auth()->id());
        }

        return view('components.leads.⚡index', [
            'leads' => $leads,
            'sheets' => $sheetsQuery->get(),
        ]);
    }
};
?>

<div class="max-w-9xl mx-auto px-4 sm:px-6 lg:px-8 py-6" wire:poll.3s="refreshLeadsData">
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

    <!-- Leads Table -->
    @if(auth()->user()->isScrapper())
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
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @forelse($leadsData as $index => $row)
                            <tr class="{{ empty($row['id']) ? 'bg-blue-50' : 'hover:bg-gray-50' }}">
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
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="px-6 py-12 text-center text-gray-500">
                                    Select a sheet to view and add leads.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @else
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
