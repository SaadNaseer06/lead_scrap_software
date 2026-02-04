<?php

use App\Models\User;
use Livewire\Component;
use Illuminate\Support\Facades\Hash;

new class extends Component
{
    public $userId;
    public $name = '';
    public $email = '';
    public $password = '';
    public $password_confirmation = '';
    public $role = '';
    public $user;

    protected $listeners = ['closeModal' => 'close'];

    public function mount($userId)
    {
        $this->userId = $userId;
        $this->loadUser();
    }

    public function loadUser()
    {
        if (!$this->userId) {
            return;
        }
        
        try {
            $this->user = User::findOrFail($this->userId);
            $this->name = $this->user->name;
            $this->email = $this->user->email;
            $this->role = $this->user->role;
            $this->reset(['password', 'password_confirmation']);
        } catch (\Exception $e) {
            $this->dispatch('show-toast', [
                'type' => 'error',
                'message' => 'User not found.'
            ]);
            $this->dispatch('close-edit-modal');
        }
    }

    public function close()
    {
        // Only reset password fields, keep name/email/role changes until saved
        $this->reset(['password', 'password_confirmation']);
        $this->loadUser(); // Reload original data to discard unsaved changes
        $this->dispatch('close-edit-modal');
    }
    
    public function updated($propertyName)
    {
        // Clear validation errors when user starts typing again
        $this->resetValidation($propertyName);
    }

    public function save()
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email,' . $this->userId],
            'role' => ['required', 'string', 'in:admin,front_sale,upsale,scrapper'],
        ];

        if (!empty($this->password)) {
            $rules['password'] = ['required', 'string', 'min:8', 'confirmed'];
        }

        $this->validate($rules);

        try {
            $this->user->name = $this->name;
            $this->user->email = $this->email;
            $this->user->role = $this->role;

            if (!empty($this->password)) {
                $this->user->password = Hash::make($this->password);
            }

            $this->user->save();

            $this->dispatch('user-updated');
            $this->dispatch('show-toast', [
                'type' => 'success',
                'message' => 'User updated successfully.'
            ]);
            $this->reset(['password', 'password_confirmation']);
            $this->loadUser(); // Reload to show updated data
            $this->dispatch('close-edit-modal');
        } catch (\Exception $e) {
            $this->dispatch('show-toast', [
                'type' => 'error',
                'message' => 'Error updating user: ' . $e->getMessage()
            ]);
        }
    }

    public function render()
    {
        return view('components.users.⚡edit');
    }
};
?>

<div 
    x-data="{ show: true }"
    x-show="show"
    x-transition:enter="ease-out duration-300"
    x-transition:enter-start="opacity-0"
    x-transition:enter-end="opacity-100"
    x-transition:leave="ease-in duration-200"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    class="fixed inset-0 z-50 overflow-y-auto bg-black bg-opacity-50"
    wire:ignore.self
>
    <div class="flex min-h-full items-center justify-center p-4 sm:p-6">
        <div 
            x-show="show"
            x-transition:enter="ease-out duration-300"
            x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
            x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
            x-transition:leave="ease-in duration-200"
            x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
            x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
            @click.away="$wire.close()"
            class="relative w-full transform overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-black/5 transition-all sm:my-8 sm:max-w-3xl"
        >
            <!-- Modal Header -->
            <div class="bg-gradient-to-r from-blue-50 via-white to-blue-50 px-6 py-5 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-xl font-bold text-gray-900">Edit User</h3>
                        <p class="text-sm text-gray-600 mt-1">Update user information</p>
                    </div>
                    <button 
                        wire:click="close"
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
                <div class="space-y-6">
                    <!-- Name -->
                    <div>
                        <label for="edit_name" class="block text-sm font-semibold text-gray-700 mb-2">
                            Name <span class="text-red-500">*</span>
                        </label>
                        <input
                            type="text"
                            id="edit_name"
                            wire:model.defer="name"
                            required
                            class="w-full px-4 py-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white @error('name') border-red-500 @enderror"
                            placeholder="Enter full name"
                        >
                        @error('name')
                            <span class="text-red-500 text-sm mt-1 block">{{ $message }}</span>
                        @enderror
                    </div>

                    <!-- Email -->
                    <div>
                        <label for="edit_email" class="block text-sm font-semibold text-gray-700 mb-2">
                            Email <span class="text-red-500">*</span>
                        </label>
                        <input
                            type="email"
                            id="edit_email"
                            wire:model.defer="email"
                            required
                            class="w-full px-4 py-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white @error('email') border-red-500 @enderror"
                            placeholder="email@example.com"
                        >
                        @error('email')
                            <span class="text-red-500 text-sm mt-1 block">{{ $message }}</span>
                        @enderror
                    </div>

                    <!-- Password (Optional) -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div>
                            <label for="edit_password" class="block text-sm font-semibold text-gray-700 mb-2">
                                New Password <span class="text-gray-500 text-xs">(leave blank to keep current)</span>
                            </label>
                            <input
                                type="password"
                                id="edit_password"
                                wire:model.defer="password"
                                minlength="8"
                                class="w-full px-4 py-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white @error('password') border-red-500 @enderror"
                                placeholder="Minimum 8 characters"
                            >
                            @error('password')
                                <span class="text-red-500 text-sm mt-1 block">{{ $message }}</span>
                            @enderror
                        </div>

                        <div>
                            <label for="edit_password_confirmation" class="block text-sm font-semibold text-gray-700 mb-2">
                                Confirm New Password
                            </label>
                            <input
                                type="password"
                                id="edit_password_confirmation"
                                wire:model.defer="password_confirmation"
                                minlength="8"
                                class="w-full px-4 py-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white"
                                placeholder="Confirm password"
                            >
                        </div>
                    </div>

                    <!-- Role -->
                    <div>
                        <label for="edit_role" class="block text-sm font-semibold text-gray-700 mb-2">
                            Role <span class="text-red-500">*</span>
                        </label>
                        <select
                            id="edit_role"
                            wire:model.defer="role"
                            required
                            class="w-full px-4 py-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white @error('role') border-red-500 @enderror"
                        >
                            <option value="">Select a role...</option>
                            <option value="admin">Admin</option>
                            <option value="front_sale">Front Sale</option>
                            <option value="upsale">Upsale</option>
                            <option value="scrapper">Scrapper</option>
                        </select>
                        @error('role')
                            <span class="text-red-500 text-sm mt-1 block">{{ $message }}</span>
                        @enderror
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="mt-8 flex items-center justify-end space-x-3 pt-6 border-t border-gray-200">
                    <button
                        type="button"
                        wire:click="close"
                        class="px-5 py-2.5 border border-gray-300 rounded-xl text-gray-700 font-semibold hover:bg-gray-50 transition-colors"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="save"
                        class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-semibold shadow-sm hover:shadow-md transition-all disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        <span wire:loading.remove wire:target="save">Update User</span>
                        <span wire:loading wire:target="save">Updating...</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
