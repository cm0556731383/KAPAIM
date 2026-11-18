<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Customer;
use App\Models\ExternalIntegrationSetting;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Role;
use App\Models\School;
use App\Models\StatusDefinition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** "+ לקוחה חדשה" on the customers page (⚡customers.blade.php). */
class ManualCustomerTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    /** Smove accounts contacts (GET /Contacts by email) and the system list's members. */
    private array $smoveAccount = [];

    private array $smoveList = [];

    protected function setUp(): void
    {
        parent::setUp();

        $fullAccess = Role::create(['name' => 'גישה מלאה', 'is_active' => true]);
        $fullAccess->permissions()->create(['resource' => '*', 'action' => '*', 'is_allowed' => true]);

        $this->owner = User::create([
            'name' => 'בעלת העסק', 'email' => 'owner@kapaim.test', 'password' => 'password',
            'role_id' => $fullAccess->id, 'is_active' => true,
        ]);

        StatusDefinition::create(['scope' => 'customer', 'name' => 'פעילה', 'is_active' => true, 'sort_order' => 1]);
        LeadSource::create(['name' => 'פייסבוק', 'is_active' => true]);

        ExternalIntegrationSetting::updateOrCreate(['system' => 'smove'], ['is_active' => true, 'settings' => ['api_key' => 'x', 'list_id' => 111]]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/Lists/111/Contacts')) {
                return Http::response($this->smoveList, 200);
            }

            if ($request->method() === 'GET' && str_contains($request->url(), '/Contacts')) {
                $email = mb_strtolower($request->data()['q.email.like'] ?? '');

                return Http::response(array_values(array_filter($this->smoveAccount, fn ($c) => mb_strtolower($c['email']) === $email)), 200);
            }

            return Http::response(['id' => 42], 200);
        });
    }

    private function joins(): array
    {
        return Http::recorded(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), '/Contacts'))
            ->map(fn ($pair) => mb_strtolower($pair[0]->data()['email']))->values()->all();
    }

    private function form()
    {
        return Livewire::actingAs($this->owner)->test('customers')
            ->set('newSchoolName', 'אורות')
            ->set('newCity', 'ירושלים')
            ->set('newBusinessNumber', '580012345')
            ->set('newSourceId', (string) LeadSource::first()->id)
            ->set('newContacts', [
                ['name' => 'רחל כהן', 'role' => 'מנהלת', 'phone' => '0501234567', 'email' => 'rachel@orot.test', 'is_primary' => true, 'is_accounting_contact' => false],
                ['name' => 'לאה לוי', 'role' => '', 'phone' => '', 'email' => 'books@orot.test', 'is_primary' => false, 'is_accounting_contact' => true],
            ]);
    }

    public function test_creates_a_customer_with_its_contacts_when_none_is_in_smove(): void
    {
        $this->form()->call('createCustomer')->assertHasNoErrors()->assertSet('smoveMatches', [])->assertRedirect();

        $customer = Customer::sole();
        $this->assertSame('אורות', $customer->school->name);
        $this->assertSame('ירושלים', $customer->school->city);
        $this->assertSame('פעילה', $customer->status->name);
        $this->assertSame('פייסבוק', $customer->lead->source->name);
        $this->assertSame(['books@orot.test', 'rachel@orot.test'], $customer->contacts()->orderBy('email')->pluck('email')->all());
        $this->assertTrue($customer->contacts()->where('email', 'rachel@orot.test')->value('is_primary'));
        $this->assertTrue($customer->contacts()->where('email', 'books@orot.test')->value('is_accounting_contact'));
        // A new contact joins the Smove list as usual (the accounting-only one gets no list mail).
        $this->assertSame(['rachel@orot.test'], $this->joins());
    }

    public function test_a_contact_already_in_smove_asks_for_approval_showing_its_smove_name(): void
    {
        $this->smoveAccount = [['id' => 900, 'email' => 'Rachel@Orot.test', 'firstName' => 'רחל', 'lastName' => 'כהן-לוי', 'canReceiveEmails' => true]];
        $this->smoveList = [['id' => 900, 'email' => 'rachel@orot.test', 'canReceiveEmails' => true]];

        $test = $this->form()->call('createCustomer')
            ->assertHasNoErrors()
            ->assertSee('איש קשר כבר קיים ב-Smove')
            ->assertSee('רחל כהן-לוי')
            ->assertNoRedirect();

        $this->assertSame(0, Customer::count());
        $this->assertSame([0], array_keys($test->get('smoveMatches')));

        $test->call('confirmSmoveMatch')->assertRedirect();

        $contact = Contact::where('email', 'rachel@orot.test')->sole();
        $this->assertSame(900, (int) $contact->smove_contact_id);
        // Already on the list: linked, never re-joined (that would reset its consent).
        $this->assertSame([], $this->joins());
    }

    public function test_an_approved_contact_not_yet_on_the_list_is_linked_and_joins_it(): void
    {
        $this->smoveAccount = [['id' => 900, 'email' => 'rachel@orot.test', 'firstName' => 'רחל כהן', 'lastName' => 'כהן', 'canReceiveEmails' => false]];

        $this->form()->call('createCustomer')->assertSee('רחל כהן')->call('confirmSmoveMatch')->assertRedirect();

        $this->assertSame(900, (int) Contact::where('email', 'rachel@orot.test')->value('smove_contact_id'));
        $this->assertSame(['rachel@orot.test'], $this->joins());
    }

    public function test_going_back_to_edit_creates_nothing(): void
    {
        $this->smoveAccount = [['id' => 900, 'email' => 'rachel@orot.test', 'firstName' => 'רחל', 'lastName' => '', 'canReceiveEmails' => true]];

        $this->form()->call('createCustomer')->call('cancelSmoveMatch')->assertSet('smoveMatches', [])->assertSee('פתיחת הלקוחה');

        $this->assertSame(0, Customer::count());
        $this->assertSame(0, School::count());
    }

    public function test_required_fields_and_duplicates_are_refused(): void
    {
        Livewire::actingAs($this->owner)->test('customers')
            ->set('newContacts', [['name' => '', 'role' => '', 'phone' => '', 'email' => '', 'is_primary' => false, 'is_accounting_contact' => false]])
            ->call('createCustomer')
            ->assertHasErrors(['newSchoolName', 'newContacts.0.name', 'newContacts.0.phone', 'newContacts.0.email']);

        School::create(['name' => 'אורות']);
        $this->form()->call('createCustomer')->assertHasErrors('newSchoolName');

        School::query()->update(['name' => 'אחר', 'business_number' => '580-012345']);
        $this->form()->call('createCustomer')->assertHasErrors('newBusinessNumber');

        $this->assertSame(0, Customer::count());
        $this->assertSame(0, Lead::count());
    }

    public function test_without_smove_connected_it_just_creates_the_customer(): void
    {
        ExternalIntegrationSetting::query()->delete();

        $this->form()->call('createCustomer')->assertRedirect();

        $this->assertSame(1, Customer::count());
    }

    public function test_only_customer_managers_see_and_use_the_button(): void
    {
        $viewer = Role::create(['name' => 'צפייה', 'is_active' => true]);
        $viewer->permissions()->create(['resource' => 'customers', 'action' => 'view', 'is_allowed' => true]);
        $user = User::create(['name' => 'צופה', 'email' => 'v@kapaim.test', 'password' => 'password', 'role_id' => $viewer->id, 'is_active' => true]);

        Livewire::actingAs($user)->test('customers')->assertDontSee('+ לקוחה חדשה')
            ->set('newSchoolName', 'אורות')->call('createCustomer')->assertForbidden();

        Livewire::actingAs($this->owner)->test('customers')->assertSee('+ לקוחה חדשה');
    }

    // ===== "+ איש קשר חדש" in the customer card =====

    private function customer(string $name, string $email): Customer
    {
        return app(\App\Services\CustomerImport::class)->createCustomer([
            'school' => ['name' => $name], 'status' => null, 'lead_source' => null, 'converted_at' => null,
            'contacts' => [['name' => 'ראשית', 'phone' => '0500000000', 'email' => $email, 'is_primary' => true, 'is_accounting_contact' => false]],
        ], $this->owner, app(\App\Services\ActivityLogger::class));
    }

    private function addContactForm(Customer $customer, string $email)
    {
        return Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $customer])
            ->set('contactName', 'יעל רואת חשבון')
            ->set('contactEmail', $email)
            ->set('contactIsAccountingContact', false);
    }

    public function test_card_contact_in_smove_and_shared_with_another_customer_asks_for_approval(): void
    {
        $other = $this->customer('נועם', 'a@noam.test');
        Contact::create(['school_id' => $other->school_id, 'customer_id' => $other->id, 'name' => 'יעל', 'email' => 'Yael@Calc.test']);
        $customer = $this->customer('אורות', 'r@orot.test');
        $joinsBefore = count($this->joins());
        $this->smoveAccount = [['id' => 777, 'email' => 'yael@calc.test', 'firstName' => 'יעל', 'lastName' => 'רו"ח', 'canReceiveEmails' => true]];
        $this->smoveList = [['id' => 777, 'email' => 'yael@calc.test', 'canReceiveEmails' => true]];

        $test = $this->addContactForm($customer->fresh(), 'yael@calc.test')->call('addContact')
            ->assertHasNoErrors()
            ->assertSee('איש קשר כבר קיים ב-Smove')
            ->assertSee('יעל רו"ח')
            ->assertSee('כתובת זו כבר משמשת איש קשר של: נועם');

        $this->assertSame(1, $customer->contacts()->count());

        $test->call('confirmContactSmoveMatch')->assertSet('contactSmoveMatch', []);

        $contact = $customer->contacts()->where('email', 'yael@calc.test')->sole();
        $this->assertSame(777, (int) $contact->smove_contact_id);
        $this->assertCount($joinsBefore, $this->joins()); // on the list already: not re-joined
    }

    public function test_card_contact_going_back_adds_nothing_and_one_not_in_smove_is_added_right_away(): void
    {
        $customer = $this->customer('אורות', 'r@orot.test');
        $this->smoveAccount = [['id' => 777, 'email' => 'yael@calc.test', 'firstName' => 'יעל', 'lastName' => '', 'canReceiveEmails' => true]];

        $this->addContactForm($customer, 'yael@calc.test')->call('addContact')->call('cancelContactSmoveMatch')
            ->assertSet('contactSmoveMatch', [])->assertSee('+ הוספת איש קשר');
        $this->assertSame(1, $customer->contacts()->count());

        $this->addContactForm($customer, 'new@calc.test')->call('addContact')->assertSet('contactSmoveMatch', []);
        $this->assertTrue($customer->contacts()->where('email', 'new@calc.test')->exists());
        $this->assertContains('new@calc.test', $this->joins());
    }

    public function test_card_contact_email_changed_after_the_check_is_checked_again(): void
    {
        $customer = $this->customer('אורות', 'r@orot.test');
        $this->smoveAccount = [['id' => 777, 'email' => 'yael@calc.test', 'firstName' => 'יעל', 'lastName' => '', 'canReceiveEmails' => true]];

        $this->addContactForm($customer, 'yael@calc.test')->call('addContact')
            ->set('contactEmail', 'other@calc.test')->call('confirmContactSmoveMatch')
            ->assertSet('contactSmoveMatch', []);

        // other@ isn't in Smove: added without the old match's link.
        $contact = $customer->contacts()->where('email', 'other@calc.test')->sole();
        $this->assertNotSame(777, (int) $contact->smove_contact_id);
    }

    // ===== Editing a contact shared by several customers =====

    /** Two customers sharing yael@calc.test, plus a third with someone else. */
    private function sharedContacts(): array
    {
        $orot = $this->customer('אורות', 'r@orot.test');
        $noam = $this->customer('נועם', 'n@noam.test');
        $other = $this->customer('יובלים', 'y@yuvalim.test');

        $mine = Contact::create(['school_id' => $orot->school_id, 'customer_id' => $orot->id, 'name' => 'יעל', 'role' => 'רו"ח', 'email' => 'yael@calc.test', 'phone' => '0501111111', 'is_accounting_contact' => true]);
        $theirs = Contact::create(['school_id' => $noam->school_id, 'customer_id' => $noam->id, 'name' => 'יעל', 'role' => 'הנה"ח', 'email' => 'Yael@Calc.test', 'phone' => '0501111111', 'is_primary' => true]);

        return [$orot, $mine, $theirs, $other];
    }

    public function test_editing_a_shared_contact_warns_and_on_approval_changes_it_for_every_customer(): void
    {
        [$orot, $mine, $theirs, $other] = $this->sharedContacts();

        $test = Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $orot])
            ->call('editContact', $mine->id)
            ->set('contactName', 'יעל כהן')
            ->set('contactPhone', '0502222222')
            ->set('contactRole', 'מנהלת חשבונות')
            ->call('updateContact')
            ->assertSet('contactSharedWith', ['נועם'])
            ->assertDispatched('open-modal', name: 'shared-contact')
            ->assertSee('ישתנה')
            ->assertSee('כדאי להוסיף איש קשר חדש');

        $this->assertSame('יעל', $mine->fresh()->name); // nothing saved yet

        $test->call('confirmSharedContactUpdate')->assertSet('contactSharedWith', [])->assertSet('editingContactId', null);

        $mine->refresh();
        $theirs->refresh();
        $this->assertSame(['יעל כהן', '0502222222', 'מנהלת חשבונות'], [$mine->name, $mine->phone, $mine->role]);
        // Personal details follow; role and the marks stay the other customer's own.
        $this->assertSame(['יעל כהן', '0502222222', 'הנה"ח', true, false], [$theirs->name, $theirs->phone, $theirs->role, $theirs->is_primary, $theirs->is_accounting_contact]);
        $this->assertSame('ראשית', $other->contacts()->sole()->name);
    }

    public function test_editing_a_shared_contact_can_become_a_new_contact_instead(): void
    {
        [$orot, $mine, $theirs] = $this->sharedContacts();

        Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $orot])
            ->call('editContact', $mine->id)
            ->set('contactName', 'דנה')
            ->set('contactEmail', 'dana@calc.test')
            ->call('updateContact')
            ->call('addSharedEditAsNewContact')
            ->assertSet('editingContactId', null)
            ->assertSet('contactName', 'דנה')
            ->assertDispatched('open-modal', name: 'new-contact')
            ->call('addContact');

        $this->assertSame('יעל', $mine->fresh()->name);
        $this->assertSame('יעל', $theirs->fresh()->name);
        $this->assertTrue($orot->contacts()->where('email', 'dana@calc.test')->where('name', 'דנה')->exists());
    }

    public function test_no_warning_for_an_unshared_contact_or_a_role_only_change(): void
    {
        [$orot, $mine, $theirs, $other] = $this->sharedContacts();

        Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $orot])
            ->call('editContact', $mine->id)->set('contactRole', 'יועצת')->call('updateContact')
            ->assertSet('contactSharedWith', [])->assertNotDispatched('open-modal');
        $this->assertSame('יועצת', $mine->fresh()->role);
        $this->assertSame('הנה"ח', $theirs->fresh()->role);

        $solo = $other->contacts()->sole();
        Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $other])
            ->call('editContact', $solo->id)->set('contactName', 'שם חדש')->call('updateContact')
            ->assertSet('contactSharedWith', []);
        $this->assertSame('שם חדש', $solo->fresh()->name);
    }

    public function test_going_back_from_the_shared_warning_saves_nothing(): void
    {
        [$orot, $mine, $theirs] = $this->sharedContacts();

        Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $orot])
            ->call('editContact', $mine->id)->set('contactName', 'אחרת')->call('updateContact')
            ->call('cancelSharedContactUpdate')
            ->assertSet('contactSharedWith', [])
            ->assertSet('editingContactId', $mine->id)
            ->assertSet('contactName', 'אחרת');

        $this->assertSame('יעל', $mine->fresh()->name);
        $this->assertSame('יעל', $theirs->fresh()->name);
    }
}
