<?php

use App\Models\LeadSheet;
use App\Models\Team;
use Livewire\Component;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

new class extends Component
{
    public $name = '';
    public $teamIds = [];
    public $successMessage = '';
    public $formKey = 1;

    public function updated()
    {
        if ($this->successMessage !== '') {
            $this->successMessage = '';
        }
    }

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
            $rules = [
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    \Illuminate\Validation\Rule::unique('lead_sheets', 'name')
                ],
                'teamIds' => Team::exists() ? ['array', 'min:1'] : ['array'],
                'teamIds.*' => 'exists:teams,id',
            ];
            $this->validate($rules, [
                'name.required' => 'Sheet name is required.',
                'name.unique' => 'A sheet with this name already exists. Please choose a different name.',
                'teamIds.min' => 'Select at least one team so sales users can see this sheet.',
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

                $sheet->teams()->sync(array_map('intval', $this->teamIds ?? []));

                DB::commit();

                Log::info('Sheet created successfully', [
                    'sheet_id' => $sheet->id,
                    'sheet_name' => $sheet->name,
                    'user_id' => auth()->id(),
                ]);

                // Reset form
                $this->reset(['name', 'teamIds']);
                $this->resetErrorBag();
                $this->successMessage = 'Sheet created successfully!';
                $this->formKey++;

                // Dispatch events to refresh other components
                $this->dispatch('sheet-created', sheetId: $sheet->id);
                
                // Show success message
                $this->dispatch('show-toast', ['type' => 'success', 'message' => 'Sheet created successfully!']);
                
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

    public function render()
    {
        $teams = Team::orderBy('name')->get(['id', 'name']);
        return view('components.sheets.⚡create', ['teams' => $teams]);
    }
};
?>

<div class="bg-white rounded-lg shadow-sm border-2 border-blue-200 p-4">
    @if($successMessage)
        <div class="mb-4 px-4 py-3 bg-emerald-50 border border-emerald-200 rounded-lg text-emerald-800">
            {{ $successMessage }}
        </div>
    @endif
    @if($teams->isEmpty())
        <div class="px-4 py-3 bg-amber-50 border border-amber-200 rounded-lg text-amber-800">
            <p class="font-medium">Cannot create a sheet yet</p>
            <p class="text-sm mt-1">No teams exist. Ask your admin to create teams and add sales users first. Sheets must be assigned to a team so only that team's members see the leads.</p>
            <a href="{{ route('teams.index') }}" class="inline-block mt-3 text-sm font-medium text-amber-700 underline hover:text-amber-900">Go to Teams (admin only)</a>
        </div>
    @else
        <form wire:submit="save" wire:key="sheet-create-form-{{ $formKey }}" class="space-y-4">
            <div>
                <label for="sheet_name" class="block text-sm font-semibold text-gray-700 mb-2">
                    Sheet Name <span class="text-red-500">*</span>
                </label>
                <input
                    type="text"
                    id="sheet_name"
                    wire:model.defer="name"
                    class="w-full max-w-md px-4 py-2.5 border-2 border-blue-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-600 @error('name') border-red-500 @enderror"
                    placeholder="e.g. March 2026 Leads"
                    autocomplete="off"
                    required
                >
                @error('name') 
                    <span class="text-red-500 text-sm mt-1 block">{{ $message }}</span> 
                @enderror
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">
                    Assign to Team(s) <span class="text-red-500">*</span>
                </label>
                <p class="text-xs text-gray-600 mb-2">Select at least one team. Only members of selected teams will see this sheet and its leads.</p>
                <div class="flex flex-wrap gap-3 p-3 border-2 border-blue-200 rounded-lg bg-gray-50 max-h-36 overflow-y-auto @error('teamIds') border-red-500 @enderror">
                    @foreach($teams as $team)
                        <label class="inline-flex items-center gap-2 cursor-pointer px-2 py-1 rounded hover:bg-gray-100">
                            <input type="checkbox" wire:model.defer="teamIds" value="{{ $team->id }}" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                            <span class="text-sm text-gray-800">{{ $team->name }}</span>
                        </label>
                    @endforeach
                </div>
                @error('teamIds') 
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
    @endif
</div>
