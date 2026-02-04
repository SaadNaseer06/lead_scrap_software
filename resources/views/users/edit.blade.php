<x-app-layout title="Edit User">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <!-- Header -->
            <div class="bg-gradient-to-r from-blue-50 to-indigo-50 px-6 py-5 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">Edit User</h1>
                        <p class="text-sm text-gray-600 mt-1">Update user information</p>
                    </div>
                    <a href="{{ route('users.index') }}" class="text-gray-600 hover:text-gray-900 transition-colors">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </a>
                </div>
            </div>

            <!-- Form -->
            <form action="{{ route('users.update', $user) }}" method="POST" class="p-6 sm:p-8">
                @csrf
                @method('PUT')

                <div class="space-y-6">
                    <!-- Name -->
                    <div>
                        <label for="name" class="block text-sm font-semibold text-gray-700 mb-2">
                            Name <span class="text-red-500">*</span>
                        </label>
                        <input
                            type="text"
                            id="name"
                            name="name"
                            value="{{ old('name', $user->name) }}"
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
                        <label for="email" class="block text-sm font-semibold text-gray-700 mb-2">
                            Email <span class="text-red-500">*</span>
                        </label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            value="{{ old('email', $user->email) }}"
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
                            <label for="password" class="block text-sm font-semibold text-gray-700 mb-2">
                                New Password <span class="text-gray-500 text-xs">(leave blank to keep current)</span>
                            </label>
                            <input
                                type="password"
                                id="password"
                                name="password"
                                minlength="8"
                                class="w-full px-4 py-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white @error('password') border-red-500 @enderror"
                                placeholder="Minimum 8 characters"
                            >
                            @error('password')
                                <span class="text-red-500 text-sm mt-1 block">{{ $message }}</span>
                            @enderror
                        </div>

                        <div>
                            <label for="password_confirmation" class="block text-sm font-semibold text-gray-700 mb-2">
                                Confirm New Password
                            </label>
                            <input
                                type="password"
                                id="password_confirmation"
                                name="password_confirmation"
                                minlength="8"
                                class="w-full px-4 py-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white"
                                placeholder="Confirm password"
                            >
                        </div>
                    </div>

                    <!-- Role -->
                    <div>
                        <label for="role" class="block text-sm font-semibold text-gray-700 mb-2">
                            Role <span class="text-red-500">*</span>
                        </label>
                        <select
                            id="role"
                            name="role"
                            required
                            class="w-full px-4 py-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white @error('role') border-red-500 @enderror"
                        >
                            <option value="">Select a role...</option>
                            <option value="admin" {{ old('role', $user->role) === 'admin' ? 'selected' : '' }}>Admin</option>
                            <option value="front_sale" {{ old('role', $user->role) === 'front_sale' ? 'selected' : '' }}>Front Sale</option>
                            <option value="upsale" {{ old('role', $user->role) === 'upsale' ? 'selected' : '' }}>Upsale</option>
                            <option value="scrapper" {{ old('role', $user->role) === 'scrapper' ? 'selected' : '' }}>Scrapper</option>
                        </select>
                        @error('role')
                            <span class="text-red-500 text-sm mt-1 block">{{ $message }}</span>
                        @enderror
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="mt-8 flex items-center justify-end space-x-3 pt-6 border-t border-gray-200">
                    <a href="{{ route('users.index') }}" class="px-5 py-2.5 border border-gray-300 rounded-xl text-gray-700 font-semibold hover:bg-gray-50 transition-colors">
                        Cancel
                    </a>
                    <button
                        type="submit"
                        class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-semibold shadow-sm hover:shadow-md transition-all"
                    >
                        Update User
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
