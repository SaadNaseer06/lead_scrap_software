<?php

use App\Models\LeadSheet;
use Livewire\Component;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

new class extends Component
{
    public $name = '';

    public function save()
    {
        // Log the attempt
        Log::info('Sheet creation attempt', [
            'user_id' => auth()->id(),
            'user_name' => auth()->user()->name ?? 'Unknown',
            'sheet_name' => $this->name,
            'can_create' => auth()->user()->canCreateSheets() ?? false,
        ]);

        try {
            if (!auth()->check()) {
                Log::warning('Sheet creation failed: User not authenticated');
                $this->dispatch('show-toast', ['type' => 'error', 'message' => 'You must be logged in.']);
                return;
            }

            if (!auth()->user()->canCreateSheets()) {
                Log::warning('Sheet creation failed: User does not have permission', [
                    'user_id' => auth()->id(),
                    'role' => auth()->user()->role ?? 'Unknown'
                ]);
                $this->dispatch('show-toast', ['type' => 'error', 'message' => 'You do not have permission to create sheets.']);
                return;
            }

            // Validate the input - check for global uniqueness (matching database constraint)
            $this->validate([
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    \Illuminate\Validation\Rule::unique('lead_sheets', 'name')
                ],
            ], [
                'name.required' => 'Sheet name is required.',
                'name.unique' => 'A sheet with this name already exists. Please choose a different name.',
            ]);

            // Use transaction for safety
            DB::beginTransaction();

            try {
                // Create the sheet
                $sheet = LeadSheet::create([
                    'name' => trim($this->name),
                    'created_by' => auth()->id(),
                ]);

                if (!$sheet || !$sheet->id) {
                    throw new \Exception('Failed to create sheet in database - no ID returned.');
                }

                DB::commit();

                Log::info('Sheet created successfully', [
                    'sheet_id' => $sheet->id,
                    'sheet_name' => $sheet->name,
                    'user_id' => auth()->id(),
                ]);

                // Reset form
                $this->reset('name');
                $this->resetErrorBag();

                // Dispatch events to refresh other components
                $this->dispatch('sheet-created');
                
                // Show success message
                $this->dispatch('show-toast', ['type' => 'success', 'message' => 'Sheet created successfully!']);
                session()->flash('message', 'Sheet created successfully.');
                
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
            
        } catch (ValidationException $e) {
            // Re-throw validation exceptions so Livewire can handle them properly
            Log::warning('Sheet creation validation failed', [
                'errors' => $e->errors(),
                'user_id' => auth()->id(),
            ]);
            throw $e;
        } catch (\Illuminate\Database\QueryException $e) {
            // Handle database errors specifically
            Log::error('Database error creating sheet', [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'user_id' => auth()->id(),
                'sheet_name' => $this->name,
            ]);
            
            $errorMessage = 'Failed to create sheet. ';
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                $errorMessage .= 'A sheet with this name already exists.';
            } else {
                $errorMessage .= 'Please try again or contact support.';
            }
            
            $this->dispatch('show-toast', ['type' => 'error', 'message' => $errorMessage]);
            $this->addError('name', $errorMessage);
        } catch (\Exception $e) {
            Log::error('Error creating sheet', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => auth()->id(),
                'sheet_name' => $this->name,
                'trace' => $e->getTraceAsString()
            ]);
            
            $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Failed to create sheet. Please try again.']);
            $this->addError('name', 'An error occurred while creating the sheet.');
        }
    }
};
?>

<div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
    <form wire:submit="save" class="flex flex-col sm:flex-row gap-3 items-start sm:items-end">
        <div class="flex-1 w-full">
            <label for="sheet_name" class="block text-sm font-semibold text-gray-700 mb-2">
                Sheet Name <span class="text-red-500">*</span>
            </label>
            <input
                type="text"
                id="sheet_name"
                wire:model.defer="name"
                class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 @error('name') border-red-500 @enderror"
                placeholder="e.g. March 2026 Leads"
                autocomplete="off"
                required
            >
            @error('name') 
                <span class="text-red-500 text-sm mt-1 block">{{ $message }}</span> 
            @enderror
        </div>
        <button
            type="submit"
            wire:loading.attr="disabled"
            wire:target="save"
            class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold shadow-sm hover:shadow-md transition-all disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center space-x-2 min-w-[120px]"
        >
            <span wire:loading.remove wire:target="save">Create Sheet</span>
            <span wire:loading wire:target="save" class="flex items-center space-x-2">
                <svg class="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span>Creating...</span>
            </span>
        </button>
    </form>
</div>
