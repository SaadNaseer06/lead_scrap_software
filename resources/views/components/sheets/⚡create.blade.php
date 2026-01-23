<?php

use App\Models\LeadSheet;
use Livewire\Component;

new class extends Component
{
    public $name = '';

    protected $rules = [
        'name' => 'required|string|max:255|unique:lead_sheets,name',
    ];

    public function save()
    {
        if (!auth()->user()->isScrapper()) {
            abort(403);
        }

        $this->validate();

        LeadSheet::create([
            'name' => $this->name,
            'created_by' => auth()->id(),
        ]);

        $this->reset('name');

        $this->dispatch('sheet-created');
        request()->session()->flash('message', 'Sheet created successfully.');
    }
};
?>

<div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
    <form wire:submit="save" class="flex flex-col sm:flex-row gap-3 items-start sm:items-end">
        <div class="flex-1 w-full">
            <label for="sheet_name" class="block text-sm font-semibold text-gray-700 mb-2">Sheet Name</label>
            <input
                type="text"
                id="sheet_name"
                wire:model="name"
                class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 @error('name') border-red-500 @enderror"
                placeholder="e.g. March 2026 Leads"
            >
            @error('name') <span class="text-red-500 text-sm mt-1 block">{{ $message }}</span> @enderror
        </div>
        <button
            type="submit"
            class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold shadow-sm hover:shadow-md transition-all"
        >
            Create Sheet
        </button>
    </form>
</div>
