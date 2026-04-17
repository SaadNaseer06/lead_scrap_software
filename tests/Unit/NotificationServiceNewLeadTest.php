<?php

namespace Tests\Unit;

use App\Events\NotificationStateChanged;
use App\Models\Lead;
use App\Models\LeadSheet;
use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class NotificationServiceNewLeadTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_new_lead_notification_without_name_detail_or_contact(): void
    {
        Event::fake([NotificationStateChanged::class]);

        User::factory()->create(['role' => 'front_sale']);
        $owner = User::factory()->create(['role' => 'scrapper']);
        $sheet = LeadSheet::create([
            'name' => 'Sheet '.uniqid(),
            'created_by' => $owner->id,
        ]);
        $lead = Lead::create([
            'created_by' => $owner->id,
            'lead_sheet_id' => $sheet->id,
            'lead_date' => now()->toDateString(),
            'status' => 'no response',
            'name' => 'Pat',
        ]);

        $this->actingAs($owner);

        $this->assertFalse(NotificationService::notifySalesNewLeadWhenCoreFieldsComplete($lead));
        $this->assertSame(0, Notification::query()->where('type', 'new_lead')->count());
    }

    public function test_no_notification_when_name_and_contact_but_no_detail(): void
    {
        Event::fake([NotificationStateChanged::class]);

        User::factory()->create(['role' => 'front_sale']);
        $owner = User::factory()->create(['role' => 'scrapper']);
        $sheet = LeadSheet::create([
            'name' => 'Sheet '.uniqid(),
            'created_by' => $owner->id,
        ]);
        $lead = Lead::create([
            'created_by' => $owner->id,
            'lead_sheet_id' => $sheet->id,
            'lead_date' => now()->toDateString(),
            'status' => 'no response',
            'name' => 'Pat',
            'email' => 'pat@example.com',
            'phone' => '5550000',
        ]);

        $this->actingAs($owner);

        $this->assertFalse(NotificationService::notifySalesNewLeadWhenCoreFieldsComplete($lead));
        $this->assertSame(0, Notification::query()->where('type', 'new_lead')->count());
    }

    public function test_new_lead_notification_once_name_plus_email_or_phone(): void
    {
        Event::fake([NotificationStateChanged::class]);

        User::factory()->create(['role' => 'front_sale']);
        $owner = User::factory()->create(['role' => 'scrapper']);
        $sheet = LeadSheet::create([
            'name' => 'Sheet '.uniqid(),
            'created_by' => $owner->id,
        ]);
        $leadEmailOnly = Lead::create([
            'created_by' => $owner->id,
            'lead_sheet_id' => $sheet->id,
            'lead_date' => now()->toDateString(),
            'status' => 'no response',
            'name' => 'Pat',
            'email' => 'pat@example.com',
            'detail' => 'Email-only path.',
        ]);

        $this->actingAs($owner);

        $this->assertTrue(NotificationService::notifySalesNewLeadWhenCoreFieldsComplete($leadEmailOnly));
        $this->assertSame(1, Notification::query()->where('type', 'new_lead')->count());

        $this->assertFalse(NotificationService::notifySalesNewLeadWhenCoreFieldsComplete($leadEmailOnly->fresh()));
        $this->assertSame(1, Notification::query()->where('type', 'new_lead')->count());

        $leadPhoneOnly = Lead::create([
            'created_by' => $owner->id,
            'lead_sheet_id' => $sheet->id,
            'lead_date' => now()->toDateString(),
            'status' => 'no response',
            'name' => 'Sam',
            'phone' => '5550199',
            'detail' => 'Phone-only path.',
        ]);

        $this->assertTrue(NotificationService::notifySalesNewLeadWhenCoreFieldsComplete($leadPhoneOnly));
        $this->assertSame(2, Notification::query()->where('type', 'new_lead')->count());
    }

    public function test_invalid_email_without_phone_does_not_notify(): void
    {
        Event::fake([NotificationStateChanged::class]);

        User::factory()->create(['role' => 'front_sale']);
        $owner = User::factory()->create(['role' => 'scrapper']);
        $sheet = LeadSheet::create([
            'name' => 'Sheet '.uniqid(),
            'created_by' => $owner->id,
        ]);
        $lead = Lead::create([
            'created_by' => $owner->id,
            'lead_sheet_id' => $sheet->id,
            'lead_date' => now()->toDateString(),
            'status' => 'no response',
            'name' => 'Pat',
            'email' => 'not-an-email',
            'detail' => 'Has detail but bad email and no phone.',
        ]);

        $this->actingAs($owner);

        $this->assertFalse(NotificationService::notifySalesNewLeadWhenCoreFieldsComplete($lead));
        $this->assertSame(0, Notification::query()->where('type', 'new_lead')->count());
    }

    public function test_invalid_email_with_phone_still_notifies(): void
    {
        Event::fake([NotificationStateChanged::class]);

        User::factory()->create(['role' => 'front_sale']);
        $owner = User::factory()->create(['role' => 'scrapper']);
        $sheet = LeadSheet::create([
            'name' => 'Sheet '.uniqid(),
            'created_by' => $owner->id,
        ]);
        $lead = Lead::create([
            'created_by' => $owner->id,
            'lead_sheet_id' => $sheet->id,
            'lead_date' => now()->toDateString(),
            'status' => 'no response',
            'name' => 'Pat',
            'email' => 'not-an-email',
            'phone' => '5550111',
            'detail' => 'Bad email but phone OK.',
        ]);

        $this->actingAs($owner);

        $this->assertTrue(NotificationService::notifySalesNewLeadWhenCoreFieldsComplete($lead));
        $this->assertSame(1, Notification::query()->where('type', 'new_lead')->count());
    }
}
