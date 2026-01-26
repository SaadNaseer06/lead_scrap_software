<x-app-layout>
    <div class="max-w-9xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900 mb-2">Welcome back, {{ auth()->user()->name }}!</h1>
            <p class="text-gray-600">Here's what's happening with your leads today</p>
        </div>

        @if(auth()->user()->canCreateSheets())
            <div class="mb-6">
                <h2 class="text-lg font-semibold text-gray-900 mb-3">Create Sheet</h2>
                <livewire:sheets.create />
            </div>
        @endif

        <livewire:dashboard.stats />

        @if(auth()->user()->canCreateSheets())
            <livewire:dashboard.sheets />
        @endif
        @if(!auth()->user()->isScrapper())
            <livewire:dashboard.recent-leads />
        @endif
    </div>
</x-app-layout>
