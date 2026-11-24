<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Bundle;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Role;
use App\Models\School;
use App\Models\StatusDefinition;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\HebrewCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/** 2026-11-24: a subscription bundle per school year — the subscription runs that year's months, whenever it's sold. */
class SubscriptionSchoolYearTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create(['name' => 'גישה מלאה', 'is_active' => true]);
        $role->permissions()->create(['resource' => '*', 'action' => '*', 'is_allowed' => true]);
        $this->owner = User::create(['name' => 'בעלת העסק', 'email' => 'owner@kapaim.test', 'password' => 'password', 'role_id' => $role->id, 'is_active' => true]);

        AppSetting::put(HebrewCalendar::START_MONTH_SETTING, '12'); // אלול
    }

    private function customer(): Customer
    {
        $school = School::create(['name' => 'בית ספר '.random_int(1, 999999)]);
        $status = StatusDefinition::firstOrCreate(['scope' => 'lead', 'name' => Lead::NEW_STATUS_NAME], ['is_active' => true, 'sort_order' => 1]);
        $lead = Lead::create(['school_id' => $school->id, 'status_id' => $status->id, 'assigned_user_id' => $this->owner->id, 'phone' => '0501234567', 'email' => 'a'.random_int(1, 999999).'@school.test']);
        $this->actingAs($this->owner);

        return $lead->convertToCustomer(app(ActivityLogger::class));
    }

    private function bundle(?int $year, string $name = 'מנוי'): Bundle
    {
        return Bundle::create(['name' => $name.' '.$year, 'price' => 4000, 'is_subscription_type' => true, 'school_year' => $year, 'is_active' => true]);
    }

    public function test_school_years_map_to_their_first_month_and_are_read_in_any_common_form(): void
    {
        $this->assertSame('2026-08-14', HebrewCalendar::schoolYearStart(5787, 12)->toDateString()); // 1 אלול תשפ״ו
        $this->assertSame('2026-09-12', HebrewCalendar::schoolYearStart(5787, 0)->toDateString());  // 1 תשרי תשפ״ז
        $this->assertSame('תשפ״ז (2026–2027)', HebrewCalendar::schoolYearLabel(5787));

        foreach (['תשפ"ז', 'תשפז', 'תשפ״ז (2026–2027)', '5787', '2026-2027', '2026/27', '2027'] as $typed) {
            $this->assertSame(5787, HebrewCalendar::parseSchoolYear($typed), $typed);
        }

        $this->assertNull(HebrewCalendar::parseSchoolYear('בקרוב'));
        $this->assertNull(HebrewCalendar::parseSchoolYear('2026-2028'));
    }

    public function test_next_years_subscription_sold_early_runs_next_years_months(): void
    {
        $this->travelTo('2027-05-10'); // אייר תשפ״ז — this year's months still running
        $customer = $this->customer();

        Deal::createForCustomer($customer, null, $this->bundle(5787));
        $renewal = Deal::createForCustomer($customer, null, $this->bundle(5788));

        $this->assertSame('2027-09-03', $renewal->subscription->first_month_date->toDateString()); // 1 אלול תשפ״ז
        $this->assertSame('אלול תשפ״ז', $renewal->subscription->monthLabel(1));
        $this->assertSame('סיוון תשפ״ח', $renewal->subscription->monthLabel(10));

        // This year's ends with its own months; the renewal stays active.
        $this->travelTo('2027-07-15');
        \App\Models\Subscription::endExpired(app(ActivityLogger::class));
        $this->assertSame([false, true], $customer->subscriptions()->orderBy('id')->get()->map->isActive()->all());
    }

    public function test_a_bundle_without_a_year_still_follows_the_sale_date(): void
    {
        $this->travelTo('2026-10-16');

        $deal = Deal::createForCustomer($this->customer(), null, $this->bundle(null));

        $this->assertSame('2026-08-14', $deal->subscription->first_month_date->toDateString());
    }

    public function test_a_bundle_whose_school_year_is_over_cannot_be_sold(): void
    {
        $this->travelTo('2027-07-15'); // after סיוון תשפ״ז

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('כבר הסתיימה');

        Deal::createForCustomer($this->customer(), null, $this->bundle(5787));
    }

    public function test_the_catalog_asks_for_the_school_year_of_a_subscription_bundle(): void
    {
        $component = Livewire::actingAs($this->owner)->test('programs-catalog')
            ->set('bundleName', 'מנוי תשפ"ח')
            ->set('bundlePrice', '4500')
            ->set('bundleIsSubscriptionType', true)
            ->assertSee('שנת לימודים')
            ->call('addBundle')
            ->assertHasErrors(['bundleSchoolYear' => 'required_if_accepted']);

        $component->set('bundleSchoolYear', '5788')->call('addBundle')->assertHasNoErrors();
        $bundle = Bundle::where('name', 'מנוי תשפ"ח')->sole();
        $this->assertSame(5788, $bundle->school_year);

        // A plain bundle never keeps a year.
        Livewire::actingAs($this->owner)->test('programs-catalog')
            ->set('bundleName', 'רגיל')->set('bundlePrice', '100')->set('bundleSchoolYear', '5788')
            ->call('addBundle')->assertHasNoErrors();
        $this->assertNull(Bundle::where('name', 'רגיל')->value('school_year'));

        // Editing: the year can be changed, and a subscription bundle can't lose it.
        Livewire::actingAs($this->owner)->test('programs-catalog')
            ->call('editItem', 'bundle', $bundle->id)
            ->assertSet('editSchoolYear', '5788')
            ->set('editSchoolYear', '')
            ->call('saveItem')
            ->assertHasErrors('editSchoolYear')
            ->set('editSchoolYear', '5789')
            ->call('saveItem')
            ->assertHasNoErrors();
        $this->assertSame(5789, $bundle->fresh()->school_year);

        $this->get(route('programs-catalog'))->assertSee('תשפ״ט (2028–2029)');
    }
}
