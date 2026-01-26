<?php

use App\Models\Lead;
use App\Models\User;
use Livewire\Component;
use Livewire\Attributes\On;

new class extends Component
{
    public $name = '';
    public $email = '';
    public $phone = '';
    public $company = '';
    public $notes = '';
    public $lead_sheet_id = '';
    public $showModal = false;

    protected $rules = [
        'name' => 'required|string|max:255',
        'email' => 'nullable|email|max:255',
        'phone' => 'nullable|string|max:255',
        'company' => 'nullable|string|max:255',
        'notes' => 'nullable|string',
        'lead_sheet_id' => 'nullable|exists:lead_sheets,id',
    ];

    #[On('open-create-modal')]
    public function openModal()
    {
        $this->showModal = true;
        $this->resetForm();
    }

    public function closeModal()
    {
        $this->showModal = false;
        $this->resetForm();
    }

    public function resetForm()
    {
        $this->reset(['name', 'email', 'phone', 'company', 'notes', 'lead_sheet_id']);
        $this->resetErrorBag();
    }

    #[On('sheet-created')]
    public function refreshSheets()
    {
    }

    public function save()
    {
        if (!auth()->user()->canCreateLeads()) {
            abort(403);
        }

        $rules = $this->rules;
        if (auth()->user()->isScrapper()) {
            $rules['lead_sheet_id'] = 'required|exists:lead_sheets,id';
        }
        $this->validate($rules);

        $leadData = [
            'created_by' => auth()->id(),
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'company' => $this->company,
            'notes' => $this->notes,
            'status' => 'no response',
        ];

        if ($this->lead_sheet_id) {
            $leadData['lead_sheet_id'] = $this->lead_sheet_id;
        }

        $lead = Lead::create($leadData);

        // Notify all sales users
        $salesUsers = User::whereIn('role', ['sales', 'upsale', 'front_sale'])->get();
        foreach ($salesUsers as $user) {
            \App\Models\Notification::create([
                'user_id' => $user->id,
                'lead_id' => $lead->id,
                'type' => 'new_lead',
                'message' => "New lead '{$lead->name}' has been added by " . auth()->user()->name,
            ]);
        }

        // Dispatch events to refresh leads list and notifications
        $this->dispatch('lead-created');
        
        // Reset form
        $this->resetForm();
        
        // Close modal
        $this->closeModal();
        
        // Set session flash message - this will work with Livewire
        request()->session()->flash('message', 'Lead created successfully! Sales team has been notified.');
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
            class="fixed inset-0 bg-gray-900 bg-opacity-50 transition-opacity"
            @click="close()"
        ></div>

        <!-- Modal -->
        <div class="flex min-h-full items-center justify-center p-4">
            <div 
                x-show="show"
                x-transition:enter="ease-out duration-300"
                x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave="ease-in duration-200"
                x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                @click.away="close()"
                class="relative transform overflow-hidden rounded-lg bg-white shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-2xl"
            >
                <!-- Modal Header -->
                <div class="bg-gray-50 px-6 py-4 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-xl font-bold text-gray-900">Create New Lead</h3>
                            <p class="text-sm text-gray-600 mt-1">Add a new lead to the system</p>
                        </div>
                        <button 
                            wire:click="closeModal"
                            type="button"
                            class="text-gray-400 hover:text-gray-500 transition-colors p-1 rounded-lg hover:bg-gray-200"
                        >
                            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Modal Body -->
                <form wire:submit="save" class="bg-white px-6 py-6">
                    <div class="space-y-5">
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
                                    class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 @error('lead_sheet_id') border-red-500 @enderror"
                                >
                                    <option value="">Select a sheet...</option>
                                    @foreach(\App\Models\LeadSheet::where('created_by', auth()->id())->orderBy('created_at', 'desc')->get() as $sheet)
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
                                class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 @error('name') border-red-500 @enderror"
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
                                    class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 @error('email') border-red-500 @enderror"
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
                                    class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 @error('phone') border-red-500 @enderror"
                                    placeholder="+1 (555) 000-0000"
                                >
                                @error('phone') <span class="text-red-500 text-sm mt-1 block">{{ $message }}</span> @enderror
                            </div>
                        </div>

                        <div>
                            <label for="company" class="block text-sm font-semibold text-gray-700 mb-2">Company</label>
                            <input 
                                type="text" 
                                id="company"
                                wire:model="company" 
                                class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 @error('company') border-red-500 @enderror"
                                placeholder="Company name"
                            >
                            @error('company') <span class="text-red-500 text-sm mt-1 block">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label for="notes" class="block text-sm font-semibold text-gray-700 mb-2">Notes</label>
                            <textarea 
                                id="notes"
                                wire:model="notes" 
                                rows="4"
                                class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 resize-none @error('notes') border-red-500 @enderror"
                                placeholder="Additional notes about the lead..."
                            ></textarea>
                            @error('notes') <span class="text-red-500 text-sm mt-1 block">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <!-- Modal Footer -->
                    <div class="mt-6 flex gap-4 justify-end">
                        <button 
                            type="button"
                            wire:click="closeModal"
                            class="px-5 py-2.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg font-semibold transition-colors"
                        >
                            Cancel
                        </button>
                        <button 
                            type="submit" 
                            class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold shadow-sm hover:shadow-md transition-all"
                        >
                            Create Lead
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
