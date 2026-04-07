<?php

namespace Tests\Feature;

use App\Events\NotificationStateChanged;
use App\Models\Lead;
use App\Models\LeadGroup;
use App\Models\LeadSheet;
use App\Models\Notification;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

class SheetAndNotificationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_sheet_creation_resets_inputs_and_flashes_success(): void
    {
        $user = User::factory()->create(['role' => 'scrapper']);
        $team = Team::create(['name' => 'Alpha Team']);

        Livewire::actingAs($user)
            ->test('sheets.create')
            ->set('name', 'Bark')
            ->set('teamIds', [$team->id])
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('sheet-created')
            ->assertDispatched('show-toast')
            ->assertSet('name', '')
            ->assertSet('teamIds', []);

        $this->assertDatabaseHas('lead_sheets', [
            'name' => 'Bark',
            'created_by' => $user->id,
        ]);

    }

    public function test_tabs_only_load_the_selected_group_data(): void
    {
        $user = User::factory()->create(['role' => 'scrapper']);
        $sheet = LeadSheet::create([
            'name' => 'Bark',
            'created_by' => $user->id,
        ]);
        $tabOne = LeadGroup::create([
            'lead_sheet_id' => $sheet->id,
            'name' => 'tab1',
            'sort_order' => 1,
        ]);
        $tabTwo = LeadGroup::create([
            'lead_sheet_id' => $sheet->id,
            'name' => 'tab2',
            'sort_order' => 2,
        ]);
        $tabThree = LeadGroup::create([
            'lead_sheet_id' => $sheet->id,
            'name' => 'tab3',
            'sort_order' => 3,
        ]);

        Lead::create([
            'created_by' => $user->id,
            'lead_sheet_id' => $sheet->id,
            'lead_group_id' => $tabOne->id,
            'lead_date' => now()->toDateString(),
            'status' => 'no response',
            'name' => 'Lead One',
        ]);

        Lead::create([
            'created_by' => $user->id,
            'lead_sheet_id' => $sheet->id,
            'lead_group_id' => $tabTwo->id,
            'lead_date' => now()->toDateString(),
            'status' => 'no response',
            'name' => 'Lead Two',
        ]);

        Lead::create([
            'created_by' => $user->id,
            'lead_sheet_id' => $sheet->id,
            'lead_group_id' => null,
            'lead_date' => now()->toDateString(),
            'status' => 'no response',
            'name' => 'Ungrouped Lead',
        ]);

        $component = Livewire::actingAs($user)
            ->test('leads.index', ['sheetFilter' => (string) $sheet->id, 'viewMode' => 'table']);

        $component->assertSet('leadsData', function (array $rows) {
            return count($rows) === 2
                && $rows[0]['name'] === 'Ungrouped Lead'
                && $rows[1]['name'] === '';
        });

        $component
            ->set('groupFilter', (string) $tabOne->id)
            ->assertSet('leadsData', function (array $rows) {
                return count($rows) === 2
                    && $rows[0]['name'] === 'Lead One'
                    && $rows[1]['name'] === '';
            });

        $component
            ->set('groupFilter', (string) $tabTwo->id)
            ->assertSet('leadsData', function (array $rows) {
                return count($rows) === 2
                    && $rows[0]['name'] === 'Lead Two'
                    && $rows[1]['name'] === '';
            });

        $component
            ->set('groupFilter', (string) $tabThree->id)
            ->assertSet('leadsData', function (array $rows) {
                return count($rows) === 1
                    && $rows[0]['id'] === null
                    && $rows[0]['name'] === '';
            });
    }

    public function test_same_name_can_be_saved_in_different_tabs_without_reusing_the_other_tab_record(): void
    {
        $user = User::factory()->create(['role' => 'scrapper']);
        $sheet = LeadSheet::create([
            'name' => 'Bark',
            'created_by' => $user->id,
        ]);
        $april = LeadGroup::create([
            'lead_sheet_id' => $sheet->id,
            'name' => 'April',
            'sort_order' => 1,
        ]);
        $may = LeadGroup::create([
            'lead_sheet_id' => $sheet->id,
            'name' => 'May',
            'sort_order' => 2,
        ]);

        $component = Livewire::actingAs($user)
            ->test('leads.index', ['sheetFilter' => (string) $sheet->id, 'viewMode' => 'table']);

        $component
            ->set('groupFilter', (string) $april->id)
            ->set('leadsData.0.name', 'steve');

        $this->assertDatabaseHas('leads', [
            'lead_sheet_id' => $sheet->id,
            'lead_group_id' => $april->id,
            'name' => 'steve',
        ]);

        $component
            ->set('groupFilter', (string) $may->id)
            ->set('leadsData.0.name', 'steve');

        $this->assertDatabaseCount('leads', 2);
        $this->assertDatabaseHas('leads', [
            'lead_sheet_id' => $sheet->id,
            'lead_group_id' => $may->id,
            'name' => 'steve',
        ]);
    }

    public function test_mark_as_read_sets_read_at_and_broadcasts(): void
    {
        Event::fake([NotificationStateChanged::class]);

        $user = User::factory()->create(['role' => 'front_sale']);
        $team = Team::create(['name' => 'Sales Team']);
        $team->users()->attach($user);
        $sheet = LeadSheet::create([
            'name' => 'Notifications',
            'created_by' => $user->id,
        ]);
        $sheet->teams()->attach($team);
        $lead = Lead::create([
            'created_by' => $user->id,
            'lead_sheet_id' => $sheet->id,
            'lead_date' => now()->toDateString(),
            'status' => 'no response',
            'name' => 'Notification Lead',
        ]);
        $notification = Notification::create([
            'user_id' => $user->id,
            'lead_id' => $lead->id,
            'type' => 'new_lead',
            'message' => 'New lead available.',
            'read' => false,
        ]);

        Livewire::actingAs($user)
            ->test('notifications.bell')
            ->call('markAsRead', $notification->id);

        $notification->refresh();

        $this->assertTrue($notification->read);
        $this->assertNotNull($notification->read_at);

        Event::assertDispatched(NotificationStateChanged::class, function (NotificationStateChanged $event) use ($user, $notification) {
            return $event->userId === $user->id
                && $event->notificationId === $notification->id
                && $event->action === 'read';
        });
    }

    public function test_all_sheets_table_view_renders_leads_with_sheet_and_group_details(): void
    {
        $user = User::factory()->create(['role' => 'scrapper']);

        $sheetOne = LeadSheet::create([
            'name' => 'April Sheet',
            'created_by' => $user->id,
        ]);
        $sheetTwo = LeadSheet::create([
            'name' => 'May Sheet',
            'created_by' => $user->id,
        ]);
        $group = LeadGroup::create([
            'lead_sheet_id' => $sheetTwo->id,
            'name' => 'May Group',
            'sort_order' => 1,
        ]);

        Lead::create([
            'created_by' => $user->id,
            'lead_sheet_id' => $sheetOne->id,
            'lead_group_id' => null,
            'lead_date' => now()->toDateString(),
            'status' => 'no response',
            'name' => 'Alpha Lead',
            'email' => 'alpha@example.com',
        ]);

        Lead::create([
            'created_by' => $user->id,
            'lead_sheet_id' => $sheetTwo->id,
            'lead_group_id' => $group->id,
            'lead_date' => now()->toDateString(),
            'status' => 'follow up',
            'name' => 'Beta Lead',
            'services' => 'SEO',
        ]);

        Livewire::actingAs($user)
            ->test('leads.index', ['viewMode' => 'table'])
            ->assertSee('April Sheet')
            ->assertSee('May Sheet')
            ->assertSee('May Group')
            ->assertSee('Default')
            ->assertSet('leadsData', function (array $rows) {
                $names = collect($rows)->pluck('name')->filter()->sort()->values()->all();

                return $names === ['Alpha Lead', 'Beta Lead'];
            });
    }

    public function test_all_sheets_table_view_allows_editing_existing_rows(): void
    {
        $user = User::factory()->create(['role' => 'scrapper']);
        $sheet = LeadSheet::create([
            'name' => 'Bark',
            'created_by' => $user->id,
        ]);

        $lead = Lead::create([
            'created_by' => $user->id,
            'lead_sheet_id' => $sheet->id,
            'lead_date' => now()->toDateString(),
            'status' => 'no response',
            'name' => 'Editable Lead',
            'services' => 'Old Service',
        ]);

        Livewire::actingAs($user)
            ->test('leads.index', ['viewMode' => 'table'])
            ->set('leadsData.0.services', 'New Service');

        $this->assertDatabaseHas('leads', [
            'id' => $lead->id,
            'services' => 'New Service',
        ]);
    }

    public function test_all_sheets_list_view_shows_rows(): void
    {
        $user = User::factory()->create(['role' => 'scrapper']);
        $sheet = LeadSheet::create([
            'name' => 'Bark',
            'created_by' => $user->id,
        ]);

        Lead::create([
            'created_by' => $user->id,
            'lead_sheet_id' => $sheet->id,
            'lead_date' => now()->toDateString(),
            'status' => 'no response',
            'name' => 'Visible Lead',
        ]);

        Livewire::actingAs($user)
            ->test('leads.index', ['viewMode' => 'list'])
            ->assertSee('Visible Lead')
            ->assertSee('Bark');
    }
}
