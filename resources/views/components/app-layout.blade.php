@props(['title' => 'Lead Management System'])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-gray-50">
    @auth
        <nav class="bg-white shadow-md border-b border-gray-200 sticky top-0 z-50">
            <div class="max-w-9xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between h-16">
                    <div class="flex items-center">
                        <div class="flex-shrink-0 flex items-center">
                            <a href="{{ route('dashboard') }}" class="flex items-center space-x-2">
                                <div class="w-9 h-9 bg-blue-600 rounded-lg flex items-center justify-center">
                                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                    </svg>
                                </div>
                                <span class="text-xl font-bold text-gray-900">LeadPro</span>
                            </a>
                        </div>
                        <div class="hidden sm:ml-8 sm:flex sm:space-x-1">
                            <a href="{{ route('dashboard') }}" class="inline-flex items-center px-4 py-2 rounded-lg text-sm font-medium transition-colors {{ request()->routeIs('dashboard') ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:bg-gray-100' }}">
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                                </svg>
                                Dashboard
                            </a>
                            <a href="{{ route('leads.index') }}" class="inline-flex items-center px-4 py-2 rounded-lg text-sm font-medium transition-colors {{ request()->routeIs('leads.*') ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:bg-gray-100' }}">
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                                </svg>
                                Leads
                            </a>
                            <a href="{{ route('sheets.index') }}" class="inline-flex items-center px-4 py-2 rounded-lg text-sm font-medium transition-colors {{ request()->routeIs('sheets.*') ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:bg-gray-100' }}">
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                </svg>
                                Sheets
                            </a>
                            @if(auth()->user()->isAdmin())
                                <a href="{{ route('users.index') }}" class="inline-flex items-center px-4 py-2 rounded-lg text-sm font-medium transition-colors {{ request()->routeIs('users.*') ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:bg-gray-100' }}">
                                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                                    </svg>
                                    Users
                                </a>
                            @endif
                        </div>
                    </div>
                    <div class="flex items-center space-x-4">
                        <livewire:notifications.bell />
                        <div class="flex items-center space-x-3 bg-gray-50 rounded-lg px-3 py-2">
                            <div class="w-8 h-8 bg-blue-600 rounded-full flex items-center justify-center text-white font-semibold text-sm">
                                {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                            </div>
                            <div>
                                <span class="text-sm font-semibold text-gray-900 block">{{ auth()->user()->name }}</span>
                                <span class="text-xs px-2 py-0.5 rounded-full font-medium
                                    @if(auth()->user()->role === 'admin') bg-purple-100 text-purple-700
                                    @elseif(auth()->user()->role === 'upsale') bg-blue-100 text-blue-700
                                    @elseif(auth()->user()->role === 'front_sale') bg-amber-100 text-amber-700
                                    @else bg-emerald-100 text-emerald-700
                                    @endif">
                                    {{ ucwords(str_replace('_', ' ', auth()->user()->role)) }}
                                </span>
                            </div>
                        </div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="p-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition-colors">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                                </svg>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </nav>
    @endauth

    <main class="py-6">
        @if(session('message'))
            <div class="max-w-9xl mx-auto px-4 sm:px-6 lg:px-8 mb-6">
                <div class="bg-green-50 border-l-4 border-green-400 p-4 rounded-r-lg shadow-sm">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <svg class="h-5 w-5 text-green-400" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                            </svg>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-green-800">{{ session('message') }}</p>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{ $slot }}
    </main>

    @livewireScripts
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
</body>
</html>
