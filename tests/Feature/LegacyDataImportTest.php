<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Program;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Build-plan 18 (FR-8.21) — see docs/legacy-import-format.md for the full
 * column reference this test exercises via the bundled sample fixture
 * (storage/app/legacy-import-sample.csv).
 */
class LegacyDataImportTest extends TestCase
{
    use RefreshDatabase;

    private string $samplePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->samplePath = storage_path('app/legacy-import-sample.csv');

        // The sample file references real catalog program names (see
        // docs/legacy-import-format.md) — matching the names actually
        // seeded by database/seeders/ProgramsCatalogSeeder so the exact same
        // file also works end-to-end against a freshly seeded dev database.
        Program::create([
            'name' => 'רימונים בסתיו — תוכנית חודשית',
            'description' => 'תוכנית חודשית לעונת הסתיו.',
            'price' => 420,
            'is_premium' => false,
            'is_subscription_type' => false,
            'is_active' => true,
        ]);

        Program::create([
            'name' => 'ניצני חורף — תוכנית חודשית',
            'description' => 'תוכנית חודשית לעונת החורף.',
            'price' => 420,
            'is_premium' => false,
            'is_subscription_type' => false,
            'is_active' => true,
        ]);

        // Same lead_source names ReferenceDataSeeder seeds — the sample file's
        // "הפניה"/"פנייה חוזרת" values are meant to match real reference data.
        LeadSource::create(['name' => 'דף נחיתה', 'is_active' => true]);
        LeadSource::create(['name' => 'הפניה', 'is_active' => true]);
        LeadSource::create(['name' => 'פנייה חוזרת', 'is_active' => true]);
    }

    public function test_import_creates_expected_records_from_sample_csv(): void
    {
        $this->artisan('legacy:import', ['path' => $this->samplePath])->assertExitCode(0);

        // 5 rows -> 5 schools, 5 leads.
        $this->assertSame(5, School::count());
        $this->assertSame(5, Lead::count());

        // 4 of the 5 rows are already-converted customers (אורנים, ניצנים, כרמים, שקד).
        $this->assertSame(4, Customer::count());

        // Only 2 rows resolve to a real catalog program (אורנים + שקד) — כרמים's
        // program name is deliberately unmatched (see the sample file / test below).
        $this->assertSame(2, Deal::count());

        $orAnimSchool = School::where('name', 'בית ספר יסודי אורנים')->firstOrFail();
        $lead = Lead::where('school_id', $orAnimSchool->id)->firstOrFail();
        $this->assertSame('ronit@oranim-school.example', $lead->email);
        $this->assertSame('050-1112222', $lead->phone);

        $customer = Customer::where('school_id', $orAnimSchool->id)->firstOrFail();
        $this->assertSame($lead->id, $customer->lead_id);

        $deal = Deal::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('רימונים בסתיו — תוכנית חודשית', $deal->program->name);
        $this->assertEquals(420, (float) $deal->agreed_amount);

        $contact = Contact::where('school_id', $orAnimSchool->id)->firstOrFail();
        $this->assertSame('רונית לוי', $contact->name);
        $this->assertSame($customer->id, $contact->customer_id);
        $this->assertTrue($contact->is_primary);

        // Blank/unmatched lead_source falls back to the shared default source.
        $karmimLead = Lead::whereHas('school', fn ($q) => $q->where('name', 'בית ספר ממלכתי כרמים'))->firstOrFail();
        $this->assertNotNull($karmimLead->source);
        $this->assertSame('פנייה חוזרת', $karmimLead->source->name);

        $nitzanimLead = Lead::whereHas('school', fn ($q) => $q->where('name', 'גן ילדים ניצנים'))->firstOrFail();
        $this->assertSame('הגירת נתונים', $nitzanimLead->source->name);
        $this->assertTrue(LeadSource::where('name', 'הגירת נתונים')->exists());
    }

    public function test_already_converted_customer_row_always_has_a_backing_lead_with_historical_converted_at(): void
    {
        $this->artisan('legacy:import', ['path' => $this->samplePath])->assertExitCode(0);

        $school = School::where('name', 'בית ספר יסודי אורנים')->firstOrFail();
        $customer = Customer::where('school_id', $school->id)->firstOrFail();

        $this->assertNotNull($customer->lead_id);
        $this->assertSame($school->id, $customer->lead->school_id);
        $this->assertSame('2024-03-10', $customer->converted_at->toDateString());

        // No customer ever exists without a backing lead — FR-2.3/FR-8.2.
        $this->assertSame(Customer::count(), Customer::whereNotNull('lead_id')->count());
    }

    public function test_historical_deal_purchased_at_matches_the_rows_date_not_now(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-08-27 10:00:00');

        $this->artisan('legacy:import', ['path' => $this->samplePath])->assertExitCode(0);

        $school = School::where('name', 'בית ספר יסודי אורנים')->firstOrFail();
        $deal = Deal::whereHas('customer', fn ($q) => $q->where('school_id', $school->id))->firstOrFail();

        $this->assertSame('2024-03-15', $deal->purchased_at->toDateString());

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_at_least_one_backdated_activity_log_exists_per_created_record(): void
    {
        $this->artisan('legacy:import', ['path' => $this->samplePath])->assertExitCode(0);

        $school = School::where('name', 'בית ספר יסודי אורנים')->firstOrFail();
        $lead = Lead::where('school_id', $school->id)->firstOrFail();
        $customer = Customer::where('school_id', $school->id)->firstOrFail();
        $deal = Deal::where('customer_id', $customer->id)->firstOrFail();

        $leadLog = ActivityLog::where('activity_type', 'lead.created')->where('lead_id', $lead->id)->firstOrFail();
        $this->assertStringContainsString('יובא ממערכת קודמת', $leadLog->description);
        $this->assertSame('2024-03-10', $leadLog->occurred_at->toDateString());

        $contactLog = ActivityLog::where('activity_type', 'contact.created')->where('school_id', $school->id)->firstOrFail();
        $this->assertStringContainsString('יובא ממערכת קודמת', $contactLog->description);

        $customerLog = ActivityLog::where('activity_type', 'customer.created')->where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('2024-03-10', $customerLog->occurred_at->toDateString());

        $convertedLog = ActivityLog::where('activity_type', 'lead.converted')->where('lead_id', $lead->id)->firstOrFail();
        $this->assertSame('2024-03-10', $convertedLog->occurred_at->toDateString());

        $dealLog = ActivityLog::where('activity_type', 'deal.created')->where('deal_id', $deal->id)->firstOrFail();
        $this->assertStringContainsString('יובא ממערכת קודמת', $dealLog->description);
        $this->assertSame('2024-03-15', $dealLog->occurred_at->toDateString());
    }

    public function test_running_the_import_twice_against_the_same_file_does_not_create_duplicates(): void
    {
        $this->artisan('legacy:import', ['path' => $this->samplePath])->assertExitCode(0);

        $counts = [
            'schools' => School::count(),
            'leads' => Lead::count(),
            'customers' => Customer::count(),
            'contacts' => Contact::count(),
            'deals' => Deal::count(),
            'activity_logs' => ActivityLog::count(),
        ];

        $this->artisan('legacy:import', ['path' => $this->samplePath])->assertExitCode(0);

        $this->assertSame($counts['schools'], School::count());
        $this->assertSame($counts['leads'], Lead::count());
        $this->assertSame($counts['customers'], Customer::count());
        $this->assertSame($counts['contacts'], Contact::count());
        $this->assertSame($counts['deals'], Deal::count());

        // The second run creates no new activity either — every record was
        // reused, not re-created.
        $this->assertSame($counts['activity_logs'], ActivityLog::count());
    }

    public function test_a_row_with_an_unmatched_program_name_is_skipped_and_reported_without_failing_the_import(): void
    {
        $this->artisan('legacy:import', ['path' => $this->samplePath])->assertExitCode(0);

        $karmimSchool = School::where('name', 'בית ספר ממלכתי כרמים')->firstOrFail();

        // The lead/customer for this row still exist...
        $this->assertTrue(Customer::where('school_id', $karmimSchool->id)->exists());

        // ...but no deal was created for the unmatched program name.
        $customer = Customer::where('school_id', $karmimSchool->id)->firstOrFail();
        $this->assertSame(0, Deal::where('customer_id', $customer->id)->count());

        $reportFiles = File::files(storage_path('app/legacy-import-reports'));
        $this->assertNotEmpty($reportFiles);

        $latestReport = collect($reportFiles)->sortByDesc(fn ($file) => $file->getMTime())->first();
        $reportContents = File::get($latestReport->getPathname());

        $this->assertStringContainsString('תוכנית שאינה קיימת בקטלוג', $reportContents);
        $this->assertStringContainsString('deals_skipped: 1', $reportContents);
    }

    public function test_dry_run_creates_nothing_in_the_database_but_still_reports(): void
    {
        $this->artisan('legacy:import', ['path' => $this->samplePath, '--dry-run' => true])->assertExitCode(0);

        $this->assertSame(0, School::count());
        $this->assertSame(0, Lead::count());
        $this->assertSame(0, Customer::count());
        $this->assertSame(0, Contact::count());
        $this->assertSame(0, Deal::count());
        $this->assertSame(0, ActivityLog::count());

        $reportFiles = File::files(storage_path('app/legacy-import-reports'));
        $this->assertNotEmpty($reportFiles);

        $latestReport = collect($reportFiles)->sortByDesc(fn ($file) => $file->getMTime())->first();
        $reportContents = File::get($latestReport->getPathname());

        $this->assertStringContainsString('leads_created: 5', $reportContents);
        $this->assertStringContainsString('customers_created: 4', $reportContents);
    }

    public function test_the_end_of_run_report_file_is_written_with_expected_summary_counts(): void
    {
        $this->artisan('legacy:import', ['path' => $this->samplePath])->assertExitCode(0);

        $reportFiles = File::files(storage_path('app/legacy-import-reports'));
        $this->assertNotEmpty($reportFiles);

        $latestReport = collect($reportFiles)->sortByDesc(fn ($file) => $file->getMTime())->first();
        $reportContents = File::get($latestReport->getPathname());

        $this->assertStringContainsString('schools_created: 5', $reportContents);
        $this->assertStringContainsString('leads_created: 5', $reportContents);
        $this->assertStringContainsString('customers_created: 4', $reportContents);
        $this->assertStringContainsString('deals_created: 2', $reportContents);
        $this->assertStringContainsString('deals_skipped: 1', $reportContents);
        $this->assertStringContainsString('rows_ok: 5', $reportContents);
        $this->assertStringContainsString('rows_error: 0', $reportContents);
    }

    protected function tearDown(): void
    {
        // Keep the real repo tree clean of test-generated reports.
        File::deleteDirectory(storage_path('app/legacy-import-reports'));

        parent::tearDown();
    }
}
