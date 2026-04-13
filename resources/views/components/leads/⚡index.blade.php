<?php

use App\Models\Lead;
use App\Models\LeadGroup;
use App\Models\LeadSheet;
use App\Models\User;
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
    public $editingGroupId = null;
    public $editingGroupName = '';
    public $addGroupFormKey = 1;
    private $rowUniqueIds = [];
    protected $queryString = [
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
            $this->pendingCreates = [];
            $this->leadsData = [];
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
        $this->pendingCreates = [];
        $this->leadsData = [];
        $this->resetPage();
        $this->loadSheetLeads();
    }

    public function updatedGroupFilter()
    {
        $this->pendingCreates = [];
        $this->leadsData = [];
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
        $this->pendingCreates = [];
        $this->leadsData = [];
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
        $this->ensureEmptyRow();
        $this->dispatch('show-toast', ['type' => 'success', 'message' => 'Lead deleted successfully.']);
        $this->dispatch('lead-updated');
    }

    #[On('lead-created')]
    #[On('lead-updated')]
    #[On('lead-opened')]
    public function refreshLeads()
    {
        // Reset to first page when new leads are added
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
        $this->pendingCreates = [];
        $this->leadsData = [];
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

            $targetGroup = $this->resolveImportTargetGroup($sheet->id);

            $extension = strtolower($this->importFile->getClientOriginalExtension() ?: pathinfo($this->importFile->getClientOriginalName(), PATHINFO_EXTENSION));
            $reader = $this->createImportReader($extension);
            $reader->open($this->importFile->getRealPath());
            $readerOpened = true;

            $headerMap = [];
            $rowNumber = 0;
            $imported = 0;
            $skipped = 0;

            foreach ($reader->getSheetIterator() as $sheetIterator) {
                foreach ($sheetIterator->getRowIterator() as $row) {
                    $rowNumber++;
                    $rowValues = $row->toArray();

                    if ($rowNumber === 1) {
                        $headerMap = $this->buildImportHeaderMap($rowValues);

                        if (!isset($headerMap['name'])) {
                            $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Import file must contain a Name column.']);
                            return;
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

                    Lead::create([
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

                    $imported++;
                }

                // Import only the first worksheet for now.
                break;
            }

            if ($imported === 0) {
                $this->dispatch('show-toast', ['type' => 'warning', 'message' => 'No rows were imported. Please check your file data.']);
                return;
            }

            $this->sheetFilter = (string) $sheet->id;
            $this->groupFilter = (string) $targetGroup->id;
            $this->importSheetId = (string) $sheet->id;
            $this->importFile = null;
            $this->resetValidation(['importFile', 'importSheetId']);

            $this->pendingCreates = [];
            $this->leadsData = [];
            $this->loadSheetLeads();
            $this->resetPage();
            $this->dispatch('lead-created');
            $this->dispatch('close-import-modal');

            $message = "Imported {$imported} lead(s) successfully.";
            if ($skipped > 0) {
                $message .= " Skipped {$skipped} row(s) without a name.";
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

    protected function resolveImportTargetGroup(int $sheetId): LeadGroup
    {
        if ($this->sheetFilter && (int) $this->sheetFilter === $sheetId && $this->groupFilter) {
            $selectedGroup = LeadGroup::where('id', (int) $this->groupFilter)
                ->where('lead_sheet_id', $sheetId)
                ->first();

            if ($selectedGroup) {
                return $selectedGroup;
            }
        }

        $firstGroup = LeadGroup::where('lead_sheet_id', $sheetId)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->first();

        if ($firstGroup) {
            return $firstGroup;
        }

        $nextSortOrder = (LeadGroup::where('lead_sheet_id', $sheetId)->max('sort_order') ?? 0) + 1;

        return LeadGroup::create([
            'lead_sheet_id' => $sheetId,
            'name' => 'Imported',
            'sort_order' => $nextSortOrder,
        ]);
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
        return match ($normalizedHeader) {
            'name', 'fullname', 'leadname' => 'name',
            'email', 'emailaddress' => 'email',
            'phone', 'phoneno', 'phonenumber', 'mobile', 'mobileno' => 'phone',
            'company' => 'company',
            'services', 'service', 'job' => 'services',
            'location', 'city', 'country', 'address' => 'location',
            'position', 'designation', 'jobtitle', 'role' => 'position',
            'platform', 'source', 'leadsource' => 'platform',
            'linkedin', 'linkedinurl', 'linkedinprofile', 'links', 'link', 'sociallink', 'sociallinks', 'facebooklink', 'facebookurl', 'instagramlink', 'instagramurl', 'instalink', 'instaurl' => 'social_links',
            'detail', 'details', 'description', 'budgetotherdetail', 'budgetotherdetails', 'otherdetail', 'otherdetails' => 'detail',
            'weblink', 'websitelink', 'weburl', 'website', 'websiteurl', 'url' => 'web_link',
            'notes', 'note', 'comment', 'comments' => 'notes',
            'leaddate', 'date' => 'lead_date',
            'status' => 'status',
            default => null,
        };
    }

    protected function normalizeImportHeader(mixed $header): string
    {
        $stringHeader = is_string($header) ? $header : (string) $header;
        $stringHeader = strtolower(trim($stringHeader));

        return preg_replace('/[^a-z0-9]+/', '', $stringHeader) ?? '';
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
                    $this->ensureEmptyRow();
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

            if (!$this->sheetFilter && empty($row['id'])) {
                return;
            }

            if ($this->sheetFilter && ($this->groupFilter === '' || $this->groupFilter === null) && empty($row['id'])) {
                return;
            }

            if (empty($row['id'])) {
                if (empty($row['name'])) {
                    return;
                }

                if (!empty($this->pendingCreates[$index])) {
                    return;
                }

                $this->pendingCreates[$index] = true;

                try {
                    $existingLead = Lead::where('created_by', auth()->id())
                        ->where('lead_sheet_id', $this->sheetFilter)
                        ->where('name', $row['name'])
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
                        $this->leadsData[$index]['id'] = $existingLead->id;
                        $this->pendingCreates[$index] = false;
                        return;
                    }

                    $lead = Lead::create([
                        'social_links' => $normalizedSocialLinks = $this->normalizeSocialLinks(!empty($row['social_links']) ? trim($row['social_links']) : null),
                        'created_by' => auth()->id(),
                        'lead_sheet_id' => $this->sheetFilter,
                        'lead_group_id' => $this->groupFilter ?: null,
                        'lead_date' => now()->toDateString(),
                        'status' => 'no response',
                        'name' => trim($row['name']),
                        'email' => !empty($row['email']) ? trim($row['email']) : null,
                        'services' => !empty($row['services']) ? trim($row['services']) : null,
                        'phone' => !empty($row['phone']) ? trim($row['phone']) : null,
                        'location' => !empty($row['location']) ? trim($row['location']) : null,
                        'position' => !empty($row['position']) ? trim($row['position']) : null,
                        'platform' => !empty($row['platform']) ? trim($row['platform']) : null,
                        'detail' => !empty($row['detail']) ? trim($row['detail']) : null,
                        'web_link' => !empty($row['web_link']) ? trim($row['web_link']) : null,
                    ]);

                    // Notify sales users and broadcast the update to their active clients.
                    $salesUsers = User::whereIn('role', ['sales', 'upsale', 'front_sale'])->get();
                    
                    if ($salesUsers->isNotEmpty()) {
                        NotificationService::createForUsers(
                            $salesUsers,
                            $lead,
                            'new_lead',
                            "New lead '{$lead->name}' has been added by " . auth()->user()->name
                        );
                    }

                    $this->leadsData[$index]['id'] = $lead->id;
                    $this->dispatch('lead-created');
                    $this->ensureEmptyRow();
                    $this->pendingCreates[$index] = false;
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('Error creating lead in table: ' . $e->getMessage());
                    $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Failed to save lead. Please try again.']);
                    $this->pendingCreates[$index] = false;
                }
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
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Error updating lead: ' . $e->getMessage());
                $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Failed to update lead.']);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error in updatedLeadsData: ' . $e->getMessage());
        }
    }

    public function loadSheetLeads()
    {
        try {
            if (!auth()->check()) {
                $this->leadsData = [$this->emptyRow()];
                return;
            }

            if (!$this->sheetFilter) {
                if (!$this->canEditAcrossAllSheets()) {
                    $this->leadsData = [];
                    return;
                }

                $this->leadsData = Lead::with(['leadSheet', 'leadGroup'])
                    ->where('created_by', auth()->id())
                    ->tap(fn ($query) => $this->applyTableSearchAndStatusFilters($query))
                    ->orderBy('created_at', 'desc')
                    ->get()
                    ->map(function ($lead) {
                        return [
                            '_row_key' => 'lead-'.$lead->id,
                            'id' => $lead->id,
                            'sheet_name' => $lead->leadSheet?->name ?? '',
                            'group_name' => $lead->leadGroup?->name ?? 'Ungrouped',
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
                    })
                    ->values()
                    ->all();

                return;
            }

            $sheet = LeadSheet::find($this->sheetFilter);
            if (!$sheet || $sheet->created_by !== auth()->id()) {
                $this->leadsData = [$this->emptyRow()];
                $this->dispatch('show-toast', ['type' => 'error', 'message' => 'Selected sheet not found or access denied.']);
                return;
            }
            // Only scrapper can load editable grid rows for own sheets.
            if (!auth()->user()->isScrapper()) {
                $this->leadsData = [$this->emptyRow()];
                return;
            }

            if ($this->groupFilter === '' || $this->groupFilter === null) {
                $this->leadsData = [];
                return;
            }

            $leadsQuery = Lead::where('created_by', auth()->id())
                ->where('lead_sheet_id', $this->sheetFilter);
            $this->applyTableSearchAndStatusFilters($leadsQuery);
            $this->applySelectedGroupScope($leadsQuery);
            $this->leadsData = $leadsQuery->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($lead) {
                    return [
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
                })
                ->values()
                ->all();

            $this->ensureEmptyRow();
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error loading sheet leads: ' . $e->getMessage());
            $this->leadsData = [$this->emptyRow()];
            $this->dispatch('show-toast', type: 'error', message: 'Failed to load leads. Please try again.');
        }
    }

    public function ensureEmptyRow()
    {
        // Don't add a new row if there's no data
        if (empty($this->leadsData)) {
            $this->leadsData[] = $this->emptyRow();
            return;
        }

        $last = end($this->leadsData);
        
        // If last row is empty (no id and no name), don't add another empty row
        if (!$last || (empty($last['id']) && empty($last['name']))) {
            return;
        }

        // If last row has an ID (saved lead), always ensure there's an empty row after it
        if (!empty($last['id'])) {
            $this->leadsData[] = $this->emptyRow();
            return;
        }

        // If last row has a name but no ID yet (being created), wait for creation to complete
        // Don't add empty row until the lead is fully created and has an ID
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

            if (auth()->check() && $this->viewMode === 'table' && empty($this->leadsData)) {
                if (!$this->sheetFilter && $this->canEditAcrossAllSheets()) {
                    $this->loadSheetLeads();
                } elseif ($this->sheetFilter) {
                    $currentSheet = LeadSheet::find($this->sheetFilter);
                    if ($currentSheet && $currentSheet->created_by === auth()->id() && $this->canEditAcrossAllSheets()) {
                        $this->loadSheetLeads();
                    }
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
                'sheets' => $sheetsQuery->get(),
                'groups' => $groups,
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error rendering leads index: ' . $e->getMessage());
            return view('components.leads.⚡index', [
                'leads' => \Illuminate\Pagination\LengthAwarePaginator::empty(),
                'sheets' => collect([]),
                'groups' => collect([]),
            ]);
        }
    }
};
?>

<div
    class="max-w-9xl mx-auto px-4 sm:px-6 lg:px-8 py-6"
    wire:poll.5s="refreshLeadsData"
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
                        x-on:click="importModalOpen = true"
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
                                wire:model="importSheetId"
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
                    $queryParams = request()->query();
                    $queryParams['viewMode'] = 'table';
                    if (!isset($queryParams['sheetFilter']) && $sheetFilter) {
                        $queryParams['sheetFilter'] = $sheetFilter;
                    }
                @endphp
                <a 
                    href="{{ route('leads.index', $queryParams) }}"
                    class="px-4 py-2 text-sm font-semibold rounded-l-lg transition-colors {{ $viewMode === 'table' ? 'bg-blue-600 text-white' : 'text-gray-700 hover:bg-gray-50' }}"
                >
                    <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                    </svg>
                    Table View
                </a>
                @php
                    $queryParams['viewMode'] = 'list';
                @endphp
                <a 
                    href="{{ route('leads.index', $queryParams) }}"
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
        @if($sheetFilter && ($groupFilter === '' || $groupFilter === null))
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
                        <tbody class="bg-white divide-y divide-gray-200">
                            @forelse($leadsData as $index => $row)
                                @php
                                    $rowKey = $row['_row_key'] ?? ('idx-' . $index);
                                @endphp
                                <tr
                                    wire:key="lead-row-{{ $rowKey }}"
                                    class="{{ empty($row['id']) ? 'bg-blue-50' : 'hover:bg-gray-50' }}"
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
                                        <input type="text" wire:key="lead-name-{{ $rowKey }}" wire:model.live.debounce.500ms="leadsData.{{ $index }}.name" class="w-48 px-2 py-1 border border-gray-300 rounded" placeholder="Name">
                                    </td>
                                    <td class="px-4 py-2">
                                        <input type="email" wire:key="lead-email-{{ $rowKey }}" wire:model.live.debounce.500ms="leadsData.{{ $index }}.email" class="w-56 px-2 py-1 border border-gray-300 rounded" placeholder="Email">
                                    </td>
                                    <td class="px-4 py-2">
                                        <input type="text" wire:key="lead-services-{{ $rowKey }}" wire:model.live.debounce.500ms="leadsData.{{ $index }}.services" class="w-40 px-2 py-1 border border-gray-300 rounded" placeholder="Services">
                                    </td>
                                    <td class="px-4 py-2">
                                        <input type="text" wire:key="lead-phone-{{ $rowKey }}" wire:model.live.debounce.500ms="leadsData.{{ $index }}.phone" class="w-40 px-2 py-1 border border-gray-300 rounded" placeholder="Phone">
                                    </td>
                                    <td class="px-4 py-2">
                                        <input type="text" wire:key="lead-location-{{ $rowKey }}" wire:model.live.debounce.500ms="leadsData.{{ $index }}.location" class="w-40 px-2 py-1 border border-gray-300 rounded" placeholder="Location">
                                    </td>
                                    <td class="px-4 py-2">
                                        <input type="text" wire:key="lead-position-{{ $rowKey }}" wire:model.live.debounce.500ms="leadsData.{{ $index }}.position" class="w-40 px-2 py-1 border border-gray-300 rounded" placeholder="Position">
                                    </td>
                                    <td class="px-4 py-2">
                                        <input type="text" wire:key="lead-platform-{{ $rowKey }}" wire:model.live.debounce.500ms="leadsData.{{ $index }}.platform" class="w-32 px-2 py-1 border border-gray-300 rounded" placeholder="Platform">
                                    </td>
                                    <td class="px-4 py-2">
                                        <textarea wire:key="lead-social-links-{{ $rowKey }}" wire:model.live.debounce.500ms="leadsData.{{ $index }}.social_links" class="w-56 px-2 py-1 border border-gray-300 rounded" rows="2" placeholder="Social links (LinkedIn, Facebook, Instagram)"></textarea>
                                    </td>
                                    <td class="px-4 py-2">
                                        <input type="text" wire:key="lead-detail-{{ $rowKey }}" wire:model.live.debounce.500ms="leadsData.{{ $index }}.detail" class="w-56 px-2 py-1 border border-gray-300 rounded" placeholder="Details">
                                    </td>
                                    <td class="px-4 py-2">
                                        <input type="text" wire:key="lead-web-link-{{ $rowKey }}" wire:model.live.debounce.500ms="leadsData.{{ $index }}.web_link" class="w-48 px-2 py-1 border border-gray-300 rounded" placeholder="Web link">
                                    </td>
                                    <td class="px-4 py-2">
                                        @if(!empty($row['id']))
                                            <button
                                                type="button"
                                                class="px-3 py-1 text-xs font-semibold text-red-700 bg-red-50 hover:bg-red-100 rounded"
                                                onclick="event.stopPropagation(); if(confirm('Delete this lead?')) { @this.call('deleteLeadRow', {{ $row['id'] }}) }"
                                            >
                                                Delete
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ $sheetFilter ? '11' : '13' }}" class="px-6 py-12 text-center text-gray-500">
                                        No leads found.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
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
                                            <a href="{{ route('leads.show', $lead->id) }}" class="text-sm font-semibold text-gray-900 hover:text-blue-600 transition-colors">
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
                                    <a href="{{ route('leads.show', $lead->id) }}" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white text-sm font-semibold rounded-lg hover:bg-blue-700 transition-colors">
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
                                        <a href="{{ route('leads.show', $lead->id) }}" class="text-sm font-semibold text-gray-900 hover:text-blue-600 transition-colors">
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
                                    <a href="{{ route('leads.show', $lead->id) }}" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white text-sm font-semibold rounded-lg hover:bg-blue-700 transition-colors">
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
                                        <a href="{{ route('leads.show', $lead->id) }}" class="text-sm font-semibold text-gray-900 hover:text-blue-600 transition-colors">
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
                                    <a href="{{ route('leads.show', $lead->id) }}" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white text-sm font-semibold rounded-lg hover:bg-blue-700 transition-colors">
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
