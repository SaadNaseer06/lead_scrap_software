<?php

use App\Models\Lead;
use App\Models\User;
use App\Models\LeadSheet;
use Livewire\Component;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

new class extends Component
{
    public $name = '';
    public $email = '';
    public $phone = '';
    public $lead_date = '';
    public $services = '';
    public $budget = '';
    public $credits = '';
    public $detail = '';
    public $notes = '';
    public $lead_sheet_id = '';
    public $showModal = false;
    public $isSaving = false;
    public $sheets = [];

    protected $rules = [
        'name' => 'required|string|max:255',
        'email' => 'nullable|email|max:255',
        'phone' => 'nullable|string|max:255',
        'lead_date' => 'nullable|date',
        'services' => 'nullable|string|max:255',
        'budget' => 'nullable|string|max:255',
        'credits' => 'nullable|string|max:255',
        'detail' => 'nullable|string',
        'notes' => 'nullable|string',
        'lead_sheet_id' => 'nullable|exists:lead_sheets,id',
    ];

    public function mount()
    {
        $this->loadSheets();
    }

    #[On('open-create-modal')]
    public function openModal()
    {
        try {
            $this->showModal = true;
            $this->resetForm();
            $this->loadSheets();
        } catch (\Exception $e) {
            Log::error('Error opening create modal: ' . $e->getMessage());
                $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Failed to open form. Please try again.']);
        }
    }

    public function closeModal()
    {
        $this->showModal = false;
        $this->resetForm();
    }

    public function resetForm()
    {
        $this->reset(['name', 'email', 'phone', 'lead_date', 'services', 'budget', 'credits', 'detail', 'notes', 'lead_sheet_id']);
        $this->resetErrorBag();
        $this->isSaving = false;
    }

    #[On('sheet-created')]
    public function refreshSheets()
    {
        try {
            $this->loadSheets();
        } catch (\Exception $e) {
            Log::error('Error refreshing sheets: ' . $e->getMessage());
        }
    }

    public function loadSheets()
    {
        try {
            if (auth()->check() && auth()->user()->canCreateSheets()) {
                $this->sheets = LeadSheet::where('created_by', auth()->id())
                    ->orderBy('created_at', 'desc')
                    ->get();
            } else {
                $this->sheets = [];
            }
        } catch (\Exception $e) {
            Log::error('Error loading sheets: ' . $e->getMessage());
            $this->sheets = [];
        }
    }

    public function save()
    {
        try {
            $this->isSaving = true;

            if (!auth()->check()) {
                $this->dispatch('show-toast', ['type' => 'error', 'message' => 'You must be logged in to create leads.']);
                $this->isSaving = false;
                return;
            }

            if (!auth()->user()->canCreateLeads()) {
                $this->dispatch('show-toast', ['type' => 'error', 'message' => 'You do not have permission to create leads.']);
                $this->isSaving = false;
                return;
            }

            $rules = $this->rules;
            if (auth()->user()->isScrapper()) {
                $rules['lead_sheet_id'] = 'required|exists:lead_sheets,id';
            }
            
            $this->validate($rules);

            DB::beginTransaction();

            try {
                $leadData = [
                    'created_by' => auth()->id(),
                    'name' => trim($this->name),
                    'email' => $this->email ? trim($this->email) : null,
                    'phone' => $this->phone ? trim($this->phone) : null,
                    'lead_date' => $this->lead_date ?: null,
                    'services' => $this->services ? trim($this->services) : null,
                    'budget' => $this->budget ? trim($this->budget) : null,
                    'credits' => $this->credits ? trim($this->credits) : null,
                    'detail' => $this->detail ? trim($this->detail) : null,
                    'notes' => $this->notes ? trim($this->notes) : null,
                    'status' => 'no response',
                ];

                if ($this->lead_sheet_id) {
                    // Verify sheet exists and belongs to user
                    $sheet = LeadSheet::where('id', $this->lead_sheet_id)
                        ->where('created_by', auth()->id())
                        ->first();
                    
                    if (!$sheet) {
                        throw new \Exception('Selected sheet not found or access denied.');
                    }
                    
                    $leadData['lead_sheet_id'] = $this->lead_sheet_id;
                }

                $lead = Lead::create($leadData);

                // Notify all sales users efficiently
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
                    // Dispatch event to refresh notification bells for all users
                    $this->dispatch('notification-created');
                }

                DB::commit();

                // Dispatch events to refresh leads list and notifications
                $this->dispatch('lead-created');
                
                // Reset form
                $this->resetForm();
                
                // Close modal
                $this->closeModal();
                
                // Show success message
                $this->dispatch('show-toast', ['type' => 'success', 'message' => 'Lead created successfully! Sales team has been notified.']);
                request()->session()->flash('message', 'Lead created successfully! Sales team has been notified.');

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->isSaving = false;
            throw $e;
        } catch (\Exception $e) {
            $this->isSaving = false;
            Log::error('Error creating lead: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Failed to create lead. Please try again.']);
        }
    }

    // No render method needed for anonymous components
};
?>

