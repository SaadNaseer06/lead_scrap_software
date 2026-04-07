<?php

use App\Models\Team;
use App\Models\User;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\On;

new class extends Component
{
    use WithPagination;

    public $search = '';
    public $showForm = false;
    public $editingId = null;
    public $name = '';
    public $description = '';
    public $userIds = [];
    public $successMessage = '';

    protected $queryString = ['search' => ['except' => '']];

    public function updatingSearch()
    {
        $this->resetPage();
    }

    #[On('team-created')]
    #[On('team-updated')]
    #[On('team-deleted')]
    public function refreshList()
    {
        $this->resetPage();
        $this->closeForm();
    }

    public function openCreate()
    {
        $this->editingId = null;
        $this->name = '';
        $this->description = '';
        $this->userIds = [];
        $this->showForm = true;
        $this->resetValidation();
        $this->successMessage = '';
    }

    public function openEdit($teamId)
    {
        $team = Team::with('users')->find($teamId);
        if (!$team) return;
        $this->editingId = $team->id;
        $this->name = $team->name;
        $this->description = $team->description ?? '';
        $this->userIds = $team->users->pluck('id')->map(fn ($id) => (string) $id)->toArray();
        $this->showForm = true;
        $this->resetValidation();
        $this->successMessage = '';
    }

    public function closeForm()
    {
        $this->showForm = false;
        $this->editingId = null;
        $this->name = '';
        $this->description = '';
        $this->userIds = [];
    }

    public function save()
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'userIds' => 'array',
            'userIds.*' => 'exists:users,id',
        ], [
            'name.required' => 'Team name is required.',
        ]);

        if ($this->editingId) {
            $team = Team::findOrFail($this->editingId);
            $team->update([
                'name' => trim($this->name),
                'description' => trim($this->description) ?: null,
            ]);
            $team->users()->sync(array_map('intval', $this->userIds));
            $this->dispatch('team-updated');
            $this->dispatch('show-toast', ['type' => 'success', 'message' => 'Team updated successfully.']);
            $this->successMessage = 'Team updated successfully.';
        } else {
            $team = Team::create([
                'name' => trim($this->name),
                'description' => trim($this->description) ?: null,
            ]);
            $team->users()->sync(array_map('intval', $this->userIds));
            $this->dispatch('team-created');
            $this->dispatch('show-toast', ['type' => 'success', 'message' => 'Team created successfully.']);
            $this->successMessage = 'Team created successfully.';
        }

        $this->closeForm();
    }

    public function deleteTeam($teamId)
    {
        $team = Team::find($teamId);
        if (!$team) return;
        $team->users()->detach();
        $team->leadSheets()->detach();
        $team->delete();
        $this->dispatch('team-deleted');
        $this->dispatch('show-toast', ['type' => 'success', 'message' => 'Team deleted.']);
        $this->successMessage = 'Team deleted.';
    }

    public function render()
    {
        $query = Team::withCount('users');

        if ($this->search) {
            $query->where('name', 'like', '%' . $this->search . '%');
        }

        $teams = $query->orderBy('name')->paginate(15);
        $users = User::whereIn('role', ['front_sale', 'upsale'])->orderBy('name')->get(['id', 'name', 'email', 'role']);

        return view('components.teams.⚡index', [
            'teams' => $teams,
            'users' => $users,
        ]);
    }
};
?>

<div class="max-w-9xl mx-auto px-4 sm:px-6 lg:px-8">
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <div class="bg-gradient-to-r from-blue-50 to-indigo-50 px-6 py-5 border-b border-gray-200">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Teams</h1>
                    <p class="text-sm text-gray-600 mt-1">Control who sees which leads. Add sales users to teams; when a sheet is assigned to a team, only that team’s members see it.</p>
                </div>
                @if(!$showForm)
                    <button type="button" wire:click="openCreate"
                        class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg shadow-sm hover:shadow-md transition-all">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                        Add Team
                    </button>
                @endif
            </div>
        </div>

        <div class="px-6 py-3 bg-blue-50 border-b border-blue-100">
            <p class="text-sm text-blue-800"><strong>How it works:</strong> 1) Create a team and add sales users (Front Sale / Upsale). 2) When a scrapper creates a sheet, they choose which team(s) can see it. 3) Only those team members will see that sheet and its leads on the Leads page.</p>
        </div>

        @if($successMessage)
            <div class="px-6 py-4 bg-emerald-50 border-b border-emerald-200 text-emerald-800">
                {{ $successMessage }}
            </div>
        @endif

        @if($showForm)
            <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-800 mb-3">{{ $editingId ? 'Edit Team' : 'New Team' }}</h2>
                <form wire:submit="save" class="space-y-4">
                    <div>
                        <label for="team_name" class="block text-sm font-medium text-gray-700">Team name <span class="text-red-500">*</span></label>
                        <input type="text" id="team_name" wire:model.defer="name"
                            class="mt-1 w-full max-w-md px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 @error('name') border-red-500 @enderror"
                            placeholder="e.g. Sales Team A">
                        @error('name') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label for="team_description" class="block text-sm font-medium text-gray-700">Description (optional)</label>
                        <input type="text" id="team_description" wire:model.defer="description"
                            class="mt-1 w-full max-w-md px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                            placeholder="Short description">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Members (Front Sale & Upsale only)</label>
                        <div class="max-w-md border border-gray-300 rounded-lg p-3 bg-white max-h-48 overflow-y-auto space-y-2">
                            @foreach($users as $user)
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" wire:model.defer="userIds" value="{{ $user->id }}"
                                        class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                    <span class="text-sm text-gray-800">{{ $user->name }}</span>
                                    <span class="text-xs text-gray-500">({{ $user->email }})</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" wire:loading.attr="disabled"
                            class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg disabled:opacity-50">
                            {{ $editingId ? 'Update Team' : 'Create Team' }}
                        </button>
                        <button type="button" wire:click="closeForm"
                            class="px-4 py-2 bg-gray-200 hover:bg-gray-300 text-gray-800 font-medium rounded-lg">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        @endif

        <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
            <div class="relative max-w-md">
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search teams..."
                    class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                </svg>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Team</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Members</th>
                        <th class="px-6 py-3 text-right text-xs font-semibold text-gray-700 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($teams as $team)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4">
                                <div class="font-semibold text-gray-900">{{ $team->name }}</div>
                                @if($team->description)
                                    <div class="text-sm text-gray-500 mt-0.5">{{ $team->description }}</div>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600">{{ $team->users_count }} user(s)</td>
                            <td class="px-6 py-4 text-right">
                                <button type="button" wire:click="openEdit({{ $team->id }})" class="text-blue-600 hover:text-blue-900 mr-3">Edit</button>
                                <button type="button" onclick="if(confirm('Delete this team? Sheets will be unlinked.')) { @this.call('deleteTeam', {{ $team->id }}) }" class="text-red-600 hover:text-red-900">Delete</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="px-6 py-12 text-center text-gray-500">
                                No teams yet. Create one and assign users.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($teams->hasPages())
            <div class="bg-gray-50 px-6 py-4 border-t border-gray-200">{{ $teams->links() }}</div>
        @endif
    </div>
</div>
