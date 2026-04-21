<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Lead Management System' }}</title>
    <x-favicon-links />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-gray-50">
    @auth
        <nav class="bg-white shadow-lg">
            <div class="max-w-9xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between h-16">
                    <div class="flex">
                        <div class="flex-shrink-0 flex items-center">
                            <a href="{{ route('dashboard') }}" class="text-xl font-bold text-blue-600">Lead Manager</a>
                        </div>
                        <div class="hidden sm:ml-6 sm:flex sm:space-x-8">
                            <a href="{{ route('dashboard') }}" class="border-blue-500 text-gray-900 inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium">
                                Dashboard
                            </a>
                            <a href="{{ route('leads.index') }}" class="border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium">
                                Leads
                            </a>
                            <a href="{{ route('sheets.index') }}" class="{{ request()->routeIs('sheets.*') ? 'border-blue-500 text-gray-900' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' }} inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium">
                                Sheets
                            </a>
                            @if(auth()->user()->isAdmin())
                                <a href="{{ route('users.index') }}" class="{{ request()->routeIs('users.*') ? 'border-blue-500 text-gray-900' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' }} inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium">
                                    Users
                                </a>
                                <a href="{{ route('teams.index') }}" class="{{ request()->routeIs('teams.*') ? 'border-blue-500 text-gray-900' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' }} inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium">
                                    Teams
                                </a>
                            @endif
                        </div>
                    </div>
                    <div class="flex items-center space-x-4">
                        <livewire:notifications.bell />
                        <div class="flex items-center space-x-2">
                            <span class="text-sm text-gray-700">{{ auth()->user()->name }}</span>
                            <span class="px-2 py-1 text-xs font-semibold rounded-full 
                                @if(auth()->user()->role === 'admin') bg-purple-100 text-purple-800
                                @elseif(auth()->user()->role === 'upsale') bg-blue-100 text-blue-800
                                @elseif(auth()->user()->role === 'front_sale') bg-amber-100 text-amber-800
                                @else bg-green-100 text-green-800
                                @endif">
                                {{ ucwords(str_replace('_', ' ', auth()->user()->role)) }}
                            </span>
                        </div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="text-gray-500 hover:text-gray-700 text-sm font-medium">
                                Logout
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </nav>
    @endauth

    <main class="py-6">
        @if(session('message'))
            <div class="max-w-9xl mx-auto px-4 sm:px-6 lg:px-8 mb-4">
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative" role="alert">
                    <span class="block sm:inline">{{ session('message') }}</span>
                </div>
            </div>
        @endif

        {{ $slot }}
    </main>

    <!-- Toast Notification Container -->
    <div id="toast-container" class="fixed top-4 right-4 z-50 space-y-2" style="z-index: 9999;"></div>

    @livewireScripts
    
    <script>
        // Toast Notification System
        function showToast(message, type = 'info') {
            if (!message) return;

            const container = document.getElementById('toast-container');
            if (!container) return;

            const toast = document.createElement('div');
            const bgColors = {
                'success': 'bg-green-500',
                'error': 'bg-red-500',
                'warning': 'bg-yellow-500',
                'info': 'bg-blue-500'
            };
            const bgColor = bgColors[type] || bgColors.info;

            toast.className = `${bgColor} text-white px-6 py-4 rounded-lg shadow-lg flex items-center justify-between min-w-[300px] max-w-[500px] transform transition-all duration-300 translate-x-full opacity-0`;
            toast.innerHTML = `
                <span class="flex-1">${message}</span>
                <button onclick="this.parentElement.remove()" class="ml-4 text-white hover:text-gray-200 focus:outline-none">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            `;

            container.appendChild(toast);

            // Animate in
            setTimeout(() => {
                toast.classList.remove('translate-x-full', 'opacity-0');
            }, 10);

            // Auto remove after 5 seconds
            setTimeout(() => {
                toast.classList.add('translate-x-full', 'opacity-0');
                setTimeout(() => {
                    if (toast.parentElement) {
                        toast.remove();
                    }
                }, 300);
            }, 5000);
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Listen for browser events
            window.addEventListener('show-toast', function(event) {
                const { type = 'info', message = '' } = event.detail || {};
                showToast(message, type);
            });
        });

        // Listen for Livewire events (works after Livewire loads)
        document.addEventListener('livewire:init', () => {
            Livewire.on('show-toast', (data) => {
                const message = typeof data === 'string' ? data : (data?.message || '');
                const type = data?.type || 'info';
                showToast(message, type);
            });
        });
    </script>
</body>
</html>