<div>
    <!-- Modal Overlay -->
    <div 
        x-data="{ 
            show: @entangle('showModal').live,
            close() { 
                $wire.closeModal();
            }
        }"
        x-show="show"
        x-cloak
        @keydown.escape.window="close()"
        class="fixed inset-0 z-50 overflow-y-auto"
        style="display: none;"
        wire:ignore.self
    >
        <!-- Background overlay -->
        <div 
            x-show="show"
            x-transition:enter="ease-out duration-300"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="ease-in duration-200"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity"
            @click="close()"
        ></div>

        <!-- Modal -->
        <div class="flex min-h-full items-center justify-center p-4 sm:p-6">
            <div 
                x-show="show"
                x-transition:enter="ease-out duration-300"
                x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave="ease-in duration-200"
                x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                @click.away="close()"
                class="relative w-full transform overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-black/5 transition-all sm:my-8 sm:max-w-3xl"
            >
                <!-- Modal Header -->
                <div class="bg-gradient-to-r from-slate-50 via-white to-slate-50 px-6 py-5 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-xl font-bold text-gray-900">Create New Lead</h3>
                            <p class="text-sm text-gray-600 mt-1">Add a new lead to the system</p>
                        </div>
                        <button 
                            wire:click="closeModal"
                            type="button"
                            class="text-gray-400 hover:text-gray-600 transition-colors p-2 rounded-full hover:bg-white/70"
                        >
                            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Modal Body -->
                <form wire:submit="save" class="bg-white px-6 sm:px-8 py-6 sm:py-7">
                    <div class="space-y-6 max-h-[70vh] overflow-y-auto pr-1">
                        @if(auth()->user()->canCreateSheets())
                            <div>
                                <label for="lead_sheet_id" class="block text-sm font-semibold text-gray-700 mb-2">
                                    Sheet
                                    @if(auth()->user()->isScrapper())
                                        <span class="text-red-500">*</span>
                                    @endif
                                </label>
                                <select
                                    id="lead_sheet_id"
                                    wire:model="lead_sheet_id"
                                    wire:loading.attr="disabled"
                                    class="w-full px-4 py-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white @error('lead_sheet_id') border-red-500 @enderror disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    <option value="">Select a sheet...</option>
                                    @foreach($sheets as $sheet)
                                        <option value="{{ $sheet->id }}">{{ $sheet->name }}</option>
                                    @endforeach
                                </select>
                                @error('lead_sheet_id') <span class="text-red-500 text-sm mt-1 block">{{ $message }}</span> @enderror
                            </div>
                        @endif

                        <div>
                            <label for="name" class="block text-sm font-semibold text-gray-700 mb-2">
                                Name <span class="text-red-500">*</span>
                            </label>
                            <input 
                                type="text" 
                                id="name"
                                wire:model="name"
                                wire:loading.attr="disabled"
                                class="w-full px-4 py-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white @error('name') border-red-500 @enderror disabled:opacity-50 disabled:cursor-not-allowed"
                                placeholder="Enter lead name"
                            >
                            @error('name') <span class="text-red-500 text-sm mt-1 block">{{ $message }}</span> @enderror
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <div>
                                <label for="email" class="block text-sm font-semibold text-gray-700 mb-2">Email</label>
                                <input 
                                    type="email" 
                                    id="email"
                                    wire:model="email" 
                                    class="w-full px-4 py-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white @error('email') border-red-500 @enderror"
                                    placeholder="email@example.com"
                                >
                                @error('email') <span class="text-red-500 text-sm mt-1 block">{{ $message }}</span> @enderror
                            </div>

                            <div>
                                <label for="phone" class="block text-sm font-semibold text-gray-700 mb-2">Phone</label>
                                <input 
                                    type="text" 
                                    id="phone"
                                    wire:model="phone" 
                                    class="w-full px-4 py-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white @error('phone') border-red-500 @enderror"
                                    placeholder="+1 (555) 000-0000"
                                >
                                @error('phone') <span class="text-red-500 text-sm mt-1 block">{{ $message }}</span> @enderror
                            </div>
                        </div>

                        <div>
                            <label for="lead_date" class="block text-sm font-semibold text-gray-700 mb-2">Lead Date</label>
                            <input 
                                type="date" 
                                id="lead_date"
                                wire:model="lead_date" 
                                class="w-full px-4 py-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white @error('lead_date') border-red-500 @enderror"
                                placeholder="Select lead date"
                            >
                            @error('lead_date') <span class="text-red-500 text-sm mt-1 block">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label for="services" class="block text-sm font-semibold text-gray-700 mb-2">Service</label>
                            <input 
                                type="text" 
                                id="services"
                                wire:model="services" 
                                class="w-full px-4 py-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white @error('services') border-red-500 @enderror"
                                placeholder="Service"
                            >
                            @error('services') <span class="text-red-500 text-sm mt-1 block">{{ $message }}</span> @enderror
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <div>
                                <label for="budget" class="block text-sm font-semibold text-gray-700 mb-2">Budget</label>
                                <input 
                                    type="text" 
                                    id="budget"
                                    wire:model="budget" 
                                    class="w-full px-4 py-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white @error('budget') border-red-500 @enderror"
                                    placeholder="Budget"
                                >
                                @error('budget') <span class="text-red-500 text-sm mt-1 block">{{ $message }}</span> @enderror
                            </div>

                            <div>
                                <label for="credits" class="block text-sm font-semibold text-gray-700 mb-2">Credits</label>
                                <input 
                                    type="text" 
                                    id="credits"
                                    wire:model="credits" 
                                    class="w-full px-4 py-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white @error('credits') border-red-500 @enderror"
                                    placeholder="Credits"
                                >
                                @error('credits') <span class="text-red-500 text-sm mt-1 block">{{ $message }}</span> @enderror
                            </div>
                        </div>

                        <div>
                            <label for="detail" class="block text-sm font-semibold text-gray-700 mb-2">Business Description</label>
                            <textarea 
                                id="detail"
                                wire:model="detail" 
                                rows="4"
                                class="w-full px-4 py-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 resize-none bg-white @error('detail') border-red-500 @enderror"
                                placeholder="Business description"
                            ></textarea>
                            @error('detail') <span class="text-red-500 text-sm mt-1 block">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label for="notes" class="block text-sm font-semibold text-gray-700 mb-2">Additional Comments</label>
                            <textarea 
                                id="notes"
                                wire:model="notes" 
                                rows="4"
                                class="w-full px-4 py-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 resize-none bg-white @error('notes') border-red-500 @enderror"
                                placeholder="Additional comments"
                            ></textarea>
                            @error('notes') <span class="text-red-500 text-sm mt-1 block">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <!-- Modal Footer -->
                    <div class="mt-6 flex flex-col-reverse sm:flex-row gap-3 sm:justify-end border-t border-gray-100 pt-4">
                        <button 
                            type="button"
                            wire:click="closeModal"
                            class="px-5 py-2.5 bg-white border border-gray-200 hover:bg-gray-50 text-gray-700 rounded-xl font-semibold transition-colors"
                        >
                            Cancel
                        </button>
                        <button 
                            type="submit" 
                            wire:loading.attr="disabled"
                            wire:target="save"
                            class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-semibold shadow-sm hover:shadow-md transition-all disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center space-x-2 min-w-[120px]"
                        >
                            <span wire:loading.remove wire:target="save">Create Lead</span>
                            <span wire:loading wire:target="save" class="flex items-center space-x-2">
                                <svg class="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <span>Saving...</span>
                            </span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
