<?php

use App\Models\Lead;
use App\Models\LeadGroup;
use App\Models\LeadSheet;
use App\Services\NotificationService;
use Carbon\Carbon;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\WithFileUploads;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Builder;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

new class extends Component
{
    use WithPagination, WithFileUploads;

    public $search = '';
    public $statusFilter = '';
    public $sheetFilter = '';
    public $groupFilter = '';
    public $newGroupName = '';
    public $viewMode = 'table'; 
    public $importSheetId = '';
    public $importFile;
    public $leadsData = [];
    public $pendingCreates = [];
    public $draftLeadRows = [];
    public $editingGroupId = null;
    public $editingGroupName = '';
    public $addGroupFormKey = 1;
    public $tableStateSignature = '';
    public $hasMoreTableRows = false;
    public $tableCursorId = null;
    public $tableLoadedCount = 0;
    private $rowUniqueIds = [];
    protected $queryString = [
        'search' => ['except' => ''],
        'statusFilter' => ['except' => ''],
        'sheetFilter' => ['except' => ''],
        'groupFilter' => ['except' => ''],
        'viewMode' => ['except' => 'table'],
    ];

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function updatedSearch()
    {
        if (auth()->check() && $this->viewMode === 'table' && $this->canEditAcrossAllSheets()) {
            $this->resetTableViewport();
            $this->loadSheetLeads();
        }
    }

    public function updatingStatusFilter()
    {
        $this->resetPage();
    }

    public function updatedStatusFilter()
    {
        if (auth()->check() && $this->viewMode === 'table' && $this->canEditAcrossAllSheets()) {
            $this->resetTableViewport();
            $this->loadSheetLeads();
        }
    }

    public function updatingSheetFilter()
    {
        $this->resetPage();
    }

    public function updatedSheetFilter()
    {
        if (!$this->sheetFilter) {
            if (auth()->check() && auth()->user()->isScrapper()) {
                $this->importSheetId = '';
            }
            $this->groupFilter = '';
            $this->editingGroupId = null;
            $this->editingGroupName = '';
            $this->resetTableViewport();
            $this->loadSheetLeads();
            return;
        }

        $firstGroupId = LeadGroup::where('lead_sheet_id', $this->sheetFilter)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->value('id');

        if (auth()->check() && auth()->user()->isScrapper()) {
            $this->importSheetId = (string) $this->sheetFilter;
        }

        $this->groupFilter = $firstGroupId ? (string) $firstGroupId : null;
        $this->editingGroupId = null;
        $this->editingGroupName = '';
        $this->resetTableViewport();
        $this->resetPage();
        $this->loadSheetLeads();
    }

    public function updatedGroupFilter()
    {
        $this->resetTableViewport();
        $this->editingGroupId = null;
        $this->editingGroupName = '';
        $this->resetPage();
        $this->loadSheetLeads();
    }

    public function selectGroup($groupId)
    {
        if (!$this->sheetFilter) {
            return;
        }

        $group = LeadGroup::where('id', $groupId)
            ->where('lead_sheet_id', $this->sheetFilter)
            ->first();

        if (!$group) {
            $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Tab not found for this sheet.']);
            return;
        }

        $this->groupFilter = (string) $groupId;
        $this->resetTableViewport();
        $this->resetPage();
        $this->loadSheetLeads();
    }

    public function addGroup()
    {
        $this->validate(['newGroupName' => 'required|string|max:255'], ['newGroupName.required' => 'Table name is required.']);
        $sheetId = (int) $this->sheetFilter;
        $sheet = LeadSheet::where('id', $sheetId)->where('created_by', auth()->id())->first();
        if (!$sheet) {
            $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Sheet not found or access denied.']);
            return;
        }
        $maxOrder = LeadGroup::where('lead_sheet_id', $sheetId)->max('sort_order') ?? 0;
        $group = LeadGroup::create([
            'lead_sheet_id' => $sheetId,
            'name' => trim($this->newGroupName),
            'sort_order' => $maxOrder + 1,
        ]);
        $this->newGroupName = '';
        $this->groupFilter = (string) $group->id;
        $this->resetValidation();
        $this->addGroupFormKey++;
        $this->resetTableViewport();
        $this->loadSheetLeads();
        $this->dispatch('show-toast', ['type' => 'success', 'message' => 'Tab added.']);
    }

    public function removeGroup($groupId)
    {
        $group = LeadGroup::find($groupId);
        if (!$group) return;
        $sheet = $group->leadSheet;
        if (!$sheet || $sheet->created_by !== auth()->id()) {
            $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Access denied.']);
            return;
        }
        $group->leads()->delete();
        $group->delete();
        if ($this->groupFilter == $groupId) $this->groupFilter = '';
        $this->resetTableViewport();
        $this->loadSheetLeads();
        $this->dispatch('show-toast', ['type' => 'success', 'message' => 'Tab removed. Leads in it were soft-deleted.']);
    }


    public function startEditingGroup($groupId)
    {
        $group = LeadGroup::find($groupId);
        if (!$group) {
            return;
        }

        $sheet = $group->leadSheet;
        if (!$sheet || $sheet->created_by !== auth()->id()) {
            $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Access denied.']);
            return;
        }

        $this->groupFilter = (string) $groupId;
        $this->editingGroupId = $groupId;
        $this->editingGroupName = $group->name;
    }

    public function cancelEditingGroup()
    {
        $this->editingGroupId = null;
        $this->editingGroupName = '';
    }

    public function updateGroup()
    {
        if (!$this->editingGroupName || trim($this->editingGroupName) === '') {
            $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Tab name cannot be empty.']);
            return;
        }

        $this->validate([
            'editingGroupName' => 'required|string|max:255',
        ], [
            'editingGroupName.required' => 'Tab name is required.'
        ]);

        $group = LeadGroup::find($this->editingGroupId);
        if (!$group) {
            $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Tab not found.']);
            return;
        }

        $sheet = $group->leadSheet;
        if (!$sheet || $sheet->created_by !== auth()->id()) {
            $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Access denied.']);
            return;
        }

        $group->update(['name' => trim($this->editingGroupName)]);

        $this->editingGroupId = null;
        $this->editingGroupName = '';
        $this->loadSheetLeads();
        $this->resetValidation();
        $this->dispatch('show-toast', ['type' => 'success', 'message' => 'Tab renamed successfully.']);
    }

    public function deleteLeadRow($leadId)
    {
        if (!auth()->check() || !$this->canEditAcrossAllSheets()) {
            $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Access denied.']);
            return;
        }

        $lead = Lead::where('id', $leadId)
            ->where('created_by', auth()->id())
            ->first();

        if (!$lead) {
            $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Lead not found.']);
            return;
        }

        $lead->delete();
        $this->leadsData = array_values(array_filter(
            $this->leadsData,
            fn ($row) => ($row['id'] ?? null) !== $leadId
        ));
        $this->tableLoadedCount = $this->visibleTableRecordCount();
        $this->dispatch('show-toast', ['type' => 'success', 'message' => 'Lead deleted successfully.']);
        $this->dispatch('lead-updated');
    }

    #[On('lead-created')]
    #[On('lead-updated')]
    #[On('lead-opened')]
    public function refreshLeads()
    {
        if (auth()->check() && $this->viewMode === 'table' && $this->canEditAcrossAllSheets()) {
            $this->resetTableViewport();
            $this->loadSheetLeads();
            return;
        }

        $this->resetPage();
    }

    #[On('sheet-created')]
    public function refreshSheets($sheetId = null)
    {
        $this->resetPage();

        if (!auth()->check() || !$sheetId) {
            return;
        }

        $sheet = LeadSheet::where('id', $sheetId)
            ->where('created_by', auth()->id())
            ->first();

        if (!$sheet) {
            return;
        }

        $this->sheetFilter = (string) $sheetId;
        $firstGroupId = LeadGroup::where('lead_sheet_id', $sheetId)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->value('id');
        $this->groupFilter = $firstGroupId ? (string) $firstGroupId : null;
        $this->resetTableViewport();
        $this->editingGroupId = null;
        $this->editingGroupName = '';
        $this->loadSheetLeads();
    }

    public function refreshLeadsData()
    {
        // This method is called by polling to refresh the leads list
        // The render method will automatically fetch fresh data
    }

    public function exportLeads()
    {
        $writer = null;
        $tempFilePath = null;

        try {
            if (!auth()->check()) {
                $this->dispatch('show-toast', ['type' => 'error', 'message' => 'You must be logged in to export leads.']);
                return null;
            }

            $this->persistTableRowsForExport();

            $tempFilePath = tempnam(sys_get_temp_dir(), 'leads_export_');
            if ($tempFilePath === false) {
                throw new \RuntimeException('Unable to prepare export file.');
            }

            $xlsxFilePath = $tempFilePath . '.xlsx';
            if (file_exists($xlsxFilePath)) {
                @unlink($xlsxFilePath);
            }
            @rename($tempFilePath, $xlsxFilePath);
            $tempFilePath = $xlsxFilePath;

            $writer = new XlsxWriter();
            $writer->openToFile($tempFilePath);

            $this->writeExportWorkbook($writer);
            $writer->close();
            $writer = null;

            return response()->download(
                $tempFilePath,
                $this->buildExportFileName(),
                ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
            )->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            if ($writer !== null) {
                try {
                    $writer->close();
                } catch (\Throwable $closeException) {
                    // Ignore close failures after export exception.
                }
            }

            if ($tempFilePath && file_exists($tempFilePath)) {
                @unlink($tempFilePath);
            }

            Log::error('Lead export failed', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);
            $message = $e instanceof \RuntimeException
                ? $e->getMessage()
                : 'Failed to export leads. Please try again.';
            $this->dispatch('show-toast', ['type' => 'error', 'message' => $message]);
            return null;
        }
    }

    protected function persistTableRowsForExport(): void
    {
        if ($this->viewMode !== 'table' || !$this->canEditAcrossAllSheets()) {
            return;
        }

        $rows = $this->leadsData;

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $email = trim((string) ($row['email'] ?? ''));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                // Do not block export for table rows with invalid in-progress edits.
                // The export query reads persisted DB records; skip mutating this row.
                continue;
            }

            $payload = [
                'name' => $name,
                'email' => $email !== '' ? $email : null,
                'services' => ($services = trim((string) ($row['services'] ?? ''))) !== '' ? $services : null,
                'phone' => ($phone = trim((string) ($row['phone'] ?? ''))) !== '' ? $phone : null,
                'location' => ($location = trim((string) ($row['location'] ?? ''))) !== '' ? $location : null,
                'position' => ($position = trim((string) ($row['position'] ?? ''))) !== '' ? $position : null,
                'platform' => ($platform = trim((string) ($row['platform'] ?? ''))) !== '' ? $platform : null,
                'social_links' => $this->normalizeSocialLinks(($row['social_links'] ?? null)),
                'detail' => ($detail = trim((string) ($row['detail'] ?? ''))) !== '' ? $detail : null,
                'web_link' => ($webLink = trim((string) ($row['web_link'] ?? ''))) !== '' ? $webLink : null,
            ];

            if (!empty($row['id'])) {
                Lead::where('id', $row['id'])
                    ->where('created_by', auth()->id())
                    ->update($payload);

                $updatedLead = Lead::where('id', $row['id'])
                    ->where('created_by', auth()->id())
                    ->first();
                if ($updatedLead) {
                    NotificationService::notifySalesNewLeadWhenCoreFieldsComplete($updatedLead);
                }

                $this->leadsData[$index]['social_links'] = $payload['social_links'] ?? '';
                continue;
            }

            if (!$this->sheetFilter || $this->groupFilter === '' || $this->groupFilter === null) {
                continue;
            }

            $lead = Lead::create([
                'created_by' => auth()->id(),
                'lead_sheet_id' => $this->sheetFilter,
                'lead_group_id' => $this->groupFilter ?: null,
                'lead_date' => now()->toDateString(),
                'status' => 'no response',
                ...$payload,
            ]);

            NotificationService::notifySalesNewLeadWhenCoreFieldsComplete($lead);

            $this->leadsData[$index]['id'] = $lead->id;
            $this->leadsData[$index]['social_links'] = $payload['social_links'] ?? '';
        }
    }

    protected function writeExportWorkbook(XlsxWriter $writer): void
    {
        $usedSheetNames = [];
        $firstSheetWritten = false;

        if ($this->sheetFilter) {
            $sheet = LeadSheet::with('leadGroups')->find($this->sheetFilter);
            if (!$sheet) {
                throw new \RuntimeException('Selected sheet was not found.');
            }

            $groups = LeadGroup::where('lead_sheet_id', $sheet->id)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();

            foreach ($groups as $group) {
                $query = $this->baseLeadsExportQuery()
                    ->where('lead_sheet_id', $sheet->id)
                    ->where('lead_group_id', $group->id);

                $writtenRows = $this->writeExportSheet(
                    $writer,
                    $firstSheetWritten,
                    $this->sanitizeExportSheetName($group->name ?: 'Group ' . $group->id, $usedSheetNames),
                    $query
                );

                if ($writtenRows > 0) {
                    $firstSheetWritten = true;
                }
            }

            $ungroupedQuery = $this->baseLeadsExportQuery()
                ->where('lead_sheet_id', $sheet->id)
                ->whereNull('lead_group_id');

            $ungroupedRows = $this->writeExportSheet(
                $writer,
                $firstSheetWritten,
                $this->sanitizeExportSheetName('Ungrouped', $usedSheetNames),
                $ungroupedQuery
            );

            if ($ungroupedRows > 0) {
                $firstSheetWritten = true;
            }
        } else {
            $sheetsQuery = LeadSheet::query()->orderBy('created_at', 'desc');

            if (auth()->check()) {
                if (auth()->user()->isScrapper()) {
                    $sheetsQuery->where('created_by', auth()->id());
                } elseif (auth()->user()->isSalesTeam()) {
                    $userTeamIds = auth()->user()->teams()->pluck('teams.id')->toArray();
                    $sheetsQuery->where(function ($q) use ($userTeamIds) {
                        $q->where('created_by', auth()->id())
                            ->orWhereHas('teams', fn ($t) => $t->whereIn('teams.id', $userTeamIds));
                    });
                }
            }

            $sheets = $sheetsQuery->get();

            foreach ($sheets as $sheet) {
                $groups = LeadGroup::where('lead_sheet_id', $sheet->id)
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->get();

                foreach ($groups as $group) {
                    $query = $this->baseLeadsExportQuery()
                        ->where('lead_sheet_id', $sheet->id)
                        ->where('lead_group_id', $group->id);

                    $writtenRows = $this->writeExportSheet(
                        $writer,
                        $firstSheetWritten,
                        $this->sanitizeExportSheetName(($sheet->name ?: 'Sheet ' . $sheet->id) . ' - ' . ($group->name ?: 'Group ' . $group->id), $usedSheetNames),
                        $query
                    );

                    if ($writtenRows > 0) {
                        $firstSheetWritten = true;
                    }
                }

                $ungroupedQuery = $this->baseLeadsExportQuery()
                    ->where('lead_sheet_id', $sheet->id)
                    ->whereNull('lead_group_id');

                $ungroupedRows = $this->writeExportSheet(
                    $writer,
                    $firstSheetWritten,
                    $this->sanitizeExportSheetName(($sheet->name ?: 'Sheet ' . $sheet->id) . ' - Ungrouped', $usedSheetNames),
                    $ungroupedQuery
                );

                if ($ungroupedRows > 0) {
                    $firstSheetWritten = true;
                }
            }
        }

        if (!$firstSheetWritten) {
            $this->writeExportSheet(
                $writer,
                false,
                $this->sanitizeExportSheetName('Leads', $usedSheetNames),
                $this->baseLeadsExportQuery()->whereRaw('1 = 0')
            );
        }
    }

    protected function writeExportSheet(XlsxWriter $writer, bool $firstSheetWritten, string $sheetName, Builder $query): int
    {
        if ($firstSheetWritten) {
            $sheet = $writer->addNewSheetAndMakeItCurrent();
        } else {
            $sheet = $writer->getCurrentSheet();
        }

        $sheet->setName($sheetName);
        $writer->addRow(Row::fromValues($this->exportHeaders()));

        $writtenRows = 0;
        $query->orderBy('id')
            ->with(['creator', 'opener', 'comments.user', 'leadSheet', 'leadGroup'])
            ->chunk(200, function ($leads) use ($writer, &$writtenRows) {
                foreach ($leads as $lead) {
                    $writer->addRow(Row::fromValues($this->leadExportRow($lead)));
                    $writtenRows++;
                }
            });

        return $writtenRows;
    }

    protected function baseLeadsExportQuery(): Builder
    {
        $query = Lead::query();

        if (auth()->check()) {
            if (auth()->user()->isScrapper()) {
                $query->where('created_by', auth()->id());
            } elseif (auth()->user()->isSalesTeam()) {
                $userTeamIds = auth()->user()->teams()->pluck('teams.id')->toArray();
                $query->whereHas('leadSheet', function ($q) use ($userTeamIds) {
                    $q->where('created_by', auth()->id())
                        ->orWhereHas('teams', fn ($t) => $t->whereIn('teams.id', $userTeamIds));
                });
            }
        }

        if ($this->search) {
            $searchTerm = '%' . trim($this->search) . '%';
            $query->where(function ($q) use ($searchTerm) {
                $q->where('name', 'like', $searchTerm)
                    ->orWhere('email', 'like', $searchTerm)
                    ->orWhere('phone', 'like', $searchTerm)
                    ->orWhere('company', 'like', $searchTerm)
                    ->orWhere('services', 'like', $searchTerm)
                    ->orWhere('location', 'like', $searchTerm)
                    ->orWhere('position', 'like', $searchTerm)
                    ->orWhere('platform', 'like', $searchTerm)
                    ->orWhere('social_links', 'like', $searchTerm)
                    ->orWhere('detail', 'like', $searchTerm)
                    ->orWhere('web_link', 'like', $searchTerm)
                    ->orWhere('notes', 'like', $searchTerm);
            });
        }

        if ($this->statusFilter) {
            $query->where('status', $this->statusFilter);
        }

        if ($this->sheetFilter) {
            $query->where('lead_sheet_id', $this->sheetFilter);
        }

        return $query;
    }

    protected function exportHeaders(): array
    {
        return [
            'Lead ID',
            'Sheet',
            'Group',
            'Lead Date',
            'Status',
            'Name',
            'Email',
            'Phone',
            'Company',
            'Services',
            'Budget',
            'Credits',
            'Location',
            'Position',
            'Platform',
            'Social Links',
            'Detail',
            'Web Link',
            'Notes',
            'Opened By ID',
            'Opened By',
            'Opened At',
            'Created By ID',
            'Created By',
            'Created At',
            'Updated At',
            'Comments Count',
            'Comments',
        ];
    }

    protected function leadExportRow(Lead $lead): array
    {
        $comments = $lead->comments
            ->sortBy('created_at')
            ->map(function ($comment) {
                $author = $comment->user->name ?? 'Unknown';
                $timestamp = $comment->created_at?->format('Y-m-d H:i:s') ?? '';
                $message = str_replace(["\r\n", "\r"], "\n", trim((string) $comment->message));

                return trim("{$timestamp} - {$author}: {$message}");
            })
            ->filter()
            ->values()
            ->all();

        $socialLinks = $this->resolveLeadSocialLinks($lead);

        return [
            $lead->id,
            $this->sanitizeSpreadsheetValue($lead->leadSheet?->name ?? ''),
            $this->sanitizeSpreadsheetValue($lead->leadGroup?->name ?? 'Ungrouped'),
            $lead->lead_date?->format('Y-m-d') ?? '',
            $this->sanitizeSpreadsheetValue($lead->status ?? ''),
            $this->sanitizeSpreadsheetValue($lead->name ?? ''),
            $this->sanitizeSpreadsheetValue($lead->email ?? ''),
            $this->sanitizeSpreadsheetValue($lead->phone ?? ''),
            $this->sanitizeSpreadsheetValue($lead->company ?? ''),
            $this->sanitizeSpreadsheetValue($lead->services ?? ''),
            $this->sanitizeSpreadsheetValue($lead->budget ?? ''),
            $this->sanitizeSpreadsheetValue($lead->credits ?? ''),
            $this->sanitizeSpreadsheetValue($lead->location ?? ''),
            $this->sanitizeSpreadsheetValue($lead->position ?? ''),
            $this->sanitizeSpreadsheetValue($lead->platform ?? ''),
            $this->sanitizeSpreadsheetValue($socialLinks),
            $this->sanitizeSpreadsheetValue($lead->detail ?? ''),
            $this->sanitizeSpreadsheetValue($lead->web_link ?? ''),
            $this->sanitizeSpreadsheetValue($lead->notes ?? ''),
            $lead->opened_by ?? '',
            $this->sanitizeSpreadsheetValue($lead->opener?->name ?? ''),
            $lead->opened_at?->format('Y-m-d H:i:s') ?? '',
            $lead->created_by ?? '',
            $this->sanitizeSpreadsheetValue($lead->creator?->name ?? ''),
            $lead->created_at?->format('Y-m-d H:i:s') ?? '',
            $lead->updated_at?->format('Y-m-d H:i:s') ?? '',
            count($comments),
            $this->sanitizeSpreadsheetValue(implode("\n\n", $comments)),
        ];
    }

    protected function sanitizeSpreadsheetValue(?string $value): string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return '';
        }

        if (preg_match('/^[=\-+@]/', $value)) {
            return "'" . $value;
        }

        return $value;
    }

    protected function sanitizeExportSheetName(string $name, array &$usedNames): string
    {
        $base = trim($name);
        $base = preg_replace('/[\\\\\\/\\?\\*\\:\\[\\]]+/', ' ', $base) ?? '';
        $base = trim(preg_replace('/\\s+/', ' ', $base) ?? '');
        if ($base === '') {
            $base = 'Sheet';
        }

        $base = mb_substr($base, 0, 31);
        $candidate = $base;
        $suffix = 2;

        while (in_array(mb_strtolower($candidate), $usedNames, true)) {
            $suffixText = ' (' . $suffix . ')';
            $candidate = mb_substr($base, 0, 31 - mb_strlen($suffixText)) . $suffixText;
            $suffix++;
        }

        $usedNames[] = mb_strtolower($candidate);

        return $candidate;
    }

    protected function buildExportFileName(): string
    {
        $timestamp = now()->format('Ymd_His');

        if ($this->sheetFilter) {
            $sheet = LeadSheet::find($this->sheetFilter);
            $sheetName = $sheet?->name ? Str::slug($sheet->name, '_') : 'sheet';
            return "leads_{$sheetName}_{$timestamp}.xlsx";
        }

        return "leads_export_{$timestamp}.xlsx";
    }

    public function importLeadsFile()
    {
        $reader = null;
        $readerOpened = false;

        try {
            if (!auth()->check() || !auth()->user()->isScrapper()) {
                $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Only scrapper can import leads.']);
                return;
            }

            $this->validate([
                'importSheetId' => 'required|integer|exists:lead_sheets,id',
                'importFile' => 'required|file|mimes:csv,xlsx|max:10240',
            ], [
                'importSheetId.required' => 'Please select a sheet before importing.',
                'importFile.required' => 'Please choose a CSV or XLSX file to import.',
                'importFile.mimes' => 'Only CSV and XLSX files are supported.',
                'importFile.max' => 'The import file may not be greater than 10MB.',
            ]);

            $sheet = LeadSheet::where('id', (int) $this->importSheetId)
                ->where('created_by', auth()->id())
                ->first();

            if (!$sheet) {
                $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Selected sheet not found or access denied.']);
                return;
            }

            $extension = strtolower($this->importFile->getClientOriginalExtension() ?: pathinfo($this->importFile->getClientOriginalName(), PATHINFO_EXTENSION));
            $reader = $this->createImportReader($extension);
            $reader->open($this->importFile->getRealPath());
            $readerOpened = true;

            $imported = 0;
            $skipped = 0;
            $importedTabs = [];
            $firstImportedGroupId = null;
            $skippedWorksheets = [];

            foreach ($reader->getSheetIterator() as $sheetIterator) {
                $headerMap = [];
                $rowNumber = 0;
                $targetGroup = null;
                $worksheetName = $this->resolveImportWorksheetName($sheetIterator);

                foreach ($sheetIterator->getRowIterator() as $row) {
                    $rowNumber++;
                    $rowValues = $row->toArray();

                    if ($rowNumber === 1) {
                        $headerMap = $this->buildImportHeaderMap($rowValues);

                        if (!isset($headerMap['name'])) {
                            $skippedWorksheets[] = $worksheetName;
                            break;
                        }

                        continue;
                    }

                    if ($this->isImportRowEmpty($rowValues)) {
                        continue;
                    }

                    $payload = $this->buildLeadPayloadFromImportRow($rowValues, $headerMap);

                    if (empty($payload['name'])) {
                        $skipped++;
                        continue;
                    }

                    if (!$targetGroup) {
                        $targetGroup = $this->resolveImportTargetGroupForWorksheet($sheet->id, $worksheetName);
                        $importedTabs[$targetGroup->id] = $targetGroup->name;
                        $firstImportedGroupId ??= (string) $targetGroup->id;
                    }

                    $importedLead = Lead::create([
                        'created_by' => auth()->id(),
                        'lead_sheet_id' => $sheet->id,
                        'lead_group_id' => $targetGroup->id,
                        'status' => $payload['status'] ?? 'no response',
                        'name' => $payload['name'],
                        'email' => $payload['email'] ?? null,
                        'phone' => $payload['phone'] ?? null,
                        'company' => $payload['company'] ?? null,
                        'services' => $payload['services'] ?? null,
                        'location' => $payload['location'] ?? null,
                        'position' => $payload['position'] ?? null,
                        'platform' => $payload['platform'] ?? null,
                        'social_links' => $payload['social_links'] ?? null,
                        'detail' => $payload['detail'] ?? null,
                        'web_link' => $payload['web_link'] ?? null,
                        'notes' => $payload['notes'] ?? null,
                        'lead_date' => $payload['lead_date'] ?? now()->toDateString(),
                    ]);

                    NotificationService::notifySalesNewLeadWhenCoreFieldsComplete($importedLead);

                    $imported++;
                }
            }

            if ($imported === 0) {
                $message = 'No rows were imported. Please check your file data.';
                if (!empty($skippedWorksheets)) {
                    $message .= ' Skipped tab(s) without a Name column: ' . implode(', ', $skippedWorksheets) . '.';
                }
                $this->dispatch('show-toast', ['type' => 'warning', 'message' => $message]);
                return;
            }

            $this->sheetFilter = (string) $sheet->id;
            $this->groupFilter = $firstImportedGroupId;
            $this->importSheetId = (string) $sheet->id;
            $this->importFile = null;
            $this->resetValidation(['importFile', 'importSheetId']);

            $this->resetTableViewport();
            $this->loadSheetLeads();
            $this->resetPage();
            $this->dispatch('lead-created');
            $this->dispatch('close-import-modal');

            $message = "Imported {$imported} lead(s) successfully.";
            if (!empty($importedTabs)) {
                $message .= ' Created/updated ' . count($importedTabs) . ' tab(s): ' . implode(', ', array_values($importedTabs)) . '.';
            }
            if ($skipped > 0) {
                $message .= " Skipped {$skipped} row(s) without a name.";
            }
            if (!empty($skippedWorksheets)) {
                $message .= ' Skipped worksheet tab(s) without a Name column: ' . implode(', ', $skippedWorksheets) . '.';
            }

            $this->dispatch('show-toast', ['type' => 'success', 'message' => $message]);
        } catch (\Throwable $e) {
            Log::error('Lead import failed', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Import failed. Please verify your file and try again.']);
        } finally {
            if ($readerOpened && $reader instanceof ReaderInterface) {
                $reader->close();
            }
        }
    }

    protected function createImportReader(string $extension): ReaderInterface
    {
        return match (strtolower($extension)) {
            'csv' => new CsvReader(),
            'xlsx' => new XlsxReader(),
            default => throw new \RuntimeException('Unsupported import file type.'),
        };
    }

    protected function resolveImportTargetGroupForWorksheet(int $sheetId, ?string $worksheetName): LeadGroup
    {
        $groupName = $this->normalizeImportWorksheetGroupName($worksheetName);

        $existingGroup = LeadGroup::where('lead_sheet_id', $sheetId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($groupName)])
            ->first();

        if ($existingGroup) {
            return $existingGroup;
        }

        $nextSortOrder = (LeadGroup::where('lead_sheet_id', $sheetId)->max('sort_order') ?? 0) + 1;

        return LeadGroup::create([
            'lead_sheet_id' => $sheetId,
            'name' => $groupName,
            'sort_order' => $nextSortOrder,
        ]);
    }

    protected function resolveImportWorksheetName(mixed $sheetIterator): string
    {
        $name = method_exists($sheetIterator, 'getName')
            ? (string) $sheetIterator->getName()
            : '';

        return $this->normalizeImportWorksheetGroupName($name);
    }

    protected function normalizeImportWorksheetGroupName(?string $worksheetName): string
    {
        $name = trim((string) ($worksheetName ?? ''));
        if ($name === '') {
            return 'Imported';
        }

        $name = preg_replace('/\s+/', ' ', $name) ?? $name;

        return mb_substr($name, 0, 255);
    }

    protected function buildImportHeaderMap(array $headerRow): array
    {
        $map = [];

        foreach ($headerRow as $index => $headerValue) {
            $normalizedHeader = $this->normalizeImportHeader($headerValue);
            $field = $this->mapImportHeaderToField($normalizedHeader);

            if (!$field) {
                continue;
            }

            if ($field === 'social_links') {
                $map[$field] ??= [];
                $map[$field][] = $index;
                continue;
            }

            if (!isset($map[$field])) {
                $map[$field] = $index;
            }
        }

        return $map;
    }

    protected function mapImportHeaderToField(string $normalizedHeader): ?string
    {
        $exactMatch = match ($normalizedHeader) {
            'name', 'fullname', 'leadname' => 'name',
            'email', 'emailaddress' => 'email',
            'phone', 'phoneno', 'phonenumber', 'mobile', 'mobileno' => 'phone',
            'company' => 'company',
            'services', 'service', 'job' => 'services',
            'location', 'city', 'country', 'address' => 'location',
            'position', 'designation', 'jobtitle', 'role' => 'position',
            'platform', 'source', 'leadsource' => 'platform',
            'linkedin', 'linkedinurl', 'linkedinprofile', 'linkedinlink', 'linkedinlinks',
            'linkedn', 'linkednurl', 'linkednlink', 'linkdin', 'linkdinurl', 'linkdinlink',
            'social', 'sociallink', 'sociallinks', 'socialmedia', 'socialmedialink', 'socialmedialinks',
            'links', 'link',
            'facebook', 'facebooklink', 'facebooklinks', 'facebookurl', 'facebookprofile',
            'fb', 'fblink', 'fblinks', 'fburl', 'fbprofile', 'fblinkurl',
            'instagram', 'instagramlink', 'instagramlinks', 'instagramurl', 'instagramprofile',
            'insta', 'instalink', 'instalinks', 'instaurl', 'instaprofile',
            'twitter', 'twitterlink', 'twitterlinks', 'twitterurl', 'twitterprofile',
            'x', 'xlink', 'xlinks', 'xurl', 'xprofile',
            'youtube', 'youtubelink', 'youtubeurl',
            'tiktok', 'tiktoklink', 'tiktokurl',
            'snapchat', 'snapchatlink', 'snapchaturl',
            'telegram', 'telegramlink', 'telegramurl',
            'whatsapp', 'whatsapplink', 'whatsappurl',
            'pinterest', 'pinterestlink', 'pinteresturl' => 'social_links',
            'detail', 'details', 'description', 'budgetotherdetail', 'budgetotherdetails', 'otherdetail', 'otherdetails' => 'detail',
            'weblink', 'websitelink', 'weburl', 'website', 'websiteurl', 'web', 'url', 'site', 'siteurl', 'homepage', 'homepagelink', 'companywebsite', 'companyweb', 'companyurl' => 'web_link',
            'notes', 'note', 'comment', 'comments' => 'notes',
            'leaddate', 'date' => 'lead_date',
            'status' => 'status',
            default => null,
        };

        if ($exactMatch !== null) {
            return $exactMatch;
        }

        if ($this->headerLooksLikeSocialLinks($normalizedHeader)) {
            return 'social_links';
        }

        if ($this->headerLooksLikeWebLink($normalizedHeader)) {
            return 'web_link';
        }

        return null;
    }

    protected function normalizeImportHeader(mixed $header): string
    {
        $stringHeader = is_string($header) ? $header : (string) $header;
        $stringHeader = strtolower(trim($stringHeader));

        return preg_replace('/[^a-z0-9]+/', '', $stringHeader) ?? '';
    }

    protected function headerLooksLikeSocialLinks(string $normalizedHeader): bool
    {
        if ($normalizedHeader === '') {
            return false;
        }

        $aliases = [
            'social', 'sociallink', 'sociallinks', 'socialmedia', 'socialmedialink', 'socialmedialinks',
            'facebook', 'facebooklink', 'facebooklinks', 'facebookurl', 'facebookprofile',
            'fb', 'fblink', 'fblinks', 'fburl', 'fbprofile', 'fblinkurl', 'fbsocial',
            'instagram', 'instagramlink', 'instagramlinks', 'instagramurl', 'instagramprofile',
            'insta', 'instalink', 'instalinks', 'instaurl', 'instaprofile', 'instgramlink',
            'linkedin', 'linkedinlink', 'linkedinlinks', 'linkedinurl', 'linkedinprofile',
            'linkedn', 'linkednlink', 'linkednurl', 'linkdin', 'linkdinlink', 'linkdinurl',
            'twitter', 'twitterlink', 'twitterlinks', 'twitterurl', 'twitterprofile',
            'xlink', 'xlinks', 'xurl', 'xprofile',
            'youtube', 'youtubelink', 'youtubeurl',
            'tiktok', 'tiktoklink', 'tiktokurl',
            'snapchat', 'snapchatlink', 'snapchaturl',
            'telegram', 'telegramlink', 'telegramurl',
            'whatsapp', 'whatsapplink', 'whatsappurl',
            'pinterest', 'pinterestlink', 'pinteresturl',
        ];

        if ($this->headerMatchesAnyAlias($normalizedHeader, $aliases, 2)) {
            return true;
        }

        $platformKeywords = [
            'social', 'facebook', 'fb', 'instagram', 'insta', 'instagrm', 'linkedin', 'linkedn', 'linkdin',
            'twitter', 'tweet', 'youtube', 'tiktok', 'snapchat', 'telegram', 'whatsapp', 'pinterest',
        ];
        $linkKeywords = ['link', 'links', 'url', 'urls', 'profile', 'profiles', 'handle', 'handles'];

        foreach ($platformKeywords as $platformKeyword) {
            if (str_contains($normalizedHeader, $platformKeyword)) {
                foreach ($linkKeywords as $linkKeyword) {
                    if (str_contains($normalizedHeader, $linkKeyword)) {
                        return true;
                    }
                }

                if (in_array($platformKeyword, ['facebook', 'fb', 'instagram', 'insta', 'linkedin', 'linkedn', 'linkdin', 'twitter', 'youtube', 'tiktok', 'snapchat', 'telegram', 'whatsapp', 'pinterest'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function headerLooksLikeWebLink(string $normalizedHeader): bool
    {
        if ($normalizedHeader === '') {
            return false;
        }

        $aliases = [
            'web', 'weblink', 'weblinks', 'weburl', 'weburls',
            'website', 'websites', 'websitelink', 'websitelinks', 'websiteurl', 'websiteurls',
            'site', 'sitelink', 'siteurl', 'homepage', 'homepagelink', 'homepageurl',
            'companywebsite', 'companyweb', 'companyurl',
        ];

        if ($this->headerMatchesAnyAlias($normalizedHeader, $aliases, 2)) {
            return true;
        }

        $webKeywords = ['web', 'website', 'site', 'homepage'];
        $linkKeywords = ['link', 'links', 'url', 'urls'];

        foreach ($webKeywords as $webKeyword) {
            if (str_contains($normalizedHeader, $webKeyword)) {
                return true;
            }
        }

        foreach ($linkKeywords as $linkKeyword) {
            if (str_contains($normalizedHeader, $linkKeyword) && str_contains($normalizedHeader, 'web')) {
                return true;
            }
        }

        return false;
    }

    protected function headerMatchesAnyAlias(string $normalizedHeader, array $aliases, int $maxDistance = 2): bool
    {
        foreach ($aliases as $alias) {
            if ($normalizedHeader === $alias) {
                return true;
            }

            if (strlen($alias) >= 5 && (str_contains($normalizedHeader, $alias) || str_contains($alias, $normalizedHeader))) {
                return true;
            }

            if (min(strlen($normalizedHeader), strlen($alias)) >= 5 && levenshtein($normalizedHeader, $alias) <= $maxDistance) {
                return true;
            }
        }

        return false;
    }

    protected function buildLeadPayloadFromImportRow(array $rowValues, array $headerMap): array
    {
        $payload = [];

        foreach ($headerMap as $field => $index) {
            if ($field === 'social_links') {
                $payload[$field] = $this->normalizeImportSocialLinksValue($rowValues, is_array($index) ? $index : [$index]);
                continue;
            }

            $rawValue = $rowValues[$index] ?? null;

            if ($field === 'lead_date') {
                $payload[$field] = $this->normalizeImportDateValue($rawValue);
                continue;
            }

            if ($field === 'status') {
                $payload[$field] = $this->normalizeImportStatusValue($rawValue);
                continue;
            }

            $payload[$field] = $this->normalizeImportTextValue($rawValue);
        }

        $payload['name'] = trim((string) ($payload['name'] ?? ''));

        return $payload;
    }

    protected function normalizeImportTextValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    protected function normalizeImportSocialLinksValue(array $rowValues, array $indexes): ?string
    {
        $links = [];

        foreach ($indexes as $index) {
            $value = $this->normalizeImportTextValue($rowValues[$index] ?? null);

            if ($value === null) {
                continue;
            }

            foreach ($this->splitSocialLinks($value) as $link) {
                $links[] = $link;
            }
        }

        return $this->normalizeSocialLinks(implode("\n", $links));
    }

    protected function normalizeImportDateValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        try {
            return Carbon::parse($text)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function normalizeImportStatusValue(mixed $value): ?string
    {
        $text = strtolower(trim((string) ($value ?? '')));
        if ($text === '') {
            return null;
        }

        $compact = preg_replace('/\s+/', '', $text) ?? $text;

        return match ($compact) {
            'wrongnumber' => 'wrong number',
            'followup' => 'follow up',
            'hiredus' => 'hired us',
            'hiredsomeone' => 'hired someone',
            'noresponse' => 'no response',
            default => null,
        };
    }

    protected function isImportRowEmpty(array $rowValues): bool
    {
        foreach ($rowValues as $value) {
            if ($this->normalizeImportTextValue($value) !== null) {
                return false;
            }
        }

        return true;
    }

    protected function canEditAcrossAllSheets(): bool
    {
        return auth()->check() && auth()->user()->isScrapper();
    }

    protected function resetTableViewport(): void
    {
        $this->pendingCreates = [];
        $this->leadsData = [];
        $this->draftLeadRows = [];
        $this->hasMoreTableRows = false;
        $this->tableCursorId = null;
        $this->tableLoadedCount = 0;
    }

    protected function tableChunkSize(): int
    {
        return 50;
    }

    protected function splitSocialLinks(?string $value): array
    {
        if ($value === null) {
            return [];
        }

        $value = trim($value);
        if ($value === '') {
            return [];
        }

        $parts = preg_split('/[\r\n,;|]+/', $value) ?: [];

        if (count($parts) <= 1) {
            preg_match_all('/(?:https?:\/\/|www\.)[^\s,;|]+|[a-z0-9.-]+\.[a-z]{2,}[^\s,;|]*/i', $value, $matches);
            if (!empty($matches[0])) {
                $parts = $matches[0];
            }
        }

        return array_values(array_unique(array_filter(array_map(static fn ($item) => trim($item), $parts))));
    }

    protected function normalizeSocialLinks(?string $value): ?string
    {
        $links = $this->splitSocialLinks($value);

        return empty($links) ? null : implode("\n", $links);
    }


    protected function resolveLeadSocialLinks(Lead $lead): string
    {
        return $this->normalizeSocialLinks($lead->social_links) ?? '';
    }

    public function updatedLeadsData($value, $key)
    {
        try {
            if (!auth()->check()) {
                return;
            }
            if (!$this->canEditAcrossAllSheets()) {
                return;
            }

            [$index, $field] = explode('.', $key) + [null, null];
            if ($index === null || $field === null) {
                return;
            }

            $allowed = ['name', 'email', 'services', 'phone', 'location', 'position', 'platform', 'social_links', 'detail', 'web_link'];
            if (!in_array($field, $allowed, true)) {
                return;
            }

            $row = $this->leadsData[$index] ?? null;
            if (!$row) {
                return;
            }

            // Trim value
            $value = is_string($value) ? trim($value) : $value;

            if ($field === 'name' && $row['id'] && $value === '') {
                try {
                    Lead::where('id', $row['id'])
                        ->where('created_by', auth()->id())
                        ->delete();
                    array_splice($this->leadsData, (int) $index, 1);
                    $this->tableLoadedCount = $this->visibleTableRecordCount();
                    $this->dispatch('lead-updated');
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('Error deleting lead: ' . $e->getMessage());
                    $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Failed to delete lead.']);
                }
                return;
            }

            if ($field === 'email' && $value && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Invalid email format.']);
                return;
            }

            if (empty($row['id'])) {
                return;
            }

            // Update existing lead
            try {
                $updateData = [$field => $value];

                if ($field === 'social_links') {
                    $normalizedSocialLinks = $this->normalizeSocialLinks(is_string($value) ? $value : null);
                    $updateData['social_links'] = $normalizedSocialLinks;
                    $this->leadsData[$index]['social_links'] = $normalizedSocialLinks ?? '';
                }

                Lead::where('id', $row['id'])
                    ->where('created_by', auth()->id())
                    ->update($updateData);

                $updatedLead = Lead::where('id', $row['id'])
                    ->where('created_by', auth()->id())
                    ->first();
                if ($updatedLead) {
                    NotificationService::notifySalesNewLeadWhenCoreFieldsComplete($updatedLead);
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Error updating lead: ' . $e->getMessage());
                $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Failed to update lead.']);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error in updatedLeadsData: ' . $e->getMessage());
        }
    }

    public function updatedDraftLeadRows($value, $key)
    {
        try {
            if (!auth()->check() || !$this->canEditAcrossAllSheets()) {
                return;
            }

            [$index, $field] = explode('.', $key) + [null, null];
            if ($index === null || $field === null) {
                return;
            }

            $allowed = ['name', 'email', 'services', 'phone', 'location', 'position', 'platform', 'social_links', 'detail', 'web_link'];
            if (!in_array($field, $allowed, true)) {
                return;
            }

            if (!$this->shouldAppendTableDraftRow()) {
                $this->draftLeadRows = [];
                return;
            }

            $row = $this->draftLeadRows[$index] ?? null;
            if (!$row) {
                return;
            }

            $value = is_string($value) ? trim($value) : $value;

            if ($field === 'email' && $value && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Invalid email format.']);
                return;
            }

            if ($field === 'social_links') {
                $normalizedSocialLinks = $this->normalizeSocialLinks(is_string($value) ? $value : null);
                $this->draftLeadRows[$index]['social_links'] = $normalizedSocialLinks ?? '';
                $row['social_links'] = $normalizedSocialLinks ?? '';
            }

            if (!$this->sheetFilter || $this->groupFilter === '' || $this->groupFilter === null) {
                return;
            }

            if (!empty($row['id'])) {
                if ($field === 'name' && trim((string) $value) === '') {
                    Lead::where('id', $row['id'])
                        ->where('created_by', auth()->id())
                        ->delete();
                    array_splice($this->draftLeadRows, (int) $index, 1);
                    $this->ensureTrailingDraftRow();
                    $this->tableLoadedCount = $this->visibleTableRecordCount();
                    return;
                }

                $updateData = [$field => $value];
                if ($field === 'social_links') {
                    $updateData['social_links'] = $this->draftLeadRows[$index]['social_links'] ?: null;
                }

                Lead::where('id', $row['id'])
                    ->where('created_by', auth()->id())
                    ->update($updateData);

                $updatedLead = Lead::where('id', $row['id'])
                    ->where('created_by', auth()->id())
                    ->first();
                if ($updatedLead) {
                    NotificationService::notifySalesNewLeadWhenCoreFieldsComplete($updatedLead);
                }

                return;
            }

            $draftName = trim((string) ($row['name'] ?? ''));
            if ($draftName === '') {
                $this->ensureTrailingDraftRow();
                return;
            }

            $pendingKey = 'draft_' . $index;
            if (!empty($this->pendingCreates[$pendingKey])) {
                return;
            }

            $this->pendingCreates[$pendingKey] = true;

            try {
                $lead = $this->persistDraftLeadRow($this->draftLeadRows[$index]);
                if ($lead) {
                    $this->draftLeadRows[$index] = array_merge($this->draftLeadRows[$index], $this->mapLeadToTableRow($lead, false));
                    $this->ensureTrailingDraftRow();
                    $this->tableLoadedCount = $this->visibleTableRecordCount();
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Error creating lead in draft row: ' . $e->getMessage());
                $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Failed to save lead. Please try again.']);
            } finally {
                unset($this->pendingCreates[$pendingKey]);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error in updatedDraftLeadRows: ' . $e->getMessage());
        }
    }

    public function loadSheetLeads(bool $append = false)
    {
        try {
            if (!auth()->check() || !$this->canEditAcrossAllSheets()) {
                $this->resetTableViewport();
                return;
            }

            if ($append && !$this->hasMoreTableRows) {
                return;
            }

            if (!$append) {
                $this->resetTableViewport();
            }

            if ($this->sheetFilter) {
                $sheet = LeadSheet::find($this->sheetFilter);
                if (!$sheet || $sheet->created_by !== auth()->id()) {
                    $this->resetTableViewport();
                    $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Selected sheet not found or access denied.']);
                    return;
                }
            }

            if ($this->sheetFilter && ($this->groupFilter === '' || $this->groupFilter === null) && !$this->hasActiveTableFilters()) {
                $this->resetTableViewport();
                return;
            }

            $includeContext = !$this->sheetFilter;
            $query = Lead::query()->where('created_by', auth()->id());

            if ($includeContext) {
                $query->with(['leadSheet', 'leadGroup']);
            }

            if ($this->sheetFilter) {
                $query->where('lead_sheet_id', $this->sheetFilter);
            }

            $this->applyTableSearchAndStatusFilters($query);

            if ($this->sheetFilter && $this->shouldApplySelectedGroupScopeToTableQuery()) {
                $this->applySelectedGroupScope($query);
            }

            if ($this->tableCursorId !== null) {
                $query->where('id', '<', $this->tableCursorId);
            }

            $chunkSize = $this->tableChunkSize();
            $loadedLeads = $query
                ->orderByDesc('id')
                ->limit($chunkSize + 1)
                ->get();

            $this->hasMoreTableRows = $loadedLeads->count() > $chunkSize;
            $visibleLeads = $loadedLeads->take($chunkSize);

            $mappedRows = $visibleLeads
                ->map(fn ($lead) => $this->mapLeadToTableRow($lead, $includeContext))
                ->values()
                ->all();

            if ($append) {
                $this->appendTableRows($mappedRows);
            } else {
                $this->leadsData = $mappedRows;
            }

            $lastLoadedLead = $visibleLeads->last();
            if ($lastLoadedLead) {
                $this->tableCursorId = $lastLoadedLead->id;
            }

            $this->ensureTrailingDraftRow();
            $this->tableLoadedCount = $this->visibleTableRecordCount();
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error loading sheet leads: ' . $e->getMessage());
            $this->resetTableViewport();

            $this->dispatch('show-toast', type: 'error', message: 'Failed to load leads. Please try again.');
        }
    }

    public function loadMoreTableRows()
    {
        if (!auth()->check() || $this->viewMode !== 'table' || !$this->canEditAcrossAllSheets()) {
            return;
        }

        if (!$this->hasMoreTableRows) {
            return;
        }

        $this->loadSheetLeads(true);
    }

    protected function persistDraftLeadRow(array $draftRow): ?Lead
    {
        $existingLead = Lead::where('created_by', auth()->id())
            ->where('lead_sheet_id', $this->sheetFilter)
            ->where('name', trim((string) ($draftRow['name'] ?? '')))
            ->whereDate('lead_date', now()->toDateString());

        if ($this->groupFilter !== '' && $this->groupFilter !== null) {
            $existingLead->where('lead_group_id', $this->groupFilter);
        } else {
            $existingLead->whereNull('lead_group_id');
        }

        $existingLead = $existingLead
            ->orderBy('created_at', 'desc')
            ->first();

        if ($existingLead) {
            return $existingLead;
        }

        $lead = Lead::create([
            'social_links' => $this->normalizeSocialLinks(!empty($draftRow['social_links']) ? trim((string) $draftRow['social_links']) : null),
            'created_by' => auth()->id(),
            'lead_sheet_id' => $this->sheetFilter,
            'lead_group_id' => $this->groupFilter ?: null,
            'lead_date' => now()->toDateString(),
            'status' => 'no response',
            'name' => trim((string) ($draftRow['name'] ?? '')),
            'email' => !empty($draftRow['email']) ? trim((string) $draftRow['email']) : null,
            'services' => !empty($draftRow['services']) ? trim((string) $draftRow['services']) : null,
            'phone' => !empty($draftRow['phone']) ? trim((string) $draftRow['phone']) : null,
            'location' => !empty($draftRow['location']) ? trim((string) $draftRow['location']) : null,
            'position' => !empty($draftRow['position']) ? trim((string) $draftRow['position']) : null,
            'platform' => !empty($draftRow['platform']) ? trim((string) $draftRow['platform']) : null,
            'detail' => !empty($draftRow['detail']) ? trim((string) $draftRow['detail']) : null,
            'web_link' => !empty($draftRow['web_link']) ? trim((string) $draftRow['web_link']) : null,
        ]);

        NotificationService::notifySalesNewLeadWhenCoreFieldsComplete($lead);

        return $lead;
    }

    protected function appendTableRows(array $incomingRows): void
    {
        if (empty($incomingRows)) {
            return;
        }

        $persistedRows = $this->persistedTableRows();
        $existingIds = array_map(static fn ($row) => (int) ($row['id'] ?? 0), $persistedRows);

        foreach ($incomingRows as $row) {
            $rowId = (int) ($row['id'] ?? 0);
            if ($rowId === 0 || in_array($rowId, $existingIds, true)) {
                continue;
            }

            $persistedRows[] = $row;
            $existingIds[] = $rowId;
        }

        $this->leadsData = array_values($persistedRows);
    }

    protected function persistedTableRows(): array
    {
        return array_values(array_filter(
            $this->leadsData,
            static fn ($row) => !empty($row['id'])
        ));
    }

    protected function persistedDraftLeadRows(): array
    {
        return array_values(array_filter(
            $this->draftLeadRows,
            static fn ($row) => !empty($row['id'])
        ));
    }

    protected function visibleTableRecordCount(): int
    {
        return count($this->persistedTableRows()) + count($this->persistedDraftLeadRows());
    }

    /**
     * True only for the blank “new row” composer (no id and no field content yet).
     * Persisted leads and in-progress drafts use a white row background.
     */
    public function isTableScratchEmptyRow(array $row): bool
    {
        if (!empty($row['id'])) {
            return false;
        }

        return !collect(['name', 'email', 'services', 'phone', 'location', 'position', 'platform', 'social_links', 'detail', 'web_link'])
            ->contains(fn ($field) => trim((string) ($row[$field] ?? '')) !== '');
    }

    protected function shouldAppendTableDraftRow(): bool
    {
        return $this->sheetFilter !== ''
            && $this->sheetFilter !== null
            && !$this->hasActiveTableFilters()
            && $this->groupFilter !== ''
            && $this->groupFilter !== null;
    }

    protected function ensureTrailingDraftRow(): void
    {
        if (!$this->shouldAppendTableDraftRow()) {
            $this->draftLeadRows = [];
            return;
        }

        $rows = [];
        foreach ($this->draftLeadRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $hasContent = collect(['name', 'email', 'services', 'phone', 'location', 'position', 'platform', 'social_links', 'detail', 'web_link'])
                ->contains(fn ($field) => trim((string) ($row[$field] ?? '')) !== '');

            if (!empty($row['id']) || $hasContent) {
                $rows[] = $row;
            }
        }

        $lastRow = end($rows);
        if (!$lastRow || !empty($lastRow['id']) || collect(['name', 'email', 'services', 'phone', 'location', 'position', 'platform', 'social_links', 'detail', 'web_link'])
            ->contains(fn ($field) => trim((string) ($lastRow[$field] ?? '')) !== '')) {
            $rows[] = $this->emptyRow();
        }

        $this->draftLeadRows = array_values($rows);
    }

    public function emptyRow(): array
    {
        return [
            '_row_key' => Str::uuid()->toString(),
            'id' => null,
            'name' => '',
            'email' => '',
            'services' => '',
            'phone' => '',
            'location' => '',
            'position' => '',
            'platform' => '',
            'social_links' => '',
            'detail' => '',
            'web_link' => '',
        ];
    }

    public function displayValue($value, string $fallback = '—'): string
    {
        if ($value === null) {
            return $fallback;
        }

        if (is_string($value) && trim($value) === '') {
            return $fallback;
        }

        return (string) $value;
    }

    protected function mapLeadToTableRow(Lead $lead, bool $includeContext = false): array
    {
        $row = [
            '_row_key' => 'lead-'.$lead->id,
            'id' => $lead->id,
            'name' => $lead->name ?? '',
            'email' => $lead->email ?? '',
            'services' => $lead->services ?? '',
            'phone' => $lead->phone ?? '',
            'location' => $lead->location ?? '',
            'position' => $lead->position ?? '',
            'platform' => $lead->platform ?? '',
            'social_links' => $this->resolveLeadSocialLinks($lead),
            'detail' => $lead->detail ?? '',
            'web_link' => $lead->web_link ?? '',
        ];

        if ($includeContext) {
            $row['sheet_name'] = $lead->leadSheet?->name ?? '';
            $row['group_name'] = $lead->leadGroup?->name ?? 'Ungrouped';
        }

        return $row;
    }

    protected function applySelectedGroupScope($query): void
    {
        if ($this->groupFilter !== '' && $this->groupFilter !== null) {
            $query->where('lead_group_id', $this->groupFilter);
            return;
        }

        $query->whereRaw('1 = 0');
    }

    protected function applyTableSearchAndStatusFilters($query): void
    {
        if ($this->search) {
            $searchTerm = '%' . trim($this->search) . '%';

            $query->where(function ($q) use ($searchTerm) {
                $q->where('name', 'like', $searchTerm)
                    ->orWhere('email', 'like', $searchTerm)
                    ->orWhere('phone', 'like', $searchTerm)
                    ->orWhere('company', 'like', $searchTerm)
                    ->orWhere('services', 'like', $searchTerm)
                    ->orWhere('location', 'like', $searchTerm)
                    ->orWhere('position', 'like', $searchTerm)
                    ->orWhere('platform', 'like', $searchTerm)
                    ->orWhere('social_links', 'like', $searchTerm)
                    ->orWhere('detail', 'like', $searchTerm)
                    ->orWhere('web_link', 'like', $searchTerm)
                    ->orWhere('notes', 'like', $searchTerm);
            });
        }

        if ($this->statusFilter) {
            $query->where('status', $this->statusFilter);
        }
    }

    protected function hasActiveTableFilters(): bool
    {
        return trim((string) $this->search) !== '' || trim((string) $this->statusFilter) !== '';
    }

    protected function shouldApplySelectedGroupScopeToTableQuery(): bool
    {
        if ($this->groupFilter === '' || $this->groupFilter === null) {
            return false;
        }

        return !$this->hasActiveTableFilters();
    }

    protected function shouldRequireSelectedGroupForTableView(): bool
    {
        return !$this->hasActiveTableFilters();
    }

    protected function currentTableStateSignature(): string
    {
        return md5(json_encode([
            'viewMode' => $this->viewMode,
            'search' => trim((string) $this->search),
            'statusFilter' => trim((string) $this->statusFilter),
            'sheetFilter' => (string) $this->sheetFilter,
            'groupFilter' => (string) $this->groupFilter,
        ]));
    }

    protected function currentLeadsPageQueryParams(): array
    {
        $params = [];

        if ($this->search !== '') {
            $params['search'] = $this->search;
        }
        if ($this->statusFilter !== '') {
            $params['statusFilter'] = $this->statusFilter;
        }
        if ($this->sheetFilter !== '') {
            $params['sheetFilter'] = $this->sheetFilter;
        }
        if ($this->groupFilter !== '' && $this->groupFilter !== null) {
            $params['groupFilter'] = $this->groupFilter;
        }
        if ($this->viewMode !== '') {
            $params['viewMode'] = $this->viewMode;
        }

        return $params;
    }

    public function mount()
    {
        $canUseTableMode = $this->canEditAcrossAllSheets();

        // Set view mode from query parameter
        if (request()->has('viewMode') && in_array(request()->get('viewMode'), ['table', 'list'], true)) {
            $requestedViewMode = request()->get('viewMode');

            if ($requestedViewMode === 'list' || ($requestedViewMode === 'table' && $canUseTableMode)) {
                $this->viewMode = $requestedViewMode;
            }
        }

        if (!$canUseTableMode) {
            $this->viewMode = 'list';
        }

        if (auth()->check() && auth()->user()->isScrapper()) {
            if (!empty($this->sheetFilter)) {
                $this->importSheetId = (string) $this->sheetFilter;
            } else {
                $firstOwnedSheetId = LeadSheet::where('created_by', auth()->id())
                    ->orderBy('created_at', 'desc')
                    ->value('id');

                $this->importSheetId = $firstOwnedSheetId ? (string) $firstOwnedSheetId : '';
            }
        }
        
        if (auth()->check() && $this->viewMode === 'table' && empty($this->leadsData)) {
            if (!$this->sheetFilter && $this->canEditAcrossAllSheets()) {
                $this->loadSheetLeads();
                return;
            }

            if ($this->sheetFilter) {
                $sheet = LeadSheet::find($this->sheetFilter);
                if ($sheet && $sheet->created_by === auth()->id() && $this->canEditAcrossAllSheets()) {
                    $this->loadSheetLeads();
                }
            }
        }
    }

    public function render()
    {
        try {
            if (!$this->canEditAcrossAllSheets() && $this->viewMode !== 'list') {
                $this->viewMode = 'list';
            }

            if (auth()->check() && $this->viewMode === 'table' && $this->canEditAcrossAllSheets()) {
                $currentTableStateSignature = $this->currentTableStateSignature();

                if ($this->tableStateSignature !== $currentTableStateSignature) {
                    if (!$this->sheetFilter) {
                        $this->loadSheetLeads();
                    } else {
                        $currentSheet = LeadSheet::find($this->sheetFilter);
                        if ($currentSheet && $currentSheet->created_by === auth()->id()) {
                            $this->loadSheetLeads();
                        }
                    }

                    $this->tableStateSignature = $currentTableStateSignature;
                }
            }

            $query = Lead::with(['creator', 'opener', 'leadSheet', 'leadGroup'])
                ->orderBy('created_at', 'desc');

            // Apply role-based filtering
            if (auth()->check()) {
                if (auth()->user()->isScrapper()) {
                    $query->where('created_by', auth()->id());
                } elseif (auth()->user()->isSalesTeam()) {
                    // Sales: leads from sheets they created OR sheets assigned to their teams
                    $userTeamIds = auth()->user()->teams()->pluck('teams.id')->toArray();
                    $query->whereHas('leadSheet', function ($q) use ($userTeamIds) {
                        $q->where('created_by', auth()->id())
                            ->orWhereHas('teams', fn ($t) => $t->whereIn('teams.id', $userTeamIds));
                    });
                }
                // Admin: no extra filter (sees all)
            }

            // Apply search filter
            if ($this->search) {
                $searchTerm = '%' . trim($this->search) . '%';
                $query->where(function($q) use ($searchTerm) {
                    $q->where('name', 'like', $searchTerm)
                      ->orWhere('email', 'like', $searchTerm)
                      ->orWhere('phone', 'like', $searchTerm)
                      ->orWhere('company', 'like', $searchTerm)
                      ->orWhere('services', 'like', $searchTerm)
                      ->orWhere('location', 'like', $searchTerm)
                      ->orWhere('position', 'like', $searchTerm)
                      ->orWhere('platform', 'like', $searchTerm)
                      ->orWhere('social_links', 'like', $searchTerm)
                      ->orWhere('detail', 'like', $searchTerm)
                      ->orWhere('web_link', 'like', $searchTerm);
                });
            }

            // Apply status filter
            if ($this->statusFilter) {
                $query->where('status', $this->statusFilter);
            }

            // Apply sheet filter
            if ($this->sheetFilter) {
                $query->where('lead_sheet_id', $this->sheetFilter);
            }
            // Apply group (tab) filter when viewing one sheet
            if ($this->sheetFilter) {
                $this->applySelectedGroupScope($query);
            }
            if (auth()->check() && auth()->user()->isScrapper() && $this->viewMode === 'list' && !$this->sheetFilter) {
                // For list view, show all leads if no sheet filter, but for table view, require sheet filter
                // This is already handled in the view logic
            }

            $leads = $query->paginate(10);

            $sheetsQuery = LeadSheet::with('teams')->orderBy('created_at', 'desc');
            if (auth()->check()) {
                if (auth()->user()->isScrapper()) {
                    $sheetsQuery->where('created_by', auth()->id());
                } elseif (auth()->user()->isSalesTeam()) {
                    // Sales: sheets they created OR sheets assigned to their teams
                    $userTeamIds = auth()->user()->teams()->pluck('teams.id')->toArray();
                    $sheetsQuery->where(function ($q) use ($userTeamIds) {
                        $q->where('created_by', auth()->id())
                            ->orWhereHas('teams', fn ($t) => $t->whereIn('teams.id', $userTeamIds));
                    });
                }
                // Admin: no extra filter (sees all sheets)
            }

            $groups = collect([]);
            if ($this->sheetFilter) {
                $groups = LeadGroup::where('lead_sheet_id', $this->sheetFilter)->orderBy('sort_order')->orderBy('name')->get();
            }

            return view('components.leads.⚡index', [
                'leads' => $leads,
                'tableRows' => [],
                'sheets' => $sheetsQuery->get(),
                'groups' => $groups,
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error rendering leads index: ' . $e->getMessage());
            return view('components.leads.⚡index', [
                'leads' => \Illuminate\Pagination\LengthAwarePaginator::empty(),
                'tableRows' => [],
                'sheets' => collect([]),
                'groups' => collect([]),
            ]);
        }
    }
};
?>

<div
    class="max-w-9xl mx-auto px-4 sm:px-6 lg:px-8 py-6"
    @if($viewMode === 'list') wire:poll.15s="refreshLeadsData" @endif
    x-data="{ importModalOpen: false }"
    x-on:close-import-modal.window="importModalOpen = false"
    x-on:keydown.escape.window="importModalOpen = false"
>
    <!-- Header -->
    <div class="mb-6">
        <div class="flex justify-between items-center">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">Leads Management</h1>
                <p class="text-gray-600 mt-1">Manage and track all your leads</p>
            </div>
            <div class="flex items-center gap-3">
                @if(auth()->user()->isScrapper())
                    <button
                        type="button"
                        x-on:click="importModalOpen = true; $wire.set('importSheetId', @js((string) ($sheetFilter ?? '')))"
                        class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-lg font-semibold shadow-sm hover:shadow-md transition-all flex items-center space-x-2"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v12m0-12l-4 4m4-4l4 4M4 20h16"></path>
                        </svg>
                        <span>Import Leads</span>
                    </button>
                @endif
                <button 
                    wire:click="exportLeads"
                    wire:loading.attr="disabled"
                    wire:target="exportLeads"
                    class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-lg font-semibold shadow-sm hover:shadow-md transition-all flex items-center space-x-2 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 16v-8m0 8l-3-3m3 3l3-3M5 20h14"></path>
                    </svg>
                    <span wire:loading.remove wire:target="exportLeads">Export Leads</span>
                    <span wire:loading wire:target="exportLeads">Exporting...</span>
                </button>
            </div>
        </div>
    </div>

        @if(auth()->user()->canCreateSheets())
            <div class="mb-6">
                <h2 class="text-lg font-semibold text-gray-900 mb-3">Create Sheet</h2>
                <livewire:sheets.create />
            </div>
        @endif

    <!-- Create Lead Modal -->
    @if(auth()->user()->canCreateLeads())
        <livewire:leads.create />
    @endif

    @if(auth()->user()->isScrapper())
        <div
            x-cloak
            x-show="importModalOpen"
            class="fixed inset-0 z-50 flex items-center justify-center px-4 py-6"
            style="display: none;"
        >
            <div class="absolute inset-0 bg-slate-900/55 backdrop-blur-sm" x-on:click="importModalOpen = false"></div>

            <div
                x-show="importModalOpen"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 translate-y-3 scale-95"
                x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                x-transition:leave-end="opacity-0 translate-y-3 scale-95"
                class="relative w-full max-w-2xl rounded-2xl bg-white shadow-2xl border border-slate-200 overflow-hidden"
            >
                <div class="bg-gradient-to-r from-blue-600 to-blue-700 px-6 py-5 text-white">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-xl font-semibold">Import Leads</h2>
                            <p class="mt-1 text-sm text-blue-100">Upload a CSV or XLSX file and choose the target sheet.</p>
                        </div>
                        <button
                            type="button"
                            x-on:click="importModalOpen = false"
                            class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-white/10 text-white transition hover:bg-white/20"
                        >
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                </div>

                <form wire:submit.prevent="importLeadsFile" class="p-6 space-y-5">
                    <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                        <div>
                            <label for="import_sheet_id" class="block text-sm font-semibold text-slate-700 mb-2">Target Sheet</label>
                            <select
                                id="import_sheet_id"
                                wire:model.live="importSheetId"
                                class="w-full px-4 py-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white @error('importSheetId') border-red-500 @enderror"
                            >
                                <option value="">Select a sheet...</option>
                                @foreach($sheets as $sheet)
                                    <option value="{{ $sheet->id }}">{{ $sheet->name }}</option>
                                @endforeach
                            </select>
                            @error('importSheetId') <span class="text-red-500 text-sm mt-2 block">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label for="import_file" class="block text-sm font-semibold text-slate-700 mb-2">CSV or XLSX File</label>
                            <input
                                id="import_file"
                                type="file"
                                wire:model="importFile"
                                accept=".csv,.xlsx"
                                class="w-full px-3 py-3 border border-slate-300 rounded-xl bg-white text-sm file:mr-3 file:px-3 file:py-1.5 file:border-0 file:rounded-md file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 @error('importFile') border-red-500 @enderror"
                            >
                            @error('importFile') <span class="text-red-500 text-sm mt-2 block">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                        Social links, website links, location, and details from your spreadsheet will be mapped during import.
                    </div>

                    <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <button
                            type="button"
                            x-on:click="importModalOpen = false"
                            class="px-5 py-2.5 rounded-xl border border-slate-300 text-slate-700 font-semibold hover:bg-slate-50 transition"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="importLeadsFile,importFile"
                            class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-xl shadow-sm hover:shadow-md transition-all disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            <span wire:loading.remove wire:target="importLeadsFile">Import Leads</span>
                            <span wire:loading wire:target="importLeadsFile">Importing...</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- Search and Filter -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 mb-6">
        <div class="flex flex-col sm:flex-row gap-4">
            <div class="flex-1 relative">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                </div>
                <input 
                    type="text" 
                    wire:model.live.debounce.300ms="search" 
                    placeholder="Search by name, email, phone, or company..." 
                    class="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                >
            </div>
            <select 
                wire:model.live="statusFilter" 
                class="px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white"
            >
                <option value="">All Statuses</option>
                <option value="wrong number">Wrong Number</option>
                <option value="follow up">Follow Up</option>
                <option value="hired us">Hired Us</option>
                <option value="hired someone">Hired Someone</option>
                <option value="no response">No Response</option>
            </select>
            @if(auth()->user()->isScrapper() || auth()->user()->isSalesTeam() || auth()->user()->isAdmin())
                <select 
                    wire:model.live="sheetFilter" 
                    wire:key="sheet-filter-{{ $sheetFilter ?: 'all' }}-{{ $sheets->count() }}"
                    class="px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white"
                >
                    <option value="">All Sheets</option>
                    @foreach($sheets as $sheet)
                        <option value="{{ $sheet->id }}">{{ $sheet->name }}</option>
                    @endforeach
                </select>
            @endif
        </div>
        @error('sheetFilter') <span class="text-red-500 text-sm mt-2 block">{{ $message }}</span> @enderror
    </div>

    <!-- Tabs (groups) when a sheet is selected -->
    @if($sheetFilter && (auth()->user()->isScrapper() || auth()->user()->isSalesTeam() || auth()->user()->isAdmin()))
        @php
            $currentSheet = $sheets->firstWhere('id', (int)$sheetFilter);
            $canEditTabs = $currentSheet && $currentSheet->created_by === auth()->id() && auth()->user()->isScrapper();
        @endphp
        <div class="bg-white rounded-t-xl border border-gray-200 border-b-0 overflow-hidden mb-0" wire:key="tabs-{{ $sheetFilter ?: 'none' }}-{{ $groups->count() }}">
            <div class="flex items-end border-b border-gray-200 bg-gray-50/80 overflow-x-auto">
                @foreach($groups as $group)
                    @if($editingGroupId === $group->id)
                        <form wire:submit.prevent="updateGroup" class="flex items-center px-4 py-2.5 border-b-2 -mb-px border-blue-600">
                            <input 
                                type="text" 
                                wire:model="editingGroupName" 
                                placeholder="Enter tab name..." 
                                maxlength="255"
                                wire:keydown.escape="cancelEditingGroup"
                                wire:keydown.enter="updateGroup"
                                autofocus
                                class="px-2 py-1 text-sm border border-gray-300 rounded focus:ring-2 focus:ring-blue-500 focus:border-transparent w-40"
                            >
                            <button type="submit" class="ml-2 px-3 py-1 text-sm text-white bg-blue-600 hover:bg-blue-700 rounded font-medium">Save</button>
                            <button type="button" wire:click="cancelEditingGroup" class="ml-1 px-3 py-1 text-sm text-gray-600 hover:bg-gray-200 rounded font-medium">Cancel</button>
                        </form>
                    @else
                        <button type="button"
                            wire:click="selectGroup({{ $group->id }})"
                            @if($canEditTabs) wire:dblclick="startEditingGroup({{ $group->id }})" @endif
                            class="group px-4 py-2.5 text-sm font-medium whitespace-nowrap border-b-2 -mb-px transition-colors flex items-center gap-1.5 {{ (string)$groupFilter === (string)$group->id ? 'bg-white text-blue-600 border-blue-600' : 'text-gray-600 border-transparent hover:bg-gray-100' }}"
                            {!! $canEditTabs ? 'title="Double-click to rename"' : '' !!}
                        >
                            <span>{{ $group->name }}</span>
                            @if($canEditTabs)
                                <span class="opacity-0 group-hover:opacity-100 hover:opacity-100 text-gray-400 hover:text-red-600 cursor-pointer text-base leading-none select-none" onclick="event.stopPropagation(); if(confirm('Remove this tab? Leads in it will be deleted for users.')) { @this.call('removeGroup', {{ $group->id }}) }">×</span>
                            @endif
                        </button>
                    @endif
                @endforeach
                @if($canEditTabs)
                    <form wire:submit.prevent="addGroup" class="flex items-center gap-1 ml-1 pb-1.5 border-b-2 border-transparent -mb-px">
                        <input 
                            type="text" 
                            wire:model.defer="newGroupName" 
                            placeholder="+ New tab" 
                            maxlength="255"
                            wire:key="add-group-input-{{ $addGroupFormKey }}"
                            class="w-24 px-2 py-1.5 text-sm border border-gray-300 rounded focus:ring-2 focus:ring-blue-500"
                        >
                        <button type="submit" class="px-2 py-1.5 text-sm text-blue-600 hover:bg-blue-50 rounded font-medium">Add</button>
                    </form>
                    @error('newGroupName') <span class="text-red-500 text-xs ml-2">{{ $message }}</span> @enderror
                @endif
            </div>
        </div>
    @endif

    <!-- View Mode Toggle for Scrapper and Front Sale (for their own sheets) -->
    @php
        $currentSheetForView = $sheetFilter ? $sheets->firstWhere('id', (int)$sheetFilter) : null;
        $canEditInlineLeads = auth()->user()->isScrapper();
        $canUseTableView = $currentSheetForView && $currentSheetForView->created_by === auth()->id() && auth()->user()->isScrapper();
        $canViewAllLeads = !$sheetFilter && (auth()->user()->isScrapper() || auth()->user()->isSalesTeam() || auth()->user()->isAdmin());
        $canEditAllSheetsTable = !$sheetFilter && auth()->user()->isScrapper();
    @endphp
    @if($canEditInlineLeads && ($canUseTableView || $canViewAllLeads))
        <div class="mb-4 flex justify-end">
            <div class="inline-flex rounded-lg border border-gray-300 bg-white shadow-sm">
                @php
                    $tableQueryParams = ['viewMode' => 'table'];
                    if ($search !== '') {
                        $tableQueryParams['search'] = $search;
                    }
                    if ($statusFilter !== '') {
                        $tableQueryParams['statusFilter'] = $statusFilter;
                    }
                    if ($sheetFilter !== '') {
                        $tableQueryParams['sheetFilter'] = $sheetFilter;
                    }
                    if ($groupFilter !== '' && $groupFilter !== null) {
                        $tableQueryParams['groupFilter'] = $groupFilter;
                    }
                @endphp
                <a 
                    href="{{ route('leads.index', $tableQueryParams) }}"
                    class="px-4 py-2 text-sm font-semibold rounded-l-lg transition-colors {{ $viewMode === 'table' ? 'bg-blue-600 text-white' : 'text-gray-700 hover:bg-gray-50' }}"
                >
                    <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                    </svg>
                    Table View
                </a>
                @php
                    $listQueryParams = $tableQueryParams;
                    $listQueryParams['viewMode'] = 'list';
                @endphp
                <a 
                    href="{{ route('leads.index', $listQueryParams) }}"
                    class="px-4 py-2 text-sm font-semibold rounded-r-lg transition-colors {{ $viewMode === 'list' ? 'bg-blue-600 text-white' : 'text-gray-700 hover:bg-gray-50' }}"
                >
                    <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"></path>
                    </svg>
                    List View
                </a>
            </div>
        </div>
    @endif

    <!-- Leads Table View -->
    @if(($canUseTableView || $canEditAllSheetsTable) && $viewMode === 'table')
        @if($sheetFilter && ($groupFilter === '' || $groupFilter === null) && $this->shouldRequireSelectedGroupForTableView())
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 text-center text-gray-600">
                Select or create a tab/group to start adding leads in this sheet.
            </div>
        @else
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
                <div class="overflow-x-auto" wire:key="sheet-table-{{ $sheetFilter ?: 'none' }}-{{ $groupFilter !== '' ? $groupFilter : 'none' }}">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                @if(!$sheetFilter)
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Sheet</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Group</th>
                                @endif
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Name</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Email</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Services</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Phone No</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Location</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Position</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Platform</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Social Links</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Details</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Web Link</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        @php
                            $isFilteredTableView = $this->hasActiveTableFilters();
                            $tableDisplayRows = $leadsData;
                            $draftRowCount = $isFilteredTableView ? 0 : count($draftLeadRows);
                        @endphp
                        <tbody class="bg-white divide-y divide-gray-200">
                            @if(!$isFilteredTableView && $this->shouldAppendTableDraftRow())
                                @for($draftIndex = $draftRowCount - 1; $draftIndex >= 0; $draftIndex--)
                                    @php $draftRow = $draftLeadRows[$draftIndex] ?? []; @endphp
                                    <tr wire:key="lead-draft-row-{{ $draftRow['_row_key'] ?? ('draft-' . $draftIndex) }}" class="{{ $this->isTableScratchEmptyRow($draftRow) ? 'bg-blue-50' : 'bg-white hover:bg-gray-50' }}">
                                        @if(!$sheetFilter)
                                            <td class="px-4 py-2 text-sm text-gray-500 whitespace-nowrap" colspan="2">—</td>
                                        @endif
                                        <td class="px-4 py-2">
                                            <input type="text" wire:model.live.debounce.500ms="draftLeadRows.{{ $draftIndex }}.name" class="w-48 px-2 py-1 border border-gray-300 rounded" placeholder="Name">
                                        </td>
                                        <td class="px-4 py-2">
                                            <input type="email" wire:model.live.debounce.500ms="draftLeadRows.{{ $draftIndex }}.email" class="w-56 px-2 py-1 border border-gray-300 rounded" placeholder="Email">
                                        </td>
                                        <td class="px-4 py-2">
                                            <input type="text" wire:model.live.debounce.500ms="draftLeadRows.{{ $draftIndex }}.services" class="w-40 px-2 py-1 border border-gray-300 rounded" placeholder="Services">
                                        </td>
                                        <td class="px-4 py-2">
                                            <input type="text" wire:model.live.debounce.500ms="draftLeadRows.{{ $draftIndex }}.phone" class="w-40 px-2 py-1 border border-gray-300 rounded" placeholder="Phone">
                                        </td>
                                        <td class="px-4 py-2">
                                            <input type="text" wire:model.live.debounce.500ms="draftLeadRows.{{ $draftIndex }}.location" class="w-40 px-2 py-1 border border-gray-300 rounded" placeholder="Location">
                                        </td>
                                        <td class="px-4 py-2">
                                            <input type="text" wire:model.live.debounce.500ms="draftLeadRows.{{ $draftIndex }}.position" class="w-40 px-2 py-1 border border-gray-300 rounded" placeholder="Position">
                                        </td>
                                        <td class="px-4 py-2">
                                            <input type="text" wire:model.live.debounce.500ms="draftLeadRows.{{ $draftIndex }}.platform" class="w-32 px-2 py-1 border border-gray-300 rounded" placeholder="Platform">
                                        </td>
                                        <td class="px-4 py-2">
                                            <textarea wire:model.live.debounce.500ms="draftLeadRows.{{ $draftIndex }}.social_links" class="w-56 px-2 py-1 border border-gray-300 rounded" rows="2" placeholder="Social links (LinkedIn, Facebook, Instagram)"></textarea>
                                        </td>
                                        <td class="px-4 py-2">
                                            <input type="text" wire:model.live.debounce.500ms="draftLeadRows.{{ $draftIndex }}.detail" class="w-56 px-2 py-1 border border-gray-300 rounded" placeholder="Details">
                                        </td>
                                        <td class="px-4 py-2">
                                            <input type="text" wire:model.live.debounce.500ms="draftLeadRows.{{ $draftIndex }}.web_link" class="w-48 px-2 py-1 border border-gray-300 rounded" placeholder="Web link">
                                        </td>
                                        <td class="px-4 py-2"></td>
                                    </tr>
                                @endfor
                            @endif
                            @forelse($tableDisplayRows as $index => $row)
                                @php
                                    $rowKey = $row['_row_key'] ?? ('idx-' . $index);
                                @endphp
                                <tr
                                    wire:key="lead-row-{{ $rowKey }}"
                                    class="{{ $isFilteredTableView ? 'bg-white hover:bg-gray-50' : ($this->isTableScratchEmptyRow($row) ? 'bg-blue-50' : 'bg-white hover:bg-gray-50') }}"
                                >
                                    @if(!$sheetFilter)
                                        <td class="px-4 py-2 text-sm text-gray-700 whitespace-nowrap">
                                            {{ $this->displayValue($row['sheet_name'] ?? null) }}
                                        </td>
                                        <td class="px-4 py-2 text-sm text-gray-600 whitespace-nowrap">
                                            {{ $this->displayValue($row['group_name'] ?? null, 'Ungrouped') }}
                                        </td>
                                    @endif
                                    <td class="px-4 py-2">
                                        @if($isFilteredTableView)
                                            <input type="text" value="{{ $row['name'] ?? '' }}" readonly class="w-48 px-2 py-1 border border-gray-200 bg-gray-50 rounded text-gray-700">
                                        @else
                                            <input type="text" wire:key="lead-name-{{ $rowKey }}" wire:model.live.debounce.500ms="leadsData.{{ $index }}.name" class="w-48 px-2 py-1 border border-gray-300 rounded" placeholder="Name">
                                        @endif
                                    </td>
                                    <td class="px-4 py-2">
                                        @if($isFilteredTableView)
                                            <input type="text" value="{{ $row['email'] ?? '' }}" readonly class="w-56 px-2 py-1 border border-gray-200 bg-gray-50 rounded text-gray-700">
                                        @else
                                            <input type="email" wire:key="lead-email-{{ $rowKey }}" wire:model.live.debounce.500ms="leadsData.{{ $index }}.email" class="w-56 px-2 py-1 border border-gray-300 rounded" placeholder="Email">
                                        @endif
                                    </td>
                                    <td class="px-4 py-2">
                                        @if($isFilteredTableView)
                                            <input type="text" value="{{ $row['services'] ?? '' }}" readonly class="w-40 px-2 py-1 border border-gray-200 bg-gray-50 rounded text-gray-700">
                                        @else
                                            <input type="text" wire:key="lead-services-{{ $rowKey }}" wire:model.live.debounce.500ms="leadsData.{{ $index }}.services" class="w-40 px-2 py-1 border border-gray-300 rounded" placeholder="Services">
                                        @endif
                                    </td>
                                    <td class="px-4 py-2">
                                        @if($isFilteredTableView)
                                            <input type="text" value="{{ $row['phone'] ?? '' }}" readonly class="w-40 px-2 py-1 border border-gray-200 bg-gray-50 rounded text-gray-700">
                                        @else
                                            <input type="text" wire:key="lead-phone-{{ $rowKey }}" wire:model.live.debounce.500ms="leadsData.{{ $index }}.phone" class="w-40 px-2 py-1 border border-gray-300 rounded" placeholder="Phone">
                                        @endif
                                    </td>
                                    <td class="px-4 py-2">
                                        @if($isFilteredTableView)
                                            <input type="text" value="{{ $row['location'] ?? '' }}" readonly class="w-40 px-2 py-1 border border-gray-200 bg-gray-50 rounded text-gray-700">
                                        @else
                                            <input type="text" wire:key="lead-location-{{ $rowKey }}" wire:model.live.debounce.500ms="leadsData.{{ $index }}.location" class="w-40 px-2 py-1 border border-gray-300 rounded" placeholder="Location">
                                        @endif
                                    </td>
                                    <td class="px-4 py-2">
                                        @if($isFilteredTableView)
                                            <input type="text" value="{{ $row['position'] ?? '' }}" readonly class="w-40 px-2 py-1 border border-gray-200 bg-gray-50 rounded text-gray-700">
                                        @else
                                            <input type="text" wire:key="lead-position-{{ $rowKey }}" wire:model.live.debounce.500ms="leadsData.{{ $index }}.position" class="w-40 px-2 py-1 border border-gray-300 rounded" placeholder="Position">
                                        @endif
                                    </td>
                                    <td class="px-4 py-2">
                                        @if($isFilteredTableView)
                                            <input type="text" value="{{ $row['platform'] ?? '' }}" readonly class="w-32 px-2 py-1 border border-gray-200 bg-gray-50 rounded text-gray-700">
                                        @else
                                            <input type="text" wire:key="lead-platform-{{ $rowKey }}" wire:model.live.debounce.500ms="leadsData.{{ $index }}.platform" class="w-32 px-2 py-1 border border-gray-300 rounded" placeholder="Platform">
                                        @endif
                                    </td>
                                    <td class="px-4 py-2">
                                        @if($isFilteredTableView)
                                            <textarea readonly class="w-56 px-2 py-1 border border-gray-200 bg-gray-50 rounded text-gray-700" rows="2">{{ $row['social_links'] ?? '' }}</textarea>
                                        @else
                                            <textarea wire:key="lead-social-links-{{ $rowKey }}" wire:model.live.debounce.500ms="leadsData.{{ $index }}.social_links" class="w-56 px-2 py-1 border border-gray-300 rounded" rows="2" placeholder="Social links (LinkedIn, Facebook, Instagram)"></textarea>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2">
                                        @if($isFilteredTableView)
                                            <input type="text" value="{{ $row['detail'] ?? '' }}" readonly class="w-56 px-2 py-1 border border-gray-200 bg-gray-50 rounded text-gray-700">
                                        @else
                                            <input type="text" wire:key="lead-detail-{{ $rowKey }}" wire:model.live.debounce.500ms="leadsData.{{ $index }}.detail" class="w-56 px-2 py-1 border border-gray-300 rounded" placeholder="Details">
                                        @endif
                                    </td>
                                    <td class="px-4 py-2">
                                        @if($isFilteredTableView)
                                            <input type="text" value="{{ $row['web_link'] ?? '' }}" readonly class="w-48 px-2 py-1 border border-gray-200 bg-gray-50 rounded text-gray-700">
                                        @else
                                            <input type="text" wire:key="lead-web-link-{{ $rowKey }}" wire:model.live.debounce.500ms="leadsData.{{ $index }}.web_link" class="w-48 px-2 py-1 border border-gray-300 rounded" placeholder="Web link">
                                        @endif
                                    </td>
                                    <td class="px-4 py-2">
                                        @if(!empty($row['id']))
                                            @if($isFilteredTableView)
                                                <div class="flex items-center gap-2">
                                                    <a
                                                        href="{{ route('leads.show', ['id' => $row['id'], 'return_to' => route('leads.index', $this->currentLeadsPageQueryParams())]) }}"
                                                        class="px-3 py-1 text-xs font-semibold text-blue-700 bg-blue-50 hover:bg-blue-100 rounded"
                                                    >
                                                        View
                                                    </a>
                                                    <button
                                                        type="button"
                                                        class="px-3 py-1 text-xs font-semibold text-red-700 bg-red-50 hover:bg-red-100 rounded"
                                                        onclick="event.stopPropagation(); if(confirm('Delete this lead?')) { @this.call('deleteLeadRow', {{ $row['id'] }}) }"
                                                    >
                                                        Delete
                                                    </button>
                                                </div>
                                            @else
                                                <button
                                                    type="button"
                                                    class="px-3 py-1 text-xs font-semibold text-red-700 bg-red-50 hover:bg-red-100 rounded"
                                                    onclick="event.stopPropagation(); if(confirm('Delete this lead?')) { @this.call('deleteLeadRow', {{ $row['id'] }}) }"
                                                >
                                                    Delete
                                                </button>
                                            @endif
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                @if($isFilteredTableView || !$this->shouldAppendTableDraftRow())
                                    <tr>
                                        <td colspan="{{ $sheetFilter ? '11' : '13' }}" class="px-6 py-12 text-center text-gray-500">
                                            No leads found.
                                        </td>
                                    </tr>
                                @endif
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-gray-200 bg-gray-50 px-4 py-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-sm text-gray-600">
                        Loaded {{ $tableLoadedCount }} row{{ $tableLoadedCount === 1 ? '' : 's' }}
                        @if($hasMoreTableRows)
                            <span class="text-gray-500">. More rows are ready to stream in.</span>
                        @endif
                    </p>
                    @if($hasMoreTableRows)
                        <button
                            type="button"
                            wire:click="loadMoreTableRows"
                            wire:loading.attr="disabled"
                            wire:target="loadMoreTableRows"
                            class="inline-flex items-center justify-center px-4 py-2 text-sm font-semibold text-blue-700 bg-white border border-blue-200 rounded-lg hover:bg-blue-50 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            <span wire:loading.remove wire:target="loadMoreTableRows">Load More Rows</span>
                            <span wire:loading wire:target="loadMoreTableRows">Loading...</span>
                        </button>
                    @endif
                </div>
            </div>
        @endif
    @elseif($canEditInlineLeads && $canViewAllLeads && $viewMode === 'table')
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Lead</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Contact</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Sheet</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Group</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Services</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Created By</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @forelse($leads as $lead)
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="w-10 h-10 bg-blue-600 rounded-lg flex items-center justify-center text-white font-bold text-sm mr-3">
                                            {{ strtoupper(substr($lead->name, 0, 1)) }}
                                        </div>
                                        <div>
                                            <a href="{{ route('leads.show', ['id' => $lead->id, 'return_to' => route('leads.index', $this->currentLeadsPageQueryParams())]) }}" class="text-sm font-semibold text-gray-900 hover:text-blue-600 transition-colors">
                                                {{ $lead->name }}
                                            </a>
                                            <div class="text-xs text-gray-500">{{ $lead->lead_date?->format('M d, Y') ?? '—' }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">{{ $this->displayValue($lead->email) }}</div>
                                    <div class="text-xs text-gray-500">{{ $this->displayValue($lead->phone) }}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    {{ $this->displayValue($lead->leadSheet?->name) }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    {{ $lead->leadGroup->name ?? 'Ungrouped' }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                                    {{ $this->displayValue($lead->services) }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @php
                                    $statusColors = [
                                        'wrong number' => 'bg-red-100 text-red-700',
                                        'follow up' => 'bg-amber-100 text-amber-700',
                                        'hired us' => 'bg-emerald-100 text-emerald-700',
                                        'hired someone' => 'bg-purple-100 text-purple-700',
                                        'no response' => 'bg-gray-100 text-gray-700',
                                    ];
                                @endphp
                                <span class="px-3 py-1 text-xs font-semibold rounded-full {{ $statusColors[$lead->status] ?? 'bg-gray-100 text-gray-700' }}">
                                    {{ ucwords($lead->status) }}
                                </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">{{ $lead->creator->name }}</div>
                                    <div class="text-xs text-gray-500">{{ $lead->created_at->format('M d, Y') }}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <a href="{{ route('leads.show', ['id' => $lead->id, 'return_to' => route('leads.index', $this->currentLeadsPageQueryParams())]) }}" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white text-sm font-semibold rounded-lg hover:bg-blue-700 transition-colors">
                                        View
                                        <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                        </svg>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-6 py-12 text-center">
                                    <svg class="w-16 h-16 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                                    </svg>
                                    <p class="text-gray-500 font-medium">No leads found</p>
                                    <p class="text-gray-400 text-sm mt-1">Try adjusting your search or filters</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="mt-6">
            {{ $leads->links() }}
        </div>
    @elseif(($canUseTableView || $canViewAllLeads) && $viewMode === 'list')
        <!-- List View for Scrapper (same as sales team view) -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Lead</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Contact</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Sheet</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Group</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Opened By</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Created By</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @forelse($leads as $lead)
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="w-10 h-10 bg-blue-600 rounded-lg flex items-center justify-center text-white font-bold text-sm mr-3">
                                            {{ strtoupper(substr($lead->name, 0, 1)) }}
                                        </div>
                                        <a href="{{ route('leads.show', ['id' => $lead->id, 'return_to' => route('leads.index', $this->currentLeadsPageQueryParams())]) }}" class="text-sm font-semibold text-gray-900 hover:text-blue-600 transition-colors">
                                            {{ $lead->name }}
                                        </a>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">{{ $this->displayValue($lead->email) }}</div>
                                    <div class="text-xs text-gray-500">{{ $this->displayValue($lead->phone) }}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">{{ $this->displayValue($lead->leadSheet?->name) }}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    {{ $lead->leadGroup->name ?? 'Ungrouped' }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @php
                                    $statusColors = [
                                        'wrong number' => 'bg-red-100 text-red-700',
                                        'follow up' => 'bg-amber-100 text-amber-700',
                                        'hired us' => 'bg-emerald-100 text-emerald-700',
                                        'hired someone' => 'bg-purple-100 text-purple-700',
                                        'no response' => 'bg-gray-100 text-gray-700',
                                    ];
                                @endphp
                                <span class="px-3 py-1 text-xs font-semibold rounded-full {{ $statusColors[$lead->status] ?? 'bg-gray-100 text-gray-700' }}">
                                    {{ ucwords($lead->status) }}
                                </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @if($lead->opener)
                                        <div class="flex items-center">
                                            <div class="w-8 h-8 bg-emerald-500 rounded-full flex items-center justify-center text-white text-xs font-bold mr-2">
                                                {{ strtoupper(substr($lead->opener->name, 0, 1)) }}
                                            </div>
                                            <div>
                                                <div class="text-sm font-medium text-gray-900">{{ $lead->opener->name }}</div>
                                                <div class="text-xs text-gray-500">{{ $lead->opened_at?->format('M d, H:i') }}</div>
                                            </div>
                                        </div>
                                    @else
                                        <span class="text-sm text-gray-400 italic">Not opened</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">{{ $lead->creator->name }}</div>
                                    <div class="text-xs text-gray-500">{{ $lead->created_at->format('M d, Y') }}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <a href="{{ route('leads.show', ['id' => $lead->id, 'return_to' => route('leads.index', $this->currentLeadsPageQueryParams())]) }}" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white text-sm font-semibold rounded-lg hover:bg-blue-700 transition-colors">
                                        View
                                        <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                        </svg>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-6 py-12 text-center">
                                    <svg class="w-16 h-16 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                                    </svg>
                                    <p class="text-gray-500 font-medium">No leads found</p>
                                    <p class="text-gray-400 text-sm mt-1">Try adjusting your search or filters</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <!-- Pagination for List View -->
        <div class="mt-6">
            {{ $leads->links() }}
        </div>
    @elseif(!auth()->user()->isScrapper())
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Lead</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Contact</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Company</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Group</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Opened By</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Created By</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @forelse($leads as $lead)
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="w-10 h-10 bg-blue-600 rounded-lg flex items-center justify-center text-white font-bold text-sm mr-3">
                                            {{ strtoupper(substr($lead->name, 0, 1)) }}
                                        </div>
                                        <a href="{{ route('leads.show', ['id' => $lead->id, 'return_to' => route('leads.index', $this->currentLeadsPageQueryParams())]) }}" class="text-sm font-semibold text-gray-900 hover:text-blue-600 transition-colors">
                                            {{ $lead->name }}
                                        </a>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">{{ $this->displayValue($lead->email) }}</div>
                                    <div class="text-xs text-gray-500">{{ $this->displayValue($lead->phone) }}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">{{ $this->displayValue($lead->company) }}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    {{ $lead->leadGroup->name ?? 'Ungrouped' }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @php
                                    $statusColors = [
                                        'wrong number' => 'bg-red-100 text-red-700',
                                        'follow up' => 'bg-amber-100 text-amber-700',
                                        'hired us' => 'bg-emerald-100 text-emerald-700',
                                        'hired someone' => 'bg-purple-100 text-purple-700',
                                        'no response' => 'bg-gray-100 text-gray-700',
                                    ];
                                @endphp
                                <span class="px-3 py-1 text-xs font-semibold rounded-full {{ $statusColors[$lead->status] ?? 'bg-gray-100 text-gray-700' }}">
                                    {{ ucwords($lead->status) }}
                                </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @if($lead->opener)
                                        <div class="flex items-center">
                                            <div class="w-8 h-8 bg-emerald-500 rounded-full flex items-center justify-center text-white text-xs font-bold mr-2">
                                                {{ strtoupper(substr($lead->opener->name, 0, 1)) }}
                                            </div>
                                            <div>
                                                <div class="text-sm font-medium text-gray-900">{{ $lead->opener->name }}</div>
                                                <div class="text-xs text-gray-500">{{ $lead->opened_at?->format('M d, H:i') }}</div>
                                            </div>
                                        </div>
                                    @else
                                        <span class="text-sm text-gray-400 italic">Not opened</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">{{ $lead->creator->name }}</div>
                                    <div class="text-xs text-gray-500">{{ $lead->created_at->format('M d, Y') }}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <a href="{{ route('leads.show', ['id' => $lead->id, 'return_to' => route('leads.index', $this->currentLeadsPageQueryParams())]) }}" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white text-sm font-semibold rounded-lg hover:bg-blue-700 transition-colors">
                                        View
                                        <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                        </svg>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-6 py-12 text-center">
                                    <svg class="w-16 h-16 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                                    </svg>
                                    <p class="text-gray-500 font-medium">No leads found</p>
                                    <p class="text-gray-400 text-sm mt-1">Try adjusting your search or filters</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="mt-6">
            {{ $leads->links() }}
        </div>
    @endif

    @if(auth()->user()->isScrapper() && $viewMode === 'table')
        <div class="fixed bottom-6 right-6 z-40 flex flex-col gap-2">
            <button
                type="button"
                class="w-11 h-11 rounded-full bg-blue-600 text-white shadow-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
                title="Scroll to top"
                aria-label="Scroll to top"
                onclick="window.scrollTo({ top: 0, behavior: 'smooth' });"
            >
                <svg class="w-5 h-5 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"></path>
                </svg>
            </button>
            <button
                type="button"
                class="w-11 h-11 rounded-full bg-blue-600 text-white shadow-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
                title="Scroll to bottom"
                aria-label="Scroll to bottom"
                onclick="window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });"
            >
                <svg class="w-5 h-5 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                </svg>
            </button>
        </div>
    @endif
</div>
