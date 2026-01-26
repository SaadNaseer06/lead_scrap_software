<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Lead Management System' }}</title>
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
                            @if(auth()->user()->isAdmin())
                                <a href="{{ route('users.index') }}" class="border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium">
                                    Users
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
                                @elseif(in_array(auth()->user()->role, ['sales', 'upsale'])) bg-blue-100 text-blue-800
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

    @livewireScripts
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
</body>
</html>
